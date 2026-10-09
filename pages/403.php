<?php
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
http_response_code(403);
$page_title = '403 — Akses Ditolak';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $page_title ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
  <div class="text-center p-4">
    <i class="bi bi-shield-x text-danger" style="font-size:4rem"></i>
    <h2 class="mt-3 fw-bold">403 — Akses Ditolak</h2>
    <p class="text-muted">Anda tidak memiliki izin untuk mengakses halaman ini.</p>
    <a href="<?= APP_URL ?>/pages/dashboard.php" class="btn btn-success">
      <i class="bi bi-house me-1"></i> Kembali ke Dashboard
    </a>
  </div>
</body>
</html>
