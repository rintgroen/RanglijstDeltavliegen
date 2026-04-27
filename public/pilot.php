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
if (!function_exists('findNkCompetitionId')) {
  function findNkCompetitionId(PDO $pdo, string $class, int $year) {
    try {
      $st = $pdo->prepare("SELECT id FROM rankings_competitions WHERE class = ? AND year = ? AND (title LIKE 'NK %' OR title LIKE 'NK%') ORDER BY id DESC LIMIT 1");
      $st->execute([$class, $year]);
      $id = $st->fetchColumn();
      return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
  }
}
if (!function_exists('loadNkPositions')) {
  function loadNkPositions(PDO $pdo, ?int $competition_id) {
    if (!$competition_id) return ['cid'=>null, 'participants'=>0, 'pos'=>[]];
    try {
      $st = $pdo->prepare("SELECT pilot_id, pilot_name, total FROM rankings_competition_results WHERE competition_id = ? ORDER BY CAST(total AS DECIMAL(16,6)) DESC, id ASC");
      $st->execute([$competition_id]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      $participants = count($rows);
      $posByPilot = [];
      $rank = 0; $prevTotal = null; $shown = 0;
      foreach ($rows as $r) {
        $shown++;
        $tot = (float)$r['total'];
        if ($prevTotal === null || $tot < $prevTotal - 1e-9) { $rank = $shown; $prevTotal = $tot; }
        $pid = $r['pilot_id'] !== null ? (int)$r['pilot_id'] : null;
        if ($pid !== null) $posByPilot[$pid] = $rank;
      }
      return ['cid'=>$competition_id, 'participants'=>$participants, 'pos'=>$posByPilot];
    } catch (Throwable $e) {
      return ['cid'=>$competition_id, 'participants'=>0, 'pos'=>[]];
    }
  }
}
if (!function_exists('nationalsScore')) {
  function nationalsScore(?int $position, int $participants) {
    if (!$position || $participants <= 0) return 0.0;
    return 100.0 - (($position - 1) * (100.0 / $participants)); // 100 * (1 - (pos-1)/participants)
  }
}
if (!function_exists('wprsScore')) {
  function wprsScore(?float $pts, float $maxPts) {
    if ($pts === null || $maxPts <= 0) return 0.0;
    return 100.0 * ($pts / $maxPts);
  }
}
function latestYearsForPilot(PDO $pdo, int $pilotId) {
  // Gather years from competitions (both classes) and world points
  $years = [];
  try {
    $st = $pdo->query("SELECT DISTINCT year FROM rankings_competitions ORDER BY year DESC");
    if ($st) $years = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {}
  try {
    $st = $pdo->query("SELECT DISTINCT year FROM rankings_world_points ORDER BY year DESC");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $y) {
        $y = (int)$y; if (!in_array($y, $years, true)) $years[] = $y;
      }
    }
  } catch (Throwable $e) {}
  rsort($years);
  return $years;
}
function computeYearRank(PDO $pdo, int $year, string $class, int $pilotId) {
  $isHistoric = ($year <= 2008);
  $nkCurId  = findNkCompetitionId($pdo, $class, $year);
  $nkPrevId = findNkCompetitionId($pdo, $class, $year-1);
  $nkCur  = loadNkPositions($pdo, $nkCurId);
  $nkPrev = loadNkPositions($pdo, $nkPrevId);
  $nkPrev2 = ['participants'=>0, 'pos'=>[]];
  if ($isHistoric) {
    $nkPrev2Id = findNkCompetitionId($pdo, $class, $year-2);
    $nkPrev2   = loadNkPositions($pdo, $nkPrev2Id);
  }

  $maxWprs = 0.0; $wprs = [];
  if (!$isHistoric) {
    try {
      $mx = $pdo->prepare("SELECT MAX(points) FROM rankings_world_points WHERE year = ? AND class = ?");
      $mx->execute([$year, $class]);
      $maxWprs = (float)($mx->fetchColumn() ?: 0);
      $stW = $pdo->prepare("SELECT pilot_id, points FROM rankings_world_points WHERE year = ? AND class = ?");
      $stW->execute([$year, $class]);
      foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $w) { $wprs[(int)$w['pilot_id']] = (float)$w['points']; }
    } catch (Throwable $e) {}
  }

  // Gate
  if ($isHistoric) {
    if (!($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $nkPrev2['participants'] > 0)) return null;
  } else {
    if (!($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $maxWprs > 0.0)) return null;
  }

  // Compute totals for all active pilots
  $totals = []; $nameMap = [];
  try {
    $stP = $pdo->query("SELECT id, name FROM rankings_pilots WHERE active = 1");
    $pilots = $stP ? $stP->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($pilots as $p) { $nameMap[(int)$p['id']] = $p['name']; }
    foreach ($nameMap as $pid => $_) {
      $posCur  = $nkCur['pos'][$pid]  ?? null;
      $posPrev = $nkPrev['pos'][$pid] ?? null;
      $nsCur   = nationalsScore($posCur,  $nkCur['participants']);
      $nsPrev  = nationalsScore($posPrev, $nkPrev['participants']);
      if ($isHistoric) {
        $posPrev2 = $nkPrev2['pos'][$pid] ?? null;
        $nsPrev2  = nationalsScore($posPrev2, $nkPrev2['participants']);
        if ($nsCur>0 || $nsPrev>0 || $nsPrev2>0) {
          $totals[$pid] = $nsCur + 0.8*$nsPrev + 0.6*$nsPrev2;
        }
      } else {
        $wpPts = $wprs[$pid] ?? null;
        $ws    = wprsScore($wpPts, $maxWprs);
        if ($nsCur>0 || $nsPrev>0 || $ws>0) {
          $totals[$pid] = $nsCur + 0.5*$nsPrev + 1.5*$ws;
        }
      }
    }
  } catch (Throwable $e) {}

  if (empty($totals) || !isset($totals[$pilotId])) return null;

  // Sort to determine rank
  $rows = [];
  foreach ($totals as $id=>$tot) {
    $rows[] = ['id'=>$id, 'total'=>$tot, 'name'=>$nameMap[$id] ?? ''];
  }
  usort($rows, function($a,$b){
    if ($a['total'] == $b['total']) return strcasecmp($a['name'],$b['name']);
    return ($a['total'] < $b['total']) ? 1 : -1;
  });
  $rank = 1;
  foreach ($rows as $i=>$row) { if ($row['id'] == $pilotId) { $rank = $i+1; break; } }
  return $rank;
}

