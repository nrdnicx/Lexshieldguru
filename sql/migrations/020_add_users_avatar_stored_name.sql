ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `avatar_stored_name` VARCHAR(255) DEFAULT NULL AFTER `last_login`;
