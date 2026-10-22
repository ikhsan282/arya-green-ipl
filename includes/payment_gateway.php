<?php
/**
 * Klien gateway pembayaran QRIS (KlikQRIS).
 *
 * Dokumentasi: https://www.klikqris.com/dokumentasi-api
 * - PG KlikQRIS : POST /api/qris/create, GET /api/qris/status/{order_id}
 * - MY PG       : POST /api/qrisv2/create, GET /api/qrisv2/status/{merchant}/{order}
 * - Sandbox     : semua di atas dengan prefix /api/sandbox
 *
 * PENTING: field `total_amount` dari response berisi kode unik dan itulah
 * nominal yang harus dibayar pembeli. Jangan pakai `amount`.
 */

const KQ_BASE = 'https://klikqris.com/api';

/** Ambil channel aktif (satu channel per provider untuk saat ini). */
function gateway_channel(string $provider = 'klikqris'): ?array
{
    $st = db()->prepare('SELECT * FROM payment_channels WHERE provider=? AND is_active=1 ORDER BY sort_order, id LIMIT 1');
    $st->bind_param('s', $provider);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/** Base URL gateway: sandbox pakai prefix berbeda. */
function gateway_base_url(array $channel): string
{
    // ponytail: env KLIKQRIS_BASE_URL hanya untuk tes lokal (mock server); hapus bila tak perlu.
    $o = getenv('KLIKQRIS_BASE_URL');
    if ($o && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $o)) return rtrim($o, '/'); // hanya mock lokal
    return $channel['mode'] === 'sandbox' ? KQ_BASE . '/sandbox' : KQ_BASE;
}

/** Nama path endpoint create/status sesuai provider+mode. */
function gateway_paths(array $channel): array
{
    // MY PG pakai /qrisv2 di produksi. Sandbox hanya menyediakan /qris/*.
    $v2 = ($channel['provider'] === 'klikqris_my') && $channel['mode'] === 'production';
    return [
        'create' => $v2 ? '/qrisv2/create' : '/qris/create',
        'status' => $v2 ? '/qrisv2/status/%s/%s' : '/qris/status/%s',
    ];
}

/** Header autentikasi wajib untuk setiap request. */
function gateway_headers(array $channel): array
{
    return [
        'Content-Type: application/json',
        'x-api-key: ' . $channel['api_key'],
        'id_merchant: ' . $channel['merchant_id'],
    ];
}

/** HTTP request sederhana (cURL, timeout 20 dtk). */
function gateway_http(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http' => $code, 'error' => 'Koneksi ke gateway gagal.', 'json' => null];
    }
    $json = json_decode((string) $raw, true);
    // ok = HTTP 2xx + JSON valid; flag `status` dicek pemanggil (create).
    return [
        'ok'    => $code >= 200 && $code < 300 && is_array($json),
        'http'  => $code,
        'error' => is_array($json) && is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 200) : 'Respons gateway tidak dikenali.',
        'json'  => $json,
    ];
}

/**
 * Biaya penanganan QRIS untuk tagihan besar.
 *
 * MDR KlikQRIS 0,5% hanya berlaku di atas Rp 200.000 (di bawahnya gratis).
 * Warga membayar tambahan flat Rp 1.000 supaya MDR tidak menggerus IPL.
 * Tagihan persis Rp 200.000 tetap gratis → tidak kena tambahan.
 *
 * Mengembalikan total yang harus ditagihkan (tagihan + biaya penanganan).
 */
function gateway_fee(float $billAmount): float
{
    return $billAmount > 200000 ? 1000.0 : 0.0;
}

/** Total tagihan QRIS = nominal tagihan + biaya penanganan. */
function gateway_charge_total(float $billAmount): float
{
    return $billAmount + gateway_fee($billAmount);
}

/**
 * Buat transaksi QRIS baru.
 * @param float $amount total yang ditagihkan (sudah termasuk biaya penanganan).
 * @return array{ok:bool,error:?string,data:?array}
 */
function gateway_create(array $channel, string $orderId, float $amount, string $note = ''): array
{
    $paths = gateway_paths($channel);
    $body  = [
        'order_id'    => $orderId,
        'id_merchant' => $channel['merchant_id'],
        'amount'      => (int) round($amount),
    ];
    if ($note !== '') {
        $body['keterangan'] = mb_substr($note, 0, 200);
    }

    $res = gateway_http('POST', gateway_base_url($channel) . $paths['create'], gateway_headers($channel), $body);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?: ('HTTP ' . $res['http']), 'data' => null];
    }
    $data = $res['json']['data'] ?? null;
    if (empty($res['json']['status']) || !is_array($data)) {
        return ['ok' => false, 'error' => 'Respons gateway tanpa data transaksi.', 'data' => null];
    }
    return ['ok' => true, 'error' => null, 'data' => $data];
}

/** Lunas menurut gateway: status SUCCESS atau PAID (case-insensitive). */
function gateway_is_paid(array $statusData): bool
{
    return in_array(strtoupper((string) ($statusData['status'] ?? '')), ['SUCCESS', 'PAID'], true);
}

