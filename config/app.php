<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

const LEX_APP_NAME = 'LEXSHIELD';
const LEX_DEFAULT_TIMEZONE = 'Asia/Shanghai';

function lex_load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        return;
    }

    // Remove UTF-8 BOM if present.
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

    // Normalize line endings.
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    foreach (explode("\n", $contents) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Support: export VARIABLE=value
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        if (
            $name === '' ||
            !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)
        ) {
            continue;
        }

        // Remove matching surrounding quotes.
        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        /*
         * InfinityFree may disable putenv().
         * Always make the value available through $_ENV and $_SERVER.
         */
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        // Also use putenv() when the hosting environment permits it.
        if (function_exists('putenv')) {
            @putenv($name . '=' . $value);
        }
    }
}
lex_load_env_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

function lex_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value !== false && $value !== '') {
        return (string) $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }

    return $default;
}

$lexAppUrl = lex_env('APP_URL', '') ?? '';
$lexApiUrl = lex_env('API_URL', '') ?? '';
$lexSessionTimeout = (int) (lex_env('SESSION_TIMEOUT', '1800') ?? '1800');

$lexEncryptionKey = lex_env(
    'DOCUMENT_ENCRYPTION_KEY',
    'change-me-change-me-change-me-change-me-32'
) ?? 'change-me-change-me-change-me-change-me-32';

$lexCaseFileEncryptionKey = lex_env(
    'CASE_FILE_ENCRYPTION_KEY',
    $lexEncryptionKey
) ?? $lexEncryptionKey;

date_default_timezone_set(LEX_DEFAULT_TIMEZONE);

if ($lexAppUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $baseSegments = array_values(array_filter(explode('/', $basePath), static fn (string $segment): bool => $segment !== ''));
    $lastSegment = end($baseSegments);
    if (in_array($lastSegment, ['admin', 'api', 'auth', 'client', 'lawyer', 'video'], true)) {
        array_pop($baseSegments);
        $basePath = $baseSegments ? '/' . implode('/', $baseSegments) : '';
    }
    $lexAppUrl = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
}

if (
    str_starts_with($lexAppUrl, 'http://')
    && !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off'
) {
    $lexAppUrl = 'https://' . substr($lexAppUrl, 7);
}

if ($lexApiUrl === '') {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocalHost = preg_match('/^(?:localhost|127\.0\.0\.1)(?::\d+)?$/', $host) === 1;
    $lexApiUrl = $isLocalHost ? 'http://127.0.0.1:3001' : 'https://lexshieldguru.onrender.com';
}
