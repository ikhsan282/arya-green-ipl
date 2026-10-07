<?php
// Landing page publik Arya Green Pamulang
// Jika user sudah login, arahkan ke dashboard
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/pages/dashboard.php');
}

$db = db();

// Ambil ringkasan publik dinamis
$total_units = 0;
$total_dihuni = 0;
$q_units = $db->query('SELECT COUNT(*) AS total, SUM(CASE WHEN status="dihuni" THEN 1 ELSE 0 END) AS dihuni FROM units');
if ($q_units) {
    $u_row = $q_units->fetch_assoc();
    $total_units = (int)($u_row['total'] ?? 0);
    $total_dihuni = (int)($u_row['dihuni'] ?? 0);
}

// Saldo kas bulan berjalan
$cur_year = (int)date('Y');
$cur_month = (int)date('n');
$q_kas = $db->prepare('SELECT
    COALESCE(SUM(CASE WHEN type="pemasukan" THEN amount END),0) -
    COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS saldo_bulan
    FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?');
$q_kas->bind_param('ii', $cur_year, $cur_month);
$q_kas->execute();
$kas_row = $q_kas->get_result()->fetch_assoc();
$saldo_bulan = (float)($kas_row['saldo_bulan'] ?? 0);

// Polling aktif (jika ada)
$now = date('Y-m-d H:i:s');
$q_poll = $db->prepare('SELECT title, ends_at FROM polls WHERE is_public=1 AND ends_at >= ? ORDER BY created_at DESC LIMIT 1');
$q_poll->bind_param('s', $now);
$q_poll->execute();
$active_poll = $q_poll->get_result()->fetch_assoc();

// Kanal pembayaran aktif
$q_methods = $db->query('SELECT name, account_no, account_name FROM payment_methods WHERE is_active=1 ORDER BY sort_order ASC LIMIT 4');
$methods = $q_methods ? $q_methods->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(APP_NAME) ?> — <?= e(APP_TAGLINE) ?></title>
  <meta name="description" content="Sistem Informasi Pengelolaan Iuran Pengelolaan Lingkungan (IPL) & Komunitas Perumahan Arya Green Pamulang.">
  <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
  <meta name="theme-color" content="#0d5c3a">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --brand-primary: #0d5c3a;
      --brand-dark: #073823;
      --brand-accent: #20c997;
      --brand-light: #f3f8f5;
      --text-dark: #0f1f17;
      --text-muted: #52635a;
      --border-subtle: #e2ebe5;
      --card-shadow: 0 10px 30px -10px rgba(7, 56, 35, 0.08), 0 4px 6px -2px rgba(7, 56, 35, 0.03);
    }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      color: var(--text-dark);
      background-color: #ffffff;
      line-height: 1.6;
    }
    .navbar-glass {
      background: rgba(255, 255, 255, 0.92);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border-subtle);
    }
    .brand-logo-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-accent));
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }
    .hero-section {
      padding: 5rem 0 4rem;
      background: radial-gradient(circle at 80% 20%, rgba(32, 201, 151, 0.12) 0%, transparent 50%),
                  radial-gradient(circle at 20% 80%, rgba(13, 92, 58, 0.08) 0%, transparent 50%),
                  #ffffff;
      border-bottom: 1px solid var(--border-subtle);
    }
    .badge-pill {
      background: rgba(13, 92, 58, 0.08);
      color: var(--brand-primary);
      font-weight: 600;
      padding: 0.35rem 0.85rem;
      border-radius: 50rem;
      font-size: 0.82rem;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }
    .stat-box {
      background: #ffffff;
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      transition: transform .2s ease;
    }
    .stat-box:hover {
      transform: translateY(-3px);
    }
    .feature-card {
      background: #ffffff;
      border: 1px solid var(--border-subtle);
      border-radius: 16px;
      padding: 2rem 1.75rem;
      height: 100%;
      box-shadow: var(--card-shadow);
      transition: all .25s ease;
    }
    .feature-card:hover {
      border-color: var(--brand-accent);
      transform: translateY(-4px);
    }
    .feature-icon-wrapper {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: rgba(13, 92, 58, 0.08);
      color: var(--brand-primary);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      margin-bottom: 1.25rem;
    }
    .btn-brand {
      background-color: var(--brand-primary);
      color: #ffffff;
      border: none;
      font-weight: 600;
      padding: 0.65rem 1.4rem;
      border-radius: 10px;
      transition: all .2s;
    }
    .btn-brand:hover {
      background-color: var(--brand-dark);
      color: #ffffff;
      transform: translateY(-1px);
    }
    .btn-brand-outline {
      background: transparent;
      color: var(--brand-primary);
      border: 1.5px solid var(--border-subtle);
      font-weight: 600;
      padding: 0.65rem 1.4rem;
      border-radius: 10px;
      transition: all .2s;
    }
    .btn-brand-outline:hover {
      border-color: var(--brand-primary);
      background: rgba(13, 92, 58, 0.04);
      color: var(--brand-primary);
    }
    .section-title {
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--text-dark);
    }
    .footer-section {
      background: var(--brand-dark);
      color: rgba(255, 255, 255, 0.75);
      padding: 3.5rem 0 2rem;
    }
  </style>
