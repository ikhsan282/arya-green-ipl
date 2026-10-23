<?php
/**
 * Klien gateway GoPay Merchant & ShopeePay — pure PHP, tanpa Node.js.
 *
 * QRIS dinamis dihasilkan langsung dari QRIS statis merchant (kolom merchant_id)
 * dengan menerapkan logika EMVCo TLV: ubah tag 01 → '12', set tag 54 = nominal,
 * hitung CRC16-CCITT, lalu buat QR image lewat qrserver.com.
 *
 * Cek pembayaran langsung ke API merchant GoPay / ShopeePay memakai token
 * yang disimpan di kolom api_key.
 */

// Backward compat — dulu return base URL Node.js, sekarang return token string.
function gq_base(array $channel): string
{
    return trim((string) ($channel['api_key'] ?? ''));
}

// ---- CRC16-CCITT (EMVCo) ----

function qris_crc16(string $payload): string
{
    $crc = 0xFFFF;
    for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
        $crc ^= ord($payload[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

// ---- EMVCo QRIS TLV ----

/** Parse TLV string → ordered array of [tag, value]. */
function qris_parse_tlv(string $qris): array
{
    $tags = [];
    $pos = 0;
    $len = strlen($qris);
    while ($pos + 4 <= $len) {
        $tag = substr($qris, $pos, 2);
        $vlen = (int) substr($qris, $pos + 2, 2);
        $value = substr($qris, $pos + 4, $vlen);
        $tags[] = [$tag, $value];
        $pos += 4 + $vlen;
    }
    return $tags;
}

/** Rebuild TLV string from ordered array of [tag, value]. */
function qris_build_tlv(array $tags): string
{
    $out = '';
    foreach ($tags as [$tag, $value]) {
        $out .= $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }
    return $out;
}

/**
 * Ubah QRIS statis → dinamis dengan nominal tertentu.
 * @return string|null Dynamic QRIS string, atau null kalau parsing gagal.
 */
function qris_make_dynamic(string $staticQris, int $amount): ?string
{
    $tags = qris_parse_tlv($staticQris);
    if (empty($tags)) return null;

    // Strip CRC tag (63) if present
    $tags = array_values(array_filter($tags, fn($t) => $t[0] !== '63'));

    // Tag 01: change '11' (static) → '12' (dynamic)
    $found01 = false;
    foreach ($tags as &$t) {
        if ($t[0] === '01') { $t[1] = '12'; $found01 = true; break; }
    }
    unset($t);
    if (!$found01) return null;

    // Tag 54: set amount. Replace if exists, else insert before tag 58.
    $amountStr = (string) $amount;
    $found54 = false;
    foreach ($tags as &$t) {
        if ($t[0] === '54') { $t[1] = $amountStr; $found54 = true; break; }
    }
    unset($t);

    if (!$found54) {
        $ins = -1;
        foreach ($tags as $i => $t) {
            if ($t[0] === '58') { $ins = $i; break; }
        }
        $entry = ['54', $amountStr];
        if ($ins >= 0) {
            array_splice($tags, $ins, 0, [$entry]);
        } else {
            $tags[] = $entry;
        }
    }

    // Rebuild + CRC
    $payload = qris_build_tlv($tags) . '6304';
    return $payload . qris_crc16($payload);
}

// ---- HTTP helper (cURL) ----

function gq_http_curl(string $method, string $url, array $headers, ?string $body = null): array
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http' => $code, 'error' => 'Koneksi ke API gagal.', 'json' => null];
    }
    $json = json_decode((string) $raw, true);
    return [
        'ok'    => $code >= 200 && $code < 300 && is_array($json),
        'http'  => $code,
        'error' => is_array($json) && is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 200) : 'Respons API tidak dikenali.',
        'json'  => $json,
    ];
}

// ---- gq_create ----

/**
 * Buat QRIS dinamis (100% lokal, tanpa server gateway).
 *
 * Kolom per provider:
 * - merchant_id = QRIS statis merchant (dipakai generator EMVCo)
 * - api_key     = kredensial sesi/API
 *                 gopay     : JSON map header, mis. {"Authorization":"Bearer ...","X-Appversion":"..."}
 *                 shopeepay : token internal "B:..."
 * - config      = kolom JSON bebas; opsional "merchant_ids" untuk query Gojek
 *
 * @return array{ok:bool,error:?string,data:?array}
 */
