<?php
date_default_timezone_set('Asia/Dhaka');
define('CONFIG_FILE', __DIR__ . '/../config.json');

function getConfig() {
    if (!file_exists(CONFIG_FILE)) return ['installed' => false];
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true) ?: ['installed' => false];
    // Self-heal: save app_path if missing (existing installs before this version)
    if (!empty($cfg['installed']) && empty($cfg['app_path'])) {
        $cfg['app_path'] = __DIR__ . '/..'; // config.php is in includes/, app is one level up
        $cfg['app_path'] = realpath($cfg['app_path']) ?: $cfg['app_path'];
        @file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT));
    }
    return $cfg;
}
function isInstalled() { return !empty(getConfig()['installed']); }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = getConfig();
    $pdo = new PDO(
        "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // ── Sync MySQL session timezone with PHP timezone ───────────────
    try {
        $phpOffset = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime('now'));
        $sign      = $phpOffset >= 0 ? '+' : '-';
        $absOffset = abs($phpOffset);
        $tzStr     = sprintf('%s%02d:%02d', $sign, intdiv($absOffset, 3600), ($absOffset % 3600) / 60);
        $pdo->exec("SET time_zone = '{$tzStr}'");
    } catch (Exception $e) { /* ignore if timezone already matches */ }

    // ── Self-healing Schema Migrations ─────────────────────────────
    // Runs once per request process to ensure all required tables and columns exist
    static $migrated = false;
    $markerFile = __DIR__ . '/../.migration_done';
    $migrationVersion = '15'; // bump this when adding new migrations
    $currentVersion = @file_get_contents($markerFile);
    if (!$migrated && trim($currentVersion) !== $migrationVersion) {
        $migrated = true;
        $migrations = [
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `remember_token` VARCHAR(64) DEFAULT NULL",
            "CREATE TABLE IF NOT EXISTS `images` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL DEFAULT 1,`filename` VARCHAR(255) NOT NULL,`original_name` VARCHAR(255),`mime` VARCHAR(100) DEFAULT 'image/jpeg',`url` VARCHAR(500),`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `imap_accounts` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL DEFAULT 1,`name` VARCHAR(150) NOT NULL,`host` VARCHAR(255) NOT NULL,`port` INT DEFAULT 993,`username` VARCHAR(255) NOT NULL,`password` VARCHAR(255) NOT NULL,`ssl` TINYINT(1) DEFAULT 1,`last_check` DATETIME DEFAULT NULL,`status` ENUM('active','disabled') DEFAULT 'active',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `autoreply_rules` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL DEFAULT 1,`name` VARCHAR(150) NOT NULL,`imap_id` INT DEFAULT NULL,`smtp_ids` TEXT DEFAULT NULL,`from_emails` TEXT DEFAULT NULL,`status` ENUM('active','paused') DEFAULT 'active',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `autoreply_steps` (`id` INT AUTO_INCREMENT PRIMARY KEY,`rule_id` INT NOT NULL,`step_number` INT NOT NULL DEFAULT 1,`delay_minutes` INT NOT NULL DEFAULT 1,`subject` TEXT DEFAULT NULL,`html_body` LONGTEXT DEFAULT NULL,`text_body` LONGTEXT DEFAULT NULL,`image_ids` TEXT DEFAULT NULL,`img_width` VARCHAR(20) DEFAULT '600',`img_align` VARCHAR(10) DEFAULT 'center',`img_position` VARCHAR(10) DEFAULT 'top') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `autoreply_threads` (`id` INT AUTO_INCREMENT PRIMARY KEY,`rule_id` INT NOT NULL,`from_email` VARCHAR(255) NOT NULL,`from_name` VARCHAR(150) DEFAULT NULL,`subject_in` VARCHAR(255) DEFAULT NULL,`current_step` INT NOT NULL DEFAULT 1,`next_send_at` DATETIME DEFAULT NULL,`last_sent_at` DATETIME DEFAULT NULL,`reply_count` INT NOT NULL DEFAULT 0,`status` ENUM('active','completed') DEFAULT 'active',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY `uq_rule_email` (`rule_id`,`from_email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `autoreply_logs` (`id` INT AUTO_INCREMENT PRIMARY KEY,`rule_id` INT NOT NULL,`thread_id` INT NOT NULL,`step_number` INT NOT NULL,`to_email` VARCHAR(255) NOT NULL,`status` ENUM('sent','failed') NOT NULL,`error` TEXT DEFAULT NULL,`smtp_used` VARCHAR(150) DEFAULT NULL,`sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `followup_rules` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL DEFAULT 1,`name` VARCHAR(150) NOT NULL,`imap_id` INT DEFAULT NULL,`smtp_ids` TEXT DEFAULT NULL,`from_emails` TEXT DEFAULT NULL,`status` ENUM('active','paused') DEFAULT 'active',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `followup_steps` (`id` INT AUTO_INCREMENT PRIMARY KEY,`rule_id` INT NOT NULL,`step_number` INT NOT NULL DEFAULT 1,`delay_minutes` INT NOT NULL DEFAULT 60,`subject` TEXT DEFAULT NULL,`html_body` LONGTEXT DEFAULT NULL,`text_body` LONGTEXT DEFAULT NULL,`image_ids` TEXT DEFAULT NULL,`img_width` VARCHAR(20) DEFAULT '600',`img_align` VARCHAR(10) DEFAULT 'center',`img_position` VARCHAR(10) DEFAULT 'top') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `followup_contacts` (`id` INT AUTO_INCREMENT PRIMARY KEY,`rule_id` INT NOT NULL,`email` VARCHAR(255) NOT NULL,`name` VARCHAR(150) DEFAULT NULL,`current_step` INT NOT NULL DEFAULT 1,`next_send_at` DATETIME DEFAULT NULL,`last_sent_at` DATETIME DEFAULT NULL,`status` ENUM('active','completed','stopped') DEFAULT 'active',`enrolled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY `uq_fu_email` (`rule_id`,`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `followup_logs` (`id` INT AUTO_INCREMENT PRIMARY KEY,`rule_id` INT NOT NULL,`contact_id` INT NOT NULL,`step_number` INT NOT NULL,`email` VARCHAR(255) NOT NULL,`status` ENUM('sent','failed') NOT NULL,`error` TEXT DEFAULT NULL,`smtp_used` VARCHAR(150) DEFAULT NULL,`sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_admin` TINYINT(1) DEFAULT 0",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `smtp_limit` INT DEFAULT 5",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `campaign_limit` INT DEFAULT 10",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `daily_send_limit` INT DEFAULT 1000",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `autoreply_limit` INT DEFAULT 5",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `followup_limit` INT DEFAULT 5",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `expires_at` DATETIME DEFAULT NULL",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `status` ENUM('active','suspended') DEFAULT 'active'",
            "ALTER TABLE `smtp_providers` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL DEFAULT 1",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL DEFAULT 1",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `smtp_ids` TEXT DEFAULT NULL",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `from_emails` TEXT DEFAULT NULL",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `variants` LONGTEXT DEFAULT NULL",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `sent_count` INT DEFAULT 0",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `failed_count` INT DEFAULT 0",
            "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `smtp_name_used` VARCHAR(150) DEFAULT NULL",
            "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `from_email_used` VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `error_code` VARCHAR(50) DEFAULT NULL",
            "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `variant_index` INT DEFAULT NULL",
            "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `user_id` INT DEFAULT NULL",
            "ALTER TABLE `send_logs` MODIFY COLUMN `campaign_id` INT DEFAULT NULL",
            "CREATE TABLE IF NOT EXISTS `user_meta` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL,`meta_key` VARCHAR(100) NOT NULL,`meta_value` TEXT DEFAULT NULL,UNIQUE KEY `uq_user_meta` (`user_id`,`meta_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `blacklist` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL DEFAULT 1,`type` ENUM('email','domain') NOT NULL DEFAULT 'email',`email` VARCHAR(255) DEFAULT NULL,`domain` VARCHAR(255) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX `idx_bl_user` (`user_id`),INDEX `idx_bl_email` (`email`),INDEX `idx_bl_domain` (`domain`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `backup_emails` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL DEFAULT 1,`email` VARCHAR(255) NOT NULL,`name` VARCHAR(150) DEFAULT NULL,`source` ENUM('followup','autoreply') NOT NULL DEFAULT 'followup',`rule_id` INT NOT NULL DEFAULT 0,`completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,`first_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX `idx_bk_user` (`user_id`),INDEX `idx_bk_email` (`email`),UNIQUE KEY `uq_bk_user_rule_email` (`user_id`,`rule_id`,`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `inbound_emails` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`imap_account_id` INT NOT NULL,`uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,`uid_validity` BIGINT UNSIGNED NOT NULL DEFAULT 0,`from_email` VARCHAR(255) NOT NULL,`from_name` VARCHAR(255) DEFAULT NULL,`subject` VARCHAR(500) DEFAULT NULL,`received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX `idx_inb_acct` (`imap_account_id`),INDEX `idx_inb_email` (`from_email`),INDEX `idx_inb_received` (`received_at`),UNIQUE KEY `uq_inb_acct_uid` (`imap_account_id`,`uid_validity`,`uid`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `log_source` VARCHAR(20) NOT NULL DEFAULT 'campaign'",
            "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `sender_name` VARCHAR(150) DEFAULT NULL",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `imap_read_limit` INT DEFAULT 0",
            "ALTER TABLE `blacklist` MODIFY COLUMN `type` ENUM('email','domain','subject','keyword') NOT NULL DEFAULT 'email'",
            "CREATE TABLE IF NOT EXISTS `email_followup_queue` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL DEFAULT 1,
                `campaign_id` INT DEFAULT NULL,
                `rule_id` INT DEFAULT NULL,
                `contact_id` INT DEFAULT NULL,
                `recipient_email` VARCHAR(255) NOT NULL,
                `recipient_name` VARCHAR(150) DEFAULT NULL,
                `followup_order` INT NOT NULL DEFAULT 1,
                `delay_value` INT NOT NULL DEFAULT 30,
                `delay_unit` ENUM('minutes','hours','days') NOT NULL DEFAULT 'minutes',
                `delay_in_minutes` INT NOT NULL DEFAULT 30,
                `scheduled_at` DATETIME DEFAULT NULL,
                `opened_at` DATETIME DEFAULT NULL,
                `followup_started_at` DATETIME DEFAULT NULL,
                `sent_at` DATETIME DEFAULT NULL,
                `status` ENUM('pending','scheduled','sending','sent','failed','cancelled','skipped') NOT NULL DEFAULT 'pending',
                `retry_count` INT NOT NULL DEFAULT 0,
                `timezone` VARCHAR(64) DEFAULT 'UTC',
                `last_error` TEXT DEFAULT NULL,
                `tracking_token` VARCHAR(64) UNIQUE NOT NULL,
                `locked_at` DATETIME DEFAULT NULL,
                `lock_token` VARCHAR(64) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_efq_status_sched` (`status`, `scheduled_at`),
                INDEX `idx_efq_token` (`tracking_token`),
                INDEX `idx_efq_rule_email` (`rule_id`, `recipient_email`),
                INDEX `idx_efq_user_status` (`user_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `email_templates` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL DEFAULT 1,
                `name` VARCHAR(150) NOT NULL,
                `subject` VARCHAR(255) DEFAULT NULL,
                `html_body` LONGTEXT DEFAULT NULL,
                `text_body` LONGTEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_tmpl_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `system_logs` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `campaign_id` INT DEFAULT NULL,
                `rule_id` INT DEFAULT NULL,
                `queue_id` BIGINT DEFAULT NULL,
                `tracking_token` VARCHAR(64) DEFAULT NULL,
                `recipient_email` VARCHAR(255) NOT NULL,
                `event_type` ENUM('queued','sent','opened','clicked','bounced','complaint','unsubscribed','failed','retry') NOT NULL,
                `smtp_server` VARCHAR(150) DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(500) DEFAULT NULL,
                `details` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_slog_event_created` (`event_type`, `created_at`),
                INDEX `idx_slog_email` (`recipient_email`),
                INDEX `idx_slog_token` (`tracking_token`),
                INDEX `idx_slog_user_created` (`user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `mail_routing_logs` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `rule_id` INT DEFAULT NULL,
                `thread_id` VARCHAR(255) DEFAULT NULL,
                `email` VARCHAR(255) NOT NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `incoming_mailbox` VARCHAR(255) DEFAULT NULL,
                `smtp_used` VARCHAR(255) DEFAULT NULL,
                `reply_to_address` VARCHAR(255) DEFAULT NULL,
                `stage_before` VARCHAR(64) DEFAULT NULL,
                `stage_after` VARCHAR(64) DEFAULT NULL,
                `delivery_status` VARCHAR(64) DEFAULT 'success',
                `details` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_mrl_email` (`email`),
                INDEX `idx_mrl_event` (`event_type`, `created_at`),
                INDEX `idx_mrl_user` (`user_id`, `created_at`),
                INDEX `idx_mrl_thread` (`thread_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `mail_routing_queue` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `queue_type` ENUM('incoming-mail','auto-reply','followup-mail','mailbox-routing','webhook-events') NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
                `attempts` INT NOT NULL DEFAULT 0,
                `max_attempts` INT NOT NULL DEFAULT 3,
                `scheduled_at` DATETIME NOT NULL,
                `locked_at` DATETIME DEFAULT NULL,
                `lock_token` VARCHAR(64) DEFAULT NULL,
                `last_error` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_mrq_status_sched` (`status`, `scheduled_at`),
                INDEX `idx_mrq_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `image_upload` TINYINT(1) DEFAULT 1",
            "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `lead_delete` TINYINT(1) DEFAULT 1"
        ];
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
        }

        $arCols = [
            ['autoreply_threads','messages_received',    "INT NOT NULL DEFAULT 1"],
            ['autoreply_threads','awaiting_reply',       "TINYINT(1) NOT NULL DEFAULT 0"],
            ['autoreply_threads','current_imap_id',      "INT DEFAULT NULL"],
            ['autoreply_threads','last_trigger_uid',     "BIGINT UNSIGNED DEFAULT NULL"],
            ['autoreply_threads','last_trigger_imap_id', "INT DEFAULT NULL"],
            ['autoreply_threads','last_msg_id',          "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','active_mailbox',       "ENUM('primary','secondary','backup') NOT NULL DEFAULT 'primary'"],
            ['autoreply_threads','first_reply_sent',     "TINYINT(1) NOT NULL DEFAULT 0"],
            ['autoreply_threads','reply_to_mailbox',     "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','smtp_used',            "INT DEFAULT NULL"],
            ['autoreply_threads','imap_source',          "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','thread_id',            "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','original_message_id',  "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','last_message_id',      "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','references_header',    "TEXT DEFAULT NULL"],
            ['autoreply_threads','followup_status',      "ENUM('pending','running','completed','cancelled') DEFAULT 'pending'"],
            ['autoreply_threads','followup_next_run',    "DATETIME DEFAULT NULL"],
            ['autoreply_threads','conversation_stage',   "ENUM('NEW_LEAD','FIRST_REPLY_SENT','MOVED_TO_SECONDARY','FOLLOWUP_RUNNING','FOLLOWUP_COMPLETED') NOT NULL DEFAULT 'NEW_LEAD'"],
            ['autoreply_threads','scheduled_send_time',  "DATETIME DEFAULT NULL"],
            ['autoreply_threads','last_received_message_id',"VARCHAR(255) DEFAULT NULL"],
            ['autoreply_threads','last_reply_message_id',   "VARCHAR(255) DEFAULT NULL"],
            ['autoreply_rules',  'sequential_mode',      "TINYINT(1) NOT NULL DEFAULT 0"],
            ['autoreply_rules',  'imap2_id',             "INT DEFAULT NULL"],
            ['autoreply_rules',  'step1_smtp_ids',       "TEXT DEFAULT NULL"],
            ['autoreply_rules',  'enable_smart_routing', "TINYINT(1) NOT NULL DEFAULT 0"],
            ['autoreply_rules',  'primary_imap_id',      "INT DEFAULT NULL"],
            ['autoreply_rules',  'secondary_imap_id',    "INT DEFAULT NULL"],
            ['autoreply_rules',  'backup_imap_id',       "INT DEFAULT NULL"],
            ['autoreply_rules',  'primary_smtp_id',      "INT DEFAULT NULL"],
            ['autoreply_rules',  'secondary_smtp_id',    "INT DEFAULT NULL"],
            ['autoreply_rules',  'enable_reply_to_switch', "TINYINT(1) NOT NULL DEFAULT 1"],
            ['autoreply_rules',  'enable_always_send_followup', "TINYINT(1) NOT NULL DEFAULT 1"],
            ['autoreply_rules',  'enable_gmail_priority', "TINYINT(1) NOT NULL DEFAULT 1"],
            ['autoreply_rules',  'followup_rule_id',     "INT DEFAULT NULL"],
            ['imap_accounts',    'last_uid',             "BIGINT UNSIGNED NOT NULL DEFAULT 0"],
            ['imap_accounts',    'last_uid_validity',    "BIGINT UNSIGNED NOT NULL DEFAULT 0"],
            ['imap_accounts',    'process_lock_at',      "DATETIME DEFAULT NULL"],
            ['imap_accounts',    'process_lock_pid',     "VARCHAR(64) DEFAULT NULL"],
            ['autoreply_steps',  'delay_minutes',        "INT NOT NULL DEFAULT 1"],
            ['autoreply_steps',  'delay_value',          "INT NOT NULL DEFAULT 1"],
            ['autoreply_steps',  'delay_unit',           "ENUM('minutes','hours','days') NOT NULL DEFAULT 'minutes'"],
            ['followup_steps',   'delay_minutes',        "INT NOT NULL DEFAULT 60"],
            ['followup_steps',   'delay_value',          "INT NOT NULL DEFAULT 30"],
            ['followup_steps',   'delay_unit',           "ENUM('minutes','hours','days') NOT NULL DEFAULT 'minutes'"],
            ['followup_contacts','opened_at',            "DATETIME DEFAULT NULL"],
            ['followup_contacts','followup_started_at',  "DATETIME DEFAULT NULL"],
            ['followup_contacts','tracking_token',       "VARCHAR(64) DEFAULT NULL"],
            ['followup_contacts','open_count',           "INT NOT NULL DEFAULT 0"],
            ['followup_contacts','click_count',          "INT NOT NULL DEFAULT 0"],
            ['followup_rules',   'trigger_on_open',      "TINYINT(1) NOT NULL DEFAULT 1"],
            ['blacklist',        'phrase',               "VARCHAR(255) DEFAULT NULL"],
            ['users',            'autoreply_limit',      "INT DEFAULT 10"],
            ['users',            'followup_limit',       "INT DEFAULT 10"],
            ['users',            'smtp_limit',           "INT DEFAULT 10"],
            ['users',            'imap_limit',           "INT DEFAULT 10"],
            ['users',            'assigned_smtp_ids',    "TEXT DEFAULT NULL"],
            ['users',            'assigned_imap_ids',    "TEXT DEFAULT NULL"],
            ['campaigns',        'sender_name',          "VARCHAR(150) DEFAULT NULL"],
            ['campaigns',        'followup_rule_id',     "INT DEFAULT NULL"],
            ['users',            'remember_token',       "VARCHAR(64) DEFAULT NULL"],
            ['users',            'imap_read_limit',      "INT DEFAULT 0"],
            ['imap_accounts',    'emails_read',          "INT NOT NULL DEFAULT 0"],
            ['emails',           'created_at',           "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"],
            ['inbound_emails',   'message_id',           "VARCHAR(255) DEFAULT NULL"],
            ['inbound_emails',   'in_reply_to',          "VARCHAR(255) DEFAULT NULL"],
            ['inbound_emails',   'references_header',    "TEXT DEFAULT NULL"],
            ['inbound_emails',   'thread_id',            "VARCHAR(255) DEFAULT NULL"],
            ['inbound_emails',   'body',                 "LONGTEXT DEFAULT NULL"],
        ];
        foreach ($arCols as [$tbl, $col, $def]) {
            try {
                $chk = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = ?
                        AND COLUMN_NAME  = ?"
                );
                $chk->execute([$tbl, $col]);
                if ((int)$chk->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$def}");
                }
            } catch (Exception $e) {}
        }

        $idxSqls = [
            "ALTER TABLE `followup_contacts` ADD INDEX `idx_fc_status_next` (`status`, `next_send_at`)",
            "ALTER TABLE `followup_contacts` ADD INDEX `idx_fc_status_step` (`status`, `current_step`)",
            "ALTER TABLE `followup_contacts` ADD INDEX `idx_fc_token` (`tracking_token`)",
            "ALTER TABLE `autoreply_threads` ADD INDEX `idx_art_status_next` (`status`, `next_send_at`)",
            "ALTER TABLE `autoreply_threads` ADD INDEX `idx_art_status_step` (`status`, `current_step`)",
            "ALTER TABLE `autoreply_logs` ADD INDEX `idx_arl_status_sent` (`status`, `sent_at`)",
            "ALTER TABLE `followup_logs` ADD INDEX `idx_ful_status_sent` (`status`, `sent_at`)",
            "ALTER TABLE `send_logs` ADD INDEX `idx_sl_email` (`email`)",
            "ALTER TABLE `send_logs` ADD INDEX `idx_sl_user_status_sent` (`user_id`, `status`, `sent_at`)",
            "ALTER TABLE `inbound_emails` ADD INDEX `idx_inb_acct_rec` (`imap_account_id`, `received_at`)",
            "ALTER TABLE `emails` ADD INDEX `idx_em_list_created` (`list_id`, `created_at`)",
            "ALTER TABLE `followup_contacts` ADD INDEX `idx_fc_rule_created` (`rule_id`, `created_at`)",
            "ALTER TABLE `followup_contacts` ADD INDEX `idx_fc_created` (`created_at`)",
            "ALTER TABLE `autoreply_threads` MODIFY COLUMN `status` ENUM('active','completed','pending','scheduled','sending','sent','failed','cancelled') DEFAULT 'active'"
        ];
        foreach ($idxSqls as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
        // Backfill NULL created_at in emails table with parent list's created_at or NOW()
        try {
            $pdo->exec("UPDATE emails e JOIN email_lists l ON l.id = e.list_id SET e.created_at = COALESCE(l.created_at, NOW()) WHERE e.created_at IS NULL");
            $pdo->exec("UPDATE emails SET created_at = NOW() WHERE created_at IS NULL");
            $pdo->exec("UPDATE email_lists l SET total_count = (SELECT COUNT(*) FROM emails e WHERE e.list_id = l.id)");
        } catch (Exception $e) {}
        // Add index on emails.created_at for faster today/month leads queries
        try { $pdo->exec("ALTER TABLE `emails` ADD INDEX `idx_em_created` (`created_at`)"); } catch (Exception $e) {}
        @file_put_contents($markerFile, $migrationVersion);
    }
    return $pdo;
}

