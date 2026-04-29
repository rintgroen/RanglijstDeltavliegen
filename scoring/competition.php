<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$scorer = scoring_require_scorer($pdo);
$csrf = app_csrf_token();
$competitionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$competition = $competitionId > 0 ? scoring_load_competition($pdo, $competitionId) : null;
if (!$competition || !scoring_can_edit_competition($pdo, $competitionId, (int)$scorer['id'])) {
    http_response_code(404);
    app_page_start('Competitie niet gevonden - Scoring', [
        'active_scoring' => 'dashboard',
        'scoring_user' => $scorer['name'] ?: $scorer['email'],
    ]);
    echo '<main class="card"><h1>Competitie niet gevonden</h1><p class="muted">Deze competitie kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}
$isOwner = (int)$competition['scorer_id'] === (int)$scorer['id'];
$requestedTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
if ($requestedTab === 'tasks') {
    $requestedTab = 'new_task';
}
$activeTab = in_array($requestedTab, ['settings', 'new_task'], true) ? $requestedTab : 'settings';
$competitionTabByAction = [
    'update_competition' => 'settings',
    'add_buddy_scorer' => 'settings',
    'remove_buddy_scorer' => 'settings',
    'toggle_task_active' => 'settings',
    'delete_task' => 'settings',
    'create_task' => 'new_task',
    'add_waypoint' => 'settings',
    'reimport_waypoints' => 'settings',
];

$notice = null;
$error = null;
try {
    scoring_ensure_task_active_column($pdo);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? 'Taakbeheer initialiseren mislukt: ' . $e->getMessage() : 'Taakbeheer initialiseren mislukt.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = (string)($_POST['action'] ?? '');
    if (isset($competitionTabByAction[$postedAction])) {
        $activeTab = $competitionTabByAction[$postedAction];
    }
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } else {
        try {
            $action = $postedAction;
            if ($action === 'update_competition') {
                $name = trim((string)($_POST['name'] ?? ''));
                $class = (string)($_POST['class'] ?? 'Klasse 1');
                $scope = (string)($_POST['scope'] ?? 'open');
                $location = trim((string)($_POST['location'] ?? ''));
                $status = (string)($_POST['status'] ?? 'draft');
                $isPublic = isset($_POST['is_public']) ? 1 : 0;
                if ($name === '') {
                    throw new RuntimeException('Vul een competitienaam in.');
                }
                if (!in_array($status, ['draft', 'open', 'closed'], true)) {
                    throw new RuntimeException('Ongeldige status.');
                }
                $stmt = $pdo->prepare('UPDATE rankings_scoring_competitions SET name = ?, class = ?, scope = ?, location = ?, status = ?, is_public = ? WHERE id = ?');
                $stmt->execute([$name, $class, $scope, $location !== '' ? $location : null, $status, $isPublic, $competitionId]);
                $notice = 'Competitie bijgewerkt.';
                $competition = scoring_load_competition($pdo, $competitionId);
            } elseif ($action === 'add_waypoint') {
                $name = trim((string)($_POST['wp_name'] ?? ''));
                $code = trim((string)($_POST['wp_code'] ?? ''));
                $lat = scoring_decimal_or_null($_POST['wp_latitude'] ?? '');
                $lon = scoring_decimal_or_null($_POST['wp_longitude'] ?? '');
                $elev = scoring_decimal_or_null($_POST['wp_elevation_m'] ?? '');
                if ($name === '' || $lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
                    throw new RuntimeException('Vul een geldige waypointnaam, latitude en longitude in.');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO rankings_scoring_waypoints
                     (competition_id, name, code, latitude, longitude, elevation_m, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$competitionId, $name, $code !== '' ? $code : null, $lat, $lon, $elev, 'manual']);
                $notice = 'Waypoint toegevoegd.';
            } elseif ($action === 'reimport_waypoints') {
                if (empty($competition['waypoints_path'])) {
                    throw new RuntimeException('Er is geen oorspronkelijk waypointsbestand opgeslagen bij deze competitie.');
                }
                $path = scoring_public_upload_path((string)$competition['waypoints_path']);
                if (!is_file($path)) {
                    throw new RuntimeException('Het oorspronkelijke waypointsbestand is niet meer gevonden.');
                }
                $waypoints = scoring_parse_waypoints_file($path);
                $changed = scoring_upsert_competition_waypoints($pdo, $competitionId, $waypoints, 'file');
                $notice = $changed . ' waypoint(s) opnieuw ingelezen. Bestaande taakpunten blijven gekoppeld.';
            } elseif ($action === 'add_buddy_scorer') {
                $email = scoring_normalize_email((string)($_POST['buddy_email'] ?? ''));
                $name = trim((string)($_POST['buddy_name'] ?? ''));
                $buddy = scoring_add_competition_buddy($pdo, $competition, $scorer, $email, $name !== '' ? $name : null);
                $notice = 'Buddy scorer toegevoegd: ' . $buddy['scorer']['email'] . '.';
                if (!$buddy['email_sent']) {
                    $notice .= ' De uitnodigingsmail kon niet worden verstuurd.';
                }
            } elseif ($action === 'remove_buddy_scorer') {
                $buddyId = (int)($_POST['buddy_scorer_id'] ?? 0);
                if ($buddyId <= 0 || $buddyId === (int)$competition['scorer_id']) {
                    throw new RuntimeException('Deze scorer kan niet worden verwijderd.');
                }
                scoring_remove_competition_buddy($pdo, $competitionId, $buddyId);
                $notice = 'Buddy scorer verwijderd.';
            } elseif ($action === 'toggle_task_active') {
                $taskIdToUpdate = (int)($_POST['task_id'] ?? 0);
                $taskActive = isset($_POST['active']) && (string)$_POST['active'] === '1' ? 1 : 0;
                $stmt = $pdo->prepare('SELECT name FROM rankings_scoring_tasks WHERE id = ? AND competition_id = ? LIMIT 1');
                $stmt->execute([$taskIdToUpdate, $competitionId]);
                $taskName = $stmt->fetchColumn();
                if ($taskName === false) {
                    throw new RuntimeException('Taak niet gevonden.');
                }
                $stmt = $pdo->prepare('UPDATE rankings_scoring_tasks SET active = ? WHERE id = ? AND competition_id = ?');
                $stmt->execute([$taskActive, $taskIdToUpdate, $competitionId]);
                $notice = 'Taak "' . (string)$taskName . '" is ' . ($taskActive === 1 ? 'actief.' : 'inactief.');
            } elseif ($action === 'delete_task') {
                $taskIdToDelete = (int)($_POST['task_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT name FROM rankings_scoring_tasks WHERE id = ? AND competition_id = ? LIMIT 1');
                $stmt->execute([$taskIdToDelete, $competitionId]);
                $taskName = $stmt->fetchColumn();
                if ($taskName === false) {
                    throw new RuntimeException('Taak niet gevonden.');
                }
                scoring_delete_task($pdo, $taskIdToDelete, $competitionId);
                $notice = 'Taak "' . (string)$taskName . '" verwijderd.';
            } elseif ($action === 'create_task') {
                $taskName = trim((string)($_POST['task_name'] ?? ''));
                $taskDate = trim((string)($_POST['task_date'] ?? ''));
                $windowOpen = trim((string)($_POST['window_open'] ?? ''));
                $windowClose = trim((string)($_POST['window_close'] ?? ''));
                $taskType = (string)($_POST['task_type'] ?? 'race');
                if ($taskName === '' || $taskDate === '' || $windowOpen === '' || $windowClose === '') {
                    throw new RuntimeException('Vul naam, datum en taakvenster in.');
                }
                if (!in_array($taskType, ['race', 'time_trial'], true)) {
                    throw new RuntimeException('Ongeldig taaktype.');
                }
                $minDistance = scoring_decimal_or_null($_POST['minimum_distance_km'] ?? '') ?? 5.0;
                $nomDistance = scoring_decimal_or_null($_POST['nominal_distance_km'] ?? '') ?? 50.0;
                $nomTime = max(1, (int)($_POST['nominal_time_minutes'] ?? 90));
                $stmt = $pdo->prepare(
                    'INSERT INTO rankings_scoring_tasks
                     (competition_id, name, task_date, window_open_at, window_close_at, task_type,
                      minimum_distance_km, nominal_distance_km, nominal_time_minutes,
                      use_distance_points, use_time_points, use_departure_points, use_leading_points,
                      use_arrival_position_points, use_arrival_time_points)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $competitionId,
                    $taskName,
                    $taskDate,
                    scoring_local_input_to_utc_sql($windowOpen),
                    scoring_local_input_to_utc_sql($windowClose),
                    $taskType,
                    $minDistance,
                    $nomDistance,
                    $nomTime,
                    isset($_POST['use_distance_points']) ? 1 : 0,
                    isset($_POST['use_time_points']) ? 1 : 0,
                    isset($_POST['use_departure_points']) ? 1 : 0,
                    isset($_POST['use_leading_points']) ? 1 : 0,
                    isset($_POST['use_arrival_position_points']) ? 1 : 0,
                    isset($_POST['use_arrival_time_points']) ? 1 : 0,
                ]);
                header('Location: task.php?id=' . (int)$pdo->lastInsertId());
                exit;
            }
        } catch (Throwable $e) {
            $error = app_debug_enabled() ? $e->getMessage() : 'Opslaan mislukt.';
        }
    }
}

