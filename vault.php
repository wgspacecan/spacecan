<?php
// === Secure session settings (must be before session_start) ===
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// === Load environment ===
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    foreach (parse_ini_file($env_file, false, INI_SCANNER_RAW) as $key => $val) {
        putenv("$key=$val");
    }
}

$VAULT_KEY = getenv('VAULT_SECRET_KEY');
$VAULT_PASS = getenv('VAULT_PASSWORD');
$VAULT_DIR = __DIR__ . '/vault';
$THUMB_DIR = $VAULT_DIR . '/thumbs';

// Include shared logging function
require_once __DIR__ . '/functions.php';

// === Security headers ===
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

// === Rate limiting ===
define('VAULT_RATE_FILE', __DIR__ . '/logs/vault_rate.json');
define('VAULT_MAX_ATTEMPTS', 5);
define('VAULT_LOCKOUT', 900);

function isVaultLocked($ip) {
    if (!file_exists(VAULT_RATE_FILE)) return false;
    $data = json_decode(file_get_contents(VAULT_RATE_FILE), true) ?: [];
    if (!isset($data[$ip])) return false;
    $recent = array_filter($data[$ip]['attempts'] ?? [], fn($t) => time() - $t < VAULT_LOCKOUT);
    return count($recent) >= VAULT_MAX_ATTEMPTS;
}

function recordVaultAttempt($ip) {
    $data = file_exists(VAULT_RATE_FILE) ? json_decode(file_get_contents(VAULT_RATE_FILE), true) ?: [] : [];
    $data[$ip]['attempts'] = array_filter($data[$ip]['attempts'] ?? [], fn($t) => time() - $t < VAULT_LOCKOUT);
    $data[$ip]['attempts'][] = time();
    file_put_contents(VAULT_RATE_FILE, json_encode($data), LOCK_EX);
}

function clearVaultAttempts($ip) {
    $data = file_exists(VAULT_RATE_FILE) ? json_decode(file_get_contents(VAULT_RATE_FILE), true) ?: [] : [];
    unset($data[$ip]);
    file_put_contents(VAULT_RATE_FILE, json_encode($data), LOCK_EX);
}


// === Helper: human-readable file size ===
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// === Helper: generate thumbnail with ffmpeg ===
function generateThumbnail($videoPath, $thumbPath) {
    $cmd = sprintf(
        'ffmpeg -y -i %s -ss 00:00:05 -vframes 1 -vf "scale=120:-1" -q:v 3 -update 1 %s 2>&1',
        escapeshellarg($videoPath),
        escapeshellarg($thumbPath)
    );
    exec($cmd, $output, $returnCode);
    return $returnCode === 0 && file_exists($thumbPath);
}

// === Verify secret key ===
$providedKey = $_GET['key'] ?? '';
if (!hash_equals($VAULT_KEY, $providedKey)) {
    logEvent('alert', 'vault', 'invalid key attempt', ['key' => substr($providedKey, 0, 8) . '...']);
    http_response_code(404);
    exit('Not Found');
}

// === CSRF token ===
if (empty($_SESSION['vault_csrf'])) {
    $_SESSION['vault_csrf'] = bin2hex(random_bytes(32));
}

// === Handle logout ===
if (isset($_GET['logout'])) {
    unset($_SESSION['vault_auth']);
    header('Location: vault.php?key=' . urlencode($providedKey));
    exit;
}

