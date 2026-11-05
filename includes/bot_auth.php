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
 * @return array{ok:bool,status:int,code:string,key_id:?string,phone:?string}
 */
function bot_verify_request(array $server, string $body, ?int $now = null): array
{
    $fail = static fn(int $status, string $code): array =>
        ['ok' => false, 'status' => $status, 'code' => $code, 'key_id' => null, 'phone' => null];

    if (!defined('BOT_API_KEYS') || !BOT_API_KEYS) return $fail(503, 'BOT_API_DISABLED');

    $keyId = (string) ($server['HTTP_X_BOT_KEY'] ?? '');
    $ts    = (string) ($server['HTTP_X_BOT_TIMESTAMP'] ?? '');
    $phone = (string) ($server['HTTP_X_BOT_PHONE'] ?? '');
    $sig   = strtolower((string) ($server['HTTP_X_BOT_SIGNATURE'] ?? ''));
    $secret = BOT_API_KEYS[$keyId] ?? null;

    if (!is_string($secret) || strlen($secret) < 32 || $sig === '' || !ctype_digit($ts)) {
        return $fail(401, 'UNAUTHORIZED');
    }
    if (abs(($now ?? time()) - (int) $ts) > BOT_MAX_CLOCK_SKEW) {
        return $fail(401, 'TIMESTAMP_EXPIRED');
    }
    $expected = bot_signature($secret, $ts, (string) ($server['REQUEST_METHOD'] ?? 'GET'),
                              (string) ($server['REQUEST_URI'] ?? ''), $phone, $body);
    if (!hash_equals($expected, $sig)) {
        return $fail(401, 'UNAUTHORIZED');
    }
    $norm = phone_normalize($phone);
    if ($norm === null) return $fail(400, 'INVALID_PHONE');

    return ['ok' => true, 'status' => 200, 'code' => 'OK', 'key_id' => $keyId, 'phone' => $norm];
}

/**
 * Isi residents.phone_norm yang belum diproses. '' = sudah diproses tapi bukan nomor
 * seluler valid (mis. placeholder TEMP-xx), supaya tidak diproses ulang tiap request.
 */
function bot_backfill_phone_norm(mysqli $db): int
{
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
 */
function bot_resolve_actor(mysqli $db, string $phone): array
{
    bot_backfill_phone_norm($db);

    $st = $db->prepare(
        'SELECT r.id, r.name, r.phone, r.status, u.id AS unit_id, u.block, u.unit_number
           FROM residents r JOIN units u ON u.id = r.unit_id
          WHERE r.phone_norm = ? AND r.is_active = 1
          ORDER BY u.block, u.unit_number'
    );
    $st->bind_param('s', $phone);
    $st->execute();
    $residents = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        // Pertahanan berlapis: phone_norm bisa basi bila ada jalur tulis yang lupa
        // memperbaruinya. Cocokkan ulang dari kolom phone asli.
        if (phone_normalize($r['phone']) !== $phone) continue;
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

