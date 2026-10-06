<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/email_notifications.php';
require_permission('billing.send_reminder');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $counts = run_email_reminders();
    log_activity('send_reminder', 'billing',
        "Reminder sent — due:{$counts['due']} overdue:{$counts['overdue']} skipped:{$counts['skipped']}");
    flash('success',
        "Reminder terkirim: {$counts['due']} jatuh tempo, {$counts['overdue']} terlambat." .
        ($counts['skipped'] ? " {$counts['skipped']} dilewati (email kosong/sudah terkirim hari ini)." : ''));
    redirect(APP_URL . '/pages/billing/send_reminders.php');
}

// Stats
$db    = db();
$today = date('Y-m-d');
$h3    = date('Y-m-d', strtotime('+3 days'));

$due_count = $db->prepare(
    'SELECT COUNT(*) FROM bills WHERE status="belum_bayar" AND due_date BETWEEN ? AND ?'
);
$due_count->bind_param('ss', $today, $h3);
$due_count->execute();
$cnt_due = $due_count->get_result()->fetch_row()[0];

$overdue_count = $db->query('SELECT COUNT(*) FROM bills WHERE status="terlambat"')->fetch_row()[0];

$no_email_count = $db->query(
    'SELECT COUNT(*) FROM bills b
     JOIN residents r ON r.id = b.resident_id
     WHERE b.status IN ("belum_bayar","terlambat") AND (r.email IS NULL OR r.email="")'
)->fetch_row()[0];

// Recent email logs
$logs = $db->query(
    'SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 30'
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Kirim Reminder Email';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-envelope me-1 text-success"></i> Kirim Reminder Email</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Info cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card text-center py-3">
          <div class="fw-bold fs-3 text-warning"><?= $cnt_due ?></div>
          <div class="text-muted small"><i class="bi bi-clock me-1"></i>Jatuh Tempo ≤ 3 Hari</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-3">
          <div class="fw-bold fs-3 text-danger"><?= $overdue_count ?></div>
          <div class="text-muted small"><i class="bi bi-exclamation-triangle me-1"></i>Terlambat</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-3">
          <div class="fw-bold fs-3 text-secondary"><?= $no_email_count ?></div>
          <div class="text-muted small"><i class="bi bi-envelope-slash me-1"></i>Tanpa Email</div>
        </div>
      </div>
    </div>

    <!-- Send form -->
    <div class="card mb-4" style="max-width:560px">
      <div class="card-header"><i class="bi bi-send me-1 text-success"></i> Kirim Reminder Sekarang</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Sistem akan mengirim email reminder ke warga dengan tagihan yang:<br>
          • Akan jatuh tempo dalam <strong>3 hari ke depan</strong> (status: Belum Bayar)<br>
          • Sudah <strong>melewati jatuh tempo</strong> (status: Terlambat)<br>
          Email yang sama tidak akan dikirim ulang ke warga yang sama pada hari yang sama.
        </p>
        <form method="POST" onsubmit="return confirm('Kirim reminder email ke semua warga yang memenuhi syarat?')">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-send me-1"></i> Kirim Reminder Email
          </button>
          <a href="index.php" class="btn btn-outline-secondary ms-2">Kembali</a>
        </form>
      </div>
    </div>

    <!-- Log -->
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-1 text-success"></i> Log Email Terbaru</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead><tr>
              <th>Waktu</th><th>Tipe</th><th>Kepada</th><th>Subjek</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php if (empty($logs)): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Belum ada log email.</td></tr>
            <?php else: foreach ($logs as $log): ?>
              <tr>
                <td class="small text-muted"><?= fmt_date($log['created_at'], 'd M Y H:i') ?></td>
                <td><span class="badge bg-secondary"><?= e($log['type']) ?></span></td>
                <td class="small"><?= e($log['to_name'] ? $log['to_name'].' &lt;'.$log['to_email'].'&gt;' : $log['to_email']) ?></td>
                <td class="small"><?= e($log['subject']) ?></td>
                <td>
                  <?php if ($log['status'] === 'sent'): ?>
                    <span class="badge bg-success">Terkirim</span>
                  <?php else: ?>
                    <span class="badge bg-danger">Gagal</span>
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
