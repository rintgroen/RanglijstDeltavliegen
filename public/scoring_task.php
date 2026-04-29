<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/scoring.php';

class PublicScoringTaskPdf {
    private $pages = [];
    private $current = '';
    private $width = 842.0;
    private $height = 595.0;

    public function addPage(): void {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    public function text(float $x, float $y, string $text, int $size = 10, string $font = 'F1'): void {
        $this->current .= 'BT /' . $font . ' ' . $size . ' Tf 1 0 0 1 '
            . $this->num($x) . ' ' . $this->num($this->height - $y)
            . ' Tm (' . $this->escapeText($text) . ") Tj ET\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->current .= $this->num($x1) . ' ' . $this->num($this->height - $y1) . ' m '
            . $this->num($x2) . ' ' . $this->num($this->height - $y2) . " l S\n";
    }

    public function output(): string {
        if ($this->current !== '' || empty($this->pages)) {
            $this->pages[] = $this->current;
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        $nextObject = 6;
        foreach ($this->pages as $page) {
            $contentObject = $nextObject++;
            $pageObject = $nextObject++;
            $objects[$contentObject] = "<< /Length " . strlen($page) . " >>\nstream\n" . $page . "endstream";
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . $this->num($this->width) . ' ' . $this->num($this->height)
                . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents '
                . $contentObject . ' 0 R >>';
            $kids[] = $pageObject . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function num(float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function escapeText(string $text): string {
        $encoded = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) : false;
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text);
            if (!is_string($encoded)) {
                $encoded = '';
            }
        }
        return str_replace(["\\", '(', ')', "\r"], ["\\\\", "\\(", "\\)", ''], $encoded);
    }
}

function public_scoring_text_length(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function public_scoring_text_slice(string $text, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
}

function public_scoring_fit_text(string $text, int $width): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (public_scoring_text_length($text) <= $width) {
        return $text;
    }
    return rtrim(public_scoring_text_slice($text, 0, max(1, $width - 3))) . '...';
}

function public_scoring_pad_cell(string $text, int $width, string $align = 'left'): string {
    $text = public_scoring_fit_text($text, $width);
    $pad = max(0, $width - public_scoring_text_length($text));
    return $align === 'right' ? str_repeat(' ', $pad) . $text : $text . str_repeat(' ', $pad);
}

function public_scoring_table_line(array $cells): string {
    $parts = [];
    foreach ($cells as $cell) {
        $parts[] = public_scoring_pad_cell((string)$cell[0], (int)$cell[1], (string)($cell[2] ?? 'left'));
    }
    return implode('  ', $parts);
}

function public_scoring_wrap_text(string $text, int $width): array {
    $words = preg_split('/\s+/', trim($text));
    if (!$words) {
        return [''];
    }

    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (public_scoring_text_length($candidate) <= $width) {
            $line = $candidate;
            continue;
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        while (public_scoring_text_length($word) > $width) {
            $lines[] = public_scoring_text_slice($word, 0, $width);
            $word = public_scoring_text_slice($word, $width, public_scoring_text_length($word));
        }
        $line = $word;
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines ?: [''];
}

function public_scoring_download_slug(string $value, string $fallback = 'download'): string {
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value) : false;
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';
    $value = strtolower(trim($value, '-'));
    return $value !== '' ? substr($value, 0, 90) : $fallback;
}

function public_scoring_task_download_filename(array $task, ?array $competition, string $suffix): string {
    $base = (($competition['name'] ?? $task['competition_name'] ?? 'competitie') . '-' . ($task['name'] ?? 'taak'));
    return public_scoring_download_slug((string)$base, 'taak') . '-' . $suffix;
}

function public_scoring_send_binary_download(string $content, string $contentType, string $filename): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

function public_scoring_build_task_results_pdf(array $task, ?array $competition, array $publication, array $summary, array $turnpoints, array $results): string {
    $pdf = new PublicScoringTaskPdf();
    $pdf->addPage();
    $margin = 34.0;
    $right = 808.0;
    $bottom = 560.0;
    $y = 34.0;

    $ensurePage = function (float $needed = 14.0) use ($pdf, &$y, $margin, $bottom): void {
        if ($y + $needed > $bottom) {
            $pdf->addPage();
            $y = $margin;
        }
    };
    $write = function (string $text, int $size = 10, string $font = 'F1', float $indent = 0.0) use ($pdf, &$y, $margin, $ensurePage): void {
        $ensurePage($size + 6.0);
        $pdf->text($margin + $indent, $y, $text, $size, $font);
        $y += $size + 5.0;
    };
    $writeWrapped = function (string $text, int $width = 118, int $size = 10, string $font = 'F1', float $indent = 0.0) use ($write): void {
        foreach (public_scoring_wrap_text($text, $width) as $line) {
            $write($line, $size, $font, $indent);
        }
    };
    $writeMono = function (string $text, bool $bold = false) use ($pdf, &$y, $margin, $ensurePage): void {
        $ensurePage(11.0);
        $pdf->text($margin, $y, $text, 8, $bold ? 'F2' : 'F3');
        $y += 10.0;
    };

    $competitionName = (string)($competition['name'] ?? $task['competition_name'] ?? app_site_name());
    $taskName = (string)($task['name'] ?? 'Taak');
    $write($competitionName, 18, 'F2');
    $write($taskName . ' - taakresultaten', 14, 'F2');
    $pdf->line($margin, $y - 6.0, $right, $y - 6.0);
    $y += 6.0;

    if (!empty($competition['location'])) {
        $writeWrapped('Locatie: ' . (string)$competition['location']);
    }
    $writeWrapped('Publicatie: ' . scoring_utc_sql_to_display($publication['published_at'] ?? null));
    $writeWrapped('Klasse/type: ' . (string)($competition['class'] ?? $task['class'] ?? '-') . ' - ' . (string)($competition['scope'] ?? $task['scope'] ?? '-'));
    $y += 5.0;

    $write('Taak', 13, 'F2');
    $meta = [
        'Datum: ' . (string)($task['task_date'] ?? '-'),
        'Type: ' . (string)($task['task_type'] ?? '-'),
        'Afstand: ' . app_format_compact_number($publication['task_distance_km'] ?: 0, 3) . ' km',
        'Validiteit: ' . app_format_compact_number(($summary['task_validity'] ?? 0) * 100, 1) . '%',
    ];
    foreach ($meta as $line) {
        $write($line, 10, 'F1', 10.0);
    }

    if (!empty($turnpoints)) {
        [$sssIndex, $essIndex] = scoring_speed_section_indices($turnpoints);
        $write('Taakpunten', 11, 'F2', 10.0);
        foreach ($turnpoints as $idx => $tp) {
            $roles = [];
            if ($idx === $sssIndex) {
                $roles[] = 'SSS';
            }
            if ($idx === $essIndex) {
                $roles[] = 'ESS';
            }
            $line = ((int)$idx + 1) . '. ' . (string)$tp['name'] . ' - ' . (int)$tp['radius_m'] . ' m';
            if (!empty($roles)) {
                $line .= ' (' . implode(' / ', $roles) . ')';
            }
            $writeWrapped($line, 110, 9, 'F1', 20.0);
        }
    }

    $y += 8.0;
    $write('Resultaten', 13, 'F2');
    if (empty($results)) {
        $write('Geen resultaten beschikbaar.', 10, 'F1', 10.0);
    } else {
        $tableHeader = public_scoring_table_line([
            ['#', 4, 'right'],
            ['Piloot', 28],
            ['Afstand', 13, 'right'],
            ['Tijd', 9, 'right'],
            ['Afst', 7, 'right'],
            ['Tijd', 7, 'right'],
            ['Lead', 7, 'right'],
            ['Arr', 7, 'right'],
            ['Totaal', 7, 'right'],
        ]);
        $writeMono($tableHeader);
        $writeMono(str_repeat('-', public_scoring_text_length($tableHeader)));
        foreach ($results as $row) {
            if ($y + 10.0 > $bottom) {
                $pdf->addPage();
                $y = $margin;
                $write('Resultaten (vervolg)', 12, 'F2');
                $writeMono($tableHeader);
                $writeMono(str_repeat('-', public_scoring_text_length($tableHeader)));
            }
            $distance = $row['distance_km'] !== null ? app_format_compact_number($row['distance_km'], 3) . ' km' : '-';
            if ((int)$row['reached_goal'] === 1) {
                $distance .= ' goal';
            }
            $arrival = ((float)$row['arrival_position_points']) + ((float)$row['arrival_time_points']);
            $writeMono(public_scoring_table_line([
                [(string)($row['rank_no'] ?? '-'), 4, 'right'],
                [(string)$row['pilot_name'], 28],
                [$distance, 13, 'right'],
                [scoring_format_duration($row['time_seconds'] !== null ? (int)$row['time_seconds'] : null), 9, 'right'],
                [app_format_compact_number($row['distance_points'], 1), 7, 'right'],
                [app_format_compact_number($row['time_points'], 1), 7, 'right'],
                [app_format_compact_number($row['leading_points'], 1), 7, 'right'],
                [app_format_compact_number($arrival, 1), 7, 'right'],
                [app_format_compact_number($row['total_points'], 1), 7, 'right'],
            ]));
        }
    }

    if (!empty($summary['implementation_note'])) {
        $y += 8.0;
        $writeWrapped('Opmerking: ' . (string)$summary['implementation_note'], 118, 9, 'F1');
    }

    return $pdf->output();
}

function public_scoring_resolve_tracklog_path(string $relative): ?string {
    $path = scoring_public_upload_path($relative);
    $realPath = realpath($path);
    $uploadRoot = realpath(scoring_upload_root());
    if (!$realPath || !$uploadRoot || strpos($realPath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
        return null;
    }
    return $realPath;
}

function public_scoring_safe_zip_basename(string $name): string {
    $name = basename($name);
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $name) : false;
    if ($ascii !== false) {
        $name = $ascii;
    }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
    $name = trim($name, '._-');
    return $name !== '' ? substr($name, 0, 120) : 'tracklog.igc';
}

function public_scoring_unique_zip_name(array $row, array &$usedNames): string {
    $rank = (int)($row['rank_no'] ?? 0);
    $prefix = $rank > 0 ? sprintf('%03d_', $rank) : '';
    $pilot = public_scoring_download_slug((string)($row['pilot_name'] ?? 'pilot'), 'pilot');
    $original = public_scoring_safe_zip_basename((string)($row['original_filename'] ?? 'tracklog.igc'));
    $candidate = $prefix . $pilot . '_' . $original;
    $extension = pathinfo($candidate, PATHINFO_EXTENSION);
    $stem = $extension !== '' ? substr($candidate, 0, -(strlen($extension) + 1)) : $candidate;
    $suffix = $extension !== '' ? '.' . $extension : '';
    $name = $candidate;
    $counter = 2;
    while (isset($usedNames[strtolower($name)])) {
        $name = $stem . '-' . $counter . $suffix;
        $counter++;
    }
    $usedNames[strtolower($name)] = true;
    return $name;
}

function public_scoring_load_task_tracklog_entries(PDO $pdo, int $taskId): array {
    $stmt = $pdo->prepare(
        'SELECT pr.rank_no, pr.pilot_name, tl.original_filename, tl.storage_path
         FROM rankings_scoring_task_public_results pr
         JOIN rankings_scoring_task_flights f
           ON f.id = pr.source_flight_id AND f.task_id = pr.task_id
         JOIN rankings_scoring_tracklogs tl ON tl.id = f.tracklog_id
         WHERE pr.task_id = ?
           AND tl.storage_path <> ?
         ORDER BY pr.rank_no ASC, pr.pilot_name ASC, tl.original_filename ASC'
    );
    $stmt->execute([$taskId, '']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $entries = [];
    $usedNames = [];
    foreach ($rows as $row) {
        $path = public_scoring_resolve_tracklog_path((string)$row['storage_path']);
        if ($path === null) {
            continue;
        }
        $entries[] = [
            'name' => public_scoring_unique_zip_name($row, $usedNames),
            'path' => $path,
        ];
    }
    return $entries;
}

function public_scoring_zip_datetime(int $timestamp): array {
    $parts = getdate($timestamp);
    $year = max(1980, (int)$parts['year']);
    $dosTime = ((int)$parts['hours'] << 11) | ((int)$parts['minutes'] << 5) | ((int)floor((int)$parts['seconds'] / 2));
    $dosDate = (($year - 1980) << 9) | ((int)$parts['mon'] << 5) | (int)$parts['mday'];
    return [$dosTime, $dosDate];
}

function public_scoring_stream_tracklog_zip(array $entries, string $filename): void {
    $prepared = [];
    foreach ($entries as $entry) {
        if (!is_readable($entry['path'])) {
            continue;
        }
        $size = filesize($entry['path']);
        $crc = hash_file('crc32b', $entry['path']);
        if ($size === false || $crc === false || $size > 0xFFFFFFFF) {
            continue;
        }
        $prepared[] = [
            'name' => $entry['name'],
            'path' => $entry['path'],
            'size' => (int)$size,
            'crc' => hexdec($crc),
            'mtime' => filemtime($entry['path']) ?: time(),
        ];
    }

    if (empty($prepared)) {
        http_response_code(404);
        echo 'Geen tracklogs beschikbaar voor download.';
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');

    $central = '';
    $offset = 0;
    foreach ($prepared as $entry) {
        [$dosTime, $dosDate] = public_scoring_zip_datetime((int)$entry['mtime']);
        $name = $entry['name'];
        $nameLength = strlen($name);
        $localHeader = "PK\x03\x04" . pack(
            'vvvvvVVVvv',
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $entry['crc'],
            $entry['size'],
            $entry['size'],
            $nameLength,
            0
        ) . $name;
        echo $localHeader;

        $handle = fopen($entry['path'], 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 1048576);
            }
            fclose($handle);
        }

        $central .= "PK\x01\x02" . pack(
            'vvvvvvVVVvvvvvVV',
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $entry['crc'],
            $entry['size'],
            $entry['size'],
            $nameLength,
            0,
            0,
            0,
            0,
            32,
            $offset
        ) . $name;

        $offset += strlen($localHeader) + $entry['size'];
    }

    $centralOffset = $offset;
    $centralSize = strlen($central);
    echo $central;
    echo "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($prepared), count($prepared), $centralSize, $centralOffset, 0);
    exit;
}

app_enable_debug();
$pdo = app_db_or_fail();
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
$publication = $task ? scoring_load_task_publication($pdo, $taskId) : null;
if (!$task || $task['status'] !== 'published' || (int)$task['competition_public'] !== 1 || !$publication) {
    http_response_code(404);
    app_page_start(app_site_name() . ' - Resultaat niet gevonden', ['active_public' => 'scoring']);
    echo '<main class="card"><h1>Resultaat niet gevonden</h1><p class="muted">Deze taak is niet gepubliceerd.</p></main>';
    app_page_end();
    exit;
}

$download = strtolower(trim((string)($_GET['download'] ?? '')));
$results = [];
$turnpoints = [];
$competition = null;
$publicTasks = [];
try {
    $competition = scoring_load_competition($pdo, (int)$task['competition_id']);
    $publicTasks = scoring_load_public_competition_tasks($pdo, (int)$task['competition_id']);
    $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
} catch (Throwable $e) {
    if (app_debug_enabled() && $download === '') {
        echo '<pre>Task context failed: ' . h($e->getMessage()) . '</pre>';
    }
}

try {
    $results = scoring_load_task_public_results($pdo, $taskId);
} catch (Throwable $e) {
    if (app_debug_enabled() && $download === '') {
        echo '<pre>Results failed: ' . h($e->getMessage()) . '</pre>';
    }
}

$summary = [];
if (!empty($publication['scoring_summary_json'])) {
    $decoded = json_decode((string)$publication['scoring_summary_json'], true);
    $summary = is_array($decoded) ? $decoded : [];
}

$tracklogDownloadEntries = [];
try {
    $tracklogDownloadEntries = public_scoring_load_task_tracklog_entries($pdo, $taskId);
} catch (Throwable $e) {
    if (app_debug_enabled() && $download === '') {
        echo '<pre>Tracklog download context failed: ' . h($e->getMessage()) . '</pre>';
    }
}

if ($download === 'pdf') {
    $filename = public_scoring_task_download_filename($task, $competition, 'resultaten.pdf');
    $pdf = public_scoring_build_task_results_pdf($task, $competition, $publication, $summary, $turnpoints, $results);
    public_scoring_send_binary_download($pdf, 'application/pdf', $filename);
}
if ($download === 'tracklogs' || $download === 'zip') {
    $filename = public_scoring_task_download_filename($task, $competition, 'tracklogs.zip');
    public_scoring_stream_tracklog_zip($tracklogDownloadEntries, $filename);
}

$taskMap = !empty($turnpoints) ? scoring_task_map_data($turnpoints) : null;
$taskMapJson = '';
$leafletAssets = '';
$taskMapSssIndex = null;
$taskMapEssIndex = null;
if ($taskMap) {
    [$taskMapSssIndex, $taskMapEssIndex] = scoring_speed_section_indices($turnpoints);
    $taskMapJson = json_encode(
        $taskMap,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($taskMapJson === false) {
        $taskMap = null;
        $taskMapJson = '';
    } else {
        $leafletAssets = ''
            . '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">' . "\n"
            . '  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>';
    }
}

app_page_start(app_site_name() . ' - ' . $task['competition_name'] . ' ' . $task['name'], [
    'active_public' => 'scoring',
    'description' => 'Gepubliceerde taakresultaten.',
    'extra_head' => $leafletAssets,
]);
?>
<main>
  <section class="card public-competition-card">
    <div class="kicker"><?= h(($competition['class'] ?? $task['class']) . ' - ' . ($competition['scope'] ?? $task['scope'])) ?></div>
    <h1><?= h($competition['name'] ?? $task['competition_name']) ?></h1>
    <?php if (!empty($competition['location'])): ?><p class="muted"><?= h($competition['location']) ?></p><?php endif; ?>
    <?php if (!empty($publicTasks)): ?>
      <ul class="list-compact">
        <?php foreach ($publicTasks as $publicTask): ?>
          <?php
            $isCurrentTask = (int)$publicTask['id'] === (int)$task['id'];
            $taskLinkClass = 'score-link' . ($isCurrentTask ? ' is-active' : '');
          ?>
          <li>
            <span>
              <?= h($publicTask['task_date']) ?> -
              <a class="<?= h($taskLinkClass) ?>" href="scoring_task.php?id=<?= (int)$publicTask['id'] ?>"<?= $isCurrentTask ? ' aria-current="page"' : '' ?>><?= h($publicTask['name']) ?></a>
              <span class="muted">/</span>
              <a class="score-link" href="scoring_competition.php?task_id=<?= (int)$publicTask['id'] ?>">Competitieresultaat t/m <?= h($publicTask['name']) ?></a>
            </span>
            <span class="muted"><?= h(scoring_utc_sql_to_display($publicTask['published_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($taskMap): ?>
    <section class="card public-task-map-card">
      <h2><?= h($task['name']) ?></h2>
      <div class="stat-grid">
        <div class="stat"><div class="muted">Datum</div><strong><?= h($task['task_date']) ?></strong></div>
        <div class="stat"><div class="muted">Type</div><strong><?= h($task['task_type']) ?></strong></div>
        <div class="stat"><div class="muted">Afstand</div><strong><?= h(app_format_compact_number($publication['task_distance_km'] ?: 0, 3)) ?> km</strong></div>
        <div class="stat"><div class="muted">Validiteit</div><strong><?= h(app_format_compact_number(($summary['task_validity'] ?? 0) * 100, 1)) ?>%</strong></div>
      </div>
      <div class="public-task-layout">
        <div class="public-task-turnpoints">
          <h3>Taakpunten</h3>
          <ol class="task-turnpoint-list">
            <?php foreach ($turnpoints as $idx => $tp): ?>
              <?php
                $roles = [];
                if ($idx === $taskMapSssIndex) {
                    $roles[] = ['label' => 'SSS', 'class' => 'sss'];
                }
                if ($idx === $taskMapEssIndex) {
                    $roles[] = ['label' => 'ESS', 'class' => 'ess'];
                }
              ?>
              <li>
                <span class="task-turnpoint-name"><?= h($tp['name']) ?></span>
                <span class="task-turnpoint-meta">
                  <?= (int)$tp['radius_m'] ?> m
                  <?php foreach ($roles as $role): ?>
                    <strong class="<?= h($role['class']) ?>"><?= h($role['label']) ?></strong>
                  <?php endforeach; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
        <div class="public-task-map">
          <div class="task-map">
            <div class="task-map-canvas" aria-label="Kaart met taakpunten en geoptimaliseerde route">
              <span class="track-preview-loading">Kaart laden...</span>
            </div>
            <div class="task-map-legend">
              <span><i class="task-map-swatch normal"></i>Normaal</span>
              <span><i class="task-map-swatch sss"></i>SSS</span>
              <span><i class="task-map-swatch ess"></i>ESS</span>
            </div>
            <script type="application/json" class="task-map-data"><?= $taskMapJson ?></script>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <section class="card">
    <h2>Resultaten</h2>
    <?php if (empty($results)): ?>
      <p class="muted">Geen resultaten beschikbaar.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="striped">
          <thead>
            <tr>
              <th>#</th>
              <th>Piloot</th>
              <th>Afstand</th>
              <th>Tijd</th>
              <th>Afstandspunten</th>
              <th>Tijd</th>
              <th>Leading</th>
              <th>Arrival</th>
              <th>Totaal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $row): ?>
              <?php $resultStatus = scoring_task_flight_result_status($row); ?>
              <tr>
                <td><?= $resultStatus === 'dnf' ? 'DNF' : (int)$row['rank_no'] ?></td>
                <td><?= h($row['pilot_name']) ?></td>
                <td>
                  <?php if ($resultStatus === 'dnf'): ?>
                    DNF
                  <?php else: ?>
                    <?= h(app_format_compact_number($row['distance_km'], 3)) ?> km<?= (int)$row['reached_goal'] === 1 ? ' <span class="muted">goal</span>' : '' ?>
                  <?php endif; ?>
                </td>
                <td><?= h(scoring_format_duration($row['time_seconds'] !== null ? (int)$row['time_seconds'] : null)) ?></td>
                <td><?= h(app_format_compact_number($row['distance_points'], 1)) ?></td>
                <td><?= h(app_format_compact_number($row['time_points'], 1)) ?></td>
                <td><?= h(app_format_compact_number($row['leading_points'], 1)) ?></td>
                <td><?= h(app_format_compact_number(((float)$row['arrival_position_points']) + ((float)$row['arrival_time_points']), 1)) ?></td>
                <td><strong><?= h(app_format_compact_number($row['total_points'], 1)) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($summary['implementation_note'])): ?>
        <p class="muted"><?= h($summary['implementation_note']) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Downloads</h2>
    <p class="muted">Download de gepubliceerde taakresultaten of de IGC-tracklogs die voor deze taakscore zijn gebruikt.</p>
    <p class="actions">
      <a class="btn" href="scoring_task.php?id=<?= (int)$taskId ?>&amp;download=pdf">PDF taakresultaten</a>
      <?php if (!empty($tracklogDownloadEntries)): ?>
        <a class="btn secondary" href="scoring_task.php?id=<?= (int)$taskId ?>&amp;download=tracklogs">Tracklogs ZIP</a>
      <?php endif; ?>
    </p>
    <?php if (empty($tracklogDownloadEntries)): ?>
      <p class="muted">Voor deze gepubliceerde taak zijn geen downloadbare IGC-tracklogs beschikbaar.</p>
    <?php else: ?>
      <p class="muted"><?= count($tracklogDownloadEntries) ?> tracklog(s) in de ZIP.</p>
    <?php endif; ?>
  </section>
</main>
<?php app_page_end(); ?>