// ---------- Inputs ----------
$pilotId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pilotId <= 0) {
  http_response_code(400);
  echo "<p>Geen piloot gespecificeerd.</p>";
  exit;
}

// Load pilot info (name, civl_id)
$pilot = null;
try {
  $st = $pdo->prepare("SELECT id, name, civl_id FROM rankings_pilots WHERE id = ?");
  $st->execute([$pilotId]);
  $pilot = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!$pilot) {
  echo "<p>Piloot niet gevonden.</p>";
  exit;
}
$pilotName = $pilot['name'];
$civlId = $pilot['civl_id'] ?? '';

// Which years/classes to show
$allYears = latestYearsForPilot($pdo, $pilotId);
$classes = ['Klasse 1', 'Sportklasse'];

// ---------- Build table rows per (year, class) ----------
$rows = []; // list of assoc rows to render
foreach ($allYears as $y) {
  foreach ($classes as $cls) {
    $isHistoric = ($y <= 2008);
    // Resolve NKs
    $nkCurId  = findNkCompetitionId($pdo, $cls, $y);
    $nkPrevId = findNkCompetitionId($pdo, $cls, $y-1);
    $nkCur  = loadNkPositions($pdo, $nkCurId);
    $nkPrev = loadNkPositions($pdo, $nkPrevId);

    if ($isHistoric) {
      $nkPrev2Id = findNkCompetitionId($pdo, $cls, $y-2);
      $nkPrev2   = loadNkPositions($pdo, $nkPrev2Id);

      // Gate
      if (!($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $nkPrev2['participants'] > 0)) continue;

      // Components for this pilot
      $posCur   = $nkCur['pos'][$pilotId]  ?? null;
      $posPrev  = $nkPrev['pos'][$pilotId] ?? null;
      $posPrev2 = $nkPrev2['pos'][$pilotId]?? null;

      $nsCur    = nationalsScore($posCur,   $nkCur['participants']);
      $nsPrev   = nationalsScore($posPrev,  $nkPrev['participants']);
      $nsPrev2  = nationalsScore($posPrev2, $nkPrev2['participants']);

      if (!($nsCur>0 || $nsPrev>0 || $nsPrev2>0)) continue;

      $colCur   = $nsCur;
      $colPrev  = 0.8 * $nsPrev;
      $colPrev2 = 0.6 * $nsPrev2;
      $total    = $colCur + $colPrev + $colPrev2;

      // Rank within class/year
      $rank = computeYearRank($pdo, $y, $cls, $pilotId);

      $rows[] = [
        'year'=>$y, 'class'=>$cls, 'rank'=>$rank, 'historic'=>true,
        'nk_cur'=>$colCur,  'pos_cur'=>$posCur,
        'nk_prev'=>$colPrev,'pos_prev'=>$posPrev,
        'nk_prev2'=>$colPrev2,'pos_prev2'=>$posPrev2,
        'wprs'=>null,'wprs_raw'=>null,
        'total'=>$total
      ];
    } else {
      // Modern: include WPRS
      // Gate with WPRS availability
      $maxWprs = 0.0;
      try {
        $mx = $pdo->prepare('SELECT MAX(points) FROM rankings_world_points WHERE year = ? AND class = ?');
        $mx->execute([$y, $cls]);
        $maxWprs = (float)($mx->fetchColumn() ?: 0);
      } catch (Throwable $e) {}
      if (!($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $maxWprs > 0.0)) continue;

      $wpPts = null;
      try {
        $stW = $pdo->prepare('SELECT points FROM rankings_world_points WHERE year = ? AND class = ? AND pilot_id = ?');
        $stW->execute([$y, $cls, $pilotId]);
        $wpPts = $stW->fetchColumn();
        $wpPts = $wpPts !== false ? (float)$wpPts : null;
      } catch (Throwable $e) {}

      $posCur  = $nkCur['pos'][$pilotId]  ?? null;
      $posPrev = $nkPrev['pos'][$pilotId] ?? null;
      $nsCur   = nationalsScore($posCur,  $nkCur['participants']);
      $nsPrev  = nationalsScore($posPrev, $nkPrev['participants']);
      $ws      = wprsScore($wpPts, $maxWprs);
      if (!($nsCur>0 || $nsPrev>0 || $ws>0)) continue;

      $colCur  = $nsCur;
      $colPrev = 0.5 * $nsPrev;
      $colW    = 1.5 * $ws;
      $total   = $colCur + $colPrev + $colW;

      $rank = computeYearRank($pdo, $y, $cls, $pilotId);

      $rows[] = [
        'year'=>$y, 'class'=>$cls, 'rank'=>$rank, 'historic'=>false,
        'nk_cur'=>$colCur, 'pos_cur'=>$posCur,
        'nk_prev'=>$colPrev, 'pos_prev'=>$posPrev,
        'wprs'=>$colW, 'wprs_raw'=>$wpPts,
        'total'=>$total
      ];
    }
  }
}

