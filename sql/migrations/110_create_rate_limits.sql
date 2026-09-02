CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(64) NOT NULL,
  `identifier_hash` CHAR(64) NOT NULL,
  `identifier_label` VARCHAR(190) DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `blocked_until` DATETIME DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_limits_action_identifier` (`action`, `identifier_hash`),
  KEY `idx_rate_limits_expires` (`expires_at`),
  KEY `idx_rate_limits_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
