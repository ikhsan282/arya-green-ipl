<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('cashbook.view');

$db = db();

// ── Handle POST (tambah / hapus) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('cashbook.manage');

    $action = clean($_POST['_action'] ?? '');

    if ($action === 'add') {
        $type  = in_array($_POST['type'] ?? '', ['pemasukan','pengeluaran'])
                   ? $_POST['type'] : null;
        $cat   = clean($_POST['category']    ?? '');
        $amt   = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $desc  = clean($_POST['description'] ?? '');
        $date  = clean($_POST['trx_date']    ?? '');
        $uid   = auth_id();

        if (!$type || !$cat || $amt <= 0 || !$date) {
            flash('error', 'Semua kolom wajib diisi dengan benar.');
        } else {
            $stmt = $db->prepare(
                'INSERT INTO cash_book (type, category, amount, description, trx_date, created_by)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->bind_param('ssdssi', $type, $cat, $amt, $desc, $date, $uid);
            $stmt->execute();
            log_activity('create', 'cashbook', "Tambah {$type}: {$cat} " . idr($amt));
            flash('success', 'Entri kas berhasil disimpan.');
        }
        redirect(APP_URL . '/pages/cashbook/index.php?' . http_build_query([
            'year' => date('Y', strtotime($date ?: 'now')),
            'month'=> date('n', strtotime($date ?: 'now')),
        ]));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['del_id'] ?? 0);
        // Jangan hapus entri yang berasal dari sistem IPL
        $chk = $db->prepare('SELECT ref_payment_id FROM cash_book WHERE id=?');
        $chk->bind_param('i', $id);
        $chk->execute();
        $row = $chk->get_result()->fetch_row();
        if (!$row) { flash('error', 'Entri tidak ditemukan.'); }
        elseif ($row[0]) { flash('error', 'Entri otomatis dari IPL tidak bisa dihapus manual.'); }
        else {
            $del = $db->prepare('DELETE FROM cash_book WHERE id=?');
            $del->bind_param('i', $id);
            $del->execute();
            log_activity('delete', 'cashbook', "Hapus entri id={$id}");
            flash('success', 'Entri berhasil dihapus.');
        }
        redirect(APP_URL . '/pages/cashbook/index.php');
    }
}

// ── Filters ────────────────────────────────────────────────────────────────
$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? date('n'));
$f_type  = clean($_GET['type']  ?? '');
$search  = clean($_GET['q']     ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 20;

$where  = ['YEAR(trx_date)=?', 'MONTH(trx_date)=?'];
$params = [$f_year, $f_month];
$types  = 'ii';

if ($f_type)  { $where[] = 'type=?';           $params[] = $f_type;  $types .= 's'; }
if ($search)  { $where[] = '(category LIKE ? OR description LIKE ?)';
                $like = "%{$search}%";
                $params[] = $like; $params[] = $like; $types .= 'ss'; }
$wsql = implode(' AND ', $where);

// Total count
$cnt = $db->prepare("SELECT COUNT(*) FROM cash_book WHERE {$wsql}");
$cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

// Rows
$stmt = $db->prepare(
    "SELECT cb.*, u.name AS creator_name
     FROM cash_book cb
     LEFT JOIN users u ON u.id = cb.created_by
     WHERE {$wsql}
     ORDER BY cb.trx_date DESC, cb.id DESC
     LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types . 'ii', ...$fp);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary for period (always full month, ignore search/type filter)
$sum = $db->prepare(
    'SELECT
       COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END), 0) AS total_masuk,
       COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END), 0) AS total_keluar,
       COUNT(*) AS total_entri
     FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?'
);
$sum->bind_param('ii', $f_year, $f_month);
$sum->execute();
$summary = $sum->get_result()->fetch_assoc();
$saldo = (float)$summary['total_masuk'] - (float)$summary['total_keluar'];

