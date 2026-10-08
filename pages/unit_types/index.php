<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('units.view');

$db = db();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('units.delete');
    $id = (int)$_POST['id'];
    // Cek apakah tipe masih dipakai
    $chk = $db->prepare('SELECT COUNT(*) FROM units WHERE unit_type_id = ?');
    $chk->bind_param('i', $id);
    $chk->execute();
    if ($chk->get_result()->fetch_row()[0] > 0) {
        flash('error', 'Tipe unit masih digunakan oleh unit lain, tidak bisa dihapus.');
    } else {
        $stmt = $db->prepare('DELETE FROM unit_types WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity('delete', 'unit_types', "Unit type #{$id} deleted");
        flash('success', 'Tipe unit berhasil dihapus.');
    }
    redirect(APP_URL . '/pages/unit_types/index.php');
}

$types = $db->query(
    'SELECT ut.*, COUNT(u.id) AS unit_count
     FROM unit_types ut
     LEFT JOIN units u ON u.unit_type_id = ut.id
     GROUP BY ut.id
     ORDER BY ut.name'
)->fetch_all(MYSQLI_ASSOC);

// Komponen per tipe: unit_type_id => list "nama: Rp X"
$comp_rows = $db->query(
    'SELECT utc.unit_type_id, c.name, c.amount
     FROM unit_type_components utc
     JOIN ipl_components c ON c.id = utc.component_id
     WHERE c.is_active = 1
     ORDER BY c.sort_order'
)->fetch_all(MYSQLI_ASSOC);
$comp_map = [];
foreach ($comp_rows as $cr) {
    $comp_map[$cr['unit_type_id']][] = e($cr['name']) . ': ' . idr((float)$cr['amount']);
}

$page_title = 'Tipe Unit';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-grid me-1 text-success"></i> Tipe Unit</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-grid me-1 text-success"></i> Daftar Tipe Unit</span>
        <?php if (can('units.create')): ?>
          <a href="form.php" class="btn btn-success btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Tambah Tipe
          </a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>#</th>
              <th>Nama Tipe</th>
              <th>Deskripsi</th>
              <th class="text-end">IPL/Bulan</th>
              <th>Komponen</th>
              <th class="text-center">Jumlah Unit</th>
              <th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($types)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Belum ada tipe unit.</td></tr>
            <?php else: foreach ($types as $i => $t): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e($t['name']) ?></strong></td>
                <td class="text-muted small"><?= e($t['description'] ?: '—') ?></td>
                <td class="text-end fw-semibold text-success"><?= idr((float)$t['ipl_amount']) ?></td>
                <td class="small">
                  <?php if (!empty($comp_map[$t['id']])): ?>
                    <?= implode('<br>', $comp_map[$t['id']]) ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <span class="badge bg-secondary"><?= (int)$t['unit_count'] ?></span>
                </td>
                <td>
                  <?php if (can('units.edit')): ?>
                    <a href="form.php?id=<?= $t['id'] ?>"
                       class="btn btn-sm btn-outline-primary py-0 px-2">
                      <i class="bi bi-pencil"></i>
                    </a>
                  <?php endif; ?>
                  <?php if (can('units.delete') && (int)$t['unit_count'] === 0): ?>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="delete">
                      <input type="hidden" name="id" value="<?= $t['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                              data-confirm="Hapus tipe unit <?= e($t['name']) ?>?">
                        <i class="bi bi-trash"></i>
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
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
