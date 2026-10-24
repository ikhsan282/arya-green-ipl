<?php
require_once __DIR__ . '/_cli_only.php';
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$billing = file_get_contents(__DIR__ . '/../pages/billing/index.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(str_contains($schema, "('billing.delete'"), 'dedicated billing delete permission exists');
$assert(str_contains($schema, "'environments.delete','billing.delete'") && str_contains($schema, "'billing.delete',\n                     'payment_methods.view'"), 'non-super-admin chair excludes billing delete');
$assert(str_contains($billing, "require_permission('billing.delete')"), 'delete endpoint enforces dedicated permission');
$assert(str_contains($billing, "can('billing.delete')"), 'delete button gated by permission');
echo "billing_delete_role_test OK (4 assertions)\n";
