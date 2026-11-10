<?php
/**
 * GET /api/bot/staff_summary.php
 * Khusus pengurus aktif (Super Admin, Ketua, Bendahara, Sekretaris).
 * Menampilkan ringkasan penerimaan hari ini & kemarin, progres periode terbaru, saldo kas,
 * jumlah pembayaran manual yang menunggu verifikasi, aduan aktif, serta daftar penerima
 * Morning Briefing siap kirim untuk Bpk/Ibu Pengurus.
 */
require __DIR__ . '/_bootstrap.php';

$ctx   = bot_boot(['GET']);
$db    = $ctx['db'];
$actor = $ctx['actor'];

if (!$actor['is_staff']) {
    bot_error(403, 'STAFF_ONLY', 'Informasi rekapitulasi ini hanya dapat diakses oleh Pengurus.');
}

// Penerimaan hari ini (verified)
$todayRow = $db->query(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_paid),0) AS total
       FROM payments WHERE status="verified" AND DATE(COALESCE(verified_at, payment_date)) = CURDATE()'
)->fetch_assoc();

// Penerimaan kemarin (verified)
$yesterdayRow = $db->query(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_paid),0) AS total
       FROM payments WHERE status="verified" AND DATE(COALESCE(verified_at, payment_date)) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)'
)->fetch_assoc();

// Pembayaran manual menunggu verifikasi
$pendingCount = (int) $db->query('SELECT COUNT(*) FROM payments WHERE status="pending"')->fetch_row()[0];

// Tiket pengaduan aktif (baru atau proses)
$activeComplaints = (int) $db->query("SELECT COUNT(*) FROM complaints WHERE status IN ('baru', 'proses')")->fetch_row()[0];

// Agenda kegiatan terdekat (mulai hari ini ke depan)
$nextEvent = $db->query(
    "SELECT title, event_date, location FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 1"
)->fetch_assoc();

// Periode tagihan terbaru
$period = $db->query('SELECT * FROM billing_periods ORDER BY period_year DESC, period_month DESC LIMIT 1')->fetch_assoc();
$periodStats = null;
$paidPct = 0.0;
if ($period) {
    $pid = (int) $period['id'];
    $pRow = $db->query(
        "SELECT COUNT(*) AS total_bills,
                SUM(status='sudah_bayar') AS paid_bills,
                SUM(status IN ('belum_bayar','terlambat')) AS unpaid_bills,
                COALESCE(SUM(CASE WHEN status='sudah_bayar' THEN total_amount END),0) AS collected,
                COALESCE(SUM(CASE WHEN status IN ('belum_bayar','terlambat') THEN total_amount END),0) AS outstanding
           FROM bills WHERE billing_period_id={$pid}"
    )->fetch_assoc();

    $totBills  = (int) ($pRow['total_bills'] ?? 0);
    $paidBills = (int) ($pRow['paid_bills'] ?? 0);
    $paidPct   = $totBills > 0 ? round(($paidBills / $totBills) * 100, 1) : 0.0;

    $periodStats = [
        'period'        => $period['label'],
        'total_bills'   => $totBills,
        'paid_bills'    => $paidBills,
        'unpaid_bills'  => (int) ($pRow['unpaid_bills'] ?? 0),
        'paid_percent'  => $paidPct,
        'collected'     => bot_money((float) ($pRow['collected'] ?? 0)),
        'outstanding'   => bot_money((float) ($pRow['outstanding'] ?? 0)),
    ];
}

// Saldo kas total
$cashRow = $db->query(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0)
          - COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS saldo
       FROM cash_book'
)->fetch_assoc();

// Format tanggal Bahasa Indonesia untuk Morning Briefing
$daysIndo = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$monthsIndo = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$dayName = $daysIndo[(int) date('w')];
$formattedDate = $dayName . ', ' . date('j') . ' ' . $monthsIndo[(int) date('n')] . ' ' . date('Y');

// Daftar Pengurus Aktif (Ketua, Bendahara, Sekretaris) beserta teks Morning Briefing siap kirim
$saldoKasText       = bot_money((float) ($cashRow['saldo'] ?? 0))['text'];
$nominalKemarinText = bot_money((float) ($yesterdayRow['total'] ?? 0))['text'];
$cntKemarin         = (int) ($yesterdayRow['cnt'] ?? 0);
$periodeLabel       = $periodStats['period'] ?? date('F Y');
$jmlLunas           = $periodStats['paid_bills'] ?? 0;
$totUnit            = $periodStats['total_bills'] ?? 0;
$totTerkumpulText   = $periodStats['collected']['text'] ?? 'Rp 0';
$totOutstandingText = $periodStats['outstanding']['text'] ?? 'Rp 0';
$jmlBelumLunas      = $periodStats['unpaid_bills'] ?? 0;

