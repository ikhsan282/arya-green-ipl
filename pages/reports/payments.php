<?php
/**
 * Rekap Pembayaran — mempertemukan tiga sumber yang sebelumnya tidak pernah dibandingkan:
 *   1. bills          : apa yang seharusnya ditagih
 *   2. payments       : uang yang sudah diterima (semua status, bukan hanya verified)
 *   3. gateway_orders : percobaan bayar QRIS, termasuk yang gagal/kedaluwarsa
 * Selisih antara (2) verified dan (3) PAID = tanda ada pembayaran tercatat 2x atau tidak tercatat.
 */
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db = db();
$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? date('n'));

// ── 1. Tagihan periode ────────────────────────────────────────────────────────
$st = $db->prepare(
    'SELECT COUNT(*) AS total_bills,
            COALESCE(SUM(b.status="sudah_bayar"),0) AS lunas,
            COALESCE(SUM(b.status="belum_bayar"),0) AS belum,
            COALESCE(SUM(b.status="terlambat"),0)   AS terlambat,
            COALESCE(SUM(b.total_amount),0)                                   AS total_tagihan,
            COALESCE(SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount END),0) AS lunas_nilai,
            COALESCE(SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount END),0) AS tunggakan
     FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=?'
);
$st->bind_param('ii', $f_year, $f_month);
$st->execute();
$tagihan = $st->get_result()->fetch_assoc();

// ── 2. Pembayaran menurut status verifikasi (semua status) ────────────────────
$st = $db->prepare(
    'SELECT p.status, COUNT(*) AS cnt, COALESCE(SUM(p.amount_paid),0) AS total
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE bp.period_year=? AND bp.period_month=?
     GROUP BY p.status'
);
$st->bind_param('ii', $f_year, $f_month);
$st->execute();
$by_status = ['pending' => ['cnt' => 0, 'total' => 0.0], 'verified' => ['cnt' => 0, 'total' => 0.0], 'rejected' => ['cnt' => 0, 'total' => 0.0]];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $by_status[$r['status']] = ['cnt' => (int)$r['cnt'], 'total' => (float)$r['total']];
}

// ── 3. Order QRIS menurut status ──────────────────────────────────────────────
// Dicocokkan lewat periode tagihan, BUKAN created_at: order yang dibuat akhir bulan
// bisa melunasi tagihan bulan berikutnya, dan sebaliknya. Order tanpa bill_id
// (tagihan sudah dihapus) tetap dihitung lewat tanggalnya agar tidak hilang.
$st = $db->prepare(
    'SELECT o.status, COUNT(*) AS cnt, COALESCE(SUM(COALESCE(o.total_amount,o.amount)),0) AS total
     FROM payment_gateway_orders o
     LEFT JOIN bills b ON b.id=o.bill_id
     LEFT JOIN billing_periods bp ON bp.id=b.billing_period_id
     WHERE (bp.period_year=? AND bp.period_month=?)
        OR (o.bill_id IS NULL AND YEAR(o.created_at)=? AND MONTH(o.created_at)=?)
     GROUP BY o.status'
);
$st->bind_param('iiii', $f_year, $f_month, $f_year, $f_month);
$st->execute();
$orders = ['PENDING' => ['cnt' => 0, 'total' => 0.0], 'PAID' => ['cnt' => 0, 'total' => 0.0],
           'EXPIRED' => ['cnt' => 0, 'total' => 0.0], 'FAILED' => ['cnt' => 0, 'total' => 0.0]];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $orders[$r['status']] = ['cnt' => (int)$r['cnt'], 'total' => (float)$r['total']];
}

// ── 4. Per metode bayar (verified saja, dipisah tunai vs QRIS) ────────────────
// QRIS dikenali dari prefix code metode (mis. qris-gopay) — gateway_finalize menyimpannya begitu.
$st = $db->prepare(
    'SELECT COALESCE(pm.name, p.payment_method, "Tidak diketahui") AS method_name,
            COALESCE(pm.code,"") LIKE "qris-%" AS is_qris,
            COUNT(*) AS cnt, COALESCE(SUM(p.amount_paid),0) AS total
     FROM payments p
     JOIN bills b ON b.id=p.bill_id
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
     WHERE bp.period_year=? AND bp.period_month=? AND p.status="verified"
     GROUP BY method_name, is_qris ORDER BY total DESC'
);
$st->bind_param('ii', $f_year, $f_month);
$st->execute();
$by_method = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// ── 5. Kesimpulan: cocok atau tidak ───────────────────────────────────────────
// Order QRIS hanya bisa dibandingkan dengan pembayaran lewat metode QRIS.
$verified   = $by_status['verified']['total'];
$pending_pm = $by_status['pending']['total'];
$paid_orders = $orders['PAID']['total'];
$qris_by_method = 0.0;
foreach ($by_method as $m) if ((int)$m['is_qris'] === 1) $qris_by_method += (float)$m['total'];
$order_gap  = round($paid_orders - $qris_by_method, 2);   // order QRIS PAID vs pembayaran QRIS terverifikasi
$pct        = (float)$tagihan['total_tagihan'] > 0 ? ($tagihan['lunas_nilai'] / (float)$tagihan['total_tagihan']) * 100 : 0;

