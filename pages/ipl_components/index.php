<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('ipl_components.view');

$db = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('ipl_components.manage');
    csrf_verify();
    $action = clean($_POST['_action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $stmt = $db->prepare('UPDATE ipl_components SET is_active=1-is_active WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash('success', 'Status komponen diperbarui.');
        redirect(APP_URL . '/pages/ipl_components/index.php');
    }

    $name = clean($_POST['name'] ?? '');
    $amount = (float)str_replace('.', '', preg_replace('/[^0-9.]/', '', $_POST['amount'] ?? '0'));
    $vacant = isset($_POST['charge_when_vacant']) ? 1 : 0;
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($name === '') $errors[] = 'Nama komponen wajib diisi.';
    if ($amount < 0) $errors[] = 'Nominal tidak valid.';

    if (!$errors) {
        if ($id) {
            $stmt = $db->prepare('UPDATE ipl_components SET name=?,amount=?,charge_when_vacant=?,sort_order=? WHERE id=?');
            $stmt->bind_param('sdiii', $name, $amount, $vacant, $sort, $id);
        } else {
            $stmt = $db->prepare('INSERT INTO ipl_components (name,amount,charge_when_vacant,sort_order) VALUES (?,?,?,?)');
            $stmt->bind_param('sdii', $name, $amount, $vacant, $sort);
        }
        $stmt->execute();
        flash('success', 'Komponen IPL berhasil disimpan.');
        redirect(APP_URL . '/pages/ipl_components/index.php');
    }
}

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM ipl_components WHERE id=?');
    $id = (int)$_GET['edit'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
}
$rows = $db->query('SELECT * FROM ipl_components ORDER BY sort_order,name')->fetch_all(MYSQLI_ASSOC);
$page_title = 'Komponen IPL';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-list-check me-1 text-success"></i> Komponen IPL</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
    <div class="row g-3">
      <?php if (can('ipl_components.manage')): ?>
      <div class="col-lg-4">
        <div class="card"><div class="card-header"><?= $edit ? 'Edit' : 'Tambah' ?> Komponen</div><div class="card-body">
          <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-3"><label class="form-label">Nama</label><input name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label">Nominal (Rp)</label><input name="amount" class="form-control" required inputmode="numeric" value="<?= e(isset($edit['amount']) ? number_format((float)$edit['amount'],0,',','.') : '') ?>"></div>
            <div class="mb-3"><label class="form-label">Urutan</label><input type="number" name="sort_order" class="form-control" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
            <div class="form-check mb-3"><input type="checkbox" name="charge_when_vacant" class="form-check-input" id="vacant" <?= !empty($edit['charge_when_vacant']) ? 'checked' : '' ?>><label for="vacant" class="form-check-label">Tetap ditagih saat unit kosong (komponen dasar)</label></div>
            <button class="btn btn-success">Simpan</button>
            <?php if ($edit): ?><a href="index.php" class="btn btn-outline-secondary">Batal</a><?php endif; ?>
          </form>
        </div></div>
      </div>
      <?php endif; ?>
      <div class="<?= can('ipl_components.manage') ? 'col-lg-8' : 'col-12' ?>">
        <div class="card"><div class="card-header">Daftar Komponen</div><div class="table-responsive"><table class="table mb-0">
          <thead><tr><th>Nama</th><th>Nominal</th><th>Berlaku</th><th>Status</th><?php if(can('ipl_components.manage')):?><th>Aksi</th><?php endif;?></tr></thead><tbody>
          <?php foreach($rows as $r): ?><tr>
            <td><?= e($r['name']) ?></td><td><?= idr((float)$r['amount']) ?></td>
            <td><?= $r['charge_when_vacant'] ? 'Semua unit' : 'Hanya unit dihuni' ?></td>
            <td><span class="badge bg-<?= $r['is_active']?'success':'secondary' ?>"><?= $r['is_active']?'Aktif':'Nonaktif' ?></span></td>
            <?php if(can('ipl_components.manage')):?><td class="text-nowrap"><a href="?edit=<?=$r['id']?>" class="btn btn-sm btn-outline-primary">Edit</a><form method="POST" class="d-inline"><?=csrf_field()?><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-sm btn-outline-secondary"><?=$r['is_active']?'Nonaktifkan':'Aktifkan'?></button></form></td><?php endif;?>
          </tr><?php endforeach; ?>
          </tbody></table></div></div>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
