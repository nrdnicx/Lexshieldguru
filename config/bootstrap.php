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
header("Permissions-Policy: camera=(self \"https://meet.jit.si\" \"https://8x8.vc\"), microphone=(self \"https://meet.jit.si\" \"https://8x8.vc\"), display-capture=(self \"https://meet.jit.si\" \"https://8x8.vc\"), geolocation=()");
header("Content-Security-Policy: default-src 'self' https: data:; script-src 'self' https://cdn.jsdelivr.net https://cdn.jsdelivr.net/npm https://cdnjs.cloudflare.com https://meet.jit.si https://8x8.vc https://*.8x8.vc https://*.jitsi.net 'unsafe-inline'; script-src-elem 'self' https://cdn.jsdelivr.net https://cdn.jsdelivr.net/npm https://cdnjs.cloudflare.com https://meet.jit.si https://8x8.vc https://*.8x8.vc https://*.jitsi.net 'unsafe-inline'; style-src 'self' https://fonts.googleapis.com https://8x8.vc https://*.8x8.vc 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob: https:; connect-src 'self' http://127.0.0.1:3001 https://lexshieldguru.onrender.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://meet.jit.si https://8x8.vc https://*.8x8.vc https://*.jitsi.net wss://*.8x8.vc wss://*.jitsi.net; frame-src 'self' https://meet.jit.si https://8x8.vc https://*.8x8.vc; child-src 'self' https://meet.jit.si https://8x8.vc https://*.8x8.vc; media-src 'self' blob: https:; worker-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

require_once __DIR__ . '/bootstrap/core.php';
require_once __DIR__ . '/../security/rate_limiter.php';
require_once __DIR__ . '/bootstrap/mail.php';
require_once __DIR__ . '/bootstrap/auth.php';
require_once __DIR__ . '/bootstrap/view.php';
require_once __DIR__ . '/bootstrap/helpers.php';
require_once __DIR__ . '/bootstrap/storage.php';
require_once __DIR__ . '/bootstrap/case_files.php';
require_once __DIR__ . '/bootstrap/messages.php';
require_once __DIR__ . '/bootstrap/video.php';
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
