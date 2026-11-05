<?php
/**
 * GET /api/bot/me.php
 * Identitas pemilik nomor WhatsApp. Satu-satunya endpoint yang tetap menjawab untuk
 * nomor tidak terdaftar (registered=false), supaya Hermes bisa memberi arahan yang tepat.
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET'], false);
$actor = $ctx['actor'];

$residentName = $actor['residents'][0]['name'] ?? null;
bot_reply(200, [
    'registered' => $actor['registered'],
    'name'       => $residentName ?? ($actor['staff']['name'] ?? null),
    'is_staff'   => $actor['is_staff'],
    'staff_role' => $actor['staff']['role'] ?? null,
    'staff_role_label' => $actor['staff']['role_label'] ?? null,
    'units'      => array_map(static fn(array $r): array => [
        'unit_id' => $r['unit_id'],
        'unit'    => $r['unit'],
        'block'   => $r['block'],
        'resident_status' => $r['status'],
    ], $actor['residents']),
    'message'    => $actor['registered'] ? null
        : 'Nomor WhatsApp ini belum terdaftar sebagai warga ' . APP_NAME
          . '. Silakan hubungi Sekretaris/Pengurus untuk memperbarui nomor kontak.',
]);

