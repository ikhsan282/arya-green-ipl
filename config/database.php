<?php
// Database configuration - change these for cPanel deployment
define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'db_arya_green_ipl');
define('DB_PORT',     3306);
define('DB_CHARSET',  'utf8mb4');

function db(): mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset(DB_CHARSET);
    } catch (mysqli_sql_exception $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        die(json_encode(['error' => 'Koneksi database gagal. Hubungi administrator.']));
    }
    return $conn;
}