$page_title = 'Rekap Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold">Rekap Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <form class="card mb-3" method="get">
      <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
        <div><label class="form-label small mb-0">Bulan</label>
          <select name="month" class="form-select form-select-sm">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m === $f_month ? 'selected' : '' ?>><?= bulan_indo($m) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div><label class="form-label small mb-0">Tahun</label>
          <input type="number" name="year" class="form-control form-control-sm" style="width:100px" value="<?= $f_year ?>">
        </div>
        <button class="btn btn-sm btn-primary">Tampilkan</button>
        <a class="btn btn-sm btn-outline-secondary" href="export_summary.php?year=<?= $f_year ?>&month=<?= $f_month ?>">Export CSV</a>
      </div>
    </form>

    <?php if ($order_gap != 0): ?>
      <div class="alert alert-warning py-2">
        <strong>Selisih <?= idr(abs($order_gap)) ?>.</strong>
        Order QRIS lunas (<?= idr($paid_orders) ?>) tidak sama dengan pembayaran QRIS terverifikasi (<?= idr($qris_by_method) ?>).
        Periksa daftar di bawah — mungkin ada pembayaran QRIS tercatat ganda, atau order lunas yang belum tercatat sebagai pembayaran.
      </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Total tagihan</div><div class="fs-5 fw-semibold"><?= idr((float)$tagihan['total_tagihan']) ?></div>
        <div class="small text-muted"><?= (int)$tagihan['total_bills'] ?> tagihan</div>
      </div></div></div>
      <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Terbayar lunas</div><div class="fs-5 fw-semibold text-success"><?= idr((float)$tagihan['lunas_nilai']) ?></div>
        <div class="small text-muted"><?= (int)$tagihan['lunas'] ?> tagihan · <?= number_format($pct, 1, ',', '.') ?>%</div>
      </div></div></div>
      <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Tunggakan</div><div class="fs-5 fw-semibold text-danger"><?= idr((float)$tagihan['tunggakan']) ?></div>
        <div class="small text-muted"><?= (int)$tagihan['belum'] ?> belum · <?= (int)$tagihan['terlambat'] ?> terlambat</div>
      </div></div></div>
      <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Menunggu verifikasi</div><div class="fs-5 fw-semibold text-warning"><?= idr($pending_pm) ?></div>
        <div class="small text-muted"><?= $by_status['pending']['cnt'] ?> pembayaran belum diverifikasi</div>
      </div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card h-100"><div class="card-header">Pembayaran menurut status</div>
          <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Status</th><th class="text-end">Jumlah</th><th class="text-end">Nominal</th></tr></thead>
            <tbody>
            <?php foreach (['verified' => ['Terverifikasi', 'success'], 'pending' => ['Menunggu verifikasi', 'warning'], 'rejected' => ['Ditolak', 'danger']] as $k => [$lbl, $cl]): ?>
              <tr><td><span class="badge bg-<?= $cl ?>"><?= $lbl ?></span></td>
                  <td class="text-end"><?= $by_status[$k]['cnt'] ?></td>
                  <td class="text-end"><?= idr($by_status[$k]['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card h-100"><div class="card-header">Order QRIS</div>
          <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Status</th><th class="text-end">Jumlah</th><th class="text-end">Nominal</th></tr></thead>
            <tbody>
            <?php foreach (['PAID' => ['Lunas', 'success'], 'PENDING' => ['Menunggu bayar', 'secondary'], 'EXPIRED' => ['Kedaluwarsa', 'warning'], 'FAILED' => ['Gagal', 'danger']] as $k => [$lbl, $cl]): ?>
              <tr><td><span class="badge bg-<?= $cl ?>"><?= $lbl ?></span></td>
                  <td class="text-end"><?= $orders[$k]['cnt'] ?></td>
                  <td class="text-end"><?= idr($orders[$k]['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="card-body py-2 small text-muted">
            Order kedaluwarsa &amp; gagal bukan kerugian, tapi menunjukkan warga yang gagal bayar — perlu ditindaklanjuti.
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card"><div class="card-header">Per metode bayar (terverifikasi)</div>
          <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Metode</th><th>Jalur</th><th class="text-end">Transaksi</th><th class="text-end">Nominal</th></tr></thead>
            <tbody>
            <?php if (!$by_method): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Belum ada pembayaran terverifikasi pada periode ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($by_method as $m): ?>
              <tr><td><?= e($m['method_name']) ?></td>
                  <td><?= $m['is_qris'] ? '<span class="badge bg-info">QRIS</span>' : '<span class="badge bg-secondary">non-QRIS</span>' ?></td>
                  <td class="text-end"><?= (int)$m['cnt'] ?></td>
                  <td class="text-end"><?= idr((float)$m['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
