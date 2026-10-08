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

// Katalog komponen aktif dengan nominal
$components = $db->query('SELECT * FROM ipl_components WHERE is_active=1 ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);

// Assigned: component_id yang di-centang
$assigned = [];
if ($editing) {
    $stmt = $db->prepare('SELECT component_id FROM unit_type_components WHERE unit_type_id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $assigned[] = (int)$r['component_id'];
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name'        => clean($_POST['name']        ?? ''),
        'description' => clean($_POST['description'] ?? ''),
    ];
    $comp_ids = [];
    foreach ($components as $c) {
        if (isset($_POST['comp_on'][(int)$c['id']])) {
            $comp_ids[] = (int)$c['id'];
        }
    }
    
    if (!$data['name']) $errors[] = 'Nama tipe wajib diisi.';
    if (empty($comp_ids)) $errors[] = 'Minimal satu komponen harus di-assign.';

    // Cek duplikat nama
    if (empty($errors)) {
        $chk = $db->prepare('SELECT id FROM unit_types WHERE name = ? AND id != ?');
        $chk->bind_param('si', $data['name'], $id);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) $errors[] = 'Nama tipe unit sudah ada.';
    }

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            // Hitung total IPL dari komponen yang di-assign
            $placeholders = implode(',', array_fill(0, count($comp_ids), '?'));
            $stmt = $db->prepare("SELECT SUM(amount) FROM ipl_components WHERE id IN ($placeholders)");
            $stmt->bind_param(str_repeat('i', count($comp_ids)), ...$comp_ids);
            $stmt->execute();
            $ipl_amount = (float)$stmt->get_result()->fetch_row()[0];
            
            if ($editing) {
                $stmt = $db->prepare('UPDATE unit_types SET name=?, description=?, ipl_amount=? WHERE id=?');
                $stmt->bind_param('ssdi', $data['name'], $data['description'], $ipl_amount, $id);
                $stmt->execute();
            } else {
                $stmt = $db->prepare('INSERT INTO unit_types (name, description, ipl_amount) VALUES (?,?,?)');
                $stmt->bind_param('ssd', $data['name'], $data['description'], $ipl_amount);
                $stmt->execute();
                $id = $db->insert_id;
            }
            
            // Sinkronkan assignment komponen
            $stmt = $db->prepare('DELETE FROM unit_type_components WHERE unit_type_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            
            $stmt = $db->prepare('INSERT INTO unit_type_components (unit_type_id, component_id) VALUES (?,?)');
            foreach ($comp_ids as $cid) {
                $stmt->bind_param('ii', $id, $cid);
                $stmt->execute();
            }
            
            $db->commit();
            log_activity($editing ? 'update' : 'create', 'unit_types', 'Unit type ' . $data['name'] . ' saved (IPL ' . $ipl_amount . ')');
            flash('success', 'Tipe unit berhasil disimpan. Total IPL: Rp ' . number_format($ipl_amount, 0, ',', '.'));
            redirect(APP_URL . '/pages/unit_types/index.php');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Unit type save error: ' . $e->getMessage());
            $errors[] = 'Gagal menyimpan. Silakan coba lagi.';
        }
    }
    $type = array_merge($type, $data);
    $assigned = $comp_ids;
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

    <div class="card" style="max-width:640px">
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
              <label class="form-label">Deskripsi <small class="text-muted">(opsional)</small></label>
              <textarea name="description" class="form-control" rows="2"
                        maxlength="255"><?= e($type['description'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Komponen IPL <span class="text-danger">*</span></label>
              <div class="alert alert-info small mb-2">
                <i class="bi bi-info-circle me-1"></i> Centang komponen yang berlaku untuk tipe ini. Nominal diatur di menu <strong>Komponen IPL</strong>.
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="compTable">
                  <thead><tr><th style="width:36px"></th><th>Komponen</th><th>Berlaku</th><th class="text-end">Nominal</th></tr></thead>
                  <tbody>
                  <?php foreach ($components as $c): $cid = (int)$c['id']; $on = in_array($cid, $assigned); ?>
                    <tr>
                      <td><input type="checkbox" class="form-check-input comp-check" data-amount="<?= $c['amount'] ?>"
                                 name="comp_on[<?= $cid ?>]" value="1" <?= $on ? 'checked' : '' ?>></td>
                      <td><strong><?= e($c['name']) ?></strong></td>
                      <td class="small text-muted"><?= $c['charge_when_vacant'] ? 'Semua unit' : 'Hanya dihuni' ?></td>
                      <td class="text-end text-muted"><?= idr((float)$c['amount']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr class="table-light">
                      <td colspan="3" class="text-end fw-semibold">Total IPL / Bulan</td>
                      <td class="text-end fw-bold text-success" id="iplTotal">Rp 0</td>
                    </tr>
                  </tfoot>
                </table>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
  const totalEl = document.getElementById('iplTotal');
  function recalc() {
    let total = 0;
    document.querySelectorAll('.comp-check:checked').forEach(chk => {
      total += parseFloat(chk.dataset.amount) || 0;
    });
    totalEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
  }
  document.querySelectorAll('.comp-check').forEach(chk => chk.addEventListener('change', recalc));
  recalc();
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
