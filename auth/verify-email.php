<?php
require_once __DIR__ . '/../includes/auth.php';

$token = clean($_GET['token'] ?? '');
$msg   = '';
$type  = 'danger';

if (!$token) {
    $msg = 'Token tidak valid.';
} else {
    $db   = db();
    $stmt = $db->prepare(
        'SELECT id, name, email_verified_at FROM users WHERE verification_token = ? LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        $msg = 'Token tidak ditemukan atau sudah digunakan.';
    } elseif ($user['email_verified_at']) {
        $msg  = 'Email sudah terverifikasi sebelumnya. Silakan login.';
        $type = 'info';
    } else {
        $upd = $db->prepare(
            'UPDATE users SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?'
        );
        $upd->bind_param('i', $user['id']);
        $upd->execute();
        $msg  = 'Email berhasil diverifikasi! Silakan login.';
        $type = 'success';
        log_activity('verify_email', 'auth', 'Email verified for user #' . $user['id']);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verifikasi Email — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-wrapper">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-sm-8 col-md-5 col-lg-4">
      <div class="card auth-card p-4 text-center">
        <div class="auth-logo mb-2"><i class="bi bi-tree-fill"></i></div>
        <h5 class="fw-bold mb-3">Verifikasi Email</h5>
        <div class="alert alert-<?= $type ?>"><?= e($msg) ?></div>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-success">
          <i class="bi bi-box-arrow-in-right me-1"></i> Ke Halaman Login
        </a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
