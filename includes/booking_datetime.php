<?php
/**
 * Portal booking date/time rules (Asia/Manila).
 */

if (!function_exists('booking_timezone')) {
    function booking_timezone(): DateTimeZone {
        static $tz = null;
        if ($tz === null) {
            $tz = new DateTimeZone('Asia/Manila');
        }
        return $tz;
    }
}

if (!function_exists('booking_now')) {
    function booking_now(): DateTimeImmutable {
        return new DateTimeImmutable('now', booking_timezone());
    }
}

if (!function_exists('booking_today_ymd')) {
    function booking_today_ymd(): string {
        return booking_now()->format('Y-m-d');
    }
}

if (!function_exists('booking_now_minutes')) {
    function booking_now_minutes(): int {
        $n = booking_now();
        return (int) $n->format('G') * 60 + (int) $n->format('i');
    }
}

if (!function_exists('booking_normalize_hhmm')) {
    function booking_normalize_hhmm(string $t): string {
        $t = trim($t);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            return str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }
        return $t;
    }
}

if (!function_exists('booking_time_to_minutes')) {
    function booking_time_to_minutes(string $hhmmOrHms): int {
        $n = booking_normalize_hhmm($hhmmOrHms);
        if (!preg_match('/^(\d{2}):(\d{2})$/', $n, $m)) {
            return 0;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }
}

if (!function_exists('is_past_date')) {
    /** True when $dateYmd is strictly before today in Asia/Manila. */
    function is_past_date(string $dateYmd): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
            return true;
        }
        return $dateYmd < booking_today_ymd();
    }
}

if (!function_exists('is_past_time')) {
    /**
     * True when the slot on $dateYmd is not bookable (past date, or same-day time at/before now).
     */
    function is_past_time(string $dateYmd, string $timeHhmm): bool {
        if (is_past_date($dateYmd)) {
            return true;
        }
        if ($dateYmd > booking_today_ymd()) {
            return false;
        }
        return booking_time_to_minutes($timeHhmm) <= booking_now_minutes();
    }
}
