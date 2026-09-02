CREATE TABLE IF NOT EXISTS `lawyer_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyer_reviews_pair` (`lawyer_id`, `client_id`),
  KEY `idx_lawyer_reviews_lawyer` (`lawyer_id`),
  KEY `idx_lawyer_reviews_client` (`client_id`),
  CONSTRAINT `fk_lawyer_reviews_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lawyer_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
