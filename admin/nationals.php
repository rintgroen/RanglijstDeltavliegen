<?php
require_once __DIR__ . '/utils.php';
require_login();
$pdo = db();

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if (isset($_POST['save_meta'])) {
    $year = (int)$_POST['year'];
    $participants = max(1, (int)$_POST['participants']);
    $pdo->prepare('REPLACE INTO rankings_nationals_meta(year, participants) VALUES(?, ?)')->execute([$year, $participants]);
}

if (isset($_POST['save_results'])) {
    $year = (int)$_POST['year'];
    $pdo->beginTransaction();
    try {
        $meta = $pdo->prepare('SELECT participants FROM rankings_nationals_meta WHERE year=?');
        $meta->execute([$year]);
        if (!$meta->fetch()) { throw new RuntimeException('Set participants first'); }
        $pdo->prepare('DELETE FROM rankings_nationals_results WHERE year=?')->execute([$year]);
        $positions = $_POST['position'] ?? [];
        $i = 1;
        foreach ($positions as $pilot_id) {
            $pilot_id = (int)$pilot_id;
            if ($pilot_id > 0) {
                $pdo->prepare('INSERT INTO rankings_nationals_results(year, pilot_id, position) VALUES(?, ?, ?)')->execute([$year, $pilot_id, $i]);
                $i++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $err = $e->getMessage();
    }
}

$pilots = $pdo->query('SELECT id, name FROM rankings_pilots WHERE active=1 ORDER BY name')->fetchAll();
$metaStmt = $pdo->prepare('SELECT participants FROM rankings_nationals_meta WHERE year=?');
$metaStmt->execute([$year]);
$participants = (int)($metaStmt->fetchColumn() ?: 0);

$resultsStmt = $pdo->prepare('SELECT r.position, p.id, p.name FROM rankings_nationals_results r JOIN rankings_pilots p ON p.id=r.pilot_id WHERE r.year=? ORDER BY r.position');
$resultsStmt->execute([$year]);
$existing = $resultsStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nationals – Admin</title>
  <link rel="stylesheet" href="../public/assets/style.css">
</head>
<body class="container">
<header class="topbar">
  <h1>Nationals results</h1>
  <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="pilots.php">Pilots</a>
    <a href="world_points.php">World Points</a>
    <a href="logout.php">Logout</a>
  </nav>
</header>

<section class="card">
  <form method="get" class="inline">
    <label>Year <input type="number" name="year" value="<?= (int)$year ?>" min="1900" max="2100"></label>
    <button type="submit">Go</button>
  </form>
</section>

<section class="card">
  <h2>Participants</h2>
  <form method="post" class="inline">
    <input type="hidden" name="save_meta" value="1">
    <label>Year <input type="number" name="year" value="<?= (int)$year ?>"></label>
    <label>Number of participants <input type="number" name="participants" value="<?= (int)$participants ?>" min="1"></label>
    <button type="submit">Save</button>
  </form>
</section>

<section class="card">
  <h2>Positions (1 = champion)</h2>
  <?php if (!empty($err)): ?><div class="notice error"><?=h($err)?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="save_results" value="1">
    <input type="hidden" name="year" value="<?= (int)$year ?>">

    <p>Add pilots in finishing order. Leave blanks at the end if fewer than participants.</p>

    <div class="grid cols-2">
      <?php for ($i=1; $i<=max($participants, count($existing)); $i++):
        $selected = $existing[$i-1]['id'] ?? 0; ?>
        <label>#<?= $i ?>
          <select name="position[]">
            <option value="">— none —</option>
            <?php foreach ($pilots as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $selected == $p['id'] ? 'selected' : '' ?>><?=h($p['name'])?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endfor; ?>
    </div>
    <button type="submit">Save results</button>
  </form>
</section>
</body>
</html>
