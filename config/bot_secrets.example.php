<?php
/**
 * Salin file ini menjadi config/bot_secrets.php lalu isi secret asli.
 * Jangan commit file config/bot_secrets.php ke git.
 *
 * BOT_API_KEYS       : key_id => secret (minimal 32 karakter) untuk autentikasi inbound Hermes -> IPL.
 * BOT_WEBHOOK_URL    : URL HTTPS endpoint Hermes penerima event outbound (kosongkan bila memakai mode pull).
 * BOT_WEBHOOK_SECRET : secret HMAC-SHA256 untuk menandatangani webhook outbound IPL -> Hermes.
 * RECEIPT_SIGN_SECRET: secret HMAC-SHA256 untuk tautan kwitansi publik (default otomatis bila dikosongkan).
 *
 * Buat secret acak dengan:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */
if (!defined('BOT_API_KEYS')) {
    $envKeys = json_decode((string) getenv('IPL_BOT_KEYS'), true);
    $defaultKeys = [
        'hermes-prod' => 'GANTI_DENGAN_SECRET_KEY_MINIMAL_32_KARAKTER',
    ];
    define('BOT_API_KEYS', is_array($envKeys) ? array_merge($defaultKeys, $envKeys) : $defaultKeys);
}
if (!defined('BOT_WEBHOOK_URL'))    define('BOT_WEBHOOK_URL',     '');
if (!defined('BOT_WEBHOOK_SECRET')) define('BOT_WEBHOOK_SECRET',  'GANTI_DENGAN_SECRET_KEY_MINIMAL_32_KARAKTER');
if (!defined('RECEIPT_SIGN_SECRET')) define('RECEIPT_SIGN_SECRET', 'GANTI_DENGAN_SECRET_KEY_MINIMAL_32_KARAKTER');
