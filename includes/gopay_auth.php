<?php
/**
 * Login OTP & sesi GoPay Merchant (GoBiz).
 *
 * Protokol mengikuti portal GoBiz: login/request -> goid/token (grant_type=otp)
 * -> goresto/v5/public/users/config. Sesi (access+refresh token) disimpan di
 * payment_channels.api_key sebagai JSON headers siap pakai, jadi
 * gq_check_gopay() tidak perlu diubah.
 */

const GOPAY_BASE = 'https://api.gobiz.co.id';
const GOPAY_CLIENT = 'go-biz-web-new';

/** ponytail: override env hanya untuk mock tes lokal; produksi tetap ke api.gobiz.co.id. */
function gopay_base(): string
{
    return rtrim(getenv('GOPAY_BASE_URL') ?: 'https://api.gobiz.co.id', '/');
}

function gopay_headers(): array
{
    return [
        'accept'             => 'application/json, text/plain, */*',
        'accept-language'    => 'id',
        'authentication-type' => 'go-id',
        'content-type'       => 'application/json',
        'gojek-country-code' => 'ID',
        'gojek-timezone'     => 'Asia/Jakarta',
        'origin'             => 'https://portal.gofoodmerchant.co.id',
        'referer'            => 'https://portal.gofoodmerchant.co.id/',
        'user-agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
        'x-appid'            => 'go-biz-web-dashboard',
        'x-appversion'       => 'platform-v3.111.0-1708bc9a',
        'x-deviceos'         => 'Web',
        // Wajib: tanpa phonemake/phonemodel GoBiz menolak dengan goid:error:missing_field.
        'x-phonemake'        => 'Windows 10 64-bit',
        'x-phonemodel'       => 'Chrome 150.0.0.0 on Windows 10 64-bit',
        'x-platform'         => 'Web',
        'x-uniqueid'         => 'b5f98d3a-c9de-49ca-8067-dba94002b23c',
        'x-user-locale'      => 'en-GB',
        'x-user-type'        => 'merchant',
    ];
}

/** Normalisasi 08xx / +628xx / 8xx -> 8xxxxxxxxx (tanpa 62). */
function gopay_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($d, '62')) $d = substr($d, 2);
    if (str_starts_with($d, '0'))  $d = substr($d, 1);
    return $d;
}

function gopay_post(string $path, array $payload, array $headers = []): array
{
    $h = [];
    foreach (array_merge(gopay_headers(), $headers) as $k => $v) $h[] = "$k: $v";
    return gq_http_curl('POST', gopay_base() . $path, $h, json_encode($payload));
}

/**
 * Minta OTP. Mengembalikan otp_token yang wajib dikirim balik saat verifikasi.
 * ponytail: otp_token disimpan di session PHP (bukan DB) — kedaluwarsa 12 menit,
 * cukup untuk satu alur login; tak perlu tabel baru.
 */
function gopay_otp_request(string $phoneRaw): array
{
    $phone = gopay_phone($phoneRaw);
    if (strlen($phone) < 9 || strlen($phone) > 15) {
        return ['ok' => false, 'error' => 'Nomor HP tidak valid (contoh: 085119772671).'];
    }
    $res = gopay_post('/goid/login/request', [
        'client_id'    => GOPAY_CLIENT,
        'phone_number' => $phone,
        'country_code' => '62',
    ]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];

    $body = $res['json'] ?? [];
    $otpToken = $body['data']['otp_token'] ?? $body['otp_token'] ?? '';
    if ($otpToken === '') {
        // GoBiz kadang hanya membalas {success:true}; tanpa otp_token verifikasi tak mungkin.
        return ['ok' => false, 'error' => 'OTP terkirim tetapi otp_token tidak diterima. Coba lagi.'];
    }
    $_SESSION['gopay_otp'] = ['token' => $otpToken, 'phone' => $phone, 'at' => time()];
    return ['ok' => true, 'error' => null, 'phone' => $phone];
}

