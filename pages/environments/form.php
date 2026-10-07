<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';

$db      = db();
$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

if ($editing) {
    require_permission('environments.edit');
    $stmt = $db->prepare('SELECT * FROM environments WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $env = $stmt->get_result()->fetch_assoc();
    if (!$env) { flash('error', 'Lingkungan tidak ditemukan.'); redirect(APP_URL . '/pages/environments/index.php'); }
} else {
    require_permission('environments.create');
    $env = [];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name'          => clean($_POST['name']          ?? ''),
        'code'          => strtolower(trim(clean($_POST['code'] ?? ''))),
        'address'       => clean($_POST['address']       ?? ''),
        'rt'            => clean($_POST['rt']            ?? ''),
        'rw'            => clean($_POST['rw']            ?? ''),
        'kelurahan'     => clean($_POST['kelurahan']     ?? ''),
        'kecamatan'     => clean($_POST['kecamatan']     ?? ''),
        'city'          => clean($_POST['city']          ?? ''),
        'custom_domain' => clean($_POST['custom_domain'] ?? ''),
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
    ];

    if (!$data['name']) $errors[] = 'Nama lingkungan wajib diisi.';
    if (!$data['code']) $errors[] = 'Kode lingkungan wajib diisi.';
    if ($data['code'] && !preg_match('/^[a-z0-9\-]+$/', $data['code']))
        $errors[] = 'Kode hanya boleh huruf kecil, angka, dan tanda hubung.';

    if (empty($errors)) {
        $chk = $db->prepare('SELECT id FROM environments WHERE code = ? AND id != ?');
        $chk->bind_param('si', $data['code'], $id);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) $errors[] = 'Kode lingkungan sudah dipakai.';
    }

    if (empty($errors)) {
        if ($editing) {
            $stmt = $db->prepare('UPDATE environments SET name=?,code=?,address=?,rt=?,rw=?,kelurahan=?,kecamatan=?,city=?,custom_domain=?,is_active=? WHERE id=?');
            $stmt->bind_param('sssssssssii',
                $data['name'], $data['code'], $data['address'],
                $data['rt'], $data['rw'], $data['kelurahan'],
                $data['kecamatan'], $data['city'], $data['custom_domain'],
                $data['is_active'], $id);
            $stmt->execute();
            log_activity('update', 'environments', "Environment #{$id} updated");
            flash('success', 'Lingkungan berhasil diperbarui.');
        } else {
            $stmt = $db->prepare('INSERT INTO environments (name,code,address,rt,rw,kelurahan,kecamatan,city,custom_domain,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('sssssssssi',
                $data['name'], $data['code'], $data['address'],
                $data['rt'], $data['rw'], $data['kelurahan'],
                $data['kecamatan'], $data['city'], $data['custom_domain'],
                $data['is_active']);
            $stmt->execute();
            log_activity('create', 'environments', 'Environment ' . $data['name'] . ' created');
            flash('success', 'Lingkungan berhasil ditambahkan.');
        }
        redirect(APP_URL . '/pages/environments/index.php');
    }
    $env = $data;
}

$page_title = $editing ? 'Edit Lingkungan' : 'Tambah Lingkungan';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-buildings me-1 text-success"></i> <?= $page_title ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
    <?php endif; ?>

    <div class="card" style="max-width:600px">
      <div class="card-header"><?= $page_title ?></div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Nama Lingkungan <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required maxlength="100"
                     value="<?= e($env['name'] ?? '') ?>" placeholder="Cth: Arya Green Pamulang">
            </div>
            <div class="col-md-6">
              <label class="form-label">Kode <span class="text-danger">*</span></label>
              <input type="text" name="code" class="form-control" required maxlength="20"
                     value="<?= e($env['code'] ?? '') ?>" placeholder="Cth: arya-green"
                     <?= $editing ? 'readonly' : '' ?>>
              <div class="form-text">Huruf kecil, angka, tanda hubung. Tidak bisa diubah setelah dibuat.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Domain Kustom <small class="text-muted">(opsional)</small></label>
              <input type="text" name="custom_domain" class="form-control" maxlength="100"
                     value="<?= e($env['custom_domain'] ?? '') ?>" placeholder="Cth: ipl.aryagreenpamulang.my.id">
            </div>
            <div class="col-12">
              <label class="form-label">Alamat <small class="text-muted">(opsional)</small></label>
              <textarea name="address" class="form-control" rows="2" maxlength="500"><?= e($env['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-3">
              <label class="form-label">RT</label>
              <input type="text" name="rt" class="form-control" maxlength="10" value="<?= e($env['rt'] ?? '') ?>" placeholder="001">
            </div>
            <div class="col-md-3">
              <label class="form-label">RW</label>
              <input type="text" name="rw" class="form-control" maxlength="10" value="<?= e($env['rw'] ?? '') ?>" placeholder="010">
            </div>
            <div class="col-md-6">
              <label class="form-label">Kelurahan</label>
              <input type="text" name="kelurahan" class="form-control" maxlength="100" value="<?= e($env['kelurahan'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Kecamatan</label>
              <input type="text" name="kecamatan" class="form-control" maxlength="100" value="<?= e($env['kecamatan'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Kota/Kabupaten</label>
              <input type="text" name="city" class="form-control" maxlength="100" value="<?= e($env['city'] ?? '') ?>">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                       <?= ($env['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Aktif</label>
              </div>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-lg me-1"></i> Simpan
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Batal</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
