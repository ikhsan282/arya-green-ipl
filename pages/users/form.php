<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';

$db      = db();
$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
$self    = $id === auth_id();

if ($editing) {
    require_permission('users.edit');
    $stmt = $db->prepare('SELECT * FROM users WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) { flash('error','Pengguna tidak ditemukan.'); redirect(APP_URL.'/pages/users/index.php'); }
} else {
    require_permission('users.create');
    $user = [];
}

$roles  = $db->query('SELECT * FROM roles ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name'     => clean($_POST['name']     ?? ''),
        'username' => clean($_POST['username'] ?? ''),
        'email'    => clean($_POST['email']    ?? ''),
        'role_id'  => (int)($_POST['role_id']  ?? 4),
        'password' => $_POST['password']        ?? '',
        'is_active'=> isset($_POST['is_active']) ? 1 : 0,
    ];

    if (!$data['name'])     $errors[] = 'Nama wajib diisi.';
    if (!$data['username']) $errors[] = 'Username wajib diisi.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
    if (!$editing && strlen($data['password']) < 8) $errors[] = 'Password minimal 8 karakter.';
    if ($editing && $data['password'] && strlen($data['password']) < 8) $errors[] = 'Password minimal 8 karakter.';

    // Uniqueness checks
    if (empty($errors)) {
        $chk = $db->prepare('SELECT id FROM users WHERE username=? AND id!=?');
        $chk->bind_param('si', $data['username'], $id);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) $errors[] = 'Username sudah digunakan.';

        $chk2 = $db->prepare('SELECT id FROM users WHERE email=? AND id!=?');
        $chk2->bind_param('si', $data['email'], $id);
        $chk2->execute();
        if ($chk2->get_result()->fetch_row()) $errors[] = 'Email sudah digunakan.';
    }

    if (empty($errors)) {
        if ($editing) {
            if ($data['password']) {
                $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost'=>12]);
                $stmt = $db->prepare('UPDATE users SET name=?,username=?,email=?,role_id=?,password=?,is_active=? WHERE id=?');
                $stmt->bind_param('sssisii', $data['name'],$data['username'],$data['email'],$data['role_id'],$hash,$data['is_active'],$id);
            } else {
                $stmt = $db->prepare('UPDATE users SET name=?,username=?,email=?,role_id=?,is_active=? WHERE id=?');
                $stmt->bind_param('sssiii', $data['name'],$data['username'],$data['email'],$data['role_id'],$data['is_active'],$id);
            }
            $stmt->execute();
            // Clear cached permissions if editing self
            if ($self) unset($_SESSION['permissions']);
            log_activity('update','users',"User #{$id} updated");
            flash('success','Data pengguna berhasil diperbarui.');
        } else {
            $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost'=>12]);
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare('INSERT INTO users (name,username,email,role_id,password,verification_token) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('sssiss', $data['name'],$data['username'],$data['email'],$data['role_id'],$hash,$token);
            $stmt->execute();
            $new_id = $db->insert_id;
            send_verification_email($new_id, $data['email'], $data['name']);
            log_activity('create','users','User '.$data['username'].' created');
            flash('success','Pengguna berhasil ditambahkan. Email verifikasi telah dikirim.');
        }
        redirect(APP_URL . '/pages/users/index.php');
    }
    $user = array_merge($user, $data);
}

$page_title = $editing ? 'Edit Pengguna' : 'Tambah Pengguna';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><?= $page_title ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
    <?php endif; ?>
    <div class="card" style="max-width:580px">
      <div class="card-header"><?= $page_title ?></div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required
                     value="<?= e($user['name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Role <span class="text-danger">*</span></label>
              <select name="role_id" class="form-select" <?= $self ? 'disabled' : '' ?>>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= $r['id'] ?>" <?= ($user['role_id']??4)==$r['id']?'selected':'' ?>><?= e($r['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($self): ?><input type="hidden" name="role_id" value="<?= e($user['role_id']) ?>"><?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" required
                     value="<?= e($user['username'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required
                     value="<?= e($user['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Password <?= $editing ? '<small class="text-muted">(kosongkan jika tidak diubah)</small>' : '<span class="text-danger">*</span>' ?></label>
              <input type="password" name="password" class="form-control" <?= $editing ? '' : 'required' ?>
                     minlength="8" placeholder="Minimal 8 karakter" autocomplete="new-password">
            </div>
            <?php if ($editing && !$self): ?>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                       <?= ($user['is_active']??1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Pengguna Aktif</label>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Simpan</button>
            <a href="index.php" class="btn btn-outline-secondary">Batal</a>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
