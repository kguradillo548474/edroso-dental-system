<?php
/**
 * Dentist weekly schedule → time-slot grid, minus existing bookings.
 * Used by api/availability.php and api/patient_appointments.php (GET slots).
 */

if (!function_exists('edroso_weekday_name')) {
    function edroso_weekday_name(string $ymd): string {
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!$dt) {
            return 'Monday';
        }
        return $dt->format('l');
    }
}

if (!function_exists('ensure_dentist_schedules_table')) {
    function ensure_dentist_schedules_table(mysqli $db): void {
        static $done = false;
        if ($done) {
            return;
        }
        $db->query(
            "CREATE TABLE IF NOT EXISTS dentist_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dentist_id INT NOT NULL,
                day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                slot_duration_minutes INT NOT NULL DEFAULT 30,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE,
                UNIQUE KEY unique_dentist_day (dentist_id, day_of_week),
                INDEX idx_dentist_sched (dentist_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }
}

if (!function_exists('edroso_normalize_hhmm')) {
    function edroso_normalize_hhmm(string $t): string {
        $t = trim($t);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            return str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }
        return $t;
    }
}

if (!function_exists('edroso_time_to_minutes')) {
    function edroso_time_to_minutes(string $hhmmOrHms): int {
        $n = edroso_normalize_hhmm($hhmmOrHms);
        if (!preg_match('/^(\d{2}):(\d{2})$/', $n, $m)) {
            return 0;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }
}

if (!function_exists('edroso_minutes_to_hhmm')) {
    function edroso_minutes_to_hhmm(int $mins): string {
        $mins = max(0, min(24 * 60 - 1, $mins));
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('edroso_seed_default_dentist_schedule')) {
    /**
     * When a dentist has no schedule rows, insert Mon–Sat 09:00–17:00 @ 30 min (inactive Sunday).
     */
    function edroso_seed_default_dentist_schedule(mysqli $db, int $dentistId): void {
        if ($dentistId <= 0) {
            return;
        }
        $chk = $db->prepare('SELECT COUNT(*) AS c FROM dentist_schedules WHERE dentist_id = ?');
        if (!$chk) {
            return;
        }
        $chk->bind_param('i', $dentistId);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ((int) ($row['c'] ?? 0) > 0) {
            return;
        }
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $ins = $db->prepare(
            'INSERT INTO dentist_schedules (dentist_id, day_of_week, start_time, end_time, slot_duration_minutes, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        if (!$ins) {
            return;
        }
        $start = '09:00:00';
        $end   = '17:00:00';
        $slot  = 30;
        foreach ($days as $day) {
            $ins->bind_param('isssi', $dentistId, $day, $start, $end, $slot);
            $ins->execute();
        }
        $ins->close();
    }
}

if (!function_exists('edroso_clinic_fallback_slot_config')) {
    /**
     * @return array{start:string,end:string,interval:int}
     */
    function edroso_clinic_fallback_slot_config(mysqli $db): array {
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
            /* ignore */
        }
        $iv = (int) $resolved['time_per_patient'];
        if ($iv < 5 || $iv > 240) {
            $iv = 30;
        }
        return [
            'start'     => edroso_normalize_hhmm($resolved['clinic_start_time']),
            'end'       => edroso_normalize_hhmm($resolved['clinic_end_time']),
            'interval'  => $iv,
        ];
    }
}

if (!function_exists('edroso_build_slot_grid')) {
    /**
     * @return list<string> HH:MM slot starts
     */
    function edroso_build_slot_grid(string $startHhmm, string $endHhmm, int $intervalMinutes): array {
        $startM = edroso_time_to_minutes($startHhmm);
        $endM   = edroso_time_to_minutes($endHhmm);
        $iv     = max(5, $intervalMinutes);
        if ($endM <= $startM) {
            return [];
        }
        $slots = [];
        for ($t = $startM; $t + $iv <= $endM; $t += $iv) {
            $slots[] = edroso_minutes_to_hhmm($t);
        }
        return $slots;
    }
}

if (!function_exists('edroso_booked_times_for_dentist_date')) {
    /**
     * @return array<string,bool> keys HH:MM
     */
    function edroso_booked_times_for_dentist_date(mysqli $db, int $dentistId, string $dateYmd): array {
        $booked = [];
        $stmt = $db->prepare(
            "SELECT appointment_time FROM appointments
             WHERE dentist_id = ? AND appointment_date = ?
               AND status NOT IN ('Cancelled')"
        );
        if ($stmt) {
            $stmt->bind_param('is', $dentistId, $dateYmd);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $booked[edroso_normalize_hhmm(substr((string) $row['appointment_time'], 0, 8))] = true;
            }
            $stmt->close();
        }
        $stmt2 = $db->prepare(
            "SELECT preferred_time FROM patient_appointments
             WHERE dentist_id = ? AND preferred_date = ?
               AND status != 'cancelled'"
        );
        if ($stmt2) {
            $stmt2->bind_param('is', $dentistId, $dateYmd);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $booked[edroso_normalize_hhmm(substr((string) $row['preferred_time'], 0, 8))] = true;
            }
            $stmt2->close();
        }
        return $booked;
    }
}

