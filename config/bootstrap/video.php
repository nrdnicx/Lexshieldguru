<?php
declare(strict_types=1);

if (!function_exists('lex_video_env')) {
    function lex_video_env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return (string) $_SERVER[$name];
        }

        return $default;
    }
}

/**
 * LexShield video consultation foundation.
 *
 * Video meetings are authorized by a confirmed appointment. A unique,
 * unpredictable Jitsi room is generated per appointment and is never shared
 * between different client/lawyer consultations.
 */

function lex_video_config_int(string $key, int $default, int $min, int $max): int
{
    $value = lex_video_env($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false) {
        return $default;
    }

    return max($min, min($max, (int) $parsed));
}

function lex_video_join_early_minutes(): int
{
    return lex_video_config_int('LEX_VIDEO_JOIN_EARLY_MINUTES', 15, 0, 1440);
}

function lex_video_join_late_minutes(): int
{
    return lex_video_config_int('LEX_VIDEO_JOIN_LATE_MINUTES', 60, 1, 1440);
}

function lex_video_provider(): string
{
    $provider = strtolower(trim((string) (lex_video_env('LEX_VIDEO_PROVIDER') ?: 'jaas')));
    return in_array($provider, ['jitsi', 'jaas'], true) ? $provider : '';
}

function lex_video_jitsi_domain(): string
{
    $domain = trim((string) (getenv('LEX_VIDEO_JITSI_DOMAIN') ?: 'meet.jit.si'));
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = trim((string) $domain, "/ \t\n\r\0\x0B");
    return preg_match('/^[a-z0-9.-]+$/i', $domain) ? strtolower($domain) : 'meet.jit.si';
}

function lex_video_jaas_domain(): string
{
    $domain = trim((string) (lex_video_env('LEX_VIDEO_JAAS_DOMAIN') ?: '8x8.vc'));
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = trim((string) $domain, "/ \t\n\r\0\x0B");
    return preg_match('/^[a-z0-9.-]+$/i', $domain) ? strtolower($domain) : '8x8.vc';
}

function lex_video_domain_for_provider(string $provider): string
{
    return $provider === 'jaas' ? lex_video_jaas_domain() : lex_video_jitsi_domain();
}

function lex_video_jaas_app_id(): string
{
    return trim(lex_video_env('LEX_VIDEO_JAAS_APP_ID') ?: '');
}

function lex_video_jaas_key_id(): string
{
    return trim(lex_video_env('LEX_VIDEO_JAAS_KEY_ID') ?: '');
}

function lex_video_jaas_private_key(): string
{
    $encoded = trim((string) (lex_video_env('LEX_VIDEO_JAAS_PRIVATE_KEY_B64') ?: ''));
    if ($encoded !== '') {
        $decoded = base64_decode($encoded, true);
        
        if ($decoded !== false && trim($decoded) !== '') {
            return trim($decoded);
        }
    }

    $raw = trim((string) (lex_video_env('LEX_VIDEO_JAAS_PRIVATE_KEY') ?: ''));
    
    if ($raw !== '') {
        return str_replace('\\n', "\n", $raw);
    }

    return '';
}

function lex_video_token_secret(): string
{
    return trim(lex_video_env('LEX_VIDEO_TOKEN_SECRET') ?: '');
}

function lex_video_jaas_configured(): bool
{
    return lex_video_jaas_app_id() !== ''
        && lex_video_jaas_key_id() !== ''
        && lex_video_jaas_private_key() !== '';
}

