<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('users.view');

$db = db();

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle') {
    csrf_verify();
    require_permission('users.edit');
    $id  = (int)$_POST['id'];
    $act = (int)$_POST['active'];
    if ($id !== auth_id()) {
        $s = $db->prepare('UPDATE users SET is_active=? WHERE id=?');
        $s->bind_param('ii', $act, $id);
        $s->execute();
        flash('success', 'Status pengguna diperbarui.');
    }
    redirect(APP_URL . '/pages/users/index.php');
}

$search = clean($_GET['q'] ?? '');
$f_role = (int)($_GET['role_id'] ?? 0);
$page   = max(1,(int)($_GET['page'] ?? 1));
$per    = 15;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search) {
    $like = "%{$search}%";
    $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($f_role) { $where[] = 'u.role_id=?'; $params[] = $f_role; $types .= 'i'; }
$wsql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$wsql}");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT u.*, r.label AS role_label FROM users u
     JOIN roles r ON r.id=u.role_id
     WHERE {$wsql} ORDER BY u.created_at DESC LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types.'ii', ...$fp);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$roles = $db->query('SELECT * FROM roles ORDER BY id')->fetch_all(MYSQLI_ASSOC);

$page_title = 'Pengguna';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Pengguna</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-person-gear me-1 text-success"></i> Daftar Pengguna</span>
        <?php if (can('users.create')): ?>
          <a href="form.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i> Tambah Pengguna</a>
        <?php endif; ?>
      </div>
      <div class="card-body border-bottom">
        <form method="GET" class="row g-2">
          <div class="col-md-4">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari nama/username/email..." value="<?= e($search) ?>">
          </div>
          <div class="col-md-2">
            <select name="role_id" class="form-select form-select-sm">
              <option value="">Semua Role</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $f_role===$r['id']?'selected':'' ?>><?= e($r['label']) ?></option>
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
              <th>#</th><th>Nama</th><th>Username</th><th>Email</th>
              <th>Role</th><th>Verifikasi</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($users)): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada pengguna.</td></tr>
            <?php else: foreach ($users as $i => $u): ?>
              <tr>
                <td><?= $pag['offset']+$i+1 ?></td>
                <td><strong><?= e($u['name']) ?></strong></td>
                <td><code><?= e($u['username']) ?></code></td>
                <td><?= e($u['email']) ?></td>
                <td><span class="badge bg-secondary"><?= e($u['role_label']) ?></span></td>
                <td><?= $u['email_verified_at'] ? '<span class="badge bg-success">Terverifikasi</span>' : '<span class="badge bg-warning text-dark">Belum</span>' ?></td>
                <td><?= $u['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                <td><?= $u['last_login'] ? fmt_date($u['last_login'], 'd M Y H:i') : '-' ?></td>
                <td>
                  <?php if (can('users.edit')): ?>
                    <a href="form.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a>
                  <?php endif; ?>
                  <?php if (can('users.edit') && $u['id'] !== auth_id()): ?>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="toggle">
                      <input type="hidden" name="id" value="<?= $u['id'] ?>">
                      <input type="hidden" name="active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                      <button class="btn btn-sm py-0 px-2 <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                              data-confirm="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> pengguna ini?">
                        <i class="bi bi-<?= $u['is_active'] ? 'pause' : 'play' ?>"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages']>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">Menampilkan <?= count($users) ?> dari <?= $total ?> pengguna</small>
        <?= render_pagination($pag, 'index.php?q='.urlencode($search).'&role_id='.$f_role) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
