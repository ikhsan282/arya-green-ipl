<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db = db();

$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? date('n'));

// Per-period summary
$stmt = $db->prepare(
    'SELECT
       COUNT(*)                                                    AS total_bills,
       SUM(b.status="sudah_bayar")                                AS lunas,
       SUM(b.status="belum_bayar")                                AS belum,
       SUM(b.status="terlambat")                                  AS terlambat,
       COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) AS terkumpul,
       COALESCE(SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount END),0) AS tunggakan,
       COALESCE(SUM(b.fine_amount),0)                             AS total_denda
     FROM bills b
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=?'
);
$stmt->bind_param('ii', $f_year, $f_month);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Payment breakdown by method
$mstmt = $db->prepare(
    'SELECT p.payment_method, COUNT(*) AS cnt, SUM(p.amount_paid) AS total
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=? AND p.status="verified"
     GROUP BY p.payment_method'
);
$mstmt->bind_param('ii', $f_year, $f_month);
$mstmt->execute();
$by_method = $mstmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Per-unit detail
$dstmt = $db->prepare(
    'SELECT u.block, u.unit_number, ut.name AS type_name,
            r.name AS resident_name, r.phone,
            b.amount, b.fine_amount, b.total_amount, b.status, b.paid_date
     FROM bills b
     JOIN units u ON u.id=b.unit_id
     JOIN unit_types ut ON ut.id=u.unit_type_id
     LEFT JOIN residents r ON r.id=b.resident_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=?
     ORDER BY u.block, u.unit_number'
);
$dstmt->bind_param('ii', $f_year, $f_month);
$dstmt->execute();
$detail = $dstmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Yearly collection trend (all months current year)
$trend = $db->prepare(
    'SELECT bp.period_month AS m,
            COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) AS terkumpul
     FROM billing_periods bp
     LEFT JOIN bills b ON b.billing_period_id=bp.id
     WHERE bp.period_year=?
     GROUP BY bp.period_month ORDER BY bp.period_month'
);
$trend->bind_param('i', $f_year);
$trend->execute();
$trend_data = $trend->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Laporan Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Laporan Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Filter -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Tahun</label>
            <select name="year" class="form-select form-select-sm">
              <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y===$f_year?'selected':'' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Bulan</label>
            <select name="month" class="form-select form-select-sm">
              <?php for ($m=1; $m<=12; $m++): ?>
                <option value="<?= $m ?>" <?= $m===$f_month?'selected':'' ?>><?= bulan_indo($m) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-auto d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-success"><i class="bi bi-search me-1"></i>Tampilkan</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Cetak
            </button>
            <a href="export.php?year=<?= $f_year ?>&month=<?= $f_month ?>" class="btn btn-sm btn-outline-success">
              <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
            </a>
          </div>
        </form>
      </div>
    </div>

    <!-- Summary -->
    <div class="row g-2 mb-3">
      <?php
      $scards = [
        ['Total Tagihan', $summary['total_bills']??0,  'secondary'],
        ['Lunas',         $summary['lunas']??0,        'success'],
        ['Belum Bayar',   $summary['belum']??0,        'warning'],
        ['Terlambat',     $summary['terlambat']??0,    'danger'],
      ];
      foreach ($scards as [$lbl,$val,$cls]): ?>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="fw-bold fs-4 text-<?= $cls ?>"><?= $val ?></div>
          <div class="text-muted small"><?= $lbl ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header">Ringkasan Keuangan</div>
          <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
              <tr><th>Total Terkumpul</th><td class="text-success fw-bold"><?= idr((float)($summary['terkumpul']??0)) ?></td></tr>
              <tr><th>Total Tunggakan</th><td class="text-danger fw-bold"><?= idr((float)($summary['tunggakan']??0)) ?></td></tr>
              <tr><th>Total Denda</th>   <td class="text-warning"><?= idr((float)($summary['total_denda']??0)) ?></td></tr>
              <tr class="table-success">
                <th>Persentase Lunas</th>
                <td><?php $pct = $summary['total_bills']>0 ? round($summary['lunas']/$summary['total_bills']*100) : 0; ?>
                  <div class="progress" style="height:14px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"><?= $pct ?>%</div></div>
                </td>
              </tr>
            </table>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header">Pembayaran per Metode</div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <thead><tr><th>Metode</th><th>Jumlah</th><th>Total</th></tr></thead>
              <tbody>
              <?php if (empty($by_method)): ?>
                <tr><td colspan="3" class="text-muted text-center py-3">Belum ada data</td></tr>
              <?php else: foreach ($by_method as $m): ?>
                <tr>
                  <td><?= e(ucfirst($m['payment_method'])) ?></td>
                  <td><?= $m['cnt'] ?></td>
                  <td><?= idr((float)$m['total']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-header">Tren Koleksi <?= $f_year ?></div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <thead><tr><th>Bulan</th><th>Terkumpul</th></tr></thead>
              <tbody>
              <?php foreach ($trend_data as $t): ?>
                <tr <?= $t['m']==$f_month?'class="table-success"':'' ?>>
                  <td><?= bulan_indo((int)$t['m']) ?></td>
                  <td><?= idr((float)$t['terkumpul']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Detail table -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-table me-1 text-success"></i>
        Detail Tagihan — <?= bulan_indo($f_month) ?> <?= $f_year ?>
        <span class="badge bg-secondary ms-2"><?= count($detail) ?> unit</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead><tr>
              <th>#</th><th>Unit</th><th>Tipe</th><th>Warga</th><th>No. HP</th>
              <th>IPL</th><th>Denda</th><th>Total</th><th>Status</th><th>Tgl Bayar</th>
            </tr></thead>
            <tbody>
            <?php if (empty($detail)): ?>
              <tr><td colspan="10" class="text-center text-muted py-4">Belum ada tagihan untuk periode ini.</td></tr>
            <?php else: foreach ($detail as $i => $row): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= e($row['block'].'-'.$row['unit_number']) ?></td>
                <td><?= e($row['type_name']) ?></td>
                <td><?= e($row['resident_name'] ?? '-') ?></td>
                <td><?= e($row['phone'] ?? '-') ?></td>
                <td><?= idr((float)$row['amount']) ?></td>
                <td><?= $row['fine_amount']>0 ? '<span class="text-danger">'.idr((float)$row['fine_amount']).'</span>' : '-' ?></td>
                <td><?= idr((float)$row['total_amount']) ?></td>
                <td><?= bill_status_badge($row['status']) ?></td>
                <td><?= $row['paid_date'] ? fmt_date($row['paid_date']) : '-' ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<style>
@media print {
  .sidebar, .topbar, form, .btn { display: none !important; }
  .content-wrapper { margin-left: 0 !important; width: 100% !important; }
}
</style>
