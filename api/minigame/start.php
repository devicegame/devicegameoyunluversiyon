<?php
/** POST /api/minigame/start.php — sıralı tur başlatır (sunucu seed + token). */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('POST');
    $body = mg_api_body();
    mg_api_require_csrf($body);
    $user = mg_api_require_user();
    mg_api_guard('mg-start', 30, 600);

    $result = mg_run_start((int)$user['id']);
    if (empty($result['ok'])) {
        mg_api_error((string)($result['code'] ?? 'start_failed'), (string)($result['error'] ?? 'Tur başlatılamadı.'), 409, [
            'attempts' => $result['attempts'] ?? null,
            'limit' => $result['limit'] ?? null,
        ]);
    }
    mg_api_json($result);
});
