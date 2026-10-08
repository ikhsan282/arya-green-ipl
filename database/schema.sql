-- ============================================================
-- Arya Green Pamulang - IPL Management System
-- Database Schema
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+07:00";

CREATE DATABASE IF NOT EXISTS `db_arya_green_ipl`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `db_arya_green_ipl`;

-- ------------------------------------------------------------
-- Roles
-- ------------------------------------------------------------
CREATE TABLE `roles` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(50) NOT NULL UNIQUE,
  `label`      VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`name`, `label`) VALUES
  ('super_admin', 'Super Admin'),
  ('ketua',       'Ketua'),
  ('bendahara',   'Bendahara'),
  ('warga',       'Warga');

-- ------------------------------------------------------------
-- Permissions
-- ------------------------------------------------------------
CREATE TABLE `permissions` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL UNIQUE,
  `label`       VARCHAR(150) NOT NULL,
  `module`      VARCHAR(50) NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`name`, `label`, `module`) VALUES
  -- Dashboard
  ('dashboard.view',          'Lihat Dashboard',                'dashboard'),
  -- Units
  ('units.view',              'Lihat Data Unit',                'units'),
  ('units.create',            'Tambah Unit',                    'units'),
  ('units.edit',              'Edit Unit',                      'units'),
  ('units.delete',            'Hapus Unit',                     'units'),
  -- Residents
  ('residents.view',          'Lihat Data Warga',               'residents'),
  ('residents.create',        'Tambah Warga',                   'residents'),
  ('residents.edit',          'Edit Warga',                     'residents'),
  ('residents.delete',        'Hapus Warga',                    'residents'),
  -- Billing
  ('billing.view',            'Lihat Tagihan',                  'billing'),
  ('billing.generate',        'Generate Tagihan',               'billing'),
  ('billing.edit',            'Edit Tagihan',                   'billing'),
  ('billing.send_reminder',   'Kirim Reminder Email',           'billing'),
  -- Komponen IPL
  ('ipl_components.view',     'Lihat Komponen IPL',             'billing'),
  ('ipl_components.manage',   'Kelola Komponen IPL',            'billing'),
  -- Payments
  ('payments.view',           'Lihat Pembayaran',               'payments'),
  ('payments.create',         'Catat Pembayaran',               'payments'),
  ('payments.verify',         'Verifikasi Pembayaran',          'payments'),
  -- Payment Methods
  ('payment_methods.view',    'Lihat Metode Pembayaran',        'payments'),
  ('payment_methods.manage',  'Kelola Metode Pembayaran',       'payments'),
  -- Cashbook
  ('cashbook.view',           'Lihat Buku Kas',                 'cashbook'),
  ('cashbook.manage',         'Kelola Buku Kas',                'cashbook'),
  -- Reports
  ('reports.view',            'Lihat Laporan',                  'reports'),
  -- Users and roles
  ('users.view',              'Lihat Data User',                'users'),
  ('users.create',            'Tambah User',                    'users'),
  ('users.edit',              'Edit User',                      'users'),
  ('users.delete',            'Hapus User',                     'users'),
  ('roles.view',              'Lihat Roles',                    'roles'),
  ('roles.manage',            'Kelola Roles & Permissions',     'roles'),
  -- Approval pengeluaran
  ('expense.request',         'Ajukan Pengeluaran',             'expense'),
  ('expense.approve',         'Setujui/Tolak Pengeluaran',      'expense'),
  -- Aduan
  ('complaints.view',         'Lihat Aduan',                    'complaints'),
  ('complaints.manage',       'Kelola Aduan',                   'complaints'),
  -- Polling
  ('polls.view',              'Lihat Polling',                  'polls'),
  ('polls.manage',            'Kelola Polling',                 'polls'),
  ('polls.vote',              'Vote Polling',                   'polls'),
  -- Kegiatan
  ('events.view',             'Lihat Kegiatan',                 'events'),
  ('events.manage',           'Kelola Kegiatan',                'events'),
  ('events.attendance',       'Kelola Absensi',                 'events'),
  -- Inventaris
  ('inventory.view',          'Lihat Inventaris',               'inventory'),
  ('inventory.manage',        'Kelola Inventaris',              'inventory'),
  -- Surat
  ('letters.view',            'Lihat Surat RT',                 'letters'),
  ('letters.manage',          'Buat/Cetak Surat RT',            'letters'),
  -- Lingkungan
  ('environments.view',       'Lihat Lingkungan',               'environments'),
  ('environments.create',     'Tambah Lingkungan',              'environments'),
  ('environments.edit',       'Edit Lingkungan',                'environments'),
  ('environments.delete',     'Hapus Lingkungan',               'environments');

