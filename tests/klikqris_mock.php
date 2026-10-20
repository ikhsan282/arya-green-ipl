<?php
// Local HTTP mock for KlikQRIS. State is stored in KLIKQRIS_MOCK_STATE or temp dir.
$stateFile = getenv('KLIKQRIS_MOCK_STATE') ?: sys_get_temp_dir() . '/klikqris-mock-p3.json';
$state = is_file($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/test/(PAID|EXPIRED|PENDING|AMOUNT_MISMATCH)/([A-Za-z0-9_-]{1,64})$#', $path, $m)) {
    $order = $state[$m[2]] ?? ['order_id' => $m[2], 'amount' => 0, 'total_amount' => 0];
    $order['status'] = $m[1] === 'AMOUNT_MISMATCH' ? 'PAID' : $m[1];
    if ($m[1] === 'AMOUNT_MISMATCH') $order['total_amount'] = (float) $order['total_amount'] + 1;
    $state[$m[2]] = $order;
    file_put_contents($stateFile, json_encode($state));
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}
header('Content-Type: application/json');
if ($path === '/qris/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (string) ($in['order_id'] ?? '');
    $state[$id] = ['order_id' => $id, 'amount' => (int) ($in['amount'] ?? 0), 'total_amount' => (int) ($in['amount'] ?? 0), 'status' => 'PENDING'];
    file_put_contents($stateFile, json_encode($state));
    echo json_encode(['status' => true, 'data' => $state[$id] + ['qris_url' => 'https://mock.invalid/qris', 'expired_at' => '2030-01-01 00:00:00']]); exit;
}
if (preg_match('#^/qris/status/([A-Za-z0-9_-]{1,64})$#', $path, $m)) {
    echo json_encode(['data' => $state[$m[1]] ?? ['status' => 'PENDING']]); exit;
}
http_response_code(404); echo '{"status":false}';
