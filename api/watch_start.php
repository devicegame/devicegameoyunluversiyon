<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

function dg_watch_start_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dg_watch_start_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

if (!csrf_verify((string)($input['csrf'] ?? ''))) {
    dg_watch_start_out(['ok' => false, 'error' => 'CSRF doğrulaması başarısız'], 403);
}
if (!is_logged_in()) dg_watch_start_out(['ok' => false, 'error' => 'Giriş yapmalısın'], 401);

$user = current_user();
if (!$user) dg_watch_start_out(['ok' => false, 'error' => 'Giriş yapmalısın'], 401);
if ((int)$user['is_banned'] === 1) dg_watch_start_out(['ok' => false, 'error' => 'Hesabın engellenmiş'], 403);

$uid = (int)$user['id'];
$ad_id = (int)($input['ad_id'] ?? 0);
if ($ad_id <= 0) dg_watch_start_out(['ok' => false, 'error' => 'Geçersiz reklam']);

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $result = dg_mutate(function (array &$data) use ($uid, $ad_id, $tokenHash, $ip): array {
        $userIndex = dg_index_of_id($data['users'], $uid);
        if ($userIndex === null) return ['ok' => false, 'error' => 'Kullanıcı bulunamadı'];
        if ((int)($data['users'][$userIndex]['is_banned'] ?? 0) === 1) {
            return ['ok' => false, 'error' => 'Hesabın engellenmiş'];
        }

        $ad = dg_find_by_id($data['ads'], $ad_id);
        if (!$ad || (int)($ad['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Reklam bulunamadı veya pasif'];
        }

        $limit = max(1, (int)($data['settings']['daily_watch_limit'] ?? DG_DAILY_LIMIT_DEFAULT));
        $today = today_watch_count($uid, $data);
        if ($today >= $limit) {
            return ['ok' => false, 'error' => "Günlük limit doldu ($limit/$limit). Yarın tekrar gel!"];
        }

        // İki saatten eski izleme oturumlarını temizle; veri dosyası şişmesin.
        $cutoff = time() - 7200;
        $data['watch_sessions'] = array_values(array_filter(
            $data['watch_sessions'],
            function (array $s) use ($cutoff): bool {
                $ts = (int)($s['started_ts'] ?? 0);
                if ($ts <= 0) $ts = (int)strtotime((string)($s['started_at'] ?? 'now'));
                return $ts >= $cutoff;
            }
        ));

        $data['watch_sessions'][] = [
            'id' => dg_next_id($data, 'watch_sessions'),
            'user_id' => $uid,
            'ad_id' => $ad_id,
            'token_hash' => $tokenHash,
            'started_at' => dg_now(),
            'started_ts' => time(),
            'completed_at' => null,
            'is_completed' => 0,
            'ip_address' => $ip,
        ];

        return [
            'ok' => true,
            'reward' => (int)$ad['reward_dcoin'],
            'duration' => (int)$ad['duration_sec'],
        ];
    });
} catch (Throwable $e) {
    error_log('[watch_start] ' . $e->getMessage());
    dg_watch_start_out(['ok' => false, 'error' => 'Oturum oluşturulamadı'], 500);
}

if (empty($result['ok'])) dg_watch_start_out($result);

$_SESSION['watch_' . $ad_id] = $token;
dg_watch_start_out([
    'ok' => true,
    'token' => $token,
    'reward' => (int)$result['reward'],
    'duration' => (int)$result['duration'],
]);
