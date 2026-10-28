<?php
/**
 * Mini Oyun — ortak yükleyici.
 * Sıra önemlidir: çekirdek → motor → depolama → ayarlar → katalog → istatistik
 * → sıralama → cüzdan → sezon → koşu → yönetici → API yardımcıları.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/helpers.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/leaderboard.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/season.php';
require_once __DIR__ . '/run.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/maintenance.php';

/** Sayfa/API için güvenli durum özeti (e-posta, IP veya token içermez). */
function mg_public_state(?array $user): array
{
    $now = time();
    $day = mg_day_bounds($now);
    $settings = mg_settings();

    $flags = [];
    foreach ($settings['flags'] as $name => $value) {
        $flags[$name] = (int)$value === 1;
    }

    $season = mg_active_season($now);
    $engineConfig = mg_engine_config();

    $payload = [
        'ok' => true,
        'now' => $now,
        'flags' => $flags,
        'engine' => [
            'version' => MG_ENGINE_VERSION,
            'tickHz' => $engineConfig['tickHz'],
            'maxTicks' => $engineConfig['maxTicks'],
            'config' => $engineConfig,
        ],
        'board' => [
            'seed_id' => mg_current_board($now, $season)['seed_id'],
            'day_key' => $day['key'],
            'week_key' => mg_week_bounds($now)['key'],
            'season_id' => $season ? (int)$season['id'] : null,
        ],
        'season' => mg_season_public($season),
        'champions' => mg_champions(8),
        'maintenance' => (string)($settings['maintenance_note'] ?? ''),
        'economy' => [
            'approved' => (int)($settings['economy']['approved'] ?? 0) === 1,
            'note' => (string)($settings['economy']['note'] ?? ''),
        ],
        'shop_enabled' => $flags['shop'] ?? false,
        'leaderboard_enabled' => $flags['leaderboard'] ?? false,
        'practice_enabled' => $flags['practice'] ?? false,
        'max_inputs' => mg_limit('max_inputs', 600),
    ];

    if ($user) {
        $payload['user'] = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'is_admin' => (int)($user['is_admin'] ?? 0) === 1,
        ];
        $payload['wallet'] = mg_wallet_state((int)$user['id'], $day);
        $payload['attempts'] = mg_attempts_count($day['key'], (int)$user['id']);
        $payload['attempt_limit'] = mg_limit('max_ranked_runs_per_day', 20);
        $payload['rank'] = mg_board_user_rank(mg_board_key('today', $now), (int)$user['id']);
        $payload['last_run'] = mg_user_last_run((int)$user['id']);
    } else {
        $payload['user'] = null;
    }

    $payload['csrf'] = csrf_token();
    return $payload;
}

/** Sayfaya gömülecek JSON: </script> kaçışı dahil güvenli. */
function mg_json_for_html(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}';
}
