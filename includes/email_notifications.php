<?php
/**
 * Email Notifications & Reminders
 * Semua fungsi notifikasi email terpusat di sini.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

// ── Log helper ────────────────────────────────────────────────────────────────
function log_email(string $type, string $to_email, string $to_name, string $subject, int $ref_id, bool $ok): void {
    $db     = db();
    $status = $ok ? 'sent' : 'failed';
    $stmt   = $db->prepare(
        'INSERT INTO email_logs (type, to_email, to_name, subject, ref_id, status)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->bind_param('ssssis', $type, $to_email, $to_name, $subject, $ref_id, $status);
    $stmt->execute();
}

// Cek apakah email jenis ini sudah pernah dikirim untuk ref_id yang sama hari ini
function email_already_sent_today(string $type, int $ref_id): bool {
    $db   = db();
    $stmt = $db->prepare(
        'SELECT id FROM email_logs
         WHERE type=? AND ref_id=? AND DATE(created_at)=CURDATE() AND status="sent"
         LIMIT 1'
    );
    $stmt->bind_param('si', $type, $ref_id);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

// ── 1. Reminder: tagihan mendekati jatuh tempo (H-3) ─────────────────────────
function send_reminder_due(array $bill, string $email, string $name): bool {
    if (!$email) return false;
    if (email_already_sent_today('reminder_due', (int)$bill['id'])) return true;

    $subject = 'Pengingat Tagihan IPL — ' . $bill['period'] . ' | ' . APP_NAME;
    $due     = fmt_date($bill['due_date'], 'd M Y');
    $pay_url = APP_URL . '/pages/payments/form.php?bill_id=' . (int)$bill['id'];
    $body    = "
        <p>Halo <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p>Ini adalah pengingat bahwa tagihan IPL Anda untuk periode <strong>" . htmlspecialchars($bill['period']) . "</strong>
        akan jatuh tempo pada <strong>{$due}</strong>.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:4px 8px;color:#666'>Unit</td>
              <td style='padding:4px 8px'><strong>" . htmlspecialchars($bill['block'] . '-' . $bill['unit_number']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Periode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars($bill['period']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Jumlah</td>
              <td style='padding:4px 8px'><strong style='color:#198754'>" . idr((float)$bill['total_amount']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Jatuh Tempo</td>
              <td style='padding:4px 8px;color:#dc3545'><strong>{$due}</strong></td></tr>
        </table>
        <p style='margin-top:16px'>Harap segera lakukan pembayaran sebelum jatuh tempo untuk menghindari denda keterlambatan.</p>
        <p>Terima kasih atas perhatian dan kerja samanya.</p>";

    $ok = send_mail($email, $subject, mail_template('Pengingat Tagihan IPL', $body, 'Bayar Sekarang', $pay_url));
    log_email('reminder_due', $email, $name, $subject, (int)$bill['id'], $ok);
    return $ok;
}

// ── 2. Reminder: tagihan sudah terlambat ─────────────────────────────────────
function send_reminder_overdue(array $bill, string $email, string $name): bool {
    if (!$email) return false;
    if (email_already_sent_today('reminder_overdue', (int)$bill['id'])) return true;

    $subject = 'Tagihan IPL TERLAMBAT — ' . $bill['period'] . ' | ' . APP_NAME;
    $due     = fmt_date($bill['due_date'], 'd M Y');
    $pay_url = APP_URL . '/pages/payments/form.php?bill_id=' . (int)$bill['id'];
    $body    = "
        <p>Halo <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p style='color:#dc3545;font-weight:bold'>⚠ Tagihan IPL Anda untuk periode <strong>" . htmlspecialchars($bill['period']) . "</strong>
        telah melewati jatuh tempo dan berstatus <strong>TERLAMBAT</strong>.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:4px 8px;color:#666'>Unit</td>
              <td style='padding:4px 8px'><strong>" . htmlspecialchars($bill['block'] . '-' . $bill['unit_number']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Periode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars($bill['period']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Tagihan Pokok</td>
              <td style='padding:4px 8px'>" . idr((float)$bill['amount']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Denda</td>
              <td style='padding:4px 8px;color:#dc3545'>" . idr((float)$bill['fine_amount']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Total Tagihan</td>
              <td style='padding:4px 8px'><strong style='color:#dc3545'>" . idr((float)$bill['total_amount']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Jatuh Tempo</td>
              <td style='padding:4px 8px;color:#dc3545'><strong>{$due}</strong></td></tr>
        </table>
        <p style='margin-top:16px'>Mohon segera melunasi tagihan untuk menghindari penambahan denda lebih lanjut.</p>
        <p>Jika Anda sudah melakukan pembayaran, abaikan email ini.</p>";

    $ok = send_mail($email, $subject, mail_template('Tagihan Terlambat', $body, 'Bayar Sekarang', $pay_url));
    log_email('reminder_overdue', $email, $name, $subject, (int)$bill['id'], $ok);
    return $ok;
}

// ── 3. Notifikasi ke warga: pembayaran diverifikasi ───────────────────────────
function notify_payment_verified(array $payment, string $email, string $name): bool {
    if (!$email) return false;

    $subject = 'Pembayaran IPL Terverifikasi — ' . $payment['period'] . ' | ' . APP_NAME;
    $receipt_url = APP_URL . '/pages/payments/print_receipt.php?id=' . (int)$payment['id'];
    $body    = "
        <p>Halo <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p style='color:#198754'>✅ Pembayaran IPL Anda telah <strong>diverifikasi</strong> oleh pengurus.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:4px 8px;color:#666'>Unit</td>
              <td style='padding:4px 8px'><strong>" . htmlspecialchars($payment['block'] . '-' . $payment['unit_number']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Periode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars($payment['period']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Tanggal Bayar</td>
              <td style='padding:4px 8px'>" . fmt_date($payment['payment_date']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Jumlah</td>
              <td style='padding:4px 8px'><strong style='color:#198754'>" . idr((float)$payment['amount_paid']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Metode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars(ucfirst($payment['payment_method'])) . ($payment['bank_name'] ? ' — ' . htmlspecialchars($payment['bank_name']) : '') . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Diverifikasi</td>
              <td style='padding:4px 8px'>" . fmt_date($payment['verified_at'], 'd M Y H:i') . "</td></tr>
        </table>
        <p style='margin-top:16px'>Simpan email ini sebagai bukti pembayaran Anda. Terima kasih.</p>";

    $ok = send_mail($email, $subject, mail_template('Pembayaran Terverifikasi', $body, 'Lihat / Cetak Kuitansi', $receipt_url));
    log_email('payment_verified', $email, $name, $subject, (int)$payment['id'], $ok);
    return $ok;
}

// ── 4. Notifikasi ke warga: pembayaran ditolak ────────────────────────────────
function notify_payment_rejected(array $payment, string $email, string $name, string $reason = ''): bool {
    if (!$email) return false;

    $subject = 'Pembayaran IPL Ditolak — ' . $payment['period'] . ' | ' . APP_NAME;
    $pay_url = APP_URL . '/pages/payments/form.php?bill_id=' . (int)($payment['bill_id'] ?? 0);
    $reason_html = $reason ? "<p><strong>Alasan:</strong> " . htmlspecialchars($reason) . "</p>" : '';
    $body    = "
        <p>Halo <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p style='color:#dc3545'>❌ Pembayaran IPL Anda untuk periode <strong>" . htmlspecialchars($payment['period']) . "</strong>
        <strong>ditolak</strong> oleh pengurus.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:4px 8px;color:#666'>Unit</td>
              <td style='padding:4px 8px'><strong>" . htmlspecialchars($payment['block'] . '-' . $payment['unit_number']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Periode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars($payment['period']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Jumlah</td>
              <td style='padding:4px 8px'>" . idr((float)$payment['amount_paid']) . "</td></tr>
        </table>
        {$reason_html}
        <p style='margin-top:16px'>Harap hubungi pengurus atau ulangi pembayaran dengan bukti yang valid.</p>";

    $ok = send_mail($email, $subject, mail_template('Pembayaran Ditolak', $body, 'Unggah Ulang Pembayaran', $pay_url));
    log_email('payment_rejected', $email, $name, $subject, (int)$payment['id'], $ok);
    return $ok;
}

// ── 5. Notifikasi ke ketua: ada pembayaran baru masuk ─────────────────────────
function notify_admin_new_payment(array $payment, string $resident_name): void {
    $db   = db();
    // Kirim ke semua admin & super_admin yang punya email
    $res  = $db->query(
        'SELECT u.email, u.name FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE r.name IN ("super_admin","ketua") AND u.is_active=1 AND u.email IS NOT NULL'
    );
    if (!$res) return;

    $subject = 'Pembayaran Baru Menunggu Verifikasi — ' . APP_NAME;
    $verify_url = APP_URL . '/pages/payments/index.php?status=pending';
    $body    = "
        <p>Ada pembayaran IPL baru yang perlu diverifikasi.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:4px 8px;color:#666'>Unit</td>
              <td style='padding:4px 8px'><strong>" . htmlspecialchars($payment['block'] . '-' . $payment['unit_number']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Warga</td>
              <td style='padding:4px 8px'>" . htmlspecialchars($resident_name ?: '-') . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Periode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars($payment['period']) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Jumlah</td>
              <td style='padding:4px 8px'><strong>" . idr((float)$payment['amount_paid']) . "</strong></td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Metode</td>
              <td style='padding:4px 8px'>" . htmlspecialchars(ucfirst($payment['payment_method'])) . "</td></tr>
          <tr><td style='padding:4px 8px;color:#666'>Tanggal Bayar</td>
              <td style='padding:4px 8px'>" . fmt_date($payment['payment_date']) . "</td></tr>
        </table>";

    while ($ketua = $res->fetch_assoc()) {
        $ok = send_mail($ketua['email'], $subject, mail_template('Pembayaran Baru', $body, 'Lihat & Verifikasi', $verify_url));
        log_email('payment_received', $ketua['email'], $ketua['name'], $subject, (int)$payment['id'], $ok);
    }
}

// ── 6. Batch: kirim reminder untuk semua tagihan ─────────────────────────────
/**
 * Dipanggil dari halaman billing saat generate/load, atau dari halaman reminder manual.
 * Returns: ['due' => int, 'overdue' => int, 'skipped' => int]
 */
