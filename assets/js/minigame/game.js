/**
 * Device Game — "Overcharge: Kaçış" sahne denetleyicisi (v2).
 *
 * SADECE sunum + girdi toplama yapar. Fizik, düşmanlar ve skor `engine.js`
 * içindedir (sunucuyla birebir aynı motor). Skor burada hesaplanmaz.
 *
 * Zamanlama: requestAnimationFrame yalnızca birikimi besler; simülasyon her
 * zaman sabit 1/60 sn adımlarla ilerler → 30/60/120 Hz ekranlarda oyun hızı
 * ve fizik aynıdır.
 *
 * GİRDİ MODELİ (v2)
 * Motor tick başına TEK aksiyon kabul eder. Bu yüzden tuş/dokunma olayları
 * "niyet" olarak tutulur ve her tick'te öncelik sırasına göre bire çevrilir:
 *   zıpla > dash > bırak > eğil/kalk > ateş
 * Kaydedilen girdi listesi tam olarak yerel simülasyona uygulanan listedir;
 * sunucu aynı listeyi aynı tick'lerde oynatır.
 */
import { ACTIONS, CONFIG, WEAPONS, createState, step, resultOf, scoreFor } from './engine.js';

const TICK = 1 / CONFIG.tickHz;
const PX = CONFIG.unitsPerPx;
const GROUND_Y = CONFIG.groundY;
const PLAYER_SCREEN_X = CONFIG.playerX / PX; // 120 px
const PLAYER_W = CONFIG.playerW / PX;        // 28 px
const PLAYER_H = CONFIG.playerH / PX;        // 40 px
const CROUCH_H = CONFIG.crouchH / PX;        // 22 px

const STAND_RETRY = 6;    // kiriş altında kalkma denemeleri arası tick

const DEFAULT_VISUAL = Object.freeze({
    body: 'runner',
    primary: '#c5f46f',
    secondary: '#1b152c',
    glow: 0.4,
    trail: '',
    trail_color: '#63f5e8',
    frame: '',
    frame_color: '#63f5e8',
});

const WEAPON_LABEL = { blaster: 'BLASTER', plasma: 'PLAZMA' };

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/**
 * @param {{canvas: HTMLCanvasElement, onEnd?: Function, onState?: Function, onCountdown?: Function, onEvent?: Function}} options
 */
