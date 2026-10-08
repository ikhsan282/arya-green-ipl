<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/email_notifications.php';
require_permission('billing.view');

$db = db();

// ── Hapus tagihan ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('billing.generate');

    $bill_id = (int)($_POST['bill_id'] ?? 0);
    if (!$bill_id) { flash('error', 'ID tagihan tidak valid.'); redirect(APP_URL.'/pages/billing/index.php'); }

    // Cek apakah ada payment terkait
    $chk = $db->prepare('SELECT COUNT(*) FROM payments WHERE bill_id=?');
    $chk->bind_param('i', $bill_id);
    $chk->execute();
    $cnt = $chk->get_result()->fetch_row()[0];

    if ($cnt > 0) {
        flash('error', 'Tagihan tidak dapat dihapus karena sudah ada pembayaran terkait.');
        redirect(APP_URL.'/pages/billing/index.php');
    }

    // Safe hapus
    $del = $db->prepare('DELETE FROM bills WHERE id=?');
    $del->bind_param('i', $bill_id);
    $del->execute();
    log_activity('delete', 'billing', "Deleted bill #{$bill_id}");
    flash('success', 'Tagihan berhasil dihapus.');
    redirect(APP_URL.'/pages/billing/index.php');
}

// ── Generate tagihan ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'generate') {
    csrf_verify();
    require_permission('billing.generate');

    $year  = (int)$_POST['gen_year'];
    $month = (int)$_POST['gen_month'];
    $due   = clean($_POST['due_date'] ?? '');

    if ($year < 2020 || $month < 1 || $month > 12 || !$due) {
        flash('error', 'Periode atau tanggal jatuh tempo tidak valid.');
        redirect(APP_URL . '/pages/billing/index.php');
    }

    $label = period_label($year, $month);

    // Upsert billing period
    $stmt = $db->prepare(
        'INSERT INTO billing_periods (period_year, period_month, label, due_date)
         VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE due_date=VALUES(due_date), label=VALUES(label)'
    );
    $stmt->bind_param('iiss', $year, $month, $label, $due);
    $stmt->execute();
    $period_id = $db->insert_id ?: (function() use ($db, $year, $month) {
        $s = $db->prepare('SELECT id FROM billing_periods WHERE period_year=? AND period_month=?');
        $s->bind_param('ii', $year, $month);
        $s->execute();
        return $s->get_result()->fetch_row()[0];
    })();

    // Get all active units with their IPL amount
    $units_res = $db->query(
        'SELECT u.id AS unit_id, ut.ipl_amount,
                (SELECT id FROM residents WHERE unit_id=u.id AND is_active=1 ORDER BY id LIMIT 1) AS resident_id
         FROM units u JOIN unit_types ut ON ut.id = u.unit_type_id
         WHERE u.status = "dihuni"'
    );
    $generated = 0; $skipped = 0;
    while ($u = $units_res->fetch_assoc()) {
        // Skip if bill already exists
        $chk = $db->prepare('SELECT id FROM bills WHERE billing_period_id=? AND unit_id=?');
        $chk->bind_param('ii', $period_id, $u['unit_id']);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) { $skipped++; continue; }

        $amount = (float)$u['ipl_amount'];
        $ins = $db->prepare(
            'INSERT INTO bills (billing_period_id,unit_id,resident_id,amount,fine_amount,total_amount,due_date)
             VALUES (?,?,?,?,0,?,?)'
        );
        $ins->bind_param('iiidds', $period_id, $u['unit_id'], $u['resident_id'], $amount, $amount, $due);
        $ins->execute();
        $generated++;
    }
    log_activity('generate', 'billing', "Generated {$generated} bills for {$label}");
    flash('success', "Berhasil generate {$generated} tagihan untuk {$label}." . ($skipped ? " {$skipped} sudah ada, dilewati." : ''));
    redirect(APP_URL . '/pages/billing/index.php');
}

// ── Update overdue status ──────────────────────────────────────────────────
$db->query(
    'UPDATE bills SET status="terlambat"
     WHERE status="belum_bayar" AND due_date < CURDATE()'
);

