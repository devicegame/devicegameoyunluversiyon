<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

function dg_watch_complete_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dg_watch_complete_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

if (!csrf_verify((string)($input['csrf'] ?? ''))) {
    dg_watch_complete_out(['ok' => false, 'error' => 'CSRF doğrulaması başarısız'], 403);
}
if (!is_logged_in()) dg_watch_complete_out(['ok' => false, 'error' => 'Giriş yapmalısın'], 401);

$user = current_user();
if (!$user) dg_watch_complete_out(['ok' => false, 'error' => 'Giriş yapmalısın'], 401);
if ((int)$user['is_banned'] === 1) dg_watch_complete_out(['ok' => false, 'error' => 'Hesabın engellenmiş'], 403);

$uid = (int)$user['id'];
$ad_id = (int)($input['ad_id'] ?? 0);
$token = trim((string)($input['token'] ?? ''));
if ($ad_id <= 0 || $token === '') dg_watch_complete_out(['ok' => false, 'error' => 'Eksik parametre']);

$tokenHash = hash('sha256', $token);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

try {
    $result = dg_mutate(function (array &$data) use ($uid, $ad_id, $tokenHash, $ip, $ua): array {
        $userIndex = dg_index_of_id($data['users'], $uid);
        if ($userIndex === null) return ['ok' => false, 'error' => 'Kullanıcı bulunamadı'];
        if ((int)($data['users'][$userIndex]['is_banned'] ?? 0) === 1) {
            return ['ok' => false, 'error' => 'Hesabın engellenmiş'];
        }

        $ad = dg_find_by_id($data['ads'], $ad_id);
        if (!$ad || (int)($ad['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Reklam bulunamadı'];
        }

        $limit = max(1, (int)($data['settings']['daily_watch_limit'] ?? DG_DAILY_LIMIT_DEFAULT));
        if (today_watch_count($uid, $data) >= $limit) {
            return ['ok' => false, 'error' => 'Günlük limit doldu'];
        }

        $sessionIndex = null;
        foreach ($data['watch_sessions'] as $i => $s) {
            $storedHash = (string)($s['token_hash'] ?? '');
            // Eski/geçiş kayıtlarıyla uyumluluk: token alanı düz metinse hash'leyip karşılaştır.
            if ($storedHash === '' && !empty($s['token'])) $storedHash = hash('sha256', (string)$s['token']);
            if (
                hash_equals($storedHash, $tokenHash) &&
                (int)($s['user_id'] ?? 0) === $uid &&
                (int)($s['ad_id'] ?? 0) === $ad_id &&
                (int)($s['is_completed'] ?? 0) === 0
            ) {
                $sessionIndex = (int)$i;
                break;
            }
        }
        if ($sessionIndex === null) {
            return ['ok' => false, 'error' => 'Geçersiz veya zaten kullanılmış oturum'];
        }

        $session = $data['watch_sessions'][$sessionIndex];
        $started = (int)($session['started_ts'] ?? 0);
        if ($started <= 0) $started = (int)strtotime((string)($session['started_at'] ?? 'now'));
        $elapsed = time() - $started;
        $required = max(5, (int)$ad['duration_sec'] - 2);
        if ($elapsed < $required) {
            return ['ok' => false, 'error' => "Video henüz tam izlenmedi. Beklenen: {$required}s, geçen: {$elapsed}s. Lütfen sonuna kadar izleyin."];
        }

        $earned = max(0, (int)$ad['reward_dcoin']);
        $data['users'][$userIndex]['dcoin_balance'] = (int)($data['users'][$userIndex]['dcoin_balance'] ?? 0) + $earned;
        $data['users'][$userIndex]['updated_at'] = dg_now();

        $data['watch_logs'][] = [
            'id' => dg_next_id($data, 'watch_logs'),
            'user_id' => $uid,
            'ad_id' => $ad_id,
            'ad_title' => (string)$ad['title'],
            'earned_dcoin' => $earned,
            'watched_at' => dg_now(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ];
        $data['watch_sessions'][$sessionIndex]['is_completed'] = 1;
        $data['watch_sessions'][$sessionIndex]['completed_at'] = dg_now();

        return [
            'ok' => true,
            'earned' => $earned,
            'new_balance' => (int)$data['users'][$userIndex]['dcoin_balance'],
            'today_count' => today_watch_count($uid, $data),
            'daily_limit' => $limit,
        ];
    });
} catch (Throwable $e) {
    error_log('[watch_complete] ' . $e->getMessage());
    dg_watch_complete_out(['ok' => false, 'error' => 'Ödül verilirken hata oluştu'], 500);
}

if (!empty($result['ok'])) {
    $_SESSION['dcoin_balance'] = (int)$result['new_balance'];
}
dg_watch_complete_out($result, !empty($result['ok']) ? 200 : 400);
