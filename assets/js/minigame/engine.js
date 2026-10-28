/**
 * Device Game — "Overcharge: Kaçış" oyun motoru (istemci kopyası) — v2.
 *
 * KURALLAR
 * - Sabit zaman adımı: 60 tick/sn. Fizik TAM SAYI (1 birim = 1/8 px).
 *   Kayan nokta kullanılmaz; JS ve PHP aynı sonucu üretmelidir.
 * - Rastgelelik: xorshift32. Seed sunucudan gelir. Düşman ateşi PRNG
 *   KULLANMAZ (sabit kadans + faz), böylece iki motor arasında sapma olmaz.
 * - Bu dosyanın birebir PHP karşılığı: lib/minigame/engine.php
 *   Eşlik testi: `node tests/fixtures/make-vectors.mjs` + `tests/php/engine_test.php`
 * - MOTOR DEĞİŞİRSE ENGINE_VERSION artırılmalıdır; eski sürümün skorları
 *   yeni sürümün tablosuna karışmaz (tablo anahtarı sürüm içerir).
 *
 * SAVAŞ
 * - Ateş: blaster (şarjlı, rejenerasyonlu) ve plasma (düşmandan düşer).
 * - Eğilme: hitbox 40 px → 22 px; alçak engelin altından geçiş, yüksek
 *   mermilerden kaçınma. Eğilirken zıplanmaz.
 * - Dash: kısa dokunulmazlık (dodge), bekleme süreli.
 * - Can: 100 HP, 0.75 sn dokunulmazlık; tamir kiti ve kalkan düşer.
 * - Düşmanlar: muhafız (temas), drone (yüksek ateş), taret (alçak seri ateş),
 *   3 fazlı boss (60. saniyede girer).
 */

export const ENGINE_VERSION = 'v2';

export const ACTIONS = Object.freeze({
    NONE: 0,
    JUMP: 1,
    RELEASE: 2,
    FIRE: 3,
    CROUCH: 4,
    STAND: 5,
    DASH: 6,
});

export const WEAPONS = Object.freeze({
    blaster: Object.freeze({ damage: 12, mag: 8, regen: 40, speed: 60, pierce: 1, regenAmmo: 1 }),
    plasma: Object.freeze({ damage: 30, mag: 12, regen: 0, speed: 72, pierce: 2, regenAmmo: 0 }),
});

export const CONFIG = Object.freeze({
    version: ENGINE_VERSION,
    tickHz: 60,
    maxTicks: 10800,       // 180 sn güvenlik sınırı (tur 2–3 dk)

    unitsPerPx: 8,
    playerX: 960,          // dünya koordinatında sabit konum (120 px)
    playerW: 224,          // 28 px
    playerH: 320,          // 40 px (ayakta)
    crouchH: 176,          // 22 px (eğilmiş)
    playerInsetX: 14,
    playerInsetY: 10,

    gravity: 3,
    jumpV: 46,
    cutV: 18,
    maxFall: -72,
    coyoteTicks: 4,

    speedBase: 34,
    speedStep: 3,
    speedStepTicks: 600,
    speedMax: 76,

    spawnLookahead: 3400,
    firstSpawnX: 2600,
    gapTicksBase: 92,
    gapTicksMin: 56,
    gapTicksTierDrop: 2,
    gapJitter: 22,

    cellSize: 80,
    cellSpacing: 150,
    cellScore: 15,
    scoreDistDiv: 160,
    killScore: 40,
    bossScore: 500,

    // Can / savunma
    maxHp: 100,
    invulnTicks: 45,
    contactDamage: 12,
    enemyBulletDamage: 8,
    dashTicks: 12,
    dashCooldownTicks: 180,

    // Mermiler
    enemyBulletSpeed: 22,
    bulletMaxLife: 300,
    bulletPruneBack: 900,
    bulletPruneAhead: 4400,

    // Düşmanlar
    ceilingH: 220,         // baş üstü kirişin alt kenarı (eğilerek geçilir)
    ceilingWMin: 140,
    ceilingWMax: 260,

    enemyFirstX: 3000,
    enemyGapBase: 2800,
    enemyGapJitter: 2000,
    enemyLookahead: 3400,

    guardHp: 30, guardW: 140, guardH: 200,
    droneHp: 20, droneW: 120, droneH: 90, droneBaseY: 300, droneBulletY: 280,
    turretHp: 40, turretW: 130, turretH: 180,

    droneFireGap: 84,
    droneFirstFire: 90,
    turretFireGap: 64,
    turretBurstGap: 12,
    turretBurstCount: 2,

    bossHp: 150,
    bossW: 320,
    bossH: 520,
    bossOffsetX: 3600,
    bossStartTick: 3600,
    bossFireGap: 48,
    bossFirstFire: 40,
    bossPhase2: 66,        // yüzde
    bossPhase3: 33,

    // Toplama
    repairAmount: 25,
    plasmaDropAmmo: 12,

    // Görsel tuval (mantıksal piksel)
    viewW: 960,
    viewH: 540,
    groundY: 420,
});

