<?php
// Database-backed regression checks; run with /opt/data/bin/php tests/payment_channels_test.php.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = db();
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); };
$assert = static function (bool $ok, string $message) use ($fail): void { if (!$ok) $fail($message); };
$st = $db->prepare("SELECT id FROM permissions WHERE name='payment_channels.manage'"); $st->execute();
$assert((bool)$st->get_result()->fetch_row(), 'permission exists');
$st=$db->prepare("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='payment_channels.manage' AND rp.role_id IN (1,2)"); $st->execute();
$assert((int)$st->get_result()->fetch_row()[0]===2,'only super_admin and ketua receive permission');
$src=file_get_contents(__DIR__.'/../pages/payment_channels/index.php');
$assert(!str_contains($src,'value="<?=e($channel[\'api_key\']'), 'API key must not be rendered as input value');
$assert(str_contains($src,"str_repeat('•'"), 'masked API key rendered');
$assert(str_contains($src,"require_permission('payment_channels.manage')"),'route enforces channel permission');
$st=$db->prepare("SELECT id FROM payment_channels WHERE code='klikqris'"); $st->execute();
$assert((bool)$st->get_result()->fetch_row(),'seeded KlikQRIS channel exists');
echo "payment_channels_test OK\n";
