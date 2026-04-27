<?php
require_once __DIR__ . '/../db.php';

function app_debug_enabled(): bool {
    return isset($_GET['debug']);
}

function app_enable_debug(): void {
    if (app_debug_enabled()) {
        @ini_set('display_errors', '1');
        @error_reporting(E_ALL);
    }
}

function app_site_name(): string {
    return defined('SITE_NAME') ? SITE_NAME : 'Ranglijst Deltavliegen';
}

function app_area(): string {
    return defined('APP_AREA') ? (string)APP_AREA : 'public';
}

function app_is_admin(): bool {
    return app_area() === 'admin';
}

function app_asset(string $path): string {
    $path = ltrim($path, '/');
    return app_is_admin() ? '../public/assets/' . $path : 'assets/' . $path;
}

function app_public_url(string $path): string {
    $path = ltrim($path, '/');
    return app_is_admin() ? '../public/' . $path : $path;
}

function app_admin_url(string $path): string {
    $path = ltrim($path, '/');
    return app_is_admin() ? $path : '../admin/' . $path;
}

function app_db_or_fail(): PDO {
    try {
        return db();
    } catch (Throwable $e) {
        http_response_code(500);
        if (app_debug_enabled()) {
            echo '<pre>DB connect failed: ' . h($e->getMessage()) . '</pre>';
        }
        exit;
    }
}

function app_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_token'];
}

function app_check_csrf(string $field = 'csrf'): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return isset($_POST[$field], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST[$field]);
}

function app_truncate(string $text, int $width = 140): string {
    $text = trim(strip_tags($text));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, '...', 'UTF-8');
    }
    return strlen($text) > $width ? substr($text, 0, max(0, $width - 3)) . '...' : $text;
}

function app_format_points($value, int $decimals = 2): string {
    return number_format((float)$value, $decimals, ',', '.');
}

function app_format_compact_number($value, int $decimals = 3): string {
    return rtrim(rtrim(number_format((float)$value, $decimals, '.', ''), '0'), '.');
}

function app_first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
    $cols = [];
    try {
        $rs = $pdo->query("SHOW COLUMNS FROM `$table`");
        if ($rs) {
            foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    foreach ($candidates as $candidate) {
        $key = strtolower((string)$candidate);
        if (isset($cols[$key])) {
            return $cols[$key];
        }
    }

    return null;
}

function app_nav_link(string $href, string $label, bool $active = false, string $extraClass = ''): void {
    $classes = trim('nav-link ' . $extraClass . ($active ? ' is-active' : ''));
    echo '<a class="' . h($classes) . '" href="' . h($href) . '"' . ($active ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
}

function app_public_nav(string $active = ''): void {
    $items = [
        'home' => ['Home', 'home.php'],
        'ranking' => ['Klasse 1', 'ranking.php'],
        'sportclass' => ['Sportklasse', 'sportclass.php'],
        'competitions' => ['Wedstrijden', 'competitionlist.php'],
        'explanation' => ['Toelichting', 'explanation.php'],
    ];
    echo '<nav class="site-nav" aria-label="Publieke navigatie">';
    foreach ($items as $key => $item) {
        app_nav_link(app_public_url($item[1]), $item[0], $active === $key);
    }
    echo '</nav>';
}

function app_admin_nav(string $active = ''): void {
    $items = [
        'dashboard' => ['Dashboard', 'dashboard.php'],
        'pilots' => ['Piloten', 'pilots.php'],
        'world_points' => ['WPRS-punten', 'world_points.php'],
        'competition_upload' => ['Wedstrijd upload', 'competition_upload.php'],
        'memories' => ['Herinneringen', 'memories.php'],
        'logout' => ['Uitloggen', 'logout.php'],
    ];
    echo '<nav class="site-nav admin-nav" aria-label="Admin navigatie">';
    foreach ($items as $key => $item) {
        app_nav_link(app_admin_url($item[1]), $item[0], $active === $key);
    }
    echo '</nav>';
}

function app_page_start(string $title, array $options = []): void {
    $activePublic = $options['active_public'] ?? '';
    $activeAdmin = $options['active_admin'] ?? '';
    $description = $options['description'] ?? app_site_name();
    $bodyClass = trim('container ' . ($options['body_class'] ?? ''));
    $extraHead = $options['extra_head'] ?? '';
    $showPublicNav = $options['show_public_nav'] ?? true;
    $showAdminNav = $options['show_admin_nav'] ?? app_is_admin();
    $brandHref = app_public_url('home.php');
    if (app_is_admin()) {
        $brandHref = app_admin_url('dashboard.php');
    }
    ?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <link rel="stylesheet" href="<?= h(app_asset('style.css')) ?>">
  <?= $extraHead ?>
</head>
<body class="<?= h($bodyClass) ?>">
<header class="site-header">
  <a class="site-brand" href="<?= h($brandHref) ?>">
    <span class="site-title"><?= h(app_site_name()) ?></span>
    <?php if (app_is_admin()): ?><span class="site-badge">Admin</span><?php endif; ?>
  </a>
</header>
<?php
    if ($showPublicNav) {
        app_public_nav($activePublic);
    }
    if ($showAdminNav) {
        app_admin_nav($activeAdmin);
    }
}

function app_page_end(?string $footerText = null): void {
    $footerHtml = $footerText === null
        ? '&copy; ' . date('Y') . ' ' . h(app_site_name())
        : h($footerText);
    ?>
<footer class="site-footer muted">
  <p><?= $footerHtml ?></p>
</footer>
<script src="<?= h(app_asset('script.js')) ?>" defer></script>
</body>
</html>
<?php
}
