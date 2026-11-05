<?php
/**
 * GET /api/bot/staff_summary.php
 * Khusus pengurus aktif (Super Admin, Ketua, Bendahara, Sekretaris).
 * Menampilkan ringkasan penerimaan hari ini, progres periode terbaru, saldo kas,
 * dan jumlah pembayaran manual yang menunggu verifikasi.
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET']);
$db    = $ctx['db'];
$actor = $ctx['actor'];

if (!$actor['is_staff']) {
    bot_error(403, 'STAFF_ONLY', 'Informasi rekapitulasi ini hanya dapat diakses oleh Pengurus.');
}

// Penerimaan hari ini (verified)
$todayRow = $db->query(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_paid),0) AS total
       FROM payments WHERE status="verified" AND DATE(COALESCE(verified_at, payment_date)) = CURDATE()'
)->fetch_assoc();

// Pembayaran manual menunggu verifikasi
$pendingCount = (int) $db->query('SELECT COUNT(*) FROM payments WHERE status="pending"')->fetch_row()[0];

// Periode tagihan terbaru
$period = $db->query('SELECT * FROM billing_periods ORDER BY period_year DESC, period_month DESC LIMIT 1')->fetch_assoc();
$periodStats = null;
if ($period) {
    $pid = (int) $period['id'];
    $pRow = $db->query(
        "SELECT COUNT(*) AS total_bills,
                SUM(status='sudah_bayar') AS paid_bills,
                SUM(status IN ('belum_bayar','terlambat')) AS unpaid_bills,
                COALESCE(SUM(CASE WHEN status='sudah_bayar' THEN total_amount END),0) AS collected,
                COALESCE(SUM(CASE WHEN status IN ('belum_bayar','terlambat') THEN total_amount END),0) AS outstanding
           FROM bills WHERE billing_period_id={$pid}"
    )->fetch_assoc();
    $periodStats = [
        'period'       => $period['label'],
        'total_bills'  => (int) $pRow['total_bills'],
        'paid_bills'   => (int) $pRow['paid_bills'],
        'unpaid_bills' => (int) $pRow['unpaid_bills'],
        'collected'    => bot_money((float) $pRow['collected']),
        'outstanding'  => bot_money((float) $pRow['outstanding']),
    ];
}

// Saldo kas total
$cashRow = $db->query(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0)
          - COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS saldo
       FROM cash_book'
)->fetch_assoc();

bot_reply(200, [
    'as_of'                 => date('Y-m-d H:i'),
    'staff'                 => $actor['staff'],
    'today_verified_count'  => (int) $todayRow['cnt'],
    'today_verified_amount' => bot_money((float) $todayRow['total']),
    'pending_review_count'  => $pendingCount,
    'cash_balance'          => bot_money((float) ($cashRow['saldo'] ?? 0)),
    'current_period'        => $periodStats,
]);