/** Cek status transaksi di gateway. */
function gateway_status(array $channel, string $orderId): array
{
    $paths = gateway_paths($channel);
    $v2    = str_contains($paths['status'], '%s/%s');
    $url   = gateway_base_url($channel)
        . ($v2 ? sprintf($paths['status'], $channel['merchant_id'], $orderId) : sprintf($paths['status'], $orderId));

    $res = gateway_http('GET', $url, gateway_headers($channel));
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?: ('HTTP ' . $res['http']), 'data' => null];
    }
    $data = $res['json']['data'] ?? null;
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Respons status gateway tanpa data.', 'data' => null];
    }
    return ['ok' => true, 'error' => null, 'data' => $data];
}

/**
 * Simpan order gateway. Nominal yang ditagih = total_amount (sudah ada kode unik).
 */
function gateway_store_order(int $channelId, string $orderId, ?int $billId, ?int $userId, float $amount, array $data): int
{
    $total   = isset($data['total_amount']) ? (float) $data['total_amount'] : $amount;
    $qrisUrl = $data['qris_url'] ?? null;
    $direct  = $data['direct_url'] ?? null;
    $sig     = $data['signature'] ?? null;
    $type    = $data['type'] ?? null;
    $expired = !empty($data['expired_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['expired_at'])) : null;
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $st = db()->prepare(
        'INSERT INTO payment_gateway_orders
           (channel_id, order_id, bill_id, user_id, amount, total_amount, status, qris_url, direct_url, signature, gateway_type, payload, expired_at)
         VALUES (?,?,?,?,?,?,"PENDING",?,?,?,?,?,?)'
    );
    $st->bind_param('isiiddssssss', $channelId, $orderId, $billId, $userId, $amount, $total, $qrisUrl, $direct, $sig, $type, $payload, $expired);
    $st->execute();
    return (int) db()->insert_id;
}

/** Ambil order gateway berdasarkan order_id. */
function gateway_find_order(string $orderId): ?array
{
    $st = db()->prepare('SELECT o.*, c.api_key, c.merchant_id, c.mode, c.provider, c.is_active AS channel_active
                         FROM payment_gateway_orders o
                         JOIN payment_channels c ON c.id = o.channel_id
                         WHERE o.order_id=? LIMIT 1');
    $st->bind_param('s', $orderId);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/** Order gateway terbaru yang masih PENDING untuk satu tagihan. */
function gateway_pending_for_bill(int $billId): ?array
{
    $st = db()->prepare('SELECT * FROM payment_gateway_orders WHERE bill_id=? AND status="PENDING" ORDER BY id DESC LIMIT 1');
    $st->bind_param('i', $billId);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/**
 * Tandai order PAID. Idempotent: hanya efek pertama yang mengubah status.
 * @return array{changed:bool,order:?array,error:?string}
 */
function gateway_mark_paid(string $orderId, ?float $paidAmount = null, ?string $gatewayType = null): array
{
    $order = gateway_find_order($orderId);
    if (!$order) {
        return ['changed' => false, 'order' => null, 'error' => 'Order gateway tidak ditemukan.'];
    }
    if ($order['status'] === 'PAID') {
        return ['changed' => false, 'order' => $order, 'error' => null]; // sudah diproses, abaikan
    }

    $paid = $paidAmount !== null ? $paidAmount : (float) $order['total_amount'];
    $type = $gatewayType ?: $order['gateway_type'];
    $st = db()->prepare('UPDATE payment_gateway_orders SET status="PAID", paid_at=NOW(), total_amount=?, gateway_type=? WHERE id=? AND status<>"PAID"');
    $st->bind_param('dsi', $paid, $type, $order['id']);
    $st->execute();
    $changed = $st->affected_rows > 0;
    $order['status']    = 'PAID';
    $order['paid_at']   = date('Y-m-d H:i:s');
    $order['total_amount'] = $paid;
    return ['changed' => $changed, 'order' => $order, 'error' => null];
}

/** Tandai order EXPIRED (hanya kalau masih PENDING). */
function gateway_mark_expired(string $orderId): bool
{
    $st = db()->prepare('UPDATE payment_gateway_orders SET status="EXPIRED" WHERE order_id=? AND status="PENDING"');
    $st->bind_param('s', $orderId);
    $st->execute();
    return $st->affected_rows > 0;
}

/** Order ID unik dan mudah dibaca: IPL-<bill>-<waktu>-<acak>. */
function gateway_make_order_id(?int $billId): string
{
    return sprintf('IPL-%s-%s-%s', $billId ?? 0, date('ymdHis'), bin2hex(random_bytes(2)));
}

/** URL webhook aplikasi ini (didaftarkan manual di dashboard KlikQRIS; API create tidak punya callback_url). */
function gateway_webhook_url(array $channel): string
{
    $url = APP_URL . '/pages/payments/gateway_webhook.php';
    if (!empty($channel['webhook_token'])) {
        $url .= '?token=' . urlencode((string) $channel['webhook_token']);
    }
    return $url;
}

/** Finalize verified gateway order and payment atomically; safe to retry. */
function gateway_finalize(string $orderId, ?float $paid = null): array
{
    $db = db();
    try {
        $db->begin_transaction();
        $s = $db->prepare('SELECT * FROM payment_gateway_orders WHERE order_id=? FOR UPDATE');
        $s->bind_param('s', $orderId);
        $s->execute();
        $order = $s->get_result()->fetch_assoc();
        if (!$order) throw new RuntimeException('Order gateway tidak ditemukan.');
        if ($order['status'] === 'PAID') {
            $db->commit();
            return ['ok'=>true,'changed'=>false,'payment_id'=>$order['payment_id'] ? (int)$order['payment_id'] : null,'error'=>null];
        }
        $total = (float)$order['total_amount'];
        if ($paid !== null && abs($paid - $total) > 0.001) throw new RuntimeException('Nominal pembayaran tidak cocok.');
        $pm = $db->query("SELECT id,name FROM payment_methods WHERE code='qris-klikqris' LIMIT 1")->fetch_assoc();
        if (!$pm) throw new RuntimeException('Metode QRIS KlikQRIS belum tersedia.');

        // Nominal order = tagihan + biaya penanganan + kode unik dari gateway.
        // Hanya tagihan dan biaya penanganan yang masuk saldo merchant; kode unik
        // murni penanda auto-match dan tidak pernah dicairkan, jadi TIDAK boleh
        // dicatat sebagai pemasukan — kalau dicatat, buku kas lebih besar dari
        // saldo riil dan selisihnya menumpuk tiap transaksi.
        $billId    = (int) $order['bill_id'];
        $billTotal = (float) $db->query("SELECT total_amount FROM bills WHERE id={$billId}")->fetch_row()[0];
        $fee       = gateway_fee($billTotal);
        $charge    = $total - $billTotal;
        $amountPaid = $charge >= 0 ? $billTotal : $total;
        $extra      = $charge > 0 ? min($charge, $fee) : 0.0;   // biaya layanan saja
        $uniqueCode = $charge > 0 ? max(0.0, $charge - $fee) : 0.0; // sisa = kode unik

        $s = $db->prepare('INSERT INTO payments (bill_id,user_id,payment_date,amount_paid,payment_method_id,payment_method,reference_no,status,verified_at,notes) VALUES (?,?,CURDATE(),?,?,?,?,"verified",NOW(),?)');
        $userId = $order['user_id'] !== null ? (int) $order['user_id'] : null;
        $pmId = (int) $pm['id']; $name = $pm['name']; $ref = $orderId;
        $notes = 'Otomatis via KlikQRIS';
        if ($extra > 0 && $uniqueCode > 0) {
            $notes .= sprintf(' (biaya QRIS %s + kode unik %s)', number_format($extra, 0, ',', '.'), number_format($uniqueCode, 0, ',', '.'));
        } elseif ($extra > 0) {
            $notes .= sprintf(' (biaya QRIS %s)', number_format($extra, 0, ',', '.'));
        }
        $s->bind_param('iidssss', $billId, $userId, $amountPaid, $pmId, $name, $ref, $notes); $s->execute(); $paymentId = (int) $db->insert_id;

        // Buku kas: IPL selalu dicatat (sama seperti verify.php), biaya QRIS terpisah.
        $billRow = $db->query("SELECT bp.label AS period, u.block, u.unit_number
                               FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id
                               JOIN units u ON u.id=b.unit_id WHERE b.id={$billId}")->fetch_assoc();
        $ipLDesc = 'IPL ' . ($billRow['period'] ?? '') . ' — '
            . ($billRow['block'] ?? '') . '-' . ($billRow['unit_number'] ?? '');
        $k = $db->prepare('INSERT INTO cash_book (type,category,amount,description,trx_date,ref_payment_id,created_by)
                           VALUES ("pemasukan","IPL",?,?,CURDATE(),?,?)');
        $cashBy = $userId !== null ? $userId : 1;
        $k->bind_param('dsii', $amountPaid, $ipLDesc, $paymentId, $cashBy); $k->execute();

        if ($extra > 0) {
            $k2 = $db->prepare('INSERT INTO cash_book (type,category,amount,description,trx_date,ref_payment_id,created_by)
                                VALUES ("pemasukan","Biaya QRIS",?,?,CURDATE(),?,?)');
            $kDesc = 'Biaya penanganan QRIS order ' . $orderId;
            $k2->bind_param('dsii', $extra, $kDesc, $paymentId, $cashBy); $k2->execute();
        }
        $s=$db->prepare('UPDATE bills SET status="sudah_bayar",paid_date=CURDATE() WHERE id=?'); $s->bind_param('i',$billId); $s->execute();
        $s=$db->prepare('UPDATE payment_gateway_orders SET status="PAID",paid_at=NOW(),payment_id=? WHERE id=?'); $orderDbId=(int)$order['id'];$s->bind_param('ii',$paymentId,$orderDbId);$s->execute();
        $db->commit();
        return ['ok'=>true,'changed'=>true,'payment_id'=>$paymentId,'error'=>null];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok'=>false,'changed'=>false,'payment_id'=>null,'error'=>$e->getMessage()];
    }
}
