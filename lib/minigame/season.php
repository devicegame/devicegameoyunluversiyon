<?php
/**
 * Ödüllü haftalık yarışma (sezon) yönetimi.
 *
 * Yalnızca yönetici sezon açar. Durum akışı:
 *   draft → active → review → finalized → delivered
 *   (herhangi bir aşamada → cancelled)
 *
 * Ödül dağıtımı MANUELDİR: sistem oyun anahtarı/hediye kodu saklamaz ve
 * kazananı kendiliğinden açıklamaz; yönetici onayı zorunludur.
 */
declare(strict_types=1);

const MG_SEASON_STATUSES = [
    'draft' => 'Taslak',
    'active' => 'Aktif',
    'review' => 'Sonuçlar inceleniyor',
    'finalized' => 'Kesinleşti',
    'delivered' => 'Teslim edildi',
    'cancelled' => 'İptal edildi',
];

const MG_SEASON_TRANSITIONS = [
    'draft' => ['active', 'cancelled'],
    'active' => ['review', 'cancelled'],
    'review' => ['finalized', 'cancelled'],
    'finalized' => ['delivered'],
    'delivered' => [],
    'cancelled' => [],
];

function mg_seasons_store(): array
{
    $store = mg_read_json('seasons.json', []);
    if (!isset($store['seasons']) || !is_array($store['seasons'])) {
        $store = ['next_id' => 1, 'seasons' => []];
    }
    return $store;
}

function mg_seasons(): array
{
    $store = mg_seasons_store();
    return array_values($store['seasons']);
}

function mg_season(int $id): ?array
{
    foreach (mg_seasons() as $season) {
        if ((int)$season['id'] === $id) {
            return $season;
        }
    }
    return null;
}

/** Şu anda oynanan (aktif ve süresi içinde) sezon. */
function mg_active_season(int $now): ?array
{
    $best = null;
    foreach (mg_seasons() as $season) {
        if (($season['status'] ?? '') !== 'active') {
            continue;
        }
        if ($now < (int)$season['start_ts'] || $now >= (int)$season['end_ts']) {
            continue;
        }
        if ($best === null || (int)$season['id'] > (int)$best['id']) {
            $best = $season;
        }
    }
    return $best;
}

function mg_season_public(?array $season): ?array
{
    if (!$season) {
        return null;
    }
    $now = time();
    $status = (string)$season['status'];
    $label = MG_SEASON_STATUSES[$status] ?? $status;
    if ($status === 'active' && $now >= (int)$season['end_ts']) {
        $label = 'Sonuçlar inceleniyor';
    }
    $cover = is_array($season['cover'] ?? null) ? $season['cover'] : [];
    return [
        'id' => (int)$season['id'],
        'title' => (string)$season['title'],
        'prize_name' => (string)$season['prize_name'],
        'prize_note' => (string)($season['prize_note'] ?? ''),
        'cover' => [
            'c1' => mg_safe_color($cover['c1'] ?? '#2b2141'),
            'c2' => mg_safe_color($cover['c2'] ?? '#c5f46f'),
        ],
        'platform' => (string)($season['platform'] ?? ''),
        'region' => (string)($season['region'] ?? ''),
        'eligibility' => (string)($season['eligibility'] ?? ''),
        'participation' => (string)($season['participation'] ?? ''),
        'results_note' => (string)($season['results_note'] ?? ''),
        'delivery_note' => (string)($season['delivery_note'] ?? ''),
        'status' => $status,
        'status_label' => $label,
        'start_ts' => (int)$season['start_ts'],
        'end_ts' => (int)$season['end_ts'],
        'seed_pinned' => (int)($season['seed_pinned'] ?? 0) === 1,
        'final' => isset($season['final']['winner_user_id']) ? [
            'winner_username' => (string)($season['final']['winner_username'] ?? ''),
            'winner_score' => (int)($season['final']['winner_score'] ?? 0),
            'finalized_at' => (string)($season['final']['finalized_utc'] ?? ''),
        ] : null,
        'delivered' => $status === 'delivered',
    ];
}

