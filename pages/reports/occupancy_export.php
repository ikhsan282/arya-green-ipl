<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db       = db();
$f_block  = clean($_GET['block']  ?? '');
$f_type   = (int)($_GET['type']   ?? 0);
$valid_statuses = ['dihuni','kosong','dijual'];
$f_statuses = array_values(array_intersect((array)($_GET['status'] ?? []), $valid_statuses));
$f_q      = clean($_GET['q']      ?? '');

$where  = '1=1';
$params = [];
$types  = '';
if ($f_block  !== '') { $where .= ' AND u.block = ?';         $params[] = $f_block;  $types .= 's'; }
if ($f_type    > 0)   { $where .= ' AND u.unit_type_id = ?';  $params[] = $f_type;   $types .= 'i'; }
if ($f_statuses) {
    $in = implode(',', array_fill(0, count($f_statuses), '?'));
    $where .= " AND u.status IN ({$in})";
    array_push($params, ...$f_statuses);
    $types .= str_repeat('s', count($f_statuses));
}
if ($f_q !== '') {
    $where .= ' AND (u.unit_number LIKE ? OR u.block LIKE ? OR r.name LIKE ?)';
    $like = "%{$f_q}%";
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}

$stmt = $db->prepare(
    "SELECT u.block, u.unit_number, u.status AS unit_status, u.area_sqm,
            ut.name AS type_name,
            r.name AS resident_name, r.phone, r.phone_secondary, r.status AS resident_status
     FROM units u
     JOIN unit_types ut ON ut.id = u.unit_type_id
     LEFT JOIN residents r ON r.unit_id = u.id AND r.is_active = 1
     WHERE {$where}
     ORDER BY u.block, u.unit_number"
);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$lbl = ['dihuni'=>'Dihuni','kosong'=>'Kosong','dijual'=>'Dijual'];

$data = [['No','Unit','Blok','Tipe','Luas (m²)','Status Unit','Warga','No. HP Utama','No. HP Kedua','Status Huni']];
foreach ($rows as $i => $r) {
    $data[] = [
        $i + 1,
        $r['block'] . '-' . $r['unit_number'],
        $r['block'],
        $r['type_name'],
        (float)$r['area_sqm'],
        $lbl[$r['unit_status']] ?? $r['unit_status'],
        $r['resident_name'] ?? '-',
        $r['phone']          ?? '-',
        $r['phone_secondary'] ?? '-',
        $r['resident_name']  ? ($r['resident_status'] ?? '-') : '-',
    ];
}

if (($_GET['format'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../../includes/xlsx.php';
    xlsx_download('laporan_unit_' . date('Ymd') . '.xlsx', ['Data Unit' => $data]);
}

header_csv_download('laporan_unit_' . date('Ymd') . '.csv');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach ($data as $row) fputcsv($out, $row);
fclose($out);
exit;
