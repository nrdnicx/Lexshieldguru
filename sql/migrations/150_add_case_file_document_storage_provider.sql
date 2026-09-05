ALTER TABLE `case_file_documents`
  ADD COLUMN IF NOT EXISTS `storage_provider` VARCHAR(30) NOT NULL DEFAULT 'local' AFTER `stored_name`,
  ADD COLUMN IF NOT EXISTS `storage_path` VARCHAR(500) DEFAULT NULL AFTER `storage_provider`;

CREATE INDEX `idx_case_file_documents_storage`
  ON `case_file_documents` (`storage_provider`, `storage_path`);
