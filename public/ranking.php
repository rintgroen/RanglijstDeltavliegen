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
function findNkCompetitionId(PDO $pdo, int $year, string $class='Klasse 1') {
  $st = $pdo->prepare("SELECT id FROM rankings_competitions WHERE class = ? AND year = ? AND (title LIKE 'NK %' OR title LIKE 'NK%') ORDER BY id DESC LIMIT 1");
  $st->execute([$class, $year]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : null;
}
function loadNkPositions(PDO $pdo, ?int $competition_id) {
  if (!$competition_id) return ['cid'=>null,'participants'=>0,'pos'=>[]];
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
  return ['cid'=>$competition_id,'participants'=>$participants,'pos'=>$posByPilot];
}
function nationalsScore(?int $position, int $participants) {
  if (!$position || $participants <= 0) return 0.0;
  return 100.0 - (($position - 1) * (100.0 / $participants)); // == 100 * (1 - (pos-1)/participants)
}
function latestYear(PDO $pdo, string $class='Klasse 1') {
  // Prefer latest competitions year available for this class
  try {
    $st = $pdo->prepare("SELECT MAX(year) FROM rankings_competitions WHERE class = ?");
    $st->execute([$class]);
    $y = $st->fetchColumn();
    if ($y) return (int)$y;
  } catch (Throwable $e) {}
  try {
    $st = $pdo->prepare("SELECT MAX(year) FROM rankings_world_points WHERE class = ?");
    $st->execute([$class]);
    $y = $st->fetchColumn();
    if ($y) return (int)$y;
  } catch (Throwable $e) {}
  return (int)date('Y');
}
function wprsMaxForYear(PDO $pdo, int $year, string $class='Klasse 1') {
  try {
    $st = $pdo->prepare("SELECT MAX(points) FROM rankings_world_points WHERE class = ? AND year = ?");
    $st->execute([$class, $year]);
    $m = $st->fetchColumn();
    return $m ? (float)$m : 0.0;
  } catch (Throwable $e) { return 0.0; }
}
function wprsMapForYear(PDO $pdo, int $year, string $class='Klasse 1') {
  $map = [];
  try {
    $st = $pdo->prepare("SELECT pilot_id, points FROM rankings_world_points WHERE class = ? AND year = ?");
    $st->execute([$class, $year]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $map[(int)$r['pilot_id']] = (float)$r['points'];
    }
  } catch (Throwable $e) {}
  return $map;
}
function wprsScore(?float $pts, float $maxPts) {
  if ($pts === null || $maxPts <= 0) return 0.0;
  return 100.0 * ($pts / $maxPts);
}
function loadActivePilots(PDO $pdo) {
  try {
    $rs = $pdo->query("SELECT id, name FROM rankings_pilots WHERE active = 1 ORDER BY name");
    return $rs ? $rs->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    return [];
  }
}

// ---------- Inputs ----------
$defaultYear = latestYear($pdo, 'Klasse 1');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $defaultYear;
$isHistoric = ($year <= 2008);

// ---------- Pull data ----------
$nkCurId  = findNkCompetitionId($pdo, $year, 'Klasse 1');
$nkPrevId = findNkCompetitionId($pdo, $year-1, 'Klasse 1');
$nkPrev2Id= $isHistoric ? findNkCompetitionId($pdo, $year-2, 'Klasse 1') : null;

$nkCur  = loadNkPositions($pdo, $nkCurId);
$nkPrev = loadNkPositions($pdo, $nkPrevId);
$nkPrev2= $isHistoric ? loadNkPositions($pdo, $nkPrev2Id) : ['cid'=>null,'participants'=>0,'pos'=>[]];

$maxWprs = $isHistoric ? 0.0 : wprsMaxForYear($pdo, $year, 'Klasse 1');
$wprsMap = $isHistoric ? []  : wprsMapForYear($pdo, $year, 'Klasse 1');

// ---------- Availability ----------
$available = $isHistoric
  ? (($nkCur['participants'] > 0) && ($nkPrev['participants'] > 0) && ($nkPrev2['participants'] > 0))
  : (($nkCur['participants'] > 0) && ($nkPrev['participants'] > 0) && ($maxWprs > 0.0));

// ---------- Compute rows ----------
$rows = [];
if ($available) {
  $pilots = loadActivePilots($pdo);
  foreach ($pilots as $p) {
    $pid = (int)$p['id'];
    $name= $p['name'];

    $posCur  = $nkCur['pos'][$pid]  ?? null;
    $posPrev = $nkPrev['pos'][$pid] ?? null;
    $nsCur   = nationalsScore($posCur,  $nkCur['participants']);
    $nsPrev  = nationalsScore($posPrev, $nkPrev['participants']);

    if ($isHistoric) {
      $posPrev2 = $nkPrev2['pos'][$pid] ?? null;
      $nsPrev2  = nationalsScore($posPrev2, $nkPrev2['participants']);

      if ($nsCur>0 || $nsPrev>0 || $nsPrev2>0) {
        $colCur   = $nsCur;          // 100%
        $colPrev  = 0.8 * $nsPrev;   //  80%
        $colPrev2 = 0.6 * $nsPrev2;  //  60%
        $total    = $colCur + $colPrev + $colPrev2;
        $rows[] = [
          'id'=>$pid, 'name'=>$name,
          'pos_cur'=>$posCur, 'pos_prev'=>$posPrev, 'pos_prev2'=>$posPrev2,
          'nk_cur'=>$colCur, 'nk_prev'=>$colPrev, 'nk_prev2'=>$colPrev2,
          'wprs_raw'=>null, 'wprs'=>null, 'total'=>$total
        ];
      }
    } else {
      $wpPts = $wprsMap[$pid] ?? null;
      $ws    = wprsScore($wpPts, $maxWprs);
      if ($nsCur>0 || $nsPrev>0 || $ws>0) {
        $colCur = $nsCur;            // 100%
        $colPrev= 0.5 * $nsPrev;     //  50%
        $colW   = 1.5 * $ws;         // 150%
        $total  = $colCur + $colPrev + $colW;
        $rows[] = [
          'id'=>$pid, 'name'=>$name,
          'pos_cur'=>$posCur, 'pos_prev'=>$posPrev,
          'nk_cur'=>$colCur, 'nk_prev'=>$colPrev,
          'wprs_raw'=>$wpPts, 'wprs'=>$colW, 'total'=>$total
        ];
      }
    }
  }
  usort($rows, function($a,$b){
    if ($a['total'] == $b['total']) return strcasecmp($a['name'], $b['name']);
    return ($a['total'] < $b['total']) ? 1 : -1;
  });
}

// ---------- Years dropdown ----------
$years = [];
try {
  $rs = $pdo->query("SELECT DISTINCT year FROM rankings_competitions WHERE class = 'Klasse 1' ORDER BY year DESC");
  if ($rs) $years = array_map('intval', $rs->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {}
try {
  // include WPRS years as well so older WPRS-only years are selectable
  $rs = $pdo->query("SELECT DISTINCT year FROM rankings_world_points WHERE class = 'Klasse 1' ORDER BY year DESC");
  if ($rs) {
    foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $y) {
      $y = (int)$y; if (!in_array($y, $years, true)) $years[] = $y;
    }
    rsort($years);
  }
} catch (Throwable $e) {}

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – Klasse 1 <?= (int)$year ?></title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="container">
<header class="topbar">
  <h1><a href="home.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php">Home</a> ·
  <a href="ranking.php"><strong>Klasse 1</strong></a> ·
  <a href="sportclass.php">Sportklasse</a> ·
  <a href="competitionlist.php">Wedstrijden</a> ·
  <a href="explanation.php">Toelichting</a>
</nav>

<section class="card" style="padding:1rem;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
    <h2 style="margin:0;">Klasse 1 – <?= (int)$year ?></h2>
    <form method="get" action="ranking.php">
      <label for="year">Jaar: </label>
      <select id="year" name="year" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= $y==$year?'selected':'' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Toon</button></noscript>
    </form>
  </div>
</section>

<section class="card" style="padding:1rem; margin-top:1rem;">
  <?php if (!$available): ?>
    <p class="muted">
      Ranking voor <?= (int)$year ?> is nog niet beschikbaar.
      Benodigde data:
      <?php if ($isHistoric): ?>
        NK (<?= (int)$year ?>), NK (<?= (int)($year-1) ?>) en NK (<?= (int)($year-2) ?>) uit <em>Wedstrijden</em> (Klasse 1).
      <?php else: ?>
        NK (<?= (int)$year ?>), NK (<?= (int)($year-1) ?>) uit <em>Wedstrijden</em> (Klasse 1) en WPRS per 1 oktober (Klasse 1).
      <?php endif; ?>
    </p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Piloot</th>
            <th>
              <?php if ($nkCur['cid']): ?>
                <a href="competition.php?id=<?= (int)$nkCur['cid'] ?>">NK <?= (int)$year ?></a>
              <?php else: ?>NK <?= (int)$year ?><?php endif; ?>
            </th>
            <th>
              <?php if ($nkPrev['cid']): ?>
                <a href="competition.php?id=<?= (int)$nkPrev['cid'] ?>">NK <?= (int)($year-1) ?></a>
              <?php else: ?>NK <?= (int)($year-1) ?><?php endif; ?>
            </th>
            <?php if ($isHistoric): ?>
              <th>
                <?php if ($nkPrev2['cid']): ?>
                  <a href="competition.php?id=<?= (int)$nkPrev2['cid'] ?>">NK <?= (int)($year-2) ?></a>
                <?php else: ?>NK <?= (int)($year-2) ?><?php endif; ?>
              </th>
            <?php else: ?>
              <th>
                <a target="_blank" rel="noopener"
                   href="https://civlcomps.org/ranking/hang-gliding-class-1-xc/pilots?search%5Bnation_id%5D=155&amp;search%5BrankingDate%5D=<?= (int)$year ?>-10-01">
                  WPRS <?= (int)$year ?>
                </a>
              </th>
            <?php endif; ?>
            <th>Totaal</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="muted">Geen gegevens om te tonen.</td></tr>
          <?php else: $rank=1; foreach ($rows as $r): ?>
            <tr>
              <td><?= $rank++ ?></td>
              <td><a href="pilot.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
              <td><?= number_format($r['nk_cur'],2) ?><?= $r['pos_cur'] ? " <span class='muted'>(pos ".(int)$r['pos_cur'].")</span>" : '' ?></td>
              <td><?= number_format($r['nk_prev'],2) ?><?= $r['pos_prev'] ? " <span class='muted'>(pos ".(int)$r['pos_prev'].")</span>" : '' ?></td>
              <?php if ($isHistoric): ?>
                <td><?= number_format($r['nk_prev2'],2) ?><?= $r['pos_prev2'] ? " <span class='muted'>(pos ".(int)$r['pos_prev2'].")</span>" : '' ?></td>
              <?php else: ?>
                <td>
                  <?= number_format($r['wprs'],2) ?>
                  <?php if ($r['wprs_raw'] !== null): ?>
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
  <?php endif; ?>
</section>

<?php if ($DEBUG): ?>
<section class="card" style="padding:1rem; margin-top:1rem;">
  <h3>Debug</h3>
  <pre><?php
  echo 'year=', $year, ' historic=', ($isHistoric?'yes':'no'), PHP_EOL;
  echo 'nkCurId=', var_export($nkCurId, true), ' participants=', $nkCur['participants'], PHP_EOL;
  echo 'nkPrevId=', var_export($nkPrevId, true), ' participants=', $nkPrev['participants'], PHP_EOL;
  if ($isHistoric) {
    echo 'nkPrev2Id=', var_export($nkPrev2Id, true), ' participants=', $nkPrev2['participants'], PHP_EOL;
  } else {
    echo 'maxWprs=', $maxWprs, PHP_EOL;
  }
  ?></pre>
</section>
<?php endif; ?>

<footer class="muted" style="margin:1.5rem 0;">
  <p>&copy; <?= date('Y') ?> Ranglijst Deltavliegen</p>
</footer>
</body>
</html>
