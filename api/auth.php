<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/auth_login_log.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = trim((string) ($_GET['action'] ?? ''));

/** POST body parsed once (php://input is single-read). */
$parsedPost = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        $parsedPost = is_array($decoded) ? $decoded : [];
    }
    if ($action === '') {
        $bodyAction = trim((string) ($parsedPost['action'] ?? ''));
        if (in_array($bodyAction, ['login', 'logout', 'change_password'], true)) {
            $action = $bodyAction;
        }
    }
}

function ensureCredentialRecoverySchema(mysqli $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS credential_recovery_challenges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_credential_recovery_user (user_id),
            INDEX idx_credential_recovery_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->query(
        "CREATE TABLE IF NOT EXISTS admin_staff_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_type VARCHAR(64) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_staff_alerts_unread (is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function staffRecoveryThrottleOk(string $bucket): bool {
    $now = time();
    $key = 'staff_recovery_rl_' . $bucket;
    $win = 3600;
    $max = 5;
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = ['start' => $now, 'hits' => 0];
    }
    $start = (int) ($_SESSION[$key]['start'] ?? 0);
    if ($start < $now - $win) {
        $_SESSION[$key] = ['start' => $now, 'hits' => 0];
    }
    $_SESSION[$key]['hits'] = (int) ($_SESSION[$key]['hits'] ?? 0) + 1;
    return (int) $_SESSION[$key]['hits'] <= $max;
}

function insertAdminStaffAlert(mysqli $db, string $type, string $message): void {
    ensureCredentialRecoverySchema($db);
    $stmt = $db->prepare('INSERT INTO admin_staff_alerts (alert_type, message) VALUES (?, ?)');
    if ($stmt) {
        $stmt->bind_param('ss', $type, $message);
        $stmt->execute();
        $stmt->close();
    }
}

// ── Check session ─────────────────────────────────────────────────────────
if ($action === 'me') {
    if (!empty($_SESSION['user_id'])) {
        $autoLogout = 30;
        try {
            $db = getDB();
            if ($db && ($q = $db->query("SELECT `value` FROM settings WHERE `key` = 'auto_logout_minutes' LIMIT 1"))) {
                $row = $q->fetch_assoc();
                if ($row && isset($row['value'])) {
                    $v = (int) $row['value'];
                    if ($v < 0) {
                        $v = 0;
                    }
                    if ($v > 24 * 60) {
                        $v = 24 * 60;
                    }
                    $autoLogout = $v;
                }
            }
        } catch (Throwable $e) {
            $autoLogout = 30;
        }
        respond([
            'authenticated' => true,
            'auto_logout_minutes' => $autoLogout,
            'user' => [
                'id'        => $_SESSION['user_id'],
                'username'  => $_SESSION['username'],
                'full_name' => $_SESSION['full_name'] ?? '',
                'role'      => $_SESSION['role'] ?? 'admin',
            ]
        ]);
    }
    respond(['authenticated' => false]);
}

if ($action === 'csrf' && $method === 'GET') {
    respond(['csrf_token' => csrf_get_token()]);
}

