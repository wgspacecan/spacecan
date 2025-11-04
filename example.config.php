<?php

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
define('DB_USER', 'YOUR_DB_USER_HERE');
define('DB_NAME', 'image_viewer');
define('BASE_URL', 'YOUR_BASE_URL_HERE');
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

?>