<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('events.view');

$db  = db();
$uid = auth_id();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(APP_URL.'/pages/events/index.php');

$stmt = $db->prepare('SELECT e.*, u.name AS creator FROM events e LEFT JOIN users u ON u.id=e.created_by WHERE e.id=?');
$stmt->bind_param('i', $id); $stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
if (!$event) { flash('error', 'Kegiatan tidak ditemukan.'); redirect(APP_URL.'/pages/events/index.php'); }

// Absensi
$attended = $db->prepare(
    'SELECT ea.*, r.name AS resident_name, u2.unit_number, u2.block
     FROM event_attendances ea
     JOIN residents r ON r.id=ea.resident_id
     JOIN units u2 ON u2.id=r.unit_id
     WHERE ea.event_id=? ORDER BY r.name'
);
$attended->bind_param('i', $id); $attended->execute();
$attendances = $attended->get_result()->fetch_all(MYSQLI_ASSOC);
$attended_ids = array_column($attendances, 'resident_id');

// Semua warga aktif yang belum absen
$all_res = $db->query('SELECT r.id, r.name, u.unit_number, u.block FROM residents r JOIN units u ON u.id=r.unit_id WHERE r.is_active=1 ORDER BY r.name')->fetch_all(MYSQLI_ASSOC);

$page_title = 'Detail Kegiatan';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h6 class="mb-0 fw-semibold ms-1"><i class="bi bi-calendar-event me-1 text-primary"></i><?= e($event['title']) ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <div class="row g-3">
      <!-- Info Kegiatan -->
      <div class="col-md-4">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-info-circle me-1"></i> Info Kegiatan</div>
          <div class="card-body small">
            <table class="table table-sm table-borderless mb-0">
              <tr><td class="text-muted">Tipe</td><td><span class="badge bg-primary"><?= e(str_replace('_',' ',ucfirst($event['type']))) ?></span></td></tr>
              <tr><td class="text-muted">Tanggal</td><td><?= fmt_date($event['event_date'],'d M Y H:i') ?></td></tr>
              <tr><td class="text-muted">Lokasi</td><td><?= e($event['location'] ?: '—') ?></td></tr>
              <tr><td class="text-muted">Wajib</td><td><?= $event['is_mandatory'] ? '<span class="badge bg-warning text-dark">Ya</span>' : 'Tidak' ?></td></tr>
              <tr><td class="text-muted">Dibuat</td><td><?= e($event['creator'] ?? '—') ?></td></tr>
              <tr><td class="text-muted">Hadir</td><td><span class="badge bg-success"><?= count(array_filter($attendances, fn($a) => $a['status']==='hadir')) ?></span> / <?= count($all_res) ?></td></tr>
            </table>
            <?php if ($event['description']): ?>
            <hr><p class="mb-0 text-muted"><?= nl2br(e($event['description'])) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Form Catat Absensi -->
        <?php if (can('events.attendance')): ?>
        <div class="card">
          <div class="card-header"><i class="bi bi-person-check me-1 text-success"></i> Catat Absensi</div>
          <div class="card-body">
            <form method="POST" action="index.php" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="attend">
              <input type="hidden" name="event_id" value="<?= $id ?>">
              <div class="mb-2">
                <label class="form-label">Warga</label>
                <select name="resident_id" class="form-select form-select-sm ts-select" required>
                  <option value="">— Pilih —</option>
                  <?php foreach ($all_res as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['block'].'-'.$r['unit_number']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select form-select-sm">
                  <option value="hadir">Hadir</option>
                  <option value="tidak_hadir">Tidak Hadir</option>
                  <option value="izin">Izin</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Selfie <small class="text-muted">(opsional)</small></label>
                <input type="file" name="selfie_file" class="form-control form-control-sm" accept="image/*">
              </div>
              <button class="btn btn-success btn-sm w-100"><i class="bi bi-check2 me-1"></i>Catat</button>
            </form>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Daftar Absensi -->
      <div class="col-md-8">
        <div class="card">
          <div class="card-header"><i class="bi bi-list-check me-1"></i> Daftar Absensi</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Warga</th><th>Unit</th><th>Status</th><th>Waktu</th><th>Foto</th></tr></thead>
                <tbody>
                <?php if (empty($attendances)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">Belum ada absensi.</td></tr>
                <?php else: foreach ($attendances as $a): ?>
                <tr>
                  <td><?= e($a['resident_name']) ?></td>
                  <td><?= e($a['block'].'-'.$a['unit_number']) ?></td>
                  <td><?php
                    $sm = ['hadir'=>'success','tidak_hadir'=>'danger','izin'=>'warning'];
                    echo '<span class="badge bg-'.($sm[$a['status']]??'secondary').'">'.e($a['status']).'</span>';
                  ?></td>
                  <td class="small text-muted"><?= fmt_date($a['checked_at'],'d M H:i') ?></td>
                  <td><?php if ($a['selfie_file']): ?>
                    <a href="<?= UPLOAD_URL.e($a['selfie_file']) ?>" target="_blank">
                      <img src="<?= UPLOAD_URL.e($a['selfie_file']) ?>" width="36" height="36" class="rounded" style="object-fit:cover">
                    </a>
                  <?php else: echo '—'; endif; ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
