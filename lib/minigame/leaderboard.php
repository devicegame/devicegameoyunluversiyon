<?php
/**
 * Sıralamalar: dönem başına kullanıcı-başına en iyi doğrulanmış skor.
 *
 * - Anahtar: <sürüm>:<dönem> (bkz. mg_board_key()).
 * - Beraberlik: skor ↓, ulaşma zamanı ↑, koşu kimliği ↑ (deterministik).
 * - Yalnızca `verified` koşular girer; incelemede/reddedilen girmez.
 * - E-posta/IP asla döndürülmez.
 */
declare(strict_types=1);

function mg_best_path(string $boardKey): string
{
    return 'bests/' . mg_safe_key($boardKey) . '.json';
}

function mg_board_raw(string $boardKey): array
{
    $data = mg_read_json(mg_best_path($boardKey), []);
    if (!isset($data['rows']) || !is_array($data['rows'])) {
        $data = ['key' => $boardKey, 'rows' => []];
    }
    return $data;
}

/** Bir koşuyu tabloya işler (daha iyiyse). Çağıran `mg_lock()` tutmalıdır. */
function mg_best_apply(string $boardKey, int $userId, array $result, string $runId, int $achievedTs): void
{
    if ($boardKey === '') {
        return;
    }
    $board = mg_board_raw($boardKey);
    $current = $board['rows'][(string)$userId] ?? null;
    $candidate = [
        'user_id' => $userId,
        'score' => (int)($result['score'] ?? 0),
        'ticks' => (int)($result['ticks'] ?? 0),
        'cells' => (int)($result['cells'] ?? 0),
        'achieved_ts' => $achievedTs,
        'run_id' => $runId,
        'status' => 'verified',
    ];
    if ($current !== null && mg_compare_rows($candidate, $current) >= 0) {
        return;
    }
    $board['rows'][(string)$userId] = $candidate;
    $board['updated_utc'] = mg_now_utc();
    mg_write_json(mg_best_path($boardKey), $board);
}

/** <0 ise $a daha iyidir. */
function mg_compare_rows(array $a, array $b): int
{
    if ((int)$a['score'] !== (int)$b['score']) {
        return (int)$a['score'] > (int)$b['score'] ? -1 : 1;
    }
    if ((int)$a['achieved_ts'] !== (int)$b['achieved_ts']) {
        return (int)$a['achieved_ts'] < (int)$b['achieved_ts'] ? -1 : 1;
    }
    return strcmp((string)$a['run_id'], (string)$b['run_id']);
}

/** Ham satırlar, sıralı (kullanıcı adı yok). */
function mg_board_sorted(string $boardKey): array
{
    $board = mg_board_raw($boardKey);
    $rows = array_values($board['rows']);
    usort($rows, 'mg_compare_rows');
    return $rows;
}

/**
 * Görünüm: kullanıcı adı, kozmetik görseli, kendi sıran ve ilk 10 farkı.
 * Banlı kullanıcılar listelenmez.
 */
function mg_board_view(string $boardKey, int $page = 1, int $perPage = 20, ?int $viewerId = null): array
{
    $perPage = max(5, min(50, $perPage));
    $page = max(1, $page);
    $rows = mg_board_sorted($boardKey);

    $data = dg_read_data();
    $users = [];
    foreach ($data['users'] ?? [] as $user) {
        $users[(int)$user['id']] = $user;
    }

    $visualCache = [];
    $filtered = [];
    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        $user = $users[$uid] ?? null;
        if (!$user || (int)($user['is_banned'] ?? 0) === 1) {
            continue;
        }
        if (!isset($visualCache[$uid])) {
            $visualCache[$uid] = mg_loadout_visuals(mg_loadout($uid, $data));
        }
        $row['username'] = (string)$user['username'];
        $row['visual'] = $visualCache[$uid];
        $filtered[] = $row;
    }

    $total = count($filtered);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($filtered, $offset, $perPage);

    $out = [];
    foreach ($slice as $i => $row) {
        $out[] = [
            'rank' => $offset + $i + 1,
            'username' => $row['username'],
            'score' => (int)$row['score'],
            'ticks' => (int)$row['ticks'],
            'seconds' => round(((int)$row['ticks']) / 60, 1),
            'cells' => (int)$row['cells'],
            'achieved_at' => (new DateTimeImmutable('@' . (int)$row['achieved_ts']))
                ->setTimezone(mg_timezone())
                ->format('Y-m-d H:i') . ' (TR)',
            'visual' => $row['visual'],
            'is_me' => $viewerId !== null && (int)$row['user_id'] === $viewerId,
        ];
    }

    $me = null;
    if ($viewerId !== null) {
        foreach ($filtered as $i => $row) {
            if ((int)$row['user_id'] === $viewerId) {
                $top10 = $filtered[9]['score'] ?? null;
                $me = [
                    'rank' => $i + 1,
                    'score' => (int)$row['score'],
                    'ticks' => (int)$row['ticks'],
                    'cells' => (int)$row['cells'],
                    'in_top' => $i < 10,
                    'gap_to_top10' => ($i >= 10 && $top10 !== null) ? max(0, (int)$top10 - (int)$row['score']) : 0,
                ];
                break;
            }
        }
    }

    return [
        'board' => $boardKey,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
        'total' => $total,
        'rows' => $out,
        'me' => $me,
    ];
}

/** Kullanıcının tablodaki sırası (yoksa null). */
function mg_board_user_rank(string $boardKey, int $userId): ?array
{
    foreach (mg_board_sorted($boardKey) as $i => $row) {
        if ((int)$row['user_id'] === $userId) {
            return [
                'rank' => $i + 1,
                'score' => (int)$row['score'],
                'ticks' => (int)$row['ticks'],
                'cells' => (int)$row['cells'],
            ];
        }
    }
    return null;
}

/**
 * Yönetici skor düzeltmesi sonrası kullanıcının en iyisini arşivden yeniden
 * hesaplar (yalnızca ilgili dönem). Çağıran `mg_lock()` tutmalıdır.
 */
function mg_recompute_user_best(string $boardKey, int $userId, array $period): void
{
    $best = null;
    foreach (mg_archived_runs() as $run) {
        if ((int)($run['user_id'] ?? 0) !== $userId) {
            continue;
        }
        if (($run['status'] ?? '') !== 'verified') {
            continue;
        }
        $ts = (int)($run['submitted_ts'] ?? 0);
        if ($ts < (int)$period['start_ts'] || $ts >= (int)$period['end_ts']) {
            continue;
        }
        $result = $run['result'] ?? [];
        $candidate = [
            'user_id' => $userId,
            'score' => (int)($run['override']['score'] ?? $result['score'] ?? 0),
            'ticks' => (int)($result['ticks'] ?? 0),
            'cells' => (int)($result['cells'] ?? 0),
            'achieved_ts' => $ts,
            'run_id' => (string)$run['id'],
            'status' => 'verified',
        ];
        if ($best === null || mg_compare_rows($candidate, $best) < 0) {
            $best = $candidate;
        }
    }

    $board = mg_board_raw($boardKey);
    $key = (string)$userId;
    if ($best === null) {
        unset($board['rows'][$key]);
    } else {
        $board['rows'][$key] = $best;
    }
    $board['updated_utc'] = mg_now_utc();
    mg_write_json(mg_best_path($boardKey), $board);
}
