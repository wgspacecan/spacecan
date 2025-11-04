<?php
session_start();
require 'config.php';

// Get image ID
$slug = sanitize_path($_GET['slug'] ?? '');
$uuid = sanitize_path($_GET['u'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if ($uuid != '') {
    $slug = '';
}

if ($id <= 0) {
    http_response_code(404);
    logEvent('alert', 'view', 'Image not found - invalid ID', ['image_id' => $id, 'slug' => $slug]);
    die('Image not found');
}

if ($slug === '' && $uuid === '') {
    http_response_code(404);
    logEvent('alert', 'view', 'Image not found - no slug/uuid', ['image_id' => $id]);
    die('Image not found');
}

// Fetch image + album
$stmt = $mysqli->prepare("
    SELECT i.id, i.filename, i.title, i.album_id, a.slug, a.is_public, a.uuid
    FROM images i 
    JOIN albums a ON i.album_id = a.id 
    WHERE i.id = ?
    AND (a.slug = ? OR a.uuid = ?)
");
$stmt->bind_param("iss", $id, $slug, $uuid);
$stmt->execute();
$img = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$img) {
    http_response_code(404);
    logEvent('alert', 'view', 'Image not found', ['image_id' => $id, 'slug' => $slug]);
    die('Image not found');
}

if (!$img['is_public']) {
    if ($uuid != $img['uuid']) {
        http_response_code(403);
        logEvent('alert', 'view', 'Image access denied - private album', ['image_id' => $id, 'slug' => $slug]);
        die('Image not found');
    }
}

// Log view
logEvent('view', 'image', 'path:' . $img['filename'], ['id' => $id]);

// Build paths
$folder = $img['slug'];
$full_path = PUBLIC_ROOT . "/$folder/{$img['filename']}";
$web_path =  BASE_URL . "/images/public/$folder/{$img['filename']}";

// File info
$size = filesize($full_path);
$date = date("Y-m-d H:i", filemtime($full_path));

// EXIF (optional)
$exif = @exif_read_data($full_path);
$camera = $exif['Model'] ?? 'Unknown';
$focal = $exif['FocalLength'] ?? '';
$aperture = $exif['COMPUTED']['ApertureFNumber'] ?? '';
$iso = $exif['ISOSpeedRatings'] ?? '';

// Get next/prev
$stmt = $mysqli->prepare("
    SELECT id FROM images 
    WHERE album_id = ? AND id > ? 
    ORDER BY id ASC LIMIT 1
");
$stmt->bind_param("ii", $img['album_id'], $id);
$stmt->execute();
$next = $stmt->get_result()->fetch_assoc()['id'] ?? null;
$stmt->close();

$stmt = $mysqli->prepare("
    SELECT id FROM images 
    WHERE album_id = ? AND id < ? 
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("ii", $img['album_id'], $id);
$stmt->execute();
$prev = $stmt->get_result()->fetch_assoc()['id'] ?? null;
$stmt->close();

function formatBytes($bytes) {
  $units = ['B', 'KB', 'MB', 'GB'];
  $i = 0;
  while ($bytes >= 1024 && $i < count($units)-1) {
      $bytes /= 1024;
      $i++;
  }
  return round($bytes, 1) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="language" content="en">
  <meta http-equiv="content-language" content="en">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="assets/favicon.ico">
  <title>SpaceCan <?= htmlspecialchars($img['title'] ?: $img['filename']) ?></title>
  <link rel="stylesheet" href="style.css">
  <style>
    body { margin:0; font-family:Arial; background:#f4f4f4; }
    .container { max-width:1200px; margin:2rem auto; padding:1rem; background:white; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
    .image-container { text-align:center; position:relative; margin-bottom:1rem; }
    .image-container img { max-width:100%; max-height:70vh; border:2px solid #ddd; }
    .metadata { margin:1rem 0; padding:1rem; background:#f9f9f9; border:1px solid #eee; line-height:1.6; }
    .actions { text-align:center; margin:1rem 0; }
    .actions a { display:inline-block; padding:0.7rem 1.5rem; background:#0066cc; color:white; text-decoration:none; border-radius:4px; }
    .back { display:block; margin-top:1rem; color:#666; }
    .nav-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(0,0,0,0.6);
      color: white;
      border: none;
      width: 50px;
      height: 50px;
      font-size: 2rem;
      cursor: pointer;
      border-radius: 50%;
      text-decoration: none;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .prev-btn { left: 10px; }
    .next-btn { right: 10px; }

    .image-container {
      flex: 2;
      min-width: 300px;
      height: 70vh;
      position: relative;
      overflow: hidden; /* ← KEY */
      width: 100%;
      max-width: 100%;
    }
    #openseadragon {
      width: 100% !important;
      height: 100% !important;
    }

    @media (max-width: 768px) {
    .image-container {
      flex: 1 1 100% !important;
      min-width: 100% !important;
      height: 60vh !important;
      padding: 0 5px;
    }
    #openseadragon {
      width: 100% !important;
      height: 100% !important;
    }
  }
  </style>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/openseadragon@4.1.0/build/openseadragon/openseadragon.min.css">
  <script src="https://cdn.jsdelivr.net/npm/openseadragon@4.1.0/build/openseadragon/openseadragon.min.js"></script>
</head>
<body>
  
<div class="container" style="max-width:1400px; margin:1rem auto; padding:1rem; background:white; box-shadow:0 2px 10px rgba(0,0,0,0.1); display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap;">
  
  <!-- Image: 70% -->
  <div class="image-container" style="flex:2; min-width:400px; height:70vh; position:relative;">
    <div id="openseadragon" style="width:100%; height:100%; background:#000;"></div>

    <?php if ($prev): ?>
      <a href="view.php?slug=<?= $slug ?>&id=<?= $prev ?>" class="nav-btn prev-btn">←</a>
    <?php endif; ?>
    <?php if ($next): ?>
      <a href="view.php?slug=<?= $slug ?>&id=<?= $next ?>" class="nav-btn next-btn">→</a>
    <?php endif; ?>
  </div>

  <!-- Sidebar: 30% (Metadata + Actions) -->
  <div class="sidebar" style="flex:1; min-width:280px;">
    
    <!-- Metadata -->
    <div class="metadata" style="margin-bottom:1rem; padding:1rem; background:#f9f9f9; border:1px solid #eee; line-height:1.6; font-size:0.9rem;">
      <strong>Title:</strong> <?= htmlspecialchars($img['title'] ?: pathinfo($img['filename'], PATHINFO_FILENAME)) ?><br>
      <strong>Size:</strong> <?= formatBytes($size) ?><br>
      <?php if ($camera !== 'Unknown'): ?><strong>Camera:</strong> <?= htmlspecialchars($camera) ?><br><?php endif; ?>
      <?php if ($focal): ?><strong>Focal:</strong> <?= htmlspecialchars($focal) ?><br><?php endif; ?>
      <?php if ($aperture): ?><strong>Aperture:</strong> <?= htmlspecialchars($aperture) ?><br><?php endif; ?>
      <?php if ($iso): ?><strong>ISO:</strong> <?= $iso ?><br><?php endif; ?>
    </div>

    <!-- Actions -->
    <div class="actions" style="text-align:center; margin-bottom:1rem;">
      <form method="POST" action="/download.php" style="display:inline;">
          <input type="hidden" name="type" value="info">
          <input type="hidden" name="action" value="download">
          <input type="hidden" name="image" value="<?= $img['filename'] ?>">
          <input type="hidden" name="album" value="<?= $img['slug'] ?>">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
          <button type="submit" 
                  style="padding:0.7rem 1.5rem; background:#0066cc; color:white; border:none; border-radius:4px; cursor:pointer; font-size:1rem;">
              Download
          </button>
      </form>
    </div>

    <!-- Back Link -->
    <div style="text-align:center;">
      <a href="album.php?s=<?= $img['slug'] ?>" style="color:#666;text-decoration:none;">Album</a>
      <a href="index.php" style="margin-left:15px;color:#666;text-decoration:none;">Home</a>
    </div>
  </div>
</div>

<script>
  // Keyboard navigation
  document.addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft' && document.querySelector('.prev-btn')) {
      location.href = document.querySelector('.prev-btn').href;
    }
    if (e.key === 'ArrowRight' && document.querySelector('.next-btn')) {
      location.href = document.querySelector('.next-btn').href;
    }
    if (e.key === 'Escape') {
      history.back();
    }
  });
</script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    OpenSeadragon({
      id: "openseadragon",
      prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@4.1.0/build/openseadragon/images/",
      tileSources: {
        type: 'image',
        url:  '<?= $web_path ?>'
      },
      gestureSettingsMouse: {
        scrollToZoom: true,
        clickToZoom: false,
        dblClickToZoom: true
      },
      gestureSettingsTouch: {
        pinchToZoom: true
      },
      showNavigationControl: true,
      constrainDuringPan: true,
      animationTime: 0.5,
      blendTime: 0.1,
      maxZoomPixelRatio: 5,
      defaultZoomLevel: 0,
      minZoomImageRatio: 0.5,
      visibilityRatio: 0.5
    });
  });
</script>
</body>
</html>