function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            ini_set('session.gc_maxlifetime', 2592000); // 30 days
            try {
                $sessDir = __DIR__ . '/../sessions';
                if (!is_dir($sessDir)) { @mkdir($sessDir, 0775, true); }
                if (is_dir($sessDir) && is_writable($sessDir)) {
                    @session_save_path($sessDir);
                } else {
                    $sysTmp = sys_get_temp_dir();
                    $altDir = rtrim($sysTmp ?: '/tmp', '/\\') . '/mailszo_sessions';
                    if (!is_dir($altDir)) { @mkdir($altDir, 0777, true); }
                    if (is_dir($altDir) && is_writable($altDir)) {
                        @session_save_path($altDir);
                    }
                }
            } catch (Throwable $e) {}

            try {
                session_set_cookie_params([
                    'lifetime' => 2592000,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } catch (Throwable $e) {}
        }
        try { @session_start(); } catch (Throwable $e) {}
    }
}

function setRememberCookie($userId) {
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    try {
        db()->prepare('UPDATE users SET remember_token=? WHERE id=?')->execute([$hash, $userId]);
    } catch (Throwable $e) {}
    if (!headers_sent()) {
        try {
            if (PHP_VERSION_ID >= 70300) {
                @setcookie('mailpro_remember', $userId . ':' . $token, [
                    'expires'  => time() + 2592000,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                @setcookie('mailpro_remember', $userId . ':' . $token, time() + 2592000, '/', '', false, true);
            }
        } catch (Throwable $e) {}
    }
}

function clearRememberCookie() {
    $_COOKIE['mailpro_remember'] = '';
    unset($_COOKIE['mailpro_remember']);
    if (!headers_sent()) {
        try {
            if (PHP_VERSION_ID >= 70300) {
                @setcookie('mailpro_remember', '', [
                    'expires'  => 1,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
            @setcookie('mailpro_remember', '', time() - 3600, '/');
        } catch (Throwable $e) {}

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host) {
            if (strpos($host, ':') !== false) {
                $host = explode(':', $host)[0];
            }
            try {
                if (PHP_VERSION_ID >= 70300) {
                    @setcookie('mailpro_remember', '', [
                        'expires'  => 1,
                        'path'     => '/',
                        'domain'   => $host,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }
                @setcookie('mailpro_remember', '', time() - 3600, '/', $host);
            } catch (Throwable $e) {}
        }
    }
}

function checkRememberToken() {
    if (empty($_COOKIE['mailpro_remember'])) return false;
    $parts = explode(':', $_COOKIE['mailpro_remember'], 2);
    if (count($parts) !== 2) return false;
    list($uid, $token) = $parts;
    $hash = hash('sha256', $token);
    try {
        $s = db()->prepare('SELECT * FROM users WHERE id=? AND status="active"');
        $s->execute([(int)$uid]);
        $u = $s->fetch();
        if ($u && !empty($u['remember_token']) && hash_equals($u['remember_token'], $hash)) {
            startSecureSession();
            $_SESSION['uid']      = $u['id'];
            $_SESSION['uname']    = $u['username'];
            $_SESSION['is_admin'] = (bool)$u['is_admin'];
            session_write_close();
            return $u;
        }
    } catch (Exception $e) {}
    return false;
}

function jsonOut($data, $code = 200) {
    // Discard any buffered warnings/notices so they don't corrupt the JSON response
    while (ob_get_level() > 0) { ob_end_clean(); }
    // Always return HTTP 200 to prevent Nginx (fastcgi_intercept_errors) from replacing the JSON with its own HTML 404/500 error pages
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function body() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function requireAuth() {
    startSecureSession();
    if (empty($_SESSION['uid'])) {
        $user = checkRememberToken();
        if (!$user) jsonOut(['error' => 'Unauthorized'], 401);
    }
    session_write_close();
}

function requireAdmin() {
    requireAuth();
    startSecureSession();
    $isAdmin = !empty($_SESSION['is_admin']);
    session_write_close();
    if (!$isAdmin) jsonOut(['error' => 'Admin only'], 403);
}

function currentUser() {
    if (empty($_SESSION['uid'])) return null;
    $s = db()->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$_SESSION['uid']]);
    return $s->fetch();
}

/* Check user limits.
 *
 * $extra is an optional context array used by the message-count checks:
 *   ['adding' => N]            — proposed number of new messages to insert
 *   ['exclude_rule' => $rid]   — exclude this rule's existing steps from the
 *                                 count (used when REPLACING a rule's steps
 *                                 in PUT, so the rule's own old steps don't
 *                                 double-count against the cap).
 */
function checkUserLimit($user, $type, array $extra = []) {
    // Supported types: smtp_count, campaign_count, autoreply_count, followup_count.
    // Admin bypasses every cap (same convention as the existing checks).
    if ($user['is_admin']) return ['ok' => true];
    if ($type === 'smtp_count') {
        $limit = (int)($user['smtp_limit'] ?? 0);
        if ($limit <= 0) return ['ok' => false, 'msg' => 'SMTP creation disabled'];
        $s = db()->prepare('SELECT COUNT(*) FROM smtp_providers WHERE user_id=?');
        $s->execute([$user['id']]);
        if ((int)$s->fetchColumn() >= $limit) return ['ok' => false, 'msg' => "SMTP limit reached ({$limit})"];
    }
    if ($type === 'autoreply_count' || $type === 'followup_count') {
        // Caps the number of auto-reply / follow-up MESSAGES (rows in
        // autoreply_steps / followup_steps) this user can have configured
        // across ALL their rules. The check fires at rule save time so the
        // user can never bypass the cap by spreading messages across many
        // rules.
        $isAR = ($type === 'autoreply_count');
        $limit = (int)($user[$isAR ? 'autoreply_limit' : 'followup_limit'] ?? 0);
        $label = $isAR ? 'Auto-Reply' : 'Follow-Up';
        if ($limit <= 0) return ['ok' => false, 'msg' => "{$label} message creation disabled — contact admin"];

        $stepsTbl = $isAR ? 'autoreply_steps' : 'followup_steps';
        $rulesTbl = $isAR ? 'autoreply_rules' : 'followup_rules';

        $sql    = "SELECT COUNT(*) FROM {$stepsTbl} s JOIN {$rulesTbl} r ON r.id = s.rule_id WHERE r.user_id = ?";
        $params = [$user['id']];
        if (!empty($extra['exclude_rule'])) {
            $sql .= " AND s.rule_id != ?";
            $params[] = (int)$extra['exclude_rule'];
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $current  = (int)$stmt->fetchColumn();
        $adding   = max(0, (int)($extra['adding'] ?? 0));
        $totalAfter = $current + $adding;

        if ($totalAfter > $limit) {
            $rem = max(0, $limit - $current);
            return ['ok' => false,
                'msg' => "{$label} message limit reached — you have {$current} of {$limit} configured" .
                         ($adding > 0 ? ", cannot add {$adding} more ({$rem} remaining)." : '.') .
                         ' Delete an existing message or ask admin to raise the limit.'];
        }
    }
    if ($type === 'campaign_count') {
        $limit = (int)($user['campaign_limit'] ?? 0);
        if ($limit <= 0) return ['ok' => false, 'msg' => 'Campaign creation disabled'];
        $s = db()->prepare('SELECT COUNT(*) FROM campaigns WHERE user_id=?');
        $s->execute([$user['id']]);
        if ((int)$s->fetchColumn() >= $limit) return ['ok' => false, 'msg' => "Campaign limit reached ({$limit})"];
    }
    return ['ok' => true];
}

function isExpired($user) {
    if ($user['is_admin']) return false;
    if (empty($user['expires_at'])) return false;
    return strtotime($user['expires_at']) < time();
}

/* Spintax: {a|b|c} and Token protection */
function spin($text) {
    if (empty($text) || !is_string($text)) return $text;

    // 1. Protect all {{...}} placeholders (case-insensitive) so spintax {a|b} parser never corrupts double-braces
    $placeholders = [];
    $text = preg_replace_callback('/\{\{[^}]+\}\}/i', function($m) use (&$placeholders) {
        $key = "\x02TAG_" . count($placeholders) . "\x03";
        $placeholders[$key] = $m[0];
        return $key;
    }, $text);

    // 2. Process spintax {option1|option2|option3} (supports nested spintax)
    $prev = null;
    while ($prev !== $text) {
        $prev = $text;
        $text = preg_replace_callback('/\{([^{}]+)\}/', function($m) {
            $opts = explode('|', $m[1]);
            return trim($opts[array_rand($opts)]);
        }, $text);
    }

    // 3. Restore all original {{...}} placeholders exactly as written
    if (!empty($placeholders)) {
        $text = strtr($text, $placeholders);
    }

    return $text;
}

function personalize($text, $name, $email, $senderName = '', $todayDate = '') {
    if (empty($text) || !is_string($text)) return $text;
    $today = $todayDate ?: date('F j, Y g:i A');

    $text = str_ireplace('{{name}}',      $name ?: 'Valued Customer', $text);
    $text = str_ireplace('{{email}}',     $email, $text);
    $text = str_ireplace('{{modelname}}', $senderName, $text);
    $text = str_ireplace('{{todaydate}}', $today, $text);

    return $text;
}

/**
 * Check if an email address is blacklisted for a given user.
 *
 * Domain entries are treated as suffix patterns so the operator can blacklist
 * a TLD (".org"), a registrable domain ("example.com"), or any subdomain
 * level ("groups.google.com") and have it apply to every address whose
 * domain ends with that suffix.
 *
 * Matching variants computed for foo@mail.x.example.com:
 *   mail.x.example.com  .mail.x.example.com
 *   x.example.com       .x.example.com
 *   example.com         .example.com
 *   com                 .com
 * Any entry that exactly equals one of these variants triggers a hit, so:
 *   - "example.com"  blocks foo@example.com AND foo@anything.example.com
 *   - ".org"         blocks every *.org address
 *   - "groups.google.com" blocks the exact subdomain and anything under it
 *
 * Without this, blacklisting ".org" or a parent domain silently does
 * nothing (the original code did `LOWER(domain) = ?` exact match only).
 */
function isBlacklisted(string $email, int $userId): bool {
    try {
        $emailLower = strtolower(trim($email));
        if ($emailLower === '') return false;
        $atPos = strrpos($emailLower, '@');
        if ($atPos === false) return false;
        $domain = substr($emailLower, $atPos + 1);
        if ($domain === '') return false;

        // Build every suffix variant of the domain, with and without leading dot,
        // so an exact equality SQL match catches all the supported entry styles.
        $variants = [$domain, '.' . $domain];
        $parts = explode('.', $domain);
        for ($i = 1; $i < count($parts); $i++) {
            $sub = implode('.', array_slice($parts, $i));
            if ($sub === '') continue;
            $variants[] = $sub;
            $variants[] = '.' . $sub;
        }
        $variants = array_values(array_unique($variants));

        $ph = implode(',', array_fill(0, count($variants), '?'));
        // Match entries for this user OR any entry created by an admin user.
        // This ensures admin-added blacklist rules apply globally across all users.
        $sql = "SELECT id FROM blacklist
                 WHERE (user_id = ? OR user_id IN (SELECT id FROM users WHERE is_admin = 1))
                   AND (
                     (type='email'  AND LOWER(email)  = ?) OR
                     (type='domain' AND LOWER(domain) IN ({$ph}))
                   )
                 LIMIT 1";
        $params = array_merge([$userId, $emailLower], $variants);
        $s = db()->prepare($sql);
        $s->execute($params);
        return (bool) $s->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Subject Blacklist + "Has the Words" filter.
 *
 * Returns true when the inbound message's subject (or any combined text we
 * have on hand: subject, from email, from name) contains any phrase that
 * the user has stored on the Blacklist page under type='subject' or
 * type='keyword'.
 *
 * - type='subject' — case-insensitive substring match against the subject only.
 * - type='keyword' — case-insensitive substring match against
 *                    subject + from_email + from_name (everything the IMAP
 *                    fetch path gives us; no body fetch is performed).
 *
 * Kept entirely separate from isBlacklisted() so existing email/domain
 * blocking logic, callers, and behaviour remain untouched.
 */
function isMessageBlocked(int $userId, string $subject = '', string $fromEmail = '', string $fromName = ''): bool {
    try {
        $subjectLow = strtolower(trim($subject));
        $haystack   = strtolower(trim($subject . ' ' . $fromEmail . ' ' . $fromName));
        if ($subjectLow === '' && $haystack === '') return false;

        $stmt = db()->prepare(
            "SELECT type, phrase FROM blacklist
              WHERE (user_id = ? OR user_id IN (SELECT id FROM users WHERE is_admin = 1))
                AND type IN ('subject','keyword')
                AND phrase IS NOT NULL
                AND phrase <> ''"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $needle = strtolower(trim((string)$row['phrase']));
            if ($needle === '') continue;
            if ($row['type'] === 'subject') {
                if ($subjectLow !== '' && strpos($subjectLow, $needle) !== false) return true;
            } else { // keyword — match across all available text
                if ($haystack !== '' && strpos($haystack, $needle) !== false) return true;
            }
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Convert delay value & unit ('minutes', 'hours', 'days') to total minutes.
 */
function delayToMinutes(int $value, string $unit = 'minutes'): int {
    $val = max(0, $value);
    switch (strtolower(trim($unit))) {
        case 'days':
        case 'day':
        case 'd':
            return $val * 1440; // 24 * 60
        case 'hours':
        case 'hour':
        case 'h':
            return $val * 60;
        case 'minutes':
        case 'minute':
        case 'm':
        default:
            return $val;
    }
}

/**
 * Generate a cryptographically secure tracking token.
 */
function generateTrackingToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Get client IP address safely.
 */
function getClientIp(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ips = explode(',', $_SERVER[$k]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Get client User Agent string safely.
 */
function getClientUserAgent(): string {
    return substr(trim($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

/**
 * Detect Apple Mail Privacy Protection (MPP) or Google Image Proxy.
 */
function isProxyOrPrefetch(string $ua = ''): bool {
    $ua = $ua ?: getClientUserAgent();
    $low = strtolower($ua);
    return (
        strpos($low, 'googleimageproxy') !== false ||
        strpos($low, 'mozilla/5.0') !== false && (strpos($low, 'apple mail') !== false || strpos($low, 'applewebkit') !== false && strpos($low, 'safari') === false)
    );
}

/**
 * Log unified system event to system_logs table.
 */
function logSystemEvent(
    string $eventType,
    string $recipientEmail,
    string $details = '',
    ?int $userId = null,
    ?int $campaignId = null,
    ?int $ruleId = null,
    ?int $queueId = null,
    ?string $token = null,
    ?string $smtpServer = null
): bool {
    try {
        $ip = getClientIp();
        $ua = getClientUserAgent();
        $stmt = db()->prepare(
            "INSERT INTO system_logs
             (user_id, campaign_id, rule_id, queue_id, tracking_token, recipient_email, event_type, smtp_server, ip_address, user_agent, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        return $stmt->execute([
            $userId, $campaignId, $ruleId, $queueId, $token,
            strtolower(trim($recipientEmail)), $eventType, $smtpServer, $ip, $ua, $details
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resolve root application URL for tracking links and pixels.
 */
function getAppBaseUrl(): string {
    $cfg = getConfig();
    if (!empty($cfg['app_url'])) {
        return rtrim($cfg['app_url'], '/');
    }
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );
    $scheme = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $scriptDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;
    return rtrim($scheme . $host . $scriptDir, '/');
}

/**
 * Log Smart Mail Routing event.
 */
function logMailRoutingEvent(
    ?int $userId,
    ?int $ruleId,
    ?string $threadId,
    string $email,
    string $eventType,
    ?string $incomingMailbox = null,
    ?string $smtpUsed = null,
    ?string $replyToAddress = null,
    ?string $stageBefore = null,
    ?string $stageAfter = null,
    string $deliveryStatus = 'success',
    ?string $details = null
): bool {
    try {
        $stmt = db()->prepare(
            "INSERT INTO mail_routing_logs
             (user_id, rule_id, thread_id, email, event_type, incoming_mailbox, smtp_used, reply_to_address, stage_before, stage_after, delivery_status, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        return $stmt->execute([
            $userId, $ruleId, $threadId, strtolower(trim($email)), $eventType,
            $incomingMailbox, $smtpUsed, $replyToAddress, $stageBefore, $stageAfter,
            $deliveryStatus, $details
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Canonical clean subject line for thread hashing (removes Re:, Fwd:, [Tag], extra spaces).
 */
function canonicalSubject(string $subject): string {
    $s = trim($subject);
    $prev = '';
    while ($prev !== $s) {
        $prev = $s;
        $s = preg_replace('/^\s*(re|fwd|fw|aw|sv|vs|r)\s*:\s*/i', '', $s);
        $s = preg_replace('/^\s*\[[^\]]+\]\s*/i', '', $s);
    }
    $s = preg_replace('/[!\?\.\s]+$/', '', $s);
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
}

/**
 * Resolve or generate thread ID based on headers and subject.
 */
function resolveConversationThreadId(
    ?string $messageId,
    ?string $inReplyTo,
    ?string $references,
    string $fromEmail,
    string $subject
): string {
    $fromEmail = strtolower(trim($fromEmail));
    // 1. Try matching against existing thread using In-Reply-To or References
    $refCandidates = [];
    if ($inReplyTo) {
        $cleanIrt = trim($inReplyTo, " <>\r\n\t");
        if ($cleanIrt) $refCandidates[] = $cleanIrt;
    }
    if ($references) {
        preg_match_all('/<([^>]+)>/', $references, $m);
        if (!empty($m[1])) {
            foreach ($m[1] as $r) { $refCandidates[] = trim($r); }
        }
    }
    if ($refCandidates) {
        $ph = implode(',', array_fill(0, count($refCandidates), '?'));
        try {
            $stmt = db()->prepare(
                "SELECT thread_id FROM autoreply_threads 
                 WHERE original_message_id IN ($ph) OR last_message_id IN ($ph) 
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute($refCandidates);
            $existingTh = $stmt->fetchColumn();
            if ($existingTh) return $existingTh;
        } catch (Throwable $_e) {}
    }

    // 2. Try matching by active thread with sender
    try {
        $stmt = db()->prepare("SELECT thread_id FROM autoreply_threads WHERE from_email = ? AND thread_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$fromEmail]);
        $existingTh = $stmt->fetchColumn();
        if ($existingTh) return $existingTh;
    } catch (Throwable $_e) {}

    // 3. Fallback: generate new unique thread ID
    $cleanSub = canonicalSubject($subject);
    return 'th_' . substr(md5($fromEmail . '|' . $cleanSub . '|' . microtime(true)), 0, 16);
}

