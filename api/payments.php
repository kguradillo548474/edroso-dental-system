<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_require_valid();
}

/** Allowed payment modes (TC-063). */
function payment_methods_allowed(): array {
    return ['Cash', 'Card', 'GCash', 'Insurance'];
}

function normalize_payment_method_input(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (in_array($raw, payment_methods_allowed(), true)) {
        return $raw;
    }
    $legacy = [
        'Credit Card' => 'Card',
        'Bank Transfer' => 'Insurance',
        'PhilHealth' => 'Insurance',
        'HMO' => 'Insurance',
    ];
    return $legacy[$raw] ?? null;
}

function payments_fail_execute(mysqli $db, mysqli_stmt $stmt): void {
    $err = $stmt->error !== '' ? $stmt->error : $db->error;
    if ((int) $db->errno === 1265 || stripos($err, 'Data truncated') !== false) {
        respond([
            'error' => 'Database migration required: run sql/upgrade_tc063_payment_method.sql (payment_method column).',
            'code' => 'payment_method_schema',
        ], 500);
    }
    respond(['error' => $err], 500);
}

/**
 * Clinic letterhead for receipts / records (from settings table).
 *
 * @return array{clinic_name: string, dentist_name: string, prc_license: string, clinic_contact: string, clinic_hours: string}
 */
function loadClinicHeader(mysqli $db): array {
    $defaults = [
        'clinic_name' => 'Edroso Dental Clinic',
        'dentist_name' => '',
        'prc_license' => '',
        'clinic_contact' => '',
        'clinic_hours' => '',
    ];
    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT `key`, `value` FROM settings WHERE `key` IN ($placeholders)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return $defaults;
    }
    $types = str_repeat('s', count($keys));
    $stmt->bind_param($types, ...$keys);
    if (!$stmt->execute()) {
        $stmt->close();
        return $defaults;
    }
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (isset($defaults[$row['key']])) {
            $defaults[$row['key']] = (string) $row['value'];
        }
    }
    $stmt->close();
    return $defaults;
}

/** Date shown in admin when payment_date was never set (legacy rows). */
function payment_display_date_row(array $row): ?string {
    $pd = $row['payment_date'] ?? null;
    if ($pd !== null && trim((string) $pd) !== '') {
        return substr(trim((string) $pd), 0, 10);
    }
    $ca = $row['created_at'] ?? null;
    if ($ca !== null && trim((string) $ca) !== '') {
        return substr(trim((string) $ca), 0, 10);
    }
    $ad = $row['appointment_date'] ?? null;
    if ($ad !== null && trim((string) $ad) !== '') {
        return substr(trim((string) $ad), 0, 10);
    }
    return null;
}

function loadReceiptFormat(mysqli $db): string {
    $res = @$db->query("SELECT `value` FROM settings WHERE `key` = 'billing_preferences' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $j = json_decode((string) $row['value'], true);
        if (is_array($j) && !empty($j['receipt_format']) && $j['receipt_format'] === 'detailed') {
            return 'detailed';
        }
    }
    return 'simple';
}

