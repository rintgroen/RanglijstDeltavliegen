<?php
require_once __DIR__ . '/utils.php';
require_login();

app_enable_debug();
$DEBUG = app_debug_enabled();

if (!function_exists('normalize_name')) {
  function normalize_name($s) {
    $s = (string)$s;
    $s = trim(preg_replace('/\s+/u', ' ', $s));          // collapse whitespace
    $lower = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $ascii = $lower;
    if (function_exists('iconv')) {
      $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $lower);
      if ($t !== false) { $ascii = strtolower($t); }
    }
    return [$lower, $ascii];
  }
}

try {
  $pdo = db();
} catch (Exception $e) {
  http_response_code(500);
  if ($DEBUG) echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>';
  exit;
}

// CSRF
$CSRF = app_csrf_token();

/** Helpers: parsing uploads **/
function detect_delimiter($line) {
  $c = substr_count($line, ',');
  $s = substr_count($line, ';');
  $t = substr_count($line, "\t");
  if ($t >= $c && $t >= $s) return "\t";
  if ($s >= $c && $s >= $t) return ';';
  return ',';
}

function parse_csv_file($tmp_path) {
  $fh = @fopen($tmp_path, 'r');
  if (!$fh) throw new Exception('Kan CSV niet openen.');
  $first = fgets($fh);
  if ($first === false) { fclose($fh); throw new Exception('Leeg CSV bestand.'); }
  $delim = detect_delimiter($first);
  rewind($fh);

  $header = fgetcsv($fh, 0, $delim);
  if ($header === false) { fclose($fh); throw new Exception('CSV header ontbreekt.'); }

  // Normalize headers
  $cols = array();
  foreach ($header as $c) { $cols[] = is_string($c) ? trim($c) : (string)$c; }
  $colCount = count($cols);
  if ($colCount < 2) { fclose($fh); throw new Exception('Minimaal: Piloot | ...taken... [| Totaal]'); }

  $last = strtolower(trim((string)$cols[$colCount-1]));
  $hasTotal = ($last === 'totaal' || $last === 'total');

  // Determine task headers
  if ($hasTotal) {
    if ($colCount < 3) { fclose($fh); throw new Exception('Minimaal: Piloot | ...taken... | Totaal'); }
    $headers = array_slice($cols, 1, $colCount - 2);
  } else {
    $headers = array_slice($cols, 1); // all remaining columns are tasks
  }

  $rows = array();
  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    if (count($row) === 0) continue;
    if (count($row) < $colCount) { $row = array_pad($row, $colCount, ''); }
    $pilot = trim((string)$row[0]);
    if ($pilot === '') continue;

    $tasks = array();
    $endTaskIndex = $hasTotal ? $colCount-1 : $colCount;
    for ($i=1; $i<$endTaskIndex; $i++) {
      $v = $row[$i];
      $v = is_string($v) ? str_replace(',', '.', $v) : $v;
      $tasks[] = is_numeric($v) ? (float)$v : 0.0;
    }

    $total = null;
    if ($hasTotal) {
      $tv = $row[$colCount-1];
      $tv = is_string($tv) ? str_replace(',', '.', $tv) : $tv;
      $total = is_numeric($tv) ? (float)$tv : null;
    }
    if ($total === null) { $total = array_sum($tasks); }

    $rows[] = array('pilot_name'=>$pilot, 'tasks'=>$tasks, 'total'=>$total);
  }
  fclose($fh);
  return array($headers, $rows);
}

function parse_xlsx_file($tmp_path) {
  if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    throw new Exception('XLSX niet ondersteund (PhpSpreadsheet niet geïnstalleerd). Upload een CSV.');
  }
  $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_path);
  $sheet = $spreadsheet->getActiveSheet();
  $data = $sheet->toArray(null, true, true, true);
  if (!$data || count($data) < 2) throw new Exception('Bestand bevat geen data.');
  $header = array_values(array_shift($data));

  // Normalize headers
  $cols = array();
  foreach ($header as $c) { $cols[] = is_string($c) ? trim($c) : (string)$c; }
  $colCount = count($cols);
  if ($colCount < 2) throw new Exception('Minimaal: Piloot | ...taken... [| Totaal]');

  $last = strtolower(trim((string)$cols[$colCount-1]));
  $hasTotal = ($last === 'totaal' || $last === 'total');

  // Determine task headers
  if ($hasTotal) {
    if ($colCount < 3) throw new Exception('Minimaal: Piloot | ...taken... | Totaal');
    $headers = array_slice($cols, 1, $colCount - 2);
  } else {
    $headers = array_slice($cols, 1);
  }

  $rows = array();
  foreach ($data as $r) {
    $arr = array_values($r);
    if (count($arr) < $colCount) { $arr = array_pad($arr, $colCount, ''); }
    $pilot = trim((string)$arr[0]);
    if ($pilot === '') continue;

    $tasks = array();
    $endTaskIndex = $hasTotal ? $colCount-1 : $colCount;
    for ($i=1; $i<$endTaskIndex; $i++) {
      $v = $arr[$i];
      $v = is_string($v) ? str_replace(',', '.', $v) : $v;
      $tasks[] = is_numeric($v) ? (float)$v : 0.0;
    }

    $total = null;
    if ($hasTotal) {
      $tv = $arr[$colCount-1];
      $tv = is_string($tv) ? str_replace(',', '.', $tv) : $tv;
      $total = is_numeric($tv) ? (float)$tv : null;
    }
    if ($total === null) { $total = array_sum($tasks); }

    $rows[] = array('pilot_name'=>$pilot, 'tasks'=>$tasks, 'total'=>$total);
  }
  return array($headers, $rows);
}

