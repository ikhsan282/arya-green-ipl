<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('inventory.view');

$db  = db();
$uid = auth_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('inventory.manage');
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = clean($_POST['name'] ?? '');
        $cat   = clean($_POST['category'] ?? 'umum');
        $qty   = (int)($_POST['quantity'] ?? 1);
        $unit  = clean($_POST['unit'] ?? '');
        $cond  = in_array($_POST['condition']??'',['baik','rusak_ringan','rusak_berat','tidak_ada']) ? $_POST['condition'] : 'baik';
        $loc   = clean($_POST['location'] ?? '');
        $pdate = clean($_POST['purchase_date'] ?? '') ?: null;
        $price = (float)str_replace(['.', ','], ['', '.'], $_POST['purchase_price'] ?? '0') ?: null;
        $notes = clean($_POST['notes'] ?? '');
        $photo = null;
        if (!empty($_FILES['photo_file']['name'])) {
            try { $photo = upload_proof($_FILES['photo_file']); } catch (Exception $e) { flash('error', $e->getMessage()); redirect(APP_URL.'/pages/inventory/index.php'); }
        }
        if (!$name) { flash('error', 'Nama aset wajib diisi.'); redirect(APP_URL.'/pages/inventory/index.php'); }

        if ($id) {
            $s = $db->prepare('UPDATE inventory SET name=?,category=?,quantity=?,unit=?,`condition`=?,location=?,purchase_date=?,purchase_price=?,notes=?' . ($photo ? ',photo_file=?' : '') . ' WHERE id=?');
            if ($photo) $s->bind_param('ssissssdssi', $name,$cat,$qty,$unit,$cond,$loc,$pdate,$price,$notes,$photo,$id);
            else        $s->bind_param('ssissssdsi',  $name,$cat,$qty,$unit,$cond,$loc,$pdate,$price,$notes,$id);
            $s->execute();
            flash('success', 'Aset diperbarui.');
        } else {
            $s = $db->prepare('INSERT INTO inventory (name,category,quantity,unit,`condition`,location,purchase_date,purchase_price,notes,photo_file,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $s->bind_param('ssissssdssi', $name,$cat,$qty,$unit,$cond,$loc,$pdate,$price,$notes,$photo,$uid);
            $s->execute();
            flash('success', 'Aset berhasil ditambahkan.');
        }
        log_activity($id ? 'update' : 'create', 'inventory', "Aset: {$name}");
        redirect(APP_URL.'/pages/inventory/index.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM inventory WHERE id=?')->bind_param('i',$id) && $db->execute();
        flash('success', 'Aset dihapus.');
        redirect(APP_URL.'/pages/inventory/index.php');
    }
}

$search = clean($_GET['q'] ?? '');
$f_cond = clean($_GET['condition'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1)); $per = 20;

$where = ['1=1']; $params = []; $types = '';
if ($search) { $where[] = '(name LIKE ? OR category LIKE ? OR location LIKE ?)'; $l="%{$search}%"; $params[]=$l;$params[]=$l;$params[]=$l; $types.='sss'; }
if ($f_cond) { $where[] = '`condition`=?'; $params[]=$f_cond; $types.='s'; }
$wsql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM inventory WHERE {$wsql}");
if ($types) $cnt->bind_param($types,...$params); $cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag = paginate($total, $per, $page);

$stmt = $db->prepare("SELECT * FROM inventory WHERE {$wsql} ORDER BY category,name LIMIT ? OFFSET ?");
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types.'ii',...$fp); $stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cond_map = ['baik'=>['success','Baik'],'rusak_ringan'=>['warning','Rusak Ringan'],'rusak_berat'=>['danger','Rusak Berat'],'tidak_ada'=>['secondary','Tidak Ada']];