// ── Filters ────────────────────────────────────────────────────────────────
$f_year   = (int)($_GET['year']   ?? date('Y'));
$f_month  = (int)($_GET['month']  ?? date('n'));
$f_status = clean($_GET['status'] ?? '');
$search   = clean($_GET['q']      ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 15;

// Warga hanya lihat tagihan unit sendiri
$_role = auth_role();
$_uid  = auth_id();

$where  = ['bp.period_year=?', 'bp.period_month=?'];
$params = [$f_year, $f_month];
$types  = 'ii';

if ($_role === 'warga') {
    $where[] = 'r.user_id=?';
    $params[] = $_uid;
    $types .= 'i';
}
if ($f_status) { $where[] = 'b.status=?'; $params[] = $f_status; $types .= 's'; }
if ($search)   {
    $where[] = '(u.unit_number LIKE ? OR u.block LIKE ? OR r.name LIKE ?)';
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
$wsql = implode(' AND ', $where);

$cnt = $db->prepare(
    "SELECT COUNT(*) FROM bills b
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     WHERE {$wsql}"
);
$cnt->bind_param($types, ...$params);
$cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag   = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT b.*, bp.label AS period, u.unit_number, u.block,
            r.name AS resident_name, r.phone AS resident_phone
     FROM bills b
     JOIN billing_periods bp ON bp.id=b.billing_period_id
     JOIN units u ON u.id=b.unit_id
     LEFT JOIN residents r ON r.id=b.resident_id
     WHERE {$wsql} ORDER BY u.block, u.unit_number
     LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types . 'ii', ...$fp);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary for period
if ($_role === 'warga') {
    $sum = $db->prepare(
        'SELECT SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount ELSE 0 END) AS terkumpul,
                SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount ELSE 0 END) AS tunggakan,
                COUNT(*) AS total,
                SUM(b.status="sudah_bayar") AS lunas,
                SUM(b.status="belum_bayar") AS belum,
                SUM(b.status="terlambat") AS terlambat
         FROM bills b
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         LEFT JOIN residents r ON r.id=b.resident_id
         WHERE bp.period_year=? AND bp.period_month=? AND r.user_id=?'
    );
    $sum->bind_param('iii', $f_year, $f_month, $_uid);
} else {
    $sum = $db->prepare(
        'SELECT SUM(CASE WHEN b.status="sudah_bayar" THEN b.total_amount ELSE 0 END) AS terkumpul,
                SUM(CASE WHEN b.status!="sudah_bayar" THEN b.total_amount ELSE 0 END) AS tunggakan,
                COUNT(*) AS total,
                SUM(b.status="sudah_bayar") AS lunas,
                SUM(b.status="belum_bayar") AS belum,
                SUM(b.status="terlambat") AS terlambat
         FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id
         WHERE bp.period_year=? AND bp.period_month=?'
    );
    $sum->bind_param('ii', $f_year, $f_month);
}
$sum->execute();
$summary = $sum->get_result()->fetch_assoc();