/** Verifikasi OTP, ambil sesi, simpan ke payment_channels. */
function gopay_otp_verify(mysqli $db, int $channelId, string $otp): array
{
    $otp = preg_replace('/\D+/', '', $otp) ?? '';
    $pending = $_SESSION['gopay_otp'] ?? null;
    if (!is_array($pending) || time() - (int) $pending['at'] > 720) {
        return ['ok' => false, 'error' => 'Sesi OTP kedaluwarsa. Minta kode baru.'];
    }
    if (strlen($otp) < 4 || strlen($otp) > 8) {
        return ['ok' => false, 'error' => 'Kode OTP harus 4-8 digit.'];
    }

    $res = gopay_post('/goid/token', [
        'client_id'    => GOPAY_CLIENT,
        'grant_type'   => 'otp',
        'data'         => ['otp' => $otp, 'otp_token' => $pending['token']],
    ]);
    if (!$res['ok']) {
        $err = (string) $res['error'];
        if ($err === '' || $err === 'Respons API tidak dikenali.') {
            $err = 'Kode OTP salah atau kedaluwarsa (HTTP ' . $res['http'] . ').';
        }
        return ['ok' => false, 'error' => $err];
    }

    $data = $res['json']['data'] ?? $res['json'] ?? [];
    $access = (string) ($data['access_token'] ?? '');
    $refresh = (string) ($data['refresh_token'] ?? '');
    if ($access === '') {
        // GoBiz menaruh alasan di message / errors[].message, kadang tanpa message sama sekali.
        $j = $res['json'] ?? [];
        $msg = $j['errors'][0]['message'] ?? $j['message'] ?? $j['error_description'] ?? '';
        if (!is_string($msg) || $msg === '') $msg = 'Kode OTP salah atau kedaluwarsa (HTTP ' . $res['http'] . ').';
        return ['ok' => false, 'error' => (string) $msg];
    }

    // Profil merchant: pelengkap, kegagalan di sini tidak membatalkan login.
    $outlet = '';
    $merchantId = '';
    $cfgHeaders = ['authorization' => 'Bearer ' . $access];
    $prof = gq_http_curl('GET', gopay_base() . '/goresto/v5/public/users/config', array_map(
        fn($k, $v) => "$k: $v", array_keys($cfgHeaders), array_values($cfgHeaders)
    ));
    if ($prof['ok']) {
        $p = $prof['json']['data'] ?? [];
        $outlet = (string) ($p['outlet_name'] ?? $p['name'] ?? $p['merchant_name'] ?? '');
        $merchantId = (string) ($p['merchant_id'] ?? $p['id'] ?? '');
    }

    // Simpan sebagai JSON headers: gq_check_gopay() menerima bentuk ini apa adanya.
    $headers = gopay_headers();
    $headers['authorization'] = 'Bearer ' . $access;
    unset($headers['content-type'], $headers['authentication-type']);
    $json = json_encode($headers, JSON_UNESCAPED_SLASHES);

    $expiresAt = time() + (int) ($data['expires_in'] ?? 86400);
    $meta = json_encode([
        'phone' => $pending['phone'], 'outlet' => $outlet, 'merchant_id' => $merchantId,
        'refresh_token' => $refresh, 'expires_at' => $expiresAt, 'login_at' => time(),
    ], JSON_UNESCAPED_SLASHES);

    $st = $db->prepare('UPDATE payment_channels SET api_key=?, config=? WHERE id=?');
    $st->bind_param('ssi', $json, $meta, $channelId);
    $st->execute();

    unset($_SESSION['gopay_otp']);
    log_activity('update', 'payment_channels', 'gopay login OTP berhasil; sesi tersimpan');
    return ['ok' => true, 'error' => null, 'outlet' => $outlet, 'merchant_id' => $merchantId, 'expires_at' => $expiresAt];
}

/** Cek sesi masih hidup: panggil endpoint transaksi, 401 berarti kedaluwarsa. */
function gopay_session_check(array $channel): array
{
    $auth = (string) ($channel['api_key'] ?? '');
    $hdr = json_decode($auth, true);
    // Belum ada sesi: api_key kosong, bukan JSON, atau JSON tanpa Bearer.
    if (!is_array($hdr) || !str_starts_with((string) ($hdr['authorization'] ?? ''), 'Bearer ')) {
        return ['ok' => true, 'state' => 'empty', 'error' => null];
    }

    $merchantId = trim((string) ($channel['merchant_id'] ?? '')); // = Merchant ID GoPay di form
    $q = ['from' => 0, 'size' => 1, 'statuses' => 'SETTLEMENT,CAPTURE',
          'start_time' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
          'end_time'   => gmdate('Y-m-d\TH:i:s\Z', time() + 60)];
    // Kolom yang sama dipakai untuk string QRIS statis; kirim hanya bila bentuknya ID merchant.
    if (preg_match('/^[A-Za-z0-9_-]{4,60}$/', $merchantId)) $q['merchant_ids'] = $merchantId;

    $base = getenv('GOPAY_API_URL') ?: 'https://api.gojekapi.com';
    $h = [];
    foreach ((array) json_decode($auth, true) as $k => $v) $h[] = "$k: $v";
    $res = gq_http_curl('GET', $base . '/merchant-analytics/v2/merchants/transactions?' . http_build_query($q), $h);

    if ($res['http'] === 401) return ['ok' => true, 'state' => 'expired', 'error' => null];
    if ($res['ok']) return ['ok' => true, 'state' => 'valid', 'error' => null];
    return ['ok' => false, 'state' => 'unknown', 'error' => $res['error'] . ' (HTTP ' . $res['http'] . ')'];
}

/** Perbarui access token dari refresh_token. */
function gopay_refresh(mysqli $db, array $channel): array
{
    $meta = json_decode((string) ($channel['config'] ?? ''), true);
    $refresh = is_array($meta) ? (string) ($meta['refresh_token'] ?? '') : '';
    if ($refresh === '') return ['ok' => false, 'error' => 'refresh_token tidak tersimpan; login OTP ulang.'];

    $res = gopay_post('/goid/token', [
        'client_id'    => GOPAY_CLIENT,
        'grant_type'   => 'refresh_token',
        'data'         => ['refresh_token' => $refresh, 'phone_number' => (string) ($meta['phone'] ?? ''), 'country_code' => '62'],
    ]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];

    $data = $res['json']['data'] ?? $res['json'] ?? [];
    $access = (string) ($data['access_token'] ?? '');
    if ($access === '') return ['ok' => false, 'error' => 'refresh_token ditolak; login OTP ulang.'];

    $headers = gopay_headers();
    $headers['authorization'] = 'Bearer ' . $access;
    unset($headers['content-type'], $headers['authentication-type']);
    $json = json_encode($headers, JSON_UNESCAPED_SLASHES);

    $meta['refresh_token'] = (string) ($data['refresh_token'] ?? $refresh);
    $meta['expires_at'] = time() + (int) ($data['expires_in'] ?? 86400);
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);

    $st = $db->prepare('UPDATE payment_channels SET api_key=?, config=? WHERE id=?');
    $id = (int) $channel['id'];
    $st->bind_param('ssi', $json, $metaJson, $id);
    $st->execute();
    log_activity('update', 'payment_channels', 'gopay token di-refresh');
    return ['ok' => true, 'error' => null, 'expires_at' => $meta['expires_at']];
}
