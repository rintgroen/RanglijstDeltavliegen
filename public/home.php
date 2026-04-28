<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/ranking.php';

app_enable_debug();
$pdo = app_db_or_fail();

function home_ranking_top(PDO $pdo, string $class): array {
    $year = ranking_latest_complete_year($pdo, $class);
    if ($year === null) {
        return ['year' => null, 'rows' => [], 'note' => 'Nog geen complete gegevens gevonden.'];
    }
    $ranking = ranking_compute($pdo, $class, $year);
    return ['year' => $year, 'rows' => array_slice($ranking['rows'], 0, 4), 'note' => ''];
}

$k1Top = home_ranking_top($pdo, 'Klasse 1');
$sportTop = home_ranking_top($pdo, 'Sportklasse');

$latestCompetitions = [];
try {
    $rs = $pdo->query("SELECT id, year, title, class FROM rankings_competitions ORDER BY year DESC, created_at DESC LIMIT 4");
    $latestCompetitions = $rs ? $rs->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
}

$memoryTable = 'rankings_competition_memories';
$memoryTextCol = app_first_existing_column($pdo, $memoryTable, ['body', 'text', 'content', 'message', 'description', 'comment', 'note']);
$memoryAuthorCol = app_first_existing_column($pdo, $memoryTable, ['author_name', 'author', 'name', 'username', 'posted_by']);
$memoryCreatedCol = app_first_existing_column($pdo, $memoryTable, ['created_at', 'created', 'date', 'timestamp', 'posted_at']);
$memoryVisibleCol = app_first_existing_column($pdo, $memoryTable, ['is_visible', 'visible', 'is_published', 'published', 'approved']);
$memoryPhotoCol = app_first_existing_column($pdo, $memoryTable, ['photo_path', 'image_path', 'image', 'photo', 'picture', 'file_path', 'url']);
$whereVisible = $memoryVisibleCol ? "m.`$memoryVisibleCol` = 1" : '1=1';

$latestMemories = [];
try {
    $authorExpr = $memoryAuthorCol ? "m.`$memoryAuthorCol` AS mem_author" : "'' AS mem_author";
    $textExpr = $memoryTextCol ? "m.`$memoryTextCol` AS mem_text" : "'' AS mem_text";
    $createdExpr = $memoryCreatedCol ? "m.`$memoryCreatedCol` AS mem_created" : "NULL AS mem_created";
    $photoExpr = $memoryPhotoCol ? "m.`$memoryPhotoCol` AS mem_photo" : "'' AS mem_photo";
    $orderExpr = $memoryCreatedCol ? "m.`$memoryCreatedCol` DESC, m.id DESC" : 'm.id DESC';
    $sql = "SELECT m.id AS mem_id, m.competition_id AS mem_competition_id, c.title AS mem_competition_title, c.year AS mem_competition_year, $authorExpr, $textExpr, $createdExpr, $photoExpr
            FROM $memoryTable m
            LEFT JOIN rankings_competitions c ON c.id = m.competition_id
            WHERE $whereVisible
            ORDER BY $orderExpr
            LIMIT 8";
    $rs = $pdo->query($sql);
    $latestMemories = $rs ? $rs->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<p class="muted">[debug] memories query error: ' . h($e->getMessage()) . '</p>';
    }
}

