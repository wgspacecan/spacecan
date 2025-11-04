<?php
session_start();
require 'config.php';

// Must be logged in
if (!isset($_SESSION['admin'])) {
    unset($_SESSION['csrf_token']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    header('Location: login.php');
    exit;
}

// === CSRF TOKEN ===
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token';
    } else {
        $current = $_POST['current'] ?? '';
        $new     = $_POST['new'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        // Validate
        if (!$current || !$new || !$confirm) {
            $error = 'All fields required';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match';
        } elseif (strlen($new) < 8) {
            $error = 'Password too short (min 8)';
        } else {
            // Verify current password
            $stmt = $mysqli->prepare("SELECT password FROM admins WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['admin']);
            $stmt->execute();
            $hash = $stmt->get_result()->fetch_assoc()['password'];
            $stmt->close();

            if (!password_verify($current, $hash)) {
                $error = 'Current password incorrect';
                logEvent('alert', 'update', 'Password change failed - wrong current', ['admin' => $_SESSION['admin']]);
            } else {
                // Update
                $newHash = password_hash($new, PASSWORD_BCRYPT);
                $stmt = $mysqli->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $newHash, $_SESSION['admin']);
                if ($stmt->execute()) {
                    $success = 'Password updated';
                    logEvent('info', 'update', 'Password updated', ['admin_id' => $_SESSION['admin']]);
                } else {
                    $error = 'Update failed';
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Change Password</title>
  <style>
    body { font-family: Arial; max-width: 500px; margin: 2rem auto; padding: 1rem; }
    .form-group { margin: 1rem 0; }
    label { display: block; margin-bottom: .3rem; }
    input[type=password] { width: 100%; padding: .5rem; font-size: 1rem; }
    button { padding: .7rem 1.5rem; background: #0066cc; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .msg { padding: .5rem; margin: 1rem 0; border-radius: 4px; }
    .success { background: #d4edda; color: #155724; }
    .error { background: #f8d7da; color: #721c24; }
  </style>
</head>
<body>
  <h1>Change Admin Password</h1>

  <?php if ($success): ?>
    <div class="msg success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="msg error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="form-group">
      <label>Current Password</label>
      <input type="password" name="current" required>
    </div>

    <div class="form-group">
      <label>New Password</label>
      <input type="password" name="new" required minlength="8">
    </div>

    <div class="form-group">
      <label>Confirm New Password</label>
      <input type="password" name="confirm" required minlength="8">
    </div>

    <button type="submit">Update Password</button>
  </form>

  <p><a href="admin.php">Back to Admin</a></p>
</body>
</html>