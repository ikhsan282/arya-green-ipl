<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';

$db = db();
$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
require_permission('payment_methods.view');

if ($editing) {
    require_permission('payment_methods.manage');
    $stmt = $db->prepare('SELECT * FROM payment_methods WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $method = $stmt->get_result()->fetch_assoc();
    if (!$method) { flash('error', 'Metode pembayaran tidak ditemukan.'); redirect(APP_URL . '/pages/payment_methods/index.php'); }
} else {
    require_permission('payment_methods.manage');
    $method = [];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'code'         => strtolower(trim((string)($_POST['code'] ?? ''))),
        'name'         => clean($_POST['name'] ?? ''),
        'account_no'   => clean($_POST['account_no'] ?? ''),
        'account_name' => clean($_POST['account_name'] ?? ''),
        'instructions' => clean($_POST['instructions'] ?? ''),
        'sort_order'   => (int)($_POST['sort_order'] ?? 0),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
        'auto_verify'  => isset($_POST['auto_verify']) ? 1 : 0,
    ];
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $data['code'])) $errors[] = 'Kode harus 2–30 karakter: huruf kecil, angka, tanda hubung, atau garis bawah.';
    if ($data['name'] === '') $errors[] = 'Nama metode wajib diisi.';
    if ($data['sort_order'] < 0) $errors[] = 'Urutan tidak boleh negatif.';
    $chk = $db->prepare('SELECT id FROM payment_methods WHERE code=? AND id<>?');
    $chk->bind_param('si', $data['code'], $id);
    $chk->execute();
    if ($chk->get_result()->fetch_row()) $errors[] = 'Kode metode sudah digunakan.';

    // Upload gambar QR / logo (opsional)
    $qr_image = $method['qr_image'] ?? null;
    if (!empty($_FILES['qr_image']['name'])) {
        try {
            $qr_image = upload_file($_FILES['qr_image'], UPLOAD_DIR);
        } catch (RuntimeException $e) {
            $errors[] = 'QR: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        if ($editing) {
            $stmt = $db->prepare('UPDATE payment_methods SET code=?,name=?,account_no=?,account_name=?,instructions=?,qr_image=?,sort_order=?,is_active=?,auto_verify=? WHERE id=?');
            $stmt->bind_param('ssssssiiii', $data['code'], $data['name'], $data['account_no'], $data['account_name'], $data['instructions'], $qr_image, $data['sort_order'], $data['is_active'], $data['auto_verify'], $id);
            $stmt->execute();
            log_activity('update', 'payment_methods', "Payment method #{$id} updated");
        } else {
            $stmt = $db->prepare('INSERT INTO payment_methods (code,name,account_no,account_name,instructions,qr_image,sort_order,is_active,auto_verify) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('ssssssiii', $data['code'], $data['name'], $data['account_no'], $data['account_name'], $data['instructions'], $qr_image, $data['sort_order'], $data['is_active'], $data['auto_verify']);
            $stmt->execute();
            log_activity('create', 'payment_methods', "Payment method {$data['code']} created");
        }
        flash('success', 'Metode pembayaran berhasil disimpan.');
        redirect(APP_URL . '/pages/payment_methods/index.php');
    }
    $method = $data;
}

$page_title = $editing ? 'Edit Metode Pembayaran' : 'Tambah Metode Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2"><button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button><h6 class="mb-0 fw-semibold"><i class="bi bi-credit-card me-1 text-success"></i> <?= e($page_title) ?></h6></div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div><?php endif; ?>
    <div class="card" style="max-width:720px"><div class="card-header"><?= e($page_title) ?></div><div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Kode unik <span class="text-danger">*</span></label><input name="code" class="form-control" maxlength="30" pattern="[a-z0-9_-]{2,30}" required value="<?= e($method['code'] ?? '') ?>" placeholder="contoh: transfer-bca"></div>
          <div class="col-md-8"><label class="form-label">Nama metode <span class="text-danger">*</span></label><input name="name" class="form-control" maxlength="100" required value="<?= e($method['name'] ?? '') ?>" placeholder="contoh: Transfer BCA"></div>
          <div class="col-md-6"><label class="form-label">Nomor rekening / tujuan</label><input name="account_no" class="form-control" maxlength="50" value="<?= e($method['account_no'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Atas nama</label><input name="account_name" class="form-control" maxlength="100" value="<?= e($method['account_name'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Gambar QR / Logo <small class="text-muted">(JPG/PNG/WebP, maks 2MB)</small></label><input type="file" name="qr_image" class="form-control" accept="image/jpeg,image/png,image/webp"></div>
          <div class="col-md-2"><label class="form-label">Urutan</label><input type="number" name="sort_order" min="0" class="form-control" value="<?= e((string)($method['sort_order'] ?? 0)) ?>"></div>
          <div class="col-md-4 d-flex align-items-end gap-3 pb-2">
            <div class="form-check mb-2"><input type="checkbox" id="active" name="is_active" class="form-check-input" <?= !isset($method['is_active']) || $method['is_active'] ? 'checked' : '' ?>><label for="active" class="form-check-label">Aktif</label></div>
            <div class="form-check mb-2"><input type="checkbox" id="auto_verify" name="auto_verify" class="form-check-input" <?= !empty($method['auto_verify']) ? 'checked' : '' ?>><label for="auto_verify" class="form-check-label">Auto-verifikasi</label></div>
          </div>
          <div class="col-md-8"><label class="form-label">Instruksi pembayaran <small class="text-muted">(tampil di form pembayaran)</small></label><textarea name="instructions" class="form-control" rows="3"><?= e($method['instructions'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Simpan</button><a href="index.php" class="btn btn-outline-secondary">Batal</a></div>
      </form>
    </div></div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
