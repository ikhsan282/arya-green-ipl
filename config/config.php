<?php
// Application configuration
define('APP_NAME',     'Arya Green Pamulang');
define('APP_TAGLINE',  'Sistem Manajemen IPL');
define('APP_VERSION',  '1.0.0');
define('APP_URL',      'http://localhost/arya-green-ipl'); // change for cPanel

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Session
define('SESSION_LIFETIME', 7200); // 2 hours

// Upload
define('UPLOAD_DIR',      __DIR__ . '/../uploads/payment_proofs/');
define('UPLOAD_URL',      APP_URL . '/uploads/payment_proofs/');
define('UPLOAD_MAX_SIZE', 2 * 1024 * 1024); // 2 MB
define('UPLOAD_ALLOWED',  ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

// Email Configuration
// Opsi 1: PHP mail() bawaan cPanel (default)
// Opsi 2: SMTP Relay (isi SMTP_HOST untuk mengaktifkan SMTP socket, misal Gmail, Mailgun, SendGrid)
define('MAIL_FROM',       'noreply@aryagreen.id');
define('MAIL_FROM_NAME',  APP_NAME);
define('SMTP_HOST',       ''); // contoh: 'mail.aryagreen.id' atau 'smtp.gmail.com'
define('SMTP_PORT',       587); // 587 (TLS), 465 (SSL), 25
define('SMTP_USER',       '');
define('SMTP_PASS',       '');
define('SMTP_SECURE',     'tls'); // 'tls', 'ssl', atau '' (none)
define('SMTP_TIMEOUT',    15); // detik

// Rate Limiting (Login Brute Force Protection)
define('LOGIN_MAX_ATTEMPTS',    5);     // Maksimal gagal untuk satu akun
define('LOGIN_MAX_IP_ATTEMPTS', 20);    // Maksimal gagal dari satu IP (cegah password spraying)
define('LOGIN_LOCKOUT_TIME',     900);   // 15 menit (dalam detik)

// Fine / denda (IDR per day after due date)
define('FINE_PER_DAY', 5000);

// Error reporting (set to 0 on production)
define('APP_DEBUG', true);
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Autoload config & db
require_once __DIR__ . '/database.php';
