<?php
require_once __DIR__ . '/utils.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if (admin_password_matches($password)) {
        $_SESSION['is_admin'] = true;
        session_regenerate_id(true);
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Onjuist wachtwoord.';
}

app_page_start('Admin login - ' . app_site_name(), [
    'active_admin' => '',
    'show_public_nav' => false,
    'show_admin_nav' => false,
    'description' => 'Admin login voor Ranglijst Deltavliegen.',
]);
?>
<main class="card">
  <h1>Admin login</h1>
  <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Wachtwoord
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <p><button type="submit">Inloggen</button></p>
  </form>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
