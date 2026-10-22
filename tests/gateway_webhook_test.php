<?php
require_once __DIR__ . '/_cli_only.php';
// Focused gateway helper self-check; integration webhook scenarios require configured dev DB/channel.
require_once __DIR__ . '/../includes/payment_gateway.php';
assert(gateway_is_paid(['status' => 'SUCCESS']));
assert(gateway_is_paid(['status' => 'paid']));
assert(!gateway_is_paid(['status' => 'PENDING']));
assert(!gateway_is_paid([]));
assert(gateway_base_url(['mode' => 'sandbox']) === rtrim(getenv('KLIKQRIS_BASE_URL') ?: 'https://klikqris.com/api/sandbox', '/'));
echo "gateway_webhook_test helper assertions OK\n";
