<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('environments.view');

$db = db();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('environments.delete');
    $id = (int)$_POST['id'];
    // Cek apakah env masih dipakai user
    $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE env_id = ?');
    $chk->bind_param('i', $id);
    $chk->execute();
    if ($chk->get_result()->fetch_row()[0] > 0) {
        flash('error', 'Lingkungan masih digunakan oleh user, tidak bisa dihapus.');
    } else {
        $stmt = $db->prepare('DELETE FROM environments WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity('delete', 'environments', "Environment #{$id} deleted");
        flash('success', 'Lingkungan berhasil dihapus.');
    }
    redirect(APP_URL . '/pages/environments/index.php');
}

$envs = $db->query(
    'SELECT e.*, COUNT(u.id) AS user_count
     FROM environments e
     LEFT JOIN users u ON u.env_id = e.id
     GROUP BY e.id
     ORDER BY e.name'
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Lingkungan (Environments)';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-buildings me-1 text-success"></i> Lingkungan</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-buildings me-1 text-success"></i> Daftar Lingkungan</span>
        <?php if (can('environments.create')): ?>
          <a href="form.php" class="btn btn-success btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Tambah Lingkungan
          </a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>Nama</th>
              <th>Kode</th>
              <th>Lokasi</th>
              <th>Domain</th>
              <th class="text-center">User</th>
              <th class="text-center">Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($envs as $env): ?>
            <tr>
              <td><?= e($env['name']) ?></td>
              <td><code><?= e($env['code']) ?></code></td>
              <td class="text-muted small">
                <?= implode(', ', array_filter([
                    $env['rt'] ? 'RT '.$env['rt'] : '',
                    $env['rw'] ? 'RW '.$env['rw'] : '',
                    $env['kelurahan'],
                    $env['kecamatan'],
                    $env['city'],
                ])) ?>
              </td>
              <td><?= $env['custom_domain'] ? e($env['custom_domain']) : '<span class="text-muted">-</span>' ?></td>
              <td class="text-center"><?= $env['user_count'] ?></td>
              <td class="text-center">
                <?php if ($env['is_active']): ?>
                  <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Nonaktif</span>
                <?php endif; ?>
              </td>
              <td class="text-end pe-3">
                <?php if (can('environments.edit')): ?>
                  <a href="form.php?id=<?= $env['id'] ?>" class="btn btn-xs btn-outline-primary">
                    <i class="bi bi-pencil"></i>
                  </a>
                <?php endif; ?>
                <?php if (can('environments.delete') && $env['user_count'] == 0): ?>
                  <form method="POST" class="d-inline"
                        onsubmit="return confirm('Hapus lingkungan <?= e($env['name']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" value="<?= $env['id'] ?>">
                    <button class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$envs): ?>
              <tr><td colspan="7" class="text-center text-muted py-3">Belum ada lingkungan.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