// ── Staff forgot username/password: OTP + notify admins ───────────────────
if ($action === 'staff_recovery_request' && $method === 'POST') {
    csrf_require_valid($parsedPost);
    $mode = trim((string) ($parsedPost['mode'] ?? ''));
    if (!in_array($mode, ['forgot_password', 'forgot_username'], true)) {
        respond(['error' => 'Invalid recovery mode.', 'code' => 'invalid_mode'], 400);
    }
    if (!staffRecoveryThrottleOk('req_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $mode))) {
        respond(['error' => 'Too many recovery attempts. Try again later.', 'code' => 'rate_limited'], 429);
    }

    $db = getDB();
    ensureCredentialRecoverySchema($db);

    $user = null;
    if ($mode === 'forgot_password') {
        $username = trim((string) ($parsedPost['username'] ?? ''));
        if ($username === '') {
            respond(['error' => 'Enter your username.', 'code' => 'missing_username'], 400);
        }
        if (!staffRecoveryThrottleOk('user_' . hash('sha256', strtolower($username)))) {
            respond(['error' => 'Too many attempts for this account. Try again later.', 'code' => 'rate_limited'], 429);
        }
        $stmt = $db->prepare('SELECT id, username, full_name, role FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user || ($user['role'] ?? '') !== 'staff') {
            respond(['error' => 'No staff account matches that username.', 'code' => 'not_found'], 404);
        }
    } else {
        $fullName = trim((string) ($parsedPost['full_name'] ?? ''));
        if ($fullName === '') {
            respond(['error' => 'Enter your full name as registered.', 'code' => 'missing_name'], 400);
        }
        if (!staffRecoveryThrottleOk('name_' . hash('sha256', strtolower($fullName)))) {
            respond(['error' => 'Too many attempts. Try again later.', 'code' => 'rate_limited'], 429);
        }
        $roleStaff = 'staff';
        $stmt = $db->prepare(
            'SELECT id, username, full_name, role FROM users
             WHERE role = ? AND LOWER(TRIM(full_name)) = LOWER(TRIM(?))'
        );
        $stmt->bind_param('ss', $roleStaff, $fullName);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        if (count($rows) === 0) {
            respond(['error' => 'No staff profile matches that name.', 'code' => 'not_found'], 404);
        }
        if (count($rows) > 1) {
            respond(['error' => 'Multiple staff share that name. Contact an administrator.', 'code' => 'ambiguous'], 409);
        }
        $user = $rows[0];
    }

    $otp = (string) random_int(100000, 999999);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $uid = (int) $user['id'];
    $expires = (new DateTimeImmutable('now'))->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s');

    $del = $db->prepare('DELETE FROM credential_recovery_challenges WHERE user_id = ?');
    $del->bind_param('i', $uid);
    $del->execute();
    $del->close();

    $ins = $db->prepare(
        'INSERT INTO credential_recovery_challenges (user_id, otp_hash, expires_at) VALUES (?, ?, ?)'
    );
    $ins->bind_param('iss', $uid, $otpHash, $expires);
    if (!$ins->execute()) {
        respond(['error' => 'Could not issue recovery code.', 'code' => 'server_error'], 500);
    }
    $ins->close();

    $uname = (string) ($user['username'] ?? '');
    $fname = (string) ($user['full_name'] ?? '');
    $reason = $mode === 'forgot_password' ? 'forgot password' : 'forgot username';
    $msg = 'Staff member ' . $fname . ' (@' . $uname . ') started credential recovery (' . $reason . '). '
        . 'A one-time code was shown to them; it expires in 15 minutes.';
    insertAdminStaffAlert($db, 'staff_credential_recovery', $msg);

    respond([
        'success'           => true,
        'otp'             => $otp,
        'username'        => $uname,
        'full_name'       => $fname,
        'expires_in_sec'  => 900,
        'message'         => 'Use the one-time code below to set a new password. Administrators have been notified.',
    ]);
}

if ($action === 'staff_recovery_reset' && $method === 'POST') {
    csrf_require_valid($parsedPost);
    $username = trim((string) ($parsedPost['username'] ?? ''));
    $otp = preg_replace('/\D/', '', (string) ($parsedPost['otp'] ?? ''));
    $new = (string) ($parsedPost['new_password'] ?? '');
    $confirm = (string) ($parsedPost['confirm_password'] ?? '');

    if ($username === '' || strlen($otp) < 6) {
        respond(['error' => 'Username and a valid one-time code are required.', 'code' => 'missing_fields'], 400);
    }
    if ($new === '' || strlen($new) < 8) {
        respond(['error' => 'New password must be at least 8 characters.', 'code' => 'password_too_short'], 400);
    }
    if ($new !== $confirm) {
        respond(['error' => 'Password confirmation does not match.', 'code' => 'password_mismatch'], 400);
    }

    $db = getDB();
    ensureCredentialRecoverySchema($db);
    $stmt = $db->prepare('SELECT id, username, role, password FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || ($user['role'] ?? '') !== 'staff') {
        respond(['error' => 'Invalid recovery request.', 'code' => 'not_found'], 404);
    }

    $uid = (int) $user['id'];
    $ch = $db->prepare(
        'SELECT otp_hash, expires_at FROM credential_recovery_challenges WHERE user_id = ? LIMIT 1'
    );
    $ch->bind_param('i', $uid);
    $ch->execute();
    $row = $ch->get_result()->fetch_assoc();
    $ch->close();
    if (!$row) {
        respond(['error' => 'No active recovery code. Request a new code from the login page.', 'code' => 'no_challenge'], 400);
    }
    $exp = strtotime((string) ($row['expires_at'] ?? ''));
    if ($exp < time()) {
        $db->query('DELETE FROM credential_recovery_challenges WHERE user_id = ' . (int) $uid);
        respond(['error' => 'That code has expired. Request a new one.', 'code' => 'expired'], 400);
    }
    if (!password_verify($otp, (string) ($row['otp_hash'] ?? ''))) {
        respond(['error' => 'Invalid one-time code.', 'code' => 'bad_otp'], 403);
    }

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $up = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND role = \'staff\'');
    $up->bind_param('si', $newHash, $uid);
    if (!$up->execute()) {
        respond(['error' => 'Could not update password.', 'code' => 'update_failed'], 500);
    }
    $up->close();

    $db->query('DELETE FROM credential_recovery_challenges WHERE user_id = ' . (int) $uid);
    $fname = (string) ($user['full_name'] ?? '');
    insertAdminStaffAlert($db, 'staff_password_reset', 'Staff ' . $fname . ' (@' . $username . ') completed password reset using a one-time code.');

    respond(['success' => true, 'message' => 'Password updated. You can sign in with your username and new password.']);
}

