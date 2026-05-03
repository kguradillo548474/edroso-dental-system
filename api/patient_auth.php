<?php
/**
 * Patient portal auth — JSON API (portal session keys separate from staff users).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';

/** Normalize security-answer input for hashing / verification (case-insensitive, trimmed). */
function normalize_portal_recovery_answer(string $answer): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $answer)));
}

/**
 * Add recovery columns on legacy portal_users tables (no-op if already present).
 */
function ensure_portal_recovery_columns(mysqli $db): bool
{
    static $migrated = false;
    if ($migrated) {
        return true;
    }
    $cols = [
        'recovery_question' => 'VARCHAR(255) NULL',
        'recovery_answer_hash' => 'VARCHAR(255) NULL',
    ];
    foreach ($cols as $name => $definition) {
        $esc = $db->real_escape_string($name);
        $chk = $db->query("SHOW COLUMNS FROM portal_users LIKE '{$esc}'");
        if ($chk && $chk->num_rows === 0) {
            $q = 'ALTER TABLE portal_users ADD COLUMN `' . str_replace('`', '``', $name) . '` ' . $definition;
            if (!$db->query($q)) {
                return false;
            }
        }
    }
    $migrated = true;

    return true;
}

function recovery_reset_rate_key(string $email): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return hash('sha256', strtolower(trim($email)) . "\0" . $ip);
}

function recovery_reset_rate_allowed(string $key): bool
{
    $until = (int) ($_SESSION['recovery_block_until'][$key] ?? 0);

    return $until < time();
}

function recovery_reset_rate_fail(string $key): void
{
    $_SESSION['recovery_fail_count'][$key] = (int) ($_SESSION['recovery_fail_count'][$key] ?? 0) + 1;
    if ((int) $_SESSION['recovery_fail_count'][$key] >= 6) {
        $_SESSION['recovery_block_until'][$key] = time() + 900;
        $_SESSION['recovery_fail_count'][$key] = 0;
    }
}

function recovery_reset_rate_clear(string $key): void
{
    unset($_SESSION['recovery_fail_count'][$key], $_SESSION['recovery_block_until'][$key]);
}

/**
 * Create portal_users if missing (avoids silent INSERT failures on fresh DBs).
 */
function ensure_portal_users_table(mysqli $db): bool
{
    static $done = false;
    if ($done) {
        return ensure_portal_recovery_columns($db);
    }
    $sql = 'CREATE TABLE IF NOT EXISTS portal_users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      full_name VARCHAR(100) NOT NULL,
      email VARCHAR(100) NOT NULL UNIQUE,
      phone VARCHAR(20) NOT NULL,
      dob DATE NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      recovery_question VARCHAR(255) NULL,
      recovery_answer_hash VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (!$db->query($sql)) {
        return false;
    }
    $done = true;

    return ensure_portal_recovery_columns($db);
}

/**
 * Ensure admin patients table exists (mirror on register).
 */
function ensure_patients_table(mysqli $db): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $sql = 'CREATE TABLE IF NOT EXISTS patients (
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_number VARCHAR(20) UNIQUE,
      first_name VARCHAR(100) NOT NULL,
      last_name VARCHAR(100) NOT NULL,
      email VARCHAR(150),
      phone VARCHAR(30),
      date_of_birth DATE,
      gender ENUM(\'Male\',\'Female\',\'Other\'),
      address TEXT,
      medical_notes TEXT,
      status ENUM(\'active\',\'inactive\') DEFAULT \'active\',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (!$db->query($sql)) {
        return false;
    }
    $done = true;

    return true;
}

/**
 * Split portal full name into first/last for patients table (both NOT NULL).
 *
 * @return array{0:string,1:string}
 */
function portal_full_name_to_patient_names(string $full_name): array
{
    $t = trim($full_name);
    if ($t === '') {
        return ['-', '-'];
    }
    $pos = strpos($t, ' ');
    if ($pos === false) {
        return [$t, '-'];
    }
    $first = trim(substr($t, 0, $pos));
    $last  = trim(substr($t, $pos + 1));
    if ($first === '') {
        $first = '-';
    }
    if ($last === '') {
        $last = '-';
    }

    return [$first, $last];
}

