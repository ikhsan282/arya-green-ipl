<?php
$current_uri = $_SERVER['REQUEST_URI'] ?? '';
function nav_active(string $path): string {
    global $current_uri;
    return str_contains($current_uri, $path) ? ' active' : '';
}
?>
<!-- Sidebar -->
<nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-0">
  <a href="<?= APP_URL ?>/pages/dashboard.php"
     class="d-flex align-items-center p-3 text-white text-decoration-none sidebar-brand">
    <i class="bi bi-tree-fill me-2 fs-4"></i>
    <span class="fw-bold"><?= APP_NAME ?></span>
  </a>
  <hr class="text-white m-0">

  <ul class="nav nav-pills flex-column mb-auto px-2 py-3">

    <?php if (can('dashboard.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/dashboard.php"
         class="nav-link text-white<?= nav_active('/dashboard') ?>">
        <i class="bi bi-speedometer2 me-2"></i> Dashboard
      </a>
    </li>
    <?php endif; ?>

    <?php if (can('units.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/units/index.php"
         class="nav-link text-white<?= nav_active('/units') ?>">
        <i class="bi bi-houses me-2"></i> Data Unit
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

    <?php if (can('reports.view')): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/pages/reports/index.php"
         class="nav-link text-white<?= nav_active('/reports') ?>">
        <i class="bi bi-bar-chart-line me-2"></i> Laporan
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

    <?php if (can('users.view')): ?>
    <li class="nav-item mt-2">
      <small class="text-white-50 px-2 text-uppercase" style="font-size:.7rem">Pengaturan</small>
    </li>
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
