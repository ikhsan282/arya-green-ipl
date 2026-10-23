<?php
/**
 * Tes login OTP GoPay: minta OTP -> verifikasi -> sesi tersimpan -> validasi token.
 * Butuh mock: php -S 127.0.0.1:8097 tests/gobiz_mock.php  + GOPAY_BASE_URL.
 */
declare(strict_types=1);
putenv('GOPAY_BASE_URL=http://127.0.0.1:8097');
putenv('GOPAY_API_URL=http://127.0.0.1:8097');
$_SESSION = [];

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/payment_gateway_direct.php';
require __DIR__ . '/../includes/gopay_auth.php';

$asserts = 0;
function check(string $label, bool $cond): void
{
    global $asserts;
    $asserts++;
    if (!$cond) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "ok - $label\n";
}

// GoBiz menolak login/request dengan goid:error:missing_field tanpa dua header ini.
$hdrs = gopay_headers();
check('header x-phonemake ada', ($hdrs['x-phonemake'] ?? '') !== '');
check('header x-phonemodel ada', ($hdrs['x-phonemodel'] ?? '') !== '');
check('header x-appid go-biz-web-dashboard', ($hdrs['x-appid'] ?? '') === 'go-biz-web-dashboard');
check('header x-user-type merchant', ($hdrs['x-user-type'] ?? '') === 'merchant');

$db = db();
$orig = $db->query('SELECT id, api_key, config FROM payment_channels WHERE provider=\'gopay\' LIMIT 1')->fetch_assoc();
if (!$orig) { fwrite(STDERR, "SKIP: channel gopay belum ada\n"); exit(0); }
$chId = (int) $orig['id'];

