<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

if ($editing) {
    require_permission('residents.edit');
    $stmt = $db->prepare('SELECT * FROM residents WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    if (!$resident) { flash('error','Warga tidak ditemukan.'); redirect(APP_URL.'/pages/residents/index.php'); }
} else {
    require_permission('residents.create');
    $resident = [];
}

$units = $db->query(
    'SELECT u.id, u.unit_number, u.block, ut.name AS type_name
     FROM units u JOIN unit_types ut ON ut.id = u.unit_type_id
     ORDER BY u.block, u.unit_number'
)->fetch_all(MYSQLI_ASSOC);

// Load users dengan role warga untuk dropdown
$users = $db->query(
    "SELECT u.id, u.name, u.email FROM users u
     JOIN roles r ON r.id=u.role_id
     WHERE r.name='warga' ORDER BY u.name"
)->fetch_all(MYSQLI_ASSOC);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'unit_id'        => (int)$_POST['unit_id'],
        'user_id'        => (int)($_POST['user_id'] ?? 0) ?: null,
        'name'           => clean($_POST['name'] ?? ''),
        'id_card_number' => clean($_POST['id_card_number'] ?? ''),
        'phone'          => clean($_POST['phone'] ?? ''),
        'email'          => clean($_POST['email'] ?? ''),
        'status'         => clean($_POST['status'] ?? 'pemilik'),
        'move_in_date'   => clean($_POST['move_in_date'] ?? ''),
        'notes'          => clean($_POST['notes'] ?? ''),
        'is_active'      => isset($_POST['is_active']) ? 1 : 0,
    ];

    if (!$data['name'])    $errors[] = 'Nama wajib diisi.';
    if (!$data['phone'])   $errors[] = 'No. HP wajib diisi.';
    if (!$data['unit_id']) $errors[] = 'Unit wajib dipilih.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Format email tidak valid.';

    if (empty($errors)) {
        if ($editing) {
            $stmt = $db->prepare(
                'UPDATE residents SET unit_id=?,user_id=?,name=?,id_card_number=?,phone=?,email=?,
                 status=?,move_in_date=?,notes=?,is_active=? WHERE id=?'
            );
            $stmt->bind_param('iisssssssii',
                $data['unit_id'],$data['user_id'],$data['name'],$data['id_card_number'],
                $data['phone'],$data['email'],$data['status'],
                $data['move_in_date'],$data['notes'],$data['is_active'],$id);
            $stmt->execute();
            log_activity('update','residents',"Resident #{$id} updated");
            flash('success','Data warga berhasil diperbarui.');
        } else {
            $stmt = $db->prepare(
                'INSERT INTO residents (unit_id,user_id,name,id_card_number,phone,email,status,move_in_date,notes)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->bind_param('iisssssss',
                $data['unit_id'],$data['user_id'],$data['name'],$data['id_card_number'],
                $data['phone'],$data['email'],$data['status'],
                $data['move_in_date'],$data['notes']);
            $stmt->execute();
            // Update unit status
            $upd = $db->prepare("UPDATE units SET status='dihuni' WHERE id=?");
            $upd->bind_param('i',$data['unit_id']);
            $upd->execute();
            log_activity('create','residents','Resident '.$data['name'].' created');
            flash('success','Data warga berhasil ditambahkan.');
        }
        redirect(APP_URL.'/pages/residents/index.php');
    }
    $resident = $data;
}

$page_title = $editing ? 'Edit Warga' : 'Tambah Warga';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-people me-1 text-primary"></i> <?= $page_title ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
    <?php endif; ?>
    <div class="card" style="max-width:680px">
      <div class="card-header"><?= $page_title ?></div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Unit <span class="text-danger">*</span></label>
              <select name="unit_id" class="form-select" required>
                <option value="">— Pilih Unit —</option>
                <?php foreach ($units as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= ($resident['unit_id'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                    Blok <?= e($u['block']) ?> - <?= e($u['unit_number']) ?> (<?= e($u['type_name']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Link Akun User</label>
              <select name="user_id" class="form-select">
                <option value="">— Belum Punya Akun —</option>
                <?php foreach ($users as $usr): ?>
                  <option value="<?= $usr['id'] ?>" <?= ($resident['user_id'] ?? 0) == $usr['id'] ? 'selected' : '' ?>>
                    <?= e($usr['name']) ?> (<?= e($usr['email']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Jika warga punya akun login, pilih di sini</small>
            </div>
            <div class="col-md-8">
              <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required
                     value="<?= e($resident['name'] ?? '') ?>" placeholder="Nama sesuai KTP">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="pemilik" <?= ($resident['status']??'pemilik')==='pemilik'?'selected':'' ?>>Pemilik</option>
                <option value="penyewa" <?= ($resident['status']??'')==='penyewa'?'selected':'' ?>>Penyewa</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">No. KTP</label>
              <input type="text" name="id_card_number" class="form-control" maxlength="20"
                     value="<?= e($resident['id_card_number'] ?? '') ?>" placeholder="16 digit NIK">
            </div>
            <div class="col-md-6">
              <label class="form-label">No. HP <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" required
                     value="<?= e($resident['phone'] ?? '') ?>" placeholder="08xx-xxxx-xxxx">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= e($resident['email'] ?? '') ?>" placeholder="email@contoh.com">
            </div>
            <div class="col-md-6">
              <label class="form-label">Tanggal Pindah Masuk</label>
              <input type="date" name="move_in_date" class="form-control"
                     value="<?= e($resident['move_in_date'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Catatan</label>
              <textarea name="notes" class="form-control" rows="2"><?= e($resident['notes'] ?? '') ?></textarea>
            </div>
            <?php if ($editing): ?>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                       <?= ($resident['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Warga Aktif</label>
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
