<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
auth_check();
require_permission('payment_channels.manage');
require_once __DIR__ . '/../../includes/payment_gateway.php';
$db = db();
$st = $db->prepare('SELECT * FROM payment_channels WHERE code=? LIMIT 1');
$code = 'klikqris'; $st->bind_param('s', $code); $st->execute();
$channel = $st->get_result()->fetch_assoc();
if (!$channel) { http_response_code(404); exit('Channel tidak ditemukan.'); }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['_action'] ?? 'save');
    if ($action === 'save') {
        $name = clean((string)($_POST['name'] ?? ''));
        $mode = (string)($_POST['mode'] ?? '');
        $merchant = clean((string)($_POST['merchant_id'] ?? ''));
        $key = trim((string)($_POST['api_key'] ?? ''));
        $instructions = clean((string)($_POST['instructions'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || !in_array($mode, ['sandbox','production'], true)) $message = 'Nama dan mode valid wajib diisi.';
        elseif ($active && ($key === '' && empty($channel['api_key']) || $merchant === '')) $message = 'API key dan Merchant ID wajib diisi sebelum channel diaktifkan.';
        else {
            $sql = $key === ''
                ? 'UPDATE payment_channels SET name=?,mode=?,merchant_id=?,instructions=?,is_active=? WHERE id=?'
                : 'UPDATE payment_channels SET name=?,mode=?,merchant_id=?,instructions=?,is_active=?,api_key=? WHERE id=?';
            $st = $db->prepare($sql);
            if ($key === '') $st->bind_param('ssssii', $name,$mode,$merchant,$instructions,$active,$channel['id']);
            else $st->bind_param('ssssisi', $name,$mode,$merchant,$instructions,$active,$key,$channel['id']);
            $st->execute(); log_activity('update','payment_channels','KlikQRIS channel configuration updated');
            flash('success','Konfigurasi channel berhasil disimpan.'); redirect(APP_URL.'/pages/payment_channels/index.php');
        }
    } elseif ($action === 'renew') {
        $token = bin2hex(random_bytes(32)); $st=$db->prepare('UPDATE payment_channels SET webhook_token=? WHERE id=?');
        $st->bind_param('si',$token,$channel['id']); $st->execute(); log_activity('update','payment_channels','KlikQRIS webhook token regenerated');
        flash('success','Token webhook berhasil dibuat ulang.'); redirect(APP_URL.'/pages/payment_channels/index.php');
    } elseif ($action === 'test') {
        $result = gateway_status($channel, 'PING-'.bin2hex(random_bytes(6)));
        if ($result['ok']) $message='Koneksi terautentikasi: gateway menerima request status (order uji tidak harus ditemukan).';
        else $message='Tes koneksi gagal: '.(string)($result['error'] ?: 'Respons gateway tidak berhasil.');
    }
    $st=$db->prepare('SELECT * FROM payment_channels WHERE id=?'); $st->bind_param('i',$channel['id']); $st->execute(); $channel=$st->get_result()->fetch_assoc();
}
$webhook = gateway_webhook_url($channel);
$masked = empty($channel['api_key']) ? 'Belum diatur' : str_repeat('•', max(0,strlen($channel['api_key'])-4)).substr($channel['api_key'],-4);
$page_title='Channel Pembayaran'; include __DIR__.'/../../includes/header.php'; include __DIR__.'/../../includes/sidebar.php';
?>
<div class="content-wrapper"><div class="topbar d-flex align-items-center px-3 gap-2"><button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button><h6 class="mb-0 fw-semibold">Channel Pembayaran</h6></div><div id="sidebarOverlay" class="sidebar-overlay"></div><div class="main-content">
<?= render_flash() ?><?php if($message!==''): ?><div class="alert <?= str_starts_with($message,'Tes koneksi gagal')?'alert-danger':'alert-info' ?>"><?=e($message)?></div><?php endif; ?>
<div class="card" style="max-width:850px"><div class="card-header">Konfigurasi KlikQRIS</div><div class="card-body">
<form method="post"><?=csrf_field()?><input type="hidden" name="_action" value="save"><div class="row g-3">
<div class="col-md-6"><label class="form-label">Nama</label><input class="form-control" name="name" maxlength="100" required value="<?=e($channel['name'])?>"></div>
<div class="col-md-6"><label class="form-label">Mode</label><select class="form-select" name="mode"><option value="sandbox" <?=$channel['mode']==='sandbox'?'selected':''?>>Sandbox</option><option value="production" <?=$channel['mode']==='production'?'selected':''?>>Production</option></select></div>
<div class="col-md-6"><label class="form-label">Merchant ID</label><input class="form-control" name="merchant_id" maxlength="100" value="<?=e($channel['merchant_id']??'')?>"></div>
<div class="col-md-6"><label class="form-label">API key <small class="text-muted">Saat ini: <?=e($masked)?></small></label><input class="form-control" type="password" name="api_key" autocomplete="new-password" value="" placeholder="Kosongkan agar tidak berubah"></div>
<div class="col-12"><label class="form-label">Instruksi pembayaran</label><textarea class="form-control" name="instructions" rows="3"><?=e($channel['instructions']??'')?></textarea></div>
<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="active" <?=$channel['is_active']?'checked':''?>><label class="form-check-label" for="active">Aktif</label></div></div></div><button class="btn btn-success mt-3">Simpan konfigurasi</button></form>
<hr><label class="form-label fw-semibold">URL callback/webhook</label><div class="input-group"><input class="form-control" id="webhookUrl" readonly value="<?=e($webhook)?>"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)">Salin</button></div><small class="text-muted">Tempel URL ini di dashboard KlikQRIS sebagai callback/webhook.</small>
<div class="d-flex gap-2 mt-3"><form method="post"><?=csrf_field()?><input type="hidden" name="_action" value="renew"><button class="btn btn-outline-warning">Buat ulang token webhook</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="_action" value="test"><button class="btn btn-outline-primary">Tes koneksi</button></form></div>
</div></div></div></div><?php include __DIR__.'/../../includes/footer.php'; ?>
