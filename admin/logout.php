<?php
require_once __DIR__ . '/utils.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
