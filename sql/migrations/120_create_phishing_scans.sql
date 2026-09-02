CREATE TABLE IF NOT EXISTS `phishing_scans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `submitted_url` TEXT NOT NULL,
  `final_url` TEXT DEFAULT NULL,
  `status` ENUM('suspicious','phishing') NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `findings_json` JSON DEFAULT NULL,
  `redirect_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phishing_scans_status_date` (`status`, `created_at`),
  KEY `idx_phishing_scans_user_date` (`user_id`, `created_at`),
  CONSTRAINT `fk_phishing_scans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
