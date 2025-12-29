<?php
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="assets/favicon.ico">
    <title>Change Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-container">
  <h1>Change Admin Password</h1>

  <?php if ($success): ?>
    <div class="auth-msg auth-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="auth-msg auth-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="auth-form">
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
    <br>
  <p><a href="admin.php" class="home-link">Back to Admin</a></p>
</div>
</body>
</html>