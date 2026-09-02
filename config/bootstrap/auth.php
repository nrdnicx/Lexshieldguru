<?php
declare(strict_types=1);

function lex_current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = lex_db_retry(static function (): ?array {
        $stmt = lex_pdo()->prepare('SELECT id, full_name, email, role, is_active, last_login, avatar_stored_name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }, null);
    if (!$user) {
        lex_logout_session();
    }
    return $user;
}

function lex_require_login(): array
{
    $user = lex_current_user();
    if (!$user) {
        header('Location: ' . lex_app_url('auth/login.php'));
        exit;
    }
    if (($user['is_active'] ?? 0) != 1) {
        lex_logout_session();
        header('Location: ' . lex_app_url('auth/login.php'));
        exit;
    }
    return $user;
}

function lex_require_role(string|array $roles): array
{
    $user = lex_require_login();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $allowed, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $user;
}

function lex_activity_label(string $label): string
{
    return ucwords(str_replace(['_', '-'], ' ', $label));
}
