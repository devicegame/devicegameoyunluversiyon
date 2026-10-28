<?php
/**
 * Device Game — "Overcharge: Kaçış" oyun motoru (sunucu kopyası) — v2.
 *
 * KURALLAR (istemci motoruyla BİREBİR aynı olmalıdır)
 * - Sabit zaman adımı: 60 tick/sn. Fizik TAM SAYI (1 birim = 1/8 px).
 *   Kayan nokta kullanılmaz.
 * - Rastgelelik: xorshift32, seed sunucudan gelir. Düşman ateşi PRNG
 *   kullanmaz (sabit kadans + faz) → iki motor arasında sapma olmaz.
 * - Karşılık: assets/js/minigame/engine.js
 * - Eşlik testi: tests/fixtures/make-vectors.mjs + tests/php/engine_test.php
 *
 * MOTOR DEĞİŞİRSE MG_ENGINE_VERSION artırılmalıdır; sıralama anahtarı sürüm
 * içerdiği için eski skorlar yeni tabloya karışmaz.
 */
declare(strict_types=1);

const MG_ENGINE_VERSION = 'v2';

const MG_ACTION_NONE = 0;
const MG_ACTION_JUMP = 1;
const MG_ACTION_RELEASE = 2;
const MG_ACTION_FIRE = 3;
const MG_ACTION_CROUCH = 4;
const MG_ACTION_STAND = 5;
const MG_ACTION_DASH = 6;

/** Geçerli aksiyonlar (girdi doğrulaması bunu kullanır). */
function mg_valid_actions(): array
{
    return [MG_ACTION_JUMP, MG_ACTION_RELEASE, MG_ACTION_FIRE, MG_ACTION_CROUCH, MG_ACTION_STAND, MG_ACTION_DASH];
}

/** Silah tanımları (JS `WEAPONS` ile aynı). */
function mg_weapons(): array
{
    return [
        'blaster' => ['damage' => 12, 'mag' => 8, 'regen' => 40, 'speed' => 60, 'pierce' => 1, 'regenAmmo' => 1],
        'plasma' => ['damage' => 30, 'mag' => 12, 'regen' => 0, 'speed' => 72, 'pierce' => 2, 'regenAmmo' => 0],
    ];
}

function mg_weapon(string $name): array
{
    $all = mg_weapons();
    return $all[$name] ?? $all['blaster'];
}

/**
 * Motor yapılandırması. Sunucu yeniden oynatması BUNU kullanır; istemcinin
 * bildirdiği yapılandırma dikkate alınmaz.
 */
function mg_engine_config(): array
{
    return [
        'version' => MG_ENGINE_VERSION,
        'tickHz' => 60,
        'maxTicks' => 10800,

        'unitsPerPx' => 8,
        'playerX' => 960,
        'playerW' => 224,
        'playerH' => 320,
        'crouchH' => 176,
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
        'gapTicksBase' => 92,
        'gapTicksMin' => 56,
        'gapTicksTierDrop' => 2,
        'gapJitter' => 22,

        'cellSize' => 80,
        'cellSpacing' => 150,
        'cellScore' => 15,
        'scoreDistDiv' => 160,
        'killScore' => 40,
        'bossScore' => 500,

        'maxHp' => 100,
        'invulnTicks' => 45,
        'contactDamage' => 12,
        'enemyBulletDamage' => 8,
        'dashTicks' => 12,
        'dashCooldownTicks' => 180,

        'enemyBulletSpeed' => 22,
        'bulletMaxLife' => 300,
        'bulletPruneBack' => 900,
        'bulletPruneAhead' => 4400,

        'ceilingH' => 220,
        'ceilingWMin' => 140,
        'ceilingWMax' => 260,

        'enemyFirstX' => 3000,
        'enemyGapBase' => 2800,
        'enemyGapJitter' => 2000,
        'enemyLookahead' => 3400,

        'guardHp' => 30, 'guardW' => 140, 'guardH' => 200,
        'droneHp' => 20, 'droneW' => 120, 'droneH' => 90, 'droneBaseY' => 300, 'droneBulletY' => 280,
        'turretHp' => 40, 'turretW' => 130, 'turretH' => 180,

        'droneFireGap' => 84,
        'droneFirstFire' => 90,
        'turretFireGap' => 64,
        'turretBurstGap' => 12,
        'turretBurstCount' => 2,

        'bossHp' => 150,
        'bossW' => 320,
        'bossH' => 520,
        'bossOffsetX' => 3600,
        'bossStartTick' => 3600,
        'bossFireGap' => 48,
        'bossFirstFire' => 40,
        'bossPhase2' => 66,
        'bossPhase3' => 33,

        'repairAmount' => 25,
        'plasmaDropAmmo' => 12,

        'viewW' => 960,
        'viewH' => 540,
        'groundY' => 420,
    ];
}

