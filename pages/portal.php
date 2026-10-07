<?php
// Portal Warga — PWA entry point
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/functions.php';

$db  = db();
$uid = auth_id();

// Cari resident linked ke user ini
$res = $db->prepare(
    'SELECT r.*, u.unit_number, u.block, ut.name AS unit_type, ut.ipl_amount
     FROM residents r
     JOIN units u ON u.id=r.unit_id
     JOIN unit_types ut ON ut.id=u.unit_type_id
     WHERE r.user_id=? AND r.is_active=1 LIMIT 1'
);
$res->bind_param('i', $uid); $res->execute();
$resident = $res->get_result()->fetch_assoc();

// Tagihan belum lunas
$bills = [];
if ($resident) {
    $bs = $db->prepare(
        'SELECT b.*, bp.label AS period, bp.due_date
         FROM bills b
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         WHERE b.resident_id=? AND b.status IN ("belum_bayar","terlambat")
         ORDER BY bp.period_year DESC, bp.period_month DESC'
    );
    $bs->bind_param('i', $resident['id']); $bs->execute();
    $bills = $bs->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Riwayat pembayaran (5 terakhir)
$history = [];
if ($resident) {
    $hs = $db->prepare(
        'SELECT p.*, bp.label AS period
         FROM payments p
         JOIN bills b ON b.id=p.bill_id
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         WHERE b.resident_id=?
         ORDER BY p.created_at DESC LIMIT 5'
    );
    $hs->bind_param('i', $resident['id']); $hs->execute();
    $history = $hs->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Polling aktif
$now = date('Y-m-d H:i:s');
$polls = $db->prepare(
    'SELECT p.id, p.title, p.ends_at,
       (SELECT COUNT(*) FROM poll_votes WHERE poll_id=p.id AND user_id=?) AS has_voted
     FROM polls p WHERE p.starts_at<=? AND p.ends_at>=?
     ORDER BY p.created_at DESC LIMIT 3'
);
$polls->bind_param('iss', $uid, $now, $now); $polls->execute();
$polls = $polls->get_result()->fetch_all(MYSQLI_ASSOC);

$total_tunggakan = array_sum(array_column($bills, 'total_amount'));

$page_title = 'Portal Warga';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="theme-color" content="#198754">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
  <title><?= e(APP_NAME) ?> — Portal Warga</title>
  <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script>
    // Anti-FOUC: terapkan tema sebelum render
    (function(){try{var t=localStorage.getItem('agipl_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <style>
    body { background:#f0f4f0; font-size:15px; }
    .pwa-header { background:linear-gradient(135deg,#198754,#0d6efd); color:#fff; padding:1.2rem 1rem .8rem; }
    .pwa-header h5 { margin:0; font-size:1rem; }
    .card { border:none; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.07); }
    .bill-item { border-left:4px solid #dc3545; padding:.75rem 1rem; background:#fff; border-radius:0 8px 8px 0; margin-bottom:.5rem; }
    .bill-item.late { border-left-color:#dc3545; }
    .bill-item.unpaid { border-left-color:#ffc107; }
    .nav-bottom { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid #dee2e6;
      display:flex; z-index:100; box-shadow:0 -2px 8px rgba(0,0,0,.08); }
    .nav-bottom a { flex:1; text-align:center; padding:.6rem .25rem .4rem; color:#6c757d;
      text-decoration:none; font-size:.7rem; }
    .nav-bottom a.active { color:#198754; }
    .nav-bottom i { display:block; font-size:1.3rem; margin-bottom:2px; }
    .main-pwa { padding-bottom:5rem; }
    .badge-tunggakan { background:#dc3545; color:#fff; border-radius:999px; padding:1px 6px; font-size:.7rem; }
    .pwa-install-banner { display:none; background:linear-gradient(135deg,#198754,#0d6efd); color:#fff;
      border-radius:12px; padding:1rem; margin-bottom:1rem; box-shadow:0 4px 14px rgba(25,135,84,.25); }
    .pwa-install-banner.show { display:block; animation:slideIn .3s ease; }
    @keyframes slideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:none; } }
    html[data-bs-theme="dark"] body { background:#121a17; color:#e5ebe8; }
    html[data-bs-theme="dark"] .card,
    html[data-bs-theme="dark"] .bill-item,
    html[data-bs-theme="dark"] .nav-bottom,
    html[data-bs-theme="dark"] .bg-white { background:#1d2924 !important; color:#e5ebe8; }
    html[data-bs-theme="dark"] .text-muted { color:#aab8b1 !important; }
    html[data-bs-theme="dark"] .border-top,
    html[data-bs-theme="dark"] .border-bottom { border-color:#35483f !important; }
    .portal-theme-toggle { color:#fff; border:1px solid rgba(255,255,255,.5); background:transparent; }
    .portal-theme-toggle:hover { background:rgba(255,255,255,.15); }
  </style>
</head>
<body>

<div class="pwa-header">
  <div class="d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo" style="height:36px;width:auto;object-fit:contain;">
      <div>
        <div class="opacity-75 small"><?= e(APP_NAME) ?></div>
        <h5 class="mb-0"><?= e(auth_user()['name'] ?? '') ?></h5>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <?php if ($resident): ?>
        <div class="opacity-75 small">Unit</div>
        <div class="fw-bold"><?= e($resident['block'].'-'.$resident['unit_number']) ?></div>
      <?php endif; ?>
    </div>
    <button type="button" class="portal-theme-toggle dark-toggle ms-2" aria-label="Ganti tema" title="Ganti tema">
      <i class="bi bi-moon-stars"></i>
    </button>
  </div>
</div>

<div class="main-pwa p-3">

  <!-- PWA install banner (beforeinstallprompt / iOS manual) -->
  <div id="pwaInstallBanner" class="pwa-install-banner">
    <div class="d-flex align-items-center gap-3">
      <div class="fs-2"><i class="bi bi-phone"></i></div>
      <div class="flex-grow-1">
        <div class="fw-semibold">Pasang Aplikasi Arya Green</div>
        <small id="pwaInstallHint" class="opacity-75">Akses lebih cepat dari layar utama HP Anda.</small>
      </div>
      <button id="pwaInstallBtn" class="btn btn-light btn-sm fw-semibold text-nowrap">
        <i class="bi bi-download me-1"></i>Pasang
      </button>
      <button id="pwaInstallDismiss" class="btn btn-sm text-white p-1" aria-label="Tutup">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
  </div>

  <?php if (!$resident): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Akun Anda belum terhubung ke data warga. Hubungi pengurus RT.
  </div>
  <?php endif; ?>

  <!-- Tunggakan -->
  <?php if ($total_tunggakan > 0): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong><i class="bi bi-exclamation-circle text-danger me-1"></i> Tagihan Belum Lunas</strong>
        <span class="badge bg-danger"><?= count($bills) ?></span>
      </div>
      <?php foreach ($bills as $b): ?>
      <div class="bill-item <?= $b['status']==='terlambat'?'late':'unpaid' ?>">
        <div class="d-flex justify-content-between">
          <div>
            <div class="fw-semibold"><?= e($b['period']) ?></div>
            <small class="text-muted">Jatuh tempo: <?= fmt_date($b['due_date']) ?></small>
          </div>
          <div class="text-end">
            <div class="fw-bold text-danger"><?= idr((float)$b['total_amount']) ?></div>
            <?php if ($b['fine_amount'] > 0): ?>
              <small class="text-danger">+denda <?= idr((float)$b['fine_amount']) ?></small>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-2">
          <a href="<?= APP_URL ?>/pages/payments/form.php?bill_id=<?= $b['id'] ?>"
             class="btn btn-sm btn-danger w-100">
            <i class="bi bi-credit-card me-1"></i>Bayar Sekarang
          </a>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="text-center mt-2 pt-2 border-top">
        <strong>Total Tunggakan: <?= idr($total_tunggakan) ?></strong>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="card mb-3">
    <div class="card-body text-center py-3">
      <i class="bi bi-check-circle-fill text-success fs-2"></i>
      <div class="fw-semibold mt-1">Semua tagihan lunas!</div>
      <small class="text-muted">Terima kasih atas pembayaran tepat waktu.</small>
    </div>
  </div>
  <?php endif; ?>

  <!-- Polling aktif -->
  <?php if (!empty($polls)): ?>
  <div class="card mb-3">
    <div class="card-header fw-semibold bg-white border-0 pb-0">
      <i class="bi bi-bar-chart-steps me-1 text-primary"></i> Polling Aktif
    </div>
    <div class="card-body pt-2">
      <?php foreach ($polls as $p): ?>
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
        <div class="small"><?= e($p['title']) ?></div>
        <?php if ($p['has_voted']): ?>
          <span class="badge bg-success">Sudah vote</span>
        <?php else: ?>
          <a href="<?= APP_URL ?>/pages/polls/index.php" class="btn btn-sm btn-primary py-0 px-2">Vote</a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Riwayat Pembayaran -->
  <?php if (!empty($history)): ?>
  <div class="card mb-3">
    <div class="card-header fw-semibold bg-white border-0 pb-0">
      <i class="bi bi-clock-history me-1 text-success"></i> Riwayat Pembayaran
    </div>
    <div class="card-body pt-2">
      <?php foreach ($history as $h): ?>
      <div class="d-flex justify-content-between py-2 border-bottom">
        <div>
          <div class="small fw-semibold"><?= e($h['period']) ?></div>
          <small class="text-muted"><?= fmt_date($h['payment_date']) ?></small>
        </div>
        <div class="text-end">
          <div class="small fw-semibold"><?= idr((float)$h['amount_paid']) ?></div>
          <?php
          $sm = ['pending'=>['warning','Menunggu'],'verified'=>['success','Terverifikasi'],'rejected'=>['danger','Ditolak']];
          [$c,$l] = $sm[$h['status']] ?? ['secondary',$h['status']];
          echo "<span class=\"badge bg-{$c}\" style=\"font-size:.65rem\">{$l}</span>";
          ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Quick links -->
  <div class="row g-2">
    <div class="col-6">
      <a href="<?= APP_URL ?>/pages/complaints/index.php" class="card text-decoration-none">
        <div class="card-body text-center py-3">
          <i class="bi bi-chat-left-text fs-2 text-info"></i>
          <div class="small fw-semibold mt-1">Buat Aduan</div>
        </div>
      </a>
    </div>
    <div class="col-6">
      <a href="<?= APP_URL ?>/pages/events/index.php" class="card text-decoration-none">
        <div class="card-body text-center py-3">
          <i class="bi bi-calendar-event fs-2 text-primary"></i>
          <div class="small fw-semibold mt-1">Kegiatan RT</div>
        </div>
      </a>
    </div>
    <div class="col-6">
      <a href="<?= APP_URL ?>/pages/public/kas.php" target="_blank" class="card text-decoration-none">
        <div class="card-body text-center py-3">
          <i class="bi bi-eye fs-2 text-success"></i>
          <div class="small fw-semibold mt-1">Kas Publik</div>
        </div>
      </a>
    </div>
    <div class="col-6">
      <a href="<?= APP_URL ?>/auth/logout.php"
         onclick="return confirm('Keluar?')" class="card text-decoration-none">
        <div class="card-body text-center py-3">
          <i class="bi bi-box-arrow-right fs-2 text-secondary"></i>
          <div class="small fw-semibold mt-1">Keluar</div>
        </div>
      </a>
    </div>
  </div>

</div><!-- /.main-pwa -->

<!-- Bottom nav -->
<nav class="nav-bottom">
  <a href="<?= APP_URL ?>/pages/portal.php" class="active">
    <i class="bi bi-house"></i>Beranda
  </a>
  <a href="<?= APP_URL ?>/pages/billing/index.php">
    <i class="bi bi-receipt"></i>Tagihan
    <?php if (count($bills) > 0): ?>
      <span class="badge-tunggakan"><?= count($bills) ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/pages/payments/index.php">
    <i class="bi bi-cash-coin"></i>Bayar
  </a>
  <a href="<?= APP_URL ?>/pages/complaints/index.php">
    <i class="bi bi-chat-left-text"></i>Aduan
  </a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script src="<?= APP_URL ?>/assets/js/offline.js"></script>
<script>
// Register service worker untuk offline support
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(() => {});
}

// ── PWA Install Prompt ────────────────────────────────────────────────────
(function () {
  const banner  = document.getElementById('pwaInstallBanner');
  const btn     = document.getElementById('pwaInstallBtn');
  const dismiss = document.getElementById('pwaInstallDismiss');
  const hint    = document.getElementById('pwaInstallHint');
  if (!banner) return;

  const DISMISS_KEY = 'agipl_pwa_dismissed';
  const alreadyDismissed = () => localStorage.getItem(DISMISS_KEY) === '1';
  const showBanner = (hintText) => {
    if (hintText && hint) hint.textContent = hintText;
    banner.classList.add('show');
  };

  // Android / Chrome / Edge: native beforeinstallprompt
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    if (!alreadyDismissed()) showBanner();
  });

  btn?.addEventListener('click', async () => {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      if (outcome === 'accepted') banner.classList.remove('show');
      deferredPrompt = null;
    }
  });

  // iOS Safari: tidak ada beforeinstallprompt → tampilkan instruksi Share > Add to Home Screen
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;

  if (isIOS && !isStandalone && !alreadyDismissed()) {
    btn.style.display = 'none';
    showBanner('Di Safari, tekan tombol Bagikan lalu pilih "Tambahkan ke Layar Utama".');
  } else if (!deferredPrompt && !isIOS) {
    // Browser desktop tanpa beforeinstallprompt — banner tidak perlu
  }

  // Jangan tampilkan kalau aplikasi sudah terpasang
  window.addEventListener('appinstalled', () => {
    banner.classList.remove('show');
    localStorage.removeItem(DISMISS_KEY);
  });

  dismiss?.addEventListener('click', () => {
    banner.classList.remove('show');
    localStorage.setItem(DISMISS_KEY, '1');
  });
})();
</script>
</body>
</html>
