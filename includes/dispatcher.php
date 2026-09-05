<?php
/**
 * Mailpro Enterprise - High-Frequency Dispatch Engine
 *
 * Provides sub-second precision dispatching for:
 *   1. Auto-Reply Sequence Threads (autoreply_threads)
 *   2. Follow-Up Sequence Queue (email_followup_queue)
 *   3. Shared template personalization and image embedding
 *
 * Latency SLA: < 3 seconds from user's configured schedule
 */

if (!function_exists('delayToSeconds')) {
    function delayToSeconds(int $value, string $unit = 'minutes'): int {
        $val = max(0, $value);
        switch (strtolower(trim($unit))) {
            case 'seconds':
            case 'second':
            case 's':
            case 'sec':
                return $val;
            case 'days':
            case 'day':
            case 'd':
                return $val * 86400;
            case 'hours':
            case 'hour':
            case 'h':
                return $val * 3600;
            case 'minutes':
            case 'minute':
            case 'm':
            default:
                return $val * 60;
        }
    }
}

if (!function_exists('parseImageIds')) {
    function parseImageIds($raw): array {
        if (empty($raw)) return [];
        if (is_array($raw)) return array_values(array_filter(array_map('intval', $raw), fn($v) => $v > 0));
        $d = json_decode($raw, true);
        return is_array($d) ? array_values(array_filter(array_map('intval', $d), fn($v) => $v > 0)) : [];
    }
}

