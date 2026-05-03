<?php
/**
 * Shared appointment time normalization and dentist conflict detection
 * (used by api/appointments.php and api/patient_appointments.php mirror).
 */

if (!defined('EDROSO_SLOT_CONFLICT_MESSAGE')) {
    define('EDROSO_SLOT_CONFLICT_MESSAGE', 'This time slot is no longer available. Please choose another.');
}

if (!function_exists('normalizeApptTime')) {
    function normalizeApptTime($t) {
        $t = trim($t ?? '');
        if (strlen($t) === 5 && $t[2] === ':') {
            return $t . ':00';
        }
        return $t;
    }
}

if (!function_exists('dentistHasConflict')) {
    /**
     * True if another active booking exists for same dentist, date, time
     * (admin appointments or portal patient_appointments).
     */
    function dentistHasConflict($db, $dentistId, $date, $time, $excludeId = 0) {
        $t = normalizeApptTime($time);
        $sql = "SELECT id FROM appointments
                WHERE dentist_id = ? AND appointment_date = ? AND appointment_time = ?
                AND status NOT IN ('Cancelled')";
        if ($excludeId > 0) {
            $sql .= ' AND id != ?';
            $stmt = $db->prepare($sql);
            $stmt->bind_param('issi', $dentistId, $date, $t, $excludeId);
        } else {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('iss', $dentistId, $date, $t);
        }
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return true;
        }
        $stmt = $db->prepare(
            "SELECT id FROM patient_appointments
             WHERE dentist_id = ? AND preferred_date = ? AND preferred_time = ?
               AND status != 'cancelled' LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iss', $dentistId, $date, $t);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }
}

/**
 * Authoritative conflict check inside an open transaction (SELECT … FOR UPDATE).
 * Call after BEGIN; rolls back caller should happen on false.
 *
 * @param int $excludeAppointmentId        Exclude this `appointments.id` from the admin-table conflict count.
 * @param int $excludePatientAppointmentId Exclude this `patient_appointments.id` (e.g. the row just inserted before mirroring to admin).
 * @return bool true if slot is free
 */
function dentistSlotIsFreeForUpdate(mysqli $db, int $dentistId, string $dateYmd, string $timeRaw, int $excludeAppointmentId = 0, int $excludePatientAppointmentId = 0): bool {
    $t = normalizeApptTime($timeRaw);
    if ($excludeAppointmentId > 0) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM appointments
             WHERE dentist_id = ? AND appointment_date = ? AND appointment_time = ?
               AND status NOT IN ('Cancelled') AND id != ?
             FOR UPDATE"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('issi', $dentistId, $dateYmd, $t, $excludeAppointmentId);
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM appointments
             WHERE dentist_id = ? AND appointment_date = ? AND appointment_time = ?
               AND status NOT IN ('Cancelled')
             FOR UPDATE"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iss', $dentistId, $dateYmd, $t);
    }
    $stmt->execute();
    $c1 = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($c1 > 0) {
        return false;
    }

    if ($excludePatientAppointmentId > 0) {
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) AS c FROM patient_appointments
             WHERE dentist_id = ? AND preferred_date = ? AND preferred_time = ?
               AND status != 'cancelled' AND id != ?
             FOR UPDATE"
        );
        if (!$stmt2) {
            return false;
        }
        $stmt2->bind_param('issi', $dentistId, $dateYmd, $t, $excludePatientAppointmentId);
    } else {
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) AS c FROM patient_appointments
             WHERE dentist_id = ? AND preferred_date = ? AND preferred_time = ?
               AND status != 'cancelled'
             FOR UPDATE"
        );
        if (!$stmt2) {
            return false;
        }
        $stmt2->bind_param('iss', $dentistId, $dateYmd, $t);
    }
    $stmt2->execute();
    $c2 = (int) ($stmt2->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt2->close();
    return $c2 === 0;
}
