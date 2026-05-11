<?php

/**
 * Append-only authentication attempt log (staff + patient portal).
 */
function ensure_auth_login_log_table(mysqli $db): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $sql = 'CREATE TABLE IF NOT EXISTS auth_login_log (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      realm ENUM(\'staff\',\'portal\') NOT NULL,
      identifier VARCHAR(191) NOT NULL,
      success TINYINT(1) NOT NULL DEFAULT 0,
      user_id INT UNSIGNED NULL DEFAULT NULL,
      ip_address VARCHAR(45) NULL DEFAULT NULL,
      user_agent VARCHAR(512) NULL DEFAULT NULL,
      detail VARCHAR(191) NULL DEFAULT NULL,
      INDEX idx_auth_login_created (created_at),
      INDEX idx_auth_login_realm_created (realm, created_at),
      INDEX idx_auth_login_success_created (success, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (!$db->query($sql)) {
        return false;
    }
    $done = true;

    return true;
}

/**
 * @param array{realm:string,identifier:string,success:bool,user_id?:?int,detail?:string} $row
 */
function log_auth_login_attempt(mysqli $db, array $row): void
{
    if (!ensure_auth_login_log_table($db)) {
        return;
    }
    $realm = $row['realm'] === 'portal' ? 'portal' : 'staff';
    $identifier = mb_substr(trim((string) ($row['identifier'] ?? '')), 0, 191);
    $success = !empty($row['success']) ? 1 : 0;
    $userId = isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null;
    $detail = isset($row['detail']) ? mb_substr((string) $row['detail'], 0, 191) : null;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strlen($ip) > 45) {
        $ip = substr($ip, 0, 45);
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua) > 512) {
        $ua = substr($ua, 0, 512);
    }

    if ($userId === null) {
        $stmt = $db->prepare(
            'INSERT INTO auth_login_log (realm, identifier, success, user_id, ip_address, user_agent, detail)
             VALUES (?, ?, ?, NULL, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssisss', $realm, $identifier, $success, $ip, $ua, $detail);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO auth_login_log (realm, identifier, success, user_id, ip_address, user_agent, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssiisss', $realm, $identifier, $success, $userId, $ip, $ua, $detail);
    }
    $stmt->execute();
    $stmt->close();
}
