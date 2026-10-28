<?php
/**
 * Mini Oyun — entegrasyon testi (WASM/PHP CLI).
 *
 * Çalıştırma: cd tools && node php-cli-run.mjs ../tests/php/integration_test.php
 *
 * Bu test YALNIZCA geçici WASM kopyasındaki storage/ üzerinde çalışır; canlı
 * veriye dokunmaz. Başlangıçta storage/minigame ve storage/dg temizlenir.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/minigame/bootstrap.php';

const TEST_NOW = 1800000000; // 2027-01-15 08:00 UTC (sabit, gelecekte)

$checks = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ' → ' . $detail : '');
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function same(string $name, $actual, $expected, string $detail = ''): void
{
    check($name, $actual === $expected, 'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
}

function section(string $title): void
{
    echo "\n[" . $title . "]\n";
}

/* ------------------------------------------------------------------ *
 * Temiz kurulum
 * ------------------------------------------------------------------ */

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

rrmdir(__DIR__ . '/../../storage/minigame');
@unlink(__DIR__ . '/../../storage/dg/database.json');
@unlink(__DIR__ . '/../../storage/dg/database.lock');
dg_ensure_storage();
mg_ensure_storage();

/**
 * Test botu (basit v2 sürücüsü): kiriş görünce eğil, engel görünce zıpla,
 * hedef hizadaysa ateş et. Kurallara UYAN girdi üretir; ustalık hedeflemez.
 * (Tam sürüm: tools/minigame-bot.mjs — denge ölçümü orada yapılır.)
 */
function test_bot(int $seed, int $maxTicks): array
{
    $c = mg_engine_config();
    $state = mg_create_state($seed);
    $inputs = [];
    $releaseAt = -1;

    while ((int)$state['tick'] < $maxTicks) {
        $tick = (int)$state['tick'];
        $action = MG_ACTION_NONE;

        if ($releaseAt >= 0 && $tick >= $releaseAt) {
            $action = MG_ACTION_RELEASE;
            $releaseAt = -1;
        } else {
            $box = mg_player_box($state);
            $speed = (int)$state['speed'];

            // Kiriş: yaklaşırken eğil, altından geçtikten sonra kalk.
            $ceilingNear = false;
            $ceilingOver = false;
            foreach ($state['ceilings'] as $ceiling) {
                if ($ceiling['x'] + $ceiling['w'] < $box['x1']) {
                    continue;
                }
                $ceilingOver = $ceiling['x'] <= $box['x2'];
                $ceilingNear = ($ceiling['x'] - $box['x2']) <= 10 * $speed;
                break;
            }

            if (($ceilingOver || $ceilingNear) && (int)$state['crouch'] === 0 && (int)$state['grounded'] === 1) {
                $action = MG_ACTION_CROUCH;
            } elseif (!$ceilingNear && (int)$state['crouch'] === 1) {
                $action = MG_ACTION_STAND;
            } elseif ((int)$state['grounded'] === 1 && (int)$state['crouch'] === 0) {
                $right = $box['x2'];
                $blocker = null;
                foreach ($state['obstacles'] as $obstacle) {
                    if ($obstacle['x'] + $obstacle['w'] < $right) {
                        continue;
                    }
                    $blocker = $obstacle;
                    break;
                }
                if ($blocker !== null && ($blocker['x'] - $right) <= $speed * 9) {
                    $action = MG_ACTION_JUMP;
                    $releaseAt = $tick + 12;
                }
            }

            // Hedef namlu hizasındaysa ateş et (öldürme sayacı ve görevler için).
            if ($action === MG_ACTION_NONE && (int)$state['shots'] > 0) {
                $muzzle = intdiv($box['y1'] + $box['y2'], 2);
                foreach ($state['enemies'] as $enemy) {
                    if ((int)$enemy['hp'] <= 0) {
                        continue;
                    }
                    $gap = (int)$enemy['x'] - $box['x2'];
                    if ($gap <= 0 || $gap > 1500) {
                        continue;
                    }
                    if ($muzzle < (int)$enemy['y'] || $muzzle > (int)$enemy['y'] + (int)$enemy['h']) {
                        continue;
                    }
                    $action = MG_ACTION_FIRE;
                    break;
                }
            }
        }

        if ($action !== MG_ACTION_NONE) {
            $inputs[] = [$tick, $action];
        }
        $status = mg_engine_step($state, $action);
        if ($status !== 'running') {
            break;
        }
    }
    return $inputs;
}

function user_balance(int $userId): int
{
    foreach (dg_read_data()['users'] as $user) {
        if ((int)$user['id'] === $userId) {
            return (int)$user['dcoin_balance'];
        }
    }
    return -1;
}

