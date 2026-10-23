<?php
/**
 * Tes Rekap Pembayaran: angka di halaman harus cocok dengan data yang disiapkan.
 * Membuat periode uji sendiri, menghapusnya lagi di akhir (idempotent).
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$db = db();
$asserts = 0;
function check(string $l, bool $c): void { global $asserts; $asserts++; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } echo "ok - $l\n"; }
function idr_t(float $v): float { return round($v, 2); }

$Y = 2085; $M = 3;
$cleanup = function () use ($db, $Y, $M) {
    $db->query("DELETE p FROM payments p JOIN bills b ON b.id=p.bill_id JOIN billing_periods bp ON bp.id=b.billing_period_id WHERE bp.period_year=$Y AND bp.period_month=$M");
    $db->query("DELETE o FROM payment_gateway_orders o JOIN bills b ON b.id=o.bill_id JOIN billing_periods bp ON bp.id=b.billing_period_id WHERE bp.period_year=$Y AND bp.period_month=$M");
    $db->query("DELETE b FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id WHERE bp.period_year=$Y AND bp.period_month=$M");
    $db->query("DELETE FROM billing_periods WHERE period_year=$Y AND period_month=$M");
};
$cleanup();

// periode uji
$db->query("INSERT INTO billing_periods (period_year, period_month, label, due_date) VALUES ($Y, $M, 'REKAPTEST $Y-$M', '$Y-$M-10')");
$periodId = (int)$db->insert_id;
$units = $db->query('SELECT id FROM units ORDER BY id LIMIT 4')->fetch_all(MYSQLI_ASSOC);
if (count($units) < 4) { fwrite(STDERR, "SKIP: butuh minimal 4 unit\n"); $cleanup(); exit(0); }

// 4 tagihan @ 300.000 = total 1.200.000
$billIds = []; $bills = [];
foreach ($units as $i => $u) {
    $amount = 300000; $total = 300000; $status = 'belum_bayar';
    $db->query("INSERT INTO bills (billing_period_id, unit_id, amount, total_amount, status, due_date) VALUES ($periodId," . (int)$u['id'] . ",$amount,$total,'$status','$Y-$M-10')");
    $billIds[] = (int)$db->insert_id;
}
// tagihan 1 & 2 ditandai lunas (sumber angka "terbayar lunas")
$db->query("UPDATE bills SET status='sudah_bayar' WHERE id IN ({$billIds[0]}, {$billIds[1]})");

$pmCash = $db->query("SELECT id FROM payment_methods WHERE code NOT LIKE 'qris-%' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$pmCash) { $db->query("INSERT INTO payment_methods (code,name,is_active) VALUES ('tunai-test','Tunai Test',1)"); $pmCash = ['id' => $db->insert_id]; }
$pmQris = $db->query("SELECT id FROM payment_methods WHERE code LIKE 'qris-%' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$pmQris) { $db->query("INSERT INTO payment_methods (code,name,is_active) VALUES ('qris-test','QRIS Test',1)"); $pmQris = ['id' => $db->insert_id]; }
$cashId = (int)$pmCash['id']; $qrisId = (int)$pmQris['id'];

// pembayaran verified: 300.000 tunai + 300.000 QRIS = 600.000
// pembayaran pending: 300.000  (uang di jalan)
// pembayaran rejected: 100.000 (tidak boleh masuk hitungan uang)
$db->query("INSERT INTO payments (bill_id,payment_date,amount_paid,payment_method_id,payment_method,status) VALUES ({$billIds[0]},CURDATE(),300000,$cashId,'tunai','verified')");
$db->query("INSERT INTO payments (bill_id,payment_date,amount_paid,payment_method_id,payment_method,status) VALUES ({$billIds[1]},CURDATE(),300000,$qrisId,'qris','verified')");
$db->query("INSERT INTO payments (bill_id,payment_date,amount_paid,payment_method_id,payment_method,status) VALUES ({$billIds[2]},CURDATE(),300000,$cashId,'tunai','pending')");
$db->query("INSERT INTO payments (bill_id,payment_date,amount_paid,payment_method_id,payment_method,status) VALUES ({$billIds[3]},CURDATE(),100000,$cashId,'tunai','rejected')");

// order QRIS: PAID 300.000, EXPIRED 250.002, FAILED 0
$ch = (int)$db->query('SELECT id FROM payment_channels ORDER BY id LIMIT 1')->fetch_row()[0];
$mk = function (string $oid, int $bill, float $amt, string $st, ?float $tot = null) use ($db, $ch) {
    $t = $tot ?? $amt;
    $db->query("INSERT INTO payment_gateway_orders (channel_id,order_id,bill_id,amount,total_amount,status,created_at) VALUES ($ch,'$oid',$bill,$amt,$t,'$st',NOW())");
};
$mk("REKAP-PAID-$periodId", $billIds[1], 300000, 'PAID', 300000);
$mk("REKAP-EXP-$periodId",  $billIds[2], 250002, 'EXPIRED', 250002);
$mk("REKAP-FAIL-$periodId", $billIds[3], 0, 'FAILED', 0);

// ── hitung ulang logika halaman ───────────────────────────────────────────────
$st = $db->prepare('SELECT COUNT(*) total_bills,
   COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) lunas_nilai,
   COALESCE(SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount END),0) tunggakan,
   COALESCE(SUM(b.total_amount),0) total_tagihan
   FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id WHERE bp.period_year=? AND bp.period_month=?');
$st->bind_param('ii', $Y, $M); $st->execute(); $tagihan = $st->get_result()->fetch_assoc();

$st = $db->prepare('SELECT p.status, COUNT(*) cnt, COALESCE(SUM(p.amount_paid),0) total FROM payments p
   JOIN bills b ON b.id=p.bill_id JOIN billing_periods bp ON bp.id=b.billing_period_id
   WHERE bp.period_year=? AND bp.period_month=? GROUP BY p.status');
$st->bind_param('ii', $Y, $M); $st->execute();
$by = ['pending' => [0, 0.0], 'verified' => [0, 0.0], 'rejected' => [0, 0.0]];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $by[$r['status']] = [(int)$r['cnt'], (float)$r['total']];

$st = $db->prepare('SELECT o.status, COUNT(*) cnt, COALESCE(SUM(COALESCE(o.total_amount,o.amount)),0) total
   FROM payment_gateway_orders o LEFT JOIN bills b ON b.id=o.bill_id LEFT JOIN billing_periods bp ON bp.id=b.billing_period_id
   WHERE (bp.period_year=? AND bp.period_month=?) OR (o.bill_id IS NULL AND YEAR(o.created_at)=? AND MONTH(o.created_at)=?)
   GROUP BY o.status');
$st->bind_param('iiii', $Y, $M, $Y, $M); $st->execute();
$ord = ['PENDING' => [0,0.0], 'PAID' => [0,0.0], 'EXPIRED' => [0,0.0], 'FAILED' => [0,0.0]];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $ord[$r['status']] = [(int)$r['cnt'], (float)$r['total']];

$st = $db->prepare('SELECT COALESCE(pm.code,"") LIKE "qris-%" is_qris, COUNT(*) cnt, COALESCE(SUM(p.amount_paid),0) total
   FROM payments p JOIN bills b ON b.id=p.bill_id JOIN billing_periods bp ON bp.id=b.billing_period_id
   LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
   WHERE bp.period_year=? AND bp.period_month=? AND p.status="verified" GROUP BY is_qris');
$st->bind_param('ii', $Y, $M); $st->execute();
$qrisTotal = 0.0; $nonQrisTotal = 0.0;
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { if ($r['is_qris']) $qrisTotal = (float)$r['total']; else $nonQrisTotal = (float)$r['total']; }

try {
    check('total tagihan = 4 x 300.000', idr_t((float)$tagihan['total_tagihan']) == 1200000.0);
    check('terbayar lunas = 600.000', idr_t((float)$tagihan['lunas_nilai']) == 600000.0);
    check('tunggakan = 600.000', idr_t((float)$tagihan['tunggakan']) == 600000.0);

    check('verified: 2 transaksi 600.000', $by['verified'][0] === 2 && idr_t($by['verified'][1]) == 600000.0);
    check('pending: 1 transaksi 300.000', $by['pending'][0] === 1 && idr_t($by['pending'][1]) == 300000.0);
    check('rejected: 1 transaksi 100.000', $by['rejected'][0] === 1 && idr_t($by['rejected'][1]) == 100000.0);

    check('order PAID: 1 x 300.000', $ord['PAID'][0] === 1 && idr_t($ord['PAID'][1]) == 300000.0);
    check('order EXPIRED: 1 x 250.002', $ord['EXPIRED'][0] === 1 && idr_t($ord['EXPIRED'][1]) == 250002.0);
    check('order FAILED terhitung', $ord['FAILED'][0] === 1);

    check('QRIS terverifikasi 300.000', idr_t($qrisTotal) == 300000.0);
    check('non-QRIS terverifikasi 300.000', idr_t($nonQrisTotal) == 300000.0);

    // Order QRIS hanya bisa dibandingkan dengan pembayaran QRIS, bukan seluruh pembayaran.
    check('selisih QRIS lunas vs QRIS terverifikasi = 0', idr_t($ord['PAID'][1] - $qrisTotal) == 0.0);
    check('pending TIDAK ikut terkumpul', $by['pending'][1] > 0 && idr_t((float)$tagihan['lunas_nilai']) == 600000.0);
    check('rejected TIDAK dihitung sebagai uang', idr_t($by['rejected'][1]) == 100000.0 && idr_t((float)$tagihan['lunas_nilai']) == 600000.0);

    // EXPIRED/FAILED tidak boleh menambah uang masuk
    check('order gagal/kedaluwarsa bukan pemasukan',
        idr_t((float)$tagihan['lunas_nilai']) == idr_t($by['verified'][1]));

    echo "\nrekap_pembayaran_test OK ($asserts asersi)\n";
} finally {
    $cleanup();
    $db->query("DELETE FROM payment_methods WHERE code IN ('tunai-test','qris-test')");
    echo "cleanup: data uji dihapus\n";
}
