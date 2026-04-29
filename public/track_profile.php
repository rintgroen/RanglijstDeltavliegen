<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$notice = null;
$error = null;
$devLink = null;
$confirmToken = null;
$confirmProfile = null;

try {
    scoring_ensure_track_collection_tables($pdo);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? $e->getMessage() : 'Trackprofielen zijn nog niet beschikbaar.';
}

if (isset($_GET['opened']) && $error === null) {
    $notice = 'Je trackprofiel is geopend.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['token']) && is_string($_GET['token']) && $error === null) {
    try {
        $confirmProfile = scoring_load_track_collection_login_token_profile($pdo, (string)$_GET['token']);
        if (!$confirmProfile) {
            throw new RuntimeException('Deze link is ongeldig of verlopen.');
        }
        $confirmToken = (string)$_GET['token'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$profileId = isset($_SESSION['track_collection_profile_id']) ? (int)$_SESSION['track_collection_profile_id'] : 0;
$profile = ($profileId > 0 && $error === null) ? scoring_load_track_collection_profile($pdo, $profileId) : null;
if (!$profile) {
    unset($_SESSION['track_collection_profile_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    try {
        if (!app_check_csrf()) {
            throw new RuntimeException('Ongeldige inzending. Probeer het opnieuw.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'consume_token') {
            $openedProfile = scoring_consume_track_collection_login_token($pdo, (string)($_POST['token'] ?? ''));
            if (!$openedProfile) {
                throw new RuntimeException('Deze link is ongeldig of verlopen.');
            }
            $_SESSION['track_collection_profile_id'] = (int)$openedProfile['id'];
            header('Location: track_profile.php?opened=1');
            exit;
        } elseif ($action === 'request_link') {
            $email = scoring_normalize_email((string)($_POST['email'] ?? ''));
            $requestedProfile = scoring_find_or_create_track_collection_profile($pdo, '', $email);
            $token = scoring_create_track_collection_login_token($pdo, (int)$requestedProfile['id']);
            $devLink = scoring_absolute_url('public/track_profile.php?token=' . $token);
            $sent = scoring_send_track_collection_magic_link($email, $devLink);
            $notice = $sent
                ? 'We hebben je een link gestuurd om je trackprofiel te openen.'
                : 'De link kon niet worden verzonden. Probeer het later opnieuw.';
            $profile = null;
            unset($_SESSION['track_collection_profile_id']);
        } elseif ($action === 'save_profile') {
            if (!$profile) {
                throw new RuntimeException('Open je trackprofiel eerst via de link in je e-mail.');
            }
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            if ($displayName === '') {
                throw new RuntimeException('Vul je naam in.');
            }
            $flymasterSerial = scoring_flymaster_normalize_serial((string)($_POST['flymaster_serial'] ?? ''));
            $flymasterEnabled = isset($_POST['flymaster_enabled']) ? 1 : 0;
            if ($flymasterEnabled === 1 && $flymasterSerial === '') {
                throw new RuntimeException('Vul je Flymaster serienummer in voordat je toestemming geeft.');
            }
            $stmt = $pdo->prepare(
                'UPDATE rankings_track_collection_profiles
                 SET display_name = ?,
                     flymaster_serial = ?,
                     flymaster_enabled = ?,
                     flymaster_enabled_at = CASE WHEN ? = 1 AND flymaster_enabled = 0 THEN UTC_TIMESTAMP() ELSE flymaster_enabled_at END,
                     flymaster_disabled_at = CASE WHEN ? = 0 AND flymaster_enabled = 1 THEN UTC_TIMESTAMP() ELSE flymaster_disabled_at END,
                     livetrack24_enabled = 0,
                     livetrack24_disabled_at = CASE WHEN livetrack24_enabled = 1 THEN UTC_TIMESTAMP() ELSE livetrack24_disabled_at END
                 WHERE id = ?'
            );
            $stmt->execute([
                $displayName,
                $flymasterSerial !== '' ? $flymasterSerial : null,
                $flymasterEnabled,
                $flymasterEnabled,
                $flymasterEnabled,
                (int)$profile['id'],
            ]);
            $profile = scoring_load_track_collection_profile($pdo, (int)$profile['id']);
            $notice = 'Je trackprofiel is opgeslagen.';
        } elseif ($action === 'close_profile') {
            unset($_SESSION['track_collection_profile_id']);
            $profile = null;
            $notice = 'Je trackprofiel is gesloten.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

app_page_start(app_site_name() . ' - Trackprofiel', [
    'active_public' => 'track_profile',
    'description' => 'Trackprofiel voor competitie scoring.',
]);
?>
<main>
  <section class="card narrow-card">
    <div class="kicker">Competitie scoring</div>
    <h1>Trackprofiel</h1>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($devLink && app_debug_enabled()): ?>
      <div class="notice"><a href="<?= h($devLink) ?>">Debug link openen</a></div>
    <?php endif; ?>

    <?php if ($confirmToken !== null && $confirmProfile): ?>
      <p class="muted">Bevestig dat je je trackprofiel wilt openen<?= !empty($confirmProfile['email']) ? ' voor ' . h((string)$confirmProfile['email']) : '' ?>.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="consume_token">
        <input type="hidden" name="token" value="<?= h($confirmToken) ?>">
        <p><button type="submit">Trackprofiel openen</button></p>
      </form>
    <?php elseif (!$profile): ?>
      <p class="muted">
        Als je een Flymaster tracker hebt, kun je deze in een trackprofiel met het serienummer aan RanglijstDeltavliegen koppelen. Hiermee kunnen we je tracklog voor toekomstige taken automatisch ophalen bij Flymaster. Een tracklog uploaden is dan niet meer nodig, maar kan nog steeds in geval je deze als backup wilt meegeven.
      </p>
      <p class="muted">
        Vul je e-mailadres in. Vervolgens krijg je een tijdelijke link waarmee je je trackprofiel kunt aanmaken/aanpassen zonder wachtwoord. Dit doe je eenmalig, de rest verzorgen wij.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="request_link">
        <label>E-mailadres
          <input type="email" name="email" required maxlength="190" autocomplete="email">
        </label>
        <p><button type="submit">Link naar mijn trackprofiel sturen</button></p>
      </form>
    <?php else: ?>
      <p class="muted">Beheer hier de gegevens die gebruikt worden voor competitietaken en automatische Flymaster track-verzameling.</p>
      <div class="profile-status">
        <div>
          <span class="status-label">E-mail</span>
          <strong><?= h($profile['email']) ?></strong>
        </div>
        <div>
          <span class="status-label">Naam in uitslagen</span>
          <strong><?= h(trim((string)$profile['display_name']) !== '' ? (string)$profile['display_name'] : 'nog invullen') ?></strong>
        </div>
        <div>
          <span class="status-label">Flymaster</span>
          <strong>
            <?php if (!empty($profile['flymaster_enabled']) && !empty($profile['flymaster_serial'])): ?>
              toestemming voor #<?= h($profile['flymaster_serial']) ?>
            <?php elseif (!empty($profile['flymaster_serial'])): ?>
              serienummer ingesteld
            <?php else: ?>
              niet ingesteld
            <?php endif; ?>
          </strong>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_profile">
        <label>Naam in uitslagen
          <input type="text" name="display_name" required maxlength="160" autocomplete="name" value="<?= h($profile['display_name']) ?>">
        </label>
        <label>Flymaster serienummer
          <input type="text" name="flymaster_serial" inputmode="numeric" pattern="[0-9]{3,12}" maxlength="12" value="<?= h($profile['flymaster_serial'] ?? '') ?>" placeholder="bijvoorbeeld: 915477">
        </label>
        <label class="check-row">
          <input type="checkbox" name="flymaster_enabled" value="1" <?= !empty($profile['flymaster_enabled']) ? 'checked' : '' ?>>
          Ik geef toestemming om publieke Flymaster replay-tracks voor competitietaken op te halen
        </label>
        <p class="muted">We gebruiken alleen publieke Flymaster replay-gegevens als extra service voor taakreview. Handmatige uploads blijven de fallback wanneer een reconstructie niet goed genoeg is.</p>
        <p class="actions">
          <button type="submit">Trackprofiel opslaan</button>
        </p>
      </form>

      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="close_profile">
        <button class="secondary" type="submit">Profiel sluiten</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php app_page_end(); ?>
