<?php
require_once '../includes/db.php';
require_once '../includes/appointment_conflict.php';
require_once '../includes/csrf.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if (!isset($_SESSION['_portal_admin_backfill_done'])) {
    try {
        require_once __DIR__ . '/../includes/portal_admin_sync.php';
        backfill_portal_appointments_to_admin($db);
        $_SESSION['_portal_admin_backfill_done'] = true;
    } catch (Throwable $e) {
        error_log('portal admin backfill: ' . $e->getMessage());
    }
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_require_valid();
}

function mapAdminStatusToPortal($status) {
    $norm = strtolower(trim($status));
    if ($norm === 'completed') return 'completed';
    if ($norm === 'cancelled') return 'cancelled';
    if ($norm === 'scheduled' || $norm === 'confirmed' || $norm === 'in progress') return 'scheduled';
    return 'pending';
}

function findPortalUserIdForPatient($db, $patientId) {
    if ($patientId <= 0) return 0;
    $stmt = $db->prepare("SELECT email, phone FROM patients WHERE id = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    if (!$patient) return 0;

    $email = trim((string) ($patient['email'] ?? ''));
    if ($email !== '') {
        $stmt = $db->prepare("SELECT id FROM portal_users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $portal = $stmt->get_result()->fetch_assoc();
            if ($portal) return (int) $portal['id'];
        }
    }

    $phone = preg_replace('/\D+/', '', (string) ($patient['phone'] ?? ''));
    if ($phone !== '') {
        $stmt = $db->prepare(
            "SELECT id
             FROM portal_users
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $portal = $stmt->get_result()->fetch_assoc();
            if ($portal) return (int) $portal['id'];
        }
    }

    return 0;
}

function assertAppointmentDateTimeBookable(string $apptDate, string $apptTime): void {
    if ($apptDate < date('Y-m-d')) {
        respond(['error' => 'Cannot book on a past date.'], 400);
    }
    $t = normalizeApptTime($apptTime);
    $ts = strtotime($apptDate . ' ' . substr($t, 0, 8));
    if ($ts !== false && $ts < time()) {
        respond(['error' => 'Cannot book a time slot in the past.'], 400);
    }
}

/**
 * When an appointment is Completed, ensure a payment row reflects amount due.
 */
function ensure_payment_on_completed(mysqli $db, int $appointmentId): void {
    $stmt = $db->prepare('SELECT patient_id, procedure_name, status FROM appointments WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || ($row['status'] ?? '') !== 'Completed') {
        return;
    }
    $patientId = (int) ($row['patient_id'] ?? 0);
    $desc      = trim((string) ($row['procedure_name'] ?? 'Appointment'));
    if ($patientId <= 0) {
        return;
    }

    $chk = $db->prepare('SELECT id, status FROM payments WHERE appointment_id = ? LIMIT 1');
    if (!$chk) {
        return;
    }
    $chk->bind_param('i', $appointmentId);
    $chk->execute();
    $pay = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($pay) {
        if (($pay['status'] ?? '') === 'Pending') {
            $up = $db->prepare("UPDATE payments SET status = 'Due' WHERE appointment_id = ? AND status = 'Pending'");
            if ($up) {
                $up->bind_param('i', $appointmentId);
                $up->execute();
                $up->close();
            }
        }
        return;
    }

    $amount = 0.00;
    $method = 'Cash';
    $status = 'Due';
    $ins = $db->prepare(
        'INSERT INTO payments (appointment_id, patient_id, amount, payment_method, status, description)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($ins) {
        $ins->bind_param('iidsss', $appointmentId, $patientId, $amount, $method, $status, $desc);
        $ins->execute();
        $ins->close();
    }
}

function syncPortalAppointmentStatus($db, $appointmentId, $adminStatus) {
    if ($appointmentId <= 0) return;
    $stmt = $db->prepare(
        "SELECT patient_id, appointment_date, appointment_time, procedure_name
         FROM appointments
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) return;
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    if (!$appt) return;

    $portalUserId = findPortalUserIdForPatient($db, (int) $appt['patient_id']);
    if ($portalUserId <= 0) return;

    $portalStatus = mapAdminStatusToPortal($adminStatus);
    $date = (string) $appt['appointment_date'];
    $time = normalizeApptTime((string) $appt['appointment_time']);
    $procedure = trim((string) ($appt['procedure_name'] ?? ''));

    $stmt = $db->prepare(
        "UPDATE patient_appointments
         SET status = ?
         WHERE portal_user_id = ?
           AND preferred_date = ?
           AND preferred_time = ?
         ORDER BY CASE
             WHEN reason_label = ? OR reason = ? THEN 0
             ELSE 1
         END, id DESC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('sissss', $portalStatus, $portalUserId, $date, $time, $procedure, $procedure);
        $stmt->execute();
        return;
    }

    // Fallback for older schemas without reason_label/reason columns.
    $stmt = $db->prepare(
        "UPDATE patient_appointments
         SET status = ?
         WHERE portal_user_id = ?
           AND preferred_date = ?
           AND preferred_time = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) return;
    $stmt->bind_param('siss', $portalStatus, $portalUserId, $date, $time);
    $stmt->execute();
}

switch ($method) {
    case 'GET':
        if (isset($_GET['count_by_status']) && $_GET['count_by_status'] == '1') {
            $today = (int) $db->query(
                "SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = CURDATE()"
            )->fetch_assoc()['c'];
            $upcoming = (int) $db->query(
                "SELECT COUNT(*) AS c FROM appointments WHERE appointment_date > CURDATE() AND status = 'Scheduled'"
            )->fetch_assoc()['c'];
            $completed = (int) $db->query(
                "SELECT COUNT(*) AS c FROM appointments WHERE status = 'Completed'"
            )->fetch_assoc()['c'];
            $cancelled = (int) $db->query(
                "SELECT COUNT(*) AS c FROM appointments WHERE status = 'Cancelled'"
            )->fetch_assoc()['c'];
            respond([
                'today'     => $today,
                'upcoming'  => $upcoming,
                'completed' => $completed,
                'cancelled' => $cancelled,
            ]);
        }

        if (isset($_GET['check_conflict']) && $_GET['check_conflict'] == '1') {
            $dentistId = intval($_GET['dentist_id'] ?? 0);
            $date      = $_GET['date'] ?? '';
            $time      = $_GET['time'] ?? '';
            $excludeId = intval($_GET['exclude_id'] ?? 0);
            if (!$dentistId || $date === '' || $time === '') {
                respond(['error' => 'dentist_id, date, and time are required'], 400);
            }
            $conflict = dentistHasConflict($db, $dentistId, $date, $time, $excludeId);
            respond(['conflict' => $conflict]);
        }

        $id        = $_GET['id'] ?? null;
        $date      = $_GET['date'] ?? null;
        $dentistId = $_GET['dentist_id'] ?? null;
        $status    = $_GET['status'] ?? null;
        $search    = $_GET['search'] ?? '';
        $range     = $_GET['range'] ?? null;
        $filter    = $_GET['filter'] ?? null;

        if ($id) {
            $stmt = $db->prepare(
                "SELECT a.*,
                    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                    p.patient_number, p.phone AS patient_phone,
                    d.name AS dentist_name,
                    d.specialization AS dentist_specialization,
                    (SELECT pay.status FROM payments pay WHERE pay.appointment_id = a.id ORDER BY pay.id DESC LIMIT 1) AS payment_status,
                    (SELECT pay.amount FROM payments pay WHERE pay.appointment_id = a.id ORDER BY pay.id DESC LIMIT 1) AS payment_amount
                 FROM appointments a
                 JOIN patients p ON a.patient_id  = p.id
                 JOIN dentists  d ON a.dentist_id = d.id
                 WHERE a.id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $appt = $stmt->get_result()->fetch_assoc();
            if (!$appt) respond(['error' => 'Not found'], 404);
            unset($appt['room']);
            respond($appt);
        }

        $sql = "SELECT a.*,
                    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                    p.patient_number,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
                    d.name AS dentist_name
                FROM appointments a
                JOIN patients p ON a.patient_id  = p.id
                JOIN dentists  d ON a.dentist_id = d.id";

        $conditions = [];
        $params     = [];
        $types      = '';

        if ($filter === 'today') {
            $conditions[] = 'a.appointment_date = CURDATE()';
        } elseif ($filter === 'upcoming') {
            $conditions[] = "a.appointment_date > CURDATE() AND a.status = 'Scheduled'";
        } elseif ($filter === 'completed') {
            $conditions[] = "a.status = 'Completed'";
        } elseif ($filter === 'cancelled') {
            $conditions[] = "a.status = 'Cancelled'";
        }

        if ($date) {
            $conditions[] = "a.appointment_date = ?";
            $params[]      = $date;
            $types        .= 's';
        }
        if ($range === 'today') {
            $conditions[] = "a.appointment_date = CURDATE()";
        }
        if ($range === 'upcoming') {
            $conditions[] = "a.appointment_date >= CURDATE() AND a.status NOT IN ('Cancelled','Completed')";
        }
        if ($dentistId) {
            $conditions[] = "a.dentist_id = ?";
            $params[]      = intval($dentistId);
            $types        .= 'i';
        }
        if ($status) {
            $norm = strtolower(trim($status));
            if ($norm === 'scheduled') {
                $conditions[] = "a.status IN ('Scheduled','Confirmed')";
            } elseif ($norm === 'completed') {
                $conditions[] = "a.status = 'Completed'";
            } elseif ($norm === 'cancelled') {
                $conditions[] = "a.status = 'Cancelled'";
            } else {
                $conditions[] = "a.status = ?";
                $params[]      = $status;
                $types        .= 's';
            }
        }
        if ($search) {
            $conditions[] = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR a.procedure_name LIKE ? OR d.name LIKE ?)";
            $s             = "%$search%";
            $params        = array_merge($params, [$s, $s, $s]);
            $types        .= 'sss';
        }

        if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= ' ORDER BY a.appointment_date, a.appointment_time';

        if ($params) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }

        $appts = [];
        while ($row = $result->fetch_assoc()) {
            unset($row['room']);
            $appts[] = $row;
        }
        respond($appts);
        break;

    case 'POST':
        $body       = json_decode(file_get_contents('php://input'), true);
        $patientId  = intval($body['patient_id']);
        $dentistId  = intval($body['dentist_id']);
        $procName   = $body['procedure_name']  ?? '';
        $procType   = $body['procedure_type']  ?? 'other';
        $apptDate   = $body['appointment_date'] ?? date('Y-m-d');
        $apptTime   = $body['appointment_time'] ?? '09:00';
        $duration   = intval($body['duration_minutes'] ?? 30);
        $room       = $body['room']   ?? '';
        $status     = $body['status'] ?? 'Scheduled';
        $notes      = $body['notes']  ?? '';

        if (!$patientId || !$dentistId || !$procName) {
            respond(['error' => 'patient_id, dentist_id and procedure_name are required'], 400);
        }
        if ($apptTime < '09:00' || $apptTime > '16:30') {
            respond(['error' => 'appointment_time must be between 09:00 and 16:30'], 400);
        }

        assertAppointmentDateTimeBookable($apptDate, $apptTime);

        $apptTimeDb = normalizeApptTime($apptTime);
        $db->begin_transaction();
        try {
            if (!dentistSlotIsFreeForUpdate($db, $dentistId, $apptDate, $apptTimeDb, 0)) {
                $db->rollback();
                respond(['error' => EDROSO_SLOT_CONFLICT_MESSAGE], 409);
            }
            $stmt = $db->prepare(
                "INSERT INTO appointments
                 (patient_id, dentist_id, procedure_name, procedure_type,
                  appointment_date, appointment_time, duration_minutes, room, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('iissssisss',
                $patientId, $dentistId, $procName, $procType,
                $apptDate, $apptTimeDb, $duration, $room, $status, $notes
            );
            if (!$stmt->execute()) {
                $db->rollback();
                respond(['error' => $db->error], 500);
            }
            $newId = (int) $db->insert_id;
            $db->commit();
            if ($status === 'Completed') {
                ensure_payment_on_completed($db, $newId);
            }
            respond(['success' => true, 'id' => $newId], 201);
        } catch (Throwable $e) {
            $db->rollback();
            respond(['error' => $e->getMessage()], 500);
        }
        break;

    case 'PUT':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) respond(['error' => 'ID required'], 400);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = [];
        }

        // Partial update: status-only payloads may still include csrf_token.
        $hasFullUpdateFields = isset($body['patient_id']) || isset($body['dentist_id']) || isset($body['procedure_name'])
            || isset($body['appointment_date']) || isset($body['appointment_time']) || isset($body['duration_minutes'])
            || isset($body['room']) || isset($body['notes']) || isset($body['procedure_type']);
        if (isset($body['status']) && !$hasFullUpdateFields) {
            $stmt = $db->prepare('UPDATE appointments SET status=? WHERE id=?');
            $stmt->bind_param('si', $body['status'], $id);
            if (!$stmt->execute()) {
                respond(['error' => $db->error], 500);
            }
            $statusForSync = (string) ($body['status'] ?? '');
            if ($statusForSync !== '') {
                syncPortalAppointmentStatus($db, $id, $statusForSync);
            }
            if ($statusForSync === 'Completed') {
                ensure_payment_on_completed($db, $id);
            }
            respond(['success' => true]);
        }

        $patientId = intval($body['patient_id'] ?? 0);
        $dentistId = intval($body['dentist_id'] ?? 0);
        $procName  = $body['procedure_name']   ?? '';
        $procType  = $body['procedure_type']   ?? 'other';
        $apptDate  = $body['appointment_date'] ?? date('Y-m-d');
        $apptTime  = $body['appointment_time'] ?? '09:00';
        $duration  = intval($body['duration_minutes'] ?? 30);
        $room      = $body['room']   ?? '';
        $status    = $body['status'] ?? 'Scheduled';
        $notes     = $body['notes']  ?? '';

        if (!$patientId || !$dentistId || $procName === '') {
            respond(['error' => 'patient_id, dentist_id and procedure_name are required'], 400);
        }

        if ($apptTime < '09:00' || $apptTime > '16:30') {
            respond(['error' => 'appointment_time must be between 09:00 and 16:30'], 400);
        }

        assertAppointmentDateTimeBookable($apptDate, $apptTime);

        $apptTimeDb = normalizeApptTime($apptTime);
        $db->begin_transaction();
        try {
            if (!dentistSlotIsFreeForUpdate($db, $dentistId, $apptDate, $apptTimeDb, $id)) {
                $db->rollback();
                respond(['error' => EDROSO_SLOT_CONFLICT_MESSAGE], 409);
            }
            $stmt = $db->prepare(
                'UPDATE appointments SET
                    patient_id=?, dentist_id=?, procedure_name=?, procedure_type=?,
                    appointment_date=?, appointment_time=?, duration_minutes=?,
                    room=?, status=?, notes=?
                 WHERE id=?'
            );
            $stmt->bind_param('iissssisssi',
                $patientId, $dentistId, $procName, $procType,
                $apptDate, $apptTimeDb, $duration, $room, $status, $notes, $id
            );
            if (!$stmt->execute()) {
                $db->rollback();
                respond(['error' => $db->error], 500);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            respond(['error' => $e->getMessage()], 500);
        }

        $statusForSync = (string) ($body['status'] ?? '');
        if ($statusForSync !== '') {
            syncPortalAppointmentStatus($db, $id, $statusForSync);
        }
        if ($statusForSync === 'Completed') {
            ensure_payment_on_completed($db, $id);
        }
        respond(['success' => true]);
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) respond(['error' => 'ID required'], 400);
        $stmt = $db->prepare("DELETE FROM appointments WHERE id=?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) respond(['error' => $db->error], 500);
        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
