<?php
/**
 * Mini Oyun — D-Coin ekonomisi.
 *
 * TÜM para hareketleri `dg_mutate()` içinde, tek atomik mutasyonla yapılır:
 * bakiye + defter (ledger) + sahiplik/görev kaydı aynı yazmada güncellenir.
 * Her hareketin benzersiz bir idempotency anahtarı vardır; tekrar denemeler
 * ve eşzamanlı istekler ikinci kez ödeme/yükleme yapmaz.
 *
 * Kilit sırası: mini oyun kilidi (varsa) → dg_mutate. Tersi yapılmaz.
 */
declare(strict_types=1);

/* ---------------- defter yardımcıları ---------------- */

function mg_ledger_find(array $data, string $idempotencyKey): ?array
{
    foreach ($data['minigame_ledger'] ?? [] as $row) {
        if ((string)($row['idempotency_key'] ?? '') === $idempotencyKey) {
            return $row;
        }
    }
    return null;
}

function mg_ledger_push(array &$data, array $row): array
{
    $row['id'] = dg_next_id($data, 'minigame_ledger');
    $row['created_at'] = dg_now();
    $row['created_utc'] = mg_now_utc();
    $data['minigame_ledger'][] = $row;
    return $row;
}

/** Dönemde ödenen toplam D-Coin (uid null → tüm kullanıcılar). */
function mg_reward_sum(array $data, array $period, ?int $userId): int
{
    $sum = 0;
    foreach ($data['minigame_ledger'] ?? [] as $row) {
        if ((int)($row['amount'] ?? 0) <= 0) {
            continue;
        }
        if (($row['status'] ?? 'paid') !== 'paid') {
            continue;
        }
        if ($userId !== null && (int)($row['user_id'] ?? 0) !== $userId) {
            continue;
        }
        $ts = (int)($row['created_ts'] ?? 0);
        if ($ts >= (int)$period['start_ts'] && $ts < (int)$period['end_ts']) {
            $sum += (int)$row['amount'];
        }
    }
    return $sum;
}

/** Görev talebi var mı? (dönem + görev + kullanıcı) */
function mg_claim_find(array $data, int $userId, string $periodKey, string $taskId): ?array
{
    foreach ($data['minigame_task_claims'] ?? [] as $row) {
        if ((int)($row['user_id'] ?? 0) === $userId
            && (string)($row['period'] ?? '') === $periodKey
            && (string)($row['task_id'] ?? '') === $taskId) {
            return $row;
        }
    }
    return null;
}

/* ---------------- görünüm ---------------- */

/**
 * Kullanıcının cüzdan + görev görünümü. PII içermez.
 * @param array{start_ts:int,end_ts:int,key:string} $day
 */
function mg_wallet_state(int $userId, array $day): array
{
    $data = dg_read_data();
    $balance = 0;
    foreach ($data['users'] ?? [] as $user) {
        if ((int)$user['id'] === $userId) {
            $balance = (int)($user['dcoin_balance'] ?? 0);
        }
    }
    $settings = mg_settings();
    $stats = mg_stats_user($day['key'], $userId);
    $userTotal = mg_reward_sum($data, $day, $userId);
    $globalTotal = mg_reward_sum($data, $day, null);
    $cap = max(0, (int)$settings['limits']['daily_user_reward_cap']);
    $budget = max(0, (int)$settings['limits']['daily_global_budget']);
    $rewardsOn = (int)$settings['flags']['rewards'] === 1;

    $tasks = [];
    foreach (mg_tasks(true) as $task) {
        $progress = mg_task_progress_value($task['metric'], $stats);
        $claim = mg_claim_find($data, $userId, $day['key'], (string)$task['id']);
        $status = $claim ? (string)($claim['status'] ?? 'paid') : 'available';
        $done = $progress >= $task['target'];
        if (!$claim && $done) {
            if (!$rewardsOn) {
                $status = 'rewards_off';
            } elseif ($cap > 0 && $userTotal >= $cap) {
                $status = 'blocked_limit';
            } elseif ($budget > 0 && $globalTotal >= $budget) {
                $status = 'blocked_budget';
            }
        }
        $tasks[] = [
            'id' => (string)$task['id'],
            'title' => (string)$task['title'],
            'description' => (string)$task['description'],
            'metric' => (string)$task['metric'],
            'target' => (int)$task['target'],
            'reward' => (int)$task['reward'],
            'progress' => min($progress, (int)$task['target']),
            'raw_progress' => $progress,
            'done' => $done,
            'status' => $status,
        ];
    }

    return [
        'balance' => $balance,
        'daily_earned' => $userTotal,
        'daily_cap' => $cap,
        'daily_remaining' => $cap > 0 ? max(0, $cap - $userTotal) : null,
        'budget_total' => $budget,
        'budget_used' => $globalTotal,
        'budget_exhausted' => $budget > 0 && $globalTotal >= $budget,
        'cap_reached' => $cap > 0 && $userTotal >= $cap,
        'tasks' => $tasks,
        'stats' => $stats,
        'rewards_enabled' => $rewardsOn,
        'economy_approved' => (int)($settings['economy']['approved'] ?? 0) === 1,
        'economy_note' => (string)($settings['economy']['note'] ?? ''),
    ];
}

