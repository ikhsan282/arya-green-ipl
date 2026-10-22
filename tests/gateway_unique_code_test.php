<?php
require_once __DIR__ . '/_cli_only.php';
/**
 * Regresi: kode unik KlikQRIS tidak boleh masuk buku kas.
 *
 * Gateway menambahkan nominal kecil (kode unik) di atas tagihan supaya
 * pembayaran bisa di-auto-match, tapi nomor itu TIDAK dicairkan ke saldo
 * merchant. Kalau ikut dicatat sebagai pemasukan, buku kas jadi lebih besar
 * dari saldo riil dan selisihnya menumpuk tiap transaksi.
 *
 * Jalankan: php -c cache/scratch/localdb/php.ini tests/gateway_unique_code_test.php
 */
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/payment_gateway.php';

$db  = db();
$ok  = 0; $fail = 0;
$t = function ($c, $m) use (&$ok, &$fail) {
    if ($c) { $ok++;  echo "ok   $m\n"; }
    else    { $fail++; echo "FAIL $m\n"; }
};

$billTotal = 300000.0;   // tagihan asli
$fee       = 1000.0;     // gateway_fee(300000) = 1000
$unique    = 137.0;      // kode unik gateway
$total     = $billTotal + $fee + $unique;

$ut = $db->query('SELECT id FROM unit_types LIMIT 1')->fetch_row()[0] ?? null;
$bp = $db->query('SELECT id FROM billing_periods LIMIT 1')->fetch_row()[0] ?? null;
if (!$ut || !$bp) { echo "SKIP: data master (unit_types/billing_periods) kosong\n"; exit(0); }

$tag = 'TEST-UNIQ-' . substr((string) mt_rand(), 0, 6);

// Bersihkan sisa run sebelumnya — test harus bisa dijalankan berulang.
$db->query("DELETE FROM cash_book WHERE ref_payment_id IN (SELECT id FROM payments WHERE bill_id=902)");
$db->query('DELETE FROM payments WHERE bill_id=902');
$db->query("DELETE FROM payment_gateway_orders WHERE order_id='{$tag}' OR bill_id=902");
$db->query('DELETE FROM bills WHERE id=902');
$db->query('DELETE FROM units WHERE id=902');

$db->query("INSERT INTO units(id,unit_number,block,unit_type_id) VALUES(902,'01','Z',{$ut})");
$db->query("INSERT INTO bills(id,billing_period_id,unit_id,resident_id,amount,fine_amount,total_amount,status,due_date)
            VALUES(902,{$bp},902,1,{$billTotal},0,{$billTotal},'belum_bayar','2026-10-10')");
$db->query("INSERT INTO payment_gateway_orders(channel_id,order_id,bill_id,user_id,amount,total_amount,status)
            VALUES(1,'{$tag}',902,1,{$total},{$total},'PENDING')");

$r = gateway_finalize($tag);
$t(!empty($r['ok']), 'finalize berhasil');
$payId = (int) ($r['payment_id'] ?? 0);

$p = $db->query("SELECT amount_paid, notes FROM payments WHERE id={$payId}")->fetch_assoc();
$t(abs((float) $p['amount_paid'] - $billTotal) < 0.01, 'payments.amount_paid = tagihan asli (tanpa biaya & kode unik)');

$kas = 0.0; $feeRow = 0.0;
$q = $db->query("SELECT category, amount FROM cash_book WHERE ref_payment_id={$payId}");
while ($x = $q->fetch_assoc()) {
    $kas += (float) $x['amount'];
    if ($x['category'] === 'Biaya QRIS') $feeRow = (float) $x['amount'];
}
$t(abs($feeRow - $fee) < 0.01, 'biaya QRIS di kas = ' . number_format($fee, 0, ',', '.') . ' (tanpa kode unik)');
$t(abs($kas - ($billTotal + $fee)) < 0.01, 'total kas = tagihan + biaya saja (selisih 0 dari saldo riil)');
$t(str_contains((string) $p['notes'], (string) (int) $unique), 'kode unik tercatat sebagai keterangan, bukan pemasukan');

// bersih-bersih
$db->query("DELETE FROM cash_book WHERE ref_payment_id={$payId}");
$db->query("DELETE FROM payments WHERE id={$payId}");
$db->query("DELETE FROM payment_gateway_orders WHERE order_id='{$tag}'");
$db->query('DELETE FROM bills WHERE id=902');
$db->query('DELETE FROM units WHERE id=902');

echo "\n" . ($fail ? "GAGAL {$fail}" : 'SEMUA LULUS') . " ({$ok} assert)\n";
exit($fail ? 1 : 0);
