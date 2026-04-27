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

// CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf_token'];

// Handle memory moderation (show/hide)
$admin_notice = null; $admin_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mem_admin_action']) && $_POST['mem_admin_action'] === 'moderate') {
  $csrf_ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);
  $mem_id  = isset($_POST['memory_id']) ? (int)$_POST['memory_id'] : 0;
  $target  = isset($_POST['target']) ? (string)$_POST['target'] : '';
  if ($csrf_ok && $mem_id > 0 && in_array($target, array('show','hide'), true)) {
    try {
      $val = ($target === 'show') ? 1 : 0;
      $st = $pdo->prepare('UPDATE rankings_competition_memories SET is_visible=? WHERE id=?');
      $st->execute(array($val, $mem_id));
      $admin_notice = ($target === 'show') ? 'Inzending is zichtbaar gemaakt.' : 'Inzending is verborgen.';
    } catch (Exception $e) {
      $admin_error = 'Moderatie mislukt: '.h($e->getMessage());
    }
  } else {
    $admin_error = 'Ongeldige moderatie-aanvraag.';
  }
}

// Latest memory (admin sees latest, regardless of visibility)
$latestMemory = null;
try {
  $sql = "SELECT m.id, m.competition_id, m.author_name, m.title, m.body, m.photo_path, m.created_at, m.is_visible,
                 c.title AS comp_title, c.year AS comp_year, c.class AS comp_class
          FROM rankings_competition_memories m
          JOIN rankings_competitions c ON c.id = m.competition_id
          ORDER BY m.created_at DESC
          LIMIT 1";
  $ms = $pdo->query($sql);
  $latestMemory = $ms ? $ms->fetch(PDO::FETCH_ASSOC) : null;
} catch (Exception $e) { if ($DEBUG) echo '<pre>Load memory failed: '.h($e->getMessage()).'</pre>'; }

// Stats
$stats = array(
  'pilots_total' => 0,
  'pilots_active' => 0,
  'competitions_total' => 0,
  'competitions_k1' => 0,
  'competitions_sc' => 0,
  'memories_total' => 0,
  'memories_30d' => 0,
  'wprs_k1_year' => 0,
  'wprs_sc_year' => 0,
);
$currentYear = (int)date('Y');

