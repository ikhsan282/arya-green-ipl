<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('polls.view');

$db  = db();
$uid = auth_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'create') {
        require_permission('polls.manage');
        $title  = clean($_POST['title'] ?? '');
        $desc   = clean($_POST['description'] ?? '');
        $starts = clean($_POST['starts_at'] ?? '');
        $ends   = clean($_POST['ends_at'] ?? '');
        $pub    = isset($_POST['is_public']) ? 1 : 0;
        $opts   = array_filter(array_map('trim', explode("\n", $_POST['options'] ?? '')));
        if (!$title || !$starts || !$ends || count($opts) < 2) {
            flash('error', 'Judul, waktu, dan minimal 2 pilihan wajib diisi.');
        } else {
            $s = $db->prepare('INSERT INTO polls (title,description,starts_at,ends_at,is_public,created_by) VALUES (?,?,?,?,?,?)');
            $s->bind_param('ssssii', $title, $desc, $starts, $ends, $pub, $uid);
            $s->execute();
            $pid = $db->insert_id;
            $so = $db->prepare('INSERT INTO poll_options (poll_id,label,sort) VALUES (?,?,?)');
            foreach (array_values($opts) as $i => $opt) {
                $so->bind_param('isi', $pid, $opt, $i);
                $so->execute();
            }
            log_activity('create', 'polls', "Buat polling: {$title}");
            flash('success', 'Polling berhasil dibuat.');
        }
        redirect(APP_URL.'/pages/polls/index.php');
    }

    if ($action === 'vote') {
        require_permission('polls.vote');
        $pid = (int)($_POST['poll_id'] ?? 0);
        $oid = (int)($_POST['option_id'] ?? 0);
        // Check already voted
        $chk = $db->prepare('SELECT id FROM poll_votes WHERE poll_id=? AND user_id=?');
        $chk->bind_param('ii', $pid, $uid); $chk->execute();
        if ($chk->get_result()->fetch_row()) { flash('warning', 'Anda sudah memberikan suara.'); }
        else {
            $rid = $db->prepare('SELECT id FROM residents WHERE user_id=? LIMIT 1');
            $rid->bind_param('i', $uid); $rid->execute();
            $resident_id = $rid->get_result()->fetch_row()[0] ?? null;
            $s = $db->prepare('INSERT INTO poll_votes (poll_id,poll_option_id,user_id,resident_id) VALUES (?,?,?,?)');
            $s->bind_param('iiii', $pid, $oid, $uid, $resident_id);
            $s->execute();
            flash('success', 'Suara berhasil dicatat.');
        }
        redirect(APP_URL.'/pages/polls/index.php');
    }
}

$now  = date('Y-m-d H:i:s');
$page = max(1,(int)($_GET['page'] ?? 1)); $per = 10;
$cnt  = $db->query('SELECT COUNT(*) FROM polls')->fetch_row()[0];
$pag  = paginate($cnt, $per, $page);
$polls = $db->prepare('SELECT p.*,u.name AS creator FROM polls p LEFT JOIN users u ON u.id=p.created_by ORDER BY p.created_at DESC LIMIT ? OFFSET ?');
$polls->bind_param('ii', $per, $pag['offset']); $polls->execute();
$polls = $polls->get_result()->fetch_all(MYSQLI_ASSOC);

// For each poll fetch options + vote counts + user's vote
foreach ($polls as &$p) {
    $opts = $db->prepare(
        'SELECT po.*, COUNT(pv.id) AS votes
         FROM poll_options po LEFT JOIN poll_votes pv ON pv.poll_option_id=po.id
         WHERE po.poll_id=? GROUP BY po.id ORDER BY po.sort'
    );
    $opts->bind_param('i', $p['id']); $opts->execute();
    $p['options'] = $opts->get_result()->fetch_all(MYSQLI_ASSOC);
    $p['total_votes'] = array_sum(array_column($p['options'], 'votes'));
    $myv = $db->prepare('SELECT poll_option_id FROM poll_votes WHERE poll_id=? AND user_id=?');
    $myv->bind_param('ii', $p['id'], $uid); $myv->execute();
    $p['my_vote'] = $myv->get_result()->fetch_row()[0] ?? null;
}
unset($p);

