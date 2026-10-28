<?php
/**
 * Device Game — "Overcharge: Kaçış" motorunun SUNUCU kopyası.
 *
 * `assets/js/minigame/engine.js` ile BİREBİR aynı sonucu üretmek zorundadır.
 * Tüm aritmetik tam sayıdır; 32 bit maskesi her kaydırmada uygulanır.
 * Eşlik testi: tests/fixtures/minigame-vectors.json
 */
declare(strict_types=1);

const MG_ENGINE_VERSION = 'v1';
const MG_ACTION_NONE = 0;
const MG_ACTION_JUMP = 1;
const MG_ACTION_RELEASE = 2;

/** Motor sabitleri (istemci CONFIG ile aynı). */
function mg_engine_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = [
        'version' => MG_ENGINE_VERSION,
        'tickHz' => 60,
        'maxTicks' => 7200,
        'unitsPerPx' => 8,
        'playerX' => 960,
        'playerW' => 224,
        'playerH' => 320,
        'playerInsetX' => 14,
        'playerInsetY' => 10,
        'gravity' => 3,
        'jumpV' => 46,
        'cutV' => 18,
        'maxFall' => -72,
        'coyoteTicks' => 4,
        'speedBase' => 34,
        'speedStep' => 3,
        'speedStepTicks' => 600,
        'speedMax' => 76,
        'spawnLookahead' => 3400,
        'firstSpawnX' => 2600,
        'gapTicksBase' => 50,
        'gapTicksMin' => 32,
        'gapTicksTierDrop' => 1,
        'gapJitter' => 14,
        'cellSize' => 80,
        'cellSpacing' => 150,
        'cellScore' => 15,
        'scoreDistDiv' => 160,
        'viewW' => 960,
        'viewH' => 540,
        'groundY' => 420,
    ];
    return $config;
}

