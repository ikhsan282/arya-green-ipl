-- =====================================================================
-- Perbaikan data: pembayaran QRIS KlikQRIS yang lunas tapi tidak masuk
-- buku kas.
--
-- Penyebab: sebelum perbaikan 2026-10-22, gateway_finalize() hanya menulis
-- baris cash_book bila ada biaya tambahan. Tagihan <= Rp 200.000 biayanya
-- Rp 0, sehingga pembayaran tetap tercatat lunas tapi saldo kas tidak
-- bertambah.
--
-- Aman dijalankan berulang: hanya menyentuh pembayaran yang BELUM punya
-- baris kas. Jalankan lewat phpMyAdmin setelah backup database.
-- =====================================================================

INSERT INTO `cash_book` (`type`,`category`,`amount`,`description`,`trx_date`,`ref_payment_id`,`created_by`)
SELECT 'pemasukan',
       'IPL',
       p.`amount_paid`,
       CONCAT('IPL ', COALESCE(bp.`label`,''), ' — ', COALESCE(u.`block`,''), '-', COALESCE(u.`unit_number`,'')),
       p.`payment_date`,
       p.`id`,
       COALESCE(p.`user_id`, 1)
FROM `payments` p
JOIN `bills` b            ON b.`id` = p.`bill_id`
LEFT JOIN `billing_periods` bp ON bp.`id` = b.`billing_period_id`
LEFT JOIN `units` u       ON u.`id` = b.`unit_id`
WHERE p.`status` = 'verified'
  AND p.`payment_method_id` = (SELECT `id` FROM `payment_methods` WHERE `code` = 'qris-klikqris' LIMIT 1)
  AND NOT EXISTS (SELECT 1 FROM `cash_book` c WHERE c.`ref_payment_id` = p.`id`);

-- Verifikasi: seharusnya 0 baris.
SELECT COUNT(*) AS qris_tanpa_kas
FROM `payments` p
WHERE p.`status` = 'verified'
  AND p.`payment_method_id` = (SELECT `id` FROM `payment_methods` WHERE `code` = 'qris-klikqris' LIMIT 1)
  AND NOT EXISTS (SELECT 1 FROM `cash_book` c WHERE c.`ref_payment_id` = p.`id`);
