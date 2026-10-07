<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';

$db      = db();
$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

if ($editing) {
    require_permission('units.edit');
    $stmt = $db->prepare('SELECT * FROM unit_types WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc();
    if (!$type) { flash('error', 'Tipe unit tidak ditemukan.'); redirect(APP_URL . '/pages/unit_types/index.php'); }
} else {
    require_permission('units.create');
    $type = [];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name'        => clean($_POST['name']        ?? ''),
        'description' => clean($_POST['description'] ?? ''),
        'ipl_amount'  => (float)str_replace(['.', ','], ['', '.'], $_POST['ipl_amount'] ?? '0'),
    ];

    if (!$data['name'])          $errors[] = 'Nama tipe wajib diisi.';
    if ($data['ipl_amount'] < 0) $errors[] = 'Nominal IPL tidak boleh negatif.';

    // Cek duplikat nama
    if (empty($errors)) {
        $chk = $db->prepare('SELECT id FROM unit_types WHERE name = ? AND id != ?');
        $chk->bind_param('si', $data['name'], $id);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) $errors[] = 'Nama tipe unit sudah ada.';
    }

    if (empty($errors)) {
        if ($editing) {
            $stmt = $db->prepare('UPDATE unit_types SET name=?, description=?, ipl_amount=? WHERE id=?');
            $stmt->bind_param('ssdi', $data['name'], $data['description'], $data['ipl_amount'], $id);
            $stmt->execute();
            log_activity('update', 'unit_types', "Unit type #{$id} updated");
            flash('success', 'Tipe unit berhasil diperbarui.');
        } else {
            $stmt = $db->prepare('INSERT INTO unit_types (name, description, ipl_amount) VALUES (?,?,?)');
            $stmt->bind_param('ssd', $data['name'], $data['description'], $data['ipl_amount']);
            $stmt->execute();
            log_activity('create', 'unit_types', 'Unit type ' . $data['name'] . ' created');
            flash('success', 'Tipe unit berhasil ditambahkan.');
        }
        redirect(APP_URL . '/pages/unit_types/index.php');
    }
    $type = $data;
}

$page_title = $editing ? 'Edit Tipe Unit' : 'Tambah Tipe Unit';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-grid me-1 text-success"></i> <?= $page_title ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
    <?php endif; ?>

    <div class="card" style="max-width:520px">
      <div class="card-header"><?= $page_title ?></div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Nama Tipe <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required maxlength="100"
                     value="<?= e($type['name'] ?? '') ?>" placeholder="Cth: Tipe 36, Tipe 45, Ruko">
            </div>
            <div class="col-12">
              <label class="form-label">Nominal IPL / Bulan (Rp) <span class="text-danger">*</span></label>
              <input type="text" name="ipl_amount" class="form-control" required
                     value="<?= e(isset($type['ipl_amount']) ? number_format((float)$type['ipl_amount'], 0, ',', '.') : '') ?>"
                     placeholder="Cth: 300.000" inputmode="numeric" data-rupiah>
            </div>
            <div class="col-12">
              <label class="form-label">Deskripsi <small class="text-muted">(opsional)</small></label>
              <textarea name="description" class="form-control" rows="2"
                        maxlength="255"><?= e($type['description'] ?? '') ?></textarea>
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
