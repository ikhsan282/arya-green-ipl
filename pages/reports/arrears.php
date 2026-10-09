<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db     = db();
$search = clean($_GET['q'] ?? '');

// Ambil semua warga dengan tunggakan > 0 periode, grouped per unit
$where  = "b.status IN ('belum_bayar','terlambat')";
$params = [];
$types  = '';
if ($search) {
    $where .= " AND (u.unit_number LIKE ? OR u.block LIKE ? OR r.name LIKE ?)";
    $like    = "%{$search}%";
    $params  = [$like, $like, $like];
    $types   = 'sss';
}

$stmt = $db->prepare(
    "SELECT u.block, u.unit_number, r.name AS resident_name, r.phone,
            COUNT(b.id)           AS jumlah_periode,
            SUM(b.total_amount)   AS total_tunggakan,
            MIN(bp.label)         AS periode_tertua,
            MAX(bp.label)         AS periode_terbaru,
            GROUP_CONCAT(bp.label ORDER BY bp.period_year, bp.period_month SEPARATOR ', ') AS daftar_periode
     FROM bills b
     JOIN units u ON u.id = b.unit_id
     JOIN billing_periods bp ON bp.id = b.billing_period_id
     LEFT JOIN residents r ON r.id = b.resident_id
     WHERE {$where}
     GROUP BY u.id, r.id
     ORDER BY jumlah_periode DESC, total_tunggakan DESC"
);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary
$total_unit     = count($rows);
$total_tunggakan = array_sum(array_column($rows, 'total_tunggakan'));
$max_periode     = $rows ? max(array_column($rows, 'jumlah_periode')) : 0;

$page_title = 'Rekap Tunggakan';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-exclamation-triangle me-1 text-danger"></i> Rekap Tunggakan</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Summary cards -->
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-4 text-danger"><?= $total_unit ?></div>
          <div class="text-muted small">Unit Menunggak</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-5 text-danger"><?= idr($total_tunggakan) ?></div>
          <div class="text-muted small">Total Tunggakan</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-4 text-warning"><?= $max_periode ?></div>
          <div class="text-muted small">Maks. Bulan Nunggak</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-4 text-secondary"><?= $rows ? round(array_sum(array_column($rows,'jumlah_periode'))/$total_unit,1) : 0 ?></div>
          <div class="text-muted small">Rata-rata Bulan Nunggak</div>
        </div>
      </div>
    </div>

    <!-- Filter + Export -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-md-4">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari unit/blok/nama warga..." value="<?= e($search) ?>">
          </div>
          <div class="col-auto d-flex gap-2">
            <button class="btn btn-sm btn-success"><i class="bi bi-search me-1"></i>Cari</button>
            <a href="arrears.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            <a href="arrears_export.php?q=<?= urlencode($search) ?>" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV
            </a>
            <a href="arrears_export.php?q=<?= urlencode($search) ?>&format=xlsx" class="btn btn-sm btn-danger">
              <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Cetak
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Table -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-exclamation-triangle me-1 text-danger"></i>
        Daftar Tunggakan — Semua Periode
        <span class="badge bg-danger ms-2"><?= $total_unit ?> unit</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead><tr>
              <th>#</th><th>Unit</th><th>Warga</th><th>No. HP</th>
              <th class="text-center">Jumlah Bulan</th>
              <th>Periode Nunggak</th>
              <th class="text-end">Total Tunggakan</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada tunggakan.</td></tr>
            <?php else: foreach ($rows as $i => $r): ?>
              <?php
              $badge = $r['jumlah_periode'] >= 3 ? 'danger' : ($r['jumlah_periode'] >= 2 ? 'warning' : 'secondary');
              ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><strong><?= e($r['block'].'-'.$r['unit_number']) ?></strong></td>
                <td><?= e($r['resident_name'] ?? '-') ?></td>
                <td><?= e($r['phone'] ?? '-') ?></td>
                <td class="text-center">
                  <span class="badge bg-<?= $badge ?>"><?= $r['jumlah_periode'] ?> bulan</span>
                </td>
                <td><small class="text-muted"><?= e($r['daftar_periode']) ?></small></td>
                <td class="text-end fw-bold text-danger"><?= idr((float)$r['total_tunggakan']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot class="table-light">
              <tr>
                <td colspan="6" class="text-end fw-bold">Total</td>
                <td class="text-end fw-bold text-danger"><?= idr($total_tunggakan) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<style>
@media print {
  .sidebar, .topbar, form, .btn { display: none !important; }
  .content-wrapper { margin-left: 0 !important; width: 100% !important; }
}
</style>
