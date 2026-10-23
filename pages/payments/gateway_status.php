<?php
/**
 * Polling status QRIS untuk halaman bayar (JSON).
 *
 * POST + token CSRF: endpoint ini bisa memfinalisasi tagihan, jadi tidak boleh
 * dipanggil lewat GET nyasar (prefetch browser, embed, crawler).
 */
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/payment_gateway.php';

header('Content-Type: application/json; charset=utf-8');

function gw_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gw_reply(405, ['status' => 'METHOD_NOT_ALLOWED']);
if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) gw_reply(403, ['status' => 'FORBIDDEN']);

$orderId = trim((string) ($_POST['order_id'] ?? ''));
$order = $orderId !== '' ? gateway_find_order($orderId) : null;
if (!$order) gw_reply(404, ['status' => 'NOT_FOUND']);

// Warga hanya boleh memantau order miliknya sendiri.
if (auth_role() === 'warga') {
    $st = db()->prepare('SELECT 1 FROM bills b JOIN residents r ON r.id=b.resident_id WHERE b.id=? AND r.user_id=?');
    $uid = auth_id();
    $billId = (int) $order['bill_id'];
    $st->bind_param('ii', $billId, $uid);
    $st->execute();
    if (!$st->get_result()->fetch_row()) gw_reply(403, ['status' => 'FORBIDDEN']);
}

if ($order['status'] === 'PENDING') {
    $channel = [
        'api_key'     => $order['api_key'],
        'merchant_id' => $order['merchant_id'],
        'mode'        => $order['mode'],
        'provider'    => $order['provider'] ?? '',
        'config'      => $order['config'] ?? '',
    ];
    $remote = gateway_status($channel, $orderId);
    $data = is_array($remote['data'] ?? null) ? $remote['data'] : [];
    $state = strtoupper((string) ($data['status'] ?? ''));

    if ($remote['ok'] && in_array($state, ['SUCCESS', 'PAID'], true)
        && isset($data['total_amount']) && (float) $data['total_amount'] === (float) $order['total_amount']) {
        $done = gateway_finalize($orderId, (float) $data['total_amount']);
        if ($done['ok']) {
            $order['status'] = 'PAID';
            $order['payment_id'] = $done['payment_id'];
        }
    } elseif ($remote['ok'] && $state === 'EXPIRED') {
        gateway_mark_expired($orderId);
        $order['status'] = 'EXPIRED';
    }
}

gw_reply(200, [
    'status'     => $order['status'],
    'payment_id' => isset($order['payment_id']) ? (int) $order['payment_id'] : null,
]);
