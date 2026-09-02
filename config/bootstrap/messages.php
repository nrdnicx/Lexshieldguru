<?php
declare(strict_types=1);

function lex_messages_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM messages');
        if ($stmt) {
            $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
        }
        $caseColumn = null;
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'case_id'");
        if ($stmt) {
            $caseColumn = $stmt->fetch();
        }
        if ($caseColumn && strtoupper((string) ($caseColumn['Null'] ?? '')) === 'NO') {
            $pdo->exec('ALTER TABLE `messages` MODIFY `case_id` INT UNSIGNED NULL');
        }
        $adds = [
            'attachment_original_name' => "ALTER TABLE `messages` ADD COLUMN `attachment_original_name` VARCHAR(255) DEFAULT NULL AFTER `message_text`",
            'attachment_stored_name' => "ALTER TABLE `messages` ADD COLUMN `attachment_stored_name` VARCHAR(255) DEFAULT NULL AFTER `attachment_original_name`",
            'attachment_mime_type' => "ALTER TABLE `messages` ADD COLUMN `attachment_mime_type` VARCHAR(120) DEFAULT NULL AFTER `attachment_stored_name`",
            'attachment_size' => "ALTER TABLE `messages` ADD COLUMN `attachment_size` INT UNSIGNED DEFAULT NULL AFTER `attachment_mime_type`",
            'is_deleted' => "ALTER TABLE `messages` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`",
            'deleted_at' => "ALTER TABLE `messages` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_deleted`",
        ];
        foreach ($adds as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec($sql);
            }
        }
        $done = true;
    });
}

