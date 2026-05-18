<?php
/**
 * CSV export helpers for staff reports (appointments, payments, dashboard summary).
 */

if (!function_exists('export_csv_begin')) {
    function export_csv_begin(string $filenameBase): void {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filenameBase);
        if ($safe === '') {
            $safe = 'export';
        }
        $filename = $safe . '_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF";
    }
}

if (!function_exists('export_csv_row')) {
    /** @param list<scalar|null> $cells */
    function export_csv_row(array $cells): void {
        $out = fopen('php://output', 'w');
        if (!$out) {
            return;
        }
        fputcsv($out, array_map(static function ($v) {
            if ($v === null) {
                return '';
            }
            return (string) $v;
        }, $cells));
        fclose($out);
    }
}

if (!function_exists('export_format_time_hms')) {
    function export_format_time_hms(?string $t): string {
        if ($t === null || trim($t) === '') {
            return '';
        }
        $t = trim($t);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            $h = (int) $m[1];
            $min = $m[2];
            $ampm = $h >= 12 ? 'PM' : 'AM';
            $h12 = $h % 12;
            if ($h12 === 0) {
                $h12 = 12;
            }
            return $h12 . ':' . $min . ' ' . $ampm;
        }
        return $t;
    }
}

if (!function_exists('export_appointments_query')) {
    /**
     * Build appointment list SQL (same rules as api/appointments.php GET list).
     *
     * @return array{sql:string,params:array,int>,types:string}
     */
    function export_appointments_query(array $get): array {
        $sql = "SELECT a.id,
                    a.appointment_date,
                    a.appointment_time,
                    a.procedure_name,
                    a.status,
                    a.created_at,
                    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                    p.patient_number,
                    d.name AS dentist_name
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                JOIN dentists d ON a.dentist_id = d.id";

        $conditions = [];
        $params = [];
        $types = '';

        $filter = isset($get['filter']) ? strtolower(trim((string) $get['filter'])) : '';
        $status = isset($get['status']) ? trim((string) $get['status']) : '';
        $date = isset($get['date']) ? trim((string) $get['date']) : '';
        $range = isset($get['range']) ? trim((string) $get['range']) : '';
        $dentistId = isset($get['dentist_id']) ? (int) $get['dentist_id'] : 0;
        $search = isset($get['search']) ? trim((string) $get['search']) : '';

        if ($filter === 'today') {
            $conditions[] = 'a.appointment_date = CURDATE()';
        } elseif ($filter === 'upcoming') {
            $conditions[] = "a.appointment_date > CURDATE() AND a.status = 'Scheduled'";
        } elseif ($filter === 'completed') {
            $conditions[] = "a.status = 'Completed'";
        } elseif ($filter === 'cancelled') {
            $conditions[] = "a.status = 'Cancelled'";
        }

        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $conditions[] = 'a.appointment_date = ?';
            $params[] = $date;
            $types .= 's';
        }
        if ($range === 'today') {
            $conditions[] = 'a.appointment_date = CURDATE()';
        }
        if ($range === 'upcoming') {
            $conditions[] = "a.appointment_date >= CURDATE() AND a.status NOT IN ('Cancelled','Completed')";
        }
        if ($dentistId > 0) {
            $conditions[] = 'a.dentist_id = ?';
            $params[] = $dentistId;
            $types .= 'i';
        }
        if ($status !== '') {
            $norm = strtolower($status);
            if ($norm === 'scheduled') {
                $conditions[] = "a.status IN ('Scheduled','Confirmed')";
            } elseif ($norm === 'completed') {
                $conditions[] = "a.status = 'Completed'";
            } elseif ($norm === 'cancelled') {
                $conditions[] = "a.status = 'Cancelled'";
            } else {
                $conditions[] = 'a.status = ?';
                $params[] = $status;
                $types .= 's';
            }
        }
        if ($search !== '') {
            $conditions[] = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR a.procedure_name LIKE ? OR d.name LIKE ?)";
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s]);
            $types .= 'sss';
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY a.appointment_date ASC, a.appointment_time ASC';

        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }
}

if (!function_exists('export_stream_appointments_csv')) {
    function export_stream_appointments_csv(mysqli $db, array $get, string $filenameBase): void {
        $q = export_appointments_query($get);
        export_csv_begin($filenameBase);
        export_csv_row([
            'Appointment ID',
            'Patient Name',
            'Patient Number',
            'Dentist',
            'Date',
            'Time',
            'Procedure',
            'Status',
            'Created At',
        ]);

        if ($q['params']) {
            $stmt = $db->prepare($q['sql']);
            if (!$stmt) {
                return;
            }
            $stmt->bind_param($q['types'], ...$q['params']);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $db->query($q['sql']);
        }
        if (!$res) {
            return;
        }
        while ($row = $res->fetch_assoc()) {
            export_csv_row([
                $row['id'] ?? '',
                $row['patient_name'] ?? '',
                $row['patient_number'] ?? '',
                $row['dentist_name'] ?? '',
                $row['appointment_date'] ?? '',
                export_format_time_hms($row['appointment_time'] ?? ''),
                $row['procedure_name'] ?? '',
                $row['status'] ?? '',
                $row['created_at'] ?? '',
            ]);
        }
        if (isset($stmt)) {
            $stmt->close();
        }
    }
}