function lex_video_jaas_status(): array
{
    $appId = lex_video_jaas_app_id();
    $keyId = lex_video_jaas_key_id();

    $privateKeyB64 = trim(
        (string) (lex_video_env('LEX_VIDEO_JAAS_PRIVATE_KEY_B64') ?: '')
    );

    $privateKey = lex_video_jaas_private_key();

    $privateKeyBase64Valid = false;

    if ($privateKeyB64 !== '') {
        $decoded = base64_decode($privateKeyB64, true);

        $privateKeyBase64Valid =
            $decoded !== false &&
            trim((string) $decoded) !== '';
    }

    $opensslSignAvailable = function_exists('openssl_sign');
    $opensslPrivateKeyAvailable = function_exists('openssl_pkey_get_private');

    $opensslAvailable =
        $opensslSignAvailable &&
        $opensslPrivateKeyAvailable;

    /*
     * Validate that the decoded private key can actually be loaded
     * by OpenSSL. This does NOT expose the private key.
     */
    $privateKeyValid = false;

    if ($privateKey !== '' && $opensslPrivateKeyAvailable) {
        $keyResource = openssl_pkey_get_private($privateKey);
        $privateKeyValid = $keyResource !== false;
    }

    return [
        'configured' =>
            $appId !== '' &&
            $keyId !== '' &&
            $privateKey !== '',

        'app_id_configured' =>
            $appId !== '',

        'key_id_configured' =>
            $keyId !== '',

        'private_key_configured' =>
            $privateKey !== '',

        'private_key_base64_valid' =>
            $privateKeyBase64Valid,

        'private_key_valid' =>
            $privateKeyValid,

        'openssl_available' =>
            $opensslAvailable,

        'openssl_sign_available' =>
            $opensslSignAvailable,

        'openssl_private_key_available' =>
            $opensslPrivateKeyAvailable,
    ];
}

function lex_video_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function lex_video_jaas_jwt(array $meeting, array $user): ?string
{
    if (!lex_video_jaas_configured()) {
        return null;
    }

    $privateKey = openssl_pkey_get_private(lex_video_jaas_private_key());
    if ($privateKey === false) {
        error_log('JaaS JWT generation failed: invalid private key.');
        return null;
    }

    $now = time();
    $scheduledAt = strtotime((string) ($meeting['scheduled_at'] ?? ''));
    $joinEnd = $scheduledAt !== false
        ? $scheduledAt + (lex_video_join_late_minutes() * 60)
        : $now + 3600;

    $payload = [
        'aud' => 'jitsi',
        'exp' => min($joinEnd + 300, $now + 3600),
        'iss' => 'chat',
        'nbf' => max(0, $now - 30),
        'room' => (string) $meeting['meeting_room'],
        'sub' => lex_video_jaas_app_id(),
        'context' => [
            'user' => [
                'id' => (string) ($user['id'] ?? ''),
                'name' => trim((string) ($user['full_name'] ?? 'LEXSHIELD User')),
                'avatar' => '',
                'email' => trim((string) ($user['email'] ?? '')),
                'moderator' => (($user['role'] ?? '') === 'lawyer') ? 'true' : 'false',
            ],
            'features' => [
                'livestreaming' => false,
                'recording' => false,
                'transcription' => false,
                'outbound-call' => false,
            ],
            'room' => [
                'regex' => false,
            ],
        ],
    ];

    $header = [
        'alg' => 'RS256',
        'kid' => lex_video_jaas_key_id(),
        'typ' => 'JWT',
    ];

    $encodedHeader = lex_video_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedPayload = lex_video_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signingInput = $encodedHeader . '.' . $encodedPayload;

    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        error_log('JaaS JWT generation failed: unable to sign token.');
        return null;
    }

    return $signingInput . '.' . lex_video_base64url_encode($signature);
}

