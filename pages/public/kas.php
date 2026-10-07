<?php
// Halaman publik — tidak butuh login
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

$db = db();

$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? date('n'));

// Summary bulan ini
$sum = $db->prepare(
    'SELECT
       COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS total_masuk,
       COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS total_keluar,
       COUNT(*) AS total_entri
     FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?'
);
$sum->bind_param('ii', $f_year, $f_month); $sum->execute();
$summary = $sum->get_result()->fetch_assoc();
$saldo   = (float)$summary['total_masuk'] - (float)$summary['total_keluar'];

// Entri bulan ini (tanpa info pribadi)
$entries = $db->prepare(
    'SELECT type, category, amount, description, trx_date
     FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?
     ORDER BY trx_date DESC, id DESC'
);
$entries->bind_param('ii', $f_year, $f_month); $entries->execute();
$entries = $entries->get_result()->fetch_all(MYSQLI_ASSOC);

// Saldo kumulatif per rekening (kas)
$kas_saldo = $db->query(
    'SELECT ka.name,
       COALESCE(SUM(CASE WHEN cb.type="pemasukan" THEN cb.amount END),0) -
       COALESCE(SUM(CASE WHEN cb.type="pengeluaran" THEN cb.amount END),0) AS saldo
     FROM kas_accounts ka
     LEFT JOIN cash_book cb ON cb.kas_account_id=ka.id
     WHERE ka.is_active=1
     GROUP BY ka.id ORDER BY ka.is_default DESC, ka.name'
)->fetch_all(MYSQLI_ASSOC);

// Polling publik aktif
$now = date('Y-m-d H:i:s');
$polls = $db->prepare(
    'SELECT p.id, p.title, p.ends_at,
       (SELECT COUNT(*) FROM poll_votes WHERE poll_id=p.id) AS total_votes
     FROM polls p WHERE p.is_public=1 AND p.ends_at >= ? ORDER BY p.created_at DESC LIMIT 5'
);
$polls->bind_param('s', $now); $polls->execute();
$polls = $polls->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Transparansi Keuangan — <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { background: #f8f9fa; }
    .hero { background: linear-gradient(135deg,#198754,#0d6efd); color:#fff; padding:2.5rem 1rem; text-align:center; }
    .stat-card { border:none; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  </style>
</head>
<body>

<div class="hero">
  <h2 class="fw-bold mb-1"><i class="bi bi-tree-fill me-2"></i><?= e(APP_NAME) ?></h2>
  <p class="mb-0 opacity-75">Transparansi Keuangan RT — <?= bulan_indo($f_month) . ' ' . $f_year ?></p>
</div>

<div class="container py-4">

  <!-- Filter Periode -->
  <form method="GET" class="d-flex gap-2 mb-4 justify-content-center flex-wrap">
    <select name="year" class="form-select form-select-sm" style="width:auto">
      <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
        <option value="<?= $y ?>" <?= $y===$f_year?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <select name="month" class="form-select form-select-sm" style="width:auto">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m===$f_month?'selected':'' ?>><?= bulan_indo($m) ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn btn-success btn-sm">Tampilkan</button>
  </form>

  <!-- Summary cards -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card stat-card h-100">
        <div class="card-body text-center">
          <div class="text-success fw-bold fs-5"><?= idr((float)$summary['total_masuk']) ?></div>
          <div class="text-muted small">Pemasukan</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card h-100">
        <div class="card-body text-center">
          <div class="text-danger fw-bold fs-5"><?= idr((float)$summary['total_keluar']) ?></div>
          <div class="text-muted small">Pengeluaran</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card h-100">
        <div class="card-body text-center">
          <div class="fw-bold fs-5 <?= $saldo>=0?'text-success':'text-danger' ?>"><?= idr($saldo) ?></div>
          <div class="text-muted small">Saldo Bulan Ini</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card h-100">
        <div class="card-body text-center">
          <div class="fw-bold fs-5"><?= (int)$summary['total_entri'] ?></div>
          <div class="text-muted small">Transaksi</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Saldo per rekening -->
  <?php if (!empty($kas_saldo)): ?>
  <div class="card stat-card mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-wallet2 me-1 text-success"></i> Saldo per Rekening Kas</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Rekening</th><th class="text-end">Saldo</th></tr></thead>
          <tbody>
          <?php foreach ($kas_saldo as $k): ?>
          <tr>
            <td><?= e($k['name']) ?></td>
            <td class="text-end fw-semibold <?= (float)$k['saldo']>=0?'text-success':'text-danger' ?>"><?= idr((float)$k['saldo']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Rincian transaksi -->
  <div class="card stat-card mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-table me-1 text-success"></i>
      Rincian Transaksi — <?= bulan_indo($f_month) . ' ' . $f_year ?>
    </div>
    <div class="card-body p-0">
      <?php if (empty($entries)): ?>
        <div class="p-3 text-muted text-center small">Belum ada transaksi untuk periode ini.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr>
            <th>Tanggal</th><th>Jenis</th><th>Kategori</th>
            <th>Keterangan</th><th class="text-end">Jumlah</th>
          </tr></thead>
          <tbody>
          <?php foreach ($entries as $e): ?>
          <tr>
            <td><?= fmt_date($e['trx_date'],'d M Y') ?></td>
            <td><?= $e['type']==='pemasukan'
              ? '<span class="badge bg-success">Masuk</span>'
              : '<span class="badge bg-danger">Keluar</span>' ?></td>
            <td><?= e($e['category']) ?></td>
            <td class="text-muted small"><?= e($e['description'] ?: '—') ?></td>
            <td class="text-end fw-semibold <?= $e['type']==='pemasukan'?'text-success':'text-danger' ?>">
              <?= $e['type']==='pengeluaran'?'−':'+' ?><?= idr((float)$e['amount']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Polling publik -->
  <?php if (!empty($polls)): ?>
  <div class="card stat-card mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-bar-chart-steps me-1"></i> Polling Aktif</div>
    <div class="card-body">
      <?php foreach ($polls as $p): ?>
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
        <div>
          <strong><?= e($p['title']) ?></strong>
          <div class="text-muted small">Berakhir: <?= fmt_date($p['ends_at'],'d M Y') ?> · <?= $p['total_votes'] ?> suara</div>
        </div>
        <a href="<?= APP_URL ?>/pages/polls/index.php" class="btn btn-sm btn-outline-primary">Vote</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <p class="text-center text-muted small mt-4">
    <i class="bi bi-shield-check me-1"></i>
    Data keuangan ditampilkan tanpa informasi pribadi warga.<br>
    <a href="<?= APP_URL ?>" class="text-muted">Login sebagai pengurus</a>
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