function settings_with(array $patch): void
{
    $settings = mg_settings(true);
    foreach ($patch as $key => $value) {
        if ($key === 'flags') {
            foreach ($value as $flag => $flagValue) {
                $settings['flags'][$flag] = $flagValue;
            }
        } elseif ($key === 'limits') {
            foreach ($value as $limit => $limitValue) {
                $settings['limits'][$limit] = $limitValue;
            }
        } else {
            $settings[$key] = $value;
        }
    }
    mg_set_settings($settings, 1);
}

echo "== Mini Oyun entegrasyon testi ==\n";

/* ------------------------------------------------------------------ *
 * 1. Ayarlar ve dönemler
 * ------------------------------------------------------------------ */

section('Ayarlar ve dönemler');

$settings = mg_settings(true);
same('şema', (int)$settings['schema'], MG_SETTINGS_SCHEMA);
check('ödüller varsayılan KAPALI (onay öncesi)', mg_flag('rewards') === false);
check('mağaza varsayılan KAPALI (onay öncesi)', mg_flag('shop') === false);
check('oyun varsayılan AÇIK', mg_flag('game') === true);
check('ekonomi onayı varsayılan 0', (int)$settings['economy']['approved'] === 0);
same('görev sayısı', count(mg_tasks(true)), 6);
same('görev kimlikleri', array_map(static fn(array $t): string => (string)$t['id'], mg_tasks(true)), ['gunluk-tur', 'skor-1500', 'hucre-15', 'avci-15', 'boss-avcisi', 'uc-tur']);
same('görev metrikleri', array_map(static fn(array $t): string => (string)$t['metric'], mg_tasks(true)), ['valid_runs', 'best_score', 'best_cells', 'kills_total', 'boss_kills', 'valid_runs']);
same('operasyonel limit: koşu ömrü', mg_limit('run_ttl_seconds'), 300);
same('operasyonel limit: girdi sınırı', mg_limit('max_inputs'), 3000);
check('günlük tavan görev toplamından büyük', mg_limit('daily_user_reward_cap') >= array_sum(array_map(static fn(array $t): int => (int)$t['reward'], mg_tasks(true))));

// İstanbul gün sınırı: 2026-03-08 21:30 UTC = 2026-03-09 00:30 TR
$tz = new DateTimeZone('Europe/Istanbul');
$monday = (new DateTimeImmutable('2026-03-09 00:30:00', $tz))->getTimestamp();
// v1 → v3 şema yükseltmesi: operasyonel limitler taban değere çekilir,
// ödül limitleri (işletme kararı) KORUNUR.
$previous = mg_settings(true);
mg_write_json('settings.json', [
    'schema' => 2,
    'flags' => $previous['flags'],
    'limits' => ['run_ttl_seconds' => 180, 'max_inputs' => 600, 'daily_user_reward_cap' => 100],
    'tasks' => [],
    'economy' => ['approved' => 0, 'note' => ''],
]);
$migrated = mg_settings(true);
same('şema yükseltildi', (int)$migrated['schema'], MG_SETTINGS_SCHEMA);
same('TTL tabanı 300', (int)$migrated['limits']['run_ttl_seconds'], 300);
same('girdi tabanı 3000', (int)$migrated['limits']['max_inputs'], 3000);
same('ödül limiti korunur', (int)$migrated['limits']['daily_user_reward_cap'], 100);
same('yükseltme görev listesini doldurur', count(mg_tasks(true)), 6);

// v1 görev setiyle gelen depo da yeni görevlere geçer; özel görev korunur.
mg_write_json('settings.json', [
    'schema' => 2,
    'flags' => $previous['flags'],
    'limits' => ['run_ttl_seconds' => 180],
    'tasks' => [
        ['id' => 'gunluk-tur', 'title' => 'İlk sıçrama', 'metric' => 'valid_runs', 'target' => 1, 'reward' => 15, 'order' => 1, 'enabled' => 1],
        ['id' => 'skor-800', 'title' => '800 kulübü', 'metric' => 'best_score', 'target' => 800, 'reward' => 25, 'order' => 2, 'enabled' => 1],
    ],
]);
mg_settings(true); // doğrudan dosya yazımı önbelleği geçersiz kılmaz
same('v1 görev seti yenilendi', count(mg_tasks(true)), 6);
mg_write_json('settings.json', [
    'schema' => 2,
    'flags' => $previous['flags'],
    'limits' => ['run_ttl_seconds' => 180],
    'tasks' => [
        ['id' => 'ozel-gorev', 'title' => 'Özel', 'metric' => 'valid_runs', 'target' => 1, 'reward' => 5, 'order' => 1, 'enabled' => 1],
    ],
]);
mg_settings(true);
same('özel görev korunur', count(mg_tasks(true)), 1);
mg_set_settings(mg_default_settings(), 1);