$page_title = 'Polling Warga';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-bar-chart-steps me-1 text-purple"></i> Polling Warga</h6>
    <?php if (can('polls.manage')): ?>
    <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#modalCreate">
      <i class="bi bi-plus-lg me-1"></i>Buat Polling
    </button>
    <?php endif; ?>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <?php if (empty($polls)): ?>
      <div class="text-center text-muted py-5">Belum ada polling aktif.</div>
    <?php else: foreach ($polls as $p):
      $active = $p['starts_at'] <= $now && $p['ends_at'] >= $now;
      $ended  = $p['ends_at'] < $now;
    ?>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div>
          <strong><?= e($p['title']) ?></strong>
          <?php if ($active): ?><span class="badge bg-success ms-1">Aktif</span>
          <?php elseif ($ended): ?><span class="badge bg-secondary ms-1">Selesai</span>
          <?php else: ?><span class="badge bg-warning ms-1">Belum Mulai</span><?php endif; ?>
        </div>
        <small class="text-muted"><?= fmt_date($p['starts_at'],'d M Y') ?> – <?= fmt_date($p['ends_at'],'d M Y') ?></small>
      </div>
      <div class="card-body">
        <?php if ($p['description']): ?>
          <p class="text-muted small mb-3"><?= e($p['description']) ?></p>
        <?php endif; ?>

        <?php if ($active && !$p['my_vote'] && can('polls.vote')): ?>
        <!-- Form vote -->
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="vote">
          <input type="hidden" name="poll_id" value="<?= $p['id'] ?>">
          <?php foreach ($p['options'] as $o): ?>
          <div class="form-check mb-1">
            <input class="form-check-input" type="radio" name="option_id" value="<?= $o['id'] ?>" id="opt<?= $o['id'] ?>" required>
            <label class="form-check-label" for="opt<?= $o['id'] ?>"><?= e($o['label']) ?></label>
          </div>
          <?php endforeach; ?>
          <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="bi bi-check2 me-1"></i>Kirim Suara</button>
        </form>
        <?php else: ?>
        <!-- Results -->
        <?php foreach ($p['options'] as $o):
          $pct = $p['total_votes'] > 0 ? round($o['votes'] / $p['total_votes'] * 100) : 0;
          $mine = $p['my_vote'] == $o['id'];
        ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span><?= e($o['label']) ?><?= $mine ? ' <i class="bi bi-check-circle-fill text-success ms-1"></i>' : '' ?></span>
            <span><?= $o['votes'] ?> suara (<?= $pct ?>%)</span>
          </div>
          <div class="progress" style="height:10px">
            <div class="progress-bar <?= $mine ? 'bg-success' : 'bg-primary' ?>" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <small class="text-muted"><?= $p['total_votes'] ?> total suara</small>
        <?php endif; ?>
      </div>
      <div class="card-footer text-muted small">Dibuat oleh <?= e($p['creator'] ?? '—') ?></div>
    </div>
    <?php endforeach; endif; ?>

    <?php if ($pag['total_pages'] > 1): ?>
    <div class="mt-3"><?= render_pagination($pag, '?') ?></div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('polls.manage')): ?>
<div class="modal fade" id="modalCreate" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <div class="modal-header"><h6 class="modal-title">Buat Polling Baru</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Judul</label>
          <input type="text" name="title" class="form-control form-control-sm" required maxlength="200"></div>
        <div class="mb-2"><label class="form-label">Deskripsi <small class="text-muted">(opsional)</small></label>
          <textarea name="description" class="form-control form-control-sm" rows="2"></textarea></div>
        <div class="row g-2 mb-2">
          <div class="col"><label class="form-label">Mulai</label>
            <input type="datetime-local" name="starts_at" class="form-control form-control-sm" required></div>
          <div class="col"><label class="form-label">Berakhir</label>
            <input type="datetime-local" name="ends_at" class="form-control form-control-sm" required></div>
        </div>
        <div class="mb-2"><label class="form-label">Pilihan <small class="text-muted">(satu per baris, min. 2)</small></label>
          <textarea name="options" class="form-control form-control-sm" rows="4" required placeholder="Setuju&#10;Tidak Setuju&#10;Abstain"></textarea></div>
        <div class="form-check"><input type="checkbox" name="is_public" class="form-check-input" id="chkPub">
          <label class="form-check-label" for="chkPub">Tampilkan di halaman publik</label></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Buat Polling</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