/** xorshift32 — JS tarafıyla birebir aynı. */
function mg_prng_next(array &$state): int
{
    $x = (int)$state['prng'] & 0xFFFFFFFF;
    $x ^= ($x << 13) & 0xFFFFFFFF;
    $x ^= ($x >> 17);
    $x ^= ($x << 5) & 0xFFFFFFFF;
    $x &= 0xFFFFFFFF;
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

/** Skor: mesafe + hücre + öldürme + boss. Tam sayı. */
function mg_score_for(int $distance, int $cells, int $kills = 0, bool $bossKilled = false): int
{
    $c = mg_engine_config();
    return intdiv($distance, $c['scoreDistDiv'])
        + $cells * $c['cellScore']
        + $kills * $c['killScore']
        + ($bossKilled ? $c['bossScore'] : 0);
}

function mg_normalize_seed(int $seed): int
{
    $n = $seed & 0xFFFFFFFF;
    return $n === 0 ? 0x9E3779B9 : $n;
}

function mg_create_state(int $seed): array
{
    $c = mg_engine_config();
    $s = mg_normalize_seed($seed);
    return [
        'version' => MG_ENGINE_VERSION,
        'seed' => $s,
        'prng' => $s,
        'tick' => 0,
        'distance' => 0,
        'speed' => $c['speedBase'],
        'y' => 0,
        'vy' => 0,
        'grounded' => 1,
        'coyote' => $c['coyoteTicks'],
        'crouch' => 0,
        'jumps' => 0,

        'hp' => $c['maxHp'],
        'invuln' => 0,
        'shield' => 0,
        'dashUntil' => 0,
        'dashReadyAt' => 0,

        'weapon' => 'blaster',
        'shots' => mg_weapon('blaster')['mag'],
        'regenAt' => 0,

        'bullets' => [],
        'enemies' => [],
        'obstacles' => [],
        'ceilings' => [],
        'cellsList' => [],
        'pickups' => [],
        'boss' => null,
        'bossSpawned' => 0,

        'nextSpawnX' => $c['firstSpawnX'],
        'nextEnemyX' => $c['enemyFirstX'],

        'cells' => 0,
        'missed' => 0,
        'kills' => 0,
        'bossKilled' => 0,
        'damageTaken' => 0,
        'shotsFired' => 0,

        'alive' => 1,
        'reason' => '',
    ];
}

/* ---------------- dünya üretimi ---------------- */

function mg_cell_arc(array &$state, int $startX, int $height): void
{
    $c = mg_engine_config();
    $count = 3 + (mg_prng_next($state) % 3);
    for ($i = 0; $i < $count; $i++) {
        $state['cellsList'][] = ['x' => $startX + $i * $c['cellSpacing'], 'y' => $height, 'taken' => 0];
    }
}

function mg_spawn_group(array &$state): void
{
    $c = mg_engine_config();
    $x = (int)$state['nextSpawnX'];
    $tier = mg_tier_at((int)$state['tick']);
    $speed = (int)$state['speed'];
    $pattern = mg_prng_next($state) % 6;
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
    } elseif ($pattern === 4) {
        $w = $c['ceilingWMin'] + (mg_prng_next($state) % ($c['ceilingWMax'] - $c['ceilingWMin'] + 1));
        $state['ceilings'][] = ['x' => $x, 'w' => $w, 'y' => $c['ceilingH']];
        $endX = $x + $w;
        if ((mg_prng_next($state) % 100) < 60) {
            mg_cell_arc($state, $x + 20, 40 + (mg_prng_next($state) % 60));
        }
    } else {
        mg_cell_arc($state, $x, 240 + (mg_prng_next($state) % 140));
        $endX = $x + 360;
    }

    $gapTicks = max($c['gapTicksMin'], $c['gapTicksBase'] - $tier * $c['gapTicksTierDrop'])
        + (mg_prng_next($state) % $c['gapJitter']);
    $state['nextSpawnX'] = $endX + $speed * $gapTicks;
}