-- ------------------------------------------------------------
-- Role Permissions
-- ------------------------------------------------------------
CREATE TABLE `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`)       REFERENCES `roles`(`id`)       ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Super Admin: semua permission
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Ketua: semua kecuali roles.manage, users.delete, environments.*
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions`
WHERE `name` NOT IN ('roles.manage','users.delete','environments.create','environments.edit','environments.delete');

-- Bendahara: keuangan, kas, laporan, komunikasi dasar
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions`
WHERE `name` IN (
  'dashboard.view',
  'units.view','residents.view',
  'billing.view','billing.generate',
  'ipl_components.view','ipl_components.manage',
  'payments.view','payments.create','payments.verify',
  'payment_methods.view',
  'cashbook.view','cashbook.manage',
  'expense.request',
  'reports.view',
  'complaints.view',
  'polls.view','polls.vote',
  'events.view','events.attendance',
  'inventory.view',
  'letters.view'
);

-- Warga: dashboard, tagihan sendiri, komunikasi warga
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions`
WHERE `name` IN (
  'dashboard.view',
  'billing.view',
  'payments.view','payments.create',
  'complaints.view',
  'polls.view','polls.vote',
  'events.view',
  'letters.view'
);

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id`            INT UNSIGNED NOT NULL DEFAULT 4,
  `name`               VARCHAR(150) NOT NULL,
  `username`           VARCHAR(80)  NOT NULL UNIQUE,
  `email`              VARCHAR(150) NOT NULL UNIQUE,
  `password`           VARCHAR(255) NOT NULL,
  `email_verified_at`  DATETIME     DEFAULT NULL,
  `verification_token` VARCHAR(100) DEFAULT NULL,
  `reset_token`        VARCHAR(100) DEFAULT NULL,
  `reset_token_expires`DATETIME     DEFAULT NULL,
  `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`         DATETIME     DEFAULT NULL,
  `created_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default users (password = P@ssw0rd untuk semua)
-- role_id: 1=super_admin, 2=ketua, 3=bendahara, 4=warga
INSERT INTO `users` (`role_id`,`name`,`username`,`email`,`password`,`email_verified_at`) VALUES
(1, 'Super Administrator', 'superadmin', 'admin@aryagreenpamulang.my.id',
 '$2y$12$KBlsNPjTdH35lmxPkbhn..nl8LSF1UwPcHer.WsGRiEQkhKe8QY6G', NOW()),
(2, 'Ketua RT', 'ketua', 'ketua@aryagreenpamulang.my.id',
 '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW()),
(3, 'Bendahara RT', 'bendahara', 'bendahara@aryagreenpamulang.my.id',
 '$2y$12$JLqR9KocGwbqqyeS.1aAkOWk.odIIJLzSO2kIjRvtYclldC16Ogle', NOW()),
(4, 'Warga Contoh', 'warga', 'warga@aryagreenpamulang.my.id',
 '$2y$12$Q5GPXvq3oaYH51p4LNvA1e68eHndNdYqKL4gQ6oy.LtJmIXAWzCTS', NOW());

-- ------------------------------------------------------------
-- Unit Types
-- ------------------------------------------------------------
CREATE TABLE `unit_types` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ipl_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `unit_types` (`name`, `description`, `ipl_amount`) VALUES
  ('Ruko',               'Rumah Toko',                  300000.00),
  ('Rumah Type Navulia', 'Rumah hunian type Navulia',   200000.00),
  ('Rumah Type Fresia',  'Rumah hunian type Fresia',    200000.00),
  ('Rumah Type Magnolia','Rumah hunian type Magnolia',  200000.00),
  ('Rumah Type Cattleya','Rumah hunian type Cattleya',  200000.00);

-- ------------------------------------------------------------
-- Units (Hunian)
-- ------------------------------------------------------------
CREATE TABLE `units` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `unit_type_id` INT UNSIGNED NOT NULL,
  `unit_number`  VARCHAR(20)  NOT NULL UNIQUE,
  `block`        VARCHAR(10)  NOT NULL,
  `floor`        TINYINT      NOT NULL DEFAULT 1,
  `area_sqm`     DECIMAL(8,2) NOT NULL DEFAULT 0,
  `status`       ENUM('dihuni','kosong','dijual') NOT NULL DEFAULT 'kosong',
  `notes`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`unit_type_id`) REFERENCES `unit_types`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed units dari siteplan e-Praya 8 (97 unit)
INSERT IGNORE INTO `units` (`unit_type_id`,`unit_number`,`block`,`floor`,`area_sqm`,`status`,`notes`)
SELECT ut.id,s.u,s.b,1,s.a,'kosong','siteplan' FROM (SELECT id,name FROM unit_types) ut JOIN (
  SELECT 'Ruko' t,'RUKO-1' u,'RUKO' b,75.0 a UNION ALL SELECT 'Ruko','RUKO-2','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-3','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-5','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-6','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-7','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-8','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-9','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-10','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-11','RUKO',85.0 UNION ALL
  SELECT 'Ruko','RUKO-12','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-14','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-15','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-16','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-17','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-18','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-19','RUKO',75.0 UNION ALL SELECT 'Ruko','RUKO-20','RUKO',75.0 UNION ALL
  SELECT 'Ruko','RUKO-21','RUKO',0.0 UNION ALL
  SELECT 'Rumah Type Fresia','A1','A',94.0 UNION ALL SELECT 'Rumah Type Fresia','A2','A',91.0 UNION ALL
  SELECT 'Rumah Type Fresia','A3','A',107.0 UNION ALL SELECT 'Rumah Type Fresia','A5','A',135.0 UNION ALL
  SELECT 'Rumah Type Navulia','A6','A',62.0 UNION ALL SELECT 'Rumah Type Navulia','A7','A',63.0 UNION ALL
  SELECT 'Rumah Type Navulia','A8','A',63.0 UNION ALL SELECT 'Rumah Type Navulia','A9','A',64.0 UNION ALL
  SELECT 'Rumah Type Navulia','A10','A',65.0 UNION ALL SELECT 'Rumah Type Navulia','A11','A',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','A12','A',0.0 UNION ALL SELECT 'Rumah Type Navulia','A13','A',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','A14','A',142.0 UNION ALL SELECT 'Rumah Type Navulia','A15','A',142.0 UNION ALL
  SELECT 'Rumah Type Navulia','A17','A',0.0 UNION ALL SELECT 'Rumah Type Navulia','A18','A',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','A19','A',124.0 UNION ALL SELECT 'Rumah Type Navulia','A20','A',108.0 UNION ALL
  SELECT 'Rumah Type Navulia','C1','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C2','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C3','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C5','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C6','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C7','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C8','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C9','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C10','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C11','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C12','C',90.0 UNION ALL SELECT 'Rumah Type Navulia','C14','C',159.0 UNION ALL
  SELECT 'Rumah Type Navulia','C15','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C16','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C17','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C18','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C19','C',60.0 UNION ALL SELECT 'Rumah Type Navulia','C20','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','C21','C',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','D8','D',60.0 UNION ALL SELECT 'Rumah Type Navulia','D9','D',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','D10','D',60.0 UNION ALL SELECT 'Rumah Type Navulia','D11','D',118.0 UNION ALL
  SELECT 'Rumah Type Navulia','D12','D',127.0 UNION ALL SELECT 'Rumah Type Navulia','D14','D',60.0 UNION ALL
  SELECT 'Rumah Type Navulia','G1','G',141.0 UNION ALL SELECT 'Rumah Type Navulia','G2','G',63.0 UNION ALL
  SELECT 'Rumah Type Navulia','G3','G',62.0 UNION ALL SELECT 'Rumah Type Navulia','G4','G',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','G5','G',61.0 UNION ALL SELECT 'Rumah Type Navulia','G6','G',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','G7','G',60.0 UNION ALL SELECT 'Rumah Type Navulia','G8','G',96.0 UNION ALL
  SELECT 'Rumah Type Navulia','G9','G',106.0 UNION ALL SELECT 'Rumah Type Navulia','G10','G',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','G11','G',75.0 UNION ALL SELECT 'Rumah Type Navulia','G12','G',0.0 UNION ALL
  SELECT 'Rumah Type Navulia','G13','G',0.0 UNION ALL SELECT 'Rumah Type Navulia','G14','G',77.0 UNION ALL
  SELECT 'Rumah Type Navulia','G15','G',77.0 UNION ALL SELECT 'Rumah Type Navulia','G16','G',134.0 UNION ALL
  SELECT 'Rumah Type Fresia','H1','H',111.0 UNION ALL SELECT 'Rumah Type Fresia','H4','H',76.0 UNION ALL
  SELECT 'Rumah Type Fresia','H5','H',75.0 UNION ALL SELECT 'Rumah Type Fresia','H6','H',75.0 UNION ALL
  SELECT 'Rumah Type Fresia','H7','H',74.0 UNION ALL SELECT 'Rumah Type Fresia','H8','H',73.0 UNION ALL
  SELECT 'Rumah Type Fresia','H9','H',72.0 UNION ALL SELECT 'Rumah Type Fresia','H10','H',66.0 UNION ALL
  SELECT 'Rumah Type Cattleya','H11','H',85.0 UNION ALL SELECT 'Rumah Type Cattleya','H12','H',60.0 UNION ALL
  SELECT 'Rumah Type Cattleya','H13','H',61.0 UNION ALL SELECT 'Rumah Type Cattleya','H14','H',61.0 UNION ALL
  SELECT 'Rumah Type Cattleya','H21','H',66.0 UNION ALL SELECT 'Rumah Type Cattleya','H22','H',106.0 UNION ALL
  SELECT 'Rumah Type Cattleya','I1','I',115.0 UNION ALL SELECT 'Rumah Type Cattleya','I8','I',60.0 UNION ALL
  SELECT 'Rumah Type Cattleya','I9','I',60.0 UNION ALL SELECT 'Rumah Type Cattleya','I10','I',60.0 UNION ALL
  SELECT 'Rumah Type Cattleya','I11','I',82.0
) s ON ut.name=s.t;

-- ------------------------------------------------------------
-- Residents (Warga)
-- ------------------------------------------------------------
CREATE TABLE `residents` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED DEFAULT NULL,
  `unit_id`       INT UNSIGNED NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `id_card_number`VARCHAR(20)  DEFAULT NULL,
  `phone`         VARCHAR(20)  NOT NULL,
  `email`         VARCHAR(150) DEFAULT NULL,
  `status`        ENUM('pemilik','penyewa') NOT NULL DEFAULT 'pemilik',
  `move_in_date`  DATE         DEFAULT NULL,
  `move_out_date` DATE         DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`unit_id`)  REFERENCES `units`(`id`)  ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Billing Periods
-- ------------------------------------------------------------
CREATE TABLE `billing_periods` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `period_year` SMALLINT  NOT NULL,
  `period_month`TINYINT   NOT NULL,
  `label`       VARCHAR(50) NOT NULL,
  `due_date`    DATE      NOT NULL,
  `is_closed`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_period` (`period_year`, `period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- IPL Components (master: nama + nominal global)
-- charge_when_vacant=1: komponen dasar tetap ditagih saat unit kosong
-- charge_when_vacant=0: hanya ditagih jika unit dihuni
-- ------------------------------------------------------------
CREATE TABLE `ipl_components` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`               VARCHAR(100) NOT NULL,
  `amount`             DECIMAL(12,2) NOT NULL DEFAULT 0,
  `charge_when_vacant` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`         INT NOT NULL DEFAULT 0,
  `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ipl_components` (`name`,`amount`,`charge_when_vacant`,`sort_order`) VALUES
  ('Iuran Dasar Ruko',        250000.00, 1, 1),
  ('Iuran Dasar Rumah',       150000.00, 1, 2),
  ('Iuran Sampah',             50000.00, 0, 3);

-- ------------------------------------------------------------
-- Unit Type Components (komponen mana yang berlaku per tipe unit)
-- Nominal IPL/bulan tipe unit = SUM(ipl_components.amount) yang di-assign
-- ------------------------------------------------------------
CREATE TABLE `unit_type_components` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `unit_type_id` INT UNSIGNED NOT NULL,
  `component_id` INT UNSIGNED NOT NULL,
  UNIQUE KEY `uq_type_component` (`unit_type_id`, `component_id`),
  FOREIGN KEY (`unit_type_id`) REFERENCES `unit_types`(`id`)      ON DELETE CASCADE,
  FOREIGN KEY (`component_id`) REFERENCES `ipl_components`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Ruko → Dasar Ruko + Sampah; Rumah → Dasar Rumah + Sampah
INSERT IGNORE INTO `unit_type_components` (`unit_type_id`,`component_id`)
SELECT ut.id, c.id FROM `unit_types` ut
JOIN `ipl_components` c ON c.name IN ('Iuran Dasar Ruko','Iuran Sampah')
WHERE ut.name = 'Ruko';

INSERT IGNORE INTO `unit_type_components` (`unit_type_id`,`component_id`)
SELECT ut.id, c.id FROM `unit_types` ut
JOIN `ipl_components` c ON c.name IN ('Iuran Dasar Rumah','Iuran Sampah')
WHERE ut.name != 'Ruko';

-- Sinkronkan IPL/bulan tipe unit = total komponen yang di-assign
UPDATE `unit_types` ut
SET `ipl_amount` = (
  SELECT COALESCE(SUM(c.amount),0)
  FROM `unit_type_components` utc
  JOIN `ipl_components` c ON c.id = utc.component_id
  WHERE utc.unit_type_id = ut.id
);

-- ------------------------------------------------------------
-- Bills (Tagihan)
-- ------------------------------------------------------------
CREATE TABLE `bills` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `billing_period_id` INT UNSIGNED NOT NULL,
  `unit_id`           INT UNSIGNED NOT NULL,
  `resident_id`       INT UNSIGNED DEFAULT NULL,
  `amount`            DECIMAL(12,2) NOT NULL,
  `fine_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total_amount`      DECIMAL(12,2) NOT NULL,
  `status`            ENUM('belum_bayar','sudah_bayar','terlambat') NOT NULL DEFAULT 'belum_bayar',
  `due_date`          DATE NOT NULL,
  `paid_date`         DATE DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_bill` (`billing_period_id`, `unit_id`),
  FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods`(`id`) ON UPDATE CASCADE,
  FOREIGN KEY (`unit_id`)           REFERENCES `units`(`id`)           ON UPDATE CASCADE,
  FOREIGN KEY (`resident_id`)       REFERENCES `residents`(`id`)       ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Bill Components (Breakdown Komponen per Tagihan)
-- ------------------------------------------------------------
CREATE TABLE `bill_components` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bill_id`        INT UNSIGNED NOT NULL,
  `component_id`   INT UNSIGNED NOT NULL,
  `component_name` VARCHAR(100) NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`bill_id`)      REFERENCES `bills`(`id`)          ON DELETE CASCADE,
  FOREIGN KEY (`component_id`) REFERENCES `ipl_components`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Payment Methods (Master)
