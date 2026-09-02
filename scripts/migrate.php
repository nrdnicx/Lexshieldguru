<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$pdo = lex_make_pdo();
$migrationsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `version` VARCHAR(120) NOT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$appliedMap = array_fill_keys(array_map('strval', $applied), true);

$files = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
sort($files, SORT_STRING);

$appliedCount = 0;

foreach ($files as $file) {
    $version = basename($file);
    if (isset($appliedMap[$version])) {
        fwrite(STDOUT, "[skip] {$version}\n");
        continue;
    }

    $sql = trim((string) file_get_contents($file));
    if ($sql === '') {
        fwrite(STDOUT, "[skip] {$version} (empty)\n");
        continue;
    }

    fwrite(STDOUT, "[apply] {$version}\n");
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $stmt->execute(['version' => $version]);
        $appliedCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[fail] {$version}: {$e->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Done. Applied {$appliedCount} migration(s).\n");
