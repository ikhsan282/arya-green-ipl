<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('payments.view');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

$stmt = $db->prepare(
    'SELECT p.*, b.total_amount AS bill_total, bp.label AS period,
            u.unit_number, u.block, r.name AS resident_name, r.phone AS resident_phone,
            pm.name AS payment_method_name,
            vu.name AS verifier_name, cu.name AS created_by_name,
            (SELECT sig.signature_file FROM users sig JOIN roles sr ON sr.id=sig.role_id WHERE sr.name="bendahara" AND sig.is_active=1 ORDER BY sig.id LIMIT 1) AS treasurer_signature,
            (SELECT sig.name FROM users sig JOIN roles sr ON sr.id=sig.role_id WHERE sr.name="bendahara" AND sig.is_active=1 ORDER BY sig.id LIMIT 1) AS treasurer_name,
            e.name AS env_name
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     LEFT JOIN users vu ON vu.id=p.verified_by
     LEFT JOIN users cu ON cu.id=p.user_id
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     LEFT JOIN environments e ON e.id=1
     WHERE p.id=?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
if (!$pay) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

// Warga hanya boleh cetak kwitansi miliknya sendiri
if (auth_role() === 'warga') {
    $chk = $db->prepare('SELECT 1 FROM bills b LEFT JOIN residents r ON r.id=b.resident_id WHERE b.id=? AND r.user_id=?');
    $uid = auth_id();
    $chk->bind_param('ii', $pay['bill_id'], $uid);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        flash('error', 'Akses ditolak.'); redirect(APP_URL.'/pages/payments/index.php');
    }
}

