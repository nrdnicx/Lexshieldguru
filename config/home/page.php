<?php

$user = lex_current_user();
$searchQuery = trim(lex_sanitize_text($_GET['q'] ?? ''));
$selectedSpecialization = trim(lex_sanitize_text($_GET['specialization'] ?? ''));
$inquiryStatus = (string) ($_SESSION['quick_inquiry_status'] ?? '');
$inquiryValues = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'topic' => '',
    'message' => '',
];
unset($_SESSION['quick_inquiry_status']);

function lex_quick_inquiry_admin_recipient(): string
{
    $smtpUser = lex_site_setting('smtp_user');
    if (filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        return $smtpUser;
    }

    $admins = lex_recent('SELECT email FROM users WHERE role = "admin" AND is_active = 1 ORDER BY id ASC');
    foreach ($admins as $admin) {
        $email = (string) ($admin['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return '';
}

function lex_quick_inquiry_notify_admin(array $inquiry): void
{
    $recipient = lex_quick_inquiry_admin_recipient();
    if ($recipient === '') {
        return;
    }

    $body = "A new quick inquiry was submitted on LEXSHIELD.\n\n"
        . "Name: " . (string) $inquiry['full_name'] . "\n"
        . "Email: " . (string) $inquiry['email'] . "\n"
        . "Phone: " . ((string) $inquiry['phone'] !== '' ? (string) $inquiry['phone'] : 'Not provided') . "\n"
        . "Topic: " . (string) $inquiry['topic'] . "\n\n"
        . "Message:\n" . (string) $inquiry['message'] . "\n\n"
        . "Open the admin Inquiries page to review it.";

    lex_send_email($recipient, 'New LEXSHIELD quick inquiry', $body);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'quick_inquiry') {
    $inquiryValues = [
        'first_name' => lex_sanitize_text($_POST['first_name'] ?? ''),
        'last_name' => lex_sanitize_text($_POST['last_name'] ?? ''),
        'email' => lex_sanitize_email($_POST['email'] ?? ''),
        'phone' => lex_sanitize_text($_POST['phone'] ?? ''),
        'topic' => lex_sanitize_text($_POST['topic'] ?? ''),
        'message' => trim((string) ($_POST['message'] ?? '')),
    ];
    $allowedTopics = ['Family Law', 'Corporate Law', 'Labor Law', 'Criminal Defense', 'General Inquiry'];
    if (!in_array($inquiryValues['topic'], $allowedTopics, true)) {
        $inquiryValues['topic'] = 'General Inquiry';
    }

    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('home/quick_inquiry');
        $inquiryStatus = 'Invalid CSRF token. Please try again.';
    } else {
        $limit = lex_rate_limit_hit(
            'quick_inquiry',
            lex_rate_limit_key(lex_rate_limit_client_ip(), $inquiryValues['email']),
            3,
            900,
            900,
            $inquiryValues['email'] !== '' ? $inquiryValues['email'] : lex_rate_limit_client_ip()
        );
        if (!$limit['allowed']) {
            $inquiryStatus = lex_rate_limit_message((int) $limit['retry_after']);
        } elseif ($inquiryValues['first_name'] === '' || $inquiryValues['last_name'] === '' || $inquiryValues['email'] === '' || $inquiryValues['message'] === '') {
            $inquiryStatus = 'Please complete your name, email, and message.';
        } elseif (!filter_var($inquiryValues['email'], FILTER_VALIDATE_EMAIL)) {
            $inquiryStatus = 'Please enter a valid email address.';
        } else {
            $fullName = trim($inquiryValues['first_name'] . ' ' . $inquiryValues['last_name']);
            $stmt = lex_pdo()->prepare(
                'INSERT INTO quick_inquiries (full_name, email, phone, topic, message, status, ip_address, user_agent)
                 VALUES (:full_name, :email, :phone, :topic, :message, "new", :ip_address, :user_agent)'
            );
            $stmt->execute([
                'full_name' => $fullName,
                'email' => $inquiryValues['email'],
                'phone' => $inquiryValues['phone'],
                'topic' => $inquiryValues['topic'],
                'message' => $inquiryValues['message'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $inquiryId = (int) lex_pdo()->lastInsertId();
            lex_audit('submit_quick_inquiry', 'quick_inquiries', (string) $inquiryId);
            lex_quick_inquiry_notify_admin([
                'full_name' => $fullName,
                'email' => $inquiryValues['email'],
                'phone' => $inquiryValues['phone'],
                'topic' => $inquiryValues['topic'],
                'message' => $inquiryValues['message'],
            ]);
            $_SESSION['quick_inquiry_status'] = 'sent';
            header('Location: ' . lex_app_url('index.php?inquiry=sent#contact'));
            exit;
        }
    }
}

$specializations = lex_recent(
    'SELECT DISTINCT l.specialization
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status IN ("active", "busy")
       AND l.specialization <> ""
     ORDER BY l.specialization ASC'
);

$heroStats = lex_recent(
    'SELECT
        (SELECT COUNT(*) FROM lawyers l2 JOIN users u2 ON u2.id = l2.user_id WHERE u2.is_active = 1 AND l2.status = "active") AS active_lawyers,
        COALESCE((SELECT AVG(rating) FROM lawyer_reviews), 0) AS avg_rating,
        COALESCE((SELECT COUNT(*) FROM lawyer_reviews), 0) AS review_count'
);
$heroStats = $heroStats[0] ?? ['active_lawyers' => 0, 'avg_rating' => 0, 'review_count' => 0];

$lawyerSql = 'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.avatar_stored_name, u.created_at,
        COALESCE(stats.avg_rating, 0) AS avg_rating,
        COALESCE(stats.review_count, 0) AS review_count
 FROM lawyers l
 JOIN users u ON u.id = l.user_id
 LEFT JOIN (
    SELECT lawyer_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
    FROM lawyer_reviews
    GROUP BY lawyer_id
 ) stats ON stats.lawyer_id = l.id
 WHERE u.is_active = 1
   AND l.status IN ("active", "busy")';
$lawyerParams = [];
if ($selectedSpecialization !== '') {
    $lawyerSql .= ' AND l.specialization = :specialization';
    $lawyerParams['specialization'] = $selectedSpecialization;
}
$searchTerm = function_exists('mb_strtolower') ? mb_strtolower($searchQuery) : strtolower($searchQuery);
if ($searchTerm !== '') {
    $tokens = preg_split('/\s+/u', $searchTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

    if ($tokens) {
        $searchFields = [
            'LOWER(u.full_name)',
            'LOWER(l.specialization)',
            'LOWER(l.bar_number)',
            'LOWER(COALESCE(l.background, ""))',
            'LOWER(COALESCE(l.bio, ""))',
        ];
        $searchClauses = [];

        foreach ($tokens as $index => $token) {
            $fieldClauses = [];
            foreach ($searchFields as $fieldIndex => $field) {
                $placeholder = ':search_term_' . $index . '_' . $fieldIndex;
                $fieldClauses[] = $field . ' LIKE ' . $placeholder;
                $lawyerParams['search_term_' . $index . '_' . $fieldIndex] = '%' . $token . '%';
            }
            $searchClauses[] = '(' . implode(' OR ', $fieldClauses) . ')';
        }

        $lawyerSql .= ' AND (' . implode(' AND ', $searchClauses) . ')';
    }
}
$lawyerSql .= ' ORDER BY u.full_name ASC';
$lawyers = lex_recent($lawyerSql, $lawyerParams);
$visibleLawyerCount = count($lawyers);
$lawyersPerPage = 8;
$totalLawyerPages = max(1, (int) ceil($visibleLawyerCount / $lawyersPerPage));
$currentLawyerPage = max(1, min($totalLawyerPages, (int) ($_GET['lawyer_page'] ?? 1)));
$pagedLawyers = array_slice($lawyers, ($currentLawyerPage - 1) * $lawyersPerPage, $lawyersPerPage);
$directoryPageUrl = static function (int $page): string {
    $query = $_GET;
    if ($page <= 1) {
        unset($query['lawyer_page']);
    } else {
        $query['lawyer_page'] = $page;
    }
    $queryString = http_build_query($query);
    return lex_app_url('index.php' . ($queryString !== '' ? '?' . $queryString : '') . '#directory');
};

$portalLink = $user ? lex_app_url($user['role'] . '/index.php') : lex_app_url('auth/login.php');
$appointLink = $user && ($user['role'] === 'client')
    ? lex_app_url('client/appointment.php')
    : lex_app_url('auth/register.php');
$clearFiltersLink = lex_app_url('index.php#directory');
$logoAsset = lex_asset_url('public/assets/lexshield-logo.png');
$faviconAsset = lex_asset_url('public/assets/lexshield-favicon.png');

$topLawyers = array_slice($lawyers, 0, 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LEXSHIELD | Legal Services and Secure Client Collaboration</title>
  <link rel="icon" type="image/png" href="<?= lex_e($faviconAsset) ?>">
  <link rel="shortcut icon" href="<?= lex_e($faviconAsset) ?>">
  <link rel="apple-touch-icon" href="<?= lex_e($faviconAsset) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #081321;
      --bg-soft: #0d1d33;
      --panel: rgba(14, 30, 53, 0.82);
      --panel-strong: #122542;
      --card: rgba(17, 34, 60, 0.82);
      --line: rgba(255, 255, 255, 0.08);
      --line-strong: rgba(201, 168, 76, 0.28);
      --text: #f4efe5;
      --muted: #99abc9;
      --muted-soft: #6f84a6;
      --gold: #c9a84c;
      --gold-light: #ebcf7a;
      --gold-soft: rgba(201, 168, 76, 0.14);
      --green: #4bc692;
      --blue: #5b8ef0;
      --shadow: 0 24px 60px rgba(0, 0, 0, 0.34);
      --radius-sm: 14px;
      --radius-md: 22px;
      --radius-lg: 30px;
      --container: 1240px;
    }

    * {
      box-sizing: border-box;
    }

    html {
      scroll-behavior: smooth;
    }

    #home,
    section[id],
    footer[id],
    .anchor-alias {
      scroll-margin-top: 132px;
    }

    .anchor-alias {
      display: block;
      position: relative;
      top: -1px;
      height: 1px;
      overflow: hidden;
    }

    body {
      margin: 0;
      font-family: "DM Sans", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at 15% 15%, rgba(91, 142, 240, 0.16), transparent 28%),
        radial-gradient(circle at 85% 70%, rgba(201, 168, 76, 0.14), transparent 24%),
        linear-gradient(180deg, #07101d 0%, #091728 30%, #081321 100%);
      min-height: 100vh;
    }

    body::before,
    body::after {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
    }

    body::before {
      background:
        linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
      background-size: 56px 56px;
      mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.85), transparent);
    }

    body::after {
      background: radial-gradient(circle at center, rgba(8, 19, 33, 0) 0%, rgba(8, 19, 33, 0.45) 72%, rgba(8, 19, 33, 0.8) 100%);
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    img {
      display: block;
      max-width: 100%;
    }

    .page-shell,
    .site-nav {
      position: relative;
      z-index: 1;
    }

    .container {
      width: min(var(--container), calc(100% - 32px));
      margin: 0 auto;
    }

    .site-nav {
      position: sticky;
      top: 0;
      left: 0;
      right: 0;
      width: 100%;
      backdrop-filter: blur(18px);
      background: rgba(8, 19, 33, 0.78);
      border-bottom: 1px solid var(--line);
      z-index: 1000;
    }

    .site-nav__inner {
      min-height: 76px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
    }

    .brand {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      font-weight: 700;
      letter-spacing: 0.04em;
    }

    .brand__mark {
      width: 44px;
      height: 44px;
      border-radius: 14px;
      overflow: hidden;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.06);
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
      flex: 0 0 44px;
    }

    .brand__mark img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: cover;
    }

    .brand__text span {
      color: var(--gold);
    }

    .nav-links,
    .nav-actions {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .nav-toggle {
      display: none;
      width: 46px;
      height: 46px;
      padding: 0;
      border-radius: 14px;
      border: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.04);
      color: var(--text);
      cursor: pointer;
      align-items: center;
      justify-content: center;
      transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }

    .nav-toggle:hover {
      background: rgba(255, 255, 255, 0.07);
      border-color: var(--line-strong);
      transform: translateY(-1px);
    }

    .nav-toggle__box {
      width: 18px;
      height: 14px;
      position: relative;
      display: block;
    }

    .nav-toggle__box span {
      position: absolute;
      left: 0;
      width: 100%;
      height: 2px;
      border-radius: 999px;
      background: currentColor;
      transition: transform 0.2s ease, opacity 0.2s ease, top 0.2s ease;
    }

    .nav-toggle__box span:nth-child(1) { top: 0; }
    .nav-toggle__box span:nth-child(2) { top: 6px; }
    .nav-toggle__box span:nth-child(3) { top: 12px; }

    .site-nav.is-menu-open .nav-toggle__box span:nth-child(1) {
      top: 6px;
      transform: rotate(45deg);
    }

    .site-nav.is-menu-open .nav-toggle__box span:nth-child(2) {
      opacity: 0;
    }

    .site-nav.is-menu-open .nav-toggle__box span:nth-child(3) {
      top: 6px;
      transform: rotate(-45deg);
    }

    .nav-links a {
      position: relative;
      display: inline-flex;
      align-items: center;
      color: var(--muted);
      font-size: 0.94rem;
      padding: 8px 10px;
      border-radius: 999px;
      transform: translateY(0);
      transition: color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }

    .nav-links a:hover {
      color: var(--text);
      transform: translateY(-1px);
    }

    .nav-links a::after {
      content: "";
      position: absolute;
      left: 12px;
      right: 12px;
      bottom: 4px;
      height: 2px;
      border-radius: 999px;
      background: linear-gradient(90deg, var(--gold), var(--gold-light));
      transform: scaleX(0);
      transform-origin: center;
      opacity: 0;
      transition: transform 0.22s ease, opacity 0.22s ease;
    }

    .nav-links a.is-active {
      color: var(--gold-light);
      background: rgba(201, 168, 76, 0.12);
      box-shadow: inset 0 0 0 1px var(--line-strong), 0 0 18px rgba(201, 168, 76, 0.12);
      text-shadow: 0 0 14px rgba(235, 207, 122, 0.18);
      transform: translateY(-1px);
    }

    .nav-links a.is-active::after {
      transform: scaleX(1);
      opacity: 1;
    }

    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.55rem;
      min-height: 46px;
      padding: 0 20px;
      border-radius: 999px;
      border: 1px solid transparent;
      font: inherit;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .button:hover {
      transform: translateY(-1px);
    }

    .button.is-disabled,
    .button[aria-disabled="true"] {
      pointer-events: none;
      opacity: 0.58;
      box-shadow: none;
      transform: none;
      cursor: default;
    }

    .button--gold {
      background: linear-gradient(135deg, var(--gold-light), var(--gold));
      color: #091321;
      box-shadow: 0 14px 30px rgba(201, 168, 76, 0.22);
    }

    .button__spinner {
      width: 1rem;
      height: 1rem;
      border-radius: 999px;
      border: 2px solid rgba(9, 19, 33, 0.26);
      border-top-color: #091321;
      display: none;
      flex: 0 0 auto;
      animation: inquiry-send-spin 0.72s linear infinite;
    }

    .button.is-sending {
      pointer-events: none;
      cursor: progress;
      transform: none;
    }

    .button.is-sending .button__spinner {
      display: inline-block;
    }

    .button__success-icon {
      width: 1rem;
      height: 1rem;
      display: none;
      flex: 0 0 auto;
    }

    .button.is-sent .button__success-icon {
      display: inline-block;
    }

    @keyframes inquiry-send-spin {
      to {
        transform: rotate(360deg);
      }
    }

    .button--ghost {
      background: rgba(255, 255, 255, 0.02);
      border-color: var(--line-strong);
      color: var(--gold);
    }

    .button--soft {
      background: rgba(255, 255, 255, 0.05);
      border-color: var(--line);
      color: var(--text);
    }

    .hero {
      padding: 54px 0 34px;
    }

    .hero__grid {
      display: grid;
      grid-template-columns: minmax(0, 1.05fr) minmax(340px, 0.95fr);
      gap: 32px;
      align-items: center;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 16px;
      border-radius: 999px;
      margin-bottom: 22px;
      border: 1px solid var(--line-strong);
      background: var(--gold-soft);
      color: var(--gold-light);
      font-size: 0.78rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
    }

    .eyebrow__dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--gold);
      box-shadow: 0 0 0 6px rgba(201, 168, 76, 0.12);
    }

    .hero h1,
    .section-title,
    .cta-panel h2 {
      margin: 0;
      font-family: "Cormorant Garamond", serif;
      font-weight: 600;
      line-height: 0.98;
      letter-spacing: -0.03em;
    }

    .hero h1 {
      font-size: clamp(3rem, 7vw, 5.3rem);
      max-width: 10ch;
    }

    .hero h1 .accent {
      color: var(--gold-light);
    }

    .hero__lead {
      margin: 22px 0 0;
      max-width: 58ch;
      color: var(--muted);
      font-size: 1.02rem;
      line-height: 1.8;
    }

    .hero__actions {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      margin-top: 32px;
    }

    .stat-row {
      margin-top: 34px;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }

    .stat-card,
    .glass-card,
    .lawyer-card,
    .trust-strip,
    .search-panel,
    .contact-panel,
    .cta-panel {
      border: 1px solid var(--line);
      background: var(--panel);
      backdrop-filter: blur(18px);
      box-shadow: var(--shadow);
    }

    .stat-card {
      padding: 18px 20px;
      border-radius: var(--radius-md);
    }

    .stat-card span {
      display: block;
      color: var(--muted-soft);
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .stat-card strong {
      display: block;
      margin-top: 8px;
      font-size: 1.8rem;
      font-family: "Cormorant Garamond", serif;
      color: var(--text);
    }

    .glass-card {
      border-radius: var(--radius-lg);
      padding: 26px;
      position: relative;
      overflow: hidden;
    }

    .glass-card::before {
      content: "";
      position: absolute;
      inset: auto -10% 62% 30%;
      height: 220px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(201, 168, 76, 0.18), transparent 68%);
      pointer-events: none;
    }

    .dashboard-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 20px;
    }

    .overview-visual {
      margin-bottom: 18px;
      border-radius: 24px;
      overflow: hidden;
      border: 1px solid rgba(108, 163, 255, 0.26);
      background:
        radial-gradient(circle at top, rgba(69, 186, 255, 0.24), transparent 54%),
        rgba(3, 16, 42, 0.92);
      box-shadow: 0 22px 48px rgba(0, 10, 28, 0.28);
    }

    .overview-visual img {
      display: block;
      width: 100%;
      height: auto;
      object-fit: cover;
    }

    .dashboard-top small,
    .metric-card span,
    .lawyer-card__meta,
    .section-copy,
    .section-kicker,
    .trust-item,
    .contact-item span,
    .footer-copy {
      color: var(--muted);
    }

    .status-pill,
    .tag,
    .results-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 600;
    }

    .status-pill {
      background: rgba(75, 198, 146, 0.12);
      border: 1px solid rgba(75, 198, 146, 0.24);
      color: var(--green);
    }

    .status-pill::before {
      content: "";
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: currentColor;
    }

    .dashboard-metrics {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 16px;
    }

    .metric-card {
      padding: 16px;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid var(--line);
    }

    .metric-card strong {
      display: block;
      margin-top: 8px;
      font-size: 1.55rem;
      color: var(--text);
      font-family: "Cormorant Garamond", serif;
    }

    .progress-panel {
      padding: 18px;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--line);
    }

    .progress-row + .progress-row {
      margin-top: 14px;
    }

    .progress-meta {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 8px;
      color: var(--muted);
      font-size: 0.84rem;
    }

    .progress-bar {
      height: 8px;
      border-radius: 999px;
      overflow: hidden;
      background: rgba(255, 255, 255, 0.08);
    }

    .progress-bar span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, var(--gold), var(--gold-light));
    }

    .trust-strip {
      margin-top: 34px;
      border-radius: var(--radius-md);
      padding: 18px 24px;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
    }

    .trust-item {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.92rem;
    }

    .trust-item strong {
      color: var(--text);
      display: block;
      font-size: 0.94rem;
      margin-bottom: 2px;
    }

    .trust-icon {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      flex: 0 0 auto;
      display: grid;
      place-items: center;
      background: var(--gold-soft);
      border: 1px solid var(--line-strong);
      color: var(--gold-light);
      font-size: 1rem;
    }

    .trust-icon svg,
    .feature-icon svg {
      width: 20px;
      height: 20px;
      stroke: currentColor;
      fill: none;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .section {
      padding: 82px 0 0;
    }

    .section-head {
      max-width: 740px;
      margin-bottom: 28px;
    }

    .section-kicker {
      margin: 0 0 10px;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      color: var(--gold);
    }

    .section-title {
      font-size: clamp(2.5rem, 5vw, 4rem);
    }

    .section-copy {
      margin: 16px 0 0;
      font-size: 1rem;
      line-height: 1.8;
    }

    .search-panel {
      border-radius: var(--radius-lg);
      padding: 24px;
    }

    .search-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }

    .results-pill {
      border: 1px solid var(--line-strong);
      background: var(--gold-soft);
      color: var(--gold-light);
    }

    .search-form {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(220px, 0.7fr) auto;
      gap: 14px;
    }

    .field {
      display: grid;
      gap: 8px;
    }

    .field label {
      color: var(--muted);
      font-size: 0.82rem;
    }

    .field input,
    .field select,
    .contact-form input,
    .contact-form select,
    .contact-form textarea {
      width: 100%;
      min-height: 50px;
      border-radius: 16px;
      border: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.05);
      color: var(--text);
      padding: 0 16px;
      font: inherit;
      outline: none;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .field select,
    .contact-form select {
      appearance: auto;
      background-color: rgba(255, 255, 255, 0.1);
      color: #fff7e5;
      font-weight: 600;
    }

    .field select option,
    .contact-form select option {
      background: #ffffff;
      color: #0a1628;
      font-weight: 500;
    }

    .contact-form select:invalid {
      color: #fff7e5;
      font-weight: 600;
    }

    .field input:focus,
    .field select:focus,
    .contact-form input:focus,
    .contact-form select:focus,
    .contact-form textarea:focus {
      border-color: var(--line-strong);
      box-shadow: 0 0 0 4px rgba(201, 168, 76, 0.1);
      background: rgba(255, 255, 255, 0.07);
    }

    .field input::placeholder,
    .contact-form input::placeholder,
    .contact-form textarea::placeholder {
      color: var(--muted-soft);
    }

    .search-actions {
      display: flex;
      align-items: end;
      gap: 12px;
      flex-wrap: wrap;
    }

    .lawyer-grid {
      margin-top: 26px;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
    }

    .directory-pagination {
      margin-top: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
      color: var(--muted);
      font-size: 0.92rem;
    }

    .directory-pagination__links {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .directory-pagination__link {
      min-width: 38px;
      min-height: 38px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 12px;
      border-radius: 999px;
      border: 1px solid var(--line);
      color: var(--text);
      background: rgba(255, 255, 255, 0.04);
      font-weight: 700;
      text-decoration: none;
      transition: border-color 0.2s ease, background 0.2s ease, color 0.2s ease;
    }

    .directory-pagination__link:hover,
    .directory-pagination__link.is-active {
      border-color: var(--line-strong);
      background: var(--gold);
      color: #101826;
    }

    .directory-pagination__link.is-disabled {
      opacity: 0.45;
      pointer-events: none;
    }

    .lawyer-card {
      padding: 0;
      overflow: hidden;
      border-radius: 8px;
      background: #0d1d33;
      border: 1px solid rgba(255, 255, 255, 0.18);
      transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .lawyer-card:hover {
      transform: translateY(-4px);
      border-color: var(--line-strong);
      box-shadow: 0 28px 70px rgba(0, 0, 0, 0.38);
    }

    .lawyer-card__visual {
      position: relative;
      height: 174px;
      display: grid;
      place-items: center;
      overflow: hidden;
      background: #e5eef7;
    }

    .lawyer-card:nth-child(4n + 2) .lawyer-card__visual {
      background: #dff3ea;
    }

    .lawyer-card:nth-child(4n + 3) .lawyer-card__visual {
      background: #f7ecd8;
    }

    .lawyer-card:nth-child(4n + 4) .lawyer-card__visual {
      background: #ebe8fb;
    }

    .lawyer-card__visual.has-photo {
      background: #d8dee5;
    }

    .lawyer-card__photo {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .lawyer-card__person-icon {
      width: 34px;
      height: 34px;
      color: #6e95bf;
      opacity: 0.95;
    }

    .lawyer-card:nth-child(4n + 2) .lawyer-card__person-icon {
      color: #6da596;
    }

    .lawyer-card:nth-child(4n + 3) .lawyer-card__person-icon {
      color: #ad8e60;
    }

    .lawyer-card:nth-child(4n + 4) .lawyer-card__person-icon {
      color: #8d83cf;
    }

    .lawyer-card__person-icon svg {
      width: 100%;
      height: 100%;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .lawyer-card__status {
      position: absolute;
      top: 10px;
      right: 10px;
      z-index: 1;
      padding: 3px 10px;
      border-radius: 999px;
      border: 1px solid #8bd129;
      background: #f7fff0;
      color: #559400;
      font-size: 0.72rem;
      font-weight: 800;
      line-height: 1.2;
    }

    .lawyer-card__body {
      padding: 13px 12px 12px;
      background: #0d1d33;
    }

    .lawyer-card__top {
      display: flex;
      justify-content: space-between;
      align-items: start;
      gap: 10px;
    }

    .lawyer-card__identity {
      display: flex;
      flex-direction: column;
      gap: 0;
      min-width: 0;
    }

    .avatar {
      width: 100%;
      height: 100%;
      border-radius: 0;
      overflow: hidden;
      display: grid;
      place-items: center;
      background: transparent;
      border: 0;
      color: inherit;
    }

    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .lawyer-card h3 {
      margin: 0;
      color: #f8fafc;
      font-size: 0.95rem;
      font-family: "DM Sans", sans-serif;
      font-weight: 800;
      line-height: 1.15;
    }

    .lawyer-card__specialization {
      margin-top: 4px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      color: #d7dde7;
      font-size: 0.78rem;
      font-weight: 500;
    }

    .lawyer-card__specialization svg {
      width: 12px;
      height: 12px;
      flex: 0 0 auto;
      color: var(--gold-light);
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .lawyer-card__meta {
      margin-top: 8px;
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      font-size: 0.72rem;
      color: #d4dae2;
    }

    .lawyer-card__meta span {
      display: inline-flex;
      align-items: center;
      min-height: 20px;
      padding: 2px 7px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.08);
    }

    .lawyer-card__summary {
      display: none;
    }

    .rating-row {
      margin-top: 7px;
      display: flex;
      align-items: center;
      gap: 5px;
      flex-wrap: wrap;
      color: #d9dee8;
      font-size: 0.76rem;
    }

    .stars {
      letter-spacing: 0;
      color: var(--gold);
      font-size: 0.78rem;
    }

    .lawyer-card__footer {
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px solid rgba(255, 255, 255, 0.11);
      display: block;
    }

    .lawyer-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 7px;
    }

    .lawyer-actions .button {
      min-height: 32px;
      padding: 0 10px;
      border-radius: 6px;
      font-size: 0.78rem;
      justify-content: center;
      width: 100%;
    }
    .empty-state {
      margin-top: 24px;
      border-radius: 28px;
      padding: 36px;
      text-align: center;
      border: 1px solid var(--line);
      background: rgba(255, 255, 255, 0.03);
    }

    .feature-grid,
    .about-grid,
    .contact-grid,
    .footer-grid {
      display: grid;
      gap: 18px;
    }

    .feature-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .feature-card {
      padding: 24px;
      border-radius: 26px;
      border: 1px solid var(--line);
      background: var(--card);
      box-shadow: var(--shadow);
    }

    .feature-icon {
      width: 48px;
      height: 48px;
      border-radius: 16px;
      display: grid;
      place-items: center;
      margin-bottom: 18px;
      background: var(--gold-soft);
      border: 1px solid var(--line-strong);
      color: var(--gold-light);
    }

    .feature-card h3,
    .about-panel h3,
    .contact-panel h3,
    .cta-panel h2 {
      margin: 0;
      font-family: "Cormorant Garamond", serif;
      font-weight: 600;
      color: var(--text);
    }

    .feature-card h3 {
      font-size: 1.45rem;
    }

    .feature-card p,
    .about-panel p,
    .contact-panel p {
      margin: 12px 0 0;
      color: var(--muted);
      line-height: 1.8;
    }

    .about-grid {
      grid-template-columns: 1.1fr 0.9fr;
    }

    .about-panel {
      padding: 28px;
      border-radius: 28px;
      border: 1px solid var(--line);
      background: var(--card);
      box-shadow: var(--shadow);
    }

    .about-stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin-top: 18px;
    }

    .about-stat {
      padding: 18px;
      border-radius: 22px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid var(--line);
    }

    .about-stat strong {
      display: block;
      font-size: 1.8rem;
      font-family: "Cormorant Garamond", serif;
      color: var(--gold-light);
    }

    .about-stat span {
      display: block;
      margin-top: 6px;
      color: var(--muted);
      font-size: 0.88rem;
    }

    .contact-grid {
      grid-template-columns: 0.82fr 1.18fr;
      align-items: start;
    }

    .contact-panel,
    .cta-panel {
      border-radius: 30px;
      padding: 28px;
    }

    .contact-stack {
      display: grid;
      gap: 14px;
      margin-top: 20px;
    }

    .contact-item {
      display: flex;
      align-items: start;
      gap: 14px;
      padding: 18px;
      border-radius: 22px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid var(--line);
    }

    .contact-item strong {
      display: block;
      margin-bottom: 4px;
      color: var(--text);
    }

    .contact-form {
      display: grid;
      gap: 14px;
      margin-top: 20px;
    }

    .contact-form__split {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .contact-form textarea {
      min-height: 150px;
      padding: 14px 16px;
      resize: vertical;
    }

    .quick-inquiry-success {
      display: flex;
      align-items: flex-start;
      gap: 0.75rem;
      border-color: rgba(61, 220, 151, 0.35);
      background: rgba(61, 220, 151, 0.12);
      color: #dffbed;
    }

    .quick-inquiry-success__icon {
      width: 1.45rem;
      height: 1.45rem;
      border-radius: 999px;
      display: grid;
      place-items: center;
      flex: 0 0 auto;
      background: rgba(61, 220, 151, 0.18);
      color: #3ddc97;
    }

    .quick-inquiry-success__icon svg {
      width: 0.95rem;
      height: 0.95rem;
      display: block;
    }

    .quick-inquiry-success strong,
    .quick-inquiry-success span {
      display: block;
    }

    .quick-inquiry-success span {
      margin-top: 0.12rem;
      color: rgba(223, 251, 237, 0.78);
      font-size: 0.92rem;
    }

    .quick-inquiry-success.is-vanishing {
      opacity: 0;
      transform: translateY(-6px);
      transition: opacity 0.35s ease, transform 0.35s ease;
    }

    .cta-wrap {
      padding: 82px 0;
    }

    .cta-panel {
      text-align: center;
      background:
        radial-gradient(circle at top center, rgba(201, 168, 76, 0.18), transparent 34%),
        linear-gradient(135deg, rgba(17, 34, 60, 0.94), rgba(12, 27, 48, 0.96));
    }

    .cta-panel h2 {
      font-size: clamp(2.4rem, 4.6vw, 4rem);
    }

    .cta-panel p {
      max-width: 640px;
      margin: 16px auto 0;
      color: var(--muted);
      line-height: 1.8;
    }

    .cta-actions {
      margin-top: 28px;
      display: flex;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .site-footer {
      padding: 24px 0 44px;
      border-top: 1px solid rgba(255, 255, 255, 0.06);
    }

    .footer-grid {
      grid-template-columns: minmax(0, 1.2fr) auto auto;
      align-items: center;
      gap: 34px;
    }

    .footer-brand-block {
      display: grid;
      gap: 14px;
      min-width: 0;
    }

    .footer-copy {
      margin: 0;
      color: var(--muted);
      font-size: 0.72rem;
      line-height: 1.6;
      letter-spacing: 0.01em;
    }

    .footer-nav,
    .footer-legal {
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
      justify-content: center;
    }

    .footer-nav a,
    .footer-legal a {
      color: var(--muted);
      font-size: 0.72rem;
      letter-spacing: 0.01em;
      transition: color 0.2s ease, transform 0.2s ease;
    }

    .footer-nav a:hover,
    .footer-legal a:hover {
      color: var(--text);
      transform: translateY(-1px);
    }

    /* Theme alignment with the authenticated app surfaces. */
    .site-nav {
      background: rgba(7, 18, 37, 0.9);
      border-bottom-color: rgba(115, 146, 197, 0.14);
    }

    .brand__mark,
    .stat-card,
    .glass-card,
    .lawyer-card,
    .search-panel,
    .feature-card,
    .about-panel,
    .contact-panel,
    .cta-panel {
      border-color: rgba(70, 98, 138, 0.28);
      background: #07162a;
      box-shadow: none;
    }

    .brand__text,
    .hero h1,
    .section-title,
    .feature-card h3,
    .about-panel h3,
    .contact-panel h3,
    .cta-panel h2 {
      font-family: "DM Sans", sans-serif;
      color: #f7fbff;
      letter-spacing: 0;
    }

    .brand__text span,
    .hero h1 .accent,
    .section-kicker {
      color: #7db8ff;
    }

    .hero h1 {
      max-width: 12ch;
      font-weight: 800;
      line-height: 1.02;
    }

    .hero__lead,
    .section-copy,
    .feature-card p,
    .about-panel p,
    .contact-panel p,
    .footer-copy,
    .nav-links a {
      color: #a2afc3;
    }

    .nav-links a.is-active {
      color: #cfe4ff;
      background: rgba(45, 117, 255, 0.16);
      box-shadow: inset 0 0 0 1px rgba(125, 184, 255, 0.22);
      text-shadow: none;
    }

    .nav-links a::after {
      background: #2883ff;
    }

    .button {
      min-height: 42px;
      border-radius: 8px;
      font-weight: 800;
      box-shadow: none;
    }

    .button--gold {
      background: #2883ff;
      border-color: #2883ff;
      color: #ffffff;
    }

    .button--gold:hover,
    .button--gold:focus-visible {
      background: #0b66d8;
      border-color: #0b66d8;
      color: #ffffff;
    }

    .button--ghost,
    .button--soft {
      background: #0d1b2f;
      border-color: rgba(73, 99, 137, 0.35);
      color: #f5f7fb;
    }

    .button--ghost:hover,
    .button--ghost:focus-visible,
    .button--soft:hover,
    .button--soft:focus-visible {
      background: #132842;
      border-color: rgba(96, 165, 250, 0.34);
      color: #ffffff;
    }

    .stat-card,
    .glass-card,
    .search-panel,
    .feature-card,
    .about-panel,
    .contact-panel,
    .cta-panel,
    .contact-item,
    .about-stat,
    .progress-panel {
      border-radius: 8px;
    }

    .stat-card span,
    .field label,
    .progress-meta,
    .contact-item span,
    .about-stat span,
    .lawyer-card__meta {
      color: #98a9c2;
    }

    .stat-card strong,
    .about-stat strong,
    .metric-card strong {
      font-family: "DM Sans", sans-serif;
      color: #ffffff;
      font-weight: 800;
    }

    .overview-visual,
    .progress-panel,
    .metric-card,
    .contact-item,
    .about-stat {
      border-color: rgba(70, 98, 138, 0.22);
      background: rgba(12, 29, 54, 0.82);
      box-shadow: none;
    }

    .progress-bar span {
      background: #2883ff;
    }

    .section-kicker {
      font-weight: 800;
      letter-spacing: 0.08em;
    }

    .field input,
    .field select,
    .contact-form input,
    .contact-form select,
    .contact-form textarea {
      min-height: 44px;
      border-radius: 8px;
      border-color: rgba(73, 99, 137, 0.35);
      background: #0d1b2f;
      color: #f7fbff;
    }

    .field input:focus,
    .field select:focus,
    .contact-form input:focus,
    .contact-form select:focus,
    .contact-form textarea:focus {
      border-color: rgba(96, 165, 250, 0.48);
      box-shadow: 0 0 0 3px rgba(45, 117, 255, 0.16);
      background: #10213a;
    }

    .field input::placeholder,
    .contact-form input::placeholder,
    .contact-form textarea::placeholder {
      color: #7486a4;
    }

    .lawyer-card {
      background: #07162a;
    }

    .lawyer-card:hover {
      transform: translateY(-2px);
      border-color: rgba(96, 165, 250, 0.34);
      box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18);
    }

    .lawyer-card__body {
      background: #07162a;
    }

    .stars,
    .lawyer-card__specialization svg {
      color: #ffdb5c;
    }

    .lawyer-card__status {
      border-color: rgba(125, 184, 255, 0.24);
      background: rgba(125, 184, 255, 0.12);
      color: #9ec7ff;
    }

    .lawyer-card__status--active {
      border-color: rgba(0, 201, 134, 0.28);
      background: rgba(0, 201, 134, 0.14);
      color: #36d99b;
    }

    .lawyer-card__status--busy {
      border-color: rgba(255, 184, 77, 0.3);
      background: rgba(255, 184, 77, 0.14);
      color: #ffcc66;
    }

    .lawyer-card__status--inactive,
    .lawyer-card__status--suspended {
      border-color: rgba(255, 91, 112, 0.3);
      background: rgba(255, 91, 112, 0.14);
      color: #ff7c87;
    }

    .cta-panel {
      background:
        linear-gradient(135deg, rgba(45, 117, 255, 0.12), transparent 34%),
        #07162a;
    }

    @media (max-width: 1080px) {
      .hero__grid,
      .about-grid,
      .contact-grid,
      .feature-grid,
      .trust-strip {
        grid-template-columns: 1fr;
      }

      .lawyer-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .search-form,
      .contact-form__split {
        grid-template-columns: 1fr;
      }

      .search-actions {
        align-items: stretch;
      }

      .search-actions .button {
        width: 100%;
      }

      .directory-pagination {
        align-items: stretch;
        flex-direction: column;
      }
    }

    @media (max-width: 780px) {
      .nav-links,
      .nav-actions,
      .hero__actions,
      .lawyer-card__top,
      .lawyer-card__identity,
      .lawyer-card__footer {
        flex-direction: column;
        align-items: stretch;
      }

      .site-nav__inner {
        align-items: center;
        justify-content: space-between;
        gap: 14px;
      }

      .nav-toggle {
        display: inline-flex;
        margin-left: auto;
      }

      .nav-links,
      .nav-actions {
        width: 100%;
      }

      .nav-links {
        display: none;
        gap: 8px;
        padding-top: 4px;
      }

      .nav-actions {
        display: none;
        gap: 10px;
      }

      .site-nav.is-menu-open .nav-links,
      .site-nav.is-menu-open .nav-actions {
        display: flex;
      }

      .nav-links a {
        justify-content: flex-start;
        width: 100%;
      }

      .nav-links a::after {
        left: 10px;
        right: auto;
        width: calc(100% - 20px);
        bottom: 6px;
        transform-origin: left center;
      }

      .nav-actions .button,
      .hero__actions .button,
      .cta-actions .button {
        width: 100%;
      }

      .stat-row,
      .dashboard-metrics,
      .about-stats {
        grid-template-columns: 1fr;
      }

      .container {
        width: min(var(--container), calc(100% - 22px));
      }

      .hero {
        padding-top: 18px;
      }

      .glass-card,
      .search-panel,
      .contact-panel,
      .cta-panel {
        padding: 22px;
      }

      .hero h1 {
        font-size: clamp(2.8rem, 16vw, 4rem);
      }

      .hero__lead {
        font-size: 0.98rem;
        line-height: 1.7;
      }

      .section {
        padding-top: 64px;
      }

      .section-head {
        margin-bottom: 20px;
      }

      .section-title,
      .cta-panel h2 {
        font-size: clamp(2.1rem, 12vw, 3rem);
      }

      .section-copy,
      .feature-card p,
      .about-panel p,
      .contact-panel p,
      .cta-panel p {
        font-size: 0.96rem;
        line-height: 1.7;
      }

      .trust-strip {
        margin-top: 22px;
        padding: 16px;
        gap: 12px;
      }

      .trust-item {
        align-items: flex-start;
        font-size: 0.88rem;
      }

      .search-toolbar {
        align-items: flex-start;
        margin-bottom: 16px;
      }

      .search-form {
        gap: 12px;
      }

      .field input,
      .field select,
      .contact-form input,
      .contact-form select,
      .contact-form textarea {
        min-height: 48px;
      }

      .lawyer-card__visual {
        height: 168px;
      }

      .lawyer-card h3 {
        font-size: 0.95rem;
      }

      .lawyer-card__summary {
        display: none;
      }

      .lawyer-card__meta {
        gap: 6px;
        font-size: 0.72rem;
      }

      .rating-row {
        gap: 8px;
      }

      .feature-grid,
      .about-grid,
      .contact-grid,
      .footer-grid {
        gap: 14px;
      }

      .feature-card,
      .about-panel {
        padding: 22px;
        border-radius: 24px;
      }

      .about-stats {
        gap: 10px;
        margin-top: 14px;
      }

      .about-stat {
        padding: 16px;
        border-radius: 18px;
      }

      .contact-panel {
        border-radius: 24px;
      }

      .contact-stack,
      .contact-form {
        gap: 12px;
        margin-top: 16px;
      }

      .contact-item {
        padding: 16px;
        border-radius: 18px;
      }

      .contact-form textarea {
        min-height: 132px;
      }

      .cta-wrap {
        padding: 64px 0;
      }

      .cta-actions {
        margin-top: 22px;
        gap: 10px;
      }

      .footer-grid {
        grid-template-columns: 1fr;
        gap: 18px;
        justify-items: start;
      }

      .footer-nav,
      .footer-legal {
        justify-content: flex-start;
        gap: 16px;
      }
    }

    @media (max-width: 560px) {
      .lawyer-grid {
        grid-template-columns: 1fr;
      }
    }

    /* ================================================================
       LEXSHIELD HOME — MOBILE UX POLISH
       Phone-first adjustments without changing desktop behavior.
       ================================================================ */
    @media (max-width: 780px) {
      html body {
        overflow-x: hidden;
      }

      .site-nav {
        position: sticky;
        top: 0;
        z-index: 100;
      }

      .site-nav__inner {
        min-height: 64px;
        padding: 8px 0;
      }

      .brand__mark {
        width: 40px;
        height: 40px;
      }

      .brand__text {
        font-size: 0.92rem;
      }

      .page-shell {
        overflow: hidden;
      }

      .hero {
        padding: 14px 0 30px;
      }

      .hero__grid {
        gap: 18px;
      }

      .hero h1 {
        max-width: none;
        font-size: clamp(2.25rem, 12.5vw, 3.35rem);
        line-height: 1.02;
        letter-spacing: -0.045em;
      }

      .hero__lead {
        margin-top: 12px;
        font-size: 0.93rem;
        line-height: 1.58;
      }

      .hero__actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 9px;
        margin-top: 16px;
      }

      .hero__actions .button {
        width: 100%;
        min-height: 48px;
      }

      .stat-row {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        margin-top: 14px;
      }

      .stat-card {
        min-width: 0;
        padding: 12px 8px;
        text-align: center;
        border-radius: 12px;
      }

      .stat-card span {
        display: block;
        font-size: 0.62rem;
        line-height: 1.25;
      }

      .stat-card strong {
        margin-top: 4px;
        font-size: 1rem;
      }

      .hero > .container > .hero__grid > aside.glass-card {
        padding: 10px;
        border-radius: 14px;
      }

      .overview-visual {
        min-height: 180px;
        border-radius: 10px;
        overflow: hidden;
      }

      .overview-visual img {
        width: 100%;
        height: auto;
        max-height: 220px;
        object-fit: contain;
      }

      .progress-panel {
        margin-top: 9px;
        padding: 10px;
        border-radius: 10px;
      }

      .progress-row + .progress-row {
        margin-top: 10px;
      }

      .progress-meta {
        font-size: 0.67rem;
      }

      .section {
        padding: 44px 0;
      }

      .section-head {
        margin-bottom: 16px;
      }

      .section-kicker {
        font-size: 0.7rem;
      }

      .section-title,
      .cta-panel h2 {
        font-size: clamp(1.8rem, 9vw, 2.5rem);
        line-height: 1.08;
      }

      .section-copy,
      .feature-card p,
      .about-panel p,
      .contact-panel p,
      .cta-panel p {
        font-size: 0.9rem;
        line-height: 1.55;
      }

      #directory .search-panel {
        padding: 14px;
        border-radius: 14px;
      }

      #directory .search-form {
        gap: 9px;
      }

      #directory .field input,
      #directory .field select,
      #directory .search-form .button {
        min-height: 46px;
      }

      .lawyer-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
      }

      .lawyer-card {
        min-width: 0;
        border-radius: 14px;
        overflow: hidden;
      }

      .lawyer-card__visual {
        height: 145px;
      }

      .lawyer-card__body {
        padding: 11px;
      }

      .lawyer-card__top,
      .lawyer-card__identity,
      .lawyer-card__footer {
        gap: 7px;
      }

      .lawyer-card h3 {
        font-size: 0.78rem;
        line-height: 1.2;
      }

      .lawyer-card__specialization {
        font-size: 0.64rem;
      }

      .lawyer-card__meta {
        font-size: 0.62rem;
        gap: 4px;
      }

      .lawyer-card__status {
        font-size: 0.58rem;
        padding: 3px 6px;
      }

      .lawyer-card__actions,
      .lawyer-actions {
        width: 100%;
      }

      .lawyer-card__actions .button,
      .lawyer-actions .button {
        width: 100%;
        min-height: 40px;
        padding: 7px 8px;
        font-size: 0.68rem;
      }

      .feature-grid {
        grid-template-columns: 1fr;
        gap: 10px;
      }

      .feature-card,
      .about-panel,
      .contact-panel,
      .cta-panel {
        padding: 18px;
        border-radius: 16px;
      }

      .feature-card h3 {
        font-size: 1rem;
      }

      .feature-icon {
        width: 42px;
        height: 42px;
      }

      .about-grid,
      .contact-grid {
        gap: 10px;
      }

      .about-panel h3,
      .contact-panel h3 {
        font-size: 1.45rem !important;
        line-height: 1.12;
      }

      .about-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
      }

      .about-stat {
        padding: 12px;
        border-radius: 12px;
      }

      .about-stat strong {
        font-size: 1.1rem;
      }

      .contact-stack,
      .contact-form {
        gap: 9px;
        margin-top: 12px;
      }

      .contact-item {
        padding: 12px;
        border-radius: 12px;
        gap: 9px;
      }

      .contact-item strong {
        font-size: 0.82rem;
      }

      .contact-item span {
        font-size: 0.68rem;
        line-height: 1.4;
      }

      .contact-form__split {
        grid-template-columns: 1fr;
        gap: 9px;
      }

      .contact-form input,
      .contact-form select,
      .contact-form textarea {
        width: 100%;
        box-sizing: border-box;
        font-size: 0.88rem;
      }

      .contact-form textarea {
        min-height: 120px;
      }

      .contact-form .button {
        width: 100%;
        min-height: 48px;
      }

      .cta-wrap {
        padding: 36px 0;
      }

      .cta-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 9px;
      }

      .cta-actions .button {
        width: 100%;
        min-height: 48px;
      }

      .site-footer {
        padding: 24px 0 calc(24px + env(safe-area-inset-bottom));
      }

      .footer-grid {
        grid-template-columns: 1fr;
        gap: 14px;
      }

      .footer-nav,
      .footer-legal {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 14px;
        width: 100%;
      }

      .footer-nav a,
      .footer-legal a {
        min-height: 38px;
        display: flex;
        align-items: center;
      }
    }

    @media (max-width: 380px) {
      .container {
        width: min(var(--container), calc(100% - 18px));
      }

      .hero h1 {
        font-size: 2.15rem;
      }

      .stat-row {
        gap: 5px;
      }

      .stat-card {
        padding-inline: 5px;
      }

      .stat-card span {
        font-size: 0.57rem;
      }

      .stat-card strong {
        font-size: 0.92rem;
      }

      .lawyer-grid {
        gap: 7px;
      }

      .lawyer-card__visual {
        height: 125px;
      }

      .lawyer-card__body {
        padding: 9px;
      }

      .lawyer-card h3 {
        font-size: 0.72rem;
      }

      .lawyer-card__specialization,
      .lawyer-card__meta {
        font-size: 0.58rem;
      }
    }

  </style>