if (!function_exists('export_payments_query')) {
    /**
     * @return array{sql:string,params:array,int>,types:string}
     */
    function export_payments_query(array $get): array {
        $sql = "SELECT py.id,
                    py.payment_date,
                    py.created_at,
                    py.description,
                    py.amount,
                    py.payment_method,
                    py.status,
                    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                    p.patient_number,
                    a.procedure_name,
                    a.appointment_date
                FROM payments py
                JOIN patients p ON py.patient_id = p.id
                LEFT JOIN appointments a ON py.appointment_id = a.id";

        $conditions = [];
        $params = [];
        $types = '';

        $status = isset($get['status']) ? trim((string) $get['status']) : '';
        $search = isset($get['search']) ? trim((string) $get['search']) : '';
        $from = isset($get['from']) ? trim((string) $get['from']) : '';
        $to = isset($get['to']) ? trim((string) $get['to']) : '';

        if ($status !== '') {
            $conditions[] = 'py.status = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($search !== '') {
            $conditions[] = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR p.patient_number LIKE ? OR py.description LIKE ?)";
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s]);
            $types .= 'sss';
        }
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $conditions[] = 'COALESCE(py.payment_date, DATE(py.created_at)) >= ?';
            $params[] = $from;
            $types .= 's';
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $conditions[] = 'COALESCE(py.payment_date, DATE(py.created_at)) <= ?';
            $params[] = $to;
            $types .= 's';
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY COALESCE(py.payment_date, DATE(py.created_at)) DESC, py.id DESC';

        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }
}

if (!function_exists('export_payment_display_date')) {
    function export_payment_display_date(array $row): string {
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
        return '';
    }
}

if (!function_exists('export_stream_payments_csv')) {
    function export_stream_payments_csv(mysqli $db, array $get, string $filenameBase): void {
        $q = export_payments_query($get);
        export_csv_begin($filenameBase);
        export_csv_row([
            'Payment ID',
            'Patient Name',
            'Patient Number',
            'Date',
            'Description',
            'Procedure',
            'Amount (PHP)',
            'Payment Method',
            'Status',
            'Created At',
        ]);

        if ($q['params']) {
            $stmt = $db->prepare($q['sql']);
            if (!$stmt) {
                return;
            }
            $stmt->bind_param($q['types'], ...$q['params']);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $db->query($q['sql']);
        }
        if (!$res) {
            return;
        }
        while ($row = $res->fetch_assoc()) {
            export_csv_row([
                $row['id'] ?? '',
                $row['patient_name'] ?? '',
                $row['patient_number'] ?? '',
                export_payment_display_date($row),
                $row['description'] ?? '',
                $row['procedure_name'] ?? '',
                number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                $row['payment_method'] ?? '',
                $row['status'] ?? '',
                $row['created_at'] ?? '',
            ]);
        }
        if (isset($stmt)) {
            $stmt->close();
        }
    }
}

if (!function_exists('export_stream_dashboard_summary_csv')) {
    function export_stream_dashboard_summary_csv(mysqli $db, string $filenameBase): void {
        $totalPatients = (int) $db->query("SELECT COUNT(*) FROM patients WHERE status='active'")->fetch_row()[0];
        $totalDentists = (int) $db->query("SELECT COUNT(*) FROM dentists WHERE status='active'")->fetch_row()[0];
        $todayAppts = (int) $db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetch_row()[0];
        $upcomingAppts = (int) $db->query(
            "SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status NOT IN ('Cancelled','Completed')"
        )->fetch_row()[0];
        $pendingPayments = (int) $db->query("SELECT COUNT(*) FROM payments WHERE status='Pending'")->fetch_row()[0];

        $rev = $db->query(
            "SELECT
                SUM(CASE
                    WHEN YEARWEEK(COALESCE(payment_date, DATE(created_at)), 1) = YEARWEEK(CURDATE(), 1)
                    THEN amount ELSE 0 END) AS weekly,
                SUM(CASE
                    WHEN MONTH(COALESCE(payment_date, DATE(created_at))) = MONTH(CURDATE())
                     AND YEAR(COALESCE(payment_date, DATE(created_at))) = YEAR(CURDATE())
                    THEN amount ELSE 0 END) AS monthly
             FROM payments
             WHERE status IN ('Paid','Partial')"
        )->fetch_assoc();
        $weeklyRevenue = number_format((float) ($rev['weekly'] ?? 0), 2, '.', '');
        $monthlyRevenue = number_format((float) ($rev['monthly'] ?? 0), 2, '.', '');

        $funnel = [];
        $fr = $db->query('SELECT status, COUNT(*) AS count FROM appointments GROUP BY status');
        while ($fr && ($row = $fr->fetch_assoc())) {
            $funnel[$row['status']] = (int) $row['count'];
        }
        $scheduled = ($funnel['Scheduled'] ?? 0) + ($funnel['Confirmed'] ?? 0);
        $completed = $funnel['Completed'] ?? 0;
        $cancelled = $funnel['Cancelled'] ?? 0;

        export_csv_begin($filenameBase);
        export_csv_row(['Metric', 'Value']);
        export_csv_row(['Generated at', date('Y-m-d H:i:s')]);
        export_csv_row(['Active patients', $totalPatients]);
        export_csv_row(['Active dentists', $totalDentists]);
        export_csv_row(["Today's appointments", $todayAppts]);
        export_csv_row(['Upcoming appointments', $upcomingAppts]);
        export_csv_row(['Pending payment records', $pendingPayments]);
        export_csv_row(['Weekly revenue (Paid+Partial, PHP)', $weeklyRevenue]);
        export_csv_row(['Monthly revenue (Paid+Partial, PHP)', $monthlyRevenue]);
        export_csv_row(['Scheduled / confirmed appointments', $scheduled]);
        export_csv_row(['Completed appointments', $completed]);
        export_csv_row(['Cancelled appointments', $cancelled]);
    }
}
