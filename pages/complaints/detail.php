<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('complaints.view');

$db  = db();
$uid = auth_id();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(APP_URL.'/pages/complaints/index.php');

$stmt = $db->prepare(
    'SELECT c.*, r.name AS resident_name, u.name AS reporter_name, ua.name AS assignee_name
     FROM complaints c
     LEFT JOIN residents r ON r.id=c.resident_id
     LEFT JOIN users u ON u.id=c.user_id
     LEFT JOIN users ua ON ua.id=c.assigned_to
     WHERE c.id=?'
);
$stmt->bind_param('i', $id); $stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
if (!$complaint) { flash('error', 'Aduan tidak ditemukan.'); redirect(APP_URL.'/pages/complaints/index.php'); }

// Cek akses: hanya pemilik atau yang punya manage permission
if (!can('complaints.manage') && $complaint['user_id'] != $uid) {
    redirect(APP_URL.'/pages/403.php');
}

$replies = $db->prepare(
    'SELECT cr.*, u.name AS author FROM complaint_replies cr LEFT JOIN users u ON u.id=cr.user_id WHERE cr.complaint_id=? ORDER BY cr.created_at'
);
$replies->bind_param('i', $id); $replies->execute();
$replies = $replies->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Detail Aduan';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h6 class="mb-0 fw-semibold ms-1"><?= e($complaint['title']) ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <div class="row g-3">
      <div class="col-md-4">
        <div class="card">
          <div class="card-header"><i class="bi bi-info-circle me-1"></i> Info Aduan</div>
          <div class="card-body small">
            <?php
            $sm = ['baru'=>'info','diproses'=>'warning','selesai'=>'success','ditutup'=>'secondary'];
            $pm = ['rendah'=>'success','sedang'=>'warning','tinggi'=>'danger'];
            ?>
            <table class="table table-sm table-borderless mb-0">
              <tr><td class="text-muted">Pelapor</td><td><?= e($complaint['resident_name'] ?? $complaint['reporter_name'] ?? '—') ?></td></tr>
              <tr><td class="text-muted">Kategori</td><td><span class="badge bg-secondary"><?= e($complaint['category']) ?></span></td></tr>
              <tr><td class="text-muted">Prioritas</td><td><span class="badge bg-<?= $pm[$complaint['priority']]??'secondary' ?>"><?= e($complaint['priority']) ?></span></td></tr>
              <tr><td class="text-muted">Status</td><td><span class="badge bg-<?= $sm[$complaint['status']]??'secondary' ?>"><?= e($complaint['status']) ?></span></td></tr>
              <tr><td class="text-muted">Petugas</td><td><?= e($complaint['assignee_name'] ?? '—') ?></td></tr>
              <tr><td class="text-muted">Dibuat</td><td><?= fmt_date($complaint['created_at']) ?></td></tr>
              <?php if ($complaint['resolved_at']): ?>
              <tr><td class="text-muted">Selesai</td><td><?= fmt_date($complaint['resolved_at']) ?></td></tr>
              <?php endif; ?>
            </table>
          </div>
        </div>
        <?php if ($complaint['photo_file']): ?>
        <div class="card mt-3">
          <div class="card-header"><i class="bi bi-image me-1"></i> Foto Aduan</div>
          <div class="card-body p-0">
            <img src="<?= UPLOAD_URL.e($complaint['photo_file']) ?>" class="img-fluid rounded-bottom" alt="Foto aduan">
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-md-8">
        <!-- Deskripsi -->
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-file-text me-1"></i> Deskripsi</div>
          <div class="card-body"><?= nl2br(e($complaint['description'])) ?></div>
        </div>

        <!-- Thread balasan -->
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-chat-dots me-1"></i> Balasan (<?= count($replies) ?>)</div>
          <div class="card-body p-0">
            <?php if (empty($replies)): ?>
              <div class="p-3 text-muted small">Belum ada balasan.</div>
            <?php else: foreach ($replies as $rep): ?>
            <div class="p-3 border-bottom">
              <div class="d-flex justify-content-between mb-1">
                <strong class="small"><?= e($rep['author'] ?? 'Anonim') ?></strong>
                <small class="text-muted"><?= fmt_date($rep['created_at'],'d M Y H:i') ?></small>
              </div>
              <div class="small"><?= nl2br(e($rep['message'])) ?></div>
            </div>
            <?php endforeach; endif; ?>
          </div>
          <?php if (can('complaints.manage') || $complaint['user_id'] == $uid): ?>
          <div class="card-footer">
            <form method="POST" action="index.php">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="reply">
              <input type="hidden" name="complaint_id" value="<?= $id ?>">
              <div class="d-flex gap-2">
                <textarea name="message" class="form-control form-control-sm" rows="2" required placeholder="Tulis balasan…"></textarea>
                <button class="btn btn-sm btn-info align-self-end"><i class="bi bi-send"></i></button>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
