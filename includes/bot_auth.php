<?php
/**
 * Autentikasi & identitas untuk API bot (Hermes WhatsApp).
 *
 * Model keamanan:
 *  - Setiap request ditandatangani HMAC-SHA256 dengan secret bersama (BOT_API_KEYS).
 *  - Nomor WhatsApp pengirim (X-Bot-Phone) ikut ditandatangani dan WAJIB diisi oleh
 *    kode Hermes dari metadata pesan, bukan oleh LLM. Nomor inilah satu-satunya dasar
 *    isolasi data antar-warga.
 *  - Timestamp dibatasi BOT_MAX_CLOCK_SKEW detik untuk mencegah replay.
 *
 * String yang ditandatangani (dipisah "\n"):
 *   timestamp, METHOD, request URI (path + query persis seperti dikirim), phone, sha256_hex(body)
 */
require_once __DIR__ . '/functions.php';

const BOT_STAFF_ROLES = ['super_admin', 'ketua', 'bendahara', 'sekretaris'];

function bot_signature(string $secret, string $ts, string $method, string $uri, string $phone, string $body): string
{
    $canonical = implode("\n", [$ts, strtoupper($method), $uri, $phone, hash('sha256', $body)]);
    return hash_hmac('sha256', $canonical, $secret);
}

/**
 * Verifikasi header request bot.
 * Mendukung 2 metode autentikasi:
 * 1. Simple Secret / Bearer Token (Authorization: Bearer <secret>, header X-Bot-Secret, query ?secret=, body secret)
 *    -> Sangat ramah AI Agent (Hermes / LLM) yang tidak memiliki engine HMAC-SHA256.
 * 2. HMAC-SHA256 Signature (X-Bot-Key, X-Bot-Timestamp, X-Bot-Signature, X-Bot-Phone)
 *    -> Untuk Postman, script Python, dan integrasi webhook ber-replay protection.
 *
 * @return array{ok:bool,status:int,code:string,key_id:?string,phone:?string,reason?:string}
 */
function bot_verify_request(array $server, string $body, ?int $now = null): array
{
    $fail = static fn(int $status, string $code, string $reason = ''): array =>
        ['ok' => false, 'status' => $status, 'code' => $code, 'key_id' => null, 'phone' => null, 'reason' => $reason];

    if (!defined('BOT_API_KEYS') || !BOT_API_KEYS) {
        return $fail(503, 'BOT_API_DISABLED', 'Bot API belum diaktifkan di konfigurasi server.');
    }

    // Resolusi nomor pengirim (X-Bot-Phone, X-Phone, ?phone=, atau body JSON phone)
    $phone = (string) ($server['HTTP_X_BOT_PHONE'] ?? ($server['HTTP_PHONE'] ?? ($_GET['phone'] ?? '')));
    if ($phone === '' && $body !== '') {
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['phone'])) {
            $phone = (string) $json['phone'];
        }
    }

    $norm = phone_normalize($phone);
    if ($norm === null) {
        return $fail(400, 'INVALID_PHONE', 'Header X-Bot-Phone (atau parameter phone) wajib diisi nomor WhatsApp yang valid.');
    }

    // --- METODE 1: Bearer Token / Simple Secret (Paling mudah untuk AI Agent Hermes) ---
    $authHeader = (string) ($server['HTTP_AUTHORIZATION'] ?? ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $bearerToken = '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $bearerToken = $m[1];
    } elseif (!empty($server['HTTP_X_BOT_SECRET'])) {
        $bearerToken = (string) $server['HTTP_X_BOT_SECRET'];
    } elseif (!empty($_GET['secret'])) {
        $bearerToken = (string) $_GET['secret'];
    } elseif ($body !== '') {
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['secret'])) {
            $bearerToken = (string) $json['secret'];
        }
    }

    if ($bearerToken !== '') {
        foreach (BOT_API_KEYS as $kId => $kSec) {
            if (hash_equals($kSec, $bearerToken)) {
                return ['ok' => true, 'status' => 200, 'code' => 'OK', 'key_id' => (string) $kId, 'phone' => $norm];
            }
        }
        return $fail(401, 'UNAUTHORIZED', 'Secret key / Bearer token tidak cocok dengan bot_secrets.php.');
    }

    // --- METODE 2: HMAC-SHA256 Signature (Untuk Postman / script otomatis) ---
    $keyId = (string) ($server['HTTP_X_BOT_KEY'] ?? '');
    $ts    = (string) ($server['HTTP_X_BOT_TIMESTAMP'] ?? '');
    $sig   = strtolower((string) ($server['HTTP_X_BOT_SIGNATURE'] ?? ''));
    $secret = BOT_API_KEYS[$keyId] ?? null;

    if ($sig !== '' || $keyId !== '') {
        if (!is_string($secret) || strlen($secret) < 32) {
            return $fail(401, 'UNAUTHORIZED', "ID kunci '$keyId' (X-Bot-Key) tidak ditemukan di bot_secrets.php.");
        }
        if ($sig === '' || !ctype_digit($ts)) {
            return $fail(401, 'UNAUTHORIZED', 'Header X-Bot-Signature atau X-Bot-Timestamp tidak valid.');
        }
        if (abs(($now ?? time()) - (int) $ts) > BOT_MAX_CLOCK_SKEW) {
            return $fail(401, 'TIMESTAMP_EXPIRED', 'Timestamp kadaluwarsa (perbedaan waktu server & bot > 300 detik).');
        }
        $expected = bot_signature($secret, $ts, (string) ($server['REQUEST_METHOD'] ?? 'GET'),
                                  (string) ($server['REQUEST_URI'] ?? ''), $phone, $body);
        if (!hash_equals($expected, $sig)) {
            return $fail(401, 'UNAUTHORIZED', 'HMAC signature tidak cocok.');
        }
        return ['ok' => true, 'status' => 200, 'code' => 'OK', 'key_id' => $keyId, 'phone' => $norm];
    }

    return $fail(401, 'UNAUTHORIZED', 'Autentikasi diperlukan. Kirim header Authorization: Bearer <secret> atau X-Bot-Secret: <secret>.');
}

