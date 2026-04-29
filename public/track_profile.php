<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$csrf = app_csrf_token();
$notice = null;
$error = null;
$devLink = null;

try {
    scoring_ensure_track_collection_tables($pdo);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? $e->getMessage() : 'Trackprofielen zijn nog niet beschikbaar.';
}

if (isset($_GET['opened']) && $error === null) {
    $notice = 'Je trackprofiel is geopend.';
}

if (isset($_GET['token']) && is_string($_GET['token']) && $error === null) {
    try {
        $profile = scoring_consume_track_collection_login_token($pdo, (string)$_GET['token']);
        if (!$profile) {
            throw new RuntimeException('Deze link is ongeldig of verlopen.');
        }
        $_SESSION['track_collection_profile_id'] = (int)$profile['id'];
        header('Location: track_profile.php?opened=1');
        exit;
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
        if ($action === 'request_link') {
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $email = scoring_normalize_email((string)($_POST['email'] ?? ''));
            $requestedProfile = scoring_find_or_create_track_collection_profile($pdo, $displayName, $email);
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
            $usernameInput = trim((string)($_POST['livetrack24_username'] ?? ''));
            $username = '';
            if ($usernameInput !== '') {
                $candidate = scoring_livetrack24_find_username($usernameInput);
                if (!$candidate) {
                    throw new RuntimeException('Deze LiveTrack24 gebruiker kon niet worden gevonden.');
                }
                $username = (string)$candidate['username'];
            }
            $enabled = isset($_POST['livetrack24_enabled']) ? 1 : 0;
            if ($enabled === 1 && $username === '') {
                throw new RuntimeException('Vul je LiveTrack24 gebruikersnaam in voordat je automatische trackcollectie aanzet.');
            }
            $stmt = $pdo->prepare(
                'UPDATE rankings_track_collection_profiles
                 SET display_name = ?,
                     livetrack24_username = ?,
                     livetrack24_enabled = ?,
                     livetrack24_enabled_at = CASE WHEN ? = 1 AND livetrack24_enabled = 0 THEN UTC_TIMESTAMP() ELSE livetrack24_enabled_at END,
                     livetrack24_disabled_at = CASE WHEN ? = 0 AND livetrack24_enabled = 1 THEN UTC_TIMESTAMP() ELSE livetrack24_disabled_at END
                 WHERE id = ?'
            );
            $stmt->execute([
                $displayName,
                $username !== '' ? $username : null,
                $enabled,
                $enabled,
                $enabled,
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
    'description' => 'Trackprofiel voor automatische LiveTrack24 trackcollectie.',
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

    <?php if (!$profile): ?>
      <p class="muted">Vraag een tijdelijke link aan om je naam, e-mail en LiveTrack24 koppeling te beheren.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="request_link">
        <label>Naam
          <input type="text" name="display_name" required maxlength="160" autocomplete="name">
        </label>
        <label>E-mail
          <input type="email" name="email" required maxlength="190" autocomplete="email">
        </label>
        <p><button type="submit">Link sturen</button></p>
      </form>
    <?php else: ?>
      <div class="profile-status">
        <div>
          <span class="status-label">E-mail</span>
          <strong><?= h($profile['email']) ?></strong>
        </div>
        <div>
          <span class="status-label">LiveTrack24</span>
          <strong>
            <?php if (!empty($profile['livetrack24_enabled'])): ?>
              aan
            <?php elseif (!empty($profile['livetrack24_username'])): ?>
              gekoppeld
            <?php else: ?>
              niet gekoppeld
            <?php endif; ?>
          </strong>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_profile">
        <label>Naam voor uitslagen
          <input type="text" name="display_name" required maxlength="160" autocomplete="name" value="<?= h($profile['display_name']) ?>">
        </label>
        <label>LiveTrack24 gebruikersnaam of profiel-URL
          <input type="text" name="livetrack24_username" maxlength="120" value="<?= h($profile['livetrack24_username'] ?? '') ?>" placeholder="bijvoorbeeld: Miyata">
        </label>
        <label class="check-row">
          <input type="checkbox" name="livetrack24_enabled" value="1" <?= !empty($profile['livetrack24_enabled']) ? 'checked' : '' ?>>
          Automatische LiveTrack24 trackcollectie aanzetten
        </label>
        <p class="muted">We zoeken alleen publieke LiveTrack24 tracks en gebruiken handmatige uploads nog steeds als fallback.</p>
        <p class="actions">
          <button type="submit">Trackprofiel opslaan</button>
          <?php if (!empty($profile['livetrack24_username'])): ?>
            <a class="btn secondary" href="<?= h(scoring_livetrack24_profile_url((string)$profile['livetrack24_username'])) ?>" target="_blank" rel="noopener">LiveTrack24 openen</a>
          <?php endif; ?>
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
