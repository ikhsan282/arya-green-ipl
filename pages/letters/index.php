<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('letters.view');

$db  = db();
$uid = auth_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('letters.manage');
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'create') {
        $type    = clean($_POST['type'] ?? 'pengantar');
        $purpose = clean($_POST['purpose'] ?? '');
        $body    = clean($_POST['body'] ?? '');
        $rid     = (int)($_POST['resident_id'] ?? 0) ?: null;
        $date    = clean($_POST['issued_date'] ?? date('Y-m-d'));
        // Auto-generate nomor surat: RT/bulan/tahun/urutan
        $count   = $db->query('SELECT COUNT(*)+1 FROM letters WHERE YEAR(issued_date)=YEAR(NOW())')->fetch_row()[0];
        $number  = sprintf('%03d/RT/%.2s/%d', $count, date('m'), date('Y'));

        if (!$purpose || !$body) { flash('error', 'Keperluan dan isi surat wajib diisi.'); }
        else {
            $s = $db->prepare('INSERT INTO letters (type,number,resident_id,purpose,body,issued_date,issued_by) VALUES (?,?,?,?,?,?,?)');
            $s->bind_param('ssissssi', $type, $number, $rid, $purpose, $body, $date, $uid);
            $s->execute();
            $lid = $db->insert_id;
            log_activity('create', 'letters', "Surat {$number}: {$purpose}");
            flash('success', "Surat {$number} berhasil dibuat.");
            redirect(APP_URL.'/pages/letters/print.php?id='.$lid);
        }
        redirect(APP_URL.'/pages/letters/index.php');
    }
}

$page = max(1,(int)($_GET['page'] ?? 1)); $per = 20;
$cnt  = $db->query('SELECT COUNT(*) FROM letters')->fetch_row()[0];
$pag  = paginate($cnt, $per, $page);
$stmt = $db->prepare(
    'SELECT l.*, r.name AS resident_name, u.name AS issuer_name
     FROM letters l
     LEFT JOIN residents r ON r.id=l.resident_id
     LEFT JOIN users u ON u.id=l.issued_by
     ORDER BY l.issued_date DESC, l.id DESC LIMIT ? OFFSET ?'
);
$stmt->bind_param('ii',$per,$pag['offset']); $stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$residents = $db->query('SELECT id,name FROM residents WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$page_title = 'Surat RT';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-file-text me-1 text-secondary"></i> Surat RT</h6>
    <?php if (can('letters.manage')): ?>
    <button class="btn btn-sm btn-secondary ms-auto" data-bs-toggle="modal" data-bs-target="#modalCreate">
      <i class="bi bi-plus-lg me-1"></i>Buat Surat
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
              <th>No. Surat</th><th>Warga</th><th>Keperluan</th>
              <th>Tgl Terbit</th><th>Dibuat oleh</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Belum ada surat RT.</td></tr>
            <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><code><?= e($r['number'] ?? '—') ?></code></td>
              <td><?= e($r['resident_name'] ?? '—') ?></td>
              <td><?= e($r['purpose']) ?></td>
              <td><?= fmt_date($r['issued_date']) ?></td>
              <td class="small text-muted"><?= e($r['issuer_name'] ?? '—') ?></td>
              <td>
                <a href="print.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                  <i class="bi bi-printer"></i> Cetak
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

<?php if (can('letters.manage')): ?>
<div class="modal fade" id="modalCreate" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <div class="modal-header"><h6 class="modal-title">Buat Surat RT</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label">Jenis Surat</label>
            <select name="type" class="form-select form-select-sm">
              <option value="pengantar">Surat Pengantar</option>
              <option value="keterangan">Surat Keterangan</option>
              <option value="domisili">Surat Domisili</option>
              <option value="lainnya">Lainnya</option>
            </select></div>
          <div class="col-md-4"><label class="form-label">Warga</label>
            <select name="resident_id" class="form-select form-select-sm">
              <option value="">— Pilih warga —</option>
              <?php foreach ($residents as $r): ?>
                <option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label">Tanggal Terbit</label>
            <input type="date" name="issued_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-12"><label class="form-label">Keperluan</label>
            <input type="text" name="purpose" class="form-control form-control-sm" required maxlength="200"
                   placeholder="Mengurus KTP, Membuka rekening bank…"></div>
          <div class="col-12"><label class="form-label">Isi Surat</label>
            <textarea name="body" class="form-control form-control-sm" rows="6" required
                      placeholder="Yang bertanda tangan di bawah ini, Ketua RT…"></textarea>
            <div class="form-text">Akan dicetak sebagai PDF. Gunakan variabel: {nama}, {alamat}, {no_ktp}</div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-secondary btn-sm">Simpan & Cetak</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