$day = mg_day_bounds($monday);
same('gün anahtarı (İstanbul)', $day['key'], '2026-03-09');
same('gün başlangıcı TR 00:00', (new DateTimeImmutable('@' . $day['start_ts']))->setTimezone($tz)->format('Y-m-d H:i'), '2026-03-09 00:00');
same('gün süresi 24 saat', $day['end_ts'] - $day['start_ts'], 86400);
$week = mg_week_bounds($monday);
same('hafta başlangıcı pazartesi', (new DateTimeImmutable('@' . $week['start_ts']))->setTimezone($tz)->format('D'), 'Mon');
same('hafta başlangıcı TR 00:00', (new DateTimeImmutable('@' . $week['start_ts']))->setTimezone($tz)->format('Y-m-d H:i'), '2026-03-09 00:00');
$sundayBefore = (new DateTimeImmutable('2026-03-08 23:59:59', $tz))->getTimestamp();
same('pazar 23:59:59 önceki haftada', mg_week_bounds($sundayBefore)['key'] !== $week['key'], true);
$nextMonday = (new DateTimeImmutable('2026-03-16 00:00:00', $tz))->getTimestamp();
same('sonraki pazartesi yeni hafta', mg_week_bounds($nextMonday)['key'] !== $week['key'], true);
same('yarı açık aralık: bitiş dahil değil', mg_in_period($day['end_ts'], $day), false);
same('yarı açık aralık: başlangıç dahil', mg_in_period($day['start_ts'], $day), true);

/* ------------------------------------------------------------------ *
 * 2. Koşu başlatma ve doğrulama
 * ------------------------------------------------------------------ */

section('Koşu başlatma');

$startAt = time() - 150;
$run = mg_run_start(2, $startAt);
check('koşu başladı', !empty($run['ok']), (string)($run['error'] ?? ''));
check('run id biçimi', (bool)preg_match('/^run_[a-f0-9]{24}$/', (string)$run['run_id']));
check('token 64 hex', (bool)preg_match('/^[a-f0-9]{64}$/', (string)$run['token']));
check('seed > 0', (int)$run['seed'] > 0);
same('sürüm', $run['version'], MG_ENGINE_VERSION);
same('ilk tur sayacı', (int)$run['attempts'], 1);
check('seed günlük sabit (aynı gün tüm oyuncular)', (int)$run['seed'] === mg_daily_seed($startAt));
check('süre sınırı', (int)$run['expires_ts'] > $startAt);
check('depoda token düz metni yok', !str_contains((string)file_get_contents(MG_DIR . '/runs/active.json'), (string)$run['token']));

$botInputs = test_bot((int)$run['seed'], 900);
check('bot girdi üretti', count($botInputs) > 5, (string)count($botInputs));

$submitAt = time() - 20;
$submit = mg_run_submit(2, (string)$run['run_id'], (string)$run['token'], $botInputs, null, $submitAt);
check('gönderim doğrulandı', !empty($submit['ok']), (string)($submit['error'] ?? ''));
same('durum verified', $submit['status'] ?? '', 'verified');
check('sunucu skoru > 0', (int)($submit['result']['score'] ?? 0) > 0, (string)($submit['result']['score'] ?? 0));
check('görev ödülü ödenmedi (rewards kapalı)', (int)($submit['rewards']['paid'] ?? -1) === 0);
same('bakiye değişmedi', user_balance(2), 250);
same('çift gönderim: aynı girdiler', (mg_run_submit(2, (string)$run['run_id'], (string)$run['token'], $botInputs, null, $submitAt)['duplicate'] ?? false), true);
same('çift gönderim: farklı girdiler reddedilir', mg_run_submit(2, (string)$run['run_id'], (string)$run['token'], [], null, $submitAt)['code'] ?? '', 'already_finalized');

// v2 sonuç alanları ve skor formülü
check('v2 sonuç alanları', isset($submit['result']['kills'], $submit['result']['hp'], $submit['result']['bossKilled'], $submit['result']['damageTaken']));
same(
    'skor formülü (mesafe + hücre + öldürme + boss)',
    (int)($submit['result']['score'] ?? -1),
    mg_score_for(
        (int)($submit['result']['distance'] ?? 0),
        (int)($submit['result']['cells'] ?? 0),
        (int)($submit['result']['kills'] ?? 0),
        !empty($submit['result']['bossKilled'])
    )
);
check('v2 turu geçerli sayıldı (≥600 tick)', (int)($submit['result']['ticks'] ?? 0) >= 600, (string)($submit['result']['ticks'] ?? 0));
$statsToday = mg_stats_user(mg_day_bounds(time())['key'], 2);
same('istatistik: geçerli tur', (int)$statsToday['valid_runs'], 1);
same('istatistik: öldürme toplamı', (int)$statsToday['kills_total'], (int)($submit['result']['kills'] ?? 0));
same('istatistik: hasarsız tur sayacı', (int)$statsToday['no_damage_runs'], (int)($submit['result']['damageTaken'] ?? 0) === 0 ? 1 : 0);
same('görev metriği: öldürme', mg_task_progress_value('kills_total', $statsToday), (int)$statsToday['kills_total']);
same('görev metriği: boss', mg_task_progress_value('boss_kills', $statsToday), (int)$statsToday['boss_kills']);

