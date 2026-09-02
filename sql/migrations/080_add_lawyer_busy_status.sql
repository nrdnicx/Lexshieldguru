ALTER TABLE `lawyers`
  MODIFY COLUMN `status` ENUM('active','busy','suspended') NOT NULL DEFAULT 'active';
