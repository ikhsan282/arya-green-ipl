<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('residents.view');

$db = db();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('residents.delete');
    $id = (int)$_POST['id'];
    $stmt = $db->prepare('UPDATE residents SET is_active=0 WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    log_activity('delete','residents',"Resident #{$id} deactivated");
    flash('success','Data warga berhasil dihapus.');
    redirect(APP_URL . '/pages/residents/index.php');
}

$search = clean($_GET['q'] ?? '');
$f_unit = clean($_GET['unit_id'] ?? '');
$f_status = clean($_GET['status'] ?? 'active');
$page   = max(1,(int)($_GET['page'] ?? 1));
$per    = 15;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_status !== 'all') {
    $where[]  = 'r.is_active = ?';
    $params[] = ($f_status === 'active') ? 1 : 0;
    $types   .= 'i';
}
if ($search) {
    $where[]  = '(r.name LIKE ? OR r.phone LIKE ? OR r.email LIKE ?)';
    $like     = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}
if ($f_unit) {
    $where[]  = 'r.unit_id = ?';
    $params[] = (int)$f_unit;
    $types   .= 'i';
}
$wsql = implode(' AND ', $where);

$cnt  = $db->prepare("SELECT COUNT(*) FROM residents r WHERE {$wsql}");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

$sql  = "SELECT r.*, u.unit_number, u.block, ut.name AS type_name
         FROM residents r
         JOIN units u ON u.id = r.unit_id
         JOIN unit_types ut ON ut.id = u.unit_type_id
         WHERE {$wsql} ORDER BY u.block, u.unit_number, r.name
         LIMIT ? OFFSET ?";
$fp   = array_merge($params, [$per, $pag['offset']]);
$ft   = $types . 'ii';
$stmt = $db->prepare($sql);
$stmt->bind_param($ft, ...$fp);
$stmt->execute();
$residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Data Warga';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-people me-1 text-primary"></i> Data Warga</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-people me-1 text-success"></i> Daftar Warga</span>
        <?php if (can('residents.create')): ?>
          <a href="form.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i> Tambah Warga</a>
        <?php endif; ?>
      </div>
      <div class="card-body border-bottom">
        <form method="GET" class="row g-2">
          <div class="col-12 col-md-4">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari nama, HP, email..." value="<?= e($search) ?>">
          </div>
          <div class="col-6 col-md-2">
            <select name="status" class="form-select form-select-sm">
              <option value="active" <?= $f_status==='active' ?'selected':'' ?>>Aktif</option>
              <option value="inactive" <?= $f_status==='inactive'?'selected':'' ?>>Nonaktif</option>
              <option value="all" <?= $f_status==='all'?'selected':'' ?>>Semua</option>
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
              <th>#</th><th>Nama</th><th>Unit</th><th>Tipe</th>
              <th>No. HP</th><th>Email</th><th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($residents)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data warga.</td></tr>
            <?php else: ?>
            <?php foreach ($residents as $i => $r): ?>
              <tr>
                <td><?= $pag['offset'] + $i + 1 ?></td>
                <td>
                  <strong><?= e($r['name']) ?></strong><br>
                  <small class="text-muted"><?= e($r['status']) ?></small>
                </td>
                <td><?= e($r['block'].'-'.$r['unit_number']) ?></td>
                <td><?= e($r['type_name']) ?></td>
                <td><?= e($r['phone']) ?></td>
                <td><?= e($r['email'] ?? '-') ?></td>
                <td><?= $r['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                <td>
                  <?php if (can('residents.edit')): ?>
                    <a href="form.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a>
                  <?php endif; ?>
                  <?php if (can('residents.delete') && $r['is_active']): ?>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="delete">
                      <input type="hidden" name="id" value="<?= $r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0 px-2"
                              data-confirm="Nonaktifkan warga <?= e($r['name']) ?>?">
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
        <small class="text-muted">Menampilkan <?= count($residents) ?> dari <?= $total ?> warga</small>
        <?= render_pagination($pag, 'index.php?q='.urlencode($search).'&status='.urlencode($f_status)) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