function gq_create(array $channel, string $orderId, float $amount, string $note = ''): array
{
    $staticQris = trim((string) ($channel['merchant_id'] ?? ''));
    if ($staticQris === '') {
        return ['ok' => false, 'error' => 'QRIS statis merchant belum diisi.', 'data' => null];
    }
    // NMID (mis. ID1026547859169) bukan payload QRIS: tanpa tag 01 nominal tak bisa disisipkan.
    if (preg_match('/^(?:ID)?\d+$/', $staticQris)) {
        return ['ok' => false, 'error' => 'Isian QRIS Statis Merchant berisi nomor merchant/NMID, bukan payload QRIS. Salin seluruh teks hasil scan QR yang diawali 000201...', 'data' => null];
    }
    $token = gq_base($channel);
    if ($token === '') {
        return ['ok' => false, 'error' => 'Token autentikasi belum diisi.', 'data' => null];
    }

    $amt = (int) round($amount);
    $dynamic = qris_make_dynamic($staticQris, $amt);
    if ($dynamic === null) {
        return ['ok' => false, 'error' => 'Gagal membuat QRIS dinamis dari string statis.', 'data' => null];
    }

    $qrisUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($dynamic);
    $expiredAt = gmdate('c', time() + 900); // 15 menit

    return ['ok' => true, 'error' => null, 'data' => [
        'qris_url'     => $qrisUrl,
        'total_amount' => (float) $amt,
        'expired_at'   => $expiredAt,
        'trx_id'       => $orderId,
        'type'         => $channel['provider'],
        'direct_url'   => $dynamic, // simpan QRIS string untuk referensi
    ]];
}

// ---- gq_check ----

/**
 * Kode unik per order: rentang $lo..$hi (default 100..500 perak), tidak dipakai
 * order PENDING lain dengan base sama. Deterministik (bukan acak).
 * $excludeOrderId: order yang sedang diperbarui tidak menghitung dirinya sendiri.
 */
function gq_unique_touch(int $base, string $excludeOrderId = '', int $lo = 100, int $hi = 500): int
{
    $used = [];
    $st = db()->prepare('SELECT total_amount FROM payment_gateway_orders WHERE status="PENDING" AND order_id<>? AND total_amount BETWEEN ? AND ?');
    $min = $base + $lo; $max = $base + $hi;
    $st->bind_param('sdd', $excludeOrderId, $min, $max);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_row()) $used[(int) round((float) $row[0]) - $base] = true;
    for ($c = $lo; $c <= $hi; $c++) {
        if (!isset($used[$c])) return $c;
    }
    return $lo; // ponytail: rentang habis → klaim atomik tetap mencegah dobel bayar
}

/**
 * Hitung rincian nominal order direct.
 *
 * Konvensi lama (KlikQRIS) menumpuk semua tambahan di atas tagihan:
 *   total = tagihan + biaya layanan + kode unik
 * sehingga: charge  = total - tagihan
 *            extra  = min(charge, biaya layanan)   → masuk buku kas
 *            unik   = sisanya                      → penanda, tidak masuk kas
 * Tidak perlu menyimpan marker terpisah.
 */
function gq_split(float $total, float $billTotal, float $fee): array
{
    $charge = $total - $billTotal;
    $extra  = $charge > 0 ? min($charge, $fee) : 0.0;
    $unique = $charge > 0 ? max(0.0, $charge - $extra) : 0.0;
    return ['charge' => max(0.0, $charge), 'fee' => $extra, 'unique' => $unique];
}

/**
 * Klaim satu transaksi gateway untuk satu order (idempotent).
 *
 * Satu transaksi hanya boleh melunasi satu order. ID transaksi disimpan di
 * kolom `signature` (tidak dipakai provider direct); kalau transaksi itu sudah
 * dipakai order lain yang PAID, klaim ditolak.
 */
function gq_claim(array $order, ?array $trx): bool
{
    $trxId = trim((string) ($trx['transaction_id'] ?? $trx['order_sn'] ?? $trx['id'] ?? ''));
    if ($trxId === '') return true; // gateway tanpa ID transaksi: nominal unik sudah jadi pengaman
    $trxId = mb_substr($trxId, 0, 255);
    $orderDbId = (int) $order['id'];

    $st = db()->prepare('SELECT 1 FROM payment_gateway_orders WHERE signature=? AND status="PAID" AND id<>? LIMIT 1');
    $st->bind_param('si', $trxId, $orderDbId);
    $st->execute();
    if ($st->get_result()->fetch_row()) return false;

    $st = db()->prepare('UPDATE payment_gateway_orders SET signature=? WHERE id=? AND status="PENDING" AND (signature IS NULL OR signature<>?)');
    $st->bind_param('sis', $trxId, $orderDbId, $trxId);
    $st->execute();
    return $st->affected_rows > 0 || gq_signature_of($orderDbId) === $trxId;
}

