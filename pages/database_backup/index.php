<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
if (auth_role() !== 'super_admin') { http_response_code(403); exit('Akses khusus Super Admin.'); }
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_backup.php';
$db = db();
$dir = database_backup_dir();

if (isset($_GET['download'])) {
    $name = (string)$_GET['download'];
    if (!preg_match('/^(?:backup|pre_restore)_\d{8}_\d{6}_[a-f0-9]{8}\.sql$/D', $name)) { http_response_code(404); exit; }
    $file = $dir . '/' . $name;
    if (!is_file($file) || !database_backup_verify($file)) { http_response_code(404); exit('Backup tidak ditemukan atau tidak valid.'); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store, private');
    readfile($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (($_POST['_action'] ?? '') === 'backup') {
            $name = database_backup_create($db);
            flash('success', 'Backup database berhasil dibuat: ' . $name);
        } elseif (($_POST['_action'] ?? '') === 'restore') {
            if (($_POST['confirmation'] ?? '') !== 'PULIHKAN') throw new RuntimeException('Ketik PULIHKAN dengan benar untuk mengonfirmasi restore.');
            $upload = $_FILES['backup_file'] ?? null;
            if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Upload file backup gagal.');
            if ((int)$upload['size'] < 80 || (int)$upload['size'] > 64 * 1024 * 1024) throw new RuntimeException('Ukuran file harus di bawah 64 MB.');
            $incoming = tempnam($dir, '.restore-');
            if (!$incoming || !move_uploaded_file($upload['tmp_name'], $incoming)) throw new RuntimeException('File backup tidak dapat disimpan sementara.');
            @chmod($incoming, 0600);
            if (!database_backup_verify($incoming)) { @unlink($incoming); throw new RuntimeException('File tidak valid, rusak, atau berasal dari konfigurasi database berbeda.'); }
            $snapshot = database_backup_create($db, 'pre_restore');
            try {
                database_backup_restore($db, $incoming);
                flash('success', 'Pemulihan database berhasil. Backup kondisi sebelum restore: ' . $snapshot);
            } catch (Throwable $restoreError) {
                $snapshotPath = $dir . '/' . $snapshot;
                try {
                    $rollbackDb = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
                    $rollbackDb->set_charset(DB_CHARSET);
                    database_backup_restore($rollbackDb, $snapshotPath);
                    $rollbackDb->close();
                } catch (Throwable $rollbackError) {
                    error_log('Database restore and automatic rollback failed: ' . $rollbackError->getMessage());
                }
                throw new RuntimeException('Restore gagal. Backup pengaman dibuat: ' . $snapshot . '. Periksa log server sebelum mencoba lagi.');
            } finally { @unlink($incoming); }
        } else throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        error_log('Database backup/restore failed: ' . $e->getMessage());
        flash('error', $e->getMessage());
    }
    redirect(APP_URL . '/pages/database_backup/index.php');
}

$files = glob($dir . '/*.sql') ?: [];
$files = array_values(array_filter($files, 'is_file'));
usort($files, static fn($a,$b) => filemtime($b) <=> filemtime($a));
$page_title = 'Cadangan Database';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-database-lock me-1 text-success"></i> Cadangan &amp; Pemulihan Database</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1"></i> Fitur Super Admin. File backup berisi data sensitif; simpan di tempat aman. Backup hanya berisi database, bukan berkas upload/foto.</div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-cloud-arrow-down me-1"></i> Buat Cadangan</div>
      <div class="card-body">
        <p class="mb-3">Buat snapshot semua tabel dan data database saat ini. File disimpan di folder privat di luar direktori aplikasi, lalu dapat diunduh.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="backup"><button class="btn btn-success"><i class="bi bi-download me-1"></i> Buat Backup Sekarang</button></form>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-cloud-arrow-up me-1"></i> Pulihkan Database</div>
      <div class="card-body">
        <p class="text-danger">Restore mengganti struktur dan isi tabel database. Hanya backup SQL bertanda tangan yang dibuat dari instalasi database ini yang diterima. Sistem membuat backup otomatis sebelum restore.</p>
        <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Restore akan mengganti data database. Lanjutkan hanya jika file backup sudah dipastikan benar.');">
          <?= csrf_field() ?><input type="hidden" name="_action" value="restore">
          <div class="mb-3"><label class="form-label" for="backup_file">File backup SQL (maks. 64 MB)</label><input class="form-control" type="file" id="backup_file" name="backup_file" accept=".sql,text/plain,application/sql" required></div>
          <div class="mb-3"><label class="form-label" for="confirmation">Ketik <code>PULIHKAN</code> untuk konfirmasi</label><input class="form-control" id="confirmation" name="confirmation" autocomplete="off" required></div>
          <button class="btn btn-danger"><i class="bi bi-arrow-counterclockwise me-1"></i> Pulihkan Database</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-1"></i> Backup Tersimpan</div>
      <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Nama file</th><th>Waktu</th><th>Ukuran</th><th></th></tr></thead><tbody>
      <?php if (!$files): ?><tr><td colspan="4" class="text-center text-muted py-3">Belum ada backup tersimpan.</td></tr><?php endif; ?>
      <?php foreach ($files as $file): $name=basename($file); ?><tr><td><code><?= e($name) ?></code></td><td><?= date('d-m-Y H:i:s', filemtime($file)) ?></td><td><?= number_format(filesize($file)/1048576, 2) ?> MB</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?download=<?= rawurlencode($name) ?>"><i class="bi bi-download me-1"></i>Unduh</a></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
