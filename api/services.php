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
        description TEXT NULL DEFAULT NULL,
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

function ensureServicesDescriptionColumn(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $col = @$db->query("SHOW COLUMNS FROM services LIKE 'description'");
    if ($col && $col->num_rows === 0) {
        @$db->query(
            'ALTER TABLE services ADD COLUMN description TEXT NULL DEFAULT NULL AFTER price'
        );
    }
    if ($col) {
        $col->free();
    }
    $done = true;
}

function ensureServicesImageUrlColumn(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $col = @$db->query("SHOW COLUMNS FROM services LIKE 'image_url'");
    if ($col && $col->num_rows === 0) {
        @$db->query(
            'ALTER TABLE services ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER description'
        );
    }
    if ($col) {
        $col->free();
    }
    $done = true;
}

/** Allowed public image references: same-origin path or absolute http(s). */
function normalizeServiceImageUrl(?string $raw): string {
    $u = trim((string) ($raw ?? ''));
    if ($u === '') {
        return '';
    }
    if (strlen($u) > 500) {
        $u = substr($u, 0, 500);
    }
    if (preg_match('/^javascript:/i', $u)) {
        return '';
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>"]/', $u)) {
        return '';
    }
    if (preg_match('#^(https?://|/|assets/)#i', $u)) {
        return $u;
    }
    return '';
}

define('SERVICE_UPLOAD_DIR', __DIR__ . '/../assets/uploads/services');
define('SERVICE_IMAGE_MAX_BYTES', 2 * 1024 * 1024);

function serviceUploadedPhotoExtensions(): array {
    return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
}

function removeAllServiceUploadedPhotos(int $serviceId): void {
    $id = intval($serviceId);
    foreach (serviceUploadedPhotoExtensions() as $ext) {
        $p = SERVICE_UPLOAD_DIR . '/' . $id . '.' . $ext;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

function serviceUploadedPhotoPublicUrl(int $serviceId): ?string {
    $id = intval($serviceId);
    foreach (serviceUploadedPhotoExtensions() as $ext) {
        $rel = 'assets/uploads/services/' . $id . '.' . $ext;
        $full = SERVICE_UPLOAD_DIR . '/' . $id . '.' . $ext;
        if (is_file($full)) {
            return $rel;
        }
    }
    return null;
}

function ensureServiceUploadDir(): void {
    if (!is_dir(SERVICE_UPLOAD_DIR)) {
        if (!@mkdir(SERVICE_UPLOAD_DIR, 0755, true) && !is_dir(SERVICE_UPLOAD_DIR)) {
            respond(['error' => 'Could not create service upload directory.'], 500);
        }
    }
    if (!is_writable(SERVICE_UPLOAD_DIR)) {
        respond(['error' => 'Service upload directory is not writable.'], 500);
    }
}

function serviceUploadMimeType(string $tmp): ?string {
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = finfo_file($fi, $tmp);
            finfo_close($fi);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
    }
    $gi = @getimagesize($tmp);
    if ($gi && !empty($gi['mime'])) {
        return $gi['mime'];
    }
    return null;
}

/**
 * @return array{0: ?string, 1: ?int} Public URL (or https URL) and optional cache-bust version (mtime).
 */
function resolveServiceImageForCatalog(int $id, ?string $dbUrl): array {
    $dbUrl = trim((string) ($dbUrl ?? ''));
    $normalized = $dbUrl !== '' ? normalizeServiceImageUrl($dbUrl) : '';
    if ($normalized !== '') {
        if (preg_match('#^https?://#i', $normalized)) {
            return [$normalized, null];
        }
        $full = __DIR__ . '/../' . $normalized;
        if (is_file($full)) {
            return [$normalized, @filemtime($full) ?: null];
        }
        return [$normalized, null];
    }
    $up = serviceUploadedPhotoPublicUrl($id);
    if ($up) {
        $full = __DIR__ . '/../' . $up;

        return [$up, @filemtime($full) ?: null];
    }

    return [null, null];
}

function saveServiceImageFromUpload(mysqli $db, int $serviceId): void {
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        respond(['error' => 'No image file received.'], 400);
    }
    if ($_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        respond(['error' => 'Choose an image file first.'], 400);
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $err = (int) $_FILES['image']['error'];
        $msg = 'Upload failed.';
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $msg = 'File exceeds server upload limit (check upload_max_filesize / post_max_size in php.ini).';
        }
        respond(['error' => $msg], 400);
    }
    if ($_FILES['image']['size'] > SERVICE_IMAGE_MAX_BYTES) {
        respond(['error' => 'Image must be 2MB or less.'], 400);
    }
    $tmp = $_FILES['image']['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        respond(['error' => 'Invalid upload.'], 400);
    }
    $mime = serviceUploadMimeType($tmp) ?: '';
    $rasterMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($rasterMap[$mime])) {
        respond(['error' => 'Allowed types: JPEG, PNG, GIF, WebP (max 2MB).'], 400);
    }
    $gi = @getimagesize($tmp);
    if ($gi === false && $mime !== 'image/webp') {
        respond(['error' => 'Invalid or corrupted image file.'], 400);
    }
    $chk = $db->prepare('SELECT id FROM services WHERE id = ? LIMIT 1');
    $chk->bind_param('i', $serviceId);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        respond(['error' => 'Service not found.'], 404);
    }
    $chk->close();

    $ext = $rasterMap[$mime];
    ensureServiceUploadDir();
    removeAllServiceUploadedPhotos($serviceId);
    $dest = SERVICE_UPLOAD_DIR . '/' . $serviceId . '.' . $ext;
    if (!move_uploaded_file($tmp, $dest)) {
        respond(['error' => 'Could not save image.'], 500);
    }
    $rel = 'assets/uploads/services/' . $serviceId . '.' . $ext;
    $stmt = $db->prepare('UPDATE services SET image_url = ? WHERE id = ?');
    $stmt->bind_param('si', $rel, $serviceId);
    if (!$stmt->execute()) {
        respond(['error' => $db->error], 500);
    }
    $stmt->close();
    $ver = @filemtime($dest) ?: time();
    respond([
        'success' => true,
        'image_url' => $rel,
        'image_version' => $ver,
    ]);
}

