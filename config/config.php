<?php
/**
 * config/config.php
 * ------------------------------------------------------------------
 * Central application configuration.
 * Everything environment specific lives here so that moving the
 * project to another machine only requires editing this file.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// DATABASE CREDENTIALS  (XAMPP defaults)
// ---------------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'smart_waste_optimizer');
define('DB_USER', 'root');
define('DB_PASS', '');            // XAMPP root password is empty by default
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// APPLICATION SETTINGS
// ---------------------------------------------------------------------------
define('APP_NAME', 'Smart Waste Collection Optimizer');
define('APP_SHORT', 'SmartWaste');
define('APP_CITY', 'Mumbai, Maharashtra');

/**
 * Absolute URL of the project folder, e.g. /waste-php  (NO trailing slash).
 *
 * Calculated by comparing the physical project folder with DOCUMENT_ROOT so
 * it works no matter which sub-folder the current script lives in
 * (index.php, admin/dashboard.php, api/requests/create.php ...).
 */
$__docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\'));
$__appDir  = str_replace('\\', '/', dirname(__DIR__));   // <project>/config -> <project>

if ($__docRoot !== '' && str_starts_with($__appDir, $__docRoot)) {
    $__appUrl = rtrim(substr($__appDir, strlen($__docRoot)), '/');
} else {
    // CLI or a vhost without DOCUMENT_ROOT: derive from the script path.
    $__appUrl = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
}
define('APP_URL', $__appUrl === '.' ? '' : $__appUrl);

/** Where uploaded images are stored, relative to the project root */
define('UPLOAD_ROOT', 'uploads');

/** Maximum allowed upload size in bytes (2 MB) */
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);

/** Allowed image MIME types for uploads (MIME is verified, not the extension) */
define('ALLOWED_IMAGE_MIMES', ['image/jpeg', 'image/jpg', 'image/pjpeg']);

/** Number of days the hotspot engine looks back at */
define('HOTSPOT_WINDOW_DAYS', 30);

/** Radius in metres used to group reports into the same hotspot */
define('HOTSPOT_RADIUS_M', 700);

// ---------------------------------------------------------------------------
// ERROR HANDLING
// Set APP_DEBUG to false on a live server so warnings are never printed.
// ---------------------------------------------------------------------------
define('APP_DEBUG', true);

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

/** Default map centre (Mumbai) used by Leaflet before real data loads */
define('DEFAULT_MAP_LAT', 19.0760);
define('DEFAULT_MAP_LNG', 72.8777);
