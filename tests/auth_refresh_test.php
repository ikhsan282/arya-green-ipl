<?php
/**
 * Tes dua perbaikan:
 *   1. gq_check() refresh token GoPay sekali saat 401, lalu ulangi pengecekan.
 *   2. spay_token_check() memvalidasi token ShopeePay (B: prefix, 401 = mati).
 * Butuh mock: php -S 127.0.0.1:8098 tests/gq_mock.php
 *            php -S 127.0.0.1:8097 tests/gobiz_mock.php
 */
declare(strict_types=1);
putenv('GOPAY_API_URL=http://127.0.0.1:8098');
putenv('SHOPEEPAY_API_URL=http://127.0.0.1:8098/api/v1/report/transaction/get-transaction-list');
putenv('GOPAY_BASE_URL=http://127.0.0.1:8097');
$_SESSION = [];

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/payment_gateway_direct.php';
require __DIR__ . '/../includes/gopay_auth.php';
require __DIR__ . '/../includes/shopeepay_auth.php';

$asserts = 0;
function check(string $l, bool $c): void { global $asserts; $asserts++; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } echo "ok - $l\n"; }

$db = db();
$orig = $db->query("SELECT id, api_key, config FROM payment_channels WHERE provider='gopay' LIMIT 1")->fetch_assoc();
$origSp = $db->query("SELECT id, api_key FROM payment_channels WHERE provider='shopeepay' LIMIT 1")->fetch_assoc();
if (!$orig) { fwrite(STDERR, "SKIP: channel gopay tidak ada\n"); exit(0); }
$chId = (int) $orig['id'];

try {
    // State mock bersih: sisa transaksi run sebelumnya akan membuat hasil PAID, bukan PENDING.
    @unlink('/tmp/gq-mock.json');
    @unlink(sys_get_temp_dir() . '/gq-mock.json');

    // ── 1. Auto-refresh GoPay saat 401 ────────────────────────────────────────
    // Sesi dengan access token mati, tapi refresh_token sah di mock GoBiz.
    $deadHeaders = gopay_headers();
    $deadHeaders['authorization'] = 'Bearer AT-DEAD';
    unset($deadHeaders['content-type'], $deadHeaders['authentication-type']);
    $meta = ['phone' => '85119772671', 'refresh_token' => 'RT-GOOD', 'expires_at' => time() - 60, 'outlet' => 'Mock'];
    $json = json_encode($deadHeaders, JSON_UNESCAPED_SLASHES);
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    $st = $db->prepare('UPDATE payment_channels SET api_key=?, config=? WHERE id=?');
    $st->bind_param('ssi', $json, $metaJson, $chId);
    $st->execute();

    $ch = $db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc();
    check('token mati terdeteksi expired', gopay_session_check($ch)['state'] === 'expired');

    // gq_check harus memulihkan sendiri: 401 -> refresh -> ulang -> PENDING (bukan error)
    $order = ['total_amount' => 251001, 'created_at' => date('Y-m-d H:i:s'), 'gateway_type' => 'gopay'];
    $r = gq_check($ch, $order);
    check('gq_check tidak gagal setelah 401', $r['ok'] === true);
    check('gq_check melanjutkan ke status PENDING', $r['status'] === 'PENDING');

    $after = $db->query("SELECT api_key, config FROM payment_channels WHERE id=$chId")->fetch_assoc();
    $hdrAfter = json_decode((string) $after['api_key'], true);
    check('token baru tersimpan setelah auto-refresh', ($hdrAfter['authorization'] ?? '') === 'Bearer AT-GOOD');
    check('sesi jadi valid setelah auto-refresh', gopay_session_check($db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc())['state'] === 'valid');

    // Transaksi muncul -> gq_check harus menemukannya lewat token hasil refresh
    file_get_contents('http://127.0.0.1:8098/test/TX/AUTOREF-1/251001');
    $r = gq_check($db->query("SELECT * FROM payment_channels WHERE id=$chId")->fetch_assoc(), $order);
    check('pembayaran terdeteksi setelah auto-refresh', $r['paid'] === true);

    // ── 2. Validasi token ShopeePay ───────────────────────────────────────────
    if ($origSp) {
        $spId = (int) $origSp['id'];
        $chSp = ['id' => $spId, 'api_key' => ''];

        check('token kosong -> empty', spay_token_check($chSp)['state'] === 'empty');

        $chSp['api_key'] = 'tanpa-prefix';
        $r = spay_token_check($chSp);
        check('token tanpa "B:" ditolak sebelum request', $r['state'] === 'invalid' && str_contains((string) $r['error'], 'B:'));

        $chSp['api_key'] = 'B:mock-token';
        check('token valid terdeteksi', spay_token_check($chSp)['state'] === 'valid');

        $chSp['api_key'] = 'B:sudah-mati';
        check('token 401 terdeteksi expired', spay_token_check($chSp)['state'] === 'expired');

        // code != 0 dengan HTTP 200 juga harus dianggap token mati (bentuk portal asli).
        $chSp['api_key'] = 'B:code-error';
        check('code!=0 dengan HTTP 200 -> expired', spay_token_check($chSp)['state'] === 'expired');
    }

    echo "\nauth_refresh_test OK ($asserts asersi)\n";
} finally {
    $st = $db->prepare('UPDATE payment_channels SET api_key=?, config=? WHERE id=?');
    $st->bind_param('ssi', $orig['api_key'], $orig['config'], $chId);
    $st->execute();
    echo "restore: channel gopay dikembalikan\n";
}
