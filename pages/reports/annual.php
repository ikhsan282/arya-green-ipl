<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db     = db();
$f_year = (int)($_GET['year'] ?? date('Y'));

// Koleksi IPL per bulan
$ipl = $db->prepare(
    'SELECT bp.period_month AS m,
            COUNT(b.id)                                                        AS total,
            COALESCE(SUM(b.status="sudah_bayar"),0)                           AS lunas,
            COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) AS terkumpul,
            COALESCE(SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount END),0) AS tunggakan
     FROM billing_periods bp
     LEFT JOIN bills b ON b.billing_period_id=bp.id
     WHERE bp.period_year=?
     GROUP BY bp.period_month ORDER BY bp.period_month'
);
$ipl->bind_param('i', $f_year); $ipl->execute();
$ipl_rows = $ipl->get_result()->fetch_all(MYSQLI_ASSOC);
$ipl_map  = array_column($ipl_rows, null, 'm');

// Kas per bulan
$kas = $db->prepare(
    'SELECT MONTH(trx_date) AS m,
            COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
     FROM cash_book WHERE YEAR(trx_date)=?
     GROUP BY MONTH(trx_date)'
);
$kas->bind_param('i', $f_year); $kas->execute();
$kas_map = array_column($kas->get_result()->fetch_all(MYSQLI_ASSOC), null, 'm');

// Total tahunan
$total_terkumpul = array_sum(array_column($ipl_rows, 'terkumpul'));
$total_tunggakan = array_sum(array_column($ipl_rows, 'tunggakan'));
$total_masuk     = array_sum(array_column($kas_map, 'masuk'));
$total_keluar    = array_sum(array_column($kas_map, 'keluar'));
$saldo_akhir     = $total_masuk - $total_keluar;

// Top 5 unit tunggak terbanyak
$top = $db->prepare(
    'SELECT u.block, u.unit_number, r.name AS resident_name,
            COUNT(b.id) AS jumlah, SUM(b.total_amount) AS total
     FROM bills b
     JOIN units u ON u.id=b.unit_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     LEFT JOIN residents r ON r.id=b.resident_id
     WHERE bp.period_year=? AND b.status IN ("belum_bayar","terlambat")
     GROUP BY u.id ORDER BY total DESC LIMIT 5'
);
$top->bind_param('i', $f_year); $top->execute();
$top_arrears = $top->get_result()->fetch_all(MYSQLI_ASSOC);

// Build chart arrays
$labels = $tc = $tt = $tm = $tk = [];
for ($m = 1; $m <= 12; $m++) {
    $labels[] = substr(bulan_indo($m), 0, 3);
    $tc[]     = (float)($ipl_map[$m]['terkumpul'] ?? 0);
    $tt[]     = (float)($ipl_map[$m]['tunggakan'] ?? 0);
    $tm[]     = (float)($kas_map[$m]['masuk']     ?? 0);
    $tk[]     = (float)($kas_map[$m]['keluar']    ?? 0);
}

