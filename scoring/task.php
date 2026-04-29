<?php
define('APP_AREA', 'scoring');
require_once __DIR__ . '/../includes/scoring.php';

app_enable_debug();
$pdo = app_db_or_fail();
$wantsReviewMap = isset($_GET['review_map']);
$scorer = $wantsReviewMap ? scoring_current_scorer($pdo) : scoring_require_scorer($pdo);
if (!$scorer) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['error' => 'Log opnieuw in om de reviewkaart te laden.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
$csrf = app_csrf_token();
$tracklogSourceAvailable = false;
$taskReviewStatusAvailable = false;
$taskIdentityReviewAvailable = false;
try {
    scoring_ensure_track_collection_tables($pdo);
    scoring_ensure_task_review_columns($pdo);
    $tracklogSourceAvailable = scoring_tracklog_source_columns_available($pdo);
    $taskReviewStatusAvailable = scoring_task_review_status_available($pdo);
    $taskIdentityReviewAvailable = scoring_task_identity_review_available($pdo);
} catch (Throwable $e) {
    $tracklogSourceAvailable = false;
    $taskReviewStatusAvailable = false;
    $taskIdentityReviewAvailable = false;
}
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = $taskId > 0 ? scoring_load_task($pdo, $taskId) : null;
if (!$task || !scoring_can_edit_competition($pdo, (int)$task['competition_id'], (int)$scorer['id'])) {
    if ($wantsReviewMap) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Taak niet gevonden.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(404);
    app_page_start('Taak niet gevonden - Scoring', [
        'active_scoring' => 'dashboard',
        'scoring_user' => $scorer['name'] ?: $scorer['email'],
    ]);
    echo '<main class="card"><h1>Taak niet gevonden</h1><p class="muted">Deze taak kon niet worden gevonden.</p></main>';
    app_page_end();
    exit;
}

if ($wantsReviewMap) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $flightId = isset($_GET['flight_id']) ? (int)$_GET['flight_id'] : 0;
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
    exit;
}

