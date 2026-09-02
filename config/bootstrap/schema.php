<?php
declare(strict_types=1);

function lex_users_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        if ($stmt) {
            $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
        }
        if (!in_array('avatar_stored_name', $columns, true)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar_stored_name` VARCHAR(255) DEFAULT NULL AFTER `last_login`");
        }
        $done = true;
    });
}

function lex_lawyers_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM lawyers');
        $columnRows = [];
        if ($stmt) {
            $columnRows = $stmt->fetchAll();
            $columns = array_map(static fn ($row) => (string) $row['Field'], $columnRows);
        }
        if (!in_array('background', $columns, true)) {
            $pdo->exec("ALTER TABLE `lawyers` ADD COLUMN `background` TEXT DEFAULT NULL AFTER `bio`");
        }
        if (!in_array('contact_number', $columns, true)) {
            $afterColumn = in_array('background', $columns, true) ? 'background' : 'bio';
            $pdo->exec("ALTER TABLE `lawyers` ADD COLUMN `contact_number` VARCHAR(40) DEFAULT NULL AFTER `{$afterColumn}`");
            $columns[] = 'contact_number';
        }
        if (!in_array('gcash_account_name', $columns, true)) {
            $afterColumn = in_array('contact_number', $columns, true) ? 'contact_number' : (in_array('background', $columns, true) ? 'background' : 'bio');
            $pdo->exec("ALTER TABLE `lawyers` ADD COLUMN `gcash_account_name` VARCHAR(150) DEFAULT NULL AFTER `{$afterColumn}`");
            $columns[] = 'gcash_account_name';
        }
        if (!in_array('gcash_number', $columns, true)) {
            $afterColumn = in_array('gcash_account_name', $columns, true) ? 'gcash_account_name' : (in_array('contact_number', $columns, true) ? 'contact_number' : 'bio');
            $pdo->exec("ALTER TABLE `lawyers` ADD COLUMN `gcash_number` VARCHAR(40) DEFAULT NULL AFTER `{$afterColumn}`");
            $columns[] = 'gcash_number';
        }
        if (!in_array('gcash_qr_stored_name', $columns, true)) {
            $afterColumn = in_array('gcash_number', $columns, true) ? 'gcash_number' : (in_array('gcash_account_name', $columns, true) ? 'gcash_account_name' : 'bio');
            $pdo->exec("ALTER TABLE `lawyers` ADD COLUMN `gcash_qr_stored_name` VARCHAR(255) DEFAULT NULL AFTER `{$afterColumn}`");
            $columns[] = 'gcash_qr_stored_name';
        }
        foreach ($columnRows as $row) {
            if (($row['Field'] ?? '') === 'status' && !str_contains((string) ($row['Type'] ?? ''), "'busy'")) {
                $pdo->exec("ALTER TABLE `lawyers` MODIFY COLUMN `status` ENUM('active','busy','suspended') NOT NULL DEFAULT 'active'");
                break;
            }
        }
        $done = true;
    });
}

function lex_lawyer_reviews_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'lawyer_reviews'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if ($exists) {
            try {
                $pdo->query('SELECT 1 FROM `lawyer_reviews` LIMIT 1');
            } catch (Throwable $exception) {
                $pdo->exec('DROP TABLE IF EXISTS `lawyer_reviews`');
                $exists = false;
            }
        }
        if ($exists) {
            $done = true;
            return;
        }

        $pdo->exec(
            "CREATE TABLE `lawyer_reviews` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}


function lex_manual_payments_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'manual_payments'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE `manual_payments` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `client_id` INT UNSIGNED NOT NULL,
                    `lawyer_id` INT UNSIGNED DEFAULT NULL,
                    `payment_channel` ENUM('gcash') NOT NULL DEFAULT 'gcash',
                    `payment_for` VARCHAR(180) NOT NULL,
                    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `currency` VARCHAR(10) NOT NULL DEFAULT 'PHP',
                    `payer_name` VARCHAR(150) NOT NULL,
                    `payer_contact` VARCHAR(40) DEFAULT NULL,
                    `reference_number` VARCHAR(120) DEFAULT NULL,
                    `notes` TEXT DEFAULT NULL,
                    `proof_original_name` VARCHAR(255) NOT NULL,
                    `proof_stored_name` VARCHAR(255) NOT NULL,
                    `proof_mime_type` VARCHAR(120) DEFAULT NULL,
                    `proof_size` INT UNSIGNED DEFAULT NULL,
                    `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                    `admin_notes` TEXT DEFAULT NULL,
                    `reviewed_by_user_id` INT UNSIGNED DEFAULT NULL,
                    `reviewed_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_manual_payments_client` (`client_id`, `status`, `created_at`),
                    KEY `idx_manual_payments_lawyer` (`lawyer_id`, `status`, `created_at`),
                    KEY `idx_manual_payments_status` (`status`, `created_at`),
                    KEY `idx_manual_payments_reviewer` (`reviewed_by_user_id`),
                    CONSTRAINT `fk_manual_payments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_manual_payments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE SET NULL,
                    CONSTRAINT `fk_manual_payments_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } else {
            $columns = [];
            $stmt = $pdo->query('SHOW COLUMNS FROM manual_payments');
            if ($stmt) {
                $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
            }
            if (!in_array('lawyer_id', $columns, true)) {
                $pdo->exec("ALTER TABLE `manual_payments` ADD COLUMN `lawyer_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`");
                $pdo->exec("ALTER TABLE `manual_payments` ADD KEY `idx_manual_payments_lawyer` (`lawyer_id`, `status`, `created_at`)");
                $pdo->exec("ALTER TABLE `manual_payments` ADD CONSTRAINT `fk_manual_payments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE SET NULL");
            }
        }
        $done = true;
    });
}

