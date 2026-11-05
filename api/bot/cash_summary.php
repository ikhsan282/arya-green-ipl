<?php
/**
 * GET /api/bot/cash_summary.php
 * Ringkasan kas lingkungan — agregat saja, angka identik dengan section Transparansi
 * di landing page. Tanpa detail transaksi, nama penyetor, atau catatan.
 */
require __DIR__ . '/_bootstrap.php';

$ctx = bot_boot(['GET']);
$db  = $ctx['db'];

$all = $db->query(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
       FROM cash_book'
)->fetch_assoc();

$y = (int) date('Y');
$m = (int) date('n');
$st = $db->prepare(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
       FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?'
);
$st->bind_param('ii', $y, $m);
$st->execute();
$month = $st->get_result()->fetch_assoc();

bot_reply(200, [
    'as_of'          => date('Y-m-d H:i'),
    'balance'        => bot_money((float) $all['masuk'] - (float) $all['keluar']),
    'month_label'    => period_label($y, $m),
    'month_income'   => bot_money((float) $month['masuk']),
    'month_expense'  => bot_money((float) $month['keluar']),
]);

