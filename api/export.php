<?php
/**
 * Staff CSV exports — GET ?resource=appointments|payments|dashboard_summary&format=csv
 */
define('EDROSO_SKIP_JSON_HEADERS', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/export_helpers.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized. Please log in.';
    exit;
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if ($format !== 'csv') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Only format=csv is supported.';
    exit;
}

$resource = strtolower(trim((string) ($_GET['resource'] ?? '')));
$db = getDB();

switch ($resource) {
    case 'appointments':
        $status = trim((string) ($_GET['status'] ?? ''));
        $name = 'appointments';
        if ($status !== '') {
            $name .= '_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($status));
        } elseif (!empty($_GET['filter'])) {
            $name .= '_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower((string) $_GET['filter']));
        }
        export_stream_appointments_csv($db, $_GET, $name);
        break;

    case 'payments':
        if (sessionUserRole() !== 'admin') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Payment exports require an administrator account.';
            exit;
        }
        $payName = 'payments';
        $payFrom = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
        $payTo = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
        if ($payFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payFrom)) {
            $payName .= '_from_' . $payFrom;
        }
        if ($payTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payTo)) {
            $payName .= '_to_' . $payTo;
        }
        export_stream_payments_csv($db, $_GET, $payName);
        break;

    case 'dashboard_summary':
        export_stream_dashboard_summary_csv($db, 'dashboard_summary');
        break;

    default:
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unknown resource. Use resource=appointments, payments, or dashboard_summary.';
        exit;
}

exit;