function mg_season_save(array $season, ?int $actorId, string $event): void
{
    $store = mg_seasons_store();
    $found = false;
    foreach ($store['seasons'] as $i => $existing) {
        if ((int)$existing['id'] === (int)$season['id']) {
            $store['seasons'][$i] = $season;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $store['seasons'][] = $season;
        $store['next_id'] = max((int)$store['next_id'], (int)$season['id'] + 1);
    }
    mg_write_json('seasons.json', $store);
    mg_audit($actorId, 'season.' . $event, 'season:' . (int)$season['id'], [
        'status' => $season['status'] ?? '',
        'title' => $season['title'] ?? '',
    ]);
}

/** Yeni sezon (taslak). */
function mg_season_create(array $input, int $adminId): array
{
    $store = mg_seasons_store();
    $id = max(1, (int)($store['next_id'] ?? 1));
    $now = time();
    $seed = isset($input['seed']) && is_numeric($input['seed']) ? mg_normalize_seed((int)$input['seed']) : mg_normalize_seed(random_int(1, 0x7FFFFFFF));
    $season = [
        'id' => $id,
        'title' => trim((string)($input['title'] ?? 'Haftalık Yarışma')),
        'prize_name' => trim((string)($input['prize_name'] ?? '')),
        'prize_note' => trim((string)($input['prize_note'] ?? '')),
        'cover' => ['c1' => mg_safe_color($input['c1'] ?? '#2b2141'), 'c2' => mg_safe_color($input['c2'] ?? '#c5f46f')],
        'platform' => trim((string)($input['platform'] ?? '')),
        'region' => trim((string)($input['region'] ?? '')),
        'eligibility' => trim((string)($input['eligibility'] ?? '')),
        'participation' => trim((string)($input['participation'] ?? '')),
        'results_note' => trim((string)($input['results_note'] ?? '')),
        'delivery_note' => trim((string)($input['delivery_note'] ?? '')),
        'status' => 'draft',
        'start_ts' => (int)($input['start_ts'] ?? $now),
        'end_ts' => (int)($input['end_ts'] ?? $now + 7 * 86400),
        'seed' => $seed,
        'seed_pinned' => 1,
        'created_utc' => mg_now_utc(),
        'created_by' => $adminId,
        'updated_utc' => mg_now_utc(),
    ];
    mg_season_save($season, $adminId, 'create');
    return ['ok' => true, 'season' => $season];
}

function mg_season_update(int $id, array $input, int $adminId): array
{
    $season = mg_season($id);
    if (!$season) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Sezon bulunamadı.'];
    }
    if (in_array($season['status'], ['finalized', 'delivered', 'cancelled'], true)) {
        return ['ok' => false, 'code' => 'locked', 'error' => 'Kesinleşmiş sezon değiştirilemez.'];
    }
    $fields = ['title', 'prize_name', 'prize_note', 'platform', 'region', 'eligibility', 'participation', 'results_note', 'delivery_note'];
    foreach ($fields as $field) {
        if (array_key_exists($field, $input)) {
            $season[$field] = trim((string)$input[$field]);
        }
    }
    if (isset($input['c1']) || isset($input['c2'])) {
        $season['cover'] = [
            'c1' => mg_safe_color($input['c1'] ?? ($season['cover']['c1'] ?? '#2b2141')),
            'c2' => mg_safe_color($input['c2'] ?? ($season['cover']['c2'] ?? '#c5f46f')),
        ];
    }
    if (isset($input['start_ts']) && is_numeric($input['start_ts'])) {
        $season['start_ts'] = (int)$input['start_ts'];
    }
    if (isset($input['end_ts']) && is_numeric($input['end_ts'])) {
        $season['end_ts'] = (int)$input['end_ts'];
    }
    if (isset($input['seed']) && is_numeric($input['seed']) && $season['status'] === 'draft') {
        $season['seed'] = mg_normalize_seed((int)$input['seed']);
    }
    if ($season['end_ts'] <= $season['start_ts']) {
        return ['ok' => false, 'code' => 'bad_period', 'error' => 'Bitiş tarihi başlangıçtan sonra olmalı.'];
    }
    $season['updated_utc'] = mg_now_utc();
    mg_season_save($season, $adminId, 'update');
    return ['ok' => true, 'season' => $season];
}

/** Durum geçişi (yalnızca izin verilen geçişler). */
function mg_season_transition(int $id, string $to, int $adminId): array
{
    $season = mg_season($id);
    if (!$season) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Sezon bulunamadı.'];
    }
    $from = (string)$season['status'];
    if (!in_array($to, MG_SEASON_TRANSITIONS[$from] ?? [], true)) {
        return ['ok' => false, 'code' => 'bad_transition', 'error' => 'Bu durum geçişine izin verilmiyor (' . $from . ' → ' . $to . ').'];
    }
    if ($to === 'active') {
        if (!mg_flag('season')) {
            return ['ok' => false, 'code' => 'season_disabled', 'error' => 'Ödüllü yarışma bayrağı kapalı. Önce ayarlardan açın.'];
        }
        if ((int)$season['end_ts'] <= time()) {
            return ['ok' => false, 'code' => 'bad_period', 'error' => 'Bitiş tarihi geçmiş bir sezon başlatılamaz.'];
        }
        foreach (mg_seasons() as $other) {
            if ((int)$other['id'] !== $id && ($other['status'] ?? '') === 'active') {
                return ['ok' => false, 'code' => 'already_active', 'error' => 'Aynı anda yalnızca bir sezon aktif olabilir.'];
            }
        }
    }
    if ($to === 'review') {
        $season['final'] = $season['final'] ?? [];
        $season['review_snapshot'] = [
            'taken_utc' => mg_now_utc(),
            'taken_ts' => time(),
            'top' => array_slice(mg_board_sorted(mg_board_key('season', time(), $season)), 0, 10),
        ];
        $season['review_snapshot']['top'] = mg_season_snapshot_rows($season['review_snapshot']['top']);
    }
    $season['status'] = $to;
    $season['updated_utc'] = mg_now_utc();
    if ($to === 'cancelled') {
        $season['cancelled_utc'] = mg_now_utc();
        $season['cancelled_by'] = $adminId;
    }
    mg_season_save($season, $adminId, 'transition');
    return ['ok' => true, 'season' => $season];
}

