<?php
// Onboarding wizard — hanya tampil jika setup belum selesai
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('users.create'); // hanya ketua/super

$db  = db();
$uid = auth_id();
$step = (int)($_GET['step'] ?? 1);

// Cek apakah sudah ada data (skip jika sudah setup)
$unit_count     = $db->query('SELECT COUNT(*) FROM units')->fetch_row()[0];
$resident_count = $db->query('SELECT COUNT(*) FROM residents')->fetch_row()[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['_action'] ?? '');

    // Step 1: Info RT
    if ($action === 'setup_env') {
        $name  = clean($_POST['env_name'] ?? '');
        $rt    = clean($_POST['rt'] ?? '');
        $rw    = clean($_POST['rw'] ?? '');
        $kel   = clean($_POST['kelurahan'] ?? '');
        $kec   = clean($_POST['kecamatan'] ?? '');
        $city  = clean($_POST['city'] ?? '');
        if (!$name) { flash('error', 'Nama lingkungan wajib diisi.'); redirect(APP_URL.'/pages/onboarding/index.php?step=1'); }
        $s = $db->prepare('UPDATE environments SET name=?,rt=?,rw=?,kelurahan=?,kecamatan=?,city=? WHERE id=1');
        $s->bind_param('ssssss', $name, $rt, $rw, $kel, $kec, $city); $s->execute();
        log_activity('setup', 'onboarding', 'Setup info lingkungan RT');
        flash('success', 'Info RT disimpan.');
        redirect(APP_URL.'/pages/onboarding/index.php?step=2');
    }

    // Step 2a: Preview CSV sebelum import
    if ($action === 'preview_csv') {
        if (empty($_FILES['csv_file']['name'])) {
            flash('error', 'Pilih file CSV terlebih dahulu.');
            redirect(APP_URL.'/pages/onboarding/index.php?step=2');
        }
        $file = $_FILES['csv_file']['tmp_name'];
        $preview = [];
        if (($handle = fopen($file, 'r')) !== false) {
            fgetcsv($handle); // skip header
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4) continue;
                $status = trim($row[5] ?? 'pemilik');
                if (!in_array($status, ['pemilik','penyewa'])) $status = 'pemilik';
                $preview[] = [
                    'unit_number' => trim($row[0]),
                    'block'       => trim($row[1]),
                    'name'        => trim($row[2]),
                    'phone'       => trim($row[3]),
                    'email'       => trim($row[4] ?? ''),
                    'status'      => $status,
                ];
            }
            fclose($handle);
        }
        if (empty($preview)) {
            flash('error', 'File CSV kosong atau format tidak sesuai.');
            redirect(APP_URL.'/pages/onboarding/index.php?step=2');
        }
        $_SESSION['csv_preview'] = $preview;
        redirect(APP_URL.'/pages/onboarding/index.php?step=2&preview=1');
    }

    // Step 2b: Konfirmasi import dari preview
    if ($action === 'import_residents') {
        $preview = $_SESSION['csv_preview'] ?? [];
        unset($_SESSION['csv_preview']);
        if (empty($preview)) {
            flash('error', 'Tidak ada data preview. Upload ulang file CSV.');
            redirect(APP_URL.'/pages/onboarding/index.php?step=2');
        }
        $rows = 0;
        $ut_id = $db->query('SELECT id FROM unit_types LIMIT 1')->fetch_row()[0] ?? 1;
        foreach ($preview as $p) {
            [$unit_num, $block, $name, $phone, $email, $status] = array_values($p);
            $u = $db->prepare('SELECT id FROM units WHERE unit_number=? AND block=?');
            $u->bind_param('ss', $unit_num, $block); $u->execute();
            $unit = $u->get_result()->fetch_row();
            if (!$unit) {
                $s2 = 'dihuni';
                $ins = $db->prepare('INSERT INTO units (unit_type_id,unit_number,block,status) VALUES (?,?,?,?)');
                $ins->bind_param('isss', $ut_id, $unit_num, $block, $s2); $ins->execute();
                $unit_id = $db->insert_id;
            } else {
                $unit_id = $unit[0];
            }
            $r = $db->prepare('INSERT IGNORE INTO residents (unit_id,name,phone,email,status) VALUES (?,?,?,?,?)');
            $r->bind_param('issss', $unit_id, $name, $phone, $email, $status);
            if ($r->execute()) $rows++;
        }
        log_activity('import', 'onboarding', "Import {$rows} warga dari CSV");
        flash('success', "Berhasil mengimpor {$rows} warga.");
        redirect(APP_URL.'/pages/onboarding/index.php?step=3');
    }

    // Step 3: Setup kas awal
    if ($action === 'setup_kas') {
        $balance = (float)str_replace(['.', ','], ['', '.'], $_POST['initial_balance'] ?? '0');
        if ($balance > 0) {
            // Catat sebagai saldo awal di buku kas
            $s = $db->prepare('INSERT INTO cash_book (type,category,amount,description,trx_date,created_by) VALUES (?,?,?,?,?,?)');
            $type = 'pemasukan'; $cat = 'Saldo Awal'; $desc = 'Saldo kas awal setup';
            $date = date('Y-m-d');
            $s->bind_param('ssdssi', $type, $cat, $balance, $desc, $date, $uid); $s->execute();
        }
        log_activity('setup', 'onboarding', 'Setup saldo kas awal');
        flash('success', 'Setup selesai! Sistem siap digunakan.');
        redirect(APP_URL.'/pages/dashboard.php');
    }
}

