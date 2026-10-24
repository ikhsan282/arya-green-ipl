<?php
require_once __DIR__ . '/_cli_only.php';
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$envForm = file_get_contents(__DIR__ . '/../pages/environments/form.php');
$billing = file_get_contents(__DIR__ . '/../pages/billing/index.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(str_contains($schema, '`due_day`') && str_contains($schema, 'TINYINT UNSIGNED NOT NULL DEFAULT 20'), 'environment schema has default due day 20');
$assert(str_contains($envForm, "'due_day'       => (int)(\$_POST['due_day'] ?? 20)"), 'environment form accepts due day');
$assert(str_contains($envForm, 'due_day=?') && str_contains($envForm, 'due_day,custom_domain,is_active) VALUES'), 'environment create and update persist due day');
$assert(str_contains($billing, "SELECT due_day FROM environments WHERE id=1") && str_contains($billing, 'date(\'Y-m-\') . str_pad((string)$envDueDay'), 'billing generate defaults to environment due day');
echo "environment_due_date_test OK (4 assertions)\n";
