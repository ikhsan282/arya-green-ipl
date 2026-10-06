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
            vu.name AS verifier_name, cu.name AS created_by_name
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     LEFT JOIN users vu ON vu.id=p.verified_by
     LEFT JOIN users cu ON cu.id=p.user_id
     WHERE p.id=?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
if (!$pay) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

$page_title = 'Detail Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Detail Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <div class="row g-3" style="max-width:800px">
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">Informasi Pembayaran</div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><th width="45%">Unit</th><td><?= e($pay['block'].'-'.$pay['unit_number']) ?></td></tr>
              <tr><th>Warga</th><td><?= e($pay['resident_name'] ?? '-') ?></td></tr>
              <tr><th>No. HP</th><td><?= e($pay['resident_phone'] ?? '-') ?></td></tr>
              <tr><th>Periode</th><td><?= e($pay['period']) ?></td></tr>
              <tr><th>Total Tagihan</th><td><?= idr((float)$pay['bill_total']) ?></td></tr>
              <tr><th>Jumlah Dibayar</th><td><strong class="text-success"><?= idr((float)$pay['amount_paid']) ?></strong></td></tr>
              <tr><th>Tgl Bayar</th><td><?= fmt_date($pay['payment_date']) ?></td></tr>
              <tr><th>Metode</th><td><?= e(ucfirst($pay['payment_method'])) ?><?= $pay['bank_name'] ? ' — '.e($pay['bank_name']) : '' ?></td></tr>
              <tr><th>Referensi</th><td><?= e($pay['reference_no'] ?? '-') ?></td></tr>
              <tr><th>Status</th><td><?= payment_status_badge($pay['status']) ?></td></tr>
              <?php if ($pay['verifier_name']): ?>
              <tr><th>Diverifikasi</th><td><?= e($pay['verifier_name']) ?><br><small class="text-muted"><?= fmt_date($pay['verified_at'], 'd M Y H:i') ?></small></td></tr>
              <?php endif; ?>
              <?php if ($pay['notes']): ?>
              <tr><th>Catatan</th><td><?= e($pay['notes']) ?></td></tr>
              <?php endif; ?>
              <tr><th>Dicatat Oleh</th><td><?= e($pay['created_by_name'] ?? '-') ?></td></tr>
              <tr><th>Waktu Catat</th><td><?= fmt_date($pay['created_at'], 'd M Y H:i') ?></td></tr>
            </table>
          </div>
          <div class="card-footer">
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
            <?php if ($pay['status']==='verified'): ?>
              <a href="print_receipt.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm ms-2">
                <i class="bi bi-printer me-1"></i> Cetak Kwitansi
              </a>
              <?php if (in_array(auth_user()['role'] ?? '', ['super_admin', 'ketua'])): ?>
              <a href="unverify.php?id=<?= $id ?>" class="btn btn-outline-danger btn-sm ms-2">
                <i class="bi bi-x-circle me-1"></i> Batalkan Verifikasi
              </a>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (can('payments.verify') && $pay['status']==='pending'): ?>
              <a href="verify.php?id=<?= $id ?>" class="btn btn-success btn-sm ms-2"><i class="bi bi-check-lg me-1"></i> Verifikasi</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($pay['proof_file']): ?>
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">Bukti Pembayaran</div>
          <div class="card-body text-center">
            <?php $ext = strtolower(pathinfo($pay['proof_file'], PATHINFO_EXTENSION)); ?>
            <?php if (in_array($ext, ['jpg','jpeg','png','webp'])): ?>
              <img src="<?= UPLOAD_URL . e($pay['proof_file']) ?>" class="img-fluid rounded">
            <?php else: ?>
              <a href="<?= UPLOAD_URL . e($pay['proof_file']) ?>" target="_blank" class="btn btn-outline-primary">
                <i class="bi bi-file-earmark-pdf me-1"></i> Buka PDF
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