function run_email_reminders(): array {
    $db      = db();
    $today   = date('Y-m-d');
    $h3      = date('Y-m-d', strtotime('+3 days'));
    $counts  = ['due' => 0, 'overdue' => 0, 'skipped' => 0];

    // Tagihan mendekati jatuh tempo (due_date antara hari ini s/d H+3, status belum_bayar)
    $stmt = $db->prepare(
        'SELECT b.*, bp.label AS period, u.unit_number, u.block,
                r.name AS resident_name, r.email AS resident_email
         FROM bills b
         JOIN billing_periods bp ON bp.id = b.billing_period_id
         JOIN units u ON u.id = b.unit_id
         LEFT JOIN residents r ON r.id = b.resident_id
         WHERE b.status = "belum_bayar"
           AND b.due_date BETWEEN ? AND ?'
    );
    $stmt->bind_param('ss', $today, $h3);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $bill) {
        if (!$bill['resident_email']) { $counts['skipped']++; continue; }
        $ok = send_reminder_due($bill, $bill['resident_email'], $bill['resident_name'] ?? 'Warga');
        if ($ok) $counts['due']++; else $counts['skipped']++;
    }

    // Tagihan terlambat
    $stmt2 = $db->prepare(
        'SELECT b.*, bp.label AS period, u.unit_number, u.block,
                r.name AS resident_name, r.email AS resident_email
         FROM bills b
         JOIN billing_periods bp ON bp.id = b.billing_period_id
         JOIN units u ON u.id = b.unit_id
         LEFT JOIN residents r ON r.id = b.resident_id
         WHERE b.status = "terlambat"'
    );
    $stmt2->execute();
    $rows2 = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows2 as $bill) {
        if (!$bill['resident_email']) { $counts['skipped']++; continue; }
        $ok = send_reminder_overdue($bill, $bill['resident_email'], $bill['resident_name'] ?? 'Warga');
        if ($ok) $counts['overdue']++; else $counts['skipped']++;
    }

    return $counts;
}
