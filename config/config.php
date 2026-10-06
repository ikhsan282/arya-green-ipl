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

// Email (PHP mail() — compatible with cPanel)
define('MAIL_FROM',      'noreply@aryagreen.id');
define('MAIL_FROM_NAME', APP_NAME);

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
