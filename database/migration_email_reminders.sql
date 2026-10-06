-- Migration: Email Reminder & Notification Log
-- Run: mysql -u root -p db_arya_green_ipl < database/migration_email_reminders.sql

CREATE TABLE IF NOT EXISTS `email_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`       VARCHAR(50)  NOT NULL COMMENT 'reminder_due|reminder_overdue|payment_verified|payment_rejected|payment_received',
  `to_email`   VARCHAR(150) NOT NULL,
  `to_name`    VARCHAR(150) DEFAULT NULL,
  `subject`    VARCHAR(255) NOT NULL,
  `ref_id`     INT UNSIGNED DEFAULT NULL COMMENT 'bill_id or payment_id depending on type',
  `status`     ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email_logs_type`       (`type`),
  INDEX `idx_email_logs_ref_id`     (`ref_id`),
  INDEX `idx_email_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission untuk admin kirim reminder manual
INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES
  ('billing.send_reminder', 'Kirim Reminder Email', 'billing');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.name IN ('super_admin','admin') AND p.name = 'billing.send_reminder';
