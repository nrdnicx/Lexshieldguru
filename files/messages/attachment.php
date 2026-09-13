<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user = lex_require_login();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();

$messageId = lex_sanitize_int($_GET['id'] ?? 0);
$viewMode = isset($_GET['view']) && (string) $_GET['view'] === '1';
if (!$messageId) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT m.id, m.sender_id, m.receiver_id, m.attachment_original_name, m.attachment_stored_name, m.attachment_mime_type, m.attachment_size
     FROM messages m
     WHERE m.id = :id
       AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
     LIMIT 1'
);
$stmt->execute(['id' => $messageId, 'viewer_id' => (int) $user['id']]);
$message = $stmt->fetch();

if (!$message) {
    http_response_code(404);
    exit('Attachment not found.');
}

$currentUserId = (int) $user['id'];
if ($currentUserId !== (int) $message['sender_id'] && $currentUserId !== (int) $message['receiver_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$storedName = (string) ($message['attachment_stored_name'] ?? '');
$originalName = (string) ($message['attachment_original_name'] ?? '');
if ($storedName === '') {
    http_response_code(404);
    exit('Attachment not found.');
}
if ($originalName === '') {
    $originalName = basename($storedName);
}

$path = lex_messages_attachment_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file missing.');
}

$mime = strtolower((string) ($message['attachment_mime_type'] ?? 'application/octet-stream'));
$size = (int) ($message['attachment_size'] ?? filesize($path));
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

lex_audit($viewMode ? 'view_message_attachment' : 'download_message_attachment', 'messages', (string) $messageId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

// Browser-native previews: PDF and images.
$inlineMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/ogg', 'application/ogg', 'audio/mp4'];
if ($viewMode && in_array($mime, $inlineMime, true)) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $originalName) . '"');
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'; frame-ancestors \'self\';');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// Render DOCX text into a safe, browser-viewable page without requiring a third-party library.
if ($viewMode && ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $extension === 'docx')) {
    $text = '';
    $zip = new ZipArchive();
    if ($zip->open($path) === true) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (is_string($xml) && $xml !== '') {
            $dom = new DOMDocument();
            if (@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $xpath = new DOMXPath($dom);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $paragraphs = $xpath->query('//w:body/w:p');
                if ($paragraphs !== false) {
                    foreach ($paragraphs as $paragraph) {
                        $parts = [];
                        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
                            $parts[] = $node->textContent;
                        }
                        $line = trim(implode('', $parts));
                        if ($line !== '') {
                            $text .= $line . "\n";
                        }
                    }
                }
            }
        }
    }
    if ($text === '') {
        $text = 'This Word document could not be previewed in the browser. Please download the file to open it.';
    }
    $title = htmlspecialchars($originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; frame-ancestors \'self\';');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title><style>body{margin:0;background:#f3f6fb;color:#172033;font-family:Arial,sans-serif}.viewer{max-width:900px;margin:24px auto;padding:28px;background:#fff;border:1px solid #dbe3ef;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08)}h1{font-size:20px;margin:0 0 22px;overflow-wrap:anywhere}article{font-size:16px;line-height:1.75;white-space:normal;overflow-wrap:anywhere}@media(max-width:600px){.viewer{margin:0;border-radius:0;min-height:100vh;padding:20px}article{font-size:15px}}</style></head><body><main class="viewer"><h1>' . $title . '</h1><article>' . $body . '</article></main></body></html>';
    exit;
}

// Plain text/code formats can be safely shown as text when requested.
if ($viewMode && in_array($mime, ['text/plain', 'text/csv'], true)) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $originalName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// Unsupported preview type: keep the existing secure download behavior.
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalName) . '"');
readfile($path);
exit;
