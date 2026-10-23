<?php
// Mock server untuk check-payment API GoPay & ShopeePay.
// gq_create tidak perlu mock (QRIS digenerate lokal); mock ini hanya untuk gq_check.
// State di file JSON; /test/PAY/<amount> menandai pembayaran masuk.
// /test/TX/<id>/<amount>[/<time>] menandai transaksi dengan ID eksplisit —
// dipakai menguji dua order bernominal sama (satu transaksi, dua order).
$stateFile = getenv('GQ_MOCK_STATE') ?: sys_get_temp_dir() . '/gq-mock.json';
$state = is_file($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : ['tx' => []];
$state += ['tx' => []];
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body  = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
header('Content-Type: application/json');
$save = function () use (&$state, $stateFile) { file_put_contents($stateFile, json_encode($state)); };

// /test/PAY/<amount> → mark amount as paid
if (preg_match('#^/test/PAY/(\d+)$#', $path, $m)) {
    $state['tx'][] = ['id' => 'TX' . $m[1], 'amount' => (int) $m[1], 'time' => time()];
    $save(); echo '{"ok":true}'; exit;
}

// /test/TX/<id>/<amount>[/<time>] → transaksi dengan ID eksplisit
if (preg_match('#^/test/TX/([\w-]+)/(\d+)(?:/(\d+))?$#', $path, $m)) {
    $state['tx'][] = ['id' => $m[1], 'amount' => (int) $m[2], 'time' => isset($m[3]) ? (int) $m[3] : time()];
    $save(); echo '{"ok":true}'; exit;
}

// GoPay: GET /merchant-analytics/v2/merchants/transactions
// Kontrak: header Authorization wajib (sesi GoPay dikirim sebagai JSON header map).
if (str_contains($path, '/merchants/transactions')) {
    if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(401); echo '{"message":"unauthorized"}'; exit;
    }
    // /test/AUTH/<token> mengatur token yang diterima; default AT-GOOD (dipakai tes OTP).
    // "Bearer mock" tetap diterima: itu token yang dipakai suite gateway lama.
    $good = $state['good_token'] ?? null;
    $got  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($got !== 'Bearer mock' && ($good === null ? 'Bearer AT-GOOD' : $good) !== $got) {
        http_response_code(401); echo '{"message":"token expired"}'; exit;
    }
    $transactions = [];
    foreach ($state['tx'] as $tx) {
        $transactions[] = ['amount' => (int) $tx['amount'], 'transaction_id' => $tx['id'],
                           'order_id' => 'GOPAY-' . $tx['id'], 'status' => 'SETTLEMENT',
                           'transaction_time' => gmdate('Y-m-d\TH:i:s\Z', (int) $tx['time']),
                           'payer_issuer' => 'Mock Bank', 'payment_type' => 'QRIS'];
    }
    echo json_encode(['data' => $transactions]);
    exit;
}

// ShopeePay: POST /api/v1/report/transaction/get-transaction-list
// Kontrak: body dibungkus `data`, token di data.metadata.token,
// header Origin + Referer wajib. Nominal dalam rupiah utuh (bukan sen).
if (str_contains($path, '/get-transaction-list')) {
    if (empty($body['data']['metadata']['token']) || empty($_SERVER['HTTP_ORIGIN']) || empty($_SERVER['HTTP_REFERER'])) {
        http_response_code(400); echo '{"msg":"invalid request"}'; exit;
    }
    $tok = (string) ($body['data']['metadata']['token'] ?? '');
    // Gateway asli memakai token "B:..."; mock menerima bentuk itu maupun polos.
    if (!in_array($tok, ['mock-token', 'B:mock-token'], true)) {
        http_response_code(401); echo '{"msg":"token invalid"}'; exit;
    }
    $transactions = [];
    foreach ($state['tx'] as $tx) {
        $transactions[] = ['amount' => (int) $tx['amount'], 'order_sn' => $tx['id'],
                           'status' => 'SUCCESS', 'create_time' => (int) $tx['time'], 'issuer' => 'Mock Bank'];
    }
    echo json_encode(['data' => ['transactionList' => $transactions]]);
    exit;
}

http_response_code(404); echo '{"success":false,"message":"not found"}';
