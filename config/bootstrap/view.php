<?php
declare(strict_types=1);

function lex_render_toasts(array $flash): void
{
    if (!$flash) {
        return;
    }

    $toastMeta = [
        'success' => ['title' => 'Success', 'role' => 'status', 'duration' => 5600],
        'error' => ['title' => 'Error', 'role' => 'alert', 'duration' => 8200],
        'danger' => ['title' => 'Error', 'role' => 'alert', 'duration' => 8200],
        'warning' => ['title' => 'Warning', 'role' => 'status', 'duration' => 7200],
        'info' => ['title' => 'Information', 'role' => 'status', 'duration' => 6200],
        'neutral' => ['title' => 'Notice', 'role' => 'status', 'duration' => 6200],
        'default' => ['title' => 'Notice', 'role' => 'status', 'duration' => 6200],
    ];

    echo '<div class="toast-stack" aria-live="polite" aria-atomic="true" data-toast-stack>';
    foreach ($flash as $item) {
        $type = strtolower(trim((string) ($item['type'] ?? 'default')));
        $type = $type === 'danger' ? 'error' : $type;
        $meta = $toastMeta[$type] ?? $toastMeta['default'];
        echo '<div class="toast toast-' . lex_e($type) . '" role="' . lex_e($meta['role']) . '" data-toast data-toast-type="' . lex_e($type) . '" data-dismiss-after="' . (int) $meta['duration'] . '">';
        echo '<span class="toast-icon" aria-hidden="true"></span>';
        echo '<span class="toast-content">';
        echo '<strong class="toast-title">' . lex_e($meta['title']) . '</strong>';
        echo '<span class="toast-message">' . lex_e((string) ($item['message'] ?? '')) . '</span>';
        echo '</span>';
        echo '<button class="toast-close" type="button" aria-label="Dismiss notification">&times;</button>';
        echo '<span class="toast-progress" aria-hidden="true"></span>';
        echo '</div>';
    }
    echo '</div>';
}

function lex_render_notification_bell(array $user): void
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $state = lex_notifications_for_user($userId, 8);
    $items = $state['items'];
    $unreadCount = (int) $state['unread_count'];
    $badge = $unreadCount > 99 ? '99+' : (string) $unreadCount;
    $currentUri = $_SERVER['REQUEST_URI'] ?? lex_app_url('index.php');
    $bellIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21.25a2.35 2.35 0 0 0 2.28-1.8H9.72a2.35 2.35 0 0 0 2.28 1.8Zm6.45-5.15-.95-.94V10a5.5 5.5 0 0 0-4.25-5.35V4a1.25 1.25 0 1 0-2.5 0v.65A5.5 5.5 0 0 0 6.5 10v5.16l-.95.94a1.45 1.45 0 0 0 1.02 2.47h10.86a1.45 1.45 0 0 0 1.02-2.47Z" fill="currentColor"/></svg>';

    echo '<details class="notification-menu">';
    echo '<summary class="icon-button notification-trigger" aria-label="Open notifications" title="Notifications">';
    echo $bellIcon;
    if ($unreadCount > 0) {
        echo '<span class="notification-badge">' . lex_e($badge) . '</span>';
    }
    echo '</summary>';
    echo '<div class="notification-panel">';
    echo '<div class="notification-panel-head"><strong>Notifications</strong>';
    if ($unreadCount > 0) {
        echo '<form method="post" action="' . lex_e(lex_app_url('notifications.php')) . '">';
        echo lex_csrf_field();
        echo '<input type="hidden" name="action" value="mark_notifications_read">';
        echo '<input type="hidden" name="return_to" value="' . lex_e($currentUri) . '">';
        echo '<button type="submit">Mark all read</button>';
        echo '</form>';
    }
    echo '</div>';
    if (!$items) {
        echo '<div class="notification-empty">No notifications yet.</div>';
    } else {
        echo '<div class="notification-list">';
        foreach ($items as $item) {
            $isUnread = (int) ($item['is_read'] ?? 0) === 0;
            $type = preg_replace('/[^a-z0-9_-]+/i', '', strtolower((string) ($item['type'] ?? 'notice'))) ?: 'notice';
            $timeLabel = '';
            if (!empty($item['created_at'])) {
                $timestamp = strtotime((string) $item['created_at']);
                $timeLabel = $timestamp ? date('M j, g:i A', $timestamp) : (string) $item['created_at'];
            }
            echo '<article class="notification-item' . ($isUnread ? ' is-unread' : '') . '">';
            echo '<span class="notification-dot notification-dot--' . lex_e($type) . '" aria-hidden="true"></span>';
            echo '<div><p>' . lex_e((string) ($item['message'] ?? 'Notification')) . '</p>';
            if ($timeLabel !== '') {
                echo '<time datetime="' . lex_e((string) ($item['created_at'] ?? '')) . '">' . lex_e($timeLabel) . '</time>';
            }
            echo '</div></article>';
        }
        echo '</div>';
    }
    echo '</div></details>';
}

