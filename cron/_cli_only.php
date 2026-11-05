<?php
/**
 * Penjaga script cron: hanya boleh dijalankan dari CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Kunci non-blocking supaya dua siklus cron tidak berjalan tumpang tindih.
 * @return resource|null handle lock, atau null bila instance lain sedang berjalan.
 */
function cron_acquire_lock(string $name)
{
    $file = sys_get_temp_dir() . '/arya_green_ipl_' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '.lock';
    $fp   = @fopen($file, 'c+');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

