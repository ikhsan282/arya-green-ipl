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
function login_client_ip(): string {
    // REMOTE_ADDR dipakai agar header X-Forwarded-For tidak bisa dipalsukan klien.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function login_rate_limit_status(string $username): array {
    static $table_exists = null;
    if ($table_exists === null) {
        $db = db();
        $check = $db->query("SHOW TABLES LIKE 'login_attempts'");
        $table_exists = $check && $check->num_rows > 0;
    }
    if (!$table_exists) {
        return ['locked' => false, 'remaining' => LOGIN_MAX_ATTEMPTS, 'retry_after' => 0];
    }

    $db = db();
    $ip = login_client_ip();
    $normalized = strtolower(trim($username));
    $normalized = substr($normalized, 0, 100); // limit panjang sesuai kolom DB

    // Cek rate limit per akun (username/email) + IP
    $lockout_seconds = LOGIN_LOCKOUT_TIME;
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_attempt
         FROM login_attempts
         WHERE ip_address=? AND username=? AND is_success=0
           AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $stmt->bind_param('ssi', $ip, $normalized, $lockout_seconds);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $failures = (int)($row['failures'] ?? 0);

    if ($failures < LOGIN_MAX_ATTEMPTS) {
        // Cek juga batas per IP (password spraying protection)
        $stmt_ip = $db->prepare(
            'SELECT COUNT(*) AS failures FROM login_attempts
             WHERE ip_address=? AND is_success=0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt_ip->bind_param('si', $ip, $lockout_seconds);
        $stmt_ip->execute();
        $ip_row = $stmt_ip->get_result()->fetch_assoc();
        $ip_failures = (int)($ip_row['failures'] ?? 0);

        if ($ip_failures < LOGIN_MAX_IP_ATTEMPTS) {
            return ['locked' => false, 'remaining' => LOGIN_MAX_ATTEMPTS - $failures, 'retry_after' => 0];
        }
    }

    // Hitung retry_after berdasarkan last_attempt jika locked
    $last = null;
    if (isset($row['last_attempt'])) {
        $last = strtotime((string)$row['last_attempt']);
    }
    $retry_after = $last ? max(1, LOGIN_LOCKOUT_TIME - (time() - $last)) : LOGIN_LOCKOUT_TIME;
    return ['locked' => true, 'remaining' => 0, 'retry_after' => $retry_after];
}

function record_login_attempt(string $username, bool $success): void {
    static $table_exists = null;
    if ($table_exists === null) {
        $db = db();
        $check = $db->query("SHOW TABLES LIKE 'login_attempts'");
        $table_exists = $check && $check->num_rows > 0;
    }
    if (!$table_exists) return;

    $db = db();
    $ip = login_client_ip();
    $normalized = substr(strtolower(trim($username)), 0, 100);
    $ok = $success ? 1 : 0;
    $stmt = $db->prepare('INSERT INTO login_attempts (ip_address,username,is_success) VALUES (?,?,?)');
    $stmt->bind_param('ssi', $ip, $normalized, $ok);
    $stmt->execute();

    if ($success) {
        $clear = $db->prepare('DELETE FROM login_attempts WHERE ip_address=? AND username=? AND is_success=0');
        $clear->bind_param('ss', $ip, $normalized);
        $clear->execute();
    } elseif (random_int(1, 100) === 1) {
        // Cleanup probabilistik agar tabel tidak tumbuh tanpa batas.
        $db->query('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    }
}

function attempt_login(string $username, string $password): array {
    $rate = login_rate_limit_status($username);
    if ($rate['locked']) {
        $minutes = max(1, (int)ceil($rate['retry_after'] / 60));
        return ['ok' => false, 'msg' => "Terlalu banyak percobaan login. Coba lagi dalam {$minutes} menit."];
    }

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
        record_login_attempt($username, false);
        return ['ok' => false, 'msg' => 'Username atau password salah.'];
    }
    if (!$user['email_verified_at']) {
        return ['ok' => false, 'msg' => 'Email belum diverifikasi. Cek inbox Anda.'];
    }

    record_login_attempt($username, true);

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
    $link_e = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name_e = e($name);
    $body = "<p>Halo <strong>{$name_e}</strong>,</p>
             <p>Klik tombol di bawah untuk memverifikasi email Anda:</p>
             <p>Atau salin link ini:<br><small>{$link_e}</small></p>
             <p>Link berlaku 24 jam.</p>";
    return send_mail($email, 'Verifikasi Email — ' . APP_NAME, mail_template('Verifikasi Email', $body, 'Verifikasi Email', $link));
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
    $link_e = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name_e = e($user['name']);
    $body = "<p>Halo <strong>{$name_e}</strong>,</p>
             <p>Klik tombol di bawah untuk mereset password Anda:</p>
             <p>Link berlaku 1 jam. Abaikan jika Anda tidak meminta ini.</p>
             <p>Jika tombol tidak dapat digunakan, salin link ini:<br><small>{$link_e}</small></p>";
    return send_mail($email, 'Reset Password — ' . APP_NAME, mail_template('Reset Password', $body, 'Reset Password', $link));
}