// Sort rows by year desc, then by class (Klasse 1 first)
usort($rows, function($a,$b){
  if ($a['year'] == $b['year']) {
    if ($a['class'] == $b['class']) return 0;
    return $a['class'] == 'Klasse 1' ? -1 : 1;
  }
  return ($a['year'] < $b['year']) ? 1 : -1;
});

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – <?= h($pilotName) ?></title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="container">
<header class="topbar">
  <h1><a href="home.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php">Home</a> ·
  <a href="ranking.php">Klasse 1</a> ·
  <a href="sportclass.php">Sportklasse</a> ·
  <a href="competitionlist.php">Wedstrijden</a> ·
  <a href="explanation.php">Toelichting</a>
</nav>

<section class="card" style="padding:1rem;">
  <h2 style="margin:.25rem 0 0;"><?= h($pilotName) ?></h2>
  <?php if (!empty($civlId)): ?>
  <p class="muted" style="margin:.25rem 0 0;">
    <a href="https://civlcomps.org/pilot/<?= urlencode($civlId) ?>/ranking" target="_blank" rel="noopener">Wereldranglijst-profiel (CIVL)</a>
  </p>
  <?php endif; ?>
</section>

<section class="card" style="padding:1rem; margin-top:1rem;">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Jaar</th>
          <th>Klasse</th>
          <th>Rang</th>
          <th>NK(jaar)</th>
          <th>Vorig NK(jaar-1)</th>
          <th>WPRS(jaar)/NK(jaar-2)</th>
          <th>Totaal</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="muted">Geen gegevens om te tonen.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>
              <?php if ($r['class'] === 'Klasse 1'): ?>
                <a href="ranking.php?year=<?= (int)$r['year'] ?>"><?= (int)$r['year'] ?></a>
              <?php else: ?>
                <a href="sportclass.php?year=<?= (int)$r['year'] ?>"><?= (int)$r['year'] ?></a>
              <?php endif; ?>
            </td>
            <td><?= h($r['class']) ?></td>
            <td><?= $r['rank'] !== null ? (int)$r['rank'] : '—' ?></td>
            <td><?= number_format($r['nk_cur'],2) ?><?= !empty($r['pos_cur']) ? " <span class='muted'>(pos ".(int)$r['pos_cur'].")</span>" : '' ?></td>
            <td><?= number_format($r['nk_prev'],2) ?><?= !empty($r['pos_prev']) ? " <span class='muted'>(pos ".(int)$r['pos_prev'].")</span>" : '' ?></td>
            <?php if (!empty($r['historic'])): ?>
              <td><?= number_format($r['nk_prev2'],2) ?><?= !empty($r['pos_prev2']) ? " <span class='muted'>(pos ".(int)$r['pos_prev2'].")</span>" : '' ?></td>
            <?php else: ?>
              <td>
                <?= number_format($r['wprs'],2) ?>
                <?php if (isset($r['wprs_raw']) && $r['wprs_raw'] !== null): ?>
                  <span class='muted'>(WPRS <?= rtrim(rtrim(number_format((float)$r['wprs_raw'], 3, '.', ''), '0'), '.') ?>)</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td><strong><?= number_format($r['total'],2) ?></strong></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($DEBUG): ?>
<section class="card" style="padding:1rem; margin-top:1rem;">
  <h3>Debug</h3>
  <pre><?php
    echo 'Pilot ID: ', $pilotId, ' (', $pilotName, ')', PHP_EOL;
    echo 'Years considered: ', implode(', ', $allYears), PHP_EOL;
    echo 'Row count: ', count($rows), PHP_EOL;
  ?></pre>
</section>
<?php endif; ?>

<footer class="muted" style="margin:1.5rem 0;">
  <p>&copy; <?= date('Y') ?> Ranglijst Deltavliegen</p>
</footer>
</body>
</html>
