<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/functions.php';
require_permission('dashboard.view');

$db   = db();
$user = auth_user();
$now  = date('Y-m-d');
$year = (int)date('Y');
$month= (int)date('n');

$_role = auth_role();
$_uid  = auth_id();

// ── Stats ──────────────────────────────────────────────────────────────────
if ($_role === 'warga') {
    // Warga: data personal saja
    $total_units = 1; // unit warga sendiri
    $total_residents = 1;
    
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN b.status="belum_bayar" THEN 1 ELSE 0 END) AS belum,
                SUM(CASE WHEN b.status="sudah_bayar" THEN 1 ELSE 0 END) AS sudah,
                SUM(CASE WHEN b.status="terlambat"   THEN 1 ELSE 0 END) AS terlambat,
                SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount ELSE 0 END) AS terkumpul,
                SUM(CASE WHEN b.status IN ("belum_bayar","terlambat") THEN b.total_amount ELSE 0 END) AS tunggakan
         FROM bills b
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         LEFT JOIN residents r ON r.id=b.resident_id
         WHERE bp.period_year=? AND bp.period_month=? AND r.user_id=?'
    );
    $stmt->bind_param('iii', $year, $month, $_uid);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    $rp = $db->prepare(
        'SELECT p.*, b.amount, bp.label AS period, u.unit_number, u.block, r.name AS resident_name
         FROM payments p
         JOIN bills b ON b.id=p.bill_id
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         JOIN units u ON u.id=b.unit_id
         LEFT JOIN residents r ON r.id=b.resident_id
         WHERE r.user_id=? ORDER BY p.created_at DESC LIMIT 8'
    );
    $rp->bind_param('i', $_uid);
    $rp->execute();
    $recent_payments = $rp->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $od = $db->prepare(
        'SELECT b.*, u.unit_number, u.block, r.name AS resident_name, bp.label AS period
         FROM bills b
         JOIN units u ON u.id=b.unit_id
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         LEFT JOIN residents r ON r.id=b.resident_id
         WHERE b.status="terlambat" AND r.user_id=?
         ORDER BY b.due_date ASC LIMIT 8'
    );
    $od->bind_param('i', $_uid);
    $od->execute();
    $overdue = $od->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    // Admin: data global
    $total_units = $db->query('SELECT COUNT(*) FROM units')->fetch_row()[0];
    $total_residents = $db->query('SELECT COUNT(*) FROM residents WHERE is_active=1')->fetch_row()[0];
    
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status="belum_bayar" THEN 1 ELSE 0 END) AS belum,
                SUM(CASE WHEN status="sudah_bayar" THEN 1 ELSE 0 END) AS sudah,
                SUM(CASE WHEN status="terlambat"   THEN 1 ELSE 0 END) AS terlambat,
                SUM(CASE WHEN status="sudah_bayar" THEN total_amount ELSE 0 END) AS terkumpul,
                SUM(CASE WHEN status IN ("belum_bayar","terlambat") THEN total_amount ELSE 0 END) AS tunggakan
         FROM bills b
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         WHERE bp.period_year=? AND bp.period_month=?'
    );
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    $recent_payments = $db->query(
        'SELECT p.*, b.amount, bp.label AS period, u.unit_number, u.block, r.name AS resident_name
         FROM payments p
         JOIN bills b ON b.id=p.bill_id
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         JOIN units u ON u.id=b.unit_id
         LEFT JOIN residents r ON r.id=b.resident_id
         ORDER BY p.created_at DESC LIMIT 8'
    )->fetch_all(MYSQLI_ASSOC);
    
    $overdue = $db->query(
        'SELECT b.*, u.unit_number, u.block, r.name AS resident_name, bp.label AS period
         FROM bills b
         JOIN units u ON u.id=b.unit_id
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         LEFT JOIN residents r ON r.id=b.resident_id
         WHERE b.status="terlambat"
         ORDER BY b.due_date ASC LIMIT 8'
    )->fetch_all(MYSQLI_ASSOC);
}

// Yearly trend for chart
$trend_stmt = $db->prepare(
    'SELECT bp.period_month AS m,
            COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) AS terkumpul,
            COALESCE(SUM(CASE WHEN b.status IN ("belum_bayar","terlambat") THEN b.total_amount END),0) AS tunggakan
     FROM billing_periods bp
     LEFT JOIN bills b ON b.billing_period_id=bp.id
     WHERE bp.period_year=?
     GROUP BY bp.period_month ORDER BY bp.period_month'
);
$trend_stmt->bind_param('i', $year);
$trend_stmt->execute();
$trend_rows = $trend_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$trend_map  = array_column($trend_rows, null, 'm');
$chart_labels  = [];
$chart_lunas   = [];
$chart_nunggak = [];
for ($m = 1; $m <= 12; $m++) {
    $chart_labels[]  = substr(bulan_indo($m), 0, 3);
    $chart_lunas[]   = (float)($trend_map[$m]['terkumpul'] ?? 0);
    $chart_nunggak[] = (float)($trend_map[$m]['tunggakan'] ?? 0);
}

