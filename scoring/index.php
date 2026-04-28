<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$scorer = scoring_require_scorer($pdo);
$csrf = app_csrf_token();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } else {
        try {
            $name = trim((string)($_POST['name'] ?? ''));
            $class = (string)($_POST['class'] ?? 'Klasse 1');
            $scope = (string)($_POST['scope'] ?? 'open');
            $location = trim((string)($_POST['location'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Vul een competitienaam in.');
            }
            if (!in_array($class, ['Klasse 1', 'Sportklasse', 'Open'], true)) {
                throw new RuntimeException('Ongeldige klasse.');
            }
            if (!in_array($scope, ['open', 'club', 'dutch_national_candidate'], true)) {
                throw new RuntimeException('Ongeldig competitietype.');
            }
            if (!isset($_FILES['waypoints']) || $_FILES['waypoints']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Upload een waypointsbestand.');
            }
            $file = $_FILES['waypoints'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Uploadfout: code ' . $file['error']);
            }
            $maxMb = defined('SCORING_UPLOAD_MAX_MB') ? (int)SCORING_UPLOAD_MAX_MB : 12;
            if ($file['size'] > $maxMb * 1024 * 1024) {
                throw new RuntimeException('Bestand is te groot (max ' . $maxMb . ' MB).');
            }
            $waypoints = scoring_parse_waypoints_file($file['tmp_name']);
            [$dir, $urlDir] = scoring_ensure_upload_dir('waypoints');
            $filename = scoring_safe_filename($file['name'], 'wpt');
            $relativePath = $urlDir . '/' . $filename;
            if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
                throw new RuntimeException('Waypointsbestand opslaan mislukt.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO rankings_scoring_competitions
                 (scorer_id, name, class, scope, location, waypoints_original_name, waypoints_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int)$scorer['id'],
                $name,
                $class,
                $scope,
                $location !== '' ? $location : null,
                $file['name'],
                $relativePath,
            ]);
            $competitionId = (int)$pdo->lastInsertId();
            $insertWp = $pdo->prepare(
                'INSERT INTO rankings_scoring_waypoints
                 (competition_id, name, code, latitude, longitude, elevation_m, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($waypoints as $wp) {
                $insertWp->execute([
                    $competitionId,
                    $wp['name'],
                    $wp['code'],
                    $wp['latitude'],
                    $wp['longitude'],
                    $wp['elevation_m'],
                    'file',
                ]);
            }
            $pdo->commit();
            header('Location: competition.php?id=' . $competitionId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = ($e instanceof RuntimeException || app_debug_enabled()) ? $e->getMessage() : 'Competitie aanmaken mislukt.';
        }
    }
}

$competitions = [];
try {
    $competitions = scoring_load_editable_competitions($pdo, (int)$scorer['id']);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? 'Competities laden mislukt: ' . $e->getMessage() : 'Competities laden mislukt.';
}

app_page_start('Scoring dashboard - ' . app_site_name(), [
    'active_scoring' => 'dashboard',
    'scoring_user' => $scorer['name'] ?: $scorer['email'],
    'scoring_breadcrumbs' => [
        ['label' => 'Competities'],
    ],
    'description' => 'Scoring dashboard voor scorers.',
]);
?>
<main>
  <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

  <section class="card">
    <h1>Mijn competities</h1>
    <?php if (empty($competitions)): ?>
      <p class="muted">Nog geen competities aangemaakt.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>Naam</th>
              <th>Klasse</th>
              <th>Type</th>
              <th>Rol</th>
              <th>Taken</th>
              <th>Status</th>
              <th>Aangemaakt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($competitions as $competition): ?>
              <tr>
                <td><a href="competition.php?id=<?= (int)$competition['id'] ?>"><?= h($competition['name']) ?></a></td>
                <td><?= h($competition['class']) ?></td>
                <td><?= h($competition['scope']) ?></td>
                <td><?= ($competition['editor_role'] ?? 'owner') === 'owner' ? 'Eigenaar' : 'Buddy' ?></td>
                <td><?= (int)$competition['task_count'] ?></td>
                <td><?= h($competition['status']) ?><?= (int)$competition['is_public'] === 1 ? ' / publiek' : '' ?></td>
                <td><?= h(scoring_utc_sql_to_display($competition['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Nieuwe competitie</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div class="grid">
        <label>Naam
          <input type="text" name="name" required maxlength="190" placeholder="bv. Voorjaarsclubwedstrijd">
        </label>
        <label>Klasse
          <select name="class">
            <option value="Klasse 1">Klasse 1</option>
            <option value="Sportklasse">Sportklasse</option>
            <option value="Open">Open</option>
          </select>
        </label>
        <label>Type
          <select name="scope">
            <option value="open">Open competitie</option>
            <option value="club">Clubcompetitie</option>
            <option value="dutch_national_candidate">NK kandidaat</option>
          </select>
        </label>
        <label>Locatie (optioneel)
          <input type="text" name="location" maxlength="190">
        </label>
      </div>
      <label>Waypointsbestand
        <input type="file" name="waypoints" accept=".wpt,.gpx,.cup,.csv,.txt,text/plain,text/csv,application/gpx+xml,application/xml" required>
      </label>
      <p class="muted">
        Ondersteund: GPX, SeeYou CUP/CSV met kolommen voor naam, lat en lon, OziExplorer WPT en CompeGPS/FS WPT met WGS84 lat/lon of UTM waypoints.
      </p>
      <p><button type="submit">Competitie aanmaken</button></p>
    </form>
  </section>
</main>
<?php app_page_end('Scoring - ' . app_site_name()); ?>
