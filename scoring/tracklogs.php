<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$scorer = scoring_require_scorer($pdo);
scoring_ensure_track_collection_tables($pdo);
$csrf = app_csrf_token();

$q = substr(trim((string)($_GET['q'] ?? '')), 0, 190);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = null;
$tracklogs = [];
$totalRows = 0;
$selectedTracklog = null;
$selectedPreview = null;
$selectedPreviewJson = '';
$totalPages = 1;
$notice = null;

$downloadTracklog = function (int $tracklogId) use ($pdo): void {
    if ($tracklogId <= 0) {
        http_response_code(404);
        echo 'Tracklog niet gevonden.';
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT original_filename, storage_path
         FROM rankings_scoring_tracklogs
         WHERE id = ? AND storage_path <> ?
         LIMIT 1'
    );
    $stmt->execute([$tracklogId, '']);
    $tracklog = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tracklog) {
        http_response_code(404);
        echo 'Tracklog niet gevonden.';
        exit;
    }

    $path = scoring_public_upload_path((string)$tracklog['storage_path']);
    $realPath = realpath($path);
    $uploadRoot = realpath(scoring_upload_root());
    if (!$realPath || !$uploadRoot || strpos($realPath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
        http_response_code(404);
        echo 'Tracklogbestand niet gevonden.';
        exit;
    }

    $filename = preg_replace('/[\r\n\x00-\x1F\x7F]+/', '_', basename((string)$tracklog['original_filename']));
    if (!is_string($filename) || $filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'tracklog.igc';
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($realPath));
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    readfile($realPath);
    exit;
};

if (isset($_GET['download'])) {
    $downloadTracklog($selectedId);
}

$pageUrl = function (array $params = []) use ($q, &$page): string {
    $query = array_merge([
        'q' => $q,
        'page' => $page,
    ], $params);
    $query = array_filter($query, function ($value): bool {
        return $value !== null && $value !== '';
    });
    return 'tracklogs.php' . (!empty($query) ? '?' . http_build_query($query) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!app_check_csrf()) {
            throw new RuntimeException('Ongeldige inzending. Probeer het opnieuw.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'validate_tracklog') {
            throw new RuntimeException('Onbekende actie.');
        }
        $selectedId = (int)($_POST['tracklog_id'] ?? 0);
        $validation = scoring_validate_tracklog_by_id($pdo, $selectedId, true);
        $notice = 'FAI-validatie: ' . scoring_tracklog_validation_status_label($validation['validation_status'] ?? 'error') . '.';
    } catch (Throwable $e) {
        $error = app_debug_enabled() ? $e->getMessage() : 'Validatie mislukt.';
    }
}

try {
    $where = ['storage_path <> ?'];
    $params = [''];
    if ($q !== '') {
        $where[] = '(pilot_name LIKE ? OR pilot_email LIKE ? OR original_filename LIKE ?)';
        $needle = '%' . $q . '%';
        array_push($params, $needle, $needle, $needle);
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare('SELECT COUNT(*) FROM rankings_scoring_tracklogs WHERE ' . $whereSql);
    $count->execute($params);
    $totalRows = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = 'SELECT id, pilot_name, pilot_email, original_filename, storage_path, source, validation_status, first_fix_at, last_fix_at, fix_count, uploaded_at
            FROM rankings_scoring_tracklogs
            WHERE ' . $whereSql . '
            ORDER BY uploaded_at DESC, id DESC
            LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $list = $pdo->prepare($sql);
    $list->execute($params);
    $tracklogs = $list->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedId <= 0 && !empty($tracklogs)) {
        $selectedId = (int)$tracklogs[0]['id'];
    }

    if ($selectedId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, pilot_name, pilot_email, original_filename, storage_path, source, validation_status, validation_checked_at, validation_service, validation_response, first_fix_at, last_fix_at, fix_count, uploaded_at
             FROM rankings_scoring_tracklogs
             WHERE id = ? AND storage_path <> ?
             LIMIT 1'
        );
        $stmt->execute([$selectedId, '']);
        $selectedTracklog = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selectedTracklog) {
            $selectedPreview = scoring_tracklog_map_preview($pdo, (int)$selectedTracklog['id'], 1000);
            if ($selectedPreview) {
                $selectedPreviewJson = json_encode(
                    $selectedPreview,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                );
                if ($selectedPreviewJson === false) {
                    $selectedPreview = null;
                    $selectedPreviewJson = '';
                }
            }
        }
    }
} catch (Throwable $e) {
    $totalPages = 1;
    $error = app_debug_enabled() ? 'Tracklogs laden mislukt: ' . $e->getMessage() : 'Tracklogs laden mislukt.';
}

$leafletAssets = $selectedPreview
    ? '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">' . "\n"
        . '  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>'
    : '';

