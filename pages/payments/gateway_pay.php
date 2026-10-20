<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/payment_gateway.php';
require_permission('payments.create');
$db=db(); $billId=(int)($_GET['bill_id']??0); $uid=auth_id();
if(auth_role()==='warga'){$s=$db->prepare('SELECT b.*,bp.label AS period FROM bills b JOIN residents r ON r.id=b.resident_id JOIN billing_periods bp ON bp.id=b.billing_period_id WHERE b.id=? AND r.user_id=? AND b.status<>"sudah_bayar"');$s->bind_param('ii',$billId,$uid);}else{$s=$db->prepare('SELECT b.*,bp.label AS period FROM bills b JOIN billing_periods bp ON bp.id=b.billing_period_id WHERE b.id=? AND b.status<>"sudah_bayar"');$s->bind_param('i',$billId);}
$s->execute();$bill=$s->get_result()->fetch_assoc(); if(!$bill){http_response_code(404);exit('Tagihan tidak ditemukan atau tidak dapat dibayar.');}
$channel=gateway_channel(); if(!$channel || empty($channel['api_key'])||empty($channel['merchant_id'])){http_response_code(503);exit('QRIS Online sedang tidak tersedia.');}
$order=gateway_pending_for_bill($billId); if($order && $order['expired_at'] && strtotime($order['expired_at'])<=time()){gateway_mark_expired($order['order_id']);$order=null;}
if(!$order){$orderId=gateway_make_order_id($billId);$res=gateway_create($channel,$orderId,(float)$bill['total_amount'],$bill['period']);if(!$res['ok']){http_response_code(502);exit('Gagal membuat pembayaran QRIS. Coba kembali.');}$data=$res['data'];if(empty($data['qris_url'])||!isset($data['total_amount'])){http_response_code(502);exit('Respons QRIS tidak lengkap.');}gateway_store_order((int)$channel['id'],$orderId,$billId,$uid,(float)$bill['total_amount'],$data);$order=gateway_find_order($orderId);}
$page_title='Bayar QRIS Online';include __DIR__.'/../../includes/header.php';include __DIR__.'/../../includes/sidebar.php';
?>
<div class="content-wrapper"><div class="topbar d-flex align-items-center px-3"><h6 class="mb-0">Bayar QRIS Online</h6></div><div id="sidebarOverlay" class="sidebar-overlay"></div><div class="main-content"><div class="card" style="max-width:560px"><div class="card-body text-center"><h5><?= e($bill['period']) ?></h5><img src="<?= e($order['qris_url']) ?>" alt="QRIS pembayaran" class="img-fluid my-3" style="max-width:320px"><p>Total bayar tepat:</p><h2 class="fw-bold"><?= idr((float)$order['total_amount']) ?></h2><p class="text-danger">Bayar PERSIS nominal ini, termasuk kode unik.</p><p>Batas waktu: <strong><?= e($order['expired_at']??'—') ?></strong></p><p id="payStatus">Menunggu pembayaran…</p></div></div></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
<script>const poll=async()=>{try{const r=await fetch('gateway_status.php?order_id='+encodeURIComponent(<?= json_encode($order['order_id']) ?>),{credentials:'same-origin'});const j=await r.json();if(j.status==='PAID'&&j.payment_id){location.href='detail.php?id='+j.payment_id;return}if(j.status==='EXPIRED'||j.status==='FAILED'){document.getElementById('payStatus').textContent='Pembayaran '+j.status;return}}catch(e){}setTimeout(poll,5000)};setTimeout(poll,5000);</script>
