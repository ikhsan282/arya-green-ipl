<?php
/**
 * Biaya penanganan QRIS untuk tagihan > Rp 200.000 (MDR 0,5% ditanggung pembeli).
 * Butuh DB lokal + mock KlikQRIS berjalan (lihat tests/klikqris_mock.php).
 */
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$db = db(); $ok = 0; $fail = 0;
$t = function ($c, $m) use (&$ok, &$fail) { if ($c) { $ok++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } };

// 1. Aturan biaya
$t(gateway_fee(150000) == 0,      'tagihan 150rb gratis');
$t(gateway_fee(200000) == 0,      'tagihan 200rb pas batas -> gratis');
$t(gateway_fee(200001) == 1000,   'tagihan 200.001 kena biaya');
$t(gateway_fee(500000) == 1000,   'tagihan 500rb tetap flat 1.000');
$t(gateway_charge_total(300000) == 301000, 'total tagih 300rb = 301rb');

// 2. Alur nyata: order, bayar, finalisasi
$ch = gateway_channel();
if (!$ch) { echo "SKIP: channel KlikQRIS tidak aktif\n"; exit(1); }
$u = (int) $db->query("SELECT id FROM units LIMIT 1")->fetch_row()[0];
$db->query("INSERT INTO billing_periods (period_year,period_month,label,due_date) VALUES (2097,11,'FEETEST',CURDATE())");
$p = (int) $db->insert_id;
$db->query("INSERT INTO bills (billing_period_id,unit_id,resident_id,amount,total_amount,status,due_date,notes)
            VALUES ($p,$u,NULL,300000,300000,'belum_bayar',CURDATE(),'FEETEST')");
$bill = (int) $db->insert_id;

$charge = gateway_charge_total(300000);
$oid = gateway_make_order_id($bill);
$res = gateway_create($ch, $oid, $charge, 'Tes biaya');
$t($res['ok'], 'gateway_create berhasil');
$t((int) $res['data']['amount'] === 301000, 'nominal dikirim ke gateway = 301.000');

gateway_store_order((int) $ch['id'], $oid, $bill, null, $charge, $res['data']);
$o = gateway_find_order($oid);
$fin = gateway_finalize($oid, (float) $o['total_amount']);
$t($fin['ok'], 'finalize berhasil');
$payId = (int) $fin['payment_id'];

$pay = $db->query("SELECT amount_paid,notes FROM payments WHERE id=$payId")->fetch_assoc();
$t((float) $pay['amount_paid'] === 300000.0, 'amount_paid = tagihan saja (300.000), bukan 301.000');
$t(str_contains((string) $pay['notes'], 'Otomatis via KlikQRIS'), 'notes terisi teks, bukan 0');
$t(str_contains((string) $pay['notes'], '1.000'), 'notes menyebut biaya 1.000');

$cb = $db->query("SELECT category,amount FROM cash_book WHERE ref_payment_id=$payId")->fetch_assoc();
$t($cb !== null && $cb['category'] === 'Biaya QRIS' && (float) $cb['amount'] === 1000.0,
   'biaya 1.000 masuk kas sebagai "Biaya QRIS", terpisah dari IPL');

// 3. Idempotensi: finalize ulang tidak menggandakan
$again = gateway_finalize($oid, (float) $o['total_amount']);
$t($again['ok'] && $again['changed'] === false, 'finalize ulang tidak menggandakan');
$n = (int) $db->query("SELECT COUNT(*) FROM cash_book WHERE ref_payment_id=$payId")->fetch_row()[0];
$t($n === 1, 'baris kas biaya tidak dobel');

// bersihkan
$db->query("DELETE FROM cash_book WHERE ref_payment_id=$payId");
$db->query("DELETE FROM payments WHERE id=$payId");
$db->query("DELETE FROM payment_gateway_orders WHERE order_id='$oid'");
$db->query("DELETE FROM bills WHERE id=$bill");
$db->query("DELETE FROM billing_periods WHERE id=$p");

echo "\n" . ($fail ? "GAGAL $fail" : "SEMUA LULUS") . " ($ok assert)\n";
exit($fail ? 1 : 0);
