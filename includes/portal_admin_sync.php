<?php
/**
 * Detect portal rows missing an admin appointments mirror, and backfill them.
 */
require_once __DIR__ . '/portal_booking_mirror.php';

/**
 * True if an appointments row already represents this portal booking (same patient linkage + slot).
 */
function portal_appointment_has_admin_mirror(mysqli $db, array $pa): bool {
    $portalUserId = (int) ($pa['portal_user_id'] ?? 0);
    $dentistId = (int) ($pa['dentist_id'] ?? 0);
    if ($portalUserId <= 0 || $dentistId <= 0) {
        return false;
    }
    $date = (string) ($pa['preferred_date'] ?? '');
    $time = normalizeApptTime((string) ($pa['preferred_time'] ?? ''));

    $adminPid = find_admin_patient_id($db, $portalUserId);
    if ($adminPid !== null) {
        $stmt = $db->prepare(
            "SELECT id FROM appointments
             WHERE patient_id = ? AND dentist_id = ? AND appointment_date = ? AND appointment_time = ?
               AND status <> 'Cancelled'
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iiss', $adminPid, $dentistId, $date, $time);
            $stmt->execute();
            $found = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($found) {
                return true;
            }
        }
    }

    $stmt = $db->prepare(
        "SELECT a.id FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN portal_users u ON u.id = ?
         WHERE a.dentist_id = ? AND a.appointment_date = ? AND a.appointment_time = ?
           AND a.status <> 'Cancelled'
           AND (
             (TRIM(LOWER(COALESCE(u.email, ''))) <> '' AND LOWER(TRIM(p.email)) = LOWER(TRIM(u.email)))
             OR (
               REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone, ''), ' ', ''), '-', ''), '(', ''), ')', '') <> ''
               AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.phone, ''), ' ', ''), '-', ''), '(', ''), ')', '')
                 = REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone, ''), ' ', ''), '-', ''), '(', ''), ')', '')
             )
           )
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiss', $portalUserId, $dentistId, $date, $time);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

/**
 * @param array<string,mixed> $pa patient_appointments row (mysqli fetch_assoc)
 * @return array<string,mixed>
 */
function portal_mirror_body_from_patient_appointment_row(array $pa): array {
    $pd = $pa['patient_details'] ?? null;
    if (is_string($pd)) {
        $decoded = json_decode($pd, true);
        $pd = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($pd)) {
        $pd = [];
    }
    $hh = $pa['health_history'] ?? null;
    if (is_string($hh)) {
        $decoded = json_decode($hh, true);
        $hh = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($hh)) {
        $hh = [];
    }
    $reason_label = trim((string) ($pa['reason_label'] ?? ''));
    if ($reason_label === '') {
        $reason_label = trim((string) ($pa['reason'] ?? ''));
    }
    if ($reason_label === '') {
        $reason_label = 'Portal booking';
    }

    return [
        'portal_user_id' => (int) ($pa['portal_user_id'] ?? 0),
        'patient_appointment_id' => (int) ($pa['id'] ?? 0),
        'date' => (string) ($pa['preferred_date'] ?? ''),
        'time' => normalizeApptTime((string) ($pa['preferred_time'] ?? '')),
        'dentist_id' => (int) ($pa['dentist_id'] ?? 0),
        'reason_label' => $reason_label,
        'patient_details' => $pd,
        'health_history' => $hh,
        'payment_method' => (string) ($pa['payment_method'] ?? ''),
        'payment_reference' => trim((string) ($pa['payment_reference'] ?? '')),
        'payment_proof_path' => trim((string) ($pa['payment_proof_path'] ?? '')),
        'portal_status' => (string) ($pa['status'] ?? 'pending'),
    ];
}

/**
 * Insert missing admin appointments for portal rows (pending, scheduled, completed). Skips cancelled.
 *
 * @return array{mirrored:int,already_synced:int,skipped_no_dentist:int,failed:int}
 */
function backfill_portal_appointments_to_admin(mysqli $db): array {
    $out = [
        'mirrored' => 0,
        'already_synced' => 0,
        'skipped_no_dentist' => 0,
        'failed' => 0,
    ];

    $sql = "SELECT * FROM patient_appointments
            WHERE status IN ('pending','scheduled','completed')
            ORDER BY id ASC";
    $res = $db->query($sql);
    if (!$res) {
        return $out;
    }

    while ($pa = $res->fetch_assoc()) {
        if ((int) ($pa['dentist_id'] ?? 0) <= 0) {
            $out['skipped_no_dentist']++;
            continue;
        }
        if (portal_appointment_has_admin_mirror($db, $pa)) {
            $out['already_synced']++;
            continue;
        }
        try {
            mirror_portal_booking_to_admin(portal_mirror_body_from_patient_appointment_row($pa), $db);
        } catch (Throwable $e) {
            error_log('portal_admin_sync mirror exception: ' . $e->getMessage());
            $out['failed']++;
            continue;
        }
        if (portal_appointment_has_admin_mirror($db, $pa)) {
            $out['mirrored']++;
        } else {
            $out['failed']++;
        }
    }
    $res->free();

    return $out;
}
