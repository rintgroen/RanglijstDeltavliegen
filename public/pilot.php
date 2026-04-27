<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/ranking.php';

app_enable_debug();
$pdo = app_db_or_fail();

$pilotId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pilotId <= 0) {
    http_response_code(400);
    app_page_start(app_site_name() . ' - Piloot', ['active_public' => '']);
    echo '<main class="card"><h1>Piloot</h1><p class="muted">Geen piloot gespecificeerd.</p></main>';
    app_page_end();
    exit;
}

$pilot = null;
try {
    $st = $pdo->prepare("SELECT id, name, civl_id FROM rankings_pilots WHERE id = ?");
    $st->execute([$pilotId]);
    $pilot = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

if (!$pilot) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Piloot niet gevonden', ['active_public' => '']);
    echo '<main class="card"><h1>Piloot niet gevonden</h1><p class="muted">Deze piloot kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}

$history = ranking_pilot_history($pdo, $pilotId);
$pilotName = (string)$pilot['name'];
$civlId = $pilot['civl_id'] ?? '';

app_page_start(app_site_name() . ' - ' . $pilotName, [
    'description' => 'Ranglijsthistorie voor ' . $pilotName . '.',
]);
?>
<main>
  <section class="card">
    <div class="kicker">Piloot</div>
    <h1><?= h($pilotName) ?></h1>
    <?php if (!empty($civlId)): ?>
      <p class="muted">
        <a href="https://civlcomps.org/pilot/<?= urlencode((string)$civlId) ?>/ranking" target="_blank" rel="noopener">Wereldranglijst-profiel (CIVL)</a>
      </p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Ranglijsthistorie</h2>
    <div class="table-responsive">
      <table class="striped">
        <thead>
          <tr>
            <th>Jaar</th>
            <th>Klasse</th>
            <th>Rang</th>
            <th>NK jaar</th>
            <th>Vorig NK</th>
            <th>WPRS / NK jaar -2</th>
            <th>Totaal</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($history)): ?>
            <tr><td colspan="7" class="muted">Geen gegevens om te tonen.</td></tr>
          <?php else: ?>
            <?php foreach ($history as $row): ?>
              <tr>
                <td>
                  <a href="<?= h(ranking_page_for_class($row['class'])) ?>?year=<?= (int)$row['year'] ?>"><?= (int)$row['year'] ?></a>
                </td>
                <td><?= h($row['class']) ?></td>
                <td><?= isset($row['rank']) ? (int)$row['rank'] : '-' ?></td>
                <td><?= app_format_points($row['nk_cur']) ?><?= $row['pos_cur'] ? ' <span class="muted">(pos ' . (int)$row['pos_cur'] . ')</span>' : '' ?></td>
                <td><?= app_format_points($row['nk_prev']) ?><?= $row['pos_prev'] ? ' <span class="muted">(pos ' . (int)$row['pos_prev'] . ')</span>' : '' ?></td>
                <?php if (!empty($row['historic'])): ?>
                  <td><?= app_format_points($row['nk_prev2']) ?><?= $row['pos_prev2'] ? ' <span class="muted">(pos ' . (int)$row['pos_prev2'] . ')</span>' : '' ?></td>
                <?php else: ?>
                  <td>
                    <?= app_format_points($row['wprs']) ?>
                    <?php if ($row['wprs_raw'] !== null): ?>
                      <span class="muted">(WPRS <?= h(app_format_compact_number($row['wprs_raw'])) ?>)</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td><strong><?= app_format_points($row['total']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if (app_debug_enabled()): ?>
    <section class="card">
      <h2>Debug</h2>
      <pre><?php
        echo 'Pilot ID: ', $pilotId, ' (', $pilotName, ')', PHP_EOL;
        echo 'Row count: ', count($history), PHP_EOL;
      ?></pre>
    </section>
  <?php endif; ?>
</main>
<?php app_page_end(); ?>
