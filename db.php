<?php
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Missing config.php. Copy config.example.php to config.php and fill in your local settings.');
}
require_once $configPath;

function db() : PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $mysqlInitCommand = null;
        if (class_exists('Pdo\\Mysql') && defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
            $mysqlInitCommand = constant('Pdo\\Mysql::ATTR_INIT_COMMAND');
        } elseif (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $mysqlInitCommand = constant('PDO::MYSQL_ATTR_INIT_COMMAND');
        }
        if ($mysqlInitCommand !== null) {
            $opts[$mysqlInitCommand] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    }
    return $pdo;
}
