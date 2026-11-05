<?php
/**
 * Cron tiap 1 menit: mengirim antrean notifikasi `wa_outbox` ke webhook Hermes.
 *
 * Jadwal cPanel:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/arya-green-ipl/cron/wa_dispatch.php
 */
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/wa_outbox.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $lock = cron_acquire_lock('wa_dispatch');
    if (!$lock) exit(0);
    $res = wa_dispatch_batch(50);
    echo json_encode($res) . PHP_EOL;
}

