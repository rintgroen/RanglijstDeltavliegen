<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/qrcode.php';

function scoring_timezone(): DateTimeZone {
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'Europe/Amsterdam');
    }
    return $tz;
}

function scoring_utc_timezone(): DateTimeZone {
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('UTC');
    }
    return $tz;
}

function scoring_now_utc(): string {
    return (new DateTimeImmutable('now', scoring_utc_timezone()))->format('Y-m-d H:i:s');
}

function scoring_local_input_to_utc_sql(string $value): string {
    $value = trim($value);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, scoring_timezone());
    if (!$dt) {
        $dt = new DateTimeImmutable($value, scoring_timezone());
    }
    return $dt->setTimezone(scoring_utc_timezone())->format('Y-m-d H:i:s');
}

function scoring_utc_sql_to_local_input(?string $value): string {
    if (!$value) {
        return '';
    }
    $dt = new DateTimeImmutable($value, scoring_utc_timezone());
    return $dt->setTimezone(scoring_timezone())->format('Y-m-d\TH:i');
}

function scoring_utc_sql_to_local_date(?string $value): string {
    if (!$value) {
        return '';
    }
    $dt = new DateTimeImmutable($value, scoring_utc_timezone());
    return $dt->setTimezone(scoring_timezone())->format('Y-m-d');
}

function scoring_utc_sql_to_local_time(?string $value): string {
    if (!$value) {
        return '';
    }
    $dt = new DateTimeImmutable($value, scoring_utc_timezone());
    return $dt->setTimezone(scoring_timezone())->format('H:i');
}

function scoring_utc_sql_to_display(?string $value): string {
    if (!$value) {
        return '-';
    }
    $dt = new DateTimeImmutable($value, scoring_utc_timezone());
    return $dt->setTimezone(scoring_timezone())->format('Y-m-d H:i');
}

function scoring_gate_local_to_utc_sql(string $taskDate, string $time): string {
    $dt = new DateTimeImmutable($taskDate . ' ' . trim($time), scoring_timezone());
    return $dt->setTimezone(scoring_utc_timezone())->format('Y-m-d H:i:s');
}

function scoring_normalize_email(string $email): string {
    return strtolower(trim($email));
}

function scoring_current_scorer(PDO $pdo): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $id = isset($_SESSION['scorer_id']) ? (int)$_SESSION['scorer_id'] : 0;
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, email, name, active FROM rankings_scorers WHERE id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $scorer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$scorer) {
        unset($_SESSION['scorer_id']);
        return null;
    }
    return $scorer;
}

function scoring_require_scorer(PDO $pdo): array {
    $scorer = scoring_current_scorer($pdo);
    if (!$scorer) {
        header('Location: login.php');
        exit;
    }
    return $scorer;
}

function scoring_site_base_url(): string {
    if (defined('SITE_BASE_URL') && trim((string)SITE_BASE_URL) !== '') {
        return rtrim((string)SITE_BASE_URL, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = defined('BASE_URL') ? (string)BASE_URL : '/';
    return rtrim($scheme . '://' . $host . '/' . trim($base, '/'), '/');
}

function scoring_absolute_url(string $path): string {
    return scoring_site_base_url() . '/' . ltrim($path, '/');
}

function scoring_task_share_path(int $taskId): string {
    return 'public/task_board.php?id=' . $taskId;
}

function scoring_task_share_url(int $taskId): string {
    return scoring_absolute_url(scoring_task_share_path($taskId));
}

function scoring_download_slug(string $value, string $fallback = 'download'): string {
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value) : false;
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';
    $value = strtolower(trim($value, '-'));
    return $value !== '' ? substr($value, 0, 90) : $fallback;
}

function scoring_task_xctsk_filename(array $task): string {
    $base = (string)($task['competition_name'] ?? 'competitie') . '-' . (string)($task['name'] ?? 'taak');
    return scoring_download_slug($base, 'taak') . '.xctsk';
}

function scoring_xctsk_time(?string $utcSql): string {
    if (!$utcSql) {
        return '00:00:00Z';
    }
    $dt = new DateTimeImmutable($utcSql, scoring_utc_timezone());
    return $dt->setTimezone(scoring_utc_timezone())->format('H:i:s') . 'Z';
}

function scoring_xctsk_gate_times(array $task, array $gates): array {
    $times = [];
    if ((string)($task['task_type'] ?? 'race') === 'race') {
        foreach ($gates as $gate) {
            if (!empty($gate['gate_time_at'])) {
                $times[] = scoring_xctsk_time((string)$gate['gate_time_at']);
            }
        }
    }
    if (empty($times)) {
        $times[] = scoring_xctsk_time((string)($task['window_open_at'] ?? ''));
    }
    return array_values(array_unique($times));
}

function scoring_xctsk_start_type(array $task): string {
    return (string)($task['task_type'] ?? 'race') === 'time_trial' ? 'ELAPSED-TIME' : 'RACE';
}

function scoring_xctsk_start_type_code(array $task): int {
    return scoring_xctsk_start_type($task) === 'ELAPSED-TIME' ? 2 : 1;
}

function scoring_xctsk_waypoint_name(array $turnpoint): string {
    $code = trim((string)($turnpoint['code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $name = trim((string)($turnpoint['name'] ?? 'Waypoint'));
    return $name !== '' ? $name : 'Waypoint';
}

function scoring_xctsk_waypoint_description(array $turnpoint): string {
    $name = trim((string)($turnpoint['name'] ?? ''));
    $code = trim((string)($turnpoint['code'] ?? ''));
    return $name !== '' && $code !== '' && strcasecmp($name, $code) !== 0 ? $name : '';
}

function scoring_xctsk_encode_num(int $num): string {
    $value = $num << 1;
    if ($num < 0) {
        $value = ~$value;
    }

    $encoded = '';
    while ($value > 0x1F) {
        $encoded .= chr((($value & 0x1F) | 0x20) + 63);
        $value >>= 5;
    }
    return $encoded . chr($value + 63);
}

function scoring_xctsk_encode_turnpoint(array $turnpoint): string {
    $altitude = $turnpoint['elevation_m'] !== null && $turnpoint['elevation_m'] !== ''
        ? (int)round((float)$turnpoint['elevation_m'])
        : 0;
    return scoring_xctsk_encode_num((int)round((float)$turnpoint['longitude'] * 100000))
        . scoring_xctsk_encode_num((int)round((float)$turnpoint['latitude'] * 100000))
        . scoring_xctsk_encode_num($altitude)
        . scoring_xctsk_encode_num(max(1, (int)($turnpoint['radius_m'] ?? 400)));
}

function scoring_build_task_xctsk_document(array $task, array $turnpoints, array $gates): array {
    [$sssIndex, $essIndex] = scoring_speed_section_indices($turnpoints);
    $xctskTurnpoints = [];
    foreach ($turnpoints as $idx => $turnpoint) {
        $waypoint = [
            'name' => scoring_xctsk_waypoint_name($turnpoint),
            'lat' => round((float)$turnpoint['latitude'], 7),
            'lon' => round((float)$turnpoint['longitude'], 7),
            'altSmoothed' => $turnpoint['elevation_m'] !== null && $turnpoint['elevation_m'] !== ''
                ? (int)round((float)$turnpoint['elevation_m'])
                : 0,
        ];
        $description = scoring_xctsk_waypoint_description($turnpoint);
        if ($description !== '') {
            $waypoint['description'] = $description;
        }

        $row = [
            'radius' => max(1, (int)($turnpoint['radius_m'] ?? 400)),
            'waypoint' => $waypoint,
        ];
        if ($idx === $sssIndex) {
            $row = ['type' => 'SSS'] + $row;
        } elseif ($idx === $essIndex) {
            $row = ['type' => 'ESS'] + $row;
        }
        $xctskTurnpoints[] = $row;
    }

    return [
        'taskType' => 'CLASSIC',
        'version' => 1,
        'earthModel' => 'WGS84',
        'turnpoints' => $xctskTurnpoints,
        'takeoff' => [
            'timeOpen' => scoring_xctsk_time((string)($task['window_open_at'] ?? '')),
            'timeClose' => scoring_xctsk_time((string)($task['window_close_at'] ?? '')),
        ],
        'sss' => [
            'type' => scoring_xctsk_start_type($task),
            'direction' => 'EXIT',
            'timeGates' => scoring_xctsk_gate_times($task, $gates),
            'timeClose' => scoring_xctsk_time((string)($task['window_close_at'] ?? '')),
        ],
        'goal' => [
            'type' => 'CYLINDER',
            'deadline' => scoring_xctsk_time((string)($task['window_close_at'] ?? '')),
        ],
    ];
}

function scoring_build_task_xctsk_qr_document(array $task, array $turnpoints, array $gates): array {
    [$sssIndex, $essIndex] = scoring_speed_section_indices($turnpoints);
    $xctskTurnpoints = [];
    foreach ($turnpoints as $idx => $turnpoint) {
        $row = [
            'z' => scoring_xctsk_encode_turnpoint($turnpoint),
            'n' => scoring_xctsk_waypoint_name($turnpoint),
        ];
        $description = scoring_xctsk_waypoint_description($turnpoint);
        if ($description !== '') {
            $row['d'] = $description;
        }
        if ($idx === $sssIndex) {
            $row['t'] = 2;
        } elseif ($idx === $essIndex) {
            $row['t'] = 3;
        }
        $xctskTurnpoints[] = $row;
    }

    return [
        'taskType' => 'CLASSIC',
        'version' => 2,
        't' => $xctskTurnpoints,
        's' => [
            'g' => scoring_xctsk_gate_times($task, $gates),
            'd' => 2,
            't' => scoring_xctsk_start_type_code($task),
        ],
        'g' => [
            'd' => scoring_xctsk_time((string)($task['window_close_at'] ?? '')),
            't' => 2,
        ],
        'e' => 0,
        'to' => scoring_xctsk_time((string)($task['window_open_at'] ?? '')),
        'tc' => scoring_xctsk_time((string)($task['window_close_at'] ?? '')),
    ];
}

function scoring_json_encode_or_fail(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Taak kon niet als XCTSK worden opgebouwd.');
    }
    return $json;
}

function scoring_build_task_xctsk_json(array $task, array $turnpoints, array $gates): string {
    return scoring_json_encode_or_fail(scoring_build_task_xctsk_document($task, $turnpoints, $gates));
}

function scoring_build_task_xctsk_qr_payload(array $task, array $turnpoints, array $gates): string {
    $json = scoring_json_encode_or_fail(scoring_build_task_xctsk_qr_document($task, $turnpoints, $gates));
    $payload = 'XCTSK:' . $json;
    if (strlen($payload) > 1800 && function_exists('gzcompress')) {
        $compressed = 'XCTSKZ:' . base64_encode(gzcompress($json, 9));
        if (strlen($compressed) < strlen($payload)) {
            return $compressed;
        }
    }
    return $payload;
}

function scoring_task_qr_svg(array $task, array $turnpoints, array $gates): string {
    $payload = scoring_build_task_xctsk_qr_payload($task, $turnpoints, $gates);
    return AppQrCode::svg($payload);
}

function scoring_set_mail_error(string $message): void {
    $GLOBALS['SCORING_LAST_MAIL_ERROR'] = $message;
}

function scoring_last_mail_error(): string {
    return (string)($GLOBALS['SCORING_LAST_MAIL_ERROR'] ?? '');
}

function scoring_mail_from(): string {
    $email = defined('SCORING_MAIL_FROM') ? trim((string)SCORING_MAIL_FROM) : '';
    $name = defined('SCORING_MAIL_FROM_NAME') ? trim((string)SCORING_MAIL_FROM_NAME) : app_site_name();
    if ($email === '') {
        return '';
    }
    if ($name === '') {
        return $email;
    }
    $name = str_replace(['"', '<', '>'], '', $name);
    return $name . ' <' . $email . '>';
}

function scoring_html_email_shell(string $title, string $bodyHtml): string {
    return '<!doctype html><html><body style="margin:0;background:#edf7fc;font-family:Arial,Helvetica,sans-serif;color:#102436;">'
        . '<div style="max-width:640px;margin:0 auto;padding:24px;">'
        . '<div style="background:#ffffff;border:1px solid #bfd8e8;border-radius:8px;padding:22px;">'
        . '<h1 style="font-size:22px;line-height:1.25;margin:0 0 14px;color:#0b2033;">' . h($title) . '</h1>'
        . $bodyHtml
        . '<p style="margin:22px 0 0;color:#516779;font-size:13px;">' . h(app_site_name()) . '</p>'
        . '</div></div></body></html>';
}

function scoring_postmark_request(array $payload): bool {
    $token = defined('POSTMARK_SERVER_TOKEN') ? trim((string)POSTMARK_SERVER_TOKEN) : '';
    if ($token === '') {
        scoring_set_mail_error('Postmark server token is not configured.');
        return false;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        scoring_set_mail_error('Could not encode Postmark payload.');
        return false;
    }

    $url = 'https://api.postmarkapp.com/email';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Postmark-Server-Token: ' . $token,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        if ($response === false || $status < 200 || $status >= 300) {
            scoring_set_mail_error($curlError !== '' ? $curlError : ('Postmark HTTP ' . $status . ': ' . (string)$response));
            return false;
        }
        scoring_set_mail_error('');
        return true;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    if (function_exists('http_get_last_response_headers')) {
        $responseHeaders = http_get_last_response_headers();
        $responseHeaders = is_array($responseHeaders) ? $responseHeaders : [];
    } else {
        $definedVars = get_defined_vars();
        $responseHeaders = isset($definedVars['http_response_header']) && is_array($definedVars['http_response_header'])
            ? $definedVars['http_response_header']
            : [];
    }
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
    if ($response === false || $status < 200 || $status >= 300) {
        scoring_set_mail_error('Postmark HTTP ' . $status . ': ' . (string)$response);
        return false;
    }
    scoring_set_mail_error('');
    return true;
}

function scoring_send_email(string $to, string $subject, string $textBody, string $htmlBody = '', string $tag = 'scoring'): bool {
    $from = scoring_mail_from();
    if ($from === '') {
        scoring_set_mail_error('SCORING_MAIL_FROM is not configured.');
        return false;
    }

    $postmarkToken = defined('POSTMARK_SERVER_TOKEN') ? trim((string)POSTMARK_SERVER_TOKEN) : '';
    if ($postmarkToken !== '') {
        $payload = [
            'From' => $from,
            'To' => $to,
            'Subject' => $subject,
            'TextBody' => $textBody,
            'Tag' => $tag,
        ];
        if ($htmlBody !== '') {
            $payload['HtmlBody'] = $htmlBody;
        }
        $messageStream = defined('POSTMARK_MESSAGE_STREAM') ? trim((string)POSTMARK_MESSAGE_STREAM) : '';
        if ($messageStream !== '') {
            $payload['MessageStream'] = $messageStream;
        }
        return scoring_postmark_request($payload);
    }

    $headers = ['From: ' . $from];
    if ($htmlBody !== '') {
        $boundary = 'scoring-' . bin2hex(random_bytes(8));
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $textBody . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $htmlBody . "\r\n"
            . '--' . $boundary . "--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $textBody;
    }

    $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    scoring_set_mail_error($sent ? '' : 'PHP mail() returned false.');
    return $sent;
}

function scoring_send_magic_link(string $email, string $link): bool {
    $subject = app_site_name() . ' scorer login';
    $text = "Hallo,\n\nGebruik deze link om in te loggen als scorer:\n\n" . $link
        . "\n\nDeze link verloopt automatisch. Heb je deze link niet aangevraagd, dan kun je deze mail negeren.\n";
    $html = scoring_html_email_shell('Je scorer loginlink', ''
        . '<p style="margin:0 0 14px;">Hallo,</p>'
        . '<p style="margin:0 0 18px;">Met onderstaande knop log je tijdelijk in bij de competitie scoring.</p>'
        . '<p style="margin:0 0 18px;"><a href="' . h($link) . '" style="background:#0f6fa8;border-radius:6px;color:#ffffff;display:inline-block;font-weight:bold;padding:11px 16px;text-decoration:none;">Inloggen als scorer</a></p>'
        . '<p style="margin:0 0 12px;color:#516779;">Deze link verloopt automatisch. Heb je deze link niet aangevraagd, dan kun je deze mail negeren.</p>'
        . '<p style="margin:0;color:#516779;font-size:13px;">Werkt de knop niet? Open deze link:<br><a href="' . h($link) . '">' . h($link) . '</a></p>');
    return scoring_send_email($email, $subject, $text, $html, 'scorer-login');
}

function scoring_send_scorer_welcome_email(string $email, ?string $name = null): bool {
    $loginUrl = scoring_absolute_url('scoring/login.php');
    $greeting = trim((string)$name) !== '' ? 'Hallo ' . trim((string)$name) . ',' : 'Hallo,';
    $subject = 'Welkom bij de competitie scoring van ' . app_site_name();
    $text = $greeting . "\n\n"
        . "Je e-mailadres is toegevoegd als scorer voor " . app_site_name() . ".\n\n"
        . "Je kunt via deze pagina een tijdelijke loginlink aanvragen:\n" . $loginUrl . "\n\n"
        . "Na het inloggen kun je competities aanmaken, waypoints uploaden, taken instellen, IGC-tracklogs koppelen, resultaten controleren en publiceren.\n";
    $html = scoring_html_email_shell('Welkom als scorer', ''
        . '<p style="margin:0 0 14px;">' . h($greeting) . '</p>'
        . '<p style="margin:0 0 14px;">Je e-mailadres is toegevoegd als scorer voor ' . h(app_site_name()) . '.</p>'
        . '<p style="margin:0 0 18px;">Via de scoring omgeving kun je competities aanmaken, waypoints uploaden, taken instellen, IGC-tracklogs koppelen, resultaten controleren en publiceren.</p>'
        . '<p style="margin:0 0 18px;"><a href="' . h($loginUrl) . '" style="background:#0f6fa8;border-radius:6px;color:#ffffff;display:inline-block;font-weight:bold;padding:11px 16px;text-decoration:none;">Open de scoring omgeving</a></p>'
        . '<p style="margin:0;color:#516779;font-size:13px;">Je logt in door op die pagina een tijdelijke loginlink aan te vragen voor dit e-mailadres.</p>');
    return scoring_send_email($email, $subject, $text, $html, 'scorer-welcome');
}

function scoring_send_competition_buddy_email(string $email, string $competitionName, ?string $name = null, ?string $inviterName = null): bool {
    $loginUrl = scoring_absolute_url('scoring/login.php');
    $greeting = trim((string)$name) !== '' ? 'Hallo ' . trim((string)$name) . ',' : 'Hallo,';
    $inviter = trim((string)$inviterName);
    $subject = 'Je bent toegevoegd als scorer voor ' . $competitionName;
    $text = $greeting . "\n\n"
        . ($inviter !== '' ? $inviter . " heeft je toegevoegd als scorer voor:\n" : "Je bent toegevoegd als scorer voor:\n")
        . $competitionName . "\n\n"
        . "Je kunt via deze pagina een tijdelijke loginlink aanvragen:\n" . $loginUrl . "\n\n"
        . "Na het inloggen kun je deze competitie beheren, taken aanpassen, tracklogs koppelen, scoren en publiceren.\n";
    $html = scoring_html_email_shell('Toegevoegd als scorer', ''
        . '<p style="margin:0 0 14px;">' . h($greeting) . '</p>'
        . '<p style="margin:0 0 14px;">' . ($inviter !== '' ? h($inviter) . ' heeft je toegevoegd als scorer voor:' : 'Je bent toegevoegd als scorer voor:') . '</p>'
        . '<p style="margin:0 0 18px;font-weight:bold;">' . h($competitionName) . '</p>'
        . '<p style="margin:0 0 18px;">Na het inloggen kun je deze competitie beheren, taken aanpassen, tracklogs koppelen, scoren en publiceren.</p>'
        . '<p style="margin:0 0 18px;"><a href="' . h($loginUrl) . '" style="background:#0f6fa8;border-radius:6px;color:#ffffff;display:inline-block;font-weight:bold;padding:11px 16px;text-decoration:none;">Open de scoring omgeving</a></p>'
        . '<p style="margin:0;color:#516779;font-size:13px;">Je logt in door op die pagina een tijdelijke loginlink aan te vragen voor dit e-mailadres.</p>');
    return scoring_send_email($email, $subject, $text, $html, 'scorer-buddy');
}

function scoring_upload_root(): string {
    return __DIR__ . '/../public/uploads/scoring';
}

function scoring_public_upload_path(string $relative): string {
    return __DIR__ . '/../public/' . ltrim($relative, '/');
}

function scoring_safe_filename(string $name, string $fallbackExt = 'dat'): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext ?: $fallbackExt);
    return date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . ($ext ?: $fallbackExt);
}

function scoring_ensure_upload_dir(string $kind): array {
    $subdir = trim($kind, '/') . '/' . date('Y/m');
    $full = scoring_upload_root() . '/' . $subdir;
    if (!is_dir($full) && !@mkdir($full, 0755, true)) {
        throw new RuntimeException('Uploadmap kon niet worden aangemaakt.');
    }
    return [$full, 'uploads/scoring/' . $subdir];
}

