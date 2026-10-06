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
  ('petugas',     'Petugas'),
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
  ('dashboard.view',        'Lihat Dashboard',             'dashboard'),
  -- Units
  ('units.view',            'Lihat Data Unit',             'units'),
  ('units.create',          'Tambah Unit',                 'units'),
  ('units.edit',            'Edit Unit',                   'units'),
  ('units.delete',          'Hapus Unit',                  'units'),
  -- Residents
  ('residents.view',        'Lihat Data Warga',            'residents'),
  ('residents.create',      'Tambah Warga',                'residents'),
  ('residents.edit',        'Edit Warga',                  'residents'),
  ('residents.delete',      'Hapus Warga',                 'residents'),
  -- Billing
  ('billing.view',          'Lihat Tagihan',               'billing'),
  ('billing.generate',      'Generate Tagihan',            'billing'),
  ('billing.edit',          'Edit Tagihan',                'billing'),
  -- Payments
  ('payments.view',         'Lihat Pembayaran',            'payments'),
  ('payments.create',       'Catat Pembayaran',            'payments'),
  ('payments.verify',       'Verifikasi Pembayaran',       'payments'),
  -- Reports
  ('reports.view',          'Lihat Laporan',               'reports'),
  -- Users
  ('users.view',            'Lihat Data User',             'users'),
  ('users.create',          'Tambah User',                 'users'),
  ('users.edit',            'Edit User',                   'users'),
  ('users.delete',          'Hapus User',                  'users'),
  -- Cashbook
  ('cashbook.view',          'Lihat Buku Kas',              'cashbook'),
  ('cashbook.manage',        'Kelola Buku Kas',             'cashbook'),
  -- Roles
  ('roles.view',            'Lihat Roles',                 'roles'),
  ('roles.manage',          'Kelola Roles & Permissions',  'roles'),
  -- Billing extra
  ('billing.send_reminder', 'Kirim Reminder Email',        'billing');

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

-- Super Admin gets everything
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Ketua: all except roles.manage and users.delete
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions`
WHERE `name` NOT IN ('roles.manage','users.delete');

-- Petugas: dashboard, view units/residents, billing, payments (no delete)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions`
WHERE `name` IN (
  'dashboard.view','units.view','residents.view',
  'billing.view','billing.generate',
  'payments.view','payments.create','payments.verify',
  'reports.view'
);