$page_title = 'Setup Awal RT';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-magic me-1 text-success"></i> Setup Awal RT</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content" style="max-width:680px">
    <?= render_flash() ?>

    <!-- Progress steps -->
    <div class="d-flex gap-2 mb-4">
      <?php foreach ([1=>'Info RT', 2=>'Import Warga', 3=>'Kas Awal'] as $s => $l): ?>
      <div class="flex-fill text-center">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold mb-1"
             style="width:32px;height:32px;background:<?= $step>=$s?'#198754':'#dee2e6' ?>;color:<?= $step>=$s?'#fff':'#6c757d' ?>">
          <?= $step > $s ? '✓' : $s ?>
        </div>
        <div class="small <?= $step===$s?'fw-semibold':'text-muted' ?>"><?= $l ?></div>
      </div>
      <?php if ($s < 3): ?>
        <div class="flex-shrink-0 d-flex align-items-center" style="margin-top:-16px">
          <div style="height:2px;width:32px;background:<?= $step>$s?'#198754':'#dee2e6' ?>"></div>
        </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($step === 1): ?>
    <!-- Step 1: Info RT -->
    <div class="card">
      <div class="card-header"><i class="bi bi-geo-alt me-1"></i> Informasi Lingkungan RT</div>
      <div class="card-body">
        <?php
        $env = $db->query('SELECT * FROM environments WHERE id=1')->fetch_assoc();
        ?>
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="_action" value="setup_env">
          <div class="mb-2"><label class="form-label">Nama Perumahan / Lingkungan</label>
            <input type="text" name="env_name" class="form-control" required value="<?= e($env['name'] ?? APP_NAME) ?>"></div>
          <div class="row g-2 mb-2">
            <div class="col-md-3"><label class="form-label">RT</label>
              <input type="text" name="rt" class="form-control form-control-sm" maxlength="10" value="<?= e($env['rt'] ?? '') ?>" placeholder="001"></div>
            <div class="col-md-3"><label class="form-label">RW</label>
              <input type="text" name="rw" class="form-control form-control-sm" maxlength="10" value="<?= e($env['rw'] ?? '') ?>" placeholder="010"></div>
            <div class="col-md-6"><label class="form-label">Kelurahan</label>
              <input type="text" name="kelurahan" class="form-control form-control-sm" value="<?= e($env['kelurahan'] ?? '') ?>"></div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6"><label class="form-label">Kecamatan</label>
              <input type="text" name="kecamatan" class="form-control form-control-sm" value="<?= e($env['kecamatan'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Kota/Kabupaten</label>
              <input type="text" name="city" class="form-control form-control-sm" value="<?= e($env['city'] ?? '') ?>"></div>
          </div>
          <button class="btn btn-success w-100">Lanjut <i class="bi bi-arrow-right ms-1"></i></button>
        </form>
      </div>
    </div>

    <?php elseif ($step === 2): ?>
    <!-- Step 2: Import CSV -->
    <?php $csv_preview = $_SESSION['csv_preview'] ?? null; ?>
    <?php if ($csv_preview && isset($_GET['preview'])): ?>
    <!-- Preview table -->
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-table me-1"></i> Preview Data (<?= count($csv_preview) ?> baris)</span>
        <a href="?step=2" class="btn btn-sm btn-outline-secondary" onclick="<?php unset($_SESSION['csv_preview']); ?>">
          <i class="bi bi-arrow-left me-1"></i>Upload Ulang
        </a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height:360px;overflow-y:auto">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
              <tr><th>#</th><th>Unit</th><th>Blok</th><th>Nama</th><th>Telepon</th><th>Email</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($csv_preview as $i => $row): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><?= e($row['unit_number']) ?></td>
              <td><?= e($row['block']) ?></td>
              <td><?= e($row['name']) ?></td>
              <td><?= e($row['phone']) ?></td>
              <td><?= e($row['email']) ?></td>
              <td><span class="badge bg-<?= $row['status']==='pemilik'?'primary':'secondary' ?>"><?= e($row['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="_action" value="import_residents">
          <div class="d-flex gap-2">
            <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-x me-1"></i>Batal</a>
            <button class="btn btn-success flex-fill">
              <i class="bi bi-check-lg me-1"></i>Konfirmasi Import <?= count($csv_preview) ?> Warga
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Import Data Warga</div>
      <div class="card-body">
        <div class="alert alert-info small">
          <strong>Format CSV:</strong> unit_number, block, nama, telepon, email (opsional), status (pemilik/penyewa)<br>
          Contoh: <code>A01,A,Budi Santoso,08123456789,budi@email.com,pemilik</code>
        </div>
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="_action" value="preview_csv">
          <div class="mb-3">
            <label class="form-label">File CSV</label>
            <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
          </div>
          <div class="d-flex gap-2">
            <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
            <button class="btn btn-success flex-fill"><i class="bi bi-eye me-1"></i>Preview Data</button>
          </div>
        </form>

        <hr>
        <div class="text-center">
          <small class="text-muted">Sudah punya data? </small>
          <a href="?step=3" class="btn btn-sm btn-outline-secondary">Lewati langkah ini</a>
        </div>

        <?php if ($unit_count > 0 || $resident_count > 0): ?>
        <div class="alert alert-success mt-3 small mb-0">
          <i class="bi bi-check-circle me-1"></i>
          Sudah ada <?= $unit_count ?> unit dan <?= $resident_count ?> warga di sistem.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Download template -->
    <div class="card mt-3">
      <div class="card-body text-center">
        <a href="download_template.php" class="btn btn-outline-success btn-sm">
          <i class="bi bi-download me-1"></i>Download Template CSV
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php elseif ($step === 3): ?>
    <!-- Step 3: Saldo awal kas -->
    <div class="card">
      <div class="card-header"><i class="bi bi-wallet2 me-1"></i> Saldo Kas Awal</div>
      <div class="card-body">
        <p class="text-muted small">Masukkan saldo kas yang sudah ada sebelum menggunakan sistem ini. Kosongkan jika mulai dari nol.</p>
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="_action" value="setup_kas">
          <div class="mb-3">
            <label class="form-label">Saldo Awal (Rp)</label>
            <input type="text" name="initial_balance" class="form-control form-control-lg"
                   data-rupiah inputmode="numeric" placeholder="0" value="0">
          </div>
          <div class="d-flex gap-2">
            <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
            <button class="btn btn-success flex-fill"><i class="bi bi-check-lg me-1"></i>Selesai Setup</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
