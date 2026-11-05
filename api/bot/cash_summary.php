<?php
/**
 * GET /api/bot/cash_summary.php
 * Ringkasan kas lingkungan dan rincian pengeluaran untuk transparansi warga:
 * - Saldo total kas saat ini
 * - Arus kas bulan berjalan (pemasukan & pengeluaran)
 * - Rincian pengeluaran per kategori bulan ini
 * - Daftar transaksi pengeluaran terbaru (tanggal, kategori, deskripsi, nominal)
 *
 * Query params opsional:
 * - year  (int): tahun (default: tahun berjalan)
 * - month (int): bulan 1-12 (default: bulan berjalan)
 * - limit (int): jumlah pengeluaran terbaru (default: 5, max: 20)
 */
require __DIR__ . '/_bootstrap.php';

$ctx = bot_boot(['GET']);
$db  = $ctx['db'];

// Saldo kumulatif sepanjang masa
$all = $db->query(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
       FROM cash_book'
)->fetch_assoc();

$y = (int) ($_GET['year'] ?? date('Y'));
$m = (int) ($_GET['month'] ?? date('n'));
if ($m < 1 || $m > 12) $m = (int) date('n');
if ($y < 2020 || $y > 2100) $y = (int) date('Y');

// Arus kas periode yang dipilih
$st = $db->prepare(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
       FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?'
);
$st->bind_param('ii', $y, $m);
$st->execute();
$month = $st->get_result()->fetch_assoc();

// Rincian pengeluaran per kategori untuk periode ini
$stCat = $db->prepare(
    'SELECT category, SUM(amount) AS total, COUNT(*) AS count
       FROM cash_book
      WHERE type="pengeluaran" AND YEAR(trx_date)=? AND MONTH(trx_date)=?
      GROUP BY category
      ORDER BY total DESC'
);
$stCat->bind_param('ii', $y, $m);
$stCat->execute();
$resCat = $stCat->get_result();
$expensesByCategory = [];
while ($row = $resCat->fetch_assoc()) {
    $expensesByCategory[] = [
        'category' => $row['category'],
        'count'    => (int) $row['count'],
        'total'    => bot_money((float) $row['total']),
    ];
}

// Daftar pengeluaran terbaru
$limit = max(1, min(20, (int) ($_GET['limit'] ?? 5)));
$stRecent = $db->prepare(
    'SELECT id, category, amount, description, trx_date
       FROM cash_book
      WHERE type="pengeluaran"
      ORDER BY trx_date DESC, id DESC
      LIMIT ?'
);
$stRecent->bind_param('i', $limit);
$stRecent->execute();
$resRecent = $stRecent->get_result();
$recentExpenses = [];
while ($row = $resRecent->fetch_assoc()) {
    $recentExpenses[] = [
        'date'        => $row['trx_date'],
        'category'    => $row['category'],
        'description' => $row['description'] ?? '',
        'amount'      => bot_money((float) $row['amount']),
    ];
}

bot_reply(200, [
    'as_of'                      => date('Y-m-d H:i'),
    'balance'                    => bot_money((float) $all['masuk'] - (float) $all['keluar']),
    'month_label'                => period_label($y, $m),
    'month_income'               => bot_money((float) $month['masuk']),
    'month_expense'              => bot_money((float) $month['keluar']),
    'month_expenses_by_category' => $expensesByCategory,
    'recent_expenses'            => $recentExpenses,
]);
