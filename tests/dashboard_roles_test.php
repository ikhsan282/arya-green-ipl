<?php
require_once __DIR__ . '/_cli_only.php';
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$dashboard = file_get_contents(__DIR__ . '/../pages/dashboard.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(str_contains($schema, "('sekretaris',  'Sekretaris')"), 'sekretaris role seeded');
$assert(str_contains($schema, "SELECT (SELECT `id` FROM `roles` WHERE `name`='sekretaris')"), 'role permission assignment resolves by name');
$secretaryBlock = substr($schema, strpos($schema, '-- Sekretaris:'), strpos($schema, '-- Ketua:') - strpos($schema, '-- Sekretaris:'));
$assert(str_contains($secretaryBlock, "'residents.view'") && str_contains($secretaryBlock, "'events.manage'"), 'secretary gets resident administration and events permissions');
$assert(!str_contains($secretaryBlock, "'payments.view'") && !str_contains($secretaryBlock, "'cashbook.view'"), 'secretary does not get finance detail permissions');
// Ensure sensitive capabilities stay excluded from the secretary allowlist.
foreach (['cashbook.manage', 'payments.verify', 'payment_channels.manage', 'users.delete', 'roles.manage'] as $forbidden) {
    $assert(!str_contains($secretaryBlock, "'{$forbidden}'"), "secretary denied {$forbidden}");
}
$assert(str_contains($schema, "INSERT INTO `users` (`role_id`,`name`,`username`,`email`,`password`,`is_active`)\nSELECT `id`, 'Sekretaris', 'sekretaris', 'sekretaris@aryagreenpamulang.my.id', NULL, 0\nFROM `roles` WHERE `name`='sekretaris'"), 'disabled secretary placeholder user is seeded without a password');
$assert(str_contains($dashboard, "if (\$_role === 'warga')"), 'separate resident layout');
$assert(str_contains($dashboard, 'Saldo Kas Lingkungan') && str_contains($dashboard, 'Pengeluaran Kas'), 'resident cash transparency cards');
$assert(str_contains($dashboard, 'Kegiatan Mendatang') && str_contains($dashboard, 'Pembayaran Saya'), 'resident information sections');
$assert(str_contains($dashboard, "if (\$_role !== 'warga')"), 'staff-only cashflow chart remains guarded');
$assert(str_contains($dashboard, "label: 'Terkumpul'"), 'staff chart labels stay unchanged');
$assert(str_contains($dashboard, "label: 'Tunggakan'"), 'staff arrears chart stays unchanged');
echo "dashboard_roles_test OK (14 assertions)\n";
