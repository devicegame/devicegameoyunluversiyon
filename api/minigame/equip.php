<?php
/** POST /api/minigame/equip.php {csrf, slot, item_id} — kuşan/çıkar. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('POST');
    $body = mg_api_body();
    mg_api_require_csrf($body);
    $user = mg_api_require_user();
    mg_api_guard('mg-equip', 60, 600);

    $slot = trim((string)($body['slot'] ?? ''));
    $itemId = trim((string)($body['item_id'] ?? ''));
    if ($itemId === '') {
        $result = mg_unequip_item((int)$user['id'], $slot);
    } else {
        $result = mg_equip_item((int)$user['id'], $slot, $itemId);
    }
    if (empty($result['ok'])) {
        mg_api_error((string)($result['code'] ?? 'equip_failed'), (string)($result['error'] ?? 'Kuşanılamadı.'), 409);
    }

    $loadout = mg_loadout((int)$user['id']);
    mg_api_json([
        'ok' => true,
        'loadout' => $loadout,
        'visuals' => mg_loadout_visuals($loadout),
    ]);
});
