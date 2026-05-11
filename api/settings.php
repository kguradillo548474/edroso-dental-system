<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_require_valid();
}

function ensureSettingsTable(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(191) NOT NULL PRIMARY KEY,
        `value` TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seedDefaultSettingsIfEmpty(mysqli $db): void {
    $r = $db->query('SELECT COUNT(*) AS c FROM settings');
    $row = $r ? $r->fetch_assoc() : ['c' => 0];
    if ((int) ($row['c'] ?? 0) > 0) {
        return;
    }
    $defaults = [
        'clinic_name'                  => 'Edroso Dental Clinic',
        'dentist_name'                 => 'Dr. Alex Edroso',
        'prc_license'                  => '',
        'clinic_contact'               => '09171234567',
        'clinic_address'               => '',
        'clinic_hours'                 => 'Mon–Sat 9:00 AM – 5:00 PM',
        'time_per_patient'             => '30',
        'walkin_limit'                 => '10',
        'appointment_system_enabled'   => '1',
        'patient_record_preferences'   => '{"required_fields":["name","contact"],"notes_format":"free_text"}',
        'billing_preferences'          => '{"payment_types":["Cash","GCash","Bank Transfer","PhilHealth","HMO"],"down_payment_enabled":false,"down_payment_percent":0,"receipt_format":"simple"}',
        'portal_referral_sources'      => '["Facebook","Instagram","TikTok","Referral","Walk-in","Other"]',
        'auto_logout_minutes'          => '30',
    ];
    $stmt = $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->bind_param('ss', $k, $v);
        $stmt->execute();
    }
    $stmt->close();
}

/** Whitelisted keys for customer-site / marketing (no staff session). */
function respond_public_clinic_settings(mysqli $db): void {
    ensureSettingsTable($db);
    seedDefaultSettingsIfEmpty($db);
    $allowed = [
        'clinic_name',
        'clinic_address',
        'clinic_hours',
        'clinic_contact',
        'dentist_name',
        'prc_license',
    ];
    $out = array_fill_keys($allowed, '');
    if ($res = $db->query('SELECT `key`, `value` FROM settings')) {
        while ($row = $res->fetch_assoc()) {
            $k = (string) ($row['key'] ?? '');
            if (array_key_exists($k, $out)) {
                $out[$k] = (string) ($row['value'] ?? '');
            }
        }
    }
    respond($out);
}

if ($method === 'GET' && ($_GET['public'] ?? '') === '1') {
    $db = getDB();
    respond_public_clinic_settings($db);
}

requireAuth();

$db = getDB();

ensureSettingsTable($db);
seedDefaultSettingsIfEmpty($db);

/** Keys staff may create/update (clinic-facing profile only). */
function staffWritableSettingKeys(): array {
    return [
        'clinic_name',
        'dentist_name',
        'prc_license',
        'clinic_contact',
        'clinic_address',
        'clinic_hours',
    ];
}

function assertStaffMayWriteSettingsKey(string $key): void {
    if (sessionUserRole() !== 'staff') {
        return;
    }
    if (!in_array($key, staffWritableSettingKeys(), true)) {
        respond(['error' => 'Staff accounts cannot change this setting. Ask an administrator.', 'code' => 'staff_settings_limited'], 403);
    }
}

/**
 * Relative web path under project root (e.g. assets/uploads/payment_qr/…).
 */
function settings_cashless_qr_path_is_allowed(string $path): bool {
    $path = trim(str_replace('\\', '/', $path));
    return (bool) preg_match('#^assets/uploads/payment_qr/qr_[a-f0-9]{16}\.(jpg|png|webp)$#i', $path);
}

function settings_delete_cashless_qr_file_if_managed(mysqli $db): void {
    $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
    if (!$stmt) {
        return;
    }
    $k = 'cashless_payment_qr_path';
    $stmt->bind_param('s', $k);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return;
    }
    $old = trim((string) ($row['value'] ?? ''));
    if ($old !== '' && settings_cashless_qr_path_is_allowed($old)) {
        $full = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $old);
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'upload_cashless_payment_qr') {
    if (sessionUserRole() === 'staff') {
        respond(['error' => 'Only administrators can upload the payment QR code.'], 403);
    }
    if (!isset($_FILES['qr']) || !is_array($_FILES['qr'])) {
        respond(['error' => 'No file uploaded'], 400);
    }
    $err = (int) ($_FILES['qr']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        respond(['error' => 'Upload failed (code ' . $err . ')'], 400);
    }
    $tmp = (string) ($_FILES['qr']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        respond(['error' => 'Invalid upload'], 400);
    }
    $maxBytes = 5 * 1024 * 1024;
    $size = (int) ($_FILES['qr']['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        respond(['error' => 'File must be under 5 MB'], 400);
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string) finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        respond(['error' => 'Allowed types: JPEG, PNG, WebP'], 400);
    }
    $ext = $allowed[$mime];
    $destDir = __DIR__ . '/../assets/uploads/payment_qr';
    if (!is_dir($destDir)) {
        if (!@mkdir($destDir, 0755, true)) {
            respond(['error' => 'Could not create upload directory'], 500);
        }
    }
    settings_delete_cashless_qr_file_if_managed($db);
    $basename = 'qr_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = $destDir . DIRECTORY_SEPARATOR . $basename;
    if (!@move_uploaded_file($tmp, $destPath)) {
        respond(['error' => 'Could not save file'], 500);
    }
    $webPath = 'assets/uploads/payment_qr/' . $basename;
    $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $key = 'cashless_payment_qr_path';
    $stmt->bind_param('ss', $key, $webPath);
    if (!$stmt->execute()) {
        $stmt->close();
        respond(['error' => $db->error], 500);
    }
    $stmt->close();
    respond(['success' => true, 'path' => $webPath]);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'clear_cashless_payment_qr') {
    if (sessionUserRole() === 'staff') {
        respond(['error' => 'Only administrators can change the payment QR code.'], 403);
    }
    settings_delete_cashless_qr_file_if_managed($db);
    $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $key = 'cashless_payment_qr_path';
    $empty = '';
    $stmt->bind_param('ss', $key, $empty);
    if (!$stmt->execute()) {
        $stmt->close();
        respond(['error' => $db->error], 500);
    }
    $stmt->close();
    respond(['success' => true]);
}

if ($method === 'GET') {
    $settings = [];
    if ($res = $db->query('SELECT `key`, `value` FROM settings')) {
        while ($row = $res->fetch_assoc()) {
            $settings[$row['key']] = $row['value'];
        }
    }
    respond(['settings' => $settings]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    // Batch: { "settings": { "key": "value", ... } }
    if (!empty($body['settings']) && is_array($body['settings'])) {
        $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach ($body['settings'] as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            assertStaffMayWriteSettingsKey($k);
            $val = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('ss', $k, $val);
            if (!$stmt->execute()) {
                respond(['error' => $db->error], 500);
            }
        }
        respond(['success' => true]);
    }

    // Single key/value
    $key = $body['key'] ?? '';
    if ($key !== '') {
        assertStaffMayWriteSettingsKey((string) $key);
        $value = $body['value'] ?? '';
        if (!is_scalar($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } else {
            $value = (string) $value;
        }
        $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        $stmt->bind_param('ss', $key, $value);
        if (!$stmt->execute()) {
            respond(['error' => $db->error], 500);
        }
        respond(['success' => true]);
    }

    respond(['error' => 'Provide `settings` object or `key` and `value`.'], 400);
}

respond(['error' => 'Method not allowed'], 405);
