<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {

    $type = $_POST['type'] ?? '';
    $filename = basename($_POST['image'] ?? '');  // Sanitize: strip path components
    $slug = basename($_POST['album'] ?? '');      // Sanitize: strip path components

    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF');
    }

    // Validate inputs
    if (empty($filename) || empty($slug)) {
        http_response_code(400);
        exit('Bad Request');
    }

    // Build and validate file path
    $file_path = PUBLIC_ROOT . "/$slug/$filename";
    $real_path = realpath($file_path);
    $allowed_dir = realpath(PUBLIC_ROOT);

    // Security: Ensure file is within allowed directory
    if (!$real_path || !$allowed_dir || strpos($real_path, $allowed_dir) !== 0) {
        logEvent('alert', 'download', 'Path traversal attempt', [
            'filename' => $filename,
            'slug' => $slug
        ]);
        http_response_code(404);
        exit('Not Found');
    }

    // Log via logEvent()
    logEvent($type, 'download', 'image', [
        'filename' => $filename,
        'slug' => $slug
    ]);

    // --- SERVE FILE ---
    $mime = mime_content_type($real_path) ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($real_path));
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');

    readfile($real_path);
    exit;
} else {
    logEvent('alert', 'download', 'Invalid access', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    http_response_code(405);
    exit('Method Not Allowed');
}