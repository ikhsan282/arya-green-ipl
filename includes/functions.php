<?php
require_once __DIR__ . '/../config/config.php';

// ── CSRF ──────────────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('CSRF token tidak valid.');
    }
}

// ── FLASH MESSAGES ────────────────────────────────────────────────────────────
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): string {
    if (empty($_SESSION['flash'])) return '';
    $map = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $map[$f['type']] ?? 'info';
        $html .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
               . htmlspecialchars($f['msg'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ── REDIRECT ──────────────────────────────────────────────────────────────────
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// ── SANITIZE / ESCAPE ─────────────────────────────────────────────────────────
function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean(string $v): string {
    return trim(strip_tags($v));
}

// ── PAGINATION ────────────────────────────────────────────────────────────────
function paginate(int $total, int $per_page, int $current): array {
    $total_pages = (int)ceil($total / $per_page);
    $offset      = ($current - 1) * $per_page;
    return [
        'total'       => $total,
        'per_page'    => $per_page,
        'current'     => $current,
        'total_pages' => $total_pages,
        'offset'      => max(0, $offset),
    ];
}

function render_pagination(array $p, string $url_base): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $prev = $p['current'] - 1;
    $next = $p['current'] + 1;
    $disabled = $p['current'] <= 1 ? ' disabled' : '';
    $html .= "<li class=\"page-item{$disabled}\"><a class=\"page-link\" href=\"{$url_base}&page={$prev}\">‹</a></li>";
    for ($i = 1; $i <= $p['total_pages']; $i++) {
        $active = $i === $p['current'] ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"{$url_base}&page={$i}\">{$i}</a></li>";
    }
    $disabled = $p['current'] >= $p['total_pages'] ? ' disabled' : '';
    $html .= "<li class=\"page-item{$disabled}\"><a class=\"page-link\" href=\"{$url_base}&page={$next}\">›</a></li>";
    $html .= '</ul></nav>';
    return $html;
}

// ── MONEY ─────────────────────────────────────────────────────────────────────
function idr(float $v): string {
    return 'Rp ' . number_format($v, 0, ',', '.');
}

function terbilang(float $n): string {
    $n = (int)round($n);
    if ($n === 0) return 'nol';
    $s = ['','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan',
          'sepuluh','sebelas','dua belas','tiga belas','empat belas','lima belas',
          'enam belas','tujuh belas','delapan belas','sembilan belas'];
    $r = function(int $n) use (&$r, $s): string {
        if ($n === 0)        return '';
        if ($n < 20)         return $s[$n];
        if ($n < 100)        return $s[(int)($n/10)].' puluh'.($n%10 ? ' '.$s[$n%10] : '');
        if ($n < 200)        return 'seratus'.($n%100 ? ' '.$r($n%100) : '');
        if ($n < 1000)       return $s[(int)($n/100)].' ratus'.($n%100 ? ' '.$r($n%100) : '');
        if ($n < 2000)       return 'seribu'.($n%1000 ? ' '.$r($n%1000) : '');
        if ($n < 1000000)    return $r((int)($n/1000)).' ribu'.($n%1000 ? ' '.$r($n%1000) : '');
        if ($n < 1000000000) return $r((int)($n/1000000)).' juta'.($n%1000000 ? ' '.$r($n%1000000) : '');
        return $r((int)($n/1000000000)).' miliar'.($n%1000000000 ? ' '.$r($n%1000000000) : '');
    };
    return ucfirst(trim($r($n)));
}

// ── DATES ─────────────────────────────────────────────────────────────────────
function fmt_date(string $date, string $fmt = 'd M Y'): string {
    if (!$date) return '-';
    return date($fmt, strtotime($date));
}

function bulan_indo(int $m): string {
    $b = ['','Januari','Februari','Maret','April','Mei','Juni',
          'Juli','Agustus','September','Oktober','November','Desember'];
    return $b[$m] ?? '';
}

function period_label(int $year, int $month): string {
    return bulan_indo($month) . ' ' . $year;
}

// ── FILE UPLOAD ───────────────────────────────────────────────────────────────
// ponytail: satu helper untuk semua upload (bukti bayar, QR, foto); folder & daftar
// tipe jadi parameter. Upgrade path: pindah ke storage service jika perlu.
function upload_file(array $file, string $dest_dir = UPLOAD_DIR, array $allowed = UPLOAD_ALLOWED): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error: ' . $file['error']);
    }
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        throw new RuntimeException('Ukuran file melebihi batas 2 MB.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Tipe file tidak diizinkan (JPG, PNG, WebP, PDF).');
    }
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $dest     = $dest_dir . $filename;
    if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal menyimpan file.');
    }
    return $filename;
}

function upload_proof(array $file): string {
    return upload_file($file);
}

