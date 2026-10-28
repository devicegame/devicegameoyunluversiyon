<?php
/**
 * Mini Oyun — yönetici işlemleri (mevcut admin paneli içinden çağrılır).
 *
 * - İnceleme kuyruğu: şüpheli koşuları onayla/reddet (yalnızca `pending`).
 * - Skor düzeltme: gerekçeli, denetim kayıtlı, tablo yeniden hesaplanır.
 * - Ekonomi raporu: dolaşıma giren/harcanan D-Coin + aktif oyuncu.
 *   "Gelir" değildir; gerçek satış verisi mevcut mağaza tablolarındadır.
 */
declare(strict_types=1);

/** İnceleme bekleyen koşular (en yeni önce). */
function mg_pending_runs(int $limit = 30): array
{
    $rows = [];
    foreach (mg_archived_runs(20000) as $run) {
        if (($run['status'] ?? '') !== 'pending') {
            continue;
        }
        $rows[] = [
            'run_id' => (string)$run['id'],
            'user_id' => (int)$run['user_id'],
            'username' => mg_username((int)$run['user_id']),
            'submitted_utc' => (string)($run['submitted_utc'] ?? ''),
            'submitted_ts' => (int)($run['submitted_ts'] ?? 0),
            'score' => (int)($run['result']['score'] ?? 0),
            'ticks' => (int)($run['result']['ticks'] ?? 0),
            'cells' => (int)($run['result']['cells'] ?? 0),
            'risk' => (int)($run['risk'] ?? 0),
            'reasons' => (array)($run['risk_reasons'] ?? []),
            'input_hash' => (string)($run['input_hash'] ?? ''),
            'ip_hash' => (string)($run['ip_hash'] ?? ''),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['submitted_ts'] <=> $a['submitted_ts']);
    return array_slice($rows, 0, max(1, $limit));
}

function mg_username(int $userId): string
{
    foreach (dg_read_data()['users'] ?? [] as $user) {
        if ((int)$user['id'] === $userId) {
            return (string)$user['username'];
        }
    }
    return 'oyuncu#' . $userId;
}

/**
 * İnceleme kararı. Yalnızca `pending` koşular için; ikinci çağrı durumu
 * değiştirmez (idempotent).
 */
function mg_admin_review_run(string $runId, string $decision, string $note, int $adminId): array
{
    $decision = $decision === 'approve' ? 'approve' : ($decision === 'reject' ? 'reject' : '');
    if ($decision === '') {
        return ['ok' => false, 'code' => 'bad_decision', 'error' => 'Geçersiz karar.'];
    }

    $outcome = mg_lock(static function () use ($runId, $decision, $note, $adminId): array {
        $run = mg_run_archived($runId);
        if ($run === null) {
            return ['ok' => false, 'code' => 'not_found', 'error' => 'Koşu bulunamadı.'];
        }
        if (($run['status'] ?? '') === 'verified' && $decision === 'approve') {
            return ['ok' => true, 'already' => true, 'status' => 'verified', 'run' => $run];
        }
        if ($decision === 'approve' && !in_array($run['status'] ?? '', ['pending'], true)) {
            return ['ok' => false, 'code' => 'not_pending', 'error' => 'Koşu inceleme beklemiyor.'];
        }
        $status = $decision === 'approve' ? 'verified' : 'rejected';
        $run['status'] = $status;
        $run['review'] = [
            'decision' => $decision,
            'note' => trim($note),
            'by' => $adminId,
            'utc' => mg_now_utc(),
        ];

        if ($decision === 'approve') {
            $minTicks = mg_limit('min_valid_ticks', 600);
            $ticks = (int)($run['result']['ticks'] ?? 0);
            $counts = $ticks >= $minTicks;
            $run['counts_for_board'] = $counts;
            if ($counts) {
                $submitted = (int)($run['submitted_ts'] ?? time());
                foreach ((array)($run['board_keys'] ?? []) as $key) {
                    if ($key !== '') {
                        mg_best_apply((string)$key, (int)$run['user_id'], $run['result'] ?? [], $runId, $submitted);
                    }
                }
                mg_stats_bump(mg_day_bounds($submitted)['key'], (int)$run['user_id'], $run['result'] ?? [], $minTicks, $submitted);
            }
        }

        mg_write_json(mg_run_archive_path($runId, (int)($run['started_ts'] ?? time())), $run);
        mg_audit($adminId, 'run.review', 'run:' . $runId, [
            'decision' => $decision,
            'user_id' => (int)$run['user_id'],
            'score' => (int)($run['result']['score'] ?? 0),
            'note' => trim($note),
        ]);
        return ['ok' => true, 'already' => false, 'status' => $status, 'run' => $run];
    });

    if (empty($outcome['ok'])) {
        return $outcome;
    }

    if (($outcome['status'] ?? '') === 'verified' && empty($outcome['run']['rewards']['settled'])) {
        $run = $outcome['run'];
        $day = mg_day_bounds((int)($run['submitted_ts'] ?? time()));
        $settled = mg_settle_run_rewards((int)$run['user_id'], $runId, $run['result'] ?? [], $day);
        mg_lock(static function () use ($runId, $settled): void {
            $archived = mg_run_archived($runId);
            if ($archived === null) {
                return;
            }
            $archived['rewards'] = $settled + ['settled' => true];
            mg_write_json(mg_run_archive_path($runId, (int)($archived['started_ts'] ?? time())), $archived);
        });
        $outcome['rewards'] = $settled;
    }

    return $outcome;
}

/** Skor düzeltmesi: gerekçeli, denetim kayıtlı; tablo yeniden hesaplanır. */
function mg_admin_override_score(string $runId, int $newScore, string $reason, int $adminId): array
{
    $newScore = max(0, min(1000000, $newScore));
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'code' => 'reason_required', 'error' => 'Gerekçe zorunludur.'];
    }
    return mg_lock(static function () use ($runId, $newScore, $reason, $adminId): array {
        $run = mg_run_archived($runId);
        if ($run === null) {
            return ['ok' => false, 'code' => 'not_found', 'error' => 'Koşu bulunamadı.'];
        }
        $old = (int)($run['result']['score'] ?? 0);
        $run['override'] = [
            'score' => $newScore,
            'previous' => $old,
            'reason' => $reason,
            'by' => $adminId,
            'utc' => mg_now_utc(),
        ];
        mg_write_json(mg_run_archive_path($runId, (int)($run['started_ts'] ?? time())), $run);

        // Etkilenen tabloları kullanıcı için yeniden hesapla.
        $submitted = (int)($run['submitted_ts'] ?? time());
        foreach ((array)($run['board_keys'] ?? []) as $type => $key) {
            if ($key === '') {
                continue;
            }
            $period = $type === 'today'
                ? mg_day_bounds($submitted)
                : ($type === 'week' ? mg_week_bounds($submitted) : ['start_ts' => 0, 'end_ts' => PHP_INT_MAX]);
            mg_recompute_user_best((string)$key, (int)$run['user_id'], $period);
        }

        mg_audit($adminId, 'run.override', 'run:' . $runId, [
            'previous' => $old,
            'score' => $newScore,
            'reason' => $reason,
        ]);
        return ['ok' => true, 'previous' => $old, 'score' => $newScore];
    });
}