$page_title = 'Tagihan IPL';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-receipt me-1 text-warning"></i> Tagihan IPL</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Generate form -->
    <?php if (can('billing.generate')): ?>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-lightning-charge me-1 text-warning"></i> Generate Tagihan Baru</div>
      <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="generate">
          <div class="col-6 col-md-2">
            <label class="form-label">Tahun</label>
            <input type="number" name="gen_year" class="form-control form-control-sm"
                   value="<?= date('Y') ?>" min="2020" max="2099" required>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Bulan</label>
            <select name="gen_month" class="form-select form-select-sm" required>
              <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $m==(int)date('n')?'selected':'' ?>><?= bulan_indo($m) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Jatuh Tempo</label>
            <input type="date" name="due_date" class="form-control form-control-sm"
                   value="<?= date('Y-m-') . '20' ?>" required>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-warning btn-sm"
                    onclick="return confirm('Generate tagihan untuk periode ini?')">
              <i class="bi bi-lightning-charge me-1"></i> Generate
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="row g-2 mb-3">
      <?php
      $cards = [
        ['Total',     $summary['total']??0,     'secondary','receipt'],
        ['Lunas',     $summary['lunas']??0,     'success',  'check-circle'],
        ['Belum',     $summary['belum']??0,     'warning',  'clock'],
        ['Terlambat', $summary['terlambat']??0, 'danger',   'exclamation-triangle'],
      ];
      foreach ($cards as [$lbl,$val,$cls,$icon]):?>
      <div class="col-6 col-md-3">
        <div class="card text-center py-2">
          <div class="text-<?= $cls ?> fw-bold fs-4"><?= $val ?></div>
          <div class="text-muted small"><i class="bi bi-<?= $icon ?> me-1"></i><?= $lbl ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-receipt me-1 text-success"></i>
          Tagihan — <?= bulan_indo($f_month) ?> <?= $f_year ?>
          <small class="text-success ms-2">Terkumpul: <?= idr((float)($summary['terkumpul']??0)) ?></small>
          <small class="text-danger ms-2">Tunggakan: <?= idr((float)($summary['tunggakan']??0)) ?></small>
        </span>
        <?php if (can('billing.send_reminder')): ?>
        <a href="<?= APP_URL ?>/pages/billing/send_reminders.php"
           class="btn btn-sm btn-outline-warning">
          <i class="bi bi-envelope me-1"></i> Kirim Reminder
        </a>
        <?php endif; ?>
      </div>
      <div class="card-body border-bottom">
        <form method="GET" class="row g-2">
          <div class="col-6 col-md-2">
            <select name="year" class="form-select form-select-sm">
              <?php for ($y=date('Y');$y>=2020;$y--): ?>
                <option value="<?= $y ?>" <?= $y===$f_year?'selected':'' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="month" class="form-select form-select-sm">
              <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $m===$f_month?'selected':'' ?>><?= bulan_indo($m) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="status" class="form-select form-select-sm">
              <option value="">Semua Status</option>
              <option value="belum_bayar" <?= $f_status==='belum_bayar'?'selected':'' ?>>Belum Bayar</option>
              <option value="sudah_bayar" <?= $f_status==='sudah_bayar'?'selected':'' ?>>Sudah Bayar</option>
              <option value="terlambat"   <?= $f_status==='terlambat'  ?'selected':'' ?>>Terlambat</option>
            </select>
          </div>
          <div class="col-md-3">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari unit/warga..." value="<?= e($search) ?>">
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
              <th>#</th><th>Unit</th><th>Warga</th><th>IPL</th>
              <th>Denda</th><th>Total</th><th>Jatuh Tempo</th><th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($bills)): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada tagihan. Silakan generate terlebih dahulu.</td></tr>
            <?php else: foreach ($bills as $i => $b): ?>
              <tr>
                <td><?= $pag['offset']+$i+1 ?></td>
                <td><strong><?= e($b['block'].'-'.$b['unit_number']) ?></strong></td>
                <td><?= e($b['resident_name'] ?? '-') ?></td>
                <td><?= idr((float)$b['amount']) ?></td>
                <td><?= $b['fine_amount']>0 ? '<span class="text-danger">'.idr((float)$b['fine_amount']).'</span>' : '-' ?></td>
                <td><strong><?= idr((float)$b['total_amount']) ?></strong></td>
                <td><?= fmt_date($b['due_date']) ?></td>
                <td><?= bill_status_badge($b['status']) ?></td>
                <td>
                  <?php if (can('payments.create') && $b['status'] !== 'sudah_bayar'): ?>
                    <a href="<?= APP_URL ?>/pages/payments/form.php?bill_id=<?= $b['id'] ?>"
                       class="btn btn-sm btn-outline-success py-0 px-2">
                      <i class="bi bi-cash-coin"></i>
                    </a>
                  <?php endif; ?>
                  <?php if (can('billing.edit')): ?>
                    <a href="detail.php?id=<?= $b['id'] ?>"
                       class="btn btn-sm btn-outline-secondary py-0 px-2">
                      <i class="bi bi-eye"></i>
                    </a>
                  <?php endif; ?>
                  <?php if (can('billing.generate') && $b['status'] === 'belum_bayar'): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Hapus tagihan ini? Aksi tidak dapat dibatalkan.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="delete">
                      <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus tagihan">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['total_pages']>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">Menampilkan <?= count($bills) ?> dari <?= $total ?> tagihan</small>
        <?= render_pagination($pag, "index.php?year={$f_year}&month={$f_month}&status=".urlencode($f_status)."&q=".urlencode($search)) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