// ── Login ─────────────────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    csrf_require_valid($parsedPost);
    $username = trim((string) ($parsedPost['username'] ?? ''));
    $password = trim((string) ($parsedPost['password'] ?? ''));

    if (!$username || !$password) {
        respond(['error' => 'Username and password are required.', 'code' => 'missing_credentials'], 400);
    }

    $db   = getDB();
    ensure_auth_login_log_table($db);
    $stmt = $db->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $valid = $user && password_verify($password, $user['password']);
    $detail = $valid ? 'login_ok' : ($user ? 'bad_password' : 'unknown_user');
    log_auth_login_attempt($db, [
        'realm'      => 'staff',
        'identifier' => $username,
        'success'    => $valid,
        'user_id'    => $user ? (int) $user['id'] : null,
        'detail'     => $detail,
    ]);

    if (!$valid) {
        respond(['error' => 'Invalid username or password.', 'code' => 'invalid_credentials'], 401);
    }

    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];

    session_write_close();

    respond([
        'success' => true,
        'user'    => [
            'id'        => $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ]
    ]);
}

// ── Logout ────────────────────────────────────────────────────────────────
if ($action === 'logout' && $method === 'POST') {
    csrf_require_valid($parsedPost);
    session_unset();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    respond(['success' => true]);
}

// ── Change password (logged-in admin/staff) ───────────────────────────────
if ($action === 'change_password' && $method === 'POST') {
    csrf_require_valid($parsedPost);
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'Unauthorized. Please log in.', 'redirect' => 'login.html', 'code' => 'unauthorized'], 401);
    }
    $old = (string) ($parsedPost['old_password'] ?? '');
    $new = (string) ($parsedPost['new_password'] ?? '');
    $confirm = (string) ($parsedPost['confirm_password'] ?? '');

    if ($old === '' || $new === '') {
        respond(['error' => 'Old and new passwords are required.', 'code' => 'missing_fields'], 400);
    }
    if ($new !== $confirm) {
        respond(['error' => 'New password and confirmation do not match.', 'code' => 'password_mismatch'], 400);
    }
    if (strlen($new) < 8) {
        respond(['error' => 'New password must be at least 8 characters.', 'code' => 'password_too_short'], 400);
    }
    if ($new === $old) {
        respond(['error' => 'New password must be different from your current password.', 'code' => 'password_reuse'], 400);
    }

    $db = getDB();
    $uid = (int) $_SESSION['user_id'];
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        respond(['error' => 'User not found.', 'code' => 'user_not_found'], 404);
    }

    $hash = $row['password'];
    $validOld = password_verify($old, $hash);

    if (!$validOld) {
        respond(['error' => 'Current password is incorrect.', 'code' => 'wrong_current_password'], 403);
    }

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $u = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
    $u->bind_param('si', $newHash, $uid);
    if (!$u->execute()) {
        respond(['error' => 'Could not update password.', 'code' => 'update_failed'], 500);
    }

    respond(['success' => true, 'code' => 'password_changed']);
}

respond(['error' => 'Invalid action', 'code' => 'invalid_action'], 400);