-- ------------------------------------------------------------
CREATE TABLE `payment_methods` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`         VARCHAR(30)  NOT NULL UNIQUE,
  `name`         VARCHAR(100) NOT NULL,
  `account_no`   VARCHAR(50)  DEFAULT NULL,
  `account_name` VARCHAR(100) DEFAULT NULL,
  `qr_image`     VARCHAR(255) DEFAULT NULL,
  `instructions` TEXT DEFAULT NULL,
  `auto_verify`  TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payment_methods` (`code`, `name`, `auto_verify`, `sort_order`) VALUES
  ('tunai',    'Tunai',          1, 1),
  ('transfer', 'Transfer Bank',  0, 2),
  ('qris',     'QRIS',           0, 3),
  ('lainnya',  'Lainnya',        0, 4);

-- ------------------------------------------------------------
-- Payments
-- ------------------------------------------------------------
CREATE TABLE `payments` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bill_id`        INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `payment_date`   DATE         NOT NULL,
  `amount_paid`    DECIMAL(12,2) NOT NULL,
  `payment_method_id` INT UNSIGNED DEFAULT NULL,
  `payment_method` VARCHAR(50)  NOT NULL DEFAULT 'tunai',
  `bank_name`      VARCHAR(50)  DEFAULT NULL,
  `reference_no`   VARCHAR(100) DEFAULT NULL,
  `proof_file`     VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verified_by`    INT UNSIGNED DEFAULT NULL,
  `verified_at`    DATETIME     DEFAULT NULL,
  `notes`          TEXT DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bill_id`)     REFERENCES `bills`(`id`)  ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Activity Log
-- ------------------------------------------------------------
CREATE TABLE `activity_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `module`     VARCHAR(50)  NOT NULL,
  `description`TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Email Logs
