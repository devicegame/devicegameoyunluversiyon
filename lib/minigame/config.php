<?php
/**
 * Mini Oyun — ayarlar, bayraklar, limitler, dönemler ve tohumlar.
 *
 * Dönemler: depolama UTC'dir; günlük/haftalık sınırlar Europe/Istanbul
 * saatine göre YARI AÇIK aralıklardır [başlangıç, bitiş).
 */
declare(strict_types=1);

const MG_SETTINGS_SCHEMA = 2;

/** Başlangıç ayarları (seed). */
function mg_default_settings(): array
{
    static $defaults = null;
    if ($defaults === null) {
        $path = __DIR__ . '/../../data/minigame/settings.php';
        $defaults = is_file($path) ? (array)require $path : [
            'schema' => MG_SETTINGS_SCHEMA,
            'flags' => ['game' => 1, 'practice' => 1, 'rewards' => 1, 'shop' => 0, 'leaderboard' => 1, 'season' => 0],
            'limits' => ['max_ranked_runs_per_day' => 20, 'daily_user_reward_cap' => 100, 'daily_global_budget' => 5000],
            'tasks' => [],
            'economy' => ['approved' => 0, 'note' => ''],
            'maintenance_note' => '',
        ];
    }
    return $defaults;
}

/** Depodaki ayarları varsayılanlarla birleştirir (eksik alan = varsayılan). */
function mg_settings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    mg_ensure_storage();
    $defaults = mg_default_settings();
    $stored = mg_read_json('settings.json', []);
    if (!$stored) {
        $settings = $defaults;
        mg_write_json('settings.json', $settings);
    } else {
        $settings = array_replace_recursive($defaults, $stored);
        // Görev listesi varsa bütünüyle depodan gelir (admin düzenler).
        $settings['tasks'] = isset($stored['tasks']) && is_array($stored['tasks']) ? array_values($stored['tasks']) : $defaults['tasks'];
    }
    $cache = $settings;
    return $cache;
}

function mg_set_settings(array $settings, ?int $actorId = null): array
{
    $defaults = mg_default_settings();
    $settings = array_replace_recursive($defaults, $settings);
    if (!isset($settings['tasks']) || !is_array($settings['tasks'])) {
        $settings['tasks'] = $defaults['tasks'];
    }
    $settings['updated_utc'] = mg_now_utc();
    $settings['updated_by'] = $actorId;
    mg_write_json('settings.json', $settings);
    mg_audit($actorId, 'settings.update', 'settings', ['flags' => $settings['flags'], 'limits' => $settings['limits']]);
    mg_settings(true);
    return $settings;
}

function mg_flag(string $name): bool
{
    $settings = mg_settings();
    return (int)($settings['flags'][$name] ?? 0) === 1;
}

function mg_limit(string $name, int $default = 0): int
{
    $settings = mg_settings();
    $value = $settings['limits'][$name] ?? $default;
    return is_numeric($value) ? max(0, (int)$value) : $default;
}

/** Aktif görevler (enabled=1), sıra numarasına göre. */
function mg_tasks(bool $onlyEnabled = true): array
{
    $settings = mg_settings();
    $tasks = [];
    foreach ($settings['tasks'] as $task) {
        if (!is_array($task) || empty($task['id'])) {
            continue;
        }
        if ($onlyEnabled && (int)($task['enabled'] ?? 1) !== 1) {
            continue;
        }
        $task['target'] = max(1, (int)($task['target'] ?? 1));
        $task['reward'] = max(0, (int)($task['reward'] ?? 0));
        $tasks[] = $task;
    }
    usort($tasks, static fn(array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
    return $tasks;
}

function mg_task(?string $taskId): ?array
{
    foreach (mg_tasks(false) as $task) {
        if ((string)$task['id'] === (string)$taskId) {
            return $task;
        }
    }
    return null;
}

/* ------------------------------------------------------------------ *
 * Dönemler (Europe/Istanbul, yarı açık aralık)
 * ------------------------------------------------------------------ */

function mg_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Europe/Istanbul');
    }
    return $tz;
}

