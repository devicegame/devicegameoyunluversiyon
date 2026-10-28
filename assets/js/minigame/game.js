/**
 * Device Game — "Overcharge: Kaçış" sahne denetleyicisi.
 *
 * SADECE sunum + girdi toplama yapar. Fizik ve skor `engine.js` içindedir
 * (sunucuyla birebir aynı motor). Skor burada hesaplanmaz.
 *
 * Zamanlama: requestAnimationFrame yalnızca birikimi besler; simülasyon her
 * zaman sabit 1/60 sn adımlarla ilerler → 30/60/120 Hz ekranlarda oyun hızı
 * ve fizik aynıdır.
 */
import { ACTIONS, CONFIG, createState, step, resultOf, scoreFor } from './engine.js';

const TICK = 1 / CONFIG.tickHz;
const PX = CONFIG.unitsPerPx;
const GROUND_Y = CONFIG.groundY;
const PLAYER_SCREEN_X = CONFIG.playerX / PX; // 120 px
const PLAYER_W = CONFIG.playerW / PX;        // 28 px
const PLAYER_H = CONFIG.playerH / PX;        // 40 px

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

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/**
 * @param {{canvas: HTMLCanvasElement, onEnd?: Function, onState?: Function, onCountdown?: Function}} options
 */
export function createGame(options) {
    const canvas = options.canvas;
    const ctx = canvas.getContext('2d');
    const onEnd = options.onEnd || function () {};
    const onState = options.onState || function () {};
    const onCountdown = options.onCountdown || function () {};

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
    let trail = [];
    let sparks = [];
    let idleT = 0;
    let dpr = 1;

    // Girdi kuyruğu: olay anındaki tick'e yazılır, o tick'te uygulanır.
    const queued = [];

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
        queued.length = 0;
        acc = 0;
        countdownStart = performance.now();
        countdownShown = -1;
        trail = [];
        sparks = [];
        shake = 0;
        flash = 0;
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
        if (!reduced) shake = 12;
        beep(reason === 'time' ? 640 : 120, reason === 'time' ? 0.22 : 0.32, 'sawtooth', 0.07);
        onEnd(payload);
    }

    /* ---------------- simülasyon ---------------- */

    function stepOnce() {
        if (!run) return;
        let action = ACTIONS.NONE;
        while (queued.length && queued[0].tick <= run.tick) {
            action = queued.shift().action;
        }
        const cellsBefore = run.cells;
        const status = step(run, action);
        if (run.cells > cellsBefore) {
            beep(880 + run.cells * 4, 0.05, 'sine', 0.04);
            if (!reduced) {
                sparks.push({ x: PLAYER_SCREEN_X + PLAYER_W / 2, y: GROUND_Y - run.y / PX - PLAYER_H / 2, life: 1 });
            }
        }
        if (status === 'collision') {
            finish('collision');
        } else if (status === 'time') {
            finish('time');
        } else if (!reduced && run.tick % 2 === 0 && visual.trail) {
            trail.push({ x: PLAYER_SCREEN_X + 4, y: GROUND_Y - run.y / PX - 8, life: 1 });
            if (trail.length > 30) trail.shift();
        }
    }

    /**
     * Oyuncu girdisi. Aksiyon, çağrıldığı tick'e yazılır ve o tick'te
     * uygulanır; sunucu aynı listeyi aynı sırayla oynatır.
     */
    function requestAction(action) {
        if (state !== 'running' || !run) return;
        if (action !== ACTIONS.JUMP && action !== ACTIONS.RELEASE) return;
        const tick = run.tick;
        const lastInput = inputs.length ? inputs[inputs.length - 1] : null;
        if (lastInput && lastInput[0] === tick) {
            lastInput[1] = action;
            if (queued.length && queued[queued.length - 1].tick === tick) {
                queued[queued.length - 1].action = action;
            } else {
                queued.push({ tick, action });
            }
            return;
        }
        inputs.push([tick, action]);
        queued.push({ tick, action });
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
            // Uzun bir takılma olduysa birikimi taşırma (yavaş cihazlar).
            if (acc > TICK * 8) acc = 0;
        } else {
            idleT += dt;
        }

        if (shake > 0) shake = Math.max(0, shake - dt * 40);
        if (flash > 0) flash = Math.max(0, flash - dt * 3);
        if (state !== 'running') {
            for (const p of trail) p.life -= dt * 2;
            trail = trail.filter((p) => p.life > 0);
        }
        for (const s of sparks) s.life -= dt * 2.2;
        sparks = sparks.filter((s) => s.life > 0);

        render(now / 1000);
    }

    /* ---------------- çizim ---------------- */

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

        // perspektif ızgara
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

        // zemin
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
            ctx.shadowBlur = 14 * pulse;
            ctx.fillStyle = '#63f5e8';
            const s = half * 1.3;
            ctx.fillRect(-s / 2, -s / 2, s, s);
            ctx.restore();
        }
    }

    function drawTrail() {
        for (const p of trail) {
            ctx.globalAlpha = Math.max(0, p.life) * 0.5;
            ctx.fillStyle = visual.trail_color;
            const w = Math.max(2, 18 * p.life);
            ctx.fillRect(p.x - w, p.y, w, 5);
        }
        ctx.globalAlpha = 1;
    }

    function drawPlayer(current, t) {
        const feet = GROUND_Y - (current.y || 0) / PX;
        const x = PLAYER_SCREEN_X;
        const bodyH = PLAYER_H * 0.74;
        const airborne = current.grounded === 0;
        const bob = !airborne && (state === 'running' || state === 'idle') && !reduced ? Math.sin(t * 16) * 1.2 : 0;
        const y = feet - PLAYER_H + bob;

        if (visual.trail) drawTrail();

        ctx.save();
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
            ctx.beginPath();
            if (ctx.roundRect) ctx.roundRect(x, y, PLAYER_W, bodyH, 7);
            else ctx.rect(x, y, PLAYER_W, bodyH);
            ctx.fill();
        }
        ctx.restore();

        ctx.fillStyle = visual.secondary;
        ctx.strokeStyle = visual.primary;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(x + PLAYER_W / 2, y - 6, 7.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(x + PLAYER_W / 2 + 1, y - 8, 4, 3);

        if (state === 'running' || state === 'countdown') {
            const swing = reduced ? 3 : Math.sin(t * 20) * 6;
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

    function drawHud(current) {
        if (!current) return;
        ctx.save();
        ctx.fillStyle = 'rgba(10, 6, 20, 0.55)';
        ctx.strokeStyle = 'rgba(220, 204, 255, 0.16)';
        ctx.lineWidth = 1;
        const panel = (x, y, w, h) => {
            ctx.beginPath();
            if (ctx.roundRect) ctx.roundRect(x, y, w, h, 12);
            else ctx.rect(x, y, w, h);
            ctx.fill();
            ctx.stroke();
        };
        panel(20, 18, 210, 62);
        panel(CONFIG.viewW - 232, 18, 212, 62);

        ctx.fillStyle = '#bdb3cb';
        ctx.textAlign = 'left';
        ctx.font = '600 12px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('SKOR', 36, 40);
        ctx.fillStyle = '#f6f1ff';
        ctx.font = '800 26px "Bricolage Grotesque", system-ui, sans-serif';
        ctx.fillText(String(scoreFor(current.distance, current.cells)), 36, 68);

        ctx.fillStyle = '#bdb3cb';
        ctx.font = '600 12px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('SÜRE', CONFIG.viewW - 216, 40);
        ctx.fillStyle = '#f6f1ff';
        ctx.font = '800 22px "Bricolage Grotesque", system-ui, sans-serif';
        ctx.fillText((current.tick / CONFIG.tickHz).toFixed(1) + 's', CONFIG.viewW - 216, 68);

        ctx.fillStyle = '#63f5e8';
        ctx.font = '700 13px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText('◈ ' + current.cells, CONFIG.viewW - 96, 68);
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
            drawObstacles(current);
            drawCells(current, t);
            drawPlayer(current, t);
            for (const s of sparks) {
                ctx.globalAlpha = Math.max(0, s.life);
                ctx.fillStyle = '#63f5e8';
                ctx.beginPath();
                ctx.arc(s.x, s.y - (1 - s.life) * 26, Math.max(1, 3 * s.life), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1;
            drawHud(current);
        } else {
            drawPlayer({ y: 0, grounded: 1 }, t);
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

    function isPlayKey(code) {
        return code === 'Space' || code === 'ArrowUp' || code === 'KeyW';
    }

    function onKeyDown(event) {
        if (event.repeat || !isPlayKey(event.code)) return;
        // Form alanlarında oyun tuşlarını yutmayalım.
        const tag = (document.activeElement && document.activeElement.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (state === 'running') event.preventDefault();
        requestAction(ACTIONS.JUMP);
    }

    function onKeyUp(event) {
        if (!isPlayKey(event.code)) return;
        if (state === 'running') event.preventDefault();
        requestAction(ACTIONS.RELEASE);
    }

    function onPointerDown(event) {
        if (state !== 'running') return;
        event.preventDefault();
        canvas.setPointerCapture && canvas.setPointerCapture(event.pointerId);
        requestAction(ACTIONS.JUMP);
    }

    function onPointerUp(event) {
        if (state !== 'running') return;
        requestAction(ACTIONS.RELEASE);
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
        abort() {
            if (state === 'running' || state === 'countdown' || state === 'paused') {
                queued.length = 0;
                inputs = [];
                run = null;
                setState('idle');
            }
        },
        pause() {
            if (state === 'running') {
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
            queued.length = 0;
            inputs = [];
            run = null;
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