// Build in-memory pilot maps (by name) to resolve pilot_id
function load_pilot_maps(PDO $pdo) {
  $maps = ['exact'=>[], 'ascii'=>[]];
  $tables = ['rankings_pilots', 'pilots'];
  $loaded = false;
  foreach ($tables as $t) {
    try {
      $rs = $pdo->query("SELECT id, name FROM $t");
      if ($rs) {
        foreach ($rs as $row) {
          $name = (string)$row['name'];
          list($lower, $ascii) = normalize_name($name);
          $maps['exact'][$lower] = (int)$row['id'];
          $maps['ascii'][$ascii] = (int)$row['id'];
        }
        $loaded = true;
        break;
      }
    } catch (Throwable $e) {
      // try next table
    }
  }
  return $maps;
}

function resolve_pilot_id($maps, $pilot_name) {
  list($lower, $ascii) = normalize_name($pilot_name);
  if (isset($maps['exact'][$lower])) return $maps['exact'][$lower];
  if (isset($maps['ascii'][$ascii])) return $maps['ascii'][$ascii];
  return null;
}

// Notices
$admin_notice = null; $admin_error = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
  if (!app_check_csrf()) {
    $admin_error = 'Ongeldige CSRF-token.';
  } else {
    try {
      if ($action === 'upload_competition') {
        $year  = isset($_POST['year']) ? (int)$_POST['year'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $cls   = (string)($_POST['class'] ?? '');
        if ($year <= 0 || $title === '' || ($cls !== 'Klasse 1' && $cls !== 'Sportklasse')) {
          throw new Exception('Controleer de velden (jaar, titel, klasse).');
        }
        if (!isset($_FILES['results_file']) || $_FILES['results_file']['error'] === UPLOAD_ERR_NO_FILE) {
          throw new Exception('Geen resultatenbestand geüpload.');
        }
        $file = $_FILES['results_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Uploadfout: code '.$file['error']);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext === 'csv') {
          list($headers, $rows) = parse_csv_file($file['tmp_name']);
        } else {
          list($headers, $rows) = parse_xlsx_file($file['tmp_name']);
        }
        if (empty($headers) || empty($rows)) throw new Exception('Geen bruikbare data gevonden.');

        // Prepare pilot maps once
        $pilot_maps = load_pilot_maps($pdo);

        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO rankings_competitions (year, title, class, tasks_headers_json, tasks_count, created_at) VALUES (?,?,?,?,?,NOW())');
        $ins->execute(array($year, $title, $cls, json_encode(array_values($headers), JSON_UNESCAPED_UNICODE), (int)count($headers)));
        $cid = (int)$pdo->lastInsertId();

        $insr = $pdo->prepare('INSERT INTO rankings_competition_results (competition_id, pilot_id, pilot_name, tasks_json, total) VALUES (?,?,?,?,?)');
        foreach ($rows as $r) {
          $pid = resolve_pilot_id($pilot_maps, $r['pilot_name']);
          $insr->execute(array($cid, $pid, $r['pilot_name'], json_encode(array_values($r['tasks']), JSON_UNESCAPED_UNICODE), (float)$r['total']));
        }
        $pdo->commit();
        $admin_notice = 'Wedstrijd geüpload (ID '.$cid.', '.count($rows).' resultaten).';

      } elseif ($action === 'delete_competition') {
        $cid = isset($_POST['comp_id']) ? (int)$_POST['comp_id'] : 0;
        if ($cid <= 0) throw new Exception('Ongeldige wedstrijd-id.');
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM rankings_competition_results WHERE competition_id = ?')->execute(array($cid));
        $pdo->prepare('DELETE FROM rankings_competition_memories WHERE competition_id = ?')->execute(array($cid));
        $pdo->prepare('DELETE FROM rankings_competitions WHERE id = ?')->execute(array($cid));
        $pdo->commit();
        $admin_notice = 'Wedstrijd verwijderd.';

      } elseif ($action === 'download_csv') {
        $cid = isset($_POST['comp_id']) ? (int)$_POST['comp_id'] : 0;
        if ($cid <= 0) throw new Exception('Ongeldige wedstrijd-id.');

        // Load competition meta (headers + title/year/class)
        $st = $pdo->prepare('SELECT title, year, class, tasks_headers_json FROM rankings_competitions WHERE id = ? LIMIT 1');
        $st->execute(array($cid));
        $comp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$comp) throw new Exception('Wedstrijd niet gevonden.');

        $headers = array();
        if (!empty($comp['tasks_headers_json'])) {
          $tmp = json_decode($comp['tasks_headers_json'], true);
          if (is_array($tmp)) $headers = $tmp;
        }

        // Load results
        $rs = $pdo->prepare('SELECT pilot_name, tasks_json, total FROM rankings_competition_results WHERE competition_id = ? ORDER BY CAST(total AS DECIMAL(16,6)) DESC, id ASC');
        $rs->execute(array($cid));
        $rows = $rs->fetchAll(PDO::FETCH_ASSOC);

        // If no headers, infer from first row
        if (empty($headers) && !empty($rows)) {
          $firstTasks = json_decode($rows[0]['tasks_json'], true);
          if (is_array($firstTasks)) {
            $headers = array();
            for ($i=0; $i<count($firstTasks); $i++) $headers[] = 'Taak '.($i+1);
          }
        }

        // Build filename: ID_Jaar_Titel_Klasse.csv (RFC 5987 for UTF-8)
        $raw_name = $cid . '_' . ($comp['year'] ?? '') . '_' . ($comp['title'] ?? '') . '_' . ($comp['class'] ?? '');
        $san = preg_replace('/[^A-Za-z0-9\\-]+/', '_', $raw_name);
        $san = preg_replace('/_+/', '_', $san);
        $san = trim($san, '_');
        if ($san === '') { $san = 'competition_' . $cid; }
        $filename = $san . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

        // UTF-8 BOM for Excel
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        $head = array_merge(array('Piloot'), $headers, array('Totaal'));
        fputcsv($out, $head);
        foreach ($rows as $r) {
          $tasks = array();
          if (!empty($r['tasks_json'])) {
            $arr = json_decode($r['tasks_json'], true);
            if (is_array($arr)) $tasks = $arr;
          }
          if (!empty($headers)) {
            $tasks = array_pad($tasks, count($headers), 0);
          }
          $line = array_merge(array($r['pilot_name']), $tasks, array($r['total']));
          fputcsv($out, $line);
        }
        fclose($out);
        exit;
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $admin_error = $e->getMessage();
    }
  }
}