-- ------------------------------------------------------------
CREATE TABLE `email_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`       VARCHAR(50)  NOT NULL,
  `to_email`   VARCHAR(150) NOT NULL,
  `to_name`    VARCHAR(150) DEFAULT NULL,
  `subject`    VARCHAR(255) NOT NULL,
  `ref_id`     INT UNSIGNED DEFAULT NULL,
  `status`     ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email_logs_type`       (`type`),
  INDEX `idx_email_logs_ref_id`     (`ref_id`),
  INDEX `idx_email_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Cash Book (Buku Kas)
-- ------------------------------------------------------------
CREATE TABLE `cash_book` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`           ENUM('pemasukan','pengeluaran') NOT NULL,
  `category`       VARCHAR(100) NOT NULL,
  `amount`         DECIMAL(14,2) NOT NULL,
  `description`    TEXT DEFAULT NULL,
  `trx_date`       DATE NOT NULL,
  `proof_file`     VARCHAR(255) DEFAULT NULL,
  `ref_payment_id` INT UNSIGNED DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`ref_payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_cashbook_trx_date` (`trx_date`),
  INDEX `idx_cashbook_type`     (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Indexes ──────────────────────────────────────────────────────────────────
ALTER TABLE `bills`
  ADD INDEX `idx_bills_status`   (`status`),
  ADD INDEX `idx_bills_due_date` (`due_date`);

ALTER TABLE `payments`
  ADD INDEX `idx_payments_status` (`status`);

ALTER TABLE `activity_logs`
  ADD INDEX `idx_activity_logs_created_at` (`created_at`);

COMMIT;

-- ------------------------------------------------------------
-- 1. Approval pengeluaran
-- ------------------------------------------------------------
CREATE TABLE `expense_requests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category`       VARCHAR(100) NOT NULL,
  `amount`         DECIMAL(14,2) NOT NULL,
  `description`    TEXT NOT NULL,
  `trx_date`       DATE NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by`   INT UNSIGNED DEFAULT NULL,
  `reviewed_by`    INT UNSIGNED DEFAULT NULL,
  `reviewed_at`    DATETIME DEFAULT NULL,
  `review_note`    TEXT DEFAULT NULL,
  `cash_book_id`   INT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`requested_by`)   REFERENCES `users`(`id`)        ON DELETE SET NULL,
  FOREIGN KEY (`reviewed_by`)    REFERENCES `users`(`id`)        ON DELETE SET NULL,
  FOREIGN KEY (`cash_book_id`)   REFERENCES `cash_book`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Aduan warga
