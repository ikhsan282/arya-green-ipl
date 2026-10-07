<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db     = db();
$f_year = (int)($_GET['year'] ?? date('Y'));

// Arus kas bulanan gabungan dari cash_book
$stmt = $db->prepare(
    'SELECT MONTH(trx_date) AS m,
            COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END), 0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END), 0) AS keluar
     FROM cash_book
     WHERE YEAR(trx_date) = ?
     GROUP BY MONTH(trx_date)
     ORDER BY m'
);
$stmt->bind_param('i', $f_year);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$by_month = array_column($rows, null, 'm');

// Total tahunan
$total_masuk  = array_sum(array_column($rows, 'masuk'));
$total_keluar = array_sum(array_column($rows, 'keluar'));

// Breakdown pengeluaran per kategori tahun ini
$cat_stmt = $db->prepare(
    'SELECT category, SUM(amount) AS total
     FROM cash_book
     WHERE type="pengeluaran" AND YEAR(trx_date)=?
     GROUP BY category ORDER BY total DESC LIMIT 10'
);
$cat_stmt->bind_param('i', $f_year);
$cat_stmt->execute();
$by_category = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Top pemasukan per kategori
$inc_stmt = $db->prepare(
    'SELECT category, SUM(amount) AS total
     FROM cash_book
     WHERE type="pemasukan" AND YEAR(trx_date)=?
     GROUP BY category ORDER BY total DESC LIMIT 10'
);
$inc_stmt->bind_param('i', $f_year);
$inc_stmt->execute();
$by_inc_category = $inc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build chart data
$labels  = [];
$d_masuk = [];
$d_keluar= [];
$running = 0;
$d_saldo = [];
for ($m = 1; $m <= 12; $m++) {
    $labels[]   = substr(bulan_indo($m), 0, 3);
    $masuk      = (float)($by_month[$m]['masuk']  ?? 0);
    $keluar     = (float)($by_month[$m]['keluar'] ?? 0);
    $running   += $masuk - $keluar;
    $d_masuk[]  = $masuk;
    $d_keluar[] = $keluar;
    $d_saldo[]  = $running;
}

