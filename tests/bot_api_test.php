<?php
require_once __DIR__ . '/_cli_only.php';
/**
 * Tes integrasi end-to-end API Bot Hermes (/api/bot/*), cron qris_poll,
 * notifikasi pengurus (Ketua, Bendahara, Sekretaris), dan kwitansi publik.
 *
 * Jalankan: php tests/bot_api_test.php
 */
$secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
putenv('IPL_BOT_KEYS=' . json_encode(['hermes-test' => $secret]));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bot_auth.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/payment_gateway_direct.php';
require_once __DIR__ . '/../cron/qris_poll.php';

$db = db();
$assertions = 0;
$ok = static function (bool $cond, string $msg) use (&$assertions): void {
    $assertions++;
    if (!$cond) {
        fwrite(STDERR, "FAIL [{$assertions}]: {$msg}\n");
        exit(1);
    }
};

// Jalankan built-in PHP server lokal untuk menguji endpoint HTTP /api/bot/* secara nyata
$port = 18091;
$root = realpath(__DIR__ . '/..');
$env  = array_merge(getenv(), [
    'IPL_BOT_KEYS' => json_encode(['hermes-test' => $secret]),
]);
$cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root];
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/bot_api_srv.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/bot_api_srv.err', 'a']];
$proc = proc_open($cmd, $descriptors, $pipes, $root, $env);
$ok(is_resource($proc), 'PHP built-in server started');

// Tunggu server siap
$ready = false;
for ($i = 0; $i < 30; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $ready = true; break; }
    usleep(100000);
}

$callBot = static function (string $method, string $path, string $phone, ?array $jsonBody = null) use ($port, $secret): array {
    $body = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    $ts   = (string) time();
    $sig  = bot_signature($secret, $ts, $method, $path, $phone, $body);
    $ch   = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Bot-Key: hermes-test',
            'X-Bot-Timestamp: ' . $ts,
            'X-Bot-Phone: ' . $phone,
            'X-Bot-Signature: ' . $sig,
        ],
        CURLOPT_POSTFIELDS     => $method === 'POST' ? $body : null,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
};

// Siapkan data uji yang bersih
$cleanup = static function () use ($db): void {
    $db->query("DELETE FROM wa_outbox WHERE payload LIKE '%TESTBOT%' OR ref_type='complaint'");
    $db->query("DELETE k FROM cash_book k JOIN payments p ON p.id=k.ref_payment_id JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTBOT'");
    $db->query("DELETE p FROM payments p JOIN bills b ON b.id=p.bill_id WHERE b.notes='TESTBOT'");
    $db->query("DELETE o FROM payment_gateway_orders o JOIN bills b ON b.id=o.bill_id WHERE b.notes='TESTBOT'");
    $db->query("DELETE FROM bills WHERE notes='TESTBOT'");
    $db->query("DELETE FROM billing_periods WHERE label LIKE 'TESTBOT%'");
    $db->query("DELETE FROM complaints WHERE title LIKE 'TESTBOT%'");
    $db->query("UPDATE users SET phone=NULL, wa_notify=0 WHERE username IN ('ketua','bendahara','sekretaris')");
    $db->query("UPDATE payment_channels SET is_active=0 WHERE provider IN ('gopay','shopeepay')");
};