$last = mg_user_last_run(2);
check('son tur işaretçisi', is_array($last) && $last['run_id'] === (string)$run['run_id']);
same('son tur durumu verified', $last['status'] ?? '', 'verified');
same('son tur skoru sunucu skoru', (int)($last['score'] ?? -1), (int)($submit['result']['score'] ?? 0));
same('başka kullanıcının son turu yok', mg_user_last_run(77), null);

// Yabancı kullanıcı
$run2 = mg_run_start(3, time() - 150);
check('ikinci koşu', !empty($run2['ok']), (string)($run2['error'] ?? ''));
same('yabancı koşuya erişim', mg_run_submit(2, (string)$run2['run_id'], (string)$run2['token'], [], null, time())['code'] ?? '', 'forbidden');
same('yanlış token', mg_run_submit(3, (string)$run2['run_id'], str_repeat('a', 64), [], null, time())['code'] ?? '', 'bad_token');
same('olmayan koşu', mg_run_submit(3, 'run_00000000000000000000000000000000', 'x', [], null, time())['code'] ?? '', 'bad_run');

// Geleceğe ait girdi / geçersiz girdi
same('gelecek tick', mg_validate_inputs([[10800, 1]])['code'] ?? '', 'invalid_input');
same('negatif tick', mg_validate_inputs([[-1, 1]])['code'] ?? '', 'invalid_input');
same('sırasız girdi', mg_validate_inputs([[10, 1], [4, 2]])['code'] ?? '', 'invalid_input');
same('geçersiz aksiyon', mg_validate_inputs([[10, 9]])['code'] ?? '', 'invalid_input');
same('sıfır aksiyon reddedilir (kayıt gerekmez)', mg_validate_inputs([[10, 0]])['code'] ?? '', 'invalid_input');
same('aşırı girdi', mg_validate_inputs(array_fill(0, 3001, [1, 1]))['code'] ?? '', 'invalid_input');
$v2Inputs = mg_validate_inputs([[5, 4], [40, 5], [60, 3], [90, 6], [120, 1], [128, 2], [150, 3]]);
same('v2 aksiyonları geçerli', $v2Inputs['ok'] ?? false, true);
same('v2 aksiyon sayısı', count($v2Inputs['inputs'] ?? []), 7);

// Zamanlama hilesi: hemen gönderim
$run3 = mg_run_start(2, time());
$cheat = mg_run_submit(2, (string)$run3['run_id'], (string)$run3['token'], test_bot((int)$run3['seed'], 7200), null, time());
same('imkânsız zamanlama', $cheat['code'] ?? '', 'impossible_timing');
same('imkânsız koşu reddedildi', mg_run_archived((string)$run3['run_id'])['status'] ?? '', 'rejected');

// İstemci skoru uyuşmazlığı
$run4 = mg_run_start(2, time() - 150);
$inputs4 = test_bot((int)$run4['seed'], 1200);
$bad = mg_run_submit(2, (string)$run4['run_id'], (string)$run4['token'], $inputs4, 999999, time() - 20);
same('sahte skor reddedildi', $bad['code'] ?? '', 'score_mismatch');
same('sahte skor arşivlendi', mg_run_archived((string)$run4['run_id'])['status'] ?? '', 'rejected');

// Süresi dolmuş koşu
$run5 = mg_run_start(2, time() - 400);
$expired = mg_run_submit(2, (string)$run5['run_id'], (string)$run5['token'], [], null, time());
same('süresi dolmuş koşu', $expired['code'] ?? '', 'expired');

/* ------------------------------------------------------------------ *
 * 3. Ödüller, tavan ve idempotency
 * ------------------------------------------------------------------ */

section('Ödüller ve tavan');

settings_with(['flags' => ['rewards' => 1], 'limits' => ['min_valid_ticks' => 60]]);
check('ödüller açıldı', mg_flag('rewards'));
// Not: test botu insan seviyesinde değil; 600 tick şartı görev akışını
// engellemesin diye bu bölümde "geçerli tur" eşiği 1 sn'ye çekilir.
// Gerçek eşik ayrıca doğrulanır (aşağıda ve motor testinde).