$treasurerSignature = signature_image_data_uri($pay['treasurer_signature'] ?? null);
$page_title = 'Cetak Kwitansi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Kwitansi Pembayaran IPL - <?= e($pay['block'].'-'.$pay['unit_number']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    /* A5 portrait = ukuran kertas satu-satunya; layar hanya pratinjau */
    @page { size: A5 portrait; margin: 8mm 9mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; color: #111; background: #f4f4f4; margin: 0; }
    .receipt-box { max-width: 148mm; margin: 0 auto; background: #fff; border: 1px solid #ccc;
                   padding: 6mm 7mm; position: relative; overflow: hidden; }
    .receipt-head { display: flex; align-items: center; gap: 6mm; border-bottom: 1.5px solid #198754; padding-bottom: 3.5mm; margin-bottom: 4mm; }
    .logo-img { height: 15mm; width: auto; }
    .org-name { font-size: 13pt; font-weight: 700; line-height: 1.15; margin: 0; }
    .org-sub { font-size: 8pt; color: #555; margin: 0.5mm 0 0; }
    .doc-title { text-align: center; font-size: 11.5pt; font-weight: 700; letter-spacing: .04em; margin: 0 0 1mm; }
    .doc-no { text-align: center; font-size: 8.5pt; color: #666; margin-bottom: 4mm; }
    .kv { width: 100%; border-collapse: collapse; font-size: 9pt; }
    .kv th { text-align: left; font-weight: 400; color: #555; width: 34mm; padding: 1mm 0; vertical-align: top; }
    .kv td.sep { width: 3mm; padding: 1mm 0; color: #999; }
    .kv td { padding: 1mm 0; vertical-align: top; }
    .kv td.val { font-weight: 600; }
    .amount-row { display: flex; align-items: center; margin: 3.5mm 0 3mm; border: 1.5px solid #198754;
                  background: #eef4f0; border-radius: 2px; }
    .amount-row .lbl { font-size: 9pt; color: #555; padding: 3mm 0 3mm 3mm; width: 34mm; }
    .amount-row .val { font-size: 13pt; font-weight: 700; letter-spacing: .01em; padding: 3mm; }
    .amount-words { font-size: 8.5pt; font-style: italic; color: #333; margin-top: 1mm; }
    .divider { border: 0; border-top: 1px solid #ddd; margin: 3.5mm 0; }
    .sign-grid { display: flex; justify-content: space-between; margin-top: 4mm; gap: 8mm; }
    .sign-cell { width: 50%; text-align: center; font-size: 9pt; }
    .sign-space { height: 14mm; }
    .sign-img { display: block; max-height: 13mm; max-width: 45mm; margin: 0 auto; object-fit: contain; }
    .sign-name { border-top: 1px solid #333; padding-top: 1.5mm; display: inline-block; min-width: 45mm; font-weight: 600; }
    .receipt-foot { border-top: 1px solid #eee; margin-top: 4mm; padding-top: 2mm;
                    font-size: 7.5pt; color: #777; text-align: center; line-height: 1.4; }
    @media print {
      .no-print { display: none !important; }
      body { background: #fff; padding: 0; }
      .receipt-box { max-width: none; border: 0; padding: 0; margin: 0; box-shadow: none; }
      .receipt-head { page-break-after: avoid; }
      .sign-grid { page-break-inside: avoid; }
    }
    @media screen { .receipt-box { box-shadow: 0 2px 12px rgba(0,0,0,.12); margin-top: 1.5rem; margin-bottom: 1.5rem; } }
  </style>
</head>
<body>
  <?php $unit_label = trim(($pay['block'] ?? '') . '-' . ($pay['unit_number'] ?? ''), '-'); ?>
  <div class="container no-print py-3 text-end">
    <button class="btn btn-primary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i> Cetak / Simpan PDF
    </button>
    <a href="<?= APP_URL ?>/pages/payments/detail.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Kembali</a>
  </div>

  <div class="receipt-box">
    <!-- Kop -->
    <div class="receipt-head">
      <?php if (file_exists(__DIR__ . '/../../assets/images/logo.png')): ?>
        <img src="<?= APP_URL ?>/assets/images/logo.png" class="logo-img" alt="">
      <?php endif; ?>
      <div>
        <p class="org-name"><?= e($pay['env_name'] ?? 'ARYA GREEN PAMULANG') ?></p>
        <p class="org-sub">Iuran Pengelolaan Lingkungan (IPL)</p>
        <p class="org-sub">RT 005 / RW 020 — Pamulang Barat, Tangerang Selatan</p>
      </div>
    </div>

    <!-- Judul -->
    <div class="doc-title">KWITANSI PEMBAYARAN</div>
    <div class="doc-no">No. <?= e(sprintf('KWT/%04d/%02d/%05d', $pay['period_year'] ?? date('Y', strtotime($pay['payment_date'])), $pay['period_month'] ?? date('n', strtotime($pay['payment_date'])), $pay['id'])) ?></div>

    <!-- Identitas -->
    <table class="kv">
      <tr><th>Telah terima dari</th><td class="sep">:</td><td class="val"><?= e($pay['resident_name'] ?? '-') ?></td></tr>
      <tr><th>Unit</th><td class="sep">:</td><td class="val"><?= e($unit_label) ?></td></tr>
      <tr><th>Periode Tagihan</th><td class="sep">:</td><td><?= e($pay['period'] ?? '-') ?></td></tr>
      <tr><th>Untuk pembayaran</th><td class="sep">:</td><td>Iuran Pemeliharaan Lingkungan (IPL)</td></tr>
    </table>

    <!-- Jumlah -->
    <div class="amount-row">
      <div class="lbl">Jumlah</div>
      <div class="val"><?= idr((float)$pay['amount_paid']) ?></div>
    </div>
    <div class="amount-words">Terbilang: <strong><?= terbilang((float)$pay['amount_paid']) ?> Rupiah</strong></div>

    <hr class="divider">

    <!-- Detail pembayaran -->
    <table class="kv">
      <tr><th>Metode Pembayaran</th><td class="sep">:</td><td><?= e($pay['payment_method_name'] ?? ($pay['payment_method'] ?: '-')) ?><?= $pay['bank_name'] ? ' — ' . e($pay['bank_name']) : '' ?></td></tr>
      <?php if ($pay['reference_no']): ?>
      <tr><th>No. Referensi</th><td class="sep">:</td><td><?= e($pay['reference_no']) ?></td></tr>
      <?php endif; ?>
      <tr><th>Tanggal Bayar</th><td class="sep">:</td><td><?= fmt_date($pay['payment_date'], 'd F Y') ?></td></tr>
      <?php if ($pay['verifier_name']): ?>
      <tr><th>Diverifikasi oleh</th><td class="sep">:</td><td><?= e($pay['verifier_name']) ?> <span style="color:#777">(<?= fmt_date($pay['verified_at'], 'd M Y H:i') ?>)</span></td></tr>
      <?php else: ?>
      <tr><th>Status</th><td class="sep">:</td><td style="color:#b8860b;font-weight:600"><?= e(ucfirst($pay['status'])) ?></td></tr>
      <?php endif; ?>
    </table>

    <!-- Tanda tangan -->
    <div class="sign-grid">
      <div class="sign-cell">
        <div>Warga / Penyetor</div>
        <div class="sign-space"></div>
        <div class="sign-name"><?= e($pay['resident_name'] ?? '&nbsp;') ?></div>
      </div>
      <div class="sign-cell">
        <div>Pamulang, <?= fmt_date(date('Y-m-d'), 'd F Y') ?></div>
        <div style="font-size:9pt">Bendahara</div>
        <div class="sign-space" style="height:12mm"><?php if ($treasurerSignature): ?><img class="sign-img" src="<?= e($treasurerSignature) ?>" alt="Tanda tangan Bendahara"><?php endif; ?></div>
        <div class="sign-name"><?= e($pay['treasurer_name'] ?? $pay['verifier_name'] ?? '&nbsp;') ?></div>
      </div>
    </div>

    <div class="receipt-foot">
      Kwitansi ini sah sebagai bukti pembayaran resmi IPL <?= e($pay['env_name'] ?? 'Arya Green Pamulang') ?>.<br>
      Dicetak pada <?= date('d/m/Y H:i') ?>.
    </div>
  </div>

  <script>
    // Auto-print jika parameter ?print=1
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('print') === '1') {
      window.onload = () => window.print();
    }
  </script>
</body>
</html>