-- ------------------------------------------------------------
CREATE TABLE `complaints` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `resident_id` INT UNSIGNED DEFAULT NULL,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `category`    VARCHAR(50)  NOT NULL DEFAULT 'umum',
  `priority`    ENUM('rendah','sedang','tinggi') NOT NULL DEFAULT 'sedang',
  `status`      ENUM('baru','diproses','selesai','ditutup') NOT NULL DEFAULT 'baru',
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `photo_file`  VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`)     ON DELETE SET NULL,
  INDEX `idx_complaints_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `complaint_replies` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `complaint_id` INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED DEFAULT NULL,
  `message`      TEXT NOT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`complaint_id`) REFERENCES `complaints`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Polling / surat suara
-- ------------------------------------------------------------
CREATE TABLE `polls` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `starts_at`   DATETIME NOT NULL,
  `ends_at`     DATETIME NOT NULL,
  `is_public`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poll_options` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `label`   VARCHAR(200) NOT NULL,
  `sort`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poll_votes` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id`        INT UNSIGNED NOT NULL,
  `poll_option_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `resident_id`    INT UNSIGNED DEFAULT NULL,
  `voted_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_vote` (`poll_id`, `user_id`),
  FOREIGN KEY (`poll_id`)        REFERENCES `polls`(`id`)        ON DELETE CASCADE,
  FOREIGN KEY (`poll_option_id`) REFERENCES `poll_options`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)        REFERENCES `users`(`id`)        ON DELETE SET NULL,
  FOREIGN KEY (`resident_id`)    REFERENCES `residents`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Kegiatan & absensi