function lex_video_remote_jaas_token(array $meeting, array $user): ?array
{
    $endpoint = lex_api_url('api/video/jaas-token');
    $secret = lex_video_token_secret();
    if ($endpoint === '' || $secret === '') {
        return null;
    }

    $now = time();
    $scheduledAt = strtotime((string) ($meeting['scheduled_at'] ?? ''));
    $joinEnd = $scheduledAt !== false
        ? $scheduledAt + (lex_video_join_late_minutes() * 60)
        : $now + 3600;

    $payload = [
        'meeting' => [
            'room' => (string) ($meeting['meeting_room'] ?? ''),
            'appointment_id' => (int) ($meeting['id'] ?? 0),
            'case_id' => (int) ($meeting['case_id'] ?? 0),
        ],
        'user' => [
            'id' => (string) ($user['id'] ?? ''),
            'name' => trim((string) ($user['full_name'] ?? 'LEXSHIELD User')),
            'email' => trim((string) ($user['email'] ?? '')),
            'role' => (string) ($user['role'] ?? ''),
        ],
        'expiresAt' => min($joinEnd + 300, $now + 3600),
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return null;
    }

    $timestamp = (string) $now;
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Lexshield-Timestamp: ' . $timestamp,
        'X-Lexshield-Signature: ' . $signature,
    ];

    $response = null;
    if (function_exists('curl_init')) {
        $curl = curl_init($endpoint);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($raw !== false && $status >= 200 && $status < 300) {
                $response = (string) $raw;
            } else {
                error_log('Remote JaaS token request failed with HTTP status ' . $status . '.');
            }
            curl_close($curl);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($endpoint, false, $context);
        if ($raw !== false) {
            $status = 0;
            foreach (($http_response_header ?? []) as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
            if ($status >= 200 && $status < 300) {
                $response = (string) $raw;
            } else {
                error_log('Remote JaaS token request failed with HTTP status ' . $status . '.');
            }
        }
    }

    if ($response === null) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['jwt'])) {
        error_log('Remote JaaS token response was invalid.');
        return null;
    }

    return [
        'jwt' => (string) $decoded['jwt'],
        'app_id' => trim((string) ($decoded['app_id'] ?? lex_video_jaas_app_id())),
        'domain' => trim((string) ($decoded['domain'] ?? lex_video_jaas_domain())),
    ];
}

function lex_video_generate_room(): string
{
    // 192 bits of entropy; the random token is deliberately unrelated to
    // appointment IDs, case IDs, names, emails, or other predictable data.
    return 'LexShield-' . bin2hex(random_bytes(24));
}

function lex_video_prepare_for_confirmed_appointment(int $appointmentId): bool
{
    if ($appointmentId <= 0 || lex_video_provider() === '') {
        return false;
    }

    return (bool) lex_db_retry(static function () use ($appointmentId): bool {
        $pdo = lex_pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, status, meeting_provider, meeting_room, meeting_enabled
                 FROM appointments
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $appointmentId]);
            $appointment = $stmt->fetch();

            if (!$appointment || (string) $appointment['status'] !== 'confirmed') {
                $pdo->rollBack();
                return false;
            }

            $room = trim((string) ($appointment['meeting_room'] ?? ''));
            $provider = trim((string) ($appointment['meeting_provider'] ?? ''));

            if ($room === '' || $provider !== lex_video_provider()) {
                $room = lex_video_generate_room();
                $provider = lex_video_provider();

                $update = $pdo->prepare(
                    'UPDATE appointments
                     SET meeting_provider = :provider,
                         meeting_room = :room,
                         meeting_enabled = 1,
                         meeting_created_at = COALESCE(meeting_created_at, NOW())
                     WHERE id = :id'
                );
                $update->execute([
                    'provider' => $provider,
                    'room' => $room,
                    'id' => $appointmentId,
                ]);
            } elseif ((int) ($appointment['meeting_enabled'] ?? 0) !== 1) {
                $pdo->prepare(
                    'UPDATE appointments
                     SET meeting_enabled = 1
                     WHERE id = :id'
                )->execute(['id' => $appointmentId]);
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Video consultation preparation failed: ' . $e->getMessage());
            return false;
        }
    });
}

/**
 * Return the appointment only when the current user is the assigned client
 * or lawyer, the appointment is confirmed, and the meeting is enabled.
 *
 * The returned array also contains can_join so the caller can decide whether
 * to display/allow the consultation at the current time.
 */