function lex_page_header(string $title, string $active = '', ?array $user = null): void
{
    $user = $user ?? lex_current_user();
    $role = $user['role'] ?? '';
    $isLoggedIn = (bool) $user;
    $flash = lex_flash_get();
    $isWorkspaceLayout = $isLoggedIn && in_array($role, ['admin', 'lawyer', 'client'], true);
    $isDashboardLayout = $isWorkspaceLayout && in_array($role, ['lawyer', 'client'], true) && $active === 'dashboard';
    $buildInitials = static function (string $value, string $fallback): string {
        $clean = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($value));
        $parts = array_values(array_filter(explode(' ', (string) $clean)));
        if (!$parts) {
            return $fallback;
        }

        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    };
    $userFullName = (string) ($user['full_name'] ?? '');
    $userRoleLabel = ucfirst((string) $role);
    $userAvatarUrl = lex_profile_avatar_url((string) ($user['avatar_stored_name'] ?? ''));
    $userInitials = $buildInitials($userFullName, $role === 'lawyer' ? 'LW' : 'CL');
    $links = [
        'admin' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '../admin/index.php'],
            ['key' => 'messages', 'label' => 'Messages', 'href' => 'messages.php'],
            ['key' => 'inquiries', 'label' => 'Inquiries', 'href' => 'inquiries.php'],
            ['key' => 'lawyers', 'label' => 'Lawyers', 'href' => 'manage_lawyers.php'],
            ['key' => 'clients', 'label' => 'Clients', 'href' => 'manage_clients.php'],
            ['key' => 'payments', 'label' => 'Payments', 'href' => 'payments.php'],
            ['key' => 'phishing', 'label' => 'Phishing Scans', 'href' => 'phishing_scans.php'],
            ['key' => 'audit', 'label' => 'Audit Logs', 'href' => 'audit_logs.php'],
            ['key' => 'settings', 'label' => 'Settings', 'href' => 'system_settings.php'],
        ],
        'lawyer' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => lex_app_url('lawyer/index.php')],
            ['key' => 'profile', 'label' => 'Profile', 'href' => lex_app_url('lawyer/profile.php')],
            ['key' => 'case-files', 'label' => 'Case Files', 'href' => lex_app_url('case_files.php')],
            ['key' => 'appointments', 'label' => 'Appointments', 'href' => lex_app_url('lawyer/appointment.php')],
            ['key' => 'availability', 'label' => 'Availability', 'href' => lex_app_url('lawyer/availability.php')],
            ['key' => 'payments', 'label' => 'Payments', 'href' => lex_app_url('lawyer/payments.php')],
            ['key' => 'messages', 'label' => 'Messages', 'href' => lex_app_url('lawyer/messages.php')],
        ],
        'client' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => lex_app_url('client/index.php')],
            ['key' => 'lawyers', 'label' => 'Lawyers', 'href' => lex_app_url('client/lawyers.php')],
            ['key' => 'payments', 'label' => 'Payments', 'href' => lex_app_url('client/payments.php')],
            ['key' => 'billing', 'label' => 'Billing', 'href' => lex_app_url('client/billing.php')],
            ['key' => 'profile', 'label' => 'Profile', 'href' => lex_app_url('client/profile.php')],
            ['key' => 'case-files', 'label' => 'Case Files', 'href' => lex_app_url('case_files.php')],
            ['key' => 'messages', 'label' => 'Messages', 'href' => lex_app_url('client/messages.php')],
            ['key' => 'appointments', 'label' => 'Appointments', 'href' => lex_app_url('client/appointment.php')],
        ],
    ];
    $navIcons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.75 4h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 10.25v-5.5A.75.75 0 0 1 4.75 4Zm9 0h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-5.5a.75.75 0 0 1 .75-.75Zm-9 9h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5A.75.75 0 0 1 4 19.25v-5.5a.75.75 0 0 1 .75-.75Zm9 0h5.5a.75.75 0 0 1 .75.75v5.5a.75.75 0 0 1-.75.75h-5.5a.75.75 0 0 1-.75-.75v-5.5a.75.75 0 0 1 .75-.75Z" fill="currentColor"/></svg>',
        'profile' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 1.5c-4 0-7.25 2.2-7.25 4.9a.75.75 0 0 0 1.5 0c0-1.57 2.44-3.4 5.75-3.4s5.75 1.83 5.75 3.4a.75.75 0 0 0 1.5 0c0-2.7-3.25-4.9-7.25-4.9Z" fill="currentColor"/></svg>',
        'case-files' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5.75 4h4.12c.5 0 .98.2 1.33.56l1.09 1.11c.07.07.17.11.27.11h5.69A1.75 1.75 0 0 1 20 7.53v10.72A1.75 1.75 0 0 1 18.25 20h-12.5A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg>',
        'availability' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 3.75h12A2.25 2.25 0 0 1 20.25 6v12A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V6A2.25 2.25 0 0 1 6 3.75Zm0 1.5a.75.75 0 0 0-.75.75v3h13.5V6a.75.75 0 0 0-.75-.75H6Zm-.75 5.25v7.5c0 .414.336.75.75.75h12a.75.75 0 0 0 .75-.75v-7.5H5.25Zm3 2h2v2h-2v-2Zm4 0h2v2h-2v-2Z" fill="currentColor"/></svg>',
        'appointments' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Zm11.25 6h-12v8.25c0 .41.34.75.75.75h10.5c.41 0 .75-.34.75-.75V9.5Z" fill="currentColor"/></svg>',
        'messages' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v8.5A1.75 1.75 0 0 1 18.25 16H9.9l-3.68 3.08A.75.75 0 0 1 5 18.5V16.1a1.75 1.75 0 0 1-1-1.6v-8.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg>',
        'inquiries' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v8.5A1.75 1.75 0 0 1 19.25 17H8.7l-3.45 2.68A.75.75 0 0 1 4 19.08V6.75A1.75 1.75 0 0 1 4.75 5Zm3.5 4.25a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5Zm0 3a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5Z" fill="currentColor"/></svg>',
        'lawyers' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 11a3.25 3.25 0 1 0-3.25-3.25A3.25 3.25 0 0 0 8.5 11Zm7 1.5A2.75 2.75 0 1 0 12.75 9.75 2.75 2.75 0 0 0 15.5 12.5ZM3.75 19.25c0-2.4 2.96-4.25 6.75-4.25s6.75 1.85 6.75 4.25a.75.75 0 0 1-1.5 0c0-1.33-2.13-2.75-5.25-2.75s-5.25 1.42-5.25 2.75a.75.75 0 0 1-1.5 0Zm12.68-3.97c2.34.28 4.07 1.56 4.07 3.22a.75.75 0 0 1-1.5 0c0-.73-.92-1.43-2.75-1.72a.75.75 0 1 1 .18-1.5Z" fill="currentColor"/></svg>',
        'payments' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.75 6h14.5A1.75 1.75 0 0 1 21 7.75v8.5A1.75 1.75 0 0 1 19.25 18H4.75A1.75 1.75 0 0 1 3 16.25v-8.5A1.75 1.75 0 0 1 4.75 6Zm0 2.5v1h14.5v-1Zm9.75 5.25a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5Z" fill="currentColor"/></svg>',
        'phishing' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.25 19.25 6v5.3c0 4.44-2.9 7.68-7.25 9.45-4.35-1.77-7.25-5.01-7.25-9.45V6L12 3.25Zm0 2.14L6.45 7.5v3.8c0 3.44 2.05 6.02 5.55 7.58 3.5-1.56 5.55-4.14 5.55-7.58V7.5L12 5.39Zm-.75 3.36h1.5v5.25h-1.5V8.75Zm0 6.75h1.5V17h-1.5v-1.5Z" fill="currentColor"/></svg>',
        'billing' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.75 3.5h10.5A1.75 1.75 0 0 1 19 5.25v13.5A1.75 1.75 0 0 1 17.25 20H6.75A1.75 1.75 0 0 1 5 18.75V5.25A1.75 1.75 0 0 1 6.75 3.5Zm2 4.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5Zm0 4a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5Zm0 4a.75.75 0 0 0 0 1.5h3.5a.75.75 0 0 0 0-1.5Z" fill="currentColor"/></svg>',
        'lawyers' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 11a3.25 3.25 0 1 0-3.25-3.25A3.25 3.25 0 0 0 8.5 11Zm7 1.5A2.75 2.75 0 1 0 12.75 9.75 2.75 2.75 0 0 0 15.5 12.5ZM3.75 19.25c0-2.4 2.96-4.25 6.75-4.25s6.75 1.85 6.75 4.25a.75.75 0 0 1-1.5 0c0-1.33-2.13-2.75-5.25-2.75s-5.25 1.42-5.25 2.75a.75.75 0 0 1-1.5 0Zm12.68-3.97c2.34.28 4.07 1.56 4.07 3.22a.75.75 0 0 1-1.5 0c0-.73-.92-1.43-2.75-1.72a.75.75 0 1 1 .18-1.5Z" fill="currentColor"/></svg>',
        'clients' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 12a3.5 3.5 0 1 0-3.5-3.5A3.5 3.5 0 0 0 12 12Zm-6.5 7a.75.75 0 0 1-.75-.75c0-2.55 3.18-4.75 7.25-4.75s7.25 2.2 7.25 4.75a.75.75 0 0 1-1.5 0c0-1.53-2.42-3.25-5.75-3.25S6.25 16.72 6.25 18.25A.75.75 0 0 1 5.5 19Z" fill="currentColor"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.75 4h10.5A1.75 1.75 0 0 1 19 5.75v12.5A1.75 1.75 0 0 1 17.25 20H6.75A1.75 1.75 0 0 1 5 18.25V5.75A1.75 1.75 0 0 1 6.75 4Zm2 3.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5Zm0 4a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5Zm0 4a.75.75 0 0 0 0 1.5h4a.75.75 0 0 0 0-1.5Z" fill="currentColor"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.93 3.56a1.5 1.5 0 0 1 2.14 0l.53.54a1.5 1.5 0 0 0 1.5.37l.73-.22a1.5 1.5 0 0 1 1.9 1.18l.14.75a1.5 1.5 0 0 0 1.13 1.05l.75.17a1.5 1.5 0 0 1 1.15 1.93l-.23.72a1.5 1.5 0 0 0 .37 1.5l.54.53a1.5 1.5 0 0 1 0 2.14l-.54.53a1.5 1.5 0 0 0-.37 1.5l.23.72a1.5 1.5 0 0 1-1.15 1.93l-.75.17a1.5 1.5 0 0 0-1.13 1.05l-.14.75a1.5 1.5 0 0 1-1.9 1.18l-.73-.22a1.5 1.5 0 0 0-1.5.37l-.53.54a1.5 1.5 0 0 1-2.14 0l-.53-.54a1.5 1.5 0 0 0-1.5-.37l-.73.22a1.5 1.5 0 0 1-1.9-1.18l-.14-.75a1.5 1.5 0 0 0-1.13-1.05l-.75-.17a1.5 1.5 0 0 1-1.15-1.93l.23-.72a1.5 1.5 0 0 0-.37-1.5l-.54-.53a1.5 1.5 0 0 1 0-2.14l.54-.53a1.5 1.5 0 0 0 .37-1.5l-.23-.72A1.5 1.5 0 0 1 4.25 7.4L5 7.23a1.5 1.5 0 0 0 1.13-1.05l.14-.75a1.5 1.5 0 0 1 1.9-1.18l.73.22a1.5 1.5 0 0 0 1.5-.37l.53-.54ZM12 9a3 3 0 1 0 3 3 3 3 0 0 0-3-3Z" fill="currentColor"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.75 4A1.75 1.75 0 0 0 9 5.75v2.5a.75.75 0 0 0 1.5 0v-2.5a.25.25 0 0 1 .25-.25h6.5a.25.25 0 0 1 .25.25v12.5a.25.25 0 0 1-.25.25h-6.5a.25.25 0 0 1-.25-.25v-2.5a.75.75 0 0 0-1.5 0v2.5A1.75 1.75 0 0 0 10.75 20h6.5A1.75 1.75 0 0 0 19 18.25V5.75A1.75 1.75 0 0 0 17.25 4Zm-5.22 7.47a.75.75 0 0 0 0 1.06l3 3a.75.75 0 1 0 1.06-1.06L7.88 12.75h7.37a.75.75 0 0 0 0-1.5H7.88l1.72-1.72a.75.75 0 0 0-1.06-1.06Z" fill="currentColor"/></svg>',
    ];
    $nav = $links[$role] ?? [];
    $bodyClasses = [];
    if ($isWorkspaceLayout) {
        $bodyClasses[] = 'app-workspace';
        $bodyClasses[] = 'app-workspace--' . $role;
    }
    if ($isDashboardLayout) {
        $bodyClasses[] = 'dashboard-layout';
        $bodyClasses[] = 'dashboard-layout--' . $role;
    }
    $bodyClassAttribute = $bodyClasses ? ' class="' . lex_e(implode(' ', $bodyClasses)) . '"' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . lex_e($title) . ' | ' . lex_e(LEX_APP_NAME) . '</title>';
    echo '<link rel="icon" type="image/png" href="' . lex_e(lex_asset_url('public/assets/lexshield-favicon.png')) . '">';
    echo '<link rel="shortcut icon" href="' . lex_e(lex_asset_url('public/assets/lexshield-favicon.png')) . '">';
    echo '<link rel="apple-touch-icon" href="' . lex_e(lex_asset_url('public/assets/lexshield-favicon.png')) . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . lex_e(lex_asset_url('public/css/style.css')) . '">';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/base.js')) . '"></script>';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/case-files.js')) . '"></script>';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/messages-ui.js')) . '"></script>';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/main.js')) . '"></script>';
    echo '</head><body' . $bodyClassAttribute . ' data-api-base="' . lex_e(lex_api_url()) . '" data-role="' . lex_e($role) . '">';
    echo '<a class="skip-link" href="#main">Skip to content</a>';
    echo '<div class="app-shell">';
    if ($isLoggedIn && $role) {
        echo '<aside class="sidebar" id="sidebar" aria-label="Primary">';
        echo '<div class="brand-block"><div class="brand-mark"><img src="' . lex_e(lex_asset_url('public/assets/lexshield-logo.png')) . '" alt="' . lex_e(LEX_APP_NAME) . ' logo"></div><div class="brand-copy"><strong class="brand-wordmark">LEX<span>SHIELD</span></strong><span class="brand-tagline">Secure legal compliance</span></div></div>';
        echo '<nav class="sidebar-nav">';
        foreach ($nav as $item) {
            $activeClass = $active === $item['key'] ? ' active' : '';
            $icon = $navIcons[$item['key']] ?? $navIcons['dashboard'];
            $iconHtml = $isWorkspaceLayout ? '<span class="nav-link-icon" aria-hidden="true">' . $icon . '</span>' : '';
            $labelHtml = $isWorkspaceLayout ? '<span class="nav-link-label">' . lex_e($item['label']) . '</span>' : lex_e($item['label']);
            echo '<a class="nav-link' . $activeClass . '" href="' . lex_e($item['href']) . '">' . $iconHtml . $labelHtml . '</a>';
        }
        echo '</nav>';
        $logoutIcon = $isWorkspaceLayout ? '<span class="nav-link-icon" aria-hidden="true">' . $navIcons['logout'] . '</span>' : '';
        $logoutLabel = $isWorkspaceLayout ? '<span class="nav-link-label">Logout</span>' : 'Logout';
        echo '<div class="sidebar-footer"><a class="nav-link" href="' . lex_e(lex_app_url('auth/logout.php')) . '">' . $logoutIcon . $logoutLabel . '</a></div>';
        echo '</aside>';
    }
    echo '<main class="main-content" id="main">';
    echo '<header class="topbar">';
    echo '<button class="icon-button" id="sidebarToggle" type="button" aria-label="Toggle navigation">&#9776;</button>';
    echo '<div class="topbar-title"><h1>' . lex_e($title) . '</h1></div>';
    echo '<div class="topbar-actions">';
    echo '<button class="icon-button" id="themeToggle" type="button" aria-label="Toggle theme">&#9681;</button>';
    if ($user) {
        if ($isWorkspaceLayout) {
            lex_render_notification_bell($user);
        }
        if ($isWorkspaceLayout) {
            echo '<div class="dashboard-user-chip">';
            if ($userAvatarUrl !== '') {
                echo '<img class="dashboard-user-avatar" src="' . lex_e($userAvatarUrl) . '" alt="Avatar for ' . lex_e($userFullName) . '">';
            } else {
                echo '<span class="dashboard-user-avatar dashboard-user-avatar--fallback" aria-hidden="true">' . lex_e($userInitials) . '</span>';
            }
            echo '<div class="dashboard-user-meta"><strong>' . lex_e($userFullName) . '</strong><span>' . lex_e($userRoleLabel) . '</span></div>';
            echo '</div>';
        } else {
            echo '<div class="user-chip"><strong>' . lex_e($userFullName) . '</strong><span>' . lex_e($userRoleLabel) . '</span></div>';
        }
    } else {
        echo '<a class="button button-primary" href="' . lex_e(lex_app_url('auth/login.php')) . '">Login</a>';
    }
    echo '</div></header>';
    lex_render_toasts($flash);
}