/** xorshift32 — PHP tarafıyla birebir aynı. */
function prngNext(state) {
    let x = state.prng;
    x ^= (x << 13); x >>>= 0;
    x ^= (x >>> 17); x >>>= 0;
    x ^= (x << 5); x >>>= 0;
    state.prng = x;
    return x;
}

/** Zorluk kademesi 0–14. */
export function tierAt(tick) {
    return Math.min(14, Math.floor(tick / CONFIG.speedStepTicks));
}

/** tick'teki dünya hızı (birim/tick). */
export function speedAt(tick) {
    return Math.min(CONFIG.speedMax, CONFIG.speedBase + tierAt(tick) * CONFIG.speedStep);
}

/** Skor: mesafe + hücre + öldürme + boss. Tam sayı. */
export function scoreFor(distance, cells, kills, bossKilled) {
    return Math.floor(distance / CONFIG.scoreDistDiv)
        + cells * CONFIG.cellScore
        + (kills | 0) * CONFIG.killScore
        + (bossKilled ? CONFIG.bossScore : 0);
}

export function normalizeSeed(seed) {
    const n = Number(seed) >>> 0;
    return n === 0 ? 0x9E3779B9 : n;
}

export function createState(seed) {
    const s = normalizeSeed(seed);
    return {
        version: ENGINE_VERSION,
        seed: s,
        prng: s,
        tick: 0,
        distance: 0,
        speed: CONFIG.speedBase,
        y: 0,
        vy: 0,
        grounded: 1,
        coyote: CONFIG.coyoteTicks,
        crouch: 0,
        jumps: 0,

        hp: CONFIG.maxHp,
        invuln: 0,
        shield: 0,
        dashUntil: 0,
        dashReadyAt: 0,

        weapon: 'blaster',
        shots: WEAPONS.blaster.mag,
        regenAt: 0,

        bullets: [],
        enemies: [],
        obstacles: [],
        ceilings: [],
        cellsList: [],
        pickups: [],
        boss: null,
        bossSpawned: 0,

        nextSpawnX: CONFIG.firstSpawnX,
        nextEnemyX: CONFIG.enemyFirstX,

        cells: 0,
        missed: 0,
        kills: 0,
        bossKilled: 0,
        damageTaken: 0,
        shotsFired: 0,

        alive: 1,
        reason: '',
    };
}

/* ---------------- dünya üretimi ---------------- */

function cellArc(state, startX, height) {
    const count = 3 + (prngNext(state) % 3);
    for (let i = 0; i < count; i++) {
        state.cellsList.push({ x: startX + i * CONFIG.cellSpacing, y: height, taken: 0 });
    }
}

/**
 * Tek engel grubu üretir. Gruplar arası boşluk, o anki hıza göre TICK
 * cinsinden hesaplanır: yüksek hızda da tepki süresi aynı kalır.
 */