</head>
<body>
  <nav class="site-nav">
    <div class="container site-nav__inner">
      <a class="brand" href="#home">
        <span class="brand__mark"><img src="<?= lex_e($logoAsset) ?>" alt="LexShield logo"></span>
        <span class="brand__text">LEX<span>SHIELD</span></span>
      </a>

      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav-menu" aria-label="Open navigation">
        <span class="nav-toggle__box" aria-hidden="true">
          <span></span>
          <span></span>
          <span></span>
        </span>
      </button>

      <div class="nav-links" id="site-nav-menu">
        <a href="#home">Home</a>
        <a href="#directory">Lawyers</a>
        <a href="#services">Services</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
      </div>

      <div class="nav-actions">
        <a class="button button--ghost" href="<?= lex_e($portalLink) ?>"><?= $user ? 'Open Portal' : 'Login' ?></a>
        <a class="button button--gold" href="<?= lex_e($appointLink) ?>"><?= $user && $user['role'] === 'client' ? 'Book Appointment' : 'Get Started' ?></a>
      </div>
    </div>
  </nav>

  <main class="page-shell">
    <section class="hero" id="home">
      <div class="container">
        <div class="hero__grid">
          <div>

            <h1>Legal guidance with a <span class="accent">secure digital edge</span>.</h1>
            <p class="hero__lead">
              LexShield connects clients with active legal professionals through a platform built for privacy, appointment flow, and trusted communication. Search the directory, review lawyer profiles, and move into the portal when you are ready.
            </p>

            <div class="hero__actions">
              <a class="button button--gold" href="#directory">Browse lawyers</a>
              <a class="button button--soft" href="<?= lex_e($appointLink) ?>"><?= $user && $user['role'] === 'client' ? 'Start appointment' : 'Create an account' ?></a>
            </div>

            <div class="stat-row" aria-label="Platform statistics">
              <div class="stat-card">
                <span>Active lawyers</span>
                <strong><?= number_format((int) $heroStats['active_lawyers']) ?></strong>
              </div>
              <div class="stat-card">
                <span>Average rating</span>
                <strong><?= number_format((float) $heroStats['avg_rating'], 1) ?>/5</strong>
              </div>
              <div class="stat-card">
                <span>Client reviews</span>
                <strong><?= number_format((int) $heroStats['review_count']) ?></strong>
              </div>
            </div>
          </div>

          <aside class="glass-card" aria-label="Platform snapshot">
            <div class="dashboard-top">
              <div>
              
            <div class="overview-visual">
              <img src="<?= lex_e(lex_app_url('public/assets/lexshield-platform-overview.png')) ?>" alt="LexShield platform overview logo">
            </div>

            <div class="progress-panel">
              <div class="progress-row">
                <div class="progress-meta">
                  <span>Directory coverage</span>
                  <span><?= number_format($visibleLawyerCount) ?> profiles</span>
                </div>
                <div class="progress-bar"><span style="width: <?= max(18, min(100, $visibleLawyerCount * 10)) ?>%;"></span></div>
              </div>
              <div class="progress-row">
                <div class="progress-meta">
                  <span>Client trust signal</span>
                  <span><?= number_format((float) $heroStats['avg_rating'], 1) ?>/5</span>
                </div>
                <div class="progress-bar"><span style="width: <?= max(12, min(100, (int) round(((float) $heroStats['avg_rating'] / 5) * 100))) ?>%;"></span></div>
              </div>
              <div class="progress-row">
                <div class="progress-meta">
                  <span>Review participation</span>
                  <span><?= number_format((int) $heroStats['review_count']) ?> ratings</span>
                </div>
                <div class="progress-bar"><span style="width: <?= max(10, min(100, (int) $heroStats['review_count'] * 4)) ?>%;"></span></div>
              </div>
            </div>
          </aside>
        </div>

    <span class="anchor-alias" id="directiory" aria-hidden="true"></span>
    <section class="section" id="directory">
      <div class="container">
        <div class="section-head">
          <p class="section-kicker">Lawyer Directory</p>
          <h2 class="section-title">Find the right legal professional faster.</h2>
         
        </div>

        <div class="search-panel">
          <div class="search-toolbar">
            
          </div>

          <form class="search-form" method="get" action="<?= lex_e(lex_app_url('index.php#directory')) ?>">
            <div class="field">
              <label for="landing-q">Search</label>
              <input id="landing-q" type="search" name="q" value="<?= lex_e($searchQuery) ?>" placeholder="Name, specialization, bar number, or background">
            </div>

            <div class="field">
              <label for="landing-specialization">Specialization</label>
              <select id="landing-specialization" name="specialization">
                <option value="">All specializations</option>
                <?php foreach ($specializations as $specialization): ?>
                  <?php $specializationValue = (string) $specialization['specialization']; ?>
                  <option value="<?= lex_e($specializationValue) ?>"<?= $selectedSpecialization === $specializationValue ? ' selected' : '' ?>><?= lex_e($specializationValue) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="search-actions">
              <button class="button button--gold" type="submit">Search</button>
              <a class="button button--soft" href="<?= lex_e($clearFiltersLink) ?>">Clear</a>
            </div>
          </form>

          <?php if (!$lawyers): ?>
            <div class="empty-state">
              <h3 style="margin:0 0 10px;font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:600;">No lawyers match the current filter</h3>
              <p style="margin:0 auto;max-width:60ch;color:var(--muted);line-height:1.8;">
                Try broadening your search terms or clearing the specialization filter to view the full active directory.
              </p>
              <div class="hero__actions" style="justify-content:center;margin-top:24px;">
                <a class="button button--gold" href="<?= lex_e($clearFiltersLink) ?>">Reset search</a>
                <a class="button button--soft" href="<?= lex_e($appointLink) ?>">Start appointment</a>
              </div>
            </div>
          <?php else: ?>
            <div class="lawyer-grid">
              <?php foreach ($pagedLawyers as $lawyer): ?>
                <?php
                  $avatarUrl = lex_profile_avatar_url((string) ($lawyer['avatar_stored_name'] ?? ''));
                  $lawyerInitials = strtoupper(substr(preg_replace('/\s+/', '', (string) ($lawyer['full_name'] ?? 'LW')) ?: 'LW', 0, 2));
                  $avgRatingValue = (float) ($lawyer['avg_rating'] ?? 0);
                  $avgRating = number_format($avgRatingValue, 1);
                  $reviewCount = (int) ($lawyer['review_count'] ?? 0);
                  $summary = trim((string) ($lawyer['background'] ?? '')) !== ''
                      ? trim((string) $lawyer['background'])
                      : trim((string) ($lawyer['bio'] ?? ''));
                  $summary = $summary !== '' ? $summary : 'Profile details are available inside the lawyer view page for this record.';
                  $lawyerStatus = strtolower((string) ($lawyer['status'] ?? 'active'));
                  $statusLabel = $lawyerStatus === 'active' ? 'Available' : ucfirst($lawyerStatus);
                  $cardStatusLabel = ucfirst($lawyerStatus);
                  $canBookLawyer = $lawyerStatus === 'active';
                  $ratingRounded = max(0, min(5, (int) round($avgRatingValue)));
                  $lawyerName = (string) $lawyer['full_name'];
                  $barDigits = preg_replace('/\D+/', '', (string) ($lawyer['bar_number'] ?? ''));
                  $rollNumber = $barDigits !== '' ? substr(str_pad($barDigits, 4, '0', STR_PAD_LEFT), -4) : 'N/A';
                  $joinedDate = !empty($lawyer['created_at']) ? date('M Y', strtotime((string) $lawyer['created_at'])) : 'Date N/A';
                ?>
                <article class="lawyer-card" aria-labelledby="lawyer-<?= (int) $lawyer['id'] ?>">
                  <div class="lawyer-card__visual<?= $avatarUrl !== '' ? ' has-photo' : '' ?>">
                    <span class="lawyer-card__status lawyer-card__status--<?= lex_e($lawyerStatus) ?>"><?= lex_e($cardStatusLabel) ?></span>
                    <?php if ($avatarUrl !== ''): ?>
                      <img class="lawyer-card__photo" src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e($lawyerName) ?>">
                    <?php else: ?>
                      <span class="lawyer-card__person-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                          <circle cx="12" cy="8" r="3.5"></circle>
                          <path d="M5.5 20a6.5 6.5 0 0 1 13 0"></path>
                        </svg>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="lawyer-card__body">
                    <div class="lawyer-card__top">
                      <div class="lawyer-card__identity">
                        <h3 id="lawyer-<?= (int) $lawyer['id'] ?>"><?= lex_e($lawyerName) ?></h3>
                        <div class="lawyer-card__specialization">
                          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M10 6h4"></path>
                            <path d="M9 6V4h6v2"></path>
                            <rect x="4" y="6" width="16" height="14" rx="2"></rect>
                            <path d="M4 12h16"></path>
                          </svg>
                          <?= lex_e((string) $lawyer['specialization']) ?>
                        </div>
                      </div>
                    </div>

                    <p class="lawyer-card__summary"><?= lex_e($summary) ?></p>

                    <div class="rating-row">
                      <span class="stars" aria-hidden="true"><?= str_repeat('&#9733;', $ratingRounded) . str_repeat('&#9734;', 5 - $ratingRounded) ?></span>
                      <span><?= lex_e($avgRating) ?> &middot; <?= number_format($reviewCount) ?> review<?= $reviewCount === 1 ? '' : 's' ?></span>
                    </div>

                    <div class="lawyer-card__meta">
                      <span>Roll <?= lex_e($rollNumber) ?></span>
                      <span><?= lex_e($joinedDate) ?></span>
                    </div>

                    <div class="lawyer-card__footer">
                      <div class="lawyer-actions">
                        <a class="button button--soft" href="<?= lex_e(lex_app_url('lawyer/view.php?id=' . (int) $lawyer['id'])) ?>">Profile</a>
                        <?php if ($canBookLawyer): ?>
                          <a class="button button--gold" href="<?= lex_e($user && $user['role'] === 'client' ? lex_app_url('client/appointment.php?lawyer_id=' . (int) $lawyer['id']) : lex_app_url('auth/register.php')) ?>">Book &#8599;</a>
                        <?php else: ?>
                          <span class="button button--gold is-disabled" aria-disabled="true">Busy</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <?php if ($totalLawyerPages > 1): ?>
              <nav class="directory-pagination" aria-label="Lawyer directory pages">
                <span class="directory-pagination__status">
                  Showing <?= number_format((($currentLawyerPage - 1) * $lawyersPerPage) + 1) ?>-<?= number_format(min($visibleLawyerCount, $currentLawyerPage * $lawyersPerPage)) ?> of <?= number_format($visibleLawyerCount) ?> lawyers
                </span>
                <div class="directory-pagination__links">
                  <a class="directory-pagination__link<?= $currentLawyerPage <= 1 ? ' is-disabled' : '' ?>" href="<?= lex_e($directoryPageUrl(max(1, $currentLawyerPage - 1))) ?>" aria-label="Previous page">Prev</a>
                  <?php for ($page = 1; $page <= $totalLawyerPages; $page++): ?>
                    <a class="directory-pagination__link<?= $page === $currentLawyerPage ? ' is-active' : '' ?>" href="<?= lex_e($directoryPageUrl($page)) ?>"<?= $page === $currentLawyerPage ? ' aria-current="page"' : '' ?>><?= (int) $page ?></a>
                  <?php endfor; ?>
                  <a class="directory-pagination__link<?= $currentLawyerPage >= $totalLawyerPages ? ' is-disabled' : '' ?>" href="<?= lex_e($directoryPageUrl(min($totalLawyerPages, $currentLawyerPage + 1))) ?>" aria-label="Next page">Next</a>
                </div>
              </nav>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="section" id="services">
      <div class="container">
        <div class="section-head">
          <p class="section-kicker">What LexShield Delivers</p>
          <h2 class="section-title">Secure Connections for Legal Services.</h2>
          <p class="section-copy">
            Lexshield simplifies access to legal services by connecting clients with verify legal practitioners through a secure and intuitive platform build for confidence.
          </p>
        </div>

        <div class="feature-grid">
          <article class="feature-card">
            <div class="feature-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="7"></circle>
                <path d="M20 20l-3.5-3.5"></path>
                <path d="M11 8v6"></path>
                <path d="M8 11h6"></path>
              </svg>
            </div>
            <h3>Lawyer discovery</h3>
            <p>Browse a curated directory of qualified lawyers, explore their areas of expertise,professinal experience, and client feedback to make informed decisions.</p>
          </article>
          <article class="feature-card">
            <div class="feature-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <path d="M4 12h10"></path>
                <path d="M11 5l7 7-7 7"></path>
                <rect x="3" y="5" width="6" height="14" rx="2"></rect>
              </svg>
            </div>
            <h3>Secure transition</h3>
            <p>From scheduling consultations to managing legal matters, LexShield ensures a seamless and secure experience.</p>
          </article>
          <article class="feature-card">
            <div class="feature-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"></path>
                <path d="M9 12l2 2 4-4"></path>
              </svg>
            </div>
            <h3>Trust-first presentation</h3>
            <p>LexShield prioritizes transparency and trust in every interaction, ensuring clients feel confident in their legal journey.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section" id="about">
      <div class="container">
        <div class="about-grid">
          <div class="about-panel">
            <p class="section-kicker">About LexShield</p>
            <h3 style="font-size:2.35rem;">Built for legal professionalism and secure client handling.</h3>
            <p>
              LexShield is positioned as a legal platform where public discovery, professional profiles, and client onboarding work together. The landing page now reflects that more clearly with better storytelling and stronger visual hierarchy.
            </p>
            <p>
              Instead of a minimal directory shell, visitors now get a fuller brand impression before moving into the authenticated experience for appointments, communication, and ongoing legal workflows.
            </p>
          </div>

          <div class="about-panel">
            <p class="section-kicker">Platform Snapshot</p>
            <h3 style="font-size:2rem;">Live data from your project, not placeholder cards.</h3>
            <div class="about-stats">
              <div class="about-stat">
                <strong><?= number_format((int) $heroStats['active_lawyers']) ?></strong>
                <span>Active lawyers</span>
              </div>
              <div class="about-stat">
                <strong><?= number_format($visibleLawyerCount) ?></strong>
                <span>Search results shown</span>
              </div>
              <div class="about-stat">
                <strong><?= number_format((float) $heroStats['avg_rating'], 1) ?></strong>
                <span>Average rating</span>
              </div>
              <div class="about-stat">
                <strong><?= number_format((int) $heroStats['review_count']) ?></strong>
                <span>Total reviews</span>
              </div>
            </div>
            <?php if ($topLawyers): ?>
              <div style="margin-top:18px;color:var(--muted);line-height:1.8;">
                Featured names on this page come directly from your existing lawyer records, including
                <?php
                  $featuredNames = array_map(static fn (array $entry): string => (string) $entry['full_name'], $topLawyers);
                  echo lex_e(implode(', ', $featuredNames));
                ?>.
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="section" id="contact">
      <div class="container">
        <div class="contact-grid">
          <div class="contact-panel">
            <p class="section-kicker">Contact</p>
            <h3 style="font-size:2.2rem;">Need help getting started?</h3>
            <p>Use the portal for account-based actions, or contact the team through your preferred channel for general inquiries and onboarding support.</p>

            <div class="contact-stack">
              <div class="contact-item">
                <div class="trust-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                    <path d="M4 7l8 6 8-6"></path>
                  </svg>
                </div>
                <div>
                  <strong>Email support</strong>
                  <span>General inquiries and platform coordination.</span>
                </div>
              </div>
              <div class="contact-item">
                <div class="trust-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"></path>
                    <path d="M9.5 12.5l1.7 1.7 3.8-4.2"></path>
                  </svg>
                </div>
                <div>
                  <strong>Portal access</strong>
                  <span><?= $user ? 'Your dashboard is ready to open.' : 'Login or register to continue securely.' ?></span>
                </div>
              </div>
              <div class="contact-item">
                <div class="trust-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <rect x="4" y="5" width="16" height="15" rx="2"></rect>
                    <path d="M8 3v4"></path>
                    <path d="M16 3v4"></path>
                    <path d="M4 10h16"></path>
                  </svg>
                </div>
                <div>
                  <strong>Appointments</strong>
                  <span>Jump directly into appointment flow once you choose a lawyer.</span>
                </div>
              </div>
            </div>
          </div>

          <div class="contact-panel">
            <p class="section-kicker">Quick Inquiry</p>
            <h3 style="font-size:2rem;">A polished contact panel for the public page.</h3>
            <p>Send a short note and the admin team will review it from the inquiry inbox.</p>

            <?php if ($inquiryStatus === 'sent' || (string) ($_GET['inquiry'] ?? '') === 'sent'): ?>
              <div class="inline-banner quick-inquiry-success" role="status">
                <span class="quick-inquiry-success__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                  </svg>
                </span>
                <span>
                  <strong>Sent successfully</strong>
                  <span>Your inquiry was sent. The team will review it soon.</span>
                </span>
              </div>
            <?php elseif ($inquiryStatus !== ''): ?>
              <div class="inline-banner" role="alert"><?= lex_e($inquiryStatus) ?></div>
            <?php endif; ?>

            <form class="contact-form" action="<?= lex_e(lex_app_url('index.php#contact')) ?>" method="post" data-no-loading data-quick-inquiry-form<?= ($inquiryStatus === 'sent' || (string) ($_GET['inquiry'] ?? '') === 'sent') ? ' data-inquiry-sent="true"' : '' ?>>
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="quick_inquiry">
              <div class="contact-form__split">
                <input type="text" name="first_name" required placeholder="First name" value="<?= lex_e($inquiryValues['first_name']) ?>">
                <input type="text" name="last_name" required placeholder="Last name" value="<?= lex_e($inquiryValues['last_name']) ?>">
              </div>
              <div class="contact-form__split">
                <input type="email" name="email" required placeholder="Email address" value="<?= lex_e($inquiryValues['email']) ?>">
                <input type="text" name="phone" placeholder="Phone number" value="<?= lex_e($inquiryValues['phone']) ?>">
              </div>
              <select name="topic" required>
                <option value=""<?= $inquiryValues['topic'] === '' ? ' selected' : '' ?> disabled>Choose a topic</option>
                <?php foreach (['Family Law', 'Corporate Law', 'Labor Law', 'Criminal Defense', 'General Inquiry'] as $topic): ?>
                  <option value="<?= lex_e($topic) ?>"<?= $inquiryValues['topic'] === $topic ? ' selected' : '' ?>><?= lex_e($topic) ?></option>
                <?php endforeach; ?>
                </select>
              <textarea name="message" required placeholder="Tell us what you need help with."><?= lex_e($inquiryValues['message']) ?></textarea>
              <button class="button button--gold" type="submit" data-quick-inquiry-submit>
                <span class="button__spinner" aria-hidden="true"></span>
                <svg class="button__success-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
                <span data-quick-inquiry-submit-label>Send inquiry</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </section>

    <section class="cta-wrap">
      <div class="container">
        <div class="cta-panel">
 
          <div class="cta-actions">
            <a class="button button--gold" href="<?= lex_e($appointLink) ?>"><?= $user && $user['role'] === 'client' ? 'Continue to appointments' : 'Create account' ?></a>
            <a class="button button--soft" href="<?= lex_e($portalLink) ?>"><?= $user ? 'Open portal' : 'Login now' ?></a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-brand-block">
        <a class="brand" href="#home">
          <span class="brand__mark"><img src="<?= lex_e($logoAsset) ?>" alt="LexShield logo"></span>
          <span class="brand__text">LEX<span>SHIELD</span></span>
        </a>
        <p class="footer-copy">
          &copy; <?= date('Y') ?> LexShield Legal Compliance. All rights reserved.
        </p>
      </div>
      <nav class="footer-nav" aria-label="Footer navigation">
        <a href="#home">Home</a>
        <a href="#directory">Lawyers</a>
        <a href="#services">Services</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
      </nav>
      
     
    </div>
  </footer>

  <script>
    (() => {
      const nav = document.querySelector('.site-nav');
      const navToggle = document.querySelector('.nav-toggle');
      const navLinks = Array.from(document.querySelectorAll('.site-nav a[href^="#"]'));
      const sections = navLinks
        .map((link) => {
          const href = link.getAttribute('href');
          if (!href || href === '#') {
            return null;
          }

          const target = document.querySelector(href);
          if (!target) {
            return null;
          }

          return { link, href, target };
        })
        .filter(Boolean);

      const setActiveLink = (activeHref) => {
        navLinks.forEach((link) => {
          link.classList.toggle('is-active', link.getAttribute('href') === activeHref);
        });
      };

      const closeMobileMenu = () => {
        if (!nav || !navToggle) {
          return;
        }

        nav.classList.remove('is-menu-open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Open navigation');
      };

      const openMobileMenu = () => {
        if (!nav || !navToggle) {
          return;
        }

        nav.classList.add('is-menu-open');
        navToggle.setAttribute('aria-expanded', 'true');
        navToggle.setAttribute('aria-label', 'Close navigation');
      };

      const syncActiveLinkToViewport = () => {
        if (!sections.length) {
          return;
        }

        const navHeight = nav ? nav.offsetHeight : 0;
        const checkpoint = navHeight + 80;
        let activeSection = sections[0];

        sections.forEach((section) => {
          const rect = section.target.getBoundingClientRect();
          if (rect.top <= checkpoint) {
            activeSection = section;
          }
        });

        setActiveLink(activeSection.href);
      };

      navLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
          const href = link.getAttribute('href');
          if (!href || href === '#') {
            return;
          }

          const target = document.querySelector(href);
          if (!target) {
            return;
          }

          event.preventDefault();

          const navHeight = nav ? nav.offsetHeight : 0;
          const extraOffset = 40;
          const targetTop = target.getBoundingClientRect().top + window.scrollY - navHeight - extraOffset;

          window.scrollTo({
            top: Math.max(0, targetTop),
            behavior: 'smooth'
          });

          setActiveLink(href);
          closeMobileMenu();

          if (window.location.hash !== href) {
            history.pushState(null, '', href);
          } else {
            history.replaceState(null, '', href);
          }
        });
      });

      if (navToggle) {
        navToggle.addEventListener('click', () => {
          if (nav && nav.classList.contains('is-menu-open')) {
            closeMobileMenu();
          } else {
            openMobileMenu();
          }
        });
      }

      const inquiryForm = document.querySelector('[data-quick-inquiry-form]');
      const inquirySubmit = document.querySelector('[data-quick-inquiry-submit]');
      const inquirySubmitLabel = document.querySelector('[data-quick-inquiry-submit-label]');
      const inquirySuccess = document.querySelector('.quick-inquiry-success');

      if (inquiryForm?.dataset.inquirySent === 'true' && inquirySubmit && inquirySubmitLabel) {
        inquirySubmit.classList.add('is-sent');
        inquirySubmitLabel.textContent = 'Sent successfully';

        window.setTimeout(() => {
          inquirySubmit.classList.remove('is-sent');
          inquirySubmitLabel.textContent = 'Send inquiry';
        }, 3000);
      }

      if (inquirySuccess) {
        window.setTimeout(() => {
          inquirySuccess.classList.add('is-vanishing');
          window.setTimeout(() => inquirySuccess.remove(), 360);
        }, 3000);
      }

      inquiryForm?.addEventListener('submit', (event) => {
        if (!inquiryForm.checkValidity()) {
          return;
        }

        inquiryForm.classList.add('is-sending');
        if (inquirySubmit) {
          inquirySubmit.disabled = true;
          inquirySubmit.classList.add('is-sending');
          inquirySubmit.setAttribute('aria-busy', 'true');
        }
        if (inquirySubmitLabel) {
          inquirySubmitLabel.textContent = 'Sending...';
        }
      });

      window.addEventListener('resize', () => {
        if (window.innerWidth > 780) {
          closeMobileMenu();
        }
      });

      window.addEventListener('scroll', syncActiveLinkToViewport, { passive: true });
      window.addEventListener('load', syncActiveLinkToViewport);
      syncActiveLinkToViewport();
    })();
  </script>
</body>
</html>

