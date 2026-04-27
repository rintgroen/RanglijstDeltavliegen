<?php
require_once __DIR__ . '/utils.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $name = trim($_POST['name'] ?? '');
        $nation = trim($_POST['nation'] ?? 'NED');
        $civl = trim($_POST['civl_id'] ?? '');
        $civl_id = ($civl === '') ? null : (int)$civl;
        if ($name !== '') {
            $stmt = $pdo->prepare('INSERT INTO rankings_pilots(name, nation, civl_id, active) VALUES(?, ?, ?, 1)');
            $stmt->execute([$name, strtoupper($nation ?: 'NED'), $civl_id]);
        }
        header('Location: pilots.php'); exit;
    }
    if (isset($_POST['update'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $nation = trim($_POST['nation'] ?? 'NED');
        $civl = trim($_POST['civl_id'] ?? '');
        $civl_id = ($civl === '') ? null : (int)$civl;
        $active = isset($_POST['active']) ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE rankings_pilots SET name=?, nation=?, civl_id=?, active=? WHERE id=?');
        $stmt->execute([$name, strtoupper($nation ?: 'NED'), $civl_id, $active, $id]);
        header('Location: pilots.php'); exit;
    }
}

$pilots = $pdo->query('SELECT * FROM rankings_pilots ORDER BY active DESC, name ASC')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pilots – Admin</title>
  <link rel="stylesheet" href="../public/assets/style.css">
</head>
<body class="container">
  <header class="topbar">
    <h1>Pilots</h1>
    <nav>
      <a href="dashboard.php">Dashboard</a>
    </nav>
  </header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="../public/ranking.php">Klasse 1</a> ·
  <a href="../public/sportclass.php">Sportklasse</a> ·
  <a href="../public/competitionlist.php">Wedstrijden</a> ·
  <a href="../public/explanation.php">Toelichting</a>
</nav>

<nav class="card admin-nav" style="margin:.5rem 0 1rem; padding:.5rem 1rem;">
  <a href="dashboard.php">Dashboard</a> ·
  <a href="pilots.php"><strong>Pilots</strong></a> ·
  <a href="world_points.php">World points</a> ·
  <a href="competition_upload.php">Competition upload</a> ·
  <a href="memories.php">Memories</a>
</nav>



  <section class="card">
    <h2>Add pilot</h2>
    <form method="post">
      <input type="hidden" name="create" value="1">
      <div class="grid">
        <label>Name<br><input type="text" name="name" required></label>
        <label>Nation (ISO-3)<br><input type="text" name="nation" value="NED" maxlength="3"></label>
        <label>CIVL ID (numeric)<br><input type="number" name="civl_id" inputmode="numeric" pattern="\d*"></label>
      </div>
      <button type="submit">Add</button>
    </form>
  </section>

  <section class="card">
    <h2>All pilots</h2>
    <table>
      <thead><tr><th>Name</th><th>Nation</th><th>CIVL ID</th><th>Active</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($pilots as $p): ?>
        <tr>
          <td><?=h($p['name'])?></td>
          <td><?=h($p['nation'])?></td>
          <td><?= isset($p['civl_id']) && $p['civl_id'] !== null && $p['civl_id'] !== '' ? (int)$p['civl_id'] : '—' ?></td>
          <td><?= $p['active'] ? 'Yes' : 'No' ?></td>
          <td>
            <details>
              <summary>Edit</summary>
              <form method="post" class="inline">
                <input type="hidden" name="update" value="1">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <label>Name <input type="text" name="name" value="<?=h($p['name'])?>"></label>
                <label>Nation <input type="text" name="nation" value="<?=h($p['nation'])?>" maxlength="3"></label>
                <label>CIVL ID <input type="number" name="civl_id" value="<?= isset($p['civl_id']) ? h($p['civl_id']) : '' ?>"></label>
                <label><input type="checkbox" name="active" <?= $p['active'] ? 'checked' : '' ?>> Active</label>
                <button type="submit">Save</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</body>
</html>