<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/payment_gateway.php';
header('Content-Type: application/json; charset=utf-8');
$orderId=trim((string)($_GET['order_id']??''));$order=$orderId!==''?gateway_find_order($orderId):null;
if(!$order){http_response_code(404);echo json_encode(['status'=>'NOT_FOUND']);exit;}
if(auth_role()==='warga'){$s=db()->prepare('SELECT 1 FROM bills b JOIN residents r ON r.id=b.resident_id WHERE b.id=? AND r.user_id=?');$uid=auth_id();$s->bind_param('ii',$order['bill_id'],$uid);$s->execute();if(!$s->get_result()->fetch_row()){http_response_code(403);echo json_encode(['status'=>'FORBIDDEN']);exit;}}
if($order['status']==='PENDING'){$channel=['api_key'=>$order['api_key'],'merchant_id'=>$order['merchant_id'],'mode'=>$order['mode'],'provider'=>$order['provider']];$remote=gateway_status($channel,$orderId);if($remote['ok']&&is_array($remote['data'])){$st=strtoupper((string)($remote['data']['status']??''));if(in_array($st,['SUCCESS','PAID'],true)&&(float)($remote['data']['total_amount']??-1)===(float)$order['total_amount']){$done=gateway_finalize($orderId,(float)$remote['data']['total_amount']);if($done['ok']){$order['status']='PAID';$order['payment_id']=$done['payment_id'];}}elseif($st==='EXPIRED'){gateway_mark_expired($orderId);$order['status']='EXPIRED';}}}
echo json_encode(['status'=>$order['status'],'payment_id'=>isset($order['payment_id'])?(int)$order['payment_id']:null]);
