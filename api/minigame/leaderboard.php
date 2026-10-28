<?php
/** GET /api/minigame/leaderboard.php?board=today|week|all|season&page=1 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('GET');
    mg_api_guard('mg-leaderboard', 180, 300);

    if (!mg_flag('leaderboard')) {
        mg_api_error('leaderboard_disabled', 'Sıralama tablosu şu an kapalı.', 503);
    }

    $user = is_logged_in() ? current_user() : null;
    $viewerId = $user ? (int)$user['id'] : null;
    $type = (string)($_GET['board'] ?? 'today');
    if (!in_array($type, ['today', 'week', 'all', 'season'], true)) {
        $type = 'today';
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $now = time();
    $season = mg_active_season($now);

    if ($type === 'season' && !$season) {
        mg_api_json(['ok' => true, 'board' => 'season', 'type' => 'season', 'rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'me' => null, 'no_season' => true]);
    }

    $key = mg_board_key($type, $now, $season);
    $view = mg_board_view($key, $page, 20, $viewerId);
    $view['ok'] = true;
    $view['type'] = $type;
    $view['seed_id'] = mg_current_board($now, $season)['seed_id'];
    $view['version'] = MG_ENGINE_VERSION;
    $view['season_title'] = $season ? (string)$season['title'] : null;
    mg_api_json($view);
});
