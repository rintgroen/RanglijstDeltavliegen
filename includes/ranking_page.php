<?php
require_once __DIR__ . '/ranking.php';

function ranking_render_page(string $class, string $activePublic): void {
    app_enable_debug();
    $pdo = app_db_or_fail();
    $years = ranking_years($pdo, $class);
    $defaultYear = $years[0] ?? ranking_latest_year($pdo, $class);
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$defaultYear;
    $ranking = ranking_compute($pdo, $class, $year);
    $isHistoric = (bool)$ranking['is_historic'];
    $rows = $ranking['rows'];
    $nkCur = $ranking['nk_cur'];
    $nkPrev = $ranking['nk_prev'];
    $nkPrev2 = $ranking['nk_prev2'];

    $title = app_site_name() . ' - ' . $class . ' ' . $year;
    app_page_start($title, [
        'active_public' => $activePublic,
        'description' => $class . ' ranglijst deltavliegen ' . $year,
    ]);
    ?>
<main>
  <section class="card">
    <div class="section-header">
      <div>
        <div class="kicker">Ranglijst</div>
        <h1><?= h($class) ?> <?= (int)$year ?></h1>
      </div>
      <form method="get" action="<?= h(ranking_page_for_class($class)) ?>" class="toolbar">
        <label for="year">Jaar
          <select id="year" name="year" onchange="this.form.submit()">
            <?php foreach ($years as $candidateYear): ?>
              <option value="<?= (int)$candidateYear ?>" <?= (int)$candidateYear === $year ? 'selected' : '' ?>><?= (int)$candidateYear ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button type="submit">Toon</button></noscript>
      </form>
    </div>
  </section>

  <section class="card">
    <?php if (!$ranking['available']): ?>
      <p class="muted">
        Ranking voor <?= (int)$year ?> is nog niet beschikbaar.
        Benodigde data:
        <?php if ($isHistoric): ?>
          NK <?= (int)$year ?>, NK <?= (int)($year - 1) ?> en NK <?= (int)($year - 2) ?> uit Wedstrijden (<?= h($class) ?>).
        <?php else: ?>
          NK <?= (int)$year ?>, NK <?= (int)($year - 1) ?> uit Wedstrijden (<?= h($class) ?>) en WPRS per 1 oktober (<?= h($class) ?>).
        <?php endif; ?>
      </p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>#</th>
              <th>Piloot</th>
              <th>
                <?php if ($nkCur['cid']): ?>
                  <a href="competition.php?id=<?= (int)$nkCur['cid'] ?>">NK <?= (int)$year ?></a>
                <?php else: ?>NK <?= (int)$year ?><?php endif; ?>
              </th>
              <th>
                <?php if ($nkPrev['cid']): ?>
                  <a href="competition.php?id=<?= (int)$nkPrev['cid'] ?>">NK <?= (int)($year - 1) ?></a>
                <?php else: ?>NK <?= (int)($year - 1) ?><?php endif; ?>
              </th>
              <?php if ($isHistoric): ?>
                <th>
                  <?php if ($nkPrev2['cid']): ?>
                    <a href="competition.php?id=<?= (int)$nkPrev2['cid'] ?>">NK <?= (int)($year - 2) ?></a>
                  <?php else: ?>NK <?= (int)($year - 2) ?><?php endif; ?>
                </th>
              <?php else: ?>
                <th>
                  <a target="_blank" rel="noopener" href="<?= h(ranking_wprs_url($class, $year)) ?>">WPRS <?= (int)$year ?></a>
                </th>
              <?php endif; ?>
              <th>Totaal</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="muted">Geen gegevens om te tonen.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= (int)$row['rank'] ?></td>
                  <td><a href="pilot.php?id=<?= (int)$row['id'] ?>"><?= h($row['name']) ?></a></td>
                  <td><?= app_format_points($row['nk_cur']) ?><?= $row['pos_cur'] ? ' <span class="muted">(pos ' . (int)$row['pos_cur'] . ')</span>' : '' ?></td>
                  <td><?= app_format_points($row['nk_prev']) ?><?= $row['pos_prev'] ? ' <span class="muted">(pos ' . (int)$row['pos_prev'] . ')</span>' : '' ?></td>
                  <?php if ($isHistoric): ?>
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
    <?php endif; ?>
  </section>

  <?php if (app_debug_enabled()): ?>
    <section class="card">
      <h2>Debug</h2>
      <pre><?php
        echo 'year=', $year, ' historic=', ($isHistoric ? 'yes' : 'no'), PHP_EOL;
        echo 'nkCurId=', var_export($nkCur['cid'], true), ' participants=', $nkCur['participants'], PHP_EOL;
        echo 'nkPrevId=', var_export($nkPrev['cid'], true), ' participants=', $nkPrev['participants'], PHP_EOL;
        if ($isHistoric) {
            echo 'nkPrev2Id=', var_export($nkPrev2['cid'], true), ' participants=', $nkPrev2['participants'], PHP_EOL;
        } else {
            echo 'maxWprs=', $ranking['max_wprs'], PHP_EOL;
        }
      ?></pre>
    </section>
  <?php endif; ?>
</main>
<?php
    app_page_end();
}
