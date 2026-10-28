<?php
/**
 * Günlük kullanıcı istatistikleri (görev ilerlemesi bundan hesaplanır).
 * Yalnızca doğrulanmış, yeterince uzun turlar sayılır.
 */
declare(strict_types=1);

function mg_stats_path(string $dayKey): string
{
    return 'stats/' . mg_safe_key($dayKey) . '.json';
}

function mg_stats_day(string $dayKey): array
{
    $data = mg_read_json(mg_stats_path($dayKey), []);
    if (!isset($data['users']) || !is_array($data['users'])) {
        $data = ['day' => $dayKey, 'users' => []];
    }
    return $data;
}

function mg_stats_user(string $dayKey, int $userId): array
{
    $day = mg_stats_day($dayKey);
    $row = $day['users'][(string)$userId] ?? [];
    return [
        'runs' => (int)($row['runs'] ?? 0),
        'valid_runs' => (int)($row['valid_runs'] ?? 0),
        'best_score' => (int)($row['best_score'] ?? 0),
        'best_cells' => (int)($row['best_cells'] ?? 0),
        'cells_total' => (int)($row['cells_total'] ?? 0),
        'last_ts' => (int)($row['last_ts'] ?? 0),
    ];
}

/**
 * Koşu sonucunu günlük istatistiğe işler. Çağıran taraf `mg_lock()` tutmalıdır.
 * @param int $minTicks "geçerli tur" eşiği (varsayılan 10 sn)
 */
function mg_stats_bump(string $dayKey, int $userId, array $result, int $minTicks, int $now): array
{
    $day = mg_stats_day($dayKey);
    $key = (string)$userId;
    $row = $day['users'][$key] ?? [
        'runs' => 0,
        'valid_runs' => 0,
        'best_score' => 0,
        'best_cells' => 0,
        'cells_total' => 0,
        'last_ts' => 0,
    ];
    $row['runs'] = (int)$row['runs'] + 1;
    $row['cells_total'] = (int)$row['cells_total'] + (int)($result['cells'] ?? 0);
    if ((int)($result['ticks'] ?? 0) >= $minTicks) {
        $row['valid_runs'] = (int)$row['valid_runs'] + 1;
        $row['best_score'] = max((int)$row['best_score'], (int)($result['score'] ?? 0));
        $row['best_cells'] = max((int)$row['best_cells'], (int)($result['cells'] ?? 0));
    }
    $row['last_ts'] = $now;
    $day['users'][$key] = $row;
    mg_write_json(mg_stats_path($dayKey), $day);
    return $row;
}

/** Görev metriğinin bugünkü değeri. */
function mg_task_progress_value(string $metric, array $stats): int
{
    switch ($metric) {
        case 'valid_runs':
            return (int)($stats['valid_runs'] ?? 0);
        case 'best_score':
            return (int)($stats['best_score'] ?? 0);
        case 'best_cells':
            return (int)($stats['best_cells'] ?? 0);
        case 'cells_total':
            return (int)($stats['cells_total'] ?? 0);
        default:
            return 0;
    }
}
