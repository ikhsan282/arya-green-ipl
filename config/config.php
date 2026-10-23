<?php
// Application configuration
define('APP_NAME',     'Arya Green Pamulang');
define('APP_TAGLINE',  'Sistem Manajemen IPL');
define('APP_VERSION',  '1.0.0');
define('APP_URL',      'http://localhost:8080/arya-green-ipl'); // change for cPanel

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
// Opsi 1: PHP mail() bawaan cPanel (default) — kosongkan SMTP_HOST
// Opsi 2: SMTP Relay — isi SMTP_HOST untuk mengaktifkan SMTP socket
//
// ==== HOSTINGER (aktif) ====
// Host: smtp.hostinger.com (bukan mail.domain, itu untuk IMAP/POP)
// Akun email dibuat di hPanel → Email → Email Accounts
// Port 465 (SSL langsung) sering diblokir di jaringan tertentu; 587 + STARTTLS
// adalah jalur paling aman — keduanya didukung.
define('MAIL_FROM',       'noreply@aryagreenpamulang.my.id');
define('MAIL_FROM_NAME',  APP_NAME);
define('SMTP_HOST',       'smtp.hostinger.com');
define('SMTP_PORT',       587);      // 587 = STARTTLS (disarankan), 465 = SSL
define('SMTP_USER',       'noreply@aryagreenpamulang.my.id'); // harus sama dengan MAIL_FROM
define('SMTP_PASS',       'PASSWORD_EMAIL_DI_HPANEL');        // password akun email, bukan password hPanel
define('SMTP_SECURE',     'tls');    // 'tls' untuk 587, 'ssl' untuk 465
define('SMTP_TIMEOUT',    15);       // detik

// ==== GMAIL (contoh, nonaktif — hapus komentar untuk memakai) ====
// Syarat: aktifkan 2FA di akun Google, lalu buat App Password
// (Google Account → Security → App passwords) — password biasa DITOLAK.
// define('MAIL_FROM',      'namakamu@gmail.com');
// define('SMTP_HOST',      'smtp.gmail.com');
// define('SMTP_PORT',      465);      // 465 = SSL; pakai 587 + 'tls' bila 465 diblokir
// define('SMTP_USER',      'namakamu@gmail.com');
// define('SMTP_PASS',      'xxxx xxxx xxxx xxxx'); // App Password 16 digit
// define('SMTP_SECURE',    'ssl');
// define('SMTP_TIMEOUT',   15);

// Rate Limiting (Login Brute Force Protection)
define('LOGIN_MAX_ATTEMPTS',    5);     // Maksimal gagal untuk satu akun
define('LOGIN_MAX_IP_ATTEMPTS', 20);    // Maksimal gagal dari satu IP (cegah password spraying)
define('LOGIN_LOCKOUT_TIME',     900);   // 15 menit (dalam detik)

// Fine / denda (IDR per day after due date)
define('FINE_PER_DAY', 5000);

// Error reporting (set to 0 on production)
define('APP_DEBUG', false); // set true only for local development
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Autoload config & db
require_once __DIR__ . '/database.php';
