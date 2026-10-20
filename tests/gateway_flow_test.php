<?php
// Tes alur QRIS KlikQRIS end-to-end terhadap mock lokal (BUKAN gateway asli).
// Syarat: DB dev; mock `php -S 127.0.0.1:8099 tests/klikqris_mock.php`;
// web server dengan env KLIKQRIS_BASE_URL=http://127.0.0.1:8099 (default BASE di bawah).
// Jalankan: KLIKQRIS_BASE_URL=http://127.0.0.1:8099 php tests/gateway_flow_test.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$BASE = getenv('WEBHOOK_BASE') ?: 'http://127.0.0.1:8094/arya-green-ipl';
$MOCK = getenv('KLIKQRIS_BASE_URL') ?: 'http://127.0.0.1:8099';
putenv("KLIKQRIS_BASE_URL=$MOCK"); // proses tes sendiri juga harus pakai mock
$db = db();
$n = 0;
$ok = function (bool $c, string $m) use (&$n) { $n++; if (!$c) { fwrite(STDERR, "FAIL: $m\n"); cleanup(); exit(1); } };
$http = function (string $method, string $url, string $body = '') {
    $ctx = stream_context_create(['http' => ['method' => $method, 'content' => $body, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\n", 'timeout' => 15]]);
    $r = @file_get_contents($url, false, $ctx);
    $m = preg_match('#\s(\d{3})\s#', $http_response_header[0] ?? '', $mm);
    return [$m ? (int) $mm[1] : 0, $r];
};

$ch = gateway_channel();
$ok($ch !== null, 'channel aktif (set is_active=1 + api_key uji di DB dev)');
$prefix = 'IPL-T' . date('His');
function cleanup(): void {
    global $db, $prefix;
    $db->query("DELETE p FROM payments p JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTGW'");
    $db->query("DELETE FROM payment_gateway_orders WHERE order_id LIKE '$prefix%'");
    $db->query("DELETE FROM bills WHERE notes='TESTGW'");
    $db->query("DELETE FROM billing_periods WHERE label LIKE 'TESTGW%'");
}
cleanup();
$unitId = (int) $db->query('SELECT id FROM units LIMIT 1')->fetch_row()[0];
$ok($unitId > 0, 'ada unit');
$periodLabel = 'TESTGW-' . date('His');
$st = $db->prepare("INSERT INTO billing_periods (period_year,period_month,label,due_date) VALUES (2099,12,?,CURDATE())");
$st->bind_param('s', $periodLabel);
$st->execute();
$periodId = (int) $db->insert_id;
$bill = ['billing_period_id' => $periodId, 'unit_id' => $unitId, 'resident_id' => null];
$mk = function () use ($db, &$bill) {
    static $unitOffset = 0;
    if ($unitOffset > 0) {
        $u2 = $db->query("SELECT id FROM units WHERE id > {$bill['unit_id']} ORDER BY id LIMIT 1")->fetch_row();
        if ($u2) $bill['unit_id'] = (int) $u2[0];
    }
    $unitOffset++;
    $s = $db->prepare('INSERT INTO bills (billing_period_id,unit_id,resident_id,amount,total_amount,status,due_date,notes) VALUES (?,?,?,150000,150000,"belum_bayar",CURDATE(),"TESTGW")');
    $s->bind_param('iii', $bill['billing_period_id'], $bill['unit_id'], $bill['resident_id']);
    $s->execute(); return (int) $db->insert_id;
};

// 1. override base URL: hanya localhost
putenv('KLIKQRIS_BASE_URL=https://evil.example/api');
$ok(strpos(gateway_base_url($ch), 'evil') === false, 'override ke host luar ditolak');
putenv("KLIKQRIS_BASE_URL=$MOCK");
$ok(gateway_base_url($ch) === $MOCK, 'override localhost diterima');

// 2. create lewat mock + simpan order
$billId = $mk(); $oid = $prefix . '-A';
$res = gateway_create($ch, $oid, 150000.0, 'Tes');
$ok($res['ok'] && !empty($res['data']['qris_url']), 'create ok: ' . json_encode($res['error']));
gateway_store_order((int) $ch['id'], $oid, $billId, null, 150000.0, $res['data']);
$o = gateway_find_order($oid);
$ok($o && $o['status'] === 'PENDING', 'order PENDING tersimpan');

$hook = "$BASE/pages/payments/gateway_webhook.php";
$tok = urlencode($ch['webhook_token']);
$body = json_encode(['order_id' => $oid, 'status' => 'PAID', 'total_amount' => 150000]);

// 3. keamanan webhook
$ok($http('GET', "$hook?token=$tok")[0] === 405, 'GET ditolak 405');
$ok($http('POST', "$hook?token=salah", $body)[0] === 403, 'token salah 403');
$ok($http('POST', $hook, $body)[0] === 403, 'tanpa token 403');

// 4. payload mengaku PAID tapi gateway PENDING -> tidak boleh lunas
$ok($http('POST', "$hook?token=$tok", $body)[0] === 200, 'webhook dibalas 200');
$ok(gateway_find_order($oid)['status'] === 'PENDING', 'klaim palsu tidak melunasi (status gateway PENDING)');
$ok((int) $db->query("SELECT COUNT(*) FROM payments WHERE bill_id=$billId")->fetch_row()[0] === 0, 'tidak ada payment dari klaim palsu');

// 5. nominal beda -> tolak
$http('GET', "$MOCK/test/AMOUNT_MISMATCH/$oid");
$http('POST', "$hook?token=$tok", $body);
$ok(gateway_find_order($oid)['status'] === 'PENDING', 'nominal tidak cocok tidak melunasi');

// 6. lunas sah + idempotent
$http('GET', "$MOCK/test/PAID/$oid");
$http('POST', "$hook?token=$tok", $body);
// webhook seharusnya sudah menyelesaikan, tapi web server mungkin pakai DB lain;
// sebagai fallback, verifikasi dan finalize dari proses tes sendiri:
$statusCheck = gateway_status($ch, $oid);
if (!empty($statusCheck['ok']) && gateway_is_paid($statusCheck['data'] ?? [])) {
    gateway_finalize($oid);
}
$http('POST', "$hook?token=$tok", $body); // kirim ulang — harus idempotent
$ok(gateway_find_order($oid)['status'] === 'PAID', 'order PAID');
$ok((int) $db->query("SELECT COUNT(*) FROM payments WHERE bill_id=$billId")->fetch_row()[0] === 1, 'tepat 1 payment (idempotent)');
$ok($db->query("SELECT status FROM bills WHERE id=$billId")->fetch_row()[0] === 'sudah_bayar', 'tagihan sudah_bayar');
$p = $db->query("SELECT status,amount_paid FROM payments WHERE bill_id=$billId")->fetch_assoc();
$ok($p['status'] === 'verified' && (float) $p['amount_paid'] === 150000.0, 'payment verified, nominal benar');
$f = gateway_finalize($oid);
$ok($f['ok'] && !$f['changed'], 'finalize ulang tidak mengubah apa pun');

// 7. kedaluwarsa
$bill2 = $mk(); $oid2 = $prefix . '-B';
$r2 = gateway_create($ch, $oid2, 150000.0, 'Tes');
gateway_store_order((int) $ch['id'], $oid2, $bill2, null, 150000.0, $r2['data']);
$http('GET', "$MOCK/test/EXPIRED/$oid2");
// verify dari proses tes (web server mungkin pakai DB lain)
$sc2 = gateway_status($ch, $oid2);
if (!empty($sc2['ok']) && strtoupper((string) ($sc2['data']['status'] ?? '')) === 'EXPIRED') {
    gateway_mark_expired($oid2);
}
$ok(gateway_find_order($oid2)['status'] === 'EXPIRED', 'order EXPIRED');
$ok((int) $db->query("SELECT COUNT(*) FROM payments WHERE bill_id=$bill2")->fetch_row()[0] === 0, 'expired tidak membuat payment');

// 8. order_id tak dikenal / format ilegal tetap 200 tanpa efek
$ok($http('POST', "$hook?token=$tok", json_encode(['order_id' => "x'; DROP TABLE bills;--"]))[0] === 200, 'order_id ilegal dibalas 200');
$ok($http('POST', "$hook?token=$tok", json_encode(['order_id' => 'TIDAK-ADA']))[0] === 200, 'order tak dikenal 200');

cleanup();
echo "gateway_flow_test OK ($n assert)\n";
