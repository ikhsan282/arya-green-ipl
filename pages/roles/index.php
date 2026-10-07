<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('roles.manage');

$db = db();

// Save role permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_permissions') {
    csrf_verify();
    $role_id = (int)$_POST['role_id'];
    if (!$role_id) { flash('error','Role tidak valid.'); redirect(APP_URL.'/pages/roles/index.php'); }

    // Delete existing, re-insert selected
    $del = $db->prepare('DELETE FROM role_permissions WHERE role_id=?');
    $del->bind_param('i', $role_id);
    $del->execute();

    $perm_ids = array_map('intval', $_POST['permissions'] ?? []);
    if ($perm_ids) {
        $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)');
        foreach ($perm_ids as $pid) {
            $ins->bind_param('ii', $role_id, $pid);
            $ins->execute();
        }
    }
    // Flush all sessions' permissions cache by clearing in DB (next load will re-query)
    log_activity('update','roles',"Permissions updated for role #{$role_id}");
    flash('success','Hak akses berhasil disimpan.');
    redirect(APP_URL.'/pages/roles/index.php');
}

$roles = $db->query('SELECT * FROM roles ORDER BY id')->fetch_all(MYSQLI_ASSOC);

// Group permissions by module
$all_perms = $db->query('SELECT * FROM permissions ORDER BY module, name')->fetch_all(MYSQLI_ASSOC);
$by_module = [];
foreach ($all_perms as $p) $by_module[$p['module']][] = $p;

// Selected role
$sel_role_id = (int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$assigned = [];
if ($sel_role_id) {
    $stmt = $db->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?');
    $stmt->bind_param('i', $sel_role_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $assigned[$row['permission_id']] = true;
}

$page_title = 'Roles & Akses';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-shield-lock me-1 text-danger"></i> Roles &amp; Hak Akses</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="row g-3">
      <!-- Role selector -->
      <div class="col-md-3">
        <div class="card">
          <div class="card-header"><i class="bi bi-shield-lock me-1 text-success"></i> Daftar Role</div>
          <div class="list-group list-group-flush">
            <?php foreach ($roles as $r): ?>
              <a href="index.php?role_id=<?= $r['id'] ?>"
                 class="list-group-item list-group-item-action <?= $sel_role_id===$r['id']?'active':'' ?>">
                <?= e($r['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Permissions matrix -->
      <div class="col-md-9">
        <?php $sel_role = array_values(array_filter($roles, fn($r)=>$r['id']===$sel_role_id))[0] ?? null; ?>
        <?php if ($sel_role): ?>
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span>Hak Akses: <strong><?= e($sel_role['label']) ?></strong></span>
            <small class="text-muted"><?= count($assigned) ?> izin aktif</small>
          </div>
          <div class="card-body">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="save_permissions">
              <input type="hidden" name="role_id" value="<?= $sel_role_id ?>">

              <?php foreach ($by_module as $module => $perms): ?>
              <div class="mb-3">
                <div class="d-flex align-items-center mb-2">
                  <strong class="text-capitalize text-success"><?= e($module) ?></strong>
                  <hr class="flex-grow-1 ms-2 my-0">
                </div>
                <div class="row g-2">
                  <?php foreach ($perms as $perm): ?>
                  <div class="col-md-4">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox"
                             name="permissions[]" value="<?= $perm['id'] ?>"
                             id="perm_<?= $perm['id'] ?>"
                             <?= isset($assigned[$perm['id']]) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="perm_<?= $perm['id'] ?>">
                        <?= e($perm['label']) ?>
                        <br><code class="fs-7 text-muted"><?= e($perm['name']) ?></code>
                      </label>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>

              <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-success">
                  <i class="bi bi-check-lg me-1"></i> Simpan Perubahan
                </button>
                <button type="button" class="btn btn-outline-secondary"
                        onclick="document.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=true)">
                  Pilih Semua
                </button>
                <button type="button" class="btn btn-outline-secondary"
                        onclick="document.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=false)">
                  Hapus Semua
                </button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