/**
 * Isi residents.phone_norm dan phone_secondary_norm yang belum diproses.
 */
function bot_backfill_phone_norm(mysqli $db): int
{
    // Cek keberadaan kolom phone_secondary_norm secara aman
    $hasSec = false;
    try {
        $chk = $db->query("SHOW COLUMNS FROM residents LIKE 'phone_secondary_norm'")->fetch_assoc();
        $hasSec = !empty($chk);
    } catch (Throwable) {}

    if ($hasSec) {
        $rows = $db->query('SELECT id, phone, phone_secondary FROM residents WHERE phone_norm IS NULL OR phone_secondary_norm IS NULL')->fetch_all(MYSQLI_ASSOC);
        if (!$rows) return 0;
        $st = $db->prepare('UPDATE residents SET phone_norm=?, phone_secondary_norm=? WHERE id=?');
        foreach ($rows as $r) {
            $norm = phone_normalize($r['phone']) ?? '';
            $normSec = !empty($r['phone_secondary']) ? (phone_normalize($r['phone_secondary']) ?? '') : '';
            $id = (int) $r['id'];
            $st->bind_param('ssi', $norm, $normSec, $id);
            $st->execute();
        }
        return count($rows);
    }

    $rows = $db->query('SELECT id, phone FROM residents WHERE phone_norm IS NULL')->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return 0;
    $st = $db->prepare('UPDATE residents SET phone_norm=? WHERE id=?');
    foreach ($rows as $r) {
        $norm = phone_normalize($r['phone']) ?? '';
        $id = (int) $r['id'];
        $st->bind_param('si', $norm, $id);
        $st->execute();
    }
    return count($rows);
}

/**
 * Identitas pemilik nomor WhatsApp.
 * Satu nomor bisa sekaligus pengurus dan warga, dan bisa memiliki beberapa unit.
 * Mendukung nomor HP utama (suami) maupun nomor kedua (istri/pasangan).
 */
function bot_resolve_actor(mysqli $db, string $phone): array
{
    bot_backfill_phone_norm($db);

    // Cek kolom phone_secondary_norm
    $hasSec = false;
    try {
        $chk = $db->query("SHOW COLUMNS FROM residents LIKE 'phone_secondary_norm'")->fetch_assoc();
        $hasSec = !empty($chk);
    } catch (Throwable) {}

    if ($hasSec) {
        $st = $db->prepare(
            'SELECT r.id, r.name, r.phone, r.phone_secondary, r.status, u.id AS unit_id, u.block, u.unit_number
               FROM residents r JOIN units u ON u.id = r.unit_id
              WHERE (r.phone_norm = ? OR r.phone_secondary_norm = ?) AND r.is_active = 1
              ORDER BY u.block, u.unit_number'
        );
        $st->bind_param('ss', $phone, $phone);
    } else {
        $st = $db->prepare(
            'SELECT r.id, r.name, r.phone, r.status, u.id AS unit_id, u.block, u.unit_number
               FROM residents r JOIN units u ON u.id = r.unit_id
              WHERE r.phone_norm = ? AND r.is_active = 1
              ORDER BY u.block, u.unit_number'
        );
        $st->bind_param('s', $phone);
    }
    $st->execute();
    $residents = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        // Pertahanan berlapis: cocokkan ulang dari kolom phone asli
        $p1 = phone_normalize($r['phone'] ?? null);
        $p2 = !empty($r['phone_secondary']) ? phone_normalize($r['phone_secondary']) : null;
        if ($p1 !== $phone && $p2 !== $phone) continue;
        $residents[] = [
            'resident_id' => (int) $r['id'],
            'name'        => $r['name'],
            'status'      => $r['status'],
            'unit_id'     => (int) $r['unit_id'],
            'unit'        => $r['unit_number'],
            'block'       => $r['block'],
        ];
    }

    $placeholders = implode(',', array_fill(0, count(BOT_STAFF_ROLES), '?'));
    $st = $db->prepare(
        "SELECT u.id, u.name, r.name AS role, r.label AS role_label, u.wa_notify
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.phone = ? AND u.is_active = 1 AND r.name IN ($placeholders)
          ORDER BY u.role_id LIMIT 1"
    );
    $params = array_merge([$phone], BOT_STAFF_ROLES);
    $st->bind_param(str_repeat('s', count($params)), ...$params);
    $st->execute();
    $staffRow = $st->get_result()->fetch_assoc();
    $staff = $staffRow ? [
        'user_id'    => (int) $staffRow['id'],
        'name'       => $staffRow['name'],
        'role'       => $staffRow['role'],
        'role_label' => $staffRow['role_label'],
        'wa_notify'  => (bool) $staffRow['wa_notify'],
    ] : null;

    return [
        'phone'      => $phone,
        'registered' => $residents || $staff,
        'is_staff'   => $staff !== null,
        'staff'      => $staff,
        'residents'  => $residents,
    ];
}

/** Format uang untuk respons bot: angka bulat + teks siap tampil (LLM tidak perlu memformat). */
function bot_money(float $v): array
{
    return ['value' => (int) round($v), 'text' => idr($v)];
}

