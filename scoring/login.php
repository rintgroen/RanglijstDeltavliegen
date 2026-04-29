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
$confirmToken = null;
$confirmScorer = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['token'])) {
    try {
        $confirmScorer = scoring_load_scorer_login_token($pdo, (string)$_GET['token']);
        if (!$confirmScorer) {
            throw new RuntimeException('Deze loginlink is ongeldig of verlopen.');
        }
        $confirmToken = (string)$_GET['token'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_check_csrf()) {
        $error = 'Ongeldige inzending. Probeer het opnieuw.';
    } else {
        $action = (string)($_POST['action'] ?? 'request_link');
        if ($action === 'consume_token') {
            try {
                $scorerLogin = scoring_consume_scorer_login_token($pdo, (string)($_POST['token'] ?? ''));
                if (!$scorerLogin) {
                    throw new RuntimeException('Deze loginlink is ongeldig of verlopen.');
                }
                $_SESSION['scorer_id'] = (int)$scorerLogin['scorer_id'];
                session_regenerate_id(true);
                header('Location: index.php');
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } elseif ($action === 'request_link') {
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
        } else {
            $error = 'Ongeldige inzending. Probeer het opnieuw.';
        }
    }
}

if ($confirmScorer) {
    unset($confirmScorer['token_id']);
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
  <?php if ($confirmToken !== null && $confirmScorer): ?>
    <p class="muted">Bevestig dat je wilt inloggen als scorer<?= !empty($confirmScorer['email']) ? ' voor ' . h((string)$confirmScorer['email']) : '' ?>.</p>
  <?php else: ?>
    <p class="muted">Vul het e-mailadres in dat door de beheerder is toegevoegd. Je ontvangt een tijdelijke loginlink.</p>
  <?php endif; ?>
  <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($devLink && app_debug_enabled()): ?>
    <div class="notice"><a href="<?= h($devLink) ?>">Debug loginlink openen</a></div>
  <?php endif; ?>
  <?php if ($confirmToken !== null && $confirmScorer): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="consume_token">
      <input type="hidden" name="token" value="<?= h($confirmToken) ?>">
      <p><button type="submit">Inloggen als scorer</button></p>
    </form>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="request_link">
      <label>E-mail
        <input type="email" name="email" required autocomplete="email">
      </label>
      <p><button type="submit">Stuur loginlink</button></p>
    </form>
  <?php endif; ?>
</main>
<?php app_page_end(); ?>