function scoring_placeholder_email(string $pilotName, string $fileHash): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $pilotName), '-'));
    if ($slug === '') {
        $slug = 'pilot';
    }
    return substr($slug, 0, 40) . '+' . substr($fileHash, 0, 12) . '@scoring.local';
}

function scoring_is_placeholder_email(?string $email): bool {
    return is_string($email) && substr(strtolower($email), -14) === '@scoring.local';
}

function scoring_display_pilot_email(?string $email): string {
    return scoring_is_placeholder_email($email) ? 'geen e-mail' : (string)$email;
}

function scoring_table_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function scoring_table_index_exists(PDO $pdo, string $table, string $index): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function scoring_schema_change_is_duplicate(Throwable $e, array $mysqlCodes, array $sqlStates = []): bool {
    if (!$e instanceof PDOException) {
        return false;
    }
    $errorInfo = is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
    $driverCode = isset($errorInfo[1]) ? (int)$errorInfo[1] : 0;
    $sqlState = isset($errorInfo[0]) ? (string)$errorInfo[0] : (string)$e->getCode();
    return in_array($driverCode, $mysqlCodes, true) || in_array($sqlState, $sqlStates, true);
}

function scoring_exec_schema_change(PDO $pdo, string $sql, array $ignoreMysqlCodes = [], array $ignoreSqlStates = []): void {
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (scoring_schema_change_is_duplicate($e, $ignoreMysqlCodes, $ignoreSqlStates)) {
            return;
        }
        throw $e;
    }
}

function scoring_ensure_track_collection_tables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rankings_track_collection_profiles (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          display_name VARCHAR(160) NOT NULL,
          email VARCHAR(190) NOT NULL,
          email_verified_at DATETIME DEFAULT NULL,
          livetrack24_username VARCHAR(120) DEFAULT NULL,
          livetrack24_user_id INT UNSIGNED DEFAULT NULL,
          livetrack24_enabled TINYINT(1) NOT NULL DEFAULT 0,
          livetrack24_enabled_at DATETIME DEFAULT NULL,
          livetrack24_disabled_at DATETIME DEFAULT NULL,
          last_livetrack24_check_at DATETIME DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_rankings_track_collection_profiles_email (email),
          KEY idx_rankings_track_collection_profiles_lt24 (livetrack24_username),
          KEY idx_rankings_track_collection_profiles_enabled (livetrack24_enabled, livetrack24_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rankings_track_collection_login_tokens (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          profile_id INT UNSIGNED NOT NULL,
          token_hash CHAR(64) NOT NULL,
          expires_at DATETIME NOT NULL,
          used_at DATETIME DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_rankings_track_collection_login_tokens_hash (token_hash),
          KEY idx_rankings_track_collection_login_tokens_profile (profile_id),
          CONSTRAINT fk_rankings_track_collection_login_tokens_profile
            FOREIGN KEY (profile_id) REFERENCES rankings_track_collection_profiles(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source')) {
        scoring_exec_schema_change($pdo, "ALTER TABLE rankings_scoring_tracklogs ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'manual_upload' AFTER file_hash", [1060], ['42S21']);
    }
    if (!scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source_external_id')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_tracklogs ADD COLUMN source_external_id VARCHAR(190) DEFAULT NULL AFTER source', [1060], ['42S21']);
    }
    if (!scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source_url')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_tracklogs ADD COLUMN source_url VARCHAR(255) DEFAULT NULL AFTER source_external_id', [1060], ['42S21']);
    }
    if (!scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source_fetched_at')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_tracklogs ADD COLUMN source_fetched_at DATETIME DEFAULT NULL AFTER source_url', [1060], ['42S21']);
    }
    if (!scoring_table_index_exists($pdo, 'rankings_scoring_tracklogs', 'uq_rankings_scoring_tracklogs_source')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_tracklogs ADD UNIQUE KEY uq_rankings_scoring_tracklogs_source (source, source_external_id)', [1061], ['42000']);
    }
    if (scoring_table_index_exists($pdo, 'rankings_scoring_tracklogs', 'uq_rankings_scoring_tracklogs_hash_email')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_tracklogs DROP INDEX uq_rankings_scoring_tracklogs_hash_email', [1091], ['42000']);
    }
    if (!scoring_table_index_exists($pdo, 'rankings_scoring_tracklogs', 'idx_rankings_scoring_tracklogs_hash_email')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_tracklogs ADD KEY idx_rankings_scoring_tracklogs_hash_email (file_hash, pilot_email)', [1061], ['42000']);
    }
}

function scoring_track_collection_available(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM rankings_track_collection_profiles LIMIT 1');
        $pdo->query('SELECT 1 FROM rankings_track_collection_login_tokens LIMIT 1');
        return scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source');
    } catch (Throwable $e) {
        return false;
    }
}

function scoring_tracklog_source_columns_available(PDO $pdo): bool {
    return scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source')
        && scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source_external_id')
        && scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source_url')
        && scoring_table_column_exists($pdo, 'rankings_scoring_tracklogs', 'source_fetched_at');
}

function scoring_ensure_task_review_columns(PDO $pdo): void {
    if (!scoring_table_column_exists($pdo, 'rankings_scoring_task_flights', 'result_status')) {
        scoring_exec_schema_change($pdo, "ALTER TABLE rankings_scoring_task_flights ADD COLUMN result_status VARCHAR(30) NOT NULL DEFAULT 'track' AFTER pilot_email", [1060], ['42S21']);
    }
    if (!scoring_table_column_exists($pdo, 'rankings_scoring_task_flights', 'identity_reviewed')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_task_flights ADD COLUMN identity_reviewed TINYINT(1) NOT NULL DEFAULT 0 AFTER result_status', [1060], ['42S21']);
    }
    if (!scoring_table_index_exists($pdo, 'rankings_scoring_task_flights', 'idx_rankings_scoring_task_flights_status')) {
        scoring_exec_schema_change($pdo, 'ALTER TABLE rankings_scoring_task_flights ADD KEY idx_rankings_scoring_task_flights_status (task_id, result_status)', [1061], ['42000']);
    }
}

function scoring_task_review_status_available(PDO $pdo): bool {
    return scoring_table_column_exists($pdo, 'rankings_scoring_task_flights', 'result_status');
}

function scoring_task_identity_review_available(PDO $pdo): bool {
    return scoring_table_column_exists($pdo, 'rankings_scoring_task_flights', 'identity_reviewed');
}

function scoring_track_collection_magic_link_ttl_minutes(): int {
    if (defined('TRACK_COLLECTION_MAGIC_LINK_TTL_MINUTES')) {
        return max(5, (int)TRACK_COLLECTION_MAGIC_LINK_TTL_MINUTES);
    }
    return defined('SCORING_MAGIC_LINK_TTL_MINUTES') ? max(5, (int)SCORING_MAGIC_LINK_TTL_MINUTES) : 30;
}

function scoring_find_or_create_track_collection_profile(PDO $pdo, string $displayName, string $email): array {
    scoring_ensure_track_collection_tables($pdo);
    $displayName = trim($displayName);
    $email = scoring_normalize_email($email);
    if ($displayName === '') {
        throw new RuntimeException('Vul je naam in.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vul een geldig e-mailadres in.');
    }

    $stmt = $pdo->prepare('SELECT * FROM rankings_track_collection_profiles WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        return $profile;
    }

    $stmt = $pdo->prepare('INSERT INTO rankings_track_collection_profiles (display_name, email) VALUES (?, ?)');
    $stmt->execute([$displayName, $email]);
    $profileId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM rankings_track_collection_profiles WHERE id = ? LIMIT 1');
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        throw new RuntimeException('Profiel kon niet worden aangemaakt.');
    }
    return $profile;
}

function scoring_load_track_collection_profile(PDO $pdo, int $profileId): ?array {
    if ($profileId <= 0) {
        return null;
    }
    scoring_ensure_track_collection_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM rankings_track_collection_profiles WHERE id = ? LIMIT 1');
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    return $profile ?: null;
}

function scoring_create_track_collection_login_token(PDO $pdo, int $profileId): string {
    scoring_ensure_track_collection_tables($pdo);
    if ($profileId <= 0) {
        throw new RuntimeException('Profiel ontbreekt.');
    }
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('now', scoring_utc_timezone()))
        ->modify('+' . scoring_track_collection_magic_link_ttl_minutes() . ' minutes')
        ->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO rankings_track_collection_login_tokens (profile_id, token_hash, expires_at)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$profileId, hash('sha256', $token), $expires]);
    return $token;
}

function scoring_consume_track_collection_login_token(PDO $pdo, string $token): ?array {
    scoring_ensure_track_collection_tables($pdo);
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, p.*
         FROM rankings_track_collection_login_tokens t
         JOIN rankings_track_collection_profiles p ON p.id = t.profile_id
         WHERE t.token_hash = ?
           AND t.used_at IS NULL
           AND t.expires_at >= UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        return null;
    }
    $pdo->prepare('UPDATE rankings_track_collection_login_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([(int)$profile['token_id']]);
    $pdo->prepare(
        'UPDATE rankings_track_collection_profiles
         SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
         WHERE id = ?'
    )->execute([(int)$profile['id']]);
    unset($profile['token_id']);
    $profile['email_verified_at'] = $profile['email_verified_at'] ?: scoring_now_utc();
    return $profile;
}

function scoring_send_track_collection_magic_link(string $email, string $link): bool {
    $subject = app_site_name() . ' trackprofiel';
    $text = "Hallo,\n\nGebruik deze link om je trackprofiel te openen:\n\n" . $link
        . "\n\nHier kun je automatische LiveTrack24 trackcollectie aan- of uitzetten. Deze link verloopt automatisch.\n";
    $html = scoring_html_email_shell('Je trackprofiel', ''
        . '<p style="margin:0 0 14px;">Hallo,</p>'
        . '<p style="margin:0 0 18px;">Met onderstaande knop open je je trackprofiel voor competitie scoring.</p>'
        . '<p style="margin:0 0 18px;"><a href="' . h($link) . '" style="background:#0f6fa8;border-radius:6px;color:#ffffff;display:inline-block;font-weight:bold;padding:11px 16px;text-decoration:none;">Trackprofiel openen</a></p>'
        . '<p style="margin:0 0 12px;color:#516779;">Hier kun je automatische LiveTrack24 trackcollectie aan- of uitzetten. Deze link verloopt automatisch.</p>'
        . '<p style="margin:0;color:#516779;font-size:13px;">Werkt de knop niet? Open deze link:<br><a href="' . h($link) . '">' . h($link) . '</a></p>');
    return scoring_send_email($email, $subject, $text, $html, 'track-profile-login');
}

function scoring_http_get(string $url, int $timeoutSeconds = 15, string $accept = '*/*'): string {
    $userAgent = 'Mozilla/5.0 (compatible; RanglijstDeltavliegen/1.0; +https://www.ranglijstdeltavliegen.nl)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Kon externe URL niet voorbereiden.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = (string)curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        if ($body === false) {
            throw new RuntimeException('Kon externe URL niet ophalen' . ($error !== '' ? ': ' . $error : '') . '.');
        }
        if ($status >= 400) {
            throw new RuntimeException('Externe URL gaf HTTP ' . $status . '.');
        }
        return (string)$body;
    }

    $headers = [
        'User-Agent: ' . $userAgent,
        'Accept: ' . $accept,
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => $timeoutSeconds,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (function_exists('http_get_last_response_headers')) {
        $responseHeaders = http_get_last_response_headers();
        $responseHeaders = is_array($responseHeaders) ? $responseHeaders : [];
    } else {
        $definedVars = get_defined_vars();
        $responseHeaders = isset($definedVars['http_response_header']) && is_array($definedVars['http_response_header'])
            ? $definedVars['http_response_header']
            : [];
    }
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
    if ($body === false) {
        throw new RuntimeException('Kon externe URL niet ophalen.');
    }
    if ($status >= 400) {
        throw new RuntimeException('Externe URL gaf HTTP ' . $status . '.');
    }
    return $body;
}

function scoring_livetrack24_base_url(): string {
    return 'https://www.livetrack24.com';
}

function scoring_livetrack24_username_from_input(string $input): string {
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $input)) {
        $parts = parse_url($input);
        $path = isset($parts['path']) ? trim((string)$parts['path'], '/') : '';
        $segments = $path !== '' ? explode('/', $path) : [];
        $userIndex = array_search('user', $segments, true);
        if ($userIndex !== false && isset($segments[$userIndex + 1])) {
            $input = rawurldecode((string)$segments[$userIndex + 1]);
        } elseif (!empty($segments)) {
            $input = rawurldecode((string)end($segments));
        }
    }
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (strlen($input) > 120 || preg_match('/[\s\/?#&<>]/', $input)) {
        throw new RuntimeException('Vul een geldige LiveTrack24 gebruikersnaam of profiel-URL in.');
    }
    return $input;
}

function scoring_livetrack24_profile_url(string $username): string {
    return scoring_livetrack24_base_url() . '/user/' . rawurlencode($username);
}

function scoring_livetrack24_track_url(string $trackId): string {
    return scoring_livetrack24_base_url() . '/track/' . rawurlencode($trackId);
}

function scoring_livetrack24_find_username(string $username): ?array {
    $username = scoring_livetrack24_username_from_input($username);
    if ($username === '') {
        return null;
    }
    $url = scoring_livetrack24_base_url() . '/EXT_pilot_functions.php?op=findPilot&format=json&q=' . rawurlencode($username);
    $body = scoring_http_get($url, 12, 'application/json,text/plain,*/*');
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    foreach ($decoded as $candidate) {
        if (!is_array($candidate) || !isset($candidate['id'])) {
            continue;
        }
        $id = trim((string)$candidate['id']);
        if ($id !== '' && strcasecmp($id, $username) === 0) {
            return [
                'username' => $id,
                'label' => trim(strip_tags((string)($candidate['name'] ?? $id))),
            ];
        }
    }
    return null;
}

function scoring_livetrack24_list_public_tracks(string $username, string $fromDate, string $toDate): array {
    $username = scoring_livetrack24_username_from_input($username);
    if ($username === '') {
        return ['tracks' => [], 'user_id' => null, 'url' => ''];
    }
    $url = scoring_livetrack24_profile_url($username)
        . '/tracks/from/' . rawurlencode($fromDate)
        . '/to/' . rawurlencode($toDate);
    $html = scoring_http_get($url, 20, 'text/html,*/*');
    $trackIds = [];
    if (preg_match_all('/data-trackID=["\'](\d+)["\']/i', $html, $m)) {
        foreach ($m[1] as $trackId) {
            $trackIds[(string)$trackId] = (string)$trackId;
        }
    }
    if (preg_match_all('~href=["\']/track/(\d+)["\']~i', $html, $m)) {
        foreach ($m[1] as $trackId) {
            $trackIds[(string)$trackId] = (string)$trackId;
        }
    }

    $userId = null;
    if (preg_match('/data-userID=["\'](\d+)["\']/i', $html, $m)) {
        $userId = (int)$m[1];
    } elseif (preg_match('/id=["\']posted_on["\'][^>]*value=["\'](\d+)["\']/i', $html, $m)) {
        $userId = (int)$m[1];
    }

    return [
        'tracks' => array_values($trackIds),
        'user_id' => $userId,
        'url' => $url,
    ];
}

function scoring_livetrack24_fetch_track_info(string $trackId): ?array {
    $trackId = trim($trackId);
    if ($trackId === '' || !ctype_digit($trackId)) {
        return null;
    }
    $url = scoring_livetrack24_base_url() . '/EXT_flight_v3.php?op=flight_info&flightID=' . rawurlencode($trackId);
    $body = scoring_http_get($url, 20, 'application/json,text/plain,*/*');
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    if (trim((string)($decoded['username'] ?? '')) === '' || (string)($decoded['username'] ?? '') === 'null') {
        return null;
    }
    $points = $decoded['points'] ?? [];
    if (!is_array($points) || empty($points['lat']) || !is_array($points['lat'])) {
        return null;
    }
    return $decoded;
}

