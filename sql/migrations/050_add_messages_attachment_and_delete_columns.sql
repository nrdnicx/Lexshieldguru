ALTER TABLE `messages`
  ADD COLUMN IF NOT EXISTS `attachment_original_name` VARCHAR(255) DEFAULT NULL AFTER `message_text`,
  ADD COLUMN IF NOT EXISTS `attachment_stored_name` VARCHAR(255) DEFAULT NULL AFTER `attachment_original_name`,
  ADD COLUMN IF NOT EXISTS `attachment_mime_type` VARCHAR(120) DEFAULT NULL AFTER `attachment_stored_name`,
  ADD COLUMN IF NOT EXISTS `attachment_size` INT UNSIGNED DEFAULT NULL AFTER `attachment_mime_type`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`,
  ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_deleted`;
