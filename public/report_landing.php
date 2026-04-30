<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : (isset($_GET['task_id']) ? (int)$_GET['task_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0));
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
if (!$task) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Taak niet gevonden', ['show_public_nav' => false]);
    echo '<main class="card"><h1>Taak niet gevonden</h1><p class="muted">Deze taak kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}

$competition = scoring_load_competition($pdo, (int)$task['competition_id']);
$notice = null;
$error = null;
$postedName = '';
$postedConditions = '';
$postedRetrieval = '0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedName = trim((string)($_POST['pilot_name'] ?? ''));
    $postedConditions = trim((string)($_POST['conditions'] ?? ''));
    $postedRetrieval = (string)($_POST['needs_retrieval'] ?? '0');
    try {
        if (!app_check_csrf()) {
            throw new RuntimeException('Ongeldige inzending. Probeer het opnieuw.');
        }
        scoring_store_landing_report(
            $pdo,
            $taskId,
            $postedName,
            $postedConditions !== '' ? $postedConditions : null,
            $postedRetrieval === '1'
        );
        $notice = 'Dank je, je landing is gemeld. Fijn dat je veilig bent.';
        $postedName = '';
        $postedConditions = '';
        $postedRetrieval = '0';
    } catch (Throwable $e) {
        $error = app_debug_enabled() ? $e->getMessage() : $e->getMessage();
    }
}

$taskBoardHref = 'task_board.php?id=' . (int)$taskId;
$trackUploadHref = 'track_upload.php?task_id=' . (int)$taskId;

app_page_start(app_site_name() . ' - Landing melden', [
    'show_public_nav' => false,
    'description' => 'Veilige landing melden voor een wedstrijdtaak.',
]);
?>
<main>
  <section class="card narrow-card">
    <div class="kicker"><?= h(($competition['class'] ?? $task['class'] ?? '') . ' - ' . ($competition['scope'] ?? $task['scope'] ?? '')) ?></div>
    <h1>Landing melden</h1>
    <p class="muted"><?= h($competition['name'] ?? $task['competition_name']) ?> - <?= h($task['name']) ?></p>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="task_id" value="<?= (int)$taskId ?>">
      <label>Naam
        <input type="text" name="pilot_name" required maxlength="160" autocomplete="name" value="<?= h($postedName) ?>">
      </label>
      <fieldset class="radio-card-group radio-card-group-wide">
        <legend>Condities</legend>
        <label><input type="radio" name="conditions" value="" <?= $postedConditions === '' ? 'checked' : '' ?>> Niet ingevuld</label>
        <label><input type="radio" name="conditions" value="safe" <?= $postedConditions === 'safe' ? 'checked' : '' ?>> Veilig</label>
        <label><input type="radio" name="conditions" value="challenging" <?= $postedConditions === 'challenging' ? 'checked' : '' ?>> Uitdagend</label>
        <label><input type="radio" name="conditions" value="dangerous" <?= $postedConditions === 'dangerous' ? 'checked' : '' ?>> Gevaarlijk</label>
      </fieldset>
      <fieldset class="radio-card-group">
        <legend>Retrieval nodig?</legend>
        <label><input type="radio" name="needs_retrieval" value="0" <?= $postedRetrieval !== '1' ? 'checked' : '' ?>> Nee</label>
        <label><input type="radio" name="needs_retrieval" value="1" <?= $postedRetrieval === '1' ? 'checked' : '' ?>> Ja</label>
      </fieldset>
      <p><button type="submit">Ik ben veilig</button></p>
    </form>
    <p class="actions">
      <a class="btn secondary" href="<?= h($trackUploadHref) ?>">Tracklog uploaden</a>
      <a class="btn secondary" href="<?= h($taskBoardHref) ?>">Terug naar taak</a>
    </p>
  </section>
</main>
<?php app_page_end(); ?>
