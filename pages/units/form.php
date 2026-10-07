<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

if ($editing) {
    require_permission('units.edit');
    $stmt = $db->prepare('SELECT * FROM units WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $unit = $stmt->get_result()->fetch_assoc();
    if (!$unit) { flash('error', 'Unit tidak ditemukan.'); redirect(APP_URL . '/pages/units/index.php'); }
} else {
    require_permission('units.create');
    $unit = [];
}

$unit_types = $db->query('SELECT * FROM unit_types ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'unit_type_id' => (int)$_POST['unit_type_id'],
        'unit_number'  => clean($_POST['unit_number'] ?? ''),
        'block'        => strtoupper(clean($_POST['block'] ?? '')),
        'floor'        => (int)($_POST['floor'] ?? 1),
        'area_sqm'     => (float)str_replace(',', '.', $_POST['area_sqm'] ?? 0),
        'status'       => clean($_POST['status'] ?? 'kosong'),
        'notes'        => clean($_POST['notes'] ?? ''),
    ];
    if (!$data['unit_number']) $errors[] = 'Nomor unit wajib diisi.';
    if (!$data['block'])       $errors[] = 'Blok wajib diisi.';
    if (!$data['unit_type_id']) $errors[] = 'Tipe unit wajib dipilih.';

    if (empty($errors)) {
        if ($editing) {
            $stmt = $db->prepare(
                'UPDATE units SET unit_type_id=?,unit_number=?,block=?,floor=?,area_sqm=?,status=?,notes=? WHERE id=?'
            );
            $stmt->bind_param('issidssi', $data['unit_type_id'],$data['unit_number'],$data['block'],
                $data['floor'],$data['area_sqm'],$data['status'],$data['notes'],$id);
            $stmt->execute();
            log_activity('update','units',"Unit #{$id} updated");
            flash('success','Unit berhasil diperbarui.');
        } else {
            $stmt = $db->prepare(
                'INSERT INTO units (unit_type_id,unit_number,block,floor,area_sqm,status,notes) VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->bind_param('issidss', $data['unit_type_id'],$data['unit_number'],$data['block'],
                $data['floor'],$data['area_sqm'],$data['status'],$data['notes']);
            $stmt->execute();
            log_activity('create','units','Unit '.$data['unit_number'].' created');
            flash('success','Unit berhasil ditambahkan.');
        }
        redirect(APP_URL . '/pages/units/index.php');
    }
    $unit = $data; // repopulate
}

$page_title = $editing ? 'Edit Unit' : 'Tambah Unit';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-houses me-1 text-success"></i> <?= $page_title ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".e($e)."</li>"; ?></ul></div>
    <?php endif; ?>
    <div class="card" style="max-width:640px">
      <div class="card-header"><?= $page_title ?></div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nomor Unit <span class="text-danger">*</span></label>
              <input type="text" name="unit_number" class="form-control" required
                     value="<?= e($unit['unit_number'] ?? '') ?>" placeholder="Cth: A-01">
            </div>
            <div class="col-md-6">
              <label class="form-label">Blok <span class="text-danger">*</span></label>
              <input type="text" name="block" class="form-control" required
                     value="<?= e($unit['block'] ?? '') ?>" placeholder="Cth: A" maxlength="10">
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipe Unit <span class="text-danger">*</span></label>
              <select name="unit_type_id" class="form-select" required>
                <option value="">— Pilih Tipe —</option>
                <?php foreach ($unit_types as $t): ?>
                  <option value="<?= $t['id'] ?>" <?= ($unit['unit_type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                    <?= e($t['name']) ?> (<?= idr((float)$t['ipl_amount']) ?>/bln)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Lantai</label>
              <input type="number" name="floor" class="form-control" min="1" max="99"
                     value="<?= e($unit['floor'] ?? 1) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Luas (m²)</label>
              <input type="number" name="area_sqm" class="form-control" step="0.01" min="0"
                     value="<?= e($unit['area_sqm'] ?? '') ?>" placeholder="0">
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="kosong" <?= ($unit['status'] ?? '') === 'kosong' ? 'selected' : '' ?>>Kosong</option>
                <option value="dihuni" <?= ($unit['status'] ?? '') === 'dihuni' ? 'selected' : '' ?>>Dihuni</option>
                <option value="dijual" <?= ($unit['status'] ?? '') === 'dijual' ? 'selected' : '' ?>>Dijual</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Catatan</label>
              <textarea name="notes" class="form-control" rows="2"><?= e($unit['notes'] ?? '') ?></textarea>
            </div>
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
