<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$notice = null;
$error = null;

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
        scoring_store_tracklog_upload($pdo, $_FILES['tracklog'], $pilotName, $pilotEmail);
        $notice = 'Dank je, je tracklog is ontvangen. De scorer kan hem nu meenemen in de juiste taak.';
    } catch (Throwable $e) {
        $error = app_debug_enabled() ? $e->getMessage() : $e->getMessage();
    }
}

app_page_start(app_site_name() . ' - Track upload', [
    'active_public' => 'track_upload',
    'description' => 'IGC tracklog uploaden voor competitie scoring.',
]);
?>
<main>
  <section class="card narrow-card">
    <div class="kicker">Competitie scoring</div>
    <h1>Track upload</h1>
    <p class="muted">Upload je IGC-tracklog. Je naam en e-mail worden gebruikt om je over meerdere taken in dezelfde competitie te herkennen.</p>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
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
