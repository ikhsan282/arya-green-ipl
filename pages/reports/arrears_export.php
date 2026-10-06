<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db     = db();
$search = clean($_GET['q'] ?? '');

$where  = "b.status IN ('belum_bayar','terlambat')";
$params = [];
$types  = '';
if ($search) {
    $where  .= " AND (u.unit_number LIKE ? OR u.block LIKE ? OR r.name LIKE ?)";
    $like    = "%{$search}%";
    $params  = [$like, $like, $like];
    $types   = 'sss';
}

$stmt = $db->prepare(
    "SELECT u.block, u.unit_number, r.name AS resident_name, r.phone,
            COUNT(b.id)         AS jumlah_periode,
            SUM(b.total_amount) AS total_tunggakan,
            GROUP_CONCAT(bp.label ORDER BY bp.period_year, bp.period_month SEPARATOR ', ') AS daftar_periode
     FROM bills b
     JOIN units u ON u.id = b.unit_id
     JOIN billing_periods bp ON bp.id = b.billing_period_id
     LEFT JOIN residents r ON r.id = b.resident_id
     WHERE {$where}
     GROUP BY u.id, r.id
     ORDER BY jumlah_periode DESC, total_tunggakan DESC"
);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="rekap_tunggakan_' . date('Ymd') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel

fputcsv($out, ['No','Unit','Nama Warga','No. HP','Jumlah Bulan','Periode Nunggak','Total Tunggakan']);
foreach ($rows as $i => $r) {
    fputcsv($out, [
        $i + 1,
        $r['block'] . '-' . $r['unit_number'],
        $r['resident_name'] ?? '-',
        $r['phone'] ?? '-',
        $r['jumlah_periode'],
        $r['daftar_periode'],
        (float)$r['total_tunggakan'],
    ]);
}
fclose($out);
exit;
