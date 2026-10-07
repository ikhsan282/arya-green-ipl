<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('payment_methods.view');

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle') {
    csrf_verify();
    require_permission('payment_methods.manage');
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare('UPDATE payment_methods SET is_active = NOT is_active WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    log_activity('update', 'payment_methods', "Payment method #{$id} status toggled");
    flash('success', 'Status metode pembayaran diperbarui.');
    redirect(APP_URL . '/pages/payment_methods/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('payment_methods.manage');
    $id = (int)($_POST['id'] ?? 0);
    $chk = $db->prepare('SELECT COUNT(*) FROM payments WHERE payment_method_id=?');
    $chk->bind_param('i', $id);
    $chk->execute();
    if ((int)$chk->get_result()->fetch_row()[0] > 0) {
        flash('error', 'Metode sudah digunakan pada pembayaran. Nonaktifkan saja, jangan hapus.');
    } else {
        $stmt = $db->prepare('DELETE FROM payment_methods WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity('delete', 'payment_methods', "Payment method #{$id} deleted");
        flash('success', 'Metode pembayaran dihapus.');
    }
    redirect(APP_URL . '/pages/payment_methods/index.php');
}

$methods = $db->query('SELECT * FROM payment_methods ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);
$page_title = 'Metode Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-credit-card me-1 text-success"></i> Metode Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-credit-card me-1 text-success"></i> Master Metode Pembayaran</span>
        <?php if (can('payment_methods.manage')): ?>
          <a href="form.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i> Tambah Metode</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>#</th><th>Nama</th><th>Kode</th><th>Tujuan Pembayaran</th><th>QR</th><th>Verifikasi</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php if (!$methods): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Belum ada metode pembayaran.</td></tr>
            <?php else: foreach ($methods as $i => $m): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e($m['name']) ?></strong><br><small class="text-muted"><?= e($m['instructions'] ?: 'Tanpa instruksi') ?></small></td>
                <td><code><?= e($m['code']) ?></code></td>
                <td><?= e($m['account_no'] ?: '-') ?><?= $m['account_name'] ? '<br><small class="text-muted">a.n. '.e($m['account_name']).'</small>' : '' ?></td>
                <td>
                  <?php if ($m['qr_image']): ?>
                    <a href="<?= UPLOAD_URL . e($m['qr_image']) ?>" target="_blank">
                      <img src="<?= UPLOAD_URL . e($m['qr_image']) ?>" alt="QR <?= e($m['name']) ?>" style="max-height:48px" class="img-thumbnail">
                    </a>
                  <?php else: ?>-<?php endif; ?>
                </td>
                <td><?= (int)$m['auto_verify'] === 1 ? '<span class="badge bg-info">Auto</span>' : '<span class="badge bg-warning text-dark">Manual</span>' ?></td>
                <td><span class="badge bg-<?= $m['is_active'] ? 'success' : 'secondary' ?>"><?= $m['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td class="text-nowrap">
                  <?php if (can('payment_methods.manage')): ?>
                    <a href="form.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                      <button class="btn btn-sm btn-outline-<?= $m['is_active'] ? 'warning' : 'success' ?> py-0 px-2" title="Aktif/nonaktif"><i class="bi bi-power"></i></button>
                    </form>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0 px-2" data-confirm="Hapus metode ini?"><i class="bi bi-trash"></i></button>
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