function spawnGroup(state) {
    const x = state.nextSpawnX;
    const tier = tierAt(state.tick);
    const speed = state.speed;
    const pattern = prngNext(state) % 6;
    const maxH = Math.min(240, 120 + tier * 9);
    let endX = x + 160;

    if (pattern === 0) {
        const w = 120 + (prngNext(state) % 90);
        const h = 70 + (prngNext(state) % (maxH - 70 + 1));
        state.obstacles.push({ x: x, w: w, h: h });
        endX = x + w;
        if ((prngNext(state) % 100) < 55) {
            cellArc(state, x + 30, h + 150 + (prngNext(state) % 80));
        }
    } else if (pattern === 1) {
        const w = 100;
        const gap = 70 + (prngNext(state) % 40);
        const h1 = 90 + (prngNext(state) % (maxH - 90 + 1));
        const h2 = Math.min(maxH, h1 + (prngNext(state) % 40));
        state.obstacles.push({ x: x, w: w, h: h1 });
        state.obstacles.push({ x: x + w + gap, w: w, h: h2 });
        endX = x + w + gap + w;
        if ((prngNext(state) % 100) < 45) {
            cellArc(state, x + 40, Math.max(h1, h2) + 160 + (prngNext(state) % 60));
        }
    } else if (pattern === 2) {
        const w = 90;
        const h1 = Math.min(maxH, 100 + (prngNext(state) % 40));
        const h2 = Math.min(maxH, h1 + 40 + (prngNext(state) % 30));
        const h3 = Math.min(maxH, h2 + 30 + (prngNext(state) % 30));
        state.obstacles.push({ x: x, w: w, h: h1 });
        state.obstacles.push({ x: x + w, w: w, h: h2 });
        state.obstacles.push({ x: x + w * 2, w: w, h: h3 });
        endX = x + w * 3;
    } else if (pattern === 3) {
        // Alçak ve geniş duvar: eğilerek altından geçilir (zıplamak zor).
        const w = 240 + (prngNext(state) % 70);
        const h = 70 + (prngNext(state) % (70 + Math.min(30, tier * 3)));
        state.obstacles.push({ x: x, w: w, h: h });
        endX = x + w;
        if ((prngNext(state) % 100) < 50) {
            cellArc(state, x + 30, h + 140 + (prngNext(state) % 70));
        }
    } else if (pattern === 4) {
        // Baş üstü kiriş: EĞİLEREK geçilir (zıplamak çarpar).
        const w = CONFIG.ceilingWMin + (prngNext(state) % (CONFIG.ceilingWMax - CONFIG.ceilingWMin + 1));
        state.ceilings.push({ x: x, w: w, y: CONFIG.ceilingH });
        endX = x + w;
        if ((prngNext(state) % 100) < 60) {
            cellArc(state, x + 20, 40 + (prngNext(state) % 60));
        }
    } else {
        cellArc(state, x, 240 + (prngNext(state) % 140));
        endX = x + 360;
    }

    const gapTicks = Math.max(CONFIG.gapTicksMin, CONFIG.gapTicksBase - tier * CONFIG.gapTicksTierDrop)
        + (prngNext(state) % CONFIG.gapJitter);
    state.nextSpawnX = endX + speed * gapTicks;
}

/** Düşman üretir: muhafız (0), drone (1), taret (2). */
function spawnEnemy(state) {
    const x = state.nextEnemyX;
    const kind = prngNext(state) % 3;
    if (kind === 0) {
        state.enemies.push({
            type: 'guard', x: x, y: 0, w: CONFIG.guardW, h: CONFIG.guardH,
            hp: CONFIG.guardHp, maxHp: CONFIG.guardHp, nextFire: 0, burst: 0, phase: 1,
        });
    } else if (kind === 1) {
        state.enemies.push({
            type: 'drone', x: x, y: CONFIG.droneBaseY, w: CONFIG.droneW, h: CONFIG.droneH,
            hp: CONFIG.droneHp, maxHp: CONFIG.droneHp,
            nextFire: state.tick + CONFIG.droneFirstFire, burst: 0, phase: 1,
        });
    } else {
        state.enemies.push({
            type: 'turret', x: x, y: 0, w: CONFIG.turretW, h: CONFIG.turretH,
            hp: CONFIG.turretHp, maxHp: CONFIG.turretHp,
            nextFire: state.tick + CONFIG.turretFireGap, burst: 0, phase: 1,
        });
    }
    state.nextEnemyX = x + CONFIG.enemyGapBase + (prngNext(state) % CONFIG.enemyGapJitter);
}

