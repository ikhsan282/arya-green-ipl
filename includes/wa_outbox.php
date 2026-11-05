<?php
/**
 * Transactional Outbox untuk notifikasi WhatsApp via Hermes.
 *
 * Setiap event ditulis dulu ke tabel `wa_outbox` dengan kunci deduplikasi
 * (event_type, to_phone, ref_type, ref_id) supaya pemanggilan ulang tidak
 * pernah menghasilkan notifikasi ganda. Pengiriman ke Hermes dilakukan
 * terpisah oleh cron/wa_dispatch.php (push) atau api/bot/outbox.php (pull).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bot_auth.php';
require_once __DIR__ . '/receipt_link.php';

function wa_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Masukkan 1 event ke wa_outbox (idempoten via INSERT IGNORE).
 * @return int|null ID baris baru, atau null bila duplikat / nomor tidak valid.
 */
function wa_enqueue(string $eventType, ?string $toPhone, string $audience, ?string $refType, ?int $refId, array $data): ?int
{
    $norm = phone_normalize($toPhone);
    if ($norm === null) return null;
    if (!in_array($audience, ['resident', 'staff'], true)) $audience = 'resident';

    $eventId = wa_uuid4();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db = db();
    $st = $db->prepare(
        'INSERT IGNORE INTO wa_outbox (event_id, event_type, to_phone, audience, ref_type, ref_id, payload)
         VALUES (?,?,?,?,?,?,?)'
    );
    $rt = $refType ?? '';
    $ri = $refId ?? 0;
    $st->bind_param('sssssis', $eventId, $eventType, $norm, $audience, $rt, $ri, $json);
    $st->execute();
    return $st->affected_rows > 0 ? (int) $db->insert_id : null;
}

/** Hitung saldo kas total saat ini. */
function wa_current_cash_balance(mysqli $db): float
{
    $row = $db->query(
        'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0)
              - COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS saldo
           FROM cash_book'
    )->fetch_assoc();
    return (float) ($row['saldo'] ?? 0);
}

