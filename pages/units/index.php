<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('units.view');

$db = db();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('units.delete');
    $id = (int)$_POST['id'];
    $stmt = $db->prepare('DELETE FROM units WHERE id = ?');
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        log_activity('delete', 'units', "Unit #{$id} deleted");
        flash('success', 'Unit berhasil dihapus.');
    } else {
        flash('error', 'Gagal menghapus unit.');
    }
    redirect(APP_URL . '/pages/units/index.php');
}

// Search & filter
$search  = clean($_GET['q'] ?? '');
$f_block = clean($_GET['block'] ?? '');
$f_status= clean($_GET['status'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 15;

$where = ['1=1'];
$params = [];
$types  = '';
if ($search) {
    $where[]  = '(u.unit_number LIKE ? OR u.block LIKE ?)';
    $like     = "%{$search}%";
    $params[] = $like; $params[] = $like;
    $types   .= 'ss';
}
if ($f_block) {
    $where[]  = 'u.block = ?';
    $params[] = $f_block;
    $types   .= 's';
}
if ($f_status) {
    $where[]  = 'u.status = ?';
    $params[] = $f_status;
    $types   .= 's';
}
$where_sql = implode(' AND ', $where);

// Count
$count_sql = "SELECT COUNT(*) FROM units u WHERE {$where_sql}";
$stmt = $db->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

// Fetch
$sql = "SELECT u.*, ut.name AS type_name, ut.ipl_amount
        FROM units u JOIN unit_types ut ON ut.id = u.unit_type_id
        WHERE {$where_sql} ORDER BY u.block, u.unit_number
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$fetch_params  = $params;
$fetch_params[] = $per;
$fetch_params[] = $pag['offset'];
$fetch_types   = $types . 'ii';
$stmt->bind_param($fetch_types, ...$fetch_params);
$stmt->execute();
$units = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Blocks for filter
$blocks = $db->query('SELECT DISTINCT block FROM units ORDER BY block')->fetch_all(MYSQLI_ASSOC);

$page_title = 'Data Unit';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Data Unit</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-houses me-1 text-success"></i> Daftar Unit Hunian</span>
        <?php if (can('units.create')): ?>
          <a href="form.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i> Tambah Unit</a>
        <?php endif; ?>
      </div>
      <div class="card-body border-bottom">
        <form method="GET" class="row g-2">
          <div class="col-12 col-md-4">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari nomor/blok..." value="<?= e($search) ?>">
          </div>
          <div class="col-6 col-md-2">
            <select name="block" class="form-select form-select-sm">
              <option value="">Semua Blok</option>
              <?php foreach ($blocks as $b): ?>
                <option value="<?= e($b['block']) ?>" <?= $f_block === $b['block'] ? 'selected' : '' ?>><?= e($b['block']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="status" class="form-select form-select-sm">
              <option value="">Semua Status</option>
              <option value="dihuni"  <?= $f_status === 'dihuni'  ? 'selected' : '' ?>>Dihuni</option>
              <option value="kosong"  <?= $f_status === 'kosong'  ? 'selected' : '' ?>>Kosong</option>
              <option value="dijual"  <?= $f_status === 'dijual'  ? 'selected' : '' ?>>Dijual</option>
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
              <th>#</th><th>Blok</th><th>No. Unit</th><th>Tipe</th>
              <th>Luas (m²)</th><th>IPL/Bulan</th><th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($units)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data unit.</td></tr>
            <?php else: ?>
            <?php foreach ($units as $i => $u): ?>
              <tr>
                <td><?= $pag['offset'] + $i + 1 ?></td>
                <td><?= e($u['block']) ?></td>
                <td><strong><?= e($u['unit_number']) ?></strong></td>
                <td><?= e($u['type_name']) ?></td>
                <td><?= number_format((float)$u['area_sqm'], 0) ?></td>
                <td><?= idr((float)$u['ipl_amount']) ?></td>
                <td><?= unit_status_badge($u['status']) ?></td>
                <td>
                  <a href="detail.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">
                    <i class="bi bi-eye"></i>
                  </a>
                  <?php if (can('units.edit')): ?>
                    <a href="form.php?id=<?= $u['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2">
                      <i class="bi bi-pencil"></i>
                    </a>
                  <?php endif; ?>
                  <?php if (can('units.delete')): ?>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="delete">
                      <input type="hidden" name="id" value="<?= $u['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                              data-confirm="Hapus unit <?= e($u['unit_number']) ?>? Data terkait akan ikut terhapus.">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages'] > 1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">Menampilkan <?= count($units) ?> dari <?= $total ?> unit</small>
        <?= render_pagination($pag, 'index.php?q=' . urlencode($search) . '&block=' . urlencode($f_block) . '&status=' . urlencode($f_status)) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
