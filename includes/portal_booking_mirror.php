<?php
/**
 * Mirror portal patient_appointments rows into admin appointments (shared by API + sync tools).
 */
require_once __DIR__ . '/appointment_conflict.php';

if (!function_exists('portal_status_to_admin')) {
    /**
     * Map portal-side status (lowercase) to admin appointments status (Title Case).
     */
    function portal_status_to_admin(string $portalStatus): string {
        static $map = [
            'pending'   => 'Scheduled',
            'scheduled' => 'Scheduled',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];
        return $map[strtolower(trim($portalStatus))] ?? 'Scheduled';
    }

    function appointment_procedure_type_from_label(string $label): string {
        $value = strtolower(trim($label));
        if (preg_match('/clean|prophylaxis|polish|scaling/', $value)) {
            return 'cleaning';
        }
        if (preg_match('/root\s*canal|endodontic/', $value)) {
            return 'rootcanal';
        }
        if (preg_match('/extract|extraction|wisdom/', $value)) {
            return 'extraction';
        }
        if (preg_match('/fill|filling|cavity|pasta/', $value)) {
            return 'filling';
        }
        if (preg_match('/crown|cap/', $value)) {
            return 'crown';
        }
        if (preg_match('/whiten|bleach|whitening/', $value)) {
            return 'whitening';
        }
        if (preg_match('/consult|check[-\s]*up|evaluation/', $value)) {
            return 'other';
        }
        return 'other';
    }

    function find_admin_patient_id(mysqli $db, int $portalUserId): ?int {
        $stmt = $db->prepare(
            'SELECT p.id
             FROM patients p
             JOIN portal_users u ON u.email = p.email
             WHERE u.id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $portalUserId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->bind_result($patientId);
        if ($stmt->fetch()) {
            $stmt->close();
            return $patientId;
        }
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT p.id
             FROM patients p
             JOIN portal_users u ON REPLACE(REPLACE(REPLACE(p.phone, ' ', ''), '+', ''), '-', '') = REPLACE(REPLACE(REPLACE(u.phone, ' ', ''), '+', ''), '-', '')
             WHERE u.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $portalUserId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->bind_result($patientId);
        if ($stmt->fetch()) {
            $stmt->close();
            return $patientId;
        }
        $stmt->close();
        return null;
    }

    function find_default_dentist_id(mysqli $db): ?int {
        $stmt = $db->prepare('SELECT id FROM dentists WHERE status = ? ORDER BY id ASC LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $active = 'active';
        $stmt->bind_param('s', $active);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->bind_result($dentistId);
        if ($stmt->fetch()) {
            $stmt->close();
            return $dentistId;
        }
        $stmt->close();
        $stmt = $db->prepare('SELECT id FROM dentists ORDER BY id ASC LIMIT 1');
        if (!$stmt) {
            return null;
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->bind_result($dentistId);
        if ($stmt->fetch()) {
            $stmt->close();
            return $dentistId;
        }
        $stmt->close();
        return null;
    }

    function mirror_portal_booking_to_admin(array $body, mysqli $db): void {
        $patientId = find_admin_patient_id($db, $body['portal_user_id']);
        if ($patientId === null) {
            $puStmt = $db->prepare(
                'SELECT full_name, email, phone, dob FROM portal_users WHERE id = ? LIMIT 1'
            );
            if (!$puStmt) {
                error_log('patient_appointments mirror: portal_users lookup prepare failed: ' . $db->error);
                return;
            }
            $puStmt->bind_param('i', $body['portal_user_id']);
            $puStmt->execute();
            $puStmt->bind_result($puName, $puEmail, $puPhone, $puDob);
            if (!$puStmt->fetch()) {
                $puStmt->close();
                error_log('patient_appointments mirror: portal user not found id=' . $body['portal_user_id']);
                return;
            }
            $puStmt->close();

            $puName  = trim((string) $puName);
            $puEmail = trim((string) $puEmail);
            $puPhone = preg_replace('/\s+/', '', (string) $puPhone);
            $puDob   = (string) $puDob;

            if (function_exists('portal_full_name_to_patient_names')) {
                [$firstName, $lastName] = portal_full_name_to_patient_names($puName);
            } else {
                $pos = strpos($puName, ' ');
                $firstName = $pos !== false ? trim(substr($puName, 0, $pos)) : $puName;
                $lastName  = $pos !== false ? trim(substr($puName, $pos + 1)) : '-';
                if ($firstName === '') {
                    $firstName = '-';
                }
                if ($lastName  === '') {
                    $lastName  = '-';
                }
            }

            $patNum = 'PAT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $gender = 'Other';
            $addr   = '';
            $notes  = '';

            $pd = $body['patient_details'] ?? null;
            if (is_array($pd)) {
                $addrParts = array_filter([
                    trim($pd['street'] ?? ''),
                    trim($pd['city'] ?? ''),
                    trim($pd['state'] ?? ''),
                    trim($pd['country'] ?? ''),
                    trim($pd['postal_code'] ?? ''),
                ], function ($v) { return $v !== ''; });
                if ($addrParts) {
                    $addr = implode(', ', $addrParts);
                }
            }

            $insP = $db->prepare(
                'INSERT INTO patients (patient_number, first_name, last_name, email, phone, date_of_birth, gender, address, medical_notes)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            if ($insP) {
                $insP->bind_param(
                    'sssssssss',
                    $patNum, $firstName, $lastName, $puEmail, $puPhone, $puDob, $gender, $addr, $notes
                );
                if ($insP->execute()) {
                    $patientId = (int) $db->insert_id;
                } else {
                    error_log('patient_appointments mirror: patient insert failed: ' . $insP->error);
                }
                $insP->close();
            }

            if ($patientId === null) {
                error_log('patient_appointments mirror: could not create patient for portal user ' . $body['portal_user_id']);
                return;
            }
        }
        $dentistId = $body['dentist_id'] ?? null;
        if ($dentistId === null || $dentistId <= 0) {
            $dentistId = find_default_dentist_id($db);
        }
        if ($dentistId === null) {
            return;
        }

        $apptDate = (string) ($body['date'] ?? '');
        $apptTime = normalizeApptTime((string) ($body['time'] ?? ''));

        $db->begin_transaction();
        try {
            $excludePortalRow = (int) ($body['patient_appointment_id'] ?? 0);
            if (!dentistSlotIsFreeForUpdate($db, (int) $dentistId, $apptDate, $apptTime, 0, $excludePortalRow)) {
                $db->rollback();
                error_log(
                    'patient_appointments mirror: dentist conflict (skipping appointments insert) '
                    . 'portal_user_id=' . (int) ($body['portal_user_id'] ?? 0)
                    . ' dentist_id=' . (int) $dentistId
                    . ' appointment_date=' . $apptDate
                    . ' appointment_time=' . $apptTime
                );
                return;
            }
        } catch (Throwable $e) {
            $db->rollback();
            error_log('patient_appointments mirror: lock failed: ' . $e->getMessage());
            return;
        }

        $procedureName = trim($body['reason_label'] ?? 'Portal booking');
        $procedureType = appointment_procedure_type_from_label($procedureName);
        $status = portal_status_to_admin($body['portal_status'] ?? 'pending');
        $room = 'TBD';
        $duration = 30;
        $stmt = $db->prepare(
            'INSERT INTO appointments (patient_id, dentist_id, procedure_name, procedure_type, appointment_date, appointment_time, duration_minutes, room, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            $db->rollback();
            error_log('patient_appointments mirror: prepare failed: ' . $db->error);
            return;
        }
        $notesParts = ['Portal booking'];
        $pd = $body['patient_details'] ?? null;
        $hh = $body['health_history'] ?? null;
        if (is_array($pd)) {
            $name = trim($pd['full_name'] ?? '');
            if ($name === '') {
                $first = trim($pd['first_name'] ?? '');
                $last = trim($pd['last_name'] ?? '');
                $name = trim($first . ' ' . $last);
            }
            if ($name !== '') {
                $notesParts[] = 'Patient: ' . $name;
            }
            $addrParts = array_filter([
                trim($pd['street'] ?? ''),
                trim($pd['city'] ?? ''),
                trim($pd['state'] ?? ''),
                trim($pd['country'] ?? ''),
                trim($pd['postal_code'] ?? ''),
            ], function ($v) { return $v !== ''; });
            if ($addrParts) {
                $notesParts[] = 'Address: ' . implode(', ', $addrParts);
            }
        }
        if (is_array($hh)) {
            $concerns = $hh['concerns'] ?? [];
            if (is_array($concerns) && $concerns) {
                $notesParts[] = 'Concerns: ' . implode(', ', $concerns);
            }
            $med = trim($hh['medical_conditions'] ?? '');
            if ($med !== '') {
                $notesParts[] = 'Medical: ' . $med;
            }
            if (($hh['allergies'] ?? '') === 'yes') {
                $spec = trim($hh['allergies_specify'] ?? 'Yes');
                $notesParts[] = 'Allergies: ' . ($spec !== '' ? $spec : 'Yes');
            }
        }
        $pm = trim($body['payment_method'] ?? '');
        if ($pm !== '') {
            $notesParts[] = 'Payment: ' . $pm;
        }
        $pref = trim((string) ($body['payment_reference'] ?? ''));
        if ($pref !== '') {
            $notesParts[] = 'Payment ref: ' . $pref;
        }
        $proof = trim((string) ($body['payment_proof_path'] ?? ''));
        if ($proof !== '') {
            $notesParts[] = 'Payment proof: ' . $proof;
        }
        $notes = implode("\n", $notesParts);
        $stmt->bind_param(
            'iissssisss',
            $patientId,
            $dentistId,
            $procedureName,
            $procedureType,
            $apptDate,
            $apptTime,
            $duration,
            $room,
            $status,
            $notes
        );
        if (!$stmt->execute()) {
            $db->rollback();
            error_log('patient_appointments mirror failed: ' . $stmt->error);
            $stmt->close();
            return;
        }
        $stmt->close();
        $db->commit();
    }
}
