<?php
require_once __DIR__ . '/_cli_only.php';
$root=dirname(__DIR__);
$gateway=file_get_contents($root.'/includes/payment_gateway.php');
$pay=file_get_contents($root.'/pages/payments/gateway_pay.php');
$status=file_get_contents($root.'/pages/payments/gateway_status.php');
$form=file_get_contents($root.'/pages/payments/form.php');
assert(str_contains($gateway,'function gateway_finalize(string $orderId, ?float $paid = null): array'));
assert(str_contains($gateway,'FOR UPDATE') && str_contains($gateway,'payment_id=?'));
assert(str_contains($pay,'gateway_pending_for_bill($billId)'));
assert(str_contains($pay,"'total_amount'"));
assert(str_contains($status,'gateway_finalize($orderId'));
assert(str_contains($status,'gateway_mark_expired($orderId)'));
assert(str_contains($form,'gateway_pay.php?bill_id='));
assert(str_contains($form,'$gateway_filter'));
echo "gateway_pay_test OK\n";
