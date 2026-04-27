<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/ranking.php';

app_enable_debug();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$pdo = app_db_or_fail();

function competition_safe_filename(string $ext = 'jpg'): string {
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    return bin2hex(random_bytes(8)) . '.' . strtolower($ext ?: 'jpg');
}

function competition_tie_aware_ranks(array $rows): array {
    $ranks = [];
    $rank = 0;
    $index = 0;
    $previous = null;
    foreach ($rows as $row) {
        $index++;
        $total = is_numeric($row['total']) ? (float)$row['total'] : 0.0;
        if ($previous === null || abs($total - $previous) > 1e-9) {
            $rank = $index;
            $previous = $total;
        }
        $ranks[] = $rank;
    }
    return $ranks;
}

$competitionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($competitionId <= 0) {
    http_response_code(400);
    app_page_start(app_site_name() . ' - Wedstrijd', ['active_public' => 'competitions']);
    echo '<main class="card"><h1>Wedstrijd</h1><p class="muted">Geen wedstrijd gespecificeerd.</p></main>';
    app_page_end();
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mem_action'] ?? '') === 'create') {
    $honeypot = trim((string)($_POST['website'] ?? ''));
    if ($honeypot !== '' || !app_check_csrf()) {
        $errors[] = 'Ongeldige inzending. Probeer het opnieuw.';
    } else {
        $author = trim((string)($_POST['author'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($author === '') {
            $errors[] = 'Vul je naam in.';
        }
        if ($body === '') {
            $errors[] = 'Schrijf een herinnering.';
        }

        $photoPath = null;
        if (isset($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['photo'];
            if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) {
                    $errors[] = 'Afbeelding moet jpg, png, gif of webp zijn.';
                } elseif ($file['size'] > 6 * 1024 * 1024) {
                    $errors[] = 'Afbeelding is te groot (max 6 MB).';
                } else {
                    $baseDir = __DIR__ . '/uploads/memories/' . $competitionId;
                    $baseUrl = 'uploads/memories/' . $competitionId;
                    if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true)) {
                        $errors[] = 'Uploadmap kon niet worden aangemaakt.';
                    } else {
                        $filename = competition_safe_filename($allowed[$mime]);
                        $destination = $baseDir . '/' . $filename;
                        if (@move_uploaded_file($file['tmp_name'], $destination)) {
                            $photoPath = $baseUrl . '/' . $filename;
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
                $stmt = $pdo->prepare(
                    'INSERT INTO rankings_competition_memories
                     (competition_id, author_name, author_email, title, body, photo_path)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $competitionId,
                    $author,
                    $email !== '' ? $email : null,
                    $title !== '' ? $title : null,
                    $body,
                    $photoPath,
                ]);
                header('Location: competition.php?id=' . $competitionId . '#memories');
                exit;
            } catch (Throwable $e) {
                $errors[] = app_debug_enabled()
                    ? 'Opslaan van herinnering is mislukt: ' . $e->getMessage()
                    : 'Opslaan van herinnering is mislukt. Probeer het later opnieuw.';
            }
        }
    }
}

$competition = null;
try {
    $stmt = $pdo->prepare(
        'SELECT id, year, title, class, tasks_headers_json, created_at
         FROM rankings_competitions
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$competitionId]);
    $competition = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Competition load failed: ' . h($e->getMessage()) . '</pre>';
    }
}

if (!$competition) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Wedstrijd niet gevonden', ['active_public' => 'competitions']);
    echo '<main class="card"><h1>Wedstrijd niet gevonden</h1><p class="muted">Deze wedstrijd kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}

$class = $competition['class'] ?: 'Klasse 1';
$rankingUrl = ranking_page_for_class($class) . '?year=' . (int)$competition['year'];

$participantCount = 0;
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rankings_competition_results WHERE competition_id = ?');
    $stmt->execute([$competitionId]);
    $participantCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
}

$taskHeaders = [];
if (!empty($competition['tasks_headers_json'])) {
    $decodedHeaders = json_decode((string)$competition['tasks_headers_json'], true);
    if (is_array($decodedHeaders)) {
        $taskHeaders = $decodedHeaders;
    }
}

$results = [];
try {
    $stmt = $pdo->prepare(
        'SELECT cr.id, cr.pilot_id, cr.pilot_name, cr.tasks_json, cr.total, p.name AS pname
         FROM rankings_competition_results cr
         LEFT JOIN rankings_pilots p ON p.id = cr.pilot_id
         WHERE cr.competition_id = ?
         ORDER BY CAST(cr.total AS DECIMAL(16,6)) DESC, cr.id ASC'
    );
    $stmt->execute([$competitionId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Results load failed: ' . h($e->getMessage()) . '</pre>';
    }
}

if (empty($taskHeaders)) {
    foreach ($results as $result) {
        $tasks = json_decode((string)$result['tasks_json'], true);
        if (is_array($tasks)) {
            for ($i = 0; $i < count($tasks); $i++) {
                $taskHeaders[] = 'Taak ' . ($i + 1);
            }
            break;
        }
    }
}

$displayRanks = competition_tie_aware_ranks($results);
$csrf = app_csrf_token();

$memories = [];
try {
    $stmt = $pdo->prepare(
        'SELECT author_name, author_email, title, body, photo_path, created_at
         FROM rankings_competition_memories
         WHERE competition_id = ? AND is_visible = 1
         ORDER BY created_at DESC'
    );
    $stmt->execute([$competitionId]);
    $memories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Memories load failed: ' . h($e->getMessage()) . '</pre>';
    }
}