$waypoints = [];
$tasks = [];
$editors = [];
try {
    $competition = scoring_load_competition($pdo, $competitionId);
    $isOwner = (int)$competition['scorer_id'] === (int)$scorer['id'];
    $editors = scoring_load_competition_editors($pdo, $competition);

    $stmt = $pdo->prepare('SELECT * FROM rankings_scoring_waypoints WHERE competition_id = ? ORDER BY name ASC');
    $stmt->execute([$competitionId]);
    $waypoints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM rankings_scoring_task_turnpoints tt WHERE tt.task_id = t.id) AS turnpoint_count,
                (SELECT COUNT(*) FROM rankings_scoring_task_flights f WHERE f.task_id = t.id) AS flight_count
         FROM rankings_scoring_tasks t
         WHERE t.competition_id = ?
         ORDER BY t.task_date ASC, t.id ASC'
    );
    $stmt->execute([$competitionId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? 'Laden mislukt: ' . $e->getMessage() : 'Laden mislukt.';
}

$activeTasks = [];
foreach ($tasks as $taskRow) {
    if ((int)($taskRow['active'] ?? 1) === 1) {
        $activeTasks[] = $taskRow;
    }
}

$defaultDate = date('Y-m-d');
$defaultOpen = date('Y-m-d\T09:00');
$defaultClose = date('Y-m-d\T19:00');

app_page_start($competition['name'] . ' - Scoring', [
    'active_scoring' => 'dashboard',
    'scoring_user' => $scorer['name'] ?: $scorer['email'],
    'scoring_breadcrumbs' => [
        ['label' => 'Competities', 'href' => 'index.php'],
        ['label' => $competition['name'], 'href' => 'competition.php?id=' . (int)$competitionId],
        ['label' => $activeTab === 'new_task' ? 'Nieuwe taak' : 'Instellingen'],
    ],
    'description' => 'Competitie beheren.',
]);
?>
<main>
  <section class="competition-tabs">
    <nav class="site-nav public-nav competition-section-nav" aria-label="Competitie onderdelen">
      <span class="competition-nav-title"><?= h($competition['name']) ?></span>
      <div class="nav-cluster nav-cluster-single<?= $activeTab === 'settings' ? ' has-active' : '' ?>">
        <?php app_nav_link('competition.php?id=' . (int)$competitionId . '&tab=settings', 'Instellingen', $activeTab === 'settings'); ?>
      </div>
      <div class="nav-cluster<?= $activeTab === 'new_task' ? ' has-active' : '' ?>" role="group" aria-labelledby="competition-nav-tasks">
        <span class="nav-group-label" id="competition-nav-tasks">Taken</span>
        <?php foreach ($activeTasks as $taskNav): ?>
          <?php app_nav_link('task.php?id=' . (int)$taskNav['id'], (string)$taskNav['name'], false); ?>
        <?php endforeach; ?>
        <?php app_nav_link('competition.php?id=' . (int)$competitionId . '&tab=new_task', 'Nieuwe taak', $activeTab === 'new_task'); ?>
      </div>
    </nav>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($activeTab === 'settings'): ?>
      <section class="card">
        <h2>Instellingen</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="update_competition">
          <div class="grid">
            <label>Naam
              <input type="text" name="name" value="<?= h($competition['name']) ?>" required maxlength="190">
            </label>
            <label>Klasse
              <select name="class">
                <?php foreach (['Klasse 1', 'Sportklasse', 'Open'] as $classOption): ?>
                  <option value="<?= h($classOption) ?>" <?= $competition['class'] === $classOption ? 'selected' : '' ?>><?= h($classOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Type
              <select name="scope">
                <option value="open" <?= $competition['scope'] === 'open' ? 'selected' : '' ?>>Open competitie</option>
                <option value="club" <?= $competition['scope'] === 'club' ? 'selected' : '' ?>>Clubcompetitie</option>
                <option value="dutch_national_candidate" <?= $competition['scope'] === 'dutch_national_candidate' ? 'selected' : '' ?>>NK kandidaat</option>
              </select>
            </label>
            <label>Status
              <select name="status">
                <option value="draft" <?= $competition['status'] === 'draft' ? 'selected' : '' ?>>Concept</option>
                <option value="open" <?= $competition['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="closed" <?= $competition['status'] === 'closed' ? 'selected' : '' ?>>Gesloten</option>
              </select>
            </label>
            <label>Locatie
              <input type="text" name="location" value="<?= h($competition['location'] ?? '') ?>" maxlength="190">
            </label>
            <label class="check-row">
              <input type="checkbox" name="is_public" <?= (int)$competition['is_public'] === 1 ? 'checked' : '' ?>> Publieke resultaten tonen
            </label>
          </div>
          <p><button type="submit">Opslaan</button></p>
        </form>
      </section>

      <section class="card">
        <h2>Scorers</h2>
        <form method="post" class="panel">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="add_buddy_scorer">
          <div class="grid">
            <label>Naam (optioneel)
              <input type="text" name="buddy_name" maxlength="160">
            </label>
            <label>E-mail
              <input type="email" name="buddy_email" required maxlength="190">
            </label>
          </div>
          <p><button type="submit">Buddy scorer toevoegen</button></p>
        </form>

        <?php if (empty($editors)): ?>
          <p class="muted">Nog geen scorers gekoppeld.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="striped compact-table">
              <thead>
                <tr>
                  <th>Naam</th>
                  <th>E-mail</th>
                  <th>Rol</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($editors as $editor): ?>
                  <tr>
                    <td><?= h($editor['name'] ?: '-') ?></td>
                    <td><?= h($editor['email']) ?></td>
                    <td><?= ($editor['role'] ?? 'buddy') === 'owner' ? 'Eigenaar' : 'Buddy' ?></td>
                    <td><?= (int)$editor['active'] === 1 ? 'Actief' : 'Inactief' ?></td>
                    <td>
                      <?php if (($editor['role'] ?? 'buddy') !== 'owner'): ?>
                        <form method="post" class="inline">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="remove_buddy_scorer">
                          <input type="hidden" name="buddy_scorer_id" value="<?= (int)$editor['id'] ?>">
                          <button class="danger" type="submit">Verwijderen</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <p class="muted">Buddy scorers kunnen na login dezelfde competitie en taken bewerken.</p>
      </section>

      <section class="card">
        <h2>Waypoints</h2>
        <form method="post" class="panel">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="add_waypoint">
          <div class="grid">
            <label>Naam
              <input type="text" name="wp_name" required maxlength="120">
            </label>
            <label>Code
              <input type="text" name="wp_code" maxlength="40">
            </label>
            <label>Latitude
              <input type="number" name="wp_latitude" step="0.000001" required>
            </label>
            <label>Longitude
              <input type="number" name="wp_longitude" step="0.000001" required>
            </label>
            <label>Hoogte (m)
              <input type="number" name="wp_elevation_m" step="1">
            </label>
          </div>
          <p><button type="submit">Waypoint toevoegen</button></p>
        </form>

        <p class="muted"><?= (int)count($waypoints) ?> waypoints geladen<?= $competition['waypoints_original_name'] ? ' uit ' . h($competition['waypoints_original_name']) : '' ?>.</p>
        <?php if (!empty($competition['waypoints_path'])): ?>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="reimport_waypoints">
            <button class="secondary" type="submit">Waypoints opnieuw inlezen</button>
            <span class="muted">Werkt bestaande waypoint-coordinaten bij uit het oorspronkelijke bestand.</span>
          </form>
        <?php endif; ?>
        <?php if (!empty($waypoints)): ?>
          <div class="waypoint-list">
            <?php foreach ($waypoints as $wp): ?>
              <span><?= h($wp['name']) ?> <small><?= h(app_format_compact_number($wp['latitude'], 5)) ?>, <?= h(app_format_compact_number($wp['longitude'], 5)) ?></small></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="card">
        <h2>Taken beheren</h2>
        <?php if (empty($tasks)): ?>
          <p class="muted">Nog geen taken aangemaakt.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="striped compact-table task-management-table">
              <thead>
                <tr>
                  <th>Taak</th>
                  <th>Datum</th>
                  <th>Status</th>
                  <th>Data</th>
                  <th>Actief</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tasks as $taskRow): ?>
                  <?php $taskIsActive = (int)($taskRow['active'] ?? 1) === 1; ?>
                  <tr class="<?= $taskIsActive ? '' : 'is-inactive' ?>">
                    <td>
                      <a href="task.php?id=<?= (int)$taskRow['id'] ?>"><?= h($taskRow['name']) ?></a>
                      <?php if (!$taskIsActive): ?>
                        <div class="muted">Niet zichtbaar in het scorer menu.</div>
                      <?php endif; ?>
                    </td>
                    <td><?= h($taskRow['task_date']) ?></td>
                    <td><?= h($taskRow['status']) ?></td>
                    <td>
                      <?= (int)$taskRow['turnpoint_count'] ?> taakpunten<br>
                      <span class="muted"><?= (int)$taskRow['flight_count'] ?> track(s)</span>
                    </td>
                    <td>
                      <form method="post" class="inline task-active-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="toggle_task_active">
                        <input type="hidden" name="task_id" value="<?= (int)$taskRow['id'] ?>">
                        <input type="hidden" name="active" value="0">
                        <label class="check-row task-active-toggle">
                          <input type="checkbox" name="active" value="1" <?= $taskIsActive ? 'checked' : '' ?>> Actief
                        </label>
                        <button class="secondary" type="submit">Bijwerken</button>
                      </form>
                    </td>
                    <td>
                      <form method="post" class="inline" onsubmit="return confirm('Weet je zeker dat je deze taak wilt verwijderen? Alle taakdata wordt uit de database verwijderd. Dit kan niet ongedaan worden gemaakt.');">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete_task">
                        <input type="hidden" name="task_id" value="<?= (int)$taskRow['id'] ?>">
                        <button class="danger" type="submit">Verwijderen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="card">
        <h2>Nieuwe taak</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="create_task">
          <div class="grid">
            <label>Taaknaam
              <input type="text" name="task_name" required maxlength="190" placeholder="Taak 1">
            </label>
            <label>Taakdatum
              <input type="date" name="task_date" value="<?= h($defaultDate) ?>" required>
            </label>
            <label>Venster open
              <input type="datetime-local" name="window_open" value="<?= h($defaultOpen) ?>" required>
            </label>
            <label>Venster dicht
              <input type="datetime-local" name="window_close" value="<?= h($defaultClose) ?>" required>
            </label>
            <label>Type
              <select name="task_type">
                <option value="race">Race</option>
                <option value="time_trial">Time trial</option>
              </select>
            </label>
            <label>Minimum afstand (km)
              <input type="number" name="minimum_distance_km" step="0.1" value="5.0">
            </label>
            <label>Nominale afstand (km)
              <input type="number" name="nominal_distance_km" step="0.1" value="50.0">
            </label>
            <label>Nominale tijd (min)
              <input type="number" name="nominal_time_minutes" value="90" min="1">
            </label>
          </div>
          <div class="checkbox-grid">
            <label><input type="checkbox" name="use_distance_points" checked> Afstandspunten</label>
            <label><input type="checkbox" name="use_time_points" checked> Tijdspunten</label>
            <label><input type="checkbox" name="use_leading_points" checked> Leadingpunten</label>
            <label><input type="checkbox" name="use_departure_points"> Departurepunten</label>
            <label><input type="checkbox" name="use_arrival_position_points"> Arrival positiepunten</label>
            <label><input type="checkbox" name="use_arrival_time_points"> Arrival tijdpunten</label>
          </div>
          <p><button type="submit">Taak aanmaken</button></p>
        </form>
      </section>
    <?php endif; ?>
  </section>
</main>
<?php app_page_end('Scoring - ' . app_site_name()); ?>