function scoring_livetrack24_download_igc_to_temp(string $trackId, string $username): string {
    $trackId = trim($trackId);
    if ($trackId === '' || !ctype_digit($trackId)) {
        throw new RuntimeException('Ongeldig LiveTrack24 track-ID.');
    }
    $url = scoring_livetrack24_base_url() . '/leo_live.php?op=igc&trackID=' . rawurlencode($trackId);
    if ($username !== '') {
        $url .= '&user=' . rawurlencode($username);
    }
    $body = scoring_http_get($url, 30, 'text/plain,application/octet-stream,*/*');
    if (trim($body) === '' || !preg_match('/^A/m', $body) || !preg_match('/^B\d{6}/m', $body)) {
        throw new RuntimeException('LiveTrack24 gaf geen bruikbaar IGC-bestand terug.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'lt24_');
    if ($tmp === false || @file_put_contents($tmp, $body) === false) {
        throw new RuntimeException('Tijdelijk LiveTrack24-bestand kon niet worden opgeslagen.');
    }
    return $tmp;
}

function scoring_livetrack24_track_matches_task(array $trackInfo, array $task, array $taskBbox): bool {
    $first = isset($trackInfo['firstPointTM']) ? (int)$trackInfo['firstPointTM'] : 0;
    $last = isset($trackInfo['lastPointTM']) ? (int)$trackInfo['lastPointTM'] : 0;
    if ($first <= 0 || $last <= 0) {
        return false;
    }
    $openTs = (new DateTimeImmutable((string)$task['window_open_at'], scoring_utc_timezone()))->getTimestamp();
    $closeTs = (new DateTimeImmutable((string)$task['window_close_at'], scoring_utc_timezone()))->getTimestamp();
    if ($first > $closeTs || $last < $openTs) {
        return false;
    }

    $minLat = isset($trackInfo['min_lat']) ? (float)$trackInfo['min_lat'] : 1000.0;
    $maxLat = isset($trackInfo['max_lat']) ? (float)$trackInfo['max_lat'] : -1000.0;
    $minLon = isset($trackInfo['min_lon']) ? (float)$trackInfo['min_lon'] : 1000.0;
    $maxLon = isset($trackInfo['max_lon']) ? (float)$trackInfo['max_lon'] : -1000.0;
    if ($minLat > 90 || $maxLat < -90 || $minLon > 180 || $maxLon < -180) {
        return false;
    }

    return $maxLat >= (float)$taskBbox['min_lat']
        && $minLat <= (float)$taskBbox['max_lat']
        && $maxLon >= (float)$taskBbox['min_lon']
        && $minLon <= (float)$taskBbox['max_lon'];
}

function scoring_find_tracklog_by_source(PDO $pdo, string $source, string $externalId): ?array {
    if (!scoring_tracklog_source_columns_available($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, pilot_name, pilot_email
         FROM rankings_scoring_tracklogs
         WHERE source = ? AND source_external_id = ?
         LIMIT 1'
    );
    $stmt->execute([$source, $externalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function scoring_link_tracklog_to_task(PDO $pdo, array $task, int $tracklogId, string $pilotName, string $pilotEmail): int {
    $insert = $pdo->prepare(
        'INSERT INTO rankings_scoring_task_flights (task_id, tracklog_id, pilot_name, pilot_email)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE pilot_name = VALUES(pilot_name), pilot_email = VALUES(pilot_email)'
    );
    $insert->execute([(int)$task['id'], $tracklogId, $pilotName, $pilotEmail]);

    $lookup = $pdo->prepare('SELECT id FROM rankings_scoring_task_flights WHERE task_id = ? AND tracklog_id = ? LIMIT 1');
    $lookup->execute([(int)$task['id'], $tracklogId]);
    $flightId = (int)$lookup->fetchColumn();
    if ($flightId > 0 && scoring_pilot_identities_available($pdo)) {
        scoring_assign_known_task_flight_identity($pdo, (int)$task['competition_id'], $flightId, $pilotName, $pilotEmail);
    }
    return $flightId;
}

function scoring_import_livetrack24_for_task(PDO $pdo, array $task, array $turnpoints): array {
    scoring_ensure_track_collection_tables($pdo);
    if (empty($turnpoints)) {
        throw new RuntimeException('Voeg taakpunten toe voordat je LiveTrack24 tracks zoekt.');
    }
    $bbox = scoring_task_bbox($turnpoints);
    $fromDate = (new DateTimeImmutable((string)$task['window_open_at'], scoring_utc_timezone()))
        ->modify('-1 day')
        ->format('Y-m-d');
    $toDate = (new DateTimeImmutable((string)$task['window_close_at'], scoring_utc_timezone()))
        ->modify('+1 day')
        ->format('Y-m-d');

    $stmt = $pdo->query(
        "SELECT *
         FROM rankings_track_collection_profiles
         WHERE livetrack24_enabled = 1
           AND email_verified_at IS NOT NULL
           AND livetrack24_username IS NOT NULL
           AND livetrack24_username <> ''
         ORDER BY display_name ASC"
    );
    $profiles = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $summary = [
        'profiles_checked' => 0,
        'tracks_seen' => 0,
        'candidates' => 0,
        'imported' => 0,
        'already_linked' => 0,
        'errors' => 0,
        'messages' => [],
    ];
    $seenTrackIds = [];

    foreach ($profiles as $profile) {
        $summary['profiles_checked']++;
        $profileId = (int)$profile['id'];
        $username = (string)$profile['livetrack24_username'];
        try {
            $list = scoring_livetrack24_list_public_tracks($username, $fromDate, $toDate);
            if (!empty($list['user_id'])) {
                $pdo->prepare('UPDATE rankings_track_collection_profiles SET livetrack24_user_id = ? WHERE id = ?')
                    ->execute([(int)$list['user_id'], $profileId]);
            }
            foreach ($list['tracks'] as $trackId) {
                $trackId = (string)$trackId;
                if (isset($seenTrackIds[$trackId])) {
                    continue;
                }
                $seenTrackIds[$trackId] = true;
                $summary['tracks_seen']++;

                $trackInfo = scoring_livetrack24_fetch_track_info($trackId);
                if (!$trackInfo || !scoring_livetrack24_track_matches_task($trackInfo, $task, $bbox)) {
                    continue;
                }
                $summary['candidates']++;

                $tracklog = scoring_find_tracklog_by_source($pdo, 'livetrack24', $trackId);
                $wasImported = false;
                if (!$tracklog) {
                    $tmpPath = null;
                    try {
                        $tmpPath = scoring_livetrack24_download_igc_to_temp($trackId, $username);
                        $tracklogId = scoring_store_tracklog_file(
                            $pdo,
                            $tmpPath,
                            'livetrack24-' . $username . '-' . $trackId . '.igc',
                            (string)$profile['display_name'],
                            (string)$profile['email'],
                            [
                                'source' => 'livetrack24',
                                'external_id' => $trackId,
                                'url' => scoring_livetrack24_track_url($trackId),
                            ]
                        );
                        $tracklog = [
                            'id' => $tracklogId,
                            'pilot_name' => (string)$profile['display_name'],
                            'pilot_email' => (string)$profile['email'],
                        ];
                        $wasImported = true;
                        $summary['imported']++;
                    } finally {
                        if ($tmpPath && is_file($tmpPath)) {
                            @unlink($tmpPath);
                        }
                    }
                }

                $flightId = scoring_link_tracklog_to_task(
                    $pdo,
                    $task,
                    (int)$tracklog['id'],
                    (string)$tracklog['pilot_name'],
                    (string)$tracklog['pilot_email']
                );
                if ($flightId > 0 && !$wasImported) {
                    $summary['already_linked']++;
                }
            }
            $pdo->prepare('UPDATE rankings_track_collection_profiles SET last_livetrack24_check_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$profileId]);
        } catch (Throwable $e) {
            $summary['errors']++;
            $summary['messages'][] = (string)$profile['display_name'] . ': ' . $e->getMessage();
        }
    }

    return $summary;
}

function scoring_pilot_identities_available(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $pdo->query('SELECT 1 FROM rankings_scoring_pilot_identities LIMIT 1');
        $pdo->query('SELECT 1 FROM rankings_scoring_pilot_identity_aliases LIMIT 1');
        $pdo->query('SELECT 1 FROM rankings_scoring_task_flight_identities LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function scoring_pilot_identity_normalized_name(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function scoring_pilot_identity_normalized_email(?string $email): string {
    $email = scoring_normalize_email((string)$email);
    return ($email !== '' && !scoring_is_placeholder_email($email)) ? $email : '';
}

function scoring_load_competition_pilot_identities(PDO $pdo, int $competitionId): array {
    if ($competitionId <= 0 || !scoring_pilot_identities_available($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, display_name, primary_email
         FROM rankings_scoring_pilot_identities
         WHERE competition_id = ?
         ORDER BY display_name ASC, id ASC'
    );
    $stmt->execute([$competitionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scoring_pilot_identity_option_label(string $name, ?string $email): string {
    $label = trim($name);
    $email = scoring_pilot_identity_normalized_email($email);
    if ($email !== '') {
        $label .= ' - ' . $email;
    }
    return $label;
}

function scoring_load_competition_pilot_identifier_options(PDO $pdo, array $currentTask): array {
    $competitionId = (int)($currentTask['competition_id'] ?? 0);
    $taskId = (int)($currentTask['id'] ?? 0);
    $taskDate = (string)($currentTask['task_date'] ?? '');
    if ($competitionId <= 0 || $taskId <= 0 || $taskDate === '' || !scoring_pilot_identities_available($pdo)) {
        return [];
    }

    $options = [];
    foreach (scoring_load_competition_pilot_identities($pdo, $competitionId) as $identity) {
        $id = (int)$identity['id'];
        if ($id <= 0) {
            continue;
        }
        $options['identity:' . $id] = [
            'value' => 'identity:' . $id,
            'label' => scoring_pilot_identity_option_label((string)$identity['display_name'], $identity['primary_email'] ?? null),
            'normalized_name' => scoring_pilot_identity_normalized_name((string)$identity['display_name']),
            'normalized_email' => scoring_pilot_identity_normalized_email($identity['primary_email'] ?? null),
            'source' => 'identity',
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT f.id AS flight_id, f.pilot_name, f.pilot_email,
                fi.identity_id,
                pi.display_name AS identity_display_name,
                pi.primary_email AS identity_primary_email,
                t.task_date, t.id AS task_id
         FROM rankings_scoring_task_flights f
         JOIN rankings_scoring_tasks t ON t.id = f.task_id
         LEFT JOIN rankings_scoring_task_flight_identities fi ON fi.flight_id = f.id
         LEFT JOIN rankings_scoring_pilot_identities pi ON pi.id = fi.identity_id
         WHERE t.competition_id = ?
           AND (t.task_date < ? OR (t.task_date = ? AND t.id < ?))
         ORDER BY t.task_date DESC, t.id DESC, f.pilot_name ASC"
    );
    $stmt->execute([$competitionId, $taskDate, $taskDate, $taskId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $identityId = (int)($row['identity_id'] ?? 0);
        if ($identityId > 0) {
            $key = 'identity:' . $identityId;
            if (!isset($options[$key])) {
                $name = (string)($row['identity_display_name'] ?: $row['pilot_name']);
                $email = $row['identity_primary_email'] ?: ($row['pilot_email'] ?? null);
                $options[$key] = [
                    'value' => $key,
                    'label' => scoring_pilot_identity_option_label($name, $email),
                    'normalized_name' => scoring_pilot_identity_normalized_name($name),
                    'normalized_email' => scoring_pilot_identity_normalized_email($email),
                    'source' => 'identity',
                ];
            }
            continue;
        }

        $normalizedName = scoring_pilot_identity_normalized_name((string)$row['pilot_name']);
        $normalizedEmail = scoring_pilot_identity_normalized_email($row['pilot_email'] ?? null);
        if ($normalizedName === '') {
            continue;
        }
        $key = 'previous:' . $normalizedName . '|' . $normalizedEmail;
        if (!isset($options[$key])) {
            $options[$key] = [
                'value' => 'previous:' . (int)$row['flight_id'],
                'label' => scoring_pilot_identity_option_label((string)$row['pilot_name'], $row['pilot_email'] ?? null),
                'normalized_name' => $normalizedName,
                'normalized_email' => $normalizedEmail,
                'source' => 'previous',
            ];
        }
    }

    usort($options, static function ($a, $b) {
        return strcasecmp((string)$a['label'], (string)$b['label']);
    });
    return array_values($options);
}

function scoring_suggest_competition_pilot_identifier(array $options, string $pilotName, ?string $pilotEmail = null): string {
    $normalizedName = scoring_pilot_identity_normalized_name($pilotName);
    $normalizedEmail = scoring_pilot_identity_normalized_email($pilotEmail);
    if ($normalizedEmail !== '') {
        foreach ($options as $option) {
            if (($option['normalized_email'] ?? '') === $normalizedEmail) {
                return (string)$option['value'];
            }
        }
    }
    if ($normalizedName !== '') {
        foreach ($options as $option) {
            if (($option['normalized_name'] ?? '') === $normalizedName) {
                return (string)$option['value'];
            }
        }
    }
    return 'new';
}

function scoring_find_competition_pilot_identity_id(PDO $pdo, int $competitionId, string $pilotName, ?string $pilotEmail = null): ?int {
    if ($competitionId <= 0 || !scoring_pilot_identities_available($pdo)) {
        return null;
    }
    $normalizedName = scoring_pilot_identity_normalized_name($pilotName);
    $normalizedEmail = scoring_pilot_identity_normalized_email($pilotEmail);

    if ($normalizedEmail !== '') {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM rankings_scoring_pilot_identities
             WHERE competition_id = ? AND primary_email = ?
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([$competitionId, $normalizedEmail]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $stmt = $pdo->prepare(
            'SELECT identity_id
             FROM rankings_scoring_pilot_identity_aliases
             WHERE competition_id = ? AND normalized_email = ?
             ORDER BY last_seen_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$competitionId, $normalizedEmail]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }

    if ($normalizedName === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT identity_id
         FROM rankings_scoring_pilot_identity_aliases
         WHERE competition_id = ? AND normalized_name = ? AND normalized_email = ?
         ORDER BY last_seen_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$competitionId, $normalizedName, $normalizedEmail]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $stmt = $pdo->prepare(
        'SELECT identity_id
         FROM rankings_scoring_pilot_identity_aliases
         WHERE competition_id = ? AND normalized_name = ?
         ORDER BY last_seen_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$competitionId, $normalizedName]);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
}

function scoring_upsert_pilot_identity_alias(PDO $pdo, int $competitionId, int $identityId, string $pilotName, ?string $pilotEmail = null): void {
    if ($competitionId <= 0 || $identityId <= 0 || !scoring_pilot_identities_available($pdo)) {
        return;
    }
    $pilotName = trim($pilotName);
    if ($pilotName === '') {
        return;
    }
    $email = scoring_pilot_identity_normalized_email($pilotEmail);
    $stmt = $pdo->prepare(
        'INSERT INTO rankings_scoring_pilot_identity_aliases
         (competition_id, identity_id, pilot_name, pilot_email, normalized_name, normalized_email)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           identity_id = VALUES(identity_id),
           pilot_name = VALUES(pilot_name),
           pilot_email = VALUES(pilot_email),
           last_seen_at = NOW()'
    );
    $stmt->execute([
        $competitionId,
        $identityId,
        $pilotName,
        $email !== '' ? $email : null,
        scoring_pilot_identity_normalized_name($pilotName),
        $email,
    ]);
    scoring_assign_matching_competition_flights_to_identity($pdo, $competitionId, $identityId, $pilotName, $email !== '' ? $email : null);
}

function scoring_assign_matching_competition_flights_to_identity(PDO $pdo, int $competitionId, int $identityId, string $pilotName, ?string $pilotEmail = null): void {
    if ($competitionId <= 0 || $identityId <= 0 || !scoring_pilot_identities_available($pdo)) {
        return;
    }
    $normalizedName = scoring_pilot_identity_normalized_name($pilotName);
    if ($normalizedName === '') {
        return;
    }
    $normalizedEmail = scoring_pilot_identity_normalized_email($pilotEmail);
    $stmt = $pdo->prepare(
        'SELECT f.id, f.pilot_name, f.pilot_email
         FROM rankings_scoring_task_flights f
         JOIN rankings_scoring_tasks t ON t.id = f.task_id
         WHERE t.competition_id = ?'
    );
    $stmt->execute([$competitionId]);
    $insert = $pdo->prepare(
        'INSERT INTO rankings_scoring_task_flight_identities (flight_id, identity_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE identity_id = VALUES(identity_id), updated_at = NOW()'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $flight) {
        if (scoring_pilot_identity_normalized_name((string)$flight['pilot_name']) !== $normalizedName) {
            continue;
        }
        if (scoring_pilot_identity_normalized_email($flight['pilot_email'] ?? null) !== $normalizedEmail) {
            continue;
        }
        $insert->execute([(int)$flight['id'], $identityId]);
    }
}

function scoring_create_competition_pilot_identity(PDO $pdo, int $competitionId, string $pilotName, ?string $pilotEmail = null): int {
    if ($competitionId <= 0 || !scoring_pilot_identities_available($pdo)) {
        return 0;
    }
    $pilotName = trim($pilotName);
    if ($pilotName === '') {
        throw new RuntimeException('Vul een pilotnaam in.');
    }
    $email = scoring_pilot_identity_normalized_email($pilotEmail);
    $stmt = $pdo->prepare(
        'INSERT INTO rankings_scoring_pilot_identities (competition_id, display_name, primary_email)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$competitionId, $pilotName, $email !== '' ? $email : null]);
    $identityId = (int)$pdo->lastInsertId();
    scoring_upsert_pilot_identity_alias($pdo, $competitionId, $identityId, $pilotName, $email !== '' ? $email : null);
    return $identityId;
}

function scoring_assign_task_flight_identity(PDO $pdo, int $competitionId, int $flightId, int $identityId, string $pilotName, ?string $pilotEmail = null): int {
    if ($competitionId <= 0 || $flightId <= 0 || !scoring_pilot_identities_available($pdo)) {
        return 0;
    }
    if ($identityId <= 0) {
        $identityId = scoring_create_competition_pilot_identity($pdo, $competitionId, $pilotName, $pilotEmail);
        if ($identityId <= 0) {
            return 0;
        }
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, primary_email
             FROM rankings_scoring_pilot_identities
             WHERE id = ? AND competition_id = ?
             LIMIT 1'
        );
        $stmt->execute([$identityId, $competitionId]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$identity) {
            throw new RuntimeException('Ongeldige pilot-identiteit.');
        }
        $email = scoring_pilot_identity_normalized_email($pilotEmail);
        if ($email !== '' && trim((string)($identity['primary_email'] ?? '')) === '') {
            $pdo->prepare('UPDATE rankings_scoring_pilot_identities SET primary_email = ? WHERE id = ?')->execute([$email, $identityId]);
        }
        scoring_upsert_pilot_identity_alias($pdo, $competitionId, $identityId, $pilotName, $email !== '' ? $email : null);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO rankings_scoring_task_flight_identities (flight_id, identity_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE identity_id = VALUES(identity_id), updated_at = NOW()'
    );
    $stmt->execute([$flightId, $identityId]);
    return $identityId;
}

function scoring_assign_task_flight_identifier_selection(PDO $pdo, int $competitionId, int $flightId, string $selection, string $pilotName, ?string $pilotEmail = null): int {
    if ($selection === '' || $selection === 'new') {
        return scoring_assign_task_flight_identity($pdo, $competitionId, $flightId, 0, $pilotName, $pilotEmail);
    }
    if (ctype_digit($selection)) {
        return scoring_assign_task_flight_identity($pdo, $competitionId, $flightId, (int)$selection, $pilotName, $pilotEmail);
    }
    if (strpos($selection, 'identity:') === 0) {
        return scoring_assign_task_flight_identity($pdo, $competitionId, $flightId, (int)substr($selection, 9), $pilotName, $pilotEmail);
    }
    if (strpos($selection, 'previous:') !== 0) {
        throw new RuntimeException('Ongeldige pilot-identifier.');
    }

    $previousFlightId = (int)substr($selection, 9);
    $stmt = $pdo->prepare(
        'SELECT f.id, f.pilot_name, f.pilot_email, fi.identity_id
         FROM rankings_scoring_task_flights f
         JOIN rankings_scoring_tasks t ON t.id = f.task_id
         LEFT JOIN rankings_scoring_task_flight_identities fi ON fi.flight_id = f.id
         WHERE f.id = ? AND t.competition_id = ?
         LIMIT 1'
    );
    $stmt->execute([$previousFlightId, $competitionId]);
    $previous = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$previous) {
        throw new RuntimeException('Ongeldige vorige pilot-identifier.');
    }

    $identityId = (int)($previous['identity_id'] ?? 0);
    if ($identityId <= 0) {
        $identityId = scoring_assign_task_flight_identity(
            $pdo,
            $competitionId,
            $previousFlightId,
            0,
            (string)$previous['pilot_name'],
            $previous['pilot_email'] ?? null
        );
    }
    return scoring_assign_task_flight_identity($pdo, $competitionId, $flightId, $identityId, $pilotName, $pilotEmail);
}

function scoring_assign_known_task_flight_identity(PDO $pdo, int $competitionId, int $flightId, string $pilotName, ?string $pilotEmail = null): ?int {
    $identityId = scoring_find_competition_pilot_identity_id($pdo, $competitionId, $pilotName, $pilotEmail);
    if (!$identityId) {
        return null;
    }
    scoring_assign_task_flight_identity($pdo, $competitionId, $flightId, $identityId, $pilotName, $pilotEmail);
    return $identityId;
}

function scoring_insert_tracklog_record(
    PDO $pdo,
    string $pilotName,
    string $pilotEmail,
    string $originalName,
    string $relativePath,
    string $hash,
    array $igc,
    array $source = []
): int {
    $sourceName = trim((string)($source['source'] ?? 'manual_upload'));
    if ($sourceName === '') {
        $sourceName = 'manual_upload';
    }
    $sourceExternalId = isset($source['external_id']) && trim((string)$source['external_id']) !== ''
        ? trim((string)$source['external_id'])
        : null;
    $sourceUrl = isset($source['url']) && trim((string)$source['url']) !== ''
        ? trim((string)$source['url'])
        : null;
    $hasSourceColumns = scoring_tracklog_source_columns_available($pdo);

    $columns = [
        'pilot_name',
        'pilot_email',
        'original_filename',
        'storage_path',
        'file_hash',
    ];
    $values = [
        $pilotName,
        $pilotEmail,
        $originalName,
        $relativePath,
        $hash,
    ];
    $updates = [
        'id = LAST_INSERT_ID(id)',
        'pilot_name = VALUES(pilot_name)',
        'original_filename = VALUES(original_filename)',
        'storage_path = VALUES(storage_path)',
        'first_fix_at = VALUES(first_fix_at)',
        'last_fix_at = VALUES(last_fix_at)',
        'min_lat = VALUES(min_lat)',
        'max_lat = VALUES(max_lat)',
        'min_lon = VALUES(min_lon)',
        'max_lon = VALUES(max_lon)',
        'fix_count = VALUES(fix_count)',
        'uploaded_at = NOW()',
    ];

    if ($hasSourceColumns) {
        $columns[] = 'source';
        $columns[] = 'source_external_id';
        $columns[] = 'source_url';
        $columns[] = 'source_fetched_at';
        $values[] = $sourceName;
        $values[] = $sourceExternalId;
        $values[] = $sourceUrl;
        $values[] = $sourceName === 'manual_upload' ? null : scoring_now_utc();
        if ($sourceExternalId !== null) {
            $updates[] = 'source = VALUES(source)';
            $updates[] = 'source_external_id = VALUES(source_external_id)';
            $updates[] = 'source_url = VALUES(source_url)';
            $updates[] = 'source_fetched_at = VALUES(source_fetched_at)';
        }
    }

    $columns = array_merge($columns, [
        'first_fix_at',
        'last_fix_at',
        'min_lat',
        'max_lat',
        'min_lon',
        'max_lon',
        'fix_count',
    ]);
    $values = array_merge($values, [
        $igc['first_fix_at'],
        $igc['last_fix_at'],
        $igc['min_lat'],
        $igc['max_lat'],
        $igc['min_lon'],
        $igc['max_lon'],
        $igc['fix_count'],
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO rankings_scoring_tracklogs
         (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')
         ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
    );
    $stmt->execute($values);

    $tracklogId = (int)$pdo->lastInsertId();
    if ($tracklogId > 0) {
        return $tracklogId;
    }

    if ($hasSourceColumns && $sourceExternalId !== null) {
        $lookup = $pdo->prepare(
            'SELECT id FROM rankings_scoring_tracklogs
             WHERE source = ? AND source_external_id = ?
             LIMIT 1'
        );
        $lookup->execute([$sourceName, $sourceExternalId]);
        $tracklogId = (int)$lookup->fetchColumn();
        if ($tracklogId > 0) {
            return $tracklogId;
        }
    }

    $lookup = $pdo->prepare('SELECT id FROM rankings_scoring_tracklogs WHERE file_hash = ? AND pilot_email = ? LIMIT 1');
    $lookup->execute([$hash, $pilotEmail]);
    $tracklogId = (int)$lookup->fetchColumn();
    if ($tracklogId <= 0) {
        throw new RuntimeException('Tracklog is opgeslagen, maar kon niet worden teruggevonden.');
    }
    return $tracklogId;
}

function scoring_store_tracklog_file(
    PDO $pdo,
    string $sourcePath,
    string $originalName,
    string $pilotName,
    ?string $pilotEmail = null,
    array $source = []
): int {
    $pilotName = trim($pilotName);
    if ($pilotName === '') {
        throw new RuntimeException('Vul een pilotnaam in.');
    }
    if ($sourcePath === '' || !is_file($sourcePath)) {
        throw new RuntimeException('Tracklogbestand is niet beschikbaar.');
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'igc') {
        throw new RuntimeException('Upload een bestand met extensie .igc.');
    }

    $igc = scoring_parse_igc_file($sourcePath);
    $hash = hash_file('sha256', $sourcePath);
    $email = scoring_normalize_email((string)$pilotEmail);
    if ($email === '') {
        $email = scoring_placeholder_email($pilotName, $hash);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vul een geldig e-mailadres in of laat het leeg.');
    }

    [$dir, $urlDir] = scoring_ensure_upload_dir('tracklogs');
    $filename = scoring_safe_filename($originalName, 'igc');
    $destination = $dir . '/' . $filename;
    if (!@copy($sourcePath, $destination)) {
        throw new RuntimeException('Tracklog opslaan mislukt.');
    }
    $relativePath = $urlDir . '/' . $filename;

    return scoring_insert_tracklog_record($pdo, $pilotName, $email, $originalName, $relativePath, $hash, $igc, $source);
}

function scoring_store_tracklog_upload(PDO $pdo, array $file, string $pilotName, ?string $pilotEmail = null): int {
    $pilotName = trim($pilotName);
    if ($pilotName === '') {
        throw new RuntimeException('Vul een pilotnaam in.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Upload een IGC-bestand.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Uploadfout: code ' . (int)$file['error']);
    }

    $maxMb = defined('SCORING_UPLOAD_MAX_MB') ? (int)SCORING_UPLOAD_MAX_MB : 12;
    if (($file['size'] ?? 0) > $maxMb * 1024 * 1024) {
        throw new RuntimeException('Bestand is te groot (max ' . $maxMb . ' MB).');
    }

    $originalName = (string)($file['name'] ?? 'tracklog.igc');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'igc') {
        throw new RuntimeException('Upload een bestand met extensie .igc.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('Uploadbestand is niet beschikbaar.');
    }

    $igc = scoring_parse_igc_file($tmpName);
    $hash = hash_file('sha256', $tmpName);
    $email = scoring_normalize_email((string)$pilotEmail);
    if ($email === '') {
        $email = scoring_placeholder_email($pilotName, $hash);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vul een geldig e-mailadres in of laat het leeg.');
    }

    [$dir, $urlDir] = scoring_ensure_upload_dir('tracklogs');
    $filename = scoring_safe_filename($originalName, 'igc');
    $relativePath = $urlDir . '/' . $filename;
    if (!@move_uploaded_file($tmpName, $dir . '/' . $filename)) {
        throw new RuntimeException('Tracklog opslaan mislukt.');
    }

    return scoring_insert_tracklog_record($pdo, $pilotName, $email, $originalName, $relativePath, $hash, $igc);
}

function scoring_manual_minimum_filename(): string {
    return 'manual-minimum-distance';
}

function scoring_manual_status_filename(string $status): string {
    $status = scoring_normalize_task_flight_result_status($status);
    if ($status === 'minimum_distance') {
        return scoring_manual_minimum_filename();
    }
    if ($status === 'dnf') {
        return 'manual-dnf';
    }
    if ($status === 'abs') {
        return 'manual-abs';
    }
    return 'manual-review-entry';
}

function scoring_normalize_task_flight_result_status(?string $status): string {
    $status = strtolower(trim((string)$status));
    $allowed = ['track', 'minimum_distance', 'dnf', 'abs', 'alternate'];
    return in_array($status, $allowed, true) ? $status : 'track';
}

function scoring_task_flight_result_status(array $flight): string {
    $status = scoring_normalize_task_flight_result_status($flight['result_status'] ?? 'track');
    if ($status !== 'track') {
        return $status;
    }
    $filename = (string)($flight['original_filename'] ?? '');
    if ($filename === scoring_manual_minimum_filename()) {
        return 'minimum_distance';
    }
    if ($filename === scoring_manual_status_filename('dnf')) {
        return 'dnf';
    }
    if ($filename === scoring_manual_status_filename('abs')) {
        return 'abs';
    }
    return 'track';
}

function scoring_task_flight_is_track_candidate(array $flight): bool {
    $status = scoring_task_flight_result_status($flight);
    if ($status !== 'track' && $status !== 'alternate') {
        return false;
    }
    if (array_key_exists('storage_path', $flight)) {
        return trim((string)($flight['storage_path'] ?? '')) !== '';
    }
    return true;
}

function scoring_task_flight_status_label(string $status): string {
    $labels = [
        'track' => 'Tracklog',
        'minimum_distance' => 'Minimumafstand',
        'dnf' => 'DNF',
        'abs' => 'ABS',
        'alternate' => 'Alternatief',
    ];
    return $labels[scoring_normalize_task_flight_result_status($status)] ?? 'Tracklog';
}

function scoring_is_manual_minimum_tracklog(array $tracklog): bool {
    return scoring_task_flight_result_status($tracklog) === 'minimum_distance'
        || ((string)($tracklog['original_filename'] ?? '') === scoring_manual_minimum_filename()
            && trim((string)($tracklog['storage_path'] ?? '')) === '');
}

function scoring_add_manual_minimum_flight(PDO $pdo, array $task, string $pilotName, ?string $pilotEmail = null): int {
    return scoring_add_manual_status_flight($pdo, $task, 'minimum_distance', $pilotName, $pilotEmail);
}

function scoring_add_manual_status_flight(PDO $pdo, array $task, string $status, string $pilotName, ?string $pilotEmail = null): int {
    scoring_ensure_task_review_columns($pdo);
    $status = scoring_normalize_task_flight_result_status($status);
    if (!in_array($status, ['minimum_distance', 'dnf', 'abs'], true)) {
        throw new RuntimeException('Kies minimumafstand, DNF of ABS.');
    }
    $pilotName = trim($pilotName);
    if ($pilotName === '') {
        throw new RuntimeException('Vul een pilotnaam in.');
    }
    $email = scoring_normalize_email((string)$pilotEmail);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vul een geldig e-mailadres in of laat het leeg.');
    }

    $hash = hash('sha256', 'manual-' . $status . '|' . (int)$task['id'] . '|' . $pilotName . '|' . $email . '|' . bin2hex(random_bytes(16)));
    if ($email === '') {
        $email = scoring_placeholder_email($pilotName, $hash);
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO rankings_scoring_tracklogs
             (pilot_name, pilot_email, original_filename, storage_path, file_hash,
              first_fix_at, last_fix_at, min_lat, max_lat, min_lon, max_lon, fix_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $pilotName,
            $email,
            scoring_manual_status_filename($status),
            '',
            $hash,
            '2000-01-01 00:00:00',
            '2000-01-01 00:00:00',
            0,
            0,
            0,
            0,
            0,
        ]);
        $tracklogId = (int)$pdo->lastInsertId();
        if ($tracklogId <= 0) {
            throw new RuntimeException('Minimumafstand kon niet worden opgeslagen.');
        }

        $distance = $status === 'minimum_distance' ? max(0.0, (float)$task['minimum_distance_km']) : null;
        $evaluation = $status === 'minimum_distance'
            ? scoring_manual_minimum_evaluation($task)
            : ($status === 'dnf' ? scoring_manual_dnf_evaluation() : scoring_manual_abs_evaluation());
        $isExcluded = $status === 'abs' ? 1 : 0;
        $excludeReason = $status === 'abs' ? 'ABS' : null;

        $insert = $pdo->prepare(
            'INSERT INTO rankings_scoring_task_flights
             (task_id, tracklog_id, pilot_name, pilot_email, result_status, is_excluded, exclude_reason, distance_km, reached_ess, reached_goal, evaluation_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
        );
        $insert->execute([
            (int)$task['id'],
            $tracklogId,
            $pilotName,
            $email,
            $status,
            $isExcluded,
            $excludeReason,
            $distance,
            json_encode($evaluation, JSON_UNESCAPED_UNICODE),
        ]);

        $flightId = (int)$pdo->lastInsertId();
        if ($startedTransaction) {
            $pdo->commit();
        }
        return $flightId;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function scoring_decimal_or_null($value): ?float {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : null;
}

function scoring_parse_coordinate($value, bool $isLongitude): ?float {
    $raw = strtoupper(trim((string)$value));
    if ($raw === '') {
        return null;
    }
    $sign = 1.0;
    if (strpos($raw, 'S') !== false || strpos($raw, 'W') !== false) {
        $sign = -1.0;
    }
    $raw = str_replace(['N', 'S', 'E', 'W', '+'], '', $raw);
    $raw = trim($raw);
    if ($raw !== '' && $raw[0] === '-') {
        $sign = -1.0;
        $raw = ltrim($raw, '-');
    }
    $raw = str_replace(',', '.', $raw);

    if (preg_match('/^(\d{1,3})[:\s](\d+(?:\.\d+)?)(?:[:\s](\d+(?:\.\d+)?))?$/', $raw, $m)) {
        $deg = (float)$m[1];
        $min = (float)$m[2];
        $sec = isset($m[3]) ? (float)$m[3] : 0.0;
        return $sign * ($deg + ($min / 60.0) + ($sec / 3600.0));
    }

    if (is_numeric($raw)) {
        $num = (float)$raw;
        $abs = abs($num);
        $degDigits = $isLongitude ? 3 : 2;
        if ($abs > ($isLongitude ? 180.0 : 90.0)) {
            $text = preg_replace('/\D/', '', (string)$raw);
            if (strlen($text) >= $degDigits + 2) {
                $deg = (float)substr($text, 0, $degDigits);
                $minutes = (float)substr($text, $degDigits) / 1000.0;
                return $sign * ($deg + ($minutes / 60.0));
            }
        }
        return $sign * $abs;
    }

    if (preg_match('/^(\d{' . ($isLongitude ? '3' : '2') . '})(\d{2}(?:\.\d+)?)$/', $raw, $m)) {
        return $sign * ((float)$m[1] + ((float)$m[2] / 60.0));
    }

    return null;
}

function scoring_parse_elevation($value): ?float {
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return null;
    }
    $value = str_replace(',', '.', $value);
    if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
        return (float)$m[0];
    }
    return null;
}

function scoring_dms_tokens_to_decimal(string $hemisphere, $degrees, $minutes, $seconds): ?float {
    $hemisphere = strtoupper(trim($hemisphere));
    if (!in_array($hemisphere, ['N', 'S', 'E', 'W'], true)) {
        return null;
    }
    if (!is_numeric($degrees) || !is_numeric($minutes) || !is_numeric($seconds)) {
        return null;
    }
    $value = abs((float)$degrees) + (abs((float)$minutes) / 60.0) + (abs((float)$seconds) / 3600.0);
    if ($hemisphere === 'S' || $hemisphere === 'W') {
        $value *= -1.0;
    }
    return $value;
}

function scoring_utm_to_latlon(int $zone, string $zoneLetter, float $easting, float $northing): ?array {
    if ($zone < 1 || $zone > 60 || $easting <= 0 || $northing <= 0) {
        return null;
    }

    $a = 6378137.0;
    $eccSquared = 0.00669438;
    $k0 = 0.9996;
    $eccPrimeSquared = $eccSquared / (1.0 - $eccSquared);
    $e1 = (1.0 - sqrt(1.0 - $eccSquared)) / (1.0 + sqrt(1.0 - $eccSquared));

    $x = $easting - 500000.0;
    $y = $northing;
    $zoneLetter = strtoupper($zoneLetter);
    if ($zoneLetter !== '' && $zoneLetter < 'N') {
        $y -= 10000000.0;
    }

    $longOrigin = (($zone - 1) * 6) - 180 + 3;
    $m = $y / $k0;
    $mu = $m / ($a * (1.0 - ($eccSquared / 4.0) - (3.0 * $eccSquared * $eccSquared / 64.0) - (5.0 * $eccSquared * $eccSquared * $eccSquared / 256.0)));

    $phi1Rad = $mu
        + ((3.0 * $e1 / 2.0) - (27.0 * $e1 * $e1 * $e1 / 32.0)) * sin(2.0 * $mu)
        + ((21.0 * $e1 * $e1 / 16.0) - (55.0 * $e1 * $e1 * $e1 * $e1 / 32.0)) * sin(4.0 * $mu)
        + (151.0 * $e1 * $e1 * $e1 / 96.0) * sin(6.0 * $mu)
        + (1097.0 * $e1 * $e1 * $e1 * $e1 / 512.0) * sin(8.0 * $mu);

    $sinPhi = sin($phi1Rad);
    $cosPhi = cos($phi1Rad);
    $tanPhi = tan($phi1Rad);
    $n1 = $a / sqrt(1.0 - ($eccSquared * $sinPhi * $sinPhi));
    $t1 = $tanPhi * $tanPhi;
    $c1 = $eccPrimeSquared * $cosPhi * $cosPhi;
    $r1 = $a * (1.0 - $eccSquared) / pow(1.0 - ($eccSquared * $sinPhi * $sinPhi), 1.5);
    $d = $x / ($n1 * $k0);

    $lat = $phi1Rad - (($n1 * $tanPhi / $r1) * (
        ($d * $d / 2.0)
        - ((5.0 + (3.0 * $t1) + (10.0 * $c1) - (4.0 * $c1 * $c1) - (9.0 * $eccPrimeSquared)) * pow($d, 4) / 24.0)
        + ((61.0 + (90.0 * $t1) + (298.0 * $c1) + (45.0 * $t1 * $t1) - (252.0 * $eccPrimeSquared) - (3.0 * $c1 * $c1)) * pow($d, 6) / 720.0)
    ));

    $lon = deg2rad($longOrigin) + (
        $d
        - ((1.0 + (2.0 * $t1) + $c1) * pow($d, 3) / 6.0)
        + ((5.0 - (2.0 * $c1) + (28.0 * $t1) - (3.0 * $c1 * $c1) + (8.0 * $eccPrimeSquared) + (24.0 * $t1 * $t1)) * pow($d, 5) / 120.0)
    ) / max(0.000001, $cosPhi);

    return ['latitude' => rad2deg($lat), 'longitude' => rad2deg($lon)];
}

function scoring_gpx_child_text($node, string $name): string {
    $matches = $node->xpath('./*[local-name()="' . $name . '"]');
    if ($matches && isset($matches[0])) {
        return trim((string)$matches[0]);
    }
    return '';
}

function scoring_parse_gpx_waypoints_file(string $path): array {
    $content = @file_get_contents($path);
    if ($content === false || stripos($content, '<gpx') === false) {
        return [];
    }

    $waypoints = [];
    if (function_exists('simplexml_load_string')) {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml instanceof SimpleXMLElement) {
            $nodes = $xml->xpath('//*[local-name()="wpt" or local-name()="rtept"]');
            if ($nodes) {
                foreach ($nodes as $idx => $node) {
                    $lat = scoring_decimal_or_null((string)$node['lat']);
                    $lon = scoring_decimal_or_null((string)$node['lon']);
                    if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
                        continue;
                    }
                    $name = scoring_gpx_child_text($node, 'name');
                    if ($name === '') {
                        $name = scoring_gpx_child_text($node, 'desc');
                    }
                    if ($name === '') {
                        $name = 'WP ' . ($idx + 1);
                    }
                    $waypoints[] = [
                        'name' => substr($name, 0, 120),
                        'code' => null,
                        'latitude' => $lat,
                        'longitude' => $lon,
                        'elevation_m' => scoring_parse_elevation(scoring_gpx_child_text($node, 'ele')),
                    ];
                }
            }
        }
    }

    if (empty($waypoints)) {
        preg_match_all('~<(wpt|rtept)\b([^>]*)>(.*?)</\1>~is', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $idx => $match) {
            $attrs = $match[2];
            $body = $match[3];
            if (!preg_match('/\blat=["\']([^"\']+)["\']/', $attrs, $latMatch) || !preg_match('/\blon=["\']([^"\']+)["\']/', $attrs, $lonMatch)) {
                continue;
            }
            $lat = scoring_decimal_or_null($latMatch[1]);
            $lon = scoring_decimal_or_null($lonMatch[1]);
            if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
                continue;
            }
            $name = preg_match('~<name>(.*?)</name>~is', $body, $nameMatch) ? html_entity_decode(trim(strip_tags($nameMatch[1])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ('WP ' . ($idx + 1));
            $ele = preg_match('~<ele>(.*?)</ele>~is', $body, $eleMatch) ? $eleMatch[1] : '';
            $waypoints[] = [
                'name' => substr($name, 0, 120),
                'code' => null,
                'latitude' => $lat,
                'longitude' => $lon,
                'elevation_m' => scoring_parse_elevation($ele),
            ];
        }
    }

    return $waypoints;
}

function scoring_parse_compegps_wpt_line(string $line): ?array {
    if (!preg_match('/^\s*W\s+/u', $line)) {
        return null;
    }
    $tokens = preg_split('/\s+/', trim($line));
    if (!$tokens || count($tokens) < 4) {
        return null;
    }
    $name = $tokens[1] ?? 'Waypoint';

    for ($i = 2; $i < count($tokens) - 2; $i++) {
        if (preg_match('/^(\d{1,2})([C-HJ-NP-X])$/i', $tokens[$i], $m) && is_numeric($tokens[$i + 1]) && is_numeric($tokens[$i + 2])) {
            $converted = scoring_utm_to_latlon((int)$m[1], $m[2], (float)$tokens[$i + 1], (float)$tokens[$i + 2]);
            if ($converted) {
                return [
                    'name' => substr($name, 0, 120),
                    'code' => substr($name, 0, 40),
                    'latitude' => $converted['latitude'],
                    'longitude' => $converted['longitude'],
                    'elevation_m' => isset($tokens[$i + 5]) ? scoring_parse_elevation($tokens[$i + 5]) : null,
                ];
            }
        }
    }

    return null;
}

function scoring_guess_waypoint_from_parts(array $parts, ?array $headerMap = null): ?array {
    $name = $parts[0] ?? '';
    $code = null;
    $lat = null;
    $lon = null;
    $elev = null;

    if (count($parts) >= 9 && preg_match('/^[NS]$/i', (string)$parts[1]) && preg_match('/^[EW]$/i', (string)$parts[5])) {
        $lat = scoring_dms_tokens_to_decimal((string)$parts[1], $parts[2], $parts[3], $parts[4]);
        $lon = scoring_dms_tokens_to_decimal((string)$parts[5], $parts[6], $parts[7], $parts[8]);
        if ($lat !== null && $lon !== null && abs($lat) <= 90 && abs($lon) <= 180) {
            return [
                'name' => substr(trim((string)$parts[0]), 0, 120),
                'code' => substr(trim((string)$parts[0]), 0, 40),
                'latitude' => $lat,
                'longitude' => $lon,
                'elevation_m' => scoring_parse_elevation($parts[9] ?? ''),
            ];
        }
    }

    if ($headerMap) {
        $nameIdx = $headerMap['name'] ?? ($headerMap['title'] ?? 0);
        $codeIdx = $headerMap['code'] ?? null;
        $latIdx = $headerMap['lat'] ?? ($headerMap['latitude'] ?? null);
        $lonIdx = $headerMap['lon'] ?? ($headerMap['lng'] ?? ($headerMap['longitude'] ?? null));
        $elevIdx = $headerMap['elev'] ?? ($headerMap['elevation'] ?? ($headerMap['alt'] ?? null));
        $name = $parts[$nameIdx] ?? $name;
        $code = $codeIdx !== null ? ($parts[$codeIdx] ?? null) : null;
        $lat = $latIdx !== null ? scoring_parse_coordinate($parts[$latIdx] ?? '', false) : null;
        $lon = $lonIdx !== null ? scoring_parse_coordinate($parts[$lonIdx] ?? '', true) : null;
        $elev = $elevIdx !== null ? scoring_parse_elevation($parts[$elevIdx] ?? '') : null;
    }

    if (($lat === null || $lon === null) && count($parts) >= 4 && is_numeric($parts[0] ?? null)) {
        $oziLat = scoring_parse_coordinate($parts[2] ?? '', false);
        $oziLon = scoring_parse_coordinate($parts[3] ?? '', true);
        if ($oziLat !== null && $oziLon !== null && abs($oziLat) <= 90 && abs($oziLon) <= 180) {
            $name = $parts[1] ?? $name;
            $code = $parts[1] ?? null;
            $lat = $oziLat;
            $lon = $oziLon;
            $oziElev = scoring_parse_elevation($parts[14] ?? '');
            $elev = ($oziElev !== null && $oziElev > -700) ? $oziElev * 0.3048 : null;
        }
    }

    if ($lat === null || $lon === null) {
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $maybeLat = scoring_parse_coordinate($parts[$i], false);
            $maybeLon = scoring_parse_coordinate($parts[$i + 1], true);
            if ($maybeLat !== null && $maybeLon !== null && abs($maybeLat) <= 90 && abs($maybeLon) <= 180) {
                $lat = $maybeLat;
                $lon = $maybeLon;
                if (strtoupper((string)($parts[0] ?? '')) === 'W' && isset($parts[1])) {
                    $name = $parts[1];
                    $code = $parts[1];
                } elseif (is_numeric($parts[0] ?? null) && isset($parts[1])) {
                    $name = $parts[1];
                    $code = $parts[1];
                } else {
                    $name = $parts[0] ?? $name;
                    $code = isset($parts[1]) && $i > 1 ? $parts[1] : $code;
                }
                $elev = scoring_parse_elevation($parts[$i + 2] ?? '');
                break;
            }
        }
    }

    $name = trim((string)$name, " \t\n\r\0\x0B\"");
    if ($name === '' || $lat === null || $lon === null) {
        return null;
    }

    return [
        'name' => substr($name, 0, 120),
        'code' => $code !== null && trim((string)$code) !== '' ? substr(trim((string)$code), 0, 40) : null,
        'latitude' => $lat,
        'longitude' => $lon,
        'elevation_m' => $elev,
    ];
}

function scoring_upsert_competition_waypoints(PDO $pdo, int $competitionId, array $waypoints, string $source = 'file'): int {
    $stmt = $pdo->prepare('SELECT id, name, code FROM rankings_scoring_waypoints WHERE competition_id = ?');
    $stmt->execute([$competitionId]);
    $byCode = [];
    $byName = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $code = strtolower(trim((string)($row['code'] ?? '')));
        $name = strtolower(trim((string)($row['name'] ?? '')));
        if ($code !== '' && !isset($byCode[$code])) {
            $byCode[$code] = $id;
        }
        if ($name !== '' && !isset($byName[$name])) {
            $byName[$name] = $id;
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO rankings_scoring_waypoints
         (competition_id, name, code, latitude, longitude, elevation_m, source)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE rankings_scoring_waypoints
         SET name = ?, code = ?, latitude = ?, longitude = ?, elevation_m = ?, source = ?
         WHERE id = ? AND competition_id = ?'
    );

    $changed = 0;
    foreach ($waypoints as $wp) {
        $code = strtolower(trim((string)($wp['code'] ?? '')));
        $name = strtolower(trim((string)($wp['name'] ?? '')));
        $id = null;
        if ($code !== '' && isset($byCode[$code])) {
            $id = $byCode[$code];
        } elseif ($name !== '' && isset($byName[$name])) {
            $id = $byName[$name];
        }

        if ($id !== null) {
            $update->execute([
                $wp['name'],
                $wp['code'],
                $wp['latitude'],
                $wp['longitude'],
                $wp['elevation_m'],
                $source,
                $id,
                $competitionId,
            ]);
        } else {
            $insert->execute([
                $competitionId,
                $wp['name'],
                $wp['code'],
                $wp['latitude'],
                $wp['longitude'],
                $wp['elevation_m'],
                $source,
            ]);
        }
        $changed++;
    }

    return $changed;
}

function scoring_parse_waypoints_file(string $path): array {
    $gpxWaypoints = scoring_parse_gpx_waypoints_file($path);
    if (!empty($gpxWaypoints)) {
        return $gpxWaypoints;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        throw new RuntimeException('Waypointsbestand is leeg of onleesbaar.');
    }

    $waypoints = [];
    $headerMap = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === '*') {
            continue;
        }

        if (preg_match('/^\s*w\s/u', $line)) {
            continue;
        }

        $compeWaypoint = scoring_parse_compegps_wpt_line($line);
        if ($compeWaypoint) {
            $waypoints[] = $compeWaypoint;
            continue;
        }

        $delimiter = ',';
        foreach (["\t", ';', ','] as $candidate) {
            if (substr_count($line, $candidate) > substr_count($line, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        if (substr_count($line, $delimiter) === 0 && preg_match('/\s+/', $line)) {
            $parts = preg_split('/\s+/', trim($line));
        } else {
            $parts = array_map('trim', str_getcsv($line, $delimiter));
        }
        if (count($parts) < 3) {
            continue;
        }

        $lowerParts = array_map(function ($part) { return strtolower(trim($part)); }, $parts);
        if (in_array('lat', $lowerParts, true) || in_array('latitude', $lowerParts, true)) {
            $headerMap = [];
            foreach ($lowerParts as $idx => $header) {
                $headerMap[$header] = $idx;
            }
            continue;
        }

        $waypoint = scoring_guess_waypoint_from_parts($parts, $headerMap);
        if ($waypoint) {
            $waypoints[] = $waypoint;
        }
    }

    if (empty($waypoints)) {
        throw new RuntimeException('Geen bruikbare waypoints gevonden. Ondersteund: GPX, SeeYou CUP/CSV met name-lat-lon, OziExplorer WPT en CompeGPS/FS WPT met WGS84 lat/lon of UTM waypoints.');
    }
    return $waypoints;
}

function scoring_igc_fix_from_b_record(string $line, DateTimeImmutable $date, int &$dayOffset, ?int &$previousSeconds): ?array {
    if (strlen($line) < 35 || $line[0] !== 'B') {
        return null;
    }
    $hh = (int)substr($line, 1, 2);
    $mm = (int)substr($line, 3, 2);
    $ss = (int)substr($line, 5, 2);
    $seconds = ($hh * 3600) + ($mm * 60) + $ss;
    if ($previousSeconds !== null && $seconds + 3600 < $previousSeconds) {
        $dayOffset++;
    }
    $previousSeconds = $seconds;

    $latDeg = (int)substr($line, 7, 2);
    $latMin = (float)substr($line, 9, 5) / 1000.0;
    $latHem = substr($line, 14, 1);
    $lonDeg = (int)substr($line, 15, 3);
    $lonMin = (float)substr($line, 18, 5) / 1000.0;
    $lonHem = substr($line, 23, 1);
    $validity = substr($line, 24, 1);
    if ($validity !== 'A' && $validity !== 'V') {
        return null;
    }
    $alt = trim(substr($line, 30, 5));
    $lat = $latDeg + ($latMin / 60.0);
    $lon = $lonDeg + ($lonMin / 60.0);
    if ($latHem === 'S') {
        $lat *= -1;
    }
    if ($lonHem === 'W') {
        $lon *= -1;
    }
    $time = $date->modify('+' . $dayOffset . ' days')->setTime($hh, $mm, $ss);
    return [
        'time_utc' => $time->format('Y-m-d H:i:s'),
        'lat' => $lat,
        'lon' => $lon,
        'altitude_m' => is_numeric($alt) ? (int)$alt : null,
    ];
}

function scoring_parse_igc_file(string $path): array {
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        throw new RuntimeException('IGC-bestand is leeg of onleesbaar.');
    }
    $date = null;
    foreach ($lines as $line) {
        if (preg_match('/^HFDTE(?:DATE:)?\s*(\d{2})(\d{2})(\d{2})/i', $line, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = (int)$m[3];
            $year += $year >= 80 ? 1900 : 2000;
            $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), scoring_utc_timezone());
            break;
        }
    }
    if (!$date) {
        throw new RuntimeException('IGC-bestand bevat geen HFDTE-datum.');
    }

    $fixes = [];
    $dayOffset = 0;
    $previousSeconds = null;
    foreach ($lines as $line) {
        $fix = scoring_igc_fix_from_b_record($line, $date, $dayOffset, $previousSeconds);
        if ($fix) {
            $fixes[] = $fix;
        }
    }
    if (empty($fixes)) {
        throw new RuntimeException('IGC-bestand bevat geen bruikbare B-records.');
    }

    $lats = array_column($fixes, 'lat');
    $lons = array_column($fixes, 'lon');
    return [
        'fixes' => $fixes,
        'first_fix_at' => $fixes[0]['time_utc'],
        'last_fix_at' => $fixes[count($fixes) - 1]['time_utc'],
        'min_lat' => min($lats),
        'max_lat' => max($lats),
        'min_lon' => min($lons),
        'max_lon' => max($lons),
        'fix_count' => count($fixes),
    ];
}

function scoring_sample_tracklog_points(array $fixes, int $maxPoints = 500): array {
    $count = count($fixes);
    if ($count === 0) {
        return [];
    }

    $maxPoints = max(1, min(1000, $maxPoints));
    $indices = [];
    if ($count <= $maxPoints) {
        $indices = range(0, $count - 1);
    } elseif ($maxPoints === 1) {
        $indices = [0];
    } else {
        $last = $count - 1;
        for ($i = 0; $i < $maxPoints; $i++) {
            $indices[] = (int)round($i * $last / ($maxPoints - 1));
        }
        $indices = array_values(array_unique($indices));
    }

    $points = [];
    $previous = null;
    foreach ($indices as $index) {
        $fix = $fixes[$index] ?? null;
        if (!$fix || !isset($fix['lat'], $fix['lon'])) {
            continue;
        }
        $point = [round((float)$fix['lat'], 6), round((float)$fix['lon'], 6)];
        if ($previous !== $point) {
            $points[] = $point;
            $previous = $point;
        }
    }
    return $points;
}

function scoring_tracklog_map_preview(PDO $pdo, int $tracklogId, int $maxPoints = 500): ?array {
    if ($tracklogId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, original_filename, storage_path
         FROM rankings_scoring_tracklogs
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$tracklogId]);
    $tracklog = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tracklog || trim((string)($tracklog['storage_path'] ?? '')) === '') {
        return null;
    }

    $path = scoring_public_upload_path((string)$tracklog['storage_path']);
    if (!is_file($path)) {
        return null;
    }

    try {
        $igc = scoring_parse_igc_file($path);
    } catch (Throwable $e) {
        return null;
    }

    $points = scoring_sample_tracklog_points($igc['fixes'] ?? [], $maxPoints);
    if (empty($points)) {
        return null;
    }

    return [
        'filename' => (string)$tracklog['original_filename'],
        'points' => $points,
        'fix_count' => (int)$igc['fix_count'],
    ];
}

function scoring_task_review_group_key(array $flight): string {
    $identityId = (int)($flight['identity_id'] ?? 0);
    if ($identityId > 0) {
        return 'identity:' . $identityId;
    }
    $suggested = (string)($flight['suggested_identifier'] ?? '');
    if (strpos($suggested, 'identity:') === 0) {
        return $suggested;
    }
    $rawEmail = $flight['pilot_email'] ?? null;
    $email = scoring_is_placeholder_email($rawEmail)
        ? ''
        : scoring_pilot_identity_normalized_email($rawEmail);
    if ($email !== '') {
        return 'email:' . $email;
    }
    $name = scoring_pilot_identity_normalized_name((string)($flight['pilot_name'] ?? ''));
    return 'name:' . ($name !== '' ? $name : 'unknown-' . (int)($flight['id'] ?? 0));
}

function scoring_task_review_group_label(array $flight): string {
    $identityName = trim((string)($flight['identity_display_name'] ?? ''));
    return $identityName !== '' ? $identityName : trim((string)($flight['pilot_name'] ?? 'Piloot'));
}

function scoring_task_review_group_tokens(array $flight): array {
    $tokens = [];
    $rawEmail = $flight['pilot_email'] ?? null;
    $email = scoring_is_placeholder_email($rawEmail)
        ? ''
        : scoring_pilot_identity_normalized_email($rawEmail);
    if ($email !== '') {
        $tokens[] = 'email:' . $email;
    }
    $identityEmail = scoring_pilot_identity_normalized_email($flight['identity_primary_email'] ?? null);
    if ($identityEmail !== '') {
        $tokens[] = 'email:' . $identityEmail;
    }
    $identityId = (int)($flight['identity_id'] ?? 0);
    if ($identityId > 0) {
        $tokens[] = 'identity:' . $identityId;
    }
    $suggested = (string)($flight['suggested_identifier'] ?? '');
    if (strpos($suggested, 'identity:') === 0) {
        $tokens[] = $suggested;
    }
    $name = scoring_pilot_identity_normalized_name((string)($flight['pilot_name'] ?? ''));
    if ($name !== '') {
        $tokens[] = 'name:' . $name;
    }
    $identityName = scoring_pilot_identity_normalized_name((string)($flight['identity_display_name'] ?? ''));
    if ($identityName !== '') {
        $tokens[] = 'name:' . $identityName;
    }
    if (empty($tokens)) {
        $tokens[] = 'flight:' . (int)($flight['id'] ?? 0);
    }
    return array_values(array_unique($tokens));
}

function scoring_task_review_group_update_meta(array &$group, array $flight): void {
    $label = scoring_task_review_group_label($flight);
    if ($label !== '' && ($group['label'] === '' || strlen($label) > strlen((string)$group['label']))) {
        $group['label'] = $label;
    }
    $email = $flight['identity_primary_email'] ?? ($flight['pilot_email'] ?? null);
    if (!scoring_is_placeholder_email($email) && scoring_pilot_identity_normalized_email($email) !== '') {
        $group['email'] = $email;
    }
}

function scoring_build_task_review_groups(array $flights): array {
    $groups = [];
    $tokenIndex = [];
    foreach ($flights as $flight) {
        $tokens = scoring_task_review_group_tokens($flight);
        $matchedKeys = [];
        foreach ($tokens as $token) {
            if (isset($tokenIndex[$token])) {
                $matchedKeys[$tokenIndex[$token]] = true;
            }
        }
        $key = !empty($matchedKeys) ? (string)array_key_first($matchedKeys) : $tokens[0];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'label' => scoring_task_review_group_label($flight),
                'email' => $flight['identity_primary_email'] ?? ($flight['pilot_email'] ?? null),
                'flights' => [],
                'tokens' => [],
            ];
        }
        foreach (array_keys($matchedKeys) as $matchedKey) {
            if ($matchedKey === $key || !isset($groups[$matchedKey])) {
                continue;
            }
            foreach ($groups[$matchedKey]['flights'] as $mergedFlight) {
                $groups[$key]['flights'][] = $mergedFlight;
                scoring_task_review_group_update_meta($groups[$key], $mergedFlight);
            }
            foreach ($groups[$matchedKey]['tokens'] as $mergedToken) {
                $groups[$key]['tokens'][$mergedToken] = true;
                $tokenIndex[$mergedToken] = $key;
            }
            unset($groups[$matchedKey]);
        }
        $groups[$key]['flights'][] = $flight;
        scoring_task_review_group_update_meta($groups[$key], $flight);
        foreach ($tokens as $token) {
            $groups[$key]['tokens'][$token] = true;
            $tokenIndex[$token] = $key;
        }
    }
    foreach ($groups as &$group) {
        unset($group['tokens']);
    }
    unset($group);
    uasort($groups, function ($a, $b) {
        return strcasecmp((string)$a['label'], (string)$b['label']);
    });
    return array_values($groups);
}

function scoring_task_tracklog_review_map_data(PDO $pdo, array $flight, ?array $taskMap, int $maxPoints = 350): ?array {
    if (!$taskMap || !scoring_task_flight_is_track_candidate($flight)) {
        return null;
    }
    $preview = scoring_tracklog_map_preview($pdo, (int)($flight['tracklog_id'] ?? 0), $maxPoints);
    if (!$preview) {
        return null;
    }
    return [
        'task' => $taskMap,
        'track' => $preview,
    ];
}

function scoring_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $a = 6378.137;
    $f = 1 / 298.257223563;
    $b = (1 - $f) * $a;
    $u1 = atan((1 - $f) * tan(deg2rad($lat1)));
    $u2 = atan((1 - $f) * tan(deg2rad($lat2)));
    $l = deg2rad($lon2 - $lon1);
    $lambda = $l;
    $lambdaPrevious = 0.0;
    $sinSigma = 0.0;
    $cosSigma = 0.0;
    $sigma = 0.0;
    $cosSqAlpha = 0.0;
    $cos2SigmaM = 0.0;

    for ($iter = 0; $iter < 100; $iter++) {
        $sinLambda = sin($lambda);
        $cosLambda = cos($lambda);
        $sinSigma = sqrt(
            pow(cos($u2) * $sinLambda, 2)
            + pow(cos($u1) * sin($u2) - sin($u1) * cos($u2) * $cosLambda, 2)
        );
        if ($sinSigma == 0.0) {
            return 0.0;
        }
        $cosSigma = sin($u1) * sin($u2) + cos($u1) * cos($u2) * $cosLambda;
        $sigma = atan2($sinSigma, $cosSigma);
        $sinAlpha = cos($u1) * cos($u2) * $sinLambda / $sinSigma;
        $cosSqAlpha = 1 - ($sinAlpha * $sinAlpha);
        $cos2SigmaM = $cosSqAlpha == 0.0 ? 0.0 : $cosSigma - (2 * sin($u1) * sin($u2) / $cosSqAlpha);
        $c = $f / 16 * $cosSqAlpha * (4 + $f * (4 - (3 * $cosSqAlpha)));
        $lambdaPrevious = $lambda;
        $lambda = $l + (1 - $c) * $f * $sinAlpha * (
            $sigma + $c * $sinSigma * ($cos2SigmaM + $c * $cosSigma * (-1 + 2 * $cos2SigmaM * $cos2SigmaM))
        );
        if (abs($lambda - $lambdaPrevious) <= 1e-12) {
            break;
        }
    }

    if (abs($lambda - $lambdaPrevious) > 1e-9) {
        $r = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $aa = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        return $r * 2 * atan2(sqrt($aa), sqrt(max(0.0, 1.0 - $aa)));
    }

    $uSq = $cosSqAlpha * (($a * $a) - ($b * $b)) / ($b * $b);
    $aa = 1 + $uSq / 16384 * (4096 + $uSq * (-768 + $uSq * (320 - 175 * $uSq)));
    $bb = $uSq / 1024 * (256 + $uSq * (-128 + $uSq * (74 - 47 * $uSq)));
    $deltaSigma = $bb * $sinSigma * (
        $cos2SigmaM + $bb / 4 * (
            $cosSigma * (-1 + 2 * $cos2SigmaM * $cos2SigmaM)
            - $bb / 6 * $cos2SigmaM * (-3 + 4 * $sinSigma * $sinSigma) * (-3 + 4 * $cos2SigmaM * $cos2SigmaM)
        )
    );
    return $b * $aa * ($sigma - $deltaSigma);
}

function scoring_center_route_distance_km(array $turnpoints): float {
    $distance = 0.0;
    for ($i = 1; $i < count($turnpoints); $i++) {
        $a = $turnpoints[$i - 1];
        $b = $turnpoints[$i];
        $distance += scoring_haversine_km((float)$a['latitude'], (float)$a['longitude'], (float)$b['latitude'], (float)$b['longitude']);
    }
    return $distance;
}

function scoring_optimised_route_metrics(array $turnpoints): array {
    $count = count($turnpoints);
    if ($count < 2) {
        $path = [];
        foreach ($turnpoints as $tp) {
            $path[] = [round((float)$tp['latitude'], 7), round((float)$tp['longitude'], 7)];
        }
        return ['distance' => 0.0, 'cumulative' => array_fill(0, $count, 0.0), 'path' => $path];
    }

    static $cache = [];
    $cacheKey = md5(json_encode(array_map(function ($tp) {
        return [
            round((float)$tp['latitude'], 7),
            round((float)$tp['longitude'], 7),
            round(((float)($tp['radius_m'] ?? 0)) / 1000.0, 3),
        ];
    }, $turnpoints)));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $latSum = 0.0;
    foreach ($turnpoints as $tp) {
        $latSum += (float)$tp['latitude'];
    }
    $lat0 = deg2rad($latSum / $count);
    $lonRef = (float)$turnpoints[0]['longitude'];
    $latRef = (float)$turnpoints[0]['latitude'];
    $a = 6378.137;
    $f = 1 / 298.257223563;
    $e2 = $f * (2 - $f);
    $sinLat = sin($lat0);
    $m = $a * (1 - $e2) / pow(1 - ($e2 * $sinLat * $sinLat), 1.5);
    $n = $a / sqrt(1 - ($e2 * $sinLat * $sinLat));
    $xScale = $n * cos($lat0) * (pi() / 180.0);
    $yScale = $m * (pi() / 180.0);

    $points = [];
    foreach ($turnpoints as $tp) {
        $points[] = [
            'x' => ((float)$tp['longitude'] - $lonRef) * $xScale,
            'y' => ((float)$tp['latitude'] - $latRef) * $yScale,
            'r' => max(0.0, ((float)($tp['radius_m'] ?? 0)) / 1000.0),
        ];
    }

    $pathDistance = function (array $angles) use ($points, $count): float {
        $path = [];
        for ($i = 0; $i < $count; $i++) {
            if ($i === 0 || $points[$i]['r'] <= 0.0) {
                $path[$i] = ['x' => $points[$i]['x'], 'y' => $points[$i]['y']];
            } else {
                $path[$i] = [
                    'x' => $points[$i]['x'] + ($points[$i]['r'] * cos($angles[$i])),
                    'y' => $points[$i]['y'] + ($points[$i]['r'] * sin($angles[$i])),
                ];
            }
        }
        $distance = 0.0;
        for ($i = 1; $i < $count; $i++) {
            $distance += hypot($path[$i]['x'] - $path[$i - 1]['x'], $path[$i]['y'] - $path[$i - 1]['y']);
        }
        return $distance;
    };

    $seeds = [];
    foreach (['previous', 'next', 'bisector', 'zero', 'quarter'] as $mode) {
        $angles = array_fill(0, $count, 0.0);
        for ($i = 1; $i < $count; $i++) {
            $prev = $points[max(0, $i - 1)];
            $next = $points[min($count - 1, $i + 1)];
            if ($mode === 'previous') {
                $angles[$i] = atan2($prev['y'] - $points[$i]['y'], $prev['x'] - $points[$i]['x']);
            } elseif ($mode === 'next') {
                $angles[$i] = atan2($next['y'] - $points[$i]['y'], $next['x'] - $points[$i]['x']);
            } elseif ($mode === 'bisector') {
                $v1x = $prev['x'] - $points[$i]['x'];
                $v1y = $prev['y'] - $points[$i]['y'];
                $v2x = $next['x'] - $points[$i]['x'];
                $v2y = $next['y'] - $points[$i]['y'];
                $l1 = max(0.000001, hypot($v1x, $v1y));
                $l2 = max(0.000001, hypot($v2x, $v2y));
                $angles[$i] = atan2(($v1y / $l1) + ($v2y / $l2), ($v1x / $l1) + ($v2x / $l2));
            } elseif ($mode === 'quarter') {
                $angles[$i] = pi() / 2.0;
            }
        }
        $seeds[] = $angles;
    }

    $bestAngles = $seeds[0];
    $bestDistance = PHP_FLOAT_MAX;
    foreach ($seeds as $angles) {
        $step = 1.0;
        for ($iter = 0; $iter < 2000; $iter++) {
            $improved = false;
            for ($i = 1; $i < $count; $i++) {
                if ($points[$i]['r'] <= 0.0) {
                    continue;
                }
                $baseAngle = $angles[$i];
                $baseDistance = $pathDistance($angles);
                $chosenAngle = $baseAngle;
                $chosenDistance = $baseDistance;
                foreach ([-$step, $step] as $delta) {
                    $angles[$i] = $baseAngle + $delta;
                    $distance = $pathDistance($angles);
                    if ($distance < $chosenDistance) {
                        $chosenDistance = $distance;
                        $chosenAngle = $angles[$i];
                        $improved = true;
                    }
                }
                $angles[$i] = $chosenAngle;
            }
            if (!$improved) {
                $step *= 0.55;
            }
            if ($step < 1e-8) {
                break;
            }
        }
        $distance = $pathDistance($angles);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestAngles = $angles;
        }
    }

    $path = [];
    for ($i = 0; $i < $count; $i++) {
        if ($i === 0 || $points[$i]['r'] <= 0.0) {
            $path[$i] = ['x' => $points[$i]['x'], 'y' => $points[$i]['y']];
        } else {
            $path[$i] = [
                'x' => $points[$i]['x'] + ($points[$i]['r'] * cos($bestAngles[$i])),
                'y' => $points[$i]['y'] + ($points[$i]['r'] * sin($bestAngles[$i])),
            ];
        }
    }
    $cumulative = [0.0];
    for ($i = 1; $i < $count; $i++) {
        $cumulative[$i] = $cumulative[$i - 1] + hypot($path[$i]['x'] - $path[$i - 1]['x'], $path[$i]['y'] - $path[$i - 1]['y']);
    }

    $safeXScale = abs($xScale) > 0.000000001 ? $xScale : 0.000000001;
    $safeYScale = abs($yScale) > 0.000000001 ? $yScale : 0.000000001;
    $routePath = [];
    foreach ($path as $point) {
        $routePath[] = [
            round($latRef + ($point['y'] / $safeYScale), 7),
            round($lonRef + ($point['x'] / $safeXScale), 7),
        ];
    }

    $cache[$cacheKey] = [
        'distance' => $cumulative[$count - 1],
        'cumulative' => $cumulative,
        'path' => $routePath,
    ];
    return $cache[$cacheKey];
}

function scoring_task_distance_km(array $turnpoints): float {
    return scoring_optimised_route_metrics($turnpoints)['distance'];
}

function scoring_task_map_data(array $turnpoints): ?array {
    if (empty($turnpoints)) {
        return null;
    }

    [$sssIndex, $essIndex] = scoring_speed_section_indices($turnpoints);
    $points = [];
    foreach ($turnpoints as $idx => $tp) {
        $isSss = $idx === $sssIndex;
        $isEss = $idx === $essIndex;
        $role = 'normal';
        if ($isSss && $isEss) {
            $role = 'sss_ess';
        } elseif ($isSss) {
            $role = 'sss';
        } elseif ($isEss) {
            $role = 'ess';
        }

        $points[] = [
            'sequence' => (int)($tp['sequence_no'] ?? ($idx + 1)),
            'name' => (string)($tp['name'] ?? ('TP ' . ($idx + 1))),
            'code' => (string)($tp['code'] ?? ''),
            'lat' => round((float)$tp['latitude'], 7),
            'lon' => round((float)$tp['longitude'], 7),
            'radius_m' => max(0, (int)($tp['radius_m'] ?? 0)),
            'role' => $role,
        ];
    }

    $metrics = scoring_optimised_route_metrics($turnpoints);
    return [
        'turnpoints' => $points,
        'route' => $metrics['path'] ?? [],
        'distance_km' => (float)($metrics['distance'] ?? 0.0),
    ];
}

function scoring_speed_section_center_distance_km(array $turnpoints): float {
    if (count($turnpoints) < 2) {
        return 0.0;
    }
    list($sssIndex, $essIndex) = scoring_speed_section_indices($turnpoints);
    $distance = 0.0;
    for ($i = $sssIndex + 1; $i <= $essIndex; $i++) {
        $a = $turnpoints[$i - 1];
        $b = $turnpoints[$i];
        $distance += scoring_haversine_km((float)$a['latitude'], (float)$a['longitude'], (float)$b['latitude'], (float)$b['longitude']);
    }
    return $distance;
}

function scoring_speed_section_boundary_distance_km(array $turnpoints): float {
    if (count($turnpoints) < 2) {
        return 0.0;
    }
    list($sssIndex, $essIndex) = scoring_speed_section_indices($turnpoints);
    $cumulative = scoring_route_cumulative($turnpoints);
    return max(0.0, (float)($cumulative[$essIndex] ?? 0.0) - (float)($cumulative[$sssIndex] ?? 0.0));
}

function scoring_route_cumulative(array $route): array {
    return scoring_optimised_route_metrics($route)['cumulative'];
}

function scoring_project_progress_km(float $lat, float $lon, array $route, array $cumulative): float {
    if (count($route) < 2) {
        return 0.0;
    }
    $earth = 6371.0088;
    $bestDistance = PHP_FLOAT_MAX;
    $bestProgress = 0.0;
    for ($i = 1; $i < count($route); $i++) {
        $a = $route[$i - 1];
        $b = $route[$i];
        $lat0 = deg2rad(((float)$a['latitude'] + (float)$b['latitude']) / 2.0);
        $ax = deg2rad((float)$a['longitude']) * $earth * cos($lat0);
        $ay = deg2rad((float)$a['latitude']) * $earth;
        $bx = deg2rad((float)$b['longitude']) * $earth * cos($lat0);
        $by = deg2rad((float)$b['latitude']) * $earth;
        $px = deg2rad($lon) * $earth * cos($lat0);
        $py = deg2rad($lat) * $earth;
        $vx = $bx - $ax;
        $vy = $by - $ay;
        $wx = $px - $ax;
        $wy = $py - $ay;
        $len2 = max(0.000001, ($vx * $vx) + ($vy * $vy));
        $t = max(0.0, min(1.0, (($wx * $vx) + ($wy * $vy)) / $len2));
        $projX = $ax + ($t * $vx);
        $projY = $ay + ($t * $vy);
        $dist = sqrt((($px - $projX) * ($px - $projX)) + (($py - $projY) * ($py - $projY)));
        if ($dist < $bestDistance) {
            $bestDistance = $dist;
            $bestProgress = $cumulative[$i - 1] + ($t * max(0.0, $cumulative[$i] - $cumulative[$i - 1]));
        }
    }
    return $bestProgress;
}

function scoring_task_bbox(array $turnpoints, float $marginDeg = 0.8): array {
    $lats = array_map('floatval', array_column($turnpoints, 'latitude'));
    $lons = array_map('floatval', array_column($turnpoints, 'longitude'));
    return [
        'min_lat' => min($lats) - $marginDeg,
        'max_lat' => max($lats) + $marginDeg,
        'min_lon' => min($lons) - $marginDeg,
        'max_lon' => max($lons) + $marginDeg,
    ];
}

function scoring_refresh_tracklog_metadata(PDO $pdo, array $tracklog): bool {
    if (empty($tracklog['storage_path'])) {
        return false;
    }
    $path = scoring_public_upload_path((string)$tracklog['storage_path']);
    if (!is_file($path)) {
        return false;
    }
    try {
        $igc = scoring_parse_igc_file($path);
        $stmt = $pdo->prepare(
            'UPDATE rankings_scoring_tracklogs
             SET first_fix_at = ?, last_fix_at = ?, min_lat = ?, max_lat = ?, min_lon = ?, max_lon = ?, fix_count = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $igc['first_fix_at'],
            $igc['last_fix_at'],
            $igc['min_lat'],
            $igc['max_lat'],
            $igc['min_lon'],
            $igc['max_lon'],
            $igc['fix_count'],
            (int)$tracklog['id'],
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function scoring_format_duration(?int $seconds): string {
    if ($seconds === null) {
        return '-';
    }
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}

function scoring_load_competition(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT c.*, s.email AS scorer_email, s.name AS scorer_name FROM rankings_scoring_competitions c JOIN rankings_scorers s ON s.id = c.scorer_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function scoring_load_task(PDO $pdo, int $taskId): ?array {
    $stmt = $pdo->prepare('SELECT t.*, c.name AS competition_name, c.scorer_id, c.class, c.scope, c.is_public AS competition_public FROM rankings_scoring_tasks t JOIN rankings_scoring_competitions c ON c.id = t.competition_id WHERE t.id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function scoring_publication_snapshots_available(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $pdo->query('SELECT 1 FROM rankings_scoring_task_publications LIMIT 1');
        $pdo->query('SELECT 1 FROM rankings_scoring_task_public_results LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function scoring_ensure_publication_snapshot_tables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rankings_scoring_task_publications (
          task_id INT UNSIGNED NOT NULL,
          task_distance_km DECIMAL(8,3) DEFAULT NULL,
          scoring_summary_json MEDIUMTEXT DEFAULT NULL,
          published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (task_id),
          KEY idx_rankings_scoring_task_publications_published (published_at),
          CONSTRAINT fk_rankings_scoring_task_publications_task
            FOREIGN KEY (task_id) REFERENCES rankings_scoring_tasks(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rankings_scoring_task_public_results (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          task_id INT UNSIGNED NOT NULL,
          source_flight_id INT UNSIGNED NOT NULL,
          pilot_name VARCHAR(160) NOT NULL,
          pilot_email VARCHAR(190) DEFAULT NULL,
          pilot_identity_id INT UNSIGNED DEFAULT NULL,
          result_status VARCHAR(30) NOT NULL DEFAULT \'track\',
          distance_km DECIMAL(9,3) DEFAULT NULL,
          start_time_at DATETIME DEFAULT NULL,
          ess_time_at DATETIME DEFAULT NULL,
          goal_time_at DATETIME DEFAULT NULL,
          time_seconds INT UNSIGNED DEFAULT NULL,
          reached_ess TINYINT(1) NOT NULL DEFAULT 0,
          reached_goal TINYINT(1) NOT NULL DEFAULT 0,
          distance_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          time_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          departure_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          leading_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          arrival_position_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          arrival_time_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          total_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
          rank_no INT UNSIGNED DEFAULT NULL,
          evaluation_json MEDIUMTEXT DEFAULT NULL,
          scored_at DATETIME DEFAULT NULL,
          published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_rankings_scoring_task_public_results_flight (task_id, source_flight_id),
          KEY idx_rankings_scoring_task_public_results_task (task_id),
          KEY idx_rankings_scoring_task_public_results_identity (pilot_identity_id),
          CONSTRAINT fk_rankings_scoring_task_public_results_task
            FOREIGN KEY (task_id) REFERENCES rankings_scoring_tasks(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!scoring_table_column_exists($pdo, 'rankings_scoring_task_public_results', 'result_status')) {
        scoring_exec_schema_change($pdo, "ALTER TABLE rankings_scoring_task_public_results ADD COLUMN result_status VARCHAR(30) NOT NULL DEFAULT 'track' AFTER pilot_identity_id", [1060], ['42S21']);
    }
}

function scoring_load_task_publication(PDO $pdo, int $taskId): ?array {
    if ($taskId <= 0 || !scoring_publication_snapshots_available($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT *
         FROM rankings_scoring_task_publications
         WHERE task_id = ?
         LIMIT 1'
    );
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function scoring_load_task_public_results(PDO $pdo, int $taskId): array {
    if ($taskId <= 0 || !scoring_publication_snapshots_available($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT *
         FROM rankings_scoring_task_public_results
         WHERE task_id = ?
         ORDER BY rank_no IS NULL ASC, rank_no ASC, total_points DESC, pilot_name ASC'
    );
    $stmt->execute([$taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scoring_load_public_competition_tasks(PDO $pdo, int $competitionId): array {
    if ($competitionId <= 0 || !scoring_publication_snapshots_available($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT t.id, t.name, t.task_date, p.published_at
         FROM rankings_scoring_tasks t
         JOIN rankings_scoring_task_publications p ON p.task_id = t.id
         WHERE t.competition_id = ? AND t.status = 'published'
         ORDER BY t.task_date ASC, t.id ASC"
    );
    $stmt->execute([$competitionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scoring_clear_task_publication(PDO $pdo, int $taskId): void {
    if ($taskId <= 0 || !scoring_publication_snapshots_available($pdo)) {
        return;
    }
    $pdo->prepare('DELETE FROM rankings_scoring_task_public_results WHERE task_id = ?')->execute([$taskId]);
    $pdo->prepare('DELETE FROM rankings_scoring_task_publications WHERE task_id = ?')->execute([$taskId]);
}

function scoring_publish_task_results(PDO $pdo, int $taskId): array {
    if ($taskId <= 0) {
        throw new RuntimeException('Taak niet gevonden.');
    }
    scoring_ensure_publication_snapshot_tables($pdo);
    scoring_ensure_task_review_columns($pdo);
    if (!scoring_publication_snapshots_available($pdo)) {
        throw new RuntimeException('De publicatie-tabellen ontbreken nog. Voer database/scoring_schema.sql opnieuw uit.');
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $task = scoring_load_task($pdo, $taskId);
        if (!$task) {
            throw new RuntimeException('Taak niet gevonden.');
        }

        $identitySelect = 'f.pilot_name, f.pilot_email, NULL AS pilot_identity_id';
        $identityJoin = '';
        if (scoring_pilot_identities_available($pdo)) {
            $identitySelect = "COALESCE(pi.display_name, f.pilot_name) AS pilot_name,
                    COALESCE(NULLIF(pi.primary_email, ''), f.pilot_email) AS pilot_email,
                    pi.id AS pilot_identity_id";
            $identityJoin = 'LEFT JOIN rankings_scoring_task_flight_identities fi ON fi.flight_id = f.id
             LEFT JOIN rankings_scoring_pilot_identities pi ON pi.id = fi.identity_id AND pi.competition_id = t.competition_id';
        }

        $stmt = $pdo->prepare(
            "SELECT f.id AS source_flight_id,
                    $identitySelect,
                    f.result_status,
                    f.distance_km, f.start_time_at, f.ess_time_at, f.goal_time_at, f.time_seconds,
                    f.reached_ess, f.reached_goal, f.distance_points, f.time_points, f.departure_points,
                    f.leading_points, f.arrival_position_points, f.arrival_time_points, f.total_points,
                    f.rank_no, f.evaluation_json, f.scored_at
             FROM rankings_scoring_task_flights f
             JOIN rankings_scoring_tasks t ON t.id = f.task_id
             $identityJoin
             WHERE f.task_id = ?
               AND f.is_excluded = 0
               AND f.scored_at IS NOT NULL
             ORDER BY f.rank_no IS NULL ASC, f.rank_no ASC, f.total_points DESC, pilot_name ASC"
        );
        $stmt->execute([$taskId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            throw new RuntimeException('Score de taak voordat je publiceert.');
        }

        $publishedAt = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        $pdo->prepare('DELETE FROM rankings_scoring_task_public_results WHERE task_id = ?')->execute([$taskId]);
        $insert = $pdo->prepare(
            'INSERT INTO rankings_scoring_task_public_results
             (task_id, source_flight_id, pilot_name, pilot_email, pilot_identity_id,
              result_status, distance_km, start_time_at, ess_time_at, goal_time_at, time_seconds,
              reached_ess, reached_goal, distance_points, time_points, departure_points,
              leading_points, arrival_position_points, arrival_time_points, total_points,
              rank_no, evaluation_json, scored_at, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $identityId = isset($row['pilot_identity_id']) ? (int)$row['pilot_identity_id'] : 0;
            $insert->execute([
                $taskId,
                (int)$row['source_flight_id'],
                (string)$row['pilot_name'],
                $row['pilot_email'] ?? null,
                $identityId > 0 ? $identityId : null,
                scoring_task_flight_result_status($row),
                $row['distance_km'],
                $row['start_time_at'],
                $row['ess_time_at'],
                $row['goal_time_at'],
                $row['time_seconds'],
                (int)$row['reached_ess'],
                (int)$row['reached_goal'],
                $row['distance_points'],
                $row['time_points'],
                $row['departure_points'],
                $row['leading_points'],
                $row['arrival_position_points'],
                $row['arrival_time_points'],
                $row['total_points'],
                $row['rank_no'],
                $row['evaluation_json'],
                $row['scored_at'],
                $publishedAt,
            ]);
        }

        $pdo->prepare(
            'INSERT INTO rankings_scoring_task_publications
             (task_id, task_distance_km, scoring_summary_json, published_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               task_distance_km = VALUES(task_distance_km),
               scoring_summary_json = VALUES(scoring_summary_json),
               published_at = VALUES(published_at),
               updated_at = NOW()'
        )->execute([
            $taskId,
            $task['task_distance_km'],
            $task['scoring_summary_json'],
            $publishedAt,
        ]);

        $pdo->prepare('UPDATE rankings_scoring_tasks SET status = ?, published_at = ? WHERE id = ?')->execute(['published', $publishedAt, $taskId]);
        $pdo->prepare('UPDATE rankings_scoring_competitions SET is_public = 1 WHERE id = ?')->execute([(int)$task['competition_id']]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return ['published_rows' => count($rows), 'published_at' => $publishedAt];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function scoring_competition_standings_pilot_key(string $pilotName, ?string $pilotEmail, ?int $pilotIdentityId = null): string {
    if ($pilotIdentityId !== null && $pilotIdentityId > 0) {
        return 'identity:' . $pilotIdentityId;
    }

    $email = scoring_normalize_email((string)$pilotEmail);
    if ($email !== '' && !scoring_is_placeholder_email($email)) {
        return 'email:' . $email;
    }

    $name = trim(preg_replace('/\s+/', ' ', $pilotName));
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return 'name:' . $name;
}

function scoring_competition_standings_through_task(PDO $pdo, int $taskId): array {
    $throughTask = scoring_load_task($pdo, $taskId);
    if (!$throughTask) {
        throw new RuntimeException('Taak niet gevonden.');
    }
    if ((string)$throughTask['status'] !== 'published') {
        throw new RuntimeException('Deze taak is nog niet gepubliceerd.');
    }
    if (!scoring_publication_snapshots_available($pdo)) {
        throw new RuntimeException('De publicatie-tabellen ontbreken nog. Voer database/scoring_schema.sql opnieuw uit.');
    }
    if (!scoring_load_task_publication($pdo, $taskId)) {
        throw new RuntimeException('Deze taak heeft nog geen gepubliceerde score-snapshot.');
    }

    $competition = scoring_load_competition($pdo, (int)$throughTask['competition_id']);
    if (!$competition) {
        throw new RuntimeException('Competitie niet gevonden.');
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.name, t.task_date, p.published_at
         FROM rankings_scoring_tasks t
         JOIN rankings_scoring_task_publications p ON p.task_id = t.id
         WHERE t.competition_id = ?
           AND t.status = 'published'
           AND (t.task_date < ? OR (t.task_date = ? AND t.id <= ?))
         ORDER BY t.task_date ASC, t.id ASC"
    );
    $stmt->execute([
        (int)$throughTask['competition_id'],
        (string)$throughTask['task_date'],
        (string)$throughTask['task_date'],
        $taskId,
    ]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tasks)) {
        return ['competition' => $competition, 'through_task' => $throughTask, 'tasks' => [], 'rows' => []];
    }

    $taskIndexById = [];
    foreach ($tasks as $index => $task) {
        $taskIndexById[(int)$task['id']] = $index;
    }

    $taskIds = array_map(static function ($task) {
        return (int)$task['id'];
    }, $tasks);
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT r.task_id, r.pilot_name, r.pilot_email, r.pilot_identity_id, r.total_points
         FROM rankings_scoring_task_public_results r
         JOIN rankings_scoring_tasks t ON t.id = r.task_id
         WHERE r.task_id IN ($placeholders)
         ORDER BY t.task_date ASC, t.id ASC, r.rank_no ASC, r.total_points DESC, r.pilot_name ASC"
    );
    $stmt->execute($taskIds);

    $rowsByPilot = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $flight) {
        $taskIdForFlight = (int)$flight['task_id'];
        if (!isset($taskIndexById[$taskIdForFlight])) {
            continue;
        }

        $key = scoring_competition_standings_pilot_key((string)$flight['pilot_name'], $flight['pilot_email'] ?? null, isset($flight['pilot_identity_id']) ? (int)$flight['pilot_identity_id'] : null);
        if (!isset($rowsByPilot[$key])) {
            $rowsByPilot[$key] = [
                'pilot_name' => (string)$flight['pilot_name'],
                'pilot_email' => (string)($flight['pilot_email'] ?? ''),
                'task_points' => array_fill(0, count($tasks), 0.0),
                'total_points' => 0.0,
            ];
        }

        $taskIndex = $taskIndexById[$taskIdForFlight];
        $points = round((float)$flight['total_points'], 1);
        if ($points >= (float)$rowsByPilot[$key]['task_points'][$taskIndex]) {
            $rowsByPilot[$key]['total_points'] += $points - (float)$rowsByPilot[$key]['task_points'][$taskIndex];
            $rowsByPilot[$key]['task_points'][$taskIndex] = $points;
            $rowsByPilot[$key]['pilot_name'] = (string)$flight['pilot_name'];
            if (!scoring_is_placeholder_email($flight['pilot_email'] ?? null)) {
                $rowsByPilot[$key]['pilot_email'] = (string)$flight['pilot_email'];
            }
        }
    }

    $rows = array_values($rowsByPilot);
    usort($rows, static function ($a, $b) {
        if (abs((float)$a['total_points'] - (float)$b['total_points']) < 0.0001) {
            return strcasecmp((string)$a['pilot_name'], (string)$b['pilot_name']);
        }
        return (float)$a['total_points'] < (float)$b['total_points'] ? 1 : -1;
    });

    $rank = 0;
    $shown = 0;
    $previousTotal = null;
    foreach ($rows as &$row) {
        $shown++;
        $total = (float)$row['total_points'];
        if ($previousTotal === null || abs($total - $previousTotal) > 0.0001) {
            $rank = $shown;
            $previousTotal = $total;
        }
        $row['rank_no'] = $rank;
        $row['total_points'] = round($total, 1);
    }
    unset($row);

    return ['competition' => $competition, 'through_task' => $throughTask, 'tasks' => $tasks, 'rows' => $rows];
}

function scoring_competition_buddies_available(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $pdo->query('SELECT 1 FROM rankings_scoring_competition_scorers LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function scoring_can_edit_competition(PDO $pdo, int $competitionId, int $scorerId): bool {
    if ($competitionId <= 0 || $scorerId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT scorer_id FROM rankings_scoring_competitions WHERE id = ? LIMIT 1');
    $stmt->execute([$competitionId]);
    $ownerId = (int)$stmt->fetchColumn();
    if ($ownerId <= 0) {
        return false;
    }
    if ($ownerId === $scorerId) {
        return true;
    }
    if (!scoring_competition_buddies_available($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rankings_scoring_competition_scorers WHERE competition_id = ? AND scorer_id = ?');
    $stmt->execute([$competitionId, $scorerId]);
    return (int)$stmt->fetchColumn() > 0;
}

function scoring_load_editable_competitions(PDO $pdo, int $scorerId): array {
    if (scoring_competition_buddies_available($pdo)) {
        $stmt = $pdo->prepare(
            "SELECT c.*,
                    CASE WHEN c.scorer_id = ? THEN 'owner' ELSE COALESCE(cs.role, 'buddy') END AS editor_role,
                    (SELECT COUNT(*) FROM rankings_scoring_tasks t WHERE t.competition_id = c.id) AS task_count
             FROM rankings_scoring_competitions c
             LEFT JOIN rankings_scoring_competition_scorers cs
               ON cs.competition_id = c.id AND cs.scorer_id = ?
             WHERE c.scorer_id = ? OR cs.scorer_id IS NOT NULL
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([$scorerId, $scorerId, $scorerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare(
        "SELECT c.*, 'owner' AS editor_role,
                (SELECT COUNT(*) FROM rankings_scoring_tasks t WHERE t.competition_id = c.id) AS task_count
         FROM rankings_scoring_competitions c
         WHERE c.scorer_id = ?
         ORDER BY c.created_at DESC"
    );
    $stmt->execute([$scorerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scoring_load_competition_editors(PDO $pdo, array $competition): array {
    $ownerId = (int)$competition['scorer_id'];
    $stmt = $pdo->prepare('SELECT id, email, name, active FROM rankings_scorers WHERE id = ? LIMIT 1');
    $stmt->execute([$ownerId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    $editors = [];
    if ($owner) {
        $owner['role'] = 'owner';
        $editors[] = $owner;
    }

    if (!scoring_competition_buddies_available($pdo)) {
        return $editors;
    }

    $stmt = $pdo->prepare(
        "SELECT s.id, s.email, s.name, s.active, cs.role
         FROM rankings_scoring_competition_scorers cs
         JOIN rankings_scorers s ON s.id = cs.scorer_id
         WHERE cs.competition_id = ? AND cs.scorer_id <> ?
         ORDER BY s.email ASC"
    );
    $stmt->execute([(int)$competition['id'], $ownerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $editors[] = $row;
    }
    return $editors;
}

function scoring_find_or_create_scorer(PDO $pdo, string $email, ?string $name = null): array {
    $email = scoring_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vul een geldig e-mailadres in.');
    }
    $name = trim((string)$name);

    $stmt = $pdo->prepare('SELECT id, email, name, active FROM rankings_scorers WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $scorer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($scorer) {
        if ((int)$scorer['active'] !== 1) {
            throw new RuntimeException('Deze scorer bestaat, maar is inactief.');
        }
        if ($name !== '' && trim((string)$scorer['name']) === '') {
            $pdo->prepare('UPDATE rankings_scorers SET name = ? WHERE id = ?')->execute([$name, (int)$scorer['id']]);
            $scorer['name'] = $name;
        }
        return ['scorer' => $scorer, 'created' => false];
    }

    $stmt = $pdo->prepare('INSERT INTO rankings_scorers (email, name, active) VALUES (?, ?, 1)');
    $stmt->execute([$email, $name !== '' ? $name : null]);
    return [
        'scorer' => [
            'id' => (int)$pdo->lastInsertId(),
            'email' => $email,
            'name' => $name !== '' ? $name : null,
            'active' => 1,
        ],
        'created' => true,
    ];
}

function scoring_add_competition_buddy(PDO $pdo, array $competition, array $inviter, string $email, ?string $name = null): array {
    if (!scoring_competition_buddies_available($pdo)) {
        throw new RuntimeException('De tabel rankings_scoring_competition_scorers ontbreekt nog. Voer database/scoring_schema.sql opnieuw uit.');
    }

    $result = scoring_find_or_create_scorer($pdo, $email, $name);
    $buddy = $result['scorer'];
    if ((int)$buddy['id'] === (int)$competition['scorer_id']) {
        throw new RuntimeException('Deze scorer is al eigenaar van de competitie.');
    }
    if ((int)$buddy['id'] === (int)$inviter['id']) {
        throw new RuntimeException('Je bent al scorer voor deze competitie.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO rankings_scoring_competition_scorers (competition_id, scorer_id, role, invited_by_scorer_id)
         VALUES (?, ?, 'buddy', ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role), invited_by_scorer_id = VALUES(invited_by_scorer_id)"
    );
    $stmt->execute([(int)$competition['id'], (int)$buddy['id'], (int)$inviter['id']]);

    $sent = scoring_send_competition_buddy_email(
        (string)$buddy['email'],
        (string)$competition['name'],
        $buddy['name'] ?? null,
        $inviter['name'] ?: $inviter['email']
    );

    return ['scorer' => $buddy, 'email_sent' => $sent, 'created' => (bool)$result['created']];
}

function scoring_remove_competition_buddy(PDO $pdo, int $competitionId, int $scorerId): void {
    if (!scoring_competition_buddies_available($pdo)) {
        throw new RuntimeException('De buddy scorer tabel ontbreekt.');
    }
    $stmt = $pdo->prepare('DELETE FROM rankings_scoring_competition_scorers WHERE competition_id = ? AND scorer_id = ?');
    $stmt->execute([$competitionId, $scorerId]);
}

function scoring_load_task_turnpoints(PDO $pdo, int $taskId): array {
    $stmt = $pdo->prepare(
        'SELECT tt.*, w.name, w.code, w.latitude, w.longitude, w.elevation_m
         FROM rankings_scoring_task_turnpoints tt
         JOIN rankings_scoring_waypoints w ON w.id = tt.waypoint_id
         WHERE tt.task_id = ?
         ORDER BY tt.sequence_no ASC'
    );
    $stmt->execute([$taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scoring_load_task_gates(PDO $pdo, int $taskId): array {
    $stmt = $pdo->prepare('SELECT * FROM rankings_scoring_task_start_gates WHERE task_id = ? ORDER BY gate_time_at ASC');
    $stmt->execute([$taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scoring_speed_section_indices(array $turnpoints): array {
    $sss = 0;
    $ess = max(0, count($turnpoints) - 1);
    foreach ($turnpoints as $idx => $tp) {
        if (!empty($tp['is_speed_section_start'])) {
            $sss = $idx;
        }
        if (!empty($tp['is_speed_section_end'])) {
            $ess = $idx;
        }
    }
    if ($ess < $sss) {
        $ess = $sss;
    }
    return [$sss, $ess];
}

function scoring_match_task_tracklogs(PDO $pdo, array $task, array $turnpoints): int {
    if (empty($turnpoints)) {
        return 0;
    }
    $bbox = scoring_task_bbox($turnpoints);

    $findMatches = function () use ($pdo, $task, $bbox): array {
        $stmt = $pdo->prepare(
            'SELECT id, pilot_name, pilot_email
             FROM rankings_scoring_tracklogs
             WHERE first_fix_at <= ?
               AND last_fix_at >= ?
               AND max_lat >= ?
               AND min_lat <= ?
               AND max_lon >= ?
               AND min_lon <= ?
               AND fix_count > 0
               AND storage_path <> ?'
        );
        $stmt->execute([
            $task['window_close_at'],
            $task['window_open_at'],
            $bbox['min_lat'],
            $bbox['max_lat'],
            $bbox['min_lon'],
            $bbox['max_lon'],
            '',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $tracklogs = $findMatches();
    if (empty($tracklogs)) {
        $stmt = $pdo->prepare(
            'SELECT id, storage_path
             FROM rankings_scoring_tracklogs
             WHERE max_lat >= ?
               AND min_lat <= ?
               AND max_lon >= ?
               AND min_lon <= ?
               AND fix_count > 0
               AND storage_path <> ?'
        );
        $stmt->execute([$bbox['min_lat'], $bbox['max_lat'], $bbox['min_lon'], $bbox['max_lon'], '']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            scoring_refresh_tracklog_metadata($pdo, $candidate);
        }
        $tracklogs = $findMatches();
    }

    if (empty($tracklogs)) {
        return 0;
    }

    $insert = $pdo->prepare(
        'INSERT INTO rankings_scoring_task_flights (task_id, tracklog_id, pilot_name, pilot_email)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE pilot_name = VALUES(pilot_name), pilot_email = VALUES(pilot_email)'
    );
    foreach ($tracklogs as $tracklog) {
        $insert->execute([(int)$task['id'], (int)$tracklog['id'], $tracklog['pilot_name'], $tracklog['pilot_email']]);
        if (scoring_pilot_identities_available($pdo)) {
            $lookup = $pdo->prepare('SELECT id FROM rankings_scoring_task_flights WHERE task_id = ? AND tracklog_id = ? LIMIT 1');
            $lookup->execute([(int)$task['id'], (int)$tracklog['id']]);
            $flightId = (int)$lookup->fetchColumn();
            if ($flightId > 0) {
                scoring_assign_known_task_flight_identity($pdo, (int)$task['competition_id'], $flightId, (string)$tracklog['pilot_name'], $tracklog['pilot_email'] ?? null);
            }
        }
    }
    return count($tracklogs);
}

function scoring_interpolated_cylinder_hit(array $fixes, array $turnpoint, int $cursorTs): ?array {
    $radiusM = (float)$turnpoint['radius_m'];
    $previous = null;
    foreach ($fixes as $fix) {
        if ($fix['ts'] < $cursorTs) {
            continue;
        }
        $distanceM = scoring_haversine_km(
            (float)$fix['lat'],
            (float)$fix['lon'],
            (float)$turnpoint['latitude'],
            (float)$turnpoint['longitude']
        ) * 1000.0;
        if ($distanceM <= $radiusM) {
            if ($previous !== null && $previous['distance_m'] > $radiusM && $fix['ts'] > $previous['fix']['ts']) {
                $denominator = max(0.001, $previous['distance_m'] - $distanceM);
                $fraction = max(0.0, min(1.0, ($previous['distance_m'] - $radiusM) / $denominator));
                $hit = $fix;
                $hit['ts'] = (int)round($previous['fix']['ts'] + (($fix['ts'] - $previous['fix']['ts']) * $fraction));
                $hit['time_utc'] = gmdate('Y-m-d H:i:s', $hit['ts']);
                $hit['lat'] = (float)$previous['fix']['lat'] + (((float)$fix['lat'] - (float)$previous['fix']['lat']) * $fraction);
                $hit['lon'] = (float)$previous['fix']['lon'] + (((float)$fix['lon'] - (float)$previous['fix']['lon']) * $fraction);
                return $hit;
            }
            return $fix;
        }
        $previous = ['fix' => $fix, 'distance_m' => $distanceM];
    }
    return null;
}

function scoring_evaluate_flight(array $task, array $turnpoints, array $gates, array $fixes): array {
    $windowOpen = strtotime($task['window_open_at'] . ' UTC');
    $windowClose = strtotime($task['window_close_at'] . ' UTC');
    $taskDistance = scoring_task_distance_km($turnpoints);
    $taskDistance = max($taskDistance, (float)$task['minimum_distance_km']);
    $route = array_values($turnpoints);
    $cumulative = scoring_route_cumulative($route);
    list($sssIndex, $essIndex) = scoring_speed_section_indices($turnpoints);
    $sssProgress = $cumulative[$sssIndex] ?? 0.0;
    $essProgress = $cumulative[$essIndex] ?? $taskDistance;
    $speedDistance = max(0.1, $essProgress - $sssProgress);

    $filtered = [];
    foreach ($fixes as $fix) {
        $ts = strtotime($fix['time_utc'] . ' UTC');
        if ($ts >= $windowOpen && $ts <= $windowClose) {
            $fix['ts'] = $ts;
            $filtered[] = $fix;
        }
    }
    if (empty($filtered)) {
        return [
            'distance_km' => (float)$task['minimum_distance_km'],
            'reached_ess' => false,
            'reached_goal' => false,
            'start_time_at' => null,
            'ess_time_at' => null,
            'goal_time_at' => null,
            'time_seconds' => null,
            'leading_coefficient' => null,
            'turnpoints_reached' => 0,
            'notes' => ['Geen fixes in taakvenster.'],
        ];
    }

    $reached = [];
    $lastReachedIndex = -1;
    $cursorTs = $windowOpen;
    foreach ($turnpoints as $idx => $tp) {
        $hit = scoring_interpolated_cylinder_hit($filtered, $tp, $cursorTs);
        if ($hit === null) {
            break;
        }
        $reached[$idx] = $hit;
        $lastReachedIndex = $idx;
        $cursorTs = $hit['ts'];
    }

    $reachedGoal = $lastReachedIndex === count($turnpoints) - 1;
    $nextTurnpointIndex = $reachedGoal ? null : max(0, $lastReachedIndex + 1);
    $closestDistanceToNext = null;
    if ($reachedGoal) {
        $distance = $taskDistance;
    } else {
        $completedDistance = $lastReachedIndex >= 0 ? (float)($cumulative[$lastReachedIndex] ?? 0.0) : 0.0;
        $distance = $completedDistance;
        if ($nextTurnpointIndex !== null && isset($turnpoints[$nextTurnpointIndex])) {
            $nextTurnpoint = $turnpoints[$nextTurnpointIndex];
            $closestDistanceToNext = PHP_FLOAT_MAX;
            $searchAfterTs = $lastReachedIndex >= 0 && isset($reached[$lastReachedIndex])
                ? (int)$reached[$lastReachedIndex]['ts']
                : $windowOpen;
            foreach ($filtered as $fix) {
                if ($fix['ts'] < $searchAfterTs) {
                    continue;
                }
                $remaining = scoring_haversine_km(
                    (float)$fix['lat'],
                    (float)$fix['lon'],
                    (float)$nextTurnpoint['latitude'],
                    (float)$nextTurnpoint['longitude']
                );
                $remaining = max(0.0, $remaining - (((float)$nextTurnpoint['radius_m']) / 1000.0));
                if ($remaining < $closestDistanceToNext) {
                    $closestDistanceToNext = $remaining;
                }
            }
            if ($closestDistanceToNext !== PHP_FLOAT_MAX) {
                $distanceToNext = (float)($cumulative[$nextTurnpointIndex] ?? $completedDistance);
                $distance = max($completedDistance, min($distanceToNext, $distanceToNext - $closestDistanceToNext));
            } else {
                $closestDistanceToNext = null;
            }
        }
    }
    $distance = max((float)$task['minimum_distance_km'], min($taskDistance, $distance));
    $reachedEss = isset($reached[$essIndex]);

    $startTs = null;
    if (isset($reached[$sssIndex])) {
        $crossTs = $reached[$sssIndex]['ts'];
        if (($task['task_type'] ?? 'race') === 'race' && !empty($gates)) {
            $gateTimes = array_map(function ($gate) { return strtotime($gate['gate_time_at'] . ' UTC'); }, $gates);
            sort($gateTimes);
            $startTs = $gateTimes[0] ?? $crossTs;
            foreach ($gateTimes as $gateTs) {
                if ($gateTs <= $crossTs) {
                    $startTs = $gateTs;
                }
            }
        } else {
            $startTs = $crossTs;
        }
    }
    $essTs = $reachedEss ? $reached[$essIndex]['ts'] : null;
    $goalTs = $reachedGoal ? $reached[count($turnpoints) - 1]['ts'] : null;
    $timeSeconds = ($startTs !== null && $essTs !== null && $essTs >= $startTs) ? ($essTs - $startTs) : null;

    $leadingCoefficient = null;
    if ($startTs !== null) {
        $lastTs = $startTs;
        $minToEss = $speedDistance;
        $area = 0.0;
        foreach ($filtered as $fix) {
            if ($fix['ts'] < $startTs) {
                continue;
            }
            if ($essTs !== null && $fix['ts'] > $essTs) {
                break;
            }
            $progress = min($distance, scoring_project_progress_km((float)$fix['lat'], (float)$fix['lon'], $route, $cumulative));
            $progressInSpeed = max(0.0, min($speedDistance, $progress - $sssProgress));
            $minToEss = min($minToEss, $speedDistance - $progressInSpeed);
            $dt = max(0, $fix['ts'] - $lastTs);
            $area += $minToEss * $dt;
            $lastTs = $fix['ts'];
        }
        $leadingCoefficient = $area / (3600.0 * max(0.1, $speedDistance));
    }

    $tsToSql = function ($ts) {
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    };

    return [
        'distance_km' => round($distance, 3),
        'reached_ess' => $reachedEss,
        'reached_goal' => $reachedGoal,
        'start_time_at' => $tsToSql($startTs),
        'ess_time_at' => $tsToSql($essTs),
        'goal_time_at' => $tsToSql($goalTs),
        'time_seconds' => $timeSeconds,
        'leading_coefficient' => $leadingCoefficient,
        'turnpoints_reached' => count($reached),
        'last_reached_turnpoint_index' => $lastReachedIndex,
        'next_turnpoint_index' => $nextTurnpointIndex,
        'closest_distance_to_next_km' => $closestDistanceToNext !== null ? round($closestDistanceToNext, 3) : null,
        'notes' => [],
    ];
}

function scoring_manual_minimum_evaluation(array $task): array {
    return [
        'distance_km' => round(max(0.0, (float)$task['minimum_distance_km']), 3),
        'reached_ess' => false,
        'reached_goal' => false,
        'start_time_at' => null,
        'ess_time_at' => null,
        'goal_time_at' => null,
        'time_seconds' => null,
        'leading_coefficient' => null,
        'turnpoints_reached' => 0,
        'last_reached_turnpoint_index' => -1,
        'next_turnpoint_index' => 0,
        'closest_distance_to_next_km' => null,
        'manual_minimum_distance' => true,
        'notes' => ['Handmatig minimumafstand zonder tracklog.'],
    ];
}

function scoring_manual_dnf_evaluation(): array {
    return [
        'distance_km' => 0.0,
        'reached_ess' => false,
        'reached_goal' => false,
        'start_time_at' => null,
        'ess_time_at' => null,
        'goal_time_at' => null,
        'time_seconds' => null,
        'leading_coefficient' => null,
        'turnpoints_reached' => 0,
        'last_reached_turnpoint_index' => -1,
        'next_turnpoint_index' => null,
        'closest_distance_to_next_km' => null,
        'result_status' => 'dnf',
        'notes' => ['DNF: piloot is gestart/aangemeld, maar er is geen te scoren vlucht.'],
    ];
}

function scoring_manual_abs_evaluation(): array {
    return [
        'distance_km' => null,
        'reached_ess' => false,
        'reached_goal' => false,
        'start_time_at' => null,
        'ess_time_at' => null,
        'goal_time_at' => null,
        'time_seconds' => null,
        'leading_coefficient' => null,
        'turnpoints_reached' => 0,
        'last_reached_turnpoint_index' => -1,
        'next_turnpoint_index' => null,
        'closest_distance_to_next_km' => null,
        'result_status' => 'abs',
        'notes' => ['ABS: piloot afwezig voor deze taak.'],
    ];
}

function scoring_enabled_components(array $task): array {
    return [
        'distance' => !empty($task['use_distance_points']),
        'time' => !empty($task['use_time_points']),
        'departure' => !empty($task['use_departure_points']),
        'leading' => !empty($task['use_leading_points']),
        'arrival_position' => !empty($task['use_arrival_position_points']),
        'arrival_time' => !empty($task['use_arrival_time_points']),
    ];
}

function scoring_gap_distance_difficulty(array $included, float $minDistance, float $bestDistance): array {
    if ($bestDistance <= 0.0) {
        return ['enabled' => false, 'scores' => []];
    }

    $maxSlot = max(0, (int)floor($bestDistance * 10.0));
    $minSlot = max(0, (int)floor($minDistance * 10.0));
    $landed = array_fill(0, $maxSlot + 2, 0);
    $landedOutCount = 0;

    foreach ($included as $entry) {
        $ev = $entry['evaluation'];
        if (!empty($ev['reached_goal'])) {
            continue;
        }
        $landedOutCount++;
        $slot = (int)floor(((float)$ev['distance_km']) * 10.0);
        $slot = max($minSlot, min($maxSlot, $slot));
        $landed[$slot]++;
    }

    if ($landedOutCount <= 0) {
        return ['enabled' => false, 'scores' => []];
    }

    $lookAhead = max(30, (int)round(30.0 * $bestDistance / $landedOutCount));
    $difficulty = array_fill(0, $maxSlot + 2, 0.0);
    $sumDifficulty = 0.0;
    for ($i = 0; $i <= $maxSlot; $i++) {
        $to = min($maxSlot, $i + $lookAhead);
        for ($j = $i; $j <= $to; $j++) {
            $difficulty[$i] += $landed[$j] ?? 0;
        }
        $sumDifficulty += $difficulty[$i];
    }

    if ($sumDifficulty <= 0.0) {
        return ['enabled' => false, 'scores' => []];
    }

    $scores = array_fill(0, $maxSlot + 2, 0.0);
    $cumulative = 0.0;
    for ($i = 0; $i <= $maxSlot; $i++) {
        $cumulative += $difficulty[$i] / (2.0 * $sumDifficulty);
        $scores[$i] = $cumulative;
    }
    $scores[$maxSlot + 1] = $scores[$maxSlot];

    return ['enabled' => true, 'scores' => $scores];
}

function scoring_gap_difficulty_fraction(float $distance, array $difficulty): float {
    if (empty($difficulty['enabled']) || empty($difficulty['scores'])) {
        return 0.0;
    }
    $slotFloat = max(0.0, $distance * 10.0);
    $slot = (int)floor($slotFloat);
    $fraction = $slotFloat - $slot;
    $scores = $difficulty['scores'];
    $fallback = (float)end($scores);
    $base = (float)($scores[$slot] ?? $fallback);
    $next = (float)($scores[$slot + 1] ?? $base);
    return $base + (($next - $base) * $fraction);
}

function scoring_allocate_gap2025_points(array $task, array $evaluations, float $taskDistance): array {
    $included = array_values($evaluations);
    $count = count($included);
    $minDistance = (float)$task['minimum_distance_km'];
    $nominalDistance = max($minDistance + 0.1, (float)$task['nominal_distance_km']);
    $nominalTimeHours = max(0.1, ((int)$task['nominal_time_minutes']) / 60.0);
    $bestDistance = 0.0;
    $sumOverMinimum = 0.0;
    $goalCount = 0;
    $bestTimeHours = null;
    $bestLeading = null;

    foreach ($included as $entry) {
        $distance = (float)$entry['evaluation']['distance_km'];
        $bestDistance = max($bestDistance, $distance);
        $sumOverMinimum += max(0.0, $distance - $minDistance);
        if (!empty($entry['evaluation']['reached_goal'])) {
            $goalCount++;
        }
        if (!empty($entry['evaluation']['reached_goal']) && $entry['evaluation']['time_seconds']) {
            $hours = $entry['evaluation']['time_seconds'] / 3600.0;
            $bestTimeHours = $bestTimeHours === null ? $hours : min($bestTimeHours, $hours);
        }
        if ($entry['evaluation']['leading_coefficient'] !== null) {
            $lc = (float)$entry['evaluation']['leading_coefficient'];
            $bestLeading = $bestLeading === null ? $lc : min($bestLeading, $lc);
        }
    }

    $distanceValidity = $count > 0
        ? min(1.0, max(
            $bestDistance / $nominalDistance,
            $sumOverMinimum / max(0.1, $count * ($nominalDistance - $minDistance))
        ))
        : 0.0;
    $timeValidity = $bestTimeHours !== null
        ? min(1.0, $bestTimeHours / $nominalTimeHours)
        : min(1.0, $bestDistance / $nominalDistance);
    $taskValidity = min(1.0, max(0.0, $distanceValidity * max(0.25, $timeValidity)));
    $goalRatio = $count > 0 ? $goalCount / $count : 0.0;

    $components = scoring_enabled_components($task);
    $arrivalEnabled = $components['arrival_position'] || $components['arrival_time'];
    $baseDistanceWeight = max(0.25, min(0.9, 0.9 - (1.665 * $goalRatio) + (1.713 * $goalRatio * $goalRatio) - (0.587 * $goalRatio * $goalRatio * $goalRatio)));
    $distanceWeight = $components['distance'] ? $baseDistanceWeight : 0.0;
    $remainingWeight = max(0.0, 1.0 - $distanceWeight);
    $leadingTimeRatio = 0.175; // GAP2025 default for hang gliding.
    $arrivalRatio = 0.125;

    $leadingWeight = 0.0;
    $arrivalWeight = 0.0;
    $timeWeight = 0.0;
    if ($goalCount > 0) {
        $leadingWeight = $components['leading'] ? $remainingWeight * $leadingTimeRatio : 0.0;
        $arrivalWeight = $arrivalEnabled ? $remainingWeight * $arrivalRatio : 0.0;
        $timeWeight = $components['time'] ? max(0.0, $remainingWeight - $leadingWeight - $arrivalWeight) : 0.0;
    } else {
        $leadingWeight = $components['leading'] ? $remainingWeight : 0.0;
    }

    $weightSum = $distanceWeight + $timeWeight + $leadingWeight + $arrivalWeight;
    $leftoverWeight = max(0.0, 1.0 - $weightSum);
    if ($leftoverWeight > 0.000001) {
        if ($components['time'] && $goalCount > 0) {
            $timeWeight += $leftoverWeight;
        } elseif ($components['distance']) {
            $distanceWeight += $leftoverWeight;
        } elseif ($components['leading']) {
            $leadingWeight += $leftoverWeight;
        } elseif ($arrivalEnabled) {
            $arrivalWeight += $leftoverWeight;
        }
    }

    $available = [
        'distance' => 1000.0 * $taskValidity * $distanceWeight,
        'time' => 1000.0 * $taskValidity * $timeWeight,
        'leading' => 1000.0 * $taskValidity * $leadingWeight,
        'arrival' => 1000.0 * $taskValidity * $arrivalWeight,
    ];

    $goalOrder = $included;
    usort($goalOrder, function ($a, $b) {
        $aGoal = !empty($a['evaluation']['reached_goal']);
        $bGoal = !empty($b['evaluation']['reached_goal']);
        if ($aGoal !== $bGoal) {
            return $aGoal ? -1 : 1;
        }
        return (($a['evaluation']['goal_time_at'] ?? '') <=> ($b['evaluation']['goal_time_at'] ?? ''));
    });
    $goalRanks = [];
    $rank = 1;
    foreach ($goalOrder as $entry) {
        if (!empty($entry['evaluation']['reached_goal'])) {
            $goalRanks[$entry['flight']['id']] = $rank++;
        }
    }

    $difficulty = scoring_gap_distance_difficulty($included, $minDistance, $bestDistance);
    $scored = [];
    foreach ($included as $entry) {
        $flightId = (int)$entry['flight']['id'];
        $ev = $entry['evaluation'];
        $distance = min($taskDistance, max($minDistance, (float)$ev['distance_km']));
        if (!empty($difficulty['enabled'])) {
            $distanceFraction = min(1.0, ($bestDistance > 0.0 ? $distance / (2.0 * $bestDistance) : 0.0) + scoring_gap_difficulty_fraction($distance, $difficulty));
        } else {
            $distanceFraction = $bestDistance > 0.0 ? min(1.0, $distance / $bestDistance) : 0.0;
        }
        $distancePoints = $available['distance'] * $distanceFraction;
        $timePoints = 0.0;
        if ($bestTimeHours !== null && !empty($ev['reached_goal']) && $ev['time_seconds']) {
            $hours = $ev['time_seconds'] / 3600.0;
            $zeroAt = $bestTimeHours + sqrt($bestTimeHours);
            $fraction = $hours >= $zeroAt
                ? 0.0
                : max(0.0, 1.0 - pow(max(0.0, ($hours - $bestTimeHours) / max(0.001, $zeroAt - $bestTimeHours)), 5.0 / 6.0));
            $timePoints = $available['time'] * $fraction;
        }
        $leadingPoints = 0.0;
        if ($bestLeading !== null && $ev['leading_coefficient'] !== null) {
            $lc = (float)$ev['leading_coefficient'];
            $fraction = $lc <= $bestLeading
                ? 1.0
                : max(0.0, 1.0 - sqrt(max(0.0, ($lc - $bestLeading) / max(0.001, $bestLeading))));
            $leadingPoints = $available['leading'] * $fraction;
        }
        $arrivalPositionPoints = 0.0;
        $arrivalTimePoints = 0.0;
        if (!empty($ev['reached_goal']) && isset($goalRanks[$flightId]) && $available['arrival'] > 0.0) {
            $goalRank = $goalRanks[$flightId];
            $arrivalFraction = $goalCount > 1 ? max(0.0, 1.0 - (($goalRank - 1) / max(1, $goalCount - 1))) : 1.0;
            $arrivalCurve = 0.2 + (0.037 * $arrivalFraction) + (0.13 * $arrivalFraction * $arrivalFraction) + (0.633 * $arrivalFraction * $arrivalFraction * $arrivalFraction);
            if ($components['arrival_position'] && $components['arrival_time']) {
                $arrivalPositionPoints = $available['arrival'] * 0.5 * $arrivalCurve;
                $arrivalTimePoints = $available['arrival'] * 0.5 * $arrivalCurve;
            } elseif ($components['arrival_position']) {
                $arrivalPositionPoints = $available['arrival'] * $arrivalCurve;
            } elseif ($components['arrival_time']) {
                $arrivalTimePoints = $available['arrival'] * $arrivalCurve;
            }
        }

        $departurePoints = 0.0;
        $total = $distancePoints + $timePoints + $leadingPoints + $arrivalPositionPoints + $arrivalTimePoints + $departurePoints;
        $scored[$flightId] = [
            'distance_points' => round($distancePoints, 1),
            'time_points' => round($timePoints, 1),
            'departure_points' => round($departurePoints, 1),
            'leading_points' => round($leadingPoints, 1),
            'arrival_position_points' => round($arrivalPositionPoints, 1),
            'arrival_time_points' => round($arrivalTimePoints, 1),
            'total_points' => round($total, 1),
        ];
    }

    return [
        'points' => $scored,
        'summary' => [
            'formula_version' => 'GAP2025',
            'implementation_note' => '',
            'pilots_scored' => $count,
            'pilots_in_goal' => $goalCount,
            'task_distance_km' => round($taskDistance, 3),
            'best_distance_km' => round($bestDistance, 3),
            'best_time_seconds' => $bestTimeHours !== null ? (int)round($bestTimeHours * 3600) : null,
            'distance_validity' => round($distanceValidity, 4),
            'time_validity' => round($timeValidity, 4),
            'task_validity' => round($taskValidity, 4),
            'goal_ratio' => round($goalRatio, 4),
            'point_weights' => [
                'distance' => round($distanceWeight, 4),
                'time' => round($timeWeight, 4),
                'leading' => round($leadingWeight, 4),
                'arrival' => round($arrivalWeight, 4),
            ],
            'available_points' => [
                'distance' => round($available['distance'], 1),
                'time' => round($available['time'], 1),
                'leading' => round($available['leading'], 1),
                'arrival' => round($available['arrival'], 1),
            ],
            'enabled_components' => $components,
        ],
    ];
}

function scoring_score_task(PDO $pdo, int $taskId): array {
    scoring_ensure_task_review_columns($pdo);
    $task = scoring_load_task($pdo, $taskId);
    if (!$task) {
        throw new RuntimeException('Taak niet gevonden.');
    }
    $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
    if (count($turnpoints) < 2) {
        throw new RuntimeException('Voeg minimaal twee taakpunten toe voordat je scoort.');
    }
    $gates = scoring_load_task_gates($pdo, $taskId);
    if (($task['task_type'] ?? 'race') === 'race' && empty($gates)) {
        throw new RuntimeException('Een race-taak heeft minimaal een startgate nodig.');
    }

    $stmt = $pdo->prepare(
        'SELECT f.*, tl.storage_path, tl.original_filename, tl.fix_count
         FROM rankings_scoring_task_flights f
         JOIN rankings_scoring_tracklogs tl ON tl.id = f.tracklog_id
         WHERE f.task_id = ?
         ORDER BY f.is_excluded ASC, f.pilot_name ASC'
    );
    $stmt->execute([$taskId]);
    $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $scoreableGroups = [];
    foreach ($flights as $flight) {
        $resultStatus = scoring_task_flight_result_status($flight);
        if ((int)$flight['is_excluded'] === 1 || $resultStatus === 'abs' || $resultStatus === 'alternate') {
            continue;
        }
        $scoreableGroups[scoring_task_review_group_key($flight)][] = $flight;
    }
    foreach ($scoreableGroups as $groupFlights) {
        if (count($groupFlights) > 1) {
            throw new RuntimeException('Er staan meerdere scoring-kandidaten open voor ' . scoring_task_review_group_label($groupFlights[0]) . '. Kies in Track review eerst een enkele scoring rij per piloot.');
        }
    }

    $included = [];
    $dnfFlights = [];
    $evaluationsByFlight = [];
    $taskDistance = scoring_task_distance_km($turnpoints);

    foreach ($flights as $flight) {
        $resultStatus = scoring_task_flight_result_status($flight);
        if ((int)$flight['is_excluded'] === 1 || $resultStatus === 'abs' || $resultStatus === 'alternate') {
            continue;
        }
        if ($resultStatus === 'dnf') {
            $dnfFlights[] = $flight;
            continue;
        }
        if ($resultStatus === 'minimum_distance' || scoring_is_manual_minimum_tracklog($flight)) {
            $evaluation = scoring_manual_minimum_evaluation($task);
        } else {
            $path = scoring_public_upload_path($flight['storage_path']);
            $igc = scoring_parse_igc_file($path);
            $evaluation = scoring_evaluate_flight($task, $turnpoints, $gates, $igc['fixes']);
        }
        $included[] = ['flight' => $flight, 'evaluation' => $evaluation];
        $evaluationsByFlight[(int)$flight['id']] = $evaluation;
    }
    if (empty($included) && empty($dnfFlights)) {
        throw new RuntimeException('Geen te scoren tracklogs gevonden voor dit taakvenster en gebied.');
    }

    $allocation = !empty($included)
        ? scoring_allocate_gap2025_points($task, $included, $taskDistance)
        : [
            'points' => [],
            'summary' => [
                'formula_version' => 'GAP2025',
                'implementation_note' => '',
                'pilots_scored' => 0,
                'pilots_in_goal' => 0,
                'pilots_dnf' => 0,
                'task_distance_km' => round($taskDistance, 3),
                'best_distance_km' => 0.0,
                'best_time_seconds' => null,
                'distance_validity' => 0.0,
                'time_validity' => 0.0,
                'task_validity' => 0.0,
                'goal_ratio' => 0.0,
                'point_weights' => ['distance' => 0.0, 'time' => 0.0, 'leading' => 0.0, 'arrival' => 0.0],
                'available_points' => ['distance' => 0.0, 'time' => 0.0, 'leading' => 0.0, 'arrival' => 0.0],
                'enabled_components' => scoring_enabled_components($task),
            ],
        ];
    $rankRows = [];
    foreach ($included as $entry) {
        $flightId = (int)$entry['flight']['id'];
        $points = $allocation['points'][$flightId] ?? null;
        if ($points) {
            $rankRows[] = ['flight_id' => $flightId, 'points' => $points['total_points'], 'pilot' => $entry['flight']['pilot_name']];
        }
    }
    usort($rankRows, function ($a, $b) {
        if (abs($a['points'] - $b['points']) < 0.0001) {
            return strcasecmp($a['pilot'], $b['pilot']);
        }
        return $a['points'] < $b['points'] ? 1 : -1;
    });
    $ranks = [];
    $rank = 0;
    $shown = 0;
    $previous = null;
    foreach ($rankRows as $row) {
        $shown++;
        if ($previous === null || abs($row['points'] - $previous) > 0.0001) {
            $rank = $shown;
            $previous = $row['points'];
        }
        $ranks[$row['flight_id']] = $rank;
    }

    $update = $pdo->prepare(
        'UPDATE rankings_scoring_task_flights
         SET distance_km = ?, start_time_at = ?, ess_time_at = ?, goal_time_at = ?, time_seconds = ?,
             reached_ess = ?, reached_goal = ?, distance_points = ?, time_points = ?, departure_points = ?,
             leading_points = ?, arrival_position_points = ?, arrival_time_points = ?, total_points = ?,
             rank_no = ?, evaluation_json = ?, scored_at = NOW()
         WHERE id = ?'
    );
    foreach ($included as $entry) {
        $flightId = (int)$entry['flight']['id'];
        $ev = $evaluationsByFlight[$flightId];
        $points = $allocation['points'][$flightId];
        $update->execute([
            $ev['distance_km'],
            $ev['start_time_at'],
            $ev['ess_time_at'],
            $ev['goal_time_at'],
            $ev['time_seconds'],
            $ev['reached_ess'] ? 1 : 0,
            $ev['reached_goal'] ? 1 : 0,
            $points['distance_points'],
            $points['time_points'],
            $points['departure_points'],
            $points['leading_points'],
            $points['arrival_position_points'],
            $points['arrival_time_points'],
            $points['total_points'],
            $ranks[$flightId] ?? null,
            json_encode($ev, JSON_UNESCAPED_UNICODE),
            $flightId,
        ]);
    }

    $dnfUpdate = $pdo->prepare(
        'UPDATE rankings_scoring_task_flights
         SET distance_km = 0, start_time_at = NULL, ess_time_at = NULL, goal_time_at = NULL, time_seconds = NULL,
             reached_ess = 0, reached_goal = 0, distance_points = 0, time_points = 0, departure_points = 0,
             leading_points = 0, arrival_position_points = 0, arrival_time_points = 0, total_points = 0,
             rank_no = NULL, evaluation_json = ?, scored_at = NOW()
         WHERE id = ?'
    );
    foreach ($dnfFlights as $flight) {
        $dnfUpdate->execute([json_encode(scoring_manual_dnf_evaluation(), JSON_UNESCAPED_UNICODE), (int)$flight['id']]);
    }

    $summary = $allocation['summary'];
    $summary['pilots_dnf'] = count($dnfFlights);
    $pdo->prepare(
        "UPDATE rankings_scoring_tasks
         SET status = CASE WHEN status = 'published' THEN 'published' ELSE 'scored' END,
             task_distance_km = ?, scoring_summary_json = ?, scored_at = NOW()
         WHERE id = ?"
    )->execute([round($taskDistance, 3), json_encode($summary, JSON_UNESCAPED_UNICODE), $taskId]);

    return $summary;
}
