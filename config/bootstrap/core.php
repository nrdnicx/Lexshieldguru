<?php
declare(strict_types=1);

function lex_pdo(): PDO
{
    global $pdo, $lexDbPingChecked;

    if (!$pdo instanceof PDO) {
        return lex_reset_pdo();
    }

    if (!$lexDbPingChecked) {
        $lexDbPingChecked = true;
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            if (lex_db_is_connection_lost($e)) {
                return lex_reset_pdo();
            }
            throw $e;
        }
    }

    return $pdo;
}

function lex_db_retry(callable $callback, mixed $fallback = null): mixed
{
    try {
        return $callback();
    } catch (Throwable $e) {
        if (!lex_db_is_connection_lost($e)) {
            throw $e;
        }

        error_log('[DB] Lost connection detected, reconnecting: ' . $e->getMessage());
        try {
            lex_reset_pdo();
        } catch (Throwable $reconnectError) {
            error_log('[DB] Reconnect failed: ' . $reconnectError->getMessage());
            return $fallback;
        }

        try {
            return $callback();
        } catch (Throwable $retry) {
            error_log('[DB] Retry after reconnect failed: ' . $retry->getMessage());
            return $fallback;
        }
    }
}

function lex_app_url(string $path = ''): string
{
    global $lexAppUrl;
    return rtrim($lexAppUrl, '/') . '/' . ltrim($path, '/');
}

function lex_api_url(string $path = ''): string
{
    global $lexApiUrl;
    return rtrim($lexApiUrl, '/') . '/' . ltrim($path, '/');
}

function lex_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lex_flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function lex_flash_get(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function lex_site_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    try {
        $rows = lex_pdo()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        if (!lex_db_is_connection_lost($e)) {
            $settings = [];
            return $settings;
        }

        $settings = lex_db_retry(static function (): array {
            $rows = lex_pdo()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['setting_key']] = $row['setting_value'];
            }
            return $result;
        }, []);
    }

    return $settings;
}

function lex_site_setting(string $key, string $default = ''): string
{
    $settings = lex_site_settings();
    $value = $settings[$key] ?? $default;

    if ($value === null) {
        return $default;
    }

    return (string) $value;
}

function lex_bool_setting(string $settingKey, string $envName, bool $default = false): bool
{
    $defaultValue = $default ? 'true' : 'false';
    $settingValue = trim(lex_site_setting($settingKey, ''));
    if ($settingValue !== '') {
        return filter_var($settingValue, FILTER_VALIDATE_BOOL);
    }

    $envValue = getenv($envName);
    if ($envValue !== false && trim((string) $envValue) !== '') {
        return filter_var($envValue, FILTER_VALIDATE_BOOL);
    }

    return filter_var($defaultValue, FILTER_VALIDATE_BOOL);
}

function lex_asset_url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');

    $url = lex_app_url($path);

    $filePath = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $path);

    $version = is_file($filePath) ? (string) filemtime($filePath) : '';

    if ($version !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?')
            . 'v='
            . rawurlencode($version);
    }

    return $url;
}

function lex_stats(string $query, array $params = []): int
{
    $result = lex_db_retry(static function () use ($query, $params): int {
        $stmt = lex_pdo()->prepare($query);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }, 0);

    return (int) $result;
}

function lex_recent(string $query, array $params = []): array
{
    $result = lex_db_retry(static function () use ($query, $params): array {
        $stmt = lex_pdo()->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }, []);

    return is_array($result) ? $result : [];
}