$startReward = time() - 150;
$run6 = mg_run_start(2, $startReward);
$inputs6 = test_bot((int)$run6['seed'], 7200);
$before = user_balance(2);
$paid = mg_run_submit(2, (string)$run6['run_id'], (string)$run6['token'], $inputs6, null, time() - 20);
check('tam tur doğrulandı', !empty($paid['ok']), (string)($paid['error'] ?? ''));
check('en az bir görev ödendi', (int)($paid['rewards']['paid'] ?? 0) > 0, (string)($paid['rewards']['paid'] ?? 0));
same('bakiye ödül kadar arttı', user_balance(2), $before + (int)$paid['rewards']['paid']);
$claimCount = count(dg_read_data()['minigame_task_claims']);
check('görev talepleri kayıtlı', $claimCount >= 1, (string)$claimCount);

$again = mg_settle_run_rewards(2, (string)$run6['run_id'], $paid['result'], mg_day_bounds(time()));
same('tekrar ödeme yok', (int)$again['paid'], 0);
same('bakiye sabit', user_balance(2), $before + (int)$paid['rewards']['paid']);

// Tavan: yeni kullanıcı ile test
dg_mutate(static function (array &$data): void {
    $data['users'][] = [
        'id' => 9, 'username' => 'testcap', 'email' => 'cap@example.com',
        'password_hash' => 'x', 'dcoin_balance' => 0, 'is_admin' => 0, 'is_banned' => 0,
        'created_at' => dg_now(), 'updated_at' => dg_now(),
    ];
});
settings_with(['limits' => ['daily_user_reward_cap' => 20]]);
$capRun = mg_run_start(9, time() - 150);
$capInputs = test_bot((int)$capRun['seed'], 7200);
$capResult = mg_run_submit(9, (string)$capRun['run_id'], (string)$capRun['token'], $capInputs, null, time() - 20);
check('tavan aşılmadı', user_balance(9) <= 20, (string)user_balance(9));
$capStatuses = array_map(static fn(array $claim): string => (string)$claim['status'], $capResult['rewards']['claims'] ?? []);
check('tavan engeli bildirildi', in_array('blocked_limit', $capStatuses, true) || !empty($capResult['rewards']['cap_reached']), implode(',', $capStatuses));
$capClaims = array_filter(dg_read_data()['minigame_ledger'], static fn(array $row): bool => (int)($row['user_id'] ?? 0) === 9);
$sum9 = array_sum(array_map(static fn(array $row): int => (int)$row['amount'], $capClaims));
same('ledger toplamı = bakiye', $sum9, user_balance(9));

settings_with(['limits' => ['daily_user_reward_cap' => 100, 'daily_global_budget' => 1000]]);

// Bütçe
settings_with(['limits' => ['daily_global_budget' => 1]]);
dg_mutate(static function (array &$data): void {
    $data['users'][] = [
        'id' => 10, 'username' => 'testbudget', 'email' => 'budget@example.com',
        'password_hash' => 'x', 'dcoin_balance' => 0, 'is_admin' => 0, 'is_banned' => 0,
        'created_at' => dg_now(), 'updated_at' => dg_now(),
    ];
});
$budgetRun = mg_run_start(10, time() - 150);
$budgetInputs = test_bot((int)$budgetRun['seed'], 7200);
$budgetResult = mg_run_submit(10, (string)$budgetRun['run_id'], (string)$budgetRun['token'], $budgetInputs, null, time() - 20);
same('bütçe aşılınca ödeme yok', (int)($budgetResult['rewards']['paid'] ?? -1), 0);
same('bütçe durumu', !empty($budgetResult['rewards']['budget_exhausted']), true);
settings_with(['limits' => ['daily_global_budget' => 5000]]);

/* ------------------------------------------------------------------ *
 * 4. Kozmetik mağaza
 * ------------------------------------------------------------------ */

section('Kozmetik mağaza');

settings_with(['flags' => ['shop' => 1]]);
$catalog = mg_catalog(true);
check('katalog 12 kozmetik', count($catalog) === 12, (string)count($catalog));
check('şampiyon kozmetiği satın alınamaz', (int)mg_catalog_item('frame-champion')['purchasable'] === 0);

$bal = user_balance(2);
$buy = mg_purchase_cosmetic(2, 'trail-pulse');
check('satın alma başarılı', !empty($buy['ok']), (string)($buy['error'] ?? ''));
same('fiyat düşüldü', user_balance(2), $bal - (int)mg_catalog_item('trail-pulse')['price']);
same('sahiplik yazıldı', mg_owns_item(2, 'trail-pulse'), true);
$againBuy = mg_purchase_cosmetic(2, 'trail-pulse');
same('ikinci satın alma ücretsiz', (int)$againBuy['charged'], 0);
same('çift ücretlendirme yok', user_balance(2), $bal - (int)mg_catalog_item('trail-pulse')['price']);