function spawnBoss(state) {
    state.boss = {
        type: 'boss',
        x: state.distance + CONFIG.bossOffsetX,
        y: 0,
        w: CONFIG.bossW,
        h: CONFIG.bossH,
        hp: CONFIG.bossHp,
        maxHp: CONFIG.bossHp,
        nextFire: state.tick + CONFIG.bossFirstFire,
        burst: 0,
        phase: 1,
    };
    state.bossSpawned = 1;
}

/* ---------------- geometri ---------------- */

export function playerBox(state) {
    const height = state.crouch === 1 && state.grounded === 1 ? CONFIG.crouchH : CONFIG.playerH;
    const x1 = state.distance + CONFIG.playerX + CONFIG.playerInsetX;
    return {
        x1: x1,
        x2: x1 + CONFIG.playerW - CONFIG.playerInsetX * 2,
        y1: state.y + CONFIG.playerInsetY,
        y2: state.y + height,
    };
}

function overlaps(ax1, ax2, ay1, ay2, bx1, bx2, by1, by2) {
    return ax1 < bx2 && bx1 < ax2 && ay1 < by2 && by1 < ay2;
}

/* ---------------- aksiyonlar ---------------- */

function tryStand(state) {
    // Ayağa kalkmak bir sonraki tick'te çakışmaya yol açacaksa eğik kal.
    // (Kontrol, bu tick ilerledikten sonraki konuma göre yapılır.)
    const x1 = state.distance + CONFIG.playerX + CONFIG.playerInsetX;
    const x2 = x1 + CONFIG.playerW - CONFIG.playerInsetX * 2 + state.speed;
    for (let i = 0; i < state.obstacles.length; i++) {
        const o = state.obstacles[i];
        if (o.x >= x2) break;
        if (o.x + o.w <= x1) continue;
        if (o.h > CONFIG.crouchH) return false;
    }
    for (let i = 0; i < state.ceilings.length; i++) {
        const cl = state.ceilings[i];
        if (cl.x >= x2) break;
        if (cl.x + cl.w <= x1) continue;
        return false;
    }
    return true;
}

function fire(state) {
    const weapon = WEAPONS[state.weapon] || WEAPONS.blaster;
    if (state.shots <= 0) return;
    const box = playerBox(state);
    state.shots -= 1;
    state.shotsFired += 1;
    state.bullets.push({
        own: 1,
        x: box.x2,
        y: Math.floor((box.y1 + box.y2) / 2),
        speed: weapon.speed,
        dmg: weapon.damage,
        pierce: weapon.pierce,
        life: CONFIG.bulletMaxLife,
        hit: 0,
    });
    if (weapon.regen > 0 && state.regenAt === 0) {
        state.regenAt = state.tick + weapon.regen;
    }
}

function applyAction(state, action) {
    if (action === ACTIONS.JUMP) {
        if (state.crouch === 1) return;
        if (state.grounded === 1 || state.coyote > 0) {
            state.vy = CONFIG.jumpV;
            state.grounded = 0;
            state.coyote = 0;
            state.jumps += 1;
        }
    } else if (action === ACTIONS.RELEASE) {
        if (state.vy > CONFIG.cutV) state.vy = CONFIG.cutV;
    } else if (action === ACTIONS.FIRE) {
        fire(state);
    } else if (action === ACTIONS.CROUCH) {
        if (state.grounded === 1) state.crouch = 1;
    } else if (action === ACTIONS.STAND) {
        if (state.crouch === 1 && tryStand(state)) state.crouch = 0;
    } else if (action === ACTIONS.DASH) {
        if (state.tick >= state.dashReadyAt) {
            state.dashUntil = state.tick + CONFIG.dashTicks;
            state.dashReadyAt = state.tick + CONFIG.dashCooldownTicks;
        }
    }
}

