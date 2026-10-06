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
        $type    = in_array($_POST['type'] ?? '', ['pengantar','keterangan','domisili','lainnya']) ? $_POST['type'] : 'pengantar';
        $purpose = clean($_POST['purpose'] ?? '');
        $body    = clean($_POST['body'] ?? '');
        $rid     = (int)($_POST['resident_id'] ?? 0) ?: null;
        $date    = clean($_POST['issued_date'] ?? date('Y-m-d'));
        // Auto-generate nomor surat: urutan/RT/bulan/tahun
        $count   = $db->query('SELECT COUNT(*)+1 FROM letters WHERE YEAR(issued_date)=YEAR(NOW())')->fetch_row()[0];
        $number  = sprintf('%03d/RT/%s/%d', $count, date('m'), date('Y'));

        if (!$purpose || !$body) {
            flash('error', 'Keperluan dan isi surat wajib diisi.');
            redirect(APP_URL.'/pages/letters/index.php');
        }
        $s = $db->prepare('INSERT INTO letters (type,number,resident_id,purpose,body,issued_date,issued_by) VALUES (?,?,?,?,?,?,?)');
        $s->bind_param('ssissssi', $type, $number, $rid, $purpose, $body, $date, $uid);
        $s->execute();
        $lid = $db->insert_id;
        log_activity('create', 'letters', "Surat {$number}: {$purpose}");
        flash('success', "Surat {$number} berhasil dibuat.");
        redirect(APP_URL.'/pages/letters/print.php?id='.$lid);
    }

    if ($action === 'delete') {
        require_permission('letters.manage');
        $did = (int)($_POST['id'] ?? 0);
        if ($did) {
            $db->prepare('DELETE FROM letters WHERE id=?')->bind_param('i', $did)->execute();
            log_activity('delete', 'letters', "Hapus surat id={$did}");
            flash('success', 'Surat dihapus.');
        }
        redirect(APP_URL.'/pages/letters/index.php');
    }
}

$search = clean($_GET['q'] ?? '');
$f_type = clean($_GET['type'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

$where = ['1=1']; $params = []; $types = '';
if ($search) {
    $where[] = '(l.number LIKE ? OR l.purpose LIKE ? OR r.name LIKE ?)';
    $l = "%{$search}%"; $params[] = $l; $params[] = $l; $params[] = $l; $types .= 'sss';
}
if ($f_type) { $where[] = 'l.type=?'; $params[] = $f_type; $types .= 's'; }
$wsql = implode(' AND ', $where);

$cnt_s = $db->prepare("SELECT COUNT(*) FROM letters l LEFT JOIN residents r ON r.id=l.resident_id WHERE {$wsql}");
if ($types) $cnt_s->bind_param($types, ...$params);
$cnt_s->execute();
$total = $cnt_s->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT l.*, r.name AS resident_name, u.name AS issuer_name
     FROM letters l
     LEFT JOIN residents r ON r.id=l.resident_id
     LEFT JOIN users u ON u.id=l.issued_by
     WHERE {$wsql}
     ORDER BY l.issued_date DESC, l.id DESC LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types.'ii', ...$fp);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$residents = $db->query('SELECT id,name FROM residents WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$type_labels = ['pengantar'=>'Pengantar','keterangan'=>'Keterangan','domisili'=>'Domisili','lainnya'=>'Lainnya'];

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

    <!-- Filter -->
    <form method="GET" class="d-flex gap-2 mb-3 flex-wrap align-items-end">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px"
             placeholder="Cari nomor/keperluan/warga…" value="<?= e($search) ?>">
      <select name="type" class="form-select form-select-sm" style="max-width:160px">
        <option value="">Semua Jenis</option>
        <?php foreach ($type_labels as $v => $l): ?>
          <option value="<?= $v ?>" <?= $f_type === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search me-1"></i>Filter</button>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </form>

    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>No. Surat</th><th>Jenis</th><th>Warga</th><th>Keperluan</th>
              <th>Tgl Terbit</th><th>Dibuat oleh</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Belum ada surat RT.</td></tr>
            <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><code><?= e($r['number'] ?? '—') ?></code></td>
              <td><span class="badge bg-secondary"><?= e($type_labels[$r['type']] ?? $r['type']) ?></span></td>
              <td><?= e($r['resident_name'] ?? '—') ?></td>
              <td><?= e($r['purpose']) ?></td>
              <td><?= fmt_date($r['issued_date']) ?></td>
              <td class="small text-muted"><?= e($r['issuer_name'] ?? '—') ?></td>
              <td class="text-nowrap">
                <a href="print.php?id=<?= $r['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary py-0 px-2 me-1">
                  <i class="bi bi-printer"></i> Cetak
                </a>
                <?php if (can('letters.manage')): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus surat ini?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages'] > 1): ?>
      <div class="card-footer">
        <?= render_pagination($pag, '?q='.urlencode($search).'&type='.urlencode($f_type)) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (can('letters.manage')): ?>
<div class="modal fade" id="modalCreate" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-file-earmark-plus me-1"></i>Buat Surat RT</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Jenis Surat</label>
            <select name="type" class="form-select form-select-sm">
              <?php foreach ($type_labels as $v => $l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Warga <small class="text-muted">(opsional)</small></label>
            <select name="resident_id" class="form-select form-select-sm">
              <option value="">— Pilih warga —</option>
              <?php foreach ($residents as $res): ?>
                <option value="<?= $res['id'] ?>"><?= e($res['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Tanggal Terbit</label>
            <input type="date" name="issued_date" class="form-control form-control-sm"
                   value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Keperluan <span class="text-danger">*</span></label>
            <input type="text" name="purpose" class="form-control form-control-sm" required
                   maxlength="200" placeholder="Mengurus KTP, Membuka rekening bank…">
          </div>
          <div class="col-12">
            <label class="form-label">Isi Surat <span class="text-danger">*</span></label>
            <textarea name="body" class="form-control form-control-sm" rows="7" required
                      placeholder="Yang bertanda tangan di bawah ini, Ketua RT…"></textarea>
            <div class="form-text">
              Variabel otomatis: <code>{nama}</code> <code>{alamat}</code>
              <code>{no_ktp}</code> <code>{telepon}</code>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-secondary btn-sm">
          <i class="bi bi-printer me-1"></i>Simpan &amp; Cetak
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