/**
 * Ekonomi raporu (gelir DEĞİLDİR).
 * @return array{distributed:int,spent:int,net:int,purchases:int,purchase_count:int,active_players:int,by_day:array,season:array}
 */
function mg_economy_report(int $days = 14, ?int $now = null): array
{
    $now = $now ?? time();
    $days = max(1, min(90, $days));
    $data = dg_read_data();
    $start = mg_day_bounds($now)['start_ts'] - ($days - 1) * 86400;

    $distributed = 0;
    $spent = 0;
    $purchaseCount = 0;
    $byDay = [];
    foreach (($data['minigame_ledger'] ?? []) as $row) {
        $ts = (int)($row['created_ts'] ?? 0);
        if ($ts < $start || ($row['status'] ?? 'paid') !== 'paid') {
            continue;
        }
        $amount = (int)($row['amount'] ?? 0);
        $key = mg_day_bounds($ts)['key'];
        if (!isset($byDay[$key])) {
            $byDay[$key] = ['day' => $key, 'distributed' => 0, 'spent' => 0];
        }
        if ($amount > 0) {
            $distributed += $amount;
            $byDay[$key]['distributed'] += $amount;
        } elseif ($amount < 0) {
            $spent += -$amount;
            $byDay[$key]['spent'] += -$amount;
            if (($row['kind'] ?? '') === 'purchase') {
                $purchaseCount++;
            }
        }
    }
    ksort($byDay);

    $activeUsers = [];
    foreach (mg_archived_runs(20000) as $run) {
        $ts = (int)($run['submitted_ts'] ?? 0);
        if ($ts >= $start) {
            $activeUsers[(int)$run['user_id']] = true;
        }
    }

    $today = mg_day_bounds($now);
    $todayDistributed = mg_reward_sum($data, $today, null);
    $cap = mg_limit('daily_global_budget', 0);

    return [
        'period_days' => $days,
        'distributed' => $distributed,
        'spent' => $spent,
        'net' => $distributed - $spent,
        'purchases' => $purchaseCount,
        'purchase_count' => $purchaseCount,
        'active_players' => count($activeUsers),
        'today_rewarded' => $todayDistributed,
        'today_budget' => $cap,
        'today_cap_reached' => $cap > 0 && $todayDistributed >= $cap,
        'by_day' => array_values($byDay),
        'season' => [
            'prize_note' => 'Sezon ödülleri elle teslim edilir; sistemde para değeri tutulmaz.',
            'active' => mg_active_season($now) ? mg_active_season($now)['title'] : null,
        ],
        'note' => 'Bu rapor oyun içi D-Coin hareketlerini gösterir; mağaza geliri değildir.',
    ];
}
