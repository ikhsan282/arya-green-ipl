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

// ── EXPORT CSV ───────────────────────────────────────────────────────────────
function header_csv_download(string $filename): void {
    // RFC 6266 & RFC 5987: ASCII fallback + filename*=UTF-8''... untuk karakter non-ASCII & spasi
    $ascii   = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
    $encoded = rawurlencode($filename);
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$encoded}");
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
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
function mail_plain_text(string $html): string {
    $html = preg_replace('/<(br\s*\/?>|\/p>|\/div>|\/tr>|\/h[1-6]>)/i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\n[ \t]+/", "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}

// ponytail: SMTP socket sederhana tanpa vendor/PHPMailer (RFC 5321 auth login).
// Fallback otomatis ke mail() jika SMTP_HOST kosong.
function send_mail_smtp(string $to, string $subject, string $body, string $plain = ''): bool {
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

    // MIME multipart/alternative: HTML + plain text fallback
    $boundary = '=_AGIPL_' . bin2hex(random_bytes(8));
    $plain    = $plain !== '' ? $plain : mail_plain_text($body);
    $plain    = preg_replace('/(?<!\r)\n/', "\r\n", $plain);
    $plain    = preg_replace('/^\./m', '..', $plain);

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$from_name} <{$from_mail}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "X-Mailer: AryaGreen-IPL/1.0\r\n";

    $message  = $headers;
    $message .= "\r\n--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $plain . "\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $body . "\r\n";
    $message .= "--{$boundary}--\r\n.";
    $send = $write($message);
    $write('QUIT');
    fclose($socket);

    return str_starts_with($send, '250');
}

function send_mail(string $to, string $subject, string $body, string $plain = ''): bool {
    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        if (send_mail_smtp($to, $subject, $body, $plain)) {
            return true;
        }
        error_log("SMTP failed, attempting mail() fallback");
    }
    $plain = $plain !== '' ? $plain : mail_plain_text($body);
    $boundary = '=_AGIPL_' . bin2hex(random_bytes(8));
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    $message  = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $plain . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $body . "\r\n"
              . "--{$boundary}--";
    return mail($to, $subject, $message, $headers);
}

function mail_template(string $title, string $body_html, string $cta_text = '', string $cta_url = ''): string {
    $app_name = e(APP_NAME);
    $app_url  = e(APP_URL);
    $year     = date('Y');
    $title_e  = e($title);

    $cta_html = '';
    if ($cta_text !== '' && $cta_url !== '') {
        $cta_text_e = e($cta_text);
        $cta_url_e  = htmlspecialchars($cta_url, ENT_QUOTES, 'UTF-8');
        $cta_html = <<<HTML
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:28px 0 16px;">
          <tr>
            <td align="center">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{$cta_url_e}" style="height:44px;v-text-anchor:middle;width:240px;" arcsize="12%" stroke="f" fillcolor="#198754">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:sans-serif;font-size:15px;font-weight:bold;">{$cta_text_e}</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <a href="{$cta_url_e}" target="_blank"
                 style="display:inline-block;background-color:#198754;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;line-height:44px;text-align:center;text-decoration:none;padding:0 28px;border-radius:6px;box-shadow:0 2px 4px rgba(25,135,84,0.3);-webkit-text-size-adjust:none;">
                {$cta_text_e} &rarr;
              </a>
              <!--<![endif]-->
            </td>
          </tr>
        </table>
HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{$title_e} — {$app_name}</title>
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    body { margin: 0; padding: 0; width: 100% !important; background-color: #f3f4f6; }
    @media screen and (max-width: 600px) {
      .container-table { width: 100% !important; }
      .content-padding { padding: 20px !important; }
      .header-padding { padding: 20px 16px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:24px 0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
  <!-- Preheader text for inbox preview -->
  <div style="display:none;font-size:1px;color:#f3f4f6;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
    {$title_e} — Pemberitahuan resmi dari {$app_name}.
  </div>

  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td align="center" style="padding:0 12px;">
        <!-- Email Container Card -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" class="container-table" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.06);border:1px solid #e5e7eb;">
          <!-- Header Banner -->
          <tr>
            <td class="header-padding" style="background:linear-gradient(135deg,#198754 0%,#0d6efd 100%);background-color:#198754;padding:28px 24px;text-align:center;">
              <div style="display:inline-block;background-color:rgba(255,255,255,0.2);padding:6px 12px;border-radius:20px;margin-bottom:8px;">
                <span style="color:#ffffff;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">Sistem Manajemen IPL</span>
              </div>
              <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.3px;">{$app_name}</h1>
            </td>
          </tr>

          <!-- Title Bar -->
          <tr>
            <td style="padding:20px 28px 0;text-align:left;">
              <h2 style="margin:0;color:#111827;font-size:18px;font-weight:600;border-bottom:2px solid #e5e7eb;padding-bottom:12px;">
                {$title_e}
              </h2>
            </td>
          </tr>

          <!-- Main Body -->
          <tr>
            <td class="content-padding" style="padding:20px 28px 28px;color:#374151;font-size:14px;line-height:1.65;">
              {$body_html}
              {$cta_html}
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f9fafb;padding:20px 28px;border-top:1px solid #e5e7eb;text-align:center;font-size:12px;color:#6b7280;line-height:1.5;">
              <p style="margin:0 0 6px 0;font-weight:600;color:#374151;">&copy; {$year} {$app_name}</p>
              <p style="margin:0 0 8px 0;">Email ini dikirim secara otomatis oleh sistem. Mohon untuk tidak membalas email ini secara langsung.</p>
              <p style="margin:0;">
                <a href="{$app_url}" target="_blank" style="color:#198754;text-decoration:none;font-weight:500;">Buka Portal Warga</a>
                &bull;
                <a href="{$app_url}/pages/public/kas.php" target="_blank" style="color:#198754;text-decoration:none;font-weight:500;">Kas Publik</a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
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
