<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/email_notifications.php';
require_permission('payments.verify');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

$stmt = $db->prepare(
    'SELECT p.*, b.id AS bill_id, b.total_amount, bp.label AS period,
            u.unit_number, u.block, r.name AS resident_name, r.email AS resident_email
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     WHERE p.id=? AND p.status="pending"'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
if (!$pay) { flash('error','Pembayaran tidak ditemukan atau sudah diproses.'); redirect(APP_URL.'/pages/payments/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['action'] ?? '');
    $notes  = clean($_POST['notes'] ?? '');
    $uid    = auth_id();

    if ($action === 'verify') {
        $s = $db->prepare('UPDATE payments SET status="verified",verified_by=?,verified_at=NOW(),notes=? WHERE id=?');
        $s->bind_param('isi', $uid, $notes, $id);
        $s->execute();
        // Mark bill paid
        $s2 = $db->prepare('UPDATE bills SET status="sudah_bayar",paid_date=? WHERE id=?');
        $s2->bind_param('si', $pay['payment_date'], $pay['bill_id']);
        $s2->execute();
        // Auto-entry ke buku kas
        $s3 = $db->prepare(
            'INSERT INTO cash_book (kas_account_id,type,category,amount,description,trx_date,ref_payment_id,created_by)
             SELECT ka.id, "pemasukan", "IPL", ?, ?, ?, ?, ?
             FROM kas_accounts ka WHERE ka.is_default = 1 LIMIT 1'
        );
        $desc = 'IPL ' . $pay['period'] . ' — ' . ($pay['block'] ?? '') . '-' . ($pay['unit_number'] ?? '');
        $s3->bind_param('dssii', $pay['amount_paid'], $desc, $pay['payment_date'], $id, $uid);
        $s3->execute();
        log_activity('verify','payments',"Payment #{$id} verified");
        flash('success','Pembayaran berhasil diverifikasi.');
        // Email notifikasi ke warga
        if (!empty($pay['resident_email'])) {
            $pay['verified_at'] = date('Y-m-d H:i:s');
            notify_payment_verified($pay, $pay['resident_email'], $pay['resident_name'] ?? '');
        }
    } elseif ($action === 'reject') {
        $s = $db->prepare('UPDATE payments SET status="rejected",verified_by=?,verified_at=NOW(),notes=? WHERE id=?');
        $s->bind_param('isi', $uid, $notes, $id);
        $s->execute();
        log_activity('reject','payments',"Payment #{$id} rejected");
        flash('warning','Pembayaran ditolak.');
        // Email notifikasi ke warga
        if (!empty($pay['resident_email'])) {
            notify_payment_rejected($pay, $pay['resident_email'], $pay['resident_name'] ?? '', $notes);
        }
    }
    redirect(APP_URL . '/pages/payments/index.php');
}

$page_title = 'Verifikasi Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Verifikasi Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="row g-3" style="max-width:800px">
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">Detail Pembayaran</div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><th>Unit</th><td><?= e($pay['block'].'-'.$pay['unit_number']) ?></td></tr>
              <tr><th>Warga</th><td><?= e($pay['resident_name'] ?? '-') ?></td></tr>
              <tr><th>Periode</th><td><?= e($pay['period']) ?></td></tr>
              <tr><th>Tgl Bayar</th><td><?= fmt_date($pay['payment_date']) ?></td></tr>
              <tr><th>Jumlah</th><td><strong><?= idr((float)$pay['amount_paid']) ?></strong></td></tr>
              <tr><th>Metode</th><td><?= e(ucfirst($pay['payment_method'])) ?><?= $pay['bank_name'] ? ' - '.e($pay['bank_name']) : '' ?></td></tr>
              <tr><th>Referensi</th><td><?= e($pay['reference_no'] ?? '-') ?></td></tr>
            </table>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <?php if ($pay['proof_file']): ?>
        <div class="card mb-3">
          <div class="card-header">Bukti Pembayaran</div>
          <div class="card-body text-center">
            <?php $ext = strtolower(pathinfo($pay['proof_file'], PATHINFO_EXTENSION)); ?>
            <?php if (in_array($ext, ['jpg','jpeg','png','webp'])): ?>
              <img src="<?= UPLOAD_URL . e($pay['proof_file']) ?>" class="img-fluid rounded" style="max-height:200px">
            <?php else: ?>
              <a href="<?= UPLOAD_URL . e($pay['proof_file']) ?>" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1"></i> Lihat PDF
              </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="card">
          <div class="card-header">Aksi Verifikasi</div>
          <div class="card-body">
            <form method="POST">
              <?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label">Catatan (opsional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Catatan verifikasi..."></textarea>
              </div>
              <div class="d-flex gap-2">
                <button type="submit" name="action" value="verify" class="btn btn-success">
                  <i class="bi bi-check-lg me-1"></i> Verifikasi
                </button>
                <button type="submit" name="action" value="reject" class="btn btn-danger"
                        onclick="return confirm('Tolak pembayaran ini?')">
                  <i class="bi bi-x-lg me-1"></i> Tolak
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Batal</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
