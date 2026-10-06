<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('kas.view');

$db  = db();
$uid = auth_id();

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('kas.manage');
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'add') {
        $name = clean($_POST['name'] ?? '');
        $desc = clean($_POST['description'] ?? '');
        $def  = isset($_POST['is_default']) ? 1 : 0;
        if (!$name) { flash('error', 'Nama kas wajib diisi.'); }
        else {
            if ($def) $db->query('UPDATE kas_accounts SET is_default=0');
            $s = $db->prepare('INSERT INTO kas_accounts (name,description,is_default) VALUES (?,?,?)');
            $s->bind_param('ssi', $name, $desc, $def);
            $s->execute();
            log_activity('create', 'kas', "Tambah sub-kas: {$name}");
            flash('success', "Sub-kas '{$name}' berhasil ditambahkan.");
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['kas_id'] ?? 0);
        $db->prepare('UPDATE kas_accounts SET is_active = NOT is_active WHERE id=?')
           ->bind_param('i', $id) && $db->execute();
        flash('success', 'Status kas diperbarui.');
    }

    if ($action === 'set_default') {
        $id = (int)($_POST['kas_id'] ?? 0);
        $db->query('UPDATE kas_accounts SET is_default=0');
        $s = $db->prepare('UPDATE kas_accounts SET is_default=1 WHERE id=?');
        $s->bind_param('i', $id); $s->execute();
        flash('success', 'Kas utama diperbarui.');
    }

    redirect(APP_URL . '/pages/kas/index.php');
}

// ── Data ──────────────────────────────────────────────────────────────────
$kas_list = $db->query(
    'SELECT ka.*,
       (SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE kas_account_id=ka.id AND type="pemasukan") AS total_masuk,
       (SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE kas_account_id=ka.id AND type="pengeluaran") AS total_keluar
     FROM kas_accounts ka ORDER BY is_default DESC, name'
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Sub-Kas';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-wallet2 me-1 text-success"></i> Sub-Kas & Rekening</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <div class="row g-3">
      <!-- Daftar Kas -->
      <div class="col-md-8">
        <div class="card">
          <div class="card-header"><i class="bi bi-table me-1"></i> Daftar Sub-Kas</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead><tr>
                  <th>Nama Kas</th><th>Keterangan</th>
                  <th class="text-end">Pemasukan</th>
                  <th class="text-end">Pengeluaran</th>
                  <th class="text-end">Saldo</th>
                  <th>Status</th>
                  <?php if (can('kas.manage')): ?><th>Aksi</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($kas_list as $k):
                  $saldo = (float)$k['total_masuk'] - (float)$k['total_keluar'];
                ?>
                <tr>
                  <td>
                    <?= e($k['name']) ?>
                    <?php if ($k['is_default']): ?>
                      <span class="badge bg-success ms-1">Utama</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted small"><?= e($k['description'] ?: '—') ?></td>
                  <td class="text-end text-success"><?= idr((float)$k['total_masuk']) ?></td>
                  <td class="text-end text-danger"><?= idr((float)$k['total_keluar']) ?></td>
                  <td class="text-end fw-bold <?= $saldo >= 0 ? 'text-success' : 'text-danger' ?>"><?= idr($saldo) ?></td>
                  <td>
                    <span class="badge bg-<?= $k['is_active'] ? 'success' : 'secondary' ?>">
                      <?= $k['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                  </td>
                  <?php if (can('kas.manage')): ?>
                  <td>
                    <div class="d-flex gap-1">
                      <?php if (!$k['is_default']): ?>
                      <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="set_default">
                        <input type="hidden" name="kas_id" value="<?= $k['id'] ?>">
                        <button class="btn btn-sm btn-outline-success py-0 px-2" title="Jadikan Utama">
                          <i class="bi bi-star"></i>
                        </button>
                      </form>
                      <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="toggle">
                        <input type="hidden" name="kas_id" value="<?= $k['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2">
                          <i class="bi bi-<?= $k['is_active'] ? 'pause' : 'play' ?>"></i>
                        </button>
                      </form>
                      <?php endif; ?>
                    </div>
                  </td>
                  <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Tambah Sub-Kas -->
      <?php if (can('kas.manage')): ?>
      <div class="col-md-4">
        <div class="card">
          <div class="card-header"><i class="bi bi-plus-circle me-1 text-success"></i> Tambah Sub-Kas</div>
          <div class="card-body">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="add">
              <div class="mb-2">
                <label class="form-label">Nama Kas</label>
                <input type="text" name="name" class="form-control form-control-sm" required maxlength="100" placeholder="Kas Sosial, Kas Keamanan…">
              </div>
              <div class="mb-2">
                <label class="form-label">Keterangan</label>
                <textarea name="description" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>
              </div>
              <div class="mb-3 form-check">
                <input type="checkbox" name="is_default" class="form-check-input" id="chkDefault">
                <label class="form-check-label" for="chkDefault">Jadikan kas utama</label>
              </div>
              <button class="btn btn-success btn-sm w-100"><i class="bi bi-save me-1"></i> Simpan</button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
