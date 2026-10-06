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
  ('admin',       'Admin'),
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
  ('billing.delete',        'Hapus Tagihan',               'billing'),
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
  -- Roles
  ('roles.view',            'Lihat Roles',                 'roles'),
  ('roles.manage',          'Kelola Roles & Permissions',  'roles');

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

-- Admin: all except roles.manage and users.delete
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

-- ── Indexes ──────────────────────────────────────────────────────────────────
ALTER TABLE `bills`
  ADD INDEX `idx_bills_status`   (`status`),
  ADD INDEX `idx_bills_due_date` (`due_date`);

ALTER TABLE `payments`
  ADD INDEX `idx_payments_status` (`status`);

ALTER TABLE `activity_logs`
  ADD INDEX `idx_activity_logs_created_at` (`created_at`);

COMMIT;
