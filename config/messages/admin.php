<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../bootstrap.php';

$user = lex_require_role('admin');
$pdo = lex_pdo();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();
lex_message_thread_preferences_table_ensure();
$adminUserId = (int) $user['id'];
$currentUserLabel = $user['full_name'] ?? 'You';
$currentUserRole = lex_message_role_label('admin');

$deleteMessageAttachment = static function (array $message): void {
    if (!empty($message['attachment_stored_name'])) {
        $path = lex_messages_attachment_path((string) $message['attachment_stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
};

$cases = lex_recent(
    'SELECT c.id AS case_id, c.case_number, c.title, c.status, c.priority, lu.user_id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email, uu.avatar_stored_name AS lawyer_avatar_stored_name
     FROM cases c
     JOIN lawyers lu ON lu.id = c.lawyer_id
     JOIN users uu ON uu.id = lu.user_id
     WHERE EXISTS (
         SELECT 1
         FROM messages m
         WHERE m.case_id = c.id
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
           AND ((m.sender_id = :admin_id_1 AND m.receiver_id = lu.user_id) OR (m.sender_id = lu.user_id AND m.receiver_id = :admin_id_2))
     )
     ORDER BY c.id DESC'
    ,
    ['admin_id_1' => $adminUserId, 'admin_id_2' => $adminUserId, 'viewer_id' => $adminUserId]
);

$recipientCases = lex_recent(
    'SELECT c.id AS case_id, c.case_number, c.title, lu.user_id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email, uu.avatar_stored_name AS lawyer_avatar_stored_name
     FROM cases c
     JOIN lawyers lu ON lu.id = c.lawyer_id
     JOIN users uu ON uu.id = lu.user_id
     WHERE uu.is_active = 1
     ORDER BY c.id DESC'
);

$activeLawyers = lex_recent(
    'SELECT uu.id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email, lu.specialization AS lawyer_specialization, lu.status AS lawyer_status
     FROM users uu
     JOIN lawyers lu ON lu.user_id = uu.id
     WHERE uu.role = :role AND uu.is_active = 1 AND lu.status IN ("active", "busy")
     ORDER BY uu.full_name ASC',
    ['role' => 'lawyer']
);

$directLawyerConversations = lex_recent(
    'SELECT DISTINCT uu.id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email, uu.avatar_stored_name AS lawyer_avatar_stored_name, lu.status AS lawyer_status
     FROM users uu
     JOIN lawyers lu ON lu.user_id = uu.id
     WHERE uu.role = :role
       AND EXISTS (
           SELECT 1
           FROM messages m
           WHERE m.case_id IS NULL
             AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
             AND ((m.sender_id = :admin_id_1 AND m.receiver_id = uu.id) OR (m.sender_id = uu.id AND m.receiver_id = :admin_id_2))
       )
     ORDER BY uu.full_name ASC',
    ['role' => 'lawyer', 'admin_id_1' => $adminUserId, 'admin_id_2' => $adminUserId, 'viewer_id' => $adminUserId]
);

$caseMap = [];
$recipientMap = [];
$composeCaseMap = [];
$caseByLawyerUserId = [];
foreach ($cases as $case) {
    $caseMap[(int) $case['case_id']] = $case;
    $composeCaseMap[(int) $case['case_id']] = $case;
}
foreach ($recipientCases as $case) {
    $lawyerUserId = (int) $case['lawyer_user_id'];
    $composeCaseMap[(int) $case['case_id']] = $composeCaseMap[(int) $case['case_id']] ?? $case;
    $caseByLawyerUserId[$lawyerUserId] = $caseByLawyerUserId[$lawyerUserId] ?? $case;
}
foreach ($activeLawyers as $lawyer) {
    $lawyerUserId = (int) $lawyer['lawyer_user_id'];
    $caseInfoForLawyer = $caseByLawyerUserId[$lawyerUserId] ?? null;
    $recipientMap['lawyer:' . $lawyerUserId] = [
        'id' => $lawyerUserId,
        'name' => $lawyer['lawyer_name'],
        'role' => 'Lawyer',
        'specialization' => $lawyer['lawyer_specialization'] ?? '',
        'status' => $lawyer['lawyer_status'] ?? '',
        'case_id' => (int) ($caseInfoForLawyer['case_id'] ?? 0),
        'case_number' => $caseInfoForLawyer['case_number'] ?? '',
        'case_title' => $caseInfoForLawyer['title'] ?? '',
    ];
}
$availableRecipients = array_values($recipientMap);
$selectableRecipients = $availableRecipients;

$threadInput = (string) ($_GET['thread'] ?? '');
$selectedKind = 'lawyer';
$defaultComposeCase = $cases[0] ?? ($recipientCases[0] ?? null);
$selectedCaseId = $defaultComposeCase ? (int) $defaultComposeCase['case_id'] : 0;
$selectedDirectLawyerId = 0;
if (preg_match('/^lawyer:(\d+)$/', $threadInput, $matches)) {
    $selectedCaseId = (int) $matches[1];
    if (!isset($caseMap[$selectedCaseId])) {
        $selectedCaseId = $defaultComposeCase ? (int) $defaultComposeCase['case_id'] : 0;
    }
} elseif (preg_match('/^lawyer_user:(\d+)$/', $threadInput, $matches)) {
    $selectedDirectLawyerId = (int) $matches[1];
    $selectedCaseId = 0;
}

$message = '';
$error = '';
$partnerId = 0;
$partnerName = '';
$caseInfo = $selectedCaseId && isset($caseMap[$selectedCaseId]) ? $caseMap[$selectedCaseId] : ($selectedCaseId && isset($composeCaseMap[$selectedCaseId]) ? $composeCaseMap[$selectedCaseId] : null);
$activeCaseNumber = $caseInfo['case_number'] ?? 'No case selected';

if ($selectedCaseId && $caseInfo) {
    $partnerId = (int) $caseInfo['lawyer_user_id'];
    $partnerName = $caseInfo['lawyer_name'];
} elseif ($selectedDirectLawyerId && isset($recipientMap['lawyer:' . $selectedDirectLawyerId])) {
    $directRecipient = $recipientMap['lawyer:' . $selectedDirectLawyerId];
    $partnerId = (int) $directRecipient['id'];
    $partnerName = (string) $directRecipient['name'];
    $activeCaseNumber = 'Direct message';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    if (!empty($_POST['mark_unread_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postLawyerUserId = lex_sanitize_int($_POST['lawyer_user_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $postLawyerUserId = (int) $caseMap[$postCaseId]['lawyer_user_id'];
        }
        if ($postLawyerUserId > 0 && ($postCaseId || isset($recipientMap['lawyer:' . $postLawyerUserId]))) {
            $changed = lex_message_thread_mark_unread((int) $user['id'], $postLawyerUserId, $postCaseId > 0 ? $postCaseId : null);
            lex_flash_set($changed > 0 ? 'success' : 'error', $changed > 0 ? 'Conversation marked as unread.' : 'No received messages were available to mark unread.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        $redirectThread = $postCaseId ? 'lawyer:' . $postCaseId : 'lawyer_user:' . $postLawyerUserId;
        header('Location: ' . lex_app_url('admin/messages.php?thread=' . urlencode($redirectThread) . '&keep_unread=1'));
        exit;
    }
    if (!empty($_POST['toggle_important_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $nextImportant = !lex_message_thread_preferences((int) $user['id'], $postCaseId)['is_important'];
            lex_message_thread_set_important((int) $user['id'], $postCaseId, $nextImportant);
            lex_flash_set('success', $nextImportant ? 'Conversation marked as important.' : 'Conversation removed from important.');
        } else {
            lex_flash_set('error', 'Important is available for case-linked conversations only.');
        }
        $filterQuery = $nextImportant ? '&filter=important' : '';
        header('Location: ' . lex_app_url('admin/messages.php?thread=' . urlencode('lawyer:' . $postCaseId) . $filterQuery));
        exit;
    }
    if (!empty($_POST['toggle_mute_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $nextMuted = !lex_message_thread_preferences((int) $user['id'], $postCaseId)['is_muted'];
            lex_message_thread_set_muted((int) $user['id'], $postCaseId, $nextMuted);
            lex_flash_set('success', $nextMuted ? 'Notifications muted for this conversation.' : 'Notifications restored for this conversation.');
        } else {
            lex_flash_set('error', 'Mute is available for case-linked conversations only.');
        }
        header('Location: ' . lex_app_url('admin/messages.php?thread=' . urlencode('lawyer:' . $postCaseId)));
        exit;
    }
    if (!empty($_POST['delete_conversation_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $caseRow = $caseMap[$postCaseId];
            $partnerUserId = (int) $caseRow['lawyer_user_id'];
            $deletedCount = lex_messages_delete_conversation_for_user($postCaseId, (int) $user['id'], $partnerUserId);
            if ($deletedCount > 0) {
                lex_flash_set('success', 'Conversation deleted for your view.');
            } else {
                lex_flash_set('error', 'No matching conversation was found to delete.');
            }
        }
        header('Location: ' . lex_app_url('admin/messages.php'));
        exit;
    }
    if (!empty($_POST['delete_message_submit'])) {
        $messageId = lex_sanitize_int($_POST['message_id'] ?? 0);
        $messageRow = lex_messages_find_owned_message($pdo, $messageId, $selectedCaseId, (int) $user['id'], (int) $user['id']);
        if ($messageRow) {
            lex_messages_delete_message_for_user($messageId, (int) $user['id']);
            lex_flash_set('success', 'Message deleted for your view.');
            header('Location: ' . lex_app_url('admin/messages.php?thread=' . urlencode($threadInput)));
            exit;
        }
        $error = 'Message not found.';
    }
    if (!empty($_POST['new_message_submit'])) {
        $postReceiverId = lex_sanitize_int($_POST['new_receiver_id'] ?? 0);
        $subject = lex_sanitize_text($_POST['new_subject'] ?? '');
        $text = lex_sanitize_multiline_text($_POST['new_message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['new_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim(($subject !== '' ? "Subject: {$subject}\n\n" : '') . $text);
        $recipient = $postReceiverId ? ($recipientMap['lawyer:' . $postReceiverId] ?? null) : null;
        $resolvedCase = $postReceiverId && isset($caseByLawyerUserId[$postReceiverId]) ? $caseByLawyerUserId[$postReceiverId] : null;
        if (!$postReceiverId || !$recipient || ($payload === '' && !$attachment)) {
            $error = $error ?: 'Select a lawyer and write a message or attach a file before sending.';
        } else {
            $postCaseId = $resolvedCase ? (int) $resolvedCase['case_id'] : null;
            if ($payload === '' && $attachment) {
                $payload = 'Attachment: ' . $attachment['original_name'];
            }
            try {
                lex_messages_send_secure($pdo, (int) $user['id'], $postReceiverId, $postCaseId, $payload, $attachment);
                if (!$postCaseId || !lex_message_thread_is_muted($postReceiverId, $postCaseId)) {
                    lex_notify($postReceiverId, 'message', 'You received a secure message in LEXSHIELD.');
                }
                $redirectThread = $postCaseId ? 'lawyer:' . $postCaseId : 'lawyer_user:' . $postReceiverId;
                header('Location: messages.php?thread=' . urlencode($redirectThread));
                exit;
            } catch (Throwable $exception) {
                if (!empty($attachment['path']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
                $error = 'Unable to send the message right now.';
            }
        }
    } else {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postReceiverId = lex_sanitize_int($_POST['receiver_id'] ?? 0);
        $text = lex_sanitize_multiline_text($_POST['message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['message_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim($text);

        if (($payload === '' && !$attachment) || (!$postCaseId && !$postReceiverId)) {
            $error = $error ?: 'Select a conversation and write a message or attach a file before sending.';
        } elseif ($postCaseId && !isset($composeCaseMap[$postCaseId])) {
            $error = 'That case is not available.';
        } elseif (!$postCaseId && !isset($recipientMap['lawyer:' . $postReceiverId])) {
            $error = 'That lawyer is not available.';
        } else {
            if ($postCaseId) {
                $postReceiverId = (int) $composeCaseMap[$postCaseId]['lawyer_user_id'];
            }
            $messageCaseId = $postCaseId ?: null;
            if ($payload === '' && $attachment) {
                $payload = 'Attachment: ' . $attachment['original_name'];
            }
            try {
                lex_messages_send_secure($pdo, (int) $user['id'], $postReceiverId, $messageCaseId, $payload, $attachment);
                if (!$messageCaseId || !lex_message_thread_is_muted($postReceiverId, $messageCaseId)) {
                    lex_notify($postReceiverId, 'message', 'You received a secure message in LEXSHIELD.');
                }
                $redirectThread = $messageCaseId ? 'lawyer:' . $messageCaseId : 'lawyer_user:' . $postReceiverId;
                header('Location: messages.php?thread=' . urlencode($redirectThread));
                exit;
            } catch (Throwable $exception) {
                if (!empty($attachment['path']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
                $error = 'Unable to send the message right now.';
            }
        }
    }
}

$sidebarGroups = ['Lawyers' => []];
foreach ($cases as $case) {
    $caseId = (int) $case['case_id'];
    $conversationPreferences = lex_message_thread_preferences((int) $user['id'], $caseId);
    $unread = lex_stats(
        'SELECT COUNT(*) FROM messages WHERE case_id = :case_id AND sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0 AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)',
        ['case_id' => $caseId, 'sender_id' => (int) $case['lawyer_user_id'], 'receiver_id' => (int) $user['id'], 'viewer_id' => (int) $user['id']]
    );
    if (!empty($conversationPreferences['is_unread'])) {
        $unread = max(1, $unread);
    }
    $last = lex_recent(
        'SELECT m.message_text, m.is_encrypted, m.sent_at FROM messages m
         WHERE m.case_id = ? AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?) AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.sent_at DESC LIMIT 1',
        [$caseId, (int) $user['id'], (int) $user['id'], (int) $case['lawyer_user_id'], (int) $case['lawyer_user_id'], (int) $user['id']]
    );
$lastRow = $last[0] ?? null;
    $preview = $lastRow ? lex_message_excerpt(lex_message_display_text($lastRow)) : 'No messages yet';
    $time = $lastRow ? lex_message_timestamp((string) $lastRow['sent_at']) : '';
    $sidebarGroups['Lawyers'][] = [
        'key' => 'lawyer:' . $caseId,
        'title' => $case['lawyer_name'],
        'subtitle' => $case['case_number'] . ' · ' . $case['title'],
        'role' => 'Lawyer',
        'unread' => $unread,
        'preview' => $preview,
        'time' => $time,
        'important' => !empty($conversationPreferences['is_important']) ? '1' : '0',
        'case_id' => $caseId,
    ];
}
foreach ($directLawyerConversations as $lawyer) {
    $lawyerUserId = (int) $lawyer['lawyer_user_id'];
    $unread = lex_stats(
        'SELECT COUNT(*) FROM messages WHERE case_id IS NULL AND sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0 AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)',
        ['sender_id' => $lawyerUserId, 'receiver_id' => (int) $user['id'], 'viewer_id' => (int) $user['id']]
    );
    $last = lex_recent(
        'SELECT m.message_text, m.is_encrypted, m.sent_at FROM messages m
         WHERE m.case_id IS NULL
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
           AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.sent_at DESC LIMIT 1',
        [(int) $user['id'], (int) $user['id'], $lawyerUserId, $lawyerUserId, (int) $user['id']]
    );
    $lastRow = $last[0] ?? null;
    $preview = $lastRow ? lex_message_excerpt(lex_message_display_text($lastRow)) : 'No messages yet';
    $time = $lastRow ? lex_message_timestamp((string) $lastRow['sent_at']) : '';
    $sidebarGroups['Lawyers'][] = [
        'key' => 'lawyer_user:' . $lawyerUserId,
        'title' => $lawyer['lawyer_name'],
        'subtitle' => 'Direct message',
        'role' => 'Lawyer',
        'unread' => $unread,
        'preview' => $preview,
        'time' => $time,
        'important' => '0',
        'case_id' => 0,
        'lawyer_user_id' => $lawyerUserId,
    ];
}

if (!$selectedCaseId && !empty($sidebarGroups['Lawyers'][0])) {
    $firstThread = $sidebarGroups['Lawyers'][0];
    $selectedCaseId = (int) ($firstThread['case_id'] ?? 0);
    if ($selectedCaseId && isset($caseMap[$selectedCaseId])) {
        $caseInfo = $caseMap[$selectedCaseId];
        $partnerId = (int) $caseInfo['lawyer_user_id'];
        $partnerName = $caseInfo['lawyer_name'];
        $activeCaseNumber = $caseInfo['case_number'];
    } elseif (!empty($firstThread['lawyer_user_id']) && isset($recipientMap['lawyer:' . (int) $firstThread['lawyer_user_id']])) {
        $selectedDirectLawyerId = (int) $firstThread['lawyer_user_id'];
        $directRecipient = $recipientMap['lawyer:' . $selectedDirectLawyerId];
        $partnerId = (int) $directRecipient['id'];
        $partnerName = (string) $directRecipient['name'];
        $activeCaseNumber = 'Direct message';
    }
}

if ($partnerId && ($selectedCaseId || $selectedDirectLawyerId) && empty($_GET['keep_unread'])) {
    $caseClause = $selectedCaseId ? 'case_id = :case_id' : 'case_id IS NULL';
    $markReadParams = [
        'receiver_id' => (int) $user['id'],
        'sender_id' => $partnerId,
        'viewer_id' => (int) $user['id'],
    ];
    if ($selectedCaseId) {
        $markReadParams['case_id'] = $selectedCaseId;
    }
    $pdo->prepare(
        'UPDATE messages
         SET is_read = 1
         WHERE ' . $caseClause . ' AND receiver_id = :receiver_id AND sender_id = :sender_id AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)'
    )->execute($markReadParams);
    if ($selectedCaseId) {
        lex_message_thread_set_unread((int) $user['id'], $selectedCaseId, false);
    }
}

$threadMessages = [];
if ($partnerId && ($selectedCaseId || $selectedDirectLawyerId)) {
    $caseWhere = $selectedCaseId ? 'm.case_id = ?' : 'm.case_id IS NULL';
    $stmt = $pdo->prepare(
        'SELECT m.*, su.full_name AS sender_name, su.role AS sender_role, ru.full_name AS receiver_name, ru.role AS receiver_role
         FROM messages m
         JOIN users su ON su.id = m.sender_id
         JOIN users ru ON ru.id = m.receiver_id
         WHERE ' . $caseWhere . '
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
           AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.sent_at ASC'
    );
    $threadParams = [
        (int) $user['id'],
        (int) $user['id'],
        $partnerId,
        $partnerId,
        (int) $user['id'],
    ];
    if ($selectedCaseId) {
        array_unshift($threadParams, $selectedCaseId);
    }
    $stmt->execute($threadParams);
    $threadMessages = $stmt->fetchAll();
}

$activeThread = $selectedDirectLawyerId ? 'lawyer_user:' . $selectedDirectLawyerId : 'lawyer:' . $selectedCaseId;
$threadLabel = $partnerName ?: 'Lawyer';
$onlineLabel = 'Online';
$caseStatus = $caseInfo['status'] ?? 'open';
$casePriority = $caseInfo['priority'] ?? 'normal';
$sendDisabled = !$partnerId || (!$selectedCaseId && !$selectedDirectLawyerId);
$threadPreferences = $selectedCaseId
    ? lex_message_thread_preferences((int) $user['id'], $selectedCaseId)
    : ['is_important' => false, 'is_muted' => false];
$isThreadImportant = (bool) ($threadPreferences['is_important'] ?? false);
$isThreadMuted = (bool) ($threadPreferences['is_muted'] ?? false);
$canUseThreadPreferences = $selectedCaseId > 0;

lex_page_header('Messages', 'messages', $user);
?>
<section class="messages-page admin-messages-page" data-chat-shell data-partner-name="<?= lex_e($threadLabel) ?>">
  <div class="messages-layout admin-messages-layout">
    <aside class="chat-sidebar admin-messages-sidebar">
      <div class="card-head admin-messages-head">
        <h2>Conversations</h2>
        <span class="pill"><?= number_format(count($cases)) ?> cases</span>
      </div>
      <input class="conversation-search" type="search" placeholder="Search users or conversations" data-conversation-search>
      <div class="filter-tabs">
        <button class="filter-tab is-active" type="button" data-filter-tab="all">All</button>
        <button class="filter-tab" type="button" data-filter-tab="unread">Unread</button>
        <button class="filter-tab" type="button" data-filter-tab="important">Important</button>
      </div>
      <div class="conversation-list-scroll" data-conversation-list>
      <?php foreach ($sidebarGroups as $groupName => $items): ?>
        <div class="conversation-group">
          <h3><?= lex_e($groupName) ?></h3>
          <?php foreach ($items as $item): ?>
            <div class="conversation-item<?= $activeThread === $item['key'] ? ' is-active' : '' ?>" data-conversation-item data-unread="<?= (int) ($item['unread'] > 0 ? 1 : 0) ?>" data-important="<?= lex_e($item['important']) ?>">
              <a class="conversation-item-link" href="?thread=<?= urlencode($item['key']) ?>">
              <div class="conversation-item-top">
                <div class="conversation-item-title">
                  <strong><?= lex_e($item['title']) ?></strong>
                  <span class="conversation-role"><?= lex_e($item['role']) ?></span>
                </div>
                <span class="conversation-flags">
                  <?php if ((string) $item['important'] === '1'): ?><span class="conversation-state-pill">Important</span><?php endif; ?>
                  <?php if ((int) $item['unread'] > 0): ?><span class="badge"><?= (int) $item['unread'] ?></span><?php endif; ?>
                </span>
              </div>
              <div class="conversation-item-bottom">
                <span class="muted"><?= lex_e($item['subtitle']) ?></span>
                <small><?= lex_e($item['time']) ?></small>
              </div>
              <small><?= lex_e($item['preview']) ?></small>
              </a>
                <form method="post" class="conversation-delete-form" data-no-loading>
                <?= lex_csrf_field() ?>
                <input type="hidden" name="delete_conversation_submit" value="1">
                <input type="hidden" name="case_id" value="<?= (int) $item['case_id'] ?>">
                <input type="hidden" name="thread_kind" value="lawyer">
                <button class="conversation-delete-button" type="submit" aria-label="Delete conversation" data-confirm="Delete this conversation?">&times;</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </aside>

    <section class="chat-panel admin-messages-panel">
      <div class="chat-header admin-messages-header">
        <div>
          <h2><?= lex_e($threadLabel) ?></h2>
          <div class="status-line"><?= lex_e($activeCaseNumber) ?> · <?= lex_e($onlineLabel) ?></div>
        </div>
        <div class="header-actions">
          <div class="role-badge"><?= lex_e(lex_message_role_label('lawyer')) ?></div>
          <?php if ($isThreadMuted): ?><div class="role-badge">Muted</div><?php endif; ?>
          <button
            class="icon-chip"
            type="button"
            data-modal-open="conversationInfoModal"
            data-info-name="<?= lex_e($threadLabel) ?>"
            data-info-role="Lawyer"
            data-info-status="<?= lex_e($onlineLabel) ?>"
            data-info-case="<?= lex_e($activeCaseNumber) ?>"
            data-info-id="MSG-<?= (int) $selectedCaseId ?>"
            data-info-created="<?= lex_e(date('M j, Y')) ?>"
            data-info-activity="<?= lex_e($threadMessages ? lex_message_timestamp((string) end($threadMessages)['sent_at']) : 'No activity yet') ?>"
            data-info-avatar-url="<?= lex_e(lex_profile_avatar_url((string) ($caseInfo['lawyer_avatar_stored_name'] ?? ''))) ?>"
            aria-label="Conversation Info"
          >Info</button>
        </div>
      </div>

      <div class="chat-feed" data-chat-scroll>
        <?php if (!$selectedCaseId || !$partnerId): ?>
          <div class="info-card">
            <strong>No messages yet</strong>
            <p class="muted">Select a lawyer conversation to start chatting.</p>
          </div>
        <?php else: ?>
          <?php foreach ($threadMessages as $msg): ?>
            <?php
              $plain = $msg['is_encrypted'] ? lex_decrypt_string((string) $msg['message_text']) : (string) $msg['message_text'];
              $plain = trim($plain);
              if ($plain === '' && !empty($msg['attachment_original_name'])) {
                $plain = 'Attachment: ' . (string) $msg['attachment_original_name'];
              }
              $plain = $plain !== '' ? $plain : (string) $msg['message_text'];
              $bubbleClass = lex_message_bubble_class((int) $msg['sender_id'], (int) $user['id']);
              $statusText = (int) $msg['sender_id'] === (int) $user['id'] ? ((int) $msg['is_read'] === 1 ? 'Seen' : 'Delivered') : 'Received';
            ?>
            <div class="message-row <?= lex_e($bubbleClass) ?>">
              <article class="chat-bubble <?= lex_e($bubbleClass) ?> role-<?= lex_e((string) $msg['sender_role']) ?>">
                <?php if ((int) $msg['sender_id'] === (int) $user['id']): ?>
                  <form method="post" class="message-delete-form" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="delete_message_submit" value="1">
                    <input type="hidden" name="message_id" value="<?= (int) $msg['id'] ?>">
                    <button class="message-delete-button" type="submit" aria-label="Delete message" data-confirm="Delete this message?">&times;</button>
                  </form>
                <?php endif; ?>
                <div class="bubble-meta">
                  <div class="bubble-meta" style="gap:0.45rem;">
                    <strong><?= lex_e($msg['sender_name']) ?></strong>
                    <span class="sender-pill"><?= lex_e(lex_message_role_label((string) $msg['sender_role'])) ?></span>
                  </div>
                  <span><?= lex_e(lex_message_timestamp((string) $msg['sent_at'])) ?></span>
                </div>
                <p class="bubble-text"><?= lex_e($plain) ?></p>
                <?php if (!empty($msg['attachment_stored_name'])): ?>
                  <div class="attachment-card">
                    <strong><?= lex_e((string) ($msg['attachment_original_name'] ?? $msg['attachment_stored_name'])) ?></strong>
 <span class="muted"><?= lex_e(lex_human_file_size((int) ($msg['attachment_size'] ?? 0))) ?> &middot; Downloadable file</span>
                    <a class="button button-secondary" href="<?= lex_e(lex_app_url('message_attachment.php?id=' . (int) $msg['id'])) ?>">Download</a>
                  </div>
                <?php endif; ?>
                <div class="bubble-meta">
                  <span class="bubble-status"><?= lex_e($statusText) ?></span>
                  <span class="bubble-status"><?= lex_e(ucfirst((string) $msg['sender_role'])) ?></span>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="conversation-actions admin-messages-actions">
        <button
          class="button button-secondary"
          type="button"
          data-modal-open="newMessageModal"
          data-default-case="<?= (int) $selectedCaseId ?>"
          data-default-recipient="<?= (int) $partnerId ?>"
          data-default-role="lawyer"
        >Create New Message</button>
        <?= lex_phishing_detector_button() ?>
      </div>

      <div class="chat-composer admin-messages-composer">
        <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form" data-chat-composer enctype="multipart/form-data">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <input type="hidden" name="receiver_id" value="<?= (int) $partnerId ?>">
          <input type="hidden" name="thread_kind" value="lawyer">
          <div class="composer-row">
            <div class="composer-tools">
              <label class="icon-button ghost" style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;">
                📎
                <input type="file" hidden accept=".pdf,.docx,.png,.jpg,.jpeg,.webp" data-attachment-input name="message_attachment">
              </label>
            </div>
            <textarea class="composer-input" name="message_text" rows="3" placeholder="<?= $sendDisabled ? 'Select a conversation first' : 'Write a secure message...' ?>" <?= $sendDisabled ? 'disabled' : '' ?> data-chat-input></textarea>
            <button class="button button-primary" type="submit" <?= $sendDisabled ? 'disabled' : '' ?>>Send</button>
          </div>
          <div class="attachment-preview" data-attachment-preview hidden></div>
        </form>
      </div>
    </section>

  </div>
</section>

<div class="modal-overlay" id="newMessageModal" data-modal aria-hidden="true">
  <div class="modal-card wide admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="newMessageTitle">
    <div class="modal-header">
      <h2 id="newMessageTitle">Create New Message</h2>
      <button class="close-button" type="button" data-modal-close aria-label="Close" onclick="document.getElementById('newMessageModal')?.classList.remove('is-open');document.getElementById('newMessageModal')?.setAttribute('aria-hidden','true');">&times;</button>
    </div>
    <div class="modal-body">
      <div class="error-text" data-modal-errors></div>
      <form method="post" class="modal-grid" data-new-message-form data-no-loading enctype="multipart/form-data">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="new_message_submit" value="1">
        <input type="hidden" name="new_case_id" value="<?= (int) $selectedCaseId ?>" data-default-case-input>
        <label>Recipient Name
          <select class="modal-select" name="new_receiver_id" data-recipient-select required<?= !$selectableRecipients ? ' disabled' : '' ?>>
            <option value="" selected>Select a lawyer</option>
            <?php foreach ($selectableRecipients as $recipient): ?>
              <?php $recipientCaseId = (int) ($recipient['case_id'] ?? 0); ?>
              <option
                value="<?= (int) $recipient['id'] ?>"
                data-role="lawyer"
                data-case-id="<?= $recipientCaseId ?>"
                <?= (int) $recipient['id'] === (int) $partnerId ? 'selected' : '' ?>
              ><?= lex_e($recipient['name'] . (!empty($recipient['specialization']) ? ' · ' . $recipient['specialization'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$selectableRecipients): ?>
            <span class="muted">No active lawyers are available for messaging yet.</span>
          <?php endif; ?>
        </label>
        <input type="hidden" name="new_recipient_role" value="lawyer" data-recipient-role>
        <label>Sender
          <select class="modal-select" disabled>
            <option selected><?= lex_e($currentUserLabel) ?> (<?= lex_e($currentUserRole) ?>)</option>
          </select>
        </label>
        <label>Subject
          <input class="modal-input" type="text" name="new_subject" data-subject-input placeholder="Optional subject">
        </label>
        <label>Message <span class="muted">(optional if attaching a file)</span>
          <textarea class="modal-textarea" name="new_message_text" rows="6" data-message-input placeholder="Type your message here..."></textarea>
        </label>
        <div>
          <label class="button button-secondary file-button" data-modal-attachment-button for="adminNewMessageAttachment">Attach File</label>
          <input id="adminNewMessageAttachment" type="file" hidden accept=".pdf,.docx,.png,.jpg,.jpeg,.webp" data-modal-attachment-input name="new_attachment">
          <div class="attachment-preview" data-modal-attachment-name>No file selected</div>
        </div>
        <div class="modal-actions">
          <button class="button button-secondary" type="button" data-modal-close onclick="document.getElementById('newMessageModal')?.classList.remove('is-open');document.getElementById('newMessageModal')?.setAttribute('aria-hidden','true');">Cancel</button>
          <button class="button button-primary" type="submit">Send Message</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="conversationInfoModal" data-modal aria-hidden="true">
  <div class="modal-card admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="conversationInfoTitle">
    <div class="modal-header">
      <h2 id="conversationInfoTitle">Conversation Info</h2>
            <button class="close-button" type="button" data-modal-close aria-label="Close" onclick="document.getElementById('conversationInfoModal')?.classList.remove('is-open');document.getElementById('conversationInfoModal')?.setAttribute('aria-hidden','true');">&times;</button>
    </div>
    <div class="modal-body">
      <div class="conversation-info-layout">
        <div class="info-card conversation-info-card">
          <div class="conversation-info-person">
            <?php $infoAvatarUrl = lex_profile_avatar_url((string) ($caseInfo['lawyer_avatar_stored_name'] ?? '')); ?>
            <div class="avatar-placeholder conversation-info-avatar" data-info-avatar>
              <img src="<?= lex_e($infoAvatarUrl) ?>" alt="" data-info-avatar-img<?= $infoAvatarUrl === '' ? ' hidden' : '' ?>>
              <span data-info-avatar-text<?= $infoAvatarUrl !== '' ? ' hidden' : '' ?>>LA</span>
            </div>
            <div>
              <strong data-info-name><?= lex_e($threadLabel) ?></strong>
              <div class="modal-note"><span data-info-role>Lawyer</span> · <span data-info-status><?= lex_e($onlineLabel) ?></span></div>
            </div>
          </div>
          <div class="info-row">
            <span>Case Name</span>
            <strong data-info-case><?= lex_e($activeCaseNumber) ?></strong>
          </div>
          <div class="info-row">
            <span>Conversation ID</span>
            <strong data-info-id>MSG-<?= (int) $selectedCaseId ?></strong>
          </div>
          <div class="info-row">
            <span>Created Date</span>
            <strong data-info-created><?= lex_e(date('M j, Y')) ?></strong>
          </div>
          <div class="info-row">
            <span>Last Activity</span>
            <strong data-info-activity><?= lex_e($threadMessages ? lex_message_timestamp((string) end($threadMessages)['sent_at']) : 'No activity yet') ?></strong>
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <form method="post" data-no-loading>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="mark_unread_submit" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <input type="hidden" name="lawyer_user_id" value="<?= (int) $partnerId ?>">
          <button class="button button-secondary" type="submit" <?= !$partnerId ? 'disabled' : '' ?>>Mark as Unread</button>
        </form>
        <form method="post" data-no-loading>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="toggle_important_submit" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <button class="button button-secondary" type="submit" <?= !$canUseThreadPreferences ? 'disabled' : '' ?>><?= $isThreadImportant ? 'Remove Important' : 'Mark as Important' ?></button>
        </form>
        <form method="post" data-no-loading>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="toggle_mute_submit" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <button class="button button-secondary" type="submit" <?= !$canUseThreadPreferences ? 'disabled' : '' ?>><?= $isThreadMuted ? 'Unmute Notifications' : 'Mute Notifications' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php lex_phishing_detector_modal(); ?>

<?php lex_page_footer(); ?>
