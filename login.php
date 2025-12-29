<?php
require 'config.php';

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="assets/favicon.ico">
  <title>SpaceCan Admin Login</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-container">
  <h2>Admin Login</h2>
  <a href="index.php" class="home-link">Home</a>

  <form method="post" class="auth-form" action="admin.php">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="password" name="pass" placeholder="Password" required autofocus>
    <button type="submit">Login</button>
  </form>

  <?php if (isset($_SESSION['login_error'])): ?>
    <p class="auth-msg auth-error"><?= htmlspecialchars($_SESSION['login_error']) ?></p>
    <?php unset($_SESSION['login_error']); ?>
  <?php endif; ?>
</div>
</body>
</html>