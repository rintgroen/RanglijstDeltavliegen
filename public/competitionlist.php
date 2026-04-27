<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/app.php';

app_enable_debug();
$pdo = app_db_or_fail();

$competitions = [];
try {
    $stmt = $pdo->query('SELECT id, year, title, class, created_at FROM rankings_competitions ORDER BY year DESC, created_at DESC');
    $competitions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Competition list failed: ' . h($e->getMessage()) . '</pre>';
    }
}

$winnerStmt = $pdo->prepare(
    'SELECT cr.pilot_id AS pid,
            COALESCE(p.name, cr.pilot_name) AS winner_name,
            cr.total
     FROM rankings_competition_results cr
     LEFT JOIN rankings_pilots p ON p.id = cr.pilot_id
     WHERE cr.competition_id = ?
     ORDER BY CAST(cr.total AS DECIMAL(16,6)) DESC, cr.id ASC
     LIMIT 1'
);

app_page_start(app_site_name() . ' - Wedstrijden', [
    'active_public' => 'competitions',
    'description' => 'Wedstrijden en winnaars voor de Nederlandse ranglijst deltavliegen.',
]);
?>
<main class="card">
  <h1>Wedstrijden</h1>

  <?php if (empty($competitions)): ?>
    <p class="muted">Nog geen wedstrijden beschikbaar.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="striped">
        <thead>
          <tr>
            <th>Jaar</th>
            <th>Titel</th>
            <th>Klasse</th>
            <th>Winnaar</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($competitions as $competition): ?>
            <?php
              $winnerId = null;
              $winnerName = null;
              try {
                  $winnerStmt->execute([(int)$competition['id']]);
                  $winner = $winnerStmt->fetch(PDO::FETCH_ASSOC);
                  if ($winner) {
                      $winnerId = $winner['pid'] ? (int)$winner['pid'] : null;
                      $winnerName = $winner['winner_name'];
                  }
              } catch (Throwable $e) {
                  if (app_debug_enabled()) {
                      echo '<!-- winner lookup failed for comp ' . (int)$competition['id'] . ': ' . h($e->getMessage()) . ' -->';
                  }
              }
              $class = $competition['class'] ?: 'Klasse 1';
            ?>
            <tr>
              <td><?= (int)$competition['year'] ?></td>
              <td><a href="competition.php?id=<?= (int)$competition['id'] ?>"><?= h($competition['title']) ?></a></td>
              <td><?= h($class) ?></td>
              <td>
                <?php if ($winnerName): ?>
                  <?php if ($winnerId): ?>
                    <a href="pilot.php?id=<?= (int)$winnerId ?>"><?= h($winnerName) ?></a>
                  <?php else: ?>
                    <?= h($winnerName) ?>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
<?php app_page_end(); ?>
