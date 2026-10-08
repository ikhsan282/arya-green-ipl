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

// Katalog komponen aktif
$components = $db->query('SELECT * FROM ipl_components WHERE is_active=1 ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);

// Assigned map untuk tipe ini: component_id => amount
$assigned = [];
if ($editing) {
    $stmt = $db->prepare('SELECT component_id, amount FROM unit_type_components WHERE unit_type_id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $assigned[(int)$r['component_id']] = (float)$r['amount'];
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name'        => clean($_POST['name']        ?? ''),
        'description' => clean($_POST['description'] ?? ''),
    ];
    $comp_amounts = [];           // component_id => amount (hanya yang dicentang)
    foreach ($components as $c) {
        $cid = (int)$c['id'];
        if (!isset($_POST['comp_on'][$cid])) continue;
        $amt = (float)str_replace(['.', ','], ['', '.'], $_POST['comp_amount'][$cid] ?? '0');
        if ($amt < 0) { $errors[] = 'Nominal komponen "'.$c['name'].'" tidak boleh negatif.'; continue; }
        $comp_amounts[$cid] = $amt;
    }
    $ipl_amount = array_sum($comp_amounts);   // total otomatis

    if (!$data['name']) $errors[] = 'Nama tipe wajib diisi.';
    if ($ipl_amount <= 0) $errors[] = 'Minimal satu komponen harus di-assign dengan nominal.';

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
            $stmt = $db->prepare('INSERT INTO unit_type_components (unit_type_id, component_id, amount) VALUES (?,?,?)');
            foreach ($comp_amounts as $cid => $amt) {
                $stmt->bind_param('iid', $id, $cid, $amt);
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
    $assigned = $comp_amounts;
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
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="compTable">
                  <thead><tr><th style="width:36px"></th><th>Komponen</th><th>Berlaku</th><th style="width:180px">Nominal (Rp)</th></tr></thead>
                  <tbody>
                  <?php foreach ($components as $c): $cid = (int)$c['id']; $on = array_key_exists($cid, $assigned); ?>
                    <tr>
                      <td><input type="checkbox" class="form-check-input comp-check" data-idx="<?= $cid ?>"
                                 name="comp_on[<?= $cid ?>]" value="1" <?= $on ? 'checked' : '' ?>></td>
                      <td><strong><?= e($c['name']) ?></strong></td>
                      <td class="small text-muted"><?= $c['charge_when_vacant'] ? 'Semua unit' : 'Hanya dihuni' ?></td>
                      <td><input type="text" class="form-control form-control-sm text-end comp-amount" data-idx="<?= $cid ?>"
                                 name="comp_amount[<?= $cid ?>]" inputmode="numeric" data-rupiah
                                 value="<?= e(isset($assigned[$cid]) ? number_format($assigned[$cid], 0, ',', '.') : '') ?>" disabled></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr class="table-light">
                      <td colspan="3" class="text-end fw-semibold">Total IPL / Bulan</td>
                      <td class="text-end fw-bold text-success" id="iplTotal"><?= e(number_format(array_sum($assigned), 0, ',', '.')) ?></td>
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
  const parseRp = v => parseFloat(String(v).replace(/[^0-9]/g, '')) || 0;

  function recalc() {
    let total = 0;
    document.querySelectorAll('.comp-check').forEach(chk => {
      const cid = chk.dataset.idx;
      const amt = document.querySelector('.comp-amount[data-idx="' + cid + '"]') ||
                  document.querySelector('input[name="comp_amount[' + cid + ']"]');
      if (!amt) return;
      amt.disabled = !chk.checked;
      if (chk.checked) total += parseRp(amt.value);
    });
    totalEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
  }
  document.querySelectorAll('.comp-check').forEach(chk => chk.addEventListener('change', recalc));
  document.querySelectorAll('.comp-amount').forEach(inp => inp.addEventListener('input', recalc));
  recalc();
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
