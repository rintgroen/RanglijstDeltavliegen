<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : (isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0);
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
$competition = null;
if ($taskId > 0 && !$task) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Taak niet gevonden', ['active_public' => 'track_upload']);
    echo '<main class="card"><h1>Taak niet gevonden</h1><p class="muted">Deze taak kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}
if ($task) {
    $competition = scoring_load_competition($pdo, (int)$task['competition_id']);
}
$notice = null;
$error = null;
$trackPreview = null;
$trackPreviewJson = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!app_check_csrf()) {
            throw new RuntimeException('Ongeldige inzending. Probeer het opnieuw.');
        }
        $pilotName = trim((string)($_POST['pilot_name'] ?? ''));
        $pilotEmail = scoring_normalize_email((string)($_POST['pilot_email'] ?? ''));
        if ($pilotName === '') {
            throw new RuntimeException('Vul je naam in.');
        }
        if (!filter_var($pilotEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Vul een geldig e-mailadres in.');
        }
        if (!isset($_FILES['tracklog']) || $_FILES['tracklog']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Upload een IGC-bestand.');
        }
        $tracklogId = scoring_store_tracklog_upload($pdo, $_FILES['tracklog'], $pilotName, $pilotEmail);
        if ($task) {
            scoring_link_tracklog_to_task($pdo, $task, $tracklogId, $pilotName, $pilotEmail);
        }
        $trackPreview = scoring_tracklog_map_preview($pdo, $tracklogId);
        $validationStmt = $pdo->prepare('SELECT validation_status, validation_response FROM rankings_scoring_tracklogs WHERE id = ? LIMIT 1');
        $validationStmt->execute([$tracklogId]);
        $validationTracklog = $validationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $validationStatus = strtolower(trim((string)($validationTracklog['validation_status'] ?? 'not_checked')));
        $validationNote = '';
        if ($validationStatus === 'passed') {
            $validationNote = ' FAI-validatie: geldig.';
        } elseif ($validationStatus === 'failed') {
            $validationNote = ' FAI-validatie: niet geldig; je upload blijft beschikbaar als LOG.';
        } elseif ($validationStatus === 'error') {
            $validationNote = ' FAI-validatie kon niet worden afgerond; je upload blijft beschikbaar als LOG.';
        }
        $notice = $task
            ? 'Dank je, je tracklog is ontvangen en gekoppeld aan deze taak.' . $validationNote
            : 'Dank je, je tracklog is ontvangen. De scorer kan hem nu meenemen in de juiste taak.' . $validationNote;
    } catch (Throwable $e) {
        $error = app_debug_enabled() ? $e->getMessage() : $e->getMessage();
    }
}

$leafletAssets = '';
if ($trackPreview) {
    $trackPreviewJson = json_encode(
        $trackPreview,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($trackPreviewJson === false) {
        $trackPreview = null;
        $trackPreviewJson = '';
    } else {
        $leafletAssets = ''
            . '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">' . "\n"
            . '  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>';
    }
}

app_page_start(app_site_name() . ' - Track upload', [
    'active_public' => 'track_upload',
    'description' => 'IGC tracklog uploaden voor competitie scoring.',
    'extra_head' => $leafletAssets,
]);
?>
<main>
  <section class="card narrow-card">
    <div class="kicker">Competitie scoring</div>
    <h1>Track upload</h1>
    <?php if ($task): ?>
      <p class="muted"><?= h($competition['name'] ?? $task['competition_name']) ?> - <?= h($task['name']) ?>. Upload je IGC-tracklog voor deze taak.</p>
    <?php else: ?>
      <p class="muted">Upload je IGC-tracklog. Je naam en e-mail worden gebruikt om je over meerdere taken in dezelfde competitie te herkennen.</p>
    <?php endif; ?>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($trackPreview): ?>
      <div class="track-preview">
        <div class="track-preview-map" aria-label="Kaartvoorbeeld van je tracklog">
          <span class="track-preview-loading">Kaart laden...</span>
        </div>
        <div class="track-preview-meta">
          <strong>Trackvoorbeeld</strong>
          <span><?= (int)$trackPreview['fix_count'] ?> trackpunten uit <?= h($trackPreview['filename']) ?></span>
        </div>
        <script type="application/json" class="track-preview-data"><?= $trackPreviewJson ?></script>
      </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="task_id" value="<?= (int)$taskId ?>">
      <label>Naam
        <input type="text" name="pilot_name" required maxlength="160" autocomplete="name">
      </label>
      <label>E-mail
        <input type="email" name="pilot_email" required maxlength="190" autocomplete="email">
      </label>
      <label>IGC-tracklog
        <input type="file" name="tracklog" accept=".igc,text/plain" required>
      </label>
      <p><button type="submit">Track uploaden</button></p>
    </form>
  </section>
</main>
<?php app_page_end(); ?>
