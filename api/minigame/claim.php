<?php
/** POST /api/minigame/claim.php {csrF, run_id} — ödenmemiş ödülü idempotent yeniden dener. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('POST');
    $body = mg_api_body();
    mg_api_require_csrf($body);
    $user = mg_api_require_user();
    mg_api_guard('mg-claim', 30, 600);

    $runId = trim((string)($body['run_id'] ?? ''));
    if (!preg_match('/^run_[a-f0-9]{24}$/', $runId)) {
        mg_api_error('bad_request', 'Koşu bilgisi eksik.', 400);
    }
    $result = mg_run_claim((int)$user['id'], $runId);
    if (empty($result['ok'])) {
        mg_api_error((string)($result['code'] ?? 'claim_failed'), (string)($result['error'] ?? 'Ödül alınamadı.'), 409);
    }
    mg_api_json($result + ['ok' => true]);
});
