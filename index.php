<?php
// Front controller to send visitors to the public home page.
// Attempts '/ranking/public/home.php' then '/public/home.php'.
// Preserves query string.
$root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
$candidates = ['/ranking/public/home.php','/public/home.php'];
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
$target = null;
foreach ($candidates as $rel) {
  $abs = $root . $rel;
  if (is_file($abs)) { $target = $rel; break; }
}
if ($target === null) { $target = '/public/home.php'; }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $target . $qs, true, 302);
exit;
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Doorverwijzen…</title>
  <meta http-equiv="refresh" content="0; url=/public/home.php">
</head>
<body>
  <p>Doorverwijzen naar <a href="/public/home.php">Ranglijst Deltavliegen</a>…</p>
</body>
</html>