/** xorshift32 — JS ile birebir aynı. Durum her adımda 32 bite maskelenir. */
function mg_prng_next(array &$state): int
{
    $x = (int)$state['prng'];
    $x = ($x ^ (($x << 13) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    $x = ($x ^ ($x >> 17)) & 0xFFFFFFFF;
    $x = ($x ^ (($x << 5) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    $state['prng'] = $x;
    return $x;
}

function mg_tier_at(int $tick): int
{
    $c = mg_engine_config();
    return min(14, intdiv($tick, $c['speedStepTicks']));
}

function mg_speed_at(int $tick): int
{
    $c = mg_engine_config();
    return min($c['speedMax'], $c['speedBase'] + mg_tier_at($tick) * $c['speedStep']);
}

function mg_score_for(int $distance, int $cells): int
{
    $c = mg_engine_config();
    return intdiv($distance, $c['scoreDistDiv']) + $cells * $c['cellScore'];
}

function mg_normalize_seed(int $seed): int
{
    $seed = $seed & 0xFFFFFFFF;
    return $seed === 0 ? 0x9E3779B9 : $seed;
}

function mg_create_state(int $seed): array
{
    $seed = mg_normalize_seed($seed);
    $c = mg_engine_config();
    return [
        'version' => MG_ENGINE_VERSION,
        'seed' => $seed,
        'prng' => $seed,
        'tick' => 0,
        'distance' => 0,
        'speed' => $c['speedBase'],
        'y' => 0,
        'vy' => 0,
        'grounded' => 1,
        'coyote' => $c['coyoteTicks'],
        'jumps' => 0,
        'cells' => 0,
        'missed' => 0,
        'nextSpawnX' => $c['firstSpawnX'],
        'obstacles' => [],
        'cellsList' => [],
        'alive' => 1,
        'reason' => '',
    ];
}

/** Enerji hücresi yayı (3–5 hücre). */
function mg_cell_arc(array &$state, int $startX, int $height): void
{
    $c = mg_engine_config();
    $count = 3 + (mg_prng_next($state) % 3);
    for ($i = 0; $i < $count; $i++) {
        $state['cellsList'][] = ['x' => $startX + $i * $c['cellSpacing'], 'y' => $height, 'taken' => 0];
    }
}

/** Tek engel grubu üretir (yalnızca tick/mesafeye bağlı → deterministik). */
function mg_spawn_group(array &$state): void
{
    $c = mg_engine_config();
    $x = (int)$state['nextSpawnX'];
    $tier = mg_tier_at((int)$state['tick']);
    $speed = (int)$state['speed'];
    $pattern = mg_prng_next($state) % 5;
    $maxH = min(240, 120 + $tier * 9);
    $endX = $x + 160;

    if ($pattern === 0) {
        $w = 120 + (mg_prng_next($state) % 90);
        $h = 70 + (mg_prng_next($state) % ($maxH - 70 + 1));
        $state['obstacles'][] = ['x' => $x, 'w' => $w, 'h' => $h];
        $endX = $x + $w;
        if ((mg_prng_next($state) % 100) < 55) {
            mg_cell_arc($state, $x + 30, $h + 150 + (mg_prng_next($state) % 80));
        }
    } elseif ($pattern === 1) {
        $w = 100;
        $gap = 70 + (mg_prng_next($state) % 40);
        $h1 = 90 + (mg_prng_next($state) % ($maxH - 90 + 1));
        $h2 = min($maxH, $h1 + (mg_prng_next($state) % 40));
        $state['obstacles'][] = ['x' => $x, 'w' => $w, 'h' => $h1];
        $state['obstacles'][] = ['x' => $x + $w + $gap, 'w' => $w, 'h' => $h2];
        $endX = $x + $w + $gap + $w;
        if ((mg_prng_next($state) % 100) < 45) {
            mg_cell_arc($state, $x + 40, max($h1, $h2) + 160 + (mg_prng_next($state) % 60));
        }
    } elseif ($pattern === 2) {
        $w = 90;
        $h1 = min($maxH, 100 + (mg_prng_next($state) % 40));
        $h2 = min($maxH, $h1 + 40 + (mg_prng_next($state) % 30));
        $h3 = min($maxH, $h2 + 30 + (mg_prng_next($state) % 30));
        $state['obstacles'][] = ['x' => $x, 'w' => $w, 'h' => $h1];
        $state['obstacles'][] = ['x' => $x + $w, 'w' => $w, 'h' => $h2];
        $state['obstacles'][] = ['x' => $x + $w * 2, 'w' => $w, 'h' => $h3];
        $endX = $x + $w * 3;
    } elseif ($pattern === 3) {
        $w = 240 + (mg_prng_next($state) % 70);
        $h = 70 + (mg_prng_next($state) % (70 + min(30, $tier * 3)));
        $state['obstacles'][] = ['x' => $x, 'w' => $w, 'h' => $h];
        $endX = $x + $w;
        if ((mg_prng_next($state) % 100) < 50) {
            mg_cell_arc($state, $x + 30, $h + 140 + (mg_prng_next($state) % 70));
        }
    } else {
        mg_cell_arc($state, $x, 240 + (mg_prng_next($state) % 140));
        $endX = $x + 360;
    }

    $gapTicks = max($c['gapTicksMin'], $c['gapTicksBase'] - $tier * $c['gapTicksTierDrop'])
        + (mg_prng_next($state) % $c['gapJitter']);
    $state['nextSpawnX'] = $endX + $speed * $gapTicks;
}

function mg_player_box(array $state): array
{
    $c = mg_engine_config();
    $x1 = (int)$state['distance'] + $c['playerX'] + $c['playerInsetX'];
    return [
        'x1' => $x1,
        'x2' => $x1 + $c['playerW'] - $c['playerInsetX'] * 2,
        'y1' => (int)$state['y'] + $c['playerInsetY'],
        'y2' => (int)$state['y'] + $c['playerH'],
    ];
}

/**
 * Tek tick ilerletir. Aksiyon o tick'in fiziğinden ÖNCE uygulanır.
 * @return string 'running'|'collision'|'time'
 */
function mg_engine_step(array &$state, int $action): string
{
    if ((int)$state['alive'] !== 1) {
        return 'collision';
    }
    $c = mg_engine_config();

    if ($action === MG_ACTION_JUMP) {
        if ((int)$state['grounded'] === 1 || (int)$state['coyote'] > 0) {
            $state['vy'] = $c['jumpV'];
            $state['grounded'] = 0;
            $state['coyote'] = 0;
            $state['jumps'] = (int)$state['jumps'] + 1;
        }
    } elseif ($action === MG_ACTION_RELEASE) {
        if ((int)$state['vy'] > $c['cutV']) {
            $state['vy'] = $c['cutV'];
        }
    }

    $state['speed'] = mg_speed_at((int)$state['tick']);
    $state['distance'] = (int)$state['distance'] + (int)$state['speed'];

    if ((int)$state['grounded'] === 1) {
        $state['y'] = 0;
        $state['vy'] = 0;
        $state['coyote'] = $c['coyoteTicks'];
    } else {
        $state['vy'] = (int)$state['vy'] - $c['gravity'];
        if ((int)$state['vy'] < $c['maxFall']) {
            $state['vy'] = $c['maxFall'];
        }
        $state['y'] = (int)$state['y'] + (int)$state['vy'];
        if ((int)$state['y'] <= 0) {
            $state['y'] = 0;
            $state['vy'] = 0;
            $state['grounded'] = 1;
            $state['coyote'] = $c['coyoteTicks'];
        } elseif ((int)$state['coyote'] > 0) {
            $state['coyote'] = (int)$state['coyote'] - 1;
        }
    }

    $playerWorldX = (int)$state['distance'] + $c['playerX'];
    $guard = 0;
    while ((int)$state['nextSpawnX'] < $playerWorldX + $c['spawnLookahead'] && $guard < 8) {
        mg_spawn_group($state);
        $guard++;
    }

    $pruneBefore = $playerWorldX - 2400;
    if (count($state['obstacles']) > 0 && $state['obstacles'][0]['x'] + $state['obstacles'][0]['w'] < $pruneBefore) {
        $state['obstacles'] = array_values(array_filter(
            $state['obstacles'],
            static fn(array $o): bool => $o['x'] + $o['w'] >= $pruneBefore
        ));
    }
    if (count($state['cellsList']) > 0 && $state['cellsList'][0]['x'] < $pruneBefore) {
        $state['cellsList'] = array_values(array_filter(
            $state['cellsList'],
            static fn(array $cell): bool => $cell['x'] >= $pruneBefore
        ));
    }

    $p = mg_player_box($state);

    foreach ($state['obstacles'] as $o) {
        if ($o['x'] > $p['x2']) {
            break;
        }
        if ($o['x'] + $o['w'] <= $p['x1']) {
            continue;
        }
        if ($p['y1'] < $o['h'] && $p['y2'] > 0) {
            $state['alive'] = 0;
            $state['reason'] = 'collision';
            return 'collision';
        }
    }

    $half = intdiv($c['cellSize'], 2);
    $count = count($state['cellsList']);
    for ($i = 0; $i < $count; $i++) {
        $cell = $state['cellsList'][$i];
        if ((int)$cell['taken'] !== 0) {
            continue;
        }
        if ($cell['x'] + $half < $p['x1']) {
            $state['cellsList'][$i]['taken'] = -1;
            $state['missed'] = (int)$state['missed'] + 1;
            continue;
        }
        if ($cell['x'] - $half > $p['x2']) {
            break;
        }
        if ($p['y1'] < $cell['y'] + $half && $cell['y'] - $half < $p['y2']) {
            $state['cellsList'][$i]['taken'] = 1;
            $state['cells'] = (int)$state['cells'] + 1;
        }
    }

    $state['tick'] = (int)$state['tick'] + 1;
    if ((int)$state['tick'] >= $c['maxTicks']) {
        $state['alive'] = 0;
        $state['reason'] = 'time';
        return 'time';
    }
    return 'running';
}

function mg_result_of(array $state): array
{
    return [
        'version' => MG_ENGINE_VERSION,
        'seed' => (int)$state['seed'],
        'ticks' => (int)$state['tick'],
        'distance' => (int)$state['distance'],
        'cells' => (int)$state['cells'],
        'cellsMissed' => (int)$state['missed'],
        'cellsSeen' => (int)$state['cells'] + (int)$state['missed'],
        'jumps' => (int)$state['jumps'],
        'score' => mg_score_for((int)$state['distance'], (int)$state['cells']),
        'reason' => (string)($state['reason'] !== '' ? $state['reason'] : ((int)$state['alive'] === 1 ? 'time' : 'collision')),
        'alive' => (int)$state['alive'] === 1,
    ];
}

/**
 * Seed + girdi listesini yeniden oynatır (sunucu otoritesi).
 * @param array<int,array{0:int,1:int}> $inputs sıkı artan tick'ler
 */
function mg_simulate(int $seed, array $inputs, array $options = []): array
{
    $c = mg_engine_config();
    $maxTicks = $c['maxTicks'];
    if (isset($options['maxTicks'])) {
        $maxTicks = max(1, min($c['maxTicks'], (int)$options['maxTicks']));
    }
    $state = mg_create_state($seed);
    $count = count($inputs);
    $idx = 0;
    $reason = 'time';

    while ((int)$state['tick'] < $maxTicks) {
        $action = MG_ACTION_NONE;
        while ($idx < $count && (int)($inputs[$idx][0] ?? -1) === (int)$state['tick']) {
            $action = (int)($inputs[$idx][1] ?? 0);
            $idx++;
        }
        $status = mg_engine_step($state, $action);
        if ($status === 'collision') {
            $reason = 'collision';
            break;
        }
        if ($status === 'time') {
            $reason = 'time';
            break;
        }
    }

    $result = mg_result_of($state);
    $result['reason'] = $reason;
    $result['alive'] = $reason === 'time';
    $result['inputsApplied'] = $idx;
    return $result;
}

/** Kanonik girdi metni — JS `encodeInputs` ile aynı biçim. */
function mg_encode_inputs(array $inputs): string
{
    $parts = [];
    foreach ($inputs as $pair) {
        $parts[] = (int)$pair[0] . ':' . (int)$pair[1];
    }
    return implode(',', $parts);
}

/** Kanonik metni girdi listesine çevirir; geçersizse null. */
function mg_decode_inputs(string $text): ?array
{
    if ($text === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $text) as $part) {
        $bits = explode(':', $part);
        if (count($bits) !== 2 || !ctype_digit($bits[0]) || !ctype_digit($bits[1])) {
            return null;
        }
        $out[] = [(int)$bits[0], (int)$bits[1]];
    }
    return $out;
}

/** Motor sürümü + fizik sabitlerinin özeti (koşuya sabitlenir). */
function mg_config_hash(): string
{
    return substr(hash('sha256', MG_ENGINE_VERSION . '|' . json_encode(mg_engine_config())), 0, 16);
}
