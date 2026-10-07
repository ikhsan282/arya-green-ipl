<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('payments.verify');

// Hanya Super Admin dan Admin yang boleh batalkan verifikasi
$role = auth_user()['role'] ?? '';
if (!in_array($role, ['super_admin', 'ketua'])) {
    flash('error', 'Hanya Super Admin atau Ketua yang dapat membatalkan verifikasi.');
    redirect(APP_URL . '/pages/payments/index.php');
}

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Pembayaran tidak ditemukan.'); redirect(APP_URL.'/pages/payments/index.php'); }

$stmt = $db->prepare(
    'SELECT p.*, b.id AS bill_id
     FROM payments p
     JOIN bills b ON b.id = p.bill_id
     WHERE p.id = ? AND p.status = "verified"'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
if (!$pay) {
    flash('error', 'Pembayaran tidak ditemukan atau belum diverifikasi.');
    redirect(APP_URL . '/pages/payments/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason = clean($_POST['reason'] ?? '');
    if (!$reason) {
        flash('error', 'Alasan pembatalan wajib diisi.');
        redirect(APP_URL . '/pages/payments/unverify.php?id=' . $id);
    }

    try {
        $db->begin_transaction();

        // 1. Hapus entri cash_book yang terkait
        $del = $db->prepare('DELETE FROM cash_book WHERE ref_payment_id = ?');
        $del->bind_param('i', $id);
        $del->execute();

        // 2. Reset status payment ke pending
        $upd = $db->prepare(
            'UPDATE payments SET status="pending", verified_by=NULL, verified_at=NULL,
             notes=CONCAT(IFNULL(notes,""), " [Dibatalkan: ", ?, "]") WHERE id=?'
        );
        $upd->bind_param('si', $reason, $id);
        $upd->execute();

        // 3. Reset status bill ke belum_bayar
        $upd2 = $db->prepare(
            'UPDATE bills SET status="belum_bayar", paid_date=NULL WHERE id=?'
        );
        $upd2->bind_param('i', $pay['bill_id']);
        $upd2->execute();

        $db->commit();

        log_activity('unverify', 'payments', "Payment #{$id} verification cancelled: {$reason}");
        flash('warning', 'Verifikasi pembayaran berhasil dibatalkan. Entri kas terkait dihapus.');
    } catch (Exception $e) {
        $db->rollback();
        error_log("Payment unverify error: " . $e->getMessage());
        flash('error', 'Gagal membatalkan verifikasi. Silakan coba lagi.');
    }
    redirect(APP_URL . '/pages/payments/detail.php?id=' . $id);
}

$page_title = 'Batalkan Verifikasi';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold text-danger"><i class="bi bi-x-circle me-1"></i> Batalkan Verifikasi</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="row g-3" style="max-width:600px">
      <div class="col-12">
        <div class="card border-danger">
          <div class="card-header bg-danger text-white">
            <i class="bi bi-exclamation-triangle me-1"></i> Konfirmasi Pembatalan Verifikasi
          </div>
          <div class="card-body">
            <div class="alert alert-warning">
              <strong>Perhatian!</strong> Tindakan ini akan:
              <ul class="mb-0 mt-1">
                <li>Mengembalikan status pembayaran ke <strong>Pending</strong></li>
                <li>Menghapus entri buku kas yang terkait pembayaran ini</li>
                <li>Mengembalikan status tagihan ke <strong>Belum Bayar</strong></li>
              </ul>
            </div>
            <form method="POST">
              <?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label fw-semibold">Alasan Pembatalan <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required
                          placeholder="Contoh: Bukti transfer tidak valid, pembayaran dobel, dll."></textarea>
              </div>
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Yakin batalkan verifikasi? Entri kas akan dihapus.')">
                  <i class="bi bi-x-circle me-1"></i> Batalkan Verifikasi
                </button>
                <a href="detail.php?id=<?= $id ?>" class="btn btn-outline-secondary">Kembali</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
