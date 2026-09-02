ALTER TABLE `lawyers`
  ADD COLUMN IF NOT EXISTS `contact_number` VARCHAR(40) DEFAULT NULL AFTER `background`;
