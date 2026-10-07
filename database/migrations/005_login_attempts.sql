-- ============================================================
-- Migration: 005_login_attempts.sql
-- Rate Limiting: Brute Force & Password Spraying Protection
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address`   VARCHAR(45) NOT NULL,
  `username`     VARCHAR(100) NOT NULL,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_success`   TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_login_attempts_check` (`ip_address`, `username`, `attempted_at`),
  INDEX `idx_login_attempts_cleanup` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