try {
  $stats['pilots_total']  = (int)$pdo->query("SELECT COUNT(*) FROM rankings_pilots")->fetchColumn();
  $stats['pilots_active'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_pilots WHERE active=1")->fetchColumn();
  $stats['competitions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions")->fetchColumn();
  $stats['competitions_k1']    = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions WHERE class='Klasse 1'")->fetchColumn();
  $stats['competitions_sc']    = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions WHERE class='Sportklasse'")->fetchColumn();
  $stats['memories_total']     = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competition_memories")->fetchColumn();
  $stats['memories_30d']       = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competition_memories WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM rankings_world_points WHERE year=? AND class='Klasse 1'");
  $st->execute(array($currentYear));
  $stats['wprs_k1_year'] = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM rankings_world_points WHERE year=? AND class='Sportklasse'");
  $st->execute(array($currentYear));
  $stats['wprs_sc_year'] = (int)$st->fetchColumn();
} catch (Exception $e) { if ($DEBUG) echo '<pre>Stats failed: '.h($e->getMessage()).'</pre>'; }

// Latest competition
$latestComp = null;
try {
  $cs = $pdo->query("SELECT id, title, year, class, created_at FROM rankings_competitions ORDER BY created_at DESC LIMIT 1");
  $latestComp = $cs ? $cs->fetch(PDO::FETCH_ASSOC) : null;
} catch (Exception $e) { if ($DEBUG) echo '<pre>Latest competition failed: '.h($e->getMessage()).'</pre>'; }

?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin – Ranglijst Deltavliegen</title>
  <link rel="stylesheet" href="../public/assets/style.css">
  <style>
    .admin-nav a { margin-right: .6rem; }
    .statgrid { display:grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap:.75rem; }
    .stat { padding: .75rem 1rem; border:1px solid #e5e7eb; border-radius:.75rem; background: var(--surface, #fff); }
    .muted { color: #374151; } /* darker for contrast */
    .memory-card img { max-width:100%; height:auto; border-radius:.5rem; }
    .memory-card p { margin: .5rem 0 0; }
    .stat strong { color: #111827; }
    .btn.secondary { background: #f3f4f6; border: 1px solid #e5e7eb; }
    form.inline { display:inline; }
  </style>
</head>
<body class="container">
<header class="topbar">
  <h1><a href="../public/ranking.php" class="logo">Ranglijst Deltavliegen – Admin</a></h1>
</header>

<!-- Public menu -->
<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="../public/ranking.php">Klasse 1</a> ·
  <a href="../public/sportclass.php">Sportklasse</a> ·
  <a href="../public/competitionlist.php">Wedstrijden</a> ·
  <a href="../public/explanation.php">Toelichting</a>
</nav>

<!-- Admin menu -->
<nav class="card admin-nav" style="margin:.5rem 0 1rem; padding:.5rem 1rem;">
  <a href="dashboard.php"><strong>Dashboard</strong></a> ·
  <a href="pilots.php">Pilots</a> ·
  <a href="world_points.php">World points</a> ·
  <a href="competition_upload.php">Competition upload</a> ·
  <a href="memories.php">Memories</a>
</nav>

<main class="card">
  <h2>Dashboard</h2>
  <?php if (!empty($admin_notice)): ?><div class="alert success"><?= h($admin_notice) ?></div><?php endif; ?>
  <?php if (!empty($admin_error)): ?><div class="alert error"><?= h($admin_error) ?></div><?php endif; ?>

  <!-- Latest memory -->
  <section>
    <h3>Laatste herinnering</h3>
    <?php if (!$latestMemory): ?>
      <p class="muted">Nog geen herinneringen gevonden.</p>
    <?php else: ?>
      <?php
        $memLink = '../public/competition.php?id='.(int)$latestMemory['competition_id'].'#memories';
        $compLabel = h(($latestMemory['comp_title'] ?: 'Wedstrijd').' – '.(int)$latestMemory['comp_year'].' ('.($latestMemory['comp_class'] ?: '').')');
        $snippet = trim((string)($latestMemory['body'] ?: ''));
        $fullLen = mb_strlen($snippet);
        if ($fullLen > 350) { $snippet = mb_substr($snippet, 0, 350).'…'; }
        $isVis = isset($latestMemory['is_visible']) ? (int)$latestMemory['is_visible'] : 1;
      ?>
      <article class="card memory-card" style="padding: .75rem;">
        <header>
          <strong><?= h($latestMemory['title'] ?: 'Herinnering') ?></strong><br>
          <small class="muted">
            Door <?= h($latestMemory['author_name']) ?> — <?= h(date('Y-m-d', strtotime($latestMemory['created_at']))) ?>
            · <a href="<?= h($memLink) ?>"><?= $compLabel ?></a>
            · Status: <?= $isVis ? 'Zichtbaar' : 'Verborgen' ?>
          </small>
        </header>
        <?php if (!empty($latestMemory['photo_path'])): ?>
          <div style="margin:.5rem 0;">
            <a href="<?= h($memLink) ?>"><img src="<?= h('../public/'.$latestMemory['photo_path']) ?>" alt="foto herinnering"></a>
          </div>
        <?php endif; ?>
        <p><?= nl2br(h($snippet)) ?> <?php if ($fullLen > 350): ?><a href="<?= h($memLink) ?>">lees verder</a><?php endif; ?></p>

        <form method="post" class="inline" style="margin-top:.5rem;">
          <input type="hidden" name="mem_admin_action" value="moderate">
          <input type="hidden" name="memory_id" value="<?= (int)$latestMemory['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <?php if ($isVis): ?>
            <button class="btn" name="target" value="hide" type="submit">Verbergen</button>
          <?php else: ?>
            <button class="btn" name="target" value="show" type="submit">Zichtbaar maken</button>
          <?php endif; ?>
          <a href="memories.php">Open memories-beheer</a>
        </form>
      </article>
    <?php endif; ?>
  </section>

  <!-- Admin stats -->
  <section style="margin-top:1rem;">
    <h3>Statistieken</h3>
    <div class="statgrid">
      <div class="stat"><div class="muted">Piloten (actief/totaal)</div><div><strong><?= (int)$stats['pilots_active'] ?>/<?= (int)$stats['pilots_total'] ?></strong></div></div>
      <div class="stat"><div class="muted">Wedstrijden (totaal)</div><div><strong><?= (int)$stats['competitions_total'] ?></strong></div></div>
      <div class="stat"><div class="muted">Klasse 1 wedstrijden</div><div><strong><?= (int)$stats['competitions_k1'] ?></strong></div></div>
      <div class="stat"><div class="muted">Sportklasse wedstrijden</div><div><strong><?= (int)$stats['competitions_sc'] ?></strong></div></div>
      <div class="stat"><div class="muted">Herinneringen (30d / totaal)</div><div><strong><?= (int)$stats['memories_30d'] ?>/<?= (int)$stats['memories_total'] ?></strong></div></div>
      <div class="stat"><div class="muted">WPRS-rijen <?= (int)$currentYear ?> (K1)</div><div><strong><?= (int)$stats['wprs_k1_year'] ?></strong></div></div>
      <div class="stat"><div class="muted">WPRS-rijen <?= (int)$currentYear ?> (SC)</div><div><strong><?= (int)$stats['wprs_sc_year'] ?></strong></div></div>
      <?php if (!empty($latestComp)): ?>
        <div class="stat">
          <div class="muted">Laatste wedstrijd</div>
          <div><strong><?= h($latestComp['title']) ?> – <?= (int)$latestComp['year'] ?></strong></div>
          <div class="muted"><?= h($latestComp['class'] ?: '') ?><?php if (!empty($latestComp['created_at'])): ?> · <?= h(date('Y-m-d', strtotime($latestComp['created_at']))) ?><?php endif; ?></div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($DEBUG): ?>
    <p class="muted" style="margin-top:1rem;"><small>Debug: stats loaded for year <?= (int)$currentYear ?>.</small></p>
  <?php endif; ?>

</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Admin – Ranglijst Deltavliegen</p>
</footer>
</body>
</html>
