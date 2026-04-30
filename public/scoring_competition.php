<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
$publication = $task ? scoring_load_task_publication($pdo, $taskId) : null;
if (!$task || $task['status'] !== 'published' || (int)$task['competition_public'] !== 1 || !$publication) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Tussenstand niet gevonden', ['active_public' => 'scoring']);
    echo '<main class="card"><h1>Tussenstand niet gevonden</h1><p class="muted">Deze tussenstand is niet gepubliceerd.</p></main>';
    app_page_end();
    exit;
}

$competition = null;
$throughTask = $task;
$tasks = [];
$publicTasks = [];
$rows = [];
$loadError = null;
try {
    $standings = scoring_competition_standings_through_task($pdo, $taskId);
    $competition = $standings['competition'];
    $throughTask = $standings['through_task'];
    $tasks = $standings['tasks'];
    $rows = $standings['rows'];
} catch (Throwable $e) {
    $loadError = app_debug_enabled() ? 'Tussenstand laden mislukt: ' . $e->getMessage() : 'Tussenstand laden mislukt.';
}
try {
    if (!$competition) {
        $competition = scoring_load_competition($pdo, (int)$task['competition_id']);
    }
    $publicTasks = scoring_load_public_competition_tasks($pdo, (int)$task['competition_id']);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        $loadError = trim(($loadError ? $loadError . ' ' : '') . 'Competitielinks laden mislukt: ' . $e->getMessage());
    }
}

$competitionName = $competition ? (string)$competition['name'] : (string)$task['competition_name'];

app_page_start(app_site_name() . ' - ' . $competitionName . ' tussenstand', [
    'active_public' => 'scoring',
    'description' => 'Gepubliceerde cumulatieve competitie scoring resultaten.',
]);
?>
<main>
  <section class="card public-competition-card">
    <div class="kicker"><?= h(($competition['class'] ?? $task['class']) . ' - ' . ($competition['scope'] ?? $task['scope'])) ?></div>
    <h1><?= h($competitionName) ?></h1>
    <?php if (!empty($competition['location'])): ?><p class="muted"><?= h($competition['location']) ?></p><?php endif; ?>
    <?php if (!empty($publicTasks)): ?>
      <ul class="list-compact">
        <?php foreach ($publicTasks as $publicTask): ?>
          <?php
            $isCurrentStanding = (int)$publicTask['id'] === (int)$throughTask['id'];
            $standingLinkClass = 'score-link' . ($isCurrentStanding ? ' is-active' : '');
          ?>
          <li>
            <span>
              <?= h($publicTask['task_date']) ?> -
              <a class="score-link" href="scoring_task.php?id=<?= (int)$publicTask['id'] ?>"><?= h($publicTask['name']) ?></a>
              <span class="muted">/</span>
              <a class="<?= h($standingLinkClass) ?>" href="scoring_competition.php?task_id=<?= (int)$publicTask['id'] ?>"<?= $isCurrentStanding ? ' aria-current="page"' : '' ?>>Competitieresultaat t/m <?= h($publicTask['name']) ?></a>
            </span>
            <span class="muted"><?= h(scoring_utc_sql_to_display($publicTask['published_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Competitieresultaat t/m <?= h($throughTask['name']) ?></h2>
    <?php if ($loadError): ?>
      <div class="alert error"><?= h($loadError) ?></div>
    <?php elseif (empty($rows)): ?>
      <p class="muted">Geen cumulatieve resultaten beschikbaar.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>#</th>
              <th>Piloot</th>
              <?php foreach ($tasks as $taskHeader): ?>
                <th><?= h($taskHeader['name']) ?><br><span class="muted"><?= h($taskHeader['task_date']) ?></span></th>
              <?php endforeach; ?>
              <th>Totaal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= (int)$row['rank_no'] ?></td>
                <td><?= h($row['pilot_name']) ?></td>
                <?php foreach ($row['task_points'] as $taskIndex => $points): ?>
                  <?php $evidenceCode = scoring_normalize_evidence_code($row['task_evidence'][$taskIndex] ?? ''); ?>
                  <td>
                    <?= h(app_format_compact_number($points, 1)) ?>
                    <?php if (!empty($row['task_evidence'][$taskIndex])): ?>
                      <span class="evidence-badge evidence-<?= h(strtolower($evidenceCode)) ?>" title="<?= h(scoring_evidence_label($evidenceCode)) ?>"><?= h($evidenceCode) ?></span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td><strong><?= h(app_format_compact_number($row['total_points'], 1)) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted evidence-legend"><?= h(scoring_evidence_legend_text()) ?></p>
    <?php endif; ?>
  </section>
</main>
<?php app_page_end(); ?>