// Load recent competitions for listing
$comp_rows = array();
try {
  $rs = $pdo->query("SELECT id, year, title, class, created_at FROM rankings_competitions ORDER BY created_at DESC, year DESC LIMIT 50");
  if ($rs) { $comp_rows = $rs->fetchAll(PDO::FETCH_ASSOC); }
} catch (Exception $e) { /* ignore */ }

app_page_start('Wedstrijd upload - Admin', [
  'active_admin' => 'competition_upload',
  'description' => 'Wedstrijdresultaten uploaden en beheren.',
]);
?>
<main class="card">
  <h1>Wedstrijd upload</h1>

  <?php if (!empty($admin_notice)): ?><div class="alert success"><?= h($admin_notice) ?></div><?php endif; ?>
  <?php if (!empty($admin_error)): ?><div class="alert error"><?= h($admin_error) ?></div><?php endif; ?>

  <section class="panel">
    <h3>Nieuwe wedstrijd uploaden</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="upload_competition">
      <div class="grid">
        <label>Jaar
          <input type="number" name="year" required>
        </label>
        <label>Titel
          <input type="text" name="title" required placeholder="bv. NK 1999">
        </label>
        <label>Klasse
          <select name="class" required>
            <option value="Klasse 1">Klasse 1</option>
            <option value="Sportklasse">Sportklasse</option>
          </select>
        </label>
        <label>Resultatenbestand (CSV)
          <input type="file" name="results_file" accept=".csv,text/csv" required>
        </label>
      </div>
      <p><button type="submit">Uploaden</button></p>
    </form>
    <p class="muted">
      Bestandsindeling: <em>eerste kolom Piloot</em>, tussenliggende kolommen zijn taken, <em>optioneel</em> een laatste kolom <em>Totaal</em>.
    </p>
  </section>

  <section class="panel">
    <h3>Wedstrijden</h3>
    <?php if (empty($comp_rows)): ?>
      <p class="muted">Geen wedstrijden gevonden.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Jaar</th>
              <th>Titel</th>
              <th>Klasse</th>
              <th>Aangemaakt</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($comp_rows as $c): ?>
              <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><?= (int)$c['year'] ?></td>
                <td><?= h($c['title']) ?></td>
                <td><?= h($c['class']) ?></td>
                <td><?= h($c['created_at']) ?></td>
                <td>
                  <div class="inline">
                  <a class="btn secondary" href="../public/competition.php?id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">Open</a>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="download_csv">
                    <input type="hidden" name="comp_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit">Download CSV</button>
                  </form>
                  <form method="post" onsubmit="return confirm('Weet je zeker dat je deze wedstrijd wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="delete_competition">
                    <input type="hidden" name="comp_id" value="<?= (int)$c['id'] ?>">
                    <button class="danger" type="submit">Verwijderen</button>
                  </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php app_page_end('Admin - ' . app_site_name()); ?>
