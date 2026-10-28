<?php
/**
 * Motor eşlik + kural testi (v2).
 *
 * Çalıştırma: cd tools && node php-cli-run.mjs ../tests/php/engine_test.php
 *
 * 1) JS fikstürleri (tests/fixtures/minigame-vectors.json) PHP motorunda
 *    BİREBİR aynı sonucu vermeli.
 * 2) v2 kuralları: eğilme, ateş, dash, can, boss fazları.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/minigame/engine.php';

$failures = [];
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ' → ' . $detail : '');
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function same_int(string $label, $actual, $expected): void
{
    check($label, (int)$actual === (int)$expected, 'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
}

echo "== Motor eşlik testi (JS ↔ PHP, v2) ==\n";

$fixturePath = __DIR__ . '/../fixtures/minigame-vectors.json';
check('fixture dosyası var', is_file($fixturePath));
$fixture = json_decode((string)file_get_contents($fixturePath), true);
check('fixture JSON geçerli', is_array($fixture));
check('motor sürümü eşleşiyor', ($fixture['engine'] ?? '') === MG_ENGINE_VERSION, (string)($fixture['engine'] ?? ''));

if (!is_array($fixture) || empty($fixture['cases'])) {
    echo "Fixture yok; test durduruldu.\n";
    exit(1);
}

foreach ($fixture['cases'] as $case) {
    $name = (string)$case['name'];
    $seed = (int)$case['seed'];
    $maxTicks = (int)($case['max_ticks'] ?? 7200);
    $inputs = array_map(static fn($pair): array => [(int)$pair[0], (int)$pair[1]], $case['inputs']);
    $expect = $case['result'];

    $result = mg_simulate($seed, $inputs, ['maxTicks' => $maxTicks]);

    $ok = true;
    foreach ([
        'ticks', 'distance', 'cells', 'cellsMissed', 'cellsSeen', 'jumps',
        'kills', 'bossKilled', 'damageTaken', 'hp', 'shotsFired', 'bossHpLeft', 'score',
    ] as $field) {
        if ((int)$result[$field] !== (int)$expect[$field]) {
            $ok = false;
            check("{$name} / {$field}", false, 'beklenen ' . $expect[$field] . ', gelen ' . $result[$field]);
        }
    }
    check("{$name} / reason", (string)$result['reason'] === (string)$expect['reason'], 'beklenen ' . $expect['reason'] . ', gelen ' . $result['reason']);
    check("{$name} / canonical", mg_encode_inputs($inputs) === (string)$case['encoded']);
    if ($ok) {
        printf(
            "  ✓ %-18s tick=%5d skor=%5d hp=%3d öldürme=%3d reason=%s\n",
            $name,
            $result['ticks'],
            $result['score'],
            $result['hp'],
            $result['kills'],
            $result['reason']
        );
    }
}

// --- v2 motor kuralları -------------------------------------------------

echo "\n[Kurallar]\n";

check('normalize seed 0 → sabit', mg_create_state(0)['seed'] === 0x9E3779B9);
same_int('hız kademesi başlangıcı', mg_speed_at(0), 34);
same_int('hız kademesi 600. tick', mg_speed_at(600), 37);
same_int('hız üst sınırı', mg_speed_at(100000), 76);
same_int('skor formülü (mesafe + hücre)', mg_score_for(1600, 3), 10 + 45);
same_int('skor formülü (öldürme)', mg_score_for(0, 0, 3), 3 * 40);
same_int('skor formülü (boss)', mg_score_for(0, 0, 0, true), 500);
same_int('skor formülü (hepsi)', mg_score_for(1600, 2, 3, true), 10 + 30 + 120 + 500);

// Zıplama
$s = mg_create_state(99);
$status = mg_engine_step($s, MG_ACTION_JUMP);
check('zıplama durumu', $status === 'running' && $s['vy'] === 43 && $s['grounded'] === 0 && $s['jumps'] === 1, 'vy=' . $s['vy']);

$s2 = mg_create_state(99);
mg_engine_step($s2, MG_ACTION_JUMP);
mg_engine_step($s2, MG_ACTION_RELEASE);
check('bırakınca kısa zıplama', $s2['vy'] < 46 && $s2['vy'] <= 18, (string)$s2['vy']);

$s3 = mg_create_state(5);
mg_engine_step($s3, MG_ACTION_JUMP);
$vyBefore = $s3['vy'];
mg_engine_step($s3, MG_ACTION_JUMP);
check('çift zıplama engelli', $s3['vy'] === $vyBefore - 3 && $s3['jumps'] === 1, 'vy ' . $s3['vy'] . ' jumps ' . $s3['jumps']);

$s4 = mg_create_state(6);
mg_engine_step($s4, MG_ACTION_JUMP);
$airTicks = 1;
while ($s4['grounded'] === 0 && $airTicks < 200) {
    mg_engine_step($s4, MG_ACTION_NONE);
    $airTicks++;
}
check('tek zıplama süresi makul (25–40 tick)', $airTicks >= 25 && $airTicks <= 40, (string)$airTicks);

// Eğilme: hitbox küçülür
$c1 = mg_create_state(11);
$standing = mg_player_box($c1);
mg_engine_step($c1, MG_ACTION_CROUCH);
$crouched = mg_player_box($c1);
check('eğilme yüksekliği azaltır', $crouched['y2'] - $crouched['y1'] < $standing['y2'] - $standing['y1'], ($crouched['y2'] - $crouched['y1']) . ' < ' . ($standing['y2'] - $standing['y1']));
same_int('eğilmiş hitbox yüksekliği', $crouched['y2'] - $crouched['y1'], 166);
same_int('ayakta hitbox yüksekliği', $standing['y2'] - $standing['y1'], 310);

// Eğikken zıplanmaz
$c2 = mg_create_state(12);
mg_engine_step($c2, MG_ACTION_CROUCH);
$before = $c2['grounded'];
mg_engine_step($c2, MG_ACTION_JUMP);
check('eğikken zıplama yok', $c2['grounded'] === 1 && $c2['jumps'] === 0, 'jumps=' . $c2['jumps']);

// Kalkma
$c3 = mg_create_state(13);
mg_engine_step($c3, MG_ACTION_CROUCH);
mg_engine_step($c3, MG_ACTION_STAND);
same_int('kalkınca eğilme biter', $c3['crouch'], 0);

// Ateş: şarj azalır, mermi doğar
$f1 = mg_create_state(21);
$shotsBefore = $f1['shots'];
mg_engine_step($f1, MG_ACTION_FIRE);
same_int('ateş bir şarj harcar', $f1['shots'], $shotsBefore - 1);
check('mermi oluştu', count($f1['bullets']) >= 1);
check('mermi sahibi oyuncu', (int)$f1['bullets'][0]['own'] === 1);
same_int('atış sayacı', $f1['shotsFired'], 1);

// Şarj yenilenmesi
$f2 = mg_create_state(22);
for ($i = 0; $i < 8; $i++) {
    mg_engine_step($f2, MG_ACTION_FIRE);
}
same_int('şarj bitti (8 atış)', $f2['shots'], 0);
for ($i = 0; $i < 45; $i++) {
    mg_engine_step($f2, MG_ACTION_NONE);
}
check('şarj zamanla yenilenir', $f2['shots'] >= 1, (string)$f2['shots']);

// Dash: kısa dokunulmazlık
$d1 = mg_create_state(31);
mg_engine_step($d1, MG_ACTION_DASH);
check('dash dokunulmazlık verir', $d1['dashUntil'] > $d1['tick']);
check('dash bekleme süresi koyar', $d1['dashReadyAt'] > $d1['tick'] + 100);

// Hasar ve can
$h1 = mg_create_state(41);
$hpBefore = $h1['hp'];
$dealt = mg_damage_player($h1, 12);
check('hasar uygulandı', $dealt === true && $h1['hp'] === $hpBefore - 12, (string)$h1['hp']);
$dealt2 = mg_damage_player($h1, 12);
check('dokunulmazlık ikinci hasarı engeller', $dealt2 === false && $h1['hp'] === $hpBefore - 12);

// Can biterse tur biter
$h2 = mg_create_state(42);
for ($i = 0; $i < 12; $i++) {
    $h2['invuln'] = 0;
    mg_damage_player($h2, 12);
}
check('can sıfırda ölüm', $h2['alive'] === 0 && $h2['reason'] === 'hp' && $h2['hp'] === 0);
same_int('toplam alınan hasar (9 vuruş)', $h2['damageTaken'], 108);
check('ölümden sonra hasar işlemez', mg_damage_player($h2, 12) === false);

// Kalkan bir vuruşu emer
$h3 = mg_create_state(43);
$h3['shield'] = 1;
mg_damage_player($h3, 12);
check('kalkan hasarı emer', $h3['hp'] === 100 && $h3['shield'] === 0);

// Boss fazları: can eşiklerine göre
$b = mg_create_state(51);
$b['tick'] = 3600;
mg_engine_step($b, MG_ACTION_NONE);
check('boss 60. saniyede girer', $b['boss'] !== null && $b['bossSpawned'] === 1);
if ($b['boss'] !== null) {
    same_int('boss başlangıç fazı', (int)$b['boss']['phase'], 1);
    $b['boss']['hp'] = 90;   // %60
    mg_engine_step($b, MG_ACTION_NONE);
    same_int('boss 2. faz (%60)', (int)$b['boss']['phase'], 2);
    $b['boss']['hp'] = 40;   // %26
    mg_engine_step($b, MG_ACTION_NONE);
    same_int('boss 3. faz (%26)', (int)$b['boss']['phase'], 3);
    mg_damage_boss($b, 999);
    same_int('boss öldü', (int)$b['bossKilled'], 1);
}

// Determinizm
$a = mg_simulate(2024, [[10, 1], [40, 2], [120, 3], [200, 4], [260, 5]], ['maxTicks' => 900]);
$b2 = mg_simulate(2024, [[10, 1], [40, 2], [120, 3], [200, 4], [260, 5]], ['maxTicks' => 900]);
check('aynı girdi → aynı sonuç', json_encode($a) === json_encode($b2));
$c = mg_simulate(2025, [[10, 1], [40, 2], [120, 3], [200, 4], [260, 5]], ['maxTicks' => 900]);
check('farklı seed → farklı sonuç', json_encode($a) !== json_encode($c));

// encode/decode
check('encode boş', mg_encode_inputs([]) === '');
check('encode biçimi', mg_encode_inputs([[3, 1], [9, 3]]) === '3:1,9:3');
check('decode tur', mg_decode_inputs('3:1,9:3') === [[3, 1], [9, 3]]);
check('decode bozuk', mg_decode_inputs('3-1') === null);

// Yapılandırma
check('config hash aynı', mg_config_hash() === mg_config_hash());
same_int('config hash 16 karakter', strlen(mg_config_hash()), 16);
same_int('motor üst sınırı 180 sn', mg_engine_config()['maxTicks'], 10800);
check('altı geçerli aksiyon', count(mg_valid_actions()) === 6, (string)count(mg_valid_actions()));

echo "\n";
if ($failures) {
    echo 'SONUÇ: BAŞARISIZ — ' . count($failures) . ' hata / ' . $checks . " kontrol\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}
echo 'SONUÇ: BAŞARILI — ' . $checks . " kontrol\n";
