<?php
require_once __DIR__ . '/_cli_only.php';

define('BOT_API_KEYS', [
    'test-key' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
]);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bot_auth.php';

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// 1. phone_normalize
assertTrue(phone_normalize('0813-9846-1650') === '6281398461650', '08xx normalized to 628xx');
assertTrue(phone_normalize('+62 812 3456 7890') === '6281234567890', '+62 normalized to 628xx');
assertTrue(phone_normalize('628123456789') === '628123456789', '628xx kept');
assertTrue(phone_normalize('8123456789') === '628123456789', '8xx prefixed with 62');
assertTrue(phone_normalize('TEMP-G10') === null, 'placeholder rejected');
assertTrue(phone_normalize('0215551234') === null, 'landline rejected');
assertTrue(phone_normalize('08123') === null, 'too short rejected');

// 2. HMAC verification
$secret = BOT_API_KEYS['test-key'];
$now    = 1793812345;
$ts     = (string) $now;
$method = 'POST';
$uri    = '/arya-green-ipl/api/bot/qris_create.php';
$phone  = '081398461650';
$body   = '{"bill_id":10}';
$sig    = bot_signature($secret, $ts, $method, $uri, $phone, $body);

$server = [
    'REQUEST_METHOD'       => $method,
    'REQUEST_URI'          => $uri,
    'HTTP_X_BOT_KEY'       => 'test-key',
    'HTTP_X_BOT_TIMESTAMP' => $ts,
    'HTTP_X_BOT_PHONE'     => $phone,
    'HTTP_X_BOT_SIGNATURE' => $sig,
];

$ok = bot_verify_request($server, $body, $now);
assertTrue($ok['ok'] === true && $ok['phone'] === '6281398461650', 'valid signed request accepted & phone normalized');

// Tampered phone (e.g. prompt injection trying to change header without re-signing)
$tampered = $server;
$tampered['HTTP_X_BOT_PHONE'] = '081299998888';
$badPhone = bot_verify_request($tampered, $body, $now);
assertTrue($badPhone['ok'] === false && $badPhone['status'] === 401, 'tampered phone rejected');

// Expired timestamp
$expired = bot_verify_request($server, $body, $now + 600);
assertTrue($expired['ok'] === false && $expired['code'] === 'TIMESTAMP_EXPIRED', 'replay outside window rejected');

// Invalid phone even with valid signature
$invalidPhoneSig = bot_signature($secret, $ts, $method, $uri, 'TEMP-G10', $body);
$srvInvalidPhone = $server;
$srvInvalidPhone['HTTP_X_BOT_PHONE'] = 'TEMP-G10';
$srvInvalidPhone['HTTP_X_BOT_SIGNATURE'] = $invalidPhoneSig;
$resInvalidPhone = bot_verify_request($srvInvalidPhone, $body, $now);
assertTrue($resInvalidPhone['ok'] === false && $resInvalidPhone['code'] === 'INVALID_PHONE', 'non-mobile phone rejected');

// 3. Actor resolution against DB
$db = db();
$actor = bot_resolve_actor($db, '6281398461650');
assertTrue($actor['registered'] === true && count($actor['residents']) >= 1, 'seeded resident Ikhsan resolved by phone');
assertTrue($actor['residents'][0]['name'] === 'Ikhsan', 'resident name matches');

$unknown = bot_resolve_actor($db, '6289999999999');
assertTrue($unknown['registered'] === false && $unknown['residents'] === [], 'unregistered phone returns registered=false');

echo "bot_auth_test OK\n";

