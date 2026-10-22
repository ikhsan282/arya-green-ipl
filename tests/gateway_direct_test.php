<?php
require_once __DIR__ . '/_cli_only.php';
// Tes gateway GoPay & ShopeePay — pure PHP implementation.
// QRIS create: lokal (tidak perlu mock). Check-payment: mock server.
// Syarat: mock `php -S 127.0.0.1:8098 tests/gq_mock.php`; DB dev.
// Jalankan: php tests/gateway_direct_test.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/payment_gateway_direct.php';

$MOCK = getenv('GQ_MOCK') ?: 'http://127.0.0.1:8098';
// Point check-payment APIs at mock server
putenv("GOPAY_API_URL=$MOCK");
putenv("SHOPEEPAY_API_URL=$MOCK/api/v1/report/transaction/get-transaction-list");
$db = db();
$n = 0;
$ok = function (bool $c, string $m) use (&$n) {
    $n++; if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); }
};
$state = getenv('GQ_MOCK_STATE') ?: sys_get_temp_dir() . '/gq-mock.json';
is_file($state) && @unlink($state);
$prefix = 'IPL-GQT' . date('His');
$cleanup = function () use ($db, $prefix) {
    $db->query("DELETE k FROM cash_book k JOIN payments p ON p.id=k.ref_payment_id JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTGQ'");
    $db->query("DELETE p FROM payments p JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTGQ'");
    $db->query("DELETE o FROM payment_gateway_orders o JOIN bills b ON b.id=o.bill_id WHERE b.notes='TESTGQ'");
    $db->query("DELETE FROM payment_gateway_orders WHERE order_id LIKE '$prefix%'");
    $db->query("DELETE FROM bills WHERE notes='TESTGQ'");
    $db->query("DELETE FROM billing_periods WHERE label LIKE 'TESTGQ%'");
};
$cleanup();

// --- Tes unit QRIS (tidak perlu DB/mock) ---

// Build a properly formatted static QRIS for testing
$sampleBase = '00020101021126560016COM.NOBUBANK.WWW0118936001030000000099021012345678905204000053033605802ID5909TestShop96007Jakarta6304';
$sampleQris = $sampleBase . qris_crc16($sampleBase);

// CRC16-CCITT
$ok(strlen(qris_crc16('test')) === 4, 'CRC16 returns 4-char hex');

// TLV round-trip
$tags = qris_parse_tlv($sampleQris);
$ok(count($tags) > 0, 'TLV parse returns tags');
$ok($tags[0][0] === '00' && $tags[0][1] === '01', 'First tag is 00 = format indicator');
$tag01 = null;
foreach ($tags as $t) { if ($t[0] === '01') { $tag01 = $t[1]; break; } }
$ok($tag01 === '11', 'Tag 01 = 11 (static)');

// Roundtrip rebuild
$stripped = array_values(array_filter($tags, fn($t) => $t[0] !== '63'));
$rebuilt = qris_build_tlv($stripped) . '6304';
$rebuilt .= qris_crc16($rebuilt);
$ok($rebuilt === $sampleQris, 'TLV roundtrip rebuild matches original');

// Dynamic QRIS generation
$dynamic = qris_make_dynamic($sampleQris, 150000);
$ok($dynamic !== null, 'Dynamic QRIS generated');
$dtags = qris_parse_tlv($dynamic);
$d01 = $d54 = $d63 = null;
foreach ($dtags as $t) {
    if ($t[0] === '01') $d01 = $t[1];
    if ($t[0] === '54') $d54 = $t[1];
    if ($t[0] === '63') $d63 = $t[1];
}
$ok($d01 === '12', 'Dynamic: tag 01 = 12');
$ok($d54 === '150000', 'Dynamic: tag 54 = amount');
$ok($d63 !== null && strlen($d63) === 4, 'Dynamic: CRC tag present');

// Verify CRC
$withoutCrc = substr($dynamic, 0, -4);
$ok($d63 === qris_crc16($withoutCrc), 'Dynamic: CRC matches recalculated');

echo "QRIS unit tests OK ($n assertions)\n";

// --- Setup channels: merchant_id = QRIS statis, api_key = token ---
$gopayToken = json_encode(['Authorization' => 'Bearer mock', 'X-App-Id' => 'test']);
$db->query("UPDATE payment_channels SET api_key='" . $db->real_escape_string($gopayToken) . "', merchant_id='" . $db->real_escape_string($sampleQris) . "', is_active=1 WHERE provider='gopay'");
$db->query("UPDATE payment_channels SET api_key='B:mock-token', merchant_id='" . $db->real_escape_string($sampleQris) . "', is_active=1 WHERE provider='shopeepay'");