function mg_spawn_enemy(array &$state): void
{
    $c = mg_engine_config();
    $x = (int)$state['nextEnemyX'];
    $kind = mg_prng_next($state) % 3;
    if ($kind === 0) {
        $state['enemies'][] = [
            'type' => 'guard', 'x' => $x, 'y' => 0, 'w' => $c['guardW'], 'h' => $c['guardH'],
            'hp' => $c['guardHp'], 'maxHp' => $c['guardHp'], 'nextFire' => 0, 'burst' => 0, 'phase' => 1,
        ];
    } elseif ($kind === 1) {
        $state['enemies'][] = [
            'type' => 'drone', 'x' => $x, 'y' => $c['droneBaseY'], 'w' => $c['droneW'], 'h' => $c['droneH'],
            'hp' => $c['droneHp'], 'maxHp' => $c['droneHp'],
            'nextFire' => (int)$state['tick'] + $c['droneFirstFire'], 'burst' => 0, 'phase' => 1,
        ];
    } else {
        $state['enemies'][] = [
            'type' => 'turret', 'x' => $x, 'y' => 0, 'w' => $c['turretW'], 'h' => $c['turretH'],
            'hp' => $c['turretHp'], 'maxHp' => $c['turretHp'],
            'nextFire' => (int)$state['tick'] + $c['turretFireGap'], 'burst' => 0, 'phase' => 1,
        ];
    }
    $state['nextEnemyX'] = $x + $c['enemyGapBase'] + (mg_prng_next($state) % $c['enemyGapJitter']);
}

function mg_spawn_boss(array &$state): void
{
    $c = mg_engine_config();
    $state['boss'] = [
        'type' => 'boss',
        'x' => (int)$state['distance'] + $c['bossOffsetX'],
        'y' => 0,
        'w' => $c['bossW'],
        'h' => $c['bossH'],
        'hp' => $c['bossHp'],
        'maxHp' => $c['bossHp'],
        'nextFire' => (int)$state['tick'] + $c['bossFirstFire'],
        'burst' => 0,
        'phase' => 1,
    ];
    $state['bossSpawned'] = 1;
}

/* ---------------- geometri ---------------- */

function mg_player_box(array $state): array
{
    $c = mg_engine_config();
    $height = ((int)$state['crouch'] === 1 && (int)$state['grounded'] === 1) ? $c['crouchH'] : $c['playerH'];
    $x1 = (int)$state['distance'] + $c['playerX'] + $c['playerInsetX'];
    return [
        'x1' => $x1,
        'x2' => $x1 + $c['playerW'] - $c['playerInsetX'] * 2,
        'y1' => (int)$state['y'] + $c['playerInsetY'],
        'y2' => (int)$state['y'] + $height,
    ];
}

function mg_overlaps(int $ax1, int $ax2, int $ay1, int $ay2, int $bx1, int $bx2, int $by1, int $by2): bool
{
    return $ax1 < $bx2 && $bx1 < $ax2 && $ay1 < $by2 && $by1 < $ay2;
}

/* ---------------- aksiyonlar ---------------- */

function mg_try_stand(array $state): bool
{
    $c = mg_engine_config();
    $x1 = (int)$state['distance'] + $c['playerX'] + $c['playerInsetX'];
    $x2 = $x1 + $c['playerW'] - $c['playerInsetX'] * 2 + (int)$state['speed'];
    foreach ($state['obstacles'] as $o) {
        if ($o['x'] >= $x2) {
            break;
        }
        if ($o['x'] + $o['w'] <= $x1) {
            continue;
        }
        if ($o['h'] > $c['crouchH']) {
            return false;
        }
    }
    foreach ($state['ceilings'] as $cl) {
        if ($cl['x'] >= $x2) {
            break;
        }
        if ($cl['x'] + $cl['w'] <= $x1) {
            continue;
        }
        return false;
    }
    return true;
}

