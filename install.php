<?php
// ══════════════════════════════════════════════════════════════════════════════
//  MailsZo v4 — Installation Wizard
//  Handles: system checks, DB test, full schema create, migration, admin user
// ══════════════════════════════════════════════════════════════════════════════
define('CONFIG_FILE', __DIR__ . '/config.json');

function getConfig() {
    if (!file_exists(CONFIG_FILE)) return ['installed' => false];
    return json_decode(file_get_contents(CONFIG_FILE), true) ?: ['installed' => false];
}

// Already installed — redirect unless ?force=1
$cfg = getConfig();
if (!empty($cfg['installed']) && $_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_GET['force'])) {
    header('Location: index.php'); exit;
}

// ── AJAX POST handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $b['action'] ?? '';

    // ── System Requirements Check ──────────────────────────────────
    if ($action === 'check-requirements') {
        $results = [];

        $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
        $results[] = ['name'=>'PHP Version ('.PHP_VERSION.')','ok'=>$phpOk,'warn'=>false,'note'=>$phpOk?'':'PHP 7.4+ required'];

        $pdoOk = extension_loaded('pdo_mysql');
        $results[] = ['name'=>'PDO MySQL Extension','ok'=>$pdoOk,'warn'=>false,'note'=>$pdoOk?'':'Install php-mysql / php-pdo'];

        $jsonOk = extension_loaded('json');
        $results[] = ['name'=>'JSON Extension','ok'=>$jsonOk,'warn'=>false,'note'=>''];

        $mbOk = extension_loaded('mbstring');
        $results[] = ['name'=>'Mbstring Extension','ok'=>$mbOk,'warn'=>false,'note'=>$mbOk?'':'Install php-mbstring'];

        $sslOk = extension_loaded('openssl');
        $results[] = ['name'=>'OpenSSL Extension','ok'=>$sslOk,'warn'=>false,'note'=>$sslOk?'':'Install php-openssl (needed for SMTP SSL)'];

        $imapOk = extension_loaded('imap');
        $results[] = ['name'=>'IMAP Extension (optional)','ok'=>$imapOk,'warn'=>!$imapOk,'note'=>$imapOk?'':'Optional — needed for Auto-Reply inbox monitoring'];

        $dirWritable = is_writable(__DIR__);
        $cfgWritable = !file_exists(CONFIG_FILE) ? $dirWritable : is_writable(CONFIG_FILE);
        $results[] = ['name'=>'config.json Writable','ok'=>$cfgWritable,'warn'=>false,'note'=>$cfgWritable?'':'Run: chmod 666 config.json  (or 777 on the app folder)'];

        $uploadDir = __DIR__ . '/uploads/images';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        $uploadOk = is_dir($uploadDir) && is_writable($uploadDir);
        $results[] = ['name'=>'uploads/images/ Writable','ok'=>$uploadOk,'warn'=>false,'note'=>$uploadOk?'':'Run: chmod -R 755 uploads/'];

        $sessOk = function_exists('session_start');
        $results[] = ['name'=>'Session Support','ok'=>$sessOk,'warn'=>false,'note'=>''];

        $allOk = !in_array(false, array_column(array_filter($results, fn($r)=>!$r['warn']), 'ok'));
        echo json_encode(['ok'=>$allOk,'results'=>$results]);
        exit;
    }

    // ── Test DB Connection ─────────────────────────────────────────
    if ($action === 'check-db') {
        try {
            $dsn = "mysql:host={$b['db_host']};port={$b['db_port']};dbname={$b['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $b['db_user'], $b['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
            echo json_encode(['ok'=>true,'message'=>'✅ Connected! MySQL/MariaDB '.$ver]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'message'=>'❌ '.$e->getMessage()]);
        }
        exit;
    }

    // ── Full Installation ──────────────────────────────────────────
    if ($action === 'run') {
        try {
            $dsn = "mysql:host={$b['db_host']};port={$b['db_port']};dbname={$b['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $b['db_user'], $b['db_pass'], [
                PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // ── CREATE TABLES ──────────────────────────────────────────
            $tables = [

                // 1. USERS
                "CREATE TABLE IF NOT EXISTS `users` (
                    `id`               INT AUTO_INCREMENT PRIMARY KEY,
                    `username`         VARCHAR(100) NOT NULL UNIQUE,
                    `password`         VARCHAR(255) NOT NULL,
                    `is_admin`         TINYINT(1) DEFAULT 0,
                    `smtp_limit`       INT DEFAULT 5,
                    `campaign_limit`   INT DEFAULT 10,
                    `daily_send_limit` INT DEFAULT 1000,
                    `imap_read_limit`  INT DEFAULT 0 COMMENT '0 = use global imap_read_per_minute setting',
                    `expires_at`       DATETIME DEFAULT NULL,
                    `status`           ENUM('active','suspended') DEFAULT 'active',
                    `remember_token`   VARCHAR(64) DEFAULT NULL,
                    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 2. SMTP PROVIDERS
                "CREATE TABLE IF NOT EXISTS `smtp_providers` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`     INT NOT NULL DEFAULT 1,
                    `name`        VARCHAR(150) NOT NULL,
                    `host`        VARCHAR(255) NOT NULL,
                    `port`        INT DEFAULT 587,
                    `secure`      TINYINT(1) DEFAULT 0,
                    `username`    VARCHAR(255) DEFAULT NULL,
                    `password`    VARCHAR(255) DEFAULT NULL,
                    `from_email`  VARCHAR(255) DEFAULT NULL,
                    `from_name`   VARCHAR(150) DEFAULT NULL,
                    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 3. IMAGES
                "CREATE TABLE IF NOT EXISTS `images` (
                    `id`            INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`       INT NOT NULL DEFAULT 1,
                    `filename`      VARCHAR(255) NOT NULL,
                    `original_name` VARCHAR(255) DEFAULT NULL,
                    `mime`          VARCHAR(100) DEFAULT 'image/jpeg',
                    `url`           VARCHAR(500) DEFAULT NULL,
                    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 4. EMAIL LISTS
                "CREATE TABLE IF NOT EXISTS `email_lists` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`     INT NOT NULL DEFAULT 1,
                    `name`        VARCHAR(150) NOT NULL,
                    `total_count` INT DEFAULT 0,
                    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 5. EMAILS (contacts)
                "CREATE TABLE IF NOT EXISTS `emails` (
                    `id`      INT AUTO_INCREMENT PRIMARY KEY,
                    `list_id` INT NOT NULL,
                    `email`   VARCHAR(255) NOT NULL,
                    `name`    VARCHAR(150) DEFAULT NULL,
                    `status`  ENUM('active','unsubscribed') DEFAULT 'active',
                    UNIQUE KEY `uq_list_email` (`list_id`,`email`),
                    FOREIGN KEY (`list_id`) REFERENCES `email_lists`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 6. CAMPAIGNS
                "CREATE TABLE IF NOT EXISTS `campaigns` (
                    `id`               INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`          INT NOT NULL DEFAULT 1,
                    `name`             VARCHAR(150) NOT NULL,
                    `smtp_id`          INT DEFAULT NULL,
                    `smtp_ids`         TEXT DEFAULT NULL,
                    `from_emails`      TEXT DEFAULT NULL,
                    `list_id`          INT DEFAULT NULL,
                    `scheduled_at`     DATETIME DEFAULT NULL,
                    `per_minute_limit` INT DEFAULT 10,
                    `daily_limit`      INT DEFAULT 500,
                    `variants`         LONGTEXT DEFAULT NULL,
                    `sender_name`      VARCHAR(150) DEFAULT NULL,
                    `status`           ENUM('scheduled','running','paused','completed','failed') DEFAULT 'scheduled',
                    `sent_count`       INT DEFAULT 0,
                    `failed_count`     INT DEFAULT 0,
                    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`list_id`) REFERENCES `email_lists`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 7. SEND LOGS
                "CREATE TABLE IF NOT EXISTS `send_logs` (
                    `id`              INT AUTO_INCREMENT PRIMARY KEY,
                    `campaign_id`     INT DEFAULT NULL,
                    `user_id`         INT DEFAULT NULL,
                    `email`           VARCHAR(255) NOT NULL,
                    `status`          ENUM('sent','failed') NOT NULL,
                    `log_source`      VARCHAR(20) NOT NULL DEFAULT 'campaign' COMMENT 'campaign | autoreply | followup',
                    `error`           TEXT DEFAULT NULL,
                    `error_code`      VARCHAR(20) DEFAULT NULL,
                    `smtp_name_used`  VARCHAR(150) DEFAULT NULL,
                    `from_email_used` VARCHAR(255) DEFAULT NULL,
                    `variant_index`   INT DEFAULT NULL,
                    `sent_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_sl_campaign` (`campaign_id`),
                    INDEX `idx_sl_user`     (`user_id`),
                    INDEX `idx_sl_email`    (`email`),
                    INDEX `idx_sl_status`   (`status`),
                    INDEX `idx_sl_sent_at`  (`sent_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 8. IMAP ACCOUNTS
                "CREATE TABLE IF NOT EXISTS `imap_accounts` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`    INT NOT NULL DEFAULT 1,
                    `name`       VARCHAR(150) NOT NULL,
                    `host`       VARCHAR(255) NOT NULL,
                    `port`       INT DEFAULT 993,
                    `username`   VARCHAR(255) NOT NULL,
                    `password`   VARCHAR(255) NOT NULL,
                    `ssl`        TINYINT(1) DEFAULT 1,
                    `last_check` DATETIME DEFAULT NULL,
                    `status`     ENUM('active','disabled') DEFAULT 'active',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 9. AUTO-REPLY RULES
                "CREATE TABLE IF NOT EXISTS `autoreply_rules` (
                    `id`              INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`         INT NOT NULL DEFAULT 1,
                    `name`            VARCHAR(150) NOT NULL,
                    `imap_id`         INT DEFAULT NULL,
                    `smtp_ids`        TEXT DEFAULT NULL,
                    `from_emails`     TEXT DEFAULT NULL,
                    `status`          ENUM('active','paused') DEFAULT 'active',
                    `sequential_mode` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=message-triggered sequential replies',
                    `step1_smtp_ids`  TEXT DEFAULT NULL COMMENT 'Dedicated SMTP pool for Auto Reply 1 (first message only)',
                    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`imap_id`) REFERENCES `imap_accounts`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 10. AUTO-REPLY STEPS
                "CREATE TABLE IF NOT EXISTS `autoreply_steps` (
                    `id`            INT AUTO_INCREMENT PRIMARY KEY,
                    `rule_id`       INT NOT NULL,
                    `step_number`   INT NOT NULL DEFAULT 1,
                    `delay_minutes` INT NOT NULL DEFAULT 1,
                    `subject`       TEXT DEFAULT NULL,
                    `html_body`     LONGTEXT DEFAULT NULL,
                    `text_body`     LONGTEXT DEFAULT NULL,
                    `image_ids`     TEXT DEFAULT NULL,
                    `img_width`     VARCHAR(20) DEFAULT '600',
                    `img_align`     VARCHAR(10) DEFAULT 'center',
                    `img_position`  VARCHAR(10) DEFAULT 'top',
                    FOREIGN KEY (`rule_id`) REFERENCES `autoreply_rules`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 11. AUTO-REPLY THREADS
                "CREATE TABLE IF NOT EXISTS `autoreply_threads` (
                    `id`                INT AUTO_INCREMENT PRIMARY KEY,
                    `rule_id`           INT NOT NULL,
                    `from_email`        VARCHAR(255) NOT NULL,
                    `from_name`         VARCHAR(150) DEFAULT NULL,
                    `subject_in`        VARCHAR(255) DEFAULT NULL,
                    `current_step`      INT NOT NULL DEFAULT 1,
                    `next_send_at`      DATETIME DEFAULT NULL,
                    `last_sent_at`      DATETIME DEFAULT NULL,
                    `reply_count`       INT NOT NULL DEFAULT 0,
                    `messages_received` INT NOT NULL DEFAULT 1 COMMENT 'Total inbound messages from contact',
                    `awaiting_reply`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=waiting for next user message',
                    `status`            ENUM('active','completed') DEFAULT 'active',
                    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_rule_email` (`rule_id`,`from_email`),
                    FOREIGN KEY (`rule_id`) REFERENCES `autoreply_rules`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 12. AUTO-REPLY LOGS
                "CREATE TABLE IF NOT EXISTS `autoreply_logs` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `rule_id`     INT NOT NULL,
                    `thread_id`   INT NOT NULL,
                    `step_number` INT NOT NULL,
                    `to_email`    VARCHAR(255) NOT NULL,
                    `status`      ENUM('sent','failed') NOT NULL,
                    `error`       TEXT DEFAULT NULL,
                    `smtp_used`   VARCHAR(150) DEFAULT NULL,
                    `sent_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`rule_id`) REFERENCES `autoreply_rules`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 13. FOLLOW-UP RULES
                "CREATE TABLE IF NOT EXISTS `followup_rules` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`     INT NOT NULL DEFAULT 1,
                    `name`        VARCHAR(150) NOT NULL,
                    `imap_id`     INT DEFAULT NULL,
                    `smtp_ids`    TEXT DEFAULT NULL,
                    `from_emails` TEXT DEFAULT NULL,
                    `status`      ENUM('active','paused') DEFAULT 'active',
                    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 14. FOLLOW-UP STEPS
                "CREATE TABLE IF NOT EXISTS `followup_steps` (
                    `id`            INT AUTO_INCREMENT PRIMARY KEY,
                    `rule_id`       INT NOT NULL,
                    `step_number`   INT NOT NULL DEFAULT 1,
                    `delay_minutes` INT NOT NULL DEFAULT 60,
                    `subject`       TEXT DEFAULT NULL,
                    `html_body`     LONGTEXT DEFAULT NULL,
                    `text_body`     LONGTEXT DEFAULT NULL,
                    `image_ids`     TEXT DEFAULT NULL,
                    `img_width`     VARCHAR(20) DEFAULT '600',
                    `img_align`     VARCHAR(10) DEFAULT 'center',
                    `img_position`  VARCHAR(10) DEFAULT 'top',
                    FOREIGN KEY (`rule_id`) REFERENCES `followup_rules`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 15. FOLLOW-UP CONTACTS
                "CREATE TABLE IF NOT EXISTS `followup_contacts` (
                    `id`           INT AUTO_INCREMENT PRIMARY KEY,
                    `rule_id`      INT NOT NULL,
                    `email`        VARCHAR(255) NOT NULL,
                    `name`         VARCHAR(150) DEFAULT NULL,
                    `current_step` INT NOT NULL DEFAULT 1,
                    `next_send_at` DATETIME DEFAULT NULL,
                    `last_sent_at` DATETIME DEFAULT NULL,
                    `status`       ENUM('active','completed','stopped') DEFAULT 'active',
                    `enrolled_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_fu_email` (`rule_id`,`email`),
                    FOREIGN KEY (`rule_id`) REFERENCES `followup_rules`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 16. FOLLOW-UP LOGS
                "CREATE TABLE IF NOT EXISTS `followup_logs` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `rule_id`     INT NOT NULL,
                    `contact_id`  INT NOT NULL,
                    `step_number` INT NOT NULL,
                    `email`       VARCHAR(255) NOT NULL,
                    `status`      ENUM('sent','failed') NOT NULL,
                    `error`       TEXT DEFAULT NULL,
                    `smtp_used`   VARCHAR(150) DEFAULT NULL,
                    `sent_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`rule_id`) REFERENCES `followup_rules`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 17. BACKUP EMAILS — permanent store; used for 7-day duplicate detection
                "CREATE TABLE IF NOT EXISTS `backup_emails` (
                    `id`              INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`         INT NOT NULL DEFAULT 1,
                    `email`           VARCHAR(255) NOT NULL,
                    `name`            VARCHAR(150) DEFAULT NULL,
                    `source`          ENUM('autoreply','followup') NOT NULL DEFAULT 'autoreply',
                    `rule_id`         INT DEFAULT NULL,
                    `first_seen`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `last_replied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_backup_user_email` (`user_id`,`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 18. EMAIL FOLLOW-UP QUEUE (Read-based sequential execution)
                "CREATE TABLE IF NOT EXISTS `email_followup_queue` (
                    `id`                  BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`             INT NOT NULL DEFAULT 1,
                    `campaign_id`         INT DEFAULT NULL,
                    `rule_id`             INT DEFAULT NULL,
                    `contact_id`          INT DEFAULT NULL,
                    `recipient_email`     VARCHAR(255) NOT NULL,
                    `recipient_name`      VARCHAR(150) DEFAULT NULL,
                    `followup_order`      INT NOT NULL DEFAULT 1,
                    `delay_value`         INT NOT NULL DEFAULT 30,
                    `delay_unit`          ENUM('minutes','hours','days') NOT NULL DEFAULT 'minutes',
                    `delay_in_minutes`    INT NOT NULL DEFAULT 30,
                    `scheduled_at`        DATETIME DEFAULT NULL,
                    `opened_at`           DATETIME DEFAULT NULL,
                    `followup_started_at` DATETIME DEFAULT NULL,
                    `sent_at`             DATETIME DEFAULT NULL,
                    `status`              ENUM('pending','scheduled','sending','sent','failed','cancelled','skipped') NOT NULL DEFAULT 'pending',
                    `retry_count`         INT NOT NULL DEFAULT 0,
                    `timezone`            VARCHAR(64) DEFAULT 'UTC',
                    `last_error`          TEXT DEFAULT NULL,
                    `tracking_token`      VARCHAR(64) UNIQUE NOT NULL,
                    `locked_at`           DATETIME DEFAULT NULL,
                    `lock_token`          VARCHAR(64) DEFAULT NULL,
                    `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_efq_status_sched` (`status`, `scheduled_at`),
                    INDEX `idx_efq_token` (`tracking_token`),
                    INDEX `idx_efq_rule_email` (`rule_id`, `recipient_email`),
                    INDEX `idx_efq_user_status` (`user_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 19. EMAIL TEMPLATES
                "CREATE TABLE IF NOT EXISTS `email_templates` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`    INT NOT NULL DEFAULT 1,
                    `name`       VARCHAR(150) NOT NULL,
                    `subject`    VARCHAR(255) DEFAULT NULL,
                    `html_body`  LONGTEXT DEFAULT NULL,
                    `text_body`  LONGTEXT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_tmpl_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 20. UNIFIED SYSTEM LOGS
                "CREATE TABLE IF NOT EXISTS `system_logs` (
                    `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`         INT DEFAULT NULL,
                    `campaign_id`     INT DEFAULT NULL,
                    `rule_id`         INT DEFAULT NULL,
                    `queue_id`        BIGINT DEFAULT NULL,
                    `tracking_token`  VARCHAR(64) DEFAULT NULL,
                    `recipient_email` VARCHAR(255) NOT NULL,
                    `event_type`      ENUM('queued','sent','opened','clicked','bounced','complaint','unsubscribed','failed','retry') NOT NULL,
                    `smtp_server`     VARCHAR(150) DEFAULT NULL,
                    `ip_address`      VARCHAR(45) DEFAULT NULL,
                    `user_agent`      VARCHAR(500) DEFAULT NULL,
                    `details`         TEXT DEFAULT NULL,
                    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_slog_event_created` (`event_type`, `created_at`),
                    INDEX `idx_slog_email` (`recipient_email`),
                    INDEX `idx_slog_token` (`tracking_token`),
                    INDEX `idx_slog_user_created` (`user_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 21. MAIL ROUTING LOGS
                "CREATE TABLE IF NOT EXISTS `mail_routing_logs` (
                    `id`               BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`          INT DEFAULT NULL,
                    `rule_id`          INT DEFAULT NULL,
                    `thread_id`        VARCHAR(255) DEFAULT NULL,
                    `email`            VARCHAR(255) NOT NULL,
                    `event_type`       VARCHAR(64) NOT NULL,
                    `incoming_mailbox` VARCHAR(255) DEFAULT NULL,
                    `smtp_used`        VARCHAR(255) DEFAULT NULL,
                    `reply_to_address` VARCHAR(255) DEFAULT NULL,
                    `stage_before`     VARCHAR(64) DEFAULT NULL,
                    `stage_after`      VARCHAR(64) DEFAULT NULL,
                    `delivery_status`  VARCHAR(64) DEFAULT 'success',
                    `details`          TEXT DEFAULT NULL,
                    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_mrl_email` (`email`),
                    INDEX `idx_mrl_event` (`event_type`, `created_at`),
                    INDEX `idx_mrl_user` (`user_id`, `created_at`),
                    INDEX `idx_mrl_thread` (`thread_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // 22. MAIL ROUTING QUEUE
                "CREATE TABLE IF NOT EXISTS `mail_routing_queue` (
                    `id`           BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`      INT NOT NULL,
                    `queue_type`   ENUM('incoming-mail','auto-reply','followup-mail','mailbox-routing','webhook-events') NOT NULL,
                    `payload`      LONGTEXT NOT NULL,
                    `status`       ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
                    `attempts`     INT NOT NULL DEFAULT 0,
                    `max_attempts` INT NOT NULL DEFAULT 3,
                    `scheduled_at` DATETIME NOT NULL,
                    `locked_at`    DATETIME DEFAULT NULL,
                    `lock_token`   VARCHAR(64) DEFAULT NULL,
                    `last_error`   TEXT DEFAULT NULL,
                    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_mrq_status_sched` (`status`, `scheduled_at`),
                    INDEX `idx_mrq_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ];

            foreach ($tables as $sql) $pdo->exec($sql);

            // ── MIGRATIONS (safe for existing installs) ────────────────
            $migrations = [
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_admin` TINYINT(1) DEFAULT 0",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `smtp_limit` INT DEFAULT 10",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `imap_limit` INT DEFAULT 10",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `campaign_limit` INT DEFAULT 10",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `daily_send_limit` INT DEFAULT 1000",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `imap_read_limit` INT DEFAULT 0 COMMENT '0 = use global imap_read_per_minute setting'",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `expires_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `status` ENUM('active','suspended') DEFAULT 'active'",
                "ALTER TABLE `smtp_providers` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL DEFAULT 1",
                "ALTER TABLE `email_lists` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL DEFAULT 1",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL DEFAULT 1",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `smtp_ids` TEXT DEFAULT NULL",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `from_emails` TEXT DEFAULT NULL",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `variants` LONGTEXT DEFAULT NULL",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `sent_count` INT DEFAULT 0",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `failed_count` INT DEFAULT 0",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `sender_name` VARCHAR(150) DEFAULT NULL",
                "ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `followup_rule_id` INT DEFAULT NULL",
                "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `smtp_name_used` VARCHAR(150) DEFAULT NULL",
                "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `from_email_used` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `error_code` VARCHAR(20) DEFAULT NULL",
                "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `variant_index` INT DEFAULT NULL",
                "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `log_source` VARCHAR(20) NOT NULL DEFAULT 'campaign' COMMENT 'campaign | autoreply | followup'",
                "ALTER TABLE `emails` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `imap_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `smtp_ids` TEXT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `from_emails` TEXT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `step1_smtp_ids` TEXT DEFAULT NULL COMMENT 'Dedicated SMTP pool for Auto Reply 1 (first message only)'",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `enable_smart_routing` TINYINT(1) NOT NULL DEFAULT 0",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `primary_imap_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `secondary_imap_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `backup_imap_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `primary_smtp_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `secondary_smtp_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `enable_reply_to_switch` TINYINT(1) NOT NULL DEFAULT 1",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `enable_always_send_followup` TINYINT(1) NOT NULL DEFAULT 1",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `enable_gmail_priority` TINYINT(1) NOT NULL DEFAULT 1",
                "ALTER TABLE `autoreply_rules` ADD COLUMN IF NOT EXISTS `followup_rule_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `active_mailbox` ENUM('primary','secondary','backup') NOT NULL DEFAULT 'primary'",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `first_reply_sent` TINYINT(1) NOT NULL DEFAULT 0",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `reply_to_mailbox` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `smtp_used` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `imap_source` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `thread_id` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `original_message_id` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `last_message_id` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `references_header` TEXT DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `followup_status` ENUM('pending','running','completed','cancelled') DEFAULT 'pending'",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `followup_next_run` DATETIME DEFAULT NULL",
                "ALTER TABLE `autoreply_threads` ADD COLUMN IF NOT EXISTS `conversation_stage` ENUM('NEW_LEAD','FIRST_REPLY_SENT','MOVED_TO_SECONDARY','FOLLOWUP_RUNNING','FOLLOWUP_COMPLETED') NOT NULL DEFAULT 'NEW_LEAD'",
                "ALTER TABLE `inbound_emails` ADD COLUMN IF NOT EXISTS `message_id` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `inbound_emails` ADD COLUMN IF NOT EXISTS `in_reply_to` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `inbound_emails` ADD COLUMN IF NOT EXISTS `references_header` TEXT DEFAULT NULL",
                "ALTER TABLE `inbound_emails` ADD COLUMN IF NOT EXISTS `thread_id` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `inbound_emails` ADD COLUMN IF NOT EXISTS `body` LONGTEXT DEFAULT NULL",
                "ALTER TABLE `send_logs` ADD COLUMN IF NOT EXISTS `user_id` INT DEFAULT NULL",
                "ALTER TABLE `autoreply_steps` ADD COLUMN IF NOT EXISTS `delay_minutes` INT NOT NULL DEFAULT 1",
                "ALTER TABLE `autoreply_steps` ADD COLUMN IF NOT EXISTS `image_ids` TEXT DEFAULT NULL",
                "ALTER TABLE `autoreply_steps` ADD COLUMN IF NOT EXISTS `img_width` VARCHAR(20) DEFAULT '600'",
                "ALTER TABLE `autoreply_steps` ADD COLUMN IF NOT EXISTS `img_align` VARCHAR(10) DEFAULT 'center'",
                "ALTER TABLE `autoreply_steps` ADD COLUMN IF NOT EXISTS `img_position` VARCHAR(10) DEFAULT 'top'",
                "ALTER TABLE `followup_rules` ADD COLUMN IF NOT EXISTS `imap_id` INT DEFAULT NULL",
                "ALTER TABLE `followup_rules` ADD COLUMN IF NOT EXISTS `smtp_ids` TEXT DEFAULT NULL",
                "ALTER TABLE `followup_rules` ADD COLUMN IF NOT EXISTS `from_emails` TEXT DEFAULT NULL",
                "ALTER TABLE `followup_rules` ADD COLUMN IF NOT EXISTS `trigger_on_open` TINYINT(1) NOT NULL DEFAULT 1",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `delay_minutes` INT NOT NULL DEFAULT 60",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `delay_value` INT NOT NULL DEFAULT 30",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `delay_unit` ENUM('minutes','hours','days') NOT NULL DEFAULT 'minutes'",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `image_ids` TEXT DEFAULT NULL",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `img_width` VARCHAR(20) DEFAULT '600'",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `img_align` VARCHAR(10) DEFAULT 'center'",
                "ALTER TABLE `followup_steps` ADD COLUMN IF NOT EXISTS `img_position` VARCHAR(10) DEFAULT 'top'",
                "ALTER TABLE `followup_contacts` ADD COLUMN IF NOT EXISTS `opened_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `followup_contacts` ADD COLUMN IF NOT EXISTS `followup_started_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `followup_contacts` ADD COLUMN IF NOT EXISTS `tracking_token` VARCHAR(64) DEFAULT NULL",
                "ALTER TABLE `followup_contacts` ADD COLUMN IF NOT EXISTS `open_count` INT NOT NULL DEFAULT 0",
                "ALTER TABLE `followup_contacts` ADD COLUMN IF NOT EXISTS `click_count` INT NOT NULL DEFAULT 0",
                "CREATE TABLE IF NOT EXISTS `backup_emails` (`id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` INT NOT NULL DEFAULT 1, `email` VARCHAR(255) NOT NULL, `name` VARCHAR(150) DEFAULT NULL, `source` ENUM('autoreply','followup') NOT NULL DEFAULT 'autoreply', `rule_id` INT DEFAULT NULL, `first_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `last_replied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY `uq_backup_user_email` (`user_id`,`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];

            foreach ($migrations as $sql) {
                try { $pdo->exec($sql); } catch (Exception $e) { /* column already exists — ignore */ }
            }

            // ── ADMIN USER (upsert) ────────────────────────────────────
            $hash = password_hash($b['admin_pass'], PASSWORD_BCRYPT);
            $pdo->prepare(
                "INSERT INTO `users` (username,password,is_admin,smtp_limit,campaign_limit,daily_send_limit)
                 VALUES (?,?,1,9999,9999,9999999)
                 ON DUPLICATE KEY UPDATE
                   password=VALUES(password),is_admin=1,
                   smtp_limit=9999,campaign_limit=9999,daily_send_limit=9999999"
            )->execute([$b['admin_user'], $hash]);

            // ── UPLOADS DIR ────────────────────────────────────────────
            $upDir = __DIR__ . '/uploads/images';
            if (!is_dir($upDir)) @mkdir($upDir, 0755, true);

            // ── CONFIG.JSON ────────────────────────────────────────────
            $cronKey = bin2hex(random_bytes(16));
            $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $baseUrl = $proto . '://' . $host . $dir;

            $config = [
                'installed'    => true,
                'site_name'    => trim($b['site_name'] ?? 'MailsZo') ?: 'MailsZo',
                'db_host'      => $b['db_host'],
                'db_port'      => (int)($b['db_port'] ?? 3306),
                'db_name'      => $b['db_name'],
                'db_user'      => $b['db_user'],
                'db_pass'      => $b['db_pass'],
                'cron_key'     => $cronKey,
                'base_url'     => $baseUrl,
                'app_path'     => __DIR__,
                'installed_at' => date('c'),
            ];

            if (file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT)) === false)
                throw new Exception('Cannot write config.json — run: chmod 666 ' . CONFIG_FILE);

            echo json_encode([
                'ok'       => true,
                'cron_url' => $baseUrl . '/cron.php?key=' . $cronKey,
            ]);

        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'message'=>'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MailsZo v4 — Installer</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Mulish:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#07090f;--bg2:#0d1117;--bg3:#131a24;
  --border:#1c2535;--border2:#22304a;
  --accent:#22d3ee;--green:#34d399;--red:#f87171;--amber:#fbbf24;--purple:#a78bfa;
  --text:#dde6f0;--text2:#7a8fa8;--text3:#344055;
  --font:'Mulish',sans-serif;--display:'Syne',sans-serif
}
body{font-family:var(--font);background:radial-gradient(ellipse at 15% 50%,rgba(34,211,238,.07),transparent 55%),var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:32px 16px}
.wrap{width:100%;max-width:580px}

/* Header */
.hd{text-align:center;margin-bottom:32px}
.logo{display:inline-flex;align-items:center;gap:14px;margin-bottom:14px}
.logo-ic{width:56px;height:56px;background:linear-gradient(135deg,rgba(34,211,238,.18),rgba(52,211,153,.1));border:1px solid rgba(34,211,238,.35);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:26px}
.logo-tx{font-family:var(--display);font-size:30px;font-weight:800}
.logo-tx span{color:var(--accent)}
.hd p{color:var(--text2);font-size:13px;margin-top:4px}

/* Steps */
.steps{display:flex;align-items:center;justify-content:center;margin-bottom:28px}
.st{display:flex;flex-direction:column;align-items:center;gap:6px}
.sn{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;border:2px solid var(--border);color:var(--text3);background:var(--bg2);transition:all .3s}
.st.act .sn{border-color:var(--accent);color:var(--accent);background:rgba(34,211,238,.08);box-shadow:0 0 14px rgba(34,211,238,.2)}
.st.dn .sn{border-color:var(--green);background:var(--green);color:#000}
.sl{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.07em}
.st.act .sl{color:var(--accent)}.st.dn .sl{color:var(--green)}
.sline{flex:1;height:2px;background:var(--border);margin-bottom:20px;max-width:50px;transition:background .4s}
.sline.dn{background:var(--green)}

/* Card */
.card{background:var(--bg2);border:1px solid var(--border2);border-radius:12px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.45);margin-bottom:20px}
.ch{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.ch-ic{font-size:22px;flex-shrink:0}
.ch h2{font-family:var(--display);font-size:18px;font-weight:700;margin-bottom:2px}
.ch p{font-size:12px;color:var(--text2)}
.cb{padding:24px}

/* Form */
.fg{margin-bottom:16px}
.fl{display:block;font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.fi{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-family:var(--font);font-size:13px;outline:none;transition:border-color .2s}
.fi:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(34,211,238,.08)}
.fi::placeholder{color:var(--text3)}
.gr{display:grid;gap:12px}.g2{grid-template-columns:1fr 1fr}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:11px 22px;border-radius:8px;border:none;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;width:100%}
.bp{background:var(--accent);color:#000}.bp:hover:not(:disabled){background:#38e8ff;box-shadow:0 4px 18px rgba(34,211,238,.3)}
.bs{background:transparent;color:var(--text2);border:1px solid var(--border2)}.bs:hover:not(:disabled){background:var(--bg3);color:var(--text)}
.btn:disabled{opacity:.35;cursor:not-allowed}
.btn-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px}
.btn-sm{padding:8px 14px;font-size:12px;width:auto}

/* Alerts */
.al{padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;display:none;line-height:1.5}
.al.on{display:block}
.a-ok{background:rgba(52,211,153,.07);border:1px solid rgba(52,211,153,.22);color:var(--green)}
.a-err{background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.22);color:var(--red)}
.a-warn{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.22);color:var(--amber)}

/* Requirements */
.req-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.ri{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;font-size:13px}
.ri.ok .ic{color:var(--green)}.ri.fail .ic{color:var(--red)}.ri.warn .ic{color:var(--amber)}
.ri .rname{flex:1;font-weight:600}
.ri .rnote{font-size:11px;color:var(--text3)}
.ri.fail .rnote{color:var(--red)}.ri.warn .rnote{color:var(--amber)}

/* Log box */
.logbox{background:#060810;border:1px solid var(--border);border-radius:8px;padding:14px;font-family:'Courier New',monospace;font-size:12px;min-height:170px;max-height:270px;overflow-y:auto;margin-bottom:16px;line-height:2;color:var(--text3)}
.lk{color:var(--green)}.le{color:var(--red)}.li{color:var(--accent)}.ld{color:var(--text3)}.lw{color:var(--amber)}

/* Cron + info boxes */
.cron-box{background:#06080e;border:1px solid rgba(34,211,238,.2);border-radius:8px;padding:12px 16px;font-family:'Courier New',monospace;font-size:11px;color:var(--accent);word-break:break-all;line-height:1.8;margin:10px 0;cursor:pointer;user-select:all}
.cron-box:hover{border-color:var(--accent)}
.warn-box{background:rgba(251,191,36,.05);border:1px solid rgba(251,191,36,.2);border-radius:8px;padding:14px;font-size:12px;color:var(--amber);margin:14px 0;line-height:1.8}
.info-box{background:rgba(34,211,238,.04);border:1px solid rgba(34,211,238,.15);border-radius:8px;padding:14px;font-size:12px;color:var(--text2);margin:14px 0;line-height:1.7}
.success-list{background:rgba(52,211,153,.05);border:1px solid rgba(52,211,153,.2);border-radius:8px;padding:14px;font-size:13px;color:var(--green);line-height:2;margin-bottom:20px}

/* Success */
.sbox{text-align:center;padding:8px 0 4px}
.sbox-ic{font-size:60px;margin-bottom:18px}
.sbox h2{font-family:var(--display);font-size:26px;font-weight:800;margin-bottom:8px}
.sbox p{color:var(--text2);font-size:13px;margin-bottom:22px}

/* Spinner */
.spin-ic{display:inline-block;width:13px;height:13px;border:2px solid rgba(0,0,0,.25);border-top-color:currentColor;border-radius:50%;animation:rot .65s linear infinite}
.spin-w{border-color:rgba(255,255,255,.2);border-top-color:var(--text2)}
@keyframes rot{to{transform:rotate(360deg)}}

/* Reinstall notice */
.reinstall-notice{background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.25);border-radius:8px;padding:12px 16px;font-size:12px;color:var(--purple);margin-bottom:20px}
</style>
</head>
<body>
<div class="wrap">

  <div class="hd">
    <div class="logo">
      <div class="logo-ic">✉</div>
      <div class="logo-tx">Mail<span>Pro</span> <span style="font-size:18px;opacity:.4;font-family:var(--font);font-weight:400">v4</span></div>
    </div>
    <p>Installation Wizard — Multi-User Email Platform</p>
  </div>

  <div class="steps" id="steps">
    <div class="st act" id="s1"><div class="sn">1</div><div class="sl">Requirements</div></div>
    <div class="sline" id="l1"></div>
    <div class="st" id="s2"><div class="sn">2</div><div class="sl">Database</div></div>
    <div class="sline" id="l2"></div>
    <div class="st" id="s3"><div class="sn">3</div><div class="sl">Admin</div></div>
    <div class="sline" id="l3"></div>
    <div class="st" id="s4"><div class="sn">4</div><div class="sl">Install</div></div>
    <div class="sline" id="l4"></div>
    <div class="st" id="s5"><div class="sn">5</div><div class="sl">Done!</div></div>
  </div>

  <?php if (!empty($cfg['installed'])): ?>
  <div class="reinstall-notice">
    ⚠️ <strong>Already installed.</strong> You are re-running the installer (force mode).
    Your data is safe — tables use <code>CREATE IF NOT EXISTS</code> and migrations use <code>ADD COLUMN IF NOT EXISTS</code>.
    Only the admin password will be updated.
  </div>
  <?php endif; ?>

  <!-- ── STEP 1 — Requirements ──────────────────────────────────── -->
  <div class="card" id="step1">
    <div class="ch"><div class="ch-ic">🔍</div><div><h2>System Requirements</h2><p>Checking your server environment</p></div></div>
    <div class="cb">
      <div class="al" id="req-al"></div>
      <div id="req-list" class="req-list">
        <div style="text-align:center;color:var(--text3);padding:24px;font-size:13px">
          <span class="spin-ic spin-w"></span>&nbsp; Checking requirements…
        </div>
      </div>
      <button class="btn bp" id="btn-req-next" onclick="go(2)" disabled>Continue →</button>
    </div>
  </div>

  <!-- ── STEP 2 — Database ─────────────────────────────────────── -->
  <div class="card" id="step2" style="display:none">
    <div class="ch"><div class="ch-ic">🗄️</div><div><h2>Database Connection</h2><p>MySQL / MariaDB credentials</p></div></div>
    <div class="cb">
      <div class="al" id="db-al"></div>
      <div class="gr g2">
        <div class="fg"><label class="fl">DB Host</label>
          <input class="fi" id="db_host" value="127.0.0.1" placeholder="127.0.0.1">
        </div>
        <div class="fg"><label class="fl">DB Port</label>
          <input class="fi" id="db_port" type="number" value="3306" min="1" max="65535">
        </div>
      </div>
      <div class="fg"><label class="fl">Database Name *</label>
        <input class="fi" id="db_name" placeholder="mailszo_db">
      </div>
      <div class="gr g2">
        <div class="fg"><label class="fl">DB Username *</label>
          <input class="fi" id="db_user" placeholder="mailszo_user">
        </div>
        <div class="fg"><label class="fl">DB Password</label>
          <input class="fi" id="db_pass" type="password" placeholder="(leave blank if none)">
        </div>
      </div>
      <div class="info-box">
        💡 The database must already exist. Create it first via <strong>phpMyAdmin</strong> or:<br>
        <code style="background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.2);border-radius:4px;padding:2px 8px;font-size:11px;color:var(--accent)">CREATE DATABASE mailszo_db CHARACTER SET utf8mb4;</code>
      </div>
      <div style="margin-bottom:14px">
        <button class="btn bs btn-sm" onclick="testDB()">🔍 Test Connection</button>
      </div>
      <div class="btn-row">
        <button class="btn bs" onclick="go(1)">← Back</button>
        <button class="btn bp" onclick="go(3)">Continue →</button>
      </div>
    </div>
  </div>

  <!-- ── STEP 3 — Admin Account ────────────────────────────────── -->
  <div class="card" id="step3" style="display:none">
    <div class="ch"><div class="ch-ic">🔐</div><div><h2>Admin Account</h2><p>Create your super-admin login</p></div></div>
    <div class="cb">
      <div class="al" id="adm-al"></div>
      <div class="fg"><label class="fl">Site Name <span style="text-transform:none;font-weight:400;letter-spacing:0;color:var(--text3)">(shown in the UI)</span></label>
        <input class="fi" id="site_name" value="MailsZo" placeholder="My Mail Platform">
      </div>
      <div class="fg"><label class="fl">Admin Username *</label>
        <input class="fi" id="admin_user" value="admin" placeholder="admin">
      </div>
      <div class="gr g2">
        <div class="fg"><label class="fl">Password * <span style="text-transform:none;font-weight:400;letter-spacing:0;color:var(--text3)">(min 6 chars)</span></label>
          <input class="fi" id="admin_pass" type="password" placeholder="••••••••" autocomplete="new-password">
        </div>
        <div class="fg"><label class="fl">Confirm Password *</label>
          <input class="fi" id="admin_pass2" type="password" placeholder="••••••••" autocomplete="new-password">
        </div>
      </div>
      <div class="btn-row">
        <button class="btn bs" onclick="go(2)">← Back</button>
        <button class="btn bp" onclick="go(4)">Continue →</button>
      </div>
    </div>
  </div>

  <!-- ── STEP 4 — Install ──────────────────────────────────────── -->
  <div class="card" id="step4" style="display:none">
    <div class="ch"><div class="ch-ic">⚙️</div><div><h2>Installing MailsZo v4</h2><p>Creating tables &amp; saving configuration</p></div></div>
    <div class="cb">
      <div class="logbox" id="ilog"><div class="ld">— Ready — click Install Now to begin —</div></div>
      <div class="btn-row">
        <button class="btn bs" id="btn-back4" onclick="go(3)">← Back</button>
        <button class="btn bp" id="btn-inst" onclick="runInstall()">🚀 Install Now</button>
      </div>
    </div>
  </div>

  <!-- ── STEP 5 — Done! ────────────────────────────────────────── -->
  <div class="card" id="step5" style="display:none">
    <div class="cb">
      <div class="sbox">
        <div class="sbox-ic">🎉</div>
        <h2>Installation Complete!</h2>
        <p>MailsZo v4 is ready. Sign in with your admin credentials.</p>

        <div class="success-list">
          ✅ &nbsp;16 database tables created (migrations applied)<br>
          ✅ &nbsp;Admin account saved<br>
          ✅ &nbsp;<code style="color:inherit">config.json</code> written to disk<br>
          ✅ &nbsp;<code style="color:inherit">uploads/images/</code> directory ready
        </div>

        <div class="warn-box">
          <strong>⚙️ Set up Cron Job — run every minute:</strong><br><br>
          <strong>cPanel:</strong> Cron Jobs → Every Minute → paste the curl command below<br>
          <strong>aaPanel:</strong> Cron → Access URL → Every 1 min → paste the URL below<br>
          <strong>Linux SSH:</strong> <code>crontab -e</code> → add the line below<br>
          <div class="cron-box" id="cron-url" onclick="copyCron(this)" title="Click to copy">—</div>
          <div id="copy-hint" style="font-size:11px;opacity:.65;margin-top:4px">🖱️ Click the URL above to copy it to your clipboard</div>
        </div>

        <button class="btn bp" onclick="location.href='index.php'" style="font-size:15px;padding:14px;margin-top:4px">
          Open Dashboard →
        </button>
      </div>
    </div>
  </div>

</div><!-- /.wrap -->

<script>
let DB = {}, ADM = {};

// ── Utilities ────────────────────────────────────────────────────────────────
function log(m, t = 'i') {
  const b = document.getElementById('ilog');
  b.innerHTML += `<div class="l${t}">${m}</div>`;
  b.scrollTop = b.scrollHeight;
}
function al(id, m, t) {
  const e = document.getElementById(id);
  if (!e) return;
  e.innerHTML = m;
  e.className = `al a-${t} on`;
  if (t !== 'err') setTimeout(() => { if (e) e.className = 'al'; }, 7000);
}
function al2(id) { const e = document.getElementById(id); if (e) e.className = 'al'; }
function v(id)   { return (document.getElementById(id)?.value || '').trim(); }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Step navigation ───────────────────────────────────────────────────────────
function go(n) {
  if (n === 3) {
    if (!v('db_name') || !v('db_user')) { al('db-al', '❌ Database name and username are required', 'err'); return; }
    DB = { db_host: v('db_host') || '127.0.0.1', db_port: v('db_port') || '3306', db_name: v('db_name'), db_user: v('db_user'), db_pass: document.getElementById('db_pass').value };
    al2('db-al');
  }
  if (n === 4) {
    const p1 = document.getElementById('admin_pass').value;
    const p2 = document.getElementById('admin_pass2').value;
    if (!v('admin_user'))    { al('adm-al', '❌ Admin username is required', 'err'); return; }
    if (p1.length < 6)       { al('adm-al', '❌ Password must be at least 6 characters', 'err'); return; }
    if (p1 !== p2)           { al('adm-al', '❌ Passwords do not match', 'err'); return; }
    ADM = { admin_user: v('admin_user'), admin_pass: p1, site_name: v('site_name') || 'MailsZo' };
    document.getElementById('ilog').innerHTML = '<div class="ld">— Ready — click Install Now to begin —</div>';
    al2('adm-al');
  }

  for (let i = 1; i <= 5; i++) {
    const card = document.getElementById('step' + i);
    const step = document.getElementById('s' + i);
    if (card) card.style.display = (i === n) ? 'block' : 'none';
    if (step) {
      step.className = 'st' + (i === n ? ' act' : i < n ? ' dn' : '');
      const sn = step.querySelector('.sn');
      if (sn) sn.textContent = (i < n) ? '✓' : i;
    }
  }
  for (let i = 1; i <= 4; i++) {
    const line = document.getElementById('l' + i);
    if (line) line.className = 'sline' + (i < n ? ' dn' : '');
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Requirements check ────────────────────────────────────────────────────────
async function checkRequirements() {
  const r = await api({ action: 'check-requirements' });
  const list = document.getElementById('req-list');

  if (!r || !r.results) {
    list.innerHTML = '<div style="color:var(--red);font-size:13px;padding:8px">❌ Could not run requirements check — check PHP error logs</div>';
    return;
  }

  list.innerHTML = r.results.map(item => {
    const cls  = item.ok ? 'ok' : (item.warn ? 'warn' : 'fail');
    const icon = item.ok ? '✅' : (item.warn ? '⚠️' : '❌');
    return `<div class="ri ${cls}">
      <span class="ic" style="font-size:16px;flex-shrink:0">${icon}</span>
      <span class="rname">${item.name}</span>
      ${item.note ? `<span class="rnote">${item.note}</span>` : ''}
    </div>`;
  }).join('');

  const hasBlocker = r.results.some(i => !i.ok && !i.warn);
  const btn = document.getElementById('btn-req-next');

  if (hasBlocker) {
    al('req-al', '❌ One or more requirements failed. Fix the issues above before continuing.', 'err');
    btn.disabled = true;
  } else {
    btn.disabled = false;
    if (r.results.some(i => i.warn && !i.ok)) {
      al('req-al', '⚠️ Some optional requirements are missing. You can continue, but some features (e.g. IMAP auto-reply) may not work.', 'warn');
    }
  }
}

// ── Test DB ───────────────────────────────────────────────────────────────────
async function testDB() {
  const btn = event.target;
  btn.innerHTML = '<span class="spin-ic"></span> Testing…';
  btn.disabled = true;
  al2('db-al');
  const r = await api({
    action: 'check-db',
    db_host: v('db_host') || '127.0.0.1',
    db_port: v('db_port') || '3306',
    db_name: v('db_name'),
    db_user: v('db_user'),
    db_pass: document.getElementById('db_pass').value
  });
  al('db-al', r.message, r.ok ? 'ok' : 'err');
  btn.textContent = '🔍 Test Connection';
  btn.disabled = false;
}

// ── Run Install ───────────────────────────────────────────────────────────────
async function runInstall() {
  const btn  = document.getElementById('btn-inst');
  const back = document.getElementById('btn-back4');
  btn.innerHTML = '<span class="spin-ic"></span> Installing…';
  btn.disabled = true;
  if (back) back.disabled = true;
  document.getElementById('ilog').innerHTML = '';

  const tables = [
    'users','smtp_providers','images','email_lists','emails',
    'campaigns','send_logs','imap_accounts',
    'autoreply_rules','autoreply_steps','autoreply_threads','autoreply_logs',
    'followup_rules','followup_steps','followup_contacts','followup_logs'
  ];

  log('▶ Connecting to database…', 'i');
  log(`  Host: ${DB.db_host}:${DB.db_port}   DB: ${DB.db_name}`, 'd');

  try {
    const r = await api({ action: 'run', ...DB, ...ADM });

    if (r.ok) {
      log('', 'd');
      log('── Creating Tables ──────────────────────────────', 'd');
      for (const t of tables) {
        await sleep(60);
        log(`  ✓  ${t}`, 'k');
      }
      log('', 'd');
      log('── Applying Migrations ──────────────────────────', 'd');
      await sleep(100);
      log('  ✓  Column additions applied (IF NOT EXISTS)', 'k');
      log('', 'd');
      log('── Final Setup ──────────────────────────────────', 'd');
      await sleep(80);
      log('  ✓  Admin user created / updated', 'k');
      await sleep(60);
      log('  ✓  config.json written', 'k');
      await sleep(60);
      log('  ✓  uploads/images/ directory ready', 'k');
      log('', 'd');
      log('🎉  Installation complete!', 'k');

      document.getElementById('cron-url').textContent = r.cron_url || '(Error generating cron URL — check config.json)';
      setTimeout(() => go(5), 1000);

    } else {
      log('', 'd');
      log('✗  Error: ' + (r.message || 'Unknown error'), 'e');
      btn.disabled = false;
      btn.textContent = '🚀 Install Now';
      if (back) back.disabled = false;
    }

  } catch (e) {
    log('✗  Exception: ' + e.message, 'e');
    btn.disabled = false;
    btn.textContent = '🚀 Install Now';
    if (back) back.disabled = false;
  }
}

// ── Copy Cron URL ─────────────────────────────────────────────────────────────
function copyCron(el) {
  const txt = el.textContent.trim();
  if (!txt || txt === '—') return;
  const hint = document.getElementById('copy-hint');
  navigator.clipboard?.writeText(txt).then(() => {
    if (hint) hint.textContent = '✅ Copied to clipboard!';
    setTimeout(() => { if (hint) hint.textContent = '🖱️ Click the URL above to copy it to your clipboard'; }, 3000);
  }).catch(() => {
    const ta = document.createElement('textarea');
    ta.value = txt; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    if (hint) { hint.textContent = '✅ Copied!'; setTimeout(() => { hint.textContent = '🖱️ Click the URL above to copy it to your clipboard'; }, 3000); }
  });
}

// ── API helper ────────────────────────────────────────────────────────────────
async function api(body) {
  try {
    const res = await fetch('install.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const txt = await res.text();
    try { return JSON.parse(txt); }
    catch (e) { return { ok: false, message: 'Server error: ' + txt.replace(/<[^>]+>/g, '').slice(0, 300) }; }
  } catch (e) {
    return { ok: false, message: 'Network error: ' + e.message };
  }
}

// Boot
checkRequirements();
</script>
</body>
</html>
