<?php
/**
 * Mini Oyun — JSON API yardımcıları.
 * Sözleşme: {ok:true, ...} veya {ok:false, code, error}.
 * Üretimde asla yığın izi / SQL / dosya yolu / anahtar döndürülmez.
 */
declare(strict_types=1);

const MG_MAX_PAYLOAD_BYTES = 262144; // 256 KB

function mg_api_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

function mg_api_error(string $code, string $message, int $status = 400, array $extra = []): void
{
    mg_api_json(['ok' => false, 'code' => $code, 'error' => $message] + $extra, $status);
}

/**
 * Uç noktayı sarar: beklenmeyen hata üretimde genel mesaja dönüşür.
 * Ayrıntılar sunucu hata günlüğüne yazılır (kullanıcıya gösterilmez).
 */
function mg_api_handle(callable $handler): void
{
    try {
        $handler();
    } catch (Throwable $error) {
        error_log('[minigame] ' . get_class($error) . ': ' . $error->getMessage());
        mg_api_error('server_error', 'Beklenmeyen bir hata oluştu. Lütfen tekrar dene.', 500);
    }
}

function mg_api_raw_body(): string
{
    $raw = file_get_contents('php://input', false, null, 0, MG_MAX_PAYLOAD_BYTES + 1);
    if ($raw === false) {
        return '';
    }
    if (strlen($raw) > MG_MAX_PAYLOAD_BYTES) {
        mg_api_error('payload_too_large', 'İstek gövdesi çok büyük.', 413);
    }
    return $raw;
}

function mg_api_body(): array
{
    $raw = mg_api_raw_body();
    if ($raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        mg_api_error('bad_json', 'Geçersiz istek gövdesi.', 400);
    }
    return $decoded;
}

function mg_api_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        mg_api_error('method_not_allowed', 'Yöntem desteklenmiyor.', 405);
    }
}

function mg_api_require_csrf(array $body): void
{
    $sent = (string)($body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($sent === '' || !csrf_verify($sent)) {
        mg_api_error('csrf', 'Oturum doğrulaması başarısız. Sayfayı yenile.', 403);
    }
}

function mg_api_require_user(): array
{
    if (!is_logged_in()) {
        mg_api_error('auth', 'Bu işlem için giriş yapmalısın.', 401);
    }
    $user = current_user();
    if (!$user) {
        mg_api_error('auth', 'Oturum sona ermiş. Yeniden giriş yap.', 401);
    }
    if ((int)($user['is_banned'] ?? 0) === 1) {
        mg_api_error('banned', 'Hesabın engellenmiş.', 403);
    }
    return $user;
}

function mg_api_identity(): string
{
    $uid = current_user_id();
    return $uid ? 'u' . $uid : 'ip' . mg_ip_hash();
}

function mg_api_guard(string $scope, int $limit, int $windowSeconds): void
{
    if (!mg_rate_limit($scope, mg_api_identity(), $limit, $windowSeconds)) {
        mg_api_error('rate_limited', 'Çok fazla istek. Lütfen biraz bekle.', 429);
    }
}

/** İstemciye dönen sadeleştirilmiş koşu sonucu. */
function mg_api_result_payload(array $result): array
{
    return [
        'ticks' => (int)($result['ticks'] ?? 0),
        'seconds' => round(((int)($result['ticks'] ?? 0)) / 60, 1),
        'score' => (int)($result['score'] ?? 0),
        'cells' => (int)($result['cells'] ?? 0),
        'cells_seen' => (int)($result['cellsSeen'] ?? 0),
        'distance' => (int)($result['distance'] ?? 0),
        'reason' => (string)($result['reason'] ?? ''),
        // v2 savaş özeti (yalnızca oyuncunun kendi turu; gizli bilgi yok).
        'kills' => (int)($result['kills'] ?? 0),
        'boss_killed' => !empty($result['bossKilled']),
        'boss_max_hp' => (int)($result['bossMaxHp'] ?? 0),
        'boss_hp_left' => (int)($result['bossHpLeft'] ?? 0),
        'damage_taken' => (int)($result['damageTaken'] ?? 0),
        'hp' => (int)($result['hp'] ?? 0),
        'shots_fired' => (int)($result['shotsFired'] ?? 0),
    ];
}
