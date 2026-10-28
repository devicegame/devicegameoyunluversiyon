<?php
/** GET /api/minigame/season.php — aktif sezon, geçmiş, şampiyonlar. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('GET');
    mg_api_guard('mg-season', 120, 300);

    $now = time();
    $active = mg_active_season($now);
    $past = [];
    foreach (array_reverse(mg_seasons()) as $season) {
        if (in_array($season['status'] ?? '', ['finalized', 'delivered'], true)) {
            $past[] = mg_season_public($season);
        }
    }

    mg_api_json([
        'ok' => true,
        'active' => mg_season_public($active),
        'past' => array_slice($past, 0, 8),
        'champions' => mg_champions(8),
        'enabled' => mg_flag('season'),
    ]);
});
