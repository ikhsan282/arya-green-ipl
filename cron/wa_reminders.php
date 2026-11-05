<?php
/**
 * Cron harian (08:00 WIB): antre pengingat tagihan jatuh tempo & terlambat ke wa_outbox,
 * lalu langsung kirim batch pertama bila BOT_WEBHOOK_URL aktif.
 *
 * Jadwal cPanel:
 *   0 8 * * * /usr/local/bin/php /home/USER/public_html/arya-green-ipl/cron/wa_reminders.php
 */
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/wa_outbox.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $lock = cron_acquire_lock('wa_reminders');
    if (!$lock) exit(0);
    $queued = wa_run_reminders();
    $sent   = wa_dispatch_batch(50);
    echo json_encode(['queued' => $queued, 'dispatched' => $sent]) . PHP_EOL;
}

