<?php
session_start();
require 'config.php';

if (!$_SESSION['admin']) {
    logEvent('AUTH', 'upload', 'No admin session - unauthorized');
    exit('Unauthorized');
}

$token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    logEvent('AUTH', 'upload', 'CSRF invalid - unauthorized');
    exit('CSRF invalid');
}

// --- Get parameters ---
$album_id = (int)($_POST['album_id'] ?? 0);
$chunk_index = (int)($_POST['chunk_index'] ?? 0);
$total_chunks = (int)($_POST['total_chunks'] ?? 0);
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $_POST['filename'] ?? '');
$upload_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id'] ?? '');

// --- Validations ---
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg'];

if (!in_array($ext, $allowed)) {
    logEvent('ERROR', 'upload', 'Invalid file extension', ['ext' => $ext]);
    exit('Invalid file type');
}
if ($album_id <= 0) {
    logEvent('ERROR', 'upload', 'Invalid params', ['album_id' => $album_id, 'filename' => $filename, 'total_chunks' => $total_chunks, 'chunk_index' => $chunk_index, 'upload_id' => $upload_id]);
    exit('Invalid album_id');
}
if (!$filename) {
    logEvent('ERROR', 'upload', 'Empty filename');
    exit('Invalid filename');
}
if ($total_chunks < 0) {
    logEvent('ERROR', 'upload', 'Invalid total_chunks', ['total_chunks' => $total_chunks]);
    exit('Invalid total_chunks');
}
if ($check_index < 0 || $chunk_index >= $total_chunks) {
    logEvent('ERROR', 'upload', 'Invalid chunk_index', ['chunk_index' => $chunk_index, 'total_chunks' => $total_chunks]);
    exit('Invalid chunk_index');
}
if (!$upload_id) {
    logEvent('ERROR', 'upload', 'Empty upload_id');
    exit('Invalid upload_id');
}

// --- Validate album ---
$stmt = $mysqli->prepare("SELECT slug FROM albums WHERE id = ?");
$stmt->bind_param("i", $album_id);
$stmt->execute();
$album = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$album) {
    logEvent('ERROR', 'upload', 'Album not found', ['album_id' => $album_id]);
    exit('Album not found');
}

// --- Prepare paths ---
$slug = sanitize_path($album['slug']);
$target_dir = rtrim(PUBLIC_ROOT, '/') . "/$slug/";
$chunk_dir = $target_dir . ".chunks/";

// --- Ensure directories exist ---
if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
if (!is_dir($chunk_dir)) mkdir($chunk_dir, 0755, true);

// --- Save chunk ---
$chunk_path = $chunk_dir . "$upload_id.$chunk_index";
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
    logEvent('ERROR', 'upload', 'Failed to save chunk', ['chunk_path' => $chunk_path]);
    exit('Chunk save failed');
}

// --- Finalize on last chunk ---
if ($chunk_index == $total_chunks - 1) {
    $final_path = $target_dir . $filename;
    
    // Combine chunks
    $fp = fopen($final_path, 'wb');
    for ($i = 0; $i < $total_chunks; $i++) {
        $chunk_file = $chunk_dir . "$upload_id.$i";
        if (!file_exists($chunk_file)) {
            fclose($fp); @unlink($final_path);
            logEvent('ERROR', 'upload', 'Missing chunk during assembly', ['chunk_file' => $chunk_file]);
            exit('Missing chunk');
        }
        fwrite($fp, file_get_contents($chunk_file));
        @unlink($chunk_file);
    }
    fclose($fp);
    deleteDirectory($chunk_dir);

    // Server side MIME validation
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $final_path);
    finfo_close($finfo);

    $allowed = ['image/jpeg'];
    if (!in_array($mime, $allowed)) {
        unlink($final_path);
        logEvent('ERROR', 'upload', 'Invalid MIME type', ['mime' => $mime]);
        die('Invalid image type');
    }
    // Image size validation
    if (getimagesize($final_path) === false) {
        unlink($final_path);
        logEvent('ERROR', 'upload', 'File is not a valid image size after upload');
        die('Not a valid image');
    }

    // Generate thumbnail
    $thumb_dir = rtrim(THUMB_ROOT, '/') . "/$slug/";
    if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);
    $thumb = $thumb_dir . $filename;

    $cmd = "magick " . escapeshellarg($final_path) . " -resize 400x -quality 95 -strip -interlace JPEG " . escapeshellarg($thumb);
    exec($cmd, $output, $return_code);
    if ($return_code !== 0) {
        @unlink($final_path);
        logEvent('ERROR', 'upload', 'Thumbnail generation failed', ['cmd' => $cmd, 'output' => $output]);
        exit('Thumbnail failed');
    }

    // DB insert
    $title = pathinfo($filename, PATHINFO_FILENAME);
    $stmt = $mysqli->prepare("INSERT INTO images (album_id, filename, title) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $album_id, $filename, $title);
    if (!$stmt->execute()) {
        @unlink($final_path);
        @unlink($thumb);
        logEvent('ERROR', 'upload', 'DB insert failed', ['final_path' => $final_path, 'error' => $stmt->error]);
        exit('DB insert failed');
    }
    $stmt->close();

    // Log success
    logEvent('info', 'upload', 'File uploaded successfully', ['album_id' => $album_id, 'filename' => $filename]);
}

echo 'OK';  // ← AJAX response
exit;
?>