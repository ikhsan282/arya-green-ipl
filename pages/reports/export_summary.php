<?php
// Export CSV Rekap Pembayaran — angka sama persis dengan pages/reports/payments.php.
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db = db();
$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? date('n'));

$st = $db->prepare(
    'SELECT COUNT(*) AS total_bills,
            COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) AS lunas,
            COALESCE(SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount END),0) AS tunggakan,
            COALESCE(SUM(b.total_amount),0) AS total_tagihan
     FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=?');
$st->bind_param('ii', $f_year, $f_month);
$st->execute();
$t = $st->get_result()->fetch_assoc();

$st = $db->prepare(
    'SELECT p.status, COUNT(*) AS cnt, COALESCE(SUM(p.amount_paid),0) AS total
     FROM payments p JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=? GROUP BY p.status');
$st->bind_param('ii', $f_year, $f_month);
$st->execute();
$pay = ['pending' => [0, 0], 'verified' => [0, 0], 'rejected' => [0, 0]];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $pay[$r['status']] = [(int)$r['cnt'], (float)$r['total']];

$st = $db->prepare(
    'SELECT o.status, COUNT(*) AS cnt, COALESCE(SUM(COALESCE(o.total_amount,o.amount)),0) AS total
     FROM payment_gateway_orders o
     LEFT JOIN bills b ON b.id=o.bill_id
     LEFT JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE (bp.period_year=? AND bp.period_month=?)
        OR (o.bill_id IS NULL AND YEAR(o.created_at)=? AND MONTH(o.created_at)=?)
     GROUP BY o.status');
$st->bind_param('iiii', $f_year, $f_month, $f_year, $f_month);
$st->execute();
$ord = ['PENDING' => [0, 0], 'PAID' => [0, 0], 'EXPIRED' => [0, 0], 'FAILED' => [0, 0]];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $ord[$r['status']] = [(int)$r['cnt'], (float)$r['total']];

// Total pembayaran QRIS terverifikasi (pembanding yang setara untuk order QRIS).
$st = $db->prepare(
    'SELECT COALESCE(SUM(p.amount_paid),0) AS total
     FROM payments p JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN payment_methods pm ON pm.id=p.payment_method_id
     WHERE bp.period_year=? AND bp.period_month=? AND p.status="verified" AND pm.code LIKE "qris-%"');
$st->bind_param('ii', $f_year, $f_month);
$st->execute();
$qrisT = (float)$st->get_result()->fetch_row()[0];

$label = bulan_indo($f_month) . '_' . $f_year;
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rekap_pembayaran_' . $label . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8

$row = fn($k, $n, $v) => fputcsv($out, [$k, $n, $v]);
fputcsv($out, ['Rekap Pembayaran', $label]);
fputcsv($out, []);
fputcsv($out, ['Kategori', 'Jumlah', 'Nominal']);
$row('Total tagihan', (int)$t['total_bills'], (float)$t['total_tagihan']);
$row('Tagihan lunas', '', (float)$t['lunas']);
$row('Tunggakan', '', (float)$t['tunggakan']);
fputcsv($out, []);
fputcsv($out, ['Pembayaran', 'Transaksi', 'Nominal']);
$row('Terverifikasi', $pay['verified'][0], $pay['verified'][1]);
$row('Menunggu verifikasi', $pay['pending'][0], $pay['pending'][1]);
$row('Ditolak', $pay['rejected'][0], $pay['rejected'][1]);
fputcsv($out, []);
fputcsv($out, ['Order QRIS', 'Jumlah', 'Nominal']);
$row('Lunas', $ord['PAID'][0], $ord['PAID'][1]);
$row('Menunggu bayar', $ord['PENDING'][0], $ord['PENDING'][1]);
$row('Kedaluwarsa', $ord['EXPIRED'][0], $ord['EXPIRED'][1]);
$row('Gagal', $ord['FAILED'][0], $ord['FAILED'][1]);
fputcsv($out, []);
fputcsv($out, ['Selisih QRIS lunas vs QRIS terverifikasi', '', round($ord['PAID'][1] - $qrisT, 2)]);
fclose($out);
