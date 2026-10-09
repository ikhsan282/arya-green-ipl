<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('complaints.view');

$db  = db();
$uid = auth_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'create') {
        $title = clean($_POST['title'] ?? '');
        $desc  = clean($_POST['description'] ?? '');
        $cat   = clean($_POST['category'] ?? 'umum');
        $prio  = in_array($_POST['priority'] ?? '', ['rendah','sedang','tinggi']) ? $_POST['priority'] : 'sedang';
        $photo = null;
        if (!empty($_FILES['photo_file']['name'])) {
            try { $photo = upload_proof($_FILES['photo_file']); } catch (Exception $e) { flash('error', $e->getMessage()); redirect(APP_URL.'/pages/complaints/index.php'); }
        }
        if (!$title || !$desc) { flash('error', 'Judul dan deskripsi wajib diisi.'); }
        else {
            // resident_id from current user
            $rid = $db->prepare('SELECT id FROM residents WHERE user_id=? LIMIT 1');
            $rid->bind_param('i', $uid); $rid->execute();
            $resident_id = $rid->get_result()->fetch_row()[0] ?? null;
            $s = $db->prepare('INSERT INTO complaints (resident_id,user_id,title,description,category,priority,photo_file) VALUES (?,?,?,?,?,?,?)');
            $s->bind_param('iisssss', $resident_id, $uid, $title, $desc, $cat, $prio, $photo);
            $s->execute();
            log_activity('create', 'complaints', "Aduan baru: {$title}");
            flash('success', 'Aduan berhasil dikirim.');
        }
        redirect(APP_URL.'/pages/complaints/index.php');
    }

    if ($action === 'update_status') {
        require_permission('complaints.manage');
        $id     = (int)($_POST['complaint_id'] ?? 0);
        $status = clean($_POST['status'] ?? '');
        $assign = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $valid  = ['baru','diproses','selesai','ditutup'];
        if (!in_array($status, $valid)) { flash('error', 'Status tidak valid.'); redirect(APP_URL.'/pages/complaints/index.php'); }
        $resolved = $status === 'selesai' ? date('Y-m-d H:i:s') : null;
        $s = $db->prepare('UPDATE complaints SET status=?,assigned_to=?,resolved_at=? WHERE id=?');
        $s->bind_param('sisi', $status, $assign, $resolved, $id);
        $s->execute();
        flash('success', 'Status aduan diperbarui.');
        redirect(APP_URL.'/pages/complaints/index.php');
    }

    if ($action === 'reply') {
        $id  = (int)($_POST['complaint_id'] ?? 0);
        $msg = clean($_POST['message'] ?? '');
        // Allow: pengurus (complaints.manage) OR pemilik aduan itu sendiri
        $owner = $db->prepare('SELECT user_id FROM complaints WHERE id=? LIMIT 1');
        $owner->bind_param('i', $id); $owner->execute();
        $owner_uid = $owner->get_result()->fetch_row()[0] ?? null;
        if (!can('complaints.manage') && $owner_uid != $uid) {
            flash('error', 'Tidak diizinkan.'); redirect(APP_URL.'/pages/complaints/index.php');
        }
        if ($msg) {
            $s = $db->prepare('INSERT INTO complaint_replies (complaint_id,user_id,message) VALUES (?,?,?)');
            $s->bind_param('iis', $id, $uid, $msg);
            $s->execute();
            flash('success', 'Balasan dikirim.');
        }
        redirect(APP_URL.'/pages/complaints/detail.php?id='.$id);
    }
}

