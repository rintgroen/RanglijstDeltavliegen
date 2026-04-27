<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();

$competitions = [];
try {
    $stmt = $pdo->query(
        "SELECT c.id, c.name, c.class, c.scope, c.location, c.status, c.is_public, c.created_at,
                COUNT(t.id) AS published_task_count,
                MAX(t.published_at) AS last_published_at
         FROM rankings_scoring_competitions c
         JOIN rankings_scoring_tasks t ON t.competition_id = c.id AND t.status = 'published'
         WHERE c.is_public = 1
         GROUP BY c.id, c.name, c.class, c.scope, c.location, c.status, c.is_public, c.created_at
         ORDER BY last_published_at DESC, c.created_at DESC"
    );
    $competitions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Scoring list failed: ' . h($e->getMessage()) . '</pre>';
    }
}

app_page_start(app_site_name() . ' - Scoring', [
    'active_public' => 'scoring',
    'description' => 'Gepubliceerde competitie scoring resultaten.',
]);
?>
<main>
  <section class="card">
    <div class="kicker">Competitie scoring</div>
    <h1>Gepubliceerde resultaten</h1>
    <p class="muted">Deze competities staan los van de Nederlandse ranglijst, tenzij NK-resultaten apart via de bestaande admin-upload worden ingevoerd.</p>
  </section>

  <section class="card">
    <?php if (empty($competitions)): ?>
      <p class="muted">Nog geen gepubliceerde scoring-resultaten.</p>
    <?php else: ?>
      <div class="tiles">
        <?php foreach ($competitions as $competition): ?>
          <?php
            $stmt = $pdo->prepare("SELECT id, name, task_date, published_at FROM rankings_scoring_tasks WHERE competition_id = ? AND status = 'published' ORDER BY task_date ASC, id ASC");
            $stmt->execute([(int)$competition['id']]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <article class="card tile">
            <div class="kicker"><?= h($competition['class']) ?> - <?= h($competition['scope']) ?></div>
            <h2><?= h($competition['name']) ?></h2>
            <?php if ($competition['location']): ?><p class="muted"><?= h($competition['location']) ?></p><?php endif; ?>
            <ul class="list-compact">
              <?php foreach ($tasks as $task): ?>
                <li>
                  <span><?= h($task['task_date']) ?> - <a href="scoring_task.php?id=<?= (int)$task['id'] ?>"><?= h($task['name']) ?></a></span>
                  <span class="muted"><?= h(scoring_utc_sql_to_display($task['published_at'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php app_page_end(); ?>
