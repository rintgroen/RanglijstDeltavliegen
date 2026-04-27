<?php
require_once __DIR__ . '/utils.php';
require_login();

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();

$year = isset($_REQUEST['year']) ? (int)$_REQUEST['year'] : (int)date('Y');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } elseif (isset($_POST['save_meta'])) {
        $year = (int)$_POST['year'];
        $participants = max(1, (int)$_POST['participants']);
        $pdo->prepare('REPLACE INTO rankings_nationals_meta (year, participants) VALUES (?, ?)')->execute([$year, $participants]);
    } elseif (isset($_POST['save_results'])) {
        $year = (int)$_POST['year'];
        $pdo->beginTransaction();
        try {
            $meta = $pdo->prepare('SELECT participants FROM rankings_nationals_meta WHERE year = ?');
            $meta->execute([$year]);
            if (!$meta->fetch()) {
                throw new RuntimeException('Vul eerst het aantal deelnemers in.');
            }
            $pdo->prepare('DELETE FROM rankings_nationals_results WHERE year = ?')->execute([$year]);
            $positions = $_POST['position'] ?? [];
            $rank = 1;
            foreach ($positions as $pilotId) {
                $pilotId = (int)$pilotId;
                if ($pilotId > 0) {
                    $pdo->prepare('INSERT INTO rankings_nationals_results (year, pilot_id, position) VALUES (?, ?, ?)')->execute([$year, $pilotId, $rank]);
                    $rank++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$pilots = $pdo->query('SELECT id, name FROM rankings_pilots WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$metaStmt = $pdo->prepare('SELECT participants FROM rankings_nationals_meta WHERE year = ?');
$metaStmt->execute([$year]);
$participants = (int)($metaStmt->fetchColumn() ?: 0);

$resultsStmt = $pdo->prepare(
    'SELECT r.position, p.id, p.name
     FROM rankings_nationals_results r
     JOIN rankings_pilots p ON p.id = r.pilot_id
     WHERE r.year = ?
     ORDER BY r.position'
);
$resultsStmt->execute([$year]);
$existing = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);

app_page_start('NK resultaten - Admin', [
    'active_admin' => '',
    'description' => 'Legacy NK resultaten beheren.',
]);
?>
<main>
  <section class="card">
    <h1>NK resultaten</h1>
    <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
    <form method="get" class="toolbar">
      <label>Jaar
        <input type="number" name="year" value="<?= (int)$year ?>" min="1900" max="2100">
      </label>
      <button type="submit">Laden</button>
    </form>
  </section>

  <section class="card">
    <h2>Deelnemers</h2>
    <form method="post" class="grid">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="save_meta" value="1">
      <label>Jaar
        <input type="number" name="year" value="<?= (int)$year ?>">
      </label>
      <label>Aantal deelnemers
        <input type="number" name="participants" value="<?= (int)$participants ?>" min="1">
      </label>
      <p><button type="submit">Opslaan</button></p>
    </form>
  </section>

  <section class="card">
    <h2>Posities</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="save_results" value="1">
      <input type="hidden" name="year" value="<?= (int)$year ?>">
      <p class="muted">Voeg piloten toe in finishvolgorde. Laat lege posities onderaan staan.</p>

      <div class="grid cols-2">
        <?php for ($i = 1; $i <= max($participants, count($existing)); $i++): ?>
          <?php $selected = $existing[$i - 1]['id'] ?? 0; ?>
          <label>#<?= $i ?>
            <select name="position[]">
              <option value="">- geen -</option>
              <?php foreach ($pilots as $pilot): ?>
                <option value="<?= (int)$pilot['id'] ?>" <?= (int)$selected === (int)$pilot['id'] ? 'selected' : '' ?>><?= h($pilot['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endfor; ?>
      </div>
      <p><button type="submit">Resultaten opslaan</button></p>
    </form>
  </section>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