function clearServiceStoredImage(mysqli $db, int $serviceId): void {
    $chk = $db->prepare('SELECT id FROM services WHERE id = ? LIMIT 1');
    $chk->bind_param('i', $serviceId);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        respond(['error' => 'Service not found.'], 404);
    }
    $chk->close();
    removeAllServiceUploadedPhotos($serviceId);
    $stmt = $db->prepare('UPDATE services SET image_url = NULL WHERE id = ?');
    $stmt->bind_param('i', $serviceId);
    if (!$stmt->execute()) {
        respond(['error' => $db->error], 500);
    }
    $stmt->close();
    respond(['success' => true, 'image_url' => null, 'image_version' => null]);
}

/** One-time style updates for rows created before `description` existed. */
function backfillDefaultServiceDescriptions(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pairs = [
        ['Oral Prophylaxis', 'Thorough cleaning to remove plaque and tartar and help keep gums healthy.'],
        ['Extraction', 'Safe removal of damaged or problematic teeth with clear after-care guidance.'],
        ['Filling (Pasta)', 'Restores cavities with durable materials so you can chew comfortably again.'],
        ['Braces Adjustment', 'Orthodontic visit to keep your braces treatment progressing on schedule.'],
        ['Whitening', 'Professional whitening for a brighter smile under clinical supervision.'],
    ];
    $stmt = $db->prepare(
        'UPDATE services SET description = ? WHERE name = ? AND (description IS NULL OR description = \'\')'
    );
    if (!$stmt) {
        return;
    }
    foreach ($pairs as $pair) {
        $name = $pair[0];
        $desc = $pair[1];
        $stmt->bind_param('ss', $desc, $name);
        $stmt->execute();
    }
    $stmt->close();
}

function seedDefaultServicesIfEmpty(mysqli $db): void {
    $r = $db->query('SELECT COUNT(*) AS c FROM services');
    $row = $r ? $r->fetch_assoc() : ['c' => 0];
    if ((int) ($row['c'] ?? 0) > 0) {
        return;
    }
    $defaults = [
        ['Oral Prophylaxis', 800, 'Thorough cleaning to remove plaque and tartar and help keep gums healthy.'],
        ['Extraction', 3500, 'Safe removal of damaged or problematic teeth with clear after-care guidance.'],
        ['Filling (Pasta)', 1500, 'Restores cavities with durable materials so you can chew comfortably again.'],
        ['Braces Adjustment', 2500, 'Orthodontic visit to keep your braces treatment progressing on schedule.'],
        ['Whitening', 5000, 'Professional whitening for a brighter smile under clinical supervision.'],
    ];
    $stmt = $db->prepare('INSERT INTO services (name, price, description, active) VALUES (?, ?, ?, 1)');
    foreach ($defaults as $d) {
        $name = $d[0];
        $price = $d[1];
        $desc = $d[2];
        $stmt->bind_param('sds', $name, $price, $desc);
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
    ensureServicesDescriptionColumn($conn);
    ensureServicesImageUrlColumn($conn);
    seedDefaultServicesIfEmpty($conn);
    backfillDefaultServiceDescriptions($conn);
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
        "SELECT id, name, price, required_specialization, description, image_url FROM services WHERE active = 1 ORDER BY name ASC"
    );
    if (!$result) {
        respond(['error' => 'Query failed: ' . $conn->error], 500);
    }
    $services = [];
    while ($row = $result->fetch_assoc()) {
        $sid = (int) $row['id'];
        $dbImg = $row['image_url'] ?? null;
        [$imgUrl, $imgVer] = resolveServiceImageForCatalog($sid, is_string($dbImg) ? $dbImg : null);
        $services[] = [
            'id' => $sid,
            'name' => $row['name'],
            'price' => (float) $row['price'],
            'required_specialization' => $row['required_specialization'],
            'description' => isset($row['description']) && $row['description'] !== null && trim((string) $row['description']) !== ''
                ? (string) $row['description']
                : null,
            'image_url' => $imgUrl,
            'image_version' => $imgVer,
        ];
    }
    respond(['services' => $services]);
}

