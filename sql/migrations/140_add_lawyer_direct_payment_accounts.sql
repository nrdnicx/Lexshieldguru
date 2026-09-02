ALTER TABLE `lawyers`
  ADD COLUMN IF NOT EXISTS `gcash_account_name` VARCHAR(150) DEFAULT NULL AFTER `contact_number`,
  ADD COLUMN IF NOT EXISTS `gcash_number` VARCHAR(40) DEFAULT NULL AFTER `gcash_account_name`,
  ADD COLUMN IF NOT EXISTS `gcash_qr_stored_name` VARCHAR(255) DEFAULT NULL AFTER `gcash_number`;

ALTER TABLE `manual_payments`
  ADD COLUMN IF NOT EXISTS `lawyer_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`;

ALTER TABLE `manual_payments`
  ADD KEY IF NOT EXISTS `idx_manual_payments_lawyer` (`lawyer_id`, `status`, `created_at`);

ALTER TABLE `manual_payments`
  ADD CONSTRAINT `fk_manual_payments_lawyer`
  FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE SET NULL;
