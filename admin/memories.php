<?php
// Debug early
$DEBUG = isset($_GET['debug']);
if ($DEBUG) { @ini_set('display_errors','1'); @error_reporting(E_ALL); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

try {
  $pdo = db();
} catch (Exception $e) {
  http_response_code(500);
  if ($DEBUG) echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>';
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf_token'];

// Moderation postback (bulk or single)
$notice = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mem_admin_action'])) {
  $csrf_ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);
  if (!$csrf_ok) {
    $error = 'Ongeldige CSRF-token.';
  } else {
    $ids = array();
    if (isset($_POST['memory_id']) && is_array($_POST['memory_id'])) {
      foreach ($_POST['memory_id'] as $mid) { $ids[] = (int)$mid; }
    } elseif (isset($_POST['memory_id'])) {
      $ids[] = (int)$_POST['memory_id'];
    }
    $target = isset($_POST['target']) ? (string)$_POST['target'] : '';
    if (!empty($ids) && in_array($target, array('show','hide'), true)) {
      $val = ($target === 'show') ? 1 : 0;
      try {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE rankings_competition_memories SET is_visible={$val} WHERE id IN ($in)";
        $st  = $pdo->prepare($sql);
        $st->execute($ids);
        $notice = ($target === 'show') ? 'Inzending(en) zichtbaar gemaakt.' : 'Inzending(en) verborgen.';
      } catch (Exception $e) {
        $error = 'Moderatie mislukt: '.h($e->getMessage());
      }
    } else {
      $error = 'Geen geldige selectie opgegeven.';
    }
  }
}

// Filters
$filter = isset($_GET['filter']) ? (string)$_GET['filter'] : 'all'; // all|visible|hidden
$where = '';
if ($filter === 'visible') $where = 'WHERE m.is_visible = 1';
elseif ($filter === 'hidden') $where = 'WHERE m.is_visible = 0';

// Load memories (latest first)
$rows = array();
try {
  $sql = "SELECT m.id, m.competition_id, m.author_name, m.title, m.body, m.photo_path, m.created_at, m.is_visible,
                 c.title AS comp_title, c.year AS comp_year, c.class AS comp_class
          FROM rankings_competition_memories m
          JOIN rankings_competitions c ON c.id = m.competition_id
          $where
          ORDER BY m.created_at DESC
          LIMIT 200";
  $rs = $pdo->query($sql);
  $rows = $rs ? $rs->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (Exception $e) { if ($DEBUG) echo '<pre>Load failed: '.h($e->getMessage()).'</pre>'; }

?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin – Memories</title>
  <link rel="stylesheet" href="../public/assets/style.css">
  <style>
    .muted { color: #374151; }
    .toolbar { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; }
    .grid { overflow-x:auto; }
    table th, table td { white-space: nowrap; }
    .thumb { max-height: 64px; border-radius:.5rem; }
  </style>
</head>
<body class="container">
<header class="topbar">
  <h1><a href="dashboard.php" class="logo">Memories – Admin</a></h1>
</header>

<!-- Public menu -->
<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="../public/ranking.php">Klasse 1</a> ·
  <a href="../public/sportclass.php">Sportklasse</a> ·
  <a href="../public/competitionlist.php">Wedstrijden</a> ·
  <a href="../public/explanation.php">Toelichting</a>
</nav>

<!-- Admin menu -->
<nav class="card" style="margin:.5rem 0 1rem; padding:.5rem 1rem;">
  <a href="dashboard.php">Dashboard</a> ·
  <a href="pilots.php">Pilots</a> ·
  <a href="world_points.php">World points</a> ·
  <a href="competition_upload.php">Competition upload</a> ·
  <strong>Memories</strong>
</nav>

<main class="card">
  <h2>Memories-beheer</h2>

  <?php if (!empty($notice)): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

  <form method="get" class="toolbar">
    <label>Filter
      <select name="filter" onchange="this.form.submit()">
        <option value="all" <?= $filter==='all'?'selected':'' ?>>Alles</option>
        <option value="visible" <?= $filter==='visible'?'selected':'' ?>>Zichtbaar</option>
        <option value="hidden" <?= $filter==='hidden'?'selected':'' ?>>Verborgen</option>
      </select>
    </label>
  </form>

  <form method="post" class="grid" style="margin-top: .75rem;">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
    <input type="hidden" name="mem_admin_action" value="moderate">

    <table class="striped">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th>
          <th>ID</th>
          <th>Datum</th>
          <th>Status</th>
          <th>Wedstrijd</th>
          <th>Auteur</th>
          <th>Titel</th>
          <th>Foto</th>
          <th>Tekst (snippet)</th>
          <th>Acties</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="muted">Geen resultaten.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $snippet = trim((string)($r['body'] ?: ''));
              if (mb_strlen($snippet) > 140) $snippet = mb_substr($snippet, 0, 140).'…';
              $compLink = '../public/competition.php?id='.(int)$r['competition_id'].'#memories';
              $isVis = (int)$r['is_visible'];
            ?>
            <tr>
              <td><input type="checkbox" class="chk" name="memory_id[]" value="<?= (int)$r['id'] ?>"></td>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
              <td><?= $isVis ? 'Zichtbaar' : 'Verborgen' ?></td>
              <td><a href="<?= h($compLink) ?>"><?= h(($r['comp_title'] ?: 'Wedstrijd').' – '.(int)$r['comp_year'].' ('.($r['comp_class'] ?: '').')') ?></a></td>
              <td><?= h($r['author_name']) ?></td>
              <td><?= h($r['title'] ?: '') ?></td>
              <td><?php if (!empty($r['photo_path'])): ?><img class="thumb" src="<?= h('../public/'.$r['photo_path']) ?>" alt="thumb"><?php endif; ?></td>
              <td><?= nl2br(h($snippet)) ?></td>
              <td>
                <?php if ($isVis): ?>
                  <button class="btn" name="target" value="hide" formaction="memories.php">Verbergen</button>
                <?php else: ?>
                  <button class="btn" name="target" value="show" formaction="memories.php">Zichtbaar</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if (!empty($rows)): ?>
      <div style="margin-top:.5rem; display:flex; gap:.5rem; align-items:center;">
        <button class="btn" name="target" value="show" type="submit">Maak selectie zichtbaar</button>
        <button class="btn" name="target" value="hide" type="submit">Verberg selectie</button>
      </div>
    <?php endif; ?>
  </form>
</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Admin – Memories</p>
</footer>
</body>
</html>
