<?php
require 'config.php';
$slug = sanitize_path($_GET['s'] ?? '');
$uuid = sanitize_path($_GET['u'] ?? '');
// Validate inputs
if ($slug === '' && $uuid === '') {
  http_response_code(404);
  die('Album not found');
}
if ($slug === '' && strlen($uuid) !== 64) {
  http_response_code(404);
  die('Album not found');
}
if ($uuid === '' && strlen($slug) >= 101) {
  http_response_code(404);
  die('Album not found');
}
// Prioritize slug if both provided
if ($slug !== '') {
  $uuid = '';
}

// Validate album
$stmt = $mysqli->prepare("SELECT id, name, is_public, slug FROM albums WHERE slug = ? OR uuid = ?");
$stmt->bind_param('ss', $slug, $uuid);
$stmt->execute();
$album = $stmt->get_result()->fetch_assoc();
if (!$album) die('Album not found');

$album_id = $album['id'];
$name = $album['name'];
$slug = sanitize_path($album['slug']);

// Log view
logEvent('view', 'album', 'path:' . $slug, ['id' => $album_id]);

// Get images
$images = [];
$stmt = $mysqli->prepare("SELECT id, filename, title FROM images WHERE album_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $album_id);
$stmt->execute();
$res = $stmt->get_result();
while ($img = $res->fetch_assoc()) {
  $full = BASE_URL . "/images/public/$slug/{$img['filename']}";
  $thumb = BASE_URL . "/images/thumbnails/$slug/{$img['filename']}";
  $images[] = ['full' => $full, 'thumb' => $thumb, 'title' => $img['title'], 'id' => $img['id'], 'slug' => $slug, 'public' => $album['is_public']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="assets/favicon.ico">
  <title>SpaceCan <?= htmlspecialchars($name) ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="container">
    <a href="index.php" class="back-link">All Albums</a>
    <h1><?= htmlspecialchars($name) ?></h1>
    <div class="gallery">
      <?php foreach ($images as $i):
        $public = $i['public'];
        $link = $public ? "view.php?slug={$i['slug']}&id={$i['id']}" : "view.php?u={$uuid}&id={$i['id']}";
      ?>
        <img src="<?= $i['thumb'] ?>" alt="<?= htmlspecialchars($i['title']) ?>" onclick="location.href='<?= $link ?>'" loading="lazy">
      <?php endforeach; ?>
    </div>
  </div>
</body>
</html>