$page_title = 'Inventaris Aset';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-box-seam me-1 text-warning"></i> Inventaris Aset</h6>
    <?php if (can('inventory.manage')): ?>
    <button class="btn btn-sm btn-warning ms-auto" data-bs-toggle="modal" data-bs-target="#modalSave" data-id="0">
      <i class="bi bi-plus-lg me-1"></i>Tambah Aset
    </button>
    <?php endif; ?>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Filter -->
    <form method="GET" class="d-flex gap-2 mb-3 flex-wrap align-items-end">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px" placeholder="Cari aset…" value="<?= e($search) ?>">
      <select name="condition" class="form-select form-select-sm" style="max-width:160px">
        <option value="">Semua Kondisi</option>
        <?php foreach ($cond_map as $v=>[$c,$l]): ?>
          <option value="<?= $v ?>" <?= $f_cond===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search me-1"></i>Filter</button>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </form>

    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>Nama</th><th>Kategori</th><th>Jumlah</th><th>Kondisi</th>
              <th>Lokasi</th><th>Harga Beli</th>
              <?php if (can('inventory.manage')): ?><th></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data aset.</td></tr>
            <?php else: foreach ($rows as $r): [$cls,$lbl]=$cond_map[$r['condition']]??['secondary',$r['condition']]; ?>
            <tr>
              <td>
                <?php if ($r['photo_file']): ?>
                  <img src="<?= UPLOAD_URL . e($r['photo_file']) ?>" class="rounded me-1" width="32" height="32" style="object-fit:cover">
                <?php endif; ?>
                <strong><?= e($r['name']) ?></strong>
                <?php if ($r['notes']): ?><br><small class="text-muted"><?= e(mb_substr($r['notes'],0,50)) ?></small><?php endif; ?>
              </td>
              <td><span class="badge bg-secondary"><?= e($r['category']) ?></span></td>
              <td><?= $r['quantity'] ?> <?= e($r['unit'] ?? '') ?></td>
              <td><span class="badge bg-<?= $cls ?>"><?= $lbl ?></span></td>
              <td class="small"><?= e($r['location'] ?? '—') ?></td>
              <td class="small"><?= $r['purchase_price'] ? idr((float)$r['purchase_price']) : '—' ?></td>
              <?php if (can('inventory.manage')): ?>
              <td>
                <button class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"
                        data-bs-toggle="modal" data-bs-target="#modalSave"
                        data-id="<?= $r['id'] ?>"
                        data-name="<?= e($r['name']) ?>"
                        data-category="<?= e($r['category']) ?>"
                        data-quantity="<?= $r['quantity'] ?>"
                        data-unit="<?= e($r['unit']) ?>"
                        data-condition="<?= $r['condition'] ?>"
                        data-location="<?= e($r['location']) ?>"
                        data-notes="<?= e($r['notes']) ?>">
                  <i class="bi bi-pencil"></i>
                </button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus aset ini?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages'] > 1): ?>
      <div class="card-footer"><?= render_pagination($pag, '?q='.urlencode($search).'&condition='.urlencode($f_cond)) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (can('inventory.manage')): ?>
<div class="modal fade" id="modalSave" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" id="invId">
      <div class="modal-header"><h6 class="modal-title" id="invModalTitle">Tambah Aset</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label">Nama Aset</label>
            <input type="text" name="name" id="invName" class="form-control form-control-sm" required maxlength="200"></div>
          <div class="col-md-3"><label class="form-label">Kategori</label>
            <input type="text" name="category" id="invCategory" class="form-control form-control-sm" maxlength="100" placeholder="umum"></div>
          <div class="col-md-2"><label class="form-label">Jumlah</label>
            <input type="number" name="quantity" id="invQty" class="form-control form-control-sm" value="1" min="0"></div>
          <div class="col-md-1"><label class="form-label">Satuan</label>
            <input type="text" name="unit" id="invUnit" class="form-control form-control-sm" maxlength="30"></div>
          <div class="col-md-3"><label class="form-label">Kondisi</label>
            <select name="condition" id="invCondition" class="form-select form-select-sm">
              <?php foreach ($cond_map as $v=>[$c,$l]): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label">Lokasi</label>
            <input type="text" name="location" id="invLocation" class="form-control form-control-sm" maxlength="200"></div>
          <div class="col-md-3"><label class="form-label">Tgl Beli</label>
            <input type="date" name="purchase_date" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label">Harga Beli</label>
            <input type="text" name="purchase_price" class="form-control form-control-sm" data-rupiah inputmode="numeric"></div>
          <div class="col-md-12"><label class="form-label">Catatan</label>
            <textarea name="notes" id="invNotes" class="form-control form-control-sm" rows="2"></textarea></div>
          <div class="col-md-12"><label class="form-label">Foto <small class="text-muted">(opsional)</small></label>
            <input type="file" name="photo_file" class="form-control form-control-sm" accept="image/*"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-warning btn-sm">Simpan</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('modalSave')?.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget, d = b.dataset;
  document.getElementById('invId').value       = d.id || 0;
  document.getElementById('invModalTitle').textContent = d.id && d.id!='0' ? 'Edit Aset' : 'Tambah Aset';
  document.getElementById('invName').value     = d.name || '';
  document.getElementById('invCategory').value = d.category || 'umum';
  document.getElementById('invQty').value      = d.quantity || 1;
  document.getElementById('invUnit').value     = d.unit || '';
  document.getElementById('invCondition').value= d.condition || 'baik';
  document.getElementById('invLocation').value = d.location || '';
  document.getElementById('invNotes').value    = d.notes || '';
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
