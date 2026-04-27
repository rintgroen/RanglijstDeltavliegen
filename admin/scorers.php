<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../includes/scoring.php';
require_login();

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create') {
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Vul een geldig e-mailadres in.');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO rankings_scorers (email, name, active)
                     VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1'
                );
                $stmt->execute([$email, $name !== '' ? $name : null]);
                $sent = scoring_send_scorer_welcome_email($email, $name !== '' ? $name : null);
                if ($sent) {
                    $notice = 'Scorer opgeslagen en welkomstmail verstuurd.';
                } else {
                    $notice = 'Scorer opgeslagen, maar de welkomstmail kon niet worden verstuurd.';
                    if (app_debug_enabled() && scoring_last_mail_error() !== '') {
                        $notice .= ' Mailfout: ' . scoring_last_mail_error();
                    }
                }
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $active = isset($_POST['active']) ? 1 : 0;
                if ($id <= 0) {
                    throw new RuntimeException('Scorer niet gevonden.');
                }
                $stmt = $pdo->prepare('UPDATE rankings_scorers SET name = ?, active = ? WHERE id = ?');
                $stmt->execute([$name !== '' ? $name : null, $active, $id]);
                $notice = 'Scorer bijgewerkt.';
            }
        } catch (Throwable $e) {
            $error = app_debug_enabled() ? $e->getMessage() : 'Opslaan mislukt.';
        }
    }
}

$scorers = [];
try {
    $stmt = $pdo->query(
        'SELECT s.*,
                (SELECT COUNT(*) FROM rankings_scoring_competitions c WHERE c.scorer_id = s.id) AS competition_count
         FROM rankings_scorers s
         ORDER BY s.active DESC, s.email ASC'
    );
    $scorers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $error = app_debug_enabled() ? 'Scorers laden mislukt: ' . $e->getMessage() : 'Scorers laden mislukt.';
}

app_page_start('Scorers - Admin', [
    'active_admin' => 'scorers',
    'description' => 'Scorers beheren voor competitie scoring.',
]);
?>
<main>
  <section class="card">
    <h1>Scorers</h1>
    <p class="muted">Deze e-mailadressen mogen magic links aanvragen voor het nieuwe scoring-gedeelte.</p>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <div class="grid">
        <label>E-mail
          <input type="email" name="email" required maxlength="190">
        </label>
        <label>Naam (optioneel)
          <input type="text" name="name" maxlength="160">
        </label>
      </div>
      <p><button type="submit">Toevoegen</button></p>
    </form>
  </section>

  <section class="card">
    <h2>Toegelaten scorers</h2>
    <?php if (empty($scorers)): ?>
      <p class="muted">Nog geen scorers toegevoegd.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>E-mail</th>
              <th>Naam</th>
              <th>Competities</th>
              <th>Status</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($scorers as $scorer): ?>
              <tr>
                <td><?= h($scorer['email']) ?></td>
                <td><?= h($scorer['name'] ?: '-') ?></td>
                <td><?= (int)$scorer['competition_count'] ?></td>
                <td><?= (int)$scorer['active'] === 1 ? 'Actief' : 'Inactief' ?></td>
                <td>
                  <details>
                    <summary>Bewerken</summary>
                    <form method="post" class="grid">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$scorer['id'] ?>">
                      <label>Naam
                        <input type="text" name="name" value="<?= h($scorer['name'] ?? '') ?>" maxlength="160">
                      </label>
                      <label>
                        <input type="checkbox" name="active" <?= (int)$scorer['active'] === 1 ? 'checked' : '' ?>> Actief
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
    <?php endif; ?>
  </section>
</main>
<?php app_page_end('Admin - ' . app_site_name()); ?>
