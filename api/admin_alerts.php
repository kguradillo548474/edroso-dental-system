<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

requireAdminSession();

$db = getDB();

$db->query(
    "CREATE TABLE IF NOT EXISTS admin_staff_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alert_type VARCHAR(64) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_staff_alerts_unread (is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ($method === 'GET') {
    $limit = (int) ($_GET['limit'] ?? 20);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 50) {
        $limit = 50;
    }
    $stmt = $db->prepare(
        'SELECT id, alert_type, message, is_read, created_at
         FROM admin_staff_alerts
         ORDER BY created_at DESC, id DESC
         LIMIT ?'
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id'         => (int) $r['id'],
            'alert_type' => (string) $r['alert_type'],
            'message'    => (string) $r['message'],
            'is_read'    => (int) $r['is_read'] === 1,
            'created_at' => (string) $r['created_at'],
        ];
    }
    $stmt->close();
    $unreadInList = 0;
    foreach ($rows as $r) {
        if (!$r['is_read']) {
            $unreadInList++;
        }
    }
    $totalUnread = 0;
    if ($uq = $db->query('SELECT COUNT(*) AS c FROM admin_staff_alerts WHERE is_read = 0')) {
        $ur = $uq->fetch_assoc();
        $totalUnread = (int) ($ur['c'] ?? 0);
    }
    respond(['alerts' => $rows, 'unread_count' => $totalUnread, 'unread_in_list' => $unreadInList]);
}

if ($method === 'POST') {
    csrf_require_valid();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = $body['mark_read_ids'] ?? null;
    if (is_array($ids) && $ids) {
        $clean = array_values(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0));
        if ($clean) {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $types = str_repeat('i', count($clean));
            $sql = "UPDATE admin_staff_alerts SET is_read = 1 WHERE id IN ($placeholders)";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$clean);
                $stmt->execute();
                $stmt->close();
            }
        }
        respond(['success' => true]);
    }
    if (!empty($body['mark_all_read'])) {
        $db->query('UPDATE admin_staff_alerts SET is_read = 1 WHERE is_read = 0');
        respond(['success' => true]);
    }
    respond(['error' => 'Provide mark_read_ids array or mark_all_read.', 'code' => 'bad_request'], 400);
}
