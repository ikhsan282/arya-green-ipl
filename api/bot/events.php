<?php
/**
 * GET /api/bot/events.php?limit=5
 * Daftar kegiatan / agenda warga yang akan datang (atau hari ini).
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET']);
$db    = $ctx['db'];
$limit = max(1, min(20, (int) ($_GET['limit'] ?? 5)));

$st = $db->prepare(
    'SELECT id, title, description, type, event_date, location, is_mandatory
       FROM events
      WHERE event_date >= CURDATE()
      ORDER BY event_date ASC, id ASC
      LIMIT ?'
);
$st->bind_param('i', $limit);
$st->execute();

$events = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
    $events[] = [
        'event_id'     => (int) $e['id'],
        'title'        => $e['title'],
        'description'  => $e['description'],
        'type'         => $e['type'],
        'event_date'   => $e['event_date'],
        'location'     => $e['location'],
        'is_mandatory' => (bool) $e['is_mandatory'],
    ];
}

bot_reply(200, ['events' => $events]);

