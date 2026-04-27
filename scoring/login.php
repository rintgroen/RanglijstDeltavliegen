<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$csrf = app_csrf_token();

$notice = null;
$error = null;
$devLink = null;

if (isset($_GET['token'])) {
    $token = (string)$_GET['token'];
    $hash = hash('sha256', $token);
    try {
        $stmt = $pdo->prepare(
            'SELECT t.id AS token_id, t.scorer_id, s.email
             FROM rankings_scorer_login_tokens t
             JOIN rankings_scorers s ON s.id = t.scorer_id
             WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at >= UTC_TIMESTAMP() AND s.active = 1
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Deze loginlink is ongeldig of verlopen.');
        }
        $pdo->prepare('UPDATE rankings_scorer_login_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$row['token_id']]);
        $_SESSION['scorer_id'] = (int)$row['scorer_id'];
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $error = 'Ongeldige inzending. Probeer het opnieuw.';
    } else {
    $email = scoring_normalize_email((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldig e-mailadres in.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id FROM rankings_scorers WHERE email = ? AND active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $scorerId = (int)$stmt->fetchColumn();
            if ($scorerId > 0) {
                $token = bin2hex(random_bytes(32));
                $minutes = defined('SCORING_MAGIC_LINK_TTL_MINUTES') ? (int)SCORING_MAGIC_LINK_TTL_MINUTES : 30;
                $expires = (new DateTimeImmutable('now', scoring_utc_timezone()))->modify('+' . max(5, $minutes) . ' minutes')->format('Y-m-d H:i:s');
                $pdo->prepare('INSERT INTO rankings_scorer_login_tokens (scorer_id, token_hash, expires_at) VALUES (?, ?, ?)')
                    ->execute([$scorerId, hash('sha256', $token), $expires]);
                $devLink = scoring_absolute_url('scoring/login.php?token=' . $token);
                $sent = scoring_send_magic_link($email, $devLink);
                if ($sent) {
                    $notice = 'We hebben je een loginlink gestuurd.';
                } else {
                    $notice = 'Loginlink gemaakt, maar mail versturen lukte niet.';
                    if (app_debug_enabled() && scoring_last_mail_error() !== '') {
                        $notice .= ' Mailfout: ' . scoring_last_mail_error();
                    }
                }
            } else {
                $notice = 'Als dit e-mailadres bekend is, ontvang je een loginlink.';
            }
        } catch (Throwable $e) {
            $error = app_debug_enabled() ? $e->getMessage() : 'Loginlink aanvragen mislukt.';
        }
    }
    }
}

app_page_start('Scorer login - ' . app_site_name(), [
    'active_scoring' => '',
    'show_public_nav' => true,
    'show_scoring_nav' => false,
    'description' => 'Login voor scorers.',
]);
?>
<main class="card narrow-card">
  <h1>Scorer login</h1>
  <p class="muted">Vul het e-mailadres in dat door de beheerder is toegevoegd. Je ontvangt een tijdelijke loginlink.</p>
  <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($devLink && app_debug_enabled()): ?>
    <div class="notice"><a href="<?= h($devLink) ?>">Debug loginlink openen</a></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>E-mail
      <input type="email" name="email" required autocomplete="email">
    </label>
    <p><button type="submit">Stuur loginlink</button></p>
  </form>
</main>
<?php app_page_end(); ?>
