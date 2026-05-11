<?php
// Load environment config (DB credentials, APP_ENV, error reporting)
$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    require_once $configPath;
}
// Fallback defaults if config.php is missing (backward compatibility)
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'edroso_dental');
if (!defined('APP_ENV')) define('APP_ENV', 'development');

// Session strict mode (avoid relying on .htaccess php_value — not valid on PHP-FPM)
ini_set('session.use_strict_mode', '1');

// Start session BEFORE any output or headers
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// JSON + CORS for API scripts (backup/download scripts set EDROSO_SKIP_JSON_HEADERS)
if (!defined('EDROSO_SKIP_JSON_HEADERS') || !EDROSO_SKIP_JSON_HEADERS) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
    $host    = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST'])) : '';
    $originHost = $origin !== '' ? parse_url($origin, PHP_URL_HOST) : null;
    if ($originHost) {
        $originHost = strtolower((string) $originHost);
    }
    if ($origin !== '' && $host !== '' && $originHost !== null && $originHost === $host) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function getDB() {
    static $conn = null;
    if ($conn && !$conn->connect_error) return $conn;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(
            ['error' => 'Database connection failed: ' . $conn->connect_error],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function respond($data, $code = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $flags = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        $json = json_encode(
            ['error' => 'Server could not encode JSON response.'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
    echo $json;
    exit;
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'Unauthorized. Please log in.', 'redirect' => 'login.html'], 401);
    }
}

/** Normalized staff/admin role from session (defaults to admin for legacy rows). */
function sessionUserRole(): string {
    $r = strtolower(trim((string) ($_SESSION['role'] ?? 'admin')));
    return $r === 'staff' ? 'staff' : 'admin';
}

function requireAdminSession(): void {
    requireAuth();
    if (sessionUserRole() !== 'admin') {
        respond(['error' => 'This action requires an administrator.', 'code' => 'admin_only'], 403);
    }
}
