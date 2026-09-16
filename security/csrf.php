<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // InfinityFree may point PHP sessions at a server-level path that is not writable.
    // Store sessions inside the application directory instead.
    $lexSessionPath = __DIR__ . '/../.sessions';
    if (is_dir($lexSessionPath) && is_writable($lexSessionPath)) {
        session_save_path($lexSessionPath);
    }
    session_start();
}

function lex_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function lex_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(lex_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function lex_csrf_validate(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

