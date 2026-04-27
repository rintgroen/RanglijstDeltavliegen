<?php
require_once __DIR__ . '/utils.php';
session_destroy();
header('Location: login.php');
exit;
