<?php
require_once __DIR__ . '/utils.php';

header('Location: ' . (is_logged_in() ? 'dashboard.php' : 'login.php'));
exit;
