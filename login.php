<?php
session_start();
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
  <style>
    body {
        font-family: system-ui, sans-serif;
        margin: 0;
        background: #fafafa;
        color: #333;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
    }
    .login-container {
        background: white;
        padding: 2rem;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        width: 100%;
        max-width: 380px;
        text-align: center;
    }
    h2 {
        margin: 0 0 1rem;
        font-size: 1.8rem;
        color: #222;
    }
    .home-link {
        display: block;
        margin-bottom: 1.5rem;
        color: #666;
        text-decoration: none;
        font-size: 1rem;
    }
    form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    input[type="password"] {
        padding: 0.8rem;
        font-size: 1rem;
        border: 1px solid #ddd;
        border-radius: 8px;
        outline: none;
        transition: border 0.2s;
    }
    input[type="password"]:focus {
        border-color: #0066cc;
    }
    button {
        padding: 0.9rem;
        font-size: 1rem;
        background: #0066cc;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.2s;
    }
    button:hover {
        background: #0055aa;
    }
    .error {
        color: #d32f2f;
        font-size: 0.9rem;
        margin-top: 0.5rem;
    }
    @media (max-width: 480px) {
        .login-container {
            margin: 1rem;
            padding: 1.5rem;
        }
        h2 { font-size: 1.6rem; }
    }
  </style>
</head>
<body>
<div class="login-container">
  <h2>Admin Login</h2>
  <a href="index.php" class="home-link">Home</a>

  <form method="post" action="admin.php">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="password" name="pass" placeholder="Password" required autofocus>
    <button type="submit">Login</button>
  </form>

  <?php if (isset($_SESSION['login_error'])): ?>
    <p class="error"><?= htmlspecialchars($_SESSION['login_error']) ?></p>
    <?php unset($_SESSION['login_error']); ?>
  <?php endif; ?>
</div>
</body>
</html>