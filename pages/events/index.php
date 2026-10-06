<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('events.view');

$db  = db();
$uid = auth_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'create') {
        require_permission('events.manage');
        $title    = clean($_POST['title'] ?? '');
        $type     = in_array($_POST['type']??'',['rapat','kerja_bakti','sosial','lainnya']) ? $_POST['type'] : 'lainnya';
        $date     = clean($_POST['event_date'] ?? '');
        $loc      = clean($_POST['location'] ?? '');
        $desc     = clean($_POST['description'] ?? '');
        $mandatory= isset($_POST['is_mandatory']) ? 1 : 0;
        if (!$title || !$date) { flash('error', 'Judul dan tanggal wajib diisi.'); }
        else {
            $s = $db->prepare('INSERT INTO events (title,description,type,event_date,location,is_mandatory,created_by) VALUES (?,?,?,?,?,?,?)');
            $s->bind_param('sssssii', $title, $desc, $type, $date, $loc, $mandatory, $uid);
            $s->execute();
            log_activity('create', 'events', "Kegiatan baru: {$title}");
            flash('success', 'Kegiatan berhasil dibuat.');
        }
        redirect(APP_URL.'/pages/events/index.php');
    }

    if ($action === 'attend') {
        require_permission('events.attendance');
        $eid    = (int)($_POST['event_id'] ?? 0);
        $rid    = (int)($_POST['resident_id'] ?? 0);
        $status = in_array($_POST['status']??'',['hadir','tidak_hadir','izin']) ? $_POST['status'] : 'hadir';
        $selfie = null;
        if (!empty($_FILES['selfie_file']['name'])) {
            try { $selfie = upload_proof($_FILES['selfie_file']); } catch (Exception $e) {}
        }
        $s = $db->prepare(
            'INSERT INTO event_attendances (event_id,resident_id,status,selfie_file,checked_by)
             VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),selfie_file=COALESCE(VALUES(selfie_file),selfie_file),checked_by=VALUES(checked_by)'
        );
        $s->bind_param('iissi', $eid, $rid, $status, $selfie, $uid);
        $s->execute();
        flash('success', 'Absensi dicatat.');
        redirect(APP_URL.'/pages/events/detail.php?id='.$eid);
    }
}

$page = max(1,(int)($_GET['page'] ?? 1)); $per = 15;
$cnt  = $db->query('SELECT COUNT(*) FROM events')->fetch_row()[0];
$pag  = paginate($cnt, $per, $page);
$stmt = $db->prepare(
    'SELECT e.*, u.name AS creator,
            (SELECT COUNT(*) FROM event_attendances ea WHERE ea.event_id=e.id AND ea.status="hadir") AS hadir_count
     FROM events e LEFT JOIN users u ON u.id=e.created_by
     ORDER BY e.event_date DESC LIMIT ? OFFSET ?'
);
$stmt->bind_param('ii', $per, $pag['offset']); $stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Kegiatan & Absensi';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-calendar-event me-1 text-primary"></i> Kegiatan & Absensi</h6>
    <?php if (can('events.manage')): ?>
    <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#modalCreate">
      <i class="bi bi-plus-lg me-1"></i>Buat Kegiatan
    </button>
    <?php endif; ?>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>Kegiatan</th><th>Tipe</th><th>Tanggal</th><th>Lokasi</th>
              <th>Wajib</th><th class="text-center">Hadir</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Belum ada kegiatan.</td></tr>
            <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= e($r['title']) ?></strong>
                <?php if ($r['description']): ?>
                  <br><small class="text-muted"><?= e(mb_substr($r['description'],0,60)) ?>…</small>
                <?php endif; ?>
              </td>
              <td><span class="badge bg-primary"><?= e(str_replace('_',' ',ucfirst($r['type']))) ?></span></td>
              <td><?= fmt_date($r['event_date'],'d M Y H:i') ?></td>
              <td class="small"><?= e($r['location'] ?? '—') ?></td>
              <td><?= $r['is_mandatory'] ? '<span class="badge bg-warning text-dark">Wajib</span>' : '<span class="text-muted small">—</span>' ?></td>
              <td class="text-center"><span class="badge bg-success"><?= $r['hadir_count'] ?></span></td>
              <td>
                <a href="detail.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
                  <i class="bi bi-people"></i> Absensi
                </a>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages'] > 1): ?>
      <div class="card-footer"><?= render_pagination($pag, '?') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (can('events.manage')): ?>
<div class="modal fade" id="modalCreate" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <div class="modal-header"><h6 class="modal-title">Buat Kegiatan Baru</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Judul</label>
          <input type="text" name="title" class="form-control form-control-sm" required maxlength="200"></div>
        <div class="row g-2 mb-2">
          <div class="col"><label class="form-label">Tipe</label>
            <select name="type" class="form-select form-select-sm">
              <option value="rapat">Rapat</option>
              <option value="kerja_bakti">Kerja Bakti</option>
              <option value="sosial">Sosial</option>
              <option value="lainnya">Lainnya</option>
            </select></div>
          <div class="col"><label class="form-label">Tanggal & Waktu</label>
            <input type="datetime-local" name="event_date" class="form-control form-control-sm" required></div>
        </div>
        <div class="mb-2"><label class="form-label">Lokasi</label>
          <input type="text" name="location" class="form-control form-control-sm" maxlength="200"></div>
        <div class="mb-2"><label class="form-label">Deskripsi</label>
          <textarea name="description" class="form-control form-control-sm" rows="2"></textarea></div>
        <div class="form-check"><input type="checkbox" name="is_mandatory" class="form-check-input" id="chkMand">
          <label class="form-check-label" for="chkMand">Kehadiran wajib</label></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
