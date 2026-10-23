<?php
require_once __DIR__ . '/_cli_only.php';
/**
 * Regresi: dua tagihan bernominal SAMA tidak boleh saling melunasi.
 *
 * Bug lama: gq_check mencocokkan nominal saja, jadi satu pembayaran warga A
 * menutup tagihan warga B (nominal sama) yang belum dibayar.
 * Perbaikan: kode unik per order (1..99) + klaim satu-transaksi-satu-order.
 *
 * Syarat: mock `php -S 127.0.0.1:8098 tests/gq_mock.php` + DB dev.
 * Jalankan: php tests/gateway_collision_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/payment_gateway_direct.php';

$MOCK = getenv('GQ_MOCK') ?: 'http://127.0.0.1:8098';
putenv("GOPAY_API_URL=$MOCK");

$db = db();
$n = 0;
$ok = function (bool $c, string $m) use (&$n) {
    $n++;
    if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); }
    echo "  ok  $m\n";
};
$state = getenv('GQ_MOCK_STATE') ?: sys_get_temp_dir() . '/gq-mock.json';
is_file($state) && @unlink($state);
$hit = function (string $url) {
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
    return $r !== false;
};

$prefix = 'IPL-COL' . date('His');
$cleanup = function () use ($db, $prefix) {
    $db->query("DELETE k FROM cash_book k JOIN payments p ON p.id=k.ref_payment_id JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTCOL'");
    $db->query("DELETE p FROM payments p JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTCOL'");
    $db->query("DELETE o FROM payment_gateway_orders o JOIN bills b ON b.id=o.bill_id WHERE b.notes='TESTCOL'");
    $db->query("DELETE FROM payment_gateway_orders WHERE order_id LIKE '$prefix%'");
    $db->query("DELETE FROM bills WHERE notes='TESTCOL'");
    $db->query("DELETE FROM billing_periods WHERE label LIKE 'TESTCOL%'");
};
$cleanup();

$sampleBase = '00020101021126560016COM.NOBUBANK.WWW0118936001030000000099021012345678905204000053033605802ID5909TestShop96007Jakarta6304';
$sampleQris = $sampleBase . qris_crc16($sampleBase);
$gopayToken = json_encode(['Authorization' => 'Bearer mock']);
$db->query("UPDATE payment_channels SET api_key='" . $db->real_escape_string($gopayToken) . "', merchant_id='" . $db->real_escape_string($sampleQris) . "', config=NULL, is_active=1 WHERE provider='gopay'");

$unitIds = [];
$r = $db->query('SELECT id FROM units ORDER BY id LIMIT 2');
while ($row = $r->fetch_row()) $unitIds[] = (int) $row[0];
$ok(count($unitIds) >= 2, 'minimal 2 unit tersedia');

$label = 'TESTCOL-' . date('His');
$st = $db->prepare("INSERT INTO billing_periods (period_year,period_month,label,due_date) VALUES (2098,?,?,CURDATE())");
$month = (int) date('n');
$st->bind_param('is', $month, $label);
$st->execute();
$periodId = (int) $db->insert_id;

$mkBill = function (int $unitId) use ($db, &$periodId) {
    $s = $db->prepare('INSERT INTO bills (billing_period_id,unit_id,resident_id,amount,total_amount,status,due_date,notes) VALUES (?,?,NULL,250000,250000,"belum_bayar",CURDATE(),"TESTCOL")');
    $s->bind_param('ii', $periodId, $unitId);
    $s->execute();
    return (int) $db->insert_id;
};

$ch = gateway_channel('gopay');
$ok($ch !== null, 'kanal gopay aktif');
$charge = gateway_charge_total(250000.0); // 250.000 + 1.000 fee

// Dua order untuk dua tagihan yang nominalnya identik.
$o1 = $prefix . '-A'; $b1 = $mkBill($unitIds[0]);
$o2 = $prefix . '-B'; $b2 = $mkBill($unitIds[1]);

$r1 = gq_create($ch, $o1, $charge, 'Tes tabrakan A');
$r2 = gq_create($ch, $o2, $charge, 'Tes tabrakan B');
$ok($r1['ok'] && $r2['ok'], 'dua order QRIS dibuat');

gateway_store_order((int) $ch['id'], $o1, $b1, null, $charge, $r1['data']);
gateway_store_order((int) $ch['id'], $o2, $b2, null, $charge, $r2['data']);
$a1 = gateway_find_order($o1);
$a2 = gateway_find_order($o2);

$ok((float) $a1['total_amount'] !== (float) $a2['total_amount'],
    'nominal kedua order berbeda (kode unik: ' . $a1['total_amount'] . ' vs ' . $a2['total_amount'] . ')');
$ok((float) $a1['total_amount'] > $charge && (float) $a2['total_amount'] > $charge,
    'kode unik ada di atas biaya layanan');

// Rincian buku kas: hanya tagihan + biaya layanan, kode unik tidak masuk.
$split = gq_split((float) $a1['total_amount'], 250000.0, 1000.0);
$ok($split['fee'] === 1000.0, 'biaya layanan terhitung 1.000');
$ok($split['unique'] > 0, 'kode unik terdeteksi (' . $split['unique'] . ')');
$ok($split['charge'] === 1000.0 + $split['unique'], 'charge = biaya layanan + kode unik');

if (!$hit("$MOCK/test/PAY/0")) {
    $cleanup();
    fwrite(STDERR, "SKIP: mock server tidak jalan di $MOCK\n");
    exit(1);
}

// Warga A membayar nominal order-nya.
$ok($hit("$MOCK/test/TX/TXA/" . (int) $a1['total_amount']), 'mock mencatat transaksi warga A');

$sA = gateway_status($ch, $o1);
$ok($sA['ok'] && gateway_is_paid($sA['data']), 'order A lunas');
$sB = gateway_status($ch, $o2);
$ok($sB['ok'] && !gateway_is_paid($sB['data']), 'order B tetap PENDING (tidak ikut lunas) ← bug lama');

// Finalisasi A: jumlah masuk buku kas = tagihan, bukan nominal QRIS.
$fin = gateway_finalize($o1, (float) $sA['data']['total_amount']);
$ok($fin['ok'] && $fin['changed'], 'finalize A sukses');
$paid = (float) $db->query('SELECT amount_paid FROM payments WHERE id=' . (int) $fin['payment_id'])->fetch_row()[0];
$ok($paid === 250000.0, 'amount_paid = 250.000 (kode unik tidak dicatat)');
$cashIn = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE ref_payment_id=" . (int) $fin['payment_id'])->fetch_row()[0];
$ok($cashIn === 251000.0, 'buku kas = tagihan 250.000 + biaya layanan 1.000 (kode unik tidak masuk), dapat ' . $cashIn);
$note = (string) $db->query('SELECT notes FROM payments WHERE id=' . (int) $fin['payment_id'])->fetch_row()[0];
$ok(str_contains($note, 'kode unik'), 'kode unik tercatat di catatan pembayaran');
$ok($db->query('SELECT status FROM bills WHERE id=' . $b2)->fetch_row()[0] === 'belum_bayar', 'tagihan B tidak tersentuh');

// Klaim ganda: transaksi yang sama tidak boleh melunasi order lain.
$claimOther = gq_claim($a2, ['transaction_id' => 'TXA']);
$ok($claimOther === false, 'transaksi warga A ditolak untuk order B');
gateway_mark_expired($o2);
$ok($db->query("SELECT status FROM payment_gateway_orders WHERE order_id='$o2'")->fetch_row()[0] === 'EXPIRED', 'order B di-expire saat ditutup');

// Transaksi lama tidak boleh mencocokkan order baru (filter waktu).
$ok($hit("$MOCK/test/PAY/0"), 'mock mereset state');
// Periode baru: uq_bill unik per (periode, unit), unit yang sama tak bisa dipakai ulang.
$st = $db->prepare("INSERT INTO billing_periods (period_year,period_month,label,due_date) VALUES (2096,?,?,CURDATE())");
$label2 = $label . '-B';
$st->bind_param('is', $month, $label2);
$st->execute();
$periodId = (int) $db->insert_id;
$o3 = $prefix . '-C'; $b3 = $mkBill($unitIds[0]);
$r3 = gq_create($ch, $o3, $charge, 'Tes waktu');
gateway_store_order((int) $ch['id'], $o3, $b3, null, $charge, $r3['data']);
$a3 = gateway_find_order($o3);
$old = time() - 86400;
$ok($hit("$MOCK/test/TX/TXOLD/" . (int) $a3['total_amount'] . "/$old"), 'mock mencatat transaksi kemarin dengan nominal sama');
$s3 = gateway_status($ch, $o3);
$ok($s3['ok'] && !gateway_is_paid($s3['data']), 'transaksi lama tidak melunasi order baru');

$cleanup();
is_file($state) && @unlink($state);
$db->query("UPDATE payment_channels SET is_active=0 WHERE provider='gopay'");
echo "gateway_collision_test OK ($n asersi)\n";
