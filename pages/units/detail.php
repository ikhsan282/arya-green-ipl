<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('units.view');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Unit tidak ditemukan.'); redirect(APP_URL.'/pages/units/index.php'); }

$stmt = $db->prepare(
    'SELECT u.*, ut.name AS type_name, ut.ipl_amount,
            r.name AS resident_name, r.phone, r.email
     FROM units u
     JOIN unit_types ut ON ut.id = u.unit_type_id
     LEFT JOIN residents r ON r.unit_id = u.id AND r.is_active = 1
     WHERE u.id = ? LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$unit = $stmt->get_result()->fetch_assoc();
if (!$unit) { flash('error','Unit tidak ditemukan.'); redirect(APP_URL.'/pages/units/index.php'); }

// Riwayat tagihan semua periode
$bills = $db->prepare(
    'SELECT b.*, bp.label AS period, bp.period_year, bp.period_month,
            p.amount_paid, p.payment_method, pm.name AS payment_method_name, p.payment_date, p.status AS pay_status
     FROM bills b
     JOIN billing_periods bp ON bp.id = b.billing_period_id
     LEFT JOIN payments p ON p.bill_id = b.id AND p.status = "verified"
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     WHERE b.unit_id = ?
     ORDER BY bp.period_year DESC, bp.period_month DESC'
);
$bills->bind_param('i', $id);
$bills->execute();
$history = $bills->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary
$total_tagihan  = array_sum(array_column($history, 'total_amount'));
$total_terbayar = array_sum(array_column($history, 'amount_paid'));
$total_nunggak  = array_filter($history, fn($r) => in_array($r['status'], ['belum_bayar','terlambat']));

$page_title = 'Detail Unit ' . e($unit['block'].'-'.$unit['unit_number']);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Detail Unit</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <div class="row g-3 mb-3">
      <!-- Info unit -->
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-house me-1 text-success"></i> Info Unit</span>
            <?php if (can('units.edit')): ?>
              <a href="form.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary py-0">
                <i class="bi bi-pencil"></i> Edit
              </a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><th width="45%">Blok / Unit</th><td><strong><?= e($unit['block'].'-'.$unit['unit_number']) ?></strong></td></tr>
              <tr><th>Tipe</th><td><?= e($unit['type_name']) ?></td></tr>
              <tr><th>Luas</th><td><?= number_format((float)$unit['area_sqm'],0) ?> m²</td></tr>
              <tr><th>IPL/Bulan</th><td><?= idr((float)$unit['ipl_amount']) ?></td></tr>
              <tr><th>Status</th><td><?= unit_status_badge($unit['status']) ?></td></tr>
              <tr><th>Lantai</th><td><?= $unit['floor'] ?? '-' ?></td></tr>
            </table>
          </div>
        </div>
      </div>

      <!-- Info warga -->
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-person me-1 text-primary"></i> Penghuni Aktif</div>
          <div class="card-body">
            <?php if ($unit['resident_name']): ?>
            <table class="table table-sm table-borderless mb-0">
              <tr><th width="45%">Nama</th><td><?= e($unit['resident_name']) ?></td></tr>
              <tr><th>No. HP</th><td><?= e($unit['phone'] ?? '-') ?></td></tr>
              <tr><th>Email</th><td><?= e($unit['email'] ?? '-') ?></td></tr>
            </table>
            <?php else: ?>
              <p class="text-muted small mb-0">Belum ada penghuni aktif.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Summary tagihan -->
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-receipt me-1 text-warning"></i> Ringkasan Tagihan</div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><th width="55%">Total Tagihan</th><td><?= count($history) ?> periode</td></tr>
              <tr><th>Nunggak</th><td><span class="badge bg-danger"><?= count($total_nunggak) ?> periode</span></td></tr>
              <tr><th>Total IPL</th><td><?= idr($total_tagihan) ?></td></tr>
              <tr><th>Sudah Dibayar</th><td class="text-success fw-bold"><?= idr($total_terbayar) ?></td></tr>
              <tr><th>Sisa Tunggakan</th><td class="text-danger fw-bold"><?= idr($total_tagihan - $total_terbayar) ?></td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Riwayat tagihan -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1 text-success"></i> Riwayat Tagihan</span>
        <a href="<?= APP_URL ?>/pages/units/index.php" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead><tr>
              <th>#</th><th>Periode</th><th>IPL</th><th>Denda</th>
              <th>Total</th><th>Status</th><th>Tgl Bayar</th><th>Metode</th>
            </tr></thead>
            <tbody>
            <?php if (empty($history)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Belum ada tagihan untuk unit ini.</td></tr>
            <?php else: foreach ($history as $i => $b): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= e($b['period']) ?></td>
                <td><?= idr((float)$b['amount']) ?></td>
                <td><?= $b['fine_amount'] > 0 ? '<span class="text-danger">'.idr((float)$b['fine_amount']).'</span>' : '-' ?></td>
                <td><strong><?= idr((float)$b['total_amount']) ?></strong></td>
                <td><?= bill_status_badge($b['status']) ?></td>
                <td><?= $b['payment_date'] ? fmt_date($b['payment_date']) : '-' ?></td>
                <td><?= $b['payment_method_name'] ?? ($b['payment_method'] ? e(ucfirst($b['payment_method'])) : '-') ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