/**
 * @return array{id:int,name:string,email:string,phone:string,dob:string,has_recovery_question:bool,recovery_question:?string}|null
 */
function portal_me_payload(): ?array
{
    if (empty($_SESSION['portal_user_id'])) {
        return null;
    }
    $uid  = (int) $_SESSION['portal_user_id'];
    $name = (string) ($_SESSION['portal_user_name'] ?? '');
    $email = '';
    $phone = '';
    $dob = '';
    $recQ = '';
    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        $stmt = null;
    } else {
        $stmt = $db->prepare('SELECT full_name, email, phone, dob, recovery_question FROM portal_users WHERE id = ? LIMIT 1');
    }
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        if ($stmt->execute()) {
            $fn = '';
            $em = '';
            $ph = '';
            $dbDob = '';
            $rq = '';
            $stmt->bind_result($fn, $em, $ph, $dbDob, $rq);
            if ($stmt->fetch()) {
                $name  = (string) $fn;
                $email = (string) $em;
                $phone = (string) $ph;
                $dob = (string) $dbDob;
                $recQ = (string) $rq;
            }
        }
        $stmt->close();
    }
    $hasRec = $recQ !== '';

    return [
        'id'                     => $uid,
        'name'                   => $name,
        'email'                  => $email,
        'phone'                  => $phone,
        'dob'                    => $dob,
        'has_recovery_question'  => $hasRec,
        'recovery_question'      => $hasRec ? $recQ : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'me') {
    $p = portal_me_payload();
    if ($p !== null) {
        respond($p);
    }
    respond(['error' => 'not logged in'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'csrf') {
    respond(['csrf_token' => csrf_get_token()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['error' => 'Invalid JSON body'], 400);
}

$action = $body['action'] ?? '';

if ($action !== 'me') {
    csrf_require_valid($body);
}

if ($action === 'me') {
    $p = portal_me_payload();
    if ($p !== null) {
        respond($p);
    }
    respond(['error' => 'not logged in'], 401);
}

if ($action === 'register') {
    $first_in = trim((string) ($body['first_name'] ?? ''));
    $last_in  = trim((string) ($body['last_name'] ?? ''));
    $full_legacy = trim((string) ($body['full_name'] ?? ''));
    $email      = trim((string) ($body['email'] ?? ''));
    $phone      = normalize_portal_phone_for_validation((string) ($body['phone'] ?? ''));
    $dob        = trim((string) ($body['dob'] ?? ''));
    $password   = (string) ($body['password'] ?? '');

    if ($first_in !== '' && $last_in !== '') {
        $full_name = $first_in . ' ' . $last_in;
        $patient_first = $first_in;
        $patient_last  = $last_in;
    } elseif ($full_legacy !== '') {
        $full_name = $full_legacy;
        [$patient_first, $patient_last] = portal_full_name_to_patient_names($full_name);
    } elseif ($first_in !== '' || $last_in !== '') {
        respond(['error' => 'Both first name and last name are required.'], 400);
    } else {
        respond(['error' => 'First name and last name are required.'], 400);
    }
    $emailErr = validate_portal_email($email);
    if ($emailErr !== null) {
        respond(['error' => $emailErr], 400);
    }
    $phoneErr = validate_portal_phone((string) ($body['phone'] ?? ''));
    if ($phoneErr !== null) {
        respond(['error' => $phoneErr], 400);
    }
    if ($dob === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        respond(['error' => 'Please enter a valid date of birth.'], 400);
    }
    if ($dob >= date('Y-m-d')) {
        respond(['error' => 'Date of birth must be before today.'], 400);
    }
    if (strlen($password) < 8) {
        respond(['error' => 'Password must be at least 8 characters.'], 400);
    }

    $recoveryQuestion = trim((string) ($body['recovery_question'] ?? ''));
    $recoveryAnswer   = trim((string) ($body['recovery_answer'] ?? ''));
    if (($recoveryQuestion !== '') xor ($recoveryAnswer !== '')) {
        respond(['error' => 'Security question and answer must both be filled, or both left empty.'], 400);
    }
    if ($recoveryQuestion !== '') {
        if (strlen($recoveryQuestion) < 3 || strlen($recoveryQuestion) > 255) {
            respond(['error' => 'Security question must be between 3 and 255 characters.'], 400);
        }
        $normAns = normalize_portal_recovery_answer($recoveryAnswer);
        if (strlen($normAns) < 2) {
            respond(['error' => 'Security answer is too short.'], 400);
        }
    }

    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        respond(['error' => 'Database setup failed: ' . $db->error], 500);
    }
    if (!ensure_patients_table($db)) {
        respond(['error' => 'Database setup failed (patients table): ' . $db->error], 500);
    }

    $chk = $db->prepare('SELECT 1 FROM portal_users WHERE email = ? LIMIT 1');
    if (!$chk) {
        respond(['error' => 'Database error: ' . $db->error], 500);
    }
    $chk->bind_param('s', $email);
    if (!$chk->execute()) {
        $err = $chk->error;
        $chk->close();
        respond(['error' => 'Database error: ' . $err], 500);
    }
    $chk->store_result();
    $exists = $chk->num_rows > 0;
    $chk->close();
    if ($exists) {
        respond(['error' => 'An account with this email already exists.'], 400);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $password = '';
    unset($body['password']);

    $recQBind = null;
    $recHBind = null;
    if ($recoveryQuestion !== '') {
        $recQBind = $recoveryQuestion;
        $recHBind = password_hash(normalize_portal_recovery_answer($recoveryAnswer), PASSWORD_DEFAULT);
    }

    // Portal account must succeed on its own. Patient-row mirror is best-effort so a
    // patients-table schema mismatch never rolls back the portal user (that looked like "no account").
    $ins = $db->prepare('INSERT INTO portal_users (full_name, email, phone, dob, password_hash, recovery_question, recovery_answer_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$ins) {
        respond(['error' => 'Database error: ' . $db->error], 500);
    }
    $ins->bind_param('sssssss', $full_name, $email, $phone, $dob, $hash, $recQBind, $recHBind);
    if (!$ins->execute()) {
        $errno = (int) $ins->errno;
        $msg   = (string) $ins->error;
        $ins->close();
        if ($errno === 1062) {
            respond(['error' => 'An account with this email already exists.'], 400);
        }
        respond(['error' => 'Registration failed: ' . $msg], 500);
    }
    $ins->close();

    // Mirror to admin patients table (non-fatal if INSERT fails)
    $checkStmt = $db->prepare('SELECT id, phone, first_name, last_name, date_of_birth FROM patients WHERE email = ? LIMIT 1');
    if ($checkStmt) {
        $checkStmt->bind_param('s', $email);
        if ($checkStmt->execute()) {
            $checkStmt->store_result();
            if ($checkStmt->num_rows === 0) {
                $patientNumber = 'PAT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $firstName = $patient_first;
                $lastName  = $patient_last;
                $phoneNorm = preg_replace('/\s+/', '', $phone);
                $gender    = 'Other';
                $addr      = '';
                $notes     = '';

                $insertPatient = $db->prepare(
                    'INSERT INTO patients (patient_number, first_name, last_name, email, phone, date_of_birth, gender, address, medical_notes)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                if ($insertPatient) {
                    $insertPatient->bind_param(
                        'sssssssss',
                        $patientNumber,
                        $firstName,
                        $lastName,
                        $email,
                        $phoneNorm,
                        $dob,
                        $gender,
                        $addr,
                        $notes
                    );
                    if (!$insertPatient->execute()) {
                        error_log('patient_auth register: patients mirror failed: ' . $insertPatient->error);
                    }
                    $insertPatient->close();
                } else {
                    error_log('patient_auth register: patients prepare failed: ' . $db->error);
                }
            } else {
                $checkStmt->bind_result($existingPatientId, $existingPhone, $existingFirstName, $existingLastName, $existingDob);
                if ($checkStmt->fetch()) {
                    $phoneNorm = preg_replace('/\s+/', '', $phone);
                    $firstName = $patient_first;
                    $lastName  = $patient_last;
                    if ($existingPhone !== $phoneNorm || $existingFirstName !== $firstName || $existingLastName !== $lastName || $existingDob !== $dob) {
                        $updatePatient = $db->prepare(
                            'UPDATE patients SET first_name=?, last_name=?, phone=?, date_of_birth=?, gender=?, address=?, medical_notes=? WHERE id=?'
                        );
                        if ($updatePatient) {
                            $gender = 'Other';
                            $addr = '';
                            $notes = '';
                            $updatePatient->bind_param(
                                'sssssssi',
                                $firstName,
                                $lastName,
                                $phoneNorm,
                                $dob,
                                $gender,
                                $addr,
                                $notes,
                                $existingPatientId
                            );
                            if (!$updatePatient->execute()) {
                                error_log('patient_auth register: patients mirror update failed: ' . $updatePatient->error);
                            }
                            $updatePatient->close();
                        } else {
                            error_log('patient_auth register: patients update prepare failed: ' . $db->error);
                        }
                    }
                }
            }
        }
        $checkStmt->close();
    } else {
        error_log('patient_auth register: patients check prepare failed: ' . $db->error);
    }

    respond(['success' => true]);
}

if ($action === 'login') {
    $email    = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        respond(['error' => 'Invalid credentials.'], 401);
    }

    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        respond(['error' => 'Database setup failed: ' . $db->error], 500);
    }
    $stmt = $db->prepare('SELECT id, full_name, password_hash FROM portal_users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        respond(['error' => 'Database error: ' . $db->error], 500);
    }
    $stmt->bind_param('s', $email);
    $row = null;
    if ($stmt->execute()) {
        $rid = 0;
        $fn  = '';
        $ph  = '';
        $stmt->bind_result($rid, $fn, $ph);
        if ($stmt->fetch()) {
            $row = ['id' => $rid, 'full_name' => $fn, 'password_hash' => $ph];
        }
    }
    $stmt->close();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        respond(['error' => 'Invalid credentials.'], 401);
    }
    $password = '';
    unset($body['password']);

    session_regenerate_id(true);
    $_SESSION['portal_user_id']   = (int) $row['id'];
    $_SESSION['portal_user_name'] = $row['full_name'];

    respond(['success' => true, 'name' => $row['full_name']]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    respond(['success' => true]);
}

function ensure_reset_tokens_table(mysqli $db): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $sql = 'CREATE TABLE IF NOT EXISTS reset_tokens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      portal_user_id INT NOT NULL,
      token VARCHAR(10) NOT NULL,
      expires_at DATETIME NOT NULL,
      used TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_reset_user (portal_user_id),
      INDEX idx_reset_token (token),
      FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (!$db->query($sql)) {
        return false;
    }
    $done = true;

    return true;
}

