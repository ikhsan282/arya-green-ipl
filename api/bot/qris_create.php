<?php
/**
 * POST /api/bot/qris_create.php
 * Body JSON: {"bill_id": 123, "provider": "gopay" (opsional)}
 *
 * Membuat (atau mengembalikan yang masih aktif) order QRIS untuk satu tagihan
 * milik pengirim WhatsApp. Idempoten selama order sebelumnya belum kedaluwarsa.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/payment_gateway.php';

$ctx     = bot_boot(['POST']);
$db      = $ctx['db'];
$actor   = $ctx['actor'];
$unitIds = bot_actor_unit_ids($actor);
if (!$unitIds) {
    bot_error(403, 'NOT_RESIDENT', 'Nomor ini tidak memiliki unit warga aktif.');
}

$billId   = (int) ($ctx['body']['bill_id'] ?? 0);
$provider = isset($ctx['body']['provider']) ? trim((string) $ctx['body']['provider']) : '';
if ($billId <= 0) {
    bot_error(400, 'INVALID_BILL_ID', 'ID tagihan wajib diisi.');
}

$in = implode(',', array_fill(0, count($unitIds), '?'));
$st = $db->prepare(
    "SELECT b.*, bp.label AS period, u.unit_number, r.user_id AS resident_user_id
       FROM bills b
       JOIN billing_periods bp ON bp.id = b.billing_period_id
       JOIN units u ON u.id = b.unit_id
       LEFT JOIN residents r ON r.id = b.resident_id
      WHERE b.id = ? AND b.unit_id IN ({$in}) LIMIT 1"
);
$params = array_merge([$billId], $unitIds);
$st->bind_param(str_repeat('i', count($params)), ...$params);
$st->execute();
$bill = $st->get_result()->fetch_assoc();
if (!$bill) {
    bot_error(404, 'BILL_NOT_FOUND', 'Tagihan tidak ditemukan atau bukan milik unit Anda.');
}
if ($bill['status'] === 'sudah_bayar') {
    bot_error(409, 'ALREADY_PAID', 'Tagihan periode ' . $bill['period'] . ' untuk unit ' . $bill['unit_number'] . ' sudah lunas.');
}

// Tandai EXPIRED bila ada order lama yang sudah lewat batas waktu
$db->query(
    'UPDATE payment_gateway_orders SET status="EXPIRED"
      WHERE bill_id=' . (int) $billId . ' AND status="PENDING" AND expired_at IS NOT NULL AND expired_at <= NOW()'
);

// Pilih channel aktif
if ($provider !== '') {
    $channel = gateway_channel($provider);
} else {
    $channel = $db->query(
        'SELECT * FROM payment_channels
          WHERE is_active = 1 AND api_key IS NOT NULL AND api_key <> ""
            AND merchant_id IS NOT NULL AND merchant_id <> ""
          ORDER BY sort_order ASC, id ASC LIMIT 1'
    )->fetch_assoc() ?: null;
}
if (!$channel || empty($channel['api_key']) || empty($channel['merchant_id'])) {
    bot_error(503, 'CHANNEL_UNAVAILABLE', 'Pembayaran QRIS otomatis sedang tidak tersedia. Silakan gunakan metode transfer atau hubungi Bendahara.');
}
$prov = (string) $channel['provider'];

// Reuse order PENDING yang masih berlaku pada channel yang sama
$order = gateway_pending_for_bill($billId, $prov);
if ($order && !empty($order['expired_at']) && strtotime((string) $order['expired_at']) <= time()) {
    gateway_mark_expired((string) $order['order_id']);
    $order = null;
}

$billAmount = (float) $bill['total_amount'];
$fee        = gateway_fee($billAmount);
$charge     = gateway_charge_total($billAmount);

if (!$order) {
    // Rate-limit pembuatan QR baru: maks 5 order baru per jam per nomor
    $stRl = $db->prepare(
        'SELECT COUNT(*) FROM bot_request_logs
          WHERE phone=? AND endpoint="qris_create" AND http_status=200 AND created_at > NOW() - INTERVAL 1 HOUR'
    );
    $stRl->bind_param('s', $ctx['phone']);
    $stRl->execute();
    if ((int) $stRl->get_result()->fetch_row()[0] >= 5) {
        bot_error(429, 'QRIS_RATE_LIMITED', 'Batas pembuatan QRIS baru tercapai. Gunakan QRIS sebelumnya atau coba lagi nanti.');
    }

    $orderId = gateway_make_order_id($billId);
    $res = gateway_create($channel, $orderId, $charge, $bill['period']);
    if (!$res['ok']) {
        error_log('bot qris_create ' . $prov . ': ' . ($res['error'] ?? ''));
        bot_error(502, 'GATEWAY_ERROR', 'Gagal membuat kode QRIS saat ini. Silakan coba beberapa saat lagi.');
    }
    $data = $res['data'];
    if (empty($data['qris_url']) || !isset($data['total_amount'])) {
        bot_error(502, 'GATEWAY_INVALID_RESPONSE', 'Respons layanan QRIS tidak lengkap.');
    }
    if (empty($data['expired_at'])) {
        $data['expired_at'] = date('Y-m-d H:i:s', time() + 1800); // 30 menit
    }
    $uid = !empty($bill['resident_user_id']) ? (int) $bill['resident_user_id'] : null;
    gateway_store_order((int) $channel['id'], $orderId, $billId, $uid, $charge, $data, 'whatsapp');
    $order = gateway_find_order($orderId);
}

$total      = (float) $order['total_amount'];
$uniqueCode = max(0.0, $total - $charge);

bot_reply(200, [
    'order_id'       => $order['order_id'],
    'bill_id'        => $billId,
    'unit'           => $bill['unit_number'],
    'period'         => $bill['period'],
    'provider'       => $prov,
    'channel_name'   => $channel['name'],
    'total'          => bot_money($total),
    'breakdown'      => [
        'bill'        => bot_money($billAmount),
        'fee'         => bot_money($fee),
        'unique_code' => (int) round($uniqueCode),
    ],
    'qris_image_url' => $order['qris_url'],
    'qris_string'    => in_array($prov, GQ_DIRECT, true) ? $order['direct_url'] : null,
    'expires_at'     => $order['expired_at'],
    'instruction'    => 'Bayar PERSIS sebesar ' . idr($total) . ' (termasuk kode unik) sebelum '
                      . ($order['expired_at'] ?: '30 menit') . ' agar pembayaran terverifikasi otomatis.',
]);

