<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/payment_gateway.php';
require_permission('payments.create');

$db = db();
$billId = (int) ($_GET['bill_id'] ?? 0);
$uid = auth_id();
$provider = (string) ($_GET['provider'] ?? 'klikqris');
if (!in_array($provider, ['klikqris', 'gopay', 'shopeepay'], true)) {
    http_response_code(404);
    exit('Provider tidak dikenal.');
}

// Warga hanya boleh membayar tagihannya sendiri.
$sql = 'SELECT b.*, bp.label AS period FROM bills b
        JOIN billing_periods bp ON bp.id = b.billing_period_id';
if (auth_role() === 'warga') {
    $sql .= ' JOIN residents r ON r.id = b.resident_id WHERE b.id = ? AND r.user_id = ? AND b.status <> "sudah_bayar"';
    $s = $db->prepare($sql);
    $s->bind_param('ii', $billId, $uid);
} else {
    $sql .= ' WHERE b.id = ? AND b.status <> "sudah_bayar"';
    $s = $db->prepare($sql);
    $s->bind_param('i', $billId);
}
$s->execute();
$bill = $s->get_result()->fetch_assoc();
if (!$bill) {
    http_response_code(404);
    exit('Tagihan tidak ditemukan atau tidak dapat dibayar.');
}

$channel = gateway_channel($provider);
if (!$channel || empty($channel['api_key']) || empty($channel['merchant_id'])) {
    http_response_code(503);
    exit('QRIS Online sedang tidak tersedia.');
}

$order = gateway_pending_for_bill($billId, $provider);
if ($order && $order['expired_at'] && strtotime($order['expired_at']) <= time()) {
    gateway_mark_expired($order['order_id']);
    $order = null;
}

$billAmount = (float) $bill['total_amount'];
$fee = gateway_fee($billAmount);
$charge = gateway_charge_total($billAmount);

if (!$order) {
    $orderId = gateway_make_order_id($billId);
    $res = gateway_create($channel, $orderId, $charge, $bill['period']);
    if (!$res['ok']) {
        error_log('gateway create ' . $provider . ': ' . $res['error']);
        http_response_code(502);
        // Kesalahan konfigurasi ditampilkan apa adanya; admin perlu tahu isian mana yang salah.
        exit(str_starts_with((string) $res['error'], 'Isian') ? e($res['error']) : 'Gagal membuat pembayaran QRIS. Coba kembali.');
    }
    $data = $res['data'];
    if (empty($data['qris_url']) || !isset($data['total_amount'])) {
        http_response_code(502);
        exit('Respons QRIS tidak lengkap.');
    }
    gateway_store_order((int) $channel['id'], $orderId, $billId, $uid, $charge, $data);
    $order = gateway_find_order($orderId);
}

// Nominal unik (kode unik) bukan pendapatan: tampilkan rincian supaya warga paham.
$uniqueCode = max(0.0, (float) $order['total_amount'] - $charge);
$total = (float) $order['total_amount'];

$page_title = 'Bayar QRIS Online';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3"><h6 class="mb-0">Bayar QRIS Online — <?= e($channel['name']) ?></h6></div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <div class="card mx-auto" style="max-width:520px">
      <div class="card-body p-4 text-center">
        <h5 class="mb-1"><?= e($bill['period']) ?></h5>
        <p class="text-muted small mb-3">Scan QRIS dengan aplikasi pembayaran</p>
        <img src="<?= e($order['qris_url']) ?>" alt="QRIS pembayaran" class="img-fluid d-block mx-auto mb-4" width="260" height="260" style="max-width:100%;height:auto">
        <?php if ($fee > 0 || $uniqueCode > 0): ?>
        <div class="border rounded bg-light p-3 text-start mb-3">
          <div class="d-flex justify-content-between gap-3"><span>Tagihan IPL</span><span class="text-nowrap"><?= idr($billAmount) ?></span></div>
          <?php if ($fee > 0): ?>
          <div class="d-flex justify-content-between gap-3 mt-1 text-muted"><span>Biaya layanan QRIS</span><span class="text-nowrap"><?= idr($fee) ?></span></div>
          <?php endif; ?>
          <?php if ($uniqueCode > 0): ?>
          <div class="d-flex justify-content-between gap-3 mt-1 text-muted"><span>Kode unik</span><span class="text-nowrap"><?= idr($uniqueCode) ?></span></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <p class="text-muted mb-1">Total bayar tepat</p>
        <h2 class="fw-bold mb-1"><?= idr($total) ?></h2>
        <p class="text-danger small mb-3">Bayar persis nominal ini agar pembayaran dapat dikenali.</p>
        <p class="small mb-2">Batas waktu: <strong><?= e($order['expired_at'] ?? '—') ?></strong></p>
        <p id="payStatus" class="badge bg-warning text-dark" role="status" aria-live="polite">Menunggu pembayaran…</p>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
const cfg = {
  url: <?= json_encode(APP_URL . '/pages/payments/gateway_status.php') ?>,
  orderId: <?= json_encode($order['order_id']) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  detail: <?= json_encode(APP_URL . '/pages/payments/detail.php') ?>
};
const label = document.getElementById('payStatus');
async function poll() {
  try {
    const body = new URLSearchParams({ order_id: cfg.orderId, csrf_token: cfg.csrf });
    const r = await fetch(cfg.url, { method: 'POST', body, credentials: 'same-origin' });
    const j = await r.json();
    if (j.status === 'PAID' && j.payment_id) { location.href = cfg.detail + '?id=' + j.payment_id; return; }
    if (j.status === 'EXPIRED' || j.status === 'FAILED') { label.textContent = 'Pembayaran ' + j.status; return; }
  } catch (e) { /* jaringan putus: coba lagi */ }
  setTimeout(poll, 5000);
}
setTimeout(poll, 5000);
</script>
