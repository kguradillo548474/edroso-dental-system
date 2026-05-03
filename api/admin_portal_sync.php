<?php
/**
 * Staff-only: mirror any patient_appointments rows missing an admin appointments row.
 * POST with valid session CSRF (same as other admin APIs).
 */
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    respond(['error' => 'POST required'], 405);
}
csrf_require_valid();

require_once dirname(__DIR__) . '/includes/portal_admin_sync.php';
$stats = backfill_portal_appointments_to_admin(getDB());
respond($stats);
