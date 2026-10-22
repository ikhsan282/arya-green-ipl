<?php
/**
 * Profil pengguna: ubah data diri & ganti password.
 * Setiap user hanya bisa mengubah akunnya sendiri — tidak ada parameter id.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/functions.php';

$db  = db();
$uid = auth_id();

$stmt = $db->prepare(
    'SELECT u.*, r.name AS role_name, r.label AS role_label
     FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
if (!$me) { flash('error', 'Akun tidak ditemukan.'); redirect(APP_URL . '/auth/logout.php'); }

$errors    = [];
$active    = $_POST['_action'] ?? 'profile';
$profile_t = ['name' => $me['name'], 'username' => $me['username'], 'email' => $me['email']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    /* ── Ganti password ─────────────────────────────────────────────── */
    if ($active === 'password') {
        $cur  = (string)($_POST['current_password'] ?? '');
        $new  = (string)($_POST['new_password']     ?? '');
        $new2 = (string)($_POST['confirm_password'] ?? '');

        if ($cur === '')            $errors[] = 'Password saat ini wajib diisi.';
        if (strlen($new) < 8)       $errors[] = 'Password baru minimal 8 karakter.';
        if ($new !== $new2)         $errors[] = 'Konfirmasi password tidak cocok.';
        if ($new !== '' && $new === $cur) $errors[] = 'Password baru harus berbeda dari password saat ini.';

        if (empty($errors) && !password_verify($cur, $me['password'])) {
            $errors[] = 'Password saat ini salah.';
        }

        if (empty($errors)) {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $up = $db->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
            $up->bind_param('si', $hash, $uid);
            $up->execute();

            log_activity('update', 'users', "Password diubah sendiri oleh user #{$uid}");

            // Sesi lain ikut diputus: password berubah = kredensial lama tidak berlaku.
            session_regenerate_id(true);
            $_SESSION['pwd_changed_at'] = time();

            flash('success', 'Password berhasil diubah. Sesi lain telah diakhiri.');
            redirect(APP_URL . '/pages/profile.php');
        }
    }

    /* ── Ubah data profil ───────────────────────────────────────────── */
    if ($active === 'profile') {
        $profile_t = [
            'name'     => clean($_POST['name']     ?? ''),
            'username' => clean($_POST['username'] ?? ''),
            'email'    => clean($_POST['email']    ?? ''),
        ];

        if ($profile_t['name'] === '')     $errors[] = 'Nama wajib diisi.';
        if ($profile_t['username'] === '') $errors[] = 'Username wajib diisi.';
        if (!filter_var($profile_t['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';

        if (empty($errors)) {
            $c1 = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $c1->bind_param('si', $profile_t['username'], $uid);
            $c1->execute();
            if ($c1->get_result()->fetch_row()) $errors[] = 'Username sudah dipakai pengguna lain.';

            $c2 = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $c2->bind_param('si', $profile_t['email'], $uid);
            $c2->execute();
            if ($c2->get_result()->fetch_row()) $errors[] = 'Email sudah dipakai pengguna lain.';
        }

        if (empty($errors)) {
            $email_changed = strcasecmp($profile_t['email'], (string)$me['email']) !== 0;
            if ($email_changed) {
                // Email berubah → verifikasi ulang. Jangan sampai alamat yang belum
                // dibuktikan dipakai untuk notifikasi atau reset password.
                $tok = bin2hex(random_bytes(32));
                $up = $db->prepare('UPDATE users SET name=?, username=?, email=?, email_verified_at=NULL, verification_token=? WHERE id=?');
                $up->bind_param('ssssi', $profile_t['name'], $profile_t['username'], $profile_t['email'], $tok, $uid);
                $up->execute();
                if (function_exists('send_verification_email')) {
                    @send_verification_email($uid, $profile_t['email'], $profile_t['name']);
                }
                flash('success', 'Profil diperbarui. Cek email baru Anda untuk verifikasi.');
            } else {
                $up = $db->prepare('UPDATE users SET name=?, username=? WHERE id=?');
                $up->bind_param('ssi', $profile_t['name'], $profile_t['username'], $uid);
                $up->execute();
                flash('success', 'Profil berhasil diperbarui.');
            }
            log_activity('update', 'users', "Profil user #{$uid} diperbarui");
            redirect(APP_URL . '/pages/profile.php');
        }
        $active = 'profile';
    }
}

$page_title = 'Profil Saya';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-person-gear me-1 text-success"></i> Profil Saya</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="main-content">
    <?= render_flash() ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger py-2">
        <ul class="mb-0 ps-3">
          <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <!-- Ringkasan akun -->
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-body text-center">
            <div class="mb-2"><i class="bi bi-person-circle text-success" style="font-size:3.5rem"></i></div>
            <h5 class="fw-bold mb-1"><?= e($me['name']) ?></h5>
            <div class="text-muted small mb-2">@<?= e($me['username']) ?></div>
            <span class="badge bg-success-subtle text-success-emphasis">
              <?= e($me['role_label'] ?: ucwords(str_replace('_', ' ', (string)$me['role_name']))) ?>
            </span>

            <hr>
            <table class="table table-sm table-borderless mb-0 text-start small">
              <tr><th class="text-muted fw-normal" style="width:45%">Email</th>
                  <td class="text-break"><?= e($me['email']) ?>
                    <?php if (!$me['email_verified_at']): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis ms-1">Belum verifikasi</span>
                    <?php endif; ?>
                  </td></tr>
              <tr><th class="text-muted fw-normal">Login terakhir</th>
                  <td><?= $me['last_login'] ? fmt_date($me['last_login'], 'd M Y H:i') : '-' ?></td></tr>
              <tr><th class="text-muted fw-normal">Akun dibuat</th>
                  <td><?= fmt_date($me['created_at']) ?></td></tr>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <!-- Data profil -->
        <div class="card mb-4">
          <div class="card-header"><i class="bi bi-pencil-square me-1 text-success"></i> Data Profil</div>
          <div class="card-body">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="profile">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control" required
                         value="<?= e($profile_t['name']) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Username <span class="text-danger">*</span></label>
                  <input type="text" name="username" class="form-control" required
                         value="<?= e($profile_t['username']) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Email <span class="text-danger">*</span></label>
                  <input type="email" name="email" class="form-control" required
                         value="<?= e($profile_t['email']) ?>">
                  <div class="form-text">Mengubah email akan mewajibkan verifikasi ulang.</div>
                </div>
              </div>
              <div class="mt-3">
                <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Simpan Profil</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Ganti password -->
        <div class="card">
          <div class="card-header"><i class="bi bi-shield-lock me-1 text-success"></i> Ganti Password</div>
          <div class="card-body">
            <form method="POST" autocomplete="off">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="password">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                  <input type="password" name="current_password" class="form-control" required
                         autocomplete="current-password">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                  <input type="password" name="new_password" id="newPass" class="form-control" required
                         minlength="8" autocomplete="new-password">
                  <div class="form-text">Minimal 8 karakter.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Ulangi Password Baru <span class="text-danger">*</span></label>
                  <input type="password" name="confirm_password" id="confPass" class="form-control" required
                         minlength="8" autocomplete="new-password">
                  <div class="form-text" id="matchHint"></div>
                </div>
              </div>
              <div class="mt-3">
                <button class="btn btn-success"><i class="bi bi-key me-1"></i> Ubah Password</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
// Petunjuk cocok/tidak — validasi sebenarnya tetap di server.
const np = document.getElementById('newPass');
const cp = document.getElementById('confPass');
const hint = document.getElementById('matchHint');
function checkMatch() {
  if (!cp.value) { hint.textContent = ''; hint.className = 'form-text'; return; }
  const ok = np.value === cp.value;
  hint.textContent = ok ? 'Password cocok.' : 'Password belum sama.';
  hint.className = 'form-text ' + (ok ? 'text-success' : 'text-danger');
}
np.addEventListener('input', checkMatch);
cp.addEventListener('input', checkMatch);
</script>
