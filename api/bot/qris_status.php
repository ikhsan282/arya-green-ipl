<?php
/**
 * GET /api/bot/qris_status.php?order_id=IPL-...
 *
 * Mengecek status order QRIS milik pengirim. Bila masih PENDING, endpoint ini
 * juga memeriksa ke provider (KlikQRIS / GoPay / ShopeePay) dan memfinalisasi
 * otomatis bila pembayaran sudah masuk.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/payment_gateway.php';
require_once __DIR__ . '/../../includes/receipt_link.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$actor   = $ctx['actor'];
$unitIds = bot_actor_unit_ids($actor);

$orderId = trim((string) ($_GET['order_id'] ?? ''));
if ($orderId === '') {
    bot_error(400, 'MISSING_ORDER_ID', 'Parameter order_id wajib diisi.');
}

$order = gateway_find_order($orderId);
if (!$order) {
    bot_error(404, 'ORDER_NOT_FOUND', 'Order pembayaran tidak ditemukan.');
}

// Pastikan tagihan milik unit pengirim (kecuali pengurus)
if (!$actor['is_staff']) {
    if (!$unitIds) bot_error(403, 'FORBIDDEN', 'Akses ditolak.');
    $in = implode(',', array_fill(0, count($unitIds), '?'));
    $st = $db->prepare("SELECT 1 FROM bills WHERE id=? AND unit_id IN ({$in}) LIMIT 1");
    $params = array_merge([(int) $order['bill_id']], $unitIds);
    $st->bind_param(str_repeat('i', count($params)), ...$params);
    $st->execute();
    if (!$st->get_result()->fetch_row()) {
        bot_error(403, 'FORBIDDEN', 'Order tersebut bukan milik unit Anda.');
    }
}

if ($order['status'] === 'PENDING') {
    if (!empty($order['expired_at']) && strtotime((string) $order['expired_at']) <= time()) {
        if (gateway_mark_expired($orderId)) {
            require_once __DIR__ . '/../../includes/wa_outbox.php';
            wa_event_qris_expired($orderId);
        }
        $order['status'] = 'EXPIRED';
    } else {
        $channel = [
            'id'          => (int) $order['channel_id'],
            'api_key'     => $order['api_key'],
            'merchant_id' => $order['merchant_id'],
            'mode'        => $order['mode'],
            'provider'    => $order['provider'] ?? '',
            'config'      => $order['config'] ?? '',
        ];
        $remote = gateway_status($channel, $orderId);
        if ($remote['ok']) {
            $data  = is_array($remote['data'] ?? null) ? $remote['data'] : [];
            $state = strtoupper((string) ($data['status'] ?? ''));
            if (in_array($state, ['SUCCESS', 'PAID'], true)
                && isset($data['total_amount'])
                && abs((float) $data['total_amount'] - (float) $order['total_amount']) < 0.001) {
                $done = gateway_finalize($orderId, (float) $data['total_amount']);
                if ($done['ok']) {
                    $order['status']     = 'PAID';
                    $order['payment_id'] = $done['payment_id'];
                    $order['paid_at']    = date('Y-m-d H:i:s');
                }
            } elseif ($state === 'EXPIRED') {
                if (gateway_mark_expired($orderId)) {
                    require_once __DIR__ . '/../../includes/wa_outbox.php';
                    wa_event_qris_expired($orderId);
                }
                $order['status'] = 'EXPIRED';
            }
        }
    }
}

$paymentId = !empty($order['payment_id']) ? (int) $order['payment_id'] : null;
bot_reply(200, [
    'order_id'    => $order['order_id'],
    'bill_id'     => (int) $order['bill_id'],
    'status'      => $order['status'],
    'total'       => bot_money((float) $order['total_amount']),
    'paid_at'     => $order['paid_at'],
    'expires_at'  => $order['expired_at'],
    'payment_id'  => $paymentId,
    'receipt_url' => ($order['status'] === 'PAID' && $paymentId) ? receipt_sign_url($paymentId) : null,
]);