function gq_signature_of(int $orderDbId): string
{
    $st = db()->prepare('SELECT signature FROM payment_gateway_orders WHERE id=?');
    $st->bind_param('i', $orderDbId);
    $st->execute();
    return (string) ($st->get_result()->fetch_row()[0] ?? '');
}

/**
 * Cek apakah pembayaran masuk langsung ke API merchant.
 * Order lama tanpa kode unik tetap dicocokkan ke total_amount-nya sendiri.
 * @return array{ok:bool,paid:bool,status:string,error:?string,trx:?array}
 */
function gq_check(array $channel, array $order): array
{
    $token = gq_base($channel);
    if ($token === '') {
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => 'Token autentikasi belum diisi.', 'trx' => null];
    }

    $amount = (int) round((float) $order['total_amount']);
    $since  = strtotime((string) $order['created_at']) - 60;
    $provider = $order['gateway_type'] ?? $channel['provider'] ?? '';

    if ($provider === 'gopay') {
        $r = gq_check_gopay($token, $amount, $since, $channel);
        // Token GoPay berlaku ~24 jam. Kalau kedaluwarsa, refresh sekali lalu ulangi —
        // tanpa ini polling warga terus gagal sampai ada yang membuka panel channel.
        if (!$r['ok'] && (int) ($r['http'] ?? 0) === 401) {
            require_once __DIR__ . '/gopay_auth.php';
            $rf = gopay_refresh(db(), $channel);
            if ($rf['ok']) {
                $row = db()->query('SELECT api_key FROM payment_channels WHERE id=' . (int) $channel['id'])->fetch_assoc();
                $r = gq_check_gopay((string) ($row['api_key'] ?? ''), $amount, $since, $channel);
            }
        }
        return $r;
    }
    return gq_check_shopeepay($token, $amount, $since);
}

/** Merchant ID GoPay untuk query API — dari kolom config, JANGAN merchant_id (itu QRIS statis). */
function gq_channel_config(array $channel): array
{
    $cfg = json_decode((string) ($channel['config'] ?? ''), true);
    return is_array($cfg) ? $cfg : [];
}

function gq_check_gopay(string $authJson, int $amount, int $since, array $channel): array
{
    $authHeaders = @json_decode($authJson, true);
    if (!is_array($authHeaders) || empty($authHeaders)) {
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => 'Token GoPay bukan JSON headers valid.', 'trx' => null];
    }
    $cfg = gq_channel_config($channel);
    $merchantId = trim((string) ($cfg['merchant_ids'] ?? ''));

    $q = [
        'from'          => 0,
        'size'          => 50,
        'statuses'      => 'SETTLEMENT,CAPTURE',
        'payment_types' => 'QRIS,GOPAY',
        'start_time'    => gmdate('Y-m-d\TH:i:s\Z', $since),
        'end_time'      => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
    ];
    if ($merchantId !== '') $q['merchant_ids'] = $merchantId;

    // ponytail: env override hanya untuk mock lokal (tes); hapus bila tak perlu.
    $base = getenv('GOPAY_API_URL') ?: 'https://api.gojekapi.com';
    $url = $base . '/merchant-analytics/v2/merchants/transactions?' . http_build_query($q);

    $headers = [];
    foreach ($authHeaders as $k => $v) {
        $headers[] = "$k: $v";
    }

    $res = gq_http_curl('GET', $url, $headers);
    if (!$res['ok']) {
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => $res['error'], 'http' => $res['http'], 'trx' => null];
    }

    // Cari transaksi yang cocok nominal + waktu.
    // Waktu wajib: cegah transaksi lama tercocokkan ke order baru bernominal sama.
    // Bentuk asli: {from,size,total,transactions:[{gross_amount,transaction_status,transaction_time,...}]}
    // gross_amount dalam SEN (1000100 = Rp10.001), bukan rupiah.
    $transactions = $res['json']['transactions'] ?? $res['json']['data'] ?? null;
    if (!is_array($transactions) || !array_is_list($transactions) || ($res['json']['success'] ?? true) === false) {
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => 'Respons transaksi GoPay tidak dikenali.', 'trx' => null];
    }
    foreach ($transactions as $tx) {
        $amountRaw = $tx['gross_amount'] ?? $tx['real_gross_amount'] ?? $tx['amount'] ?? null;
        if ($amountRaw === null) continue;
        // Sen → rupiah. Nilai di bawah 100.000 dianggap sudah rupiah (mis. mock lama).
        $txAmt = ((int) round((float) $amountRaw)) / 100;
        if ((int) round($txAmt) !== $amount) {
            // toleransi data lama dalam rupiah
            if ((int) round((float) $amountRaw) !== $amount) continue;
        }
        $st = strtoupper((string) ($tx['transaction_status'] ?? $tx['status'] ?? ''));
        if ($st !== '' && !in_array($st, ['SETTLEMENT', 'CAPTURE', 'SUCCESS', 'PAID'], true)) continue;
        $txTime = strtotime((string) ($tx['transaction_time'] ?? $tx['create_time'] ?? $tx['time'] ?? ''));
        if ($txTime !== false && $txTime < $since) continue;
        return ['ok' => true, 'paid' => true, 'status' => 'PAID', 'error' => null, 'trx' => $tx];
    }
    return ['ok' => true, 'paid' => false, 'status' => 'PENDING', 'error' => null, 'trx' => null];
}

