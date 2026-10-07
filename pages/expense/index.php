<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('expense.request');

$db  = db();
$uid = auth_id();

// ── POST: ajukan pengeluaran ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'request') {
        require_permission('expense.request');
        $cat    = clean($_POST['category']    ?? '');
        $amt    = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $desc   = clean($_POST['description'] ?? '');
        $date   = clean($_POST['trx_date']    ?? '');
        if (!$cat || $amt <= 0 || !$desc || !$date) {
            flash('error', 'Semua kolom wajib diisi.');
        } else {
            $s = $db->prepare(
                'INSERT INTO expense_requests (category,amount,description,trx_date,requested_by)
                 VALUES (?,?,?,?,?)'
            );
            $s->bind_param('sdssi', $cat, $amt, $desc, $date, $uid);
            $s->execute();
            log_activity('create', 'expense', "Ajukan pengeluaran: {$cat} " . idr($amt));
            flash('success', 'Pengajuan pengeluaran berhasil dikirim.');
        }
        redirect(APP_URL . '/pages/expense/index.php');
    }

    if ($action === 'approve' || $action === 'reject') {
        require_permission('expense.approve');
        $id   = (int)($_POST['req_id'] ?? 0);
        $note = clean($_POST['review_note'] ?? '');
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $now  = date('Y-m-d H:i:s');

        if ($action === 'approve') {
            // Fetch request
            $r = $db->prepare('SELECT * FROM expense_requests WHERE id=? AND status="pending"');
            $r->bind_param('i', $id);
            $r->execute();
            $req = $r->get_result()->fetch_assoc();
            if (!$req) { flash('error', 'Pengajuan tidak ditemukan.'); redirect(APP_URL . '/pages/expense/index.php'); }

            // Catat ke cash_book
            $cb = $db->prepare(
                'INSERT INTO cash_book (type,category,amount,description,trx_date,created_by)
                 VALUES (?,?,?,?,?,?)'
            );
            $type = 'pengeluaran';
            $cb->bind_param('ssdssi', $type, $req['category'],
                            $req['amount'], $req['description'], $req['trx_date'], $uid);
            $cb->execute();
            $cb_id = $db->insert_id;

            $s = $db->prepare(
                'UPDATE expense_requests SET status=?,reviewed_by=?,reviewed_at=?,review_note=?,cash_book_id=?
                 WHERE id=?'
            );
            $s->bind_param('sissii', $status, $uid, $now, $note, $cb_id, $id);
            $s->execute();
            log_activity('approve', 'expense', "Setujui pengeluaran id={$id}");
            flash('success', 'Pengeluaran disetujui dan dicatat di buku kas.');
        } else {
            $s = $db->prepare(
                'UPDATE expense_requests SET status=?,reviewed_by=?,reviewed_at=?,review_note=? WHERE id=?'
            );
            $s->bind_param('sissi', $status, $uid, $now, $note, $id);
            $s->execute();
            log_activity('reject', 'expense', "Tolak pengeluaran id={$id}");
            flash('warning', 'Pengajuan ditolak.');
        }
        redirect(APP_URL . '/pages/expense/index.php');
    }
}