/** Daftar nomor pengurus aktif yang mengaktifkan wa_notify. */
function wa_staff_recipients(mysqli $db, array $roles = ['ketua', 'bendahara', 'sekretaris']): array
{
    if (!$roles) return [];
    $in = implode(',', array_fill(0, count($roles), '?'));
    $st = $db->prepare(
        "SELECT u.id, u.name, u.phone, r.name AS role
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.is_active = 1 AND u.wa_notify = 1 AND u.phone IS NOT NULL AND u.phone <> ''
            AND r.name IN ($in)"
    );
    $st->bind_param(str_repeat('s', count($roles)), ...$roles);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Antre notifikasi pembayaran terverifikasi (untuk warga penyetor + pengurus).
 * Aman dipanggil berkali-kali untuk payment_id yang sama.
 */
function wa_event_payment_verified(int $paymentId): int
{
    try {
        $db = db();
        $st = $db->prepare(
            'SELECT p.id, p.bill_id, p.amount_paid, p.payment_date, p.payment_method, p.reference_no, p.verified_at,
                    bp.label AS period, u.unit_number, u.block,
                    r.name AS resident_name, r.phone AS resident_phone,
                    pm.name AS method_name
               FROM payments p
               JOIN bills b ON b.id = p.bill_id
               JOIN billing_periods bp ON bp.id = b.billing_period_id
               JOIN units u ON u.id = b.unit_id
               LEFT JOIN residents r ON r.id = b.resident_id
               LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
              WHERE p.id = ? AND p.status = "verified" LIMIT 1'
        );
        $st->bind_param('i', $paymentId);
        $st->execute();
        $pay = $st->get_result()->fetch_assoc();
        if (!$pay) return 0;

        $saldo      = wa_current_cash_balance($db);
        $receiptUrl = receipt_sign_url($paymentId);
        $method     = $pay['method_name'] ?: $pay['payment_method'];
        $amt        = (float) $pay['amount_paid'];

        $common = [
            'payment_id'    => $paymentId,
            'bill_id'       => (int) $pay['bill_id'],
            'resident_name' => $pay['resident_name'] ?? 'Warga',
            'unit'          => $pay['unit_number'],
            'block'         => $pay['block'],
            'period'        => $pay['period'],
            'amount'        => (int) round($amt),
            'amount_text'   => idr($amt),
            'method'        => $method,
            'reference_no'  => $pay['reference_no'],
            'paid_at'       => $pay['verified_at'] ?: $pay['payment_date'],
            'receipt_url'   => $receiptUrl,
        ];

        $queued = 0;
        if (wa_enqueue('payment.verified', $pay['resident_phone'] ?? null, 'resident', 'payment', $paymentId, $common) !== null) {
            $queued++;
        }

        $staffPayload = $common + [
            'cash_balance'      => (int) round($saldo),
            'cash_balance_text' => idr($saldo),
        ];
        foreach (wa_staff_recipients($db, ['ketua', 'bendahara', 'sekretaris']) as $staff) {
            if (wa_enqueue('payment.verified.staff', $staff['phone'], 'staff', 'payment', $paymentId,
                           $staffPayload + ['staff_name' => $staff['name'], 'staff_role' => $staff['role']]) !== null) {
                $queued++;
            }
        }
        return $queued;
    } catch (Throwable $e) {
        error_log('wa_event_payment_verified: ' . $e->getMessage());
        return 0;
    }
}

/** Antre notifikasi ke Bendahara/Ketua saat ada transfer manual menunggu verifikasi. */
function wa_event_payment_pending_review(int $paymentId): int
{
    try {
        $db = db();
        $st = $db->prepare(
            'SELECT p.id, p.bill_id, p.amount_paid, p.payment_date, p.payment_method,
                    bp.label AS period, u.unit_number, u.block, r.name AS resident_name,
                    pm.name AS method_name
               FROM payments p
               JOIN bills b ON b.id = p.bill_id
               JOIN billing_periods bp ON bp.id = b.billing_period_id
               JOIN units u ON u.id = b.unit_id
               LEFT JOIN residents r ON r.id = b.resident_id
               LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
              WHERE p.id = ? AND p.status = "pending" LIMIT 1'
        );
        $st->bind_param('i', $paymentId);
        $st->execute();
        $pay = $st->get_result()->fetch_assoc();
        if (!$pay) return 0;

        $amt = (float) $pay['amount_paid'];
        $payload = [
            'payment_id'    => $paymentId,
            'bill_id'       => (int) $pay['bill_id'],
            'resident_name' => $pay['resident_name'] ?? 'Warga',
            'unit'          => $pay['unit_number'],
            'block'         => $pay['block'],
            'period'        => $pay['period'],
            'amount'        => (int) round($amt),
            'amount_text'   => idr($amt),
            'method'        => $pay['method_name'] ?: $pay['payment_method'],
            'verify_url'    => APP_URL . '/pages/payments/verify.php?id=' . $paymentId,
        ];
        $queued = 0;
        foreach (wa_staff_recipients($db, ['bendahara', 'ketua']) as $staff) {
            if (wa_enqueue('payment.pending_review', $staff['phone'], 'staff', 'payment', $paymentId, $payload) !== null) {
                $queued++;
            }
        }
        return $queued;
    } catch (Throwable $e) {
        error_log('wa_event_payment_pending_review: ' . $e->getMessage());
        return 0;
    }
}

/** Antre notifikasi ke warga saat pembayaran manual ditolak. */
function wa_event_payment_rejected(int $paymentId, string $reason = ''): int
{
    try {
        $db = db();
        $st = $db->prepare(
            'SELECT p.id, p.bill_id, p.amount_paid, bp.label AS period, u.unit_number,
                    r.name AS resident_name, r.phone AS resident_phone
               FROM payments p
               JOIN bills b ON b.id = p.bill_id
               JOIN billing_periods bp ON bp.id = b.billing_period_id
               JOIN units u ON u.id = b.unit_id
               LEFT JOIN residents r ON r.id = b.resident_id
              WHERE p.id = ? LIMIT 1'
        );
        $st->bind_param('i', $paymentId);
        $st->execute();
        $pay = $st->get_result()->fetch_assoc();
        if (!$pay) return 0;

        $amt = (float) $pay['amount_paid'];
        $id = wa_enqueue('payment.rejected', $pay['resident_phone'] ?? null, 'resident', 'payment', $paymentId, [
            'payment_id'    => $paymentId,
            'bill_id'       => (int) $pay['bill_id'],
            'resident_name' => $pay['resident_name'] ?? 'Warga',
            'unit'          => $pay['unit_number'],
            'period'        => $pay['period'],
            'amount'        => (int) round($amt),
            'amount_text'   => idr($amt),
            'reason'        => $reason,
        ]);
        return $id !== null ? 1 : 0;
    } catch (Throwable $e) {
        error_log('wa_event_payment_rejected: ' . $e->getMessage());
        return 0;
    }
}

/** Antre notifikasi saat order QRIS yang dibuat via WhatsApp kedaluwarsa. */
function wa_event_qris_expired(string $orderId): int
{
    try {
        $db = db();
        $st = $db->prepare(
            'SELECT o.id, o.order_id, o.bill_id, o.total_amount, o.source,
                    bp.label AS period, u.unit_number, r.name AS resident_name, r.phone AS resident_phone
               FROM payment_gateway_orders o
               JOIN bills b ON b.id = o.bill_id
               JOIN billing_periods bp ON bp.id = b.billing_period_id
               JOIN units u ON u.id = b.unit_id
               LEFT JOIN residents r ON r.id = b.resident_id
              WHERE o.order_id = ? LIMIT 1'
        );
        $st->bind_param('s', $orderId);
        $st->execute();
        $ord = $st->get_result()->fetch_assoc();
        if (!$ord || ($ord['source'] ?? 'web') !== 'whatsapp') return 0;

        $amt = (float) $ord['total_amount'];
        $id = wa_enqueue('qris.expired', $ord['resident_phone'] ?? null, 'resident', 'qris_order', (int) $ord['id'], [
            'order_id'      => $ord['order_id'],
            'bill_id'       => (int) $ord['bill_id'],
            'resident_name' => $ord['resident_name'] ?? 'Warga',
            'unit'          => $ord['unit_number'],
            'period'        => $ord['period'],
            'total'         => (int) round($amt),
            'total_text'    => idr($amt),
        ]);
        return $id !== null ? 1 : 0;
    } catch (Throwable $e) {
        error_log('wa_event_qris_expired: ' . $e->getMessage());
        return 0;
    }
}

/** Antre notifikasi ke warga saat aduannya dibalas atau diperbarui pengurus. */
function wa_event_complaint_replied(int $complaintId, int $refReplyId, string $message, string $authorName, string $status): int
{
    try {
        $db = db();
        $st = $db->prepare(
            'SELECT c.id, c.title, c.category, r.name AS resident_name, r.phone AS resident_phone
               FROM complaints c
               LEFT JOIN residents r ON r.id = c.resident_id
              WHERE c.id = ? LIMIT 1'
        );
        $st->bind_param('i', $complaintId);
        $st->execute();
        $c = $st->get_result()->fetch_assoc();
        if (!$c) return 0;

        $id = wa_enqueue('complaint.replied', $c['resident_phone'] ?? null, 'resident', 'complaint_reply', $refReplyId, [
            'complaint_id'  => $complaintId,
            'title'         => $c['title'],
            'category'      => $c['category'],
            'status'        => $status,
            'author'        => $authorName,
            'message'       => $message,
            'resident_name' => $c['resident_name'] ?? 'Warga',
        ]);
        return $id !== null ? 1 : 0;
    } catch (Throwable $e) {
        error_log('wa_event_complaint_replied: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Antre pengingat tagihan jatuh tempo & terlambat ke wa_outbox.
 * Tanggal dimasukkan ke event_type (mis. bill.reminder.due.20261105) supaya
 * uq_wa_dedup otomatis mencegah pengiriman ulang di hari yang sama.
 */
function wa_run_reminders(?string $today = null): array
{
    $db     = db();
    $today  = $today ?: date('Y-m-d');
    $ymd    = str_replace('-', '', $today);
    $h3     = date('Y-m-d', strtotime($today . ' +3 days'));
    $counts = ['due' => 0, 'overdue' => 0, 'skipped' => 0];

    // 1. Mendekati jatuh tempo (H-3 s/d H-0)
    $st = $db->prepare(
        'SELECT b.id, b.total_amount, b.due_date, bp.label AS period, u.unit_number,
                r.name AS resident_name, r.phone AS resident_phone
           FROM bills b
           JOIN billing_periods bp ON bp.id = b.billing_period_id
           JOIN units u ON u.id = b.unit_id
           LEFT JOIN residents r ON r.id = b.resident_id
          WHERE b.status = "belum_bayar" AND b.due_date BETWEEN ? AND ?'
    );
    $st->bind_param('ss', $today, $h3);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
        $amt = (float) $b['total_amount'];
        $ev  = ($b['due_date'] === $today ? 'bill.reminder.d0.' : 'bill.reminder.due.') . $ymd;
        $id  = wa_enqueue($ev, $b['resident_phone'] ?? null, 'resident', 'bill', (int) $b['id'], [
            'bill_id'       => (int) $b['id'],
            'resident_name' => $b['resident_name'] ?? 'Warga',
            'unit'          => $b['unit_number'],
            'period'        => $b['period'],
            'total'         => (int) round($amt),
            'total_text'    => idr($amt),
            'due_date'      => $b['due_date'],
        ]);
        if ($id !== null) $counts['due']++; else $counts['skipped']++;
    }

    // 2. Terlambat
    $st2 = $db->query(
        'SELECT b.id, b.total_amount, b.fine_amount, b.due_date, bp.label AS period, u.unit_number,
                r.name AS resident_name, r.phone AS resident_phone
           FROM bills b
           JOIN billing_periods bp ON bp.id = b.billing_period_id
           JOIN units u ON u.id = b.unit_id
           LEFT JOIN residents r ON r.id = b.resident_id
          WHERE b.status = "terlambat"'
    );
    foreach ($st2->fetch_all(MYSQLI_ASSOC) as $b) {
        $amt = (float) $b['total_amount'];
        $id  = wa_enqueue('bill.overdue.' . $ymd, $b['resident_phone'] ?? null, 'resident', 'bill', (int) $b['id'], [
            'bill_id'       => (int) $b['id'],
            'resident_name' => $b['resident_name'] ?? 'Warga',
            'unit'          => $b['unit_number'],
            'period'        => $b['period'],
            'total'         => (int) round($amt),
            'total_text'    => idr($amt),
            'fine'          => (int) round((float) $b['fine_amount']),
            'fine_text'     => idr((float) $b['fine_amount']),
            'due_date'      => $b['due_date'],
        ]);
        if ($id !== null) $counts['overdue']++; else $counts['skipped']++;
    }
    return $counts;
}

function wa_webhook_signature(string $secret, string $ts, string $body): string
{
    return hash_hmac('sha256', $ts . "\n" . $body, $secret);
}

/**
 * Kirim batch outbox yang berstatus pending ke BOT_WEBHOOK_URL.
 * @param callable|null $httpPost fn(string $url, array $headers, string $body): array{ok:bool,status:int,error:?string}
 */
function wa_dispatch_batch(int $limit = 50, ?callable $httpPost = null): array
{
    $stats = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
    if (!defined('BOT_WEBHOOK_URL') || BOT_WEBHOOK_URL === '' || !defined('BOT_WEBHOOK_SECRET') || strlen(BOT_WEBHOOK_SECRET) < 16) {
        return $stats;
    }

    $httpPost ??= static function (string $url, array $headers, string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'status' => 0, 'error' => $err ?: 'cURL gagal'];
        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'error' => $code >= 200 && $code < 300 ? null : 'HTTP ' . $code];
    };

    $db = db();
    $limit = max(1, min(100, $limit));
    $rows = $db->query(
        "SELECT * FROM wa_outbox WHERE status='pending' AND next_try_at <= NOW() ORDER BY id ASC LIMIT {$limit}"
    )->fetch_all(MYSQLI_ASSOC);

    $backoffs = [1 => 60, 2 => 300, 3 => 900, 4 => 3600]; // detik
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        // Normalisasi nama event harian (bill.reminder.due.20261105 -> bill.reminder.due)
        $cleanEvent = preg_replace('/\.\d{8}$/', '', (string) $row['event_type']);
        $body = json_encode([
            'event_id'    => $row['event_id'],
            'event_type'  => $cleanEvent,
            'to'          => $row['to_phone'],
            'audience'    => $row['audience'],
            'occurred_at' => date('c', strtotime((string) $row['created_at'])),
            'data'        => json_decode((string) $row['payload'], true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ts  = (string) time();
        $sig = wa_webhook_signature(BOT_WEBHOOK_SECRET, $ts, $body);
        $res = $httpPost(BOT_WEBHOOK_URL, [
            'Content-Type: application/json; charset=utf-8',
            'X-IPL-Timestamp: ' . $ts,
            'X-IPL-Signature: ' . $sig,
        ], $body);

        if ($res['ok']) {
            $up = $db->prepare('UPDATE wa_outbox SET status="sent", sent_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=?');
            $up->bind_param('i', $id);
            $up->execute();
            $stats['sent']++;
        } else {
            $attempts = (int) $row['attempts'] + 1;
            $errMsg   = substr((string) ($res['error'] ?? 'Unknown error'), 0, 250);
            if ($attempts >= 5) {
                $up = $db->prepare('UPDATE wa_outbox SET status="failed", attempts=?, last_error=? WHERE id=?');
                $up->bind_param('isi', $attempts, $errMsg, $id);
                $up->execute();
                $stats['failed']++;
            } else {
                $delay = $backoffs[$attempts] ?? 3600;
                $next  = date('Y-m-d H:i:s', time() + $delay);
                $up = $db->prepare('UPDATE wa_outbox SET attempts=?, next_try_at=?, last_error=? WHERE id=?');
                $up->bind_param('issi', $attempts, $next, $errMsg, $id);
                $up->execute();
                $stats['retried']++;
            }
        }
    }
    return $stats;
}

