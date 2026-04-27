<?php
// Force errors in debug mode as early as possible
$DEBUG = isset($_GET['debug']);
if ($DEBUG) { @ini_set('display_errors','1'); @error_reporting(E_ALL); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function nationalsScore($pos, $participants) {
  $pos = $pos ? (int)$pos : 0;
  $participants = (int)$participants;
  if ($pos <= 0 || $participants <= 0) return 0.0;
  $s = 100 - ($pos - 1) * (100.0 / $participants);
  return $s < 0 ? 0.0 : $s;
}

function wprsScore($pts, $maxPts) {
  if ($pts === null) return 0.0;
  $maxPts = (float)$maxPts;
  if ($maxPts <= 0.0) return 0.0;
  return 100.0 * ((float)$pts / $maxPts);
}

try {
  $pdo = db();
} catch (Exception $e) {
  http_response_code(500);
  if ($DEBUG) echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>';
  exit;
}

// Build year list from Klasse 1 NK competitions
$years = array();
try {
  $q = $pdo->query("SELECT DISTINCT year FROM rankings_competitions WHERE class='Sportklasse' AND title LIKE 'NK %' ORDER BY year DESC");
  if ($q) {
    $tmp = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tmp as $row) $years[] = (int)$row['year'];
  }
} catch (Exception $e) {
  if ($DEBUG) echo '<pre>Year list failed: '.h($e->getMessage()).'</pre>';
}
$defaultYear = !empty($years) ? (int)$years[0] : (int)date('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $defaultYear;

// Resolve NK competitions (current & previous year)
function findNkCompetitionId($pdo, $y) {
  try {
    $st = $pdo->prepare("SELECT id FROM rankings_competitions WHERE class='Sportklasse' AND year=? AND title = ? ORDER BY created_at DESC LIMIT 1");
    $st->execute(array($y, 'NK '.$y));
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $st2 = $pdo->prepare("SELECT id FROM rankings_competitions WHERE class='Sportklasse' AND year=? AND title LIKE 'NK %' ORDER BY created_at DESC LIMIT 1");
    $st2->execute(array($y));
    $id2 = $st2->fetchColumn();
    if ($id2) return (int)$id2;
  } catch (Exception $e) { /* ignore here */ }
  return null;
}

$nkCurId  = findNkCompetitionId($pdo, $year);
$nkPrevId = findNkCompetitionId($pdo, $year-1);

// Load NK results and compute tie-aware positions + participants
function loadNkPositions($pdo, $competitionId) {
  $res = array('participants'=>0, 'pos'=>array(), 'cid'=>null);
  if (!$competitionId) return $res;
  $res['cid'] = (int)$competitionId;
  try {
    $st = $pdo->prepare("SELECT pilot_id, total FROM rankings_competition_results WHERE competition_id=? ORDER BY CAST(total AS DECIMAL(16,6)) DESC, id ASC");
    $st->execute(array($competitionId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    return $res;
  }
  $participants = count($rows);
  $posMap = array();
  $rank = 0;
  $i = 0;
  $prevTotal = null;
  foreach ($rows as $r) {
    $i++;
    $total = is_numeric($r['total']) ? (float)$r['total'] : 0.0;
    if ($prevTotal === null || $total < $prevTotal - 1e-9 || $total > $prevTotal + 1e-9) {
      $rank = $i;  // competition ranking: 1,2,2,4,...
      $prevTotal = $total;
    }
    $pid = isset($r['pilot_id']) && $r['pilot_id'] !== null ? (int)$r['pilot_id'] : 0;
    if ($pid > 0 && !isset($posMap[$pid])) $posMap[$pid] = $rank;
  }
  $res['participants'] = $participants;
  $res['pos'] = $posMap;
  return $res;
}

$nkCur  = loadNkPositions($pdo, $nkCurId);
$nkPrev = loadNkPositions($pdo, $nkPrevId);

// Max WPRS for Klasse 1 (selected year)
$maxWprs = 0.0;
try {
  $mx = $pdo->prepare('SELECT MAX(points) FROM rankings_world_points WHERE year = ? AND class = "Sportklasse"');
  $mx->execute(array($year));
  $maxWprs = (float)($mx->fetchColumn() ?: 0);
} catch (Exception $e) {
  if ($DEBUG) echo '<pre>Max WPRS failed: '.h($e->getMessage()).'</pre>';
}

// Availability: both NKs found & have participants + some WPRS data
$available = ($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $maxWprs > 0.0);

// Compute rows
$rows = array();
if ($available) {
  try {
    // Preload WPRS for this year
    $wprs = array();
    $stW = $pdo->prepare('SELECT pilot_id, points FROM rankings_world_points WHERE year = ? AND class = "Sportklasse"');
    $stW->execute(array($year));
    foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $w) {
      $wprs[(int)$w['pilot_id']] = (float)$w['points'];
    }

    $stP = $pdo->query('SELECT id, name FROM rankings_pilots WHERE active=1 ORDER BY name');
    $pilots = $stP ? $stP->fetchAll(PDO::FETCH_ASSOC) : array();

    foreach ($pilots as $p) {
      $pid = (int)$p['id'];
      $name = $p['name'];

      $posCur  = isset($nkCur['pos'][$pid])  ? (int)$nkCur['pos'][$pid]  : null;
      $posPrev = isset($nkPrev['pos'][$pid]) ? (int)$nkPrev['pos'][$pid] : null;

      $nsCur   = nationalsScore($posCur,  $nkCur['participants']);
      $nsPrev  = nationalsScore($posPrev, $nkPrev['participants']);
      $wpPts   = isset($wprs[$pid]) ? (float)$wprs[$pid] : null;
      $ws      = wprsScore($wpPts, $maxWprs);

      if ($nsCur>0 || $nsPrev>0 || $ws>0) {
        $colCur = $nsCur;
        $colPrev= 0.5 * $nsPrev;
        $colW   = 1.5 * $ws;
        $total  = $colCur + $colPrev + $colW;
        $rows[] = array(
          'id'=>$pid, 'name'=>$name,
          'pos_cur'=>$posCur, 'pos_prev'=>$posPrev,
          'nk_cur'=>$colCur, 'nk_prev'=>$colPrev,
          'wprs_raw'=>$wpPts, 'wprs'=>$colW, 'total'=>$total
        );
      }
    }

    usort($rows, function($a,$b){
      if ($a['total'] == $b['total']) return strcasecmp($a['name'],$b['name']);
      return ($a['total'] < $b['total']) ? 1 : -1;
    });
  } catch (Exception $e) {
    if ($DEBUG) echo '<pre>Main compute failed: '.h($e->getMessage()).'</pre>';
    $rows = array();
  }
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="container">
<header class="topbar">
  <h1><a href="ranking.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php">Home</a> ·
  <a href="ranking.php">Klasse 1</a> ·
  <a href="sportclass.php"><strong>Sportklasse</strong></a> ·
  <a href="competitionlist.php">Wedstrijden</a> ·
  <a href="explanation.php">Toelichting</a>
</nav>

<main class="card">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
    <h2>Sportklasse - <?= (int)$year ?></h2>
    <form method="get" class="inline">
      <label>Jaar
        <select name="year" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y ?>" <?= $y===$year ? 'selected' : '' ?>><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($DEBUG): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
    </form>
  </div>

  <?php if (!$available): ?>
    <p class="muted">
      Ranking voor <?= (int)$year ?> is nog niet beschikbaar.
      Benodigde data: NK (<?= (int)$year ?>), NK (<?= (int)$year-1 ?>) uit <em>Wedstrijden</em> (Sportklasse) en WPRS per 1 oktober (Sportklasse).
      <?php if ($DEBUG): ?>
        <br>Debug: NK<?= (int)$year ?> comp_id=<?= $nkCurId ? (int)$nkCurId : 0 ?>, deelnemers=<?= (int)$nkCur['participants'] ?>;
        NK<?= (int)$year-1 ?> comp_id=<?= $nkPrevId ? (int)$nkPrevId : 0 ?>, deelnemers=<?= (int)$nkPrev['participants'] ?>;
        maxWPRS=<?= number_format($maxWprs,3,'.','') ?>
      <?php endif; ?>
    </p>
  <?php else: ?>
    <table class="striped">
      <thead>
        <tr>
          <th>#</th>
          <th>Piloot</th>
          <th>
            <?php if ($nkCur['cid']): ?>
              <a href="competition.php?id=<?= (int)$nkCur['cid'] ?>">NK <?= (int)$year ?></a>
            <?php else: ?>
              NK <?= (int)$year ?>
            <?php endif; ?>
          </th>
          <th>
            <?php if ($nkPrev['cid']): ?>
              <a href="competition.php?id=<?= (int)$nkPrev['cid'] ?>">NK <?= (int)($year-1) ?></a>
            <?php else: ?>
              NK <?= (int)($year-1) ?>
            <?php endif; ?>
          </th>
          <th>
            <a target="_blank" rel="noopener"
               href="https://civlcomps.org/ranking/hang-gliding-class-1-sport-xc/pilots?search%5Bnation_id%5D=155&amp;search%5BrankingDate%5D=<?= (int)$year ?>-10-01">
              WPRS <?= (int)$year ?>
            </a>
          </th>
          <th>Totaal</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="6" class="muted">Geen gegevens beschikbaar.</td></tr>
        <?php else: $rank=1; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= $rank++ ?></td>
              <td><a href="pilot.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
              <td><?= number_format($r['nk_cur'],2) ?><?= $r['pos_cur'] ? " <span class='muted'>(pos ".(int)$r['pos_cur'].")</span>" : '' ?></td>
              <td><?= number_format($r['nk_prev'],2) ?><?= $r['pos_prev'] ? " <span class='muted'>(pos ".(int)$r['pos_prev'].")</span>" : '' ?></td>
              <td>
                <?= number_format($r['wprs'],2) ?>
                <?php if ($r['wprs_raw'] !== null): ?>
                  <span class='muted'>(WPRS <?= rtrim(rtrim(number_format((float)$r['wprs_raw'], 3, '.', ''), '0'), '.') ?>)</span>
                <?php endif; ?>
              </td>
              <td><strong><?= number_format($r['total'],2) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Stijl geïnspireerd op CIVL rankings.</p>
</footer>
</body>
</html>
