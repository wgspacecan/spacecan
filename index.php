<?php
  require 'config.php';
  
  // Log view
  logEvent('view', 'home');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="assets/favicon.ico">
  <title>SpaceCan</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="container">
    <h1>Photo Albums <a class='back_button' href="admin.php" ">Admin</a></h1>
    <div class="album-grid">
    <?php
      $res = $mysqli->query("SELECT id, name, slug, thumbnail FROM albums WHERE is_public = 1 ORDER BY name");
      while ($row = $res->fetch_assoc()) {
          $album_id = $row['id'];
          $slug = sanitize_path($row['slug']);
          $thumbnail_id = (int)$row['thumbnail'];

          // === Get thumbnail from DB by ID ===
          $thumb_url = '';
          if ($thumbnail_id > 0) {
              $stmt = $mysqli->prepare("SELECT filename FROM images WHERE id = ? AND album_id = ?");
              $stmt->bind_param("ii", $thumbnail_id, $album_id);
              $stmt->execute();
              $img = $stmt->get_result()->fetch_assoc();
              $stmt->close();

              if ($img) {
                  $thumb_url = BASE_URL . '/images/thumbnails/' . $slug . '/' . $img['filename'];
              }
          }

          // === Fallback: first image by DB order ===
          if (!$thumb_url) {
              $stmt = $mysqli->prepare("SELECT filename FROM images WHERE album_id = ? ORDER BY id ASC LIMIT 1");
              $stmt->bind_param("i", $album_id);
              $stmt->execute();
              $img = $stmt->get_result()->fetch_assoc();
              $stmt->close();

              if ($img) {
                  $thumb_url = BASE_URL . '/images/thumbnails/' . $slug . '/' . $img['filename'];
              }
          }

          $safe_slug = htmlspecialchars($row['slug'], ENT_QUOTES, 'UTF-8');
          $safe_thumb = htmlspecialchars($thumb_url, ENT_QUOTES, 'UTF-8');
          echo "<a href='album.php?s={$safe_slug}' class='album-card'>
                  <img src='{$safe_thumb}' loading='lazy' onerror=\"this.src='assets/placeholder.JPG'\">
                  <span>" . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . "</span>
                </a>";
      }
      ?>
    </div>
  </div>
</body>
</html>