// === Handle thumbnail request ===
if (isset($_GET['thumb']) && !empty($_SESSION['vault_auth'])) {
    $filename = basename($_GET['thumb']);
    $videoPath = $VAULT_DIR . '/' . $filename;
    $thumbName = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    $thumbPath = $THUMB_DIR . '/' . $thumbName;

    if (!file_exists($videoPath)) {
        http_response_code(404);
        exit;
    }

    // Generate thumbnail if missing
    if (!file_exists($thumbPath)) {
        if (!generateThumbnail($videoPath, $thumbPath)) {
            http_response_code(500);
            exit;
        }
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($thumbPath);
    exit;
}

// === Handle video stream (serves 1080p from stream/ folder) ===
if (isset($_GET['stream']) && !empty($_SESSION['vault_auth'])) {
    $filename = basename($_GET['stream']);
    $streamPath = $VAULT_DIR . '/stream/' . $filename;
    $originalPath = $VAULT_DIR . '/' . $filename;

    // Check if 1080p version exists, fall back to original
    if (file_exists($streamPath)) {
        $servePath = '/internal-vault/stream/' . rawurlencode($filename);
    } elseif (file_exists($originalPath)) {
        $servePath = '/internal-vault/' . rawurlencode($filename);
    } else {
        http_response_code(404);
        exit('File not found');
    }

    logEvent('view', 'vault', 'stream', ['file' => $filename]);
    session_write_close();

    // Use X-Accel-Redirect to let Nginx serve the file directly
    header('Content-Type: video/mp4');
    header('X-Accel-Redirect: ' . $servePath);
    exit;
}

// === Handle download ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    if (!hash_equals($_SESSION['vault_csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF');
    }
    if (empty($_SESSION['vault_auth'])) {
        http_response_code(403);
        exit('Not authenticated');
    }

    $filename = basename($_POST['file'] ?? '');
    $filepath = $VAULT_DIR . '/' . $filename;

    if (!$filename || !file_exists($filepath)) {
        http_response_code(404);
        exit('File not found');
    }

    logEvent('download', 'vault', 'file downloaded', ['file' => $filename]);
    session_write_close();

    $filesize = filesize($filepath);
    $mime = mime_content_type($filepath) ?: 'application/octet-stream';

    // Range request support
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range) {
        preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
        $start = intval($matches[1]);
        $end = $matches[2] !== '' ? intval($matches[2]) : $filesize - 1;

        if ($start > $end || $start >= $filesize) {
            http_response_code(416);
            header("Content-Range: bytes */$filesize");
            exit;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$filesize");
        header("Content-Length: $length");
    } else {
        $start = 0;
        $length = $filesize;
        header("Content-Length: $filesize");
    }

    header("Content-Type: $mime");
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-store');

    $fp = fopen($filepath, 'rb');
    fseek($fp, $start);
    $sent = 0;
    while (!feof($fp) && $sent < $length) {
        $chunk = min(8192, $length - $sent);
        echo fread($fp, $chunk);
        $sent += $chunk;
        flush();
    }
    fclose($fp);
    exit;
}

// === Handle password login ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (isVaultLocked($clientIP)) {
        logEvent('auth', 'vault', 'login blocked - rate limited');
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif (!hash_equals($_SESSION['vault_csrf'], $_POST['csrf'] ?? '')) {
        recordVaultAttempt($clientIP);
        $error = 'Invalid request';
    } elseif (hash_equals($VAULT_PASS, $_POST['password'] ?? '')) {
        clearVaultAttempts($clientIP);
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['vault_auth'] = true;
        logEvent('auth', 'vault', 'login success');
        header('Location: vault.php?key=' . urlencode($providedKey));
        exit;
    } else {
        recordVaultAttempt($clientIP);
        logEvent('auth', 'vault', 'login failed');
        $error = 'Incorrect password';
    }
}

