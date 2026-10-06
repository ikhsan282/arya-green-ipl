<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('wa.send');

$db  = db();
$uid = auth_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = clean($_POST['_action'] ?? '');

    if ($action === 'queue') {
        // Antrekan reminder ke semua warga belum bayar bulan ini
        $period_id = (int)($_POST['period_id'] ?? 0);
        if (!$period_id) { flash('error', 'Pilih periode tagihan.'); redirect(APP_URL.'/pages/wa/index.php'); }

        $bills = $db->prepare(
            'SELECT b.id AS bill_id, r.id AS resident_id, r.phone, r.name AS resident_name,
                    u.unit_number, u.block, bp.label AS period, b.total_amount
             FROM bills b
             JOIN units u ON u.id=b.unit_id
             JOIN billing_periods bp ON bp.id=b.billing_period_id
             LEFT JOIN residents r ON r.id=b.resident_id
             WHERE b.billing_period_id=? AND b.status IN ("belum_bayar","terlambat")
             AND r.phone IS NOT NULL AND r.phone != ""'
        );
        $bills->bind_param('i', $period_id); $bills->execute();
        $rows = $bills->get_result()->fetch_all(MYSQLI_ASSOC);

        $tpl = clean($_POST['template'] ?? '');
        $queued = 0;
        foreach ($rows as $row) {
            $msg = strtr($tpl, [
                '{nama}'   => $row['resident_name'] ?? '',
                '{unit}'   => $row['block'].'-'.$row['unit_number'],
                '{periode}'=> $row['period'],
                '{jumlah}' => idr((float)$row['total_amount']),
            ]);
            // Cek belum ada di antrian untuk bill ini
            $chk = $db->prepare('SELECT id FROM wa_messages WHERE bill_id=? AND status="queued"');
            $chk->bind_param('i', $row['bill_id']); $chk->execute();
            if ($chk->get_result()->fetch_row()) continue;

            $s = $db->prepare('INSERT INTO wa_messages (phone,resident_id,bill_id,message) VALUES (?,?,?,?)');
            $phone = preg_replace('/[^0-9]/', '', $row['phone']);
            if (str_starts_with($phone, '0')) $phone = '62'.substr($phone,1);
            $s->bind_param('siis', $phone, $row['resident_id'], $row['bill_id'], $msg);
            $s->execute();
            $queued++;
        }
        log_activity('queue', 'wa', "Antrekan {$queued} WA reminder periode_id={$period_id}");
        flash('success', "{$queued} pesan berhasil diantrekan.");
        redirect(APP_URL.'/pages/wa/index.php');
    }

    if ($action === 'retry') {
        $id = (int)($_POST['msg_id'] ?? 0);
        $db->prepare('UPDATE wa_messages SET status="queued",error_msg=NULL WHERE id=? AND status="failed"')
           ->bind_param('i', $id)->execute();
        flash('success', 'Pesan dikembalikan ke antrian.');
        redirect(APP_URL.'/pages/wa/index.php');
    }

    if ($action === 'mark_sent') {
        // Manual mark sebagai sent (untuk integrasi manual via WhatsApp Web)
        $id = (int)($_POST['msg_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $db->prepare('UPDATE wa_messages SET status="sent",sent_at=? WHERE id=?')
           ->bind_param('si', $now, $id)->execute();
        flash('success', 'Pesan ditandai terkirim.');
        redirect(APP_URL.'/pages/wa/index.php');
    }

    if ($action === 'clear_sent') {
        $db->query('DELETE FROM wa_messages WHERE status="sent"');
        flash('success', 'Riwayat terkirim dibersihkan.');
        redirect(APP_URL.'/pages/wa/index.php');
    }
}

// ── Data ──────────────────────────────────────────────────────────────────
$f_status = clean($_GET['status'] ?? 'queued');
$page = max(1,(int)($_GET['page'] ?? 1)); $per = 25;

$where = ['1=1']; $params = []; $types = '';
if ($f_status) { $where[] = 'wm.status=?'; $params[] = $f_status; $types .= 's'; }
$wsql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM wa_messages wm WHERE {$wsql}");
if ($types) $cnt->bind_param($types, ...$params); $cnt->execute();
$total = $cnt->get_result()->fetch_row()[0];
$pag = paginate($total, $per, $page);

$stmt = $db->prepare(
    "SELECT wm.*, r.name AS resident_name, b.total_amount,
            bp.label AS period, u.unit_number, u.block
     FROM wa_messages wm
     LEFT JOIN residents r ON r.id=wm.resident_id
     LEFT JOIN bills b ON b.id=wm.bill_id
     LEFT JOIN billing_periods bp ON bp.id=b.billing_period_id
     LEFT JOIN units u ON u.id=b.unit_id
     WHERE {$wsql} ORDER BY wm.created_at DESC LIMIT ? OFFSET ?"
);
$fp = array_merge($params, [$per, $pag['offset']]);
$stmt->bind_param($types.'ii', ...$fp); $stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stats = $db->query(
    'SELECT status, COUNT(*) AS cnt FROM wa_messages GROUP BY status'
)->fetch_all(MYSQLI_ASSOC);
$stat_map = array_column($stats, 'cnt', 'status');

$periods = $db->query(
    'SELECT id,label FROM billing_periods ORDER BY period_year DESC, period_month DESC LIMIT 12'
)->fetch_all(MYSQLI_ASSOC);

$default_tpl = "Yth. {nama},\n\nTagihan IPL unit {unit} periode {periode} sebesar {jumlah} belum kami terima.\n\nMohon segera melakukan pembayaran. Terima kasih.\n\n— Pengurus RT";

$page_title = 'Reminder WhatsApp';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-whatsapp me-1 text-success"></i> Reminder WhatsApp</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>

    <!-- Stats -->
    <div class="row g-3 mb-3">
      <?php foreach (['queued'=>['warning','Antrian'],'sent'=>['success','Terkirim'],'failed'=>['danger','Gagal']] as $s=>[$c,$l]): ?>
      <div class="col-4">
        <div class="card stat-card">
          <div class="card-body d-flex align-items-center gap-3 py-2">
            <div class="stat-icon bg-<?= $c ?> bg-opacity-10 text-<?= $c ?>">
              <i class="bi bi-<?= $s==='queued'?'clock':'envelope-check' ?> fs-5"></i>
            </div>
            <div>
              <div class="text-muted small"><?= $l ?></div>
              <div class="fw-bold fs-5"><?= $stat_map[$s] ?? 0 ?></div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3">
      <!-- Form Queue -->
      <div class="col-md-4">
        <div class="card">
          <div class="card-header"><i class="bi bi-send me-1 text-success"></i> Antrekan Reminder</div>
          <div class="card-body">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="queue">
              <div class="mb-2">
                <label class="form-label">Periode Tagihan</label>
                <select name="period_id" class="form-select form-select-sm" required>
                  <option value="">— Pilih Periode —</option>
                  <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= e($p['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Template Pesan</label>
                <textarea name="template" class="form-control form-control-sm" rows="6" required><?= e($default_tpl) ?></textarea>
                <div class="form-text">Variabel: {nama}, {unit}, {periode}, {jumlah}</div>
              </div>
              <button class="btn btn-success btn-sm w-100">
                <i class="bi bi-send me-1"></i>Antrekan Pesan
              </button>
            </form>
          </div>
        </div>

        <div class="card mt-3">
          <div class="card-header"><i class="bi bi-info-circle me-1"></i> Cara Kirim</div>
          <div class="card-body small text-muted">
            <ol class="mb-0 ps-3">
              <li>Antrekan pesan dari form di atas</li>
              <li>Buka WhatsApp Web di browser lain</li>
              <li>Klik <strong>Kirim via WA</strong> untuk setiap pesan</li>
              <li>Atau integrasikan dengan API WA (Fonnte, WABLAS, dll) via webhook</li>
            </ol>
          </div>
        </div>
      </div>

      <!-- Outbox -->
      <div class="col-md-8">
        <div class="card">
          <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <span><i class="bi bi-table me-1"></i> Outbox</span>
            <div class="ms-auto d-flex gap-2 flex-wrap">
              <?php foreach (['' => 'Semua', 'queued' => 'Antrian', 'sent' => 'Terkirim', 'failed' => 'Gagal'] as $v => $l): ?>
                <a href="?status=<?= $v ?>" class="btn btn-sm <?= $f_status===$v?'btn-success':'btn-outline-secondary' ?>"><?= $l ?></a>
              <?php endforeach; ?>
              <?php if (($stat_map['sent'] ?? 0) > 0 && can('wa.send')): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Hapus semua riwayat terkirim?')">
                <?= csrf_field() ?><input type="hidden" name="_action" value="clear_sent">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead><tr>
                  <th>Nomor</th><th>Warga</th><th>Periode</th>
                  <th>Status</th><th>Waktu</th><th>Aksi</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada pesan.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                <tr>
                  <td class="small font-monospace"><?= e($r['phone']) ?></td>
                  <td class="small"><?= e($r['resident_name'] ?? '—') ?>
                    <?php if ($r['unit_number']): ?>
                      <br><span class="text-muted"><?= e($r['block'].'-'.$r['unit_number']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="small"><?= e($r['period'] ?? '—') ?></td>
                  <td><?php
                    $sm = ['queued'=>['warning','Antrian'],'sent'=>['success','Terkirim'],'failed'=>['danger','Gagal']];
                    [$c,$l] = $sm[$r['status']] ?? ['secondary',$r['status']];
                    echo "<span class=\"badge bg-{$c}\">{$l}</span>";
                    if ($r['error_msg']) echo "<br><small class='text-danger'>".e($r['error_msg'])."</small>";
                  ?></td>
                  <td class="small text-muted"><?= $r['sent_at'] ? fmt_date($r['sent_at'],'d M H:i') : fmt_date($r['created_at'],'d M H:i') ?></td>
                  <td>
                    <?php
                    $wa_url = 'https://wa.me/'.$r['phone'].'?text='.rawurlencode($r['message']);
                    ?>
                    <a href="<?= $wa_url ?>" target="_blank" class="btn btn-sm btn-success py-0 px-2 me-1" title="Kirim via WA Web">
                      <i class="bi bi-whatsapp"></i>
                    </a>
                    <?php if ($r['status'] === 'queued'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="mark_sent">
                      <input type="hidden" name="msg_id" value="<?= $r['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Tandai terkirim">
                        <i class="bi bi-check2"></i>
                      </button>
                    </form>
                    <?php elseif ($r['status'] === 'failed'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="retry">
                      <input type="hidden" name="msg_id" value="<?= $r['id'] ?>">
                      <button class="btn btn-sm btn-outline-warning py-0 px-2" title="Coba ulang">
                        <i class="bi bi-arrow-repeat"></i>
                      </button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php if ($pag['total_pages'] > 1): ?>
          <div class="card-footer"><?= render_pagination($pag, '?status='.urlencode($f_status)) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
