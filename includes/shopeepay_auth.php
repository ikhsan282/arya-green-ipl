<?php
/**
 * Token ShopeePay Partner: validasi & simpan.
 *
 * Tidak ada login OTP untuk ShopeePay — token diambil manual dari Portal Partner
 * (DevTools → Request Payload → data.metadata.token, berawalan "B:"). Yang bisa
 * diotomatiskan hanya pengecekan validitas, supaya token mati ketahuan lebih awal
 * dari sekadar "pembayaran tidak pernah lunas".
 */

/** Validasi token dengan memanggil API riwayat transaksi; 401/403 = token mati. */
function spay_token_check(array $channel): array
{
    require_once __DIR__ . '/payment_gateway_direct.php';
    $token = trim((string) ($channel['api_key'] ?? ''));
    if ($token === '') return ['ok' => true, 'state' => 'empty', 'error' => null];
    if (!str_starts_with($token, 'B:')) {
        return ['ok' => false, 'state' => 'invalid', 'error' => 'Token ShopeePay harus diawali "B:" (salin dari data.metadata.token di Portal Partner).'];
    }

    // Cek token: pembayaran bernominal negatif tidak mungkin ada, jadi "tidak ada transaksi"
    // berarti token sah. Hanya error API (token mati/ditolak) yang menggagalkan pemeriksaan.
    $r = gq_check_shopeepay($token, -1, time() - 3600);
    if ($r['ok']) return ['ok' => true, 'state' => 'valid', 'error' => null];

    $err = (string) ($r['error'] ?? '');
    $http = (int) ($r['http'] ?? 0);
    if ($http === 401 || $http === 403
        || stripos($err, 'unauthorized') !== false || stripos($err, 'expired') !== false
        || stripos($err, 'login') !== false || stripos($err, 'invalid token') !== false) {
        return ['ok' => true, 'state' => 'expired', 'error' => 'Token ShopeePay kedaluwarsa. Login ulang partner.shopee.co.id lalu tempel token baru.'];
    }
    return ['ok' => false, 'state' => 'unknown', 'error' => $err];
}
