<?php
// Download template CSV untuk import warga
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="template_import_warga.csv"');

$out = fopen('php://output', 'w');
// BOM untuk Excel agar UTF-8 terbaca
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['unit_number','block','nama','telepon','email','status']);
// Contoh data
fputcsv($out, ['A01','A','Budi Santoso','08123456789','budi@email.com','pemilik']);
fputcsv($out, ['A02','A','Siti Rahayu','08234567890','siti@email.com','penyewa']);
fputcsv($out, ['B01','B','Ahmad Dahlan','08345678901','','pemilik']);
fclose($out);
exit;
