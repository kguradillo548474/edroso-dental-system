<?php
/**
 * CLI: mirror portal bookings into admin appointments (one-off or cron).
 *
 *   php tools/sync_portal_to_admin.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/portal_admin_sync.php';

$stats = backfill_portal_appointments_to_admin(getDB());
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
