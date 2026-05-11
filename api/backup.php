<?php
define('EDROSO_SKIP_JSON_HEADERS', true);
require_once '../includes/db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized. Please log in.';
    exit;
}

if (sessionUserRole() !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database backup is only available to administrators.';
    exit;
}

$db = getDB();
$dbName = DB_NAME;

/** @return string[] */
function listTables(mysqli $db): array {
    $tables = [];
    $res = $db->query('SHOW TABLES');
    if (!$res) {
        return $tables;
    }
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    return $tables;
}

function sqlEscape(mysqli $db, string $s): string {
    return "'" . $db->real_escape_string($s) . "'";
}

function dumpTable(mysqli $db, string $table, bool $includeDrop): string {
    $out = '';
    $t = '`' . str_replace('`', '``', $table) . '`';

    $res = $db->query("SHOW CREATE TABLE $t");
    if (!$res) {
        return '';
    }
    $row = $res->fetch_assoc();
    if ($includeDrop) {
        $out .= "DROP TABLE IF EXISTS $t;\n";
    }
    $out .= $row['Create Table'] . ";\n\n";

    $rows = $db->query("SELECT * FROM $t");
    if (!$rows || $rows->num_rows === 0) {
        return $out;
    }

    while ($r = $rows->fetch_assoc()) {
        $cols = array_keys($r);
        $vals = [];
        foreach ($r as $v) {
            if ($v === null) {
                $vals[] = 'NULL';
            } elseif (is_int($v) || is_float($v)) {
                $vals[] = (string) $v;
            } else {
                $vals[] = sqlEscape($db, (string) $v);
            }
        }
        $colList = implode(', ', array_map(function ($c) {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $cols));
        $out .= "INSERT INTO $t ($colList) VALUES (" . implode(', ', $vals) . ");\n";
    }
    $out .= "\n";
    return $out;
}

$filename = 'edroso_dental_backup_' . date('Y-m-d_His') . '.sql';

header('Content-Type: application/octet-stream; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo "-- Edroso Dental Clinic SQL backup\n";
echo '-- Generated: ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

$includeDrop = filter_var($_GET['include_drop'] ?? false, FILTER_VALIDATE_BOOLEAN);

foreach (listTables($db) as $table) {
    echo dumpTable($db, $table, $includeDrop);
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
