-- Migration: Buku Kas
-- Run: mysql -u root -p db_arya_green_ipl < database/migration_cashbook.sql

-- Tabel kas
CREATE TABLE IF NOT EXISTS `cash_book` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`        ENUM('pemasukan','pengeluaran') NOT NULL,
  `category`    VARCHAR(100) NOT NULL,
  `amount`      DECIMAL(14,2) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `trx_date`    DATE NOT NULL,
  `ref_payment_id` INT UNSIGNED DEFAULT NULL COMMENT 'Link ke tabel payments jika dari IPL',
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`ref_payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_cashbook_trx_date` (`trx_date`),
  INDEX `idx_cashbook_type`     (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions
INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES
  ('cashbook.view',   'Lihat Buku Kas',    'cashbook'),
  ('cashbook.manage', 'Kelola Buku Kas',   'cashbook');

-- Beri akses ke super_admin dan admin
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.name IN ('super_admin','admin') AND p.name IN ('cashbook.view','cashbook.manage');

-- Petugas hanya view
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.name = 'petugas' AND p.name = 'cashbook.view';
