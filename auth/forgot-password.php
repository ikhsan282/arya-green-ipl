<?php
require_once __DIR__ . '/../includes/auth.php';

$action = clean($_GET['action'] ?? 'request');
$token  = clean($_GET['token'] ?? '');
$msg    = '';
$type   = 'info';

// ── Step 2: show reset form ────────────────────────────────────────────────
if ($action === 'reset' && $token) {
    $db   = db();
    $stmt = $db->prepare(
        'SELECT id, name FROM users
         WHERE reset_token = ? AND reset_token_expires > NOW() AND is_active = 1 LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        $msg  = 'Link reset tidak valid atau sudah kedaluwarsa.';
        $type = 'danger';
        $action = 'invalid';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $new  = $_POST['new_password']     ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 8) {
            $msg  = 'Password minimal 8 karakter.';
            $type = 'danger';
        } elseif ($new !== $conf) {
            $msg  = 'Konfirmasi password tidak cocok.';
            $type = 'danger';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd  = $db->prepare(
                'UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?'
            );
            $upd->bind_param('si', $hash, $user['id']);
            $upd->execute();
            log_activity('reset_password', 'auth', 'Password reset for user #' . $user['id']);
            flash('success', 'Password berhasil direset. Silakan login.');
            redirect(APP_URL . '/auth/login.php');
        }
    }
}

// ── Step 1: request reset ──────────────────────────────────────────────────
if ($action === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = clean($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg  = 'Format email tidak valid.';
        $type = 'danger';
    } else {
        send_reset_email($email); // always show success to prevent email enumeration
        $msg  = 'Jika email terdaftar, instruksi reset password telah dikirim ke inbox Anda.';
        $type = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Lupa Password — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-wrapper">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-sm-9 col-md-6 col-lg-4">
      <div class="card auth-card p-4">
        <div class="text-center mb-4">
          <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo" class="auth-logo-img mb-2">
          <h5 class="fw-bold mb-0">
            <?= $action === 'reset' ? 'Reset Password' : 'Lupa Password' ?>
          </h5>
        </div>

        <?php if ($msg): ?>
          <div class="alert alert-<?= $type ?> py-2"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php if ($action === 'request'): ?>
        <p class="text-muted small">Masukkan email terdaftar Anda. Kami akan mengirim link reset password.</p>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" id="email" name="email" class="form-control"
                     required placeholder="email@contoh.com"
                     value="<?= e($_POST['email'] ?? '') ?>">
            </div>
          </div>
          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-send me-1"></i> Kirim Link Reset
            </button>
          </div>
        </form>

        <?php elseif ($action === 'reset' && $user): ?>
        <p class="text-muted small">Halo <strong><?= e($user['name']) ?></strong>, masukkan password baru Anda.</p>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Password Baru</label>
            <input type="password" name="new_password" class="form-control"
                   required minlength="8" placeholder="Minimal 8 karakter">
          </div>
          <div class="mb-3">
            <label class="form-label">Konfirmasi Password</label>
            <input type="password" name="confirm_password" class="form-control"
                   required minlength="8" placeholder="Ulangi password baru">
          </div>
          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-lg me-1"></i> Simpan Password Baru
            </button>
          </div>
        </form>
        <?php endif; ?>

        <div class="text-center">
          <a href="<?= APP_URL ?>/auth/login.php" class="text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Login
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
