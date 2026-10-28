<?php
/** GET /api/minigame/catalog.php — kozmetikler + sahiplik + kuşanım. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('GET');
    mg_api_guard('mg-catalog', 180, 300);

    $user = is_logged_in() ? current_user() : null;
    $owned = $user ? mg_owned_item_ids((int)$user['id']) : mg_free_item_ids();
    $loadout = $user ? mg_loadout((int)$user['id']) : mg_default_loadout();

    $items = [];
    foreach (mg_catalog() as $item) {
        $isOwned = in_array($item['id'], $owned, true);
        $items[] = $item + [
            'owned' => $isOwned,
            'can_buy' => (bool)$user && !$isOwned && $item['purchasable'] === 1 && $item['price'] > 0 && $item['published'] === 1,
        ];
    }

    mg_api_json([
        'ok' => true,
        'items' => $items,
        'categories' => MG_CATEGORY_LABELS,
        'owned' => $owned,
        'loadout' => $loadout,
        'visuals' => mg_loadout_visuals($loadout),
        'balance' => $user ? mg_wallet_state((int)$user['id'], mg_day_bounds(time()))['balance'] : null,
        'shop_enabled' => mg_flag('shop'),
    ]);
});