/* ---------------- görev ödemeleri ---------------- */

/**
 * Doğrulanmış koşu sonrası günlük görev ödemeleri (idempotent).
 * Aynı gün aynı görev ikinci kez ödenmez; tavan/bütçe aşılmaz.
 *
 * @param array{start_ts:int,end_ts:int,key:string} $day
 * @return array{paid:int,balance:int,claims:array,cap_reached:bool,budget_exhausted:bool}
 */
function mg_settle_run_rewards(int $userId, string $runId, array $result, array $day): array
{
    $stats = mg_stats_user($day['key'], $userId);
    $tasks = mg_tasks(true);
    $settings = mg_settings();
    $rewardsOn = (int)$settings['flags']['rewards'] === 1;

    return dg_mutate(static function (array &$data) use ($userId, $runId, $day, $stats, $tasks, $rewardsOn): array {
        $index = dg_index_of_id($data['users'], $userId);
        if ($index === null) {
            return ['paid' => 0, 'balance' => 0, 'claims' => [], 'cap_reached' => false, 'budget_exhausted' => false];
        }
        $balance = (int)($data['users'][$index]['dcoin_balance'] ?? 0);
        $cap = max(0, (int)mg_limit('daily_user_reward_cap'));
        $budget = max(0, (int)mg_limit('daily_global_budget'));
        $userEarned = mg_reward_sum($data, $day, $userId);
        $globalEarned = mg_reward_sum($data, $day, null);
        $paid = 0;
        $claims = [];

        foreach ($tasks as $task) {
            $progress = mg_task_progress_value((string)$task['metric'], $stats);
            if ($progress < (int)$task['target']) {
                continue;
            }
            $idempotency = 'mg:task:' . $day['key'] . ':' . $task['id'] . ':' . $userId;
            $existing = mg_ledger_find($data, $idempotency);
            if ($existing) {
                $claims[] = [
                    'task_id' => (string)$task['id'],
                    'status' => (string)($existing['claim_status'] ?? 'paid'),
                    'amount' => (int)($existing['amount'] ?? 0),
                ];
                continue;
            }
            if (!$rewardsOn) {
                $claims[] = ['task_id' => (string)$task['id'], 'status' => 'rewards_off', 'amount' => 0];
                continue;
            }
            $amount = (int)$task['reward'];
            // Tavan/bütçe ASLA aşılmaz: ödeme tavanı geçecekse hiç ödenmez.
            if ($cap > 0 && $userEarned + $amount > $cap) {
                $claims[] = ['task_id' => (string)$task['id'], 'status' => 'blocked_limit', 'amount' => 0];
                continue;
            }
            if ($budget > 0 && $globalEarned + $amount > $budget) {
                $claims[] = ['task_id' => (string)$task['id'], 'status' => 'blocked_budget', 'amount' => 0];
                continue;
            }
            if ($amount <= 0) {
                $claims[] = ['task_id' => (string)$task['id'], 'status' => 'zero_reward', 'amount' => 0];
                continue;
            }

            $balance += $amount;
            $userEarned += $amount;
            $globalEarned += $amount;
            $paid += $amount;
            $data['users'][$index]['dcoin_balance'] = $balance;
            $data['users'][$index]['updated_at'] = dg_now();

            mg_ledger_push($data, [
                'user_id' => $userId,
                'amount' => $amount,
                'kind' => 'task_reward',
                'source' => 'minigame',
                'task_id' => (string)$task['id'],
                'run_id' => $runId,
                'status' => 'paid',
                'claim_status' => 'paid',
                'balance_after' => $balance,
                'idempotency_key' => $idempotency,
                'created_ts' => time(),
            ]);
            $data['minigame_task_claims'][] = [
                'id' => dg_next_id($data, 'minigame_task_claims'),
                'user_id' => $userId,
                'period' => $day['key'],
                'task_id' => (string)$task['id'],
                'run_id' => $runId,
                'amount' => $amount,
                'status' => 'paid',
                'idempotency_key' => $idempotency,
                'created_at' => dg_now(),
                'created_utc' => mg_now_utc(),
            ];
            $claims[] = ['task_id' => (string)$task['id'], 'status' => 'paid', 'amount' => $amount];
        }

        if ($index !== null && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
            $_SESSION['dcoin_balance'] = $balance;
        }

        return [
            'paid' => $paid,
            'balance' => $balance,
            'claims' => $claims,
            'cap_reached' => $cap > 0 && $userEarned >= $cap,
            'budget_exhausted' => $budget > 0 && $globalEarned >= $budget,
        ];
    });
}

/* ---------------- kozmetik satın alma ---------------- */

/**
 * Doğrudan satın alma (loot box yok). Sunucu fiyatı ve sahipliği doğrular;
 * bakiye + sahiplik + defter tek mutasyonda yazılır. Çift ücretlendirme yok.
 */
