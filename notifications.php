<?php
require_once __DIR__ . '/config/bootstrap.php';

$user = lex_current_user();
if (!$user) {
    header('Location: ' . lex_app_url('auth/login.php'));
    exit;
}

$returnTo = (string) ($_POST['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$appPath = (string) (parse_url(lex_app_url('index.php'), PHP_URL_PATH) ?: '/');
$appBasePath = rtrim(str_replace('\\', '/', dirname($appPath)), '/');
$returnPath = (string) (parse_url($returnTo, PHP_URL_PATH) ?: '');
$returnQuery = (string) (parse_url($returnTo, PHP_URL_QUERY) ?: '');
if ($returnPath === '' || str_starts_with($returnTo, '//') || ($appBasePath !== '' && !str_starts_with($returnPath, $appBasePath . '/'))) {
    $returnTo = lex_app_url('index.php');
} else {
    $returnTo = $returnPath . ($returnQuery !== '' ? '?' . $returnQuery : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_notifications_read') {
        lex_notifications_table_ensure();
        lex_pdo()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0')->execute([
            'user_id' => (int) $user['id'],
        ]);
        lex_audit('mark_notifications_read', 'notifications', null, (int) $user['id']);
        lex_flash_set('success', 'Notifications marked as read.');
    }
} else {
    lex_flash_set('error', 'Invalid notification request.');
}

header('Location: ' . $returnTo);
exit;
