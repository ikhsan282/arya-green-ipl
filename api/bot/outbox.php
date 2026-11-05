<?php
/**
 * Endpoint alternatif bila Hermes memakai mode PULL (tidak punya webhook publik):
 * - GET  /api/bot/outbox.php?limit=30
 * - POST /api/bot/outbox.php  Body: {"sent":["event_id_1"],"failed":[{"event_id":"...","error":"..."}]}
 *
 * Hanya nomor pengurus yang terdaftar di `users` yang boleh memanggil endpoint ini.
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET', 'POST']);
$db    = $ctx['db'];
$actor = $ctx['actor'];

if (!$actor['is_staff']) {
    bot_error(403, 'STAFF_ONLY', 'Endpoint outbox hanya untuk layanan Hermes internal.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent   = is_array($ctx['body']['sent'] ?? null) ? $ctx['body']['sent'] : [];
    $failed = is_array($ctx['body']['failed'] ?? null) ? $ctx['body']['failed'] : [];
    $ackCount = 0;

    if ($sent) {
        $st = $db->prepare('UPDATE wa_outbox SET status="sent", sent_at=NOW(), attempts=attempts+1, last_error=NULL WHERE event_id=? AND status="pending"');
        foreach ($sent as $evId) {
            $evId = trim((string) $evId);
            if ($evId === '') continue;
            $st->bind_param('s', $evId);
            $st->execute();
            $ackCount += max(0, $st->affected_rows);
        }
    }
    if ($failed) {
        $st = $db->prepare('UPDATE wa_outbox SET attempts=attempts+1, status=IF(attempts>=5,"failed","pending"), next_try_at=DATE_ADD(NOW(), INTERVAL 5 MINUTE), last_error=? WHERE event_id=? AND status="pending"');
        foreach ($failed as $f) {
            $evId = trim((string) ($f['event_id'] ?? ''));
            $err  = substr(trim((string) ($f['error'] ?? 'pull error')), 0, 250);
            if ($evId === '') continue;
            $st->bind_param('ss', $err, $evId);
            $st->execute();
        }
    }
    bot_reply(200, ['acknowledged' => $ackCount]);
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
$rows  = $db->query(
    "SELECT event_id, event_type, to_phone, audience, payload, created_at
       FROM wa_outbox WHERE status='pending' AND next_try_at <= NOW()
      ORDER BY id ASC LIMIT {$limit}"
)->fetch_all(MYSQLI_ASSOC);

$events = [];
foreach ($rows as $r) {
    $events[] = [
        'event_id'    => $r['event_id'],
        'event_type'  => preg_replace('/\.\d{8}$/', '', (string) $r['event_type']),
        'to'          => $r['to_phone'],
        'audience'    => $r['audience'],
        'occurred_at' => date('c', strtotime((string) $r['created_at'])),
        'data'        => json_decode((string) $r['payload'], true) ?: [],
    ];
}
bot_reply(200, ['events' => $events]);