$unitId = (int)$db->query('SELECT id FROM units LIMIT 1')->fetch_row()[0];
$ok($unitId > 0, 'ada unit');
// periode unik per run: uq_period unik pada (period_year, period_month)
$periodYear = 2099;
$db->query("DELETE FROM billing_periods WHERE period_year=$periodYear AND label LIKE 'TESTGQ%'");
$label = 'TESTGQ-' . date('His');
$st = $db->prepare("INSERT INTO billing_periods (period_year,period_month,label,due_date) VALUES (?,?,?,CURDATE())");
$periodMonth = (int)date('n');
$st->bind_param('iis', $periodYear, $periodMonth, $label); $st->execute();
$periodId = (int)$db->insert_id;
$ok($periodId > 0, 'periode tes dibuat');
$mkBill = function (float $amount, int $unitId) use ($db, $periodId) {
    // uq_bill unik pada (billing_period_id, unit_id) → satu bill per unit per periode
    $s = $db->prepare('INSERT INTO bills (billing_period_id,unit_id,resident_id,amount,total_amount,status,due_date,notes) VALUES (?,?,NULL,?,?,"belum_bayar",CURDATE(),"TESTGQ")');
    $s->bind_param('iidd', $periodId, $unitId, $amount, $amount); $s->execute(); return (int)$db->insert_id;
};
$unitIds = [];
$r = $db->query('SELECT id FROM units ORDER BY id LIMIT 2');
while ($row = $r->fetch_row()) $unitIds[] = (int)$row[0];
$ok(count($unitIds) >= 2, 'minimal 2 unit untuk tes');
$http = function (string $url) {
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
    return $r !== false && str_contains($r, 'ok');
};

foreach (['gopay', 'shopeepay'] as $i => $provider) {
    $ch = gateway_channel($provider);
    $ok($ch !== null, "$provider: kanal aktif terdeteksi");
    $ok(gq_base($ch) !== '', "$provider: token dari kolom api_key");

    $billId = $mkBill(150000.0, $unitIds[$i]);
    $charge = gateway_charge_total(150000.0);
    $oid = $prefix . '-' . strtoupper(substr($provider, 0, 2)) . $i;

    // gq_create — lokal, tidak perlu mock server
    $res = gq_create($ch, $oid, $charge, 'Tes ' . $provider);
    $ok($res['ok'], "$provider: create ok → " . (string)$res['error']);
    $ok(!empty($res['data']['qris_url']), "$provider: qris_url ada");
    $ok(str_contains($res['data']['qris_url'], 'qrserver.com'), "$provider: qris_url dari qrserver.com");
    $ok((float)$res['data']['total_amount'] === 150000.0, "$provider: total_amount = tagihan (tanpa kode unik)");

    // Verify the generated QRIS is valid dynamic
    $genQris = $res['data']['direct_url'];
    $ok($genQris !== null && strlen($genQris) > 50, "$provider: QRIS string tersimpan di direct_url");
    $gtags = qris_parse_tlv($genQris);
    $g01 = null; foreach ($gtags as $t) { if ($t[0] === '01') { $g01 = $t[1]; break; } }
    $ok($g01 === '12', "$provider: generated QRIS is dynamic (tag 01=12)");

    gateway_store_order((int)$ch['id'], $oid, $billId, null, $charge, $res['data']);
    $order = gateway_find_order($oid);
    $ok($order && $order['status'] === 'PENDING', "$provider: order PENDING tersimpan");

    // --- Check-payment via mock server ---
    // Monkey-patch gq_check to hit mock instead of real API
    // We override the check URLs by temporarily replacing channel api_key with mock-aware value
    // For gopay: JSON headers pointing at mock; for shopeepay: token with mock URL
    // The mock must be running for check tests; skip gracefully if not.
    $mockUp = @file_get_contents("$MOCK/test/PAY/0", false, stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]));
    if ($mockUp === false) {
        echo "SKIP: mock server not running at $MOCK — check-payment tests skipped\n";
        continue;
    }

    // belum bayar → PENDING
    $st2 = gateway_status($ch, $oid);
    $ok($st2['ok'] && !gateway_is_paid($st2['data']), "$provider: status awal PENDING");

    // pembeli membayar → status PAID → finalize
    $ok($http("$MOCK/test/PAY/150000"), "$provider: mock menandai pembayaran masuk");
    $st2 = gateway_status($ch, $oid);
    $ok($st2['ok'] && gateway_is_paid($st2['data']), "$provider: status jadi PAID");
    $fin = gateway_finalize($oid, (float)$st2['data']['total_amount']);
    $ok($fin['ok'] && $fin['payment_id'] > 0, "$provider: finalize ok → " . (string)$fin['error']);
    $p = $db->query('SELECT payment_method, amount_paid FROM payments WHERE id=' . (int)$fin['payment_id'])->fetch_assoc();
    $ok($p['payment_method'] === ('QRIS Online (' . ($provider === 'gopay' ? 'GoPay' : 'ShopeePay') . ')'), "$provider: metode pembayaran = {$p['payment_method']}");
    $ok((float)$p['amount_paid'] === 150000.0, "$provider: amount_paid = tagihan");
    $ok($db->query('SELECT status FROM bills WHERE id=' . $billId)->fetch_row()[0] === 'sudah_bayar', "$provider: tagihan jadi sudah_bayar");
    $again = gateway_finalize($oid, 150000.0);
    $ok($again['ok'] && $again['changed'] === false, "$provider: finalize ulang tidak dobel");

    // Reset mock state for next provider
    is_file($state) && @unlink($state);
}

// kanal nonaktif tidak dipakai tombol QRIS
$db->query("UPDATE payment_channels SET is_active=0 WHERE provider IN ('gopay','shopeepay')");
$ok(gateway_channel('gopay') === null, 'kanal nonaktif tidak terpilih');
$cleanup();
echo "gateway_direct_test OK ($n asersi)\n";