if ($action === 'change_password') {
    if (empty($_SESSION['portal_user_id'])) {
        respond(['error' => 'Unauthorized'], 401);
    }
    $uid = (int) $_SESSION['portal_user_id'];
    $current = (string) ($body['current_password'] ?? '');
    $new     = (string) ($body['new_password'] ?? '');
    $confirm = (string) ($body['confirm_password'] ?? '');
    if ($current === '') {
        respond(['error' => 'Current password is required.', 'field' => 'current_password'], 422);
    }
    if (strlen($new) < 8) {
        respond(['error' => 'New password must be at least 8 characters.', 'field' => 'new_password'], 422);
    }
    if ($new !== $confirm) {
        respond(['error' => 'New password and confirmation do not match.', 'field' => 'confirm_password'], 422);
    }
    if ($new === $current) {
        respond(['error' => 'New password must be different from the current password.', 'field' => 'new_password'], 422);
    }
    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt = $db->prepare('SELECT password_hash FROM portal_users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !password_verify($current, (string) ($row['password_hash'] ?? ''))) {
        respond(['error' => 'Current password is incorrect.', 'field' => 'current_password'], 422);
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $up = $db->prepare('UPDATE portal_users SET password_hash = ? WHERE id = ?');
    if (!$up) {
        respond(['error' => 'Database error'], 500);
    }
    $up->bind_param('si', $hash, $uid);
    if (!$up->execute()) {
        respond(['error' => 'Could not update password.'], 500);
    }
    $up->close();
    respond(['success' => true]);
}

if ($action === 'update_recovery') {
    if (empty($_SESSION['portal_user_id'])) {
        respond(['error' => 'Unauthorized'], 401);
    }
    $uid = (int) $_SESSION['portal_user_id'];
    $current = (string) ($body['current_password'] ?? '');
    $rq = trim((string) ($body['recovery_question'] ?? ''));
    $ra = trim((string) ($body['recovery_answer'] ?? ''));
    if ($current === '') {
        respond(['error' => 'Current password is required.', 'field' => 'current_password'], 422);
    }
    if ($rq === '' || $ra === '') {
        respond(['error' => 'Security question and answer are required.', 'field' => 'recovery_question'], 422);
    }
    if (strlen($rq) < 3 || strlen($rq) > 255) {
        respond(['error' => 'Security question must be between 3 and 255 characters.', 'field' => 'recovery_question'], 422);
    }
    $normAns = normalize_portal_recovery_answer($ra);
    if (strlen($normAns) < 2) {
        respond(['error' => 'Security answer is too short.', 'field' => 'recovery_answer'], 422);
    }
    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt = $db->prepare('SELECT password_hash FROM portal_users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !password_verify($current, (string) ($row['password_hash'] ?? ''))) {
        respond(['error' => 'Current password is incorrect.', 'field' => 'current_password'], 422);
    }
    $ansHash = password_hash($normAns, PASSWORD_DEFAULT);
    $up = $db->prepare('UPDATE portal_users SET recovery_question = ?, recovery_answer_hash = ? WHERE id = ?');
    if (!$up) {
        respond(['error' => 'Database error'], 500);
    }
    $up->bind_param('ssi', $rq, $ansHash, $uid);
    if (!$up->execute()) {
        respond(['error' => 'Could not save security question.'], 500);
    }
    $up->close();
    respond(['success' => true]);
}

if ($action === 'recovery_challenge') {
    $email = trim((string) ($body['email'] ?? ''));
    if ($email === '' || validate_portal_email($email) !== null) {
        respond(['success' => false, 'message' => 'Enter a valid email address.'], 400);
    }
    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        respond(['success' => false, 'message' => 'Server error. Try again later.'], 500);
    }
    $stmt = $db->prepare('SELECT recovery_question, recovery_answer_hash FROM portal_users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        respond(['success' => false, 'message' => 'Server error. Try again later.'], 500);
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        respond(['success' => false, 'message' => 'No account found with that email.'], 404);
    }
    $rq = trim((string) ($row['recovery_question'] ?? ''));
    $rh = (string) ($row['recovery_answer_hash'] ?? '');
    if ($rq === '' || $rh === '') {
        respond([
            'success' => false,
            'code'    => 'no_recovery_question',
            'message' => 'This account has no security question yet. Log in and add one under Portal → Settings, or use an email code if you still receive mail.',
        ], 422);
    }
    respond(['success' => true, 'question' => $rq]);
}

