<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

function is_logged_in(): bool { return !empty($_SESSION['is_admin']); }
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
