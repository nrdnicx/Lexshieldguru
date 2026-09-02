<?php
require_once __DIR__ . '/../../config/bootstrap.php';

$user = lex_require_login();

$lawyerId = lex_sanitize_int($_GET['lawyer_id'] ?? 0);
$storedName = '';
$targetLabel = 'gcash_qr_stored_name';
if ($lawyerId > 0) {
    $stmt = lex_pdo()->prepare(
        'SELECT l.gcash_qr_stored_name, l.user_id
         FROM lawyers l
         JOIN users u ON u.id = l.user_id
         WHERE l.id = :id
           AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['id' => $lawyerId]);
    $lawyer = $stmt->fetch();
    if (!$lawyer) {
        http_response_code(404);
        exit('Lawyer QR not found.');
    }
    $storedName = trim((string) ($lawyer['gcash_qr_stored_name'] ?? ''));
    $targetLabel = 'lawyer:' . $lawyerId;
} else {
    $storedName = trim(lex_site_setting('gcash_qr_stored_name'));
}
if ($storedName === '') {
    http_response_code(404);
    exit('GCash QR not configured.');
}

$path = lex_payment_qr_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('GCash QR file missing.');
}

$mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
if ($mime === '') {
    $mime = 'image/png';
}

lex_audit('preview_payment_qr', $lawyerId > 0 ? 'lawyers' : 'site_settings', $targetLabel);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="gcash-qr.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
readfile($path);
exit;