requireAuth();

$db = getDB();

ensureServicesTable($db);
ensureServicesRequiredSpecializationColumn($db);
ensureServicesDescriptionColumn($db);
ensureServicesImageUrlColumn($db);

if ($method === 'GET') {
    seedDefaultServicesIfEmpty($db);
    backfillDefaultServiceDescriptions($db);
    $list = [];
    $res = $db->query('SELECT id, name, required_specialization, price, active, description, image_url FROM services ORDER BY id ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['active'] = (int) $row['active'];
            $row['price'] = (float) $row['price'];
            if (array_key_exists('description', $row) && $row['description'] === '') {
                $row['description'] = null;
            }
            if (array_key_exists('image_url', $row)) {
                $iu = trim((string) ($row['image_url'] ?? ''));
                $n = $iu !== '' ? normalizeServiceImageUrl($iu) : '';
                $row['image_url'] = $n !== '' ? $n : null;
            }
            $list[] = $row;
        }
    }
    respond(['services' => $list]);
}

if ($method === 'POST') {
    $postId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($postId > 0) {
        if (($_GET['action'] ?? '') === 'clear_image') {
            clearServiceStoredImage($db, $postId);
        }
        if (isset($_FILES['image']) && is_array($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            saveServiceImageFromUpload($db, $postId);
        }
        respond(['error' => 'Use multipart field "image" to upload, or action=clear_image to remove the stored photo/URL.'], 400);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($body['name'] ?? '');
    $price = isset($body['price']) ? floatval($body['price']) : 0;
    $description = trim($body['description'] ?? '');
    $imageUrl = normalizeServiceImageUrl($body['image_url'] ?? '');
    if ($name === '') {
        respond(['error' => 'Service name is required.'], 400);
    }
    $stmt = $db->prepare('INSERT INTO services (name, price, description, image_url, active) VALUES (?, ?, ?, ?, 1)');
    $stmt->bind_param('sdss', $name, $price, $description, $imageUrl);
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
    $bodyNoCsrf = $body;
    unset($bodyNoCsrf['csrf_token']);

    /** Toggle Active/Inactive only — client always includes csrf_token, so count($body) is never 1. */
    if (array_key_exists('active', $bodyNoCsrf) && count($bodyNoCsrf) === 1) {
        $active = (int) (!!$body['active']);
        $stmt = $db->prepare('UPDATE services SET active = ? WHERE id = ?');
        $stmt->bind_param('ii', $active, $id);
    } else {
        $name = trim($body['name'] ?? '');
        $price = isset($body['price']) ? floatval($body['price']) : 0;
        $description = trim($body['description'] ?? '');
        $imageUrl = normalizeServiceImageUrl($body['image_url'] ?? '');
        if ($name === '') {
            respond(['error' => 'Service name is required.'], 400);
        }
        $active = array_key_exists('active', $body) ? ((int) (!!$body['active'])) : null;
        if ($active !== null) {
            $stmt = $db->prepare('UPDATE services SET name = ?, price = ?, description = ?, image_url = ?, active = ? WHERE id = ?');
            $stmt->bind_param('sdssii', $name, $price, $description, $imageUrl, $active, $id);
        } else {
            $stmt = $db->prepare('UPDATE services SET name = ?, price = ?, description = ?, image_url = ? WHERE id = ?');
            $stmt->bind_param('sdssi', $name, $price, $description, $imageUrl, $id);
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
    removeAllServiceUploadedPhotos($id);
    $stmt = $db->prepare('DELETE FROM services WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        respond(['error' => $db->error], 500);
    }
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);
