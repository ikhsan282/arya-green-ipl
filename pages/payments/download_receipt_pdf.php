<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('payments.view');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

$stmt = $db->prepare(
    'SELECT p.*, b.total_amount AS bill_total, bp.label AS period, bp.period_year, bp.period_month,
            u.unit_number, u.block, r.name AS resident_name, r.phone AS resident_phone,
            pm.name AS payment_method_name, vu.name AS verifier_name, e.name AS env_name
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     LEFT JOIN users vu ON vu.id=p.verified_by
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     LEFT JOIN environments e ON e.id=1
     WHERE p.id=?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
if (!$pay) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

// Warga hanya boleh download kwitansi sendiri
if (auth_role() === 'warga') {
    $chk = $db->prepare('SELECT 1 FROM bills b LEFT JOIN residents r ON r.id=b.resident_id WHERE b.id=? AND r.user_id=?');
    $uid = auth_id();
    $chk->bind_param('ii', $pay['bill_id'], $uid);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        flash('error', 'Akses ditolak.'); redirect(APP_URL.'/pages/payments/index.php');
    }
}

// Load TCPDF
require_once __DIR__ . '/../../vendor/tcpdf/tcpdf.php';

// Receipt number
$receipt_no = sprintf('KWT/%04d/%02d/%05d', $pay['period_year'], $pay['period_month'], $pay['id']);

// Create PDF — A5 portrait, layout kwitansi satu halaman
$pdf = new TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
$pdf->SetCreator('Arya Green IPL System');
$pdf->SetAuthor('Arya Green Pamulang');
$pdf->SetTitle('Kwitansi Pembayaran IPL');
$pdf->SetSubject('Kwitansi #' . $receipt_no);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();
$pdf->SetFont('dejavusans', '', 9.5);

// Lebar area cetak A5 (148 - 2*12 = 124mm)
$W = 124;

// Logo — hanya kalau PNG-nya bisa dibaca tanpa GD/Imagick.
// TCPDF butuh GD/Imagick untuk PNG ber-alpha (truecolor+alpha / palette+tRNS);
// tanpa itu lempar TCPDF ERROR dan seluruh kwitansi gagal. Server cPanel bisa
// punya GD, dev tidak — jadi logo dilewati, bukan menggagalkan dokumen.
$logo_path = __DIR__ . '/../../assets/images/emblem.png';
$logo_ok = false;
if (file_exists($logo_path)) {
    $meta = @getimagesize($logo_path);
    $is_png = $meta && $meta[2] === IMAGETYPE_PNG;
    $has_alpha = $is_png && in_array((ord(file_get_contents($logo_path, false, null, 25, 1))), [4, 6], true);
    $has_trns  = $is_png && strpos(file_get_contents($logo_path), 'tRNS') !== false;
    $logo_ok = $is_png && !$has_alpha && !$has_trns;
}
if ($logo_ok) $pdf->Image($logo_path, 12, 11, 16, 16, 'PNG');

// Header (teks bergeser kalau ada logo)
$x0 = $logo_ok ? 32 : 12;
$pdf->SetXY($x0, 11);
$pdf->SetFont('dejavusans', 'B', 13);
$pdf->Cell($W - ($x0 - 12), 5, ($pay['env_name'] ?? 'ARYA GREEN PAMULANG'), 0, 1, 'L');
$pdf->SetX($x0);
$pdf->SetFont('dejavusans', '', 8);
$pdf->Cell($W - ($x0 - 12), 4, 'Iuran Pengelolaan Lingkungan (IPL)', 0, 1, 'L');
$pdf->SetX($x0);
$pdf->Cell($W - ($x0 - 12), 4, 'RT 005 / RW 020 — Pamulang Barat, Tangerang Selatan', 0, 1, 'L');

// Garis pemisah
$pdf->Ln(2);
$y = $pdf->GetY();
$pdf->SetDrawColor(30, 30, 30);
$pdf->SetLineWidth(0.4);
$pdf->Line(12, $y, 12 + $W, $y);
$pdf->Ln(4);

// Judul
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell($W, 6, 'KWITANSI PEMBAYARAN', 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 8);
$pdf->Cell($W, 4, 'No. ' . $receipt_no, 0, 1, 'C');
$pdf->Ln(3);

// Baris detail: label kiri (40mm) | titik dua | nilai
$row = function (string $label, string $value, bool $bold = false) use ($pdf, $W) {
    $x = 12;
    $pdf->SetX($x);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->Cell(38, 6, $label, 0, 0, 'L');
    $pdf->Cell(4, 6, ':', 0, 0, 'C');
    $pdf->SetFont('dejavusans', $bold ? 'B' : '', 9);
    $pdf->MultiCell($W - 42, 6, $value, 0, 'L', false, 1);
};

$unit_label = trim(($pay['block'] ?? '') . '-' . ($pay['unit_number'] ?? ''), '-');

$row('Telah terima dari', $pay['resident_name'] ?? '-', true);
$row('Unit', $unit_label, true);
$row('Periode Tagihan', $pay['period']);
$row('Untuk pembayaran', 'Iuran Pemeliharaan Lingkungan (IPL)');

// Kotak jumlah
$pdf->Ln(2);
$pdf->SetX(12);
$pdf->SetFont('dejavusans', 'B', 9);
$pdf->Cell(38, 10, 'Jumlah', 0, 0, 'L');
$pdf->Cell(4, 10, ':', 0, 0, 'C');
$pdf->SetFillColor(238, 244, 240);
$pdf->SetDrawColor(25, 135, 84);
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->Cell($W - 42, 10, idr((float)$pay['amount_paid']), 1, 1, 'L', true);
$pdf->SetDrawColor(30, 30, 30);
$pdf->SetLineWidth(0.2);
$pdf->Ln(2);

// Detail pembayaran
$row('Metode Pembayaran', $pay['payment_method_name'] ?? ($pay['payment_method'] ?: 'Transfer'));
if ($pay['bank_name'])       $row('Bank', $pay['bank_name']);
if ($pay['reference_no'])    $row('No. Referensi', $pay['reference_no']);
$row('Tanggal Bayar', date('d F Y', strtotime($pay['payment_date'])));
if ($pay['verifier_name'])   $row('Diverifikasi oleh', $pay['verifier_name']);

// Terbilang
$pdf->Ln(1);
$pdf->SetX(12);
$pdf->SetFont('dejavusans', 'I', 8.5);
$pdf->MultiCell($W, 5, 'Terbilang: ' . terbilang((float)$pay['amount_paid']) . ' Rupiah', 0, 'L');

$pdf->Ln(4);

// Tanda tangan — kanan, blok tetap
$pdf->SetFont('dejavusans', '', 9);
$pdf->Cell($W, 5, 'Pamulang, ' . date('d F Y'), 0, 1, 'R');
$pdf->Cell($W, 5, 'Bendahara', 0, 1, 'R');
$pdf->Ln(13);
$pdf->Cell($W, 5, '( ________________________ )', 0, 1, 'R');

// Footer
$pdf->Ln(3);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(12, $pdf->GetY(), 12 + $W, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('dejavusans', 'I', 7.5);
$pdf->SetTextColor(110, 110, 110);
$pdf->MultiCell($W, 4, 'Kwitansi ini sah tanpa tanda tangan basah. Dicetak otomatis pada '
    . date('d/m/Y H:i') . ' — ' . ($pay['env_name'] ?? 'Arya Green Pamulang'), 0, 'C');

// Output
$filename = 'Kwitansi_' . str_replace(['/', ' '], ['_', '_'], $receipt_no) . '.pdf';
$pdf->Output($filename, 'I');
exit;