function lex_video_consultation_for_user(int $appointmentId, int $userId): ?array
{
    if ($appointmentId <= 0 || $userId <= 0) {
        return null;
    }

    try {
        return lex_db_retry(static function () use ($appointmentId, $userId): ?array {
            $stmt = lex_pdo()->prepare(
            'SELECT
                a.id,
                a.case_id,
                a.client_id,
                a.lawyer_id,
                a.scheduled_at,
                a.appointment_type,
                a.status,
                a.meeting_provider,
                a.meeting_room,
                a.meeting_enabled,
                a.meeting_created_at,
                c.case_number,
                cl.user_id AS client_user_id,
                lu.user_id AS lawyer_user_id
             FROM appointments a
             JOIN cases c ON c.id = a.case_id
             JOIN clients cl ON cl.id = a.client_id
             JOIN lawyers lu ON lu.id = a.lawyer_id
             WHERE a.id = :appointment_id
               AND a.status = "confirmed"
               AND a.meeting_enabled = 1
               AND a.meeting_provider IN ("jitsi", "jaas")
               AND a.meeting_room IS NOT NULL
               AND a.meeting_room <> ""
               AND (cl.user_id = :client_user_id OR lu.user_id = :lawyer_user_id)
             LIMIT 1'
        );
        $stmt->execute([
            'appointment_id' => $appointmentId,
            'client_user_id' => $userId,
            'lawyer_user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $scheduledAt = strtotime((string) $row['scheduled_at']);
        if ($scheduledAt === false) {
            return null;
        }

        $joinStart = $scheduledAt - (lex_video_join_early_minutes() * 60);
        $joinEnd = $scheduledAt + (lex_video_join_late_minutes() * 60);
        $now = time();

        $row['can_join'] = $now >= $joinStart && $now <= $joinEnd;
        $row['join_starts_at'] = date('c', $joinStart);
        $row['join_ends_at'] = date('c', $joinEnd);
        $row['video_domain'] = lex_video_domain_for_provider((string) $row['meeting_provider']);

            return $row;
        });
    } catch (Throwable $e) {
        error_log('Video consultation lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Find the confirmed video consultation associated with a case conversation
 * for the current user and their conversation partner.
 */
function lex_video_thread_for_user(int $caseId, int $userId, int $partnerUserId): ?array
{
    if ($caseId <= 0 || $userId <= 0 || $partnerUserId <= 0) {
        return null;
    }

    try {
        return lex_db_retry(static function () use ($caseId, $userId, $partnerUserId): ?array {
            $stmt = lex_pdo()->prepare(
            'SELECT
                a.id, a.case_id, a.scheduled_at, a.appointment_type, a.status,
                a.meeting_provider, a.meeting_room, a.meeting_enabled,
                c.case_number, cl.user_id AS client_user_id, lu.user_id AS lawyer_user_id
             FROM appointments a
             JOIN cases c ON c.id = a.case_id
             JOIN clients cl ON cl.id = a.client_id
             JOIN lawyers lu ON lu.id = a.lawyer_id
             WHERE a.case_id = :case_id
               AND a.status = "confirmed"
               AND a.meeting_enabled = 1
               AND a.meeting_provider IN ("jitsi", "jaas")
               AND a.meeting_room IS NOT NULL
               AND a.meeting_room <> ""
               AND ((cl.user_id = :current_user_id AND lu.user_id = :partner_user_id_a)
                    OR (lu.user_id = :current_user_id_b AND cl.user_id = :partner_user_id_b))
             ORDER BY CASE WHEN a.scheduled_at >= NOW() THEN 0 ELSE 1 END, a.scheduled_at ASC, a.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'current_user_id' => $userId,
            'partner_user_id_a' => $partnerUserId,
            'current_user_id_b' => $userId,
            'partner_user_id_b' => $partnerUserId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $scheduledAt = strtotime((string) $row['scheduled_at']);
        if ($scheduledAt === false) {
            return null;
        }

        $joinStart = $scheduledAt - (lex_video_join_early_minutes() * 60);
        $joinEnd = $scheduledAt + (lex_video_join_late_minutes() * 60);
        $now = time();

        $row['can_join'] = $now >= $joinStart && $now <= $joinEnd;
        $row['join_starts_at'] = date('c', $joinStart);
        $row['join_ends_at'] = date('c', $joinEnd);
        $row['video_domain'] = lex_video_domain_for_provider((string) $row['meeting_provider']);
            return $row;
        });
    } catch (Throwable $e) {
        error_log('Video thread lookup failed: ' . $e->getMessage());
        return null;
    }
}
