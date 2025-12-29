<?php

// === Secure session settings (must be before session_start) ===
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 1800); // 30 min server-side
    session_start();
}

// === Session timeout (30 min inactivity) ===
define('SESSION_TIMEOUT', 1800);
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// === Error handling ===
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/errors.log');

include 'functions.php';

// === Load password securely ===
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    foreach (parse_ini_file($env_file, false, INI_SCANNER_RAW) as $key => $val) {
        putenv("$key=$val");
    }
}
$DB_PASS = getenv('IMGUSER_DB_PASS');
if (!$DB_PASS) {
    logEvent('ERROR', 'database', 'msg: missing DB password');
    die('Missing DB password');
}

// === Constants ===
define('DB_HOST', 'localhost');
define('DB_USER', 'imguser');
define('DB_NAME', 'image_viewer');
define('BASE_URL', 'https://spacecan.club');
define('IMG_ROOT', __DIR__ . '/images');
define('THUMB_ROOT', IMG_ROOT . '/thumbnails');
define('PUBLIC_ROOT', IMG_ROOT . '/public');
define('LOG_FILE', __DIR__ . '/logs/access.log');

// === Secure DB connection ===
$mysqli = new mysqli(DB_HOST, DB_USER, $DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    logEvent('ERROR', 'database', 'msg:' . $mysqli->connect_error);
    http_response_code(500);
    die('Service unavailable');
}
$mysqli->set_charset('utf8mb4');

// === Security headers ===
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob: https://cdn.jsdelivr.net; font-src 'self'; connect-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header("Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()");

?>