settings_with(['flags' => ['shop' => 0]]);
same('mağaza kapalıyken engel', mg_purchase_cosmetic(2, 'color-sunset')['code'] ?? '', 'shop_disabled');
settings_with(['flags' => ['shop' => 1]]);

dg_mutate(static function (array &$data): void {
    $data['users'][] = [
        'id' => 11, 'username' => 'testpoor', 'email' => 'poor@example.com',
        'password_hash' => 'x', 'dcoin_balance' => 10, 'is_admin' => 0, 'is_banned' => 0,
        'created_at' => dg_now(), 'updated_at' => dg_now(),
    ];
});
$poor = mg_purchase_cosmetic(11, 'char-void');
same('yetersiz bakiye', $poor['code'] ?? '', 'insufficient_funds');
same('bakiye negatife düşmedi', user_balance(11), 10);
same('satın alınmayan sahiplenilmedi', mg_owns_item(11, 'char-void'), false);
same('şampiyon satın alınamaz', mg_purchase_cosmetic(2, 'frame-champion')['code'] ?? '', 'not_purchasable');

// Kuşanım
same('sahip olunmayan kuşanılamaz', mg_equip_item(2, 'trail', 'trail-comet')['code'] ?? '', 'not_owned');
same('yanlış kategori', mg_equip_item(2, 'frame', 'trail-pulse')['code'] ?? '', 'bad_category');
$equip = mg_equip_item(2, 'trail', 'trail-pulse');
same('sahip olunan kuşanıldı', !empty($equip['ok']), true);
same('kuşanım kalıcı', mg_loadout(2)['trail'], 'trail-pulse');
check('görsel palet üretildi', (bool)preg_match('/^#[0-9a-f]{6}$/', mg_loadout_visuals(mg_loadout(2))['trail_color']));

// Görsel, fizik/skoru etkilemez: aynı seed+girdi farklı kuşanımla aynı sonuç
$resA = mg_simulate(777, [[10, 1], [40, 2], [90, 1]], ['maxTicks' => 600]);
dg_mutate(static function (array &$data): void {
    foreach ($data['users'] as $i => $user) {
        if ((int)$user['id'] === 2) {
            $data['users'][$i]['dcoin_balance'] = 10000;
        }
    }
});
mg_purchase_cosmetic(2, 'char-ember');
mg_equip_item(2, 'character', 'char-ember');
$resB = mg_simulate(777, [[10, 1], [40, 2], [90, 1]], ['maxTicks' => 600]);
same('kozmetik sonucu değiştirmez', json_encode($resA), json_encode($resB));

// Delist edilen eşya sahipte kalır
$delisted = mg_catalog(true);
$delisted = array_map(static function (array $item): array {
    if ($item['id'] === 'char-ember') {
        $item['published'] = 0;
    }
    return $item;
}, $delisted);
mg_catalog_save($delisted, 1);
same('delist sonrası sahiplik korunur', mg_owns_item(2, 'char-ember'), true);
same('delist sonrası kuşanılabilir', !empty(mg_equip_item(2, 'character', 'char-ember')['ok']), true);
same('delist edilen satın alınamaz', mg_purchase_cosmetic(2, 'char-ember')['code'] ?? '', 'not_found');
$restore = mg_catalog(true);
$restore = array_map(static function (array $item): array {
    if ($item['id'] === 'char-ember') {
        $item['published'] = 1;
    }
    return $item;
}, $restore);
mg_catalog_save($restore, 1);

/* ------------------------------------------------------------------ *
 * 5. Sıralamalar
 * ------------------------------------------------------------------ */

section('Sıralamalar');

$todayKey = mg_board_key('today', time());
$rows = mg_board_sorted($todayKey);
check('bugünkü tabloda satır var', count($rows) >= 2, (string)count($rows));
$scores = array_map(static fn(array $row): int => (int)$row['score'], $rows);
$sorted = $scores;
rsort($sorted);
same('tablo skora göre azalan', $scores, $sorted);
same('kullanıcı başına tek satır', count($rows), count(array_unique(array_map(static fn(array $row): int => (int)$row['user_id'], $rows))));

// Beraberlik: skor eşit → önce ulaşan
$boardFile = mg_best_path('v1:tie-test');
mg_write_json($boardFile, ['key' => 'v1:tie-test', 'rows' => []]);
mg_best_apply('v1:tie-test', 1, ['score' => 500, 'ticks' => 600, 'cells' => 3], 'run_aaaaaaaaaaaaaaaaaaaaaaaa', 1000);
mg_best_apply('v1:tie-test', 2, ['score' => 500, 'ticks' => 600, 'cells' => 3], 'run_bbbbbbbbbbbbbbbbbbbbbbbb', 900);
mg_best_apply('v1:tie-test', 3, ['score' => 500, 'ticks' => 600, 'cells' => 3], 'run_cccccccccccccccccccccccc', 900);
$tie = mg_board_sorted('v1:tie-test');
same('beraberlikte en erken üstte', [(int)$tie[0]['user_id'], (int)$tie[1]['user_id'], (int)$tie[2]['user_id']], [2, 3, 1]);

