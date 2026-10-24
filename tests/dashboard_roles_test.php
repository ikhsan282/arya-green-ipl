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
$assert(str_contains($schema, "SELECT (SELECT `id` FROM `roles` WHERE `name`='warga'), r.`name`, LOWER(r.`name`),\n       CONCAT(LOWER(r.`name`),'@aryagreenpamulang.my.id')"), 'resident users seeded from resident names with lowercase usernames');
$assert(str_contains($schema, "INSERT INTO `users` (`role_id`,`name`,`username`,`email`,`password`,`email_verified_at`)"), 'resident accounts include login credentials');
$assert(str_contains($schema, "FROM `residents` r WHERE r.`notes`='seed'"), 'resident users are generated from seeded residents');
$assert(str_contains($schema, "UPDATE `residents` r JOIN `users` u ON u.`username`=LOWER(r.`name`)\nSET r.`user_id`=u.`id` WHERE r.`notes`='seed'"), 'each seeded resident is linked to its login user');
$assert(!str_contains($schema, "'warga', 'warga@aryagreenpamulang.my.id'"), 'generic warga sample account removed');
$secretarySeed = substr($schema, strpos($schema, "'Sekretaris', 'sekretaris'"), 300);
$assert(str_contains($secretarySeed, "'sekretaris@aryagreenpamulang.my.id'") && str_contains($secretarySeed, 'NOW()') && str_contains($secretarySeed, 'oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu'), 'secretary account is active, password-enabled, and verified on fresh install');

$assert(str_contains($dashboard, "if (\$_role === 'warga')"), 'separate resident layout');
$assert(str_contains($dashboard, 'Saldo Kas Lingkungan') && str_contains($dashboard, 'Pengeluaran Kas'), 'resident cash transparency cards');
$assert(str_contains($dashboard, 'Kegiatan Mendatang') && str_contains($dashboard, 'Pembayaran Saya'), 'resident information sections');
$assert(str_contains($dashboard, "if (\$_role !== 'warga')"), 'staff-only cashflow chart remains guarded');
$assert(str_contains($dashboard, "label: 'Terkumpul'"), 'staff chart labels stay unchanged');
$assert(str_contains($dashboard, "label: 'Tunggakan'"), 'staff arrears chart stays unchanged');
echo "dashboard_roles_test OK (14 assertions)\n";