if (!function_exists('embedImage')) {
    function embedImage(string $html, array $ids, array &$inline,
                        string $w = '600', string $align = 'center', string $pos = 'top'): string {
        if (!$ids || !function_exists('db')) return $html;

        $tags = [];
        foreach ($ids as $id) {
            $s = db()->prepare('SELECT filename,mime,url FROM images WHERE id=?');
            $s->execute([$id]);
            $img = $s->fetch(PDO::FETCH_ASSOC);
            if (!$img) continue;
            $filename = $img['filename'];

            $dirReal = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
            $candidates = [];
            $candidates[] = $dirReal . '/uploads/images/' . $filename;
            if (function_exists('getConfig')) {
                $cfg = getConfig();
                if (!empty($cfg['app_path'])) {
                    $ap = realpath($cfg['app_path']) ?: $cfg['app_path'];
                    $p = rtrim($ap, '/') . '/uploads/images/' . $filename;
                    if ($p !== $candidates[0]) $candidates[] = $p;
                }
            }
            if (!empty($_SERVER['DOCUMENT_ROOT'])) {
                $p = rtrim(realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/images/' . $filename;
                if (!in_array($p, $candidates)) $candidates[] = $p;
            }

            $path = null;
            foreach ($candidates as $c) {
                if (file_exists($c) && is_readable($c)) { $path = $c; break; }
            }

            if (!$path && !empty($img['url']) && filter_var($img['url'], FILTER_VALIDATE_URL)) {
                $raw = @file_get_contents($img['url']);
                if ($raw !== false && strlen($raw) > 0) {
                    $tmp = tempnam(sys_get_temp_dir(), 'mz_img_') . '.' . strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (@file_put_contents($tmp, $raw) !== false) {
                        $path = $tmp;
                        register_shutdown_function(fn() => @unlink($tmp));
                    }
                }
            }

            if (!$path) continue;

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mime = $img['mime'] ?: (['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][$ext] ?? 'image/jpeg');
            $cid = 'img' . md5($filename . $id) . '@mailszo.com';
            $inline[] = ['cid' => $cid, 'path' => $path, 'mime' => $mime];

            $ws = is_numeric($w) ? "width:{$w}px;max-width:100%;" : "width:{$w};max-width:100%;";
            if ($align === 'left')      { $mL = '0';    $mR = 'auto'; }
            elseif ($align === 'right') { $mL = 'auto'; $mR = '0'; }
            else                        { $mL = 'auto'; $mR = 'auto'; }
            $tags[] = "<img src=\"cid:$cid\" style=\"{$ws}height:auto;display:block;margin-left:{$mL};margin-right:{$mR};margin-bottom:16px;\" alt=\"\" />";
        }

        if (empty($tags)) return preg_replace('/\{\{image\}\}/i', '', $html);
        $allTags = implode("\n", $tags);
        if (preg_match('/\{\{image\}\}/i', $html)) {
            return preg_replace('/\{\{image\}\}/i', $allTags, $html);
        }
        return $pos === 'bottom' ? $html . '<div style="margin-top:16px">' . $allTags . '</div>'
                                 : '<div style="margin-bottom:16px">' . $allTags . '</div>' . $html;
    }
}

if (!function_exists('getDisplayName')) {
    function getDisplayName(int $uid): string {
        static $c = [];
        if (!isset($c[$uid]) && function_exists('db')) {
            try {
                $s = db()->prepare('SELECT meta_value FROM user_meta WHERE user_id=? AND meta_key=?');
                $s->execute([$uid, 'display_name']);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                $c[$uid] = ($r && !empty($r['meta_value'])) ? $r['meta_value'] : '';
            } catch (\Throwable $e) { $c[$uid] = ''; }
        }
        return $c[$uid] ?? '';
    }
}

if (!function_exists('applyDisplayName')) {
    function applyDisplayName(array $cfg, int $uid): array {
        $dn = getDisplayName($uid);
        if ($dn !== '') $cfg['from_name'] = $dn;
        return $cfg;
    }
}

if (!function_exists('buildMessage')) {
    function buildMessage(array $step, string $name, string $email, string $defSubj = '', string $senderName = '', string $todayDate = ''): array {
        $rawSubj = !empty($step['subject']) ? trim($step['subject']) : trim($defSubj);
        $bannedSubjects = ['follow', 'follow-up', 'followup', 'follow up', 'autoreply', 'auto-reply', 'auto reply', 'new follow-up rule', 'new auto-reply rule'];
        if (in_array(strtolower($rawSubj), $bannedSubjects, true) || $rawSubj === '') {
            $cleanDef = trim($defSubj);
            $rawSubj = (!empty($cleanDef) && !in_array(strtolower($cleanDef), $bannedSubjects, true))
                ? $cleanDef
                : 'Re: Regarding your inquiry';
        }
        $subj = function_exists('spin') ? spin($rawSubj) : $rawSubj;
        $html = function_exists('spin') ? spin($step['html_body'] ?? '') : ($step['html_body'] ?? '');
        $text = function_exists('spin') ? spin($step['text_body'] ?? '') : ($step['text_body'] ?? '');
        if (!$text) $text = strip_tags($html);
        $todayDate = $todayDate ?: date('F j, Y g:i A');

        if (function_exists('personalize')) {
            $subj = personalize($subj, $name, $email, $senderName, $todayDate);
            $html = personalize($html, $name, $email, $senderName, $todayDate);
            $text = personalize($text, $name, $email, $senderName, $todayDate);
        }

        $inline = [];
        $ids = parseImageIds($step['image_ids'] ?? null);
        if ($ids) {
            $html = embedImage($html, $ids, $inline, $step['img_width'] ?? '600', $step['img_align'] ?? 'center', $step['img_position'] ?? 'top');
        }
        return ['subject' => $subj, 'html' => $html, 'text' => $text, 'inlineImages' => $inline];
    }
}

if (!function_exists('saveToBackup')) {
    function saveToBackup(int $uid, string $email, string $name, string $src, int $rid): void {
        if ($rid <= 0 || !function_exists('db')) return;
        try {
            db()->prepare(
                "INSERT INTO backup_emails(user_id,email,name,source,rule_id,completed_at,first_seen)
                 VALUES(?,?,?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE
                   name=COALESCE(NULLIF(VALUES(name),''),name),
                   source=VALUES(source),
                   completed_at=NOW()"
            )->execute([$uid, $email, $name, $src, $rid]);
        } catch (\Throwable $e) {}
    }
}

/**
 * Automatically recover threads that are stuck in 'pending' for time-based rules
 * (where sequential_mode = 0 or NULL). This fixes the issue where 2nd, 3rd replies
 * were never being scheduled.
 */
function recoverStuckPendingThreads(): int {
    $recovered = 0;
    if (!function_exists('db') || (function_exists('isInstalled') && !isInstalled())) return 0;
    try {
        $stuckThreads = db()->query(
            "SELECT t.*, r.sequential_mode 
             FROM autoreply_threads t 
             JOIN autoreply_rules r ON r.id = t.rule_id 
             WHERE t.status = 'pending' AND (r.sequential_mode = 0 OR r.sequential_mode IS NULL)
             LIMIT 100"
        )->fetchAll();

        foreach ($stuckThreads as $th) {
            $rId = (int)$th['rule_id'];
            $curStep = (int)$th['current_step'];
            
            $stStmt = db()->prepare("SELECT delay_value, delay_unit, delay_minutes FROM autoreply_steps WHERE rule_id = ? AND step_number = ?");
            $stStmt->execute([$rId, $curStep]);
            $stepData = $stStmt->fetch();
            
            if ($stepData) {
                $dVal = max(0, (int)($stepData['delay_value'] ?? $stepData['delay_minutes'] ?? 0));
                $dUnit = strtolower($stepData['delay_unit'] ?? 'minutes');
                $dSecs = delayToSeconds($dVal, $dUnit);
                
                $lastSent = !empty($th['last_sent_at']) ? strtotime($th['last_sent_at']) : strtotime($th['updated_at'] ?? $th['created_at']);
                $targetTime = $lastSent + $dSecs;
                
                // If past due, schedule NOW so it dispatches immediately
                $schedAt = ($targetTime <= time()) ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $targetTime);
                
                db()->prepare("UPDATE autoreply_threads SET status = 'scheduled', scheduled_send_time = ? WHERE id = ?")
                    ->execute([$schedAt, $th['id']]);
                $recovered++;
            } else {
                db()->prepare("UPDATE autoreply_threads SET status = 'completed' WHERE id = ?")
                    ->execute([$th['id']]);
            }
        }

        // ── 2. Sequential Mode Recovery: Unlock threads waiting for lead reply when reply is received ──
        $seqPending = db()->query(
            "SELECT t.*, r.user_id as r_user_id 
             FROM autoreply_threads t 
             JOIN autoreply_rules r ON r.id = t.rule_id 
             WHERE t.status = 'pending' AND r.sequential_mode = 1
             LIMIT 50"
        )->fetchAll();

        foreach ($seqPending as $th) {
            $pEmail = strtolower(trim($th['from_email'] ?? ''));
            if (!$pEmail) continue;

            $lastSentTs = !empty($th['last_sent_at']) ? strtotime($th['last_sent_at']) : strtotime($th['created_at']);
            
            // Look for any inbound email from this sender received after last_sent_at (with 5s buffer)
            $chkInb = db()->prepare(
                "SELECT * FROM inbound_emails 
                 WHERE LOWER(TRIM(from_email)) = ? 
                   AND received_at >= ?
                 ORDER BY id DESC LIMIT 1"
            );
            $chkInb->execute([$pEmail, date('Y-m-d H:i:s', max(0, $lastSentTs - 5))]);
            $inb = $chkInb->fetch();

            if ($inb) {
                // Confirm it's not the same message ID that triggered the previous step
                $cleanInMsgId = trim(str_replace(['<','>'], '', (string)($inb['message_id'] ?? '')));
                $cleanThMsgId = trim(str_replace(['<','>'], '', (string)($th['last_received_message_id'] ?? '')));
                $isSameMsgId  = ($cleanInMsgId !== '' && $cleanThMsgId !== '' && strtolower($cleanInMsgId) === strtolower($cleanThMsgId));

                if (!$isSameMsgId) {
                    $curStep = (int)$th['current_step'];
                    $stStmt = db()->prepare("SELECT delay_value, delay_unit, delay_minutes FROM autoreply_steps WHERE rule_id = ? AND step_number = ?");
                    $stStmt->execute([(int)$th['rule_id'], $curStep]);
                    $stepData = $stStmt->fetch();

                    if ($stepData) {
                        if (empty($stepData['delay_value']) || (int)$stepData['delay_value'] === 0) {
                            $dSecs = 0;
                        } else {
                            $dVal = max(0, (int)($stepData['delay_value'] ?? $stepData['delay_minutes'] ?? 0));
                            $dUnit = strtolower($stepData['delay_unit'] ?? 'seconds');
                            $dSecs = delayToSeconds($dVal, $dUnit);
                        }
                        
                        $schedAt = ($dSecs > 0) ? date('Y-m-d H:i:s', time() + $dSecs) : date('Y-m-d H:i:s');
                        $inMsgId = trim($inb['message_id'] ?? '');
                        $newRefs = trim(($th['references_header'] ?? '') . ' ' . $inMsgId);

                        db()->prepare(
                            "UPDATE autoreply_threads 
                             SET status = 'scheduled',
                                 scheduled_send_time = ?,
                                 messages_received = messages_received + 1,
                                 reply_count = reply_count + 1,
                                 last_received_message_id = COALESCE(?, last_received_message_id),
                                 references_header = COALESCE(?, references_header),
                                 last_trigger_uid = COALESCE(?, last_trigger_uid),
                                 last_trigger_imap_id = COALESCE(?, last_trigger_imap_id),
                                 updated_at = NOW()
                             WHERE id = ?"
                        )->execute([
                            $schedAt,
                            $inMsgId ?: null,
                            $newRefs ?: null,
                            (int)($inb['uid'] ?? 0) ?: null,
                            (int)($inb['imap_account_id'] ?? 0) ?: null,
                            $th['id']
                        ]);
                        $recovered++;
                        logSystemEvent('queued', $pEmail, "Auto Reply #{$curStep} scheduled for {$schedAt} (lead replied)", (int)($th['r_user_id'] ?? 1), null, (int)$th['rule_id']);
                    } else {
                        // All steps finished
                        db()->prepare("UPDATE autoreply_threads SET status = 'completed' WHERE id = ?")->execute([$th['id']]);
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
    return $recovered;
}

/**
 * HIGH-SPEED AUTO-REPLY QUEUE DISPATCHER
 *
 * Processes all due Auto-Reply sequence threads (scheduled_send_time <= NOW()).
 * Correctly schedules step 2, 3, etc. for automatic time-based execution!
 */
function processAutoReplyQueue(int $limit = 50): int {
    $dispatched = 0;
    if (!function_exists('db') || (function_exists('isInstalled') && !isInstalled())) return 0;

    try {
        // Recover stuck sending jobs (>10m)
        db()->exec("UPDATE autoreply_threads SET status = 'scheduled' WHERE status = 'sending' AND (last_sent_at IS NULL OR last_sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))");

        // Unfreeze any pending threads that belong to time-based rules
        recoverStuckPendingThreads();

        $qDue = db()->query(
            "SELECT t.*, r.smtp_ids, r.from_emails, r.name rule_name, r.sequential_mode, r.primary_smtp_id, r.secondary_smtp_id, r.step1_smtp_ids, r.enable_reply_to_switch, r.imap2_id, u.status u_status, u.expires_at u_expires, r.user_id as r_user_id
             FROM autoreply_threads t
             LEFT JOIN autoreply_rules r ON r.id = t.rule_id
             LEFT JOIN users u ON u.id = r.user_id
             WHERE t.status = 'scheduled' AND t.scheduled_send_time <= NOW()
             ORDER BY t.scheduled_send_time ASC
             LIMIT " . (int)$limit
        )->fetchAll();

        foreach ($qDue as $job) {
            $threadId = (int)$job['id'];
            $ruleId   = (int)$job['rule_id'];
            $userId   = (int)($job['r_user_id'] ?? 1);

            // Atomic Lock
            $arLockStmt = db()->prepare("UPDATE autoreply_threads SET status = 'sending', last_sent_at = NOW() WHERE id = ? AND status = 'scheduled'");
            $arLockStmt->execute([$threadId]);
            if ($arLockStmt->rowCount() === 0) continue;

            // User check
            if (($job['u_status'] ?? '') === 'suspended' || (!empty($job['u_expires']) && strtotime($job['u_expires']) < time())) {
                db()->prepare("UPDATE autoreply_threads SET status = 'cancelled' WHERE id = ?")->execute([$threadId]);
                continue;
            }

            // Blacklist check
            if (function_exists('isBlacklisted') && isBlacklisted($job['from_email'], $userId)) {
                db()->prepare("UPDATE autoreply_threads SET status = 'cancelled' WHERE id = ?")->execute([$threadId]);
                logSystemEvent('failed', $job['from_email'], 'Auto-reply cancelled: Blacklisted recipient', $userId, null, $ruleId);
                continue;
            }

            $stepNum = (int)$job['current_step'];
            $sr = db()->prepare("SELECT * FROM autoreply_steps WHERE rule_id=? AND step_number=?");
            $sr->execute([$ruleId, $stepNum]);
            $step = $sr->fetch();

            if (!$step) {
                db()->prepare("UPDATE autoreply_threads SET status = 'completed' WHERE id = ?")->execute([$threadId]);
                saveToBackup($userId, $job['from_email'], $job['from_name'] ?? '', 'autoreply', $ruleId);
                continue;
            }

            $primarySmtpCfg = null; $secondarySmtpCfg = null; $step1SmtpPool = null; $smtpPool = [];
            if (!empty($job['primary_smtp_id']) && $job['primary_smtp_id'] > 0) {
                $psStmt = db()->prepare("SELECT * FROM smtp_providers WHERE id = ?");
                $psStmt->execute([(int)$job['primary_smtp_id']]);
                $primarySmtpCfg = $psStmt->fetch();
                if ($primarySmtpCfg) $step1SmtpPool = [$primarySmtpCfg];
            }
            if (!$step1SmtpPool && !empty($job['step1_smtp_ids'])) {
                $s1Ids = array_values(array_map('intval', json_decode($job['step1_smtp_ids'], true) ?: []));
                if ($s1Ids) {
                    $s1ph = implode(',', array_fill(0, count($s1Ids), '?'));
                    $s1ss = db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($s1ph)");
                    $s1ss->execute($s1Ids);
                    $step1SmtpPool = $s1ss->fetchAll();
                }
            }
            if (!empty($job['secondary_smtp_id']) && $job['secondary_smtp_id'] > 0) {
                $ssStmt = db()->prepare("SELECT * FROM smtp_providers WHERE id = ?");
                $ssStmt->execute([(int)$job['secondary_smtp_id']]);
                $secondarySmtpCfg = $ssStmt->fetch();
            }

            $smtpIds = array_values(array_map('intval', json_decode((string)($job['smtp_ids'] ?? ''), true) ?: []));
            if ($smtpIds) {
                $ph = implode(',', array_fill(0, count($smtpIds), '?'));
                $ss = db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($ph)");
                $ss->execute($smtpIds);
                $smtpPool = $ss->fetchAll();
            }

            if (!$secondarySmtpCfg && count($smtpPool) > 1) {
                $secondarySmtpCfg = $smtpPool[1];
            }
            if (!$primarySmtpCfg && count($smtpPool) > 0) {
                $primarySmtpCfg = $smtpPool[0];
                if (!$step1SmtpPool) $step1SmtpPool = [$primarySmtpCfg];
            }

            $isFirstReply = ($stepNum === 1);
            if ($isFirstReply) {
                $activeSmtpPool = ($step1SmtpPool && count($step1SmtpPool) > 0) ? $step1SmtpPool : ($primarySmtpCfg ? [$primarySmtpCfg] : $smtpPool);
            } else {
                $activeSmtpPool = $secondarySmtpCfg ? [$secondarySmtpCfg] : ($smtpPool ? (array_slice($smtpPool, 1) ?: $smtpPool) : ($primarySmtpCfg ? [$primarySmtpCfg] : []));
            }

            if (empty($activeSmtpPool) || empty($activeSmtpPool[0])) {
                db()->prepare("UPDATE autoreply_threads SET status = 'failed' WHERE id = ?")->execute([$threadId]);
                logSystemEvent('failed', $job['from_email'], "No SMTP available for step {$stepNum}", $userId, null, $ruleId);
                continue;
            }

            $mc = $activeSmtpPool[array_rand($activeSmtpPool)];
            if ($isFirstReply) {
                $fromPool = json_decode((string)($job['from_emails'] ?? ''), true) ?: [];
                if ($fromPool) {
                    $pk = $fromPool[array_rand($fromPool)];
                    if (is_array($pk)) { $mc['from_email'] = $pk['email'] ?? $mc['from_email']; $mc['from_name'] = $pk['name'] ?? $mc['from_name']; }
                    else { $mc['from_email'] = $pk; }
                }
            }

            $arDefSubj = !empty($job['subject_in']) ? ((stripos(trim($job['subject_in']), 're:') === 0) ? $job['subject_in'] : 'Re: ' . $job['subject_in']) : 'Re: Regarding your inquiry';
            $msg = buildMessage((array)$step, $job['from_name'] ?? '', $job['from_email'], $arDefSubj, $mc['from_name'] ?? '', date('F j, Y g:i A'));
            $mc = applyDisplayName($mc, $userId);

            $smtpNameUsed = $mc['name'] ?? '';
            $fromEmailUsed = $mc['from_email'] ?? '';
            $secReplyTo = ($secondarySmtpCfg && !empty($secondarySmtpCfg['from_email'])) ? $secondarySmtpCfg['from_email'] : '';
            if (!$secReplyTo && !empty($job['imap2_id'])) {
                try {
                    $i2Stmt = db()->prepare('SELECT username FROM imap_accounts WHERE id = ?');
                    $i2Stmt->execute([(int)$job['imap2_id']]);
                    $i2Row = $i2Stmt->fetch();
                    if ($i2Row && !empty($i2Row['username'])) $secReplyTo = $i2Row['username'];
                } catch (\Throwable $_e) {}
            }

            try {
                if (!class_exists('Mailer')) {
                    require_once __DIR__ . '/mailer.php';
                }

                $inReplyToHdr  = $job['last_received_message_id'] ?: ($job['last_message_id'] ?: ($job['original_message_id'] ?: ''));
                $referencesHdr = $job['references_header'] ?: ($job['original_message_id'] ?: '');
                $arTrackingToken = generateTrackingToken();
                $arOpts = [
                    'is_auto_reply'   => true,
                    'in_reply_to'     => $inReplyToHdr,
                    'references'      => $referencesHdr,
                    'tracking_token'  => $arTrackingToken,
                    'track_clicks'    => true,
                    'rule_id'         => $ruleId,
                    'sequence_step'   => $stepNum,
                    'user_id'         => $userId,
                    'smtp_account_id' => $mc['id'] ?? null,
                ];

                if ($secReplyTo && strtolower($secReplyTo) !== strtolower($fromEmailUsed)) {
                    $arOpts['reply_to'] = $secReplyTo; $arOpts['return_path'] = $secReplyTo; $arOpts['sender'] = $fromEmailUsed;
                }

                $sentMsgId = (new Mailer($mc))->send($job['from_email'], $job['from_name'] ?? '', $msg['subject'], $msg['html'], $msg['text'], $msg['inlineImages'], $arOpts);

                $nextNum = $stepNum + 1;
                $nr = db()->prepare("SELECT * FROM autoreply_steps WHERE rule_id=? AND step_number=?");
                $nr->execute([$ruleId, $nextNum]);
                $nextRow = $nr->fetch();

                if ($nextRow) {
                    $isSeq = !empty($job['sequential_mode']);
                    if ($isSeq) {
                        // Sequential (Message-Triggered): Wait for lead to reply before step 2
                        db()->prepare("UPDATE autoreply_threads SET current_step=?, status='pending', first_reply_sent=1, smtp_used=?, last_message_id=COALESCE(?, last_message_id), last_sent_at=NOW() WHERE id=?")
                          ->execute([$nextNum, $mc['id'] ?? null, $sentMsgId, $threadId]);
                        logSystemEvent('queued', $job['from_email'], "Auto Reply #{$nextNum} waiting for lead reply (sequential mode)", $userId, null, $ruleId);
                    } else {
                        // Time-Based Interval: Schedule next step automatically based on step delay!
                        $nextDelayVal = max(0, (int)($nextRow['delay_value'] ?? $nextRow['delay_minutes'] ?? 0));
                        $nextDelayUnit = in_array(strtolower($nextRow['delay_unit'] ?? ''), ['seconds','minutes','hours','days'], true) ? strtolower($nextRow['delay_unit']) : 'minutes';
                        $nextDelaySecs = delayToSeconds($nextDelayVal, $nextDelayUnit);
                        $nextSchedAt = date('Y-m-d H:i:s', time() + $nextDelaySecs);

                        db()->prepare("UPDATE autoreply_threads SET current_step=?, status='scheduled', scheduled_send_time=?, first_reply_sent=1, smtp_used=?, last_message_id=COALESCE(?, last_message_id), last_sent_at=NOW() WHERE id=?")
                          ->execute([$nextNum, $nextSchedAt, $mc['id'] ?? null, $sentMsgId, $threadId]);
                        logSystemEvent('queued', $job['from_email'], "Auto Reply #{$nextNum} scheduled for {$nextSchedAt} (+{$nextDelayVal} {$nextDelayUnit})", $userId, null, $ruleId);
                    }
                } else {
                    db()->prepare("UPDATE autoreply_threads SET status='completed', first_reply_sent=1, smtp_used=?, last_message_id=COALESCE(?, last_message_id), last_sent_at=NOW() WHERE id=?")
                      ->execute([$mc['id'] ?? null, $sentMsgId, $threadId]);
                    saveToBackup($userId, $job['from_email'], $job['from_name'] ?? '', 'autoreply', $ruleId);
                }

                db()->prepare("INSERT INTO autoreply_logs(rule_id,thread_id,step_number,to_email,status,smtp_used)VALUES(?,?,?,?,'sent',?)")
                    ->execute([$ruleId, $threadId, $stepNum, $job['from_email'], $smtpNameUsed]);
                db()->prepare("INSERT INTO send_logs(campaign_id,user_id,email,status,log_source,smtp_name_used,from_email_used)VALUES(NULL,?,?,'sent','autoreply',?,?)")
                    ->execute([$userId, $job['from_email'], $smtpNameUsed, $fromEmailUsed]);
                logSystemEvent('sent', $job['from_email'], "Auto Reply #{$stepNum} sent", $userId, null, $ruleId, null, $arTrackingToken, $smtpNameUsed);
                $dispatched++;

            } catch (\Throwable $e) {
                $errMsg = substr($e->getMessage(), 0, 500);
                db()->prepare("UPDATE autoreply_threads SET status='failed' WHERE id=?")->execute([$threadId]);
                db()->prepare("INSERT INTO autoreply_logs(rule_id,thread_id,step_number,to_email,status,error,smtp_used)VALUES(?,?,?,?,'failed',?,?)")
                    ->execute([$ruleId, $threadId, $stepNum, $job['from_email'], $errMsg, $smtpNameUsed]);
                db()->prepare("INSERT INTO send_logs(campaign_id,user_id,email,status,log_source,smtp_name_used,from_email_used,error)VALUES(NULL,?,?,'failed','autoreply',?,?,?)")
                    ->execute([$userId, $job['from_email'], $smtpNameUsed, $fromEmailUsed, $errMsg]);
                logSystemEvent('failed', $job['from_email'], "Auto Reply #{$stepNum} failed: $errMsg", $userId, null, $ruleId);
            }
        }
    } catch (\Throwable $e) {
        error_log("processAutoReplyQueue error: " . $e->getMessage());
    }
    return $dispatched;
}

/**
 * HIGH-SPEED FOLLOW-UP QUEUE DISPATCHER
 *
 * Processes all due Follow-Up sequence steps (email_followup_queue.scheduled_at <= NOW()).
 */
function processFollowUpQueue(int $limit = 50): int {
    $dispatched = 0;
    if (!function_exists('db') || (function_exists('isInstalled') && !isInstalled())) return 0;

    try {
        $fuPid = getmypid() ?: bin2hex(random_bytes(4));

        // Recover stuck sending jobs (>10m)
        db()->exec("UPDATE email_followup_queue 
                    SET status = 'scheduled', locked_at = NULL, lock_token = NULL 
                    WHERE status = 'sending' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

        $qDue = db()->query(
            "SELECT q.*, r.smtp_ids, r.from_emails, r.name rule_name, u.status u_status, u.expires_at u_expires
             FROM email_followup_queue q
             LEFT JOIN followup_rules r ON r.id = q.rule_id
             JOIN users u ON u.id = q.user_id
             WHERE q.status = 'scheduled' AND q.scheduled_at IS NOT NULL AND q.scheduled_at <= NOW() AND u.status = 'active'
             ORDER BY q.scheduled_at ASC
             LIMIT " . (int)$limit
        )->fetchAll();

        foreach ($qDue as $qItem) {
            $qId     = (int)$qItem['id'];
            $qUserId = (int)$qItem['user_id'];
            $qEmail  = strtolower(trim($qItem['recipient_email']));

            if (!empty($qItem['u_expires']) && strtotime($qItem['u_expires']) < time()) continue;

            if (function_exists('isBlacklisted') && isBlacklisted($qEmail, $qUserId)) {
                db()->prepare("UPDATE email_followup_queue SET status = 'cancelled', last_error = 'Blacklisted recipient' WHERE id = ?")->execute([$qId]);
                logSystemEvent('failed', $qEmail, 'Follow-up cancelled: Blacklisted recipient', $qUserId, $qItem['campaign_id'], $qItem['rule_id'], $qId);
                continue;
            }

            // Atomic Lock
            $lockStmt = db()->prepare("UPDATE email_followup_queue SET status = 'sending', locked_at = NOW(), lock_token = ? WHERE id = ? AND status = 'scheduled'");
            $lockStmt->execute([$fuPid, $qId]);
            if ($lockStmt->rowCount() === 0) continue;

            $ruleId    = (int)$qItem['rule_id'];
            $stepOrder = (int)$qItem['followup_order'];
            $stepStmt  = db()->prepare("SELECT * FROM followup_steps WHERE rule_id = ? AND step_number = ?");
            $stepStmt->execute([$ruleId, $stepOrder]);
            $stepRow   = $stepStmt->fetch();

            if (!$stepRow) {
                db()->prepare("UPDATE email_followup_queue SET status = 'skipped', last_error = 'Step template not found', locked_at = NULL WHERE id = ?")->execute([$qId]);
                continue;
            }

            $smtpIds = [];
            $activeMailbox = 'primary';
            try {
                $thCheck = db()->prepare("SELECT active_mailbox, rule_id, thread_id, conversation_stage FROM autoreply_threads WHERE from_email = ? ORDER BY id DESC LIMIT 1");
                $thCheck->execute([$qEmail]);
                $activeTh = $thCheck->fetch();
                if ($activeTh) {
                    $activeMailbox = $activeTh['active_mailbox'] ?? 'primary';
                    if (!empty($activeTh['rule_id'])) {
                        $arRuleStmt = db()->prepare("SELECT primary_smtp_id, secondary_smtp_id, enable_smart_routing FROM autoreply_rules WHERE id = ?");
                        $arRuleStmt->execute([(int)$activeTh['rule_id']]);
                        $arRuleData = $arRuleStmt->fetch();
                        if ($arRuleData && !empty($arRuleData['enable_smart_routing'])) {
                            if ($activeMailbox === 'secondary' && !empty($arRuleData['secondary_smtp_id'])) {
                                $smtpIds = [(int)$arRuleData['secondary_smtp_id']];
                            } elseif (!empty($arRuleData['primary_smtp_id'])) {
                                $smtpIds = [(int)$arRuleData['primary_smtp_id']];
                            }
                        }
                    }
                }
            } catch (\Throwable $_thEx) {}

            if (!$smtpIds && !empty($qItem['smtp_ids'])) { $d = json_decode($qItem['smtp_ids'], true); if (is_array($d)) $smtpIds = $d; }
            if (!$smtpIds) {
                $userSmtps = db()->prepare("SELECT id FROM smtp_providers WHERE user_id = ?");
                $userSmtps->execute([$qUserId]);
                $smtpIds = $userSmtps->fetchAll(PDO::FETCH_COLUMN);
            }
            if (!$smtpIds) {
                db()->prepare("UPDATE email_followup_queue SET status = 'failed', last_error = 'No SMTP configured for user', locked_at = NULL WHERE id = ?")->execute([$qId]);
                continue;
            }

            $ph = implode(',', array_fill(0, count($smtpIds), '?'));
            $ss = db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($ph)");
            $ss->execute($smtpIds);
            $smtpPool = $ss->fetchAll();
            if (!$smtpPool) {
                db()->prepare("UPDATE email_followup_queue SET status = 'failed', last_error = 'SMTP provider not found', locked_at = NULL WHERE id = ?")->execute([$qId]);
                continue;
            }

            $mc = $smtpPool[array_rand($smtpPool)];
            $fromPool = [];
            if (!empty($qItem['from_emails'])) { $d = json_decode($qItem['from_emails'], true); if (is_array($d)) $fromPool = $d; }
            if ($fromPool) {
                $pk = $fromPool[array_rand($fromPool)];
                if (is_array($pk)) { $mc['from_email'] = $pk['email'] ?? $mc['from_email']; $mc['from_name'] = $pk['name'] ?? $mc['from_name']; }
                else { $mc['from_email'] = $pk; }
            }

            // Natural subject lookup
            $defSubj = '';
            try {
                $thSubjStmt = db()->prepare("SELECT subject_in FROM autoreply_threads WHERE from_email = ? AND (rule_id IN (SELECT id FROM autoreply_rules WHERE followup_rule_id = ?) OR user_id = ?) AND subject_in IS NOT NULL AND subject_in != '' ORDER BY id DESC LIMIT 1");
                $thSubjStmt->execute([$qEmail, $ruleId, $qUserId]);
                $thSubj = $thSubjStmt->fetchColumn();
                if ($thSubj) {
                    $defSubj = (stripos(trim($thSubj), 're:') === 0) ? $thSubj : 'Re: ' . $thSubj;
                }
            } catch (\Throwable $_subEx) {}

            if (!$defSubj && !empty($qItem['campaign_id'])) {
                try {
                    $campSubjStmt = db()->prepare("SELECT subject FROM campaigns WHERE id = ?");
                    $campSubjStmt->execute([(int)$qItem['campaign_id']]);
                    $cSubj = $campSubjStmt->fetchColumn();
                    if ($cSubj) {
                        $defSubj = (stripos(trim($cSubj), 're:') === 0) ? $cSubj : 'Re: ' . $cSubj;
                    }
                } catch (\Throwable $_cEx) {}
            }

            if (!$defSubj && $stepOrder > 1) {
                $s1Stmt = db()->prepare("SELECT subject FROM followup_steps WHERE rule_id = ? AND step_number = 1 AND subject IS NOT NULL AND subject != ''");
                $s1Stmt->execute([$ruleId]);
                $s1Subj = $s1Stmt->fetchColumn();
                if ($s1Subj) {
                    $defSubj = (stripos(trim($s1Subj), 're:') === 0) ? $s1Subj : 'Re: ' . $s1Subj;
                }
            }

            if (!$defSubj) {
                try {
                    $inSubjStmt = db()->prepare("SELECT subject FROM inbound_emails WHERE from_email = ? AND subject IS NOT NULL AND subject != '' ORDER BY id DESC LIMIT 1");
                    $inSubjStmt->execute([$qEmail]);
                    $inSubj = $inSubjStmt->fetchColumn();
                    if ($inSubj) {
                        $defSubj = (stripos(trim($inSubj), 're:') === 0) ? $inSubj : 'Re: ' . $inSubj;
                    }
                } catch (\Throwable $_inEx) {}
            }

            if (!$defSubj) {
                $defSubj = 'Re: Regarding your inquiry';
            }

            $msg = buildMessage((array)$stepRow, $qItem['recipient_name'] ?? '', $qEmail, $defSubj, $mc['from_name'] ?? '', date('F j, Y g:i A'));
            $mc = applyDisplayName($mc, $qUserId);
            $fuSmtpName  = $mc['name'] ?? '';
            $fuFromEmail = $mc['from_email'] ?? '';

            try {
                if (!class_exists('Mailer')) {
                    require_once __DIR__ . '/mailer.php';
                }

                $fuInReplyTo  = '';
                $fuReferences = '';
                $fuReplyTo    = '';

                try {
                    $thStmt = db()->prepare("SELECT original_message_id, last_message_id, references_header, reply_to_mailbox FROM autoreply_threads WHERE from_email = ? AND (rule_id IN (SELECT id FROM autoreply_rules WHERE followup_rule_id = ?) OR user_id = ?) ORDER BY id DESC LIMIT 1");
                    $thStmt->execute([$qEmail, $ruleId, $qUserId]);
                    $thRow = $thStmt->fetch();
                    if ($thRow) {
                        $fuInReplyTo  = $thRow['last_message_id'] ?: ($thRow['original_message_id'] ?: '');
                        $fuReferences = $thRow['references_header'] ?: ($thRow['original_message_id'] ?: '');
                        if (!empty($thRow['reply_to_mailbox'])) {
                            $fuReplyTo = $thRow['reply_to_mailbox'];
                        }
                    }
                } catch (\Throwable $_thEx) {}

                if (!$fuReplyTo && !empty($qItem['imap_id'])) {
                    $fuImapRow = db()->query("SELECT username FROM imap_accounts WHERE id = " . (int)$qItem['imap_id'])->fetch();
                    if ($fuImapRow && filter_var($fuImapRow['username'], FILTER_VALIDATE_EMAIL)) {
                        $fuReplyTo = $fuImapRow['username'];
                    }
                }

                $mailer = new Mailer($mc);
                $sentFuMsgId = $mailer->send(
                    $qEmail,
                    $qItem['recipient_name'] ?? '',
                    $msg['subject'],
                    $msg['html'],
                    $msg['text'],
                    $msg['inlineImages'],
                    [
                        'tracking_token'  => $qItem['tracking_token'],
                        'track_clicks'    => true,
                        'in_reply_to'     => $fuInReplyTo,
                        'references'      => $fuReferences,
                        'reply_to'        => $fuReplyTo ?: $fuFromEmail,
                        'campaign_id'     => $qItem['campaign_id'] ?? null,
                        'rule_id'         => $ruleId,
                        'sequence_step'   => $stepOrder,
                        'user_id'         => $qUserId,
                        'smtp_account_id' => $mc['id'] ?? null,
                    ]
                );

                db()->prepare("UPDATE email_followup_queue SET status = 'sent', sent_at = NOW(), locked_at = NULL, lock_token = NULL WHERE id = ?")->execute([$qId]);
                logSystemEvent('sent', $qEmail, "Follow-up #{$stepOrder} sent successfully", $qUserId, $qItem['campaign_id'], $ruleId, $qId, $qItem['tracking_token'], $fuSmtpName);

                db()->prepare("INSERT INTO send_logs (campaign_id, user_id, email, status, log_source, smtp_name_used, from_email_used) VALUES (?, ?, ?, 'sent', 'followup', ?, ?)")
                    ->execute([$qItem['campaign_id'], $qUserId, $qEmail, $fuSmtpName, $fuFromEmail]);

                // Next Step
                $nextStepStmt = db()->prepare("SELECT * FROM followup_steps WHERE rule_id = ? AND step_number = ?");
                $nextStepStmt->execute([$ruleId, $stepOrder + 1]);
                $nextStepRow = $nextStepStmt->fetch();

                if ($nextStepRow) {
                    $nextDelayVal  = max(0, (int)($nextStepRow['delay_value'] ?? $nextStepRow['delay_minutes'] ?? 30));
                    $nextDelayUnit = in_array(strtolower($nextStepRow['delay_unit'] ?? ''), ['seconds','minutes','hours','days'], true) ? strtolower($nextStepRow['delay_unit']) : 'minutes';
                    $nextDelaySecs = delayToSeconds($nextDelayVal, $nextDelayUnit);
                    $nextDelayMins = max(1, (int)ceil($nextDelaySecs / 60));
                    $nextSchedAt   = date('Y-m-d H:i:s', time() + $nextDelaySecs);
                    $nextTrackingToken = generateTrackingToken();

                    $insNext = db()->prepare(
                        "INSERT INTO email_followup_queue 
                         (user_id, campaign_id, rule_id, contact_id, recipient_email, recipient_name, followup_order, delay_value, delay_unit, delay_in_minutes, scheduled_at, status, tracking_token)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)"
                    );
                    $insNext->execute([
                        $qUserId, $qItem['campaign_id'], $ruleId, $qItem['contact_id'],
                        $qEmail, $qItem['recipient_name'], $stepOrder + 1,
                        $nextDelayVal, $nextDelayUnit, $nextDelayMins, $nextSchedAt, $nextTrackingToken
                    ]);
                    $nextQid = db()->lastInsertId();
                    logSystemEvent('queued', $qEmail, "Follow-up #" . ($stepOrder + 1) . " scheduled for {$nextSchedAt} (+{$nextDelayVal} {$nextDelayUnit})", $qUserId, $qItem['campaign_id'], $ruleId, $nextQid, $nextTrackingToken);
                } else {
                    saveToBackup($qUserId, $qEmail, $qItem['recipient_name'] ?? '', 'followup', $ruleId);
                }

                $dispatched++;

            } catch (\Throwable $sendEx) {
                $err = substr($sendEx->getMessage(), 0, 500);
                $retryCount = (int)$qItem['retry_count'] + 1;

                if ($retryCount < 3) {
                    $backoffMins = ($retryCount === 1) ? 2 : (($retryCount === 2) ? 10 : 30);
                    $retryAt = date('Y-m-d H:i:s', strtotime("+{$backoffMins} minutes"));

                    db()->prepare(
                        "UPDATE email_followup_queue 
                         SET status = 'scheduled', retry_count = ?, scheduled_at = ?, last_error = ?, locked_at = NULL, lock_token = NULL 
                         WHERE id = ?"
                    )->execute([$retryCount, $retryAt, $err, $qId]);

                    logSystemEvent('retry', $qEmail, "Retry #{$retryCount} scheduled in {$backoffMins}m due to error: {$err}", $qUserId, $qItem['campaign_id'], $ruleId, $qId, $qItem['tracking_token']);
                } else {
                    db()->prepare(
                        "UPDATE email_followup_queue 
                         SET status = 'failed', retry_count = ?, last_error = ?, locked_at = NULL, lock_token = NULL 
                         WHERE id = ?"
                    )->execute([$retryCount, $err, $qId]);

                    logSystemEvent('failed', $qEmail, "Follow-up #{$stepOrder} failed after 3 retries: {$err}", $qUserId, $qItem['campaign_id'], $ruleId, $qId, $qItem['tracking_token']);
                    db()->prepare("INSERT INTO send_logs (campaign_id, user_id, email, status, log_source, smtp_name_used, from_email_used, error) VALUES (?, ?, ?, 'failed', 'followup', ?, ?, ?)")
                        ->execute([$qItem['campaign_id'], $qUserId, $qEmail, $fuSmtpName, $fuFromEmail, $err]);
                }
            }
        }
    } catch (\Throwable $_qErr) {
        error_log("processFollowUpQueue error: " . $_qErr->getMessage());
    }
    return $dispatched;
}

/**
 * HIGH-FREQUENCY SUB-MINUTE RUNNER
 *
 * Stays active for up to $maxSeconds, checking and executing due queues every 1-2 seconds.
 * Guarantees zero latency (< 2-3s delay from scheduled time) for CLI cron executions.
 */
function runRealTimeDispatchCycle(int $maxSeconds = 55): array {
    $startTime = time();
    $totalArDispatched = 0;
    $totalFuDispatched = 0;
    $totalQueueDispatched = 0;

    while ((time() - $startTime) < $maxSeconds) {
        $ar = processAutoReplyQueue(25);
        $fu = processFollowUpQueue(25);
        $totalArDispatched += $ar;
        $totalFuDispatched += $fu;

        // Process queue jobs
        if (class_exists('QueueManager')) {
            $qCount = 0;
            while ($qCount < 10) {
                $qJob = QueueManager::reserveNextJob([], 'dispatcher_loop');
                if (!$qJob) break;
                try {
                    QueueManager::executeJob($qJob);
                    QueueManager::markCompleted((int)$qJob['id']);
                    $qCount++;
                    $totalQueueDispatched++;
                } catch (\Throwable $qe) {
                    QueueManager::markFailed($qJob, $qe);
                    $qCount++;
                }
            }
        }

        // If something was dispatched, loop immediately without sleep
        if ($ar > 0 || $fu > 0) {
            usleep(200000); // 0.2s pause to yield CPU
            continue;
        }

        // Sleep 1.5s before next check
        if ((time() - $startTime) < $maxSeconds) {
            sleep(1);
        }
    }

    return [
        'ar_sent'    => $totalArDispatched,
        'fu_sent'    => $totalFuDispatched,
        'queue_jobs' => $totalQueueDispatched
    ];
}
