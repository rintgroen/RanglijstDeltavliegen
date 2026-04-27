<?php
// Copy this file to config.php on the server and fill in real values.
// Keep config.php out of Git; it contains secrets.

const SITE_NAME = 'Ranglijst Deltavliegen';
const BASE_URL = '/';

const DB_HOST = 'localhost';
const DB_NAME = 'database_name';
const DB_USER = 'database_user';
const DB_PASS = 'database_password';

// Prefer ADMIN_PASSWORD_HASH for new installs:
// php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
const ADMIN_PASSWORD_HASH = '';

// Fallback for existing installs. Leave empty when using ADMIN_PASSWORD_HASH.
const ADMIN_PASSWORD = '';

date_default_timezone_set('Europe/Amsterdam');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