/* ---------------- hasar ---------------- */

function damagePlayer(state, amount) {
    if (state.alive !== 1 || state.invuln > 0) return false;
    if (state.dashUntil > state.tick) return false;
    state.invuln = CONFIG.invulnTicks;
    if (state.shield === 1) {
        state.shield = 0;
        return true;
    }
    state.hp -= amount;
    state.damageTaken += amount;
    if (state.hp <= 0) {
        state.hp = 0;
        state.alive = 0;
        state.reason = 'hp';
    }
    return true;
}

function damageEnemy(state, enemy, amount) {
    if (enemy.hp <= 0) return;
    enemy.hp -= amount;
    if (enemy.hp <= 0) {
        enemy.hp = 0;
        state.kills += 1;
        // Düşen eşya (deterministik: göreli koordinattan türetilir, PRNG'siz)
        const roll = (enemy.x + enemy.maxHp + state.kills * 7) % 100;
        if (roll < 20) {
            state.pickups.push({ x: enemy.x + 30, y: 140, kind: 'repair', taken: 0 });
        } else if (roll < 34) {
            state.pickups.push({ x: enemy.x + 30, y: 140, kind: 'shield', taken: 0 });
        } else if (roll < 44) {
            state.pickups.push({ x: enemy.x + 30, y: 140, kind: 'plasma', taken: 0 });
        }
    }
}

/* ---------------- tek tick ---------------- */

/**
 * Tek tick ilerletir. `action` o tick'in fiziğinden ÖNCE uygulanır.
 * @returns {'running'|'hp'|'time'}
 */
