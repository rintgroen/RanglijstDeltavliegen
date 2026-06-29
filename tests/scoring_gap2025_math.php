<?php
require_once __DIR__ . '/../includes/scoring.php';

function assert_close($actual, $expected, float $epsilon, string $label): void {
    if (abs((float)$actual - (float)$expected) > $epsilon) {
        fwrite(STDERR, $label . ' expected ' . $expected . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

$launch = scoring_gap2025_launch_validity(96, 100);
assert_close($launch['validity'], 1.0, 0.000001, 'launch validity at nominal launch');

$launch = scoring_gap2025_launch_validity(48, 100);
assert_close($launch['lvr'], 0.5, 0.000001, 'launch validity ratio below nominal');
assert_close($launch['validity'], 0.50025, 0.000001, 'launch validity curve below nominal');

$distance = scoring_gap2025_distance_validity(450.0, 10, 5.0, 50.0, 50.0);
assert_close($distance['nominal_distance_area'], 29.25, 0.000001, 'nominal distance area');
assert_close($distance['validity'], 1.0, 0.000001, 'distance validity full');

$time = scoring_gap2025_time_validity(1.5, 50.0, 50.0, 1.5);
assert_close($time['validity'], 1.0, 0.000001, 'time validity at nominal time');

assert_close(scoring_gap2025_speed_fraction(1.0 + 8 / 60 + 42 / 3600, 1.0), 0.8, 0.001, 'speed fraction 80 percent example');
assert_close(scoring_gap2025_speed_fraction(1.0 + 26 / 60 + 7 / 3600, 1.0), 0.5, 0.001, 'speed fraction 50 percent example');
assert_close(scoring_gap2025_speed_fraction(2.0, 1.0), 0.0, 0.001, 'speed fraction zero example');

assert_close(scoring_gap2025_leading_factor(1.0, 1.0), 1.0, 0.000001, 'leading factor best pilot');
assert_close(scoring_gap2025_leading_factor(2.0, 1.0), 0.0, 0.000001, 'leading factor zero at doubled LCmin');
assert_close(scoring_gap2025_leading_factor(1.125, 1.0), 0.75, 0.000001, 'leading factor cubic-root curve');

$ltm = scoring_gap2025_ltm_context(['lat' => 52.0, 'lon' => 5.0]);
$cart = scoring_gap2025_geodesic_to_cartesian(52.123456, 5.234567, $ltm);
$geo = scoring_gap2025_cartesian_to_geodesic($cart['x'], $cart['y'], $ltm);
assert_close($geo['lat'], 52.123456, 0.00001, 'LTM latitude roundtrip');
assert_close($geo['lon'], 5.234567, 0.00001, 'LTM longitude roundtrip');

$route = [
    ['latitude' => 52.0, 'longitude' => 5.0, 'radius_m' => 0],
    ['latitude' => 52.1, 'longitude' => 5.0, 'radius_m' => 400],
];
$routeMetrics = scoring_optimised_route_metrics($route);
$centerDistance = scoring_center_route_distance_km($route);
assert_close($centerDistance - $routeMetrics['distance'], 0.4, 0.02, 'cylinder route optimizer reaches boundary');

$lineRoute = [
    ['latitude' => 52.0, 'longitude' => 5.0, 'radius_m' => 0],
    ['latitude' => 52.1, 'longitude' => 5.0, 'radius_m' => 400, 'control_zone_type' => 'line', 'line_orientation_deg' => 0, 'line_offset_km' => 0, 'line_half_length_km' => 1.0],
    ['latitude' => 52.2, 'longitude' => 5.0, 'radius_m' => 400],
];
$lineMetrics = scoring_optimised_route_metrics($lineRoute);
assert_close($lineMetrics['path'][1][0], 52.1, 0.0001, 'line optimizer chooses crossing latitude');
assert_close($lineMetrics['path'][1][1], 5.0, 0.0001, 'line optimizer chooses crossing longitude');

$firstStart = strtotime('2026-01-01 12:00:00 UTC');
$lc = scoring_gap2025_leading_coefficient_from_evaluation([
    'leading_trace' => [
        ['ts' => $firstStart, 'min_to_ess_km' => 10.0],
        ['ts' => $firstStart + 1800, 'min_to_ess_km' => 0.0],
    ],
    'best_min_to_ess_km' => 0.0,
], $firstStart, 1800, 10.0);
assert_close($lc, 1.0, 0.000001, 'leading coefficient full speed-section progress');

$allocation = scoring_allocate_gap2025_points([
    'class' => 'Klasse 1',
    'minimum_distance_km' => 5.0,
    'nominal_distance_km' => 50.0,
    'nominal_time_minutes' => 90,
    'leading_time_ratio' => 0.175,
    'jump_the_gun_enabled' => 1,
    'jump_the_gun_seconds_per_point' => 2.0,
    'jump_the_gun_max_seconds' => 300,
    'window_open_at' => '2026-01-01 11:00:00',
    'window_close_at' => '2026-01-01 17:00:00',
    'task_deadline_at' => '2026-01-01 17:00:00',
], [[
    'flight' => ['id' => 1, 'pilot_name' => 'Pilot A', 'manual_penalty_points' => 2.0, 'manual_bonus_points' => 1.0],
    'evaluation' => [
        'distance_km' => 50.0,
        'reached_ess' => true,
        'reached_goal' => true,
        'time_seconds' => 5400,
        'jump_the_gun_penalty_points' => 10.0,
        'ess_time_at' => '2026-01-01 13:30:00',
        'goal_time_at' => '2026-01-01 13:30:00',
        'first_task_start_at' => '2026-01-01 12:00:00',
        'last_flying_time_at' => '2026-01-01 13:30:00',
        'speed_section_distance_km' => 45.0,
        'leading_trace' => [
            ['ts' => $firstStart, 'min_to_ess_km' => 45.0],
            ['ts' => $firstStart + 5400, 'min_to_ess_km' => 0.0],
        ],
        'best_min_to_ess_km' => 0.0,
        'goal_is_ess' => true,
    ],
]], 50.0, 2);
assert_close($allocation['summary']['pilots_present'], 2, 0.000001, 'allocation counts DNF as present');
if (!($allocation['summary']['launch_validity'] < 1.0)) {
    fwrite(STDERR, 'launch validity should be devalued when one of two present pilots flies' . PHP_EOL);
    exit(1);
}
$points = $allocation['points'][1];
assert_close($points['raw_points'] - $points['total_points'], 11.0, 0.000001, 'automatic and manual adjustments reduce final points');

$task = [
    'class' => 'Klasse 1',
    'window_open_at' => '2026-01-01 11:00:00',
    'window_close_at' => '2026-01-01 17:00:00',
    'task_deadline_at' => '2026-01-01 17:00:00',
    'task_type' => 'race',
    'minimum_distance_km' => 1.0,
    'nominal_distance_km' => 10.0,
    'nominal_time_minutes' => 60,
    'jump_the_gun_enabled' => 1,
    'jump_the_gun_seconds_per_point' => 2.0,
    'jump_the_gun_max_seconds' => 300,
];
$turnpoints = [
    ['latitude' => 52.0, 'longitude' => 5.0, 'radius_m' => 400, 'is_speed_section_start' => 1, 'is_speed_section_end' => 0],
    ['latitude' => 52.09, 'longitude' => 5.0, 'radius_m' => 400, 'is_speed_section_start' => 0, 'is_speed_section_end' => 1],
];
$gates = [['gate_time_at' => '2026-01-01 12:00:00']];
$lineReach = scoring_reach_turnpoints([
    ['time_utc' => '2026-01-01 11:30:00', 'ts' => strtotime('2026-01-01 11:30:00 UTC'), 'lat' => 52.0, 'lon' => 5.0],
    ['time_utc' => '2026-01-01 12:05:00', 'ts' => strtotime('2026-01-01 12:05:00 UTC'), 'lat' => 52.095, 'lon' => 5.0],
    ['time_utc' => '2026-01-01 12:06:00', 'ts' => strtotime('2026-01-01 12:06:00 UTC'), 'lat' => 52.105, 'lon' => 5.0],
], [
    ['latitude' => 52.0, 'longitude' => 5.0, 'radius_m' => 400],
    ['latitude' => 52.1, 'longitude' => 5.0, 'radius_m' => 400, 'control_zone_type' => 'line', 'line_orientation_deg' => 0, 'line_offset_km' => 0, 'line_half_length_km' => 1.0],
], strtotime('2026-01-01 11:00:00 UTC'));
if ($lineReach[1] !== 1) {
    fwrite(STDERR, 'expected line control zone to be reached' . PHP_EOL);
    exit(1);
}

$early = scoring_evaluate_flight($task, $turnpoints, $gates, [
    ['time_utc' => '2026-01-01 11:59:00', 'lat' => 52.0, 'lon' => 5.0],
    ['time_utc' => '2026-01-01 13:00:00', 'lat' => 52.09, 'lon' => 5.0],
]);
assert_close($early['time_seconds'], 3600, 0.000001, 'early starter time is measured from first gate');
assert_close($early['early_start_seconds'], 60, 0.000001, 'early start seconds');
assert_close($early['jump_the_gun_penalty_points'], 30.0, 0.000001, 'jump-the-gun penalty points');
if ($early['jump_the_gun_status'] !== 'jump_the_gun_penalty') {
    fwrite(STDERR, 'expected jump_the_gun_penalty status, got ' . $early['jump_the_gun_status'] . PHP_EOL);
    exit(1);
}

$tooEarly = scoring_evaluate_flight($task, $turnpoints, $gates, [
    ['time_utc' => '2026-01-01 11:50:00', 'lat' => 52.0, 'lon' => 5.0],
    ['time_utc' => '2026-01-01 13:00:00', 'lat' => 52.09, 'lon' => 5.0],
]);
if ($tooEarly['jump_the_gun_status'] !== 'minimum_distance') {
    fwrite(STDERR, 'expected minimum_distance status, got ' . $tooEarly['jump_the_gun_status'] . PHP_EOL);
    exit(1);
}
assert_close($tooEarly['distance_km'], 1.0, 0.000001, 'too-early starter receives minimum distance');

echo "GAP 2025 math checks passed\n";