-- Warga: dashboard, own billing & payments only
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions`
WHERE `name` IN ('dashboard.view','billing.view','payments.view','payments.create');

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

-- Default super admin: password = Admin@1234
INSERT INTO `users` (`role_id`,`name`,`username`,`email`,`password`,`email_verified_at`) VALUES
(1, 'Super Administrator', 'superadmin', 'admin@aryagreen.id',
 '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uHV/WiduW', NOW());

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
  ('Tipe 36',  'Hunian tipe 36 m²',   250000.00),
  ('Tipe 45',  'Hunian tipe 45 m²',   300000.00),
  ('Tipe 54',  'Hunian tipe 54 m²',   350000.00),
  ('Tipe 72',  'Hunian tipe 72 m²',   400000.00),
  ('Tipe 90',  'Hunian tipe 90 m²',   500000.00),
  ('Ruko',     'Rumah Toko',          750000.00);

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
-- Payments
-- ------------------------------------------------------------
CREATE TABLE `payments` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bill_id`        INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `payment_date`   DATE         NOT NULL,
  `amount_paid`    DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('tunai','transfer','qris','lainnya') NOT NULL DEFAULT 'tunai',
  `bank_name`      VARCHAR(50)  DEFAULT NULL,
  `reference_no`   VARCHAR(100) DEFAULT NULL,
  `proof_file`     VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verified_by`    INT UNSIGNED DEFAULT NULL,
  `verified_at`    DATETIME     DEFAULT NULL,
  `notes`          TEXT DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bill_id`)     REFERENCES `bills`(`id`)  ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE
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
  `ref_payment_id` INT UNSIGNED DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`ref_payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_cashbook_trx_date` (`trx_date`),
  INDEX `idx_cashbook_type`     (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Role Permissions: tambahan cashbook & billing.send_reminder
-- ------------------------------------------------------------
-- super_admin & ketua: cashbook full + send_reminder
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.name IN ('super_admin','ketua')
  AND p.name IN ('cashbook.view','cashbook.manage','billing.send_reminder');

-- petugas: cashbook view only
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.name = 'petugas' AND p.name = 'cashbook.view';

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
-- 1. Sub-kas (multi rekening)
-- ------------------------------------------------------------
CREATE TABLE `kas_accounts` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `balance`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `kas_accounts` (`name`, `description`, `is_default`) VALUES
  ('Kas Utama', 'Rekening kas utama RT', 1),
  ('Kas Sosial', 'Dana sosial warga', 0);

-- Tambah kolom kas_account_id ke cash_book
ALTER TABLE `cash_book`
  ADD COLUMN `kas_account_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD FOREIGN KEY (`kas_account_id`) REFERENCES `kas_accounts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ------------------------------------------------------------
-- 2. Approval pengeluaran
-- ------------------------------------------------------------
CREATE TABLE `expense_requests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `kas_account_id` INT UNSIGNED DEFAULT NULL,
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
  FOREIGN KEY (`kas_account_id`) REFERENCES `kas_accounts`(`id`) ON DELETE SET NULL,
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
-- 9. Permissions baru
-- ------------------------------------------------------------
INSERT INTO `permissions` (`name`, `label`, `module`) VALUES
  -- Sub-kas
  ('kas.view',             'Lihat Sub-Kas',              'kas'),
  ('kas.manage',           'Kelola Sub-Kas',             'kas'),
  -- Approval pengeluaran
  ('expense.request',      'Ajukan Pengeluaran',         'expense'),
  ('expense.approve',      'Setujui/Tolak Pengeluaran',  'expense'),
  -- Aduan
  ('complaints.view',      'Lihat Aduan',                'complaints'),
  ('complaints.manage',    'Kelola Aduan',               'complaints'),
  -- Polling
  ('polls.view',           'Lihat Polling',              'polls'),
  ('polls.manage',         'Kelola Polling',             'polls'),
  ('polls.vote',           'Vote Polling',               'polls'),
  -- Kegiatan
  ('events.view',          'Lihat Kegiatan',             'events'),
  ('events.manage',        'Kelola Kegiatan',            'events'),
  ('events.attendance',    'Kelola Absensi',             'events'),
  -- Inventaris
  ('inventory.view',       'Lihat Inventaris',           'inventory'),
  ('inventory.manage',     'Kelola Inventaris',          'inventory'),
  -- Surat
  ('letters.view',         'Lihat Surat RT',             'letters'),
  ('letters.manage',       'Buat/Cetak Surat RT',        'letters'),
  -- Multi-env
  ('environments.manage',  'Kelola Multi Lingkungan',    'environments');

-- Super admin: semua permission baru
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`
WHERE `name` IN (
  'kas.view','kas.manage','expense.request','expense.approve',
  'complaints.view','complaints.manage',
  'polls.view','polls.manage','polls.vote',
  'events.view','events.manage','events.attendance',
  'inventory.view','inventory.manage',
  'letters.view','letters.manage','environments.manage'
);

-- Ketua: semua kecuali environments.manage
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions`
WHERE `name` IN (
  'kas.view','kas.manage','expense.request','expense.approve',
  'complaints.view','complaints.manage',
  'polls.view','polls.manage','polls.vote',
  'events.view','events.manage','events.attendance',
  'inventory.view','inventory.manage',
  'letters.view','letters.manage'
);

-- Petugas: view + request pengeluaran + absensi + complaints.view
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM `permissions`
WHERE `name` IN (
  'kas.view','expense.request',
  'complaints.view','polls.view','polls.vote',
  'events.view','events.attendance',
  'inventory.view','letters.view'
);

-- Warga: view publik + vote + aduan + absensi
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM `permissions`
WHERE `name` IN (
  'complaints.view','polls.view','polls.vote','events.view'
);

COMMIT;