// ── Query ──────────────────────────────────────────────────────────────────
$f_status = clean($_GET['status'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 20;

$where  = ['1=1'];
$params = [];
$types  = '';
if ($f_status) { $where[] = 'er.status=?'; $params[] = $f_status; $types .= 's'; }
// Non-approver hanya lihat milik sendiri
if (!can('expense.approve')) { $where[] = 'er.requested_by=?'; $params[] = $uid; $types .= 'i'; }

$wsql = implode(' AND ', $where);
$cnt  = $db->prepare("SELECT COUNT(*) FROM expense_requests er WHERE {$wsql}");
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT er.*,
            u1.name AS requester_name, u2.name AS reviewer_name
     FROM expense_requests er
     LEFT JOIN users u1 ON u1.id = er.requested_by
     LEFT JOIN users u2 ON u2.id = er.reviewed_by
     WHERE {$wsql}
     ORDER BY er.created_at DESC LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types . 'ii', ...$fp);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pending_count = $db->query('SELECT COUNT(*) FROM expense_requests WHERE status="pending"')->fetch_row()[0];

$page_title = 'Approval Pengeluaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-file-earmark-check me-1 text-warning"></i> Approval Pengeluaran</h6>
    <?php if ($pending_count > 0 && can('expense.approve')): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $pending_count ?> menunggu</span>
    <?php endif; ?>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <?php if (can('expense.request')): ?>
    <!-- Form Pengajuan -->
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-plus-circle me-1 text-warning"></i> Ajukan Pengeluaran</div>
      <div class="card-body">
        <form method="POST" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="request">
          <div class="col-md-3">
            <label class="form-label">Kategori</label>
            <input type="text" name="category" class="form-control form-control-sm" required maxlength="100" placeholder="Listrik, ATK…">
          </div>
          <div class="col-md-2">
            <label class="form-label">Jumlah (Rp)</label>
            <input type="text" name="amount" class="form-control form-control-sm" required data-rupiah inputmode="numeric">
          </div>
          <div class="col-md-2">
            <label class="form-label">Tanggal</label>
            <input type="date" name="trx_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Keterangan</label>
            <input type="text" name="description" class="form-control form-control-sm" required maxlength="255">
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <button class="btn btn-warning btn-sm w-100"><i class="bi bi-send me-1"></i>Ajukan</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Daftar Pengajuan -->
    <div class="card">
      <div class="card-header d-flex gap-2 align-items-center flex-wrap">
        <span><i class="bi bi-table me-1"></i> Riwayat Pengajuan</span>
        <div class="ms-auto d-flex gap-2">
          <?php foreach ([''=>'Semua','pending'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak'] as $v => $l): ?>
            <a href="?status=<?= $v ?>" class="btn btn-sm <?= $f_status===$v ? 'btn-warning' : 'btn-outline-secondary' ?>"><?= $l ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>Tanggal</th><th>Kategori</th>
              <th class="text-end">Jumlah</th><th>Keterangan</th>
              <th>Diajukan</th><th>Status</th>
              <?php if (can('expense.approve')): ?><th>Aksi</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Belum ada pengajuan.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?= fmt_date($r['trx_date']) ?></td>
                <td><?= e($r['category']) ?></td>
                <td class="text-end fw-semibold text-danger"><?= idr((float)$r['amount']) ?></td>
                <td class="text-muted small"><?= e($r['description']) ?></td>
                <td class="small"><?= e($r['requester_name'] ?? '—') ?></td>
                <td><?php
                  $map = ['pending'=>['warning','Menunggu'],'approved'=>['success','Disetujui'],'rejected'=>['danger','Ditolak']];
                  [$cls,$lbl] = $map[$r['status']] ?? ['secondary',$r['status']];
                  echo "<span class=\"badge bg-{$cls}\">{$lbl}</span>";
                  if ($r['review_note']) echo "<br><small class='text-muted'>" . e($r['review_note']) . "</small>";
                ?></td>
                <?php if (can('expense.approve')): ?>
                <td>
                  <?php if ($r['status'] === 'pending'): ?>
                  <button class="btn btn-sm btn-success py-0 px-2 me-1"
                          data-bs-toggle="modal" data-bs-target="#modalApprove"
                          data-id="<?= $r['id'] ?>" data-label="<?= e($r['category']) ?> <?= idr((float)$r['amount']) ?>">
                    <i class="bi bi-check-lg"></i>
                  </button>
                  <button class="btn btn-sm btn-outline-danger py-0 px-2"
                          data-bs-toggle="modal" data-bs-target="#modalReject"
                          data-id="<?= $r['id'] ?>">
                    <i class="bi bi-x-lg"></i>
                  </button>
                  <?php elseif ($r['reviewer_name']): ?>
                    <small class="text-muted"><?= e($r['reviewer_name']) ?></small>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages'] > 1): ?>
      <div class="card-footer">
        <?= render_pagination($pag, '?status='.urlencode($f_status)) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Approve -->
<div class="modal fade" id="modalApprove" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="approve">
      <input type="hidden" name="req_id" id="approveId">
      <div class="modal-header"><h6 class="modal-title">Setujui Pengeluaran</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p id="approveLabel" class="fw-semibold"></p>
        <label class="form-label">Catatan (opsional)</label>
        <textarea name="review_note" class="form-control form-control-sm" rows="2"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-success btn-sm">Setujui & Catat</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Reject -->
<div class="modal fade" id="modalReject" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="reject">
      <input type="hidden" name="req_id" id="rejectId">
      <div class="modal-header"><h6 class="modal-title">Tolak Pengajuan</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label">Alasan penolakan</label>
        <textarea name="review_note" class="form-control form-control-sm" rows="2" required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('modalApprove')?.addEventListener('show.bs.modal', e => {
  const btn = e.relatedTarget;
  document.getElementById('approveId').value = btn.dataset.id;
  document.getElementById('approveLabel').textContent = btn.dataset.label;
});
document.getElementById('modalReject')?.addEventListener('show.bs.modal', e => {
  document.getElementById('rejectId').value = e.relatedTarget.dataset.id;
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