function mg_player_fire(array &$state): void
{
    if ((int)$state['shots'] <= 0) {
        return;
    }
    $c = mg_engine_config();
    $weapon = mg_weapon((string)$state['weapon']);
    $box = mg_player_box($state);
    $state['shots'] = (int)$state['shots'] - 1;
    $state['shotsFired'] = (int)$state['shotsFired'] + 1;
    $state['bullets'][] = [
        'own' => 1,
        'x' => $box['x2'],
        'y' => intdiv($box['y1'] + $box['y2'], 2),
        'speed' => $weapon['speed'],
        'dmg' => $weapon['damage'],
        'pierce' => $weapon['pierce'],
        'life' => $c['bulletMaxLife'],
        'hit' => 0,
    ];
    if ($weapon['regen'] > 0 && (int)$state['regenAt'] === 0) {
        $state['regenAt'] = (int)$state['tick'] + $weapon['regen'];
    }
}

function mg_apply_action(array &$state, int $action): void
{
    $c = mg_engine_config();
    if ($action === MG_ACTION_JUMP) {
        if ((int)$state['crouch'] === 1) {
            return;
        }
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
    } elseif ($action === MG_ACTION_FIRE) {
        mg_player_fire($state);
    } elseif ($action === MG_ACTION_CROUCH) {
        if ((int)$state['grounded'] === 1) {
            $state['crouch'] = 1;
        }
    } elseif ($action === MG_ACTION_STAND) {
        if ((int)$state['crouch'] === 1 && mg_try_stand($state)) {
            $state['crouch'] = 0;
        }
    } elseif ($action === MG_ACTION_DASH) {
        if ((int)$state['tick'] >= (int)$state['dashReadyAt']) {
            $state['dashUntil'] = (int)$state['tick'] + $c['dashTicks'];
            $state['dashReadyAt'] = (int)$state['tick'] + $c['dashCooldownTicks'];
        }
    }
}

/* ---------------- hasar ---------------- */

function mg_damage_player(array &$state, int $amount): bool
{
    $c = mg_engine_config();
    if ((int)$state['alive'] !== 1 || (int)$state['invuln'] > 0) {
        return false;
    }
    if ((int)$state['dashUntil'] > (int)$state['tick']) {
        return false;
    }
    $state['invuln'] = $c['invulnTicks'];
    if ((int)$state['shield'] === 1) {
        $state['shield'] = 0;
        return true;
    }
    $state['hp'] = (int)$state['hp'] - $amount;
    $state['damageTaken'] = (int)$state['damageTaken'] + $amount;
    if ((int)$state['hp'] <= 0) {
        $state['hp'] = 0;
        $state['alive'] = 0;
        $state['reason'] = 'hp';
    }
    return true;
}

function mg_damage_enemy(array &$state, int $index, int $amount): void
{
    if ((int)$state['enemies'][$index]['hp'] <= 0) {
        return;
    }
    $enemy = &$state['enemies'][$index];
    $enemy['hp'] = (int)$enemy['hp'] - $amount;
    if ((int)$enemy['hp'] > 0) {
        return;
    }
    $enemy['hp'] = 0;
    $state['kills'] = (int)$state['kills'] + 1;
    $roll = ((int)$enemy['x'] + (int)$enemy['maxHp'] + (int)$state['kills'] * 7) % 100;
    if ($roll < 20) {
        $state['pickups'][] = ['x' => (int)$enemy['x'] + 30, 'y' => 140, 'kind' => 'repair', 'taken' => 0];
    } elseif ($roll < 34) {
        $state['pickups'][] = ['x' => (int)$enemy['x'] + 30, 'y' => 140, 'kind' => 'shield', 'taken' => 0];
    } elseif ($roll < 44) {
        $state['pickups'][] = ['x' => (int)$enemy['x'] + 30, 'y' => 140, 'kind' => 'plasma', 'taken' => 0];
    }
}

