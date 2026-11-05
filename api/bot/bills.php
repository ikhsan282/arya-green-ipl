<?php
/**
 * GET /api/bot/bills.php?unit_id=<opsional>&history=<1-12, default 6>
 *
 * - active : tagihan belum lunas untuk unit milik pengirim (tagihan melekat pada unit).
 * - history: tagihan yang tercatat atas nama pengirim sendiri (resident_id), supaya
 *            penghuni baru tidak melihat riwayat penghuni sebelumnya.
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET']);
$db    = $ctx['db'];
$actor = $ctx['actor'];

$unitIds = bot_actor_unit_ids($actor);
if (!$unitIds) {
    bot_error(403, 'NOT_RESIDENT', 'Nomor ini terdaftar sebagai pengurus, bukan sebagai warga pemilik/penghuni unit.');
}
if (isset($_GET['unit_id']) && $_GET['unit_id'] !== '') {
    $want = (int) $_GET['unit_id'];
    if (!in_array($want, $unitIds, true)) {
        bot_error(403, 'FORBIDDEN_UNIT', 'Unit tersebut tidak terdaftar atas nomor WhatsApp Anda.');
    }
    $unitIds = [$want];
}
$historyN    = max(1, min(12, (int) ($_GET['history'] ?? 6)));
$residentIds = array_values(array_unique(array_column($actor['residents'], 'resident_id')));

$in  = static fn(array $ids): string => implode(',', array_fill(0, count($ids), '?'));
$bind = static function (mysqli_stmt $st, array $ints): void {
    if ($ints) $st->bind_param(str_repeat('i', count($ints)), ...$ints);
};

$unitLabel = static fn(array $b): string => $b['unit_number'];

// ── Tagihan aktif ────────────────────────────────────────────────────────────
$st = $db->prepare(
    "SELECT b.id, b.unit_id, b.amount, b.fine_amount, b.total_amount, b.status, b.due_date,
            bp.label AS period, bp.period_year, bp.period_month, u.unit_number,
            (SELECT COUNT(*) FROM payments p WHERE p.bill_id=b.id AND p.status='pending') AS pending_review
       FROM bills b
       JOIN billing_periods bp ON bp.id = b.billing_period_id
       JOIN units u ON u.id = b.unit_id
      WHERE b.unit_id IN ({$in($unitIds)}) AND b.status IN ('belum_bayar','terlambat')
      ORDER BY bp.period_year, bp.period_month, u.unit_number"
);
$bind($st, $unitIds);
$st->execute();
$activeRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$components = [];
$orders     = [];
if ($activeRows) {
    $billIds = array_map('intval', array_column($activeRows, 'id'));

    $st = $db->prepare("SELECT bill_id, component_name, amount FROM bill_components
                         WHERE bill_id IN ({$in($billIds)}) ORDER BY id");
    $bind($st, $billIds);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $components[(int) $c['bill_id']][] = ['name' => $c['component_name'], 'amount' => bot_money((float) $c['amount'])];
    }

    // Order QRIS yang masih bisa dibayar — Hermes harus menawarkan QR yang sama,
    // bukan membuat baru (kode unik berbeda = risiko warga membayar dua kali).
    $st = $db->prepare("SELECT bill_id, order_id, total_amount, expired_at FROM payment_gateway_orders
                         WHERE bill_id IN ({$in($billIds)}) AND status='PENDING'
                           AND (expired_at IS NULL OR expired_at > NOW())
                         ORDER BY id DESC");
    $bind($st, $billIds);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $orders[(int) $o['bill_id']] ??= [
            'order_id'   => $o['order_id'],
            'total'      => bot_money((float) $o['total_amount']),
            'expires_at' => $o['expired_at'],
        ];
    }
}

$today  = date('Y-m-d');
$active = [];
$sum    = 0.0;
foreach ($activeRows as $b) {
    $id = (int) $b['id'];
    $overdue = $b['status'] === 'terlambat' || $b['due_date'] < $today;
    $sum += (float) $b['total_amount'];
    $active[] = [
        'bill_id'        => $id,
        'unit_id'        => (int) $b['unit_id'],
        'unit'           => $unitLabel($b),
        'period'         => $b['period'],
        'amount'         => bot_money((float) $b['amount']),
        'fine'           => bot_money((float) $b['fine_amount']),
        'total'          => bot_money((float) $b['total_amount']),
        'due_date'       => $b['due_date'],
        'status'         => $overdue ? 'terlambat' : 'belum_bayar',
        'components'     => $components[$id] ?? [],
        'pending_review' => (int) $b['pending_review'] > 0, // ada transfer manual menunggu verifikasi bendahara
        'pending_qris'   => $orders[$id] ?? null,
    ];
}

// ── Riwayat ──────────────────────────────────────────────────────────────────
$history = [];
if ($residentIds) {
    $params = array_merge($unitIds, $residentIds, [$historyN]);
    $st = $db->prepare(
        "SELECT b.id, b.total_amount, b.status, b.due_date, b.paid_date, bp.label AS period, u.unit_number,
                (SELECT p.payment_method FROM payments p
                  WHERE p.bill_id=b.id AND p.status='verified' ORDER BY p.id DESC LIMIT 1) AS method
           FROM bills b
           JOIN billing_periods bp ON bp.id = b.billing_period_id
           JOIN units u ON u.id = b.unit_id
          WHERE b.unit_id IN ({$in($unitIds)}) AND b.resident_id IN ({$in($residentIds)})
          ORDER BY bp.period_year DESC, bp.period_month DESC, u.unit_number
          LIMIT ?"
    );
    $bind($st, $params);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $h) {
        $history[] = [
            'bill_id'   => (int) $h['id'],
            'unit'      => $unitLabel($h),
            'period'    => $h['period'],
            'total'     => bot_money((float) $h['total_amount']),
            'status'    => $h['status'] === 'sudah_bayar' ? 'lunas'
                           : ($h['status'] === 'terlambat' || $h['due_date'] < $today ? 'terlambat' : 'belum_bayar'),
            'paid_date' => $h['paid_date'],
            'method'    => $h['method'],
        ];
    }
}

bot_reply(200, [
    'active'        => $active,
    'active_count'  => count($active),
    'active_total'  => bot_money($sum),
    'history'       => $history,
]);