app_page_start(app_site_name() . ' - ' . $competition['title'], [
    'active_public' => 'competitions',
    'description' => $competition['title'] . ' ' . (int)$competition['year'] . ' resultaten.',
]);
?>
<main>
  <section class="card">
    <div class="kicker">Wedstrijd</div>
    <h1><?= h($competition['title']) ?> <?= (int)$competition['year'] ?></h1>
    <div class="grid">
      <div class="panel"><strong>Jaar</strong><br><?= (int)$competition['year'] ?></div>
      <div class="panel"><strong>Klasse</strong><br><?= h($class) ?></div>
      <div class="panel"><strong>Deelnemers</strong><br><?= (int)$participantCount ?></div>
      <div class="panel"><strong>Taken</strong><br><?= (int)count($taskHeaders) ?></div>
    </div>
    <p><a class="btn" href="<?= h($rankingUrl) ?>">Bekijk ranglijst <?= (int)$competition['year'] ?></a></p>
  </section>

  <section class="card">
    <h2>Resultaten</h2>
    <?php if (empty($results)): ?>
      <p class="muted">Geen resultaten beschikbaar voor deze wedstrijd.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>#</th>
              <th>Piloot</th>
              <?php foreach ($taskHeaders as $header): ?>
                <th><?= h($header) ?></th>
              <?php endforeach; ?>
              <th>Totaal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $index => $result): ?>
              <?php
                $pilotId = $result['pilot_id'] ? (int)$result['pilot_id'] : 0;
                $pilotName = $result['pname'] ?: $result['pilot_name'];
                $tasks = json_decode((string)$result['tasks_json'], true);
                $tasks = is_array($tasks) ? $tasks : [];
              ?>
              <tr>
                <td><?= (int)$displayRanks[$index] ?></td>
                <td>
                  <?php if ($pilotId > 0): ?>
                    <a href="pilot.php?id=<?= $pilotId ?>"><?= h($pilotName) ?></a>
                  <?php else: ?>
                    <?= h($pilotName) ?>
                  <?php endif; ?>
                </td>
                <?php for ($taskIndex = 0; $taskIndex < count($taskHeaders); $taskIndex++): ?>
                  <?php $value = isset($tasks[$taskIndex]) && is_numeric($tasks[$taskIndex]) ? (float)$tasks[$taskIndex] : 0.0; ?>
                  <td><?= h(app_format_compact_number($value)) ?></td>
                <?php endfor; ?>
                <td><strong><?= h(app_format_compact_number($result['total'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section id="memories" class="card">
    <h2>Herinneringen</h2>

    <?php if (!empty($errors)): ?>
      <div class="alert error">
        <ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if (empty($memories)): ?>
      <p class="muted">Nog geen herinneringen toegevoegd. Wees de eerste en deel jouw herinnering aan deze wedstrijd.</p>
    <?php else: ?>
      <div class="memory-grid">
        <?php foreach ($memories as $memory): ?>
          <article class="panel memory-card">
            <header>
              <strong><?= h($memory['title'] ?: 'Herinnering') ?></strong><br>
              <span class="muted">Door <?= h($memory['author_name']) ?> - <?= h(date('Y-m-d', strtotime($memory['created_at']))) ?></span>
            </header>
            <?php if (!empty($memory['photo_path'])): ?>
              <p><img src="<?= h($memory['photo_path']) ?>" alt="Foto herinnering"></p>
            <?php endif; ?>
            <p><?= nl2br(h($memory['body'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h3>Voeg jouw herinnering toe</h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="mem_action" value="create">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" hidden>
        <div class="grid">
          <label>Naam
            <input type="text" name="author" required maxlength="120">
          </label>
          <label>E-mail (optioneel)
            <input type="email" name="email" maxlength="190">
          </label>
        </div>
        <div class="grid">
          <label>Titel (optioneel)
            <input type="text" name="title" maxlength="160">
          </label>
          <label>Foto (optioneel, max 6 MB)
            <input type="file" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp">
          </label>
        </div>
        <label>Herinnering
          <textarea name="body" required rows="5"></textarea>
        </label>
        <p><button type="submit">Plaatsen</button></p>
      </form>
    </div>
  </section>

  <?php if (app_debug_enabled()): ?>
    <section class="card">
      <h2>Debug</h2>
      <p class="muted">comp_id=<?= (int)$competition['id'] ?>, class=<?= h($class) ?>, rows=<?= (int)count($results) ?>, memories=<?= (int)count($memories) ?>.</p>
    </section>
  <?php endif; ?>
</main>
<?php app_page_end(); ?>
