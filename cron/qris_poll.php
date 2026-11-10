<?php
/**
 * Cron tiap 1 menit: memantau order QRIS yang masih PENDING.
 *
 * Penting untuk GoPay/ShopeePay Direct dan order yang dibuat lewat WhatsApp
 * karena tidak ada browser yang melakukan polling `gateway_status.php`.
 *
 * Jadwal cPanel:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/arya-green-ipl/cron/qris_poll.php
 */
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/wa_outbox.php';

function run_qris_poll(): array
{
    $db    = db();
    $stats = ['checked' => 0, 'paid' => 0, 'expired' => 0, 'reminded' => 0, 'errors' => 0];

    // 1. Tandai order PENDING yang sudah melewati expired_at (atau >35 menit bila expired_at kosong)
    $expRows = $db->query(
        'SELECT order_id FROM payment_gateway_orders
          WHERE status="PENDING"
            AND ((expired_at IS NOT NULL AND expired_at <= NOW())
                 OR (expired_at IS NULL AND created_at <= NOW() - INTERVAL 35 MINUTE))
          LIMIT 100'
    )->fetch_all(MYSQLI_ASSOC);

    foreach ($expRows as $er) {
        $oid = (string) $er['order_id'];
        if (gateway_mark_expired($oid)) {
            wa_event_qris_expired($oid);
            $stats['expired']++;
        }
    }

    // 2. Order WhatsApp PENDING yang akan kedaluwarsa dalam <= 10 menit (pengingat ramah 1x via uq_wa_dedup)
    $expSoonRows = $db->query(
        'SELECT order_id FROM payment_gateway_orders
          WHERE status="PENDING"
            AND source="whatsapp"
            AND expired_at IS NOT NULL
            AND expired_at > NOW()
            AND expired_at <= DATE_ADD(NOW(), INTERVAL 10 MINUTE)
          LIMIT 50'
    )->fetch_all(MYSQLI_ASSOC);

    foreach ($expSoonRows as $esr) {
        $stats['reminded'] += wa_event_qris_expiring_soon((string) $esr['order_id']);
    }

    // 3. Periksa order PENDING yang masih aktif (maks 30 terbaru)
    $pending = $db->query(
        'SELECT o.*, c.api_key, c.merchant_id, c.config, c.mode, c.provider
           FROM payment_gateway_orders o
           JOIN payment_channels c ON c.id = o.channel_id
          WHERE o.status = "PENDING"
          ORDER BY o.id ASC LIMIT 30'
    )->fetch_all(MYSQLI_ASSOC);

    foreach ($pending as $order) {
        $stats['checked']++;
        $orderId = (string) $order['order_id'];
        $channel = [
            'id'          => (int) $order['channel_id'],
            'api_key'     => $order['api_key'],
            'merchant_id' => $order['merchant_id'],
            'mode'        => $order['mode'],
            'provider'    => $order['provider'] ?? '',
            'config'      => $order['config'] ?? '',
        ];
        $remote = gateway_status($channel, $orderId);
        if (!$remote['ok']) {
            $stats['errors']++;
            continue;
        }
        $data  = is_array($remote['data'] ?? null) ? $remote['data'] : [];
        $state = strtoupper((string) ($data['status'] ?? ''));

        if (in_array($state, ['SUCCESS', 'PAID'], true)
            && isset($data['total_amount'])
            && abs((float) $data['total_amount'] - (float) $order['total_amount']) < 0.001) {
            $done = gateway_finalize($orderId, (float) $data['total_amount']);
            if ($done['ok'] && $done['changed']) {
                $stats['paid']++;
            }
        } elseif ($state === 'EXPIRED') {
            if (gateway_mark_expired($orderId)) {
                wa_event_qris_expired($orderId);
                $stats['expired']++;
            }
        }
    }
    return $stats;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $lock = cron_acquire_lock('qris_poll');
    if (!$lock) exit(0);
    $res = run_qris_poll();
    if ($res['paid'] > 0 || $res['expired'] > 0 || $res['reminded'] > 0) {
        wa_dispatch_batch(50);
    }
    echo json_encode($res) . PHP_EOL;
}

