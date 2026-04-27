<?php
require_once __DIR__ . '/app.php';

function ranking_page_for_class(string $class): string {
    return $class === 'Sportklasse' ? 'sportclass.php' : 'ranking.php';
}

function ranking_wprs_url(string $class, int $year): string {
    $slug = $class === 'Sportklasse' ? 'hang-gliding-class-1-sport-xc' : 'hang-gliding-class-1-xc';
    return 'https://civlcomps.org/ranking/' . $slug . '/pilots?search%5Bnation_id%5D=155&search%5BrankingDate%5D=' . $year . '-10-01';
}

function ranking_find_nk_competition_id(PDO $pdo, string $class, int $year): ?int {
    try {
        $st = $pdo->prepare(
            "SELECT id
             FROM rankings_competitions
             WHERE class = ? AND year = ? AND (title LIKE 'NK %' OR title LIKE 'NK%')
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        $st->execute([$class, $year]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ranking_load_nk_positions(PDO $pdo, ?int $competitionId): array {
    $result = ['cid' => $competitionId, 'participants' => 0, 'pos' => [], 'names' => []];
    if (!$competitionId) {
        $result['cid'] = null;
        return $result;
    }

    try {
        $st = $pdo->prepare(
            "SELECT pilot_id, pilot_name, total
             FROM rankings_competition_results
             WHERE competition_id = ?
             ORDER BY CAST(total AS DECIMAL(16,6)) DESC, id ASC"
        );
        $st->execute([$competitionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $result;
    }

    $result['participants'] = count($rows);
    $rank = 0;
    $shown = 0;
    $previousTotal = null;

    foreach ($rows as $row) {
        $shown++;
        $total = (float)$row['total'];
        if ($previousTotal === null || abs($total - $previousTotal) > 1e-9) {
            $rank = $shown;
            $previousTotal = $total;
        }
        $pilotId = $row['pilot_id'] !== null ? (int)$row['pilot_id'] : 0;
        if ($pilotId > 0 && !isset($result['pos'][$pilotId])) {
            $result['pos'][$pilotId] = $rank;
            $result['names'][$pilotId] = (string)$row['pilot_name'];
        }
    }

    return $result;
}

function ranking_nationals_score(?int $position, int $participants): float {
    if (!$position || $participants <= 0) {
        return 0.0;
    }
    return max(0.0, 100.0 - (($position - 1) * (100.0 / $participants)));
}

function ranking_wprs_score(?float $points, float $maxPoints): float {
    if ($points === null || $maxPoints <= 0.0) {
        return 0.0;
    }
    return 100.0 * ($points / $maxPoints);
}

function ranking_load_active_pilots(PDO $pdo): array {
    try {
        $rs = $pdo->query("SELECT id, name FROM rankings_pilots WHERE active = 1 ORDER BY name");
        return $rs ? $rs->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function ranking_load_wprs_map(PDO $pdo, string $class, int $year): array {
    $points = [];
    $maxPoints = 0.0;
    try {
        $st = $pdo->prepare("SELECT pilot_id, points FROM rankings_world_points WHERE class = ? AND year = ?");
        $st->execute([$class, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pilotId = (int)$row['pilot_id'];
            $value = (float)$row['points'];
            $points[$pilotId] = $value;
            if ($value > $maxPoints) {
                $maxPoints = $value;
            }
        }
    } catch (Throwable $e) {
        return ['points' => [], 'max' => 0.0];
    }
    return ['points' => $points, 'max' => $maxPoints];
}

function ranking_years(PDO $pdo, string $class): array {
    $years = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT year FROM rankings_competitions WHERE class = ? ORDER BY year DESC");
        $st->execute([$class]);
        $years = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
    }

    try {
        $st = $pdo->prepare("SELECT DISTINCT year FROM rankings_world_points WHERE class = ? ORDER BY year DESC");
        $st->execute([$class]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $year) {
            $year = (int)$year;
            if (!in_array($year, $years, true)) {
                $years[] = $year;
            }
        }
    } catch (Throwable $e) {
    }

    rsort($years);
    return $years;
}

function ranking_latest_year(PDO $pdo, string $class): int {
    $years = ranking_years($pdo, $class);
    return $years[0] ?? (int)date('Y');
}

function ranking_compute(PDO $pdo, string $class, int $year): array {
    $isHistoric = $year <= 2008;
    $nkCur = ranking_load_nk_positions($pdo, ranking_find_nk_competition_id($pdo, $class, $year));
    $nkPrev = ranking_load_nk_positions($pdo, ranking_find_nk_competition_id($pdo, $class, $year - 1));
    $nkPrev2 = ['cid' => null, 'participants' => 0, 'pos' => [], 'names' => []];
    if ($isHistoric) {
        $nkPrev2 = ranking_load_nk_positions($pdo, ranking_find_nk_competition_id($pdo, $class, $year - 2));
    }

    $wprs = ['points' => [], 'max' => 0.0];
    if (!$isHistoric) {
        $wprs = ranking_load_wprs_map($pdo, $class, $year);
    }

    $available = $isHistoric
        ? ($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $nkPrev2['participants'] > 0)
        : ($nkCur['participants'] > 0 && $nkPrev['participants'] > 0 && $wprs['max'] > 0.0);

    $rows = [];
    if ($available) {
        foreach (ranking_load_active_pilots($pdo) as $pilot) {
            $pilotId = (int)$pilot['id'];
            $posCur = $nkCur['pos'][$pilotId] ?? null;
            $posPrev = $nkPrev['pos'][$pilotId] ?? null;
            $scoreCur = ranking_nationals_score($posCur, $nkCur['participants']);
            $scorePrev = ranking_nationals_score($posPrev, $nkPrev['participants']);

            if ($isHistoric) {
                $posPrev2 = $nkPrev2['pos'][$pilotId] ?? null;
                $scorePrev2 = ranking_nationals_score($posPrev2, $nkPrev2['participants']);
                if ($scoreCur <= 0 && $scorePrev <= 0 && $scorePrev2 <= 0) {
                    continue;
                }
                $row = [
                    'id' => $pilotId,
                    'name' => (string)$pilot['name'],
                    'pos_cur' => $posCur,
                    'pos_prev' => $posPrev,
                    'pos_prev2' => $posPrev2,
                    'nk_cur' => $scoreCur,
                    'nk_prev' => 0.8 * $scorePrev,
                    'nk_prev2' => 0.6 * $scorePrev2,
                    'wprs_raw' => null,
                    'wprs' => null,
                ];
                $row['total'] = $row['nk_cur'] + $row['nk_prev'] + $row['nk_prev2'];
            } else {
                $rawWprs = $wprs['points'][$pilotId] ?? null;
                $scoreWprs = ranking_wprs_score($rawWprs, $wprs['max']);
                if ($scoreCur <= 0 && $scorePrev <= 0 && $scoreWprs <= 0) {
                    continue;
                }
                $row = [
                    'id' => $pilotId,
                    'name' => (string)$pilot['name'],
                    'pos_cur' => $posCur,
                    'pos_prev' => $posPrev,
                    'nk_cur' => $scoreCur,
                    'nk_prev' => 0.5 * $scorePrev,
                    'nk_prev2' => null,
                    'pos_prev2' => null,
                    'wprs_raw' => $rawWprs,
                    'wprs' => 1.5 * $scoreWprs,
                ];
                $row['total'] = $row['nk_cur'] + $row['nk_prev'] + $row['wprs'];
            }
            $rows[] = $row;
        }

        usort($rows, function ($a, $b) {
            if (abs($a['total'] - $b['total']) < 1e-9) {
                return strcasecmp($a['name'], $b['name']);
            }
            return $a['total'] < $b['total'] ? 1 : -1;
        });

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);
    }

    return [
        'class' => $class,
        'year' => $year,
        'is_historic' => $isHistoric,
        'available' => $available,
        'rows' => $rows,
        'nk_cur' => $nkCur,
        'nk_prev' => $nkPrev,
        'nk_prev2' => $nkPrev2,
        'max_wprs' => $wprs['max'],
    ];
}

function ranking_latest_complete_year(PDO $pdo, string $class): ?int {
    foreach (ranking_years($pdo, $class) as $year) {
        $ranking = ranking_compute($pdo, $class, (int)$year);
        if ($ranking['available']) {
            return (int)$year;
        }
    }
    return null;
}

function ranking_pilot_history(PDO $pdo, int $pilotId, array $classes = ['Klasse 1', 'Sportklasse']): array {
    $years = [];
    foreach ($classes as $class) {
        foreach (ranking_years($pdo, $class) as $year) {
            if (!in_array($year, $years, true)) {
                $years[] = $year;
            }
        }
    }
    rsort($years);

    $history = [];
    foreach ($years as $year) {
        foreach ($classes as $class) {
            $ranking = ranking_compute($pdo, $class, (int)$year);
            if (!$ranking['available']) {
                continue;
            }
            foreach ($ranking['rows'] as $row) {
                if ((int)$row['id'] === $pilotId) {
                    $row['year'] = (int)$year;
                    $row['class'] = $class;
                    $row['historic'] = $ranking['is_historic'];
                    $history[] = $row;
                    break;
                }
            }
        }
    }

    return $history;
}