export function step(state, action) {
    if (!state.alive) return 'hp';

    applyAction(state, action);

    state.speed = speedAt(state.tick);
    state.distance += state.speed;

    if (state.grounded === 1) {
        state.y = 0;
        state.vy = 0;
        state.coyote = CONFIG.coyoteTicks;
    } else {
        state.vy -= CONFIG.gravity;
        if (state.vy < CONFIG.maxFall) state.vy = CONFIG.maxFall;
        state.y += state.vy;
        if (state.y <= 0) {
            state.y = 0;
            state.vy = 0;
            state.grounded = 1;
            state.coyote = CONFIG.coyoteTicks;
        } else if (state.coyote > 0) {
            state.coyote -= 1;
        }
    }
    if (state.grounded === 0) state.crouch = 0;

    // Şarj / cephane yenilenmesi
    const weapon = WEAPONS[state.weapon] || WEAPONS.blaster;
    if (weapon.regen > 0 && state.shots < weapon.mag) {
        if (state.regenAt === 0) {
            state.regenAt = state.tick + weapon.regen;
        } else if (state.tick >= state.regenAt) {
            state.shots += 1;
            state.regenAt = state.shots < weapon.mag ? state.tick + weapon.regen : 0;
        }
    }

    if (state.invuln > 0) state.invuln -= 1;

    const playerWorldX = state.distance + CONFIG.playerX;
    let guard = 0;
    while (state.nextSpawnX < playerWorldX + CONFIG.spawnLookahead && guard < 8) {
        spawnGroup(state);
        guard += 1;
    }
    guard = 0;
    while (state.nextEnemyX < playerWorldX + CONFIG.enemyLookahead && guard < 4) {
        spawnEnemy(state);
        guard += 1;
    }
    if (state.bossSpawned === 0 && state.tick >= CONFIG.bossStartTick) {
        spawnBoss(state);
    }
    if (state.boss !== null) {
        state.boss.x = state.distance + CONFIG.bossOffsetX;
    }

    // Geride kalanları temizle
    const pruneBefore = playerWorldX - 2400;
    if (state.obstacles.length && state.obstacles[0].x + state.obstacles[0].w < pruneBefore) {
        state.obstacles = state.obstacles.filter((o) => o.x + o.w >= pruneBefore);
    }
    if (state.ceilings.length && state.ceilings[0].x + state.ceilings[0].w < pruneBefore) {
        state.ceilings = state.ceilings.filter((c) => c.x + c.w >= pruneBefore);
    }
    if (state.cellsList.length && state.cellsList[0].x < pruneBefore) {
        state.cellsList = state.cellsList.filter((c) => c.x >= pruneBefore);
    }
    if (state.pickups.length && state.pickups[0].x < pruneBefore) {
        state.pickups = state.pickups.filter((p) => p.x >= pruneBefore);
    }
    if (state.enemies.length && state.enemies[0].x + state.enemies[0].w < pruneBefore) {
        state.enemies = state.enemies.filter((e) => e.x + e.w >= pruneBefore);
    }

    const p = playerBox(state);

    /* --- engel çarpışması --- */
    for (let i = 0; i < state.obstacles.length; i++) {
        const o = state.obstacles[i];
        if (o.x > p.x2) break;
        if (o.x + o.w <= p.x1) continue;
        if (p.y1 < o.h && p.y2 > 0) {
            damagePlayer(state, CONFIG.contactDamage);
            if (state.alive !== 1) return 'hp';
        }
    }

    /* --- baş üstü kiriş: eğilerek geçilir --- */
    for (let i = 0; i < state.ceilings.length; i++) {
        const cl = state.ceilings[i];
        if (cl.x > p.x2) break;
        if (cl.x + cl.w <= p.x1) continue;
        if (p.y2 > cl.y) {
            damagePlayer(state, CONFIG.contactDamage);
            if (state.alive !== 1) return 'hp';
        }
    }

    /* --- düşman ateşi --- */
    const bulletBack = playerWorldX - CONFIG.bulletPruneBack;
    const bulletAhead = playerWorldX + CONFIG.bulletPruneAhead;

    for (let i = 0; i < state.enemies.length; i++) {
        const e = state.enemies[i];
        if (e.hp <= 0) continue;
        const ahead = e.x - p.x2;
        if (ahead > 1900 || ahead < 120) continue;

        if (e.type === 'drone') {
            if (e.nextFire === 0) e.nextFire = state.tick + CONFIG.droneFirstFire;
            if (state.tick >= e.nextFire) {
                e.nextFire = state.tick + CONFIG.droneFireGap;
                state.bullets.push({
                    own: 0, x: e.x, y: CONFIG.droneBulletY,
                    speed: CONFIG.enemyBulletSpeed, dmg: CONFIG.enemyBulletDamage,
                    pierce: 1, life: CONFIG.bulletMaxLife, hit: 0,
                });
            }
        } else if (e.type === 'turret') {
            if (e.nextFire === 0) e.nextFire = state.tick + CONFIG.turretFireGap;
            if (state.tick >= e.nextFire) {
                state.bullets.push({
                    own: 0, x: e.x, y: 150,
                    speed: CONFIG.enemyBulletSpeed, dmg: CONFIG.enemyBulletDamage,
                    pierce: 1, life: CONFIG.bulletMaxLife, hit: 0,
                });
                e.burst += 1;
                if (e.burst >= CONFIG.turretBurstCount) {
                    e.burst = 0;
                    e.nextFire = state.tick + CONFIG.turretFireGap;
                } else {
                    e.nextFire = state.tick + CONFIG.turretBurstGap;
                }
            }
        }
    }

    /* --- boss --- */
    if (state.boss !== null && state.boss.hp > 0) {
        const b = state.boss;
        const pct = Math.floor((b.hp * 100) / b.maxHp);
        b.phase = pct > CONFIG.bossPhase2 ? 1 : (pct > CONFIG.bossPhase3 ? 2 : 3);
        if (state.tick >= b.nextFire) {
            b.nextFire = state.tick + CONFIG.bossFireGap;
            const y = b.phase === 1 ? 150 : (b.phase === 2 ? 280 : (b.burst % 2 === 0 ? 150 : 280));
            b.burst += 1;
            state.bullets.push({
                own: 0, x: b.x, y: y,
                speed: CONFIG.enemyBulletSpeed, dmg: CONFIG.enemyBulletDamage,
                pierce: 1, life: CONFIG.bulletMaxLife, hit: 0,
            });
            if (b.phase === 2 && b.burst % 3 === 0) {
                state.bullets.push({
                    own: 0, x: b.x, y: 150,
                    speed: CONFIG.enemyBulletSpeed, dmg: CONFIG.enemyBulletDamage,
                    pierce: 1, life: CONFIG.bulletMaxLife, hit: 0,
                });
            }
        }
    }

    /* --- mermiler --- */
    const surviving = [];
    for (let i = 0; i < state.bullets.length; i++) {
        const b = state.bullets[i];
        if (b.life <= 0) continue;
        b.life -= 1;
        if (b.own === 1) {
            b.x += b.speed + state.speed;
        } else {
            b.x += state.speed - b.speed;
        }
        if (b.x < bulletBack || b.x > bulletAhead) continue;

        if (b.own === 1) {
            let consumed = 0;
            for (let j = 0; j < state.enemies.length; j++) {
                const e = state.enemies[j];
                if (e.hp <= 0) continue;
                if (b.x < e.x || b.x > e.x + e.w) continue;
                if (b.y < e.y || b.y > e.y + e.h) continue;
                damageEnemy(state, e, b.dmg);
                consumed += 1;
                if (consumed >= b.pierce) break;
            }
            if (state.boss !== null && state.boss.hp > 0 && consumed < b.pierce) {
                const bb = state.boss;
                if (b.x >= bb.x && b.x <= bb.x + bb.w && b.y >= bb.y && b.y <= bb.y + bb.h) {
                    damageBoss(state, bb, b.dmg);
                    consumed += 1;
                }
            }
            if (consumed > 0) {
                b.hit = 1;
                continue;
            }
            surviving.push(b);
        } else {
            if (b.x >= p.x1 && b.x <= p.x2 && b.y >= p.y1 && b.y <= p.y2) {
                damagePlayer(state, b.dmg);
                continue;
            }
            surviving.push(b);
        }
    }
    state.bullets = surviving;

    /* --- düşman teması --- */
    for (let i = 0; i < state.enemies.length; i++) {
        const e = state.enemies[i];
        if (e.hp <= 0) continue;
        if (e.type === 'drone') continue;
        if (overlaps(p.x1, p.x2, p.y1, p.y2, e.x, e.x + e.w, e.y, e.y + e.h)) {
            damagePlayer(state, CONFIG.contactDamage);
        }
    }
    if (state.boss !== null && state.boss.hp > 0) {
        const bb = state.boss;
        if (overlaps(p.x1, p.x2, p.y1, p.y2, bb.x, bb.x + bb.w, bb.y, bb.y + bb.h)) {
            damagePlayer(state, CONFIG.contactDamage);
        }
    }

    /* --- enerji hücreleri --- */
    const half = Math.floor(CONFIG.cellSize / 2);
    for (let i = 0; i < state.cellsList.length; i++) {
        const c = state.cellsList[i];
        if (c.taken !== 0) continue;
        if (c.x + half < p.x1) {
            c.taken = -1;
            state.missed += 1;
            continue;
        }
        if (c.x - half > p.x2) break;
        if (p.y1 < c.y + half && c.y - half < p.y2) {
            c.taken = 1;
            state.cells += 1;
        }
    }

    /* --- düşen eşyalar --- */
    for (let i = 0; i < state.pickups.length; i++) {
        const it = state.pickups[i];
        if (it.taken !== 0) continue;
        const size = 60;
        if (it.x + size < p.x1) continue;
        if (it.x - size > p.x2) break;
        if (!(p.y1 < it.y + size && it.y - size < p.y2)) continue;
        it.taken = 1;
        if (it.kind === 'repair') {
            state.hp = Math.min(CONFIG.maxHp, state.hp + CONFIG.repairAmount);
        } else if (it.kind === 'shield') {
            state.shield = 1;
        } else if (it.kind === 'plasma') {
            state.weapon = 'plasma';
            state.shots = CONFIG.plasmaDropAmmo;
            state.regenAt = 0;
        }
    }
    if (state.pickups.length) {
        state.pickups = state.pickups.filter((it) => it.taken === 0);
    }

    // Plasma bitti → blaster'a dön
    if (state.weapon === 'plasma' && state.shots <= 0) {
        state.weapon = 'blaster';
        state.shots = WEAPONS.blaster.mag;
        state.regenAt = 0;
    }

    if (state.alive !== 1) return 'hp';

    state.tick += 1;
    if (state.tick >= CONFIG.maxTicks) {
        state.alive = 0;
        state.reason = 'time';
        return 'time';
    }
    return 'running';
}

