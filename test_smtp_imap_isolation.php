<?php
/**
 * Automated Isolation & Validation Test for SMTP/IMAP Assignments
 * 
 * Verifies:
 * 1. Updating User A's SMTP/IMAP does not modify User B's configuration.
 * 2. Cross-user SMTP/IMAP assignment validation blocks incorrect associations.
 */

// Local SQLite in-memory database for validation testing
$db = new PDO('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// Create SQLite tables to mock the MySQL schema for validation testing
$db->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password TEXT,
    is_admin INTEGER DEFAULT 0,
    assigned_smtp_ids TEXT,
    assigned_imap_ids TEXT
)");

$db->exec("CREATE TABLE smtp_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT,
    host TEXT,
    from_email TEXT
)");

$db->exec("CREATE TABLE imap_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT,
    host TEXT,
    username TEXT,
    password TEXT
)");

$db->exec("CREATE TABLE autoreply_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT,
    imap_id INTEGER,
    smtp_ids TEXT
)");

// Local validation helper functions mimicking the api.php logic but using the passed-in PDO instance
function testGetAllowedSmtpIds(PDO $db, int $userId): array {
    $allowed = [];
    $s = $db->prepare("SELECT id FROM smtp_providers WHERE user_id = ?");
    $s->execute([$userId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $allowed[] = (int)$row['id'];
    }
    $s = $db->prepare("SELECT assigned_smtp_ids FROM users WHERE id = ?");
    $s->execute([$userId]);
    $userRow = $s->fetch(PDO::FETCH_ASSOC);
    $assigned = $userRow ? $userRow['assigned_smtp_ids'] : null;
    if (!empty($assigned)) {
        $d = json_decode($assigned, true);
        if (is_array($d)) {
            foreach ($d as $id) {
                $allowed[] = (int)$id;
            }
        }
    }
    return array_unique($allowed);
}

function testGetAllowedImapIds(PDO $db, int $userId): array {
    $allowed = [];
    $s = $db->prepare("SELECT id FROM imap_accounts WHERE user_id = ?");
    $s->execute([$userId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $allowed[] = (int)$row['id'];
    }
    $s = $db->prepare("SELECT assigned_imap_ids FROM users WHERE id = ?");
    $s->execute([$userId]);
    $userRow = $s->fetch(PDO::FETCH_ASSOC);
    $assigned = $userRow ? $userRow['assigned_imap_ids'] : null;
    if (!empty($assigned)) {
        $d = json_decode($assigned, true);
        if (is_array($d)) {
            foreach ($d as $id) {
                $allowed[] = (int)$id;
            }
        }
    }
    return array_unique($allowed);
}

function testValidateRuleSmtpImap(PDO $db, int $userId, $imapId, $imap2Id, $smtpIds, $step1SmtpIds = null): ?string {
    $chk = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $chk->execute([$userId]);
    $userRow = $chk->fetch(PDO::FETCH_ASSOC);
    $isAdminUser = $userRow ? (bool)$userRow['is_admin'] : false;
    if ($isAdminUser) {
        return null;
    }

    $allowedImaps = testGetAllowedImapIds($db, $userId);
    $allowedSmtps = testGetAllowedSmtpIds($db, $userId);

    if ($imapId !== null && $imapId !== '' && !in_array((int)$imapId, $allowedImaps, true)) {
        return "Selected IMAP 1 account is not owned by or assigned to the rule owner.";
    }
    if ($imap2Id !== null && $imap2Id !== '' && !in_array((int)$imap2Id, $allowedImaps, true)) {
        return "Selected IMAP 2 account is not owned by or assigned to the rule owner.";
    }

    if ($smtpIds !== null) {
        $ids = is_string($smtpIds) ? json_decode($smtpIds, true) : $smtpIds;
        if (is_array($ids)) {
            foreach ($ids as $sid) {
                if (!in_array((int)$sid, $allowedSmtps, true)) {
                    return "One of the selected SMTP servers is not owned by or assigned to the rule owner.";
                }
            }
        }
    }

    if ($step1SmtpIds !== null) {
        $ids = is_string($step1SmtpIds) ? json_decode($step1SmtpIds, true) : $step1SmtpIds;
        if (is_array($ids)) {
            foreach ($ids as $sid) {
                if (!in_array((int)$sid, $allowedSmtps, true)) {
                    return "One of the selected Step 1 SMTP servers is not owned by or assigned to the rule owner.";
                }
            }
        }
    }

    return null;
}

function logTest($msg, $status = 'INFO') {
    $colors = ['PASS' => "\033[32m[PASS]\033[0m", 'FAIL' => "\033[31m[FAIL]\033[0m", 'INFO' => "\033[36m[INFO]\033[0m"];
    echo ($colors[$status] ?? "[{$status}]") . " " . $msg . "\n";
}

try {
    $db->beginTransaction();

    // 1. Create User A and User B
    $db->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)")
        ->execute(['test_user_a', password_hash('password123', PASSWORD_BCRYPT)]);
    $userAId = $db->lastInsertId();

    $db->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)")
        ->execute(['test_user_b', password_hash('password123', PASSWORD_BCRYPT)]);
    $userBId = $db->lastInsertId();

    logTest("Created test users in SQLite: User A (#$userAId), User B (#$userBId)", 'INFO');

    // 2. Create SMTP servers
    $db->prepare("INSERT INTO smtp_providers (user_id, name, host, from_email) VALUES (?, 'SMTP_A', 'smtp.a.com', 'a@a.com')")
        ->execute([$userAId]);
    $smtpAId = $db->lastInsertId();

    $db->prepare("INSERT INTO smtp_providers (user_id, name, host, from_email) VALUES (?, 'SMTP_B', 'smtp.b.com', 'b@b.com')")
        ->execute([$userBId]);
    $smtpBId = $db->lastInsertId();

    logTest("Created SMTP providers: SMTP A (#$smtpAId) for User A, SMTP B (#$smtpBId) for User B", 'INFO');

    // 3. Create IMAP accounts
    $db->prepare("INSERT INTO imap_accounts (user_id, name, host, username, password) VALUES (?, 'IMAP_A', 'imap.a.com', 'a', 'pass')")
        ->execute([$userAId]);
    $imapAId = $db->lastInsertId();

    $db->prepare("INSERT INTO imap_accounts (user_id, name, host, username, password) VALUES (?, 'IMAP_B', 'imap.b.com', 'b', 'pass')")
        ->execute([$userBId]);
    $imapBId = $db->lastInsertId();

    logTest("Created IMAP accounts: IMAP A (#$imapAId) for User A, IMAP B (#$imapBId) for User B", 'INFO');

    // 4. Test validation helper
    // Scenario A: User A rule using User A's SMTP and IMAP -> Should pass
    $err = testValidateRuleSmtpImap($db, $userAId, $imapAId, null, [$smtpAId]);
    if ($err !== null) {
        throw new Exception("Validation failed unexpectedly for User A using User A's resources: $err");
    }
    logTest("Valid request (User A using own resources) passed validation successfully", 'PASS');

    // Scenario B: User A rule using User B's IMAP -> Should be blocked
    $err = testValidateRuleSmtpImap($db, $userAId, $imapBId, null, [$smtpAId]);
    if ($err === null) {
        throw new Exception("Validation failed to block User A using User B's IMAP account");
    }
    logTest("Validation blocked User A using User B's IMAP: '$err'", 'PASS');

    // Scenario C: User A rule using User B's SMTP -> Should be blocked
    $err = testValidateRuleSmtpImap($db, $userAId, $imapAId, null, [$smtpBId]);
    if ($err === null) {
        throw new Exception("Validation failed to block User A using User B's SMTP server");
    }
    logTest("Validation blocked User A using User B's SMTP: '$err'", 'PASS');

    // 5. Test User isolation on assignment changes
    // Scenario D: Assign SMTP A to User A, verify User B remains unassigned
    $db->prepare("UPDATE users SET assigned_smtp_ids = ? WHERE id = ?")
        ->execute([json_encode([$smtpAId]), $userAId]);

    $stmt = $db->prepare("SELECT assigned_smtp_ids FROM users WHERE id = ?");
    $stmt->execute([$userBId]);
    $userBSmtpsRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $userBSmtps = $userBSmtpsRow ? $userBSmtpsRow['assigned_smtp_ids'] : null;
    if ($userBSmtps !== null) {
        throw new Exception("Updating User A's SMTP assignments unexpectedly modified User B's assignments: " . var_export($userBSmtps, true));
    }
    logTest("Updating User A's SMTP assignments did not affect User B's SMTP assignments", 'PASS');

    // 6. Verify Auto-Reply isolation
    // Create rule for User A
    $db->prepare("INSERT INTO autoreply_rules (user_id, name, imap_id, smtp_ids) VALUES (?, 'Rule_A', ?, ?)")
        ->execute([$userAId, $imapAId, json_encode([$smtpAId])]);
    $ruleAId = $db->lastInsertId();

    // Create rule for User B
    $db->prepare("INSERT INTO autoreply_rules (user_id, name, imap_id, smtp_ids) VALUES (?, 'Rule_B', ?, ?)")
        ->execute([$userBId, $imapBId, json_encode([$smtpBId])]);
    $ruleBId = $db->lastInsertId();

    // Update User A's rule name, SMTP, and IMAP settings
    $db->prepare("UPDATE autoreply_rules SET name = ?, imap_id = ?, smtp_ids = ? WHERE id = ?")
        ->execute(['Rule_A_Updated', $imapAId, json_encode([$smtpAId]), $ruleAId]);

    // Check User B's rule - should remain exactly as it was
    $stmt = $db->prepare("SELECT * FROM autoreply_rules WHERE id = ?");
    $stmt->execute([$ruleBId]);
    $ruleB = $stmt->fetch();
    if ($ruleB['name'] !== 'Rule_B' || (int)$ruleB['imap_id'] !== (int)$imapBId || json_decode($ruleB['smtp_ids'], true) !== [$smtpBId]) {
        throw new Exception("Updating User A's Autoreply rule unexpectedly changed User B's Autoreply rule: " . var_export($ruleB, true));
    }
    logTest("Updating User A's Auto-Reply rule did not affect User B's Auto-Reply configuration", 'PASS');

    $db->rollBack();
    logTest("All isolation and validation tests passed successfully!", 'PASS');
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logTest("Test failed: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'FAIL');
    exit(1);
}
