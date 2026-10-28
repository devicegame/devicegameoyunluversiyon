<?php
/**
 * Koşu yaşam döngüsü: başlat → girdileri doğrula → sunucuda yeniden oyna →
 * sonuçlandır (tek sefer) → ödülleri öde.
 *
 * İstemci skoru ASLA otorite değildir; yalnızca karşılaştırılır.
 */
declare(strict_types=1);

/* ---------------- depolama yardımcıları ---------------- */

function mg_runs_active(): array
{
    $active = mg_read_json('runs/active.json', []);
    return is_array($active) ? $active : [];
}

function mg_run_archive_path(string $runId, ?int $ts = null): string
{
    $day = mg_day_bounds($ts ?? time())['key'];
    return 'runs/' . mg_safe_key($day) . '/' . mg_safe_key($runId) . '.json';
}

function mg_archived_runs(int $limit = 5000): array
{
    $out = [];
    foreach (glob(MG_DIR . '/runs/*/*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $run = json_decode($raw, true);
        if (is_array($run)) {
            $out[] = $run;
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return $out;
}

function mg_run_archived(string $runId): ?array
{
    foreach (glob(MG_DIR . '/runs/*/' . mg_safe_key($runId) . '.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $run = json_decode($raw, true);
        if (is_array($run)) {
            return $run;
        }
    }
    return null;
}

/** Kullanıcının son koşusuna işaretçi (her submit'te güncellenir). */
function mg_last_run_path(int $userId): string
{
    return 'last/u' . $userId . '.json';
}

/**
 * Kullanıcının son turu (durum arşivden CANLI okunur: inceleme sonucu ve
 * yönetici düzeltmesi anında yansır). PII içermez.
 */
function mg_user_last_run(int $userId): ?array
{
    $pointer = mg_read_json(mg_last_run_path($userId), []);
    $runId = (string)($pointer['run_id'] ?? '');
    if ($runId === '') {
        return null;
    }
    $day = (string)($pointer['day'] ?? '');
    $run = $day !== ''
        ? mg_read_json('runs/' . mg_safe_key($day) . '/' . mg_safe_key($runId) . '.json', [])
        : [];
    if (!$run) {
        $run = mg_run_archived($runId) ?? [];
    }
    if (!$run || (int)($run['user_id'] ?? 0) !== $userId) {
        return null;
    }
    return [
        'run_id' => $runId,
        'status' => (string)($run['status'] ?? ''),
        'score' => (int)($run['override']['score'] ?? $run['result']['score'] ?? 0),
        'ticks' => (int)($run['result']['ticks'] ?? 0),
        'cells' => (int)($run['result']['cells'] ?? 0),
        'submitted_utc' => (string)($run['submitted_utc'] ?? ''),
        'counts' => !empty($run['counts_for_board']),
    ];
}

/* ---------------- günlük tur sayacı ---------------- */

function mg_attempts_path(string $dayKey): string
{
    return 'attempts/' . mg_safe_key($dayKey) . '.json';
}

function mg_attempts_count(string $dayKey, int $userId): int
{
    $data = mg_read_json(mg_attempts_path($dayKey), []);
    return (int)($data['users'][(string)$userId] ?? 0);
}

/** Çağıran `mg_lock()` tutmalıdır. */
function mg_attempts_bump(string $dayKey, int $userId): void
{
    $data = mg_read_json(mg_attempts_path($dayKey), []);
    $data['day'] = $dayKey;
    $data['users'][(string)$userId] = (int)($data['users'][(string)$userId] ?? 0) + 1;
    mg_write_json(mg_attempts_path($dayKey), $data);
}

/* ---------------- başlatma ---------------- */

/**
 * Sunucu tarafı koşu başlatır. Token yalnızca bu yanıtta döner; depoda
 * yalnızca SHA-256 özeti tutulur.
 */
function mg_run_start(int $userId, ?int $now = null): array
{
    $now = $now ?? time();
    if (!mg_flag('game')) {
        return ['ok' => false, 'code' => 'game_disabled', 'error' => 'Mini oyun şu an bakımda.'];
    }
    $day = mg_day_bounds($now);
    $limit = mg_limit('max_ranked_runs_per_day', 20);
    $attempts = mg_attempts_count($day['key'], $userId);
    if ($limit > 0 && $attempts >= $limit) {
        return [
            'ok' => false,
            'code' => 'daily_attempts',
            'error' => 'Bugünkü sıralı tur hakkın doldu. Yarın yeniden deneyebilirsin.',
            'attempts' => $attempts,
            'limit' => $limit,
        ];
    }

    $token = bin2hex(random_bytes(32));
    $runId = 'run_' . bin2hex(random_bytes(12));
    $ttl = max(60, mg_limit('run_ttl_seconds', 300));
    $season = mg_active_season($now);
    $board = mg_current_board($now, $season);

    $record = [
        'id' => $runId,
        'user_id' => $userId,
        'mode' => 'ranked',
        'version' => MG_ENGINE_VERSION,
        'config_hash' => mg_config_hash(),
        'seed' => (int)$board['seed'],
        'seed_id' => (string)$board['seed_id'],
        'season_id' => $board['season_id'],
        'board_keys' => $board['board_keys'],
        'token_hash' => hash('sha256', $token),
        'started_ts' => $now,
        'started_utc' => mg_iso($now),
        'expires_ts' => $now + $ttl,
        'status' => 'active',
        'ip_hash' => mg_ip_hash(),
    ];

    mg_lock(static function () use ($runId, $record, $day, $userId, $now): void {
        $active = mg_runs_active();
        foreach ($active as $id => $run) {
            if ((int)($run['expires_ts'] ?? 0) < $now) {
                unset($active[$id]);
            }
        }
        $active[$runId] = $record;
        mg_write_json('runs/active.json', $active);
        mg_attempts_bump($day['key'], $userId);
    });

    return [
        'ok' => true,
        'run_id' => $runId,
        'token' => $token,
        'seed' => (int)$board['seed'],
        'seed_id' => (string)$board['seed_id'],
        'version' => MG_ENGINE_VERSION,
        'config' => mg_engine_config(),
        'max_ticks' => (int)mg_engine_config()['maxTicks'],
        'started_ts' => $now,
        'expires_ts' => $now + $ttl,
        'season_id' => $board['season_id'],
        'attempts' => $attempts + 1,
        'attempt_limit' => $limit,
    ];
}

/* ---------------- girdi doğrulama ---------------- */

/**
 * Girdi listesi: sınırlı boyut, tam sayı tick, sıkı artan sıra, geçerli aksiyon.
 * @return array{ok:bool,code?:string,error?:string,inputs?:array}
 */
function mg_validate_inputs($raw, ?int $maxInputs = null): array
{
    $maxInputs = $maxInputs ?? max(1, mg_limit('max_inputs', 3000));
    if (!is_array($raw)) {
        return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi listesi geçersiz.'];
    }
    if (count($raw) > $maxInputs) {
        return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Çok fazla girdi.'];
    }
    $maxTicks = (int)mg_engine_config()['maxTicks'];
    $clean = [];
    $lastTick = -1;
    foreach ($raw as $entry) {
        if (!is_array($entry) || count($entry) !== 2) {
            return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi kaydı geçersiz.'];
        }
        $values = array_values($entry);
        if (!is_int($values[0]) && !(is_string($values[0]) && ctype_digit($values[0]))) {
            return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi tick değeri geçersiz.'];
        }
        if (!is_int($values[1]) && !(is_string($values[1]) && ctype_digit($values[1]))) {
            return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi aksiyonu geçersiz.'];
        }
        $tick = (int)$values[0];
        $action = (int)$values[1];
        if ($tick < 0 || $tick >= $maxTicks) {
            return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi tick aralığı geçersiz.'];
        }
        if ($tick <= $lastTick) {
            return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi sırası geçersiz.'];
        }
        if (!in_array($action, mg_valid_actions(), true)) {
            return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Geçersiz aksiyon.'];
        }
        $lastTick = $tick;
        $clean[] = [$tick, $action];
    }
    return ['ok' => true, 'inputs' => $clean];
}

/* ---------------- risk sezgileri ---------------- */

/**
 * Kesin yargı değil: yüksek riskli koşular ödül almadan incelemeye alınır.
 * Eşikler istemciye AÇIKLANMAZ.
 */
function mg_run_risk(array $inputs, array $result, int $ticks, int $elapsed): array
{
    $reasons = [];
    $risk = 0;

    $jumpTicks = [];
    foreach ($inputs as $input) {
        if ((int)$input[1] === MG_ACTION_JUMP) {
            $jumpTicks[] = (int)$input[0];
        }
    }

    $cellsSeen = (int)($result['cellsSeen'] ?? 0);
    $cells = (int)($result['cells'] ?? 0);
    if ($cellsSeen >= 18 && $cells * 100 >= $cellsSeen * 97) {
        $risk++;
        $reasons[] = 'perfect_cells';
    }

    // Ateş/eğilme girdileri meşrudur; bu eşik yalnızca anormal yoğunluğu yakalar.
    if ($ticks > 0 && count($inputs) > intdiv($ticks, 5) + 80) {
        $risk++;
        $reasons[] = 'high_input_rate';
    }

    if (count($jumpTicks) >= 18) {
        $gaps = [];
        for ($i = 1; $i < count($jumpTicks); $i++) {
            $gaps[] = $jumpTicks[$i] - $jumpTicks[$i - 1];
        }
        $min = min($gaps);
        $max = max($gaps);
        if ($max - $min <= 2) {
            $risk++;
            $reasons[] = 'regular_rhythm';
        }
    }

    if ($elapsed > 0 && $ticks >= 60 && count($inputs) === 0) {
        $risk++;
        $reasons[] = 'no_inputs_long_run';
    }

    $shots = 0;
    foreach ($inputs as $input) {
        if ((int)$input[1] === MG_ACTION_FIRE) {
            $shots++;
        }
    }
    $kills = (int)($result['kills'] ?? 0);
    // 20+ düşman alt edip HİÇ hasar almamak insan için çok zordur (bot seviyesi).
    if ($shots >= 40 && $kills >= 20 && (int)($result['damageTaken'] ?? 0) === 0) {
        $risk++;
        $reasons[] = 'perfect_accuracy_no_damage';
    }

    return ['risk' => $risk, 'reasons' => $reasons, 'pending' => $risk >= 2];
}

/* ---------------- sonuçlandırma ---------------- */

/**
 * Koşuyu sonuçlandırır. Aynı koşu ikinci kez işlenmez; tekrar gönderimde
 * saklanan sonuç döner (ağ hatası sonrası güvenli yeniden deneme).
 *
 * @param array<int,array{0:int,1:int}> $inputs
 * @return array{ok:bool,code?:string,error?:string,status?:string,result?:array,rewards?:array,duplicate?:bool}
 */
function mg_run_submit(int $userId, string $runId, string $token, array $inputs, ?int $clientScore = null, ?int $now = null): array
{
    $now = $now ?? time();
    $validated = mg_validate_inputs($inputs);
    if (empty($validated['ok'])) {
        return ['ok' => false, 'code' => (string)$validated['code'], 'error' => (string)$validated['error']];
    }
    $inputs = $validated['inputs'];
    $tokenHash = hash('sha256', $token);

    $outcome = mg_lock(static function () use ($userId, $runId, $tokenHash, $inputs, $clientScore, $now): array {
        $active = mg_runs_active();
        $run = $active[$runId] ?? null;

        if ($run === null) {
            $archived = mg_run_archived($runId);
            if ($archived === null) {
                return ['ok' => false, 'code' => 'bad_run', 'error' => 'Koşu bulunamadı.'];
            }
            if ((int)($archived['user_id'] ?? 0) !== $userId) {
                return ['ok' => false, 'code' => 'forbidden', 'error' => 'Bu koşu sana ait değil.'];
            }
            $sentHash = hash('sha256', mg_encode_inputs($inputs));
            if ($sentHash !== (string)($archived['input_hash'] ?? '')) {
                return ['ok' => false, 'code' => 'already_finalized', 'error' => 'Bu koşu zaten sonuçlandı.'];
            }
            if (($archived['status'] ?? '') === 'rejected') {
                return ['ok' => false, 'code' => 'rejected', 'error' => 'Bu koşu reddedildi.', 'result' => $archived['result'] ?? null];
            }
            return [
                'ok' => true,
                'duplicate' => true,
                'status' => (string)($archived['status'] ?? 'verified'),
                'pending' => ($archived['status'] ?? '') === 'pending',
                'result' => $archived['result'] ?? null,
                'rewards' => $archived['rewards'] ?? null,
            ];
        }

        if ((int)($run['user_id'] ?? 0) !== $userId) {
            return ['ok' => false, 'code' => 'forbidden', 'error' => 'Bu koşu sana ait değil.'];
        }
        if (($run['status'] ?? '') !== 'active') {
            return ['ok' => false, 'code' => 'not_active', 'error' => 'Koşu artık aktif değil.'];
        }
        if (!hash_equals((string)$run['token_hash'], $tokenHash)) {
            return ['ok' => false, 'code' => 'bad_token', 'error' => 'Koşu anahtarı geçersiz.'];
        }
        if ($now > (int)$run['expires_ts']) {
            unset($active[$runId]);
            $run['status'] = 'expired';
            $run['closed_ts'] = $now;
            mg_write_json('runs/active.json', $active);
            mg_write_json(mg_run_archive_path($runId, (int)$run['started_ts']), $run);
            return ['ok' => false, 'code' => 'expired', 'error' => 'Koşunun süresi doldu. Yeni tur başlat.'];
        }
        if ((string)$run['version'] !== MG_ENGINE_VERSION || (string)$run['config_hash'] !== mg_config_hash()) {
            return ['ok' => false, 'code' => 'version_mismatch', 'error' => 'Oyun güncellendi. Yeni tur başlat.'];
        }

        $result = mg_simulate((int)$run['seed'], $inputs);

        // Girdiler ölümden sonrasını içeremez.
        if ($inputs) {
            $lastTick = (int)$inputs[count($inputs) - 1][0];
            if ($lastTick >= (int)$result['ticks'] && (int)$result['ticks'] > 0) {
                return ['ok' => false, 'code' => 'invalid_input', 'error' => 'Girdi zamanlaması geçersiz.'];
            }
        }

        $elapsed = max(0, $now - (int)$run['started_ts']);
        $tolerance = mg_limit('timing_tolerance_ticks', 6) + intdiv($elapsed, 10);
        $maxPossibleTicks = $elapsed * (int)mg_engine_config()['tickHz'] + $tolerance;
        if ((int)$result['ticks'] > $maxPossibleTicks) {
            unset($active[$runId]);
            $run['status'] = 'rejected';
            $run['reject_reason'] = 'impossible_timing';
            $run['closed_ts'] = $now;
            $run['result'] = $result;
            $run['input_hash'] = hash('sha256', mg_encode_inputs($inputs));
            mg_write_json('runs/active.json', $active);
            mg_write_json(mg_run_archive_path($runId, (int)$run['started_ts']), $run);
            return ['ok' => false, 'code' => 'impossible_timing', 'error' => 'Tur süresi sunucu kaydıyla uyuşmuyor.'];
        }

        if ($clientScore !== null && (int)$clientScore !== (int)$result['score']) {
            unset($active[$runId]);
            $run['status'] = 'rejected';
            $run['reject_reason'] = 'score_mismatch';
            $run['client_score'] = (int)$clientScore;
            $run['server_score'] = (int)$result['score'];
            $run['closed_ts'] = $now;
            $run['result'] = $result;
            $run['input_hash'] = hash('sha256', mg_encode_inputs($inputs));
            mg_write_json('runs/active.json', $active);
            mg_write_json(mg_run_archive_path($runId, (int)$run['started_ts']), $run);
            return ['ok' => false, 'code' => 'score_mismatch', 'error' => 'Skor doğrulanamadı.'];
        }

        $minTicks = mg_limit('min_valid_ticks', 600);
        $risk = mg_run_risk($inputs, $result, (int)$result['ticks'], $elapsed);
        $status = $risk['pending'] ? 'pending' : 'verified';
        $counts = (int)$result['ticks'] >= $minTicks;

        $run['status'] = $status;
        $run['result'] = $result;
        $run['client_score'] = $clientScore;
        $run['inputs'] = $inputs;
        $run['inputs_count'] = count($inputs);
        $run['input_hash'] = hash('sha256', mg_encode_inputs($inputs));
        $run['risk'] = $risk['risk'];
        $run['risk_reasons'] = $risk['reasons'];
        $run['submitted_ts'] = $now;
        $run['submitted_utc'] = mg_iso($now);
        $run['elapsed_seconds'] = $elapsed;
        $run['counts_for_board'] = $status === 'verified' && $counts;
        $run['rewards'] = ['paid' => 0, 'claims' => [], 'settled' => false];

        if ($run['counts_for_board']) {
            $dayKey = mg_day_bounds($now)['key'];
            foreach ((array)$run['board_keys'] as $type => $key) {
                if ($key !== '') {
                    mg_best_apply((string)$key, $userId, $result, $runId, $now);
                }
            }
            mg_stats_bump($dayKey, $userId, $result, $minTicks, $now);
            $run['day_key'] = $dayKey;
        }

        unset($active[$runId]);
        mg_write_json('runs/active.json', $active);
        mg_write_json(mg_run_archive_path($runId, (int)$run['started_ts']), $run);
        mg_write_json(mg_last_run_path($userId), [
            'run_id' => $runId,
            'day' => mg_day_bounds((int)$run['started_ts'])['key'],
            'utc' => mg_now_utc(),
            'ts' => $now,
        ]);

        return [
            'ok' => true,
            'status' => $status,
            'pending' => $status === 'pending',
            'result' => $result,
            'counts' => $run['counts_for_board'],
            'attempts' => mg_attempts_count(mg_day_bounds($now)['key'], $userId),
            'attempt_limit' => mg_limit('max_ranked_runs_per_day', 20),
        ];
    });

    if (empty($outcome['ok']) || ($outcome['status'] ?? '') !== 'verified' || empty($outcome['counts'])) {
        return $outcome;
    }

    // Ödüller: ayrı mutasyon, idempotent. Başarısız olursa koşu doğrulanmış
    // kalır; `claim` uç noktası veya bir sonraki tur telafi eder.
    $day = mg_day_bounds($now);
    $settled = mg_settle_run_rewards($userId, $runId, $outcome['result'], $day);
    $outcome['rewards'] = $settled;

    mg_lock(static function () use ($runId, $settled): void {
        $archived = mg_run_archived($runId);
        if ($archived === null) {
            return;
        }
        $archived['rewards'] = $settled + ['settled' => true];
        mg_write_json(mg_run_archive_path($runId, (int)($archived['started_ts'] ?? time())), $archived);
    });

    return $outcome;
}

/**
 * Koşu durumunu sorgular (ağ hatası sonrası idempotent kontrol).
 */
function mg_run_status(int $userId, string $runId): array
{
    $active = mg_runs_active();
    if (isset($active[$runId])) {
        if ((int)$active[$runId]['user_id'] !== $userId) {
            return ['ok' => false, 'code' => 'forbidden', 'error' => 'Bu koşu sana ait değil.'];
        }
        return [
            'ok' => true,
            'status' => 'active',
            'expires_ts' => (int)$active[$runId]['expires_ts'],
            'result' => null,
        ];
    }
    $archived = mg_run_archived($runId);
    if (!$archived || (int)$archived['user_id'] !== $userId) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Koşu bulunamadı.'];
    }
    return [
        'ok' => true,
        'status' => (string)($archived['status'] ?? ''),
        'result' => $archived['result'] ?? null,
        'rewards' => $archived['rewards'] ?? null,
        'risk' => (int)($archived['risk'] ?? 0),
    ];
}

/**
 * Ödenmemiş doğrulanmış koşu için ödülleri yeniden dener (idempotent).
 */
function mg_run_claim(int $userId, string $runId): array
{
    $run = mg_run_archived($runId);
    if (!$run || (int)($run['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Koşu bulunamadı.'];
    }
    if (($run['status'] ?? '') !== 'verified' || empty($run['counts_for_board'])) {
        return ['ok' => false, 'code' => 'not_eligible', 'error' => 'Bu koşu için ödül yok.'];
    }
    if (!empty($run['rewards']['settled'])) {
        return ['ok' => true, 'settled' => true, 'rewards' => $run['rewards']];
    }
    $day = mg_day_bounds((int)($run['submitted_ts'] ?? time()));
    $settled = mg_settle_run_rewards($userId, $runId, $run['result'] ?? [], $day);
    mg_lock(static function () use ($runId, $settled): void {
        $archived = mg_run_archived($runId);
        if ($archived === null) {
            return;
        }
        $archived['rewards'] = $settled + ['settled' => true];
        mg_write_json(mg_run_archive_path($runId, (int)($archived['started_ts'] ?? time())), $archived);
    });
    return ['ok' => true, 'settled' => true, 'rewards' => $settled];
}