$page_title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-wrapper">
  <!-- Topbar -->
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-speedometer2 me-1 text-primary"></i> Dashboard</h6>
    <span class="ms-auto text-muted small"><?= date('l, d F Y') ?></span>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success bg-opacity-10 text-success">
              <i class="bi bi-houses fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Total Unit</div>
              <div class="fw-bold fs-4"><?= $total_units ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
              <i class="bi bi-people fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Total Warga</div>
              <div class="fw-bold fs-4"><?= $total_residents ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
              <i class="bi bi-receipt fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Belum Bayar</div>
              <div class="fw-bold fs-4"><?= (int)($stats['belum'] ?? 0) + (int)($stats['terlambat'] ?? 0) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success bg-opacity-10 text-success">
              <i class="bi bi-cash-coin fs-4"></i>
            </div>
            <div>
              <div class="text-muted small">Terkumpul Bulan Ini</div>
              <div class="fw-bold fs-5"><?= idr((float)($stats['terkumpul'] ?? 0)) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Progress row -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-pie-chart me-1 text-success"></i>
            Status Tagihan — <?= period_label($year, $month) ?>
          </div>
          <div class="card-body">
            <?php
            $total_b   = (int)($stats['total'] ?? 0);
            $sudah_b   = (int)($stats['sudah'] ?? 0);
            $pct       = $total_b > 0 ? round($sudah_b / $total_b * 100) : 0;
            ?>
            <div class="d-flex justify-content-between mb-1 small">
              <span>Lunas <strong><?= $sudah_b ?></strong></span>
              <span>Belum <strong><?= (int)($stats['belum'] ?? 0) ?></strong></span>
              <span>Terlambat <strong><?= (int)($stats['terlambat'] ?? 0) ?></strong></span>
            </div>
            <div class="progress" style="height:18px">
              <div class="progress-bar bg-success" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
            </div>
            <div class="mt-3 d-flex justify-content-between">
              <div>
                <div class="text-muted small">Terkumpul</div>
                <div class="fw-bold text-success"><?= idr((float)($stats['terkumpul'] ?? 0)) ?></div>
              </div>
              <div class="text-end">
                <div class="text-muted small">Tunggakan</div>
                <div class="fw-bold text-danger"><?= idr((float)($stats['tunggakan'] ?? 0)) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-exclamation-triangle me-1 text-danger"></i> Tagihan Terlambat</span>
            <?php if (can('billing.view')): ?>
            <a href="<?= APP_URL ?>/pages/billing/index.php?status=terlambat" class="btn btn-sm btn-outline-danger">Lihat Semua</a>
            <?php endif; ?>
          </div>
          <div class="card-body p-0">
            <?php if (empty($overdue)): ?>
              <div class="p-3 text-muted small">Tidak ada tagihan terlambat.</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead><tr>
                  <th>Unit</th><th>Warga</th><th>Periode</th><th>Total</th>
                </tr></thead>
                <tbody>
                <?php foreach ($overdue as $o): ?>
                <tr>
                  <td><?= e($o['block'] . '-' . $o['unit_number']) ?></td>
                  <td><?= e($o['resident_name'] ?? '-') ?></td>
                  <td><?= e($o['period']) ?></td>
                  <td><?= idr((float)$o['total_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent payments -->
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-1 text-success"></i> Pembayaran Terbaru</span>
        <?php if (can('payments.view')): ?>
        <a href="<?= APP_URL ?>/pages/payments/index.php" class="btn btn-sm btn-outline-success">Lihat Semua</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recent_payments)): ?>
          <div class="p-3 text-muted small">Belum ada pembayaran.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>Tanggal</th><th>Unit</th><th>Warga</th><th>Periode</th>
              <th>Jumlah</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_payments as $rp): ?>
            <tr>
              <td><?= fmt_date($rp['payment_date']) ?></td>
              <td><?= e($rp['block'] . '-' . $rp['unit_number']) ?></td>
              <td><?= e($rp['resident_name'] ?? '-') ?></td>
              <td><?= e($rp['period']) ?></td>
              <td><?= idr((float)$rp['amount_paid']) ?></td>
              <td><?= payment_status_badge($rp['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Chart tren pembayaran -->
    <div class="card mt-3">
      <div class="card-header"><i class="bi bi-bar-chart-line me-1 text-success"></i> Tren Pembayaran <?= $year ?></div>
      <div class="card-body"><canvas id="dashChart" height="90"></canvas></div>
    </div>

  </div><!-- /.main-content -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('dashChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [
      {
        label: 'Terkumpul',
        data: <?= json_encode($chart_lunas) ?>,
        backgroundColor: 'rgba(25,135,84,0.7)',
        borderColor: 'rgba(25,135,84,1)',
        borderWidth: 1
      },
      {
        label: 'Tunggakan',
        data: <?= json_encode($chart_nunggak) ?>,
        backgroundColor: 'rgba(220,53,69,0.6)',
        borderColor: 'rgba(220,53,69,1)',
        borderWidth: 1
      }
    ]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: 'top' } },
    scales: {
      y: {
        ticks: { callback: v => 'Rp ' + (v/1000000).toFixed(1) + 'jt' }
      }
    }
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
