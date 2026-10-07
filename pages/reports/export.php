<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db      = db();
$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? date('n'));

$stmt = $db->prepare(
    'SELECT u.block, u.unit_number, ut.name AS type_name,
            r.name AS resident_name, r.phone,
            b.amount, b.fine_amount, b.total_amount, b.status, b.paid_date
     FROM bills b
     JOIN units u ON u.id=b.unit_id
     JOIN unit_types ut ON ut.id=u.unit_type_id
     LEFT JOIN residents r ON r.id=b.resident_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=?
     ORDER BY u.block, u.unit_number'
);
$stmt->bind_param('ii', $f_year, $f_month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$label    = bulan_indo($f_month) . '_' . $f_year;
$filename = "laporan_ipl_{$label}.csv";

header_csv_download($filename);

$out = fopen('php://output', 'w');
// UTF-8 BOM agar Excel baca karakter Indonesia dengan benar
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['No','Unit','Tipe','Nama Warga','No. HP','IPL','Denda','Total','Status','Tgl Bayar']);

$status_map = [
    'belum_bayar' => 'Belum Bayar',
    'sudah_bayar' => 'Sudah Bayar',
    'terlambat'   => 'Terlambat',
];

foreach ($rows as $i => $r) {
    fputcsv($out, [
        $i + 1,
        $r['block'] . '-' . $r['unit_number'],
        $r['type_name'],
        $r['resident_name'] ?? '-',
        $r['phone'] ?? '-',
        (float)$r['amount'],
        (float)$r['fine_amount'],
        (float)$r['total_amount'],
        $status_map[$r['status']] ?? $r['status'],
        $r['paid_date'] ? date('d/m/Y', strtotime($r['paid_date'])) : '-',
    ]);
}

fclose($out);
exit;
