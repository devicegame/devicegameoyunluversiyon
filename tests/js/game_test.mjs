/**
 * Sahne denetleyicisi (game.js) testi — tarayıcısız.
 *
 * Amaç:
 *  1) İstemcinin kaydettiği girdi listesi sunucu motoruyla AYNI skoru üretmeli.
 *  2) Oyun hızı kare hızından bağımsız: aynı seed, aynı anda bitmeli (30/60/120 Hz).
 *
 * Çalıştırma: node tests/js/game_test.mjs
 */
import assert from 'node:assert/strict';
import { simulate, encodeInputs, CONFIG } from '../../assets/js/minigame/engine.js';
import { playBot } from '../../tools/minigame-bot.mjs';

/* ---------------- minimal DOM stub ---------------- */

const listeners = new Map();
let rafCallback = null;
let nowMs = 0;

const gradient = { addColorStop() {} };
const ctxStub = new Proxy({}, {
    get(target, prop) {
        if (prop === 'createLinearGradient' || prop === 'createRadialGradient') return () => gradient;
        if (prop === 'canvas') return canvas;
        if (prop in target) return target[prop];
        return () => {};
    },
    set(target, prop, value) {
        target[prop] = value;
        return true;
    },
});

const canvas = {
    width: 960,
    height: 540,
    style: {},
    getContext: () => ctxStub,
    addEventListener(type, handler) {
        listeners.set('canvas:' + type, handler);
    },
    removeEventListener() {},
    setPointerCapture() {},
};

globalThis.window = {
    devicePixelRatio: 2,
    matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
    addEventListener(type, handler) {
        listeners.set('window:' + type, handler);
    },
    removeEventListener(type) {
        listeners.delete('window:' + type);
    },
    AudioContext: undefined,
};
globalThis.document = { activeElement: { tagName: 'BODY' }, hidden: false, addEventListener() {} };
globalThis.performance = { now: () => nowMs };
globalThis.requestAnimationFrame = (cb) => { rafCallback = cb; return 1; };
globalThis.cancelAnimationFrame = () => { rafCallback = null; };
globalThis.localStorage = { getItem: () => null, setItem() {} };

const { createGame } = await import('../../assets/js/minigame/game.js');

function resetStubs() {
    listeners.clear();
    rafCallback = null;
    nowMs = 0;
}

/* ---------------- yardımcılar ---------------- */

function makeGame(onEnd) {
    return createGame({ canvas, onEnd, onState() {}, onCountdown() {} });
}

/** rAF döngüsünü verilen kare süresiyle ilerletir. */
function runLoop(game, { frameMs, maxFrames = 20000, onFrame = null, until = null }) {
    let frames = 0;
    while (rafCallback && frames < maxFrames) {
        if (until && until(game)) break;
        const cb = rafCallback;
        rafCallback = null;
        if (onFrame) onFrame(game, frames);
        nowMs += frameMs;
        cb(nowMs);
        frames += 1;
    }
    if (rafCallback) cancelAnimationFrame(0);
    rafCallback = null;
    return frames;
}

function keyDown() {
    const handler = listeners.get('window:keydown');
    if (handler) handler({ code: 'Space', repeat: false, preventDefault() {} });
}
function keyUp() {
    const handler = listeners.get('window:keyup');
    if (handler) handler({ code: 'Space', preventDefault() {} });
}

let checks = 0;
function ok(condition, label) {
    checks += 1;
    if (condition) {
        console.log('  ✓ ' + label);
    } else {
        console.log('  ✗ ' + label);
        process.exitCode = 1;
    }
}

/* ---------------- 1) istemci ↔ sunucu aynı sonuç ---------------- */

console.log('== Sahne denetleyicisi testi ==');

const SEED = 20240301;
const scripted = playBot(SEED, { maxTicks: 1800 }).inputs;

resetStubs();
let ended = null;
const game = makeGame((payload) => { ended = payload; });
game.start({ seed: SEED, ranked: true, meta: { run_id: 'run_x' } });

const sentDown = new Set();
const sentUp = new Set();
runLoop(game, {
    frameMs: 1000 / 60,
    until: () => ended !== null,
    onFrame: (g) => {
        const run = g.getRun();
        if (!run) return;
        const tick = run.tick;
        for (const [t, action] of scripted) {
            if (t === tick && action === 1 && !sentDown.has(t)) { sentDown.add(t); keyDown(); }
            if (t === tick && action === 2 && !sentUp.has(t)) { sentUp.add(t); keyUp(); }
        }
    },
});

ok(ended !== null, 'koşu sonuçlandı');
const inputs = ended ? ended.inputs : [];
ok(inputs.length === scripted.length, 'girdiler birebir kaydedildi (' + inputs.length + '/' + scripted.length + ')');
ok(encodeInputs(inputs) === encodeInputs(scripted), 'kaydedilen girdi dizisi botun dizisiyle aynı');

const serverReplay = simulate(SEED, inputs);
ok(serverReplay.score === ended.result.score, 'istemci ve sunucu skoru aynı (' + ended.result.score + ')');
ok(serverReplay.ticks === ended.result.ticks, 'tur süresi aynı (' + ended.result.ticks + ' tick)');
ok(serverReplay.cells === ended.result.cells, 'hücre sayısı aynı');


// Girdiler sıkı artan olmalı (sunucu kuralı)
let increasing = true;
for (let i = 1; i < inputs.length; i++) {
    if (inputs[i][0] <= inputs[i - 1][0]) increasing = false;
}
ok(increasing, 'girdi tick\'leri sıkı artan');
ok(inputs.every(([, a]) => a === 1 || a === 2), 'yalnızca geçerli aksiyonlar');
game.destroy();

/* ---------------- 2) kare hızı bağımsızlığı ---------------- */

function runToEnd(frameMs) {
    resetStubs();
    let result = null;
    const g = makeGame((payload) => { result = payload; });
    g.start({ seed: 4242, ranked: false, meta: {} });
    const frames = runLoop(g, { frameMs, until: () => result !== null });
    g.destroy();
    return { result, frames };
}

const at30 = runToEnd(1000 / 30);
const at60 = runToEnd(1000 / 60);
const at120 = runToEnd(1000 / 120);

ok(at30.result !== null && at60.result !== null && at120.result !== null, 'üç kare hızında da koşu bitti');
const t30 = at30.result.result;
const t60 = at60.result.result;
const t120 = at120.result.result;
ok(t60.ticks === t30.ticks, '30 Hz ve 60 Hz aynı tick\'te bitti (' + t60.ticks + ')');
ok(t60.ticks === t120.ticks, '60 Hz ve 120 Hz aynı tick\'te bitti');
ok(t60.score === t30.score && t60.score === t120.score, 'skorlar kare hızından bağımsız');

// Gerçek zaman ≈ tick / 60 + 3 sn sayaç
const expected60 = Math.ceil((t60.ticks / CONFIG.tickHz + 3) * 60);
ok(Math.abs(at60.frames - expected60) <= 3, 'kare sayısı 60 Hz ile uyumlu (' + at60.frames + ' ≈ ' + expected60 + ')');
const expected30 = Math.ceil((t30.ticks / CONFIG.tickHz + 3) * 30);
ok(Math.abs(at30.frames - expected30) <= 3, 'kare sayısı 30 Hz ile uyumlu (' + at30.frames + ' ≈ ' + expected30 + ')');

console.log('\n' + (process.exitCode ? 'SONUÇ: BAŞARISIZ' : 'SONUÇ: BAŞARILI') + ' — ' + checks + ' kontrol');