app_page_start('Tracklogs - Scoring', [
    'active_scoring' => 'tracklogs',
    'scoring_user' => $scorer['name'] ?: $scorer['email'],
    'scoring_breadcrumbs' => [
        ['label' => 'Tracklogs'],
    ],
    'description' => 'Tracklogs zoeken, bekijken en downloaden.',
    'extra_head' => $leafletAssets,
]);
?>
<main>
  <section class="card">
    <div class="section-header">
      <div>
        <h1>Tracklogs</h1>
        <p class="muted">Zoek op piloot, e-mail of bestandsnaam. Open een tracklog om hem op de kaart te bekijken.</p>
      </div>
      <form class="toolbar tracklog-search" method="get">
        <label>Zoeken
          <input type="search" name="q" value="<?= h($q) ?>" maxlength="190" placeholder="naam, e-mail of bestand">
        </label>
        <button type="submit">Zoeken</button>
      </form>
    </div>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  </section>

  <section class="card">
    <div class="tracklog-browser">
      <div class="tracklog-list">
        <div class="section-header">
          <h2>Geüpload</h2>
          <span class="muted"><?= (int)$totalRows ?> tracklog(s)</span>
        </div>
        <?php if (empty($tracklogs)): ?>
          <p class="muted">Geen tracklogs gevonden.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="striped compact-table">
              <thead>
                <tr>
                  <th>Piloot</th>
                  <th>Bestand</th>
                  <th>Upload</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tracklogs as $tracklog): ?>
                  <?php $isSelected = (int)$tracklog['id'] === $selectedId; ?>
                  <?php $tracklogHref = $pageUrl(['id' => (int)$tracklog['id']]); ?>
                  <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
                    <td>
                      <a href="<?= h($tracklogHref) ?>"><?= h($tracklog['pilot_name']) ?></a>
                      <br><span class="muted"><?= h(scoring_display_pilot_email($tracklog['pilot_email'])) ?></span>
                    </td>
                    <td>
                      <a href="<?= h($tracklogHref) ?>"><?= h($tracklog['original_filename']) ?></a>
                      <?php $evidenceCode = scoring_tracklog_evidence_code($tracklog); ?>
                      <br><span class="muted"><?= (int)$tracklog['fix_count'] ?> fixes</span>
                      <br><span class="evidence-badge evidence-<?= h(strtolower($evidenceCode)) ?>" title="<?= h(scoring_evidence_label($evidenceCode)) ?>"><?= h($evidenceCode) ?></span>
                      <span class="muted"><?= h(scoring_tracklog_validation_status_label($tracklog['validation_status'] ?? null)) ?></span>
                    </td>
                    <td><?= h(scoring_utc_sql_to_display($tracklog['uploaded_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Tracklog pagina's">
              <?php if ($page > 1): ?><a class="btn secondary" href="<?= h($pageUrl(['page' => $page - 1, 'id' => null])) ?>">Vorige</a><?php endif; ?>
              <span class="muted">Pagina <?= (int)$page ?> van <?= (int)$totalPages ?></span>
              <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= h($pageUrl(['page' => $page + 1, 'id' => null])) ?>">Volgende</a><?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="tracklog-detail">
        <?php if (!$selectedTracklog): ?>
          <h2>Kaart</h2>
          <p class="muted">Selecteer een tracklog om de kaart te tonen.</p>
        <?php else: ?>
          <div class="section-header">
            <div>
              <h2><?= h($selectedTracklog['pilot_name']) ?></h2>
              <p class="muted"><?= h($selectedTracklog['original_filename']) ?></p>
            </div>
            <?php $selectedSource = strtolower(trim((string)($selectedTracklog['source'] ?? 'manual_upload'))); ?>
            <div class="inline">
              <?php if (!in_array($selectedSource, ['flymaster_replay', 'livetrack24'], true)): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="validate_tracklog">
                  <input type="hidden" name="tracklog_id" value="<?= (int)$selectedTracklog['id'] ?>">
                  <button type="submit" class="secondary">FAI valideren</button>
                </form>
              <?php endif; ?>
              <a class="btn secondary" href="<?= h($pageUrl(['id' => (int)$selectedTracklog['id'], 'download' => 1])) ?>">Download IGC</a>
            </div>
          </div>
          <dl class="tracklog-facts">
            <div>
              <dt>Vlucht</dt>
              <dd><?= h(scoring_utc_sql_to_display($selectedTracklog['first_fix_at'])) ?> - <?= h(scoring_utc_sql_to_display($selectedTracklog['last_fix_at'])) ?></dd>
            </div>
            <div>
              <dt>Fixes</dt>
              <dd><?= (int)$selectedTracklog['fix_count'] ?></dd>
            </div>
            <div>
              <dt>E-mail</dt>
              <dd><?= h(scoring_display_pilot_email($selectedTracklog['pilot_email'])) ?></dd>
            </div>
            <?php $selectedEvidenceCode = scoring_tracklog_evidence_code($selectedTracklog); ?>
            <div>
              <dt>Bewijs</dt>
              <dd><span class="evidence-badge evidence-<?= h(strtolower($selectedEvidenceCode)) ?>" title="<?= h(scoring_evidence_label($selectedEvidenceCode)) ?>"><?= h($selectedEvidenceCode) ?></span> <?= h(scoring_evidence_label($selectedEvidenceCode)) ?></dd>
            </div>
            <div>
              <dt>FAI-validatie</dt>
              <dd><?= h(scoring_tracklog_validation_status_label($selectedTracklog['validation_status'] ?? null)) ?></dd>
            </div>
            <div>
              <dt>Gecontroleerd</dt>
              <dd><?= h(scoring_utc_sql_to_display($selectedTracklog['validation_checked_at'] ?? null)) ?></dd>
            </div>
            <?php $validationMessage = scoring_tracklog_validation_message($selectedTracklog); ?>
            <?php if ($validationMessage !== ''): ?>
              <div>
                <dt>Bericht</dt>
                <dd><?= h($validationMessage) ?></dd>
              </div>
            <?php endif; ?>
          </dl>
          <?php if ($selectedPreview): ?>
            <div class="track-preview track-preview-large">
              <div class="track-preview-map" aria-label="Kaartvoorbeeld van <?= h($selectedTracklog['pilot_name']) ?>">
                <span class="track-preview-loading">Kaart laden...</span>
              </div>
              <script type="application/json" class="track-preview-data"><?= $selectedPreviewJson ?></script>
            </div>
          <?php else: ?>
            <div class="alert error">Kaartvoorbeeld kon niet worden opgebouwd voor deze tracklog.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php app_page_end(); ?>
