<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('payments.view');

$db   = db();
$page = max(1,(int)($_GET['page'] ?? 1));
$per  = 15;

$f_status = clean($_GET['status'] ?? '');
$f_method = clean($_GET['method'] ?? '');
$search   = clean($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_status) { $where[] = 'p.status=?';          $params[] = $f_status; $types .= 's'; }
if ($f_method) { $where[] = 'p.payment_method_id=?'; $params[] = (int)$f_method; $types .= 'i'; }
if ($search)   {
    $where[] = '(u.unit_number LIKE ? OR r.name LIKE ? OR p.reference_no LIKE ?)';
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
$wsql = implode(' AND ', $where);

$cnt = $db->prepare(
    "SELECT COUNT(*) FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     WHERE {$wsql}"
);
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT p.*, b.amount AS bill_amount, bp.label AS period,
            u.unit_number, u.block, r.name AS resident_name,
            pm.name AS payment_method_name,
            vu.name AS verifier_name
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     LEFT JOIN users vu ON vu.id=p.verified_by
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     WHERE {$wsql} ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types.'ii', ...$fp);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Riwayat Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-cash-coin me-1 text-success"></i> Daftar Pembayaran</span>
        <?php if (can('payments.create')): ?>
          <a href="form.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i> Catat Pembayaran</a>
        <?php endif; ?>
      </div>
      <div class="card-body border-bottom">
        <form method="GET" class="row g-2">
          <div class="col-md-3">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari unit/warga/ref..." value="<?= e($search) ?>">
          </div>
          <div class="col-6 col-md-2">
            <select name="status" class="form-select form-select-sm">
              <option value="">Semua Status</option>
              <option value="pending"  <?= $f_status==='pending'  ?'selected':'' ?>>Pending</option>
              <option value="verified" <?= $f_status==='verified' ?'selected':'' ?>>Terverifikasi</option>
              <option value="rejected" <?= $f_status==='rejected' ?'selected':'' ?>>Ditolak</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="method" class="form-select form-select-sm">
              <option value="">Semua Metode</option>
              <?php
              $all_methods = $db->query('SELECT id, name FROM payment_methods ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);
              foreach ($all_methods as $am): ?>
                <option value="<?= $am['id'] ?>" <?= $f_method === (string)$am['id'] ? 'selected' : '' ?>><?= e($am['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-outline-success"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>#</th><th>Tanggal</th><th>Unit</th><th>Warga</th><th>Periode</th>
              <th>Jumlah</th><th>Metode</th><th>Referensi</th><th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($payments)): ?>
              <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data pembayaran.</td></tr>
            <?php else: foreach ($payments as $i => $p): ?>
              <tr>
                <td><?= $pag['offset']+$i+1 ?></td>
                <td><?= fmt_date($p['payment_date']) ?></td>
                <td><?= e($p['block'].'-'.$p['unit_number']) ?></td>
                <td><?= e($p['resident_name'] ?? '-') ?></td>
                <td><?= e($p['period']) ?></td>
                <td><strong><?= idr((float)$p['amount_paid']) ?></strong></td>
                <td><?= e($p['payment_method_name'] ?? ($p['payment_method'] ?: '-')) ?><?= $p['bank_name'] ? '<br><small class="text-muted">'.e($p['bank_name']).'</small>' : '' ?></td>
                <td><?= e($p['reference_no'] ?? '-') ?></td>
                <td><?= payment_status_badge($p['status']) ?></td>
                <td>
                  <?php if (can('payments.verify') && $p['status']==='pending'): ?>
                    <a href="verify.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success py-0 px-2">
                      <i class="bi bi-check-lg"></i>
                    </a>
                  <?php endif; ?>
                  <a href="detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">
                    <i class="bi bi-eye"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages']>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">Menampilkan <?= count($payments) ?> dari <?= $total ?> pembayaran</small>
        <?= render_pagination($pag,'index.php?q='.urlencode($search).'&status='.urlencode($f_status).'&method='.urlencode($f_method)) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
