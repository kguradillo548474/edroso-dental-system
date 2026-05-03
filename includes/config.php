<?php
/**
 * Edroso Dental Clinic — Environment Configuration
 *
 * Copy this file or edit directly. Protected from web access by includes/.htaccess.
 * Values here override all hardcoded defaults in db.php.
 */

// ── Environment ──────────────────────────────────────────────────────────
// 'development' or 'production'
define('APP_ENV', 'development');

// ── Database ─────────────────────────────────────────────────────────────
if (APP_ENV === 'production') {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'edroso_prod');
    define('DB_PASS', '');            // ← set a real password for production
    define('DB_NAME', 'edroso_dental');
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'edroso_dental');
}

// ── Error Reporting ──────────────────────────────────────────────────────
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// ── Security ─────────────────────────────────────────────────────────────
// Allow destructive operations (backup download, test scripts) only in dev
define('ALLOW_DESTRUCTIVE_OPS', APP_ENV !== 'production');