if (!function_exists('edroso_dentist_schedule_row_count')) {
    function edroso_dentist_schedule_row_count(mysqli $db, int $dentistId): int {
        ensure_dentist_schedules_table($db);
        if ($dentistId <= 0) {
            return 0;
        }
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM dentist_schedules WHERE dentist_id = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $dentistId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['c'] ?? 0);
    }
}

if (!function_exists('edroso_next_available_dates_with_slots')) {
    /**
     * Next calendar dates (starting today) where the dentist has at least one bookable slot.
     *
     * @return list<string> YYYY-MM-DD
     */
    function edroso_next_available_dates_with_slots(mysqli $db, int $dentistId, int $scanDays, int $maxResults): array {
        if ($dentistId <= 0 || $maxResults <= 0 || $scanDays <= 0) {
            return [];
        }
        $out = [];
        $d = new DateTimeImmutable('today');
        for ($i = 0; $i < $scanDays && count($out) < $maxResults; $i++) {
            $ymd = $d->modify('+' . $i . ' days')->format('Y-m-d');
            $slots = edroso_available_slots_response($db, $dentistId, $ymd);
            foreach ($slots as $s) {
                if (!empty($s['available'])) {
                    $out[] = $ymd;
                    break;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('edroso_available_slots_response')) {
    /**
     * @return list<array{time:string,available:bool,count:int,portal_count:int,admin_count:int}>
     */
    function edroso_available_slots_response(mysqli $db, int $dentistId, string $dateYmd): array {
        ensure_dentist_schedules_table($db);
        $schedCount = edroso_dentist_schedule_row_count($db, $dentistId);
        if ($schedCount === 0) {
            edroso_seed_default_dentist_schedule($db, $dentistId);
            $schedCount = edroso_dentist_schedule_row_count($db, $dentistId);
        }

        $dayName = edroso_weekday_name($dateYmd);
        $stmt = $db->prepare(
            'SELECT start_time, end_time, slot_duration_minutes
             FROM dentist_schedules
             WHERE dentist_id = ? AND day_of_week = ? AND is_active = 1
             LIMIT 1'
        );
        $allSlots = [];
        if ($stmt) {
            $stmt->bind_param('is', $dentistId, $dayName);
            $stmt->execute();
            $sch = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($sch) {
                $st = edroso_normalize_hhmm(substr((string) $sch['start_time'], 0, 8));
                $en = edroso_normalize_hhmm(substr((string) $sch['end_time'], 0, 8));
                $iv = (int) ($sch['slot_duration_minutes'] ?? 30);
                $allSlots = edroso_build_slot_grid($st, $en, $iv);
            }
        }
        // If this dentist has any weekly schedule rows but no *active* window for this weekday, do not
        // fall back to clinic-wide hours (prevents showing bookable times on days they are off).
        if (!$allSlots && $schedCount > 0) {
            $allSlots = [];
        } elseif (!$allSlots) {
            $fb = edroso_clinic_fallback_slot_config($db);
            $allSlots = edroso_build_slot_grid($fb['start'], $fb['end'], $fb['interval']);
        }

        $booked = edroso_booked_times_for_dentist_date($db, $dentistId, $dateYmd);

        $nowCutoff = null;
        if ($dateYmd === date('Y-m-d')) {
            $nowCutoff = edroso_time_to_minutes(date('H:i'));
        }

        $out = [];
        foreach ($allSlots as $time) {
            if ($nowCutoff !== null && edroso_time_to_minutes($time) <= $nowCutoff) {
                $out[] = [
                    'time'          => $time,
                    'available'     => false,
                    'count'         => 1,
                    'portal_count'  => 0,
                    'admin_count'   => 1,
                ];
                continue;
            }
            $occupied = isset($booked[$time]);
            $out[] = [
                'time'          => $time,
                'available'     => !$occupied,
                'count'         => $occupied ? 1 : 0,
                'portal_count'  => $occupied ? 1 : 0,
                'admin_count'   => 0,
            ];
        }
        return $out;
    }
}
