<?php
require_once '../includes/db.php';
requireAuth();

$db = getDB();
$stats = [];

// Core counts
$stats['total_patients']          = (int)$db->query("SELECT COUNT(*) FROM patients WHERE status='active'")->fetch_row()[0];
$stats['total_dentists']          = (int)$db->query("SELECT COUNT(*) FROM dentists WHERE status='active'")->fetch_row()[0];
$stats['today_appointments']      = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetch_row()[0];
$stats['upcoming_appointments']   = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status NOT IN ('Cancelled','Completed')")->fetch_row()[0];
$stats['completed_appointments']  = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status='Completed'")->fetch_row()[0];
$stats['cancelled_appointments']  = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status='Cancelled'")->fetch_row()[0];
$stats['pending_payments']        = (int)$db->query("SELECT COUNT(*) FROM payments WHERE status='Pending'")->fetch_row()[0];

// Revenue
$rev = $db->query("SELECT
    SUM(CASE WHEN YEARWEEK(payment_date,1)=YEARWEEK(CURDATE(),1) THEN amount ELSE 0 END) AS weekly,
    SUM(CASE WHEN MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE()) THEN amount ELSE 0 END) AS monthly
    FROM payments WHERE status='Paid'")->fetch_assoc();
$stats['weekly_revenue']  = floatval($rev['weekly']  ?? 0);
$stats['monthly_revenue'] = floatval($rev['monthly'] ?? 0);

// Recent appointments
$r = $db->query(
    "SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
     p.patient_number, d.name AS dentist_name
     FROM appointments a
     JOIN patients p ON a.patient_id = p.id
     JOIN dentists  d ON a.dentist_id = d.id
     ORDER BY a.created_at DESC LIMIT 6"
);
$stats['recent_appointments'] = [];
while ($row = $r->fetch_assoc()) {
    unset($row['room']);
    $stats['recent_appointments'][] = $row;
}

// Procedure distribution
$r = $db->query("SELECT procedure_type, COUNT(*) AS count FROM appointments GROUP BY procedure_type ORDER BY count DESC");
$stats['procedure_distribution'] = [];
while ($row = $r->fetch_assoc()) $stats['procedure_distribution'][] = $row;

// Appointment funnel
$r = $db->query("SELECT status, COUNT(*) AS count FROM appointments GROUP BY status");
$stats['appointment_funnel'] = [];
while ($row = $r->fetch_assoc()) $stats['appointment_funnel'][$row['status']] = (int)$row['count'];

respond($stats);