if ($action === 'forgot_password') {
    $email = trim((string) ($body['email'] ?? ''));
    if ($email === '' || validate_portal_email($email) !== null) {
        respond(['message' => 'If that email is registered, a code has been sent.']);
    }
    $db = getDB();
    if (!ensure_portal_users_table($db) || !ensure_reset_tokens_table($db)) {
        respond(['message' => 'If that email is registered, a code has been sent.']);
    }
    $stmt = $db->prepare('SELECT id FROM portal_users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        respond(['message' => 'If that email is registered, a code has been sent.']);
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) {
        respond(['message' => 'If that email is registered, a code has been sent.']);
    }
    $portalUserId = (int) $u['id'];
    $code = (string) random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 15 * 60);
    $ins = $db->prepare('INSERT INTO reset_tokens (portal_user_id, token, expires_at, used) VALUES (?, ?, ?, 0)');
    if ($ins) {
        $ins->bind_param('iss', $portalUserId, $code, $expires);
        $ins->execute();
        $ins->close();
    }
    $subject = 'Edroso Dental — password reset code';
    $msgBody = "Your verification code is: {$code}\n\nIt expires in 15 minutes. If you did not request this, ignore this email.";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($email, $subject, $msgBody, $headers);
    respond(['message' => 'If that email is registered, a code has been sent.']);
}

