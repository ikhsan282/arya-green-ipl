<?php
/**
 * Bootstrap bersama untuk semua endpoint /api/bot/*.
 * Tidak memakai sesi PHP sama sekali: identitas murni dari header bertanda tangan.
 *
 * Pemakaian di endpoint:
 *   require __DIR__ . '/_bootstrap.php';
 *   $ctx = bot_boot(['GET']);            // ['phone'=>..., 'actor'=>[...], 'body'=>[...]]
 *   bot_reply(200, [...data...]);
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/bot_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$GLOBALS['BOT_CTX'] = ['phone' => null, 'endpoint' => basename($_SERVER['SCRIPT_NAME'] ?? '', '.php')];

/** Kirim respons sukses dan akhiri request. */
function bot_reply(int $status, array $data): never
{
    bot_log_request($status);
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Kirim respons error dan akhiri request. `message` aman ditampilkan apa adanya ke warga. */
function bot_error(int $status, string $code, string $message): never
{
    bot_log_request($status);
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Catat hanya request yang sudah lolos autentikasi (request liar tidak boleh membanjiri DB). */
function bot_log_request(int $status): void
{
    $phone = $GLOBALS['BOT_CTX']['phone'] ?? null;
    if ($phone === null) return;
    try {
        $st = db()->prepare('INSERT INTO bot_request_logs (phone, endpoint, http_status, ip_address) VALUES (?,?,?,?)');
        $ep = $GLOBALS['BOT_CTX']['endpoint'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $st->bind_param('ssis', $phone, $ep, $status, $ip);
        $st->execute();
        if (random_int(1, 200) === 1) {
            db()->query('DELETE FROM bot_request_logs WHERE created_at < NOW() - INTERVAL 30 DAY');
        }
    } catch (Throwable $e) {
        error_log('bot_log_request: ' . $e->getMessage());
    }
}

/**
 * Validasi method, tanda tangan, rate-limit, dan resolusi identitas.
 * @param string[] $methods
 * @param bool $requireRegistered tolak nomor yang tidak terdaftar sebagai warga/pengurus
 */
function bot_boot(array $methods, bool $requireRegistered = true): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        bot_error(405, 'METHOD_NOT_ALLOWED', 'Metode tidak diizinkan.');
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 16384) bot_error(413, 'PAYLOAD_TOO_LARGE', 'Permintaan terlalu besar.');

    $auth = bot_verify_request($_SERVER, $raw);
    if (!$auth['ok']) {
        error_log('bot auth failed: ' . $auth['code'] . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        bot_error($auth['status'], $auth['code'], 'Permintaan tidak dapat diautentikasi.');
    }
    $GLOBALS['BOT_CTX']['phone'] = $auth['phone'];
    $db = db();

    $st = $db->prepare('SELECT COUNT(*) FROM bot_request_logs WHERE phone=? AND created_at > NOW() - INTERVAL 60 SECOND');
    $st->bind_param('s', $auth['phone']);
    $st->execute();
    if ((int) $st->get_result()->fetch_row()[0] >= BOT_RATE_PER_MIN) {
        bot_error(429, 'RATE_LIMITED', 'Terlalu banyak permintaan. Coba lagi dalam satu menit.');
    }

    $body = [];
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (!is_array($body)) bot_error(400, 'INVALID_JSON', 'Format permintaan tidak valid.');
    }

    $actor = bot_resolve_actor($db, $auth['phone']);
    if ($requireRegistered && !$actor['registered']) {
        bot_error(403, 'UNREGISTERED',
            'Nomor WhatsApp ini belum terdaftar sebagai warga ' . APP_NAME
            . '. Silakan hubungi Sekretaris/Pengurus untuk memperbarui nomor kontak.');
    }
    return ['phone' => $auth['phone'], 'actor' => $actor, 'body' => $body, 'db' => $db];
}

/** Daftar unit_id milik actor (sebagai warga aktif). */
function bot_actor_unit_ids(array $actor): array
{
    return array_values(array_unique(array_column($actor['residents'], 'unit_id')));
}

set_exception_handler(static function (Throwable $e): void {
    error_log('bot api ' . ($GLOBALS['BOT_CTX']['endpoint'] ?? '?') . ': ' . $e->getMessage());
    bot_error(500, 'INTERNAL_ERROR', 'Terjadi gangguan pada sistem. Silakan coba beberapa saat lagi.');
});

