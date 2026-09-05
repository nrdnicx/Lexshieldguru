<?php
declare(strict_types=1);

function lex_messages_delete_attachment_file(array $message): void
{
    if (!empty($message['attachment_stored_name'])) {
        $path = lex_messages_attachment_path((string) $message['attachment_stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function lex_messages_delete_conversation_for_user(int $caseId, int $currentUserId, int $partnerUserId): int
{
    $deletedCount = lex_mark_conversation_deleted_for_user($caseId, $currentUserId, $partnerUserId);
    if ($deletedCount > 0) {
        lex_audit('delete_conversation', 'messages', (string) $caseId);
    }
    return $deletedCount;
}

function lex_messages_find_owned_message(PDO $pdo, int $messageId, int $caseId, int $senderId, int $viewerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, sender_id, attachment_stored_name
         FROM messages
         WHERE id = :id
           AND case_id = :case_id
           AND sender_id = :sender_id
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $messageId,
        'case_id' => $caseId,
        'sender_id' => $senderId,
        'viewer_id' => $viewerId,
    ]);
    $message = $stmt->fetch();
    return $message ?: null;
}

function lex_messages_delete_message_for_user(int $messageId, int $viewerId): void
{
    lex_mark_message_deleted_for_user($messageId, $viewerId);
    lex_audit('delete_message', 'messages', (string) $messageId);
}

function lex_messages_send_secure(PDO $pdo, int $senderId, int $receiverId, ?int $caseId, string $payload, ?array $attachment = null): int
{
    $encrypted = lex_encrypt_string($payload);
    $stmt = $pdo->prepare(
        'INSERT INTO messages (sender_id, receiver_id, case_id, message_text, is_encrypted, sent_at, is_read, attachment_original_name, attachment_stored_name, attachment_mime_type, attachment_size)
         VALUES (:sender_id, :receiver_id, :case_id, :message_text, 1, NOW(), 0, :attachment_original_name, :attachment_stored_name, :attachment_mime_type, :attachment_size)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'receiver_id' => $receiverId,
        'case_id' => $caseId,
        'message_text' => $encrypted,
        'attachment_original_name' => $attachment['original_name'] ?? null,
        'attachment_stored_name' => $attachment['stored_name'] ?? null,
        'attachment_mime_type' => $attachment['mime_type'] ?? null,
        'attachment_size' => $attachment['size'] ?? null,
    ]);
    $messageId = (int) $pdo->lastInsertId();
    lex_audit('send_message', 'messages', (string) $messageId);
    return $messageId;
}

function lex_phishing_detector_button(): string
{
    return '<button class="button button-secondary phishing-detector-trigger" type="button" data-modal-open="phishingDetectorModal">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.75 5.25 5.2v5.4c0 4.22 2.67 8.06 6.75 9.65 4.08-1.59 6.75-5.43 6.75-9.65V5.2L12 2.75Zm0 3.07 4.25 1.54v3.24c0 2.84-1.6 5.5-4.25 6.9-2.65-1.4-4.25-4.06-4.25-6.9V7.36L12 5.82Z" fill="currentColor"/></svg>'
        . '<span>Check URL</span>'
        . '</button>';
}

function lex_phishing_detector_modal(): void
{
    $endpointPath = lex_api_url('api/phishing/check');
    ?>
    <div class="modal-overlay phishing-detector-modal" id="phishingDetectorModal" data-modal aria-hidden="true">
      <div class="modal-card phishing-detector-card" role="dialog" aria-modal="true" aria-labelledby="phishingDetectorTitle">
        <div class="modal-header">
          <h2 id="phishingDetectorTitle">Phishing Detector</h2>
          <button class="close-button" type="button" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <form class="phishing-detector-form" data-phishing-form data-no-loading data-endpoint="<?= lex_e($endpointPath) ?>" novalidate>
            <label class="phishing-detector-field">
              <span>URL</span>
              <input class="modal-input" type="url" name="url" placeholder="https://example.com" autocomplete="url" data-phishing-input required>
            </label>
            <p class="phishing-detector-error" data-phishing-error role="alert" hidden></p>
            <div class="phishing-detector-result" data-phishing-result role="status" aria-live="polite" hidden></div>
            <div class="modal-actions">
              <button class="button button-secondary" type="button" data-modal-close data-phishing-cancel>Cancel</button>
              <button class="button button-primary" type="submit" data-phishing-submit>
                <span class="phishing-spinner" aria-hidden="true" hidden></span>
                <span data-phishing-submit-label>Scan</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
}
