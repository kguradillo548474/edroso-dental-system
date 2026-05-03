<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';

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

// ── Login ─────────────────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    csrf_require_valid($parsedPost);
    $username = trim((string) ($parsedPost['username'] ?? ''));
    $password = trim((string) ($parsedPost['password'] ?? ''));

    if (!$username || !$password) {
        respond(['error' => 'Username and password are required.', 'code' => 'missing_credentials'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $valid = $user && password_verify($password, $user['password']);

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