function mg_damage_boss(array &$state, int $amount): void
{
    $boss = &$state['boss'];
    if ($boss === null || (int)$boss['hp'] <= 0) {
        return;
    }
    $boss['hp'] = (int)$boss['hp'] - $amount;
    if ((int)$boss['hp'] > 0) {
        return;
    }
    $boss['hp'] = 0;
    $state['bossKilled'] = 1;
    $state['kills'] = (int)$state['kills'] + 1;
    $state['pickups'][] = ['x' => (int)$boss['x'] + 60, 'y' => 120, 'kind' => 'repair', 'taken' => 0];
}

/* ---------------- tek tick ---------------- */

/**
 * Tek tick ilerletir. Aksiyon o tick'in fiziğinden ÖNCE uygulanır.
 * @return string 'running'|'hp'|'time'
 */
function mg_engine_step(array &$state, int $action): string
{
    if ((int)$state['alive'] !== 1) {
        return 'hp';
    }
    $c = mg_engine_config();

    mg_apply_action($state, $action);

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
    if ((int)$state['grounded'] === 0) {
        $state['crouch'] = 0;
    }

    $weapon = mg_weapon((string)$state['weapon']);
    if ($weapon['regen'] > 0 && (int)$state['shots'] < $weapon['mag']) {
        if ((int)$state['regenAt'] === 0) {
            $state['regenAt'] = (int)$state['tick'] + $weapon['regen'];
        } elseif ((int)$state['tick'] >= (int)$state['regenAt']) {
            $state['shots'] = (int)$state['shots'] + 1;
            $state['regenAt'] = (int)$state['shots'] < $weapon['mag']
                ? (int)$state['tick'] + $weapon['regen']
                : 0;
        }
    }

    if ((int)$state['invuln'] > 0) {
        $state['invuln'] = (int)$state['invuln'] - 1;
    }

    $playerWorldX = (int)$state['distance'] + $c['playerX'];
    $guard = 0;
    while ((int)$state['nextSpawnX'] < $playerWorldX + $c['spawnLookahead'] && $guard < 8) {
        mg_spawn_group($state);
        $guard++;
    }
    $guard = 0;
    while ((int)$state['nextEnemyX'] < $playerWorldX + $c['enemyLookahead'] && $guard < 4) {
        mg_spawn_enemy($state);
        $guard++;
    }
    if ((int)$state['bossSpawned'] === 0 && (int)$state['tick'] >= $c['bossStartTick']) {
        mg_spawn_boss($state);
    }
    if ($state['boss'] !== null) {
        $state['boss']['x'] = (int)$state['distance'] + $c['bossOffsetX'];
    }

    $pruneBefore = $playerWorldX - 2400;
    if ($state['obstacles'] && $state['obstacles'][0]['x'] + $state['obstacles'][0]['w'] < $pruneBefore) {
        $state['obstacles'] = array_values(array_filter(
            $state['obstacles'],
            static fn(array $o): bool => $o['x'] + $o['w'] >= $pruneBefore
        ));
    }
    if ($state['ceilings'] && $state['ceilings'][0]['x'] + $state['ceilings'][0]['w'] < $pruneBefore) {
        $state['ceilings'] = array_values(array_filter(
            $state['ceilings'],
            static fn(array $cl): bool => $cl['x'] + $cl['w'] >= $pruneBefore
        ));
    }
    if ($state['cellsList'] && $state['cellsList'][0]['x'] < $pruneBefore) {
        $state['cellsList'] = array_values(array_filter(
            $state['cellsList'],
            static fn(array $cell): bool => $cell['x'] >= $pruneBefore
        ));
    }
    if ($state['pickups'] && $state['pickups'][0]['x'] < $pruneBefore) {
        $state['pickups'] = array_values(array_filter(
            $state['pickups'],
            static fn(array $it): bool => $it['x'] >= $pruneBefore
        ));
    }
    if ($state['enemies'] && $state['enemies'][0]['x'] + $state['enemies'][0]['w'] < $pruneBefore) {
        $state['enemies'] = array_values(array_filter(
            $state['enemies'],
            static fn(array $e): bool => $e['x'] + $e['w'] >= $pruneBefore
        ));
    }

    $p = mg_player_box($state);

    /* --- engel çarpışması: hasar (ölüm değil) --- */
    foreach ($state['obstacles'] as $o) {
        if ($o['x'] > $p['x2']) {
            break;
        }
        if ($o['x'] + $o['w'] <= $p['x1']) {
            continue;
        }
        if ($p['y1'] < $o['h'] && $p['y2'] > 0) {
            mg_damage_player($state, $c['contactDamage']);
            if ((int)$state['alive'] !== 1) {
                return 'hp';
            }
        }
    }

    /* --- baş üstü kiriş: eğilerek geçilir --- */
    foreach ($state['ceilings'] as $cl) {
        if ($cl['x'] > $p['x2']) {
            break;
        }
        if ($cl['x'] + $cl['w'] <= $p['x1']) {
            continue;
        }
        if ($p['y2'] > $cl['y']) {
            mg_damage_player($state, $c['contactDamage']);
            if ((int)$state['alive'] !== 1) {
                return 'hp';
            }
        }
    }

    /* --- düşman ateşi --- */
    $bulletBack = $playerWorldX - $c['bulletPruneBack'];
    $bulletAhead = $playerWorldX + $c['bulletPruneAhead'];

    foreach ($state['enemies'] as $i => $e) {
        if ((int)$e['hp'] <= 0) {
            continue;
        }
        $ahead = (int)$e['x'] - $p['x2'];
        if ($ahead > 1900 || $ahead < 120) {
            continue;
        }
        if ($e['type'] === 'drone') {
            if ((int)$e['nextFire'] === 0) {
                $state['enemies'][$i]['nextFire'] = (int)$state['tick'] + $c['droneFirstFire'];
            }
            if ((int)$state['tick'] >= (int)$state['enemies'][$i]['nextFire']) {
                $state['enemies'][$i]['nextFire'] = (int)$state['tick'] + $c['droneFireGap'];
                $state['bullets'][] = [
                    'own' => 0, 'x' => (int)$e['x'], 'y' => $c['droneBulletY'],
                    'speed' => $c['enemyBulletSpeed'], 'dmg' => $c['enemyBulletDamage'],
                    'pierce' => 1, 'life' => $c['bulletMaxLife'], 'hit' => 0,
                ];
            }
        } elseif ($e['type'] === 'turret') {
            if ((int)$e['nextFire'] === 0) {
                $state['enemies'][$i]['nextFire'] = (int)$state['tick'] + $c['turretFireGap'];
            }
            if ((int)$state['tick'] >= (int)$state['enemies'][$i]['nextFire']) {
                $state['bullets'][] = [
                    'own' => 0, 'x' => (int)$e['x'], 'y' => 150,
                    'speed' => $c['enemyBulletSpeed'], 'dmg' => $c['enemyBulletDamage'],
                    'pierce' => 1, 'life' => $c['bulletMaxLife'], 'hit' => 0,
                ];
                $state['enemies'][$i]['burst'] = (int)$state['enemies'][$i]['burst'] + 1;
                if ((int)$state['enemies'][$i]['burst'] >= $c['turretBurstCount']) {
                    $state['enemies'][$i]['burst'] = 0;
                    $state['enemies'][$i]['nextFire'] = (int)$state['tick'] + $c['turretFireGap'];
                } else {
                    $state['enemies'][$i]['nextFire'] = (int)$state['tick'] + $c['turretBurstGap'];
                }
            }
        }
    }

    /* --- boss --- */
    if ($state['boss'] !== null && (int)$state['boss']['hp'] > 0) {
        $pct = intdiv((int)$state['boss']['hp'] * 100, (int)$state['boss']['maxHp']);
        $state['boss']['phase'] = $pct > $c['bossPhase2'] ? 1 : ($pct > $c['bossPhase3'] ? 2 : 3);
        if ((int)$state['tick'] >= (int)$state['boss']['nextFire']) {
            $burst = (int)$state['boss']['burst'];
            $state['boss']['nextFire'] = (int)$state['tick'] + $c['bossFireGap'];
            $phase = (int)$state['boss']['phase'];
            $y = $phase === 1 ? 150 : ($phase === 2 ? 280 : ($burst % 2 === 0 ? 150 : 280));
            $state['boss']['burst'] = $burst + 1;
            $state['bullets'][] = [
                'own' => 0, 'x' => (int)$state['boss']['x'], 'y' => $y,
                'speed' => $c['enemyBulletSpeed'], 'dmg' => $c['enemyBulletDamage'],
                'pierce' => 1, 'life' => $c['bulletMaxLife'], 'hit' => 0,
            ];
            if ($phase === 2 && ($burst + 1) % 3 === 0) {
                $state['bullets'][] = [
                    'own' => 0, 'x' => (int)$state['boss']['x'], 'y' => 150,
                    'speed' => $c['enemyBulletSpeed'], 'dmg' => $c['enemyBulletDamage'],
                    'pierce' => 1, 'life' => $c['bulletMaxLife'], 'hit' => 0,
                ];
            }
        }
    }

    /* --- mermiler --- */
    $surviving = [];
    foreach ($state['bullets'] as $b) {
        if ((int)$b['life'] <= 0) {
            continue;
        }
        $b['life'] = (int)$b['life'] - 1;
        if ((int)$b['own'] === 1) {
            $b['x'] = (int)$b['x'] + (int)$b['speed'] + (int)$state['speed'];
        } else {
            $b['x'] = (int)$b['x'] + (int)$state['speed'] - (int)$b['speed'];
        }
        if ((int)$b['x'] < $bulletBack || (int)$b['x'] > $bulletAhead) {
            continue;
        }

        if ((int)$b['own'] === 1) {
            $consumed = 0;
            foreach ($state['enemies'] as $j => $e) {
                if ((int)$e['hp'] <= 0) {
                    continue;
                }
                if ((int)$b['x'] < (int)$e['x'] || (int)$b['x'] > (int)$e['x'] + (int)$e['w']) {
                    continue;
                }
                if ((int)$b['y'] < (int)$e['y'] || (int)$b['y'] > (int)$e['y'] + (int)$e['h']) {
                    continue;
                }
                mg_damage_enemy($state, $j, (int)$b['dmg']);
                $consumed++;
                if ($consumed >= (int)$b['pierce']) {
                    break;
                }
            }
            if ($state['boss'] !== null && (int)$state['boss']['hp'] > 0 && $consumed < (int)$b['pierce']) {
                $bb = $state['boss'];
                if ((int)$b['x'] >= (int)$bb['x'] && (int)$b['x'] <= (int)$bb['x'] + (int)$bb['w']
                    && (int)$b['y'] >= (int)$bb['y'] && (int)$b['y'] <= (int)$bb['y'] + (int)$bb['h']) {
                    mg_damage_boss($state, (int)$b['dmg']);
                    $consumed++;
                }
            }
            if ($consumed > 0) {
                continue;
            }
            $surviving[] = $b;
        } else {
            if ((int)$b['x'] >= $p['x1'] && (int)$b['x'] <= $p['x2']
                && (int)$b['y'] >= $p['y1'] && (int)$b['y'] <= $p['y2']) {
                mg_damage_player($state, (int)$b['dmg']);
                continue;
            }
            $surviving[] = $b;
        }
    }
    $state['bullets'] = $surviving;

    /* --- düşman teması --- */
    foreach ($state['enemies'] as $e) {
        if ((int)$e['hp'] <= 0 || $e['type'] === 'drone') {
            continue;
        }
        if (mg_overlaps(
            $p['x1'], $p['x2'], $p['y1'], $p['y2'],
            (int)$e['x'], (int)$e['x'] + (int)$e['w'], (int)$e['y'], (int)$e['y'] + (int)$e['h']
        )) {
            mg_damage_player($state, $c['contactDamage']);
        }
    }
    if ($state['boss'] !== null && (int)$state['boss']['hp'] > 0) {
        $bb = $state['boss'];
        if (mg_overlaps(
            $p['x1'], $p['x2'], $p['y1'], $p['y2'],
            (int)$bb['x'], (int)$bb['x'] + (int)$bb['w'], (int)$bb['y'], (int)$bb['y'] + (int)$bb['h']
        )) {
            mg_damage_player($state, $c['contactDamage']);
        }
    }

    /* --- enerji hücreleri --- */
    $half = intdiv($c['cellSize'], 2);
    foreach ($state['cellsList'] as $i => $cell) {
        if ((int)$cell['taken'] !== 0) {
            continue;
        }
        if ((int)$cell['x'] + $half < $p['x1']) {
            $state['cellsList'][$i]['taken'] = -1;
            $state['missed'] = (int)$state['missed'] + 1;
            continue;
        }
        if ((int)$cell['x'] - $half > $p['x2']) {
            break;
        }
        if ($p['y1'] < (int)$cell['y'] + $half && (int)$cell['y'] - $half < $p['y2']) {
            $state['cellsList'][$i]['taken'] = 1;
            $state['cells'] = (int)$state['cells'] + 1;
        }
    }

    /* --- düşen eşyalar --- */
    foreach ($state['pickups'] as $i => $it) {
        if ((int)$it['taken'] !== 0) {
            continue;
        }
        $size = 60;
        if ((int)$it['x'] + $size < $p['x1']) {
            continue;
        }
        if ((int)$it['x'] - $size > $p['x2']) {
            break;
        }
        if (!($p['y1'] < (int)$it['y'] + $size && (int)$it['y'] - $size < $p['y2'])) {
            continue;
        }
        $state['pickups'][$i]['taken'] = 1;
        if ($it['kind'] === 'repair') {
            $state['hp'] = min($c['maxHp'], (int)$state['hp'] + $c['repairAmount']);
        } elseif ($it['kind'] === 'shield') {
            $state['shield'] = 1;
        } elseif ($it['kind'] === 'plasma') {
            $state['weapon'] = 'plasma';
            $state['shots'] = $c['plasmaDropAmmo'];
            $state['regenAt'] = 0;
        }
    }
    if ($state['pickups']) {
        $state['pickups'] = array_values(array_filter(
            $state['pickups'],
            static fn(array $it): bool => (int)$it['taken'] === 0
        ));
    }

    if ($state['weapon'] === 'plasma' && (int)$state['shots'] <= 0) {
        $state['weapon'] = 'blaster';
        $state['shots'] = mg_weapon('blaster')['mag'];
        $state['regenAt'] = 0;
    }

    if ((int)$state['alive'] !== 1) {
        return 'hp';
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
    $c = mg_engine_config();
    $bossHp = $state['boss'] !== null ? (int)$state['boss']['hp'] : 0;
    return [
        'version' => MG_ENGINE_VERSION,
        'seed' => (int)$state['seed'],
        'ticks' => (int)$state['tick'],
        'distance' => (int)$state['distance'],
        'cells' => (int)$state['cells'],
        'cellsMissed' => (int)$state['missed'],
        'cellsSeen' => (int)$state['cells'] + (int)$state['missed'],
        'jumps' => (int)$state['jumps'],
        'kills' => (int)$state['kills'],
        'bossKilled' => (int)$state['bossKilled'],
        'damageTaken' => (int)$state['damageTaken'],
        'hp' => (int)$state['hp'],
        'shotsFired' => (int)$state['shotsFired'],
        'bossMaxHp' => $c['bossHp'],
        'bossHpLeft' => $bossHp,
        'score' => mg_score_for(
            (int)$state['distance'],
            (int)$state['cells'],
            (int)$state['kills'],
            (int)$state['bossKilled'] === 1
        ),
        'reason' => (string)($state['reason'] !== '' ? $state['reason'] : ((int)$state['alive'] === 1 ? 'time' : 'hp')),
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
        if ($status === 'hp') {
            $reason = 'hp';
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

/** Yapılandırma özeti (koşu sırasında değişen ayarları yakalar). */
function mg_config_hash(): string
{
    $c = mg_engine_config();
    $parts = [
        MG_ENGINE_VERSION, $c['tickHz'], $c['maxTicks'], $c['gravity'], $c['jumpV'],
        $c['speedBase'], $c['speedMax'], $c['maxHp'], $c['bossHp'], $c['bossStartTick'],
    ];
    return substr(hash('sha256', implode('|', array_map('strval', $parts))), 0, 16);
}
