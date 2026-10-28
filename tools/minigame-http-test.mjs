/**
 * Mini Oyun — HTTP duman testi (php-wasm önizleme sunucusu üzerinde).
 *
 *   cd tools && node minigame-http-test.mjs
 *
 * Kapsam: giriş, CSRF, koşu başlatma/gönderme, idempotent tekrar gönderim,
 * sıralama, katalog, yetkisiz erişim ve sayfa hatası kontrolü.
 * NOT: Süre doğrulaması için koşu gerçekten beklenir (birkaç saniye).
 */
import { spawn } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { simulate, CONFIG } from '../assets/js/minigame/engine.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env.PORT || 8123);
const BASE = `http://127.0.0.1:${PORT}`;

let passed = 0;
const failures = [];

function check(name, ok, detail = '') {
    if (ok) {
        passed += 1;
        return;
    }
    failures.push(name + (detail ? ` → ${detail}` : ''));
    console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
}

const cookies = new Map();

function cookieHeader() {
    return [...cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

function storeCookies(response) {
    const list = typeof response.headers.getSetCookie === 'function'
        ? response.headers.getSetCookie()
        : (response.headers.get('set-cookie') ? [response.headers.get('set-cookie')] : []);
    for (const raw of list) {
        const [pair] = raw.split(';');
        const index = pair.indexOf('=');
        if (index > 0) cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
    }
}

async function request(url, options = {}) {
    const headers = { ...(options.headers || {}) };
    if (cookies.size) headers.cookie = cookieHeader();
    const response = await fetch(BASE + url, { ...options, headers, redirect: 'manual' });
    storeCookies(response);
    const text = await response.text();
    return { response, text, status: response.status };
}

async function jsonRequest(url, body) {
    const { response, text, status } = await request(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json', accept: 'application/json' },
        body: JSON.stringify(body),
    });
    let data = null;
    try {
        data = JSON.parse(text);
    } catch {
        data = { raw: text.slice(0, 300) };
    }
    return { status, data, response };
}

function extractCsrf(html) {
    const input = html.match(/name="csrf"\s+value="([^"]+)"/);
    if (input) return input[1];
    const bootstrap = html.match(/id="mg-bootstrap"[^>]*>([\s\S]*?)<\/script>/);
    if (bootstrap) {
        try {
            return JSON.parse(bootstrap[1]).csrf;
        } catch {
            return null;
        }
    }
    return null;
}

/* ---------------- sunucu ---------------- */

console.log('[test] önizleme sunucusu başlatılıyor…');
const server = spawn(process.execPath, ['preview-server.mjs'], {
    cwd: here,
    env: { ...process.env, PORT: String(PORT) },
    stdio: ['ignore', 'pipe', 'pipe'],
});
let serverLog = '';
server.stdout.on('data', (chunk) => { serverLog += chunk.toString(); });
server.stderr.on('data', (chunk) => { serverLog += chunk.toString(); });

let ready = false;
for (let i = 0; i < 120; i += 1) {
    if (serverLog.includes('hazır')) {
        ready = true;
        break;
    }
    if (server.exitCode !== null) break;
    await sleep(500);
}
if (!ready) {
    console.error('Sunucu başlamadı:\n' + serverLog);
    process.exit(1);
}
await sleep(600);

