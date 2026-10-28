<?php
/** GET /api/minigame/run.php?id=run_... — koşu durumu (idempotent kontrol). */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('GET');
    $user = mg_api_require_user();
    mg_api_guard('mg-run-status', 240, 300);

    $runId = trim((string)($_GET['id'] ?? ''));
    if (!preg_match('/^run_[a-f0-9]{24}$/', $runId)) {
        mg_api_error('bad_run', 'Koşu bulunamadı.', 404);
    }
    $result = mg_run_status((int)$user['id'], $runId);
    if (empty($result['ok'])) {
        mg_api_error((string)($result['code'] ?? 'not_found'), (string)($result['error'] ?? 'Koşu bulunamadı.'), 404);
    }
    $payload = [
        'ok' => true,
        'status' => (string)$result['status'],
        'result' => $result['result'] ? mg_api_result_payload($result['result']) : null,
        'rewards' => $result['rewards'] ?? null,
    ];
    mg_api_json($payload);
});
