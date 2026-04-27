<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

$DEBUG = isset($_GET['debug']);
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();

// Year selector
$year = isset($_REQUEST['year']) ? (int)$_REQUEST['year'] : (int)date('Y');

// Load pilots
$pilots = [];
try {
  $stmt = $pdo->query('SELECT id, name FROM rankings_pilots WHERE active=1 ORDER BY name');
  $pilots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  if ($DEBUG) echo '<pre>Pilots load failed: ' . h($e->getMessage()) . '</pre>';
}

// Existing points for both classes
$points = []; // $points[pilot_id]['Klasse 1'|'Sportklasse'] = points
try {
  $ps = $pdo->prepare('SELECT pilot_id, class, points FROM rankings_world_points WHERE year = ?');
  $ps->execute([$year]);
  foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pid = (int)$row['pilot_id'];
    $cls = $row['class'] ?: 'Klasse 1';
    $points[$pid][$cls] = $row['points'];
  }
} catch (Throwable $e) {
  if ($DEBUG) echo '<pre>World points load failed: ' . h($e->getMessage()) . '</pre>';
}

$notice = null;
$errors = [];

// Handle POST save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $year = isset($_POST['year']) ? (int)$_POST['year'] : $year;

  $k1 = isset($_POST['points_k1']) && is_array($_POST['points_k1']) ? $_POST['points_k1'] : [];
  $sk = isset($_POST['points_sk']) && is_array($_POST['points_sk']) ? $_POST['points_sk'] : [];

  // Use INSERT ... ON DUPLICATE KEY UPDATE to avoid duplicate key errors
  // Requires a UNIQUE KEY on (pilot_id, year, class)
  $upsert = $pdo->prepare('INSERT INTO rankings_world_points (pilot_id, year, class, points)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE points = VALUES(points)');

  $changed = 0;
  foreach ($pilots as $p) {
    $pid = (int)$p['id'];

    // Klasse 1
    if (array_key_exists($pid, $k1)) {
      $val = trim((string)$k1[$pid]);
      if ($val !== '') {
        if (!is_numeric($val)) {
          $errors[] = "Ongeldig getal voor " . h($p['name']) . " (Klasse 1).";
        } else {
          $num = (float)$val;
          try {
            $upsert->execute([$pid, $year, 'Klasse 1', $num]);
            $changed++;
            $points[$pid]['Klasse 1'] = $num;
          } catch (Throwable $e) {
            $errors[] = "Opslaan mislukt voor " . h($p['name']) . " (Klasse 1): " . h($e->getMessage());
          }
        }
      }
    }

    // Sportklasse
    if (array_key_exists($pid, $sk)) {
      $val = trim((string)$sk[$pid]);
      if ($val !== '') {
        if (!is_numeric($val)) {
          $errors[] = "Ongeldig getal voor " . h($p['name']) . " (Sportklasse).";
        } else {
          $num = (float)$val;
          try {
            $upsert->execute([$pid, $year, 'Sportklasse', $num]);
            $changed++;
            $points[$pid]['Sportklasse'] = $num;
          } catch (Throwable $e) {
            $errors[] = "Opslaan mislukt voor " . h($p['name']) . " (Sportklasse): " . h($e->getMessage());
          }
        }
      }
    }
  }

  if ($changed > 0 && empty($errors)) {
    $notice = "Wijzigingen opgeslagen voor jaar $year.";
  } elseif ($changed === 0 && empty($errors)) {
    $notice = "Geen wijzigingen gevonden.";
  }
}

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – WPRS punten</title>
  <link rel="stylesheet" href="../public/assets/style.css">
</head>
<body class="container">
<header class="topbar">
  <h1><a href="../public/ranking.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>



<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="../public/ranking.php">Klasse 1</a> ·
  <a href="../public/sportclass.php">Sportklasse</a> ·
  <a href="../public/competitionlist.php">Wedstrijden</a> ·
  <a href="../public/explanation.php">Toelichting</a>
</nav>

<nav class="card admin-nav" style="margin:.5rem 0 1rem; padding:.5rem 1rem;">
  <a href="dashboard.php">Dashboard</a> ·
  <a href="pilots.php">Pilots</a> ·
  <a href="world_points.php"><strong>World points</strong></a> ·
  <a href="competition_upload.php">Competition upload</a> ·
  <a href="memories.php">Memories</a>
</nav>




<main class="card">
  <h2>WPRS-punten bewerken</h2>

  <form method="get" class="inline">
    <label>Jaar
      <input type="number" name="year" min="1980" max="2100" value="<?= (int)$year ?>">
    </label>
    <button class="btn" type="submit">Laad</button>
  </form>

  <?php if ($notice): ?>
    <div class="alert success"><?= h($notice) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="year" value="<?= (int)$year ?>">
    <table class="striped">
      <thead>
        <tr>
          <th>Piloot</th>
          <th>Klasse 1 Points</th>
          <th>Sportklasse Points</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pilots as $p): ?>
          <?php
            $pid = (int)$p['id'];
            $k1v = isset($points[$pid]['Klasse 1']) ? (float)$points[$pid]['Klasse 1'] : '';
            $skv = isset($points[$pid]['Sportklasse']) ? (float)$points[$pid]['Sportklasse'] : '';
          ?>
          <tr>
            <td><?= h($p['name']) ?></td>
            <td>
              <input type="number" step="0.001" name="points_k1[<?= $pid ?>]" value="<?= $k1v !== '' ? htmlspecialchars(number_format((float)$k1v, 3, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="0.000">
            </td>
            <td>
              <input type="number" step="0.001" name="points_sk[<?= $pid ?>]" value="<?= $skv !== '' ? htmlspecialchars(number_format((float)$skv, 3, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="0.000">
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top: 1rem;">
      <button class="btn" type="submit">Opslaan</button>
    </div>
  </form>
</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Stijl geïnspireerd op CIVL rankings.</p>
</footer>
</body>
</html>