$agendaText = $nextEvent
    ? '• ' . $nextEvent['title'] . ' (' . date('d/m/Y', strtotime($nextEvent['event_date'])) . ($nextEvent['location'] ? ' - ' . $nextEvent['location'] : '') . ')'
    : '• Belum ada agenda kegiatan terdekat';

$recipients = [];
$resStaff = $db->query(
    "SELECT u.id, u.name, u.phone, r.name AS role
       FROM users u
       JOIN roles r ON r.id = u.role_id
      WHERE u.is_active = 1 AND r.name IN ('ketua', 'bendahara', 'sekretaris')
      ORDER BY FIELD(r.name, 'ketua', 'bendahara', 'sekretaris'), u.id ASC"
);

if ($resStaff) {
    while ($stf = $resStaff->fetch_assoc()) {
        $roleLabel = match ($stf['role']) {
            'ketua'      => 'Ketua RT',
            'bendahara'  => 'Bendahara',
            'sekretaris' => 'Sekretaris',
            default      => ucfirst($stf['role']),
        };
        $pNorm = phone_normalize($stf['phone']);
        $bText = "☕ *Selamat Pagi, Bpk/Ibu {$stf['name']} ({$roleLabel})*\n"
            . "*Morning Briefing — Arya Green Pamulang*\n"
            . "📅 {$formattedDate} (Pukul 08:00 WIB)\n\n"
            . "Berikut ringkasan operasional cluster pagi ini:\n\n"
            . "💰 *1. Keuangan & Kas Cluster*\n"
            . "• Saldo Kas Terkini : *{$saldoKasText}*\n"
            . "• Penerimaan Kemarin : {$nominalKemarinText} ({$cntKemarin} transaksi)\n\n"
            . "📊 *2. Progres IPL Bulan {$periodeLabel}*\n"
            . "• Ketercapaian : *{$jmlLunas}/{$totUnit} Unit* ({$paidPct}%)\n"
            . "• Dana Terkumpul : {$totTerkumpulText}\n"
            . "• Sisa Tagihan : {$totOutstandingText} ({$jmlBelumLunas} unit)\n\n"
            . "⏳ *3. Perlu Tindak Lanjut Pengurus*\n"
            . "• Bukti Bayar Menunggu Verifikasi : *{$pendingCount} transaksi*\n"
            . "• Aduan/Keluhan Warga (Baru/Proses) : *{$activeComplaints} tiket*\n\n"
            . "📅 *4. Agenda / Kegiatan Terdekat*\n"
            . "{$agendaText}\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━\n"
            . "🔗 *Akses Cepat Dashboard:*\n"
            . APP_URL . "/pages/dashboard/\n\n"
            . "Semoga aktivitas Bpk/Ibu hari ini berjalan lancar dan penuh berkah. Salam kompak Pengurus Arya Green! 🙏";

        $recipients[] = [
            'id'            => (int) $stf['id'],
            'name'          => $stf['name'],
            'role'          => $stf['role'],
            'role_label'    => $roleLabel,
            'phone'         => $pNorm,
            'briefing_text' => $bText,
        ];
    }
}

bot_reply(200, [
    'as_of'                    => date('Y-m-d H:i'),
    'briefing_date'            => $formattedDate,
    'staff'                    => $actor['staff'],
    'recipients'               => $recipients,
    'today_verified_count'     => (int) ($todayRow['cnt'] ?? 0),
    'today_verified_amount'    => bot_money((float) ($todayRow['total'] ?? 0)),
    'yesterday_verified_count' => $cntKemarin,
    'yesterday_verified_amount'=> bot_money((float) ($yesterdayRow['total'] ?? 0)),
    'pending_review_count'     => $pendingCount,
    'active_complaints_count'  => $activeComplaints,
    'cash_balance'             => bot_money((float) ($cashRow['saldo'] ?? 0)),
    'current_period'           => $periodStats,
    'upcoming_event'           => $nextEvent,
]);


