<?php
require_once __DIR__ . '/_cli_only.php';
$billing = file_get_contents(__DIR__ . '/../pages/billing/index.php');
if (!str_contains($billing, 'can(\'billing.delete\') && $b[\'status\'] !== \'sudah_bayar\'')) {
    fwrite(STDERR, "FAIL: super admin delete action should show for all bills without paid status\n");
    exit(1);
}
echo "billing_delete_status_test OK\n";
