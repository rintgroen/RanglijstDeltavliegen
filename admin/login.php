<?php
require_once __DIR__ . '/utils.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    if (hash_equals(ADMIN_PASSWORD, $pass)) {
        $_SESSION['is_admin'] = true;
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Incorrect password';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login – <?=h(SITE_NAME)?></title>
  <link rel="stylesheet" href="../public/assets/style.css">
</head>
<body class="container">
  <h1>Admin Login</h1>
  <?php if ($error): ?><div class="notice error"><?=h($error)?></div><?php endif; ?>
  <form method="post" class="card">
    <label>Password<br>
      <input type="password" name="password" required>
    </label>
    <button type="submit">Login</button>
  </form>
</body>
</html>
