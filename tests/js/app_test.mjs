/**
 * Mini Oyun — arayüz (app.js) DOM testi.
 *
 * Gerçek tarayıcı yerine jsdom kullanır: sayfa HTML'i php-wasm önizleme
 * sunucusundan alınır, canvas 2D bağlamı stub'lanır ve zaman sanal olarak
 * ilerletilir. Böylece app.js'in gerçek DOM kimlikleriyle uyumu ve temel
 * akış (antrenman turu, sonuç ekranı, sekme geçişi) doğrulanır.
 *
 * Çalıştırma: cd tools && node ../tests/js/app_test.mjs
 */
import { spawn } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = dirname(fileURLToPath(import.meta.url));
const toolsDir = join(here, '..', '..', 'tools');
const { JSDOM } = createRequire(join(toolsDir, 'package.json'))('jsdom');
const PORT = Number(process.env.APP_TEST_PORT || 8124);
const BASE = `http://127.0.0.1:${PORT}`;

let checks = 0;
const failures = [];
function check(name, ok, detail = '') {
    checks += 1;
    if (!ok) {
        failures.push(name + (detail ? ` → ${detail}` : ''));
        console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
    }
}

/* ---------------- sayfa HTML'i ---------------- */

const server = spawn(process.execPath, ['preview-server.mjs'], {
    cwd: toolsDir,
    env: { ...process.env, PORT: String(PORT) },
    stdio: ['ignore', 'pipe', 'pipe'],
});
let log = '';
server.stdout.on('data', (c) => { log += c.toString(); });
server.stderr.on('data', (c) => { log += c.toString(); });
for (let i = 0; i < 120 && !log.includes('hazır'); i += 1) {
    if (server.exitCode !== null) break;
    await sleep(500);
}
if (!log.includes('hazır')) {
    console.error('Önizleme sunucusu başlamadı:\n' + log);
    process.exit(1);
}

let html = '';
try {
    const response = await fetch(BASE + '/mini-oyun.php');
    html = await response.text();
    check('sayfa alındı', response.status === 200, String(response.status));
} finally {
    server.kill('SIGTERM');
    await sleep(200);
}

check('HTML PHP hatası içermiyor', !/(?:Fatal error|Parse error|Warning|Notice):/.test(html));

/* ---------------- DOM + stub'lar ---------------- */

const dom = new JSDOM(html, { url: BASE + '/mini-oyun.php', pretendToBeVisual: false });
const { window } = dom;
const { document } = window;

function makeContext() {
    const gradient = { addColorStop() {} };
    const base = {
        canvas: null,
        createLinearGradient: () => gradient,
        createRadialGradient: () => gradient,
        createPattern: () => null,
        measureText: () => ({ width: 42 }),
        getImageData: () => ({ data: new Uint8ClampedArray(4) }),
        putImageData() {},
        setLineDash() {},
        getLineDash: () => [],
        save() {}, restore() {}, beginPath() {}, closePath() {},
        moveTo() {}, lineTo() {}, arc() {}, arcTo() {}, rect() {}, roundRect() {},
        fill() {}, stroke() {}, clip() {}, fillRect() {}, strokeRect() {}, clearRect() {},
        fillText() {}, strokeText() {},
        translate() {}, rotate() {}, scale() {}, setTransform() {}, resetTransform() {},
        drawImage() {}, quadraticCurveTo() {}, bezierCurveTo() {}, ellipse() {},
    };
    return new Proxy(base, {
        get(target, prop) {
            if (prop in target) return target[prop];
            if (prop === 'globalAlpha' || prop === 'lineWidth' || prop === 'shadowBlur') return 1;
            return undefined;
        },
        set(target, prop, value) {
            target[prop] = value;
            return true;
        },
    });
}

window.HTMLCanvasElement.prototype.getContext = function getContext() {
    return makeContext();
};
window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener() {},
    removeEventListener() {},
    addListener() {},
    removeListener() {},
    dispatchEvent() { return false; },
});

const rafQueue = [];
let rafTime = 0;
let rafId = 0;
window.requestAnimationFrame = (callback) => {
    rafId += 1;
    rafQueue.push({ id: rafId, callback });
    return rafId;
};
window.cancelAnimationFrame = (id) => {
    const index = rafQueue.findIndex((entry) => entry.id === id);
    if (index >= 0) rafQueue.splice(index, 1);
};

