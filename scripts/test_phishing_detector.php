<?php
declare(strict_types=1);

define('LEX_PHISHING_DETECTOR_STANDALONE', true);
require_once __DIR__ . '/../api/phishing/check.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$registrableCases = [
    'https://example.co.uk' => 'example.co.uk',
    'https://subdomain.example.co.uk' => 'example.co.uk',
];

foreach ($registrableCases as $url => $expectedDomain) {
    $host = (string) parse_url($url, PHP_URL_HOST);
    $actualDomain = lex_phishing_registrable_domain($host);
    assert_true($actualDomain === $expectedDomain, "{$url} registrable domain should be {$expectedDomain}, got {$actualDomain}");
}

$safeCases = [
    'https://login.microsoft.com',
    'https://accounts.google.com',
    'https://www.paypal.com',
    'https://secure.lexshield.com',
];

foreach ($safeCases as $url) {
    $response = lex_phishing_response_for_url($url, false);
    assert_true($response['status'] !== 'phishing', "{$url} should not be phishing");
    $joinedFindings = implode(' ', $response['findings'] ?? []);
    assert_true(!str_contains($joinedFindings, 'registrable domain'), "{$url} should not be flagged for brand impersonation");
}

$phishingCases = [
    'https://login.microsoft.com.attacker.com',
    'https://microsoft.com.attacker.com',
    'https://secure-paypal.attacker.com',
    'https://paypal-login.attacker.com',
    'https://google.verify-attacker.com',
    'https://secure-login.example.net.verify-account.com',
    'https://paypa1-attacker.com',
    'https://micr0soft-login-attacker.com',
];

foreach ($phishingCases as $url) {
    $response = lex_phishing_response_for_url($url, false);
    assert_true($response['status'] === 'phishing', "{$url} should be phishing, got {$response['status']} with score {$response['score']}");
}

echo "Phishing detector tests passed.\n";
