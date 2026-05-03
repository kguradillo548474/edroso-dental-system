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
