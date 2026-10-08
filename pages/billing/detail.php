<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('billing.view');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Tagihan tidak ditemukan.'); redirect(APP_URL.'/pages/billing/index.php'); }

$stmt = $db->prepare(
    'SELECT b.*, bp.label AS period, bp.period_year, bp.period_month,
            u.unit_number, u.block, ut.name AS type_name,
            r.name AS resident_name, r.phone AS resident_phone
     FROM bills b
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     JOIN unit_types ut ON ut.id=u.unit_type_id
     LEFT JOIN residents r ON r.id=b.resident_id
     WHERE b.id=?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();
if (!$bill) { flash('error','Tagihan tidak ditemukan.'); redirect(APP_URL.'/pages/billing/index.php'); }

// Warga hanya boleh lihat tagihan unitnya sendiri
if (auth_role() === 'warga') {
    $chk = $db->prepare('SELECT 1 FROM bills b LEFT JOIN residents r ON r.id=b.resident_id WHERE b.id=? AND r.user_id=?');
    $uid = auth_id();
    $chk->bind_param('ii', $id, $uid);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        flash('error', 'Akses ditolak.'); redirect(APP_URL.'/pages/billing/index.php');
    }
}

// Payment history for this bill
$payments = $db->prepare(
    'SELECT p.*, pm.name AS payment_method_name, u.name AS verified_by_name
     FROM payments p
     LEFT JOIN users u ON u.id=p.verified_by
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     WHERE p.bill_id=? ORDER BY p.created_at DESC'
);
$payments->bind_param('i', $id);
$payments->execute();
$payment_list = $payments->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Detail Tagihan';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-receipt me-1 text-warning"></i> Detail Tagihan</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="row g-3">
      <div class="col-md-5">
        <div class="card">
          <div class="card-header">Informasi Tagihan</div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><th width="40%">Periode</th><td><?= e($bill['period']) ?></td></tr>
              <tr><th>Unit</th><td><strong><?= e($bill['block'].'-'.$bill['unit_number']) ?></strong></td></tr>
              <tr><th>Tipe</th><td><?= e($bill['type_name']) ?></td></tr>
              <tr><th>Warga</th><td><?= e($bill['resident_name'] ?? '-') ?></td></tr>
              <tr><th>No. HP</th><td><?= e($bill['resident_phone'] ?? '-') ?></td></tr>
              <tr><th>IPL</th><td><?= idr((float)$bill['amount']) ?></td></tr>
              <tr><th>Denda</th><td class="text-danger"><?= idr((float)$bill['fine_amount']) ?></td></tr>
              <tr><th>Total</th><td><strong><?= idr((float)$bill['total_amount']) ?></strong></td></tr>
              <tr><th>Jatuh Tempo</th><td><?= fmt_date($bill['due_date']) ?></td></tr>
              <tr><th>Status</th><td><?= bill_status_badge($bill['status']) ?></td></tr>
              <?php if ($bill['paid_date']): ?>
              <tr><th>Tgl Bayar</th><td><?= fmt_date($bill['paid_date']) ?></td></tr>
              <?php endif; ?>
            </table>
          </div>
          <div class="card-footer d-flex gap-2">
            <?php if (can('payments.create') && $bill['status'] !== 'sudah_bayar'): ?>
              <a href="<?= APP_URL ?>/pages/payments/form.php?bill_id=<?= $id ?>"
                 class="btn btn-success btn-sm">
                <i class="bi bi-cash-coin me-1"></i> Catat Pembayaran
              </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
          </div>
        </div>
      </div>
      <div class="col-md-7">
        <div class="card">
          <div class="card-header">Riwayat Pembayaran</div>
          <div class="card-body p-0">
            <?php if (empty($payment_list)): ?>
              <div class="p-3 text-muted">Belum ada pembayaran untuk tagihan ini.</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead><tr>
                  <th>Tanggal</th><th>Jumlah</th><th>Metode</th><th>Ref</th><th>Status</th><th>Bukti</th>
                </tr></thead>
                <tbody>
                <?php foreach ($payment_list as $p): ?>
                <tr>
                  <td><?= fmt_date($p['payment_date']) ?></td>
                  <td><?= idr((float)$p['amount_paid']) ?></td>
                  <td><?= e($p['payment_method_name'] ?? ($p['payment_method'] ?: '-')) ?><?= $p['bank_name'] ? ' - '.e($p['bank_name']) : '' ?></td>
                  <td><?= e($p['reference_no'] ?? '-') ?></td>
                  <td><?= payment_status_badge($p['status']) ?></td>
                  <td>
                    <?php if ($p['proof_file']): ?>
                      <a href="<?= UPLOAD_URL . e($p['proof_file']) ?>" target="_blank" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-1">
                        <i class="bi bi-file-earmark"></i>
                      </a>
                    <?php else: ?>-<?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
