<?php
$current_uri = $_SERVER['REQUEST_URI'] ?? '';
function nav_active(string $path): string {
    global $current_uri;
    return str_contains($current_uri, $path) ? ' active' : '';
}
?>
<nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-0">
  <a href="<?= APP_URL ?>/pages/dashboard.php"
     class="d-flex align-items-center p-3 text-white text-decoration-none sidebar-brand">
    <i class="bi bi-tree-fill me-2 fs-4"></i>
    <span class="fw-bold"><?= APP_NAME ?></span>
  </a>
  <hr class="text-white m-0">

  <ul class="nav nav-pills flex-column mb-auto px-2 py-2" style="overflow-y:auto">

    <?php if (can('dashboard.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/dashboard.php"
         class="nav-link text-white<?= nav_active('/dashboard') ?>">
        <i class="bi bi-speedometer2 me-2"></i> Dashboard
      </a>
    </li>
    <?php endif; ?>

    <!-- ── Data Unit & Warga ── -->
    <?php if (can('units.view')): ?>
    <li class="nav-item mt-2">
      <small class="text-white-50 px-2 text-uppercase" style="font-size:.7rem">Data</small>
    </li>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/units/index.php"
         class="nav-link text-white<?= nav_active('/units/') ?>">
        <i class="bi bi-houses me-2"></i> Data Unit
      </a>
    </li>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/unit_types/index.php"
         class="nav-link text-white<?= nav_active('/unit_types') ?>">
        <i class="bi bi-grid me-2"></i> Tipe Unit
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('residents.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/residents/index.php"
         class="nav-link text-white<?= nav_active('/residents') ?>">
        <i class="bi bi-people me-2"></i> Data Warga
      </a>
    </li>
    <?php endif; ?>

    <!-- ── Tagihan & Pembayaran ── -->
    <?php if (can('billing.view') || can('payments.view')): ?>
    <li class="nav-item mt-2">
      <small class="text-white-50 px-2 text-uppercase" style="font-size:.7rem">Keuangan</small>
    </li>
    <?php endif; ?>

    <?php if (can('billing.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/billing/index.php"
         class="nav-link text-white<?= nav_active('/billing') ?>">
        <i class="bi bi-receipt me-2"></i> Tagihan IPL
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('payments.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/payments/index.php"
         class="nav-link text-white<?= nav_active('/payments') ?>">
        <i class="bi bi-cash-coin me-2"></i> Pembayaran
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('cashbook.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/cashbook/index.php"
         class="nav-link text-white<?= nav_active('/cashbook') ?>">
        <i class="bi bi-journal-text me-2"></i> Buku Kas
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('kas.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/kas/index.php"
         class="nav-link text-white<?= nav_active('/kas/') ?>">
        <i class="bi bi-wallet2 me-2"></i> Sub-Kas
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('expense.request') || can('expense.approve')): ?>
    <li class="nav-item">
      <?php
      $pending_exp = 0;
      if (can('expense.approve')) {
          $pending_exp = db()->query('SELECT COUNT(*) FROM expense_requests WHERE status="pending"')->fetch_row()[0] ?? 0;
      }
      ?>
      <a href="<?= APP_URL ?>/pages/expense/index.php"
         class="nav-link text-white<?= nav_active('/expense') ?>">
        <i class="bi bi-file-earmark-check me-2"></i> Pengeluaran
        <?php if ($pending_exp > 0): ?>
          <span class="badge bg-warning text-dark ms-1"><?= $pending_exp ?></span>
        <?php endif; ?>
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('reports.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/reports/index.php"
         class="nav-link text-white<?= nav_active('/reports/index') ?>">
        <i class="bi bi-bar-chart-line me-2"></i> Laporan
      </a>
    </li>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/reports/arrears.php"
         class="nav-link text-white<?= nav_active('/reports/arrears') ?>">
        <i class="bi bi-exclamation-triangle me-2"></i> Rekap Tunggakan
      </a>
    </li>
    <?php endif; ?>

    <!-- ── Komunikasi ── -->
    <?php if (can('billing.send_reminder') || can('complaints.view') || can('polls.view')): ?>
    <li class="nav-item mt-2">
      <small class="text-white-50 px-2 text-uppercase" style="font-size:.7rem">Komunikasi</small>
    </li>
    <?php endif; ?>

    <?php if (can('billing.send_reminder')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/billing/send_reminders.php"
         class="nav-link text-white<?= nav_active('/send_reminders') ?>">
        <i class="bi bi-envelope me-2"></i> Reminder Email
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('complaints.view')): ?>
    <li class="nav-item">
      <?php
      $new_complaints = 0;
      if (can('complaints.manage')) {
          $new_complaints = db()->query('SELECT COUNT(*) FROM complaints WHERE status="baru"')->fetch_row()[0] ?? 0;
      }
      ?>
      <a href="<?= APP_URL ?>/pages/complaints/index.php"
         class="nav-link text-white<?= nav_active('/complaints') ?>">
        <i class="bi bi-chat-left-text me-2"></i> Aduan Warga
        <?php if ($new_complaints > 0): ?>
          <span class="badge bg-info ms-1"><?= $new_complaints ?></span>
        <?php endif; ?>
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('polls.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/polls/index.php"
         class="nav-link text-white<?= nav_active('/polls') ?>">
        <i class="bi bi-bar-chart-steps me-2"></i> Polling
      </a>
    </li>
    <?php endif; ?>

    <!-- ── Kegiatan & Aset ── -->
    <?php if (can('events.view') || can('inventory.view') || can('letters.view')): ?>
    <li class="nav-item mt-2">
      <small class="text-white-50 px-2 text-uppercase" style="font-size:.7rem">Lingkungan</small>
    </li>
    <?php endif; ?>

    <?php if (can('events.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/events/index.php"
         class="nav-link text-white<?= nav_active('/events') ?>">
        <i class="bi bi-calendar-event me-2"></i> Kegiatan
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('inventory.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/inventory/index.php"
         class="nav-link text-white<?= nav_active('/inventory') ?>">
        <i class="bi bi-box-seam me-2"></i> Inventaris
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('letters.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/letters/index.php"
         class="nav-link text-white<?= nav_active('/letters') ?>">
        <i class="bi bi-file-text me-2"></i> Surat RT
      </a>
    </li>
    <?php endif; ?>

    <!-- Transparansi Publik -->
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/public/kas.php" target="_blank"
         class="nav-link text-white-50<?= nav_active('/public/kas') ?>">
        <i class="bi bi-eye me-2"></i> Kas Publik <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.7rem"></i>
      </a>
    </li>

    <!-- ── Pengaturan ── -->
    <?php if (can('users.view') || can('roles.manage') || can('environments.view')): ?>
    <li class="nav-item mt-2">
      <small class="text-white-50 px-2 text-uppercase" style="font-size:.7rem">Pengaturan</small>
    </li>
    <?php endif; ?>

    <?php if (can('users.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/users/index.php"
         class="nav-link text-white<?= nav_active('/users') ?>">
        <i class="bi bi-person-gear me-2"></i> Pengguna
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('roles.manage')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/roles/index.php"
         class="nav-link text-white<?= nav_active('/roles') ?>">
        <i class="bi bi-shield-lock me-2"></i> Roles & Akses
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('environments.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/environments/index.php"
         class="nav-link text-white<?= nav_active('/environments') ?>">
        <i class="bi bi-buildings me-2"></i> Lingkungan
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('users.create')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/onboarding/index.php"
         class="nav-link text-white<?= nav_active('/onboarding') ?>">
        <i class="bi bi-magic me-2"></i> Setup / Onboarding
      </a>
    </li>
    <?php endif; ?>

  </ul>

  <hr class="text-white m-0">
  <div class="p-3">
    <div class="d-flex align-items-center mb-2">
      <div class="avatar-sm me-2">
        <i class="bi bi-person-circle fs-4 text-white-50"></i>
      </div>
      <div class="text-white lh-1">
        <div class="fw-semibold" style="font-size:.85rem"><?= e($current_user['name'] ?? '') ?></div>
        <small class="text-white-50"><?= e(ucwords(str_replace('_',' ', $current_user['role'] ?? ''))) ?></small>
      </div>
    </div>
    <a href="<?= APP_URL ?>/auth/logout.php"
       class="btn btn-sm btn-outline-light w-100"
       onclick="return confirm('Yakin ingin keluar?')">
      <i class="bi bi-box-arrow-right me-1"></i> Keluar
    </a>
  </div>
</nav>