// === Get file list (videos only) ===
$files = [];
$videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];
if (is_dir($VAULT_DIR)) {
    foreach (scandir($VAULT_DIR) as $f) {
        $path = $VAULT_DIR . '/' . $f;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($f[0] !== '.' && is_file($path) && in_array($ext, $videoExtensions)) {
            $has1080p = file_exists($VAULT_DIR . '/stream/' . $f);
            $files[] = [
                'name' => $f,
                'size' => filesize($path),
                'mtime' => filemtime($path),
                'has1080p' => $has1080p
            ];
        }
    }
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilates Vault</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .vault-table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        .vault-table th, .vault-table td { padding: 0.8rem; text-align: left; border-bottom: 1px solid #ddd; vertical-align: middle; }
        .vault-table th { background: #f5f5f5; font-weight: 600; }
        .vault-table tr:hover { background: #fafafa; }
        .vault-table .size { color: #666; white-space: nowrap; }
        .vault-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .vault-header h1 { margin: 0; }
        .logout-link { color: #666; text-decoration: none; }
        .empty-msg { color: #666; font-style: italic; margin-top: 2rem; }

        .thumb-cell { width: 70px; }
        .thumb-img { width: 60px; height: 40px; object-fit: cover; border-radius: 4px; background: #eee; }

        .actions { white-space: nowrap; }
        .btn { padding: 0.4rem 0.8rem; font-size: 0.9rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-play { background: #28a745; color: white; margin-right: 0.5rem; }
        .btn-play:hover { background: #218838; }
        .btn-play.no-stream { background: #dc3545; }
        .btn-play.no-stream:hover { background: #c82333; }
        .btn-dl { background: #0066cc; color: white; }
        .btn-dl:hover { background: #0055aa; }
        .quality-badge { font-size: 0.7rem; padding: 0.15rem 0.4rem; border-radius: 3px; margin-left: 0.5rem; font-weight: 600; }
        .quality-1080p { background: #28a745; color: white; }
        .quality-4k { background: #dc3545; color: white; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { position: relative; max-width: 90vw; max-height: 90vh; }
        .modal-close { position: absolute; top: -40px; right: 0; color: white; font-size: 2rem; cursor: pointer; background: none; border: none; padding: 0.5rem; }
        .modal-close:hover { color: #ccc; }
        .modal-video { max-width: 90vw; max-height: 80vh; background: #000; }
        .modal-info { color: white; margin-top: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 1rem; }
        .modal-dl { background: #0066cc; color: white; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .modal-dl:hover { background: #0055aa; }
    </style>
</head>
<body>
<div class="container">
<?php if (empty($_SESSION['vault_auth'])): ?>
    <div class="auth-container">
        <h2>Pilates Vault</h2>
        <?php if (!empty($error)): ?>
            <div class="auth-msg auth-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" class="auth-form">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf" value="<?= $_SESSION['vault_csrf'] ?>">
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autofocus>
            </div>
            <button type="submit">Enter</button>
        </form>
    </div>
<?php else: ?>
    <div class="vault-header">
        <h1>Pilates Vault</h1>
        <a href="?key=<?= urlencode($providedKey) ?>&logout=1" class="logout-link">Logout</a>
    </div>

    <?php if (empty($files)): ?>
        <p class="empty-msg">No files available.</p>
    <?php else: ?>
        <table class="vault-table">
            <thead>
                <tr>
                    <th class="thumb-cell"></th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($files as $file): ?>
                <tr>
                    <td class="thumb-cell">
                        <img src="?key=<?= urlencode($providedKey) ?>&thumb=<?= urlencode($file['name']) ?>"
                             alt="" class="thumb-img" loading="lazy">
                    </td>
                    <td><?= htmlspecialchars($file['name']) ?></td>
                    <td class="size"><?= formatBytes($file['size']) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-play<?= $file['has1080p'] ? '' : ' no-stream' ?>"
                                onclick="openPlayer('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>', <?= $file['has1080p'] ? 'true' : 'false' ?>)">
                            &#9658; Play
                        </button>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="download">
                            <input type="hidden" name="csrf" value="<?= $_SESSION['vault_csrf'] ?>">
                            <input type="hidden" name="file" value="<?= htmlspecialchars($file['name']) ?>">
                            <button type="submit" class="btn btn-dl">Download</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Video Modal -->
    <div id="videoModal" class="modal" onclick="closePlayer(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closePlayer()">&times;</button>
            <video id="modalVideo" class="modal-video" controls preload="auto"></video>
            <div class="modal-info">
                <span>
                    <span id="modalTitle" class="modal-title"></span>
                    <span id="modalQuality" class="quality-badge"></span>
                </span>
                <form method="POST" style="display:inline" id="modalDownloadForm">
                    <input type="hidden" name="action" value="download">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['vault_csrf'] ?>">
                    <input type="hidden" name="file" id="modalDownloadFile" value="">
                    <button type="submit" class="modal-dl">Download</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const key = <?= json_encode($providedKey) ?>;
        const modal = document.getElementById('videoModal');
        const video = document.getElementById('modalVideo');
        const title = document.getElementById('modalTitle');
        const quality = document.getElementById('modalQuality');
        const dlFile = document.getElementById('modalDownloadFile');

        function openPlayer(filename, has1080p) {
            video.src = 'vault.php?key=' + encodeURIComponent(key) + '&stream=' + encodeURIComponent(filename);
            title.textContent = filename;
            dlFile.value = filename;
            quality.textContent = has1080p ? '1080p' : '4K';
            quality.className = 'quality-badge ' + (has1080p ? 'quality-1080p' : 'quality-4k');
            modal.classList.add('active');
            video.play();
        }

        function closePlayer(e) {
            if (e && e.target !== modal) return;
            modal.classList.remove('active');
            video.pause();
            video.src = '';
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closePlayer();
        });
    </script>
<?php endif; ?>
</div>
</body>
</html>
