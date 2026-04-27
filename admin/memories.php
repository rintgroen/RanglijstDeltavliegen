<?php
require_once __DIR__ . '/utils.php';
require_login();

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mem_admin_action'])) {
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } else {
        $ids = [];
        $target = (string)($_POST['target'] ?? '');
        $singleAction = (string)($_POST['single_action'] ?? '');
        if (preg_match('/^(show|hide):(\d+)$/', $singleAction, $match)) {
            $target = $match[1];
            $ids[] = (int)$match[2];
        } elseif (isset($_POST['memory_id']) && is_array($_POST['memory_id'])) {
            foreach ($_POST['memory_id'] as $memoryId) {
                $memoryId = (int)$memoryId;
                if ($memoryId > 0) {
                    $ids[] = $memoryId;
                }
            }
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids) || !in_array($target, ['show', 'hide'], true)) {
            $error = 'Geen geldige selectie opgegeven.';
        } else {
            try {
                $value = $target === 'show' ? 1 : 0;
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE rankings_competition_memories SET is_visible = $value WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $notice = $target === 'show' ? 'Inzending(en) zichtbaar gemaakt.' : 'Inzending(en) verborgen.';
            } catch (Throwable $e) {
                $error = app_debug_enabled() ? 'Moderatie mislukt: ' . $e->getMessage() : 'Moderatie mislukt.';
            }
        }
    }
}

$filter = (string)($_GET['filter'] ?? 'all');
$where = '';
if ($filter === 'visible') {
    $where = 'WHERE m.is_visible = 1';
} elseif ($filter === 'hidden') {
    $where = 'WHERE m.is_visible = 0';
} else {
    $filter = 'all';
}

$rows = [];
try {
    $sql = "SELECT m.id, m.competition_id, m.author_name, m.title, m.body, m.photo_path, m.created_at, m.is_visible,
                   c.title AS comp_title, c.year AS comp_year, c.class AS comp_class
            FROM rankings_competition_memories m
            JOIN rankings_competitions c ON c.id = m.competition_id
            $where
            ORDER BY m.created_at DESC
            LIMIT 200";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        $error = 'Herinneringen laden mislukt: ' . $e->getMessage();
    }
}

app_page_start('Herinneringen - Admin', [
    'active_admin' => 'memories',
    'description' => 'Herinneringen modereren voor Ranglijst Deltavliegen.',
]);
?>
<main class="card">
  <h1>Herinneringen</h1>
  <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

  <form method="get" class="toolbar">
    <label>Filter
      <select name="filter" onchange="this.form.submit()">
        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Alles</option>
        <option value="visible" <?= $filter === 'visible' ? 'selected' : '' ?>>Zichtbaar</option>
        <option value="hidden" <?= $filter === 'hidden' ? 'selected' : '' ?>>Verborgen</option>
      </select>
    </label>
    <noscript><button type="submit">Filteren</button></noscript>
  </form>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="mem_admin_action" value="moderate">
    <div class="table-responsive">
      <table class="striped">
        <thead>
          <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.memory-check').forEach((item) => item.checked = this.checked)" aria-label="Alles selecteren"></th>
            <th>ID</th>
            <th>Datum</th>
            <th>Status</th>
            <th>Wedstrijd</th>
            <th>Auteur</th>
            <th>Titel</th>
            <th>Foto</th>
            <th>Tekst</th>
            <th>Acties</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="muted">Geen resultaten.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $snippet = app_truncate((string)($row['body'] ?: ''), 140);
                $competitionLink = '../public/competition.php?id=' . (int)$row['competition_id'] . '#memories';
                $isVisible = (int)$row['is_visible'];
                $competitionLabel = ($row['comp_title'] ?: 'Wedstrijd') . ' - ' . (int)$row['comp_year'] . ' (' . ($row['comp_class'] ?: '') . ')';
              ?>
              <tr>
                <td><input type="checkbox" class="memory-check" name="memory_id[]" value="<?= (int)$row['id'] ?>"></td>
                <td><?= (int)$row['id'] ?></td>
                <td><?= h(date('Y-m-d H:i', strtotime($row['created_at']))) ?></td>
                <td><?= $isVisible ? 'Zichtbaar' : 'Verborgen' ?></td>
                <td><a href="<?= h($competitionLink) ?>"><?= h($competitionLabel) ?></a></td>
                <td><?= h($row['author_name']) ?></td>
                <td><?= h($row['title'] ?: '') ?></td>
                <td><?php if (!empty($row['photo_path'])): ?><img class="thumb" src="<?= h('../public/' . $row['photo_path']) ?>" alt="Foto"><?php endif; ?></td>
                <td><?= h($snippet) ?></td>
                <td>
                  <?php if ($isVisible): ?>
                    <button name="single_action" value="hide:<?= (int)$row['id'] ?>" type="submit">Verbergen</button>
                  <?php else: ?>
                    <button name="single_action" value="show:<?= (int)$row['id'] ?>" type="submit">Zichtbaar</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($rows)): ?>
      <p class="inline">
        <button name="target" value="show" type="submit">Maak selectie zichtbaar</button>
        <button name="target" value="hide" type="submit">Verberg selectie</button>
      </p>
    <?php endif; ?>
  </form>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
