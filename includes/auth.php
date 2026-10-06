<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

// Start session once
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    session_set_cookie_params(SESSION_LIFETIME, '/', '', false, true);
    session_start();
}

// ── Core Auth ──────────────────────────────────────────────────────────────────
function auth_check(): void {
    if (empty($_SESSION['user_id'])) {
        flash('error', 'Silakan login terlebih dahulu.');
        redirect(APP_URL . '/auth/login.php');
    }
    // Session timeout
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset(); session_destroy();
        redirect(APP_URL . '/auth/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

function auth_user(): array {
    return $_SESSION['auth_user'] ?? [];
}

function auth_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function auth_role(): string {
    return $_SESSION['auth_user']['role'] ?? '';
}

// ── Permissions ────────────────────────────────────────────────────────────────
function load_permissions(int $role_id): void {
    if (!empty($_SESSION['permissions'])) return;
    $db   = db();
    $stmt = $db->prepare(
        'SELECT p.name FROM permissions p
         JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = ?'
    );
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    $res  = $stmt->get_result();
    $perms = [];
    while ($row = $res->fetch_assoc()) $perms[] = $row['name'];
    $_SESSION['permissions'] = $perms;
}

function can(string $permission): bool {
    return in_array($permission, $_SESSION['permissions'] ?? [], true);
}

function require_permission(string $permission): void {
    auth_check();
    if (!can($permission)) {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

// ── Login / Logout ─────────────────────────────────────────────────────────────
function attempt_login(string $username, string $password): array {
    $db   = db();
    $stmt = $db->prepare(
        'SELECT u.*, r.name AS role FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1 LIMIT 1'
    );
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['ok' => false, 'msg' => 'Username atau password salah.'];
    }
    if (!$user['email_verified_at']) {
        return ['ok' => false, 'msg' => 'Email belum diverifikasi. Cek inbox Anda.'];
    }

    // Regenerate session to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']      = $user['id'];
    $_SESSION['auth_user']    = [
        'id'     => $user['id'],
        'name'   => $user['name'],
        'email'  => $user['email'],
        'role'   => $user['role'],
        'role_id'=> $user['role_id'],
    ];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['permissions']);
    load_permissions((int)$user['role_id']);

    // Update last_login
    $stmt2 = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt2->bind_param('i', $user['id']);
    $stmt2->execute();

    log_activity('login', 'auth', 'Login berhasil');
    return ['ok' => true, 'role' => $user['role']];
}

function logout(): void {
    log_activity('logout', 'auth', 'Logout');
    session_unset();
    session_destroy();
    redirect(APP_URL . '/auth/login.php');
}

// ── Email Verification ─────────────────────────────────────────────────────────
function send_verification_email(int $user_id, string $email, string $name): bool {
    $token = bin2hex(random_bytes(32));
    $db    = db();
    $stmt  = $db->prepare('UPDATE users SET verification_token = ? WHERE id = ?');
    $stmt->bind_param('si', $token, $user_id);
    $stmt->execute();

    $link = APP_URL . '/auth/verify-email.php?token=' . $token;
    $body = "<p>Halo <strong>{$name}</strong>,</p>
             <p>Klik tombol di bawah untuk memverifikasi email Anda:</p>
             <p><a href='{$link}' style='background:#198754;color:#fff;padding:10px 20px;
                border-radius:4px;text-decoration:none'>Verifikasi Email</a></p>
             <p>Atau salin link ini:<br><small>{$link}</small></p>
             <p>Link berlaku 24 jam.</p>";
    return send_mail($email, 'Verifikasi Email — ' . APP_NAME, mail_template('Verifikasi Email', $body));
}

// ── Password Reset ─────────────────────────────────────────────────────────────
function send_reset_email(string $email): bool {
    $db   = db();
    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) return false; // silent fail — don't reveal existence

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt2   = $db->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
    $stmt2->bind_param('ssi', $token, $expires, $user['id']);
    $stmt2->execute();

    $link = APP_URL . '/auth/forgot-password.php?action=reset&token=' . $token;
    $body = "<p>Halo <strong>{$user['name']}</strong>,</p>
             <p>Klik tombol di bawah untuk mereset password Anda:</p>
             <p><a href='{$link}' style='background:#198754;color:#fff;padding:10px 20px;
                border-radius:4px;text-decoration:none'>Reset Password</a></p>
             <p>Link berlaku 1 jam. Abaikan jika Anda tidak meminta ini.</p>";
    return send_mail($email, 'Reset Password — ' . APP_NAME, mail_template('Reset Password', $body));
}
