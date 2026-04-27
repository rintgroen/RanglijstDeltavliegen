<?php
require_once __DIR__ . '/utils.php';
require_login();

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();

$year = isset($_REQUEST['year']) ? (int)$_REQUEST['year'] : (int)date('Y');
$notice = null;
$errors = [];

$pilots = [];
try {
    $stmt = $pdo->query('SELECT id, name FROM rankings_pilots WHERE active = 1 ORDER BY name');
    $pilots = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        $errors[] = 'Piloten laden mislukt: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $errors[] = 'Ongeldige CSRF-token.';
    } else {
        $year = isset($_POST['year']) ? (int)$_POST['year'] : $year;
        $k1 = isset($_POST['points_k1']) && is_array($_POST['points_k1']) ? $_POST['points_k1'] : [];
        $sport = isset($_POST['points_sport']) && is_array($_POST['points_sport']) ? $_POST['points_sport'] : [];

        $upsert = $pdo->prepare(
            'INSERT INTO rankings_world_points (pilot_id, year, class, points)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE points = VALUES(points)'
        );

        $changed = 0;
        foreach ($pilots as $pilot) {
            $pilotId = (int)$pilot['id'];
            foreach (['Klasse 1' => $k1[$pilotId] ?? null, 'Sportklasse' => $sport[$pilotId] ?? null] as $class => $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                if (!is_numeric($value)) {
                    $errors[] = 'Ongeldig getal voor ' . $pilot['name'] . ' (' . $class . ').';
                    continue;
                }
                try {
                    $upsert->execute([$pilotId, $year, $class, (float)$value]);
                    $changed++;
                } catch (Throwable $e) {
                    $errors[] = app_debug_enabled()
                        ? 'Opslaan mislukt voor ' . $pilot['name'] . ': ' . $e->getMessage()
                        : 'Opslaan mislukt voor ' . $pilot['name'] . '.';
                }
            }
        }

        if (empty($errors)) {
            $notice = $changed > 0 ? 'Wijzigingen opgeslagen voor jaar ' . $year . '.' : 'Geen wijzigingen gevonden.';
        }
    }
}

$points = [];
try {
    $stmt = $pdo->prepare('SELECT pilot_id, class, points FROM rankings_world_points WHERE year = ?');
    $stmt->execute([$year]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $points[(int)$row['pilot_id']][$row['class'] ?: 'Klasse 1'] = $row['points'];
    }
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        $errors[] = 'WPRS-punten laden mislukt: ' . $e->getMessage();
    }
}

app_page_start('WPRS-punten - Admin', [
    'active_admin' => 'world_points',
    'description' => 'WPRS-punten beheren voor Ranglijst Deltavliegen.',
]);
?>
<main class="card">
  <h1>WPRS-punten</h1>

  <form method="get" class="toolbar">
    <label>Jaar
      <input type="number" name="year" min="1980" max="2100" value="<?= (int)$year ?>">
    </label>
    <button type="submit">Laden</button>
  </form>

  <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="year" value="<?= (int)$year ?>">
    <div class="table-responsive">
      <table class="striped">
        <thead>
          <tr>
            <th>Piloot</th>
            <th>Klasse 1 punten</th>
            <th>Sportklasse punten</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pilots as $pilot): ?>
            <?php
              $pilotId = (int)$pilot['id'];
              $k1Value = isset($points[$pilotId]['Klasse 1']) ? app_format_compact_number($points[$pilotId]['Klasse 1']) : '';
              $sportValue = isset($points[$pilotId]['Sportklasse']) ? app_format_compact_number($points[$pilotId]['Sportklasse']) : '';
            ?>
            <tr>
              <td><?= h($pilot['name']) ?></td>
              <td><input type="number" step="0.001" name="points_k1[<?= $pilotId ?>]" value="<?= h($k1Value) ?>" placeholder="0.000"></td>
              <td><input type="number" step="0.001" name="points_sport[<?= $pilotId ?>]" value="<?= h($sportValue) ?>" placeholder="0.000"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p><button type="submit">Opslaan</button></p>
  </form>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