-- ------------------------------------------------------------
CREATE TABLE `events` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type`        ENUM('rapat','kerja_bakti','sosial','lainnya') NOT NULL DEFAULT 'lainnya',
  `event_date`  DATETIME NOT NULL,
  `location`    VARCHAR(200) DEFAULT NULL,
  `is_mandatory`TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_attendances` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`    INT UNSIGNED NOT NULL,
  `resident_id` INT UNSIGNED NOT NULL,
  `status`      ENUM('hadir','tidak_hadir','izin') NOT NULL DEFAULT 'hadir',
  `selfie_file` VARCHAR(255) DEFAULT NULL,
  `checked_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `checked_by`  INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY `uq_attendance` (`event_id`, `resident_id`),
  FOREIGN KEY (`event_id`)    REFERENCES `events`(`id`)    ON DELETE CASCADE,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`checked_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Inventaris aset
-- ------------------------------------------------------------
CREATE TABLE `inventory` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(200) NOT NULL,
  `category`     VARCHAR(100) NOT NULL DEFAULT 'umum',
  `quantity`     INT NOT NULL DEFAULT 1,
  `unit`         VARCHAR(30) DEFAULT NULL,
  `condition`    ENUM('baik','rusak_ringan','rusak_berat','tidak_ada') NOT NULL DEFAULT 'baik',
  `location`     VARCHAR(200) DEFAULT NULL,
  `purchase_date`DATE DEFAULT NULL,
  `purchase_price`DECIMAL(14,2) DEFAULT NULL,
  `notes`        TEXT DEFAULT NULL,
  `photo_file`   VARCHAR(255) DEFAULT NULL,
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Surat RT
-- ------------------------------------------------------------
CREATE TABLE `letters` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`        VARCHAR(50)  NOT NULL DEFAULT 'pengantar',
  `number`      VARCHAR(50)  DEFAULT NULL,
  `resident_id` INT UNSIGNED DEFAULT NULL,
  `purpose`     VARCHAR(200) NOT NULL,
  `body`        TEXT NOT NULL,
  `issued_date` DATE NOT NULL,
  `issued_by`   INT UNSIGNED DEFAULT NULL,
  `pdf_file`    VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`issued_by`)   REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Multi lingkungan (RT/RW)
