<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db     = db();
$f_year = (int)($_GET['year'] ?? date('Y'));

$stmt = $db->prepare(
    'SELECT MONTH(trx_date) AS m,
            COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END), 0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END), 0) AS keluar
     FROM cash_book
     WHERE YEAR(trx_date) = ?
     GROUP BY MONTH(trx_date)
     ORDER BY m'
);
$stmt->bind_param('i', $f_year);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$by_month = array_column($rows, null, 'm');

$filename = 'arus_kas_' . $f_year . '.csv';
header_csv_download($filename);

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Bulan', 'Pemasukan', 'Pengeluaran', 'Surplus/Defisit', 'Saldo Kumulatif']);

$cumulative = 0;
for ($m = 1; $m <= 12; $m++) {
    $masuk  = (float)($by_month[$m]['masuk']  ?? 0);
    $keluar = (float)($by_month[$m]['keluar'] ?? 0);
    $net    = $masuk - $keluar;
    $cumulative += $net;
    fputcsv($out, [bulan_indo($m).' '.$f_year, $masuk, $keluar, $net, $cumulative]);
}
fclose($out);
exit;