function mg_purchase_cosmetic(int $userId, string $itemId): array
{
    if (!mg_flag('shop')) {
        return ['ok' => false, 'code' => 'shop_disabled', 'error' => 'Kozmetik mağazası şu an kapalı.'];
    }
    $item = mg_catalog_item($itemId);
    if (!$item || $item['published'] !== 1) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Kozmetik bulunamadı.'];
    }
    if ($item['free'] === 1) {
        return ['ok' => true, 'already_owned' => true, 'charged' => 0, 'balance' => mg_wallet_state($userId, mg_day_bounds(time()))['balance'], 'item' => $item];
    }
    if ($item['purchasable'] !== 1) {
        return ['ok' => false, 'code' => 'not_purchasable', 'error' => 'Bu kozmetik satın alınamaz.'];
    }
    $price = (int)$item['price'];
    if ($price <= 0) {
        return ['ok' => false, 'code' => 'not_purchasable', 'error' => 'Bu kozmetiğin fiyatı tanımlı değil.'];
    }

    return dg_mutate(static function (array &$data) use ($userId, $item, $price): array {
        foreach ($data['minigame_entitlements'] ?? [] as $row) {
            if ((int)($row['user_id'] ?? 0) === $userId && (string)($row['item_id'] ?? '') === $item['id']) {
                $balance = 0;
                foreach ($data['users'] as $user) {
                    if ((int)$user['id'] === $userId) {
                        $balance = (int)$user['dcoin_balance'];
                    }
                }
                return ['ok' => true, 'already_owned' => true, 'charged' => 0, 'balance' => $balance, 'item' => $item];
            }
        }
        $index = dg_index_of_id($data['users'], $userId);
        if ($index === null) {
            return ['ok' => false, 'code' => 'no_user', 'error' => 'Kullanıcı bulunamadı.'];
        }
        $balance = (int)($data['users'][$index]['dcoin_balance'] ?? 0);
        if ($balance < $price) {
            return ['ok' => false, 'code' => 'insufficient_funds', 'error' => 'Yetersiz D-Coin.', 'balance' => $balance, 'price' => $price];
        }
        $balance -= $price;
        $data['users'][$index]['dcoin_balance'] = $balance;
        $data['users'][$index]['updated_at'] = dg_now();

        $data['minigame_entitlements'][] = [
            'id' => dg_next_id($data, 'minigame_entitlements'),
            'user_id' => $userId,
            'item_id' => $item['id'],
            'source' => 'purchase',
            'price' => $price,
            'acquired_at' => dg_now(),
            'acquired_utc' => mg_now_utc(),
        ];
        mg_ledger_push($data, [
            'user_id' => $userId,
            'amount' => -$price,
            'kind' => 'purchase',
            'source' => 'minigame',
            'item_id' => $item['id'],
            'status' => 'paid',
            'claim_status' => 'paid',
            'balance_after' => $balance,
            'idempotency_key' => 'mg:buy:' . $userId . ':' . $item['id'],
            'created_ts' => time(),
        ]);
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
            $_SESSION['dcoin_balance'] = $balance;
        }
        return ['ok' => true, 'already_owned' => false, 'charged' => $price, 'balance' => $balance, 'item' => $item];
    });
}

/**
 * Yönetici bakiye düzeltmesi: eski kayıt SİLİNMEZ, dengeleyici ledger girişi
 * eklenir. Gerekçe zorunludur ve denetim günlüğüne yazılır.
 */
function mg_admin_balance_adjust(int $userId, int $amount, string $reason, int $adminId): array
{
    $reason = trim($reason);
    if ($amount === 0 || $reason === '') {
        return ['ok' => false, 'code' => 'bad_request', 'error' => 'Tutar ve gerekçe zorunludur.'];
    }
    $result = dg_mutate(static function (array &$data) use ($userId, $amount, $reason, $adminId): array {
        $index = dg_index_of_id($data['users'], $userId);
        if ($index === null) {
            return ['ok' => false, 'code' => 'no_user', 'error' => 'Kullanıcı bulunamadı.'];
        }
        $balance = (int)($data['users'][$index]['dcoin_balance'] ?? 0);
        if ($balance + $amount < 0) {
            return ['ok' => false, 'code' => 'insufficient', 'error' => 'Bakiye negatife düşemez.', 'balance' => $balance];
        }
        $balance += $amount;
        $data['users'][$index]['dcoin_balance'] = $balance;
        $data['users'][$index]['updated_at'] = dg_now();
        mg_ledger_push($data, [
            'user_id' => $userId,
            'amount' => $amount,
            'kind' => 'admin_adjust',
            'source' => 'admin',
            'reason' => $reason,
            'admin_id' => $adminId,
            'status' => 'paid',
            'claim_status' => 'paid',
            'balance_after' => $balance,
            'idempotency_key' => 'mg:admin:' . $adminId . ':' . bin2hex(random_bytes(8)),
            'created_ts' => time(),
        ]);
        return ['ok' => true, 'balance' => $balance];
    });
    mg_audit($adminId, 'wallet.adjust', 'user:' . $userId, ['amount' => $amount, 'reason' => $reason]);
    return $result;
}