$page_title = 'Ringkasan Tahunan ' . $f_year;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-calendar3 me-1 text-success"></i> Ringkasan Tahunan <?= $f_year ?></h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Filter tahun -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-auto">
            <select name="year" class="form-select form-select-sm">
              <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y===$f_year?'selected':'' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-success"><i class="bi bi-search me-1"></i>Tampilkan</button>
          </div>
          <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Cetak
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- KPI cards -->
    <div class="row g-2 mb-3">
      <?php $kpis = [
        ['IPL Terkumpul',  $total_terkumpul, 'success', 'cash-stack'],
        ['Total Tunggakan',$total_tunggakan, 'danger',  'exclamation-triangle'],
        ['Total Masuk Kas',$total_masuk,     'info',    'arrow-down-circle'],
        ['Saldo Kas Akhir',$saldo_akhir,     $saldo_akhir>=0?'success':'danger', 'wallet2'],
      ];
      foreach ($kpis as [$lbl,$val,$cls,$ico]): ?>
      <div class="col-6 col-md-3">
        <div class="card text-center py-3">
          <i class="bi bi-<?= $ico ?> fs-4 text-<?= $cls ?>"></i>
          <div class="fw-bold text-<?= $cls ?> mt-1"><?= idr((float)$val) ?></div>
          <div class="text-muted small"><?= $lbl ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Grafik -->
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Koleksi IPL per Bulan</div>
          <div class="card-body"><canvas id="iplChart" height="200"></canvas></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Arus Kas per Bulan</div>
          <div class="card-body"><canvas id="kasChart" height="200"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Tabel bulanan -->
    <div class="row g-3 mb-3">
      <div class="col-md-8">
        <div class="card">
          <div class="card-header">Rekap per Bulan</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead><tr>
                  <th>Bulan</th><th>Total Unit</th><th>Lunas</th>
                  <th>Terkumpul</th><th>Tunggakan</th><th>Masuk Kas</th><th>Keluar Kas</th>
                </tr></thead>
                <tbody>
                <?php for ($m = 1; $m <= 12; $m++):
                  $r = $ipl_map[$m] ?? ['total'=>0,'lunas'=>0,'terkumpul'=>0,'tunggakan'=>0];
                  $k = $kas_map[$m]  ?? ['masuk'=>0,'keluar'=>0];
                  $pct = $r['total']>0 ? round($r['lunas']/$r['total']*100) : null;
                ?>
                <tr <?= $m==(int)date('n')&&$f_year==(int)date('Y')?'class="table-success fw-semibold"':'' ?>>
                  <td><?= bulan_indo($m) ?></td>
                  <td><?= $r['total'] ?></td>
                  <td>
                    <?= $r['lunas'] ?>
                    <?php if ($pct!==null): ?>
                      <small class="text-muted">(<?= $pct ?>%)</small>
                    <?php endif; ?>
                  </td>
                  <td class="text-success"><?= $r['terkumpul']>0 ? idr((float)$r['terkumpul']) : '-' ?></td>
                  <td class="text-danger"><?= $r['tunggakan']>0 ? idr((float)$r['tunggakan']) : '-' ?></td>
                  <td><?= $k['masuk']>0   ? idr((float)$k['masuk'])  : '-' ?></td>
                  <td><?= $k['keluar']>0  ? idr((float)$k['keluar']) : '-' ?></td>
                </tr>
                <?php endfor; ?>
                <tr class="table-secondary fw-bold">
                  <td>Total</td><td colspan="2"></td>
                  <td class="text-success"><?= idr($total_terkumpul) ?></td>
                  <td class="text-danger"><?= idr($total_tunggakan) ?></td>
                  <td><?= idr($total_masuk) ?></td>
                  <td><?= idr($total_keluar) ?></td>
                </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card">
          <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Top Tunggakan <?= $f_year ?></div>
          <div class="card-body p-0">
            <?php if (empty($top_arrears)): ?>
              <div class="text-center text-muted py-4">Tidak ada tunggakan 🎉</div>
            <?php else: ?>
            <table class="table table-sm mb-0">
              <thead><tr><th>Unit</th><th>Warga</th><th>Bln</th><th>Total</th></tr></thead>
              <tbody>
              <?php foreach ($top_arrears as $a): ?>
              <tr>
                <td><?= e($a['block'].'-'.$a['unit_number']) ?></td>
                <td><?= e($a['resident_name'] ?? '-') ?></td>
                <td class="text-center"><?= $a['jumlah'] ?></td>
                <td class="text-danger"><?= idr((float)$a['total']) ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode($labels) ?>;
const fmt = v => 'Rp ' + (v/1000000).toFixed(1) + 'jt';

new Chart(document.getElementById('iplChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [
      { label: 'Terkumpul', data: <?= json_encode($tc) ?>, backgroundColor: 'rgba(25,135,84,.7)', borderRadius: 3 },
      { label: 'Tunggakan', data: <?= json_encode($tt) ?>, backgroundColor: 'rgba(220,53,69,.5)', borderRadius: 3 }
    ]
  },
  options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: fmt } } } }
});

new Chart(document.getElementById('kasChart'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label: 'Masuk',  data: <?= json_encode($tm) ?>, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.1)', fill: true, tension: .3 },
      { label: 'Keluar', data: <?= json_encode($tk) ?>, borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.1)',  fill: true, tension: .3 }
    ]
  },
  options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: fmt } } } }
});
</script>
<style>
@media print {
  .sidebar, .topbar, form, .btn { display: none !important; }
  .content-wrapper { margin-left: 0 !important; width: 100% !important; }
}
</style>
