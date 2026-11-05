-- Migration: Tambah nomor telepon kedua (pasangan/istri/suami) pada data warga
ALTER TABLE `residents`
  ADD COLUMN `phone_secondary` VARCHAR(20) DEFAULT NULL AFTER `phone`,
  ADD COLUMN `phone_secondary_norm` VARCHAR(20) DEFAULT NULL AFTER `phone_norm`,
  ADD INDEX `idx_residents_phone_sec_norm` (`phone_secondary_norm`);
