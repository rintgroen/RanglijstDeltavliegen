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

function safe_filename($ext = 'jpg') {
  $ext = preg_replace('/[^a-z0-9]/i','', $ext);
  if ($ext === '') $ext = 'jpg';
  return bin2hex(random_bytes(8)) . '.' . strtolower($ext);
}

try { $pdo = db(); }
catch (Exception $e) { http_response_code(500); if ($DEBUG) echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>'; exit; }

// Read competition id
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo $DEBUG ? 'Missing competition id' : ''; exit; }

// Handle new memory POST before output (PRG pattern)
$notice = null; $errors = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mem_action']) && $_POST['mem_action'] === 'create') {
  // Basic anti-spam: honeypot + CSRF
  $honeypot = isset($_POST['website']) ? trim($_POST['website']) : '';
  $csrf_ok  = isset($_POST['csrf']) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);
  if ($honeypot !== '' || !$csrf_ok) {
    $errors[] = 'Ongeldige inzending. Probeer het opnieuw.';
  } else {
    $author = trim((string)($_POST['author'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $title  = trim((string)($_POST['title'] ?? ''));
    $body   = trim((string)($_POST['body'] ?? ''));
    if ($author === '') $errors[] = 'Vul je naam in.';
    if ($body === '')   $errors[] = 'Schrijf een herinnering.';

    $photo_rel = null;
    if (isset($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
      $file = $_FILES['photo'];
      if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
        // Validate mime
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowed = array('image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp');
        if (!isset($allowed[$mime])) {
          $errors[] = 'Afbeelding moet jpg, png, gif of webp zijn.';
        } else {
          // Limit size to ~6MB
          if ($file['size'] > 6 * 1024 * 1024) {
            $errors[] = 'Afbeelding is te groot (max 6 MB).';
          } else {
            // Prepare directory
            $baseDir = __DIR__ . '/uploads/memories/' . (int)$id;
            $baseUrl = 'uploads/memories/' . (int)$id;
            if (!is_dir($baseDir)) { @mkdir($baseDir, 0755, true); }
            $ext = $allowed[$mime];
            $fname = safe_filename($ext);
            $dest = $baseDir . '/' . $fname;
            if (@move_uploaded_file($file['tmp_name'], $dest)) {
              $photo_rel = $baseUrl . '/' . $fname;
            } else {
              $errors[] = 'Uploaden van afbeelding is mislukt.';
            }
          }
        }
      } else {
        $errors[] = 'Uploaden van afbeelding is mislukt.';
      }
    }

    if (empty($errors)) {
      try {
        $stmt = $pdo->prepare('INSERT INTO rankings_competition_memories (competition_id, author_name, author_email, title, body, photo_path) VALUES (?,?,?,?,?,?)');
        $stmt->execute(array($id, $author, $email !== '' ? $email : null, $title !== '' ? $title : null, $body, $photo_rel));
        header('Location: competition.php?id='.(int)$id.'#memories');
        exit;
      } catch (Exception $e) {
        $errors[] = 'Opslaan van herinnering is mislukt: '.h($e->getMessage());
      }
    }
  }
}

// Load competition (including class and tasks headers)
$comp = null;
try {
  $stmt = $pdo->prepare('SELECT id, year, title, class, tasks_headers_json, created_at
                         FROM rankings_competitions
                         WHERE id = ? LIMIT 1');
  $stmt->execute(array($id));
  $comp = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { if ($DEBUG) echo '<pre>Competition load failed: '.h($e->getMessage()).'</pre>'; }

if (!$comp) { http_response_code(404); echo $DEBUG ? 'Competition not found' : ''; exit; }

$cls = isset($comp['class']) && $comp['class'] ? $comp['class'] : 'Klasse 1';
$rankUrl = ($cls === 'Sportklasse') ? 'sportclass.php?year='.(int)$comp['year'] : 'ranking.php?year='.(int)$comp['year'];

// Participants
$participantCount = 0;
try {
  $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM rankings_competition_results WHERE competition_id = ?');
  $cntStmt->execute(array($id));
  $participantCount = (int)$cntStmt->fetchColumn();
} catch (Exception $e) { if ($DEBUG) echo '<pre>Participants count failed: '.h($e->getMessage()).'</pre>'; }

// Task headers
$taskHeaders = array();
if (!empty($comp['tasks_headers_json'])) {
  $hdrs = json_decode($comp['tasks_headers_json'], true);
  if (is_array($hdrs)) $taskHeaders = $hdrs;
}

// Results
$rows = array();
try {
  $rs = $pdo->prepare('SELECT cr.id, cr.pilot_id, cr.pilot_name, cr.tasks_json, cr.total, p.name AS pname
                       FROM rankings_competition_results cr
                       LEFT JOIN rankings_pilots p ON p.id = cr.pilot_id
                       WHERE cr.competition_id = ?
                       ORDER BY CAST(cr.total AS DECIMAL(16,6)) DESC, cr.id ASC');
  $rs->execute(array($id));
  $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { if ($DEBUG) echo '<pre>Results load failed: '.h($e->getMessage()).'</pre>'; }

// If no headers, infer from first row's tasks_json
if (empty($taskHeaders)) {
  foreach ($rows as $r) {
    if (!empty($r['tasks_json'])) {
      $arr = json_decode($r['tasks_json'], true);
      if (is_array($arr)) {
        $taskHeaders = array();
        for ($i=0; $i<count($arr); $i++) $taskHeaders[] = 'Taak '.($i+1);
        break;
      }
    }
  }
}

// Tie-aware ranking for display
function tieAwareRanks($rows) {
  $ranks = array();
  $rank = 0; $i = 0; $prev = null;
  foreach ($rows as $r) {
    $i++;
    $t = is_numeric($r['total']) ? (float)$r['total'] : 0.0;
    if ($prev === null || abs($t - $prev) > 1e-9) { $rank = $i; $prev = $t; }
    $ranks[] = $rank;
  }
  return $ranks;
}
$displayRanks = tieAwareRanks($rows);

// Generate CSRF token for the memory form
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Load existing memories for this competition
$memories = array();
try {
  $ms = $pdo->prepare('SELECT author_name, author_email, title, body, photo_path, created_at FROM rankings_competition_memories WHERE competition_id = ? AND is_visible = 1 ORDER BY created_at DESC');
  $ms->execute(array($id));
  $memories = $ms->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { if ($DEBUG) echo '<pre>Memories load failed: '.h($e->getMessage()).'</pre>'; }

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – <?= h($comp['title']) ?> <?= (int)$comp['year'] ?></title>
  <link rel="stylesheet" href="assets/style.css">

  <style>
    /* Style textarea similar to other inputs */
    textarea {
      width: 100%;
      padding: .5rem .6rem;
      border: 1px solid #e5e7eb;
      border-radius: .5rem;
      background: var(--surface, #fff);
      font: inherit;
      box-sizing: border-box;
      min-height: 140px;
      resize: vertical;
    }
    label > textarea { margin-top: .25rem; }
  </style>

</head>
<body class="container">
<header class="topbar">
  <h1><a href="ranking.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>

<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php">Home</a> ·
  <a href="ranking.php">Klasse 1</a> ·
  <a href="sportclass.php">Sportklasse</a> ·
  <a href="competitionlist.php">Wedstrijden</a> ·
  <a href="explanation.php">Toelichting</a>
</nav>

<main class="card">
  <h2><?= h($comp['title']) ?> – <?= (int)$comp['year'] ?></h2>

  <div class="card" style="margin:1rem 0; padding:.5rem 1rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
    <div><strong>Jaar:</strong> <?= (int)$comp['year'] ?></div>
    <div><strong>Klasse:</strong> <?= h($cls) ?></div>
    <div><strong>Deelnemers:</strong> <?= (int)$participantCount ?></div>
    <?php if (!empty($taskHeaders)): ?>
      <div><strong>Taken:</strong> <?= (int)count($taskHeaders) ?></div>
    <?php endif; ?>
    <?php if (!empty($comp['created_at'])): ?>
      <div><strong>Aangemaakt:</strong> <?= h($comp['created_at']) ?></div>
    <?php endif; ?>
    <div style="margin-left:auto;"></div>
    <a class="btn" href="<?= h($rankUrl) ?>">Bekijk ranglijst <?= (int)$comp['year'] ?></a>
  </div>

  <?php if (empty($rows)): ?>
    <p class="muted">Geen resultaten beschikbaar voor deze wedstrijd.</p>
  <?php else: ?>
    <table class="striped">
      <thead>
        <tr>
          <th>#</th>
          <th>Piloot</th>
          <?php foreach ($taskHeaders as $th): ?>
            <th><?= h($th) ?></th>
          <?php endforeach; ?>
          <th>Totaal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i=>$r): ?>
          <?php
            $pid   = isset($r['pilot_id']) && $r['pilot_id'] ? (int)$r['pilot_id'] : 0;
            $pname = !empty($r['pname']) ? $r['pname'] : $r['pilot_name'];
            $tasks = array();
            if (!empty($r['tasks_json'])) {
              $arr = json_decode($r['tasks_json'], true);
              if (is_array($arr)) $tasks = $arr;
            }
          ?>
          <tr>
            <td><?= (int)$displayRanks[$i] ?></td>
            <td>
              <?php if ($pid > 0): ?>
                <a href="pilot.php?id=<?= $pid ?>"><?= h($pname) ?></a>
              <?php else: ?>
                <?= h($pname) ?>
              <?php endif; ?>
            </td>
            <?php
              $n = count($taskHeaders);
              for ($k=0; $k<$n; $k++) {
                $val = isset($tasks[$k]) && is_numeric($tasks[$k]) ? (float)$tasks[$k] : 0.0;
                echo '<td>'.h(rtrim(rtrim(number_format($val, 3, '.', ''), '0'), '.')).'</td>';
              }
            ?>
            <td><strong><?= h(rtrim(rtrim(number_format((float)$r['total'], 3, '.', ''), '0'), '.')) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <section id="memories" style="margin-top:2rem;">
    <h3>Herinneringen</h3>

    <?php if (!empty($errors)): ?>
      <div class="alert error">
        <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
      </div>
    <?php elseif ($notice): ?>
      <div class="alert success"><?= h($notice) ?></div>
    <?php endif; ?>

    <?php if (empty($memories)): ?>
      <p class="muted">Nog geen herinneringen toegevoegd. Wees de eerste en deel jouw herinnering aan deze wedstrijd.</p>
    <?php else: ?>
      <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1rem;">
        <?php foreach ($memories as $m): ?>
          <article class="card" style="padding: .75rem;">
            <header>
              <strong><?= h($m['title'] ?: 'Herinnering') ?></strong><br>
              <small class="muted">Door <?= h($m['author_name']) ?> — <?= h(date('Y-m-d', strtotime($m['created_at']))) ?></small>
            </header>
            <?php if (!empty($m['photo_path'])): ?>
              <div style="margin:.5rem 0;">
                <img src="<?= h($m['photo_path']) ?>" alt="foto herinnering" style="max-width:100%; height:auto; border-radius:.5rem;">
              </div>
            <?php endif; ?>
            <p><?= nl2br(h($m['body'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card" style="margin-top:1rem; padding:1rem;">
      <h4>Voeg jouw herinnering toe</h4>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="mem_action" value="create">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="text" name="website" value="" style="display:none">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:.75rem; max-width:900px;">
          <label>Naam
            <input type="text" name="author" required maxlength="120" placeholder="Je naam">
          </label>
          <label>E-mail (optioneel)
            <input type="email" name="email" maxlength="190" placeholder="alleen voor contact (niet gepubliceerd)">
          </label>
        </div>
        <div style="display:grid; gap:.75rem; max-width:900px; margin-top:.75rem;">
          <label>Titel (optioneel)
            <input type="text" name="title" maxlength="160" placeholder="bv. 'De winnende finaleglide'">
          </label>
          <label>Herinnering
            <textarea name="body" required rows="5" placeholder="Schrijf een inhoudelijke herinnering (bv. tactiek, weer, startvolgorde, bijzondere momenten)"></textarea>
          </label>
          <label>Foto (optioneel — jpg, png, gif, webp; max 6 MB)
            <input type="file" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp">
          </label>
        </div>
        <div style="margin-top:.75rem;">
          <button class="btn" type="submit">Plaatsen</button>
        </div>
      </form>
    </div>
  </section>

  <?php if ($DEBUG): ?>
    <p class="muted"><small>Debug: comp_id=<?= (int)$comp['id'] ?>, class=<?= h($cls) ?>, headers=<?= h(json_encode($taskHeaders)) ?>, rows=<?= (int)count($rows) ?>, memories=<?= (int)count($memories) ?>.</small></p>
  <?php endif; ?>
</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Stijl geïnspireerd op CIVL rankings.</p>
</footer>
</body>
</html>
