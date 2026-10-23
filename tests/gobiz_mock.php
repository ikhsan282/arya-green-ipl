<?php
/**
 * Mock GoBiz untuk tes login OTP + cek sesi (tanpa menyentuh API asli).
 * Jalankan: php -S 127.0.0.1:8097 tests/gobiz_mock.php
 * State: token valid bila header Authorization == "Bearer AT-GOOD".
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
header('Content-Type: application/json');

function ok(array $data): void { echo json_encode(['success' => true, 'data' => $data]); exit; }
function bad(string $msg, int $code = 400): void { http_response_code($code); echo json_encode(['success' => false, 'errors' => [['message' => $msg]]]); exit; }

switch (true) {
    case $path === '/goid/login/request':
        if (($body['phone_number'] ?? '') === '') bad('phone_number wajib');
        ok(['otp_token' => 'OTP-MOCK-' . substr(md5((string) $body['phone_number']), 0, 8), 'expires_in' => 720]);

    case $path === '/goid/token':
        if (($body['grant_type'] ?? '') === 'otp') {
            if (($body['data']['otp'] ?? '') !== '1234') bad('OTP salah', 401);
            ok(['access_token' => 'AT-GOOD', 'refresh_token' => 'RT-GOOD', 'expires_in' => 86400]);
        }
        if (($body['grant_type'] ?? '') === 'refresh_token') {
            if (($body['data']['refresh_token'] ?? '') !== 'RT-GOOD') bad('refresh ditolak', 401);
            ok(['access_token' => 'AT-GOOD', 'refresh_token' => 'RT-GOOD', 'expires_in' => 86400]);
        }
        bad('grant_type tidak dikenal');

    case $path === '/goresto/v5/public/users/config':
        // Bentuk asli GoBiz: profil ada di dalam node `merchant`, bukan di data.
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer AT-FORBIDDEN') bad('forbidden', 403);
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer AT-GOOD') bad('unauthorized', 401);
        // Profil ada di root respons (bukan dibungkus success/data).
        echo json_encode(['merchant' => ['outlet_name' => 'Warung Mock Arya', 'id' => 'M-MOCK-1']]); exit;

    case str_starts_with($path, '/merchant-analytics/'):
        // AT-FORBIDDEN meniru GoPay membalas 403 (token basi/device header dianggap asing).
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer AT-FORBIDDEN') bad('forbidden', 403);
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer AT-GOOD') bad('unauthorized', 401);
        // merchant_ids yang tidak dikenal -> 403 juga, seperti API asli.
        if (($_GET['merchant_ids'] ?? '') === 'G-BAD') bad('forbidden', 403);
        ok(['transactions' => []]);
}

bad('endpoint mock tidak ada: ' . $path, 404);