</head>
<body>

  <!-- Header / Navigation -->
  <nav class="navbar navbar-expand-lg navbar-glass sticky-top py-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-dark" href="<?= APP_URL ?>">
        <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo" style="height:48px;width:auto;object-fit:contain;">
        <div>
          <div class="lh-1 text-dark fs-5 fw-bold"><?= e(APP_NAME) ?></div>
          <small class="text-muted fw-normal" style="font-size:0.75rem"><?= e(APP_TAGLINE) ?></small>
        </div>
      </a>
      <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
        <i class="bi bi-list fs-4"></i>
      </button>
      <div class="collapse navbar-collapse" id="navMenu">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3 my-3 my-lg-0">
          <li class="nav-item">
            <a class="nav-link text-dark fw-medium" href="#fitur">Fitur Lingkungan</a>
          </li>
          <li class="nav-item">
            <a class="nav-link text-dark fw-medium" href="#transparansi">Transparansi Kas</a>
          </li>
          <li class="nav-item">
            <a class="nav-link text-dark fw-medium" href="#pembayaran">Metode Bayar</a>
          </li>
          <li class="nav-item">
            <a class="nav-link text-dark fw-medium" href="<?= APP_URL ?>/pages/public/kas.php">
              <i class="bi bi-wallet2 me-1 text-success"></i>Kas Terbuka
            </a>
          </li>
          <li class="nav-item ms-lg-2">
            <a class="btn btn-brand w-100" href="<?= APP_URL ?>/auth/login.php">
              <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Akun
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="hero-section">
    <div class="container">
      <div class="row align-items-center gy-5">
        <div class="col-lg-7">
          <div class="badge-pill mb-3">
            <i class="bi bi-shield-check"></i> Lingkungan Nyaman, Transparan & Akuntabel
          </div>
          <h1 class="display-5 fw-bold mb-3 section-title" style="line-height:1.2;">
            Portal Warga Digital <span style="color:var(--brand-primary);">Arya Green Pamulang</span>
          </h1>
          <p class="lead text-muted mb-4" style="font-size:1.15rem;">
            Hunian minimalis modern oleh Brantas Abipraya Properti (BUMN) dengan sistem pengelolaan RT terpadu. Cek tagihan IPL, lapor aduan, polling musyawarah, dan transparansi kas lingkungan — semua dalam satu aplikasi.
          </p>
          <div class="d-flex flex-wrap gap-3">
            <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-brand btn-lg">
              <i class="bi bi-door-open-fill me-1"></i> Masuk ke Portal Warga
            </a>
            <a href="<?= APP_URL ?>/pages/public/kas.php" class="btn btn-brand-outline btn-lg">
              <i class="bi bi-graph-up-arrow me-1"></i> Lihat Rekap Kas Publik
            </a>
          </div>

          <?php if ($active_poll): ?>
            <div class="mt-4 p-3 rounded-3" style="background:#eaf6f0; border-left:4px solid var(--brand-primary);">
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success">Polling Aktif</span>
                <span class="fw-semibold text-dark"><?= e($active_poll['title']) ?></span>
              </div>
              <small class="text-muted">Berakhir pada <?= date('d M Y H:i', strtotime($active_poll['ends_at'])) ?> WIB — Login untuk memberikan suara Anda.</small>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-lg-5">
          <div class="card border-0 shadow-lg" style="border-radius:20px; overflow:hidden;">
            <div class="p-4 text-white" style="background:linear-gradient(135deg, var(--brand-dark), var(--brand-primary));">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="small text-white-50">Ringkasan Lingkungan</span>
                <span class="badge bg-light text-success fw-bold">Live Data</span>
              </div>
              <h4 class="fw-bold mb-1">Arya Green Pamulang</h4>
              <p class="small text-white-70 mb-0"><i class="bi bi-geo-alt me-1"></i>Pamulang, Tangerang Selatan</p>
              <p class="small text-white-60 mb-0" style="font-size:0.7rem;"><i class="bi bi-shield-check me-1"></i>Brantas Abipraya Properti</p>
            </div>
            <div class="card-body p-4 bg-white">
              <div class="row g-3">
                <div class="col-6">
                  <div class="stat-box text-center">
                    <div class="text-muted small mb-1">Total Unit</div>
                    <div class="h3 fw-bold mb-0 text-dark"><?= $total_units ?></div>
                    <small class="text-success"><i class="bi bi-check2"></i> <?= $total_dihuni ?> Dihuni</small>
                  </div>
                </div>
                <div class="col-6">
                  <div class="stat-box text-center">
                    <div class="text-muted small mb-1">Surplus Kas <?= bulan_indo($cur_month) ?></div>
                    <div class="h5 fw-bold mb-0 <?= $saldo_bulan >= 0 ? 'text-success' : 'text-danger' ?>">
                      <?= idr($saldo_bulan) ?>
                    </div>
                    <small class="text-muted">Bulan berjalan</small>
                  </div>
                </div>
              </div>

              <div class="mt-4 pt-3 border-top">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="small fw-semibold text-dark">Portal Warga Berbasis PWA</span>
                  <span class="badge bg-success-subtle text-success">Mobile Ready</span>
                </div>
                <p class="small text-muted mb-3">
                  Dapat dipasang langsung di layar utama smartphone Anda seperti aplikasi asli tanpa download dari App Store.
                </p>
                <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-success btn-sm w-100">
                  <i class="bi bi-phone me-1"></i> Buka Portal di Smartphone
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section class="py-5" id="fitur" style="background-color: var(--brand-light);">
    <div class="container py-4">
      <div class="text-center max-w-700 mx-auto mb-5">
        <div class="badge-pill mb-2">Layanan Terpadu</div>
        <h2 class="section-title h1">Kemudahan untuk Seluruh Warga</h2>
        <p class="text-muted">Semua administrasi dan informasi RT kini terpusat, cepat, dan dapat diakses dari mana saja.</p>
      </div>

      <div class="row g-4">
        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="feature-icon-wrapper">
              <i class="bi bi-receipt"></i>
            </div>
            <h5 class="fw-bold mb-2">Tagihan & Bukti Bayar Digital</h5>
            <p class="text-muted small mb-0">
              Notifikasi tagihan bulanan langsung, konfirmasi pembayaran mudah lewat transfer/QRIS, serta cetak kuitansi resmi bertanda tangan.
            </p>
          </div>
        </div>

        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="feature-icon-wrapper">
              <i class="bi bi-wallet2"></i>
            </div>
            <h5 class="fw-bold mb-2">Transparansi Kas Lingkungan</h5>
            <p class="text-muted small mb-0">
              Setiap rupiah pemasukan dan pengeluaran dicatat secara akuntabel. Warga dapat melihat laporan keuangan bulanan tanpa birokrasi.
            </p>
          </div>
        </div>

        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="feature-icon-wrapper">
              <i class="bi bi-megaphone"></i>
            </div>
            <h5 class="fw-bold mb-2">Layanan Aduan & Aspirasi</h5>
            <p class="text-muted small mb-0">
              Laporkan kendala fasilitas, keamanan, atau kebersihan lingkungan dilengkapi foto. Pengurus dapat merespons progres secara langsung.
            </p>
          </div>
        </div>

        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="feature-icon-wrapper">
              <i class="bi bi-pie-chart"></i>
            </div>
            <h5 class="fw-bold mb-2">Polling & Musyawarah Online</h5>
            <p class="text-muted small mb-0">
              Keputusan bersama RT kini lebih mudah dengan fitur voting online yang transparan, aman dari manipulasi, dan tepat sasaran.
            </p>
          </div>
        </div>

        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="feature-icon-wrapper">
              <i class="bi bi-file-earmark-text"></i>
            </div>
            <h5 class="fw-bold mb-2">Surat Pengantar RT Otomatis</h5>
            <p class="text-muted small mb-0">
              Pengajuan surat domisili atau keterangan resmi warga diproses dengan penomoran otomatis yang rapi dan siap cetak.
            </p>
          </div>
        </div>

        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="feature-icon-wrapper">
              <i class="bi bi-calendar-event"></i>
            </div>
            <h5 class="fw-bold mb-2">Agenda & Absensi Kegiatan</h5>
            <p class="text-muted small mb-0">
              Jadwal kerja bakti, rapat warga, dan acara peringatan hari besar terorganisir dengan sistem presensi kehadiran warga berfoto.
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Payment Methods Section -->
  <section class="py-5" id="pembayaran">
    <div class="container py-4">
      <div class="row align-items-center gy-4">
        <div class="col-lg-5">
          <div class="badge-pill mb-2">Pembayaran Fleksibel</div>
          <h2 class="section-title h1 mb-3">Kanal Pembayaran Resmi</h2>
          <p class="text-muted mb-4">
            Pengurus menyediakan berbagai opsi pembayaran yang aman dan tercatat otomatis ke dalam pembukuan iuran warga.
          </p>
          <div class="d-flex flex-column gap-3">
            <div class="d-flex align-items-start gap-3">
              <div class="text-success fs-4"><i class="bi bi-check-circle-fill"></i></div>
              <div>
                <h6 class="fw-bold mb-1">Auto-Verifikasi Cepat</h6>
                <p class="text-muted small mb-0">Pembayaran tunai atau metode terintegrasi langsung lunas dan menerbitkan kuitansi.</p>
              </div>
            </div>
            <div class="d-flex align-items-start gap-3">
              <div class="text-success fs-4"><i class="bi bi-check-circle-fill"></i></div>
              <div>
                <h6 class="fw-bold mb-1">Bukti Bayar Tersimpan Aman</h6>
                <p class="text-muted small mb-0">Foto bukti transfer tersimpan rapi dan dapat ditinjau kapan saja dari akun masing-masing.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="row g-3">
            <?php if ($methods): ?>
              <?php foreach ($methods as $m): ?>
                <div class="col-sm-6">
                  <div class="p-3 rounded-3 border h-100 bg-white" style="box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <span class="fw-bold text-dark"><?= e($m['name']) ?></span>
                      <i class="bi bi-credit-card-2-front text-success"></i>
                    </div>
                    <?php if ($m['account_no']): ?>
                      <div class="font-monospace fw-semibold fs-6 text-primary"><?= e($m['account_no']) ?></div>
                      <small class="text-muted d-block">a.n. <?= e($m['account_name'] ?: APP_NAME) ?></small>
                    <?php else: ?>
                      <small class="text-muted">Metode bayar langsung ke bendahara lingkungan.</small>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12">
                <div class="alert alert-info mb-0">Kanal pembayaran dapat dilihat pada form pembayaran setelah login.</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="py-5" style="background: linear-gradient(135deg, var(--brand-dark), var(--brand-primary)); color:#ffffff;">
    <div class="container py-4 text-center">
      <h2 class="fw-bold mb-3">Siap Mengakses Portal Warga Arya Green?</h2>
      <p class="lead text-white-50 mb-4 max-w-700 mx-auto">
        Masuk menggunakan akun yang telah diberikan oleh pengurus RT untuk melihat tagihan, histori pembayaran, dan layanan warga lainnya.
      </p>
      <div class="d-flex justify-content-center gap-3 flex-wrap">
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-light btn-lg text-success fw-bold px-4">
          <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
        </a>
        <a href="<?= APP_URL ?>/pages/public/kas.php" class="btn btn-outline-light btn-lg px-4">
          <i class="bi bi-cash-stack me-1"></i> Rekap Kas Terbuka
        </a>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer-section">
    <div class="container">
      <div class="row gy-4 mb-4">
        <div class="col-md-6">
          <div class="d-flex align-items-center gap-2 mb-2">
            <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo" style="height:40px;width:auto;object-fit:contain;mix-blend-mode:screen;">
            <h5 class="text-white fw-bold mb-0"><?= e(APP_NAME) ?></h5>
          </div>
          <p class="small text-white-50 mb-2">
            <?= e(APP_TAGLINE) ?> — Aplikasi pengelolaan iuran dan operasional lingkungan perumahan Arya Green Pamulang.
          </p>
          <small class="text-white-50 d-block"><i class="bi bi-geo-alt me-1"></i>Pamulang, Tangerang Selatan, Banten</small>
          <small class="text-white-50 d-block mt-1"><i class="bi bi-building me-1"></i>Developer: Brantas Abipraya Properti (BUMN)</small>
        </div>
        <div class="col-md-3">
          <h6 class="text-white fw-bold mb-3">Tautan Publik</h6>
          <ul class="list-unstyled small d-flex flex-column gap-2 mb-0">
            <li><a href="<?= APP_URL ?>/auth/login.php" class="text-white-50 text-decoration-none">Portal Login</a></li>
            <li><a href="<?= APP_URL ?>/pages/public/kas.php" class="text-white-50 text-decoration-none">Transparansi Kas</a></li>
            <li><a href="<?= APP_URL ?>/auth/forgot-password.php" class="text-white-50 text-decoration-none">Lupa Password</a></li>
          </ul>
        </div>
        <div class="col-md-3">
          <h6 class="text-white fw-bold mb-3">Dukungan Pengurus</h6>
          <p class="small text-white-50 mb-2">
            Mengalami kendala akun atau butuh data penghuni baru? Hubungi pengurus RT / bendahara setempat.
          </p>
          <span class="badge bg-success">Versi <?= e(APP_VERSION) ?></span>
        </div>
      </div>
      <div class="border-top border-secondary pt-3 text-center small text-white-50">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Hak cipta dilindungi undang-undang.
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