function damageBoss(state, boss, amount) {
    if (boss.hp <= 0) return;
    boss.hp -= amount;
    if (boss.hp <= 0) {
        boss.hp = 0;
        state.bossKilled = 1;
        state.kills += 1;
        state.pickups.push({ x: boss.x + 60, y: 120, kind: 'repair', taken: 0 });
    }
}

export function resultOf(state) {
    return {
        version: ENGINE_VERSION,
        seed: state.seed,
        ticks: state.tick,
        distance: state.distance,
        cells: state.cells,
        cellsMissed: state.missed,
        cellsSeen: state.cells + state.missed,
        jumps: state.jumps,
        kills: state.kills,
        bossKilled: state.bossKilled,
        damageTaken: state.damageTaken,
        hp: state.hp,
        shotsFired: state.shotsFired,
        bossMaxHp: CONFIG.bossHp,
        bossHpLeft: state.boss !== null ? state.boss.hp : 0,
        score: scoreFor(state.distance, state.cells, state.kills, state.bossKilled === 1),
        reason: state.reason || (state.alive === 1 ? 'time' : 'hp'),
        alive: state.alive === 1,
    };
}

/** Girdileri tick'e göre uygulayarak koşuyu baştan oynatır. */
export function simulate(seed, inputs, options) {
    const opts = options || {};
    const maxTicks = Math.max(1, Math.min(CONFIG.maxTicks, opts.maxTicks || CONFIG.maxTicks));
    const state = createState(seed);
    const list = Array.isArray(inputs) ? inputs : [];
    let idx = 0;
    let reason = 'time';

    while (state.tick < maxTicks) {
        let action = ACTIONS.NONE;
        while (idx < list.length && (list[idx][0] | 0) === state.tick) {
            action = list[idx][1] | 0;
            idx += 1;
        }
        const status = step(state, action);
        if (status === 'hp') { reason = 'hp'; break; }
        if (status === 'time') { reason = 'time'; break; }
    }

    const result = resultOf(state);
    result.reason = reason;
    result.alive = reason === 'time';
    result.inputsApplied = idx;
    return result;
}

/** Kanonik girdi metni (PHP aynı biçimi üretir). */
export function encodeInputs(inputs) {
    if (!Array.isArray(inputs) || !inputs.length) return '';
    return inputs.map((pair) => (pair[0] | 0) + ':' + (pair[1] | 0)).join(',');
}

/** Kanonik metni girdi listesine çevirir (geçersizse null). */
export function decodeInputs(text) {
    if (typeof text !== 'string' || text === '') return [];
    const out = [];
    for (const part of text.split(',')) {
        const bits = part.split(':');
        if (bits.length !== 2) return null;
        const t = Number(bits[0]);
        const a = Number(bits[1]);
        if (!Number.isInteger(t) || !Number.isInteger(a)) return null;
        out.push([t, a]);
    }
    return out;
}

export function configHashParts() {
    return [
        ENGINE_VERSION, CONFIG.tickHz, CONFIG.maxTicks, CONFIG.gravity, CONFIG.jumpV,
        CONFIG.speedBase, CONFIG.speedMax, CONFIG.maxHp, CONFIG.bossHp, CONFIG.bossStartTick,
    ].join('|');
}
