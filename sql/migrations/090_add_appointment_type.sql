ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `appointment_type` VARCHAR(120) NOT NULL DEFAULT 'Client Intake Consultation' AFTER `scheduled_at`;