/** @return array{start_ts:int,end_ts:int,key:string} */
function mg_day_bounds(int $ts): array
{
    $local = (new DateTimeImmutable('@' . $ts))->setTimezone(mg_timezone());
    $start = $local->setTime(0, 0, 0);
    $end = $start->modify('+1 day');
    return [
        'start_ts' => $start->getTimestamp(),
        'end_ts' => $end->getTimestamp(),
        'key' => $start->format('Y-m-d'),
    ];
}

/** Pazartesi 00:00 → sonraki pazartesi 00:00 (ISO hafta). */
function mg_week_bounds(int $ts): array
{
    $local = (new DateTimeImmutable('@' . $ts))->setTimezone(mg_timezone());
    $isoDow = (int)$local->format('N'); // 1 = pazartesi … 7 = pazar
    $start = $local->setTime(0, 0, 0)->modify('-' . ($isoDow - 1) . ' days');
    $end = $start->modify('+7 days');
    return [
        'start_ts' => $start->getTimestamp(),
        'end_ts' => $end->getTimestamp(),
        'key' => $start->format('o-\WW'),
    ];
}

/** Verilen zaman bir dönem aralığında mı? (yarı açık) */
function mg_in_period(int $ts, array $period): bool
{
    return $ts >= (int)$period['start_ts'] && $ts < (int)$period['end_ts'];
}

/* ------------------------------------------------------------------ *
 * Tohum (seed) ve sıralama anahtarları
 * ------------------------------------------------------------------ */

/** Günlük bölüm tohumu: aynı gün herkes için aynı düzen. */
function mg_daily_seed(int $now): int
{
    $day = mg_day_bounds($now)['key'];
    $hex = substr(hash('sha256', 'mg-seed|' . MG_ENGINE_VERSION . '|' . $day), 0, 8);
    $seed = (int)hexdec($hex) & 0xFFFFFFFF;
    return $seed === 0 ? 0x1F123BB5 : $seed;
}

function mg_seed_id(string $scope, string $key): string
{
    return $scope . ':' . $key . ':' . MG_ENGINE_VERSION;
}

/**
 * Sıralama anahtarı: <sürüm>:<dönem>.
 * Sürüm ayrımı, motor değişince tabloların karışmasını engeller.
 */
function mg_board_key(string $type, int $now, ?array $season = null): string
{
    switch ($type) {
        case 'today':
            return MG_ENGINE_VERSION . ':d-' . mg_day_bounds($now)['key'];
        case 'week':
            return MG_ENGINE_VERSION . ':w-' . mg_week_bounds($now)['key'];
        case 'season':
            return MG_ENGINE_VERSION . ':s-' . (int)($season['id'] ?? 0);
        case 'all':
        default:
            return MG_ENGINE_VERSION . ':all';
    }
}

/**
 * Koşunun bağlanacağı sıralama/tur bağlamı.
 * Sezon aktifse bölüm tohumu sezona sabitlenir (herkes aynı düzeni oynar).
 * @return array{seed:int,seed_id:string,season_id:?int,season:?array,board_keys:array<string,string>}
 */
function mg_current_board(int $now, ?array $season = null): array
{
    $seasonId = null;
    $seed = 0;
    $seedId = '';

    if ($season && ($season['status'] ?? '') === 'active' && mg_in_period($now, $season) && (int)($season['seed_pinned'] ?? 0) === 1) {
        $seasonId = (int)$season['id'];
        $seed = mg_normalize_seed((int)($season['seed'] ?? 0));
        $seedId = mg_seed_id('season', (string)$seasonId);
    } else {
        $seed = mg_daily_seed($now);
        $seedId = mg_seed_id('daily', mg_day_bounds($now)['key']);
    }

    return [
        'seed' => $seed,
        'seed_id' => $seedId,
        'season_id' => $seasonId,
        'season' => $season,
        'board_keys' => [
            'today' => mg_board_key('today', $now, $season),
            'week' => mg_board_key('week', $now, $season),
            'all' => mg_board_key('all', $now, $season),
            'season' => $seasonId ? mg_board_key('season', $now, $season) : '',
        ],
    ];
}
