<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('users.view');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT signature_file FROM users WHERE id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_row();
$filename = $row[0] ?? null;
if (!$filename || !preg_match('/^[a-f0-9]{48}\\.(png|jpg)$/', $filename)) {
    http_response_code(404);
    exit;
}
$path = SIGNATURE_DIR . $filename;
$info = @getimagesize($path);
if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $info['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
