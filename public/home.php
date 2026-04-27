<?php
$DEBUG = isset($_GET['debug']);
if ($DEBUG) { @ini_set('display_errors','1'); @error_reporting(E_ALL); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

try { $pdo = db(); } catch (Throwable $e) {
  http_response_code(500);
  if ($DEBUG) echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>';
  exit;
}

// ---------- Helpers ----------
function load_pilot_names(PDO $pdo, array $pilot_ids) {
  $names = [];
  if (empty($pilot_ids)) return $names;
  $pilot_ids = array_values(array_unique(array_map('intval', $pilot_ids)));
  $in = implode(',', array_fill(0, count($pilot_ids), '?'));
  $tables = ['rankings_pilots', 'pilots'];
  foreach ($tables as $t) {
    try {
      $st = $pdo->prepare("SELECT id, name FROM $t WHERE id IN ($in)");
      $st->execute($pilot_ids);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $names[(int)$r['id']] = $r['name']; }
      if (!empty($names)) break;
    } catch (Throwable $e) {}
  }
  return $names;
}
function find_nk_competition_id(PDO $pdo, $class, $year) {
  $st = $pdo->prepare("SELECT id FROM rankings_competitions WHERE class = ? AND year = ? AND (title LIKE 'NK %' OR title LIKE 'NK%') ORDER BY id DESC LIMIT 1");
  $st->execute([$class, $year]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : null;
}
function get_positions_from_comp(PDO $pdo, $competition_id) {
  $st = $pdo->prepare("SELECT pilot_id, pilot_name, total FROM rankings_competition_results WHERE competition_id = ? ORDER BY CAST(total AS DECIMAL(16,6)) DESC, id ASC");
  $st->execute([$competition_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $participants = count($rows);
  $posByPilot = []; $nameByPilot = [];
  $rank = 0; $prevTotal = null; $shownCount = 0;
  foreach ($rows as $r) {
    $shownCount++; $total = (float)$r['total'];
    if ($prevTotal === null || $total < $prevTotal - 1e-9) { $rank = $shownCount; $prevTotal = $total; }
    $pid = $r['pilot_id'] !== null ? (int)$r['pilot_id'] : null;
    if ($pid !== null) { $posByPilot[$pid] = $rank; $nameByPilot[$pid] = $r['pilot_name']; }
  }
  return [$posByPilot, $participants, $nameByPilot];
}
function latest_complete_year(PDO $pdo, $class) {
  try {
    $rs = $pdo->prepare("SELECT DISTINCT year FROM rankings_world_points WHERE class = ? ORDER BY year DESC");
    $rs->execute([$class]);
    $years = array_map('intval', $rs->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) { return null; }
  foreach ($years as $y) {
    $nkY = find_nk_competition_id($pdo, $class, $y);
    $nkPrev = find_nk_competition_id($pdo, $class, $y - 1);
    if ($nkY && $nkPrev) return $y;
  }
  return null;
}
function compute_ranking(PDO $pdo, $class, $year) {
  $result = [];
  $nkId = find_nk_competition_id($pdo, $class, $year);
  $nkPrevId = find_nk_competition_id($pdo, $class, $year - 1);
  if (!$nkId || !$nkPrevId) return [[], $year, null, null];
  list($posY, $cntY, $nameY) = get_positions_from_comp($pdo, $nkId);
  list($posP, $cntP, $nameP) = get_positions_from_comp($pdo, $nkPrevId);
  if ($cntY <= 0 || $cntP <= 0) return [[], $year, null, null];
  $wprs = []; $maxPts = 0.0;
  try {
    $st = $pdo->prepare("SELECT pilot_id, points FROM rankings_world_points WHERE class = ? AND year = ?");
    $st->execute([$class, $year]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $pid = (int)$r['pilot_id']; $pts = (float)$r['points']; $wprs[$pid] = $pts; if ($pts > $maxPts) $maxPts = $pts; }
  } catch (Throwable $e) {}
  if ($maxPts <= 0) return [[], $year, null, null];
  $pilot_ids = array_unique(array_merge(array_keys($posY), array_keys($posP), array_keys($wprs)));
  if (empty($pilot_ids)) return [[], $year, null, null];
  $names = load_pilot_names($pdo, $pilot_ids);
  foreach ($pilot_ids as $pid) { if (!isset($names[$pid])) { $names[$pid] = $nameY[$pid] ?? ($nameP[$pid] ?? 'Piloot #'.$pid); } }
  foreach ($pilot_ids as $pid) {
    $nk_score = isset($posY[$pid]) ? (100.0 - (($posY[$pid]-1) * (100.0 / $cntY))) : 0.0;
    $nk_prev_score = isset($posP[$pid]) ? (100.0 - (($posP[$pid]-1) * (100.0 / $cntP))) : 0.0;
    $wprs_score = isset($wprs[$pid]) && $maxPts > 0 ? (100.0 * ($wprs[$pid] / $maxPts)) : 0.0;
    $total = $nk_score + 0.5 * $nk_prev_score + 1.5 * $wprs_score;
    $result[] = ['pilot_id'=>$pid,'name'=>$names[$pid],'nk'=>$nk_score,'nk_prev'=>$nk_prev_score,'wprs'=>$wprs_score,'total'=>$total];
  }
  usort($result, function($a,$b){ if ($a['total']==$b['total']) return strcmp($a['name'],$b['name']); return ($a['total']<$b['total'])?1:-1; });
  return [$result, $year, $cntY, $cntP];
}

// ---------- Utilities for dynamic column detection ----------
function first_existing_column(PDO $pdo, $table, array $candidates) {
  $cols = [];
  try {
    $rs = $pdo->query("SHOW COLUMNS FROM `$table`");
    if ($rs) {
      foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[strtolower($r['Field'])] = $r['Field'];
      }
    }
  } catch (Throwable $e) { return null; }
  foreach ($candidates as $cand) {
    $lc = strtolower($cand);
    if (isset($cols[$lc])) return $cols[$lc];
  }
  return null;
}

// ---------- Data ----------
$k1_year = latest_complete_year($pdo, 'Klasse 1');
$k1_top = []; $k1_note = '';
if ($k1_year !== null) { list($rankings, $_y, $_cntY, $_cntP) = compute_ranking($pdo, 'Klasse 1', $k1_year); $k1_top = array_slice($rankings, 0, 4); }
else { $k1_note = 'Nog geen complete gegevens gevonden.'; }

$sk_year = latest_complete_year($pdo, 'Sportklasse');
$sk_top = []; $sk_note = '';
if ($sk_year !== null) { list($rankings, $_y2, $_cntY2, $_cntP2) = compute_ranking($pdo, 'Sportklasse', $sk_year); $sk_top = array_slice($rankings, 0, 4); }
else { $sk_note = 'Nog geen complete gegevens gevonden.'; }

$latest_comps = [];
try {
  $rs = $pdo->query("SELECT id, year, title, class FROM rankings_competitions ORDER BY year DESC, created_at DESC LIMIT 4");
  if ($rs) $latest_comps = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ------- Memories (visible), dynamic column resolution -------
$latest_memories = [];
$mem_table = 'rankings_competition_memories';
$mem_text_col = first_existing_column($pdo, $mem_table, ['text','content','message','body','memo','description','comment','comments','note','notes']);
$mem_author_col = first_existing_column($pdo, $mem_table, ['author','name','author_name','user','username','posted_by','poster','created_by']);
$mem_created_col = first_existing_column($pdo, $mem_table, ['created_at','created','date','timestamp','ts','posted_at','updated_at']);
$mem_visible_col = first_existing_column($pdo, $mem_table, ['is_visible','visible','is_published','published','approved']);
$mem_photo_col = first_existing_column($pdo, $mem_table, ['photo_path','image_path','image','photo','picture','file','file_path','filepath','url','src']);

if ($DEBUG) {
  echo '<div class="card" style="margin:1rem 0; padding:.5rem 1rem;"><strong>[debug] memories columns</strong>: ';
  echo 'text=' . h($mem_text_col ?: '—') . ', author=' . h($mem_author_col ?: '—') . ', created=' . h($mem_created_col ?: '—') . ', visible=' . h($mem_visible_col ?: '—') . ', photo=' . h($mem_photo_col ?: '—');
  echo '</div>';
}

$author_expr = $mem_author_col ? "m.`$mem_author_col` AS mem_author" : "'' AS mem_author";
$text_expr   = $mem_text_col   ? "m.`$mem_text_col` AS mem_text"   : "'' AS mem_text";
$created_sel = $mem_created_col ? "m.`$mem_created_col` AS mem_created" : "NULL AS mem_created";
$created_ord = $mem_created_col ? "m.`$mem_created_col`" : "NULL";
$where_vis   = $mem_visible_col ? "m.`$mem_visible_col` = 1" : "1=1";

try {
  $sql = "SELECT m.id AS mem_id, m.competition_id AS mem_competition_id, $author_expr, $text_expr, $created_sel
          FROM $mem_table m
          WHERE $where_vis
          ORDER BY COALESCE($created_ord, m.id) DESC
          LIMIT 4";
  $rs = $pdo->query($sql);
  if ($rs !== false) { $latest_memories = $rs->fetchAll(PDO::FETCH_ASSOC); }
} catch (Throwable $e) {
  if ($DEBUG) echo '<p class="muted">[debug] memories query error: '.h($e->getMessage()).'</p>';
}

// ------- Background slideshow images from memories.photo_path (or similar) -------
$bgImages = [];
try {
  if ($mem_photo_col) {
    $order_expr = $mem_created_col ? "m.`$mem_created_col`" : "m.id";
    $imgExt = "(jpg|jpeg|png|webp|gif)";
    $sqlBG = "SELECT m.`$mem_photo_col` AS img_path
              FROM $mem_table m
              WHERE $where_vis
                AND m.`$mem_photo_col` IS NOT NULL
                AND m.`$mem_photo_col` <> ''
                AND LOWER(m.`$mem_photo_col`) REGEXP '\\\\.$imgExt$'
              ORDER BY COALESCE($order_expr, m.id) DESC
              LIMIT 12";
    $rsBG = $pdo->query($sqlBG);
    if ($rsBG !== false) {
      $bgImages = array_map(function($r){ return $r['img_path']; }, $rsBG->fetchAll(PDO::FETCH_ASSOC));
    }
  }
} catch (Throwable $e) {
  if ($DEBUG) echo '<p class="muted">[debug] bg images error: '.h($e->getMessage()).'</p>';
}
// Normalize paths
$bgImages = array_values(array_filter(array_map(function($p){
  $p = (string)$p;
  if ($p === '') return null;
  if (preg_match('~^https?://~i', $p)) return $p;
  return ltrim($p, '/');
}, $bgImages)));

// Site stats
$stats = ['competitions'=>0, 'pilots'=>0, 'memories'=>0, 'results'=>0];
try { $stats['competitions'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions")->fetchColumn(); } catch (Throwable $e) {}
try {
  $stats['pilots'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_pilots")->fetchColumn();
} catch (Throwable $e) {
  try { $stats['pilots'] = (int)$pdo->query("SELECT COUNT(*) FROM pilots")->fetchColumn(); } catch (Throwable $e2) {}
}
try { $stats['memories'] = (int)$pdo->query("SELECT COUNT(*) FROM $mem_table")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['results'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competition_results")->fetchColumn(); } catch (Throwable $e) {}

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – Home</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1rem; }
    .tile { padding: 1rem 1.25rem; }
    .tile h3 { margin: 0 0 .5rem; font-size: 1.25rem; }
    .tile .muted { color: #374151; }
    .list-compact li { display:flex; justify-content:space-between; padding:.25rem 0; border-bottom: 1px dashed #e5e7eb; }
    .list-compact li:last-child { border-bottom: 0; }
    .kicker { font-weight:600; letter-spacing:.02em; color:#374151; }
    .stat-grid { display:grid; grid-template-columns: repeat(2, minmax(120px,1fr)); gap:.75rem; }
    .stat { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: .75rem; padding:.75rem 1rem; }
    .stat .value { font-size:1.5rem; font-weight:700; color:#374151; }
    .stat .muted { color:#374151; }

    /* --- Background slideshow --- */
    #bg-slideshow { position: fixed; inset: 0; z-index: -3; overflow: hidden; }
    #bg-slideshow .slide {
      position: absolute; inset: 0;
      background-size: cover;
      background-position: center center;
      opacity: 0;
      filter: grayscale(10%) contrast(95%) brightness(0.55) saturate(90%);
      animation-name: bgFade;
      animation-duration: 40s;
      animation-timing-function: linear;
      animation-iteration-count: infinite;
    }
    .bg-overlay { position: fixed; inset: 0; background: rgba(17,24,39,0.40); z-index: -2; pointer-events: none; }

    @keyframes bgFade {
      0% { opacity: 0; }
      5% { opacity: 1; }
      20% { opacity: 1; }
      25% { opacity: 0; }
      100% { opacity: 0; }
    }
      body.container { background: rgba(0, 0, 0, 0); }
      .card { background: rgba(0, 0, 0, 0.65); }
  </style>
</head>
<body class="container">
<?php if (!empty($bgImages)): ?>
<div id="bg-slideshow">
  <?php
    $delay = 0;
    $step = 8; // seconds between slides
    foreach ($bgImages as $idx => $img):
      $delay = $idx * $step;
  ?>
    <div class="slide" style="background-image:url('<?= h($img) ?>'); animation-delay: <?= (int)$delay ?>s;"></div>
  <?php endforeach; ?>
</div>
<div class="bg-overlay"></div>
<?php endif; ?>

<header class="topbar">
  <h1><a href="home.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php"><strong>Home</strong></a> ·
  <a href="ranking.php">Klasse 1</a> ·
  <a href="sportclass.php">Sportklasse</a> ·
  <a href="competitionlist.php">Wedstrijden</a> ·
  <a href="explanation.php">Toelichting</a>
</nav>

<main class="tiles">
  <section class="card tile">
    <div class="kicker">Actuele top (Klasse 1 – <?= $k1_year ? (int)$k1_year : '—' ?>)</div>
    <h3>Top 4 Klasse 1</h3>
    <?php if (!empty($k1_top)): ?>
      <ol class="list-compact" style="list-style:none; padding:0; margin:.5rem 0 0;">
        <?php foreach ($k1_top as $i => $row): ?>
          <li>
            <span><?php if ($i==0): ?><b><?php endif; ?>
              <?= (int)($i+1) ?>. <a href="pilot.php?id=<?= (int)$row['pilot_id'] ?>"><?= h($row['name']) ?></a>
            <?php if ($i==0): ?></b><?php endif; ?></span>
            <span><?= number_format($row['total'], 1) ?></span>
          </li>
        <?php endforeach; ?>
      </ol>
      <div style="margin-top:.5rem;"><a class="btn" href="ranking.php?year=<?= (int)$k1_year ?>">Bekijk volledige ranglijst</a></div>
    <?php else: ?>
      <p class="muted"><?= h($k1_note) ?></p>
    <?php endif; ?>
  </section>

  <section class="card tile">
    <div class="kicker">Actuele top (Sportklasse – <?= $sk_year ? (int)$sk_year : '—' ?>)</div>
    <h3>Top 4 Sportklasse</h3>
    <?php if (!empty($sk_top)): ?>
      <ol class="list-compact" style="list-style:none; padding:0; margin:.5rem 0 0;">
        <?php foreach ($sk_top as $i => $row): ?>
          <li>
            <span><?php if ($i==0): ?><b><?php endif; ?>
              <?= (int)($i+1) ?>. <a href="pilot.php?id=<?= (int)$row['pilot_id'] ?>"><?= h($row['name']) ?></a>
            <?php if ($i==0): ?></b><?php endif; ?></span>
            <span><?= number_format($row['total'], 1) ?></span>
          </li>
        <?php endforeach; ?>
      </ol>
      <div style="margin-top:.5rem;"><a class="btn" href="sportclass.php?year=<?= (int)$sk_year ?>">Bekijk volledige ranglijst</a></div>
    <?php else: ?>
      <p class="muted"><?= h($sk_note) ?></p>
    <?php endif; ?>
  </section>

  <section class="card tile">
    <div class="kicker">Recent</div>
    <h3>Laatste 4 wedstrijden</h3>
    <?php if (!empty($latest_comps)): ?>
      <ul class="list-compact" style="list-style:none; padding:0; margin:.5rem 0 0;">
        <?php foreach ($latest_comps as $c): ?>
          <li>
            <span><?= isset($c['year']) ? (int)$c['year'] : 0 ?> · <a href="competition.php?id=<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a> (<?= h($c['class']) ?>)</span>
            <span>→</span>
          </li>
        <?php endforeach; ?>
      </ul>
      <div style="margin-top:.5rem;"><a class="btn" href="competitionlist.php">Alle wedstrijden</a></div>
    <?php else: ?>
      <p class="muted">Nog geen wedstrijden beschikbaar.</p>
    <?php endif; ?>
  </section>

  <section class="card tile">
    <div class="kicker">Collectief geheugen</div>
    <h3>Laatst toegevoegde herinneringen</h3>
    <?php if (!empty($latest_memories)): ?>
      <ul style="list-style:none; padding:0; margin:.5rem 0 0;">
        <?php foreach ($latest_memories as $m): ?>
          <li style="margin:.5rem 0; border-bottom:1px dashed #e5e7eb; padding-bottom:.5rem;">
            <div style="font-size:.85rem;color:#c5c7cb;">Door <?= h(($m['mem_author'] ?? '') !== '' ? $m['mem_author'] : 'Onbekend') ?></div>
            <div class="muted" style="font-weight:600;color:#e5e7eb;">
              <?php
              $sn = isset($m['mem_text']) ? (string)$m['mem_text'] : '';
              $snippet = strip_tags($sn);
              if (function_exists('mb_strimwidth')) { $snippet = mb_strimwidth($snippet, 0, 140, '…', 'UTF-8'); }
              else { if (strlen($snippet) > 140) $snippet = substr($snippet, 0, 137) . '…'; }
              echo h($snippet);
              ?>
            </div>
            <?php $cid = isset($m['mem_competition_id']) ? (int)$m['mem_competition_id'] : 0; ?>
            <?php if ($cid > 0): ?>
              <div style="margin-top:.25rem;"><a class="btn" href="competition.php?id=<?= $cid ?>">Bekijk wedstrijd</a></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted">Nog geen herinneringen geplaatst.</p>
      <?php if ($DEBUG): ?>
        <?php
          try {
            $totalMem = (int)$pdo->query("SELECT COUNT(*) FROM $mem_table")->fetchColumn();
            $visibleMem = (int)$pdo->query("SELECT COUNT(*) FROM $mem_table WHERE $where_vis")->fetchColumn();
            $ids = $pdo->query("SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM $mem_table WHERE $where_vis")->fetchColumn();
            echo '<p class="muted">[debug] Totaal in database: ' . $totalMem . ', zichtbaar: ' . $visibleMem . '.</p>';
            echo '<p class="muted">[debug] Zichtbare memory IDs: ' . h($ids) . '.</p>';
          } catch (Throwable $e) {}
        ?>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="card tile">
    <div class="kicker">Overzicht</div>
    <h3>Site statistieken</h3>
    <div class="stat-grid">
      <div class="stat">
        <div class="muted">Wedstrijden</div>
        <div class="value"><?= (int)$stats['competitions'] ?></div>
      </div>
      <div class="stat">
        <div class="muted">Piloten</div>
        <div class="value"><?= (int)$stats['pilots'] ?></div>
      </div>
      <div class="stat">
        <div class="muted">Herinneringen</div>
        <div class="value"><?= (int)$stats['memories'] ?></div>
      </div>
      <div class="stat">
        <div class="muted">Resultaatregels</div>
        <div class="value"><?= (int)$stats['results'] ?></div>
      </div>
    </div>
  </section>

  <section class="card tile">
    <div class="kicker">Site beheer</div>
    <h3>Contact</h3>
    Vragen en opmerkingen over deze webpagina kunnen worden gestuurd naar <a href="mailto:rob@intgroen.net">rob@intgroen.net</a>.
    </div>
  </section>
</main>

<footer class="muted" style="margin: 1.5rem 0;">
  <p>&copy; <?= date('Y') ?> Ranglijst Deltavliegen</p>
</footer>
</body>
</html>
