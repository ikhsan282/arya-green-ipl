<?php
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/pages/dashboard.php');
}

$error = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Username dan password wajib diisi.';
    } else {
        $result = attempt_login($username, $password);
        if ($result['ok']) {
            redirect(APP_URL . '/pages/dashboard.php');
        } else {
            $error = $result['msg'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-wrapper">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-sm-9 col-md-6 col-lg-4">
      <div class="card auth-card p-4">
        <div class="text-center mb-4">
          <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo" class="auth-logo-img mb-2">
          <h4 class="fw-bold mb-0"><?= APP_NAME ?></h4>
          <small class="text-muted"><?= APP_TAGLINE ?></small>
        </div>

        <?php if ($timeout): ?>
          <div class="alert alert-warning py-2">Sesi Anda telah berakhir. Silakan login kembali.</div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>
        <?= render_flash() ?>

        <form method="POST" action="">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username / Email</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" id="username" name="username" class="form-control"
                     value="<?= e($_POST['username'] ?? '') ?>" required autofocus
                     placeholder="Masukkan username atau email">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" id="password" name="password" class="form-control"
                     required placeholder="Masukkan password">
              <button class="btn btn-outline-secondary" type="button" id="togglePwd">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
            </button>
          </div>
          <div class="text-center">
            <a href="<?= APP_URL ?>/auth/forgot-password.php" class="text-muted small">
              Lupa password?
            </a>
          </div>
        </form>
      </div>
      <p class="text-center text-white-50 mt-3 small">&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.getElementById('togglePwd').addEventListener('click', function() {
    const pwd = document.getElementById('password');
    const icon = this.querySelector('i');
    if (pwd.type === 'password') {
      pwd.type = 'text'; icon.className = 'bi bi-eye-slash';
    } else {
      pwd.type = 'password'; icon.className = 'bi bi-eye';
    }
  });
</script>
</body>
</html>
