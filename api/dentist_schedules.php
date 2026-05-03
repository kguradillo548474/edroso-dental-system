<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability_slots.php';

requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if (in_array($method, ['POST', 'DELETE'], true)) {
    csrf_require_valid();
}

ensure_dentist_schedules_table($db);

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

if ($method === 'GET') {
    $dentistId = isset($_GET['dentist_id']) ? (int) $_GET['dentist_id'] : 0;
    if ($dentistId <= 0) {
        respond(['error' => 'dentist_id is required'], 400);
    }
    $stmt = $db->prepare(
        "SELECT id, dentist_id, day_of_week, start_time, end_time, slot_duration_minutes, is_active
         FROM dentist_schedules WHERE dentist_id = ?
         ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')"
    );
    $stmt->bind_param('i', $dentistId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['is_active'] = (int) $row['is_active'];
        $row['slot_duration_minutes'] = (int) $row['slot_duration_minutes'];
        $rows[] = $row;
    }
    $stmt->close();
    respond(['schedules' => $rows]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(['error' => 'Invalid JSON body'], 400);
    }
    $dentistId = (int) ($body['dentist_id'] ?? 0);
    $day      = trim((string) ($body['day_of_week'] ?? ''));
    $start    = trim((string) ($body['start_time'] ?? ''));
    $end      = trim((string) ($body['end_time'] ?? ''));
    $slotMin  = (int) ($body['slot_duration_minutes'] ?? 30);
    $active   = array_key_exists('is_active', $body) ? ((int) (!!$body['is_active'])) : 1;

    if ($dentistId <= 0 || !in_array($day, $days, true)) {
        respond(['error' => 'dentist_id and valid day_of_week are required'], 400);
    }
    if ($start === '' || $end === '') {
        respond(['error' => 'start_time and end_time are required'], 400);
    }
    if ($slotMin < 5 || $slotMin > 240) {
        respond(['error' => 'slot_duration_minutes must be between 5 and 240'], 400);
    }

    $startSql = strlen($start) === 5 ? $start . ':00' : $start;
    $endSql   = strlen($end) === 5 ? $end . ':00' : $end;

    $upsert = $db->prepare(
        'INSERT INTO dentist_schedules (dentist_id, day_of_week, start_time, end_time, slot_duration_minutes, is_active)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            start_time = VALUES(start_time),
            end_time = VALUES(end_time),
            slot_duration_minutes = VALUES(slot_duration_minutes),
            is_active = VALUES(is_active)'
    );
    if (!$upsert) {
        respond(['error' => $db->error], 500);
    }
    $upsert->bind_param('isssii', $dentistId, $day, $startSql, $endSql, $slotMin, $active);
    if (!$upsert->execute()) {
        respond(['error' => $upsert->error], 500);
    }
    $upsert->close();
    respond(['success' => true]);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        respond(['error' => 'id is required'], 400);
    }
    $stmt = $db->prepare('DELETE FROM dentist_schedules WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        respond(['error' => $stmt->error], 500);
    }
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);