try {
    $ok($ready, 'server listening on port ' . $port);
    $cleanup();

    // Set nomor WA untuk Ketua, Bendahara, Sekretaris
    $db->query("UPDATE users SET phone='628110000001', wa_notify=1 WHERE username='ketua'");
    $db->query("UPDATE users SET phone='628110000002', wa_notify=1 WHERE username='bendahara'");
    $db->query("UPDATE users SET phone='628110000003', wa_notify=1 WHERE username='sekretaris'");

    // Warga 1 (Ikhsan, 081398461650) sudah ada di seed
    $wargaPhone = '081398461650';
    $resRow = $db->query("SELECT id, unit_id FROM residents WHERE phone='081398461650' LIMIT 1")->fetch_assoc();
    $ok(!empty($resRow), 'seeded resident Ikhsan found');
    $resId  = (int) $resRow['id'];
    $unitId = (int) $resRow['unit_id'];

    // Buat periode & tagihan uji untuk unit Ikhsan
    $db->query("INSERT INTO billing_periods (period_year, period_month, label, due_date) VALUES (2098, 11, 'TESTBOT Nov 2098', CURDATE())");
    $periodId = (int) $db->insert_id;
    $db->query("INSERT INTO bills (billing_period_id, unit_id, resident_id, amount, fine_amount, total_amount, status, due_date, notes)
                VALUES ({$periodId}, {$unitId}, {$resId}, 200000, 0, 200000, 'belum_bayar', CURDATE(), 'TESTBOT')");
    $billId = (int) $db->insert_id;

    // 1. /api/bot/me.php — warga terdaftar vs nomor asing
    $me = $callBot('GET', '/api/bot/me.php', $wargaPhone);
    $ok($me['status'] === 200 && $me['json']['data']['registered'] === true, 'me.php returns registered=true for resident');
    $ok($me['json']['data']['units'][0]['unit_id'] === $unitId, 'me.php returns resident unit');

    $stranger = $callBot('GET', '/api/bot/me.php', '089988887777');
    $ok($stranger['status'] === 200 && $stranger['json']['data']['registered'] === false, 'me.php returns registered=false for stranger');

    // Nomor asing ditolak di endpoint lain
    $strangerBills = $callBot('GET', '/api/bot/bills.php', '089988887777');
    $ok($strangerBills['status'] === 403 && $strangerBills['json']['error']['code'] === 'UNREGISTERED', 'stranger blocked from bills.php');

    // 2. /api/bot/bills.php
    $bills = $callBot('GET', '/api/bot/bills.php', $wargaPhone);
    $ok($bills['status'] === 200 && $bills['json']['data']['active_count'] >= 1, 'bills.php returns active bills');
    $foundTestBill = false;
    foreach ($bills['json']['data']['active'] as $b) {
        if ($b['bill_id'] === $billId) {
            $foundTestBill = ($b['total']['value'] === 200000);
        }
    }
    $ok($foundTestBill, 'test bill present with exact amount 200000');

    // 3. /api/bot/cash_summary.php
    $cash = $callBot('GET', '/api/bot/cash_summary.php', $wargaPhone);
    $ok($cash['status'] === 200 && isset($cash['json']['data']['balance']['text']), 'cash_summary.php returns aggregate balance');

    // 4. /api/bot/qris_create.php (aktifkan channel gopay dengan QRIS statis contoh)
    $sampleBase = '00020101021126560016COM.NOBUBANK.WWW0118936001030000000099021012345678905204000053033605802ID5909TestShop96007Jakarta6304';
    $sampleQris = $sampleBase . qris_crc16($sampleBase);
    $gopayToken = json_encode(['Authorization' => 'Bearer mock', 'X-App-Id' => 'test']);
    $db->query("UPDATE payment_channels SET api_key='" . $db->real_escape_string($gopayToken) . "', merchant_id='" . $db->real_escape_string($sampleQris) . "', is_active=1 WHERE provider='gopay'");

    $qris1 = $callBot('POST', '/api/bot/qris_create.php', $wargaPhone, ['bill_id' => $billId]);
    $ok($qris1['status'] === 200 && !empty($qris1['json']['data']['order_id']), 'qris_create.php creates dynamic QRIS order');
    $orderId  = $qris1['json']['data']['order_id'];
    $payTotal = $qris1['json']['data']['total']['value'];
    $ok($payTotal > 200000 && $payTotal <= 200500, "qris total includes unique code ({$payTotal})");

    // Panggilan kedua untuk bill yang sama harus mengembalikan order_id yang SAMA (idempoten)
    $qris2 = $callBot('POST', '/api/bot/qris_create.php', $wargaPhone, ['bill_id' => $billId]);
    $ok($qris2['status'] === 200 && $qris2['json']['data']['order_id'] === $orderId, 'qris_create.php reuses active PENDING order');

    // 5. Warga biasa ditolak akses staff_summary.php
    $forbidStaff = $callBot('GET', '/api/bot/staff_summary.php', $wargaPhone);
    $ok($forbidStaff['status'] === 403 && $forbidStaff['json']['error']['code'] === 'STAFF_ONLY', 'resident blocked from staff_summary.php');

    // Pengurus (Ketua) diizinkan akses staff_summary.php
    $staffSum = $callBot('GET', '/api/bot/staff_summary.php', '628110000001');
    $ok($staffSum['status'] === 200 && $staffSum['json']['data']['staff']['role'] === 'ketua', 'ketua allowed on staff_summary.php');

    // 6. Finalisasi pembayaran -> memicu 4 notifikasi WA (1 warga + Ketua + Bendahara + Sekretaris)
    $fin = gateway_finalize($orderId, (float) $payTotal);
    $ok($fin['ok'] && $fin['changed'] === true && $fin['payment_id'] > 0, 'gateway_finalize succeeds');

    $qStatus = $callBot('GET', '/api/bot/qris_status.php?order_id=' . urlencode($orderId), $wargaPhone);
    $ok($qStatus['status'] === 200 && $qStatus['json']['data']['status'] === 'PAID', 'qris_status.php reports PAID');
    $ok(!empty($qStatus['json']['data']['receipt_url']), 'qris_status.php includes signed receipt_url');

    // Cek 4 event di wa_outbox untuk payment_id tersebut
    $pid = (int) $fin['payment_id'];
    $outRows = $db->query("SELECT event_type, to_phone, audience FROM wa_outbox WHERE ref_type='payment' AND ref_id={$pid} ORDER BY audience, to_phone")->fetch_all(MYSQLI_ASSOC);
    $ok(count($outRows) === 4, 'exactly 4 WA notifications enqueued (1 resident + 3 staff)');

    // 7. Uji pull & ack via /api/bot/outbox.php oleh pengurus
    $pull = $callBot('GET', '/api/bot/outbox.php?limit=10', '628110000001');
    $ok($pull['status'] === 200 && count($pull['json']['data']['events']) >= 4, 'outbox.php returns pending events');
    $evIds = array_column($pull['json']['data']['events'], 'event_id');
    $ack = $callBot('POST', '/api/bot/outbox.php', '628110000001', ['sent' => $evIds]);
    $ok($ack['status'] === 200 && $ack['json']['data']['acknowledged'] >= 4, 'outbox.php acknowledges sent events');

    // 8. Uji complaints.php (POST + GET) & events.php
    $newComp = $callBot('POST', '/api/bot/complaints.php', $wargaPhone, [
        'title'       => 'TESTBOT Lampu jalan padam',
        'description' => 'Lampu depan blok A mati sejak semalam',
        'category'    => 'fasilitas',
    ]);
    $ok($newComp['status'] === 201 && str_starts_with($newComp['json']['data']['ticket_code'], 'PG-'), 'complaints.php POST creates ticket');

    $listComp = $callBot('GET', '/api/bot/complaints.php', $wargaPhone);
    $ok($listComp['status'] === 200 && count($listComp['json']['data']['complaints']) >= 1, 'complaints.php GET lists own complaints');

    $evList = $callBot('GET', '/api/bot/events.php', $wargaPhone);
    $ok($evList['status'] === 200 && is_array($evList['json']['data']['events']), 'events.php returns events array');

    // 9. Uji halaman kwitansi publik bertanda tangan via HTTP
    $receiptUrl = $qStatus['json']['data']['receipt_url'];
    $receiptPath = (string) parse_url($receiptUrl, PHP_URL_PATH) . '?' . (string) parse_url($receiptUrl, PHP_URL_QUERY);
    // Hilangkan prefix /arya-green-ipl bila ada karena docroot built-in server adalah root repo
    $receiptPath = preg_replace('#^/arya-green-ipl/#', '/', $receiptPath);
    $html = @file_get_contents("http://127.0.0.1:{$port}{$receiptPath}");
    $ok($html !== false && str_contains($html, 'KWITANSI PEMBAYARAN') && str_contains($html, 'TESTBOT Nov 2098'), 'receipt_public.php renders verified receipt without login');
} finally {
    $cleanup();
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
}

echo "bot_api_test OK ({$assertions} assertions)\n";

