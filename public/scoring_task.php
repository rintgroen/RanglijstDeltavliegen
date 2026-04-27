<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
if (!$task || $task['status'] !== 'published' || (int)$task['competition_public'] !== 1) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Resultaat niet gevonden', ['active_public' => 'scoring']);
    echo '<main class="card"><h1>Resultaat niet gevonden</h1><p class="muted">Deze taak is niet gepubliceerd.</p></main>';
    app_page_end();
    exit;
}

$results = [];
try {
    $stmt = $pdo->prepare(
        'SELECT *
         FROM rankings_scoring_task_flights
         WHERE task_id = ? AND is_excluded = 0 AND scored_at IS NOT NULL
         ORDER BY rank_no ASC, total_points DESC, pilot_name ASC'
    );
    $stmt->execute([$taskId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Results failed: ' . h($e->getMessage()) . '</pre>';
    }
}

$summary = [];
if (!empty($task['scoring_summary_json'])) {
    $decoded = json_decode((string)$task['scoring_summary_json'], true);
    $summary = is_array($decoded) ? $decoded : [];
}

app_page_start(app_site_name() . ' - ' . $task['competition_name'] . ' ' . $task['name'], [
    'active_public' => 'scoring',
    'description' => 'Gepubliceerde taakresultaten.',
]);
?>
<main>
  <section class="card">
    <div class="kicker"><?= h($task['competition_name']) ?></div>
    <h1><?= h($task['name']) ?></h1>
    <div class="stat-grid">
      <div class="stat"><div class="muted">Datum</div><strong><?= h($task['task_date']) ?></strong></div>
      <div class="stat"><div class="muted">Type</div><strong><?= h($task['task_type']) ?></strong></div>
      <div class="stat"><div class="muted">Afstand</div><strong><?= h(app_format_compact_number($task['task_distance_km'] ?: 0, 3)) ?> km</strong></div>
      <div class="stat"><div class="muted">Validiteit</div><strong><?= h(app_format_compact_number(($summary['task_validity'] ?? 0) * 100, 1)) ?>%</strong></div>
    </div>
  </section>

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
