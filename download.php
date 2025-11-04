<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {

    $type = $_POST['type'] ?? '';
    $filename = ($_POST['image'] ?? 0);
    $slug = ($_POST['album'] ?? 0);

    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF');
    }

    // Log via logEvent()
    logEvent($type, 'download', 'image', [
        'filename' => $filename,
        'slug' => $slug
    ]);

    // --- SERVE FILE ---
    $file_path = PUBLIC_ROOT . "/$slug/$filename";
    $filename = basename($file_path);
    $mime = mime_content_type($file_path) ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');

    readfile($file_path);
    exit;
} else {
    logEvent('alert', 'download', 'Invalid access', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    http_response_code(405);
    exit('Method Not Allowed');
}