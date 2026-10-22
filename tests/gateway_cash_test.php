<?php
/**
 * Regresi: pembayaran QRIS yang berhasil HARUS masuk buku kas.
 * Bug lama: kas hanya dicatat bila ada biaya tambahan, sehingga tagihan
 * <= Rp 200.000 (yang biayanya Rp 0) tidak pernah muncul di saldo kas.
 */
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "FATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n");
    }
});
set_exception_handler(function ($e) {
    fwrite(STDERR, 'EXC: ' . $e->getMessage() . "\n");
});

$db = db(); $ok = 0; $fail = 0;
$t = function ($c, $m) use (&$ok, &$fail) { if ($c) { $ok++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } };

$ch = gateway_channel();
if (!$ch) { echo "SKIP: channel KlikQRIS tidak aktif\n"; exit(1); }

$u = (int) $db->query("SELECT id FROM units LIMIT 1")->fetch_row()[0];

// Bersihkan sisa run sebelumnya (period unik per tahun-bulan).
$db->query("DELETE FROM cash_book WHERE ref_payment_id IN (SELECT id FROM payments WHERE bill_id IN (SELECT id FROM bills WHERE notes='CASHTEST'))");
$db->query("DELETE FROM payments WHERE bill_id IN (SELECT id FROM bills WHERE notes='CASHTEST')");
$db->query("DELETE FROM payment_gateway_orders WHERE order_id LIKE 'IPL-%'") ;
$db->query("DELETE FROM bills WHERE notes='CASHTEST'");
$db->query("DELETE FROM billing_periods WHERE label='CASHTEST'");

$db->query("INSERT INTO billing_periods (period_year,period_month,label,due_date) VALUES (2095,8,'CASHTEST',CURDATE())");
$p = (int) $db->insert_id;

/**
 * Jalankan satu siklus: buat order, bayar, finalize, lalu kembalikan
 * baris kas yang lahir dari pembayaran itu.
 */
function run_case($db, $ch, $u, $p, $amount, $tag) {
    // Setiap kasus memakai unit berbeda: UNIQUE (billing_period_id, unit_id).
    $used = (int) $db->query("SELECT COUNT(*) FROM bills WHERE notes='CASHTEST' AND billing_period_id=$p")->fetch_row()[0];
    if ($used > 0) {
        $nx = $db->query("SELECT id FROM units WHERE id > $u ORDER BY id LIMIT 1 OFFSET " . ($used - 1))->fetch_row();
        if ($nx) { $u = (int) $nx[0]; }
    }
    $db->query("INSERT INTO bills (billing_period_id,unit_id,resident_id,amount,total_amount,status,due_date,notes)
                VALUES ($p,$u,NULL,$amount,$amount,'belum_bayar',CURDATE(),'CASHTEST')");
    $bill = (int) $db->insert_id;
    $charge = gateway_charge_total((float) $amount);
    $oid = gateway_make_order_id($bill);
    $res = gateway_create($ch, $oid, $charge, $tag);
    if (!$res['ok']) { throw new RuntimeException('create gagal: ' . $res['error']); }
    gateway_store_order((int) $ch['id'], $oid, $bill, null, $charge, $res['data']);
    $o = gateway_find_order($oid);
    $fin = gateway_finalize($oid, (float) $o['total_amount']);
    if (!$fin['ok']) { throw new RuntimeException('finalize gagal: ' . $fin['error']); }
    $pid = (int) $fin['payment_id'];
    $rows = $db->query("SELECT category,amount FROM cash_book WHERE ref_payment_id=$pid ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    return [$bill, $pid, $oid, $rows];
}

// ── Kasus 1: tagihan 150.000 (tanpa biaya) ────────────────────────────────
[$b1, $p1, $o1, $r1] = run_case($db, $ch, $u, $p, 150000, 'Kasus gratis');
$t(count($r1) === 1, 'tagihan 150rb: tepat 1 baris kas (got ' . count($r1) . ')');
$t(($r1[0]['category'] ?? '') === 'IPL', 'kategori IPL');
$t((float) ($r1[0]['amount'] ?? 0) === 150000.0, 'nominal kas 150.000');

// ── Kasus 2: tagihan 300.000 (kena biaya 1.000) ───────────────────────────
[$b2, $p2, $o2, $r2] = run_case($db, $ch, $u, $p, 300000, 'Kasus berbiaya');
$t(count($r2) === 2, 'tagihan 300rb: 2 baris kas (IPL + biaya), got ' . count($r2));
$byCat = [];
foreach ($r2 as $r) { $byCat[$r['category']] = (float) $r['amount']; }
$t(($byCat['IPL'] ?? 0) === 300000.0, 'kas IPL = 300.000');
$t(($byCat['Biaya QRIS'] ?? 0) === 1000.0, 'kas Biaya QRIS = 1.000');

// ── Kasus 3: saldo kas benar-benar bertambah ──────────────────────────────
$total = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE ref_payment_id IN ($p1,$p2)")->fetch_row()[0];
$t($total === 451000.0, 'total kas dari 2 pembayaran = 451.000 (got ' . $total . ')');

// ── Kasus 4: idempotensi ──────────────────────────────────────────────────
gateway_finalize($o1, (float) gateway_find_order($o1)['total_amount']);
$n = (int) $db->query("SELECT COUNT(*) FROM cash_book WHERE ref_payment_id=$p1")->fetch_row()[0];
$t($n === 1, 'finalize ulang tidak menambah baris kas');

// bersihkan
foreach ([$p1, $p2] as $pid) $db->query("DELETE FROM cash_book WHERE ref_payment_id=$pid");
$db->query("DELETE FROM payments WHERE id IN ($p1,$p2)");
foreach ([$o1, $o2] as $oid) $db->query("DELETE FROM payment_gateway_orders WHERE order_id='$oid'");
$db->query("DELETE FROM bills WHERE notes='CASHTEST'");
$db->query("DELETE FROM billing_periods WHERE id=$p");

echo "\n" . ($fail ? "GAGAL $fail" : "SEMUA LULUS") . " ($ok assert)\n";
exit($fail ? 1 : 0);
