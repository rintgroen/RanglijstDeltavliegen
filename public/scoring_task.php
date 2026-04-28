<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
$publication = $task ? scoring_load_task_publication($pdo, $taskId) : null;
if (!$task || $task['status'] !== 'published' || (int)$task['competition_public'] !== 1 || !$publication) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Resultaat niet gevonden', ['active_public' => 'scoring']);
    echo '<main class="card"><h1>Resultaat niet gevonden</h1><p class="muted">Deze taak is niet gepubliceerd.</p></main>';
    app_page_end();
    exit;
}

$results = [];
$turnpoints = [];
$competition = null;
$publicTasks = [];
try {
    $competition = scoring_load_competition($pdo, (int)$task['competition_id']);
    $publicTasks = scoring_load_public_competition_tasks($pdo, (int)$task['competition_id']);
    $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Task context failed: ' . h($e->getMessage()) . '</pre>';
    }
}

try {
    $results = scoring_load_task_public_results($pdo, $taskId);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Results failed: ' . h($e->getMessage()) . '</pre>';
    }
}

$summary = [];
if (!empty($publication['scoring_summary_json'])) {
    $decoded = json_decode((string)$publication['scoring_summary_json'], true);
    $summary = is_array($decoded) ? $decoded : [];
}

$taskMap = !empty($turnpoints) ? scoring_task_map_data($turnpoints) : null;
$taskMapJson = '';
$leafletAssets = '';
$taskMapSssIndex = null;
$taskMapEssIndex = null;
if ($taskMap) {
    [$taskMapSssIndex, $taskMapEssIndex] = scoring_speed_section_indices($turnpoints);
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

app_page_start(app_site_name() . ' - ' . $task['competition_name'] . ' ' . $task['name'], [
    'active_public' => 'scoring',
    'description' => 'Gepubliceerde taakresultaten.',
    'extra_head' => $leafletAssets,
]);
?>
<main>
  <section class="card public-competition-card">
    <div class="kicker"><?= h(($competition['class'] ?? $task['class']) . ' - ' . ($competition['scope'] ?? $task['scope'])) ?></div>
    <h1><?= h($competition['name'] ?? $task['competition_name']) ?></h1>
    <?php if (!empty($competition['location'])): ?><p class="muted"><?= h($competition['location']) ?></p><?php endif; ?>
    <?php if (!empty($publicTasks)): ?>
      <ul class="list-compact">
        <?php foreach ($publicTasks as $publicTask): ?>
          <?php
            $isCurrentTask = (int)$publicTask['id'] === (int)$task['id'];
            $taskLinkClass = 'score-link' . ($isCurrentTask ? ' is-active' : '');
          ?>
          <li>
            <span>
              <?= h($publicTask['task_date']) ?> -
              <a class="<?= h($taskLinkClass) ?>" href="scoring_task.php?id=<?= (int)$publicTask['id'] ?>"<?= $isCurrentTask ? ' aria-current="page"' : '' ?>><?= h($publicTask['name']) ?></a>
              <span class="muted">/</span>
              <a class="score-link" href="scoring_competition.php?task_id=<?= (int)$publicTask['id'] ?>">Competitieresultaat t/m <?= h($publicTask['name']) ?></a>
            </span>
            <span class="muted"><?= h(scoring_utc_sql_to_display($publicTask['published_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($taskMap): ?>
    <section class="card public-task-map-card">
      <h2><?= h($task['name']) ?></h2>
      <div class="stat-grid">
        <div class="stat"><div class="muted">Datum</div><strong><?= h($task['task_date']) ?></strong></div>
        <div class="stat"><div class="muted">Type</div><strong><?= h($task['task_type']) ?></strong></div>
        <div class="stat"><div class="muted">Afstand</div><strong><?= h(app_format_compact_number($publication['task_distance_km'] ?: 0, 3)) ?> km</strong></div>
        <div class="stat"><div class="muted">Validiteit</div><strong><?= h(app_format_compact_number(($summary['task_validity'] ?? 0) * 100, 1)) ?>%</strong></div>
      </div>
      <div class="public-task-layout">
        <div class="public-task-turnpoints">
          <h3>Taakpunten</h3>
          <ol class="task-turnpoint-list">
            <?php foreach ($turnpoints as $idx => $tp): ?>
              <?php
                $roles = [];
                if ($idx === $taskMapSssIndex) {
                    $roles[] = ['label' => 'SSS', 'class' => 'sss'];
                }
                if ($idx === $taskMapEssIndex) {
                    $roles[] = ['label' => 'ESS', 'class' => 'ess'];
                }
              ?>
              <li>
                <span class="task-turnpoint-name"><?= h($tp['name']) ?></span>
                <span class="task-turnpoint-meta">
                  <?= (int)$tp['radius_m'] ?> m
                  <?php foreach ($roles as $role): ?>
                    <strong class="<?= h($role['class']) ?>"><?= h($role['label']) ?></strong>
                  <?php endforeach; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
        <div class="public-task-map">
          <div class="task-map">
            <div class="task-map-canvas" aria-label="Kaart met taakpunten en geoptimaliseerde route">
              <span class="track-preview-loading">Kaart laden...</span>
            </div>
            <div class="task-map-legend">
              <span><i class="task-map-swatch normal"></i>Normaal</span>
              <span><i class="task-map-swatch sss"></i>SSS</span>
              <span><i class="task-map-swatch ess"></i>ESS</span>
            </div>
            <script type="application/json" class="task-map-data"><?= $taskMapJson ?></script>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <section class="card">
    <h2>Resultaten</h2>
    <?php if (empty($results)): ?>
      <p class="muted">Geen resultaten beschikbaar.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>#</th>
              <th>Piloot</th>
              <th>Afstand</th>
              <th>Tijd</th>
              <th>Afstandspunten</th>
              <th>Tijd</th>
              <th>Leading</th>
              <th>Arrival</th>
              <th>Totaal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $row): ?>
              <tr>
                <td><?= (int)$row['rank_no'] ?></td>
                <td><?= h($row['pilot_name']) ?></td>
                <td><?= h(app_format_compact_number($row['distance_km'], 3)) ?> km<?= (int)$row['reached_goal'] === 1 ? ' <span class="muted">goal</span>' : '' ?></td>
                <td><?= h(scoring_format_duration($row['time_seconds'] !== null ? (int)$row['time_seconds'] : null)) ?></td>
                <td><?= h(app_format_compact_number($row['distance_points'], 1)) ?></td>
                <td><?= h(app_format_compact_number($row['time_points'], 1)) ?></td>
                <td><?= h(app_format_compact_number($row['leading_points'], 1)) ?></td>
                <td><?= h(app_format_compact_number(((float)$row['arrival_position_points']) + ((float)$row['arrival_time_points']), 1)) ?></td>
                <td><strong><?= h(app_format_compact_number($row['total_points'], 1)) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($summary['implementation_note'])): ?>
        <p class="muted"><?= h($summary['implementation_note']) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<?php app_page_end(); ?>