$competition = scoring_load_competition($pdo, (int)$task['competition_id']);
$taskTabs = [
    'settings' => '1. Taak instellen en delen',
    'review' => '2. tracks controleren',
    'scoring' => '3. scoren en publiceren',
];
$requestedTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$activeTab = isset($taskTabs[$requestedTab]) ? $requestedTab : 'settings';
$taskTabByAction = [
    'update_task' => 'settings',
    'add_gate' => 'settings',
    'delete_gate' => 'settings',
    'add_turnpoint' => 'settings',
    'delete_turnpoint' => 'settings',
    'update_turnpoints' => 'settings',
    'match_tracks' => 'review',
    'collect_livetrack24' => 'review',
    'add_manual_flight' => 'review',
    'save_review' => 'review',
    'score_task' => 'scoring',
    'publish_task' => 'scoring',
    'unpublish_task' => 'scoring',
];
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = (string)($_POST['action'] ?? '');
    if (isset($taskTabByAction[$postedAction])) {
        $activeTab = $taskTabByAction[$postedAction];
    }
    if (!app_check_csrf()) {
        $error = 'Ongeldige CSRF-token.';
    } else {
        try {
            $action = $postedAction;
            if ($action === 'update_task') {
                $name = trim((string)($_POST['name'] ?? ''));
                $taskDate = trim((string)($_POST['task_date'] ?? ''));
                $windowOpen = trim((string)($_POST['window_open'] ?? ''));
                $windowClose = trim((string)($_POST['window_close'] ?? ''));
                $taskType = (string)($_POST['task_type'] ?? 'race');
                if ($name === '' || $taskDate === '' || $windowOpen === '' || $windowClose === '') {
                    throw new RuntimeException('Vul naam, datum en taakvenster in.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE rankings_scoring_tasks
                     SET name = ?, task_date = ?, window_open_at = ?, window_close_at = ?, task_type = ?,
                         minimum_distance_km = ?, nominal_distance_km = ?, nominal_time_minutes = ?,
                         use_distance_points = ?, use_time_points = ?, use_departure_points = ?, use_leading_points = ?,
                         use_arrival_position_points = ?, use_arrival_time_points = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $name,
                    $taskDate,
                    scoring_local_input_to_utc_sql($windowOpen),
                    scoring_local_input_to_utc_sql($windowClose),
                    $taskType,
                    scoring_decimal_or_null($_POST['minimum_distance_km'] ?? '') ?? 5.0,
                    scoring_decimal_or_null($_POST['nominal_distance_km'] ?? '') ?? 50.0,
                    max(1, (int)($_POST['nominal_time_minutes'] ?? 90)),
                    isset($_POST['use_distance_points']) ? 1 : 0,
                    isset($_POST['use_time_points']) ? 1 : 0,
                    isset($_POST['use_departure_points']) ? 1 : 0,
                    isset($_POST['use_leading_points']) ? 1 : 0,
                    isset($_POST['use_arrival_position_points']) ? 1 : 0,
                    isset($_POST['use_arrival_time_points']) ? 1 : 0,
                    $taskId,
                ]);
                $notice = 'Taak bijgewerkt.';
            } elseif ($action === 'add_gate') {
                $gateTime = trim((string)($_POST['gate_time'] ?? ''));
                if ($gateTime === '') {
                    throw new RuntimeException('Vul een startgate tijd in.');
                }
                $pdo->prepare('INSERT INTO rankings_scoring_task_start_gates (task_id, gate_time_at) VALUES (?, ?)')
                    ->execute([$taskId, scoring_gate_local_to_utc_sql($task['task_date'], $gateTime)]);
                $notice = 'Startgate toegevoegd.';
            } elseif ($action === 'delete_gate') {
                $gateId = (int)($_POST['gate_id'] ?? 0);
                $pdo->prepare('DELETE FROM rankings_scoring_task_start_gates WHERE id = ? AND task_id = ?')->execute([$gateId, $taskId]);
                $notice = 'Startgate verwijderd.';
            } elseif ($action === 'add_turnpoint') {
                $waypointId = (int)($_POST['waypoint_id'] ?? 0);
                $radius = max(1, (int)($_POST['radius_m'] ?? 400));
                if ($waypointId <= 0) {
                    throw new RuntimeException('Selecteer een waypoint.');
                }
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM rankings_scoring_waypoints WHERE id = ? AND competition_id = ?');
                $stmt->execute([$waypointId, (int)$task['competition_id']]);
                if ((int)$stmt->fetchColumn() === 0) {
                    throw new RuntimeException('Waypoint hoort niet bij deze competitie.');
                }
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM rankings_scoring_task_turnpoints WHERE task_id = ?');
                $stmt->execute([$taskId]);
                $sequence = (int)$stmt->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO rankings_scoring_task_turnpoints
                     (task_id, waypoint_id, sequence_no, radius_m, is_speed_section_start, is_speed_section_end)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$taskId, $waypointId, $sequence, $radius, 0, 0]);
                $notice = 'Taakpunt toegevoegd.';
            } elseif ($action === 'delete_turnpoint') {
                $turnpointId = (int)($_POST['turnpoint_id'] ?? 0);
                $pdo->prepare('DELETE FROM rankings_scoring_task_turnpoints WHERE id = ? AND task_id = ?')->execute([$turnpointId, $taskId]);
                $rows = scoring_load_task_turnpoints($pdo, $taskId);
                $seq = 1;
                $upd = $pdo->prepare('UPDATE rankings_scoring_task_turnpoints SET sequence_no = ? WHERE id = ?');
                foreach ($rows as $row) {
                    $upd->execute([$seq++, (int)$row['id']]);
                }
                $notice = 'Taakpunt verwijderd.';
            } elseif ($action === 'update_turnpoints') {
                $deleteId = (int)($_POST['delete_turnpoint_id'] ?? 0);
                if ($deleteId > 0) {
                    $pdo->prepare('DELETE FROM rankings_scoring_task_turnpoints WHERE id = ? AND task_id = ?')->execute([$deleteId, $taskId]);
                    $rows = scoring_load_task_turnpoints($pdo, $taskId);
                    $seq = 1;
                    $upd = $pdo->prepare('UPDATE rankings_scoring_task_turnpoints SET sequence_no = ? WHERE id = ?');
                    foreach ($rows as $row) {
                        $upd->execute([$seq++, (int)$row['id']]);
                    }
                    $notice = 'Taakpunt verwijderd.';
                } else {
                    $radii = isset($_POST['radius']) && is_array($_POST['radius']) ? $_POST['radius'] : [];
                    $sssId = (int)($_POST['sss_turnpoint_id'] ?? 0);
                    $essId = (int)($_POST['ess_turnpoint_id'] ?? 0);
                    $rows = scoring_load_task_turnpoints($pdo, $taskId);
                    $upd = $pdo->prepare(
                        'UPDATE rankings_scoring_task_turnpoints
                         SET radius_m = ?, is_speed_section_start = ?, is_speed_section_end = ?
                         WHERE id = ? AND task_id = ?'
                    );
                    foreach ($rows as $row) {
                        $id = (int)$row['id'];
                        $radius = max(1, (int)($radii[$id] ?? $row['radius_m']));
                        $upd->execute([$radius, $id === $sssId ? 1 : 0, $id === $essId ? 1 : 0, $id, $taskId]);
                    }
                    $notice = 'Taakpunten bijgewerkt.';
                }
            } elseif ($action === 'match_tracks') {
                $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
                $task = scoring_load_task($pdo, $taskId);
                $matched = scoring_match_task_tracklogs($pdo, $task, $turnpoints);
                $notice = $matched . ' tracklog(s) gekoppeld aan deze taak.';
            } elseif ($action === 'collect_livetrack24') {
                $turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
                $task = scoring_load_task($pdo, $taskId);
                $summary = scoring_import_livetrack24_for_task($pdo, $task, $turnpoints);
                $matchedUploads = scoring_match_task_tracklogs($pdo, $task, $turnpoints);
                $notice = 'LiveTrack24 gecontroleerd: '
                    . (int)$summary['profiles_checked'] . ' profiel(en), '
                    . (int)$summary['tracks_seen'] . ' track(s), '
                    . (int)$summary['candidates'] . ' kandidaat/kandidaten, '
                    . (int)$summary['imported'] . ' nieuw geimporteerd.';
                $notice .= ' Uploads gecontroleerd: ' . (int)$matchedUploads . ' kandidaat/kandidaten gekoppeld.';
                if ((int)$summary['already_linked'] > 0) {
                    $notice .= ' ' . (int)$summary['already_linked'] . ' bestaande kandidaat/kandidaten gekoppeld.';
                }
                if ((int)$summary['errors'] > 0) {
                    $notice .= ' ' . (int)$summary['errors'] . ' fout(en).';
                }
            } elseif ($action === 'add_manual_flight') {
                $manualType = (string)($_POST['manual_entry_type'] ?? 'tracklog');
                $pilotName = trim((string)($_POST['manual_pilot_name'] ?? ''));
                $pilotEmail = scoring_normalize_email((string)($_POST['manual_pilot_email'] ?? ''));
                if ($manualType === 'tracklog') {
                    if (!isset($_FILES['manual_tracklog']) || !is_array($_FILES['manual_tracklog'])) {
                        throw new RuntimeException('Upload een IGC-bestand.');
                    }
                    $tracklogId = scoring_store_tracklog_upload($pdo, $_FILES['manual_tracklog'], $pilotName, $pilotEmail !== '' ? $pilotEmail : null);
                    $stmt = $pdo->prepare('SELECT pilot_name, pilot_email FROM rankings_scoring_tracklogs WHERE id = ? LIMIT 1');
                    $stmt->execute([$tracklogId]);
                    $tracklog = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$tracklog) {
                        throw new RuntimeException('Tracklog kon niet worden gekoppeld.');
                    }
                    if ($taskReviewStatusAvailable) {
                        $insert = $pdo->prepare(
                            'INSERT INTO rankings_scoring_task_flights (task_id, tracklog_id, pilot_name, pilot_email, result_status)
                             VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE pilot_name = VALUES(pilot_name), pilot_email = VALUES(pilot_email), result_status = VALUES(result_status), is_excluded = 0, exclude_reason = NULL'
                        );
                        $insert->execute([$taskId, $tracklogId, $tracklog['pilot_name'], $tracklog['pilot_email'], 'track']);
                    } else {
                        $insert = $pdo->prepare(
                            'INSERT INTO rankings_scoring_task_flights (task_id, tracklog_id, pilot_name, pilot_email)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE pilot_name = VALUES(pilot_name), pilot_email = VALUES(pilot_email), is_excluded = 0, exclude_reason = NULL'
                        );
                        $insert->execute([$taskId, $tracklogId, $tracklog['pilot_name'], $tracklog['pilot_email']]);
                    }
                    if (scoring_pilot_identities_available($pdo)) {
                        $lookup = $pdo->prepare('SELECT id FROM rankings_scoring_task_flights WHERE task_id = ? AND tracklog_id = ? LIMIT 1');
                        $lookup->execute([$taskId, $tracklogId]);
                        $flightId = (int)$lookup->fetchColumn();
                        if ($flightId > 0) {
                            scoring_assign_known_task_flight_identity($pdo, (int)$task['competition_id'], $flightId, (string)$tracklog['pilot_name'], $tracklog['pilot_email'] ?? null);
                        }
                    }
                    $notice = 'Tracklog van ' . $tracklog['pilot_name'] . ' is toegevoegd aan deze taak.';
                } elseif (in_array($manualType, ['minimum_distance', 'dnf', 'abs'], true)) {
                    $task = scoring_load_task($pdo, $taskId);
                    if (!$task) {
                        throw new RuntimeException('Taak niet gevonden.');
                    }
                    $flightId = scoring_add_manual_status_flight($pdo, $task, $manualType, $pilotName, $pilotEmail !== '' ? $pilotEmail : null);
                    if (scoring_pilot_identities_available($pdo) && $flightId > 0) {
                        scoring_assign_known_task_flight_identity($pdo, (int)$task['competition_id'], $flightId, $pilotName, $pilotEmail !== '' ? $pilotEmail : null);
                    }
                    $notice = scoring_task_flight_status_label($manualType) . ' voor ' . $pilotName . ' is toegevoegd aan deze taak.';
                } else {
                    throw new RuntimeException('Kies tracklog uploaden, minimumafstand, DNF of ABS.');
                }
            } elseif ($action === 'save_review') {
                $reviewStatusSelect = $taskReviewStatusAvailable ? 'f.result_status' : "'track' AS result_status";
                $stmt = $pdo->prepare(
                    'SELECT f.id, f.pilot_name, f.pilot_email, ' . $reviewStatusSelect . ', tl.storage_path, tl.original_filename
                     FROM rankings_scoring_task_flights f
                     JOIN rankings_scoring_tracklogs tl ON tl.id = f.tracklog_id
                     WHERE f.task_id = ?'
                );
                $stmt->execute([$taskId]);
                $flightRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $reasons = isset($_POST['exclude_reason']) && is_array($_POST['exclude_reason']) ? $_POST['exclude_reason'] : [];
                $flightGroups = isset($_POST['flight_group']) && is_array($_POST['flight_group']) ? $_POST['flight_group'] : [];
                $reviewActions = isset($_POST['review_action']) && is_array($_POST['review_action']) ? $_POST['review_action'] : [];
                $trackChoices = isset($_POST['track_choice']) && is_array($_POST['track_choice']) ? $_POST['track_choice'] : [];
                $identitySelections = isset($_POST['pilot_identity_group']) && is_array($_POST['pilot_identity_group']) ? $_POST['pilot_identity_group'] : [];
                $identityReviewedSelections = isset($_POST['identity_reviewed_group']) && is_array($_POST['identity_reviewed_group']) ? $_POST['identity_reviewed_group'] : [];
                $groupsByKey = [];
                foreach ($flightRows as $flightRow) {
                    $flightId = (int)$flightRow['id'];
                    $groupKey = (string)($flightGroups[$flightId] ?? ('flight:' . $flightId));
                    if (!isset($groupsByKey[$groupKey])) {
                        $groupsByKey[$groupKey] = [
                            'key' => $groupKey,
                            'pilot_name' => (string)$flightRow['pilot_name'],
                            'pilot_email' => $flightRow['pilot_email'] ?? null,
                            'rows' => [],
                        ];
                    }
                    $groupsByKey[$groupKey]['rows'][] = $flightRow;
                }
                $taskForReview = scoring_load_task($pdo, $taskId);
                if (!$taskForReview) {
                    throw new RuntimeException('Taak niet gevonden.');
                }
                $manualSelectionByGroup = [];
                foreach ($groupsByKey as $groupKey => &$group) {
                    $reviewAction = (string)($reviewActions[$groupKey] ?? 'track');
                    if (!in_array($reviewAction, ['track', 'minimum_distance', 'dnf', 'abs', 'exclude'], true)) {
                        $reviewAction = 'track';
                    }
                    if (in_array($reviewAction, ['minimum_distance', 'dnf', 'abs'], true)) {
                        $manualFlightId = 0;
                        foreach ($group['rows'] as $row) {
                            if (scoring_task_flight_result_status($row) === $reviewAction) {
                                $manualFlightId = (int)$row['id'];
                                break;
                            }
                        }
                        if ($manualFlightId <= 0) {
                            $manualFlightId = scoring_add_manual_status_flight(
                                $pdo,
                                $taskForReview,
                                $reviewAction,
                                (string)$group['pilot_name'],
                                scoring_pilot_identity_normalized_email($group['pilot_email'] ?? null) !== '' ? (string)$group['pilot_email'] : null
                            );
                            $group['rows'][] = [
                                'id' => $manualFlightId,
                                'pilot_name' => $group['pilot_name'],
                                'pilot_email' => $group['pilot_email'],
                                'result_status' => $reviewAction,
                            ];
                        }
                        $manualSelectionByGroup[$groupKey] = $manualFlightId;
                    }
                }
                unset($group);

                if ($taskReviewStatusAvailable && $taskIdentityReviewAvailable) {
                    $upd = $pdo->prepare('UPDATE rankings_scoring_task_flights SET result_status = ?, identity_reviewed = ?, is_excluded = ?, exclude_reason = ? WHERE id = ? AND task_id = ?');
                } elseif ($taskReviewStatusAvailable) {
                    $upd = $pdo->prepare('UPDATE rankings_scoring_task_flights SET result_status = ?, is_excluded = ?, exclude_reason = ? WHERE id = ? AND task_id = ?');
                } elseif ($taskIdentityReviewAvailable) {
                    $upd = $pdo->prepare('UPDATE rankings_scoring_task_flights SET identity_reviewed = ?, is_excluded = ?, exclude_reason = ? WHERE id = ? AND task_id = ?');
                } else {
                    $upd = $pdo->prepare('UPDATE rankings_scoring_task_flights SET is_excluded = ?, exclude_reason = ? WHERE id = ? AND task_id = ?');
                }
                foreach ($groupsByKey as $groupKey => $group) {
                    $reviewAction = (string)($reviewActions[$groupKey] ?? 'track');
                    if (!in_array($reviewAction, ['track', 'minimum_distance', 'dnf', 'abs', 'exclude'], true)) {
                        $reviewAction = 'track';
                    }
                    $identityReviewed = isset($identityReviewedSelections[$groupKey]) ? 1 : 0;
                    $selectedFlightId = 0;
                    if ($reviewAction === 'track') {
                        $selectedFlightId = max(0, (int)($trackChoices[$groupKey] ?? 0));
                        if ($selectedFlightId <= 0) {
                            throw new RuntimeException('Kies een track voor ' . $group['pilot_name'] . ' of selecteer een andere reviewstatus.');
                        }
                        $trackChoiceValid = false;
                        foreach ($group['rows'] as $row) {
                            if ((int)$row['id'] === $selectedFlightId && scoring_task_flight_is_track_candidate($row)) {
                                $trackChoiceValid = true;
                                break;
                            }
                        }
                        if (!$trackChoiceValid) {
                            throw new RuntimeException('De gekozen track hoort niet bij ' . $group['pilot_name'] . '.');
                        }
                    } elseif (isset($manualSelectionByGroup[$groupKey])) {
                        $selectedFlightId = (int)$manualSelectionByGroup[$groupKey];
                    }

                    foreach ($group['rows'] as $flightRow) {
                        $flightId = (int)$flightRow['id'];
                        $resultStatus = scoring_task_flight_result_status($flightRow);
                        $newResultStatus = $resultStatus;
                        $isSelected = $selectedFlightId > 0 && $flightId === $selectedFlightId;
                        $isExcluded = $isSelected ? 0 : 1;
                        $reason = trim((string)($reasons[$flightId] ?? ''));
                        if (scoring_task_flight_is_track_candidate($flightRow)) {
                            $newResultStatus = ($reviewAction === 'track' && $isSelected) ? 'track' : 'alternate';
                        }
                        if ($reviewAction === 'exclude') {
                            $isExcluded = 1;
                            $reason = $reason !== '' ? $reason : 'Uitgesloten';
                        } elseif ($reviewAction === 'abs' && $isSelected) {
                            $isExcluded = 1;
                            $reason = $reason !== '' ? $reason : 'ABS';
                        } elseif (!$isSelected) {
                            $reason = $reason !== '' ? $reason : 'Alternatieve kandidaat';
                        } elseif ($resultStatus === 'abs') {
                            $isExcluded = 1;
                            $reason = $reason !== '' ? $reason : 'ABS';
                        } else {
                            $reason = '';
                        }
                        if ($taskReviewStatusAvailable && $taskIdentityReviewAvailable) {
                            $upd->execute([$newResultStatus, $identityReviewed, $isExcluded, $reason !== '' ? $reason : null, $flightId, $taskId]);
                        } elseif ($taskReviewStatusAvailable) {
                            $upd->execute([$newResultStatus, $isExcluded, $reason !== '' ? $reason : null, $flightId, $taskId]);
                        } elseif ($taskIdentityReviewAvailable) {
                            $upd->execute([$identityReviewed, $isExcluded, $reason !== '' ? $reason : null, $flightId, $taskId]);
                        } else {
                            $upd->execute([$isExcluded, $reason !== '' ? $reason : null, $flightId, $taskId]);
                        }
                    }

                    if ($selectedFlightId > 0 && scoring_pilot_identities_available($pdo)) {
                        $selection = (string)($identitySelections[$groupKey] ?? '');
                        if ($selection !== '' && $reviewAction !== 'exclude') {
                            scoring_assign_task_flight_identifier_selection($pdo, (int)$task['competition_id'], $selectedFlightId, $selection, (string)$group['pilot_name'], $group['pilot_email'] ?? null);
                        }
                    }
                }
                $notice = 'Review opgeslagen.';
            } elseif ($action === 'score_task') {
                $summary = scoring_score_task($pdo, $taskId);
                $notice = 'Taak gescoord: ' . (int)$summary['pilots_scored'] . ' piloten';
                if (!empty($summary['pilots_dnf'])) {
                    $notice .= ', ' . (int)$summary['pilots_dnf'] . ' DNF';
                }
                $notice .= ', taakvaliditeit ' . app_format_compact_number($summary['task_validity'] * 100, 1) . '%.';
                if ($task['status'] === 'published') {
                    $notice .= ' De publicatie blijft ongewijzigd tot je Publicatie bijwerken kiest.';
                }
            } elseif ($action === 'publish_task') {
                $publication = scoring_publish_task_results($pdo, $taskId);
                $notice = 'Resultaten en tussenstand gepubliceerd: ' . (int)$publication['published_rows'] . ' piloten.';
            } elseif ($action === 'unpublish_task') {
                scoring_clear_task_publication($pdo, $taskId);
                $pdo->prepare('UPDATE rankings_scoring_tasks SET status = ?, published_at = NULL WHERE id = ?')->execute(['scored', $taskId]);
                $notice = 'Publicatie ingetrokken.';
            }
        } catch (Throwable $e) {
            $error = ($e instanceof RuntimeException || app_debug_enabled()) ? $e->getMessage() : 'Actie mislukt.';
        }
    }
}

