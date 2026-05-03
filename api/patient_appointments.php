<?php
/**
 * Patient portal appointments — availability + booking + list + cancel.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/appointment_conflict.php';
require_once __DIR__ . '/../includes/portal_booking_mirror.php';
require_once __DIR__ . '/../includes/availability_slots.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Create patient_appointments table if it does not exist (mirrors sql/patient_appointments.sql).
 */
function ensure_patient_appointments_table(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $db->query("CREATE TABLE IF NOT EXISTS patient_appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        portal_user_id INT NOT NULL,
        dentist_id INT NULL DEFAULT NULL,
        preferred_date DATE NOT NULL,
        preferred_time TIME NOT NULL,
        reason VARCHAR(100),
        service_id INT DEFAULT NULL,
        reason_label VARCHAR(150) DEFAULT NULL,
        meeting_type ENUM('online','in_person') DEFAULT 'in_person' COMMENT 'Deprecated: app now always stores in_person',
        patient_details JSON,
        health_history JSON,
        payment_method VARCHAR(50),
        status ENUM('pending','scheduled','completed','cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_preferred_date (preferred_date),
        INDEX idx_status (status),
        INDEX idx_date_status (preferred_date, status),
        FOREIGN KEY (portal_user_id) REFERENCES portal_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Older installs: add dentist_id for per-dentist availability and booking.
 */
function ensure_patient_appointments_dentist_id_column(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $col = @$db->query("SHOW COLUMNS FROM patient_appointments LIKE 'dentist_id'");
    if ($col && $col->num_rows === 0) {
        @$db->query(
            'ALTER TABLE patient_appointments ADD COLUMN dentist_id INT NULL DEFAULT NULL AFTER portal_user_id'
        );
    }
    if ($col) {
        $col->free();
    }
    $done = true;
}

ensure_patient_appointments_table(getDB());
ensure_patient_appointments_dentist_id_column(getDB());

function walk_in_seat_limit(mysqli $db): int {
    $default = 10;
    try {
        $q = $db->query("SELECT `value` FROM settings WHERE `key` = 'walk_in_limit' LIMIT 1");
        if ($q && ($row = $q->fetch_assoc()) && isset($row['value'])) {
            $n = (int) $row['value'];
            return $n > 0 ? $n : $default;
        }
    } catch (Throwable $e) {
        /* settings table may not exist */
    }
    return $default;
}

function normalize_time_hhmm(string $t): string {
    $t = trim($t);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
        return str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }
    return $t;
}

function parse_time_to_minutes(string $hhmmOrHms): int {
    $n = normalize_time_hhmm($hhmmOrHms);
    if (!preg_match('/^(\d{2}):(\d{2})$/', $n, $m)) {
        return 0;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

function minutes_to_slot_hhmm(int $mins): string {
    $mins = max(0, min(24 * 60 - 1, $mins));
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
}

/**
 * Defaults apply when settings keys are missing. dentists uses status = 'active' (no is_active column).
 *
 * @return array{clinic_start_time:string,clinic_end_time:string,interval_minutes:int,max_per_slot:int}
 */
function clinic_slot_generation_config(mysqli $db): array {
    $defaults = [
        'clinic_start_time' => '09:00',
        'clinic_end_time'   => '17:00',
        'time_per_patient'  => '30',
    ];
    $resolved = $defaults;
    try {
        $k0 = 'clinic_start_time';
        $k1 = 'clinic_end_time';
        $k2 = 'time_per_patient';
        $stmt = $db->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN (?,?,?)');
        if ($stmt) {
            $stmt->bind_param('sss', $k0, $k1, $k2);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $k = (string) ($row['key'] ?? '');
                if ($k !== '' && array_key_exists($k, $defaults)) {
                    $v = trim((string) ($row['value'] ?? ''));
                    if ($v !== '') {
                        $resolved[$k] = $v;
                    }
                }
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        /* settings table may not exist */
    }

    $startNorm = normalize_time_hhmm($resolved['clinic_start_time']);
    $endNorm   = normalize_time_hhmm($resolved['clinic_end_time']);
    $interval  = (int) $resolved['time_per_patient'];
    if ($interval < 5 || $interval > 240) {
        $interval = 30;
    }

    $startM = parse_time_to_minutes($startNorm);
    $endM   = parse_time_to_minutes($endNorm);
    if ($endM <= $startM) {
        $startM = parse_time_to_minutes($defaults['clinic_start_time']);
        $endM   = parse_time_to_minutes($defaults['clinic_end_time']);
    }

    $maxPerSlot = 1;
    try {
        $cntStmt = $db->prepare("SELECT COUNT(*) AS c FROM dentists WHERE status = 'active'");
        if ($cntStmt) {
            $cntStmt->execute();
            $crow = $cntStmt->get_result()->fetch_assoc();
            $cntStmt->close();
            $maxPerSlot = max(1, (int) ($crow['c'] ?? 0));
        }
    } catch (Throwable $e) {
        $maxPerSlot = 1;
    }

    return [
        'clinic_start_time' => normalize_time_hhmm(minutes_to_slot_hhmm($startM)),
        'clinic_end_time'   => normalize_time_hhmm(minutes_to_slot_hhmm($endM)),
        'interval_minutes'  => $interval,
        'max_per_slot'      => $maxPerSlot,
    ];
}

/**
 * @param array{clinic_start_time:string,clinic_end_time:string,interval_minutes:int,max_per_slot:int} $cfg
 * @return list<string> slot start labels HH:MM (aligned to interval; last slot ends by clinic_end)
 */
function build_clinic_time_slots_from_config(array $cfg): array {
    $startM = parse_time_to_minutes($cfg['clinic_start_time']);
    $endM   = parse_time_to_minutes($cfg['clinic_end_time']);
    $iv     = (int) ($cfg['interval_minutes'] ?? 30);
    if ($iv < 5) {
        $iv = 30;
    }
    if ($endM <= $startM) {
        return [];
    }
    $slots = [];
    for ($t = $startM; $t + $iv <= $endM; $t += $iv) {
        $slots[] = minutes_to_slot_hhmm($t);
    }
    return $slots;
}

function require_portal_session(): void {
    if (empty($_SESSION['portal_user_id'])) {
        respond(['error' => 'Unauthorized'], 401);
    }
}

/**
 * Map admin appointments.status to portal patient_appointments.status (matches api/appointments.php).
 */
function map_admin_status_to_portal_status(string $status): string {
    $norm = strtolower(trim($status));
    if ($norm === 'completed') {
        return 'completed';
    }
    if ($norm === 'cancelled') {
        return 'cancelled';
    }
    if ($norm === 'scheduled' || $norm === 'confirmed' || $norm === 'in progress') {
        return 'scheduled';
    }
    return 'pending';
}

/**
 * Synthetic portal list ids for admin-only rows (prefix avoids collision with numeric patient_appointments.id).
 */
function portal_list_id_for_admin_appointment(int $adminAppointmentId): string {
    return 'a' . $adminAppointmentId;
}

/**
 * Portal rows used to hide duplicate admin rows (same slot as a portal booking / mirror).
 *
 * @return list<array{preferred_date:string,preferred_time:string,dentist_id:?int}>
 */
function portal_slot_fingerprints_for_dedup(mysqli $db, int $portalUserId, string $portalStatus): array {
    if ($portalStatus === 'scheduled') {
        $stmt = $db->prepare(
            "SELECT preferred_date, preferred_time, dentist_id
             FROM patient_appointments
             WHERE portal_user_id = ? AND status IN ('pending','scheduled')"
        );
    } elseif ($portalStatus === 'completed') {
        $stmt = $db->prepare(
            "SELECT preferred_date, preferred_time, dentist_id
             FROM patient_appointments
             WHERE portal_user_id = ? AND status = 'completed'"
        );
    } elseif ($portalStatus === 'cancelled') {
        $stmt = $db->prepare(
            "SELECT preferred_date, preferred_time, dentist_id
             FROM patient_appointments
             WHERE portal_user_id = ? AND status = 'cancelled'"
        );
    } else {
        return [];
    }
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $portalUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $stmt->close();
    return $out;
}

function admin_appointment_matches_portal_fingerprint(array $admin, array $fingerprints): bool {
    $ad = (string) ($admin['appointment_date'] ?? '');
    $at = normalizeApptTime((string) ($admin['appointment_time'] ?? ''));
    $dent = isset($admin['dentist_id']) ? (int) $admin['dentist_id'] : 0;
    foreach ($fingerprints as $f) {
        $fd = (string) ($f['preferred_date'] ?? '');
        $ft = normalizeApptTime((string) ($f['preferred_time'] ?? ''));
        $fdent = isset($f['dentist_id']) ? (int) $f['dentist_id'] : 0;
        if ($fd !== $ad || $ft !== $at) {
            continue;
        }
        if ($fdent > 0 && $dent > 0 && $fdent !== $dent) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * Admin appointments to surface on the portal for a linked patient (manual bookings, etc.).
 *
 * @return list<array<string,mixed>>
 */
function fetch_admin_appointments_for_portal_status(mysqli $db, int $adminPatientId, string $portalStatus): array {
    $where = '';
    switch ($portalStatus) {
        case 'pending':
            return [];
        case 'scheduled':
            $where = "ap.status IN ('Scheduled','Confirmed','In Progress')";
            break;
        case 'completed':
            $where = "ap.status = 'Completed'";
            break;
        case 'cancelled':
            $where = "ap.status = 'Cancelled'";
            break;
        default:
            return [];
    }
    $sql = "SELECT ap.*, d.name AS dentist_name, d.specialization AS dentist_specialization
            FROM appointments ap
            LEFT JOIN dentists d ON d.id = ap.dentist_id
            WHERE ap.patient_id = ? AND $where
            ORDER BY ap.appointment_date DESC, ap.appointment_time DESC";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $adminPatientId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * @param array<string,mixed> $ap
 * @return array<string,mixed>
 */
function admin_appointment_to_portal_list_row(
    array $ap,
    string $portalUserName,
    string $portalUserEmail
): array {
    $adminId = (int) ($ap['id'] ?? 0);
    $proc    = trim((string) ($ap['procedure_name'] ?? ''));
    $portalSt = map_admin_status_to_portal_status((string) ($ap['status'] ?? ''));
    return [
        'id'                       => portal_list_id_for_admin_appointment($adminId),
        'portal_user_id'           => null,
        'dentist_id'               => isset($ap['dentist_id']) ? (int) $ap['dentist_id'] : null,
        'preferred_date'           => (string) ($ap['appointment_date'] ?? ''),
        'preferred_time'           => (string) ($ap['appointment_time'] ?? ''),
        'reason'                   => $proc !== '' ? $proc : 'Appointment',
        'reason_label'             => $proc !== '' ? $proc : 'Appointment',
        'service_id'               => null,
        'meeting_type'             => 'in_person',
        'patient_details'          => null,
        'health_history'           => null,
        'payment_method'           => null,
        'status'                   => $portalSt,
        'created_at'               => $ap['created_at'] ?? null,
        'portal_user_name'         => $portalUserName,
        'portal_user_email'        => $portalUserEmail,
        'dentist_name'             => $ap['dentist_name'] ?? null,
        'dentist_specialization'   => $ap['dentist_specialization'] ?? null,
        'procedure_name'           => $proc !== '' ? $proc : 'Appointment',
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    csrf_require_valid();
}

// ── POST cancel ?action=cancel&id=X or id=aN (admin-only appointment) ───
if ($method === 'POST' && ($_GET['action'] ?? '') === 'cancel') {
    require_portal_session();
    $uid   = (int) $_SESSION['portal_user_id'];
    $db    = getDB();
    $rawId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

    if (preg_match('/^a(\d+)$/', $rawId, $m)) {
        $adminId = (int) $m[1];
        if ($adminId <= 0) {
            respond(['error' => 'Invalid appointment id'], 400);
        }
        $adminPatientId = find_admin_patient_id($db, $uid);
        if ($adminPatientId === null) {
            respond(['error' => 'Could not cancel appointment'], 400);
        }
        $chk = $db->prepare('SELECT id, status FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1');
        if (!$chk) {
            respond(['error' => 'Database error'], 500);
        }
        $chk->bind_param('ii', $adminId, $adminPatientId);
        $chk->execute();
        $apRow = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$apRow) {
            respond(['error' => 'Could not cancel appointment'], 400);
        }
        if (strtolower((string) ($apRow['status'] ?? '')) === 'cancelled') {
            respond(['success' => true]);
        }
        $cancelled = 'Cancelled';
        $up = $db->prepare("UPDATE appointments SET status = ? WHERE id = ? AND patient_id = ? AND status != 'Cancelled'");
        if (!$up || !$up->bind_param('sii', $cancelled, $adminId, $adminPatientId) || !$up->execute() || $up->affected_rows === 0) {
            if ($up) {
                $up->close();
            }
            respond(['error' => 'Could not cancel appointment'], 400);
        }
        $up->close();
        respond(['success' => true]);
    }

    $id = (int) $rawId;
    if ($id <= 0) {
        respond(['error' => 'Invalid appointment id'], 400);
    }
    $stmt = $db->prepare(
        'UPDATE patient_appointments SET status = ? WHERE id = ? AND portal_user_id = ?'
    );
    $st = 'cancelled';
    $stmt->bind_param('sii', $st, $id, $uid);
    if (!$stmt->execute() || $stmt->affected_rows === 0) {
        $stmt->close();
        respond(['error' => 'Could not cancel appointment'], 400);
    }
    $stmt->close();

    // ── Sync cancellation to admin appointments table ────────────────
    try {
        $sel = $db->prepare(
            'SELECT preferred_date, preferred_time, reason_label FROM patient_appointments WHERE id = ? LIMIT 2'
        );
        $sel->bind_param('i', $id);
        $sel->execute();
        $sel->bind_result($prefDate, $prefTime, $reasonLabel);
        if ($sel->fetch()) {
            $sel->close();
            $adminPatientId = find_admin_patient_id($db, $uid);
            if ($adminPatientId !== null) {
                $adminStatus = portal_status_to_admin('cancelled');
                $updated = false;
                $sync = $db->prepare(
                    "UPDATE appointments SET status = ?
                     WHERE appointment_date = ? AND appointment_time = ? AND patient_id = ?
                     AND status != ?"
                );
                $sync->bind_param('sssis', $adminStatus, $prefDate, $prefTime, $adminPatientId, $adminStatus);
                $sync->execute();
                $updated = $sync->affected_rows > 0;
                $sync->close();

                // Fallback for legacy mirrored rows with bad time (e.g. 00:00:00).
                if (!$updated) {
                    $pick = $db->prepare(
                        "SELECT id
                         FROM appointments
                         WHERE patient_id = ?
                           AND appointment_date = ?
                           AND status != ?
                           AND notes LIKE 'Portal booking%'
                         ORDER BY
                           CASE WHEN procedure_name = ? THEN 0 ELSE 1 END,
                           ABS(TIME_TO_SEC(TIMEDIFF(appointment_time, ?))) ASC,
                           id DESC
                         LIMIT 1"
                    );
                    if ($pick) {
                        $pick->bind_param('issss', $adminPatientId, $prefDate, $adminStatus, $reasonLabel, $prefTime);
                        $pick->execute();
                        $pick->bind_result($adminApptId);
                        if ($pick->fetch()) {
                            $pick->close();
                            $syncById = $db->prepare("UPDATE appointments SET status = ? WHERE id = ? AND status != ?");
                            if ($syncById) {
                                $syncById->bind_param('sis', $adminStatus, $adminApptId, $adminStatus);
                                $syncById->execute();
                                $syncById->close();
                            }
                        } else {
                            $pick->close();
                        }
                    }
                }
            }
        } else {
            $sel->close();
        }
    } catch (Throwable $e) {
        error_log('patient_appointments cancel sync: ' . $e->getMessage());
    }

    respond(['success' => true]);
}

// ── GET ?id=N or id=aN — single appointment detail (portal session) ──────
if ($method === 'GET' && isset($_GET['id']) && $_GET['id'] !== '') {
    require_portal_session();
    $rawId = trim((string) $_GET['id']);
    $uid   = (int) $_SESSION['portal_user_id'];
    $db    = getDB();

    if (preg_match('/^a(\d+)$/', $rawId, $m)) {
        $adminId = (int) $m[1];
        if ($adminId <= 0) {
            respond(['error' => 'Invalid appointment id'], 400);
        }
        $adminPatientId = find_admin_patient_id($db, $uid);
        if ($adminPatientId === null) {
            respond(['error' => 'Not found'], 404);
        }
        $stmt = $db->prepare(
            'SELECT ap.*, d.name AS dentist_name, d.specialization AS dentist_specialization
             FROM appointments ap
             LEFT JOIN dentists d ON d.id = ap.dentist_id
             WHERE ap.id = ? AND ap.patient_id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            respond(['error' => 'Database error'], 500);
        }
        $stmt->bind_param('ii', $adminId, $adminPatientId);
        $stmt->execute();
        $ap = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ap) {
            respond(['error' => 'Not found'], 404);
        }
        $proc = trim((string) ($ap['procedure_name'] ?? ''));
        $row  = [
            'id'                     => portal_list_id_for_admin_appointment($adminId),
            'portal_user_id'         => $uid,
            'dentist_id'             => isset($ap['dentist_id']) ? (int) $ap['dentist_id'] : null,
            'preferred_date'         => (string) ($ap['appointment_date'] ?? ''),
            'preferred_time'         => (string) ($ap['appointment_time'] ?? ''),
            'reason'                 => $proc !== '' ? $proc : 'Appointment',
            'reason_label'           => $proc !== '' ? $proc : 'Appointment',
            'service_id'             => null,
            'meeting_type'           => 'in_person',
            'patient_details'        => null,
            'health_history'         => null,
            'payment_method'         => null,
            'status'                 => map_admin_status_to_portal_status((string) ($ap['status'] ?? '')),
            'created_at'             => $ap['created_at'] ?? null,
            'dentist_name'           => $ap['dentist_name'] ?? null,
            'dentist_specialization' => $ap['dentist_specialization'] ?? null,
        ];
        $row['procedure_name'] = $proc !== '' ? $proc : 'Appointment';

        $row['clinic_address'] = '';
        $addrRes = @$db->query("SELECT `value` FROM settings WHERE `key` = 'clinic_address' LIMIT 1");
        if ($addrRes && ($ar = $addrRes->fetch_assoc())) {
            $row['clinic_address'] = (string) ($ar['value'] ?? '');
        }

        $row['payment_status'] = null;
        $ps = $db->prepare('SELECT status FROM payments WHERE appointment_id = ? ORDER BY id DESC LIMIT 1');
        if ($ps) {
            $ps->bind_param('i', $adminId);
            $ps->execute();
            $pr = $ps->get_result()->fetch_assoc();
            $ps->close();
            if ($pr && isset($pr['status'])) {
                $row['payment_status'] = $pr['status'];
            }
        }

        $row['notes'] = trim((string) ($ap['notes'] ?? ''));
        respond($row);
    }

    $id = (int) $rawId;
    if ($id <= 0) {
        respond(['error' => 'Invalid appointment id'], 400);
    }
    $stmt = $db->prepare(
        'SELECT a.*, d.name AS dentist_name, d.specialization AS dentist_specialization
         FROM patient_appointments a
         LEFT JOIN dentists d ON d.id = a.dentist_id
         WHERE a.id = ? AND a.portal_user_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        respond(['error' => 'Not found'], 404);
    }
    if (isset($row['patient_details']) && is_string($row['patient_details'])) {
        $row['patient_details'] = json_decode($row['patient_details'], true);
    }
    if (isset($row['health_history']) && is_string($row['health_history'])) {
        $row['health_history'] = json_decode($row['health_history'], true);
    }
    $rl = isset($row['reason_label']) ? trim((string) $row['reason_label']) : '';
    $rn = isset($row['reason']) ? trim((string) $row['reason']) : '';
    $row['procedure_name'] = $rl !== '' ? $rl : $rn;

    $row['clinic_address'] = '';
    $addrRes = @$db->query("SELECT `value` FROM settings WHERE `key` = 'clinic_address' LIMIT 1");
    if ($addrRes && ($ar = $addrRes->fetch_assoc())) {
        $row['clinic_address'] = (string) ($ar['value'] ?? '');
    }

    $row['payment_status'] = null;
    $adminPatientId = find_admin_patient_id($db, $uid);
    $dentId = isset($row['dentist_id']) ? (int) $row['dentist_id'] : 0;
    if ($adminPatientId !== null && $dentId > 0) {
        $prefDate = (string) ($row['preferred_date'] ?? '');
        $prefTime = normalizeApptTime((string) ($row['preferred_time'] ?? ''));
        $ps = $db->prepare(
            'SELECT pay.status FROM appointments ap
             LEFT JOIN payments pay ON pay.appointment_id = ap.id
             WHERE ap.patient_id = ? AND ap.appointment_date = ? AND ap.appointment_time = ? AND ap.dentist_id = ?
             ORDER BY ap.id DESC LIMIT 1'
        );
        if ($ps) {
            $ps->bind_param('issi', $adminPatientId, $prefDate, $prefTime, $dentId);
            $ps->execute();
            $pr = $ps->get_result()->fetch_assoc();
            $ps->close();
            if ($pr && isset($pr['status'])) {
                $row['payment_status'] = $pr['status'];
            }
        }
    }

    $rln = trim((string) ($row['reason_label'] ?? ''));
    $rr  = trim((string) ($row['reason'] ?? ''));
    $row['notes'] = $rln !== '' ? $rln : ($rr !== '' ? $rr : '');

    respond($row);
}

// ── GET ?date=YYYY-MM-DD&dentist_id=N (server-generated time slots, per dentist) ─
if ($method === 'GET' && isset($_GET['date']) && $_GET['date'] !== '') {
    $date = $_GET['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(['error' => 'Invalid date'], 400);
    }

    $dentistId = isset($_GET['dentist_id']) ? (int) $_GET['dentist_id'] : 0;
    if ($dentistId <= 0) {
        respond(['error' => 'dentist_id is required'], 400);
    }

    $db = getDB();
    $vd = $db->prepare("SELECT id FROM dentists WHERE id = ? AND status = 'active' LIMIT 1");
    if (!$vd) {
        respond(['error' => 'Database error'], 500);
    }
    $vd->bind_param('i', $dentistId);
    $vd->execute();
    $vdOk = (bool) $vd->get_result()->fetch_assoc();
    $vd->close();
    if (!$vdOk) {
        respond(['error' => 'Invalid or inactive dentist'], 400);
    }

    $slots = edroso_available_slots_response($db, $dentistId, $date);
    respond(['slots' => $slots]);
}

// ── GET ?user_id=X&status=Y ───────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['user_id'], $_GET['status'])) {
    require_portal_session();
    $userId = (int) $_GET['user_id'];
    $status  = trim((string) $_GET['status']);
    if ($userId !== (int) $_SESSION['portal_user_id']) {
        respond(['error' => 'Unauthorized'], 401);
    }
    $allowed = ['pending', 'scheduled', 'completed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        respond(['error' => 'Invalid status'], 400);
    }
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT a.*, u.full_name AS portal_user_name, u.email AS portal_user_email,
                d.name AS dentist_name, d.specialization AS dentist_specialization
         FROM patient_appointments a
         INNER JOIN portal_users u ON u.id = a.portal_user_id
         LEFT JOIN dentists d ON d.id = a.dentist_id
         WHERE a.portal_user_id = ? AND a.status = ?
         ORDER BY a.preferred_date DESC, a.preferred_time DESC'
    );
    $stmt->bind_param('is', $userId, $status);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    foreach ($rows as &$r) {
        if (isset($r['patient_details']) && is_string($r['patient_details'])) {
            $r['patient_details'] = json_decode($r['patient_details'], true);
        }
        if (isset($r['health_history']) && is_string($r['health_history'])) {
            $r['health_history'] = json_decode($r['health_history'], true);
        }
        $rl = isset($r['reason_label']) ? trim((string) $r['reason_label']) : '';
        $rn = isset($r['reason']) ? trim((string) $r['reason']) : '';
        $r['procedure_name'] = $rl !== '' ? $rl : $rn;
    }
    unset($r);

    $adminPatientId = find_admin_patient_id($db, $userId);
    if ($adminPatientId !== null && $status !== 'pending') {
        $fingerprints = portal_slot_fingerprints_for_dedup($db, $userId, $status);
        $puName  = '';
        $puEmail = '';
        $puStmt  = $db->prepare('SELECT full_name, email FROM portal_users WHERE id = ? LIMIT 1');
        if ($puStmt) {
            $puStmt->bind_param('i', $userId);
            $puStmt->execute();
            $puStmt->bind_result($puName, $puEmail);
            $puStmt->fetch();
            $puStmt->close();
        }
        foreach (fetch_admin_appointments_for_portal_status($db, $adminPatientId, $status) as $ap) {
            if (admin_appointment_matches_portal_fingerprint($ap, $fingerprints)) {
                continue;
            }
            $rows[] = admin_appointment_to_portal_list_row($ap, (string) $puName, (string) $puEmail);
        }
    }

    respond(['appointments' => $rows]);
}

// ── POST JSON new booking ───────────────────────────────────────────────────
if ($method === 'POST') {
    require_portal_session();
    $portal_user_id = (int) $_SESSION['portal_user_id'];

    // 1) Auth done above. 2) Parse + validate payload before limits (TC-036).
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(['error' => 'Invalid JSON body'], 400);
    }

    $required = ['date', 'time', 'service_id', 'dentist_id', 'reason_label'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $body)) {
            respond(['error' => 'Missing field: ' . $field], 400);
        }
    }
    if (trim((string) ($body['date'] ?? '')) === '') {
        respond(['error' => 'Missing field: date'], 400);
    }
    if (trim((string) ($body['time'] ?? '')) === '') {
        respond(['error' => 'Missing field: time'], 400);
    }
    if ((int) ($body['service_id'] ?? 0) <= 0) {
        respond(['error' => 'Missing field: service_id'], 400);
    }
    if ((int) ($body['dentist_id'] ?? 0) <= 0) {
        respond(['error' => 'Missing field: dentist_id'], 400);
    }
    if (trim((string) ($body['reason_label'] ?? '')) === '') {
        respond(['error' => 'Missing field: reason_label'], 400);
    }

    $date = trim((string) ($body['date'] ?? ''));
    $time = trim((string) ($body['time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(['error' => 'Invalid date'], 400);
    }
    $timeNorm = normalize_time_hhmm($time);
    if (!preg_match('/^\d{2}:\d{2}$/', $timeNorm)) {
        respond(['error' => 'Invalid time'], 400);
    }
    $timeSql = $timeNorm . ':00';
    if ($date < date('Y-m-d')) {
        respond(['error' => 'Date cannot be in the past'], 400);
    }
    if ($date === date('Y-m-d')) {
        $nowM = parse_time_to_minutes(date('H:i'));
        $slotM = parse_time_to_minutes($timeNorm);
        if ($slotM <= $nowM) {
            respond(['error' => 'Selected time has already passed today.'], 400);
        }
    }

    $service_id   = isset($body['service_id']) ? (int) $body['service_id'] : 0;
    $dentist_id   = isset($body['dentist_id']) ? (int) $body['dentist_id'] : 0;
    $reason_label = isset($body['reason_label']) ? trim((string) $body['reason_label']) : '';
    $meeting_type = 'in_person';
    $payment_method = trim((string) ($body['payment_method'] ?? ''));
    if ($payment_method === '') {
        respond(['error' => 'Payment method is required'], 400);
    }
    $consent = !empty($body['consent']);
    if (!$consent) {
        respond(['error' => 'Consent is required'], 400);
    }

    $patient_details = $body['patient_details'] ?? null;
    $health_history  = $body['health_history'] ?? null;
    if (!is_array($patient_details) || !is_array($health_history)) {
        respond(['error' => 'Invalid patient or health payload'], 400);
    }

    $bookingEmail = (string) ($patient_details['email'] ?? '');
    $bookingPhone = (string) ($patient_details['phone'] ?? '');
    $emailErr = validate_portal_email($bookingEmail);
    if ($emailErr !== null) {
        respond(['error' => $emailErr, 'field' => 'patient_details.email'], 422);
    }
    $phoneErr = validate_portal_phone($bookingPhone);
    if ($phoneErr !== null) {
        respond(['error' => $phoneErr, 'field' => 'patient_details.phone'], 422);
    }

    $db = getDB();
    $dobStmt = $db->prepare('SELECT dob FROM portal_users WHERE id = ? LIMIT 1');
    if (!$dobStmt) {
        respond(['error' => 'Could not verify patient profile'], 500);
    }
    $dobStmt->bind_param('i', $portal_user_id);
    if (!$dobStmt->execute()) {
        $dobStmt->close();
        respond(['error' => 'Could not verify patient profile'], 500);
    }
    $dobStmt->bind_result($profileDob);
    if (!$dobStmt->fetch()) {
        $dobStmt->close();
        respond(['error' => 'Could not verify patient profile'], 400);
    }
    $dobStmt->close();
    $patient_details['dob'] = (string) $profileDob;
    $patient_details['consent'] = true;

    $pdJson = json_encode($patient_details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hhJson = json_encode($health_history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($pdJson === false || $hhJson === false) {
        respond(['error' => 'Could not encode details'], 500);
    }

    $reason = function_exists('mb_substr') ? mb_substr($reason_label, 0, 100) : substr($reason_label, 0, 100);
    if (strlen($reason_label) > 150) {
        $reason_label = function_exists('mb_substr') ? mb_substr($reason_label, 0, 150) : substr($reason_label, 0, 150);
    }

    // ── 3) Active booking cap (max 2 pending+scheduled) — distinct from rate limit (TC-038) ──
    $activeLimitMsg = ['error' => 'You have reached the maximum of 2 active bookings.'];
    $preLimit = $db->prepare(
        'SELECT COUNT(*) AS total FROM patient_appointments
         WHERE portal_user_id = ? AND status IN (\'pending\',\'scheduled\')'
    );
    if (!$preLimit) {
        respond(['error' => 'Could not verify booking limit'], 500);
    }
    $preLimit->bind_param('i', $portal_user_id);
    $preLimit->execute();
    $preRow = $preLimit->get_result()->fetch_assoc();
    $preLimit->close();
    if ((int) ($preRow['total'] ?? 0) >= 2) {
        respond($activeLimitMsg, 422);
    }

    // ── 4) Cooldown: 5 minutes between bookings (TC-039) ─────────────────
    $cdStmt = $db->prepare(
        'SELECT created_at FROM patient_appointments
         WHERE portal_user_id = ?
         ORDER BY created_at DESC LIMIT 1'
    );
    $cdStmt->bind_param('i', $portal_user_id);
    $cdStmt->execute();
    $cdRow = $cdStmt->get_result()->fetch_assoc();
    $cdStmt->close();
    if ($cdRow) {
        $lastTime = strtotime($cdRow['created_at']);
        if ($lastTime !== false && (time() - $lastTime) < 300) {
            $retryCd = max(1, 300 - (time() - $lastTime));
            if (!headers_sent()) {
                header('Retry-After: ' . $retryCd);
            }
            respond(['error' => 'Please wait before booking again.', 'retry_after' => $retryCd], 429);
        }
    }

    // ── 5) Rate limit: max 5 validated POSTs / 60s window per portal user (TC-040) ──
    // Keep this AFTER payload validation, active-booking checks, and cooldown checks.
    $now = time();
    $window = 60;
    $maxReqs = 5;
    if (!isset($_SESSION['portal_booking_rate']) || !is_array($_SESSION['portal_booking_rate'])) {
        $_SESSION['portal_booking_rate'] = [];
    }
    $rateKey = (string) $portal_user_id;
    $state = $_SESSION['portal_booking_rate'][$rateKey] ?? null;
    if (!is_array($state)) {
        $state = [
            'window_start' => $now,
            'count' => 0,
        ];
    }
    $windowStart = isset($state['window_start']) ? (int) $state['window_start'] : $now;
    $count = isset($state['count']) ? (int) $state['count'] : 0;
    if ($windowStart <= 0 || $windowStart > $now) {
        $windowStart = $now;
        $count = 0;
    }
    if (($now - $windowStart) >= $window) {
        $windowStart = $now;
        $count = 0;
    }
    if ($count >= $maxReqs) {
        $retryRl = max(1, $window - ($now - $windowStart));
        if (!headers_sent()) {
            header('Retry-After: ' . $retryRl);
        }
        respond(['error' => 'Too many requests. Please try again later.', 'retry_after' => $retryRl], 429);
    }
    $count++;
    $_SESSION['portal_booking_rate'][$rateKey] = [
        'window_start' => $windowStart,
        'count' => $count,
    ];

    // ── Begin transaction to prevent race conditions ───────────────────
    $db->begin_transaction();

    try {
        // ── Per-user booking limit (max 2) — authoritative under lock (TC-038) ──
        $limitChk = $db->prepare(
            'SELECT COUNT(*) AS total FROM patient_appointments
             WHERE portal_user_id = ? AND status IN (\'pending\',\'scheduled\')
             FOR UPDATE'
        );
        $limitChk->bind_param('i', $portal_user_id);
        $limitChk->execute();
        $limitRow = $limitChk->get_result()->fetch_assoc();
        $limitChk->close();
        if ((int) ($limitRow['total'] ?? 0) >= 2) {
            $db->rollback();
            respond($activeLimitMsg, 422);
        }

        if (!dentistSlotIsFreeForUpdate($db, $dentist_id, $date, $timeSql, 0)) {
            $db->rollback();
            respond(['error' => EDROSO_SLOT_CONFLICT_MESSAGE], 409);
        }

        // ── Insert booking ──────────────────────────────────────────────
        $stmt = $db->prepare(
            'INSERT INTO patient_appointments
             (portal_user_id, dentist_id, preferred_date, preferred_time, reason, service_id, reason_label, meeting_type, patient_details, health_history, payment_method, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statusIns = 'pending';
        $stmt->bind_param(
            'iississsssss',
            $portal_user_id,
            $dentist_id,
            $date,
            $timeSql,
            $reason,
            $service_id,
            $reason_label,
            $meeting_type,
            $pdJson,
            $hhJson,
            $payment_method,
            $statusIns
        );
        if (!$stmt->execute()) {
            $dbErr = $stmt->error !== '' ? $stmt->error : $db->error;
            $stmt->close();
            $db->rollback();
            respond(['error' => 'DB error: ' . $dbErr], 500);
        }
        $newId = (int) $db->insert_id;
        $stmt->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        respond(['error' => 'Booking failed: ' . $e->getMessage()], 500);
    }

    try {
        mirror_portal_booking_to_admin([
            'portal_user_id' => $portal_user_id,
            'patient_appointment_id' => $newId,
            'date' => $date,
            'time' => $timeSql,
            'service_id' => $service_id,
            'dentist_id' => $dentist_id,
            'reason_label' => $reason_label,
            'patient_details' => $patient_details,
            'health_history' => $health_history,
            'payment_method' => $payment_method,
        ], $db);
    } catch (Throwable $e) {
        error_log('patient_appointments mirror exception: ' . $e->getMessage());
    }

    respond(['success' => true, 'appointment_id' => $newId]);
}

respond(['error' => 'Method not allowed'], 405);
