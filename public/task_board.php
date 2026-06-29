<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
if (!$task) {
    http_response_code(404);
    app_page_start('Taak niet gevonden - ' . app_site_name(), [
        'show_public_nav' => false,
        'description' => 'Taakbriefing niet gevonden.',
    ]);
    echo '<main class="card"><h1>Taak niet gevonden</h1><p class="muted">Deze taak kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}

$competition = null;
$turnpoints = [];
$gates = [];
$error = null;
try {
    $competition = scoring_load_competition($pdo, (int)$task['competition_id']);
    $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
    $gates = scoring_load_task_gates($pdo, $taskId);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? $e->getMessage() : 'Taakgegevens konden niet worden geladen.';
}

$download = strtolower(trim((string)($_GET['download'] ?? '')));
if ($download === 'xctsk' && count($turnpoints) >= 2) {
    $content = scoring_build_task_xctsk_json($task, $turnpoints, $gates);
    $filename = scoring_task_xctsk_filename($task);
    header('Content-Type: application/xctsk; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

$qrSvg = '';
$taskCodePayload = '';
$qrError = null;
if (count($turnpoints) >= 2) {
    try {
        $taskCodePayload = scoring_build_task_xctsk_qr_payload($task, $turnpoints, $gates);
        $qrSvg = AppQrCode::svg($taskCodePayload);
    } catch (Throwable $e) {
        $qrError = app_debug_enabled() ? $e->getMessage() : 'QR-code kon niet worden gemaakt.';
    }
}

$routeDistance = !empty($turnpoints) ? scoring_task_distance_km($turnpoints) : 0.0;
$speedDistance = !empty($turnpoints) ? scoring_speed_section_boundary_distance_km($turnpoints) : 0.0;
[$sssIndex, $essIndex] = !empty($turnpoints) ? scoring_speed_section_indices($turnpoints) : [0, 0];
$showStartGates = (string)($task['task_type'] ?? 'race') === 'race';
$taskMap = !empty($turnpoints) ? scoring_task_map_data($turnpoints) : null;
$taskMapJson = '';
$leafletAssets = '';
if ($taskMap) {
    $taskMapJson = json_encode(
        $taskMap,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($taskMapJson === false) {
        $taskMap = null;
        $taskMapJson = '';
    } else {
        $leafletAssets = ''
            . '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">' . "\n"
            . '  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>';
    }
}
$gateLabels = [];
if ($showStartGates) {
    foreach ($gates as $gate) {
        $gateLabels[] = scoring_utc_sql_to_local_time($gate['gate_time_at']);
    }
    if (empty($gateLabels)) {
        $gateLabels[] = scoring_utc_sql_to_local_time($task['window_open_at']);
    }
}
$taskDeadlineAt = scoring_task_deadline_at($task);
$reportingDeadlineAt = scoring_task_reporting_deadline_at($task);
$landingReportHref = 'report_landing.php?task_id=' . (int)$taskId;
$trackUploadHref = 'track_upload.php?task_id=' . (int)$taskId;

app_page_start(($competition['name'] ?? $task['competition_name']) . ' - ' . $task['name'], [
    'show_public_nav' => false,
    'description' => 'Taakdetails en QR-code voor vluchtinstrumenten.',
    'body_class' => 'task-share-page',
    'extra_head' => $leafletAssets,
]);
?>
<main>
  <section class="card public-competition-card task-share-card">
    <div class="kicker"><?= h(($competition['class'] ?? $task['class'] ?? '') . ' - ' . ($competition['scope'] ?? $task['scope'] ?? '')) ?></div>
    <h1><?= h($task['name']) ?></h1>
    <p class="muted">
      <?= h($competition['name'] ?? $task['competition_name']) ?>
      <?php if (!empty($competition['location'])): ?> - <?= h($competition['location']) ?><?php endif; ?>
    </p>
  </section>

  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

  <section class="card public-task-map-card task-share-map-card">
    <h2>Taak</h2>
      <div class="stat-grid">
        <div class="stat"><div class="muted">Datum</div><strong><?= h($task['task_date']) ?></strong></div>
        <div class="stat"><div class="muted">Type</div><strong><?= h(scoring_xctsk_start_type($task)) ?></strong></div>
        <div class="stat"><div class="muted">Take-off open</div><strong><?= h(scoring_utc_sql_to_display($task['window_open_at'])) ?></strong></div>
        <div class="stat"><div class="muted">Taakdeadline</div><strong><?= h(scoring_utc_sql_to_display($taskDeadlineAt)) ?></strong></div>
        <div class="stat"><div class="muted">Melddeadline</div><strong><?= h(scoring_utc_sql_to_display($reportingDeadlineAt)) ?></strong></div>
        <div class="stat"><div class="muted">Route totaal</div><strong><?= h(app_format_compact_number($routeDistance, 3)) ?> km</strong></div>
        <div class="stat"><div class="muted">Speedsectie</div><strong><?= h(app_format_compact_number($speedDistance, 3)) ?> km</strong></div>
      </div>

    <div class="public-task-layout">
      <div class="public-task-turnpoints">
        <?php if ($showStartGates): ?>
          <h3>Startgates</h3>
          <div class="chip-list task-share-gates">
            <?php foreach ($gateLabels as $gateLabel): ?>
              <span><?= h($gateLabel) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <h3>Taakpunten</h3>
        <?php if (empty($turnpoints)): ?>
          <p class="muted">Nog geen taakpunten.</p>
        <?php else: ?>
          <ol class="task-turnpoint-list">
            <?php foreach ($turnpoints as $idx => $tp): ?>
              <?php
                $roles = [];
                if ($idx === $sssIndex) {
                    $roles[] = ['label' => 'SSS', 'class' => 'sss'];
                }
                if ($idx === $essIndex) {
                    $roles[] = ['label' => 'ESS', 'class' => 'ess'];
                }
                $code = trim((string)($tp['code'] ?? ''));
              ?>
              <li>
                <span class="task-turnpoint-name">
                  <?= h(scoring_xctsk_waypoint_name($tp)) ?>
                  <?php if ($code !== '' && strcasecmp($code, (string)$tp['name']) !== 0): ?>
                    <span class="muted"><?= h($tp['name']) ?></span>
                  <?php endif; ?>
                </span>
                <span class="task-turnpoint-meta">
                  <?= (int)$tp['radius_m'] ?> m
                  <span><?= h(number_format((float)$tp['latitude'], 5, '.', '')) ?>, <?= h(number_format((float)$tp['longitude'], 5, '.', '')) ?></span>
                  <?php foreach ($roles as $role): ?>
                    <strong class="<?= h($role['class']) ?>"><?= h($role['label']) ?></strong>
                  <?php endforeach; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
      <div class="public-task-map">
        <div class="task-map">
          <div class="task-map-canvas" aria-label="Kaart met taakpunten en geoptimaliseerde route">
            <span class="track-preview-loading"><?= $taskMap ? 'Kaart laden...' : 'Geen taakpunten voor de kaart.' ?></span>
          </div>
          <?php if ($taskMap): ?>
            <div class="task-map-legend">
              <span><i class="task-map-swatch normal"></i>Normaal</span>
              <span><i class="task-map-swatch sss"></i>SSS</span>
              <span><i class="task-map-swatch ess"></i>ESS</span>
            </div>
            <script type="application/json" class="task-map-data"><?= $taskMapJson ?></script>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

	  <section class="card task-share-code-card">
	    <h2>Delen met instrumenten</h2>
    <?php if ($qrSvg !== ''): ?>
      <div class="task-share-code-layout">
        <div class="task-share-code-copy">
          <p class="actions">
            <a class="btn" href="task_board.php?id=<?= (int)$taskId ?>&amp;download=xctsk">Download XCTSK</a>
          </p>
          <label>Taakcode
            <textarea readonly rows="5"><?= h($taskCodePayload) ?></textarea>
          </label>
          <p class="muted">XCTSK taakcode voor XCTrack, FlySkyHy en compatibele Volandoo/CIVL workflows.</p>
        </div>
        <div class="task-share-qr"><?= $qrSvg ?></div>
      </div>
    <?php elseif ($qrError): ?>
      <p class="muted"><?= h($qrError) ?></p>
    <?php else: ?>
      <p class="muted">Voeg minimaal twee taakpunten toe om een instrumentcode te maken.</p>
	    <?php endif; ?>
	  </section>

	  <section class="card task-share-pilot-actions-card">
	    <h2>Na de vlucht</h2>
	    <p class="muted">Meld voor <?= h(scoring_utc_sql_to_display($reportingDeadlineAt)) ?> dat je veilig bent. De taakdeadline <?= h(scoring_utc_sql_to_display($taskDeadlineAt)) ?> is de laatste tijd die voor deze taak telt.</p>
	    <p class="task-share-pilot-actions">
	      <a class="btn" href="<?= h($landingReportHref) ?>">Landing melden</a>
	      <a class="btn secondary" href="<?= h($trackUploadHref) ?>">Tracklog uploaden</a>
	    </p>
	    <p class="muted task-share-pilot-note">Live-tracking kan als voorlopige score-evidence worden gebruikt. Een originele, valide IGC kan gunstiger zijn voor timing en blijft de beste evidence voor strengere wedstrijden.</p>
	  </section>
	</main>
<?php app_page_end(app_site_name()); ?>
