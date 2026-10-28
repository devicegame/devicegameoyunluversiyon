/**
 * Device Game — "Overcharge: Kaçış" oyun motoru (istemci kopyası).
 *
 * KURALLAR
 * - Sabit zaman adımı: 60 tick/sn. Fizik TAM SAYI (1 birim = 1/8 px).
 *   Kayan nokta kullanılmaz; JS ve PHP aynı sonucu üretmelidir.
 * - Rastgelelik: xorshift32. Seed sunucudan gelir.
 * - Bu dosyanın birebir PHP karşılığı: lib/minigame/engine.php
 *   Eşlik testi: `node tools/minigame-vectors.mjs` + `tests/php/engine_test.php`
 * - MOTOR DEĞİŞİRSE ENGINE_VERSION artırılmalıdır; eski sürümün skorları
 *   yeni sürümün tablosuna karışmaz (tablo anahtarı sürüm içerir).
 */

export const ENGINE_VERSION = 'v1';

export const ACTIONS = Object.freeze({ NONE: 0, JUMP: 1, RELEASE: 2 });

export const CONFIG = Object.freeze({
    version: ENGINE_VERSION,
    tickHz: 60,
    maxTicks: 7200,        // 120 sn güvenlik sınırı (normal tur 1–2 dk)

    unitsPerPx: 8,
    playerX: 960,          // dünya koordinatında sabit konum (120 px)
    playerW: 224,          // 28 px
    playerH: 320,          // 40 px
    playerInsetX: 14,      // affedici hitbox
    playerInsetY: 10,

    gravity: 3,            // birim/tick^2
    jumpV: 46,             // ilk zıplama hızı
    cutV: 18,              // bırakınca kısa zıplama
    maxFall: -72,
    coyoteTicks: 4,

    speedBase: 34,
    speedStep: 3,
    speedStepTicks: 600,   // her 10 sn bir kademe
    speedMax: 76,

    spawnLookahead: 3400,
    firstSpawnX: 2600,
    gapTicksBase: 50,
    gapTicksMin: 32,
    gapTicksTierDrop: 1,
    gapJitter: 14,

    cellSize: 80,
    cellSpacing: 150,
    cellScore: 15,
    scoreDistDiv: 160,

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

/** Skor: mesafe + enerji hücresi. Tam sayı. */
export function scoreFor(distance, cells) {
    return Math.floor(distance / CONFIG.scoreDistDiv) + cells * CONFIG.cellScore;
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
        jumps: 0,
        cells: 0,
        missed: 0,
        nextSpawnX: CONFIG.firstSpawnX,
        obstacles: [],
        cellsList: [],
        alive: 1,
        reason: '',
    };
}

function cellArc(state, startX, height) {
    const count = 3 + (prngNext(state) % 3);
    for (let i = 0; i < count; i++) {
        state.cellsList.push({ x: startX + i * CONFIG.cellSpacing, y: height, taken: 0 });
    }
}

/**
 * Tek engel grubu üretir. Gruplar arası boşluk, o anki hıza göre TICK
 * cinsinden hesaplanır: yüksek hızda da tepki süresi aynı kalır, zorluk
 * engel yüksekliği ve desen sıklığıyla artar.
 */
function spawnGroup(state) {
    const x = state.nextSpawnX;
    const tier = tierAt(state.tick);
    const speed = state.speed;
    const pattern = prngNext(state) % 5;
    const maxH = Math.min(240, 120 + tier * 9);
    let endX = x + 160;

    if (pattern === 0) {
        // Tek blok: genişlik sabit, yükseklik kademeyle artar (zamanlama daralır)
        const w = 120 + (prngNext(state) % 90);
        const h = 70 + (prngNext(state) % (maxH - 70 + 1));
        state.obstacles.push({ x: x, w: w, h: h });
        endX = x + w;
        if ((prngNext(state) % 100) < 55) {
            cellArc(state, x + 30, h + 150 + (prngNext(state) % 80));
        }
    } else if (pattern === 1) {
        // İkiz blok: tek zıplamayla geçilecek kadar yakın (toplam açıklık sınırlı)
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
        // Kademeli duvar: bitişik üç blok (tek zıplama, görsel basamak)
        const w = 90;
        const h1 = Math.min(maxH, 100 + (prngNext(state) % 40));
        const h2 = Math.min(maxH, h1 + 40 + (prngNext(state) % 30));
        const h3 = Math.min(maxH, h2 + 30 + (prngNext(state) % 30));
        state.obstacles.push({ x: x, w: w, h: h1 });
        state.obstacles.push({ x: x + w, w: w, h: h2 });
        state.obstacles.push({ x: x + w * 2, w: w, h: h3 });
        endX = x + w * 3;
    } else if (pattern === 3) {
        // Alçak ve geniş duvar: yüksek değil, genişliği zamanlamayı daraltır
        const w = 240 + (prngNext(state) % 70);
        const h = 70 + (prngNext(state) % (70 + Math.min(30, tier * 3)));
        state.obstacles.push({ x: x, w: w, h: h });
        endX = x + w;
        if ((prngNext(state) % 100) < 50) {
            cellArc(state, x + 30, h + 140 + (prngNext(state) % 70));
        }
    } else {
        // Nefes alma aralığı: sadece enerji yayı
        cellArc(state, x, 240 + (prngNext(state) % 140));
        endX = x + 360;
    }

    const gapTicks = Math.max(CONFIG.gapTicksMin, CONFIG.gapTicksBase - tier * CONFIG.gapTicksTierDrop)
        + (prngNext(state) % CONFIG.gapJitter);
    state.nextSpawnX = endX + speed * gapTicks;
}

function playerBox(state) {
    const x1 = state.distance + CONFIG.playerX + CONFIG.playerInsetX;
    return {
        x1: x1,
        x2: x1 + CONFIG.playerW - CONFIG.playerInsetX * 2,
        y1: state.y + CONFIG.playerInsetY,
        y2: state.y + CONFIG.playerH,
    };
}

/**
 * Tek tick ilerletir. `action` o tick'in fiziğinden ÖNCE uygulanır.
 * @returns {'running'|'collision'|'time'}
 */
export function step(state, action) {
    if (!state.alive) return 'collision';

    if (action === ACTIONS.JUMP) {
        if (state.grounded === 1 || state.coyote > 0) {
            state.vy = CONFIG.jumpV;
            state.grounded = 0;
            state.coyote = 0;
            state.jumps += 1;
        }
    } else if (action === ACTIONS.RELEASE) {
        if (state.vy > CONFIG.cutV) state.vy = CONFIG.cutV;
    }

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

    const playerWorldX = state.distance + CONFIG.playerX;
    let guard = 0;
    while (state.nextSpawnX < playerWorldX + CONFIG.spawnLookahead && guard < 8) {
        spawnGroup(state);
        guard += 1;
    }

    // Geride kalanları temizle (bellek/CPU)
    const pruneBefore = playerWorldX - 2400;
    if (state.obstacles.length && state.obstacles[0].x + state.obstacles[0].w < pruneBefore) {
        state.obstacles = state.obstacles.filter((o) => o.x + o.w >= pruneBefore);
    }
    if (state.cellsList.length && state.cellsList[0].x < pruneBefore) {
        state.cellsList = state.cellsList.filter((c) => c.x >= pruneBefore);
    }

    const p = playerBox(state);

    for (let i = 0; i < state.obstacles.length; i++) {
        const o = state.obstacles[i];
        if (o.x > p.x2) break;
        if (o.x + o.w <= p.x1) continue;
        if (p.y1 < o.h && p.y2 > 0) {
            state.alive = 0;
            state.reason = 'collision';
            return 'collision';
        }
    }

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

    state.tick += 1;
    if (state.tick >= CONFIG.maxTicks) {
        state.alive = 0;
        state.reason = 'time';
        return 'time';
    }
    return 'running';
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
        score: scoreFor(state.distance, state.cells),
        reason: state.reason || (state.alive === 1 ? 'time' : 'collision'),
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
        if (status === 'collision') { reason = 'collision'; break; }
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
