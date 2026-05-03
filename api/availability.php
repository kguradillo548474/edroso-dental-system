<?php
/**
 * Public read: available time slots for a dentist on a calendar date.
 * GET ?dentist_id=X&date=Y (YYYY-MM-DD)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/availability_slots.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

$dentistId = isset($_GET['dentist_id']) ? (int) $_GET['dentist_id'] : 0;
$date      = trim((string) ($_GET['date'] ?? ''));

if ($dentistId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    respond(['error' => 'dentist_id and date (YYYY-MM-DD) are required'], 400);
}

$db = getDB();
$vd = $db->prepare("SELECT id FROM dentists WHERE id = ? AND status = 'active' LIMIT 1");
if (!$vd) {
    respond(['error' => 'Database error'], 500);
}
$vd->bind_param('i', $dentistId);
$vd->execute();
if (!$vd->get_result()->fetch_assoc()) {
    $vd->close();
    respond(['error' => 'Invalid or inactive dentist'], 400);
}
$vd->close();

$slots = edroso_available_slots_response($db, $dentistId, $date);

$schedCount = edroso_dentist_schedule_row_count($db, $dentistId);
$anyAvail = false;
foreach ($slots as $s) {
    if (!empty($s['available'])) {
        $anyAvail = true;
        break;
    }
}
$payload = [
    'slots'            => $slots,
    'dentist_day_off'  => $schedCount > 0 && count($slots) === 0,
    'all_slots_booked' => count($slots) > 0 && !$anyAvail,
];
if (isset($_GET['suggest_days']) && $_GET['suggest_days'] === '1') {
    $payload['suggested_dates'] = edroso_next_available_dates_with_slots($db, $dentistId, 90, 10);
}
respond($payload);
