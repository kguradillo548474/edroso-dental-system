<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';
requireAuth();

ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_require_valid();
}

function normalizePatientPhone($phone) {
    return preg_replace('/\s+/', '', trim($phone));
}

function isValidPatientPhone($phone) {
    return (bool) preg_match('/^\+639\d{9}$/', normalizePatientPhone($phone));
}

function isValidEmailFormat($email) {
    return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email);
}

function normalizePatientGender($gen) {
    $g = trim((string) $gen);
    if ($g === '' || !in_array($g, ['Male', 'Female', 'Other'], true)) {
        // Empty ENUM '' fails under strict MySQL; use Other (matches "prefer not to say" option in UI).
        return 'Other';
    }
    return $g;
}

function validatePatientDob($dob) {
    if ($dob === null || $dob === '') {
        return 'Date of birth is required.';
    }
    $d = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$d || $d->format('Y-m-d') !== $dob) {
        return 'Invalid date of birth.';
    }
    $today = new DateTime('today');
    if ($d > $today) {
        return 'Date of birth cannot be in the future.';
    }
    return null;
}

function emailExists($db, $email, $excludeId = 0) {
    if ($excludeId > 0) {
        $stmt = $db->prepare('SELECT 1 FROM patients WHERE email = ? AND id != ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $email, $excludeId);
    } else {
        $stmt = $db->prepare('SELECT 1 FROM patients WHERE email = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $email);
    }
    if (!$stmt->execute()) {
        return false;
    }
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->free_result();
    return $exists;
}

try {
switch ($method) {
    case 'GET':
        if (array_key_exists('check_email', $_GET)) {
            $email = trim($_GET['check_email'] ?? '');
            if ($email === '') {
                respond(['exists' => false]);
            }
            if (!isValidEmailFormat($email)) {
                respond(['exists' => false]);
            }
            $excludeId = intval($_GET['exclude_id'] ?? 0);
            respond(['exists' => emailExists($db, $email, $excludeId)]);
        }

        $id     = $_GET['id'] ?? null;
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';

        if ($id) {
            $stmt = $db->prepare(
                "SELECT p.*,
                    COUNT(a.id) AS total_appointments,
                    SUM(CASE WHEN a.status='Completed' THEN 1 ELSE 0 END) AS completed_appointments
                 FROM patients p
                 LEFT JOIN appointments a ON p.id = a.patient_id
                 WHERE p.id = ?
                 GROUP BY p.id"
            );
            if (!$stmt) {
                respond(['error' => 'Database prepare failed: ' . $db->error], 500);
            }
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                respond(['error' => $stmt->error ?: $db->error], 500);
            }
            $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
            if (!$res) {
                respond(['error' => 'Server requires mysqli mysqlnd (get_result). Enable mysqlnd in PHP.'], 500);
            }
            $patient = $res->fetch_assoc();
            if (!$patient) {
                respond(['error' => 'Patient not found'], 404);
            }
            if (isset($_GET['include_history']) && (string) $_GET['include_history'] === '1') {
                $hid = (int) $id;
                $histSql = "SELECT a.id, a.appointment_date, a.appointment_time, a.procedure_name, a.status,
                    d.name AS dentist_name,
                    pay.status AS payment_status, pay.amount AS payment_amount
                 FROM appointments a
                 INNER JOIN dentists d ON d.id = a.dentist_id
                 LEFT JOIN payments pay ON pay.appointment_id = a.id
                 WHERE a.patient_id = ?
                 ORDER BY a.appointment_date DESC, a.appointment_time DESC";
                $hst = $db->prepare($histSql);
                $patient['appointment_history'] = [];
                if ($hst) {
                    $hst->bind_param('i', $hid);
                    if ($hst->execute()) {
                        $hr = $hst->get_result();
                        while ($ar = $hr->fetch_assoc()) {
                            $patient['appointment_history'][] = $ar;
                        }
                    }
                    $hst->close();
                }
            }
            respond($patient);
        }

        $sql = "SELECT p.*,
                    COUNT(a.id) AS total_appointments,
                    MAX(a.appointment_date) AS last_visit
                FROM patients p
                LEFT JOIN appointments a ON p.id = a.patient_id";

        $conditions = [];
        $params     = [];
        $types      = '';

        if ($search) {
            $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ? OR p.patient_number LIKE ? OR p.phone LIKE ?)";
            $s = "%$search%";
            $params = [$s, $s, $s, $s, $s];
            $types .= 'sssss';
        }
        if ($status !== 'all') {
            $conditions[] = "p.status = ?";
            $params[] = $status;
            $types .= 's';
        }
        if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= ' GROUP BY p.id ORDER BY p.created_at DESC';

        if ($params) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                respond(['error' => 'Database prepare failed: ' . $db->error], 500);
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                respond(['error' => $stmt->error ?: $db->error], 500);
            }
            $result = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
            if (!$result) {
                respond(['error' => 'Server requires mysqli mysqlnd for this search.'], 500);
            }
        } else {
            $result = $db->query($sql);
            if (!$result) {
                respond(['error' => $db->error], 500);
            }
        }

        $patients = [];
        while ($row = $result->fetch_assoc()) {
            $patients[] = $row;
        }
        respond($patients);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            respond(['error' => 'Invalid JSON body'], 400);
        }
        $fn    = trim($body['first_name']    ?? '');
        $ln    = trim($body['last_name']     ?? '');
        $email = trim($body['email']         ?? '');
        $phone = $body['phone']              ?? '';
        $dob   = $body['date_of_birth']      ?? null;
        $gen   = normalizePatientGender($body['gender'] ?? '');
        $addr  = $body['address']            ?? '';
        $notes = $body['medical_notes']      ?? '';

        if ($fn === '' || $ln === '') {
            respond(['error' => 'First name and last name are required'], 400);
        }
        $dobErr = validatePatientDob($dob);
        if ($dobErr) {
            respond(['error' => $dobErr], 400);
        }
        $phoneNorm = normalizePatientPhone($phone);
        if ($phoneNorm === '') {
            respond(['error' => 'Contact number is required'], 400);
        }
        if (!isValidPatientPhone($phone)) {
            respond(['error' => 'Enter a valid PH number (e.g. +63 912 345 6789)'], 400);
        }
        if ($email !== '') {
            if (!isValidEmailFormat($email)) {
                respond(['error' => 'Enter a valid email address.'], 400);
            }
            if (emailExists($db, $email, 0)) {
                respond(['error' => 'Email already registered.'], 400);
            }
        }

        // Auto patient number (COUNT-based; guard query failure)
        $cntRes = $db->query('SELECT COUNT(*) AS c FROM patients');
        if (!$cntRes) {
            respond(['error' => 'Database error counting patients: ' . $db->error], 500);
        }
        $countRow = $cntRes->fetch_assoc();
        $nextSeq  = (int) ($countRow['c'] ?? 0) + 1;
        $num      = str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        $patientNumber = 'PAT-' . date('Y') . '-' . $num;

        $stmt = $db->prepare(
            "INSERT INTO patients
             (patient_number, first_name, last_name, email, phone, date_of_birth, gender, address, medical_notes)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) {
            respond(['error' => 'Database prepare failed: ' . $db->error], 500);
        }
        $stmt->bind_param('sssssssss', $patientNumber, $fn, $ln, $email, $phoneNorm, $dob, $gen, $addr, $notes);
        if (!$stmt->execute()) {
            respond(['error' => $stmt->error ?: $db->error], 500);
        }
        respond(['success' => true, 'id' => $db->insert_id, 'patient_number' => $patientNumber], 201);
        break;

    case 'PUT':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) respond(['error' => 'ID required'], 400);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            respond(['error' => 'Invalid JSON body'], 400);
        }

        $fn     = trim($body['first_name']    ?? '');
        $ln     = trim($body['last_name']     ?? '');
        $email  = trim($body['email']        ?? '');
        $phone  = $body['phone']              ?? '';
        $dob    = $body['date_of_birth']      ?? null;
        $gen    = normalizePatientGender($body['gender'] ?? '');
        $addr   = $body['address']            ?? '';
        $notes  = $body['medical_notes']      ?? '';
        $status = $body['status']             ?? 'active';

        if ($fn === '' || $ln === '') {
            respond(['error' => 'First name and last name are required'], 400);
        }
        $dobErr = validatePatientDob($dob);
        if ($dobErr) {
            respond(['error' => $dobErr], 400);
        }
        $phoneNorm = normalizePatientPhone($phone);
        if ($phoneNorm === '') {
            respond(['error' => 'Contact number is required'], 400);
        }
        if (!isValidPatientPhone($phone)) {
            respond(['error' => 'Enter a valid PH number (e.g. +63 912 345 6789)'], 400);
        }
        if ($email !== '') {
            if (!isValidEmailFormat($email)) {
                respond(['error' => 'Enter a valid email address.'], 400);
            }
            if (emailExists($db, $email, $id)) {
                respond(['error' => 'Email already registered.'], 400);
            }
        }

        $stmt = $db->prepare(
            "UPDATE patients SET
             first_name=?, last_name=?, email=?, phone=?,
             date_of_birth=?, gender=?, address=?, medical_notes=?, status=?
             WHERE id=?"
        );
        if (!$stmt) {
            respond(['error' => 'Database prepare failed: ' . $db->error], 500);
        }
        $stmt->bind_param('sssssssssi', $fn, $ln, $email, $phoneNorm, $dob, $gen, $addr, $notes, $status, $id);
        if (!$stmt->execute()) {
            respond(['error' => $stmt->error ?: $db->error], 500);
        }
        respond(['success' => true]);
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) respond(['error' => 'ID required'], 400);
        $stmt = $db->prepare("DELETE FROM patients WHERE id=?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) respond(['error' => $db->error], 500);
        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
} catch (Throwable $e) {
    respond(['error' => 'Request failed: ' . $e->getMessage()], 500);
}
