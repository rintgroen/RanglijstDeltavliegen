<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $scorer = scoring_current_scorer($pdo);
    if (!$scorer) {
        http_response_code(401);
        echo json_encode(['error' => 'Log opnieuw in om de reviewkaart te laden.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
    $flightId = isset($_GET['flight_id']) ? (int)$_GET['flight_id'] : 0;
    $task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
    if (!$task || !scoring_can_edit_competition($pdo, (int)$task['competition_id'], (int)$scorer['id'])) {
        throw new RuntimeException('Taak niet gevonden.');
    }

    $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
    $taskMap = !empty($turnpoints) ? scoring_task_map_data($turnpoints) : null;
    if (!$taskMap) {
        throw new RuntimeException('Geen taakkaart beschikbaar.');
    }

    $stmt = $pdo->prepare(
        'SELECT f.*, tl.original_filename, tl.storage_path, tl.fix_count
         FROM rankings_scoring_task_flights f
         JOIN rankings_scoring_tracklogs tl ON tl.id = f.tracklog_id
         WHERE f.id = ? AND f.task_id = ?
         LIMIT 1'
    );
    $stmt->execute([$flightId, $taskId]);
    $flight = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$flight || !scoring_task_flight_is_track_candidate($flight)) {
        throw new RuntimeException('Track niet gevonden.');
    }

    $payload = scoring_task_tracklog_review_map_data($pdo, $flight, $taskMap, 350);
    if (!$payload) {
        $payload = ['task' => $taskMap, 'track' => null];
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