function home_memory_asset_path($path): ?string {
    $path = trim((string)$path);
    if ($path === '') {
        return null;
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return ltrim($path, '/');
}

$stats = ['competitions' => 0, 'pilots' => 0, 'memories' => 0, 'results' => 0];
try { $stats['competitions'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['pilots'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_pilots")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['memories'] = (int)$pdo->query("SELECT COUNT(*) FROM $memoryTable")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['results'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competition_results")->fetchColumn(); } catch (Throwable $e) {}

app_page_start(app_site_name() . ' - Home', [
    'active_public' => 'home',
    'body_class' => 'home-shell',
    'description' => 'Nederlandse ranglijst deltavliegen voor Klasse 1 en Sportklasse.',
]);
?>
<main>
  <section class="home-hero">
    <div class="kicker">Nederlandse ranglijst</div>
    <h1>Ranglijst Deltavliegen</h1>
    <p class="muted">Een overzicht van prestaties in Klasse 1 en Sportklasse, gebaseerd op NK-resultaten en WPRS-punten.</p>
  </section>

  <section class="tiles home-grid" aria-label="Overzicht">
    <article class="card tile">
      <div class="kicker">Actuele top <?= $k1Top['year'] ? ' - ' . (int)$k1Top['year'] : '' ?></div>
      <h2>Top 4 Klasse 1</h2>
      <?php if (!empty($k1Top['rows'])): ?>
        <ol class="list-compact">
          <?php foreach ($k1Top['rows'] as $row): ?>
            <li>
              <span><?= (int)$row['rank'] ?>. <a href="pilot.php?id=<?= (int)$row['id'] ?>"><?= h($row['name']) ?></a></span>
              <strong><?= app_format_points($row['total'], 1) ?></strong>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="actions"><a class="btn" href="ranking.php?year=<?= (int)$k1Top['year'] ?>">Bekijk volledige ranglijst</a></p>
      <?php else: ?>
        <p class="muted"><?= h($k1Top['note']) ?></p>
      <?php endif; ?>
    </article>

    <article class="card tile">
      <div class="kicker">Actuele top <?= $sportTop['year'] ? ' - ' . (int)$sportTop['year'] : '' ?></div>
      <h2>Top 4 Sportklasse</h2>
      <?php if (!empty($sportTop['rows'])): ?>
        <ol class="list-compact">
          <?php foreach ($sportTop['rows'] as $row): ?>
            <li>
              <span><?= (int)$row['rank'] ?>. <a href="pilot.php?id=<?= (int)$row['id'] ?>"><?= h($row['name']) ?></a></span>
              <strong><?= app_format_points($row['total'], 1) ?></strong>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="actions"><a class="btn" href="sportclass.php?year=<?= (int)$sportTop['year'] ?>">Bekijk volledige ranglijst</a></p>
      <?php else: ?>
        <p class="muted"><?= h($sportTop['note']) ?></p>
      <?php endif; ?>
    </article>

    <article class="card tile">
      <div class="kicker">Recent</div>
      <h2>Laatste NK Wedstrijden</h2>
      <?php if (!empty($latestCompetitions)): ?>
        <ul class="list-compact">
          <?php foreach ($latestCompetitions as $competition): ?>
            <li>
              <span><?= (int)$competition['year'] ?> - <a href="competition.php?id=<?= (int)$competition['id'] ?>"><?= h($competition['title']) ?></a></span>
              <span class="muted"><?= h($competition['class'] ?: 'Klasse 1') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="actions"><a class="btn" href="competitionlist.php">Alle NK wedstrijden</a></p>
      <?php else: ?>
        <p class="muted">Nog geen wedstrijden beschikbaar.</p>
      <?php endif; ?>
    </article>

    <article class="card tile memory-feature">
      <div class="kicker">Collectief geheugen</div>
      <h2>NK Herinneringen</h2>
      <?php if (!empty($latestMemories)): ?>
        <?php $slideCount = count($latestMemories); ?>
        <div class="memory-showcase <?= $slideCount === 1 ? 'is-single' : '' ?>" style="--memory-slide-count: <?= (int)max(1, $slideCount) ?>;">
          <?php if ($slideCount > 1): ?>
            <div class="memory-progress" aria-hidden="true"><span></span></div>
          <?php endif; ?>
          <?php foreach ($latestMemories as $index => $memory): ?>
            <?php
              $competitionId = isset($memory['mem_competition_id']) ? (int)$memory['mem_competition_id'] : 0;
              $photoPath = home_memory_asset_path($memory['mem_photo'] ?? '');
              $snippet = app_truncate((string)($memory['mem_text'] ?? ''), $photoPath ? 170 : 260);
              $author = (($memory['mem_author'] ?? '') !== '') ? $memory['mem_author'] : 'Onbekend';
              $competitionTitle = trim((string)($memory['mem_competition_title'] ?? ''));
              $competitionYear = isset($memory['mem_competition_year']) ? (int)$memory['mem_competition_year'] : 0;
              $competitionLabel = $competitionTitle !== '' ? $competitionTitle : 'wedstrijd';
              if ($competitionYear > 0 && strpos($competitionLabel, (string)$competitionYear) === false) {
                  $competitionLabel .= ' (' . $competitionYear . ')';
              }
            ?>
            <article class="memory-slide <?= $photoPath ? 'has-photo' : 'is-text-only' ?>" style="--slide-index: <?= (int)$index ?>;">
              <?php if ($photoPath): ?>
                <figure class="memory-photo">
                  <img src="<?= h($photoPath) ?>" alt="Herinnering foto">
                </figure>
              <?php endif; ?>
              <div class="memory-copy">
                <div class="muted">
                  Door <?= h($author) ?><?php if ($competitionId > 0): ?> over <a href="competition.php?id=<?= $competitionId ?>#memories"><?= h($competitionLabel) ?></a><?php endif; ?>
                </div>
                <p><?= h($snippet) ?></p>
                <?php if ($competitionId > 0): ?>
                  <a href="competition.php?id=<?= $competitionId ?>#memories">Open herinnering</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted">Nog geen herinneringen geplaatst.</p>
      <?php endif; ?>
      <p class="muted memory-contribution-note">Draag bij aan het collectieve geheugen: blader naar een <a href="competitionlist.php">NK resultatenlijst</a> en voeg daar je herinnering toe.</p>
    </article>

    <article class="card tile">
      <div class="kicker">Overzicht & contact</div>
      <h2>Site statistieken</h2>
      <div class="stat-grid">
        <div class="stat"><div class="muted">Wedstrijden</div><div class="value"><?= (int)$stats['competitions'] ?></div></div>
        <div class="stat"><div class="muted">Piloten</div><div class="value"><?= (int)$stats['pilots'] ?></div></div>
        <div class="stat"><div class="muted">Herinneringen</div><div class="value"><?= (int)$stats['memories'] ?></div></div>
        <div class="stat"><div class="muted">Resultaten</div><div class="value"><?= (int)$stats['results'] ?></div></div>
      </div>
      <div class="contact-strip">
        <h3>Contact</h3>
        <p>Vragen en opmerkingen kunnen naar <a href="mailto:rob@intgroen.net">rob@intgroen.net</a>.</p>
      </div>
    </article>
  </section>

  <?php if (app_debug_enabled()): ?>
    <section class="card">
      <h2>Debug</h2>
      <p class="muted">
        Memories columns:
        text=<?= h($memoryTextCol ?: '-') ?>,
        author=<?= h($memoryAuthorCol ?: '-') ?>,
        created=<?= h($memoryCreatedCol ?: '-') ?>,
        visible=<?= h($memoryVisibleCol ?: '-') ?>,
        photo=<?= h($memoryPhotoCol ?: '-') ?>.
      </p>
    </section>
  <?php endif; ?>
</main>
<?php app_page_end(); ?>