-- ------------------------------------------------------------
CREATE TABLE `environments` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `code`        VARCHAR(20)  NOT NULL UNIQUE,
  `address`     TEXT DEFAULT NULL,
  `rt`          VARCHAR(10) DEFAULT NULL,
  `rw`          VARCHAR(10) DEFAULT NULL,
  `kelurahan`   VARCHAR(100) DEFAULT NULL,
  `kecamatan`   VARCHAR(100) DEFAULT NULL,
  `city`        VARCHAR(100) DEFAULT NULL,
  `custom_domain` VARCHAR(100) DEFAULT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `environments` (`name`, `code`, `rt`, `rw`, `kelurahan`, `kecamatan`, `city`) VALUES
  ('Arya Green Pamulang', 'arya-green', '001', '010', 'Pamulang Barat', 'Pamulang', 'Tangerang Selatan');

-- Tambah env_id ke users (null = global/super)
ALTER TABLE `users`
  ADD COLUMN `env_id` INT UNSIGNED DEFAULT NULL AFTER `role_id`,
  ADD FOREIGN KEY (`env_id`) REFERENCES `environments`(`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 10. Rate Limiting (Login Attempts)
-- ------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address`   VARCHAR(45) NOT NULL,
  `username`     VARCHAR(100) NOT NULL,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_success`   TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_login_attempts_check` (`ip_address`, `username`, `attempted_at`),
  INDEX `idx_login_attempts_cleanup` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
