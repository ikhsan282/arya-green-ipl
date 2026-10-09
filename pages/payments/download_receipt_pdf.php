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
            pm.name AS payment_method_name, vu.name AS verifier_name
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     LEFT JOIN users vu ON vu.id=p.verified_by
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
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

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
$pdf->SetCreator('Arya Green IPL System');
$pdf->SetAuthor('Arya Green Pamulang');
$pdf->SetTitle('Kwitansi Pembayaran IPL');
$pdf->SetSubject('Kwitansi #' . $receipt_no);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pdf->SetFont('dejavusans', '', 10);

// Logo
$logo_path = __DIR__ . '/../../assets/images/emblem.png';
if (file_exists($logo_path)) {
    $pdf->Image($logo_path, 15, 15, 20, 20, 'PNG');
}

// Header
$pdf->SetXY(40, 15);
$pdf->SetFont('dejavusans', 'B', 14);
$pdf->Cell(0, 6, 'ARYA GREEN PAMULANG', 0, 1, 'L');
$pdf->SetX(40);
$pdf->SetFont('dejavusans', '', 9);
$pdf->Cell(0, 5, 'RT 005 / RW 020', 0, 1, 'L');
$pdf->SetX(40);
$pdf->Cell(0, 5, 'Kelurahan Pamulang Barat, Kecamatan Pamulang', 0, 1, 'L');
$pdf->SetX(40);
$pdf->Cell(0, 5, 'Kota Tangerang Selatan, Banten', 0, 1, 'L');

// Line
$pdf->Ln(5);
$pdf->Line(15, $pdf->GetY(), 133, $pdf->GetY());
$pdf->Ln(3);

// Title
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->Cell(0, 8, 'KWITANSI PEMBAYARAN IPL', 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 9);
$pdf->Cell(0, 5, 'No: ' . $receipt_no, 0, 1, 'C');
$pdf->Ln(5);

// Details
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(45, 6, 'Telah terima dari', 0, 0, 'L');
$pdf->Cell(5, 6, ':', 0, 0, 'L');
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(0, 6, $pay['resident_name'] ?? '-', 0, 1, 'L');

$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(45, 6, 'Unit', 0, 0, 'L');
$pdf->Cell(5, 6, ':', 0, 0, 'L');
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(0, 6, $pay['block'] . '-' . $pay['unit_number'], 0, 1, 'L');

$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(45, 6, 'Periode Tagihan', 0, 0, 'L');
$pdf->Cell(5, 6, ':', 0, 0, 'L');
$pdf->Cell(0, 6, $pay['period'], 0, 1, 'L');

$pdf->Cell(45, 6, 'Untuk pembayaran', 0, 0, 'L');
$pdf->Cell(5, 6, ':', 0, 0, 'L');
$pdf->Cell(0, 6, 'Iuran Pemeliharaan Lingkungan (IPL)', 0, 1, 'L');

$pdf->Ln(3);

// Amount
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(45, 8, 'Jumlah', 0, 0, 'L');
$pdf->Cell(5, 8, ':', 0, 0, 'L');
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'Rp ' . number_format($pay['amount_paid'], 0, ',', '.'), 1, 1, 'L', true);

$pdf->Ln(2);

// Payment details
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(45, 6, 'Metode Pembayaran', 0, 0, 'L');
$pdf->Cell(5, 6, ':', 0, 0, 'L');
$pdf->Cell(0, 6, $pay['payment_method_name'] ?? ($pay['payment_method'] ?: 'Transfer'), 0, 1, 'L');

if ($pay['bank_name']) {
    $pdf->Cell(45, 6, 'Bank', 0, 0, 'L');
    $pdf->Cell(5, 6, ':', 0, 0, 'L');
    $pdf->Cell(0, 6, $pay['bank_name'], 0, 1, 'L');
}

if ($pay['reference_no']) {
    $pdf->Cell(45, 6, 'No. Referensi', 0, 0, 'L');
    $pdf->Cell(5, 6, ':', 0, 0, 'L');
    $pdf->Cell(0, 6, $pay['reference_no'], 0, 1, 'L');
}

$pdf->Cell(45, 6, 'Tanggal Bayar', 0, 0, 'L');
$pdf->Cell(5, 6, ':', 0, 0, 'L');
$pdf->Cell(0, 6, date('d F Y', strtotime($pay['payment_date'])), 0, 1, 'L');

// Terbilang
$pdf->Ln(3);
$pdf->SetFont('dejavusans', 'I', 9);
$pdf->MultiCell(0, 5, 'Terbilang: ' . terbilang((float)$pay['amount_paid']) . ' Rupiah', 0, 'L');

$pdf->Ln(10);

// Signature
$pdf->SetFont('dejavusans', '', 9);
$today = date('d F Y');
$pdf->Cell(0, 5, 'Pamulang, ' . $today, 0, 1, 'R');
$pdf->Cell(0, 5, 'Bendahara RT 005', 0, 1, 'R');

$pdf->Ln(15);
$pdf->Cell(0, 5, '( _____________________________ )', 0, 1, 'R');

$pdf->Ln(2);
$pdf->SetFont('dejavusans', 'I', 8);
$pdf->Cell(0, 5, 'Kwitansi ini sah tanpa tanda tangan basah', 0, 1, 'C');
$pdf->Cell(0, 5, 'Dicetak otomatis pada ' . date('d/m/Y H:i'), 0, 1, 'C');

// Output
$filename = 'Kwitansi_' . str_replace(['/', ' '], ['_', '_'], $receipt_no) . '.pdf';
$pdf->Output($filename, 'I');
exit;
