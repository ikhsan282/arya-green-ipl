<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('inventory.view');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error', 'ID aset tidak valid.'); redirect(APP_URL.'/pages/inventory/index.php'); }

$stmt = $db->prepare(
    'SELECT i.*, u.name AS creator_name
     FROM inventory i
     LEFT JOIN users u ON u.id=i.created_by
     WHERE i.id=?'
);
$stmt->bind_param('i', $id); $stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
if (!$item) { flash('error', 'Aset tidak ditemukan.'); redirect(APP_URL.'/pages/inventory/index.php'); }

$cond_map = [
    'baik'         => ['success', 'Baik'],
    'rusak_ringan' => ['warning', 'Rusak Ringan'],
    'rusak_berat'  => ['danger',  'Rusak Berat'],
    'tidak_ada'    => ['secondary','Tidak Ada'],
];
[$cond_cls, $cond_lbl] = $cond_map[$item['condition']] ?? ['secondary', $item['condition']];

$page_title = 'Detail Aset — ' . $item['name'];
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h6 class="mb-0 fw-semibold ms-1"><i class="bi bi-box-seam me-1 text-warning"></i><?= e($item['name']) ?></h6>
    <?php if (can('inventory.manage')): ?>
    <button class="btn btn-sm btn-warning ms-auto"
            data-bs-toggle="modal" data-bs-target="#modalEdit">
      <i class="bi bi-pencil me-1"></i>Edit Aset
    </button>
    <?php endif; ?>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <div class="row g-3">
      <!-- Foto -->
      <?php if ($item['photo_file']): ?>
      <div class="col-md-3">
        <div class="card">
          <img src="<?= UPLOAD_URL . e($item['photo_file']) ?>"
               class="card-img-top" style="object-fit:cover;max-height:240px"
               alt="<?= e($item['name']) ?>">
        </div>
      </div>
      <?php endif; ?>

      <!-- Info utama -->
      <div class="col-md-<?= $item['photo_file'] ? '5' : '7' ?>">
        <div class="card h-100">
          <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Informasi Aset</div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><td class="text-muted" style="width:140px">Nama</td>
                  <td><strong><?= e($item['name']) ?></strong></td></tr>
              <tr><td class="text-muted">Kategori</td>
                  <td><span class="badge bg-secondary"><?= e($item['category']) ?></span></td></tr>
              <tr><td class="text-muted">Jumlah</td>
                  <td><?= (int)$item['quantity'] ?> <?= e($item['unit'] ?? '') ?></td></tr>
              <tr><td class="text-muted">Kondisi</td>
                  <td><span class="badge bg-<?= $cond_cls ?>"><?= $cond_lbl ?></span></td></tr>
              <tr><td class="text-muted">Lokasi</td>
                  <td><?= e($item['location'] ?: '—') ?></td></tr>
              <tr><td class="text-muted">Tgl Beli</td>
                  <td><?= $item['purchase_date'] ? fmt_date($item['purchase_date']) : '—' ?></td></tr>
              <tr><td class="text-muted">Harga Beli</td>
                  <td><?= $item['purchase_price'] ? idr((float)$item['purchase_price']) : '—' ?></td></tr>
              <tr><td class="text-muted">Dicatat oleh</td>
                  <td><?= e($item['creator_name'] ?? '—') ?></td></tr>
              <tr><td class="text-muted">Tgl Input</td>
                  <td><?= fmt_date($item['created_at'], 'd M Y H:i') ?></td></tr>
              <tr><td class="text-muted">Terakhir Update</td>
                  <td><?= fmt_date($item['updated_at'], 'd M Y H:i') ?></td></tr>
            </table>
          </div>
        </div>
      </div>

      <!-- Catatan -->
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header fw-semibold"><i class="bi bi-sticky me-1"></i>Catatan</div>
          <div class="card-body">
            <?php if ($item['notes']): ?>
              <p class="mb-0" style="white-space:pre-wrap"><?= e($item['notes']) ?></p>
            <?php else: ?>
              <p class="text-muted mb-0">Tidak ada catatan.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (can('inventory.manage')): ?>
<!-- Modal Edit (inline, pakai data dari $item) -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="index.php" enctype="multipart/form-data" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= $item['id'] ?>">
      <div class="modal-header">
        <h6 class="modal-title">Edit Aset</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Nama Aset</label>
            <input type="text" name="name" class="form-control form-control-sm"
                   value="<?= e($item['name']) ?>" required maxlength="200">
          </div>
          <div class="col-md-3">
            <label class="form-label">Kategori</label>
            <input type="text" name="category" class="form-control form-control-sm"
                   value="<?= e($item['category']) ?>" maxlength="100">
          </div>
          <div class="col-md-2">
            <label class="form-label">Jumlah</label>
            <input type="number" name="quantity" class="form-control form-control-sm"
                   value="<?= (int)$item['quantity'] ?>" min="0">
          </div>
          <div class="col-md-1">
            <label class="form-label">Satuan</label>
            <input type="text" name="unit" class="form-control form-control-sm"
                   value="<?= e($item['unit'] ?? '') ?>" maxlength="30">
          </div>
          <div class="col-md-3">
            <label class="form-label">Kondisi</label>
            <select name="condition" class="form-select form-select-sm">
              <?php foreach ($cond_map as $v => [$c, $l]): ?>
                <option value="<?= $v ?>" <?= $item['condition'] === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Lokasi</label>
            <input type="text" name="location" class="form-control form-control-sm"
                   value="<?= e($item['location'] ?? '') ?>" maxlength="200">
          </div>
          <div class="col-md-3">
            <label class="form-label">Tgl Beli</label>
            <input type="date" name="purchase_date" class="form-control form-control-sm"
                   value="<?= e($item['purchase_date'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Harga Beli</label>
            <input type="text" name="purchase_price" class="form-control form-control-sm"
                   value="<?= $item['purchase_price'] ? number_format((float)$item['purchase_price'], 0, ',', '.') : '' ?>"
                   data-rupiah inputmode="numeric">
          </div>
          <div class="col-12">
            <label class="form-label">Catatan</label>
            <textarea name="notes" class="form-control form-control-sm" rows="3"><?= e($item['notes'] ?? '') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Ganti Foto <small class="text-muted">(biarkan kosong jika tidak diganti)</small></label>
            <input type="file" name="photo_file" class="form-control form-control-sm" accept="image/*">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-warning btn-sm">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
