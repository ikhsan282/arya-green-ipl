<?php
/**
 * Pembuat & validator tautan kwitansi publik bertanda tangan HMAC.
 *
 * Dipakai pada notifikasi WhatsApp supaya warga dapat membuka dan mencetak
 * kwitansi langsung dari tautan tanpa harus login terlebih dahulu.
 */
require_once __DIR__ . '/../config/config.php';

function receipt_signing_secret(): string
{
    if (defined('RECEIPT_SIGN_SECRET') && strlen(RECEIPT_SIGN_SECRET) >= 32) {
        return RECEIPT_SIGN_SECRET;
    }
    if (defined('BOT_WEBHOOK_SECRET') && strlen(BOT_WEBHOOK_SECRET) >= 32) {
        return hash_hmac('sha256', 'receipt-link', BOT_WEBHOOK_SECRET);
    }
    if (defined('BOT_API_KEYS') && is_array(BOT_API_KEYS) && BOT_API_KEYS) {
        $keys  = BOT_API_KEYS;
        $first = (string) reset($keys);
        if (strlen($first) >= 32) {
            return hash_hmac('sha256', 'receipt-link', $first);
        }
    }
    // Fallback deterministik per instalasi bila secret khusus belum diisi
    $seed = __FILE__ . '|' . DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS;
    return hash('sha256', $seed);
}

function receipt_signature(int $paymentId, int $exp): string
{
    return hash_hmac('sha256', $paymentId . '|' . $exp, receipt_signing_secret());
}

function receipt_sign_url(int $paymentId, int $ttlDays = 30, ?int $now = null): string
{
    $exp = ($now ?? time()) + max(1, $ttlDays) * 86400;
    $sig = receipt_signature($paymentId, $exp);
    return APP_URL . '/pages/payments/receipt_public.php?p=' . $paymentId . '&exp=' . $exp . '&sig=' . $sig;
}

function receipt_verify_params(int $paymentId, int $exp, string $sig, ?int $now = null): bool
{
    if ($paymentId <= 0 || $exp <= ($now ?? time()) || strlen($sig) !== 64) {
        return false;
    }
    return hash_equals(receipt_signature($paymentId, $exp), strtolower($sig));
}
