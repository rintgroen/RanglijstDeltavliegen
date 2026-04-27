<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
unset($_SESSION['scorer_id']);
header('Location: login.php');
exit;
