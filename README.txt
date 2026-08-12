╔══════════════════════════════════════════════════════════════╗
║            MailsZo v4 — Multi-User Email Platform            ║
╚══════════════════════════════════════════════════════════════╝

FEATURES
────────
• Multi-user with admin panel
• Campaigns with A/B variant sending
• Multi-SMTP rotation per campaign
• Auto-Reply chains (IMAP-triggered)
• Follow-Up sequences (time-based)
• Inline image support in all email types
• Send Log + Error Log with pagination
• Cron Manager with browser Auto-Run
• Image Library with per-email random selection

REQUIREMENTS
────────────
• PHP 8.0 or higher
• MySQL 5.7+ or MariaDB 10.3+
• PHP extensions: pdo_mysql, openssl, mbstring
• Web server: Apache / Nginx / LiteSpeed

FILES INCLUDED
──────────────
  index.php          — Main application (frontend + SPA)
  api.php            — REST API backend
  cron.php           — Cron worker (campaigns, autoreply, followup)
  install.php        — Web installer wizard
  includes/
    config.php       — DB connection, helpers, spin/personalize
    mailer.php       — Raw SMTP sender with inline image support
    imap.php         — IMAP poller (php-imap + raw socket fallback)
  uploads/
    images/          — Uploaded images go here (auto-created)
  .user.ini          — PHP limits (upload 20MB, exec 120s, 256MB RAM)
  README.txt         — This file

INSTALLATION
────────────
1. Upload ALL files to your web server (public_html or subdirectory)

2. Make sure these are writable by PHP:
      chmod 755 uploads/
      chmod 755 uploads/images/
   (The installer will write config.json to the root folder — 
    make sure the root folder is also writable)

3. Open in browser:
      https://yourdomain.com/install.php
      (or https://yourdomain.com/subfolder/install.php)

4. Follow the 4-step wizard:
      Step 1: Enter MySQL database credentials
      Step 2: Create admin username + password
      Step 3: Click "Install Now"
      Step 4: Copy your Cron URL

5. Set up the cron job (every minute):
   • cPanel:  Cron Jobs → Every Minute → paste curl command
   • aaPanel: Cron → Add Task → Access URL → 1 minute → paste URL
   • Linux:   crontab -e → add:
              * * * * * curl -s "https://yourdomain.com/cron.php?key=YOURKEY" > /dev/null

   Alternative: use the built-in Browser Auto-Run in the Cron Manager
   page (no server cron needed — runs while tab is open)

AFTER INSTALLATION
──────────────────
1. Log in at index.php with your admin credentials
2. Add SMTP Server(s) under "SMTP Servers"
3. Create an Email List and import contacts (CSV)
4. Create a Campaign, add variants, pick images, set schedule
5. Run Cron (manually or via server cron) to start sending

FOR AUTO-REPLY
──────────────
1. Add an IMAP Account under "IMAP Accounts"
2. Go to "Auto-Reply" → New Rule
3. Select your IMAP account + SMTP server(s)
4. Add reply steps with subject, body, and optional images
5. Set status to Active

FOR FOLLOW-UP SEQUENCES
────────────────────────
1. Go to "Follow-Up" → New Rule
2. Select SMTP server(s)
3. Add steps with delay times
4. Enroll contacts via CSV or existing list

FOLDER PERMISSIONS (Linux/cPanel)
──────────────────────────────────
  chmod 644 *.php
  chmod 644 includes/*.php
  chmod 755 uploads/
  chmod 755 uploads/images/
  chmod 644 .user.ini
  # After install, config.json is created:
  chmod 600 config.json   (recommended for security)

TROUBLESHOOTING
───────────────
• "Login failed" after logout → Clear browser cookies, hard refresh (Ctrl+F5)
• Images not sending in emails → Check uploads/images/ folder is readable
  by PHP (not just writable). Run: chmod 755 uploads/images/
• Cron "Already running" stuck → Delete the lock file:
  /tmp/mailszo_v4.lock  (or your server's temp dir)
• "Cannot write config.json" → chmod 755 on the root install folder
• SMTP test fails → Check host/port/SSL settings. Port 587 = STARTTLS,
  Port 465 = SSL, Port 25 = plain (not recommended)

SECURITY NOTES
──────────────
• Change admin password immediately after install
• Keep config.json outside public_html if possible, or set chmod 600
• Use HTTPS for production
• The cron key is auto-generated — regenerate it if compromised

VERSION HISTORY
───────────────
v4 Fixed5 — Logout/login session fix, image sending fixes, 
            cron auto-run UI, send/error log fixes

═══════════════════════════════════════════════════════════════
