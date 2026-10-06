-- ============================================================
-- Arya Green IPL — Schema v2 (fitur tambahan)
-- Jalankan setelah schema.sql
-- ============================================================

USE `db_arya_green_ipl`;

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
-- 3. WhatsApp reminder outbox
-- ------------------------------------------------------------
CREATE TABLE `wa_messages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `phone`       VARCHAR(20)  NOT NULL,
  `resident_id` INT UNSIGNED DEFAULT NULL,
  `bill_id`     INT UNSIGNED DEFAULT NULL,
  `message`     TEXT NOT NULL,
  `status`      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `sent_at`     DATETIME DEFAULT NULL,
  `error_msg`   VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bill_id`)     REFERENCES `bills`(`id`)     ON DELETE SET NULL,
  INDEX `idx_wa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Aduan warga
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
-- 5. Polling / surat suara
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
-- 6. Kegiatan & absensi
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
-- 7. Inventaris aset
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
-- 8. Surat RT
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
-- 9. Multi lingkungan (RT/RW)
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
-- 10. Permissions baru
-- ------------------------------------------------------------
INSERT INTO `permissions` (`name`, `label`, `module`) VALUES
  -- Sub-kas
  ('kas.view',             'Lihat Sub-Kas',              'kas'),
  ('kas.manage',           'Kelola Sub-Kas',             'kas'),
  -- Approval pengeluaran
  ('expense.request',      'Ajukan Pengeluaran',         'expense'),
  ('expense.approve',      'Setujui/Tolak Pengeluaran',  'expense'),
  -- WhatsApp
  ('wa.send',              'Kirim Reminder WA',          'wa'),
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
  'wa.send','complaints.view','complaints.manage',
  'polls.view','polls.manage','polls.vote',
  'events.view','events.manage','events.attendance',
  'inventory.view','inventory.manage',
  'letters.view','letters.manage','environments.manage'
);

-- Admin: semua kecuali environments.manage
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions`
WHERE `name` IN (
  'kas.view','kas.manage','expense.request','expense.approve',
  'wa.send','complaints.view','complaints.manage',
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