$task = scoring_load_task($pdo, $taskId);
$turnpoints = scoring_load_task_turnpoints($pdo, $taskId);
$gates = scoring_load_task_gates($pdo, $taskId);
$waypoints = [];
$flights = [];
$reviewGroups = [];
$reviewGroupStates = [];
$pilotIdentityAvailable = scoring_pilot_identities_available($pdo);
$pilotIdentifierOptions = [];
$results = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM rankings_scoring_waypoints WHERE competition_id = ? ORDER BY name ASC');
    $stmt->execute([(int)$task['competition_id']]);
    $waypoints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pilotIdentifierOptions = scoring_load_competition_pilot_identifier_options($pdo, $task);

    $tracklogSourceSelect = $tracklogSourceAvailable
        ? ', tl.source, tl.source_external_id, tl.source_url'
        : ", 'manual_upload' AS source, NULL AS source_external_id, NULL AS source_url";

    if ($pilotIdentityAvailable) {
        $stmt = $pdo->prepare(
            'SELECT f.*, tl.original_filename, tl.storage_path, tl.fix_count, tl.uploaded_at, tl.first_fix_at, tl.last_fix_at,
                    fi.identity_id, pi.display_name AS identity_display_name, pi.primary_email AS identity_primary_email
                    ' . $tracklogSourceSelect . '
             FROM rankings_scoring_task_flights f
             JOIN rankings_scoring_tracklogs tl ON tl.id = f.tracklog_id
             LEFT JOIN rankings_scoring_task_flight_identities fi ON fi.flight_id = f.id
             LEFT JOIN rankings_scoring_pilot_identities pi ON pi.id = fi.identity_id
             WHERE f.task_id = ?
             ORDER BY f.is_excluded ASC, f.pilot_name ASC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT f.*, tl.original_filename, tl.storage_path, tl.fix_count, tl.uploaded_at, tl.first_fix_at, tl.last_fix_at
                    ' . $tracklogSourceSelect . '
             FROM rankings_scoring_task_flights f
             JOIN rankings_scoring_tracklogs tl ON tl.id = f.tracklog_id
             WHERE f.task_id = ?
             ORDER BY f.is_excluded ASC, f.pilot_name ASC'
        );
    }
    $stmt->execute([$taskId]);
    $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($pilotIdentityAvailable) {
        foreach ($flights as &$flight) {
            $identityId = (int)($flight['identity_id'] ?? 0);
            $flight['suggested_identifier'] = $identityId > 0
                ? 'identity:' . $identityId
                : scoring_suggest_competition_pilot_identifier($pilotIdentifierOptions, (string)$flight['pilot_name'], $flight['pilot_email'] ?? null);
        }
        unset($flight);
    }
    foreach ($flights as &$flight) {
        if (!isset($flight['result_status'])) {
            $flight['result_status'] = 'track';
        }
    }
    unset($flight);
    $reviewGroups = scoring_build_task_review_groups($flights);
    $reviewGroupStates = [];
    foreach ($reviewGroups as $group) {
        $groupKey = (string)$group['key'];
        $trackCandidates = [];
        $selectedTrackId = 0;
        $selectedAction = 'exclude';
        foreach ($group['flights'] as $candidate) {
            if (scoring_task_flight_is_track_candidate($candidate)) {
                $trackCandidates[(int)$candidate['id']] = $candidate;
            }
        }
        foreach (['track', 'minimum_distance', 'dnf', 'abs'] as $preferredStatus) {
            foreach ($group['flights'] as $candidate) {
                $candidateStatus = scoring_task_flight_result_status($candidate);
                if ((int)$candidate['is_excluded'] === 0 && $candidateStatus === $preferredStatus) {
                    $selectedAction = $candidateStatus;
                    if ($candidateStatus === 'track') {
                        $selectedTrackId = (int)$candidate['id'];
                    }
                    break 2;
                }
            }
        }
        if ($selectedAction === 'exclude') {
            foreach ($group['flights'] as $candidate) {
                if (scoring_task_flight_result_status($candidate) === 'abs') {
                    $selectedAction = 'abs';
                    break;
                }
            }
        }
        if ($selectedTrackId <= 0 && !empty($trackCandidates)) {
            $selectedTrackId = (int)array_key_first($trackCandidates);
        }
        $identityReviewed = false;
        foreach ($group['flights'] as $candidate) {
            if ((int)($candidate['identity_reviewed'] ?? 0) === 1) {
                $identityReviewed = true;
                break;
            }
        }
        $identifierSelection = 'new';
        foreach ($group['flights'] as $candidate) {
            if (!empty($candidate['suggested_identifier'])) {
                $identifierSelection = (string)$candidate['suggested_identifier'];
                break;
            }
        }
        $reviewGroupStates[$groupKey] = [
            'track_candidates' => $trackCandidates,
            'track_count' => count($trackCandidates),
            'selected_track_id' => $selectedTrackId,
            'selected_action' => $selectedAction,
            'identity_reviewed' => $identityReviewed,
            'identifier_selection' => $identifierSelection,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM rankings_scoring_task_flights
         WHERE task_id = ? AND is_excluded = 0 AND scored_at IS NOT NULL
         ORDER BY rank_no IS NULL ASC, rank_no ASC, total_points DESC, pilot_name ASC'
    );
    $stmt->execute([$taskId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = app_debug_enabled() ? 'Laden mislukt: ' . $e->getMessage() : 'Laden mislukt.';
}

$summary = [];
if (!empty($task['scoring_summary_json'])) {
    $decoded = json_decode((string)$task['scoring_summary_json'], true);
    $summary = is_array($decoded) ? $decoded : [];
}

$taskMap = !empty($turnpoints) ? scoring_task_map_data($turnpoints) : null;
$taskMapJson = '';
$leafletAssets = '';
$taskShareUrl = scoring_task_share_url($taskId);
$taskSharePublicHref = '../public/task_board.php?id=' . (int)$taskId;
$taskShareXctskHref = $taskSharePublicHref . '&download=xctsk';
if ($taskMap) {
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

app_page_start($task['name'] . ' - Scoring', [
    'active_scoring' => 'dashboard',
    'scoring_user' => $scorer['name'] ?: $scorer['email'],
    'scoring_breadcrumbs' => [
        ['label' => 'Competities', 'href' => 'index.php'],
        ['label' => $task['competition_name'], 'href' => 'competition.php?id=' . (int)$task['competition_id']],
        ['label' => $task['name']],
    ],
    'description' => 'Taak beheren en scoren.',
    'extra_head' => $leafletAssets,
]);
?>
<main>
  <section class="competition-tabs" data-tabs data-active-tab="<?= h($activeTab) ?>">
    <div class="site-nav public-nav tab-list task-section-nav">
      <span class="task-nav-title"><?= h($task['name']) ?></span>
      <div class="tab-button-list" role="tablist" aria-label="Taak onderdelen">
        <?php foreach ($taskTabs as $tabKey => $tabLabel): ?>
          <button
            type="button"
            class="tab-button<?= $activeTab === $tabKey ? ' is-active' : '' ?>"
            id="task-tab-<?= h($tabKey) ?>"
            role="tab"
            aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>"
            aria-controls="task-panel-<?= h($tabKey) ?>"
            data-tab-target="<?= h($tabKey) ?>"
            tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"
          ><?= h($tabLabel) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($notice): ?><div class="alert success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

    <div
      class="tab-panel"
      id="task-panel-settings"
      role="tabpanel"
      aria-labelledby="task-tab-settings"
      data-tab-panel="settings"
      <?= $activeTab !== 'settings' ? 'hidden' : '' ?>
    >

  <section class="card">
    <h2>Taakpunten</h2>
    <div class="public-task-layout scoring-task-layout">
      <div class="public-task-turnpoints scoring-task-turnpoints">
        <?php if (empty($turnpoints)): ?>
          <p class="muted">Nog geen taakpunten. Zonder expliciete SSS/ESS gebruikt de scorer straks de eerste en laatste taakpunten.</p>
        <?php else: ?>
          <form id="turnpoints-form" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_turnpoints">
          </form>
          <ol class="task-turnpoint-list task-turnpoint-edit-list">
            <?php foreach ($turnpoints as $tp): ?>
              <li>
                <span class="task-turnpoint-name"><?= h($tp['name']) ?></span>
                <div class="task-turnpoint-controls">
                  <label>Radius
                    <input form="turnpoints-form" type="number" name="radius[<?= (int)$tp['id'] ?>]" min="1" value="<?= (int)$tp['radius_m'] ?>">
                  </label>
                  <label><input form="turnpoints-form" type="radio" name="sss_turnpoint_id" value="<?= (int)$tp['id'] ?>" <?= (int)$tp['is_speed_section_start'] === 1 ? 'checked' : '' ?>> SSS</label>
                  <label><input form="turnpoints-form" type="radio" name="ess_turnpoint_id" value="<?= (int)$tp['id'] ?>" <?= (int)$tp['is_speed_section_end'] === 1 ? 'checked' : '' ?>> ESS</label>
                  <button form="turnpoints-form" class="danger" name="delete_turnpoint_id" value="<?= (int)$tp['id'] ?>" type="submit">Verwijderen</button>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>

        <form method="post" class="panel grid taskpoint-form taskpoint-add-form">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="add_turnpoint">
          <label>Waypoint
            <select name="waypoint_id" required>
              <option value="">Kies waypoint</option>
              <?php foreach ($waypoints as $wp): ?>
                <option value="<?= (int)$wp['id'] ?>"><?= h($wp['name']) ?><?= $wp['code'] ? ' (' . h($wp['code']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Radius (m)
            <input type="number" name="radius_m" min="1" value="400">
          </label>
          <p><button type="submit">Taakpunt toevoegen</button></p>
        </form>

        <?php if (!empty($turnpoints)): ?>
          <p class="task-turnpoint-actions"><button form="turnpoints-form" type="submit">Taakpunten opslaan</button></p>
          <?php
            $routeDistance = scoring_task_distance_km($turnpoints);
            $speedCenterDistance = scoring_speed_section_center_distance_km($turnpoints);
            $speedBoundaryDistance = scoring_speed_section_boundary_distance_km($turnpoints);
          ?>
          <p class="muted">
            Speedsectie: <?= h(app_format_compact_number($speedBoundaryDistance, 3)) ?> km
            <span>(middenpunten <?= h(app_format_compact_number($speedCenterDistance, 3)) ?> km)</span>.
            Route totaal: <?= h(app_format_compact_number($routeDistance, 3)) ?> km.
          </p>
        <?php endif; ?>
      </div>
      <div class="public-task-map">
        <div class="task-map">
          <div class="task-map-canvas" aria-label="Kaart met taakpunten en geoptimaliseerde route">
            <span class="track-preview-loading"><?= $taskMap ? 'Kaart laden...' : 'Geen taakpunten voor de kaart.' ?></span>
          </div>
          <?php if ($taskMap): ?>
            <div class="task-map-legend">
              <span><i class="task-map-swatch normal"></i>Normaal</span>
              <span><i class="task-map-swatch sss"></i>SSS</span>
              <span><i class="task-map-swatch ess"></i>ESS</span>
            </div>
            <script type="application/json" class="task-map-data"><?= $taskMapJson ?></script>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Taakinstellingen</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="update_task">
      <div class="grid">
        <label>Naam
          <input type="text" name="name" value="<?= h($task['name']) ?>" required maxlength="190">
        </label>
        <label>Datum
          <input type="date" name="task_date" value="<?= h($task['task_date']) ?>" required>
        </label>
        <label>Venster open
          <input type="datetime-local" name="window_open" value="<?= h(scoring_utc_sql_to_local_input($task['window_open_at'])) ?>" required>
        </label>
        <label>Venster dicht
          <input type="datetime-local" name="window_close" value="<?= h(scoring_utc_sql_to_local_input($task['window_close_at'])) ?>" required>
        </label>
        <label>Type
          <select name="task_type">
            <option value="race" <?= $task['task_type'] === 'race' ? 'selected' : '' ?>>Race</option>
            <option value="time_trial" <?= $task['task_type'] === 'time_trial' ? 'selected' : '' ?>>Time trial</option>
          </select>
        </label>
        <label>Minimum afstand (km)
          <input type="number" name="minimum_distance_km" step="0.1" value="<?= h(app_format_compact_number($task['minimum_distance_km'])) ?>">
        </label>
        <label>Nominale afstand (km)
          <input type="number" name="nominal_distance_km" step="0.1" value="<?= h(app_format_compact_number($task['nominal_distance_km'])) ?>">
        </label>
        <label>Nominale tijd (min)
          <input type="number" name="nominal_time_minutes" min="1" value="<?= (int)$task['nominal_time_minutes'] ?>">
        </label>
      </div>
      <div class="checkbox-grid">
        <label><input type="checkbox" name="use_distance_points" <?= (int)$task['use_distance_points'] === 1 ? 'checked' : '' ?>> Afstandspunten</label>
        <label><input type="checkbox" name="use_time_points" <?= (int)$task['use_time_points'] === 1 ? 'checked' : '' ?>> Tijdspunten</label>
        <label><input type="checkbox" name="use_leading_points" <?= (int)$task['use_leading_points'] === 1 ? 'checked' : '' ?>> Leadingpunten</label>
        <label><input type="checkbox" name="use_departure_points" <?= (int)$task['use_departure_points'] === 1 ? 'checked' : '' ?>> Departurepunten</label>
        <label><input type="checkbox" name="use_arrival_position_points" <?= (int)$task['use_arrival_position_points'] === 1 ? 'checked' : '' ?>> Arrival positiepunten</label>
        <label><input type="checkbox" name="use_arrival_time_points" <?= (int)$task['use_arrival_time_points'] === 1 ? 'checked' : '' ?>> Arrival tijdpunten</label>
      </div>
      <p><button type="submit">Opslaan</button></p>
    </form>
  </section>

  <?php if ($task['task_type'] === 'race'): ?>
    <section class="card">
      <h2>Startgates</h2>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add_gate">
        <label>Tijd
          <input type="time" name="gate_time" required>
        </label>
        <button type="submit">Toevoegen</button>
      </form>
      <?php if (!empty($gates)): ?>
        <div class="chip-list">
          <?php foreach ($gates as $gate): ?>
            <form method="post" class="chip-form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="delete_gate">
              <input type="hidden" name="gate_id" value="<?= (int)$gate['id'] ?>">
              <span><?= h(scoring_utc_sql_to_local_time($gate['gate_time_at'])) ?></span>
              <button class="link-button" type="submit">x</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="card task-share-admin-card">
    <div class="section-header">
      <div>
        <h2>Taak delen</h2>
        <p class="muted">Deel deze briefingpagina met piloten voor taakdetails, XCTSK-download en instrument QR-code.</p>
      </div>
      <?php if (count($turnpoints) >= 2): ?>
        <p class="actions">
          <a class="btn" href="<?= h($taskSharePublicHref) ?>" target="_blank" rel="noopener">Open briefingpagina</a>
          <a class="btn secondary" href="<?= h($taskShareXctskHref) ?>">Download XCTSK</a>
        </p>
      <?php endif; ?>
    </div>
    <?php if (count($turnpoints) < 2): ?>
      <p class="muted">Voeg minimaal twee taakpunten toe om een deelbare instrumenttaak te maken.</p>
    <?php else: ?>
      <label>Deellink
        <input type="url" value="<?= h($taskShareUrl) ?>" readonly>
      </label>
    <?php endif; ?>
  </section>
    </div>

    <div
      class="tab-panel"
      id="task-panel-review"
      role="tabpanel"
      aria-labelledby="task-tab-review"
      data-tab-panel="review"
      <?= $activeTab !== 'review' ? 'hidden' : '' ?>
    >
  <section class="card">
    <div class="section-header">
      <div>
        <h2>Track review</h2>
        <p class="muted">Zoek uploads en LiveTrack24-kandidaten, kies per piloot welke rij voor de score telt, en laat alternatieven staan als fallback.</p>
      </div>
      <div class="inline">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="match_tracks">
          <button type="submit">Uploads zoeken</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="collect_livetrack24">
          <button class="secondary" type="submit">LiveTrack24 zoeken</button>
        </form>
      </div>
    </div>

    <h3>Geïdentificeerde piloten</h3>
    <?php if (empty($reviewGroups)): ?>
      <p class="muted">Nog geen gekoppelde tracklogs.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_review">
        <div class="review-workspace" data-review-workspace>
          <nav class="review-pilot-list" aria-label="Piloten in review">
            <?php foreach ($reviewGroups as $groupIndex => $group): ?>
              <?php
                $groupKey = (string)$group['key'];
                $groupDomId = 'review-group-' . md5($groupKey);
                $groupState = $reviewGroupStates[$groupKey] ?? [];
                $trackCount = (int)($groupState['track_count'] ?? 0);
                $listStatus = (string)($groupState['selected_action'] ?? 'exclude');
                $identityReviewed = !empty($groupState['identity_reviewed']);
                $listStatusClass = $listStatus === 'exclude'
                    ? ' is-review-excluded'
                    : ($identityReviewed ? ' is-review-ready' : ' is-review-pending');
              ?>
              <button
                type="button"
                class="review-pilot-button<?= $listStatusClass ?><?= $groupIndex === 0 ? ' is-active' : '' ?>"
                data-review-target="<?= h($groupDomId) ?>"
                aria-controls="<?= h($groupDomId) ?>"
                aria-selected="<?= $groupIndex === 0 ? 'true' : 'false' ?>"
              >
                <span class="review-pilot-line">
                  <span class="review-pilot-name"><?= h($group['label']) ?></span>
                  <span class="review-pilot-separator" aria-hidden="true">|</span>
                  <span class="review-pilot-count"><?= $trackCount ?> track(s)</span>
                </span>
                <span class="review-pilot-email"><?= h(scoring_display_pilot_email($group['email'] ?? null)) ?></span>
              </button>
            <?php endforeach; ?>
          </nav>
          <div class="review-detail">
          <?php foreach ($reviewGroups as $groupIndex => $group): ?>
            <?php
              $groupKey = (string)$group['key'];
              $groupDomId = 'review-group-' . md5($groupKey);
              $groupState = $reviewGroupStates[$groupKey] ?? [];
              $trackCandidates = $groupState['track_candidates'] ?? [];
              $selectedTrackId = (int)($groupState['selected_track_id'] ?? 0);
              $selectedAction = (string)($groupState['selected_action'] ?? 'exclude');
              $identityReviewed = !empty($groupState['identity_reviewed']);
              $identifierSelection = (string)($groupState['identifier_selection'] ?? 'new');
            ?>
            <section
              class="review-detail-panel"
              id="<?= h($groupDomId) ?>"
              data-review-panel="<?= h($groupDomId) ?>"
              <?= $groupIndex !== 0 ? 'hidden' : '' ?>
            >
              <div class="review-detail-header">
                <div>
                  <h4><?= h($group['label']) ?></h4>
                  <p class="muted"><?= h(scoring_display_pilot_email($group['email'] ?? null)) ?> · <?= count($trackCandidates) ?> track(s)</p>
                </div>
              </div>
              <?php foreach ($group['flights'] as $flight): ?>
                <input type="hidden" name="flight_group[<?= (int)$flight['id'] ?>]" value="<?= h($groupKey) ?>">
              <?php endforeach; ?>
              <?php if ($pilotIdentityAvailable): ?>
                <label>Identifier
                  <select name="pilot_identity_group[<?= h($groupKey) ?>]">
                    <?php foreach ($pilotIdentifierOptions as $identifierOption): ?>
                      <option value="<?= h($identifierOption['value']) ?>" <?= $identifierSelection === (string)$identifierOption['value'] ? 'selected' : '' ?>><?= h($identifierOption['label']) ?></option>
                    <?php endforeach; ?>
                    <option value="new" <?= $identifierSelection === 'new' ? 'selected' : '' ?>>Nieuwe identifier: <?= h($group['label']) ?></option>
                  </select>
                </label>
                <label class="check-row review-identity-reviewed">
                  <input type="checkbox" name="identity_reviewed_group[<?= h($groupKey) ?>]" value="1" data-review-identity-reviewed <?= $identityReviewed ? 'checked' : '' ?>>
                  Piloot en vlucht gecontroleerd
                </label>
              <?php endif; ?>

              <fieldset class="review-decision-list">
                <legend>Review keuze</legend>
                <label><input type="radio" name="review_action[<?= h($groupKey) ?>]" value="exclude" data-review-action <?= $selectedAction === 'exclude' ? 'checked' : '' ?>> Exclude</label>
                <label><input type="radio" name="review_action[<?= h($groupKey) ?>]" value="abs" data-review-action <?= $selectedAction === 'abs' ? 'checked' : '' ?>> Absent</label>
                <label><input type="radio" name="review_action[<?= h($groupKey) ?>]" value="dnf" data-review-action <?= $selectedAction === 'dnf' ? 'checked' : '' ?>> Did not fly</label>
                <label><input type="radio" name="review_action[<?= h($groupKey) ?>]" value="minimum_distance" data-review-action <?= $selectedAction === 'minimum_distance' ? 'checked' : '' ?>> Minimum distance</label>
                <label><input type="radio" name="review_action[<?= h($groupKey) ?>]" value="track" data-review-action <?= $selectedAction === 'track' ? 'checked' : '' ?> <?= empty($trackCandidates) ? 'disabled' : '' ?>> Use track</label>
              </fieldset>

              <div class="review-track-controls" data-review-track-controls>
                <label>Track
                  <select name="track_choice[<?= h($groupKey) ?>]" data-review-track-select>
                    <?php foreach ($trackCandidates as $flight): ?>
                  <?php
                    $flightId = (int)$flight['id'];
                    $sourceLabel = 'Upload';
                    if (($flight['source'] ?? '') === 'livetrack24') {
                        $sourceLabel = 'LiveTrack24';
                    }
                    $filenameLabel = trim((string)($flight['original_filename'] ?? ''));
                    $timeLabel = scoring_utc_sql_to_display($flight['first_fix_at']);
                    if (!empty($flight['last_fix_at'])) {
                        $timeLabel .= ' - ' . scoring_utc_sql_to_display($flight['last_fix_at']);
                    }
                    $trackLabelParts = [$sourceLabel];
                    if ($filenameLabel !== '') {
                        $trackLabelParts[] = $filenameLabel;
                    }
                    $trackLabelParts[] = (int)$flight['fix_count'] . ' fixes';
                    $trackLabelParts[] = $timeLabel;
                    if (($flight['source'] ?? '') === 'livetrack24' && !empty($flight['source_external_id'])) {
                        $trackLabelParts[] = 'LT24 #' . $flight['source_external_id'];
                    } elseif (!empty($flight['uploaded_at'])) {
                        $trackLabelParts[] = 'upload ' . scoring_utc_sql_to_display($flight['uploaded_at']);
                    }
                    $trackLabelParts[] = 'kandidaat #' . $flightId;
                    $trackLabel = implode(' · ', $trackLabelParts);
                  ?>
                      <option value="<?= $flightId ?>" data-map-url="task.php?id=<?= (int)$taskId ?>&amp;review_map=1&amp;flight_id=<?= $flightId ?>" <?= $selectedTrackId === $flightId ? 'selected' : '' ?>><?= h($trackLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="review-map-shell" data-review-map-shell>
                <div class="review-map" data-review-map aria-label="Kaart met taak en track">
                  <span class="track-preview-loading">Kaart laden...</span>
                </div>
              </div>
            </section>
          <?php endforeach; ?>
          </div>
        </div>
        <p><button type="submit">Review opslaan</button></p>
      </form>
    <?php endif; ?>

    <div class="panel manual-flight-form">
      <h3>Handmatig toevoegen</h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add_manual_flight">
        <div class="checkbox-grid">
          <label><input type="radio" name="manual_entry_type" value="tracklog" checked> Tracklog uploaden</label>
          <label><input type="radio" name="manual_entry_type" value="minimum_distance"> Minimumafstand</label>
          <label><input type="radio" name="manual_entry_type" value="dnf"> DNF</label>
          <label><input type="radio" name="manual_entry_type" value="abs"> ABS</label>
        </div>
        <div class="grid">
          <label>Piloot
            <input type="text" name="manual_pilot_name" required maxlength="160">
          </label>
          <label>E-mail (optioneel)
            <input type="email" name="manual_pilot_email" maxlength="190">
          </label>
          <label>IGC-tracklog
            <input type="file" name="manual_tracklog" accept=".igc,text/plain">
          </label>
          <label>Minimumafstand
            <input type="text" value="<?= h(app_format_compact_number($task['minimum_distance_km'], 3)) ?> km" readonly>
          </label>
        </div>
        <p><button type="submit">Handmatig toevoegen</button></p>
      </form>
      <p class="muted">Upload een IGC-tracklog, voeg minimumafstand toe, of registreer DNF/ABS voor piloten zonder scoring track.</p>
    </div>
  </section>
    </div>

    <div
      class="tab-panel"
      id="task-panel-scoring"
      role="tabpanel"
      aria-labelledby="task-tab-scoring"
      data-tab-panel="scoring"
      <?= $activeTab !== 'scoring' ? 'hidden' : '' ?>
    >
  <section class="card">
    <div class="section-header">
      <div>
        <h2>Scoren en publiceren</h2>
        <?php if (!empty($summary)): ?>
          <p class="muted">
            Validiteit <?= h(app_format_compact_number(($summary['task_validity'] ?? 0) * 100, 1)) ?>%,
            beste afstand <?= h(app_format_compact_number($summary['best_distance_km'] ?? 0, 3)) ?> km,
            piloten <?= (int)($summary['pilots_scored'] ?? 0) ?>.
            <?php if (!empty($summary['pilots_dnf'])): ?>
              DNF <?= (int)$summary['pilots_dnf'] ?>.
            <?php endif; ?>
            <?php if (!empty($summary['available_points']) && is_array($summary['available_points'])): ?>
              Beschikbaar:
              afstand <?= h(app_format_compact_number($summary['available_points']['distance'] ?? 0, 1)) ?>,
              tijd <?= h(app_format_compact_number($summary['available_points']['time'] ?? 0, 1)) ?>,
              leading <?= h(app_format_compact_number($summary['available_points']['leading'] ?? 0, 1)) ?>,
              arrival <?= h(app_format_compact_number($summary['available_points']['arrival'] ?? 0, 1)) ?>.
            <?php endif; ?>
          </p>
        <?php else: ?>
          <p class="muted">Nog niet gescoord.</p>
        <?php endif; ?>
      </div>
      <div class="inline">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="score_task">
          <button type="submit">Score taak</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="publish_task">
          <button class="secondary" type="submit"><?= $task['status'] === 'published' ? 'Publicatie bijwerken' : 'Publiceren' ?></button>
        </form>
        <?php if ($task['status'] === 'published'): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="unpublish_task">
            <button class="secondary" type="submit">Publicatie intrekken</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($summary['implementation_note'])): ?>
      <div class="notice"><?= h($summary['implementation_note']) ?></div>
    <?php endif; ?>

    <?php if (empty($results)): ?>
      <p class="muted">Geen score-resultaten beschikbaar.</p>
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
              <tr>
                <td><?= (int)$row['rank_no'] ?></td>
                <td><?= h($row['pilot_name']) ?></td>
                <td><?= h(app_format_compact_number($row['distance_km'], 3)) ?> km<?= (int)$row['reached_goal'] === 1 ? ' <span class="muted">goal</span>' : '' ?></td>
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
      <?php if ($task['status'] === 'published'): ?>
        <p class="actions">
          <a class="btn secondary" href="../public/scoring_task.php?id=<?= (int)$task['id'] ?>">Bekijk publieke taakresultaten</a>
          <a class="btn secondary" href="../public/scoring_competition.php?task_id=<?= (int)$task['id'] ?>">Bekijk publieke tussenstand</a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
    </div>
  </section>
</main>
<?php app_page_end('Scoring - ' . app_site_name()); ?>
