<?php
/** GET /api/minigame/state.php — genel durum + kullanıcı cüzdanı. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('GET');
    mg_api_guard('mg-state', 180, 300);
    $user = is_logged_in() ? current_user() : null;
    mg_api_json(mg_public_state($user));
});
