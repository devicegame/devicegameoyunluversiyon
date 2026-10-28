<?php
/**
 * POST /api/minigame/submit.php
 * Gövde: {csrf, run_id, token, inputs:[[tick,action],...], score?}
 * Skor gönderilse bile otorite sunucudur; uyuşmazsa koşu reddedilir.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

mg_api_handle(static function (): void {
    mg_api_method('POST');
    $body = mg_api_body();
    mg_api_require_csrf($body);
    $user = mg_api_require_user();
    mg_api_guard('mg-submit', 60, 600);

    $runId = trim((string)($body['run_id'] ?? ''));
    $token = trim((string)($body['token'] ?? ''));
    $inputs = $body['inputs'] ?? [];
    $clientScore = isset($body['score']) && is_numeric($body['score']) ? (int)$body['score'] : null;

    if (!preg_match('/^run_[a-f0-9]{24}$/', $runId) || $token === '') {
        mg_api_error('bad_request', 'Koşu bilgisi eksik.', 400);
    }

    $result = mg_run_submit((int)$user['id'], $runId, $token, $inputs, $clientScore);
    if (empty($result['ok'])) {
        $code = (string)($result['code'] ?? 'submit_failed');
        $status = in_array($code, ['forbidden', 'bad_token'], true) ? 403 : 422;
        if ($code === 'bad_run') {
            $status = 404;
        }
        if ($code === 'already_finalized' || $code === 'rejected') {
            $status = 409;
        }
        $payload = ['ok' => false, 'code' => $code, 'error' => (string)($result['error'] ?? 'Tur doğrulanamadı.')];
        if (!empty($result['result'])) {
            $payload['result'] = mg_api_result_payload($result['result']);
        }
        mg_api_json($payload, $status);
    }

    $payload = [
        'ok' => true,
        'status' => (string)($result['status'] ?? ''),
        'pending' => !empty($result['pending']),
        'duplicate' => !empty($result['duplicate']),
        'result' => mg_api_result_payload($result['result'] ?? []),
        'counts' => !empty($result['counts']),
        'attempts' => $result['attempts'] ?? null,
        'attempt_limit' => $result['attempt_limit'] ?? null,
    ];
    if (isset($result['rewards'])) {
        $payload['rewards'] = [
            'paid' => (int)($result['rewards']['paid'] ?? 0),
            'balance' => (int)($result['rewards']['balance'] ?? 0),
            'claims' => $result['rewards']['claims'] ?? [],
            'cap_reached' => !empty($result['rewards']['cap_reached']),
            'budget_exhausted' => !empty($result['rewards']['budget_exhausted']),
        ];
    }
    if (!empty($result['pending'])) {
        $payload['notice'] = 'Tur incelemeye alındı. Sonuç onaylanınca sıralamaya işlenecek.';
    }
    mg_api_json($payload);
});