if ($action === 'reset_password') {
    $email           = trim((string) ($body['email'] ?? ''));
    $token           = trim((string) ($body['token'] ?? ''));
    $recoveryAnswer  = trim((string) ($body['recovery_answer'] ?? ''));
    $new             = (string) ($body['new_password'] ?? '');
    $confirm         = (string) ($body['confirm_password'] ?? '');
    if ($email === '' || validate_portal_email($email) !== null || strlen($new) < 8) {
        respond(['error' => 'Invalid request.', 'field' => 'email'], 400);
    }
    if ($new !== $confirm) {
        respond(['error' => 'Passwords do not match.', 'field' => 'confirm_password'], 422);
    }

    $db = getDB();
    if (!ensure_portal_users_table($db)) {
        respond(['error' => 'Database error'], 500);
    }

    if ($token !== '') {
        if (!ensure_reset_tokens_table($db)) {
            respond(['error' => 'Database error'], 500);
        }
        $stmt = $db->prepare(
            'SELECT rt.id AS rid, rt.portal_user_id, rt.used, rt.expires_at
             FROM reset_tokens rt
             INNER JOIN portal_users u ON u.id = rt.portal_user_id
             WHERE u.email = ? AND rt.token = ?
             ORDER BY rt.id DESC LIMIT 1'
        );
        if (!$stmt) {
            respond(['error' => 'Database error'], 500);
        }
        $stmt->bind_param('ss', $email, $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int) ($row['used'] ?? 1) !== 0) {
            respond(['error' => 'Invalid or expired code.', 'field' => 'token'], 400);
        }
        if (strtotime((string) ($row['expires_at'] ?? '')) < time()) {
            respond(['error' => 'Invalid or expired code.', 'field' => 'token'], 400);
        }
        $rid = (int) $row['rid'];
        $puid = (int) $row['portal_user_id'];
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $db->begin_transaction();
        try {
            $up = $db->prepare('UPDATE portal_users SET password_hash = ? WHERE id = ?');
            $up->bind_param('si', $hash, $puid);
            if (!$up->execute()) {
                throw new RuntimeException($up->error);
            }
            $up->close();
            $mk = $db->prepare('UPDATE reset_tokens SET used = 1 WHERE id = ?');
            $mk->bind_param('i', $rid);
            $mk->execute();
            $mk->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            respond(['error' => 'Could not reset password.'], 500);
        }
        recovery_reset_rate_clear(recovery_reset_rate_key($email));
        respond(['success' => true]);
    }

    if ($recoveryAnswer === '') {
        respond(['error' => 'Enter the email code or your security answer.', 'field' => 'token'], 400);
    }

    $rateKey = recovery_reset_rate_key($email);
    if (!recovery_reset_rate_allowed($rateKey)) {
        respond(['error' => 'Too many attempts. Wait about 15 minutes and try again.', 'field' => 'recovery_answer'], 429);
    }

    $stmt = $db->prepare('SELECT id, recovery_answer_hash FROM portal_users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $urow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ansHash = (string) ($urow['recovery_answer_hash'] ?? '');
    if (!$urow || $ansHash === '' || !password_verify(normalize_portal_recovery_answer($recoveryAnswer), $ansHash)) {
        recovery_reset_rate_fail($rateKey);
        respond(['error' => 'That answer does not match our records.', 'field' => 'recovery_answer'], 400);
    }
    $puid = (int) $urow['id'];
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->begin_transaction();
    try {
        $up = $db->prepare('UPDATE portal_users SET password_hash = ? WHERE id = ?');
        $up->bind_param('si', $hash, $puid);
        if (!$up->execute()) {
            throw new RuntimeException($up->error);
        }
        $up->close();
        if (ensure_reset_tokens_table($db)) {
            $cl = $db->prepare('UPDATE reset_tokens SET used = 1 WHERE portal_user_id = ? AND used = 0');
            if ($cl) {
                $cl->bind_param('i', $puid);
                $cl->execute();
                $cl->close();
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        respond(['error' => 'Could not reset password.'], 500);
    }
    recovery_reset_rate_clear($rateKey);
    respond(['success' => true]);
}

respond(['error' => 'Unknown action'], 400);
