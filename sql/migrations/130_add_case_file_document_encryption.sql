ALTER TABLE `case_file_documents`
  ADD COLUMN IF NOT EXISTS `encryption_algorithm` VARCHAR(40) DEFAULT NULL AFTER `file_hash`,
  ADD COLUMN IF NOT EXISTS `encryption_iv` VARCHAR(64) DEFAULT NULL AFTER `encryption_algorithm`,
  ADD COLUMN IF NOT EXISTS `encryption_tag` VARCHAR(64) DEFAULT NULL AFTER `encryption_iv`,
  ADD COLUMN IF NOT EXISTS `encrypted_at` DATETIME DEFAULT NULL AFTER `encryption_tag`;