function lex_appointments_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM appointments');
        if ($stmt) {
            $columns = array_map(static fn ($row) => (string) $row['Field'], $stmt->fetchAll());
        }

        if (!in_array('appointment_type', $columns, true)) {
            $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `appointment_type` VARCHAR(120) NOT NULL DEFAULT 'Client Intake Consultation' AFTER `scheduled_at`");
        }

        $done = true;
    });
}

function lex_email_otps_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_otps'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE `email_otps` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `email` VARCHAR(190) NOT NULL,
                    `otp_hash` VARCHAR(255) NOT NULL,
                    `purpose` VARCHAR(50) NOT NULL DEFAULT 'client_registration',
                    `expires_at` DATETIME NOT NULL,
                    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_email_otps_email_purpose` (`email`, `purpose`, `is_used`),
                    KEY `idx_email_otps_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    });
}

function lex_password_resets_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'password_resets'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE `password_resets` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NOT NULL,
                    `token_hash` CHAR(64) NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `used_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_password_resets_token` (`token_hash`),
                    KEY `idx_password_resets_user` (`user_id`, `is_used`),
                    KEY `idx_password_resets_expires` (`expires_at`),
                    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    });
}

function lex_quick_inquiries_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'quick_inquiries'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE `quick_inquiries` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `full_name` VARCHAR(150) NOT NULL,
                    `email` VARCHAR(190) NOT NULL,
                    `phone` VARCHAR(40) DEFAULT NULL,
                    `topic` VARCHAR(120) NOT NULL,
                    `message` TEXT NOT NULL,
                    `status` ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    `user_agent` VARCHAR(255) DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_quick_inquiries_status_date` (`status`, `created_at`),
                    KEY `idx_quick_inquiries_email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    });
}

function lex_phishing_scans_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'phishing_scans'");
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE `phishing_scans` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED DEFAULT NULL,
                    `submitted_url` TEXT NOT NULL,
                    `final_url` TEXT DEFAULT NULL,
                    `status` ENUM('suspicious','phishing') NOT NULL,
                    `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `findings_json` JSON DEFAULT NULL,
                    `redirect_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `user_agent` VARCHAR(255) DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_phishing_scans_status_date` (`status`, `created_at`),
                    KEY `idx_phishing_scans_user_date` (`user_id`, `created_at`),
                    CONSTRAINT `fk_phishing_scans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    });
}
