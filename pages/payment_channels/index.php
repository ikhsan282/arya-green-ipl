<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
auth_check();
require_permission('payment_channels.manage');
require_once __DIR__ . '/../../includes/payment_gateway.php';
require_once __DIR__ . '/../../includes/payment_gateway_direct.php';
require_once __DIR__ . '/../../includes/gopay_auth.php';
$db = db();
$providers = ['klikqris' => 'KlikQRIS', 'gopay' => 'GoPay Merchant', 'shopeepay' => 'ShopeePay'];
$code = (string) ($_GET['code'] ?? 'klikqris');
if (!isset($providers[$code])) { http_response_code(404); exit('Channel tidak ditemukan.'); }
$message = '';
$load = function () use ($db, $code) {
    $st = $db->prepare('SELECT * FROM payment_channels WHERE provider=? ORDER BY id LIMIT 1');
    $st->bind_param('s', $code); $st->execute();
    return $st->get_result()->fetch_assoc();
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['_action'] ?? 'save');
    $channel = $load();
    if ($action === 'renew') {
        $token = bin2hex(random_bytes(32)); $st = $db->prepare('UPDATE payment_channels SET webhook_token=? WHERE id=?');
        $id = (int)$channel['id']; $st->bind_param('si', $token, $id); $st->execute();
        log_activity('update', 'payment_channels', $code . ' webhook token regenerated');
        flash('success', 'Token webhook berhasil dibuat ulang.'); redirect(APP_URL . '/pages/payment_channels/index.php?code=' . $code);
    } elseif ($action === 'test') {
        $r = $code === 'klikqris' ? gateway_status($channel, 'PING-' . bin2hex(random_bytes(6)))
            : ($code === 'gopay' ? gopay_session_check($channel) : ['ok' => true]);
        if ($r['ok']) $message = 'Konfigurasi tersimpan. Untuk GoPay/ShopeePay cek sesi lewat /token-status gateway.';
        else { error_log($code . ' test koneksi gagal: ' . (string)$r['error']); $message = 'Tes koneksi gagal: periksa API key, Merchant ID, dan mode.'; }
    } elseif ($action === 'otp_request' && $code === 'gopay') {
        $r = gopay_otp_request((string)($_POST['otp_phone'] ?? ''));
        if ($r['ok']) { flash('success', 'Kode OTP dikirim ke +62' . $r['phone'] . '. Masukkan dalam 12 menit.'); }
        else { $_SESSION['gopay_otp_error'] = $r['error']; }
        redirect(APP_URL . '/pages/payment_channels/index.php?code=gopay');
    } elseif ($action === 'otp_verify' && $code === 'gopay') {
        $r = gopay_otp_verify($db, (int)$channel['id'], (string)($_POST['otp_code'] ?? ''));
        if ($r['ok']) { flash('success', 'Login GoPay berhasil. Sesi tersimpan' . ($r['outlet'] !== '' ? ' — ' . $r['outlet'] : '') . '.'); }
        else { $_SESSION['gopay_otp_error'] = $r['error']; }
        redirect(APP_URL . '/pages/payment_channels/index.php?code=gopay');
    } elseif ($action === 'session_check' && $code === 'gopay') {
        $channel = $load(); // state terbaru: barusan bisa saja berubah oleh OTP/refresh
        $r = gopay_session_check($channel);
        if ($r['state'] === 'valid') flash('success', 'Sesi GoPay aktif dan token valid.');
        elseif ($r['state'] === 'expired') {
            $rf = gopay_refresh($db, $channel);
            if ($rf['ok']) flash('success', 'Token kedaluwarsa — berhasil di-refresh otomatis.');
            elseif (str_contains((string) $rf['error'], 'refresh_token tidak tersimpan')) flash('warning', 'Sesi lama tidak menyimpan refresh_token. Login OTP ulang untuk membuat sesi baru.');
            else flash('danger', 'Token kedaluwarsa dan refresh gagal: ' . $rf['error']);
        } elseif ($r['state'] === 'empty') flash('warning', 'Belum ada sesi GoPay. Login lewat OTP di bawah.');
        else flash('danger', 'Gagal cek sesi: ' . $r['error']);
        redirect(APP_URL . '/pages/payment_channels/index.php?code=gopay');
    } else {
        $name = clean((string)($_POST['name'] ?? ''));
        $mode = (string)($_POST['mode'] ?? '');
        $merchant = clean((string)($_POST['merchant_id'] ?? ''));
        $key = trim((string)($_POST['api_key'] ?? '')); // KlikQRIS: api_key; GoPay/ShopeePay: token autentikasi
        $configRaw = trim((string)($_POST['config'] ?? ''));
        // config opsional: hanya terima JSON object, selain itu simpan NULL.
        $config = null;
        if ($configRaw !== '') {
            $decoded = json_decode($configRaw, true);
            $config = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        $instructions = clean((string)($_POST['instructions'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        // Sesi GoPay (refresh_token/expires_at) hidup di config — jangan ditimpa form.
        if ($code === 'gopay') {
            $cfg = json_decode((string)($channel['config'] ?? ''), true);
            $cfg = is_array($cfg) ? $cfg : [];
            $mid = trim((string)($_POST['gopay_merchant_id'] ?? ''));
            if ($mid === '') unset($cfg['merchant_ids']); else $cfg['merchant_ids'] = $mid;
            $config = $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        $keyAfter = $key !== '' ? $key : (string)($channel['api_key'] ?? '');
        if ($name === '' || !in_array($mode, ['sandbox', 'production'], true)) $message = 'Nama dan mode valid wajib diisi.';
        elseif ($active && ($keyAfter === '' || $merchant === '')) $message = 'Token dan QRIS statis merchant wajib diisi sebelum channel diaktifkan.';
        else {
            // ponytail: satu baris per provider; cukup UPDATE, INSERT tak perlu.
            $st = $db->prepare('UPDATE payment_channels SET name=?,mode=?,merchant_id=?,config=?,instructions=?,sort_order=?,is_active=?,api_key=COALESCE(NULLIF(?,\'\'),api_key) WHERE id=?');
            $id = (int)$channel['id'];
            $st->bind_param('sssssiisi', $name, $mode, $merchant, $config, $instructions, $sort, $active, $key, $id);
            if (!$st->execute()) { error_log('payment_channels save: ' . $st->error); $message = 'Gagal menyimpan konfigurasi.'; }
            else { log_activity('update', 'payment_channels', $code . ' channel configuration updated');
                flash('success', 'Konfigurasi channel berhasil disimpan.'); redirect(APP_URL . '/pages/payment_channels/index.php?code=' . $code); }
        }
    }
}
$channel = $load();
if (!$channel) { http_response_code(500); exit('Channel row belum ada; jalankan database/schema.sql.'); }
$webhook = $code === 'klikqris' ? gateway_webhook_url($channel) : '';
$masked = empty($channel['api_key']) ? 'Belum diatur'
    : str_repeat('•', max(0, strlen($channel['api_key']) - 4)) . substr($channel['api_key'], -4);
$gm = json_decode((string)($channel['config'] ?? ''), true);
$gm = is_array($gm) ? $gm : [];
$tabs = $db->query('SELECT provider, name, is_active FROM payment_channels ORDER BY sort_order, id')->fetch_all(MYSQLI_ASSOC);
$page_title = 'Channel Pembayaran'; include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper"><div class="topbar d-flex align-items-center px-3 gap-2"><button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button><h6 class="mb-0 fw-semibold">Channel Pembayaran</h6></div><div id="sidebarOverlay" class="sidebar-overlay"></div><div class="main-content">
<?= render_flash() ?><?php if ($message !== ''): ?><div class="alert <?= str_starts_with($message, 'Tes koneksi gagal') || str_starts_with($message, 'Gagal') ? 'alert-danger' : 'alert-info' ?>"><?= e($message) ?></div><?php endif; ?>
<ul class="nav nav-tabs mb-3"><?php foreach ($tabs as $t): ?><li class="nav-item"><a class="nav-link <?= $t['provider'] === $code ? 'active' : '' ?>" href="?code=<?= e($t['provider']) ?>"><?= e($t['name']) ?><?= $t['is_active'] ? ' <span class="badge bg-success">aktif</span>' : '' ?></a></li><?php endforeach; ?></ul>
<div class="card" style="max-width:850px"><div class="card-header">Konfigurasi <?= e($providers[$code]) ?></div><div class="card-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save"><div class="row g-3">
<div class="col-md-6"><label class="form-label">Nama</label><input class="form-control" name="name" maxlength="100" required value="<?= e($channel['name']) ?>"></div>
<div class="col-md-6"><label class="form-label">Mode</label><select class="form-select" name="mode"><option value="sandbox" <?= $channel['mode'] === 'sandbox' ? 'selected' : '' ?>>Sandbox</option><option value="production" <?= $channel['mode'] === 'production' ? 'selected' : '' ?>>Production</option></select></div>
<div class="col-md-6"><label class="form-label"><?= $code === 'klikqris' ? 'Merchant ID' : 'QRIS Statis Merchant' ?></label><input class="form-control" name="merchant_id" maxlength="500" value="<?= e($channel['merchant_id'] ?? '') ?>"></div>
<?php $apiLabel = match($code) { 'gopay' => 'Token/Session GoPay', 'shopeepay' => 'Token ShopeePay (B:...)', default => 'API key' }; ?><div class="col-md-6"><label class="form-label"><?= $apiLabel ?> <small class="text-muted">Saat ini: <?= e($masked) ?></small></label><input class="form-control" type="password" name="api_key" autocomplete="new-password" value="" placeholder="Kosongkan agar tidak berubah"></div>
<div class="col-md-6"><label class="form-label">Urutan tampil</label><input class="form-control" type="number" name="sort_order" value="<?= (int)$channel['sort_order'] ?>"></div>
<?php if ($code === 'gopay'): ?>
<div class="col-12"><label class="form-label">Merchant ID GoPay <small class="text-muted">(opsional, untuk query API)</small></label><input class="form-control" name="gopay_merchant_id" maxlength="60" value="<?= e((string)($gm['merchant_ids'] ?? '')) ?>" placeholder="G123456789"><small class="text-muted">Kosongkan bila tidak tahu — query API akan mengambil semua merchant dari sesi.</small></div>
<?php endif; ?>
<div class="col-12"><label class="form-label">Instruksi pembayaran</label><textarea class="form-control" name="instructions" rows="3"><?= e($channel['instructions'] ?? '') ?></textarea></div>
<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="active" <?= $channel['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="active">Aktif</label></div></div></div><button class="btn btn-success mt-3">Simpan konfigurasi</button></form>
<?php if ($code === 'klikqris'): ?>
<hr><label class="form-label fw-semibold">URL callback/webhook</label><div class="input-group"><input class="form-control" id="webhookUrl" readonly value="<?= e($webhook) ?>"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)">Salin</button></div><small class="text-muted">Tempel URL ini di dashboard KlikQRIS sebagai callback/webhook.</small>
<div class="d-flex gap-2 mt-3"><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="renew"><button class="btn btn-outline-warning">Buat ulang token webhook</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="test"><button class="btn btn-outline-primary">Tes koneksi</button></form></div>
<?php else: ?>
<?php if ($code === 'gopay'): ?>
<hr><label class="form-label fw-semibold">Sesi GoPay Merchant</label>
<?php $otpErr = $_SESSION['gopay_otp_error'] ?? ''; unset($_SESSION['gopay_otp_error']); ?>
<?php if ($otpErr !== ''): ?><div class="alert alert-danger py-2"><?= e($otpErr) ?></div><?php endif; ?>
<?php if (is_array($gm) && !empty($gm['refresh_token'])): ?>
<div class="alert alert-info py-2 small mb-3">Login sebagai <strong><?= e((string)($gm['outlet'] ?: 'Merchant GoPay')) ?></strong> (+62<?= e((string)($gm['phone'] ?? '')) ?>)<br>Token berlaku sampai <?= e(date('d M Y H:i', (int)($gm['expires_at'] ?? 0))) ?><?= !empty($gm['merchant_id']) ? ' · Merchant ID: ' . e((string)$gm['merchant_id']) : '' ?></div>
<?php endif; ?>
<div class="d-flex gap-2 mb-3"><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="session_check"><button class="btn btn-outline-primary btn-sm">Cek / refresh sesi</button></form></div>
<?php if (empty($_SESSION['gopay_otp'])): ?>
<form method="post" class="row g-2 align-items-end mb-2"><?= csrf_field() ?><input type="hidden" name="_action" value="otp_request">
<div class="col-sm-6"><label class="form-label small mb-1">Nomor HP GoBiz</label><input class="form-control" name="otp_phone" placeholder="085119772671" required autocomplete="off"></div>
<div class="col-sm-4"><button class="btn btn-outline-success">Kirim kode OTP</button></div></form>
<?php else: ?>
<form method="post" class="row g-2 align-items-end mb-2"><?= csrf_field() ?><input type="hidden" name="_action" value="otp_verify">
<div class="col-sm-6"><label class="form-label small mb-1">Kode OTP (SMS/WA ke +62<?= e((string)($_SESSION['gopay_otp']['phone'] ?? '')) ?>)</label><input class="form-control" name="otp_code" inputmode="numeric" maxlength="8" placeholder="4 digit" required autocomplete="one-time-code"></div>
<div class="col-sm-4"><button class="btn btn-success">Verifikasi & simpan sesi</button></div></form>
<?php endif; ?>
<small class="text-muted d-block mb-2">Login OTP menyimpan access+refresh token ke field Token di atas; token di-refresh otomatis saat cek sesi.</small>
<?php endif; ?>
<hr><small class="text-muted"><?= $code === 'gopay'
    ? 'Tempel JSON headers sesi GoPay Merchant di field <strong>Token</strong> (format: <code>{"Authorization":"Bearer ...","X-App-Id":"...",...}</code>). Field <strong>QRIS Statis Merchant</strong> berisi string QRIS dari struk/dashboard merchant. QRIS dinamis akan digenerate otomatis dari string statis ini.'
    : 'Tempel token ShopeePay Partner di field <strong>Token</strong> (format: <code>B:xxxxxxxx</code>). Field <strong>QRIS Statis Merchant</strong> berisi string QRIS dari struk/dashboard merchant. QRIS dinamis akan digenerate otomatis dari string statis ini.'
?></small><?php endif; ?>
</div></div></div></div><?php include __DIR__ . '/../../includes/footer.php'; ?>
