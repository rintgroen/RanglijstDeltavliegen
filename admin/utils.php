<?php
if (!defined('APP_AREA')) {
    define('APP_AREA', 'admin');
}

require_once __DIR__ . '/../includes/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function is_logged_in(): bool {
    return !empty($_SESSION['is_admin']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function admin_password_matches(string $password): bool {
    if (defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== '') {
        return password_verify($password, ADMIN_PASSWORD_HASH);
    }

    return defined('ADMIN_PASSWORD') && (string)ADMIN_PASSWORD !== '' && hash_equals((string)ADMIN_PASSWORD, $password);
}