// ── EMAIL ─────────────────────────────────────────────────────────────────────
// ponytail: SMTP socket sederhana tanpa vendor/PHPMailer (RFC 5321 auth login).
// Fallback otomatis ke mail() jika SMTP_HOST kosong.
function send_mail_smtp(string $to, string $subject, string $body): bool {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $secure = strtolower(SMTP_SECURE);
    $timeout = defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 15;

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0; $errstr = '';
    $socket = @fsockopen($remote, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        error_log("SMTP connection failed to {$remote}:{$port}: {$errstr} ({$errno})");
        return false;
    }

    // Set socket timeout for read/write operations
    stream_set_timeout($socket, $timeout);

    $read = function() use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^[0-9]{3}[ ].*$/m', $line)) break;
        }
        return $data;
    };

    $write = function(string $cmd) use ($socket, $read): string {
        fputs($socket, $cmd . "\r\n");
        return $read();
    };

    $init = $read();
    if (!str_starts_with($init, '220')) { fclose($socket); return false; }

    // EHLO with sanitized hostname (no CRLF injection)
    $ehlo_host = preg_replace('/[^\w\.-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
    $hello = $write('EHLO ' . $ehlo_host);

    // StartTLS jika port 587 atau secure=tls
    if ($secure === 'tls') {
        $tls = $write('STARTTLS');
        if (!str_starts_with($tls, '220')) { fclose($socket); return false; }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket); return false;
        }
        $hello = $write('EHLO ' . $ehlo_host);
    }

    // Auth Login jika kredensial diisi
    if ($user && $pass) {
        $auth = $write('AUTH LOGIN');
        if (!str_starts_with($auth, '334')) { fclose($socket); return false; }
        $u_res = $write(base64_encode($user));
        if (!str_starts_with($u_res, '334')) { fclose($socket); return false; }
        $p_res = $write(base64_encode($pass));
        if (!str_starts_with($p_res, '235')) {
            error_log("SMTP authentication failed for user {$user}");
            fclose($socket); return false;
        }
    }

    $from_mail = MAIL_FROM;
    $from_name = MAIL_FROM_NAME;

    $m_from = $write('MAIL FROM:<' . $from_mail . '>');
    if (!str_starts_with($m_from, '250')) { fclose($socket); return false; }

    $rcpt = $write('RCPT TO:<' . $to . '>');
    if (!str_starts_with($rcpt, '250') && !str_starts_with($rcpt, '251')) { fclose($socket); return false; }

    $data = $write('DATA');
    if (!str_starts_with($data, '354')) { fclose($socket); return false; }

    // Normalize line endings to CRLF per RFC 5321
    $body = preg_replace('/(?<!\r)\n/', "\r\n", $body);
    // Dot-stuffing: lines starting with '.' get an extra '.'
    $body = preg_replace('/^\./m', '..', $body);

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from_mail}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "X-Mailer: AryaGreen-IPL/1.0\r\n";

    $message = $headers . "\r\n" . $body . "\r\n.";
    $send = $write($message);
    $write('QUIT');
    fclose($socket);

    return str_starts_with($send, '250');
}

function send_mail(string $to, string $subject, string $body): bool {
    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        if (send_mail_smtp($to, $subject, $body)) {
            return true;
        }
        error_log("SMTP failed, attempting mail() fallback");
    }
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    return mail($to, $subject, $body, $headers);
}

function mail_template(string $title, string $body_html): string {
    $app = APP_NAME;
    return <<<HTML
    <!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px">
    <div style="max-width:520px;margin:auto;background:#fff;border-radius:8px;overflow:hidden">
      <div style="background:#198754;padding:20px;color:#fff;text-align:center">
        <h2 style="margin:0">{$app}</h2>
      </div>
      <div style="padding:24px">
        <h3>{$title}</h3>
        {$body_html}
        <hr style="margin:24px 0">
        <p style="color:#999;font-size:12px;text-align:center">
          &copy; {$app} — Jangan balas email ini.
        </p>
      </div>
    </div>
    </body></html>
    HTML;
}

// ── ACTIVITY LOG ──────────────────────────────────────────────────────────────
function log_activity(string $action, string $module, string $desc = ''): void {
    $uid = $_SESSION['user_id'] ?? null;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $db  = db();
    $stmt = $db->prepare(
        'INSERT INTO activity_logs (user_id, action, module, description, ip_address) VALUES (?,?,?,?,?)'
    );
    $stmt->bind_param('issss', $uid, $action, $module, $desc, $ip);
    $stmt->execute();
}

// ── STATUS BADGES ─────────────────────────────────────────────────────────────
function bill_status_badge(string $status): string {
    $map = [
        'belum_bayar' => ['warning',  'Belum Bayar'],
        'sudah_bayar' => ['success',  'Sudah Bayar'],
        'terlambat'   => ['danger',   'Terlambat'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return "<span class=\"badge bg-{$cls}\">{$label}</span>";
}

function payment_status_badge(string $status): string {
    $map = [
        'pending'  => ['warning', 'Menunggu Verifikasi'],
        'verified' => ['success', 'Terverifikasi'],
        'rejected' => ['danger',  'Ditolak'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return "<span class=\"badge bg-{$cls}\">{$label}</span>";
}

function unit_status_badge(string $status): string {
    $map = [
        'dihuni' => ['success', 'Dihuni'],
        'kosong' => ['secondary','Kosong'],
        'dijual' => ['info',    'Dijual'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return "<span class=\"badge bg-{$cls}\">{$label}</span>";
}
