<?php
require_once __DIR__ . '/utils.php';
require_login();

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();

$notice = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mem_admin_action'] ?? '') === 'moderate') {
    $memoryId = isset($_POST['memory_id']) ? (int)$_POST['memory_id'] : 0;
    $target = (string)($_POST['target'] ?? '');
    if (!app_check_csrf() || $memoryId <= 0 || !in_array($target, ['show', 'hide'], true)) {
        $error = 'Ongeldige moderatie-aanvraag.';
    } else {
        try {
            $value = $target === 'show' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE rankings_competition_memories SET is_visible = ? WHERE id = ?');
            $stmt->execute([$value, $memoryId]);
            $notice = $target === 'show' ? 'Inzending is zichtbaar gemaakt.' : 'Inzending is verborgen.';
        } catch (Throwable $e) {
            $error = app_debug_enabled() ? 'Moderatie mislukt: ' . $e->getMessage() : 'Moderatie mislukt.';
        }
    }
}

$latestMemory = null;
try {
    $sql = "SELECT m.id, m.competition_id, m.author_name, m.title, m.body, m.photo_path, m.created_at, m.is_visible,
                   c.title AS comp_title, c.year AS comp_year, c.class AS comp_class
            FROM rankings_competition_memories m
            JOIN rankings_competitions c ON c.id = m.competition_id
            ORDER BY m.created_at DESC
            LIMIT 1";
    $stmt = $pdo->query($sql);
    $latestMemory = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Load memory failed: ' . h($e->getMessage()) . '</pre>';
    }
}

$currentYear = (int)date('Y');
$stats = [
    'pilots_total' => 0,
    'pilots_active' => 0,
    'competitions_total' => 0,
    'competitions_k1' => 0,
    'competitions_sc' => 0,
    'memories_total' => 0,
    'memories_30d' => 0,
    'wprs_k1_year' => 0,
    'wprs_sc_year' => 0,
];

try {
    $stats['pilots_total'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_pilots")->fetchColumn();
    $stats['pilots_active'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_pilots WHERE active = 1")->fetchColumn();
    $stats['competitions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions")->fetchColumn();
    $stats['competitions_k1'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions WHERE class = 'Klasse 1'")->fetchColumn();
    $stats['competitions_sc'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competitions WHERE class = 'Sportklasse'")->fetchColumn();
    $stats['memories_total'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competition_memories")->fetchColumn();
    $stats['memories_30d'] = (int)$pdo->query("SELECT COUNT(*) FROM rankings_competition_memories WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rankings_world_points WHERE year = ? AND class = 'Klasse 1'");
    $stmt->execute([$currentYear]);
    $stats['wprs_k1_year'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rankings_world_points WHERE year = ? AND class = 'Sportklasse'");
    $stmt->execute([$currentYear]);
    $stats['wprs_sc_year'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        echo '<pre>Stats failed: ' . h($e->getMessage()) . '</pre>';
    }
}

$latestCompetition = null;
try {
    $stmt = $pdo->query("SELECT id, title, year, class, created_at FROM rankings_competitions ORDER BY created_at DESC LIMIT 1");
    $latestCompetition = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
} catch (Throwable $e) {
}

app_page_start('Admin - ' . app_site_name(), [
    'active_admin' => 'dashboard',
    'active_public' => '',
    'description' => 'Admin dashboard voor Ranglijst Deltavliegen.',
]);
?>
<main class="card">
  <h1>Dashboard</h1>
  <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

  <section>
    <h2>Laatste herinnering</h2>
    <?php if (!$latestMemory): ?>
      <p class="muted">Nog geen herinneringen gevonden.</p>
    <?php else: ?>
      <?php
        $memoryLink = '../public/competition.php?id=' . (int)$latestMemory['competition_id'] . '#memories';
        $competitionLabel = ($latestMemory['comp_title'] ?: 'Wedstrijd') . ' - ' . (int)$latestMemory['comp_year'] . ' (' . ($latestMemory['comp_class'] ?: '') . ')';
        $snippet = app_truncate((string)($latestMemory['body'] ?: ''), 350);
        $isVisible = isset($latestMemory['is_visible']) ? (int)$latestMemory['is_visible'] : 1;
      ?>
      <article class="panel memory-card">
        <header>
          <strong><?= h($latestMemory['title'] ?: 'Herinnering') ?></strong><br>
          <span class="muted">
            Door <?= h($latestMemory['author_name']) ?> - <?= h(date('Y-m-d', strtotime($latestMemory['created_at']))) ?>
            - <a href="<?= h($memoryLink) ?>"><?= h($competitionLabel) ?></a>
            - Status: <?= $isVisible ? 'Zichtbaar' : 'Verborgen' ?>
          </span>
        </header>
        <?php if (!empty($latestMemory['photo_path'])): ?>
          <p><a href="<?= h($memoryLink) ?>"><img src="<?= h('../public/' . $latestMemory['photo_path']) ?>" alt="Foto herinnering"></a></p>
        <?php endif; ?>
        <p><?= nl2br(h($snippet)) ?></p>
        <form method="post" class="inline">
          <input type="hidden" name="mem_admin_action" value="moderate">
          <input type="hidden" name="memory_id" value="<?= (int)$latestMemory['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <?php if ($isVisible): ?>
            <button name="target" value="hide" type="submit">Verbergen</button>
          <?php else: ?>
            <button name="target" value="show" type="submit">Zichtbaar maken</button>
          <?php endif; ?>
          <a class="btn secondary" href="memories.php">Open beheer</a>
        </form>
      </article>
    <?php endif; ?>
  </section>

  <section>
    <h2>Statistieken</h2>
    <div class="stat-grid">
      <div class="stat"><div class="muted">Piloten actief / totaal</div><div><strong><?= (int)$stats['pilots_active'] ?> / <?= (int)$stats['pilots_total'] ?></strong></div></div>
      <div class="stat"><div class="muted">Wedstrijden totaal</div><div><strong><?= (int)$stats['competitions_total'] ?></strong></div></div>
      <div class="stat"><div class="muted">Klasse 1 wedstrijden</div><div><strong><?= (int)$stats['competitions_k1'] ?></strong></div></div>
      <div class="stat"><div class="muted">Sportklasse wedstrijden</div><div><strong><?= (int)$stats['competitions_sc'] ?></strong></div></div>
      <div class="stat"><div class="muted">Herinneringen 30d / totaal</div><div><strong><?= (int)$stats['memories_30d'] ?> / <?= (int)$stats['memories_total'] ?></strong></div></div>
      <div class="stat"><div class="muted">WPRS <?= (int)$currentYear ?> K1</div><div><strong><?= (int)$stats['wprs_k1_year'] ?></strong></div></div>
      <div class="stat"><div class="muted">WPRS <?= (int)$currentYear ?> Sportklasse</div><div><strong><?= (int)$stats['wprs_sc_year'] ?></strong></div></div>
      <?php if ($latestCompetition): ?>
        <div class="stat">
          <div class="muted">Laatste wedstrijd</div>
          <div><strong><?= h($latestCompetition['title']) ?> - <?= (int)$latestCompetition['year'] ?></strong></div>
          <div class="muted"><?= h($latestCompetition['class'] ?: '') ?><?= !empty($latestCompetition['created_at']) ? ' - ' . h(date('Y-m-d', strtotime($latestCompetition['created_at']))) : '' ?></div>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