/** Anlık görüntü satırlarına kullanıcı adı ekler (PII yok). */
function mg_season_snapshot_rows(array $rows): array
{
    $data = dg_read_data();
    $names = [];
    foreach ($data['users'] ?? [] as $user) {
        $names[(int)$user['id']] = (string)$user['username'];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'user_id' => (int)$row['user_id'],
            'username' => $names[(int)$row['user_id']] ?? ('oyuncu#' . (int)$row['user_id']),
            'score' => (int)$row['score'],
            'achieved_ts' => (int)$row['achieved_ts'],
            'run_id' => (string)$row['run_id'],
        ];
    }
    return $out;
}

/**
 * Sezonu kesinleştirir: kazananı anlık görüntüden seçer, şampiyon
 * kozmetiğini BİR KEZ verir. Aynı sezon tekrar kesinleştirilemez.
 */
function mg_season_finalize(int $id, int $adminId, ?int $winnerUserId = null): array
{
    $season = mg_season($id);
    if (!$season) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Sezon bulunamadı.'];
    }
    if (isset($season['final']['winner_user_id'])) {
        return ['ok' => true, 'already' => true, 'final' => $season['final']];
    }
    if (!in_array($season['status'], ['review', 'active'], true)) {
        return ['ok' => false, 'code' => 'bad_transition', 'error' => 'Yalnızca incelemedeki sezon kesinleştirilebilir.'];
    }

    $snapshot = $season['review_snapshot']['top'] ?? [];
    if (!$snapshot) {
        $snapshot = mg_season_snapshot_rows(array_slice(mg_board_sorted(mg_board_key('season', time(), $season)), 0, 10));
    }
    $winner = null;
    foreach ($snapshot as $row) {
        if ($winnerUserId !== null && (int)$row['user_id'] !== $winnerUserId) {
            continue;
        }
        $winner = $row;
        break;
    }
    if (!$winner) {
        return ['ok' => false, 'code' => 'no_winner', 'error' => 'Sezon tablosunda kazanan adayı yok.'];
    }

    $season['final'] = [
        'winner_user_id' => (int)$winner['user_id'],
        'winner_username' => (string)$winner['username'],
        'winner_score' => (int)$winner['score'],
        'winner_run_id' => (string)($winner['run_id'] ?? ''),
        'finalized_utc' => mg_now_utc(),
        'finalized_by' => $adminId,
        'snapshot' => $snapshot,
    ];
    $season['status'] = 'finalized';
    $season['updated_utc'] = mg_now_utc();
    mg_season_save($season, $adminId, 'finalize');
    $grant = mg_grant_champion((int)$winner['user_id'], $id);
    mg_audit($adminId, 'season.champion_grant', 'season:' . $id, [
        'user_id' => (int)$winner['user_id'],
        'already' => !empty($grant['already']),
    ]);
    return ['ok' => true, 'final' => $season['final'], 'champion_granted' => empty($grant['already'])];
}

/** Teslim takibi (ödül elle verilir; sistem kod/anahtar saklamaz). */
function mg_season_deliver(int $id, int $adminId, string $note): array
{
    $season = mg_season($id);
    if (!$season) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Sezon bulunamadı.'];
    }
    if (($season['status'] ?? '') !== 'finalized') {
        return ['ok' => false, 'code' => 'bad_transition', 'error' => 'Yalnızca kesinleşmiş sezon teslim edilebilir.'];
    }
    $season['status'] = 'delivered';
    $season['delivered'] = [
        'utc' => mg_now_utc(),
        'ts' => time(),
        'by' => $adminId,
        'note' => trim($note),
    ];
    $season['updated_utc'] = mg_now_utc();
    mg_season_save($season, $adminId, 'deliver');
    return ['ok' => true, 'season' => $season];
}

/** Şampiyonlar vitrini (kesinleşmiş/teslim edilmiş sezonlar). */
function mg_champions(int $limit = 8): array
{
    $out = [];
    foreach (array_reverse(mg_seasons()) as $season) {
        if (!in_array($season['status'] ?? '', ['finalized', 'delivered'], true)) {
            continue;
        }
        $final = $season['final'] ?? [];
        if (empty($final['winner_user_id'])) {
            continue;
        }
        $out[] = [
            'season_id' => (int)$season['id'],
            'title' => (string)$season['title'],
            'prize_name' => (string)$season['prize_name'],
            'winner_username' => (string)($final['winner_username'] ?? ''),
            'score' => (int)($final['winner_score'] ?? 0),
            'finalized_at' => (string)($final['finalized_utc'] ?? ''),
            'delivered' => ($season['status'] ?? '') === 'delivered',
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
