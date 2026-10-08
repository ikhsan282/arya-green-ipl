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
    $chk->bind_param('ii', $pay['bill_id'], auth_id());
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        flash('error', 'Akses ditolak.'); redirect(APP_URL.'/pages/payments/index.php');
    }
}

$page_title = 'Cetak Kwitansi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Kwitansi Pembayaran IPL - <?= e($pay['block'].'-'.$pay['unit_number']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    @media print {
      .no-print { display: none !important; }
      body { padding: 20mm; font-size: 12pt; }
      .receipt-box { border: 2px solid #333; box-shadow: none; }
      .receipt-header { border-bottom: 2px solid #333; }
      .receipt-footer { border-top: 1px solid #333; }
    }
    @page { margin: 15mm; size: A5 portrait; }
    .receipt-box { max-width: 100%; margin: 0 auto; }
    .logo-img { max-height: 70px; }
    .amount-words { font-size: 0.85rem; font-style: italic; color: #333; }
    .signature-line { width: 180px; border-bottom: 1px solid #333; margin-top: 40px; text-align: center; }
    .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg);
                 font-size: 120px; color: rgba(0,0,0,0.05); pointer-events: none; z-index: -1; }
  </style>
</head>
<body>
  <div class="watermark">ARYA GREEN</div>
  <div class="container py-4">
    <!-- Print button -->
    <div class="text-end no-print mb-3">
      <button class="btn btn-primary" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> Cetak / Simpan PDF
      </button>
      <a href="<?= APP_URL ?>/pages/payments/detail.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Kembali</a>
    </div>

    <!-- Receipt -->
    <div class="receipt-box card p-4">
      <div class="receipt-header text-center mb-4 pb-3">
        <img src="<?= APP_URL ?>/assets/images/logo.png" class="logo-img mb-2" alt="Logo Arya Green">
        <h4 class="mb-1 fw-bold"><?= e($pay['env_name'] ?? 'ARYA GREEN PAMULANG') ?></h4>
        <p class="mb-0 text-muted small">Iuran Pengelolaan Lingkungan</p>
      </div>

      <div class="text-center mb-4">
        <span class="badge bg-success fs-6 px-3 py-2">KWITANSI PEMBAYARAN</span>
      </div>

      <table class="table table-borderless mb-0 small">
        <tr>
          <th class="text-start" style="width: 35%">Nomor Kwitansi</th>
          <td class="fw-bold"><?= e($pay['reference_no'] ?? 'KWT-'.str_pad($pay['id'], 6, '0', STR_PAD_LEFT)) ?></td>
        </tr>
        <tr>
          <th class="text-start">Tanggal Bayar</th>
          <td><?= fmt_date($pay['payment_date']) ?></td>
        </tr>
        <tr>
          <th class="text-start">Periode</th>
          <td><?= e($pay['period']) ?></td>
        </tr>
      </table>

      <hr class="my-3">

      <table class="table table-borderless mb-0 small">
        <tr>
          <th class="text-start" style="width: 35%">Unit</th>
          <td><?= e($pay['block'].'-'.$pay['unit_number']) ?></td>
        </tr>
        <tr>
          <th class="text-start">Nama Warga</th>
          <td class="fw-bold"><?= e($pay['resident_name'] ?? '-') ?></td>
        </tr>
        <tr>
          <th class="text-start">No. HP</th>
          <td><?= e($pay['resident_phone'] ?? '-') ?></td>
        </tr>
      </table>

      <hr class="my-3">

      <table class="table table-borderless mb-0 small">
        <tr>
          <th class="text-start" style="width: 50%">Total Tagihan</th>
          <td class="text-end"><?= idr((float)$pay['bill_total']) ?></td>
        </tr>
        <tr>
          <th class="text-start">Jumlah Dibayar</th>
          <td class="text-end fw-bold fs-5"><?= idr((float)$pay['amount_paid']) ?></td>
        </tr>
        <tr>
          <th class="text-start">Metode</th>
          <td class="text-end"><?= e($pay['payment_method_name'] ?? ($pay['payment_method'] ?: '-')) ?><?= $pay['bank_name'] ? ' — '.e($pay['bank_name']) : '' ?></td>
        </tr>
        <?php if ($pay['reference_no']): ?>
        <tr>
          <th class="text-start">Ref. Transfer</th>
          <td class="text-end"><?= e($pay['reference_no']) ?></td>
        </tr>
        <?php endif; ?>
      </table>

      <div class="amount-words mt-2">
        Terbilang: <strong><?= terbilang((float)$pay['amount_paid']) ?> Rupiah</strong>
      </div>

      <hr class="my-3">

      <div class="row small">
        <div class="col-6">
          <div class="text-muted">Dicatat Oleh</div>
          <div class="fw-bold"><?= e($pay['created_by_name'] ?? '-') ?></div>
          <div class="text-muted"><?= fmt_date($pay['created_at'], 'd M Y H:i') ?></div>
        </div>
        <div class="col-6">
          <?php if ($pay['verifier_name']): ?>
          <div class="text-muted">Diverifikasi Oleh</div>
          <div class="fw-bold"><?= e($pay['verifier_name']) ?></div>
          <div class="text-muted"><?= fmt_date($pay['verified_at'], 'd M Y H:i') ?></div>
          <?php else: ?>
          <div class="text-muted">Status</div>
          <div class="fw-bold text-warning"><?= e(ucfirst($pay['status'])) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="receipt-footer mt-4 pt-3">
        <div class="row">
          <div class="col-6 text-center">
            <div class="signature-line">Penerima / Bendahara</div>
          </div>
          <div class="col-6 text-center">
            <div class="signature-line">Warga / Penyetor</div>
          </div>
        </div>
        <p class="text-center text-muted small mt-3 mb-0">
          Kwitansi ini sah sebagai bukti pembayaran resmi IPL <?= e($pay['env_name'] ?? 'Arya Green Pamulang') ?>.
          Dicetak pada <?= date('d F Y H:i') ?>.
        </p>
      </div>
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