$page_title = 'Laporan Arus Kas';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-graph-up me-1 text-success"></i> Laporan Arus Kas <?= $f_year ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Filter + Export -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-auto">
            <select name="year" class="form-select form-select-sm">
              <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $f_year ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-auto d-flex gap-2">
            <button class="btn btn-sm btn-success"><i class="bi bi-search me-1"></i>Tampilkan</button>
            <a href="cashflow_export.php?year=<?= $f_year ?>" class="btn btn-sm btn-outline-success">
              <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Cetak
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Summary -->
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-5 text-success"><?= idr($total_masuk) ?></div>
          <div class="text-muted small">Total Pemasukan</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-5 text-danger"><?= idr($total_keluar) ?></div>
          <div class="text-muted small">Total Pengeluaran</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-5 <?= ($total_masuk-$total_keluar)>=0?'text-success':'text-danger' ?>">
            <?= idr($total_masuk - $total_keluar) ?>
          </div>
          <div class="text-muted small">Surplus / Defisit</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-5"><?= $total_masuk > 0 ? round($total_keluar/$total_masuk*100, 1) : 0 ?>%</div>
          <div class="text-muted small">Rasio Pengeluaran</div>
        </div>
      </div>
    </div>

    <!-- Chart -->
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-bar-chart me-1 text-success"></i> Grafik Arus Kas <?= $f_year ?></div>
      <div class="card-body">
        <canvas id="cashflowChart" height="100"></canvas>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <!-- Top pengeluaran -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-arrow-up-circle me-1 text-danger"></i> Top Kategori Pengeluaran</div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <thead><tr><th>Kategori</th><th class="text-end">Total</th></tr></thead>
              <tbody>
              <?php if (empty($by_category)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">Belum ada data</td></tr>
              <?php else: foreach ($by_category as $c): ?>
                <tr>
                  <td><?= e($c['category']) ?></td>
                  <td class="text-end text-danger"><?= idr((float)$c['total']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- Top pemasukan -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-arrow-down-circle me-1 text-success"></i> Top Kategori Pemasukan</div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <thead><tr><th>Kategori</th><th class="text-end">Total</th></tr></thead>
              <tbody>
              <?php if (empty($by_inc_category)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">Belum ada data</td></tr>
              <?php else: foreach ($by_inc_category as $c): ?>
                <tr>
                  <td><?= e($c['category']) ?></td>
                  <td class="text-end text-success"><?= idr((float)$c['total']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabel bulanan -->
    <div class="card">
      <div class="card-header"><i class="bi bi-table me-1 text-success"></i> Rincian Bulanan</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead><tr>
              <th>Bulan</th>
              <th class="text-end text-success">Pemasukan</th>
              <th class="text-end text-danger">Pengeluaran</th>
              <th class="text-end">Surplus/Defisit</th>
              <th class="text-end">Saldo Kumulatif</th>
            </tr></thead>
            <tbody>
            <?php
            $cumulative = 0;
            for ($m = 1; $m <= 12; $m++):
                $masuk  = (float)($by_month[$m]['masuk']  ?? 0);
                $keluar = (float)($by_month[$m]['keluar'] ?? 0);
                $net    = $masuk - $keluar;
                $cumulative += $net;
                $isNow = ($m == date('n') && $f_year == date('Y'));
            ?>
              <tr <?= $isNow ? 'class="table-success fw-semibold"' : '' ?>>
                <td><?= bulan_indo($m) ?><?= $isNow ? ' <span class="badge bg-success ms-1">ini</span>' : '' ?></td>
                <td class="text-end text-success"><?= $masuk > 0 ? idr($masuk) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end text-danger"><?= $keluar > 0 ? idr($keluar) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end <?= $net >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= ($masuk+$keluar) > 0 ? ($net >= 0 ? '+' : '').idr($net) : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-end <?= $cumulative >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= ($masuk+$keluar) > 0 ? idr($cumulative) : '<span class="text-muted">—</span>' ?>
                </td>
              </tr>
            <?php endfor; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
              <tr>
                <td>Total</td>
                <td class="text-end text-success"><?= idr($total_masuk) ?></td>
                <td class="text-end text-danger"><?= idr($total_keluar) ?></td>
                <td class="text-end <?= ($total_masuk-$total_keluar)>=0?'text-success':'text-danger' ?>">
                  <?= idr($total_masuk-$total_keluar) ?>
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('cashflowChart');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      {
        label: 'Pemasukan',
        data: <?= json_encode($d_masuk) ?>,
        backgroundColor: 'rgba(25,135,84,0.7)',
        borderColor: 'rgba(25,135,84,1)',
        borderWidth: 1,
        order: 2
      },
      {
        label: 'Pengeluaran',
        data: <?= json_encode($d_keluar) ?>,
        backgroundColor: 'rgba(220,53,69,0.7)',
        borderColor: 'rgba(220,53,69,1)',
        borderWidth: 1,
        order: 2
      },
      {
        label: 'Saldo Kumulatif',
        data: <?= json_encode($d_saldo) ?>,
        type: 'line',
        borderColor: 'rgba(13,110,253,1)',
        backgroundColor: 'rgba(13,110,253,0.1)',
        borderWidth: 2,
        fill: true,
        tension: 0.3,
        pointRadius: 4,
        order: 1
      }
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { position: 'top' } },
    scales: {
      y: {
        ticks: {
          callback: v => 'Rp ' + (v/1000000).toFixed(1) + 'jt'
        }
      }
    }
  }
});
</script>
<style>
@media print {
  .sidebar, .topbar, form, .btn { display: none !important; }
  .content-wrapper { margin-left: 0 !important; width: 100% !important; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