$view = mg_board_view($todayKey, 1, 20, 2);
check('görünüm satırları kullanıcı adı içerir', isset($view['rows'][0]['username']));
check('görünümde e-posta yok', !str_contains(json_encode($view), '@'));
check('kendi sıram', is_array($view['me']));
check('sayfalama bilgisi', $view['pages'] >= 1);
check('görsel palet', isset($view['rows'][0]['visual']['primary']));

/* ------------------------------------------------------------------ *
 * 6. Sezon ve şampiyon
 * ------------------------------------------------------------------ */

section('Sezon');

settings_with(['flags' => ['season' => 1]]);
$season = mg_season_create([
    'title' => 'Test Haftası',
    'prize_name' => 'Örnek ödül (DEMO)',
    'platform' => 'PC',
    'region' => 'Türkiye',
    'eligibility' => 'Test',
    'participation' => 'Test',
    'results_note' => 'Test',
    'delivery_note' => 'Test',
    'start_ts' => time() - 300,
    'end_ts' => time() + 3600,
    'c1' => '#2b2141',
    'c2' => '#c5f46f',
], 1);
check('sezon oluşturuldu', !empty($season['ok']), (string)($season['error'] ?? ''));
$seasonId = (int)$season['season']['id'];
same('taslak durumu', $season['season']['status'] ?? '', 'draft');
$activated = mg_season_transition($seasonId, 'active', 1);
check('sezon aktifleşti', !empty($activated['ok']), (string)($activated['error'] ?? ''));
$activeSeason = mg_active_season(time());
check('aktif sezon görünüyor', $activeSeason !== null && (int)$activeSeason['id'] === $seasonId);
check('sezon tohumu sabitlendi', (int)$activeSeason['seed_pinned'] === 1);
$seasonStart = mg_run_start(2, time() - 150);
same('sezon koşusu sezon tohumunu kullanır', (int)$seasonStart['seed'], mg_normalize_seed((int)$activeSeason['seed']));
same('sezon koşusu sezon tablosuna bağlı', (int)$seasonStart['season_id'], $seasonId);
$seasonSubmit = mg_run_submit(2, (string)$seasonStart['run_id'], (string)$seasonStart['token'], test_bot((int)$seasonStart['seed'], 7200), null, time() - 20);
check('sezon koşusu doğrulandı', !empty($seasonSubmit['ok']), (string)($seasonSubmit['error'] ?? ''));
$seasonBoard = mg_board_key('season', time(), $activeSeason);
check('sezon tablosunda satır var', count(mg_board_sorted($seasonBoard)) >= 1);

same('taslak yok, inceleme', mg_season_transition($seasonId, 'review', 1)['season']['status'] ?? '', 'review');
$finalized = mg_season_finalize($seasonId, 1);
check('sezon kesinleşti', !empty($finalized['ok']), (string)($finalized['error'] ?? ''));
check('kazanan şampiyon kozmetiği aldı', mg_owns_item((int)$finalized['final']['winner_user_id'], 'frame-champion'));
same('tekrar kesinleştirme idempotent', (int)(mg_season_finalize($seasonId, 1)['final']['winner_user_id'] ?? 0), (int)$finalized['final']['winner_user_id']);
same('kesinleşmiş sezon iptal edilemez', mg_season_transition($seasonId, 'cancelled', 1)['code'] ?? '', 'bad_transition');
$delivered = mg_season_deliver($seasonId, 1, 'Test teslim');
same('teslim kaydedildi', $delivered['season']['status'] ?? '', 'delivered');
check('şampiyon vitrini', count(mg_champions(8)) >= 1);
same('sezon kapanınca aktif sezon yok', mg_active_season(time()), null);
settings_with(['flags' => ['season' => 0]]);

/* ------------------------------------------------------------------ *
 * 7. Yönetici işlemleri
 * ------------------------------------------------------------------ */

section('Yönetici');

// Risk sezgileri
$risk = mg_run_risk(
    array_map(static fn(int $i): array => [$i * 20, 1], range(1, 40)),
    ['cells' => 50, 'cellsSeen' => 50, 'ticks' => 800],
    800,
    20
);
check('mükemmel hücre + düzenli ritim şüpheli', !empty($risk['pending']));
check('risk nedeni listelenir', count($risk['reasons']) >= 2);