function gq_check_shopeepay(string $token, int $amount, int $since): array
{
    // Host & path diambil dari request asli portal Partner; SHOPEEPAY_API_URL hanya untuk mock tes.
    $url = getenv('SHOPEEPAY_API_URL') ?: 'https://shopeepay.shopee.co.id/merchant/v1/partner-web/get-transaction-list';

    // Struktur body mengikuti portal asli: {data:{metadata,pageSize,filter,sorter,next_position}}.
    // Tanpa wrapper `data` ShopeePay membalas error validasi.
    $body = json_encode([
        'data' => [
            'metadata' => [
                'token'    => $token,
                'language' => 'id',
                'timezone' => 'Asia/Jakarta',
            ],
            'pageSize' => 50,
            'filter'   => [
                'startTime'   => $since,
                'endTime'     => time() + 60,
                'serviceList' => [1, 3],
            ],
            'sorter'        => ['field' => 'createTime', 'order' => 'descend'],
            'next_position' => '',
        ],
    ]);
    // Header wajib: Origin/Referer/UA/X-Timestamp-Ms. Tanpa ini API menolak.
    $headers = [
        'Content-Type: application/json',
        'Origin: https://partner.shopee.co.id',
        'Referer: https://partner.shopee.co.id/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'X-Timestamp-Ms: ' . (string) (time() * 1000),
    ];

    $res = gq_http_curl('POST', $url, $headers, $body);
    // portal membalas {code:0,msg:""} untuk sukses; code lain = token mati/ditolak.
    $code = $res['json']['code'] ?? null;
    if (!$res['ok'] || ($code !== null && $code !== 0 && $code !== '0')) {
        $err = $res['error'];
        if (is_string($res['json']['msg'] ?? null) && $res['json']['msg'] !== '') $err = $res['json']['msg'];
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => $err, 'http' => $res['http'], 'trx' => null];
    }

    // Bentuk asli portal: data.list (bukan transactionList/transactions). list = null bila kosong.
    $transactions = $res['json']['data']['list']
        ?? $res['json']['data']['transactionList']
        ?? $res['json']['data']['transactions']
        ?? null;
    // `total` = jumlah transaksi; list absen/null + total 0 berarti memang tidak ada pembayaran.
    // Envelope tanpa `data` sama sekali tetap ditolak (bukan diam-diam dianggap kosong).
    $total = (int) ($res['json']['data']['total'] ?? 0);
    if ($transactions === null && is_array($res['json']['data'] ?? null) && $total === 0) $transactions = [];
    if (!is_array($transactions) || !array_is_list($transactions)) {
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => 'Respons transaksi ShopeePay tidak dikenali.', 'trx' => null];
    }
    $stateChanged = false;
    foreach ($transactions as $tx) {
        // Nominal rupiah utuh di bentuk "10.002" (titik = pemisah ribuan). Jangan dipotong ke 10.
        $txAmt = (int) round((float) str_replace(['.', ','], '', (string) ($tx['amount'] ?? '0')));
        if ($txAmt !== $amount) continue;
        // status 3 = berhasil (transaksi berhasil di portal ShopeePay); type 1 = pembayaran.
        $txStatus = $tx['status'] ?? null;
        if ($txStatus !== null && (int) $txStatus !== 3) { $stateChanged = true; continue; }
        if (isset($tx['transactionType']) && (int) $tx['transactionType'] !== 1) continue;
        $txTime = (int) ($tx['createTime'] ?? 0);
        if ($txTime > 0 && $txTime < $since) continue;
        return ['ok' => true, 'paid' => true, 'status' => 'PAID', 'error' => null, 'trx' => $tx];
    }
    // Nominal cocok tapi statusnya bukan berhasil → beri tahu tanpa mengklaim lunas.
    if ($stateChanged) {
        return ['ok' => false, 'paid' => false, 'status' => 'UNKNOWN', 'error' => 'Transaksi ditemukan tetapi statusnya belum berhasil.', 'trx' => null];
    }
    return ['ok' => true, 'paid' => false, 'status' => 'PENDING', 'error' => null, 'trx' => null];
}
