CREATE TABLE IF NOT EXISTS `lawyer_availability` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `is_available` TINYINT(1) NOT NULL DEFAULT 0,
  `morning_start` TIME DEFAULT NULL,
  `morning_end` TIME DEFAULT NULL,
  `afternoon_start` TIME DEFAULT NULL,
  `afternoon_end` TIME DEFAULT NULL,
  `morning_capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `afternoon_capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyer_availability_day` (`lawyer_id`, `day_of_week`),
  KEY `idx_lawyer_availability_lawyer` (`lawyer_id`),
  CONSTRAINT `fk_lawyer_availability_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lawyer_unavailable_dates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `unavailable_date` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyer_unavailable_date` (`lawyer_id`, `unavailable_date`),
  KEY `idx_lawyer_unavailable_date` (`lawyer_id`, `unavailable_date`),
  CONSTRAINT `fk_lawyer_unavailable_dates_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