$riskV2 = mg_run_risk(
    array_map(static fn(int $i): array => [$i * 20, 3], range(1, 50)),
    ['cells' => 10, 'cellsSeen' => 30, 'ticks' => 1600, 'kills' => 24, 'damageTaken' => 0],
    1600,
    30
);
check('v2: kusursuz nişan + hasarsız tur sinyali', in_array('perfect_accuracy_no_damage', $riskV2['reasons'], true), implode(',', $riskV2['reasons']));
$riskHuman = mg_run_risk(
    array_map(static fn(int $i): array => [$i * 20, 3], range(1, 50)),
    ['cells' => 10, 'cellsSeen' => 30, 'ticks' => 1600, 'kills' => 8, 'damageTaken' => 40],
    1600,
    30
);
check('v2: normal ateşli tur sinyal üretmez', !in_array('perfect_accuracy_no_damage', $riskHuman['reasons'], true), implode(',', $riskHuman['reasons']));

// Bir koşuyu bekleyen durumuna al ve onayla
$pendingRun = mg_run_start(2, time() - 150);
$pendingSubmit = mg_run_submit(2, (string)$pendingRun['run_id'], (string)$pendingRun['token'], test_bot((int)$pendingRun['seed'], 7200), null, time() - 20);
check('bekleme testi için koşu doğrulandı', !empty($pendingSubmit['ok']), (string)($pendingSubmit['error'] ?? ''));
$runId = (string)$pendingRun['run_id'];
mg_lock(static function () use ($runId): void {
    $run = mg_run_archived($runId);
    $run['status'] = 'pending';
    $run['risk'] = 2;
    $run['risk_reasons'] = ['test'];
    $run['counts_for_board'] = false;
    mg_write_json(mg_run_archive_path($runId, (int)$run['started_ts']), $run);
});
$pendingList = array_filter(mg_pending_runs(50), static fn(array $row): bool => (string)$row['run_id'] === $runId);
check('inceleme kuyruğu', count($pendingList) === 1);
$approve = mg_admin_review_run($runId, 'approve', 'Test onayı', 1);
check('onay başarılı', !empty($approve['ok']), (string)($approve['error'] ?? ''));
same('onay sonrası verified', mg_run_archived($runId)['status'] ?? '', 'verified');
check('onay sonrası tabloya işlendi', count(mg_board_sorted(mg_board_key('today', time()))) >= 2);
same('olmayan koşu incelemesi', mg_admin_review_run('run_00000000000000000000000000000000', 'approve', '', 1)['code'] ?? '', 'not_found');

// Skor düzeltme
$override = mg_admin_override_score($runId, 1234, 'Test düzeltmesi', 1);
check('skor düzeltildi', !empty($override['ok']), (string)($override['error'] ?? ''));
same('düzeltme arşivde', (int)(mg_run_archived($runId)['override']['score'] ?? 0), 1234);
same('gerekçesiz düzeltme reddedilir', mg_admin_override_score($runId, 1500, '', 1)['code'] ?? '', 'reason_required');

// Bakiye düzeltmesi: dengeleyici kayıt
$beforeAdj = user_balance(2);
$ledgerBefore = count(dg_read_data()['minigame_ledger']);
$adjust = mg_admin_balance_adjust(2, -5, 'Test düzeltmesi', 1);
check('bakiye düzeltmesi', !empty($adjust['ok']), (string)($adjust['error'] ?? ''));
same('bakiye düştü', user_balance(2), $beforeAdj - 5);
same('ledger satırı eklendi', count(dg_read_data()['minigame_ledger']), $ledgerBefore + 1);
same('negatife izin yok', mg_admin_balance_adjust(2, -100000, 'Test', 1)['code'] ?? '', 'insufficient');

// Ekonomi raporu
$report = mg_economy_report(14);
check('rapor dağıtılan tutar', isset($report['distributed']) && $report['distributed'] >= 0);
check('rapor harcama', isset($report['spent']) && $report['spent'] >= 0);
check('rapor aktif oyuncu', isset($report['active_players']));
check('denetim kaydı', count(mg_audit_tail(5)) >= 1);

/* ------------------------------------------------------------------ *
 * 8. Temizlik / saklama
 * ------------------------------------------------------------------ */

section('Temizlik');

$cron = mg_cron_run(time() + 60 * 86400);
$pruned = ['runs' => $cron['runs']];
check('temizlik çalıştı', is_array($cron) && isset($cron['runs']));
check('eski koşular silindi', !is_file(MG_DIR . '/' . mg_run_archive_path($runId, time())));
same('ledger korunur', count(dg_read_data()['minigame_ledger']) > 0, true);

/* ------------------------------------------------------------------ *
 * Sonuç
 * ------------------------------------------------------------------ */

echo "\n";
if ($failures) {
    echo 'SONUÇ: BAŞARISIZ — ' . count($failures) . ' hata / ' . $checks . " kontrol\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}
echo 'SONUÇ: BAŞARILI — ' . $checks . " kontrol\n";
