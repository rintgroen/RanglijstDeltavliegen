<?php
require_once __DIR__ . '/utils.php';
require_login();

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } elseif (isset($_POST['create'])) {
        $name = trim((string)($_POST['name'] ?? ''));
        $nation = strtoupper(trim((string)($_POST['nation'] ?? 'NED')) ?: 'NED');
        $civl = trim((string)($_POST['civl_id'] ?? ''));
        $civlId = $civl === '' ? null : (int)$civl;
        if ($name !== '') {
            $stmt = $pdo->prepare('INSERT INTO rankings_pilots (name, nation, civl_id, active) VALUES (?, ?, ?, 1)');
            $stmt->execute([$name, $nation, $civlId]);
        }
        header('Location: pilots.php');
        exit;
    } elseif (isset($_POST['update'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $nation = strtoupper(trim((string)($_POST['nation'] ?? 'NED')) ?: 'NED');
        $civl = trim((string)($_POST['civl_id'] ?? ''));
        $civlId = $civl === '' ? null : (int)$civl;
        $active = isset($_POST['active']) ? 1 : 0;
        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE rankings_pilots SET name = ?, nation = ?, civl_id = ?, active = ? WHERE id = ?');
            $stmt->execute([$name, $nation, $civlId, $active, $id]);
        }
        header('Location: pilots.php');
        exit;
    }
}

$pilots = [];
try {
    $pilots = $pdo->query('SELECT * FROM rankings_pilots ORDER BY active DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        $error = 'Piloten laden mislukt: ' . $e->getMessage();
    }
}

app_page_start('Piloten - Admin', [
    'active_admin' => 'pilots',
    'description' => 'Pilotenbeheer voor Ranglijst Deltavliegen.',
]);
?>
<main>
  <section class="card">
    <h1>Piloten</h1>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="create" value="1">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div class="grid">
        <label>Naam
          <input type="text" name="name" required>
        </label>
        <label>Nationaliteit (ISO-3)
          <input type="text" name="nation" value="NED" maxlength="3">
        </label>
        <label>CIVL ID
          <input type="number" name="civl_id" inputmode="numeric" pattern="\d*">
        </label>
      </div>
      <p><button type="submit">Toevoegen</button></p>
    </form>
  </section>

  <section class="card">
    <h2>Alle piloten</h2>
    <div class="table-responsive">
      <table class="striped">
        <thead>
          <tr>
            <th>Naam</th>
            <th>Nationaliteit</th>
            <th>CIVL ID</th>
            <th>Actief</th>
            <th>Acties</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pilots as $pilot): ?>
            <tr>
              <td><a href="../public/pilot.php?id=<?= (int)$pilot['id'] ?>"><?= h($pilot['name']) ?></a></td>
              <td><?= h($pilot['nation']) ?></td>
              <td><?= isset($pilot['civl_id']) && $pilot['civl_id'] !== null && $pilot['civl_id'] !== '' ? (int)$pilot['civl_id'] : '-' ?></td>
              <td><?= $pilot['active'] ? 'Ja' : 'Nee' ?></td>
              <td>
                <details>
                  <summary>Bewerken</summary>
                  <form method="post" class="grid">
                    <input type="hidden" name="update" value="1">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$pilot['id'] ?>">
                    <label>Naam
                      <input type="text" name="name" value="<?= h($pilot['name']) ?>" required>
                    </label>
                    <label>Nationaliteit
                      <input type="text" name="nation" value="<?= h($pilot['nation']) ?>" maxlength="3">
                    </label>
                    <label>CIVL ID
                      <input type="number" name="civl_id" value="<?= isset($pilot['civl_id']) ? h($pilot['civl_id']) : '' ?>">
                    </label>
                    <label>
                      <input type="checkbox" name="active" <?= $pilot['active'] ? 'checked' : '' ?>> Actief
                    </label>
                    <p><button type="submit">Opslaan</button></p>
                  </form>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