$f_status = clean($_GET['status'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1)); $per = 20;
$where = ['1=1']; $params = []; $types = '';
if ($f_status) { $where[] = 'c.status=?'; $params[] = $f_status; $types .= 's'; }
if (!can('complaints.manage')) { $where[] = 'c.user_id=?'; $params[] = $uid; $types .= 'i'; }
$wsql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM complaints c WHERE {$wsql}");
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT c.*, r.name AS resident_name, u.name AS user_name, ua.name AS assignee_name
     FROM complaints c
     LEFT JOIN residents r ON r.id=c.resident_id
     LEFT JOIN users u ON u.id=c.user_id
     LEFT JOIN users ua ON ua.id=c.assigned_to
     WHERE {$wsql} ORDER BY c.created_at DESC LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types.'ii', ...$fp);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$officers = can('complaints.manage')
    ? $db->query('SELECT id,name FROM users WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : [];

$page_title = 'Aduan Warga';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-chat-left-text me-1 text-info"></i> Aduan Warga</h6>
    <button class="btn btn-sm btn-info ms-auto" data-bs-toggle="modal" data-bs-target="#modalCreate">
      <i class="bi bi-plus-lg me-1"></i>Buat Aduan
    </button>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Filter -->
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <?php foreach (['' => 'Semua', 'baru' => 'Baru', 'diproses' => 'Diproses', 'selesai' => 'Selesai', 'ditutup' => 'Ditutup'] as $v => $l): ?>
        <a href="?status=<?= $v ?>" class="btn btn-sm <?= $f_status===$v ? 'btn-info' : 'btn-outline-secondary' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>#</th><th>Judul</th><th>Warga</th><th>Kategori</th>
              <th>Prioritas</th><th>Status</th><th>Petugas</th><th>Tgl</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">Belum ada aduan.</td></tr>
            <?php else: foreach ($rows as $i => $r): ?>
            <tr>
              <td><?= $pag['offset']+$i+1 ?></td>
              <td><a href="detail.php?id=<?= $r['id'] ?>"><?= e($r['title']) ?></a></td>
              <td class="small"><?= e($r['resident_name'] ?? $r['user_name'] ?? '—') ?></td>
              <td><span class="badge bg-secondary"><?= e($r['category']) ?></span></td>
              <td><?php
                $pm = ['rendah'=>'success','sedang'=>'warning','tinggi'=>'danger'];
                echo '<span class="badge bg-'.($pm[$r['priority']]??'secondary').'">' . e($r['priority']) . '</span>';
              ?></td>
              <td><?php
                $sm = ['baru'=>'info','diproses'=>'warning','selesai'=>'success','ditutup'=>'secondary'];
                echo '<span class="badge bg-'.($sm[$r['status']]??'secondary').'">' . e($r['status']) . '</span>';
              ?></td>
              <td class="small"><?= e($r['assignee_name'] ?? '—') ?></td>
              <td class="small"><?= fmt_date($r['created_at']) ?></td>
              <td>
                <a href="detail.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">
                  <i class="bi bi-eye"></i>
                </a>
                <?php if (can('complaints.manage')): ?>
                <button class="btn btn-sm btn-outline-warning py-0 px-2"
                        data-bs-toggle="modal" data-bs-target="#modalStatus"
                        data-id="<?= $r['id'] ?>" data-status="<?= $r['status'] ?>" data-assign="<?= $r['assigned_to'] ?>">
                  <i class="bi bi-pencil"></i>
                </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages'] > 1): ?>
      <div class="card-footer"><?= render_pagination($pag, '?status='.urlencode($f_status)) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Buat Aduan -->
<div class="modal fade" id="modalCreate" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <div class="modal-header"><h6 class="modal-title">Buat Aduan Baru</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Judul</label>
          <input type="text" name="title" class="form-control form-control-sm" required maxlength="200"></div>
        <div class="mb-2"><label class="form-label">Kategori</label>
          <select name="category" class="form-select form-select-sm">
            <?php foreach (['umum','kebersihan','keamanan','fasilitas','lainnya'] as $c): ?>
              <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Prioritas</label>
          <select name="priority" class="form-select form-select-sm">
            <option value="rendah">Rendah</option>
            <option value="sedang" selected>Sedang</option>
            <option value="tinggi">Tinggi</option>
          </select></div>
        <div class="mb-2"><label class="form-label">Deskripsi</label>
          <textarea name="description" class="form-control form-control-sm" rows="3" required></textarea></div>
        <div class="mb-2"><label class="form-label">Foto <small class="text-muted">(opsional)</small></label>
          <input type="file" name="photo_file" class="form-control form-control-sm" accept="image/*"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-info btn-sm">Kirim Aduan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Update Status -->
<?php if (can('complaints.manage')): ?>
<div class="modal fade" id="modalStatus" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="update_status">
      <input type="hidden" name="complaint_id" id="statusId">
      <div class="modal-header"><h6 class="modal-title">Update Status</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Status</label>
          <select name="status" id="statusSelect" class="form-select form-select-sm">
            <?php foreach (['baru','diproses','selesai','ditutup'] as $s): ?>
              <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Ditugaskan ke</label>
          <select name="assigned_to" id="statusAssign" class="form-select form-select-sm ts-select">
            <option value="">— Pilih petugas —</option>
            <?php foreach ($officers as $o): ?>
              <option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-warning btn-sm">Simpan</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('modalStatus')?.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('statusId').value = b.dataset.id;
  document.getElementById('statusSelect').value = b.dataset.status;
  document.getElementById('statusAssign').value = b.dataset.assign || '';
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
