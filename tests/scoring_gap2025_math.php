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
    'window_open_at' => '2026-01-01 11:00:00',
    'window_close_at' => '2026-01-01 17:00:00',
    'task_deadline_at' => '2026-01-01 17:00:00',
], [[
    'flight' => ['id' => 1, 'pilot_name' => 'Pilot A'],
    'evaluation' => [
        'distance_km' => 50.0,
        'reached_ess' => true,
        'reached_goal' => true,
        'time_seconds' => 5400,
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

echo "GAP 2025 math checks passed\n";
