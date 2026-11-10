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

// Ambil kontak pengurus aktif (Ketua, Bendahara, Sekretaris)
$stStaff = $db->query(
    "SELECT u.name, u.phone, r.name AS role, r.label AS role_label
       FROM users u
       JOIN roles r ON r.id = u.role_id
      WHERE u.is_active = 1 AND r.name IN ('ketua', 'bendahara', 'sekretaris')
      ORDER BY FIELD(r.name, 'ketua', 'bendahara', 'sekretaris')"
);
$contacts = [];
if ($stStaff) {
    while ($row = $stStaff->fetch_assoc()) {
        $norm = phone_normalize($row['phone']);
        $contacts[] = [
            'role'       => $row['role_label'] ?: ucfirst($row['role']),
            'name'       => $row['name'],
            'phone'      => $row['phone'] ?: null,
            'wa_link'    => $norm ? 'https://wa.me/' . $norm : null,
        ];
    }
}

$emergency = [
    ['name' => 'Pos Security / Satpam',   'phone' => 'Pos Gerbang Utama', 'notes' => 'Siaga 24 Jam'],
    ['name' => 'Polsek Pamulang',          'phone' => '(021) 7401110',      'notes' => 'Kantor Polisi Terdekat'],
    ['name' => 'RSUD Tangsel (Pamulang)',  'phone' => '(021) 74718440',     'notes' => 'Rumah Sakit Umum'],
    ['name' => 'Damkar Tangsel / Call 112','phone' => '112 / (021) 7443113', 'notes' => 'Darurat Kebakaran & Evakuasi'],
];

bot_reply(200, [
    'events'             => $events,
    'contacts'           => $contacts,
    'emergency_contacts' => $emergency,
]);

