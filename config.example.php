<?php
// Copy this file to config.php on the server and fill in real values.
// Keep config.php out of Git; it contains secrets.

const SITE_NAME = 'Ranglijst Deltavliegen';
const BASE_URL = '/';
// Absolute URL used in scorer magic-link emails. Leave empty to derive it from the current request.
const SITE_BASE_URL = '';

const DB_HOST = 'localhost';
const DB_NAME = 'database_name';
const DB_USER = 'database_user';
const DB_PASS = 'database_password';

// Prefer ADMIN_PASSWORD_HASH for new installs:
// php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
const ADMIN_PASSWORD_HASH = '';

// Fallback for existing installs. Leave empty when using ADMIN_PASSWORD_HASH.
const ADMIN_PASSWORD = '';

// Scoring module
const SCORING_MAIL_FROM = 'noreply@example.com';
const SCORING_MAIL_FROM_NAME = 'Ranglijst Deltavliegen';
const SCORING_MAGIC_LINK_TTL_MINUTES = 30;
const SCORING_UPLOAD_MAX_MB = 12;

// Postmark is used for scorer login and welcome emails when a server token is set.
// Find this under your Postmark Server > API Tokens.
const POSTMARK_SERVER_TOKEN = '';
const POSTMARK_MESSAGE_STREAM = 'outbound';

date_default_timezone_set('Europe/Amsterdam');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
