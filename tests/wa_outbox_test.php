<?php
require_once __DIR__ . '/_cli_only.php';

define('BOT_WEBHOOK_URL',    'https://hermes.local/webhook/ipl');
define('BOT_WEBHOOK_SECRET', 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/wa_outbox.php';

function assertSameVal($a, $b, string $msg): void {
    if ($a !== $b) {
        fwrite(STDERR, "FAIL: {$msg} (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")\n");
        exit(1);
    }
}

$db = db();

// Bersihkan baris test sebelumnya
$db->query("DELETE FROM wa_outbox WHERE ref_type='test_case'");

// 1. Deduplikasi wa_enqueue
$id1 = wa_enqueue('test.event', '081234567890', 'resident', 'test_case', 999001, ['hello' => 'world']);
$id2 = wa_enqueue('test.event', '+62 812-3456-7890', 'resident', 'test_case', 999001, ['hello' => 'duplicate']);
assertSameVal($id1 !== null, true, 'first enqueue succeeds');
assertSameVal($id2, null, 'duplicate enqueue ignored via uq_wa_dedup');

// 2. Dispatch dengan mock HTTP: uji kegagalan -> retry -> sukses + verifikasi HMAC
$calls = [];
$failFirst = wa_dispatch_batch(10, static function (string $url, array $headers, string $body) use (&$calls): array {
    $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
    return ['ok' => false, 'status' => 502, 'error' => 'HTTP 502'];
});
assertSameVal($failFirst['retried'] >= 1, true, 'failed webhook schedules retry');

// Majukan next_try_at agar bisa dikirim ulang sekarang
$db->query("UPDATE wa_outbox SET next_try_at = NOW() - INTERVAL 1 SECOND WHERE id = {$id1}");

$sentRes = wa_dispatch_batch(10, static function (string $url, array $headers, string $body) use (&$calls): array {
    $hdrMap = [];
    foreach ($headers as $h) {
        [$k, $v] = array_map('trim', explode(':', $h, 2));
        $hdrMap[$k] = $v;
    }
    $expectedSig = wa_webhook_signature(BOT_WEBHOOK_SECRET, $hdrMap['X-IPL-Timestamp'] ?? '', $body);
    if (!hash_equals($expectedSig, $hdrMap['X-IPL-Signature'] ?? '')) {
        return ['ok' => false, 'status' => 401, 'error' => 'Bad HMAC'];
    }
    $calls[] = ['url' => $url, 'headers' => $hdrMap, 'body' => json_decode($body, true)];
    return ['ok' => true, 'status' => 200, 'error' => null];
});
assertSameVal($sentRes['sent'] >= 1, true, 'webhook dispatched & HMAC verified');

$row = $db->query("SELECT status, attempts, sent_at FROM wa_outbox WHERE id = {$id1}")->fetch_assoc();
assertSameVal($row['status'], 'sent', 'outbox status marked sent');
assertSameVal((int) $row['attempts'], 2, 'attempts incremented to 2');

// 3. Tautan kwitansi publik bertanda tangan HMAC
$now = 1793812345;
$url = receipt_sign_url(42, 30, $now);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
assertSameVal(receipt_verify_params((int) $q['p'], (int) $q['exp'], (string) $q['sig'], $now + 60), true, 'valid receipt signature accepted');
assertSameVal(receipt_verify_params((int) $q['p'] + 1, (int) $q['exp'], (string) $q['sig'], $now + 60), false, 'tampered payment_id rejected');
assertSameVal(receipt_verify_params((int) $q['p'], (int) $q['exp'], (string) $q['sig'], (int) $q['exp'] + 1), false, 'expired receipt link rejected');

$db->query("DELETE FROM wa_outbox WHERE ref_type='test_case'");
echo "wa_outbox_test OK\n";

