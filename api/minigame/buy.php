<?php
/** POST /api/minigame/buy.php {csrf, item_id} — doğrudan kozmetik satın alma. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('POST');
    $body = mg_api_body();
    mg_api_require_csrf($body);
    $user = mg_api_require_user();
    mg_api_guard('mg-buy', 20, 600);

    $itemId = trim((string)($body['item_id'] ?? ''));
    if ($itemId === '' || !preg_match('/^[a-z0-9-]{1,40}$/', $itemId)) {
        mg_api_error('bad_request', 'Kozmetik belirtilmedi.', 400);
    }

    $result = mg_purchase_cosmetic((int)$user['id'], $itemId);
    if (empty($result['ok'])) {
        $status = ($result['code'] ?? '') === 'insufficient_funds' ? 402 : 409;
        mg_api_error((string)($result['code'] ?? 'purchase_failed'), (string)($result['error'] ?? 'Satın alma başarısız.'), $status, [
            'balance' => $result['balance'] ?? null,
            'price' => $result['price'] ?? null,
        ]);
    }

    mg_api_json([
        'ok' => true,
        'already_owned' => !empty($result['already_owned']),
        'charged' => (int)($result['charged'] ?? 0),
        'balance' => (int)($result['balance'] ?? 0),
        'item_id' => $result['item']['id'] ?? $itemId,
    ]);
});
