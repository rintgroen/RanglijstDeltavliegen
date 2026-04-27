<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

$DEBUG = isset($_GET['debug']);
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();

// Fetch competitions (now including class)
$comps = [];
try {
  $stmt = $pdo->query('SELECT id, year, title, class, created_at FROM rankings_competitions ORDER BY year DESC, created_at DESC');
  if ($stmt) $comps = $stmt->fetchAll();
} catch (Throwable $e) {
  if ($DEBUG) echo '<pre>Competition list failed: ' . h($e->getMessage()) . '</pre>';
}

// Prepared winner lookup (highest total)
$winnerStmt = $pdo->prepare(
  'SELECT cr.pilot_id AS pid,
          COALESCE(p.name, cr.pilot_name) AS winner_name,
          cr.total
   FROM rankings_competition_results cr
   LEFT JOIN rankings_pilots p ON p.id = cr.pilot_id
   WHERE cr.competition_id = ?
   ORDER BY CAST(cr.total AS DECIMAL(16,6)) DESC, cr.id ASC
   LIMIT 1'
);
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – Wedstrijden</title>
  <link rel="stylesheet" href="assets/style.css">
  <meta name="description" content="Ranglijst Deltavliegen – Wedstrijden">
</head>
<body class="container">
<header class="topbar">
  <h1><a href="ranking.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php">Home</a> ·
  <a href="ranking.php">Klasse 1</a> ·
  <a href="sportclass.php">Sportklasse</a> ·
  <a href="competitionlist.php"><strong>Wedstrijden</strong></a> ·
  <a href="explanation.php">Toelichting</a>
</nav>

<main class="card">
  <h2>Wedstrijden</h2>
  <?php if (empty($comps)): ?>
    <p class="muted">Nog geen wedstrijden beschikbaar.</p>
  <?php else: ?>
    <table class="striped">
      <thead>
        <tr>
          <th>Jaar</th>
          <th>Titel</th>
          <th>Klasse</th>
          <th>Winnaar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($comps as $c): ?>
          <?php
            $winnerId = null; $winnerName = null;
            try {
              $winnerStmt->execute([(int)$c['id']]);
              $w = $winnerStmt->fetch(PDO::FETCH_ASSOC);
              if ($w) { $winnerId = $w['pid'] ? (int)$w['pid'] : null; $winnerName = $w['winner_name']; }
            } catch (Throwable $e) {
              if ($DEBUG) echo '<!-- winner lookup failed for comp ' . (int)$c['id'] . ': ' . h($e->getMessage()) . ' -->';
            }
            $cls = isset($c['class']) && $c['class'] !== '' ? $c['class'] : 'Klasse 1';
          ?>
          <tr>
            <td><?= (int)$c['year'] ?></td>
            <td><a href="competition.php?id=<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a></td>
            <td><?= h($cls) ?></td>
            <td>
              <?php if ($winnerName): ?>
                <?php if ($winnerId): ?>
                  <a href="pilot.php?id=<?= (int)$winnerId ?>"><?= h($winnerName) ?></a>
                <?php else: ?>
                  <?= h($winnerName) ?>
                <?php endif; ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Stijl geïnspireerd op CIVL rankings.</p>
</footer>
</body>
</html>