export function createGame(options) {
    const canvas = options.canvas;
    const ctx = canvas.getContext('2d');
    const onEnd = options.onEnd || function () {};
    const onState = options.onState || function () {};
    const onCountdown = options.onCountdown || function () {};
    const onEvent = options.onEvent || function () {};

    const reduceQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    let state = 'idle';          // idle | countdown | running | paused | over
    let run = null;
    let inputs = [];
    let meta = null;
    let ranked = false;
    let acc = 0;
    let last = 0;
    let raf = 0;
    let countdownStart = 0;
    let countdownShown = -1;
    let visual = { ...DEFAULT_VISUAL };
    let muted = false;
    let reduced = reduceQuery.matches;
    let audio = null;
    let shake = 0;
    let flash = 0;
    let hurt = 0;
    let muzzles = [];
    const trailStack = [];
    let sparks = [];
    let idleT = 0;
    let dpr = 1;

    // Girdi niyetleri (olay anında işaretlenir, tick'te tüketilir).
    const intent = { jump: false, release: false, dash: false, crouch: false, fire: false, fireOnce: false };
    let standTryAt = -999;
    const touches = new Map(); // pointerId → 'jump' | 'crouch' | 'fire'

    function resetIntent() {
        intent.jump = false;
        intent.release = false;
        intent.dash = false;
        intent.crouch = false;
        intent.fire = false;
        intent.fireOnce = false;
        standTryAt = -999;
        touches.clear();
    }

    function applyDpr() {
        const next = (window.devicePixelRatio || 1) >= 1.5 ? 2 : 1;
        if (next !== dpr) dpr = next;
        const w = CONFIG.viewW * dpr;
        const h = CONFIG.viewH * dpr;
        if (canvas.width !== w || canvas.height !== h) {
            canvas.width = w;
            canvas.height = h;
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function beep(freq, duration, type, gainValue) {
        if (muted) return;
        try {
            if (!audio) {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                audio = new Ctx();
            }
            if (audio.state === 'suspended') audio.resume();
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.type = type || 'square';
            osc.frequency.value = freq;
            const now = audio.currentTime;
            const peak = gainValue || 0.05;
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(peak, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
            osc.connect(gain).connect(audio.destination);
            osc.start(now);
            osc.stop(now + duration + 0.02);
        } catch (e) {
            /* ses yoksa sessiz devam */
        }
    }

    function setState(next) {
        if (state === next) return;
        state = next;
        onState(state);
    }

    /* ---------------- yaşam döngüsü ---------------- */

    function start(payload) {
        const data = payload || {};
        meta = data.meta || null;
        ranked = !!data.ranked;
        run = createState((Number(data.seed) >>> 0) || 0x9E3779B9);
        inputs = [];
        resetIntent();
        acc = 0;
        countdownStart = performance.now();
        countdownShown = -1;
        sparks = [];
        muzzles = [];
        shake = 0;
        flash = 0;
        hurt = 0;
        onCountdown(3);
        setState('countdown');
        beep(520, 0.06, 'triangle');
    }

    function beginTicking() {
        setState('running');
        beep(720, 0.08, 'triangle');
    }

    function finish(reason) {
        if (!run) return;
        const result = resultOf(run);
        result.reason = reason || result.reason;
        const payload = {
            result,
            inputs: inputs.slice(),
            ranked,
            meta: meta || {},
        };
        setState('over');
        flash = 1;
        if (!reduced) shake = 14;
        beep(reason === 'hp' ? 110 : 640, reason === 'hp' ? 0.42 : 0.24, 'sawtooth', 0.07);
        onEvent({ type: reason === 'hp' ? 'down' : 'finish', result });
        onEnd(payload);
    }

    /* ---------------- simülasyon ---------------- */

    /**
     * Bu tick'te uygulanacak tek aksiyonu seçer. Öncelik: zıpla > dash >
     * bırak > eğil/kalk > ateş. Seçilen aksiyon kaydedilir.
     */
    function chooseAction() {
        if (!run) return ACTIONS.NONE;

        if (intent.jump) {
            if (run.crouch === 1) {
                // Eğikken zıplanmaz: önce kalk.
                intent.crouch = false;
                if (run.tick >= standTryAt) {
                    standTryAt = run.tick + STAND_RETRY;
                    return ACTIONS.STAND;
                }
            } else if (run.grounded === 1 || run.coyote > 0) {
                intent.jump = false;
                return ACTIONS.JUMP;
            }
        }

        if (intent.dash) {
            intent.dash = false;
            if (run.tick >= run.dashReadyAt) return ACTIONS.DASH;
        }

        if (intent.release) {
            intent.release = false;
            return ACTIONS.RELEASE;
        }

        if (intent.crouch) {
            if (run.crouch === 0 && run.grounded === 1) return ACTIONS.CROUCH;
        } else if (run.crouch === 1 && run.tick >= standTryAt) {
            standTryAt = run.tick + STAND_RETRY;
            return ACTIONS.STAND;
        }

        // Ateş: şarj varsa her tick'te bir atış. Toplam atış sayısı şarj +
        // yenilenme ile sınırlı olduğundan ayrı bir kadans gerekmez; bu, basılı
        // tutunca botla aynı DPS'i verir ve girdi sınırını aşmaz.
        if (intent.fireOnce) {
            intent.fireOnce = false;
            if (run.shots > 0) return ACTIONS.FIRE;
        }
        if (intent.fire && run.shots > 0) return ACTIONS.FIRE;
        return ACTIONS.NONE;
    }

    function stepOnce() {
        if (!run) return;
        const tick = run.tick;
        const action = chooseAction();
        const before = {
            cells: run.cells,
            kills: run.kills,
            damage: run.damageTaken,
            shots: run.shotsFired,
            boss: run.bossSpawned,
            bossKilled: run.bossKilled,
        };
        const status = step(run, action);
        if (action !== ACTIONS.NONE && inputs.length && inputs[inputs.length - 1][0] === tick) {
            inputs[inputs.length - 1][1] = action;
        } else if (action !== ACTIONS.NONE) {
            inputs.push([tick, action]);
        }

        if (action === ACTIONS.FIRE && run.shotsFired > before.shots) {
            muzzles.push({ t: 1 });
            beep(run.weapon === 'plasma' ? 300 : 620, 0.045, 'square', 0.035);
        }
        if (run.cells > before.cells) {
            beep(880 + run.cells * 4, 0.05, 'sine', 0.04);
            if (!reduced) {
                sparks.push({ x: PLAYER_SCREEN_X + PLAYER_W / 2, y: GROUND_Y - run.y / PX - PLAYER_H / 2, life: 1 });
            }
        }
        if (run.kills > before.kills) {
            beep(420, 0.07, 'triangle', 0.05);
        }
        if (run.damageTaken > before.damage) {
            beep(150, 0.16, 'sawtooth', 0.06);
            hurt = 1;
            if (!reduced) shake = Math.max(shake, 7);
        }
        if (run.bossSpawned === 1 && !before.boss) {
            beep(180, 0.4, 'sawtooth', 0.07);
            onEvent({ type: 'boss' });
        }
        if (run.bossKilled === 1 && before.bossKilled === 0) {
            flash = 1;
            beep(760, 0.3, 'triangle', 0.08);
            onEvent({ type: 'boss_down' });
        }

        if (status === 'time') {
            finish('time');
        } else if (status === 'hp') {
            finish('hp');
        } else if (!reduced && run.tick % 2 === 0 && visual.trail) {
            trailPush();
        }
    }

    function trailPush() {
        if (!run) return;
        trailStack.push({ x: PLAYER_SCREEN_X + 4, y: GROUND_Y - run.y / PX - (run.crouch === 1 ? CROUCH_H : PLAYER_H) + 8, life: 1 });
        if (trailStack.length > 30) trailStack.shift();
    }

    /**
     * Tek seferlik aksiyon (zıpla, dash, tek atış). Basılı tutma için
     * `setHold` kullanılır.
     */
    function requestAction(action) {
        if (state !== 'running' || !run) return false;
        if (action === ACTIONS.JUMP) {
            intent.jump = true;
            return true;
        }
        if (action === ACTIONS.RELEASE) {
            intent.release = true;
            return true;
        }
        if (action === ACTIONS.DASH) {
            intent.dash = true;
            return true;
        }
        if (action === ACTIONS.FIRE) {
            intent.fireOnce = true;
            return true;
        }
        if (action === ACTIONS.CROUCH) {
            intent.crouch = true;
            return true;
        }
        if (action === ACTIONS.STAND) {
            intent.crouch = false;
            return true;
        }
        return false;
    }

    /**
     * Basılı tutulan girdiler: 'fire' (otomatik ateş), 'crouch' (eğil).
     * Mobil düğmeler ve klavye aynı yolu kullanır.
     */
    function setHold(name, value) {
        if (state !== 'running' || !run) return;
        const on = !!value;
        if (name === 'fire') intent.fire = on;
        else if (name === 'crouch') intent.crouch = on;
    }

    /* ---------------- döngü ---------------- */

    function loop(now) {
        raf = requestAnimationFrame(loop);
        if (!last) last = now;
        let dt = (now - last) / 1000;
        last = now;
        if (dt > 0.25) dt = 0.25; // sekme dönüşünde sıçrama yok

        if (state === 'countdown') {
            const elapsed = (now - countdownStart) / 1000;
            const value = Math.max(0, 3 - Math.floor(elapsed));
            if (value !== countdownShown) {
                countdownShown = value;
                onCountdown(value);
                if (value > 0) beep(440, 0.08, 'triangle');
            }
            if (elapsed >= 3) {
                onCountdown(0);
                beginTicking();
            }
        } else if (state === 'running') {
            acc += dt;
            let guard = 0;
            while (acc >= TICK && state === 'running' && guard < 6) {
                acc -= TICK;
                stepOnce();
                guard += 1;
            }
            if (acc > TICK * 8) acc = 0;
        } else {
            idleT += dt;
        }

        if (shake > 0) shake = Math.max(0, shake - dt * 40);
        if (flash > 0) flash = Math.max(0, flash - dt * 3);
        if (hurt > 0) hurt = Math.max(0, hurt - dt * 1.6);
        for (const m of muzzles) m.t -= dt * 8;
        muzzles = muzzles.filter((m) => m.t > 0);
        if (state !== 'running') {
            for (const p of trailStack) p.life -= dt * 2;
            for (let i = trailStack.length - 1; i >= 0; i--) {
                if (trailStack[i].life <= 0) trailStack.splice(i, 1);
            }
        }
        for (const s of sparks) s.life -= dt * 2.2;
        sparks = sparks.filter((s) => s.life > 0);

        render(now / 1000);
    }

    /* ---------------- çizim: arka plan ---------------- */

    const stars = Array.from({ length: 64 }, (_, i) => {
        const a = (i * 2654435761) % 1000;
        const b = (i * 40503) % 1000;
        return { x: (a / 1000) * CONFIG.viewW, y: (b / 1000) * 280 + 20, r: 0.6 + (i % 3) * 0.5, speed: 0.08 + (i % 5) * 0.03 };
    });

    const skyline = Array.from({ length: 18 }, (_, i) => {
        const w = 54 + ((i * 97) % 84);
        return { x: i * 88, w, h: 54 + ((i * 137) % 140), lights: 2 + (i % 4) };
    });

    function drawBackground(distancePx, t) {
        const g = ctx.createLinearGradient(0, 0, 0, CONFIG.viewH);
        g.addColorStop(0, '#150d29');
        g.addColorStop(0.5, '#0e091c');
        g.addColorStop(1, '#070410');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, CONFIG.viewW, CONFIG.viewH);

        const twinkle = reduced ? 0.7 : null;
        for (const s of stars) {
            const x = ((s.x - distancePx * s.speed) % CONFIG.viewW + CONFIG.viewW) % CONFIG.viewW;
            ctx.globalAlpha = twinkle !== null ? twinkle : 0.35 + 0.35 * Math.sin(t * 2 + s.x);
            ctx.fillStyle = '#cbb8ff';
            ctx.beginPath();
            ctx.arc(x, s.y, s.r, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;

        const offset = (distancePx * 0.1) % 1584;
        for (const b of skyline) {
            const x = ((b.x - offset) % 1584 + 1584) % 1584 - 120;
            const y = GROUND_Y - b.h;
            ctx.fillStyle = '#191231';
            ctx.fillRect(x, y, b.w, b.h);
            ctx.fillStyle = 'rgba(197,244,111,0.15)';
            for (let l = 0; l < b.lights; l++) {
                ctx.fillRect(x + 8 + l * 18, y + 12, 5, 9);
            }
        }

        ctx.strokeStyle = 'rgba(150, 110, 220, 0.12)';
        ctx.lineWidth = 1;
        const gridOffset = (distancePx * 0.45) % 96;
        for (let x = -96; x < CONFIG.viewW + 96; x += 96) {
            ctx.beginPath();
            ctx.moveTo(x - gridOffset, GROUND_Y);
            ctx.lineTo((x - gridOffset - CONFIG.viewW / 2) * 0.4 + CONFIG.viewW / 2, 250);
            ctx.stroke();
        }
        for (let y = 260; y < GROUND_Y; y += 40) {
            ctx.globalAlpha = (y - 250) / (GROUND_Y - 250) * 0.8;
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(CONFIG.viewW, y);
            ctx.stroke();
        }
        ctx.globalAlpha = 1;

        const ground = ctx.createLinearGradient(0, GROUND_Y, 0, CONFIG.viewH);
        ground.addColorStop(0, '#1a1130');
        ground.addColorStop(1, '#0a0614');
        ctx.fillStyle = ground;
        ctx.fillRect(0, GROUND_Y, CONFIG.viewW, CONFIG.viewH - GROUND_Y);

        ctx.strokeStyle = 'rgba(197, 244, 111, 0.65)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(0, GROUND_Y);
        ctx.lineTo(CONFIG.viewW, GROUND_Y);
        ctx.stroke();

        ctx.strokeStyle = 'rgba(212, 173, 255, 0.16)';
        ctx.lineWidth = 2;
        const runOffset = distancePx % 120;
        for (let x = -120; x < CONFIG.viewW + 120; x += 120) {
            ctx.beginPath();
            ctx.moveTo(x - runOffset, GROUND_Y + 16);
            ctx.lineTo(x - runOffset + 44, GROUND_Y + 16);
            ctx.stroke();
        }
    }

    /* ---------------- çizim: dünya ---------------- */

    function drawRoundRect(x, y, w, h, r) {
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x, y, w, h, r);
        else ctx.rect(x, y, w, h);
    }

    function drawObstacles(current) {
        const distancePx = current.distance / PX;
        for (const o of current.obstacles) {
            const w = o.w / PX;
            const h = o.h / PX;
            const x = o.x / PX - distancePx;
            if (x + w < -60 || x > CONFIG.viewW + 60) continue;
            const y = GROUND_Y - h;
            const g = ctx.createLinearGradient(0, y, 0, GROUND_Y);
            g.addColorStop(0, '#3b2b5c');
            g.addColorStop(1, '#1b1330');
            ctx.fillStyle = g;
            ctx.fillRect(x, y, w, h);
            ctx.strokeStyle = 'rgba(248, 223, 121, 0.8)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.lineTo(x + w, y);
            ctx.stroke();
            ctx.strokeStyle = 'rgba(212, 173, 255, 0.14)';
            ctx.lineWidth = 1;
            for (let gy = y + 12; gy < GROUND_Y - 4; gy += 16) {
                ctx.beginPath();
                ctx.moveTo(x + 4, gy);
                ctx.lineTo(x + w - 4, gy);
                ctx.stroke();
            }
        }
    }

    /** Baş üstü kirişler: eğilerek geçilir. */
    function drawCeilings(current) {
        const distancePx = current.distance / PX;
        for (const cl of current.ceilings) {
            const w = cl.w / PX;
            const bottom = GROUND_Y - cl.y / PX;
            const x = cl.x / PX - distancePx;
            if (x + w < -60 || x > CONFIG.viewW + 60) continue;
            ctx.fillStyle = '#241a3d';
            ctx.fillRect(x, 0, w, bottom);
            ctx.fillStyle = 'rgba(248, 223, 121, 0.9)';
            ctx.fillRect(x, bottom - 5, w, 5);
            // tehlike çizgileri
            ctx.save();
            ctx.beginPath();
            ctx.rect(x, bottom - 16, w, 11);
            ctx.clip();
            ctx.strokeStyle = 'rgba(248, 223, 121, 0.4)';
            ctx.lineWidth = 4;
            for (let sx = x - 20; sx < x + w + 20; sx += 14) {
                ctx.beginPath();
                ctx.moveTo(sx, bottom - 3);
                ctx.lineTo(sx + 12, bottom - 17);
                ctx.stroke();
            }
            ctx.restore();
            ctx.strokeStyle = 'rgba(212, 173, 255, 0.25)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(x, bottom);
            ctx.lineTo(x + w, bottom);
            ctx.stroke();
        }
    }

    function drawCells(current, t) {
        const distancePx = current.distance / PX;
        const half = CONFIG.cellSize / 2 / PX;
        for (const c of current.cellsList) {
            if (c.taken !== 0) continue;
            const x = c.x / PX - distancePx;
            if (x < -40 || x > CONFIG.viewW + 40) continue;
            const y = GROUND_Y - c.y / PX;
            const pulse = reduced ? 0.9 : 0.7 + 0.3 * Math.sin(t * 6 + c.x * 0.01);
            ctx.save();
            ctx.translate(x, y);
            ctx.rotate(Math.PI / 4);
            ctx.shadowColor = 'rgba(99, 245, 232, 0.8)';
            ctx.shadowBlur = reduced ? 0 : 14 * pulse;
            ctx.fillStyle = '#63f5e8';
            const s = half * 1.3;
            ctx.fillRect(-s / 2, -s / 2, s, s);
            ctx.restore();
        }
    }

    function drawEnemies(current, t) {
        const distancePx = current.distance / PX;
        for (const e of current.enemies) {
            if (e.hp <= 0) continue;
            const w = e.w / PX;
            const h = e.h / PX;
            const x = e.x / PX - distancePx;
            if (x + w < -80 || x > CONFIG.viewW + 80) continue;
            const ground = GROUND_Y - e.y / PX;
            const y = ground - h;

            if (e.type === 'drone') {
                const bob = reduced ? 0 : Math.sin(t * 3 + e.x * 0.01) * 2;
                ctx.save();
                ctx.shadowColor = 'rgba(255, 107, 173, 0.7)';
                ctx.shadowBlur = reduced ? 0 : 12;
                ctx.fillStyle = '#2a1b46';
                ctx.strokeStyle = '#ff6bad';
                ctx.lineWidth = 2;
                drawRoundRect(x, y + bob, w, h, 6);
                ctx.fill();
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(x - 3, y + bob + h / 2);
                ctx.lineTo(x + w + 3, y + bob + h / 2);
                ctx.stroke();
                ctx.fillStyle = '#ff9ec9';
                ctx.fillRect(x + w / 2 - 3, y + bob + 2, 6, 3);
                ctx.restore();
            } else if (e.type === 'turret') {
                ctx.fillStyle = '#241a3d';
                ctx.strokeStyle = '#ffa94d';
                ctx.lineWidth = 2;
                drawRoundRect(x, y, w, h, 4);
                ctx.fill();
                ctx.stroke();
                ctx.fillStyle = '#ffa94d';
                ctx.fillRect(x + w / 2 - 2, y - 5, 4, 7);
                ctx.fillRect(x + w / 2 - 9, y + h * 0.45, w + 12, 4);
                ctx.fillStyle = 'rgba(255, 169, 77, 0.8)';
                ctx.fillRect(x + 4, y + 4, w - 8, 3);
            } else {
                // muhafız: gövde + vizör + bacaklar
                ctx.fillStyle = '#221636';
                ctx.strokeStyle = '#c9b6ff';
                ctx.lineWidth = 2;
                drawRoundRect(x, y, w, h * 0.72, 5);
                ctx.fill();
                ctx.stroke();
                ctx.fillStyle = '#c9b6ff';
                ctx.fillRect(x + 3, y + 3, w - 6, 4);
                ctx.fillStyle = '#161024';
                ctx.fillRect(x + 3, y + h * 0.72, w * 0.32, h * 0.28);
                ctx.fillRect(x + w * 0.62, y + h * 0.72, w * 0.32, h * 0.28);
                ctx.fillStyle = 'rgba(255, 107, 173, 0.9)';
                ctx.fillRect(x + w - 5, y + h * 0.34, 5, 4);
            }

            // can barı
            const hpw = Math.max(14, w);
            const ratio = clamp(e.hp / e.maxHp, 0, 1);
            ctx.fillStyle = 'rgba(10, 6, 20, 0.75)';
            ctx.fillRect(x + (w - hpw) / 2, y - 10, hpw, 4);
            ctx.fillStyle = ratio > 0.5 ? '#c5f46f' : ratio > 0.25 ? '#f8df79' : '#ff6bad';
            ctx.fillRect(x + (w - hpw) / 2, y - 10, hpw * ratio, 4);
        }
    }

    function drawBoss(current, t) {
        const boss = current.boss;
        if (!boss || boss.hp <= 0) return;
        const distancePx = current.distance / PX;
        const w = boss.w / PX;
        const h = boss.h / PX;
        const x = boss.x / PX - distancePx;
        if (x > CONFIG.viewW + 120) return;
        const y = GROUND_Y - h;
        const palette = ['#ff6bad', '#ffa94d', '#63f5e8'][clamp(boss.phase - 1, 0, 2)];

        ctx.save();
        ctx.shadowColor = palette;
        ctx.shadowBlur = reduced ? 0 : 18;
        ctx.fillStyle = '#1b1230';
        ctx.strokeStyle = palette;
        ctx.lineWidth = 3;
        drawRoundRect(x, y, w, h, 8);
        ctx.fill();
        ctx.stroke();

        // omuz zırhları
        ctx.fillStyle = '#2a1b46';
        ctx.fillRect(x - 8, y + h * 0.12, w + 16, 12);
        ctx.strokeStyle = palette;
        ctx.lineWidth = 2;
        ctx.strokeRect(x - 8, y + h * 0.12, w + 16, 12);

        // çekirdek
        const pulse = reduced ? 0.8 : 0.6 + 0.4 * Math.sin(t * 4);
        ctx.beginPath();
        ctx.arc(x + w / 2, y + h * 0.44, 11 + pulse * 2, 0, Math.PI * 2);
        ctx.fillStyle = palette;
        ctx.fill();
        ctx.strokeStyle = '#0b0716';
        ctx.lineWidth = 2;
        ctx.stroke();

        // faz göstergeleri (gövdedeki çentikler)
        for (let i = 0; i < 3; i++) {
            ctx.fillStyle = i < boss.phase ? palette : 'rgba(255,255,255,0.15)';
            ctx.fillRect(x + 8, y + h * 0.72 + i * 7, w - 16, 4);
        }

        // göz
        ctx.fillStyle = '#ffd9ec';
        ctx.fillRect(x + w * 0.5 - 9, y + 14, 18, 5);
        ctx.restore();
    }

    function drawBullets(current) {
        const distancePx = current.distance / PX;
        for (const b of current.bullets) {
            const x = b.x / PX - distancePx;
            if (x < -30 || x > CONFIG.viewW + 30) continue;
            const y = GROUND_Y - b.y / PX;
            if (b.own === 1) {
                ctx.fillStyle = current.weapon === 'plasma' ? '#c9b6ff' : '#c5f46f';
                if (!reduced) {
                    ctx.shadowColor = current.weapon === 'plasma' ? 'rgba(201,182,255,0.9)' : 'rgba(197,244,111,0.9)';
                    ctx.shadowBlur = 10;
                }
                ctx.fillRect(x - 2, y - 2, 16, 4);
                ctx.shadowBlur = 0;
            } else {
                ctx.fillStyle = '#ff6bad';
                if (!reduced) {
                    ctx.shadowColor = 'rgba(255,107,173,0.9)';
                    ctx.shadowBlur = 12;
                }
                ctx.beginPath();
                ctx.arc(x, y, 4.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.shadowBlur = 0;
            }
        }
    }

    function drawPickups(current, t) {
        const distancePx = current.distance / PX;
        for (const p of current.pickups) {
            if (p.taken !== 0) continue;
            const x = p.x / PX - distancePx;
            if (x < -40 || x > CONFIG.viewW + 40) continue;
            const y = GROUND_Y - p.y / PX + (reduced ? 0 : Math.sin(t * 4 + p.x * 0.02) * 2);
            ctx.save();
            ctx.translate(x, y);
            if (p.kind === 'repair') {
                ctx.fillStyle = '#7bf1a8';
                ctx.fillRect(-3, -9, 6, 18);
                ctx.fillRect(-9, -3, 18, 6);
            } else if (p.kind === 'shield') {
                ctx.strokeStyle = '#63f5e8';
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.arc(0, 0, 8, 0, Math.PI * 2);
                ctx.stroke();
                ctx.beginPath();
                ctx.arc(0, 0, 3.5, 0, Math.PI * 2);
                ctx.fillStyle = '#63f5e8';
                ctx.fill();
            } else {
                ctx.rotate(Math.PI / 4);
                ctx.fillStyle = '#c9b6ff';
                ctx.fillRect(-6, -6, 12, 12);
                ctx.strokeStyle = '#f6f1ff';
                ctx.lineWidth = 2;
                ctx.strokeRect(-6, -6, 12, 12);
            }
            ctx.restore();
        }
    }

    function drawTrail() {
        for (const p of trailStack) {
            ctx.globalAlpha = Math.max(0, p.life) * 0.5;
            ctx.fillStyle = visual.trail_color;
            const w = Math.max(2, 18 * p.life);
            ctx.fillRect(p.x - w, p.y, w, 5);
        }
        ctx.globalAlpha = 1;
    }

    function drawPlayer(current, t) {
        const crouched = current.crouch === 1;
        const height = crouched ? CROUCH_H : PLAYER_H;
        const feet = GROUND_Y - (current.y || 0) / PX;
        const x = PLAYER_SCREEN_X;
        const bodyH = height * 0.74;
        const airborne = current.grounded === 0;
        const bob = !airborne && (state === 'running' || state === 'idle') && !reduced ? Math.sin(t * 16) * 1.2 : 0;
        const y = feet - height + bob;
        const dashing = current.dashUntil > current.tick;
        const invuln = current.invuln > 0;

        if (visual.trail) drawTrail();

        ctx.save();
        if (dashing) ctx.globalAlpha = 0.55;
        else if (invuln && !reduced) ctx.globalAlpha = 0.55 + 0.45 * Math.abs(Math.sin(t * 24));
        ctx.shadowColor = visual.primary;
        ctx.shadowBlur = reduced ? 6 : 16 * (0.4 + (visual.glow || 0.4));
        ctx.fillStyle = visual.primary;
        if (visual.body === 'void') {
            ctx.beginPath();
            ctx.ellipse(x + PLAYER_W / 2, y + bodyH / 2, PLAYER_W / 2.0, bodyH / 1.7, 0, 0, Math.PI * 2);
            ctx.fill();
        } else if (visual.body === 'ember') {
            ctx.beginPath();
            ctx.moveTo(x + PLAYER_W / 2, y - 3);
            ctx.lineTo(x + PLAYER_W, feet);
            ctx.lineTo(x, feet);
            ctx.closePath();
            ctx.fill();
        } else {
            drawRoundRect(x, y, PLAYER_W, bodyH, 7);
            ctx.fill();
        }
        ctx.restore();

        // kafa
        const headY = crouched ? y - 2 : y - 6;
        ctx.fillStyle = visual.secondary;
        ctx.strokeStyle = visual.primary;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(x + PLAYER_W / 2, headY, crouched ? 6 : 7.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(x + PLAYER_W / 2 + 1, headY - 2, 4, 3);

        // kalkan halkası
        if (current.shield === 1) {
            ctx.strokeStyle = 'rgba(99, 245, 232, 0.85)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(x + PLAYER_W / 2, y + bodyH / 2, PLAYER_W * 0.85, 0, Math.PI * 2);
            ctx.stroke();
        }

        // namlu (ateş anında)
        for (const m of muzzles) {
            ctx.fillStyle = current.weapon === 'plasma' ? '#c9b6ff' : '#f8df79';
            ctx.globalAlpha = clamp(m.t, 0, 1);
            ctx.fillRect(x + PLAYER_W, y + bodyH * 0.42, 12 * m.t + 4, 3);
            ctx.globalAlpha = 1;
        }

        if (state === 'running' || state === 'countdown') {
            const swing = crouched ? 2 : (reduced ? 3 : Math.sin(t * 20) * 6);
            ctx.strokeStyle = visual.primary;
            ctx.lineWidth = 4;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(x + PLAYER_W / 2 - 4, feet - 6);
            ctx.lineTo(x + PLAYER_W / 2 - 4 + swing, feet);
            ctx.moveTo(x + PLAYER_W / 2 + 4, feet - 6);
            ctx.lineTo(x + PLAYER_W / 2 + 4 - swing, feet);
            ctx.stroke();
        }
    }

    /* ---------------- çizim: HUD ---------------- */

    function panel(x, y, w, h) {
        ctx.fillStyle = 'rgba(10, 6, 20, 0.55)';
        ctx.strokeStyle = 'rgba(220, 204, 255, 0.16)';
        ctx.lineWidth = 1;
        drawRoundRect(x, y, w, h, 12);
        ctx.fill();
        ctx.stroke();
    }

    function drawHud(current) {
        if (!current) return;
        const score = scoreFor(current.distance, current.cells, current.kills, current.bossKilled === 1);
        ctx.save();
        ctx.textAlign = 'left';

        // sol panel: skor + can + silah
        panel(20, 18, 268, 94);
        ctx.fillStyle = '#bdb3cb';
        ctx.font = '600 12px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('SKOR', 36, 40);
        ctx.fillStyle = '#f6f1ff';
        ctx.font = '800 26px "Bricolage Grotesque", system-ui, sans-serif';
        ctx.fillText(String(score), 36, 66);

        // can barı
        const hpX = 36;
        const hpY = 78;
        const hpW = 150;
        const ratio = clamp(current.hp / CONFIG.maxHp, 0, 1);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.12)';
        ctx.fillRect(hpX, hpY, hpW, 8);
        ctx.fillStyle = ratio > 0.5 ? '#c5f46f' : ratio > 0.25 ? '#f8df79' : '#ff6bad';
        ctx.fillRect(hpX, hpY, hpW * ratio, 8);
        ctx.fillStyle = '#bdb3cb';
        ctx.font = '700 11px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('CAN ' + current.hp, hpX + hpW + 8, hpY + 8);

        // silah + şarj
        const weapon = WEAPONS[current.weapon] || WEAPONS.blaster;
        ctx.fillStyle = current.weapon === 'plasma' ? '#c9b6ff' : '#c5f46f';
        ctx.font = '700 11px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(WEAPON_LABEL[current.weapon] || 'BLASTER', 36, 102);
        for (let i = 0; i < weapon.mag; i++) {
            ctx.fillStyle = i < current.shots ? (current.weapon === 'plasma' ? '#c9b6ff' : '#c5f46f') : 'rgba(255,255,255,0.16)';
            ctx.fillRect(120 + i * 9, 94, 6, 8);
        }

        // sağ panel: süre + hücre + öldürme
        panel(CONFIG.viewW - 250, 18, 230, 94);
        ctx.fillStyle = '#bdb3cb';
        ctx.font = '600 12px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('SÜRE', CONFIG.viewW - 234, 40);
        ctx.fillStyle = '#f6f1ff';
        ctx.font = '800 22px "Bricolage Grotesque", system-ui, sans-serif';
        ctx.fillText((current.tick / CONFIG.tickHz).toFixed(1) + 's', CONFIG.viewW - 234, 66);
        ctx.fillStyle = '#63f5e8';
        ctx.font = '700 13px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('◈ ' + current.cells, CONFIG.viewW - 140, 66);
        ctx.fillStyle = '#ff9ec9';
        ctx.fillText('✖ ' + current.kills, CONFIG.viewW - 78, 66);

        // dash durumu
        const dashReady = current.tick >= current.dashReadyAt;
        ctx.fillStyle = dashReady ? '#c5f46f' : 'rgba(189, 179, 203, 0.55)';
        ctx.font = '700 11px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(dashReady ? 'DASH HAZIR' : 'DASH ' + Math.ceil((current.dashReadyAt - current.tick) / 60) + 's', CONFIG.viewW - 234, 96);

        // boss barı
        const boss = current.boss;
        if (boss && boss.hp > 0) {
            const bw = 420;
            const bx = (CONFIG.viewW - bw) / 2;
            const by = 150;
            panel(bx - 12, by - 22, bw + 24, 52);
            ctx.fillStyle = '#bdb3cb';
            ctx.font = '700 11px "Instrument Sans", system-ui, sans-serif';
            ctx.fillText('ÇEKİRDEK MUHAFIZI · FAZ ' + boss.phase, bx, by - 6);
            ctx.fillStyle = 'rgba(255, 255, 255, 0.12)';
            ctx.fillRect(bx, by, bw, 10);
            const br = clamp(boss.hp / boss.maxHp, 0, 1);
            ctx.fillStyle = ['#ff6bad', '#ffa94d', '#63f5e8'][clamp(boss.phase - 1, 0, 2)];
            ctx.fillRect(bx, by, bw * br, 10);
            // faz eşikleri
            ctx.fillStyle = 'rgba(10, 6, 20, 0.75)';
            ctx.fillRect(bx + bw * (CONFIG.bossPhase2 / 100) - 1, by, 2, 10);
            ctx.fillRect(bx + bw * (CONFIG.bossPhase3 / 100) - 1, by, 2, 10);
        }

        ctx.restore();
    }

    function render(t) {
        applyDpr();
        const current = run;
        const distancePx = current ? current.distance / PX : (idleT * 70) % 6000;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, CONFIG.viewW, CONFIG.viewH);

        ctx.save();
        if (shake > 0 && !reduced) {
            ctx.translate((Math.random() - 0.5) * shake, (Math.random() - 0.5) * shake);
        }

        drawBackground(distancePx, t);

        if (current) {
            drawCeilings(current);
            drawObstacles(current);
            drawCells(current, t);
            drawPickups(current, t);
            drawEnemies(current, t);
            drawBoss(current, t);
            drawPlayer(current, t);
            drawBullets(current);
            for (const s of sparks) {
                ctx.globalAlpha = Math.max(0, s.life);
                ctx.fillStyle = '#63f5e8';
                ctx.beginPath();
                ctx.arc(s.x, s.y - (1 - s.life) * 26, Math.max(1, 3 * s.life), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1;
            if (hurt > 0 && !reduced) {
                ctx.fillStyle = 'rgba(255, 60, 110, ' + hurt * 0.18 + ')';
                ctx.fillRect(0, 0, CONFIG.viewW, CONFIG.viewH);
            }
            drawHud(current);
        } else {
            drawPlayer({ y: 0, grounded: 1, crouch: 0, tick: 0, dashUntil: 0, invuln: 0, shield: 0, weapon: 'blaster' }, t);
            ctx.textAlign = 'center';
            ctx.fillStyle = 'rgba(246, 241, 255, 0.6)';
            ctx.font = '600 17px "Instrument Sans", system-ui, sans-serif';
            ctx.fillText('Başlamak için tıkla, dokun veya boşluk tuşuna bas', CONFIG.viewW / 2, 96);
            ctx.textAlign = 'left';
        }

        if (flash > 0 && !reduced) {
            ctx.fillStyle = 'rgba(255, 255, 255, ' + flash * 0.16 + ')';
            ctx.fillRect(0, 0, CONFIG.viewW, CONFIG.viewH);
        }
        ctx.restore();
    }

    /* ---------------- girdi ---------------- */

    const JUMP_KEYS = ['Space', 'ArrowUp', 'KeyW'];
    const CROUCH_KEYS = ['ArrowDown', 'KeyS'];
    const FIRE_KEYS = ['KeyJ', 'KeyF', 'KeyX'];
    const DASH_KEYS = ['ShiftLeft', 'ShiftRight', 'KeyK'];

    function typingInForm() {
        const tag = (document.activeElement && document.activeElement.tagName) || '';
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    function onKeyDown(event) {
        if (event.repeat || typingInForm()) return;
        const code = event.code;
        if (JUMP_KEYS.includes(code)) {
            if (state === 'running') event.preventDefault();
            requestAction(ACTIONS.JUMP);
        } else if (CROUCH_KEYS.includes(code)) {
            if (state === 'running') {
                event.preventDefault();
                setHold('crouch', true);
            }
        } else if (FIRE_KEYS.includes(code)) {
            if (state === 'running') {
                event.preventDefault();
                setHold('fire', true);
            }
        } else if (DASH_KEYS.includes(code)) {
            if (state === 'running') {
                event.preventDefault();
                requestAction(ACTIONS.DASH);
            }
        }
    }

    function onKeyUp(event) {
        const code = event.code;
        if (JUMP_KEYS.includes(code)) {
            if (state === 'running') event.preventDefault();
            requestAction(ACTIONS.RELEASE);
        } else if (CROUCH_KEYS.includes(code)) {
            if (state === 'running') {
                event.preventDefault();
                setHold('crouch', false);
            }
        } else if (FIRE_KEYS.includes(code)) {
            if (state === 'running') {
                event.preventDefault();
                setHold('fire', false);
            }
        }
    }

    /** Dokunma bölgeleri: sol %34 eğil, sağ %34 zıpla, orta ateş. */
    function zoneAt(event) {
        const rect = canvas.getBoundingClientRect ? canvas.getBoundingClientRect() : null;
        const width = (rect && rect.width) || CONFIG.viewW;
        const px = typeof event.clientX === 'number' && rect ? event.clientX - rect.left : width / 2;
        const ratio = width > 0 ? px / width : 0.5;
        if (ratio < 0.34) return 'crouch';
        if (ratio > 0.66) return 'jump';
        return 'fire';
    }

    function onPointerDown(event) {
        if (state !== 'running') return;
        event.preventDefault();
        const zone = zoneAt(event);
        if (event.pointerId != null) touches.set(event.pointerId, zone);
        if (canvas.setPointerCapture && event.pointerId != null) canvas.setPointerCapture(event.pointerId);
        if (zone === 'jump') requestAction(ACTIONS.JUMP);
        else if (zone === 'crouch') setHold('crouch', true);
        else setHold('fire', true);
    }

    function onPointerUp(event) {
        if (event.pointerId != null) touches.delete(event.pointerId);
        if (state !== 'running') return;
        const zone = zoneAt(event);
        if (zone === 'jump') requestAction(ACTIONS.RELEASE);
        else if (zone === 'crouch') setHold('crouch', false);
        else setHold('fire', false);
    }

    window.addEventListener('keydown', onKeyDown, { passive: false });
    window.addEventListener('keyup', onKeyUp, { passive: false });
    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', onPointerUp);
    canvas.addEventListener('contextmenu', (e) => e.preventDefault());

    applyDpr();
    raf = requestAnimationFrame(loop);

    return {
        start,
        requestAction,
        setHold,
        abort() {
            if (state === 'running' || state === 'countdown' || state === 'paused') {
                inputs = [];
                run = null;
                resetIntent();
                setState('idle');
            }
        },
        pause() {
            if (state === 'running') {
                setHold('fire', false);
                setHold('crouch', false);
                setState('paused');
            }
        },
        resume() {
            if (state === 'paused') {
                last = 0;
                setState('running');
            }
        },
        reset() {
            inputs = [];
            run = null;
            resetIntent();
            setState('idle');
        },
        setVisual(next) {
            visual = { ...DEFAULT_VISUAL, ...(next || {}) };
        },
        setMuted(value) {
            muted = !!value;
        },
        setReducedMotion(value) {
            reduced = !!value;
        },
        getState() {
            return state;
        },
        getRun() {
            return run;
        },
        isRanked() {
            return ranked;
        },
        getMeta() {
            return meta;
        },
        getInputs() {
            return inputs.slice();
        },
        destroy() {
            cancelAnimationFrame(raf);
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('keyup', onKeyUp);
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointerup', onPointerUp);
            canvas.removeEventListener('pointercancel', onPointerUp);
        },
    };
}
