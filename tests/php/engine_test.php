<?php
/**
 * Motor eşlik testi: JS fixture'ları PHP motorunda birebir aynı sonucu vermeli.
 *
 * Çalıştırma: cd tools && node php-cli-run.mjs ../tests/php/engine_test.php
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

echo "== Motor eşlik testi (JS ↔ PHP) ==\n";

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
    foreach (['ticks', 'distance', 'cells', 'cellsMissed', 'cellsSeen', 'jumps', 'score'] as $field) {
        if ((int)$result[$field] !== (int)$expect[$field]) {
            $ok = false;
            check("{$name} / {$field}", false, 'beklenen ' . $expect[$field] . ', gelen ' . $result[$field]);
        }
    }
    check("{$name} / reason", (string)$result['reason'] === (string)$expect['reason'], 'beklenen ' . $expect['reason'] . ', gelen ' . $result['reason']);
    check("{$name} / canonical", mg_encode_inputs($inputs) === (string)$case['encoded']);
    if ($ok) {
        printf(
            "  ✓ %-20s ticks=%4d score=%5d cells=%3d reason=%s\n",
            $name,
            $result['ticks'],
            $result['score'],
            $result['cells'],
            $result['reason']
        );
    }
}

// --- Ek motor kuralları -------------------------------------------------

$state = mg_create_state(12345);
check('normalize seed 0 → sabit', mg_create_state(0)['seed'] === 0x9E3779B9);
check('hız kademesi başlangıcı', mg_speed_at(0) === 34, (string)mg_speed_at(0));
check('hız kademesi 600. tick', mg_speed_at(600) === 37, (string)mg_speed_at(600));
check('hız üst sınırı', mg_speed_at(100000) === 76, (string)mg_speed_at(100000));
check('skor formülü', mg_score_for(1600, 3) === 10 + 45, (string)mg_score_for(1600, 3));

$s = mg_create_state(99);
$status = mg_engine_step($s, MG_ACTION_JUMP);
check('zıplama durumu', $status === 'running' && $s['vy'] === 43 && $s['grounded'] === 0 && $s['jumps'] === 1, 'vy=' . $s['vy']);
$s2 = mg_create_state(99);
mg_engine_step($s2, MG_ACTION_JUMP);
mg_engine_step($s2, MG_ACTION_RELEASE);
check('bırakınca kısa zıplama', $s2['vy'] < 46 && $s2['vy'] <= 18);

// havadayken ikinci zıplama yok
$s3 = mg_create_state(5);
mg_engine_step($s3, MG_ACTION_JUMP);
$yBefore = $s3['y'];
$vyBefore = $s3['vy'];
mg_engine_step($s3, MG_ACTION_JUMP);
check('çift zıplama engelli', $s3['vy'] === $vyBefore - 3 && $s3['jumps'] === 1, 'vy ' . $s3['vy'] . ' jumps ' . $s3['jumps']);

// yaklaşık 30 tick havada kalır, sonra yere iner
$s4 = mg_create_state(6);
mg_engine_step($s4, MG_ACTION_JUMP);
$airTicks = 1;
while ($s4['grounded'] === 0 && $airTicks < 200) {
    mg_engine_step($s4, MG_ACTION_NONE);
    $airTicks++;
}
check('tek zıplama süresi makul (25–40 tick)', $airTicks >= 25 && $airTicks <= 40, (string)$airTicks);

// determinizm: aynı seed + girdi iki kez
$a = mg_simulate(2024, [[10, 1], [40, 2], [120, 1]], ['maxTicks' => 600]);
$b = mg_simulate(2024, [[10, 1], [40, 2], [120, 1]], ['maxTicks' => 600]);
check('aynı girdi → aynı sonuç', json_encode($a) === json_encode($b));

// farklı seed → farklı dünya (genellikle)
$c = mg_simulate(2025, [[10, 1], [40, 2], [120, 1]], ['maxTicks' => 600]);
check('farklı seed → farklı sonuç', json_encode($a) !== json_encode($c));

// encode/decode
check('encode boş', mg_encode_inputs([]) === '');
check('encode biçimi', mg_encode_inputs([[3, 1], [9, 2]]) === '3:1,9:2');
check('decode tur', mg_decode_inputs('3:1,9:2') === [[3, 1], [9, 2]]);
check('decode bozuk', mg_decode_inputs('3-1') === null);

// config hash kararlı
check('config hash aynı', mg_config_hash() === mg_config_hash());
check('config hash 16 karakter', strlen(mg_config_hash()) === 16);

echo "\n";
if ($failures) {
    echo 'SONUÇ: BAŞARISIZ — ' . count($failures) . ' hata / ' . $checks . " kontrol\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}
echo 'SONUÇ: BAŞARILI — ' . $checks . " kontrol\n";
