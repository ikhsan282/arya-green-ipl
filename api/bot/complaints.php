<?php
/**
 * GET  /api/bot/complaints.php       — daftar pengaduan milik warga pengirim
 * POST /api/bot/complaints.php       — buat tiket pengaduan baru
 *      Body JSON: {"title":"...","description":"...","category":"umum","priority":"sedang"}
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET', 'POST']);
$db    = $ctx['db'];
$actor = $ctx['actor'];

$residentIds = array_values(array_unique(array_column($actor['residents'], 'resident_id')));
if (!$residentIds) {
    bot_error(403, 'NOT_RESIDENT', 'Hanya warga terdaftar yang dapat mengakses layanan pengaduan.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $title = clean((string) ($ctx['body']['title'] ?? ''));
    $desc  = clean((string) ($ctx['body']['description'] ?? ''));
    $cat   = clean((string) ($ctx['body']['category'] ?? 'umum')) ?: 'umum';
    $prio  = (string) ($ctx['body']['priority'] ?? 'sedang');
    if (!in_array($prio, ['rendah', 'sedang', 'tinggi'], true)) $prio = 'sedang';

    if ($title === '' || $desc === '') {
        bot_error(400, 'VALIDATION_ERROR', 'Judul dan deskripsi pengaduan wajib diisi.');
    }
    if (mb_strlen($title) > 150) $title = mb_substr($title, 0, 150);
    if (mb_strlen($desc) > 2000) $desc  = mb_substr($desc, 0, 2000);

    $resId = $residentIds[0];
    $stU   = $db->prepare('SELECT user_id FROM residents WHERE id=? LIMIT 1');
    $stU->bind_param('i', $resId);
    $stU->execute();
    $uid = $stU->get_result()->fetch_row()[0] ?? null;
    $uid = $uid !== null ? (int) $uid : null;

    $st = $db->prepare(
        'INSERT INTO complaints (resident_id, user_id, title, description, category, priority)
         VALUES (?,?,?,?,?,?)'
    );
    $st->bind_param('iissss', $resId, $uid, $title, $desc, $cat, $prio);
    $st->execute();
    $cid = (int) $db->insert_id;

    bot_reply(201, [
        'complaint_id' => $cid,
        'ticket_code'  => sprintf('PG-%04d', $cid),
        'title'        => $title,
        'category'     => $cat,
        'priority'     => $prio,
        'status'       => 'baru',
    ]);
}

$in = implode(',', array_fill(0, count($residentIds), '?'));
$st = $db->prepare(
    "SELECT c.id, c.title, c.category, c.priority, c.status, c.created_at, c.resolved_at,
            (SELECT cr.message FROM complaint_replies cr WHERE cr.complaint_id=c.id ORDER BY cr.id DESC LIMIT 1) AS last_reply
       FROM complaints c
      WHERE c.resident_id IN ({$in})
      ORDER BY c.id DESC LIMIT 10"
);
$st->bind_param(str_repeat('i', count($residentIds)), ...$residentIds);
$st->execute();
$items = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $cid = (int) $r['id'];
    $items[] = [
        'complaint_id' => $cid,
        'ticket_code'  => sprintf('PG-%04d', $cid),
        'title'        => $r['title'],
        'category'     => $r['category'],
        'priority'     => $r['priority'],
        'status'       => $r['status'],
        'last_reply'   => $r['last_reply'],
        'created_at'   => $r['created_at'],
        'resolved_at'  => $r['resolved_at'],
    ];
}
bot_reply(200, ['complaints' => $items]);