// Yearly balance trend
$trend = $db->prepare(
    'SELECT MONTH(trx_date) AS m,
            COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
     FROM cash_book WHERE YEAR(trx_date)=?
     GROUP BY MONTH(trx_date) ORDER BY m'
);
$trend->bind_param('i', $f_year);
$trend->execute();
$trend_rows = $trend->get_result()->fetch_all(MYSQLI_ASSOC);

// Categories for datalist
$cats = $db->query(
    'SELECT DISTINCT category FROM cash_book ORDER BY category'
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Buku Kas';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-journal-text me-1 text-success"></i> Buku Kas</h6>
    <span class="ms-auto text-muted small"><?= period_label($f_year, $f_month) ?></span>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success bg-opacity-10 text-success">
              <i class="bi bi-arrow-down-circle fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Pemasukan</div>
              <div class="fw-bold"><?= idr((float)$summary['total_masuk']) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
              <i class="bi bi-arrow-up-circle fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Pengeluaran</div>
              <div class="fw-bold"><?= idr((float)$summary['total_keluar']) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon <?= $saldo >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' ?>">
              <i class="bi bi-wallet2 fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Saldo Bulan Ini</div>
              <div class="fw-bold <?= $saldo >= 0 ? 'text-success' : 'text-danger' ?>"><?= idr($saldo) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
              <i class="bi bi-list-ul fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Total Entri</div>
              <div class="fw-bold fs-4"><?= (int)$summary['total_entri'] ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">

      <!-- Tambah Entri -->
      <?php if (can('cashbook.manage')): ?>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-plus-circle me-1 text-success"></i> Tambah Entri</div>
          <div class="card-body">
            <form method="POST" id="formAddEntry">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="add">
              <div class="mb-2">
                <label class="form-label">Jenis</label>
                <div class="d-flex gap-3">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="type" id="typeMasuk" value="pemasukan" checked>
                    <label class="form-check-label text-success fw-semibold" for="typeMasuk">Pemasukan</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="type" id="typeKeluar" value="pengeluaran">
                    <label class="form-check-label text-danger fw-semibold" for="typeKeluar">Pengeluaran</label>
                  </div>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Kategori</label>
                <input type="text" name="category" class="form-control form-control-sm"
                       list="catList" placeholder="e.g. Listrik, IPL, ATK…" required maxlength="100">
                <datalist id="catList">
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= e($c['category']) ?>">
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="mb-2">
                <label class="form-label">Jumlah (Rp)</label>
                <input type="text" name="amount" id="amountInput" class="form-control form-control-sm"
                       placeholder="0" required inputmode="numeric" data-rupiah>
              </div>
              <div class="mb-2">
                <label class="form-label">Tanggal</label>
                <input type="date" name="trx_date" class="form-control form-control-sm"
                       value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Keterangan <small class="text-muted">(opsional)</small></label>
                <textarea name="description" class="form-control form-control-sm" rows="2"
                          maxlength="500"></textarea>
              </div>
              <button type="submit" class="btn btn-success btn-sm w-100">
                <i class="bi bi-save me-1"></i> Simpan Entri
              </button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tren tahunan -->
      <div class="col-lg-<?= can('cashbook.manage') ? '8' : '12' ?>">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-bar-chart-line me-1 text-success"></i> Tren Kas <?= $f_year ?></div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead><tr>
                  <th>Bulan</th>
                  <th class="text-success">Pemasukan</th>
                  <th class="text-danger">Pengeluaran</th>
                  <th>Saldo</th>
                </tr></thead>
                <tbody>
                <?php
                $trend_map = array_column($trend_rows, null, 'm');
                $running = 0;
                for ($m = 1; $m <= 12; $m++):
                    $row    = $trend_map[$m] ?? ['masuk' => 0, 'keluar' => 0];
                    $masuk  = (float)$row['masuk'];
                    $keluar = (float)$row['keluar'];
                    $net    = $masuk - $keluar;
                    $running += $net;
                    $isNow  = ($m == $f_month);
                ?>
                <tr <?= $isNow ? 'class="table-success fw-semibold"' : '' ?>>
                  <td><?= bulan_indo($m) ?><?= $isNow ? ' <span class="badge bg-success ms-1">ini</span>' : '' ?></td>
                  <td class="text-success"><?= $masuk > 0 ? idr($masuk) : '<span class="text-muted">—</span>' ?></td>
                  <td class="text-danger"><?= $keluar > 0 ? idr($keluar) : '<span class="text-muted">—</span>' ?></td>
                  <td class="<?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($masuk + $keluar) > 0 ? idr($net) : '<span class="text-muted">—</span>' ?></td>
                </tr>
                <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Daftar Entri -->
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-table me-1 text-success"></i>
          Rincian — <?= bulan_indo($f_month) ?> <?= $f_year ?>
          <span class="badge bg-secondary ms-1"><?= $total ?></span>
        </span>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-printer me-1"></i>Cetak
        </button>
      </div>

      <!-- Filter bar -->
      <div class="card-body border-bottom py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-6 col-md-2">
            <select name="year" class="form-select form-select-sm">
              <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $f_year ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="month" class="form-select form-select-sm">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $f_month ? 'selected' : '' ?>><?= bulan_indo($m) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="type" class="form-select form-select-sm">
              <option value="">Semua Jenis</option>
              <option value="pemasukan"   <?= $f_type === 'pemasukan'   ? 'selected' : '' ?>>Pemasukan</option>
              <option value="pengeluaran" <?= $f_type === 'pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
            </select>
          </div>
          <div class="col-md-3">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari kategori/keterangan…" value="<?= e($search) ?>">
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-outline-success"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>#</th>
              <th>Tanggal</th>
              <th>Jenis</th>
              <th>Kategori</th>
              <th>Keterangan</th>
              <th class="text-end">Jumlah</th>
              <th>Oleh</th>
              <?php if (can('cashbook.manage')): ?><th></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($entries)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Belum ada entri kas untuk periode ini.</td></tr>
            <?php else: foreach ($entries as $i => $row): ?>
              <tr>
                <td><?= $pag['offset'] + $i + 1 ?></td>
                <td><?= fmt_date($row['trx_date']) ?></td>
                <td>
                  <?php if ($row['type'] === 'pemasukan'): ?>
                    <span class="badge bg-success"><i class="bi bi-arrow-down-short"></i> Masuk</span>
                  <?php else: ?>
                    <span class="badge bg-danger"><i class="bi bi-arrow-up-short"></i> Keluar</span>
                  <?php endif; ?>
                </td>
                <td><?= e($row['category']) ?>
                  <?php if ($row['ref_payment_id']): ?>
                    <i class="bi bi-link-45deg text-muted" title="Dari sistem IPL"></i>
                  <?php endif; ?>
                </td>
                <td class="text-muted small"><?= e($row['description'] ?: '—') ?></td>
                <td class="text-end fw-semibold <?= $row['type'] === 'pemasukan' ? 'text-success' : 'text-danger' ?>">
                  <?= $row['type'] === 'pengeluaran' ? '−' : '+' ?><?= idr((float)$row['amount']) ?>
                </td>
                <td class="small text-muted"><?= e($row['creator_name'] ?? '—') ?></td>
                <?php if (can('cashbook.manage')): ?>
                <td>
                  <?php if (!$row['ref_payment_id']): ?>
                  <form method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="del_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                            data-confirm="Hapus entri ini?">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
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
      <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">Menampilkan <?= count($entries) ?> dari <?= $total ?> entri</small>
        <?= render_pagination($pag,
            'index.php?year='.$f_year.'&month='.$f_month
            .'&type='.urlencode($f_type).'&q='.urlencode($search)) ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /.main-content -->
</div><!-- /.content-wrapper -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<style>
@media print {
  .sidebar, .topbar, form, .btn, .card-footer { display: none !important; }
  .content-wrapper { margin-left: 0 !important; width: 100% !important; }
}
</style>
