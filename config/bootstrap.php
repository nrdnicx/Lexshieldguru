<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../security/input_sanitizer.php';
require_once __DIR__ . '/../security/session_guard.php';

lex_start_secure_session();

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self' https: data:; script-src 'self' https://cdn.jsdelivr.net https://cdn.jsdelivr.net/npm https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self' http://127.0.0.1:3001 https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

require_once __DIR__ . '/bootstrap/core.php';
require_once __DIR__ . '/../security/rate_limiter.php';
require_once __DIR__ . '/bootstrap/mail.php';
require_once __DIR__ . '/bootstrap/auth.php';
require_once __DIR__ . '/bootstrap/view.php';
require_once __DIR__ . '/bootstrap/helpers.php';
require_once __DIR__ . '/bootstrap/storage.php';
require_once __DIR__ . '/bootstrap/case_files.php';
require_once __DIR__ . '/bootstrap/messages.php';
require_once __DIR__ . '/bootstrap/schema.php';

lex_users_table_ensure();
lex_lawyers_table_ensure();
lex_lawyer_reviews_table_ensure();
lex_manual_payments_table_ensure();
lex_appointments_table_ensure();
lex_email_otps_table_ensure();
lex_password_resets_table_ensure();
lex_quick_inquiries_table_ensure();
lex_phishing_scans_table_ensure();
lex_rate_limit_table_ensure();
