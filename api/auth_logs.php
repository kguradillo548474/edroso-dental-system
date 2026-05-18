<?php
/**
 * Admin-only: read authentication attempt log (staff + patient portal sign-ins).
 */
require_once '../includes/db.php';
require_once '../includes/auth_login_log.php';

requireAdminSession();

$action = trim((string) ($_GET['action'] ?? 'list'));
if ($action !== 'list') {
    respond(['error' => 'Invalid action'], 400);
}

$db = getDB();
if (!ensure_auth_login_log_table($db)) {
    respond(['error' => 'Could not prepare login log table.'], 500);
}

$limit  = min(200, max(1, (int) ($_GET['limit'] ?? 80)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$realm  = trim((string) ($_GET['realm'] ?? ''));

if ($realm !== '' && $realm !== 'staff' && $realm !== 'portal') {
    respond(['error' => 'Invalid realm filter'], 400);
}

$cols = 'id, created_at, realm, identifier, success, user_id, ip_address, user_agent, detail';

if ($realm === 'staff' || $realm === 'portal') {
    $stmt = $db->prepare(
        "SELECT {$cols} FROM auth_login_log WHERE realm = ? ORDER BY id DESC LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('sii', $realm, $limit, $offset);
} else {
    $stmt = $db->prepare(
        "SELECT {$cols} FROM auth_login_log ORDER BY id DESC LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        respond(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('ii', $limit, $offset);
}

$stmt->execute();
$res = $stmt->get_result();
$logs = [];
while ($row = $res->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();

respond([
    'logs'   => $logs,
    'limit'  => $limit,
    'offset' => $offset,
]);