function lex_page_footer(): void
{
    echo '</main></div></body></html>';
}

function lex_auth_page_header(string $title): void
{
    $flash = lex_flash_get();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . lex_e($title) . ' | ' . lex_e(LEX_APP_NAME) . '</title>';
    echo '<link rel="icon" type="image/png" href="' . lex_e(lex_asset_url('public/assets/lexshield-favicon.png')) . '">';
    echo '<link rel="shortcut icon" href="' . lex_e(lex_asset_url('public/assets/lexshield-favicon.png')) . '">';
    echo '<link rel="apple-touch-icon" href="' . lex_e(lex_asset_url('public/assets/lexshield-favicon.png')) . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . lex_e(lex_asset_url('public/css/style.css')) . '">';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/base.js')) . '"></script>';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/case-files.js')) . '"></script>';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/messages-ui.js')) . '"></script>';
    echo '<script defer src="' . lex_e(lex_asset_url('public/js/main.js')) . '"></script>';
    echo '</head><body class="auth-page" data-api-base="' . lex_e(lex_api_url()) . '">';
    echo '<button class="icon-button auth-theme-toggle" id="themeToggle" type="button" aria-label="Toggle theme">&#9681;</button>';
    lex_render_toasts($flash);
    echo '<main class="auth-shell">';
}

function lex_auth_page_footer(): void
{
    echo '</main></body></html>';
}
