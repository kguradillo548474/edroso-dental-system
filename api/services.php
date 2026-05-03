<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_require_valid();
}

function ensureServicesTable(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        required_specialization VARCHAR(100) NULL DEFAULT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureServicesRequiredSpecializationColumn(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $col = @$db->query("SHOW COLUMNS FROM services LIKE 'required_specialization'");
    if ($col && $col->num_rows === 0) {
        @$db->query(
            'ALTER TABLE services ADD COLUMN required_specialization VARCHAR(100) NULL DEFAULT NULL AFTER name'
        );
    }
    if ($col) {
        $col->free();
    }
    $done = true;
}

function seedDefaultServicesIfEmpty(mysqli $db): void {
    $r = $db->query('SELECT COUNT(*) AS c FROM services');
    $row = $r ? $r->fetch_assoc() : ['c' => 0];
    if ((int) ($row['c'] ?? 0) > 0) {
        return;
    }
    $defaults = [
        ['Oral Prophylaxis', 800],
        ['Extraction', 3500],
        ['Filling (Pasta)', 1500],
        ['Braces Adjustment', 2500],
        ['Whitening', 5000],
    ];
    $stmt = $db->prepare('INSERT INTO services (name, price, active) VALUES (?, ?, 1)');
    foreach ($defaults as $d) {
        $name = $d[0];
        $price = $d[1];
        $stmt->bind_param('sd', $name, $price);
        $stmt->execute();
    }
}

// GET — active catalog: anonymous, or ?scope=catalog (portal / customer-site when staff session exists)
$isServiceCatalogGet = $method === 'GET'
    && !isset($_GET['id'])
    && (
        empty($_SESSION['user_id'])
        || (($_GET['scope'] ?? '') === 'catalog')
    );

if ($isServiceCatalogGet) {
    $conn = getDB();
    ensureServicesTable($conn);
    ensureServicesRequiredSpecializationColumn($conn);
    seedDefaultServicesIfEmpty($conn);
    $conn->query(
        "UPDATE services SET required_specialization = 'Orthodontist'
         WHERE required_specialization IS NULL AND (
           LOWER(name) LIKE '%brace%' OR LOWER(name) LIKE '%alignment%' OR LOWER(name) LIKE '%orthodont%'
         )"
    );
    $conn->query(
        "UPDATE services SET required_specialization = 'Endodontist'
         WHERE required_specialization IS NULL AND (
           LOWER(name) LIKE '%root canal%' OR LOWER(name) LIKE '%endodont%'
         )"
    );
    $conn->query(
        "UPDATE services SET required_specialization = 'Periodontist'
         WHERE required_specialization IS NULL AND (
           LOWER(name) LIKE '%gum%' OR LOWER(name) LIKE '%periodont%'
         )"
    );
    $conn->query(
        "UPDATE services SET required_specialization = 'Pediatric Dentist'
         WHERE required_specialization IS NULL AND (
           LOWER(name) LIKE '%pediatric%' OR LOWER(name) LIKE '%child%'
         )"
    );
    $conn->query(
        "UPDATE services SET required_specialization = 'General Dentist'
         WHERE required_specialization IS NULL AND (
           LOWER(name) LIKE '%filling%' OR LOWER(name) LIKE '%extraction%' OR LOWER(name) LIKE '%prophylaxis%'
           OR LOWER(name) LIKE '%clean%' OR LOWER(name) LIKE '%pasta%' OR LOWER(name) LIKE '%whiten%'
         )"
    );
    $conn->query(
        "UPDATE services SET required_specialization = 'General Dentist' WHERE required_specialization IS NULL"
    );
    $result = $conn->query(
        "SELECT id, name, price, required_specialization FROM services WHERE active = 1 ORDER BY name ASC"
    );
    if (!$result) {
        respond(['error' => 'Query failed: ' . $conn->error], 500);
    }
    $services = [];
    while ($row = $result->fetch_assoc()) {
        $services[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'price' => (float) $row['price'],
            'required_specialization' => $row['required_specialization'],
        ];
    }
    respond(['services' => $services]);
}

requireAuth();

$db = getDB();

ensureServicesTable($db);
ensureServicesRequiredSpecializationColumn($db);

if ($method === 'GET') {
    seedDefaultServicesIfEmpty($db);
    $list = [];
    $res = $db->query('SELECT id, name, required_specialization, price, active FROM services ORDER BY id ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['active'] = (int) $row['active'];
            $row['price'] = (float) $row['price'];
            $list[] = $row;
        }
    }
    respond(['services' => $list]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($body['name'] ?? '');
    $price = isset($body['price']) ? floatval($body['price']) : 0;
    if ($name === '') {
        respond(['error' => 'Service name is required.'], 400);
    }
    $stmt = $db->prepare('INSERT INTO services (name, price, active) VALUES (?, ?, 1)');
    $stmt->bind_param('sd', $name, $price);
    if (!$stmt->execute()) {
        respond(['error' => $db->error], 500);
    }
    respond(['success' => true, 'id' => $db->insert_id], 201);
}

if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        respond(['error' => 'Valid id is required.'], 400);
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if (array_key_exists('active', $body) && count($body) === 1) {
        $active = (int) (!!$body['active']);
        $stmt = $db->prepare('UPDATE services SET active = ? WHERE id = ?');
        $stmt->bind_param('ii', $active, $id);
    } else {
        $name = trim($body['name'] ?? '');
        $price = isset($body['price']) ? floatval($body['price']) : 0;
        if ($name === '') {
            respond(['error' => 'Service name is required.'], 400);
        }
        $active = array_key_exists('active', $body) ? ((int) (!!$body['active'])) : null;
        if ($active !== null) {
            $stmt = $db->prepare('UPDATE services SET name = ?, price = ?, active = ? WHERE id = ?');
            $stmt->bind_param('sdii', $name, $price, $active, $id);
        } else {
            $stmt = $db->prepare('UPDATE services SET name = ?, price = ? WHERE id = ?');
            $stmt->bind_param('sdi', $name, $price, $id);
        }
    }
    if (!$stmt->execute()) {
        respond(['error' => $db->error], 500);
    }
    respond(['success' => true]);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        respond(['error' => 'Valid id is required.'], 400);
    }
    $stmt = $db->prepare('DELETE FROM services WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        respond(['error' => $db->error], 500);
    }
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);
