<?php
/**
 * Edroso Dental Clinic — Environment Configuration
 *
 * Local development keeps the XAMPP defaults. Production values are read
 * from environment variables (Railway MySQL variables are supported).
 */

$appEnv = getenv('APP_ENV') ?: 'development';
if (!defined('APP_ENV')) {
    define('APP_ENV', $appEnv);
}

// Database: Railway's MySQL service exposes MYSQLHOST/MYSQLPORT/etc.
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: 'localhost'));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', (int) (getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306)));
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'edroso_dental'));
}

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Destructive/test-only operations are disabled in production.
if (!defined('ALLOW_DESTRUCTIVE_OPS')) {
    define('ALLOW_DESTRUCTIVE_OPS', APP_ENV !== 'production');
}
