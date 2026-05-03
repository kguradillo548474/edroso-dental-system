<?php
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/availability_slots.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_require_valid();
}

/**
 * Active dentists for portal booking / public catalog (no staff auth).
 * - scope=booking: always this list (fixes staff+portal same PHP session returning inactive rows).
 * - Otherwise: same list only when no staff session (legacy anonymous callers).
 */
$isBookingCatalogGet = $method === 'GET'
    && (($_GET['scope'] ?? '') === 'booking')
    && !isset($_GET['id']);
$isAnonymousCatalogGet = $method === 'GET'
    && empty($_SESSION['user_id'])
    && !isset($_GET['id']);

function dentistPublicPhotoPath(int $id): ?string {
    $baseDir = __DIR__ . '/../assets/uploads/dentists';
    $id = intval($id);
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'] as $ext) {
        $full = $baseDir . '/' . $id . '.' . $ext;
        if (is_file($full)) {
            return $full;
        }
    }
    return null;
}

function dentistPublicPhotoUrl(int $id): ?string {
    $full = dentistPublicPhotoPath($id);
    if (!$full) return null;
    $ext = pathinfo($full, PATHINFO_EXTENSION);
    return 'assets/uploads/dentists/' . intval($id) . '.' . $ext;
}

if ($isBookingCatalogGet || $isAnonymousCatalogGet) {
    $spec = trim((string) ($_GET['specialization'] ?? ''));
    if ($spec !== '') {
        $stmt = $db->prepare(
            "SELECT id, name, specialization FROM dentists WHERE status = 'active'
             AND LOWER(TRIM(COALESCE(specialization, ''))) = LOWER(?)
             ORDER BY id ASC"
        );
        if (!$stmt) {
            respond(['error' => 'Query failed: ' . $db->error], 500);
        }
        $stmt->bind_param('s', $spec);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query(
            "SELECT id, name, specialization FROM dentists WHERE status = 'active' ORDER BY id ASC"
        );
    }
    if (!$result) {
        respond(['error' => 'Query failed: ' . $db->error], 500);
    }
    $dentists = [];
    while ($row = $result->fetch_assoc()) {
        $dentists[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'specialization' => $row['specialization'],
            'photo_url' => dentistPublicPhotoUrl((int) $row['id']),
            'photo_version' => (($p = dentistPublicPhotoPath((int) $row['id'])) ? @filemtime($p) : null),
        ];
    }
    if ($spec !== '') {
        $stmt->close();
    }

    $includeSchedule = isset($_GET['include_schedule']) && $_GET['include_schedule'] === '1';
    if ($includeSchedule && $dentists) {
        ensure_dentist_schedules_table($db);
        $ids = array_column($dentists, 'id');
        foreach ($ids as $did) {
            edroso_seed_default_dentist_schedule($db, (int) $did);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $schedSql = "SELECT dentist_id, day_of_week, start_time, end_time, is_active
            FROM dentist_schedules
            WHERE dentist_id IN ($placeholders)
            ORDER BY dentist_id,
            FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')";
        $schedStmt = $db->prepare($schedSql);
        if ($schedStmt) {
            $schedStmt->bind_param($types, ...$ids);
            $schedStmt->execute();
            $schedRes = $schedStmt->get_result();
            $byDentist = [];
            while ($sr = $schedRes->fetch_assoc()) {
                $did = (int) ($sr['dentist_id'] ?? 0);
                if (!isset($byDentist[$did])) {
                    $byDentist[$did] = [];
                }
                $byDentist[$did][] = [
                    'day_of_week' => (string) ($sr['day_of_week'] ?? ''),
                    'start_time'  => substr((string) ($sr['start_time'] ?? ''), 0, 5),
                    'end_time'    => substr((string) ($sr['end_time'] ?? ''), 0, 5),
                    'is_active'   => (int) ($sr['is_active'] ?? 0),
                ];
            }
            $schedStmt->close();
            foreach ($dentists as &$dRow) {
                $dRow['weekly_schedule'] = $byDentist[$dRow['id']] ?? [];
            }
            unset($dRow);
        }
    }

    respond($dentists);
}

requireAuth();

$db = getDB();

define('DENTIST_UPLOAD_DIR', __DIR__ . '/../assets/uploads/dentists');
define('DENTIST_PHOTO_MAX_BYTES', 2 * 1024 * 1024);

/** Stored file extensions (one file per dentist id). Includes svg/gif for legacy files. */
function dentistPhotoFileExtensions(): array {
    return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
}

function removeAllDentistPhotos(int $dentistId): void {
    $id = intval($dentistId);
    foreach (dentistPhotoFileExtensions() as $ext) {
        $p = DENTIST_UPLOAD_DIR . '/' . $id . '.' . $ext;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

function dentistPhotoPublicUrl($id): ?string {
    $id = intval($id);
    foreach (dentistPhotoFileExtensions() as $ext) {
        $rel = 'assets/uploads/dentists/' . $id . '.' . $ext;
        $full = DENTIST_UPLOAD_DIR . '/' . $id . '.' . $ext;
        if (is_file($full)) {
            return $rel;
        }
    }
    return null;
}

function ensureDentistUploadDir(): void {
    if (!is_dir(DENTIST_UPLOAD_DIR)) {
        if (!@mkdir(DENTIST_UPLOAD_DIR, 0755, true) && !is_dir(DENTIST_UPLOAD_DIR)) {
            respond(['error' => 'Could not create upload directory.'], 500);
        }
    }
    if (!is_writable(DENTIST_UPLOAD_DIR)) {
        respond(['error' => 'Upload directory is not writable.'], 500);
    }
}

/**
 * Detect MIME using finfo, then getimagesize for raster fallbacks.
 */
function dentistUploadMimeType(string $tmp): ?string {
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

function saveDentistPhotoFromUpload($dentistId): void {
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        return;
    }
    if ($_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $err = (int) $_FILES['photo']['error'];
        $msg = 'Photo upload failed.';
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $msg = 'File exceeds server upload limit (check upload_max_filesize / post_max_size in php.ini).';
        } elseif ($err === UPLOAD_ERR_PARTIAL) {
            $msg = 'Upload was interrupted; please try again.';
        } elseif ($err === UPLOAD_ERR_NO_TMP_DIR) {
            $msg = 'Server temp folder missing; contact administrator.';
        } elseif ($err === UPLOAD_ERR_CANT_WRITE) {
            $msg = 'Could not write file to disk.';
        }
        respond(['error' => $msg], 400);
    }
    if ($_FILES['photo']['size'] > DENTIST_PHOTO_MAX_BYTES) {
        respond(['error' => 'Photo must be 2MB or less.'], 400);
    }

    $tmp = $_FILES['photo']['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        respond(['error' => 'Invalid upload.'], 400);
    }

    $head = @file_get_contents($tmp, false, null, 0, 16384);
    if ($head === false) {
        respond(['error' => 'Could not read upload.'], 500);
    }

    $mime = dentistUploadMimeType($tmp) ?: '';
    $slug = intval($dentistId);

    /** Allowed raster types (MIME from finfo_file / getimagesize, not client type). */
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

    $ext = $rasterMap[$mime];
    ensureDentistUploadDir();
    removeAllDentistPhotos($slug);
    $dest = DENTIST_UPLOAD_DIR . '/' . $slug . '.' . $ext;
    if (!move_uploaded_file($tmp, $dest)) {
        respond(['error' => 'Could not save photo.'], 500);
    }
}

function enrichDentistRow($row) {
    $row['photo_url'] = dentistPhotoPublicUrl($row['id']);
    return $row;
}

switch ($method) {
    case 'GET':
        $singleId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($singleId) {
            $stmt = $db->prepare('SELECT * FROM dentists WHERE id = ?');
            $stmt->bind_param('i', $singleId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                respond(['error' => 'Not found'], 404);
            }
            $sid = intval($singleId);
            $cq = $db->prepare(
                'SELECT COUNT(*) AS total_appointments,
                    SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) AS today_appointments,
                    COUNT(DISTINCT patient_id) AS total_patients
                 FROM appointments WHERE dentist_id = ?'
            );
            $cq->bind_param('i', $sid);
            $cq->execute();
            $counts = $cq->get_result()->fetch_assoc();
            $row['total_appointments'] = (int) ($counts['total_appointments'] ?? 0);
            $row['today_appointments']   = (int) ($counts['today_appointments'] ?? 0);
            $row['total_patients']       = (int) ($counts['total_patients'] ?? 0);
            respond(enrichDentistRow($row));
        }

        $result = $db->query(
            "SELECT d.*,
                COUNT(a.id)                                                AS total_appointments,
                COUNT(CASE WHEN a.appointment_date = CURDATE() THEN 1 END) AS today_appointments,
                COUNT(DISTINCT a.patient_id)                               AS total_patients
             FROM dentists d
             LEFT JOIN appointments a ON d.id = a.dentist_id
             GROUP BY d.id
             ORDER BY d.name"
        );
        $dentists = [];
        while ($row = $result->fetch_assoc()) {
            $dentists[] = enrichDentistRow($row);
        }
        respond($dentists);
        break;

    case 'POST':
        // Multipart updates MUST use POST (not PUT): PHP does not populate $_POST for multipart/form-data on PUT.
        $updateId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $name = '';
        $spec = '';
        $email = '';
        $phone = '';
        $status = 'active';
        $removePhoto = false;

        if (stripos($contentType, 'multipart/form-data') !== false) {
            if (empty($_POST) && !empty($_FILES)) {
                respond(['error' => 'Form data was not received (often PHP post_max_size too small for the image). Increase post_max_size and upload_max_filesize in php.ini.'], 400);
            }
            $name   = trim($_POST['name'] ?? '');
            $spec   = trim($_POST['specialization'] ?? '');
            $email  = trim($_POST['email'] ?? '');
            $phone  = trim($_POST['phone'] ?? '');
            $status = trim($_POST['status'] ?? 'active');
            $removePhoto = in_array(strtolower(trim((string) ($_POST['remove_photo'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
        } else {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $name   = trim($body['name'] ?? '');
            $spec   = trim($body['specialization'] ?? '');
            $email  = trim($body['email'] ?? '');
            $phone  = trim($body['phone'] ?? '');
            $status = trim($body['status'] ?? 'active');
            $removePhoto = in_array(strtolower(trim((string) ($body['remove_photo'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
            if ($updateId <= 0) {
                $updateId = intval($body['id'] ?? 0);
            }
        }

        if ($name === '') {
            respond(['error' => 'Name is required'], 400);
        }

        if ($updateId > 0) {
            $stmt = $db->prepare('UPDATE dentists SET name=?, specialization=?, email=?, phone=?, status=? WHERE id=?');
            $stmt->bind_param('sssssi', $name, $spec, $email, $phone, $status, $updateId);
            if (!$stmt->execute()) {
                respond(['error' => $db->error], 500);
            }
            if ($removePhoto) {
                removeAllDentistPhotos($updateId);
            }
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                saveDentistPhotoFromUpload($updateId);
            }
            respond(['success' => true, 'id' => $updateId]);
            break;
        }

        $stmt = $db->prepare('INSERT INTO dentists (name, specialization, email, phone) VALUES (?,?,?,?)');
        $stmt->bind_param('ssss', $name, $spec, $email, $phone);
        if (!$stmt->execute()) {
            respond(['error' => $db->error], 500);
        }
        $newId = $db->insert_id;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            saveDentistPhotoFromUpload($newId);
        }
        respond(['success' => true, 'id' => $newId], 201);
        break;

    case 'PUT':
        // JSON-only updates. Multipart + file: use POST ?id= or POST field id= (PHP does not fill $_POST on PUT multipart).
        $id = intval($_GET['id'] ?? 0);
        if (!$id) {
            respond(['error' => 'ID required'], 400);
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'multipart/form-data') !== false) {
            respond(['error' => 'Use POST with ?id= for updates that include a file upload.'], 415);
        }

        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $name   = trim($body['name'] ?? '');
        $spec   = trim($body['specialization'] ?? '');
        $email  = trim($body['email'] ?? '');
        $phone  = trim($body['phone'] ?? '');
        $status = trim($body['status'] ?? 'active');

        if ($name === '') {
            respond(['error' => 'Name is required'], 400);
        }

        $stmt = $db->prepare('UPDATE dentists SET name=?, specialization=?, email=?, phone=?, status=? WHERE id=?');
        $stmt->bind_param('sssssi', $name, $spec, $email, $phone, $status, $id);
        if (!$stmt->execute()) {
            respond(['error' => $db->error], 500);
        }
        respond(['success' => true]);
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) {
            respond(['error' => 'ID required'], 400);
        }
        $stmt = $db->prepare('DELETE FROM dentists WHERE id=?');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            respond(['error' => $db->error], 500);
        }
        removeAllDentistPhotos($id);
        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
