<?php
require 'config.php';

// === LOGOUT ===
if ($_GET['logout'] ?? '') {
  logEvent('auth', 'admin', 'Logout', ['admin_id' => $_SESSION['admin'] ?? 0]);
  session_destroy();
  header('Location: admin.php');
  exit;
}

// === CSRF TOKEN ===
if (!isset($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// === AUTH ===
if (!isset($_SESSION['admin'])) {
  $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check rate limiting first
    if (isLoginLocked($clientIP)) {
      logEvent('auth', 'admin', 'Login blocked - rate limited', ['ip' => $clientIP]);
      $_SESSION['login_error'] = "Too many failed attempts. Try again in 15 minutes.";
    } elseif (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
      $username = 'admin';
      $password = $_POST['pass'] ?? '';
      $stmt = $mysqli->prepare("SELECT id, password FROM admins WHERE username = ?");
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $data = $stmt->get_result()->fetch_assoc();
      if ($data && password_verify($password, $data['password'])) {
        clearLoginAttempts($clientIP); // Clear rate limit on success
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['admin'] = $data['id']; // Logged in
        logEvent('auth', 'admin', 'Login', ['admin_id' => $data['id']]);
        // Regenerate CSRF
        unset($_SESSION['csrf_token']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        // Redirect to admin page
        header('Location: admin.php');
        exit;
      } else {
        recordFailedLogin($clientIP); // Record failed attempt
        logEvent('auth', 'admin', 'Failed login attempt', ['username' => $username]);
        $_SESSION['login_error'] = "Invalid credentials";
      }
    } else {
      recordFailedLogin($clientIP); // CSRF failures count too
      logEvent('auth', 'admin', 'Failed login attempt - invalid CSRF');
      $_SESSION['login_error'] = "Invalid CSRF token";
    }
  }

  unset($_SESSION['csrf_token']);
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      
  header('Location: login.php');
  exit;
}

// === HANDLE POST ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    logEvent('auth', 'admin', 'Invalid CSRF token on POST');
    die('Invalid CSRF token');
  }

  $action = $_POST['action'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  $name = $_POST['album_name'] ?? '';
  $public = $_POST['public'] ?? '';

  if ($action === 'create_album' && $name !== '') {
    $stmt = $mysqli->prepare("SELECT id FROM albums WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        logEvent('alert', 'admin', 'Album creation failed - already exists', ['name' => $name]);
        $_SESSION['msg'] = "Album already exists";
    } else {
      $slug = sanitize_path($name);
      $is_public = $public === 'on' ? 1 : 0;
      $uuid = sanitize_path(bin2hex(random_bytes(32)));

      $stmt = $mysqli->prepare("INSERT INTO albums (name, slug, is_public, uuid) VALUES (?, ?, ?, ?)");
      $stmt->bind_param('ssis', $name, $slug, $is_public, $uuid);
      $stmt->execute();

      @mkdir(PUBLIC_ROOT . "/$slug", 0755, true);
      @mkdir(THUMB_ROOT . "/$slug", 0755, true);

      logEvent('info', 'create', 'Album created', ['name' => $name, 'slug' => $slug, 'is_public' => $is_public]);
      $_SESSION['msg'] = "Album created";
      $open_id = $mysqli->insert_id;
    }
    $stmt->close();
  }

  if ($action === 'delete_album' && $id > 0) {
    $stmt = $mysqli->prepare("SELECT slug FROM albums WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $album = $result->fetch_assoc();
    if (!$album) {
      logEvent('alert', 'admin', 'Album deletion failed - not found', ['album_id' => $id]);
      $_SESSION['msg'] = "Album not found";
    } else {
      $slug = sanitize_path($album['slug']);
      $public_dir = rtrim(PUBLIC_ROOT, '/') . "/$slug";
      $thumb_dir  = rtrim(THUMB_ROOT, '/') . "/$slug";

      // Delete DB entry
      $stmt = $mysqli->prepare("DELETE FROM albums WHERE id = ?");
      $stmt->bind_param("i", $id);
      $stmt->execute();

      // Delete images entries
      $stmt = $mysqli->prepare("DELETE FROM images WHERE album_id = ?");
      $stmt->bind_param("i", $id);
      $stmt->execute();

      // Delete folders
      deleteDirectory($public_dir);
      deleteDirectory($thumb_dir);

      logEvent('info', 'delete', 'Album deleted', ['album_id' => $id, 'slug' => $slug]);
      $_SESSION['msg'] = "Album and files deleted";
    }
  }

  if ($action === 'delete_image' && $id > 0) {
    $stmt = $mysqli->prepare("SELECT filename, album_id FROM images WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $img = $stmt->get_result()->fetch_assoc();
    if (!$img) {
      logEvent('alert', 'admin', 'Image deletion failed - not found', ['image_id' => $id]);
      $_SESSION['msg'] = "Image not found";
      exit('Not found');
    }

    $stmt = $mysqli->prepare("SELECT slug FROM albums WHERE id = ?");
    $stmt->bind_param("i", $img['album_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $slug = sanitize_path($result->fetch_assoc()['slug']);

    $full = PUBLIC_ROOT . "/$slug/{$img['filename']}";
    $thumb = THUMB_ROOT . "/$slug/{$img['filename']}";
    @unlink($full);
    @unlink($thumb);

    $stmt = $mysqli->prepare("DELETE FROM images WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // Regenerate CSRF
    unset($_SESSION['csrf_token']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    logEvent('info', 'delete', 'Image deleted', ['image_id' => $id, 'filename' => $img['filename'], 'album_slug' => $slug]);
    $_SESSION['msg'] = 'Image deleted';

    echo 'OK';  // ← AJAX response
    exit;
  }

  if ($action === 'update_thumbnail' && $id > 0) {
    $index = (int)($_POST['thumbnail'] ?? -1); // 0-based index from form

    if ($index < 0) {
      logEvent('alert', 'admin', 'Invalid thumbnail index', ['album_id' => $id, 'index' => $index]);
      $_SESSION['msg'] = "Invalid thumbnail index";
    } else {
        // Get image ID at that index
        $stmt = $mysqli->prepare("
            SELECT id FROM images 
            WHERE album_id = ? 
            ORDER BY id ASC 
            LIMIT 1 OFFSET ?
        ");
        $stmt->bind_param("ii", $id, $index);
        $stmt->execute();
        $result = $stmt->get_result();
        $img = $result->fetch_assoc();
        $stmt->close();

        if ($img) {
            $thumbnail_id = $img['id'];
            $stmt = $mysqli->prepare("UPDATE albums SET thumbnail = ? WHERE id = ?");
            $stmt->bind_param("ii", $thumbnail_id, $id);
            $stmt->execute();
            $stmt->close();
            logEvent('info', 'update', 'Thumbnail updated', ['album_id' => $id, 'thumbnail_image_id' => $thumbnail_id]);
            $_SESSION['msg'] = "Thumbnail updated to image #$index (ID: $thumbnail_id)";
        } else {
            logEvent('alert', 'admin', 'Thumbnail update failed - no image at index', ['album_id' => $id, 'index' => $index]);
            $_SESSION['msg'] = "No image at index $index";
        }
    }
    $open_id = $id;
  }

  if ($action === 'update_public' && $id > 0) {
    $state = $_POST['state'] === 'on' ? 1 : 0;

    $stmt = $mysqli->prepare("UPDATE albums SET is_public = ? WHERE id = ?");
    $stmt->bind_param("ii", $state, $id);
    $stmt->execute();
    $stmt->close();

    logEvent('info', 'update', 'Album visibility updated', ['album_id' => $id, 'is_public' => $state]);
    $_SESSION['msg'] = "Album visibility updated";
    $open_id = $id;
  }

  if ($action === 'update_author' && $id > 0) {
    $author = $_POST['author'] ?? '';
    $author = $author === '' ? null : $author;

    $stmt = $mysqli->prepare("UPDATE albums SET author = ? WHERE id = ?");
    $stmt->bind_param("si", $author, $id);
    $stmt->execute();
    $stmt->close();

    logEvent('info', 'update', 'Album author updated', ['album_id' => $id, 'author' => $author]);
    $_SESSION['msg'] = "Album author updated";
    $open_id = $id;
  }

  // Regenerate CSRF
  unset($_SESSION['csrf_token']);
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

  header("Location: admin.php?open=" . ($open_id ?? 0));
  exit;
} else {
  // Log admin page view - actions duplicate above for non-POST views
  #logEvent('view', 'admin', 'Accessed admin page', ['admin_id' => $_SESSION['admin'] ?? 0]);
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
  <title>SpaceCan Admin</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
  <h1>Admin 
    <div class="admin-nav">
      <a href="index.php">Home</a>
      <a href="audit.php">Audit Log</a>
      <a href="password.php">Password</a>
      <a href="?logout=1">Logout</a>
    </div>
  </h1>

  <?php if (isset($_SESSION['msg'])) echo "<p>" . htmlspecialchars($_SESSION['msg'], ENT_QUOTES, 'UTF-8') . "</p>"; $_SESSION['msg'] = ''?>

  <!-- Create Album -->
  <form method="post">
    <input type="hidden" name="action" value="create_album">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="text" name="album_name" placeholder="New Album Name" required>
    <label><input type="checkbox" name="public" checked> Public</label>
    <button style="cursor:pointer;">Create Album</button>
  </form>

  <!-- Albums -->
  <?php
  $open_id = (int)($_GET['open'] ?? 0);
  $res = $mysqli->query("SELECT * FROM albums ORDER BY name");
  while ($album = $res->fetch_assoc()):
    $slug = sanitize_path($album['slug']);
    $link = $album['is_public'] ? "album.php?s=$slug" : "album.php?u={$album['uuid']}";
    $is_open = ($open_id === (int)$album['id']) ? ' open' : '';
    $thumbnail_id = (int)$album['thumbnail'];
    
    $author = $album['author'];

    $current_author = 'none';
    if (!empty($author)) {
        $current_author = $author;
    }

    // Get index of thumbnail_id
    $current_index = 'none';
    if ($thumbnail_id > 0) {
        $stmt = $mysqli->prepare("
            SELECT COUNT(*) as pos 
            FROM images 
            WHERE album_id = ? AND id <= ? 
            ORDER BY id ASC
        ");
        $stmt->bind_param("ii", $album['id'], $thumbnail_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $current_index = $row['pos'] - 1;
    }
  ?>
    <details style="margin:1rem 0;"<?= $is_open ?> id="<?= $album['id'] ?>">
      <summary>
        <strong><?= htmlspecialchars($album['name']) ?></strong>
        (<?= $album['is_public'] ? 'Public' : 'Unlisted' ?>)
        — <a href="<?= BASE_URL ?>/<?= $link ?>" target="_blank">View</a>
        —
        <form method="post" class="inline-form" onsubmit="return confirm('Delete album and all images?')">
          <input type="hidden" name="action" value="delete_album">
          <input type="hidden" name="id" value="<?= $album['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit">Delete</button>
        </form>
      </summary>
      <div class="dropzone" id="drop-<?= $album['id'] ?>">Drag & drop JPGs here</div>
        <form method="post" class="public-form">
          <input type="hidden" name="action" value="update_public">
          <input type="hidden" name="id" value="<?= $album['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="checkbox" name="state" <?= $album['is_public'] ? 'checked' : '' ?>> Public
          <button type="submit">Update</button>
        </form>
        <form method="post" class="update-form">
          <input type="hidden" name="action" value="update_thumbnail">
          <input type="hidden" name="id" value="<?= $album['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="text" name="thumbnail" placeholder="Thumbnail" required>
          <button type="submit">Update</button>
          <span class="current-index">Current: <?= $current_index ?></span>
        </form>
        <form method="post" class="public-form">
          <input type="hidden" name="action" value="update_author">
          <input type="hidden" name="id" value="<?= $album['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="text" name="author" placeholder="Author">
          <button type="submit">Update</button>
          <span class="current-index">Current: <?= $current_author ?></span>
        </form>
      <div>
        <?php
        $stmt = $mysqli->prepare("SELECT id, filename FROM images WHERE album_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $album['id']);
        $stmt->execute();
        $imgs = $stmt->get_result();
        $i = 0;
        while ($img = $imgs->fetch_assoc()):
          $thumb = BASE_URL . "/images/thumbnails/$slug/{$img['filename']}";
        ?>
          <span class="img-row">
            <img src="<?= $thumb ?>" class="admin-thumb" alt="">
            <button type="button" onclick="delImg(<?= $img['id'] ?>, <?= $album['id'] ?>)">×</button>
            <p><?= $i; $i++; ?></p>
          </span>
        <?php endwhile; $stmt->close(); ?>
      </div>
    </details>
  <?php endwhile; ?>
</div>
</body>
<script>
  const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>
<script src="script.js"></script>
</html>