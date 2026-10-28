/**
 * Sahne denetleyicisi (game.js) testi — tarayıcısız, v2.
 *
 * Amaç:
 *  1) Denetleyicinin kaydettiği girdi listesi, motor tarafından aynı sonucu
 *     üretmeli (sunucu bu listeyi oynatır → skor eşleşmeli).
 *  2) Oyun hızı kare hızından bağımsız: aynı seed, aynı tick'te bitmeli.
 *  3) v2 girdileri (eğil, ateş, dash, dokunma bölgeleri) doğru aksiyona
 *     dönüşmeli ve tick başına tek aksiyon kuralı korunmalı.
 *
 * Çalıştırma: node tests/js/game_test.mjs
 */
import assert from 'node:assert/strict';
import { ACTIONS, CONFIG, WEAPONS, simulate, encodeInputs } from '../../assets/js/minigame/engine.js';

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
    getBoundingClientRect: () => ({ left: 0, top: 0, width: CONFIG.viewW, height: CONFIG.viewH }),
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

const { createGame } = await import('../../assets/js/minigame/game.js');

function resetStubs() {
    listeners.clear();
    rafCallback = null;
    nowMs = 0;
}

/* ---------------- yardımcılar ---------------- */

function makeGame(onEnd) {
    return createGame({ canvas, onEnd, onState() {}, onCountdown() {}, onEvent() {} });
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

const key = (type, code) => {
    const handler = listeners.get('window:' + type);
    if (handler) handler({ code, repeat: false, preventDefault() {} });
};

const pointer = (type, clientX, pointerId = 1) => {
    const handler = listeners.get('canvas:' + type);
    if (handler) handler({ clientX, pointerId, buttons: type === 'pointerdown' ? 1 : 0, preventDefault() {} });
};

let checks = 0;
const failures = [];
function ok(condition, label) {
    checks += 1;
    if (condition) {
        console.log('  ✓ ' + label);
    } else {
        failures.push(label);
        console.log('  ✗ ' + label);
    }
}

/** Basit "insan" senaryosu: tick'e göre niyet üretir. */
function humanFrame(game, tick) {
    if (tick % 97 === 0) game.requestAction(ACTIONS.JUMP);
    if (tick % 97 === 9) game.requestAction(ACTIONS.RELEASE);
    if (tick % 173 === 40) game.setHold('crouch', true);
    if (tick % 173 === 80) game.setHold('crouch', false);
    if (tick === 120) game.setHold('fire', true);
    if (tick === 520) game.setHold('fire', false);
    if (tick === 300 || tick === 900) game.requestAction(ACTIONS.DASH);
}

/* ---------------- 1) kaydedilen girdi = sunucu sonucu ---------------- */

console.log('== Sahne denetleyicisi testi (v2) ==');

const SEED = 20240301;
resetStubs();
let ended = null;
let endCount = 0;
const game = makeGame((payload) => { ended = payload; endCount += 1; });
game.start({ seed: SEED, ranked: true, meta: { run_id: 'run_x' } });

runLoop(game, {
    frameMs: 1000 / 60,
    maxFrames: 20000,
    until: () => ended !== null,
    onFrame: (g) => {
        const run = g.getRun();
        if (run && run.tick > 0) humanFrame(g, run.tick);
    },
});

ok(ended !== null, 'koşu sonuçlandı');
ok(endCount === 1, 'oyun sonu bir kez tetiklendi (' + endCount + ')');

const inputs = ended ? ended.inputs : [];
ok(inputs.length > 40, 'girdi kaydedildi (' + inputs.length + ')');
ok(encodeInputs(inputs).length > 0, 'girdi metni üretildi');

const replay = simulate(SEED, inputs);
ok(replay.score === ended.result.score, 'istemci ve sunucu skoru aynı (' + ended.result.score + ')');
ok(replay.ticks === ended.result.ticks, 'tur süresi aynı (' + ended.result.ticks + ' tick)');
ok(replay.reason === ended.result.reason, 'bitiş nedeni aynı (' + ended.result.reason + ')');
ok(replay.hp === ended.result.hp, 'kalan can aynı (' + ended.result.hp + ')');
ok(replay.kills === ended.result.kills, 'öldürme sayısı aynı (' + ended.result.kills + ')');
ok(replay.bossKilled === ended.result.bossKilled, 'boss sonucu aynı');
ok(replay.cells === ended.result.cells, 'hücre sayısı aynı');

// Sunucu kuralı: tick'ler sıkı artan, aksiyonlar geçerli, tick başına bir kayıt.
let increasing = true;
let validActions = true;
for (let i = 0; i < inputs.length; i += 1) {
    if (i > 0 && inputs[i][0] <= inputs[i - 1][0]) increasing = false;
    if (![1, 2, 3, 4, 5, 6].includes(inputs[i][1])) validActions = false;
    if (inputs[i][0] >= CONFIG.maxTicks) validActions = false;
}
ok(increasing, 'girdi tick\'leri sıkı artan');
ok(validActions, 'yalnızca geçerli aksiyonlar ve tick aralığı');
ok(new Set(inputs.map((pair) => pair[1])).size >= 4, 'zıplama/dash/ateş/eğilme kaydedildi');
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
ok(t60.reason === 'hp', 'girdisiz tur can bitişiyle bitti (' + t60.reason + ')');

const expected60 = Math.ceil((t60.ticks / CONFIG.tickHz + 3) * 60);
ok(Math.abs(at60.frames - expected60) <= 4, 'kare sayısı 60 Hz ile uyumlu (' + at60.frames + ' ≈ ' + expected60 + ')');
const expected30 = Math.ceil((t30.ticks / CONFIG.tickHz + 3) * 30);
ok(Math.abs(at30.frames - expected30) <= 4, 'kare sayısı 30 Hz ile uyumlu (' + at30.frames + ' ≈ ' + expected30 + ')');

/* ---------------- 3) girdi yolları ---------------- */

/** Klavye/tuval olaylarıyla N tick ilerletir. */
function play(seed, steps, onTick) {
    resetStubs();
    const result = { ended: null };
    const g = makeGame((payload) => { result.ended = payload; });
    g.start({ seed, ranked: false, meta: {} });
    let lastTick = -1;
    runLoop(g, {
        frameMs: 1000 / 60,
        maxFrames: 40000,
        until: () => result.ended !== null || (g.getRun() && g.getRun().tick >= steps),
        onFrame: () => {
            const run = g.getRun();
            if (!run) return;
            if (run.tick !== lastTick) {
                lastTick = run.tick;
                if (onTick) onTick(g, run.tick);
            }
        },
    });
    return { game: g, ended: result.ended };
}

// 3a) eğilme: dokunmatik sol bölge (ekranın %10'u) eğilir
{
    const session = play(777, 40, (g, tick) => {
        if (tick === 10) pointer('pointerdown', CONFIG.viewW * 0.1, 11);
        if (tick === 30) pointer('pointerup', CONFIG.viewW * 0.1, 11);
    });
    session.game.destroy();
    const recorded = session.game.getInputs();
    ok(recorded.some((pair) => pair[1] === ACTIONS.CROUCH), 'sol dokunma bölgesi eğilme kaydetti');
    ok(recorded.some((pair) => pair[1] === ACTIONS.STAND), 'parmak kalkınca kalkma kaydedildi');
}

// 3b) ateş: orta bölge basılı tutulunca kadanslı ateş
{
    const session = play(778, 120, (g, tick) => {
        if (tick === 20) pointer('pointerdown', CONFIG.viewW * 0.5, 12);
        if (tick === 100) pointer('pointerup', CONFIG.viewW * 0.5, 12);
    });
    const run = session.game.getRun();
    const recorded = session.game.getInputs();
    const fires = recorded.filter((pair) => pair[1] === ACTIONS.FIRE).length;
    session.game.destroy();
    ok(run.shotsFired > 0, 'ateş düğmesi mermi üretti (' + run.shotsFired + ')');
    ok(fires === run.shotsFired, 'her ateş girdisi bir atışa dönüştü (' + fires + ')');
    ok(fires >= WEAPONS.blaster.mag, 'tam şarj boşaltıldı (' + fires + ' ≥ ' + WEAPONS.blaster.mag + ')');
    ok(run.shots >= 0 && run.shots <= 12, 'şarj aralıkta (' + run.shots + ')');
    ok(run.weapon === 'blaster' || run.weapon === 'plasma', 'silah motor sabitlerinden biri (' + run.weapon + ')');
}

// 3c) zıplama: sağ dokunma bölgesi
{
    const session = play(779, 40, (g, tick) => {
        if (tick === 10) pointer('pointerdown', CONFIG.viewW * 0.9, 13);
        if (tick === 22) pointer('pointerup', CONFIG.viewW * 0.9, 13);
    });
    const run = session.game.getRun();
    const recorded = session.game.getInputs();
    session.game.destroy();
    ok(run.jumps >= 1, 'sağ dokunma bölgesi zıplattı');
    ok(recorded.some((pair) => pair[1] === ACTIONS.JUMP), 'zıplama kaydedildi');
    ok(recorded.some((pair) => pair[1] === ACTIONS.RELEASE), 'zıplama bırakışı kaydedildi');
}

// 3d) dash: klavye Shift
{
    const session = play(780, 30, (g, tick) => {
        if (tick === 12) key('keydown', 'ShiftLeft');
    });
    const run = session.game.getRun();
    const recorded = session.game.getInputs();
    session.game.destroy();
    ok(recorded.some((pair) => pair[1] === ACTIONS.DASH), 'dash kaydedildi');
    ok(run.dashUntil > 0 && run.dashReadyAt > 12, 'dash süre ve bekleme koydu');
}

// 3e) klavye ile eğilme: aşağı ok basılı tutulunca eğik kalır
{
    const session = play(781, 60, (g, tick) => {
        if (tick === 10) key('keydown', 'ArrowDown');
        if (tick === 40) key('keyup', 'ArrowDown');
    });
    const run = session.game.getRun();
    const recorded = session.game.getInputs();
    session.game.destroy();
    ok(run.crouch === 0, 'kalkma sonrası eğilme kapandı');
    ok(recorded.filter((pair) => pair[1] === ACTIONS.CROUCH).length === 1, 'eğilme tek kez kaydedildi');
    ok(recorded.filter((pair) => pair[1] === ACTIONS.STAND).length === 1, 'kalkma tek kez kaydedildi');
}

// 3f) eğikken zıplama isteği önce kaldırır
{
    resetStubs();
    const g = makeGame(() => {});
    g.start({ seed: 782, ranked: false, meta: {} });
    let lastTick = -1;
    runLoop(g, {
        frameMs: 1000 / 60,
        maxFrames: 4000,
        until: () => g.getRun() && g.getRun().tick >= 60,
        onFrame: () => {
            const run = g.getRun();
            if (!run || run.tick === lastTick) return;
            lastTick = run.tick;
            if (run.tick === 10) key('keydown', 'ArrowDown');
            if (run.tick === 18) g.requestAction(ACTIONS.JUMP);
            if (run.tick === 40) key('keyup', 'ArrowDown');
        },
    });
    const recorded = g.getInputs();
    const jumps = recorded.filter((pair) => pair[1] === ACTIONS.JUMP).length;
    g.destroy();
    ok(jumps === 1, 'eğikken zıplama isteği sıraya alındı (' + jumps + ')');
}

/* ---------------- 4) bot sürüşü: boss'a ulaş, kaydı yeniden oynat ---------------- */

{
    // Bot kararları denetleyiciye niyet olarak verilir; denetleyici KENDİ girdi
    // listesini üretir. Amaç: uzun turda (boss dahil) kayıt/görsel yollarının
    // istisna atmadan çalıştığını ve kaydın motorda birebir oynadığını görmek.
    const { playBot } = await import('../../tools/minigame-bot.mjs');
    const plan = playBot(1, { maxTicks: CONFIG.maxTicks });
    const byTick = new Map();
    for (const [tick, action] of plan.inputs) {
        if (!byTick.has(tick)) byTick.set(tick, []);
        byTick.get(tick).push(action);
    }

    resetStubs();
    let botEnd = null;
    let sawBoss = false;
    let maxKills = 0;
    const g = makeGame((payload) => { botEnd = payload; });
    g.start({ seed: 1, ranked: true, meta: {} });
    let seenTick = -1;
    runLoop(g, {
        frameMs: 1000 / 60,
        maxFrames: 13000,
        until: () => botEnd !== null,
        onFrame: () => {
            const run = g.getRun();
            if (!run || run.tick === seenTick) return;
            seenTick = run.tick;
            const actions = byTick.get(run.tick) || [];
            let fireHold = false;
            let crouchHold = false;
            for (let back = 0; back < 12; back += 1) {
                const past = byTick.get(run.tick - back) || [];
                if (past.includes(ACTIONS.FIRE)) fireHold = true;
                if (past.includes(ACTIONS.CROUCH)) crouchHold = true;
                if (past.includes(ACTIONS.STAND)) crouchHold = false;
            }
            g.setHold('fire', fireHold);
            g.setHold('crouch', crouchHold);
            if (actions.includes(ACTIONS.JUMP)) g.requestAction(ACTIONS.JUMP);
            if (actions.includes(ACTIONS.DASH)) g.requestAction(ACTIONS.DASH);
            if (run.boss && run.boss.hp > 0) sawBoss = true;
            maxKills = Math.max(maxKills, run.kills);
        },
    });
    const botResult = botEnd ? botEnd.result : null;
    const botReplay = botEnd ? simulate(1, botEnd.inputs) : null;
    g.destroy();

    ok(botResult !== null, 'bot sürüşünde tur bitti');
    ok(sawBoss, 'boss sahneye girdi ve çizildi');
    ok(maxKills >= 5, 'denetleyici düşman alt etti (' + maxKills + ')');
    ok(botResult && botResult.ticks > 1800, 'tur 30 saniyeden uzun sürdü (' + (botResult ? (botResult.ticks / 60).toFixed(1) : '0') + ' sn)');
    ok(botResult && botEnd.inputs.length < 1000, 'girdi sayısı sınırın altında (' + (botEnd ? botEnd.inputs.length : 0) + ' < 3000)');
    ok(botReplay && botResult && botReplay.score === botResult.score && botReplay.ticks === botResult.ticks, 'bot sürüşü kaydı motorda birebir oynadı');
}

/* ---------------- 5) sözleşme: skor istemcide hesaplanmaz ---------------- */

{
    resetStubs();
    const g = makeGame(() => {});
    g.start({ seed: 424242, ranked: true, meta: { run_id: 'r' } });
    ok(typeof g.requestAction === 'function' && typeof g.setHold === 'function', 'girdi API açık');
    ok(g.getState() === 'countdown', 'başlangıçta geri sayım');
    ok(g.getInputs().length === 0, 'başlangıçta girdi listesi boş');
    g.destroy();
}

console.log('');
if (failures.length) {
    console.log(`SONUÇ: BAŞARISIZ — ${failures.length} hata / ${checks} kontrol`);
    failures.forEach((failure) => console.log(' - ' + failure));
    process.exit(1);
}
console.log(`SONUÇ: BAŞARILI — ${checks} kontrol`);