try {
    /* ---------------- misafir ---------------- */

    console.log('\n[Misafir]');
    const guestPage = await request('/mini-oyun.php');
    check('sayfa 200', guestPage.status === 200, String(guestPage.status));
    check('PHP hatası yok', !/(?:Fatal error|Parse error|Warning|Notice):/.test(guestPage.text), guestPage.text.slice(0, 200));
    check('bootstrap JSON var', guestPage.text.includes('id="mg-bootstrap"'));
    check('oyun tuvali var', guestPage.text.includes('id="mg-canvas"'));
    check('CSS bağlı', guestPage.text.includes('assets/css/minigame.css'));
    check('app.js modül olarak yüklü', guestPage.text.includes('assets/js/minigame/app.js'));
    check('mini oyun menüde', guestPage.text.includes('mini-oyun.php'));

    const guestBootstrap = JSON.parse(guestPage.text.match(/id="mg-bootstrap"[^>]*>([\s\S]*?)<\/script>/)[1]);
    check('misafirde kullanıcı yok', guestBootstrap.state.user === null);
    check('katalog gönderildi', Array.isArray(guestBootstrap.catalog) && guestBootstrap.catalog.length >= 12);

    const guestStart = await jsonRequest('/api/minigame/start.php', { csrf: guestBootstrap.csrf });
    check('misafir koşu başlatamaz', guestStart.status === 401 && guestStart.data.code === 'auth', `${guestStart.status} ${guestStart.data.code}`);

    /* ---------------- giriş ---------------- */

    console.log('\n[Giriş]');
    const loginPage = await request('/login.php');
    const loginCsrf = extractCsrf(loginPage.text);
    check('giriş sayfası CSRF içerir', !!loginCsrf);
    const loginForm = new URLSearchParams({
        csrf: loginCsrf,
        action: 'login',
        login: 'oyuncu1',
        password: 'Admin123!',
        next: 'mini-oyun.php',
        _back: 'login.php',
    });
    const loginResponse = await request('/auth.php', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: loginForm.toString(),
    });
    check('giriş yönlendirdi', loginResponse.status === 302 || loginResponse.status === 303, String(loginResponse.status));
    check('oturum çerezi alındı', [...cookies.keys()].some((key) => key.startsWith('PHPSESSID')));
    const loginLocation = loginResponse.response.headers.get('location') || '';
    check('giriş sonrası mini oyuna döner', loginLocation.includes('mini-oyun.php'), loginLocation);

    const page = await request('/mini-oyun.php');
    check('girişli sayfa 200', page.status === 200, String(page.status));
    check('girişli sayfada hata yok', !/(?:Fatal error|Parse error|Warning|Notice):/.test(page.text));
    const boot = JSON.parse(page.text.match(/id="mg-bootstrap"[^>]*>([\s\S]*?)<\/script>/)[1]);
    check('oturum kullanıcısı', boot.state.user && boot.state.user.username === 'oyuncu1');
    check('cüzdan bilgisi geldi', typeof boot.state.wallet.balance === 'number');
    check('günlük görevler geldi', Array.isArray(boot.state.wallet.tasks) && boot.state.wallet.tasks.length === 6, String((boot.state.wallet.tasks || []).length));
    check('görevlerde boss hedefi var', (boot.state.wallet.tasks || []).some((task) => task.metric === 'boss_kills'));
    check('motor sürümü v2', boot.state.engine.version === 'v2', boot.state.engine.version);
    check('tur üst sınırı 180 sn', boot.state.engine.maxTicks === 10800, String(boot.state.engine.maxTicks));
    check('girdi sınırı 3000', boot.state.max_inputs === 3000, String(boot.state.max_inputs));
    check('ekonomi onayı etiketli', boot.state.wallet.economy_approved === false);
    check('CSRF var', typeof boot.csrf === 'string' && boot.csrf.length > 10);

    /* ---------------- API ---------------- */

    console.log('\n[API]');
    const state = await request('/api/minigame/state.php', { headers: { accept: 'application/json' } });
    const stateJson = JSON.parse(state.text);
    check('state ok', state.status === 200 && stateJson.ok === true);

    const noCsrf = await jsonRequest('/api/minigame/start.php', {});
    check('CSRF zorunlu', noCsrf.status === 403, `${noCsrf.status} ${noCsrf.data.code}`);

    const start = await jsonRequest('/api/minigame/start.php', { csrf: boot.csrf });
    check('koşu başladı', start.status === 200 && start.data.ok === true, JSON.stringify(start.data).slice(0, 200));
    check('seed döndü', Number.isInteger(start.data.seed));
    check('token döndü', typeof start.data.token === 'string' && start.data.token.length === 64);
    check('deneme sayacı', start.data.attempts >= 1);
    check('koşu v2 sürümü', start.data.version === 'v2', String(start.data.version));
    check('koşu 180 sn sınırı', start.data.max_ticks === CONFIG.maxTicks, String(start.data.max_ticks));
    check('koşu yapılandırması geldi', !!start.data.config && start.data.config.maxHp === 100);

    // v2 aksiyonları (eğil/kalk/ateş/dash/zıpla) sunucudan geçmeli. Tur,
    // girdiler bittikten sonra can bitene kadar (ya da 180 sn) sürer; süre
    // doğrulaması için gerçek tick süresi kadar beklenir.
    const inputs = [[5, 4], [40, 5], [60, 3], [70, 3], [90, 6], [120, 1], [128, 2], [150, 3], [200, 4], [260, 5]];
    const expected = simulate(start.data.seed, inputs);
    const waitMs = Math.ceil((expected.ticks / CONFIG.tickHz) * 1000) + 1200;
    console.log(`[test] tur ${expected.ticks} tick (${(expected.ticks / 60).toFixed(1)} sn) → ${(waitMs / 1000).toFixed(1)} sn bekleniyor…`);
    await sleep(waitMs);

    const submit = await jsonRequest('/api/minigame/submit.php', {
        csrf: boot.csrf,
        run_id: start.data.run_id,
        token: start.data.token,
        inputs,
    });
    check('v2 turu doğrulandı', submit.status === 200 && submit.data.ok === true, JSON.stringify(submit.data).slice(0, 200));
    check('sonuç sunucudan', submit.data.result && submit.data.result.score > 0, JSON.stringify(submit.data.result));
    check('sunucu skoru istemci motoruyla aynı', submit.data.result && submit.data.result.score === expected.score, `${submit.data.result && submit.data.result.score} ≠ ${expected.score}`);
    check('sunucu tick sayısı aynı', submit.data.result && submit.data.result.ticks === expected.ticks, `${submit.data.result && submit.data.result.ticks} ≠ ${expected.ticks}`);
    check(
        'v2 sonuç alanları',
        !!submit.data.result && Number.isInteger(submit.data.result.kills) && Number.isInteger(submit.data.result.hp)
            && typeof submit.data.result.boss_killed === 'boolean' && Number.isInteger(submit.data.result.damage_taken),
        JSON.stringify(submit.data.result).slice(0, 200)
    );
    check('kısa tur kuralı (min ticks)', submit.data.counts === (expected.ticks >= 600), `ticks=${expected.ticks} counts=${submit.data.counts}`);

    const again = await jsonRequest('/api/minigame/submit.php', {
        csrf: boot.csrf,
        run_id: start.data.run_id,
        token: start.data.token,
        inputs,
    });
    check('tekrar gönderim idempotent', again.status === 200 && again.data.duplicate === true, JSON.stringify(again.data).slice(0, 200));

    const runStatus = await request('/api/minigame/run.php?id=' + encodeURIComponent(start.data.run_id), { headers: { accept: 'application/json' } });
    const runJson = JSON.parse(runStatus.text);
    check('koşu durumu sorgulanabilir', runStatus.status === 200 && runJson.status === 'verified', runJson.status);

    const foreign = await request('/api/minigame/run.php?id=run_000000000000000000000000', { headers: { accept: 'application/json' } });
    check('yabancı/olmayan koşu 404', foreign.status === 404);

    const board = await request('/api/minigame/leaderboard.php?board=today&page=1', { headers: { accept: 'application/json' } });
    const boardJson = JSON.parse(board.text);
    check('sıralama ok', board.status === 200 && boardJson.ok === true);
    check('sıralamada e-posta sızmaz', !board.text.includes('@'));
    check('sıralama sayfalama', typeof boardJson.pages === 'number');

    const season = await request('/api/minigame/season.php', { headers: { accept: 'application/json' } });
    const seasonJson = JSON.parse(season.text);
    check('sezon ok', season.status === 200 && seasonJson.ok === true);

    const catalog = await request('/api/minigame/catalog.php', { headers: { accept: 'application/json' } });
    const catalogJson = JSON.parse(catalog.text);
    check('katalog ok', catalog.status === 200 && Array.isArray(catalogJson.items));
    check('fiyatlar tam sayı', catalogJson.items.every((item) => Number.isInteger(item.price)));

    const buyDisabled = await jsonRequest('/api/minigame/buy.php', { csrf: boot.csrf, item_id: 'trail-pulse' });
    check('mağaza kapalıyken satın alma engelli', buyDisabled.status === 409 && buyDisabled.data.code === 'shop_disabled', JSON.stringify(buyDisabled.data));

    const unownedEquip = await jsonRequest('/api/minigame/equip.php', { csrf: boot.csrf, slot: 'trail', item_id: 'trail-pulse' });
    check('sahip olunmayan kuşanılamaz', unownedEquip.status === 409 && unownedEquip.data.code === 'not_owned', JSON.stringify(unownedEquip.data));

    const badJson = await request('/api/minigame/submit.php', {
        method: 'POST',
        headers: { 'content-type': 'application/json', accept: 'application/json' },
        body: '{bozuk',
    });
    check('bozuk JSON reddedilir', badJson.status === 400, String(badJson.status));

    const wrongMethod = await request('/api/minigame/start.php', { headers: { accept: 'application/json' } });
    check('yanlış metot', wrongMethod.status === 405, String(wrongMethod.status));

    /* ---------------- yönetici ---------------- */

    console.log('\n[Yönetici]');
    const adminAsPlayer = await request('/admin/minigame.php');
    check('oyuncu admin paneline giremez', adminAsPlayer.status === 403, String(adminAsPlayer.status));

    // Yönetici oturumu (aynı çerez kavanozu yeniden giriş yapar)
    console.log('\n[Yönetici panelleri]');
    await request('/logout.php');
    cookies.clear();
    const adminLoginPage = await request('/login.php');
    const adminCsrf = extractCsrf(adminLoginPage.text);
    const adminForm = new URLSearchParams({
        csrf: adminCsrf,
        action: 'login',
        login: 'admin@devicegame.com',
        password: 'Admin123!',
        next: 'admin/minigame.php',
        _back: 'login.php',
    });
    const adminLogin = await request('/auth.php', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: adminForm.toString(),
    });
    check('yönetici girişi', adminLogin.status === 302 || adminLogin.status === 303, String(adminLogin.status));

    for (const bolum of ['ozet', 'ayarlar', 'gorevler', 'katalog', 'inceleme', 'sezon', 'rapor']) {
        const adminPage = await request('/admin/minigame.php?bolum=' + bolum);
        check(`panel ${bolum}`, adminPage.status === 200, String(adminPage.status));
        check(`panel ${bolum} hatasız`, !/(?:Fatal error|Parse error|Warning|Notice|Deprecated):/.test(adminPage.text), adminPage.text.slice(0, 300));
        if (bolum !== 'inceleme') {
            check(`panel ${bolum} CSRF içerir`, adminPage.text.includes('name="csrf"'));
        }
    }

    const settingsPage = await request('/admin/minigame.php?bolum=ayarlar');
    const settingsCsrfMatch = settingsPage.text.match(/name="csrf"\s+value="([^"]+)"/);
    check('ayarlar formu CSRF alanı', !!settingsCsrfMatch);
    if (!settingsCsrfMatch) throw new Error('ayarlar formunda csrf yok: ' + settingsPage.text.slice(0, 300));
    const settingsCsrf = settingsCsrfMatch[1];
    const saveSettings = await request('/admin/minigame.php?bolum=ayarlar', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf: settingsCsrf, action: 'save_settings', 'flags[game]': '1', 'flags[practice]': '1', 'flags[leaderboard]': '1' }).toString(),
    });
    check('ayar kaydı yönlendirir', saveSettings.status === 302, String(saveSettings.status));
    const afterSave = await request('/admin/minigame.php?bolum=ayarlar');
    check('ayar kaydı bildirimi', afterSave.text.includes('kaydedildi'), afterSave.text.slice(0, 200));

    // Sezon oluşturma (taslak) ve genel API'de görünürlük
    const seasonPage = await request('/admin/minigame.php?bolum=sezon');
    const seasonCsrf = seasonPage.text.match(/name="csrf"\s+value="([^"]+)"/)[1];
    const createSeason = await request('/admin/minigame.php?bolum=sezon', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf: seasonCsrf,
            action: 'season_create',
            title: 'HTTP Test Sezonu',
            prize_name: 'Örnek ödül (DEMO)',
            platform: 'PC',
            region: 'Türkiye',
            eligibility: 'Test',
            participation: 'Test',
            results_note: 'Test',
            delivery_note: 'Test',
            start_local: new Date(Date.now() - 3600_000).toISOString().slice(0, 16),
            end_local: new Date(Date.now() + 86400_000).toISOString().slice(0, 16),
        }).toString(),
    });
    check('sezon oluşturma yönlendirir', createSeason.status === 302, String(createSeason.status));
    const seasonAfter = await request('/admin/minigame.php?bolum=sezon');
    check('sezon listede', seasonAfter.text.includes('HTTP Test Sezonu'));
    check('sezon paneli hatasız', !/(?:Fatal error|Warning|Notice):/.test(seasonAfter.text), seasonAfter.text.slice(0, 300));

    const seasonApi = await request('/api/minigame/season.php', { headers: { accept: 'application/json' } });
    const seasonApiJson = JSON.parse(seasonApi.text);
    check('sezon API yanıtı', seasonApi.status === 200 && seasonApiJson.ok === true);
    check('taslak sezon herkese açık değil', seasonApiJson.active === null || seasonApiJson.active.status !== 'draft');
    check('sezon API anahtar sızdırmaz', !/token|secret|key/i.test(seasonApi.text));

    console.log('\n[Sayfalar]');
    for (const path of ['/', '/shop.php', '/sosyal/index.php', '/mini-oyun.php']) {
        const pageCheck = await request(path);
        check(`regresyon ${path}`, pageCheck.status === 200 && !/(?:Fatal error|Parse error):/.test(pageCheck.text), String(pageCheck.status));
    }
} finally {
    server.kill('SIGTERM');
    await sleep(300);
}

console.log('');
if (failures.length) {
    console.log(`SONUÇ: BAŞARISIZ — ${failures.length} hata / ${passed + failures.length} kontrol`);
    failures.forEach((failure) => console.log(' - ' + failure));
    process.exit(1);
}
console.log(`SONUÇ: BAŞARILI — ${passed} kontrol`);
