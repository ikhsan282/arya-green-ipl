<?php
/**
 * Penjaga test: file test hanya boleh dijalankan dari CLI.
 *
 * Folder tests/ sudah diblokir lewat .htaccess, tapi aturan itu hanya berlaku
 * di Apache. Bila ada server lain (nginx, PHP built-in) yang menyajikan folder
 * ini, test tetap tidak boleh dieksekusi lewat HTTP — beberapa di antaranya
 * menulis ke database dan memuat konfigurasi internal.
 *
 * Dipakai di baris paling atas setiap file test:
 *   require_once __DIR__ . '/_cli_only.php';
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