try {
    // 1. nomor dinormalisasi
    check('nomor 0851-1977 -> 8511977', gopay_phone('0851-1977') === '8511977');
    check('nomor 628xx -> 8xx', gopay_phone('+62 812-3456-7890') === '81234567890');

    // 2. nomor tidak valid ditolak sebelum request
    $r = gopay_otp_request('123');
    check('nomor < 9 digit ditolak', $r['ok'] === false && str_contains((string) $r['error'], 'tidak valid'));

    // 3. OTP diminta, token tersimpan di session
    $r = gopay_otp_request('085119772671');
    check('OTP terkirim', $r['ok'] === true);
    check('phone dinormalisasi ke 85119772671', ($r['phone'] ?? '') === '85119772671');
    check('otp_token tersimpan di session', ($_SESSION['gopay_otp']['token'] ?? '') !== '');

    // 4. OTP salah tidak menyimpan sesi
    $r = gopay_otp_verify($db, $chId, '9999');
    check('OTP salah ditolak', $r['ok'] === false);
    $after = $db->query("SELECT api_key FROM payment_channels WHERE id=$chId")->fetch_assoc();
    check('api_key tidak berubah saat OTP salah', $after['api_key'] === $orig['api_key']);

    // 5. OTP benar menyimpan sesi
    $r = gopay_otp_verify($db, $chId, '1234');
    check('OTP benar diterima', $r['ok'] === true);
    check('outlet terbaca dari mock', ($r['outlet'] ?? '') === 'Warung Mock Arya');

    $row = $db->query("SELECT api_key, config FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $hdr = json_decode((string) $row['api_key'], true);
    $meta = json_decode((string) $row['config'], true);
    check('api_key = JSON headers dengan Bearer', ($hdr['authorization'] ?? '') === 'Bearer AT-GOOD');
    check('headers membawa x-appid', ($hdr['x-appid'] ?? '') === 'go-biz-web-dashboard');
    check('config menyimpan refresh_token', ($meta['refresh_token'] ?? '') === 'RT-GOOD');
    check('config menyimpan phone', ($meta['phone'] ?? '') === '85119772671');
    check('config menyimpan expires_at di masa depan', (int) ($meta['expires_at'] ?? 0) > time());
    check('session OTP dibersihkan', !isset($_SESSION['gopay_otp']));

    // 6. cek sesi memakai format yang disimpan -> valid
    $ch = $db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $s = gopay_session_check($ch);
    check('sesi tersimpan terbaca valid', $s['state'] === 'valid');

    // 7. token rusak -> expired (mock balas 401)
    $hdr['authorization'] = 'Bearer AT-EXPIRED';
    $db->query("UPDATE payment_channels SET api_key='" . $db->real_escape_string(json_encode($hdr)) . "' WHERE id=$chId");
    $ch = $db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $s = gopay_session_check($ch);
    check('token 401 terbaca expired', $s['state'] === 'expired');

    // 8. refresh memulihkan token
    $rf = gopay_refresh($db, $ch);
    check('refresh_token berhasil', $rf['ok'] === true);
    $ch = $db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc();
    check('setelah refresh sesi valid lagi', gopay_session_check($ch)['state'] === 'valid');

    // 9. tanpa refresh_token -> tidak bisa refresh
    $ch['config'] = json_encode(['phone' => '85119772671']);
    $rf = gopay_refresh($db, $ch);
    check('tanpa refresh_token -> error jelas', $rf['ok'] === false && str_contains((string) $rf['error'], 'login OTP'));

    // 10. regresi: kolom merchant_id berisi QRIS statis tidak boleh merusak cek sesi
    $ch = $db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $ch['merchant_id'] = '00020101021126650013ID.CO.QRIS.WWW';
    check('QRIS statis di merchant_id -> sesi tetap valid', gopay_session_check($ch)['state'] === 'valid');
    $ch['merchant_id'] = 'G123456789';
    check('merchant id valid -> sesi tetap valid', gopay_session_check($ch)['state'] === 'valid');

    // 11. metadata sesi bertahan saat form konfigurasi disimpan ulang
    $cfg = json_decode((string) $row['config'], true);
    $cfg['merchant_ids'] = 'G123456789';
    $db->query("UPDATE payment_channels SET config='" . $db->real_escape_string(json_encode($cfg)) . "' WHERE id=$chId");
    $after = $db->query("SELECT config FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $cfg2 = json_decode((string) $after['config'], true);
    check('simpan ulang config tidak menghapus refresh_token', ($cfg2['refresh_token'] ?? '') === 'RT-GOOD');
    check('simpan ulang config tidak menghapus outlet', ($cfg2['outlet'] ?? '') === 'Warung Mock Arya');

    // 12. HTTP 403 juga = sesi tidak valid (bukan 'tidak dikenali')
    $ch = $db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $hdr = json_decode((string) $ch['api_key'], true);
    $hdr['authorization'] = 'Bearer AT-FORBIDDEN';
    $ch['api_key'] = json_encode($hdr);
    $s = gopay_session_check($ch);
    check('HTTP 403 terbaca expired (bukan error)', $s['state'] === 'expired' && $s['error'] === null);

    // 13. merchant id diambil dari config.merchant_ids; kolom merchant_id (QRIS statis) diabaikan
    $ch['api_key'] = $row['api_key'];
    $ch['merchant_id'] = '00020101021126560016COM.NOBUBANK.WWW011893600103';
    $ch['config'] = json_encode(['refresh_token' => 'RT-GOOD', 'merchant_ids' => 'G-BAD']);
    check('merchant_ids dari config benar-benar dikirim', gopay_session_check($ch)['state'] === 'expired');
    $ch['config'] = json_encode(['refresh_token' => 'RT-GOOD', 'merchant_ids' => 'G-GOOD-1']);
    check('merchant_ids valid -> sesi valid', gopay_session_check($ch)['state'] === 'valid');

    echo "\ngopay_otp_test OK ($asserts asersi)\n";
} finally {
    $st = $db->prepare('UPDATE payment_channels SET api_key=?, config=? WHERE id=?');
    $st->bind_param('ssi', $orig['api_key'], $orig['config'], $chId);
    $st->execute();
    echo "restore: channel gopay dikembalikan ke state awal\n";
}