const fetchLog = [];
window.fetch = async (url, init = {}) => {
    const clean = String(url).replace(/^.*api\/minigame\//, '');
    fetchLog.push({ url: clean, method: init.method || 'GET' });
    const json = (payload, status = 200) => ({
        ok: status >= 200 && status < 300,
        status,
        json: async () => payload,
    });
    if (clean.startsWith('leaderboard.php')) {
        return json({
            ok: true,
            rows: [
                { rank: 1, username: 'oyuncu1', score: 1200, seconds: 60, cells: 20, achieved_at: '2026-10-28 09:00 (TR)', is_me: false, visual: { primary: '#c5f46f' } },
            ],
            page: 1,
            pages: 1,
            me: null,
            total: 1,
        });
    }
    if (clean.startsWith('state.php')) {
        return json({ ok: true, flags: {}, season: null, champions: [], attempts: 0, attempt_limit: 20 });
    }
    return json({ ok: false, code: 'not_stubbed', error: 'stub yok: ' + clean }, 500);
};

Object.assign(globalThis, {
    window,
    document,
    localStorage: window.localStorage,
    HTMLElement: window.HTMLElement,
    Element: window.Element,
    Event: window.Event,
    KeyboardEvent: window.KeyboardEvent,
    MouseEvent: window.MouseEvent,
    requestAnimationFrame: window.requestAnimationFrame,
    cancelAnimationFrame: window.cancelAnimationFrame,
    matchMedia: window.matchMedia,
    getComputedStyle: window.getComputedStyle.bind(window),
    history: window.history,
    location: window.location,
    fetch: window.fetch,
});
Object.defineProperty(globalThis, 'navigator', { value: window.navigator, configurable: true });
Object.defineProperty(window, 'performance', { value: { now: () => rafTime }, configurable: true });
globalThis.performance = window.performance;

/** Koşul sağlanana kadar kare ilerletir (tur 3 dakikaya kadar sürebilir). */
function pumpUntil(predicate, maxFrames = 12000) {
    for (let i = 0; i < maxFrames; i += 1) {
        if (predicate()) return true;
        pump(1);
    }
    return predicate();
}

function pump(frames = 1, stepMs = 1000 / 60) {
    for (let i = 0; i < frames; i += 1) {
        rafTime += stepMs;
        const queue = rafQueue.splice(0, rafQueue.length);
        for (const entry of queue) {
            if (!rafQueue.some((item) => item.id === entry.id)) {
                try {
                    entry.callback(rafTime);
                } catch (error) {
                    failures.push('rAF hatası: ' + error.message);
                    console.log('  ✗ rAF hatası —', error.message, error.stack?.split('\n')[1] || '');
                }
            }
        }
    }
}

await import('file://' + join(here, '..', '..', 'assets', 'js', 'minigame', 'app.js') + '?test=' + Date.now());
pump(2);

/* ---------------- kontroller ---------------- */

const $ = (sel) => document.querySelector(sel);
const text = (sel) => ($(sel) ? $(sel).textContent.trim() : '');

check('bootstrap JSON okunuyor', !!$('#mg-bootstrap'));
check('başlangıç overlay metni', text('#mg-overlay-card').includes('Misafir antrenmanı'), text('#mg-overlay-card').slice(0, 80));
check('görevler giriş çağrısı gösterir', text('#mg-tasks').toLowerCase().includes('giriş yap'), text('#mg-tasks').slice(0, 80));
check('cüzdan notu giriş istiyor', text('#mg-cap-text').toLowerCase().includes('giriş'), text('#mg-cap-text'));

const shopItems = document.querySelectorAll('#mg-shop-grid .mg-item');
check('mağazada 12 kozmetik', shopItems.length === 12, String(shopItems.length));

// v2 kontrol düğmeleri
const padIds = ['mg-jump', 'mg-crouch', 'mg-fire', 'mg-dash'];
check('dört kontrol düğmesi var', padIds.every((id) => !!$('#' + id)), padIds.map((id) => !!$('#' + id)).join(','));
check('kontroller başlangıçta kapalı', padIds.every((id) => $('#' + id).disabled === true));
check('eğil düğmesi etiketli', ($('#mg-crouch').getAttribute('aria-label') || '').includes('Eğil'));
check('ateş düğmesi etiketli', ($('#mg-fire').getAttribute('aria-label') || '').includes('Ateş'));
check('dash düğmesi etiketli', ($('#mg-dash').getAttribute('aria-label') || '').includes('Dash'));
check('tuval etiketi eğilmeyi anlatıyor',
    ($('#mg-canvas').getAttribute('aria-label') || '').toLowerCase().includes('eğil'),
    ($('#mg-canvas').getAttribute('aria-label') || '').slice(0, 60));
check('kurallar boss fazını anlatıyor', text('#mg-rules-title') !== '' && (document.querySelector('.mg-rules') || { textContent: '' }).textContent.includes('Boss'));
check('sonuç ekranı düğmeleri sonradan açılır', $('#mg-fire').disabled === true);
check('mağaza notu (kapalı/örnek)', !$('#mg-shop-note').hidden, 'not gizli');
check('mağaza kartlarında tuval var', document.querySelectorAll('#mg-shop-grid canvas').length === 12);

check('gardırop giriş istiyor', text('#mg-wardrobe').toLowerCase().includes('giriş yap'), text('#mg-wardrobe').slice(0, 80));
check('sezon bloğu kapalı bilgisi', text('#mg-season').length > 0);
check('şampiyonlar boş durumu', text('#mg-champions').includes('şampiyon'), text('#mg-champions').slice(0, 80));

// Sekme geçişi
document.querySelector('.mg-tab[data-tab="rekorlar"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
check('sıralama paneli açıldı', !$('#mg-panel-rekorlar').hidden && $('#mg-panel-oyna').hidden);
await sleep(20);
pump(2);
check('sıralama isteği atıldı', fetchLog.some((entry) => entry.url.startsWith('leaderboard.php')), JSON.stringify(fetchLog));
check('sıralama satırı çizildi', document.querySelectorAll('#mg-board-body tr').length === 1);
check('sıralama skoru biçimli', text('#mg-board-body').includes('1.200') || text('#mg-board-body').includes('1200'), text('#mg-board-body').slice(0, 80));
check('kendi sıran yok mesajı', text('#mg-board-me').toLowerCase().includes('giriş'), text('#mg-board-me'));

document.querySelector('.mg-tab[data-tab="oyna"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
check('oyna paneline dönüldü', !$('#mg-panel-oyna').hidden);

// Ses düğmesi
const sound = $('#mg-sound');
const before = sound.getAttribute('aria-pressed');
sound.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
check('ses düğmesi durumu değişti', sound.getAttribute('aria-pressed') !== before, `${before} → ${sound.getAttribute('aria-pressed')}`);
check('ses tercihi saklandı', window.localStorage.getItem('dg-mg-sound') === 'off', String(window.localStorage.getItem('dg-mg-sound')));
sound.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

// Antrenman turu: overlay'deki "Antrenman" düğmesi
const practiceButton = [...document.querySelectorAll('#mg-overlay-card button')]
    .find((button) => button.textContent.includes('Antrenman'));
check('antrenman düğmesi var', !!practiceButton);
practiceButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
pump(1);
check('geri sayım göründü', text('#mg-overlay-card').includes('Hazır'), text('#mg-overlay-card').slice(0, 40));
check('sahne canlı sınıfı', $('#mg-stage').classList.contains('is-live'));

// 3 saniyelik geri sayım + 3 saniyelik oyun
pump(190);
check('geri sayım bitti', $('#mg-jump').disabled === false);
check('eğil/ateş/dash oyun sırasında açık', ['mg-crouch', 'mg-fire', 'mg-dash'].every((id) => $('#' + id).disabled === false));
check('overlay gizlendi', $('#mg-overlay').hidden === true);

window.dispatchEvent(new window.KeyboardEvent('keydown', { code: 'Space', key: ' ' }));
pump(40);
window.dispatchEvent(new window.KeyboardEvent('keyup', { code: 'Space', key: ' ' }));
// Ateş düğmesi basılı tutulunca mermi üretmeli (motor içinde doğrulanır).
const fire = $('#mg-fire');
fire.dispatchEvent(new window.MouseEvent('pointerdown', { bubbles: true }));
pump(40);
fire.dispatchEvent(new window.MouseEvent('pointerup', { bubbles: true }));
check('ateş düğmesi aria-pressed bırakıldı', fire.getAttribute('aria-pressed') !== 'true');
pump(130);

// Tur can bitişi veya 180 sn sınırıyla biter → sonuç ekranı
const finished = pumpUntil(() => $('#mg-overlay').hidden === false, 13000);
check('tur bitti (sonuç göründü)', finished && text('#mg-overlay-card').includes('Antrenman tamamlandı'), text('#mg-overlay-card').slice(0, 80));
check('sonuç skor alanı', text('#mg-overlay-card').includes('Skor'), text('#mg-overlay-card').slice(0, 120));
check('tekrar oyna düğmesi', [...document.querySelectorAll('#mg-overlay-card button')].some((b) => b.textContent.includes('Tekrar')));

// Görünürlük politikası: misafir antrenmanı duraklar (sıralı tur iptal edilir)
document.dispatchEvent(new window.Event('visibilitychange'));
check('görünürlük olayı hata vermedi', true);

// Hata kaydı
check('çalışma sırasında hata yok', failures.length === 0, failures.join(' | '));

console.log('');
if (failures.length) {
    console.log(`SONUÇ: BAŞARISIZ — ${failures.length} hata / ${checks} kontrol`);
    failures.forEach((failure) => console.log(' - ' + failure));
    process.exit(1);
}
console.log(`SONUÇ: BAŞARILI — ${checks} kontrol`);
process.exit(0);