switch ($method) {
    case 'GET':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? null;

        if ($id > 0) {
            $stmt = $db->prepare("SELECT py.*, CONCAT(p.first_name,' ',p.last_name) as patient_name, p.patient_number FROM payments py JOIN patients p ON py.patient_id = p.id WHERE py.id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                respond(['error' => 'Payment not found'], 404);
            }
            $row['display_date'] = payment_display_date_row($row);
            $row['clinic_header'] = loadClinicHeader($db);
            $row['receipt_format'] = loadReceiptFormat($db);
            respond($row);
        }

        $sql = "SELECT py.*, 
            CONCAT(p.first_name,' ',p.last_name) as patient_name, 
            p.patient_number,
            a.procedure_name, a.appointment_date
            FROM payments py 
            JOIN patients p ON py.patient_id = p.id
            LEFT JOIN appointments a ON py.appointment_id = a.id";

        $conditions = [];
        $params = [];
        $types = '';

        if ($status) {
            $conditions[] = "py.status = ?";
            $params[] = $status;
            $types .= 's';
        }
        if ($search) {
            $conditions[] = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR p.patient_number LIKE ? OR py.description LIKE ?)";
            $s = "%$search%";
            $params = array_merge($params, [$s, $s, $s]);
            $types .= 'sss';
        }
        if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= " ORDER BY py.created_at DESC";

        if ($params) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }

        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $row['display_date'] = payment_display_date_row($row);
            $payments[] = $row;
        }

        $statsResult = $db->query("SELECT 
            SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END) as total_paid,
            SUM(CASE WHEN status='Pending' THEN amount ELSE 0 END) as total_pending,
            COUNT(CASE WHEN status='Pending' THEN 1 END) as pending_count,
            COUNT(*) as total_count
            FROM payments");
        $stats = $statsResult->fetch_assoc();

        respond([
            'payments' => $payments,
            'stats' => $stats,
            'clinic_header' => loadClinicHeader($db),
            'receipt_format' => loadReceiptFormat($db),
        ]);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $apptId = !empty($body['appointment_id']) ? intval($body['appointment_id']) : null;
        $patientId = intval($body['patient_id'] ?? 0);
        $amount = floatval($body['amount'] ?? 0);
        $payMethodRaw = (string) ($body['payment_method'] ?? '');
        $payMethod = normalize_payment_method_input($payMethodRaw);
        if ($payMethod === null) {
            respond(['error' => 'Payment method is required and must be one of: Cash, Card, GCash, Insurance.'], 400);
        }
        $payStatus = $body['status'] ?? 'Pending';
        $desc = $body['description'] ?? '';
        $payDate = ($payStatus === 'Paid') ? date('Y-m-d') : null;

        if ($patientId <= 0 || $amount < 0) {
            respond(['error' => 'Valid patient and amount are required.'], 400);
        }

        // mysqli bind_param('i', …) cannot bind NULL for appointment_id; use explicit NULL in SQL.
        if ($apptId === null || $apptId <= 0) {
            $stmt = $db->prepare('INSERT INTO payments (appointment_id, patient_id, amount, payment_method, status, description, payment_date) VALUES (NULL,?,?,?,?,?,?)');
            $stmt->bind_param('idssss', $patientId, $amount, $payMethod, $payStatus, $desc, $payDate);
        } else {
            $stmt = $db->prepare('INSERT INTO payments (appointment_id, patient_id, amount, payment_method, status, description, payment_date) VALUES (?,?,?,?,?,?,?)');
            $stmt->bind_param('iidssss', $apptId, $patientId, $amount, $payMethod, $payStatus, $desc, $payDate);
        }
        if (!$stmt->execute()) {
            payments_fail_execute($db, $stmt);
        }
        respond(['success' => true, 'id' => $db->insert_id], 201);
        break;

    case 'PUT':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) respond(['error' => 'ID required'], 400);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $bodyNoCsrf = $body;
        unset($bodyNoCsrf['csrf_token']);

        /** Mark Paid / change status only — body includes csrf_token from admin client. */
        if (isset($body['status']) && count($bodyNoCsrf) === 1) {
            $status = (string) $body['status'];
            $stmt = $db->prepare(
                "UPDATE payments SET status = ?, payment_date = CASE
                    WHEN ? IN ('Paid','Partial') THEN IFNULL(payment_date, CURDATE())
                    ELSE NULL
                 END WHERE id = ?"
            );
            $stmt->bind_param('ssi', $status, $status, $id);
        } else {
            $amount = floatval($body['amount'] ?? 0);
            $pmRaw = (string) ($body['payment_method'] ?? '');
            $pm = normalize_payment_method_input($pmRaw);
            if ($pm === null) {
                respond(['error' => 'Payment method must be one of: Cash, Card, GCash, Insurance.'], 400);
            }
            $status = (string) ($body['status'] ?? 'Pending');
            $desc = (string) ($body['description'] ?? '');
            $stmt = $db->prepare(
                "UPDATE payments SET amount=?, payment_method=?, status=?, description=?,
                 payment_date = CASE
                    WHEN ? IN ('Paid','Partial') THEN IFNULL(payment_date, CURDATE())
                    ELSE NULL
                 END WHERE id=?"
            );
            $stmt->bind_param('dssssi', $amount, $pm, $status, $desc, $status, $id);
        }
        if (!$stmt->execute()) {
            payments_fail_execute($db, $stmt);
        }
        respond(['success' => true]);
        break;

    case 'DELETE':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) respond(['error' => 'ID required'], 400);
        $stmt = $db->prepare("DELETE FROM payments WHERE id=?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) respond(['error' => $db->error], 500);
        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