function lex_message_deletions_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'message_deletions'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if ($exists) {
            $done = true;
            return;
        }
        $pdo->exec(
            'CREATE TABLE `message_deletions` (
                `message_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `deleted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`message_id`, `user_id`),
                KEY `idx_message_deletions_user` (`user_id`),
                CONSTRAINT `fk_message_deletions_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_message_deletions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
        $done = true;
    });
}

function lex_message_thread_preferences_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'message_thread_preferences'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if ($exists) {
            $columns = [];
            $columnStmt = $pdo->query('SHOW COLUMNS FROM message_thread_preferences');
            if ($columnStmt) {
                $columns = array_map(static fn (array $row): string => (string) $row['Field'], $columnStmt->fetchAll());
            }
            if (!in_array('is_unread', $columns, true)) {
                $pdo->exec('ALTER TABLE `message_thread_preferences` ADD COLUMN `is_unread` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_muted`');
            }
            $done = true;
            return;
        }
        $pdo->exec(
            'CREATE TABLE `message_thread_preferences` (
                `user_id` INT UNSIGNED NOT NULL,
                `case_id` INT UNSIGNED NOT NULL,
                `is_important` TINYINT(1) NOT NULL DEFAULT 0,
                `is_muted` TINYINT(1) NOT NULL DEFAULT 0,
                `is_unread` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `case_id`),
                KEY `idx_message_thread_preferences_case` (`case_id`),
                CONSTRAINT `fk_message_thread_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_message_thread_preferences_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
        $done = true;
    });
}

function lex_message_thread_preferences(int $userId, int $caseId): array
{
    lex_message_thread_preferences_table_ensure();
    if ($userId <= 0 || $caseId <= 0) {
        return [
            'is_important' => false,
            'is_muted' => false,
            'is_unread' => false,
        ];
    }

    $result = lex_db_retry(static function () use ($userId, $caseId): array {
        $stmt = lex_pdo()->prepare(
            'SELECT is_important, is_muted, is_unread
             FROM message_thread_preferences
             WHERE user_id = :user_id AND case_id = :case_id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'case_id' => $caseId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return [
                'is_important' => false,
                'is_muted' => false,
                'is_unread' => false,
            ];
        }

        return [
            'is_important' => (bool) ($row['is_important'] ?? false),
            'is_muted' => (bool) ($row['is_muted'] ?? false),
            'is_unread' => (bool) ($row['is_unread'] ?? false),
        ];
    }, [
        'is_important' => false,
        'is_muted' => false,
        'is_unread' => false,
    ]);

    return is_array($result) ? $result : ['is_important' => false, 'is_muted' => false, 'is_unread' => false];
}

function lex_message_thread_set_important(int $userId, int $caseId, bool $isImportant): void
{
    lex_message_thread_preferences_table_ensure();
    lex_pdo()->prepare(
        'INSERT INTO message_thread_preferences (user_id, case_id, is_important, is_muted)
         VALUES (:user_id, :case_id, :is_important, 0)
         ON DUPLICATE KEY UPDATE is_important = VALUES(is_important), updated_at = CURRENT_TIMESTAMP'
    )->execute([
        'user_id' => $userId,
        'case_id' => $caseId,
        'is_important' => $isImportant ? 1 : 0,
    ]);
}

function lex_message_thread_set_muted(int $userId, int $caseId, bool $isMuted): void
{
    lex_message_thread_preferences_table_ensure();
    lex_pdo()->prepare(
        'INSERT INTO message_thread_preferences (user_id, case_id, is_important, is_muted)
         VALUES (:user_id, :case_id, 0, :is_muted)
         ON DUPLICATE KEY UPDATE is_muted = VALUES(is_muted), updated_at = CURRENT_TIMESTAMP'
    )->execute([
        'user_id' => $userId,
        'case_id' => $caseId,
        'is_muted' => $isMuted ? 1 : 0,
    ]);
}

function lex_message_thread_set_unread(int $userId, int $caseId, bool $isUnread): void
{
    lex_message_thread_preferences_table_ensure();
    lex_pdo()->prepare(
        'INSERT INTO message_thread_preferences (user_id, case_id, is_important, is_muted, is_unread)
         VALUES (:user_id, :case_id, 0, 0, :is_unread)
         ON DUPLICATE KEY UPDATE is_unread = VALUES(is_unread), updated_at = CURRENT_TIMESTAMP'
    )->execute([
        'user_id' => $userId,
        'case_id' => $caseId,
        'is_unread' => $isUnread ? 1 : 0,
    ]);
}

function lex_message_thread_is_muted(int $userId, int $caseId): bool
{
    $preferences = lex_message_thread_preferences($userId, $caseId);
    return (bool) ($preferences['is_muted'] ?? false);
}

function lex_message_thread_mark_unread(int $viewerId, int $partnerUserId, ?int $caseId): int
{
    if ($viewerId <= 0 || $partnerUserId <= 0) {
        return 0;
    }

    $caseClause = $caseId && $caseId > 0 ? 'case_id = :case_id' : 'case_id IS NULL';
    $params = [
        'viewer_id' => $viewerId,
        'viewer_delete_id' => $viewerId,
        'partner_user_id' => $partnerUserId,
    ];
    $hasThreadFlag = false;
    if ($caseId && $caseId > 0) {
        $params['case_id'] = $caseId;
        lex_message_thread_set_unread($viewerId, $caseId, true);
        $hasThreadFlag = true;
    }

    $stmt = lex_pdo()->prepare(
        'UPDATE messages
         SET is_read = 0
         WHERE ' . $caseClause . '
           AND sender_id = :partner_user_id
           AND receiver_id = :viewer_id
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_delete_id)'
    );
    $stmt->execute($params);

    return max($stmt->rowCount(), $hasThreadFlag ? 1 : 0);
}

function lex_message_visibility_clause(string $alias, string $userPlaceholder = ':viewer_id'): string
{
    return 'NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = ' . $alias . '.id AND md.user_id = ' . $userPlaceholder . ')';
}

function lex_mark_message_deleted_for_user(int $messageId, int $userId): void
{
    $stmt = lex_pdo()->prepare(
        'INSERT IGNORE INTO message_deletions (message_id, user_id)
         VALUES (:message_id, :user_id)'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'user_id' => $userId,
    ]);
}

function lex_mark_conversation_deleted_for_user(int $caseId, int $currentUserId, int $partnerUserId): int
{
    $pdo = lex_pdo();
    $stmt = $pdo->prepare(
        'SELECT id
         FROM messages
         WHERE case_id = :case_id
           AND ((sender_id = :me1 AND receiver_id = :partner1) OR (sender_id = :partner2 AND receiver_id = :me2))'
    );
    $stmt->execute([
        'case_id' => $caseId,
        'me1' => $currentUserId,
        'partner1' => $partnerUserId,
        'partner2' => $partnerUserId,
        'me2' => $currentUserId,
    ]);
    $ids = array_map(static fn ($row) => (int) $row['id'], $stmt->fetchAll());
    if (!$ids) {
        return 0;
    }
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO message_deletions (message_id, user_id)
         VALUES (:message_id, :user_id)'
    );
    foreach ($ids as $messageId) {
        $insert->execute([
            'message_id' => $messageId,
            'user_id' => $currentUserId,
        ]);
    }
    return count($ids);
}
