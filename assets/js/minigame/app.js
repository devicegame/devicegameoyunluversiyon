/**
 * Device Game — Mini Oyun arayüz denetleyicisi.
 *
 * Sunucu sözleşmesi:
 *  - POST api/minigame/start.php   → {run_id, token, seed, ...}
 *  - POST api/minigame/submit.php  → {status: verified|pending, result, rewards}
 *  - GET  api/minigame/run.php?id= → idempotent durum sorgusu
 *  - POST api/minigame/buy.php, equip.php
 *  - GET  api/minigame/{state,leaderboard,catalog,season}.php
 *
 * Kullanıcı verisi DOM'a `textContent` ile yazılır (XSS güvenli).
 */
import { createGame } from './game.js';
import { encodeInputs } from './engine.js';

const bootEl = document.getElementById('mg-bootstrap');
const boot = bootEl ? JSON.parse(bootEl.textContent || '{}') : {};
const API = (boot.urls && boot.urls.api) || 'api/minigame/';

const state = boot.state || {};
const flags = state.flags || {};
const logged = !!state.user;
let wallet = state.wallet || null;
let catalog = Array.isArray(boot.catalog) ? boot.catalog : [];
let owned = Array.isArray(boot.owned) ? boot.owned.slice() : [];
let loadout = boot.loadout || {};
let visuals = boot.visuals || {};
let board = null;
let boardType = 'today';
let shopFilter = 'all';
function storedSound() {
    try {
        return localStorage.getItem('dg-mg-sound') !== 'off';
    } catch (error) {
        return true;
    }
}

let soundOn = storedSound();
let reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let submitting = false;
let activeRunMeta = null;
let pendingSubmit = null;

const $ = (sel, root) => (root || document).querySelector(sel);
const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

function el(tag, attrs, children) {
    const node = document.createElement(tag);
    if (attrs) {
        for (const [key, value] of Object.entries(attrs)) {
            if (value === null || value === undefined || value === false) continue;
            if (key === 'class') node.className = value;
            else if (key === 'text') node.textContent = String(value);
            else if (key === 'style') node.setAttribute('style', value);
            else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
            else node.setAttribute(key, value === true ? '' : String(value));
        }
    }
    (Array.isArray(children) ? children : children ? [children] : []).forEach((child) => {
        if (child === null || child === undefined || child === false) return;
        node.appendChild(typeof child === 'string' || typeof child === 'number'
            ? document.createTextNode(String(child))
            : child);
    });
    return node;
}

function fmt(value) {
    return Number(value || 0).toLocaleString('tr-TR');
}

function toast(message, kind) {
    const wrap = $('#mg-toasts');
    if (!wrap) return;
    const node = el('div', { class: 'mg-toast' + (kind ? ' is-' + kind : ''), role: 'status', text: message });
    wrap.appendChild(node);
    setTimeout(() => node.remove(), 4200);
}

function announce(message) {
    const live = $('#mg-status');
    if (live) live.textContent = message;
}

/* ---------------- ağ ---------------- */

async function api(path, options) {
    const opts = options || {};
    const init = {
        method: opts.method || 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    };
    if (opts.body) {
        init.method = 'POST';
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify({ csrf: boot.csrf, ...opts.body });
    }
    let response;
    try {
        response = await fetch(API + path, init);
    } catch (error) {
        throw Object.assign(new Error('Bağlantı kurulamadı. İnternet bağlantını kontrol et.'), { code: 'offline' });
    }
    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        throw Object.assign(new Error('Sunucu beklenmeyen bir yanıt döndü.'), { code: 'bad_response', status: response.status });
    }
    if (!response.ok || data.ok === false) {
        throw Object.assign(new Error(data.error || 'İşlem tamamlanamadı.'), {
            code: data.code || 'error',
            status: response.status,
            data,
        });
    }
    return data;
}

/* ---------------- modal ---------------- */

let modalState = null;

function openModal(config) {
    const modal = $('#mg-modal');
    const card = $('#mg-modal-card');
    if (!modal || !card) return Promise.resolve(false);
    card.replaceChildren();
    card.appendChild(el('h2', { text: config.title || 'Onay' }));
    if (config.body) card.appendChild(el('p', { text: config.body }));
    const actions = el('div', { class: 'mg-modal-actions' });
    const cancel = el('button', { class: 'mg-btn mg-btn-ghost', type: 'button', text: config.cancelLabel || 'Vazgeç' });
    const confirm = el('button', { class: 'mg-btn mg-btn-primary', type: 'button', text: config.confirmLabel || 'Onayla' });
    actions.append(cancel, confirm);
    card.appendChild(actions);
    modal.hidden = false;

    const previouslyFocused = document.activeElement;
    confirm.focus();

    return new Promise((resolve) => {
        const close = (value) => {
            modal.hidden = true;
            document.removeEventListener('keydown', onKey);
            modalState = null;
            if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus();
            resolve(value);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            }
            if (event.key === 'Tab') {
                event.preventDefault();
                (document.activeElement === cancel ? confirm : cancel).focus();
            }
        };
        modalState = { close };
        cancel.addEventListener('click', () => close(false));
        confirm.addEventListener('click', () => close(true));
        const backdrop = $('.mg-modal-backdrop', modal);
        if (backdrop) backdrop.addEventListener('click', () => close(false), { once: true });
        document.addEventListener('keydown', onKey);
    });
}

/* ---------------- sekmeler ---------------- */

const tabs = $$('.mg-tab');

function selectTab(name) {
    tabs.forEach((tab) => {
        const selected = tab.dataset.tab === name;
        tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        tab.tabIndex = selected ? 0 : -1;
    });
    $$('.mg-panel').forEach((panel) => {
        panel.hidden = panel.id !== 'mg-panel-' + name;
    });
    try {
        history.replaceState(null, '', '#mg-panel-' + name);
    } catch (error) {
        /* yoksay */
    }
    if (name === 'rekorlar' && !board) loadBoard(boardType, 1);
    if (name === 'magaza') renderShop();
    if (name === 'dolabim') renderWardrobe();
}

tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectTab(tab.dataset.tab));
    tab.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        next.focus();
        selectTab(next.dataset.tab);
    });
});

/* ---------------- cüzdan ---------------- */

function renderWallet() {
    const balanceEl = $('#mg-balance');
    if (balanceEl) balanceEl.textContent = fmt(wallet ? wallet.balance : 0);

    const earned = wallet ? Number(wallet.daily_earned || 0) : 0;
    const cap = wallet ? Number(wallet.daily_cap || 0) : 0;
    const fill = $('#mg-cap-fill');
    const bar = $('#mg-cap-bar');
    if (fill && bar) {
        const pct = cap > 0 ? Math.min(100, (earned / cap) * 100) : 0;
        fill.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(Math.round(pct)));
        bar.setAttribute('aria-valuemax', String(cap || 100));
    }
    const earnedEl = $('#mg-earned');
    if (earnedEl) earnedEl.textContent = fmt(earned);
    const capEl = $('#mg-cap');
    if (capEl) capEl.textContent = cap > 0 ? fmt(cap) : '∞';

    const capText = $('#mg-cap-text');
    if (capText) {
        if (!logged) {
            capText.textContent = 'Giriş yapınca günlük D-Coin kazanımı açılır.';
        } else if (wallet && !wallet.rewards_enabled) {
            capText.textContent = 'D-Coin kazanımı yönetici tarafından durduruldu. Oyun oynanabilir.';
        } else if (wallet && wallet.budget_exhausted) {
            capText.textContent = 'Günlük ödül bütçesi doldu; oyun devam eder, yalnızca kazanç durdu.';
        } else if (wallet && wallet.cap_reached) {
            capText.textContent = 'Bugünkü kişisel D-Coin limitin doldu. Yine oynayabilirsin.';
        } else if (cap > 0) {
            capText.textContent = 'Bugün kalan: ' + fmt(Math.max(0, cap - earned)) + ' D-Coin';
        } else {
            capText.textContent = 'Bugün ' + fmt(earned) + ' D-Coin kazanıldı.';
        }
    }

    const attempts = $('#mg-attempts');
    if (attempts) {
        if (!logged) {
            attempts.textContent = 'Sıralı turlar için giriş gerekir.';
        } else {
            const used = Number(state.attempts || 0);
            const limit = Number(state.attempt_limit || 0);
            attempts.textContent = limit > 0
                ? 'Bugünkü sıralı tur: ' + used + ' / ' + limit
                : 'Bugünkü sıralı tur: ' + used;
        }
    }

    const lastRun = $('#mg-last-run');
    if (lastRun) {
        const last = state.last_run;
        if (!last || !logged) {
            lastRun.hidden = true;
        } else if (last.status === 'pending') {
            lastRun.hidden = false;
            lastRun.textContent = 'Son turun incelemede: ' + fmt(last.score) + ' puan. Onaylanınca sıralamaya işlenecek.';
        } else if (last.status === 'rejected') {
            lastRun.hidden = false;
            lastRun.textContent = 'Son turun doğrulanamadı ve sıralamaya işlenmedi.';
        } else if (last.status === 'verified') {
            lastRun.hidden = false;
            lastRun.textContent = 'Son turun: ' + fmt(last.score) + ' puan · ' + (last.ticks / 60).toFixed(1) + ' sn'
                + (last.counts ? '' : ' (sıralama için çok kısa)');
        } else {
            lastRun.hidden = true;
        }
    }

    const note = $('#mg-wallet-note');
    if (note) {
        if (wallet && wallet.budget_exhausted) {
            note.hidden = false;
            note.textContent = 'Günlük global ödül bütçesi doldu. Oyun devam ediyor, yalnızca D-Coin kazanımı durdu.';
        } else if (wallet && wallet.cap_reached) {
            note.hidden = false;
            note.textContent = 'Bugünkü kişisel limitin doldu. Yine oynayabilirsin.';
        } else {
            note.hidden = true;
        }
    }
}

/* ---------------- görevler ---------------- */

const TASK_STATUS = {
    paid: 'Ödül alındı',
    blocked_limit: 'Kişisel limit doldu',
    blocked_budget: 'Günlük bütçe doldu',
    rewards_off: 'Kazanç kapalı',
    zero_reward: 'Ödül tanımsız',
};

function renderTasks() {
    const wrap = $('#mg-tasks');
    if (!wrap) return;
    wrap.replaceChildren();
    if (!logged || !wallet) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Günlük görevler ve D-Coin kazanımı için giriş yap.' }));
        return;
    }
    const tasks = wallet.tasks || [];
    if (!tasks.length) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Bugün için tanımlı görev yok.' }));
        return;
    }
    if (!wallet.rewards_enabled) {
        wrap.appendChild(el('p', { class: 'mg-notice', text: 'D-Coin kazanımı şu an kapalı. Görevler yine görünür.' }));
    }
    tasks.forEach((task) => {
        const pct = Math.min(100, Math.round(((task.progress || 0) / Math.max(1, task.target)) * 100));
        const statusText = TASK_STATUS[task.status] || (task.done ? 'Tamamlandı' : 'Devam ediyor');
        const cls = 'mg-task'
            + (task.status === 'paid' ? ' is-claimed' : '')
            + (['blocked_limit', 'blocked_budget', 'rewards_off'].includes(task.status) ? ' is-blocked' : '')
            + (task.done && task.status === 'available' ? ' is-done' : '');
        wrap.appendChild(el('div', { class: cls }, [
            el('div', { class: 'mg-task-top' }, [
                el('span', { class: 'mg-task-title', text: task.title }),
                el('span', { class: 'mg-task-reward', text: '+' + fmt(task.reward) + ' ◈' }),
            ]),
            el('p', { class: 'mg-task-desc', text: task.description }),
            el('div', { class: 'mg-bar mg-task-bar' }, [
                el('i', { style: 'width:' + pct + '%' }),
            ]),
            el('div', { class: 'mg-task-top' }, [
                el('span', { class: 'mg-task-status', text: statusText }),
                el('span', { class: 'mg-sub', text: fmt(task.progress) + ' / ' + fmt(task.target) }),
            ]),
        ]));
    });
}

/* ---------------- sezon / şampiyonlar ---------------- */

function countdownText(endTs) {
    const diff = Math.max(0, endTs * 1000 - Date.now());
    if (diff <= 0) return 'Süre doldu';
    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    const minutes = Math.floor((diff % 3600000) / 60000);
    if (days > 0) return days + ' gün ' + hours + ' saat';
    if (hours > 0) return hours + ' saat ' + minutes + ' dk';
    return minutes + ' dakika';
}

let seasonTimer = 0;

function renderSeason() {
    const wrap = $('#mg-season');
    const chip = $('#mg-season-chip');
    if (!wrap) return;
    wrap.replaceChildren();
    if (seasonTimer) {
        clearInterval(seasonTimer);
        seasonTimer = 0;
    }
    const season = state.season;
    if (chip) chip.textContent = flags.season ? (season ? season.status_label : 'Açık') : 'Kapalı';

    if (!flags.season) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Ödüllü haftalık yarışma şu an kapalı. Açıldığında burada duyurulur.' }));
        return;
    }
    if (!season) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Şu anda ödüllü bir sezon yok. Yeni sezon duyurulduğunda burada görünecek.' }));
        return;
    }

    wrap.classList.add('mg-season-live');
    wrap.style.setProperty('--mg-cover-1', season.cover.c1);
    wrap.style.setProperty('--mg-cover-2', season.cover.c2);
    wrap.appendChild(el('span', { class: 'mg-eyebrow', text: season.status_label }));
    wrap.appendChild(el('h3', { text: season.title }));
    if (season.prize_name) wrap.appendChild(el('p', { class: 'mg-season-prize', text: 'Ödül: ' + season.prize_name }));
    if (season.platform || season.region) {
        wrap.appendChild(el('div', { class: 'mg-season-meta' }, [
            season.platform ? el('span', { text: season.platform }) : null,
            season.region ? el('span', { text: season.region }) : null,
        ]));
    }
    if (season.eligibility) wrap.appendChild(el('p', { class: 'mg-sub', text: season.eligibility }));
    if (season.participation) wrap.appendChild(el('p', { class: 'mg-sub', text: season.participation }));

    const row = el('div', { class: 'mg-countdown-row' });
    row.appendChild(el('span', { text: season.status === 'active' ? 'Kalan süre' : 'Durum' }));
    const value = el('b', { text: season.status === 'active' ? countdownText(season.end_ts) : season.status_label });
    row.appendChild(value);
    wrap.appendChild(row);

    if (season.status === 'active') {
        seasonTimer = setInterval(() => {
            value.textContent = countdownText(season.end_ts);
        }, 30000);
    }
    if (season.final && season.final.winner_username) {
        wrap.appendChild(el('p', { class: 'mg-season-winner' }, [
            el('span', { text: 'Kazanan: ' }),
            el('strong', { text: season.final.winner_username }),
            ' · ' + fmt(season.final.winner_score) + ' puan',
        ]));
    }
    if (season.results_note) wrap.appendChild(el('p', { class: 'mg-sub', text: season.results_note }));
}

function renderChampions() {
    const wrap = $('#mg-champions');
    if (!wrap) return;
    wrap.replaceChildren();
    const champions = state.champions || [];
    if (!champions.length) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Henüz kesinleşmiş şampiyon yok.' }));
        return;
    }
    champions.forEach((champion) => {
        wrap.appendChild(el('div', { class: 'mg-champion' }, [
            el('span', { class: 'mg-champion-badge', 'aria-hidden': 'true', text: '★' }),
            el('div', {}, [
                el('strong', { text: champion.winner_username || '—' }),
                el('small', { text: champion.title + ' · ' + fmt(champion.score) + ' puan' + (champion.delivered ? ' · teslim edildi' : '') }),
            ]),
        ]));
    });
}

/* ---------------- sıralama ---------------- */

async function loadBoard(type, page) {
    boardType = type || boardType;
    const body = $('#mg-board-body');
    if (!flags.leaderboard) {
        if (body) body.replaceChildren(el('tr', {}, [el('td', { colspan: '5', class: 'mg-empty', text: 'Sıralama tablosu şu an kapalı.' })]));
        return;
    }
    if (body) body.replaceChildren(el('tr', {}, [el('td', { colspan: '5', class: 'mg-empty', text: 'Yükleniyor…' })]));
    try {
        const data = await api('leaderboard.php?board=' + encodeURIComponent(boardType) + '&page=' + (page || 1));
        board = data;
        renderBoard();
    } catch (error) {
        if (body) body.replaceChildren(el('tr', {}, [el('td', { colspan: '5', class: 'mg-empty', text: error.message })]));
    }
}

function renderBoard() {
    const body = $('#mg-board-body');
    const foot = $('#mg-board-me');
    if (!body || !board) return;
    $$('#mg-board-seg button').forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.board === boardType ? 'true' : 'false');
    });
    body.replaceChildren();
    if (!board.rows || !board.rows.length) {
        body.appendChild(el('tr', {}, [el('td', {
            colspan: '5',
            class: 'mg-empty',
            text: board.no_season ? 'Aktif sezon yok.' : 'Bu dönemde henüz doğrulanmış skor yok. İlk sen ol!',
        })]));
    } else {
        board.rows.forEach((row) => {
            body.appendChild(el('tr', { class: row.is_me ? 'is-me' : '' }, [
                el('td', {}, [el('span', { class: 'mg-rank' + (row.rank <= 3 ? ' is-' + row.rank : ''), text: row.rank })]),
                el('td', {}, [
                    el('div', { class: 'mg-player' }, [
                        el('span', {
                            class: 'mg-avatar',
                            'aria-hidden': 'true',
                            style: 'background:' + (row.visual && row.visual.primary ? row.visual.primary : '#c5f46f'),
                            text: (row.username || '?').slice(0, 1).toUpperCase(),
                        }),
                        el('span', { class: 'mg-player-name', text: row.username }),
                        row.visual && row.visual.frame_rarity === 'champion'
                            ? el('span', { class: 'mg-rank is-1', style: 'min-width:1.4rem;height:1.4rem;font-size:0.7rem', title: 'Sezon şampiyonu', text: '★' })
                            : null,
                    ]),
                ]),
                el('td', {}, [el('span', { class: 'mg-score-cell', text: fmt(row.score) })]),
                el('td', {}, [el('span', { class: 'mg-sub', text: row.seconds + ' sn · ' + fmt(row.cells) + ' hücre' })]),
                el('td', {}, [el('span', { class: 'mg-sub', text: row.achieved_at })]),
            ]));
        });
    }
    if (!foot) return;
    foot.replaceChildren();
    if (!logged) {
        foot.appendChild(el('span', { class: 'mg-me-line', text: 'Kendi sıranı görmek için giriş yap.' }));
    } else if (board.me) {
        foot.appendChild(el('span', { class: 'mg-me-line' }, [
            el('strong', { text: '#' + board.me.rank }),
            ' · ' + fmt(board.me.score) + ' puan',
            board.me.gap_to_top10 > 0 ? ' · ilk 10\'a ' + fmt(board.me.gap_to_top10) + ' puan' : ' · ilk 10 içindesin',
        ]));
    } else if (board.no_season) {
        foot.appendChild(el('span', { class: 'mg-me-line', text: 'Aktif sezon yok.' }));
    } else {
        foot.appendChild(el('span', { class: 'mg-me-line', text: 'Bu tabloda henüz skorun yok.' }));
    }

    const pager = el('div', { class: 'mg-pager' });
    const prev = el('button', { class: 'mg-btn mg-btn-ghost mg-btn-sm', type: 'button', text: '←', 'aria-label': 'Önceki sayfa' });
    const next = el('button', { class: 'mg-btn mg-btn-ghost mg-btn-sm', type: 'button', text: '→', 'aria-label': 'Sonraki sayfa' });
    prev.disabled = board.page <= 1;
    next.disabled = board.page >= board.pages;
    prev.addEventListener('click', () => loadBoard(boardType, board.page - 1));
    next.addEventListener('click', () => loadBoard(boardType, board.page + 1));
    pager.append(prev, el('span', { class: 'mg-sub', text: board.page + ' / ' + board.pages }), next);
    foot.appendChild(pager);
}

$$('#mg-board-seg button').forEach((button) => {
    button.addEventListener('click', () => {
        selectTab('rekorlar');
        loadBoard(button.dataset.board, 1);
    });
});

/* ---------------- mağaza ---------------- */

function priceText(item) {
    if (item.price <= 0) return 'Ücretsiz';
    return fmt(item.price) + ' ◈';
}

const RARITY_LABEL = { common: 'Standart', rare: 'Nadir', epic: 'Özel', champion: 'Şampiyon' };

function drawItemPreview(canvas, item) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    const v = item.visual || {};
    const primary = v.primary || '#c5f46f';
    const secondary = v.secondary || '#1b152c';
    ctx.save();
    ctx.translate(w / 2, h / 2);

    if (item.category === 'frame') {
        ctx.strokeStyle = primary;
        ctx.lineWidth = 6;
        ctx.shadowColor = primary;
        ctx.shadowBlur = 18;
        ctx.beginPath();
        ctx.arc(0, 0, Math.min(w, h) * 0.3, 0, Math.PI * 2);
        ctx.stroke();
        ctx.shadowBlur = 0;
        ctx.fillStyle = secondary;
        ctx.beginPath();
        ctx.arc(0, 0, Math.min(w, h) * 0.23, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.font = '700 16px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('DG', 0, 6);
    } else if (item.category === 'trail') {
        ctx.strokeStyle = primary;
        ctx.lineWidth = 5;
        ctx.lineCap = 'round';
        ctx.shadowColor = primary;
        ctx.shadowBlur = 14;
        for (let i = 0; i < 4; i++) {
            ctx.globalAlpha = 0.85 - i * 0.16;
            ctx.beginPath();
            ctx.moveTo(-w * 0.32 + i * 8, h * 0.16 - i * 8);
            ctx.bezierCurveTo(-w * 0.1, -h * 0.12, w * 0.1, h * 0.12, w * 0.32 - i * 8, -h * 0.16 + i * 8);
            ctx.stroke();
        }
    } else if (item.category === 'color') {
        const g = ctx.createLinearGradient(-w * 0.3, 0, w * 0.3, 0);
        g.addColorStop(0, primary);
        g.addColorStop(1, secondary);
        ctx.fillStyle = g;
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(-w * 0.32, -h * 0.22, w * 0.64, h * 0.44, 14);
        else ctx.rect(-w * 0.32, -h * 0.22, w * 0.64, h * 0.44);
        ctx.fill();
    } else {
        ctx.shadowColor = primary;
        ctx.shadowBlur = 18;
        ctx.fillStyle = primary;
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(-12, -4, 24, 36, 8);
        else ctx.rect(-12, -4, 24, 36);
        ctx.fill();
        ctx.shadowBlur = 0;
        ctx.fillStyle = secondary;
        ctx.beginPath();
        ctx.arc(0, -16, 11, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = primary;
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.fillStyle = '#fff';
        ctx.fillRect(2, -19, 4, 3);
    }
    ctx.restore();
}

function renderShop() {
    const grid = $('#mg-shop-grid');
    if (!grid) return;
    grid.replaceChildren();
    const note = $('#mg-shop-note');
    if (note) {
        if (!flags.shop) {
            note.hidden = false;
            note.textContent = 'Kozmetik mağazası şu an yönetici tarafından kapatıldı.';
        } else if (wallet && !wallet.economy_approved) {
            note.hidden = false;
            note.textContent = 'Fiyatlar örnektir (prototip). Yayına almadan önce işletmeci onayı gerekir.';
        } else {
            note.hidden = true;
        }
    }
    const items = catalog.filter((item) => shopFilter === 'all' || item.category === shopFilter);
    if (!items.length) {
        grid.appendChild(el('p', { class: 'mg-empty', text: 'Bu kategoride kozmetik yok.' }));
        return;
    }
    items.forEach((item) => {
        const isOwned = owned.includes(item.id);
        const card = el('article', { class: 'mg-item' + (isOwned ? ' is-owned' : '') });
        const canvas = el('canvas', { class: 'mg-canvas', width: 320, height: 190, 'aria-hidden': 'true' });
        card.appendChild(el('div', { class: 'mg-item-preview' }, [canvas]));
        card.appendChild(el('div', { class: 'mg-item-meta' }, [
            el('h3', { text: item.name }),
            el('span', { class: 'mg-rarity is-' + (item.rarity || 'common'), text: RARITY_LABEL[item.rarity] || 'Standart' }),
        ]));
        card.appendChild(el('p', { text: item.description }));
        const meta = el('div', { class: 'mg-item-meta' });
        meta.appendChild(el('span', { class: 'mg-price' + (item.price <= 0 ? ' is-free' : ''), text: priceText(item) }));
        if (isOwned) {
            meta.appendChild(el('span', { class: 'mg-owned-tag', text: '✓ Sahipsin' }));
        } else if (!item.purchasable) {
            meta.appendChild(el('span', { class: 'mg-sub', text: 'Satın alınamaz' }));
        } else if (!logged) {
            meta.appendChild(el('a', { class: 'mg-btn mg-btn-ghost mg-btn-sm', href: (boot.urls && boot.urls.login) || 'login.php?next=mini-oyun.php', text: 'Giriş yap' }));
        } else {
            const balance = wallet ? Number(wallet.balance || 0) : 0;
            const affordable = balance >= Number(item.price || 0) || Number(item.price || 0) === 0;
            const buy = el('button', {
                class: 'mg-btn mg-btn-primary mg-btn-sm',
                type: 'button',
                text: affordable ? 'Satın al' : 'Yetersiz D-Coin',
            });
            buy.disabled = !affordable || !flags.shop;
            buy.addEventListener('click', () => buyItem(item));
            meta.appendChild(buy);
        }
        card.appendChild(meta);
        grid.appendChild(card);
        requestAnimationFrame(() => drawItemPreview(canvas, item));
    });
}

async function buyItem(item) {
    const confirmed = await openModal({
        title: 'Satın almayı onayla',
        body: item.name + ' kozmetiğini ' + fmt(item.price) + ' D-Coin karşılığında satın alacaksın. '
            + 'İşlem geri alınamaz. Kozmetikler oynanışı etkilemez.',
        confirmLabel: 'Satın al',
    });
    if (!confirmed) return;
    try {
        const result = await api('buy.php', { body: { item_id: item.id } });
        if (wallet) wallet.balance = result.balance;
        if (!owned.includes(item.id)) owned.push(item.id);
        renderWallet();
        renderShop();
        renderWardrobe();
        toast(result.already_owned ? 'Bu kozmetik zaten sende.' : item.name + ' dolabına eklendi.', 'good');
        refreshState();
    } catch (error) {
        if (error.code === 'insufficient_funds') {
            toast('Yetersiz D-Coin. Bakiyen: ' + fmt((error.data && error.data.balance) || (wallet ? wallet.balance : 0)), 'error');
        } else {
            toast(error.message, 'error');
        }
    }
}

$$('#mg-shop-filter button').forEach((button) => {
    button.addEventListener('click', () => {
        shopFilter = button.dataset.cat;
        $$('#mg-shop-filter button').forEach((b) => b.setAttribute('aria-pressed', b === button ? 'true' : 'false'));
        renderShop();
    });
});

/* ---------------- gardırop ---------------- */

function drawLoadoutPreview(canvas) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    const primary = visuals.primary || '#c5f46f';
    const secondary = visuals.secondary || '#1b152c';
    const g = ctx.createRadialGradient(w / 2, h * 0.6, 8, w / 2, h * 0.6, w * 0.75);
    g.addColorStop(0, primary + '44');
    g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);

    ctx.save();
    ctx.translate(w / 2, h * 0.56);
    ctx.shadowColor = primary;
    ctx.shadowBlur = 16;
    ctx.fillStyle = primary;
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(-20, -8, 40, 58, 12);
    else ctx.rect(-20, -8, 40, 58);
    ctx.fill();
    ctx.restore();

    ctx.fillStyle = secondary;
    ctx.beginPath();
    ctx.arc(w / 2, h * 0.36, 19, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = primary;
    ctx.lineWidth = 4;
    ctx.stroke();
    ctx.fillStyle = '#fff';
    ctx.fillRect(w / 2 + 4, h * 0.35, 8, 5);

    if (visuals.frame) {
        ctx.strokeStyle = visuals.frame_color || '#63f5e8';
        ctx.lineWidth = 4;
        ctx.shadowColor = visuals.frame_color || '#63f5e8';
        ctx.shadowBlur = 12;
        ctx.beginPath();
        ctx.arc(w / 2, h / 2, w * 0.42, 0, Math.PI * 2);
        ctx.stroke();
    }
}

function renderWardrobe() {
    const wrap = $('#mg-wardrobe');
    if (!wrap) return;
    wrap.replaceChildren();

    const preview = $('#mg-loadout-preview');
    if (preview) {
        preview.replaceChildren();
        const canvas = el('canvas', { width: 190, height: 190, 'aria-hidden': 'true' });
        preview.appendChild(canvas);
        preview.appendChild(el('div', { class: 'mg-loadout-text' }, [
            el('h3', { text: 'Aktif görünüm' }),
            el('p', { class: 'mg-sub', text: 'Kozmetikler yalnızca görseldir; hız, hitbox ve skor değişmez.' }),
        ]));
        requestAnimationFrame(() => drawLoadoutPreview(canvas));
    }

    if (!logged) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Dolabını görmek için giriş yap.' }));
        return;
    }
    const ownedItems = catalog.filter((item) => owned.includes(item.id));
    if (!ownedItems.length) {
        wrap.appendChild(el('p', { class: 'mg-empty', text: 'Henüz kozmetiğin yok.' }));
        return;
    }
    const labels = { character: 'Karakter', color: 'Renk', trail: 'İz', frame: 'Çerçeve' };
    ['character', 'color', 'trail', 'frame'].forEach((category) => {
        const items = ownedItems.filter((item) => item.category === category);
        if (!items.length) return;
        wrap.appendChild(el('h3', { class: 'mg-sub-title', text: labels[category] }));
        const grid = el('div', { class: 'mg-cat-grid' });
        items.forEach((item) => {
            const active = loadout[category] === item.id;
            const card = el('article', { class: 'mg-item' + (active ? ' is-owned' : '') });
            card.appendChild(el('div', { class: 'mg-item-meta' }, [
                el('h3', { text: item.name }),
                el('span', { class: 'mg-rarity is-' + (item.rarity || 'common'), text: RARITY_LABEL[item.rarity] || 'Standart' }),
            ]));
            card.appendChild(el('p', { text: item.description }));
            const actions = el('div', { class: 'mg-item-meta' });
            if (active) {
                actions.appendChild(el('span', { class: 'mg-owned-tag', text: '✓ Kuşanıldı' }));
                if (category !== 'character') {
                    const off = el('button', { class: 'mg-btn mg-btn-ghost mg-btn-sm', type: 'button', text: 'Çıkar' });
                    off.addEventListener('click', () => equip(category, ''));
                    actions.appendChild(off);
                }
            } else {
                const on = el('button', { class: 'mg-btn mg-btn-ghost mg-btn-sm', type: 'button', text: 'Kuşan' });
                on.addEventListener('click', () => equip(category, item.id));
                actions.appendChild(on);
            }
            card.appendChild(actions);
            grid.appendChild(card);
        });
        wrap.appendChild(grid);
    });
}

async function equip(slot, itemId) {
    try {
        const result = await api('equip.php', { body: { slot, item_id: itemId } });
        loadout = result.loadout;
        visuals = result.visuals;
        game.setVisual(visuals);
        renderWardrobe();
        toast(itemId === '' ? 'Kozmetik çıkarıldı.' : 'Kozmetik kuşanıldı.', 'good');
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* ---------------- overlay ---------------- */

const overlay = $('#mg-overlay');
const overlayCard = $('#mg-overlay-card');

function showOverlay(nodes, liveMessage) {
    if (!overlay || !overlayCard) return;
    overlayCard.replaceChildren(...(Array.isArray(nodes) ? nodes.filter(Boolean) : [nodes]));
    overlay.hidden = false;
    if (liveMessage) announce(liveMessage);
}

function hideOverlay() {
    if (overlay) overlay.hidden = true;
}

function idleOverlay() {
    const canPlay = flags.game;
    const parts = [
        el('h2', { text: logged ? 'Hazır mısın?' : 'Misafir antrenmanı' }),
        el('p', {
            text: logged
                ? 'Zıpla, enerji hücrelerini topla, engellerden kaç. Tur yaklaşık 1–2 dakika sürer.'
                : 'Antrenmanı giriş yapmadan oynayabilirsin. D-Coin kazanmak ve sıralamaya girmek için Device Game hesabınla giriş yap.',
        }),
    ];
    const actions = el('div', { class: 'mg-overlay-actions' });
    if (!canPlay) {
        parts.push(el('p', { class: 'mg-sub', text: state.maintenance || 'Mini oyun şu an bakımda.' }));
    } else {
        if (logged) {
            const play = el('button', { class: 'mg-btn mg-btn-primary', type: 'button', text: 'Sıralı turu başlat' });
            play.addEventListener('click', () => startRanked());
            actions.appendChild(play);
        }
        if (flags.practice) {
            const practice = el('button', { class: 'mg-btn mg-btn-ghost', type: 'button', text: 'Antrenman (kaydedilmez)' });
            practice.addEventListener('click', () => startPractice());
            actions.appendChild(practice);
        }
        if (!logged) {
            actions.appendChild(el('a', {
                class: 'mg-btn mg-btn-primary',
                href: (boot.urls && boot.urls.login) || 'login.php?next=mini-oyun.php',
                text: 'Giriş yap',
            }));
        }
    }
    parts.push(actions);
    parts.push(el('p', { class: 'mg-sub mg-overlay-hint', text: 'Boşluk / ↑ zıpla · bırakınca kısa zıplama · dokunmatik desteklenir' }));
    showOverlay(parts);
}

function countdownOverlay(value) {
    if (value > 0) {
        showOverlay([
            el('div', { class: 'mg-countdown', text: String(value) }),
            el('p', { class: 'mg-sub', text: 'Hazır ol…' }),
        ], String(value));
    } else {
        hideOverlay();
    }
}

function verifyingOverlay() {
    showOverlay([
        el('h2', { text: 'Tur doğrulanıyor…' }),
        el('p', { text: 'Sunucu aynı seed ve girdilerle turu yeniden oynatıyor. Bu birkaç saniye sürebilir.' }),
        el('p', { class: 'mg-sub', text: 'Sayfayı kapatma.' }),
    ], 'Tur doğrulanıyor');
}

function resultOverlay(info) {
    const result = info.result || { score: 0, ticks: 0, cells: 0 };
    const parts = [];
    if (info.pending) {
        parts.push(el('h2', { text: 'Tur incelemeye alındı' }));
        parts.push(el('p', { text: 'Girdiler kaydedildi. Skorun yüksek risk sinyali nedeniyle onay bekliyor; onaylanınca sıralamaya işlenecek.' }));
    } else if (info.practice) {
        parts.push(el('h2', { text: 'Antrenman tamamlandı' }));
        parts.push(el('p', { text: 'Bu tur kaydedilmedi; D-Coin ve sıralama için sıralı tur başlat.' }));
    } else {
        parts.push(el('h2', { text: result.reason === 'time' ? 'Süre tamamlandı!' : 'Tur bitti' }));
    }

    parts.push(el('div', { class: 'mg-result-grid' }, [
        el('div', {}, [el('span', { text: 'Skor' }), el('strong', { text: fmt(result.score) })]),
        el('div', {}, [el('span', { text: 'Süre' }), el('strong', { text: (result.seconds || ((result.ticks || 0) / 60)).toFixed(1) + ' sn' })]),
        el('div', {}, [el('span', { text: 'Hücre' }), el('strong', { text: fmt(result.cells) })]),
    ]));

    if (info.rewards && info.rewards.paid > 0) {
        const titles = {};
        ((wallet && wallet.tasks) || []).forEach((task) => { titles[task.id] = task.title; });
        const list = el('ul', { class: 'mg-reward-list' });
        (info.rewards.claims || []).forEach((claim) => {
            if (claim.status === 'paid' && claim.amount > 0) {
                list.appendChild(el('li', {}, [
                    el('span', { text: titles[claim.task_id] || claim.task_id }),
                    el('b', { text: '+' + fmt(claim.amount) + ' ◈' }),
                ]));
            }
        });
        parts.push(el('p', { class: 'mg-result-reward', text: 'Bu turda +' + fmt(info.rewards.paid) + ' D-Coin kazandın.' }));
        parts.push(list);
    } else if (!info.practice && !info.pending) {
        const reason = wallet && wallet.budget_exhausted
            ? 'Günlük ödül bütçesi doldu; oyun devam eder.'
            : (wallet && wallet.cap_reached
                ? 'Bugünkü kişisel D-Coin limitin doldu.'
                : 'Bu turda yeni bir görev tamamlanmadı ya da ödül daha önce alındı.');
        parts.push(el('p', { class: 'mg-sub', text: reason }));
    }

    const actions = el('div', { class: 'mg-overlay-actions' });
    const again = el('button', { class: 'mg-btn mg-btn-primary', type: 'button', text: 'Tekrar oyna' });
    again.addEventListener('click', () => { if (logged) startRanked(); else startPractice(); });
    const boards = el('button', { class: 'mg-btn mg-btn-ghost', type: 'button', text: 'Sıralamaya bak' });
    boards.addEventListener('click', () => { selectTab('rekorlar'); loadBoard('today', 1); });
    actions.append(again, boards);
    parts.push(actions);

    showOverlay(parts, 'Tur bitti. Skor ' + fmt(result.score));
}

function messageOverlay(title, message, actions) {
    const box = el('div', { class: 'mg-overlay-actions' });
    (actions || []).forEach((action) => box.appendChild(action));
    const back = el('button', { class: 'mg-btn mg-btn-ghost', type: 'button', text: 'Geri' });
    back.addEventListener('click', () => { game.reset(); idleOverlay(); });
    box.appendChild(back);
    showOverlay([el('h2', { text: title }), el('p', { text: message }), box], title);
}

/* ---------------- oyun akışı ---------------- */

const canvas = $('#mg-canvas');
const game = canvas ? createGame({
    canvas,
    onCountdown: countdownOverlay,
    onState: (next) => {
        const stage = $('#mg-stage');
        if (stage) stage.classList.toggle('is-live', next === 'running' || next === 'countdown');
        const jump = $('#mg-jump');
        if (jump) jump.disabled = next !== 'running';
    },
    onEnd: (payload) => {
        if (payload.ranked) {
            submitRanked(payload);
        } else {
            resultOverlay({ practice: true, result: payload.result });
        }
    },
}) : null;

if (game) {
    game.setMuted(!soundOn);
    game.setVisual(visuals);
}

function startRanked() {
    if (!game) return;
    if (!flags.game) {
        messageOverlay('Oyun kapalı', state.maintenance || 'Mini oyun şu an bakımda. Lütfen daha sonra tekrar dene.');
        return;
    }
    if (!logged) {
        window.location.href = (boot.urls && boot.urls.login) || 'login.php?next=mini-oyun.php';
        return;
    }
    hideOverlay();
    showOverlay([el('h2', { text: 'Tur hazırlanıyor…' }), el('p', { class: 'mg-sub', text: 'Sunucudan bölüm alınıyor.' })]);
    api('start.php', { body: {} })
        .then((run) => {
            activeRunMeta = run;
            state.attempts = run.attempts != null ? run.attempts : (Number(state.attempts || 0) + 1);
            if (run.attempt_limit != null) state.attempt_limit = run.attempt_limit;
            game.start({
                seed: run.seed,
                ranked: true,
                meta: { run_id: run.run_id, token: run.token, expires_ts: run.expires_ts },
            });
            renderWallet();
        })
        .catch((error) => {
            if (error.code === 'daily_attempts') {
                messageOverlay('Günlük tur hakkı doldu', error.message || 'Bugünkü sıralı tur hakkın doldu. Yarın yeniden deneyebilirsin.');
            } else if (error.code === 'game_disabled') {
                messageOverlay('Oyun kapalı', error.message || 'Mini oyun şu an bakımda.');
            } else if (error.code === 'auth' || error.status === 401) {
                messageOverlay('Oturum gerekli', 'Yeniden giriş yapmalısın.', [
                    el('a', { class: 'mg-btn mg-btn-primary', href: (boot.urls && boot.urls.login) || 'login.php?next=mini-oyun.php', text: 'Giriş yap' }),
                ]);
            } else {
                const retry = el('button', { class: 'mg-btn mg-btn-primary', type: 'button', text: 'Tekrar dene' });
                retry.addEventListener('click', () => startRanked());
                messageOverlay('Tur başlatılamadı', error.message, [retry]);
            }
        });
}

function startPractice() {
    if (!game) return;
    if (!flags.practice) {
        messageOverlay('Antrenman kapalı', 'Antrenman turları şu an kapalı.');
        return;
    }
    activeRunMeta = null;
    const seed = ((Date.now() ^ Math.floor(Math.random() * 0x7fffffff)) >>> 0) || 1;
    game.start({ seed, ranked: false, meta: { practice: true } });
    announce('Antrenman başladı');
}

async function submitRanked(payload) {
    if (submitting) return;
    const expires = Number((payload.meta && payload.meta.expires_ts) || 0);
    if (expires > 0 && Date.now() / 1000 > expires - 2) {
        messageOverlay('Tur süresi doldu', 'Bu tur sunucuda zaman aşımına uğradı. Yeni bir sıralı tur başlat.');
        return;
    }
    submitting = true;
    const body = {
        run_id: payload.meta.run_id,
        token: payload.meta.token,
        inputs: payload.inputs,
        score: payload.result.score,
    };
    pendingSubmit = body;
    activeRunMeta = null;
    verifyingOverlay();

    try {
        const response = await api('submit.php', { body });
        pendingSubmit = null;
        if (response.attempts != null) state.attempts = response.attempts;
        if (response.attempt_limit != null) state.attempt_limit = response.attempt_limit;
        if (response.rewards && wallet) {
            wallet.balance = response.rewards.balance;
            if (response.rewards.cap_reached) wallet.cap_reached = true;
            if (response.rewards.budget_exhausted) wallet.budget_exhausted = true;
        }
        resultOverlay({
            result: response.result,
            status: response.status,
            pending: response.pending,
            rewards: response.rewards,
        });
        renderWallet();
        refreshState();
        if (board) loadBoard(boardType, board.page);
    } catch (error) {
        if (error.code === 'offline' || error.code === 'bad_response') {
            const retry = el('button', { class: 'mg-btn mg-btn-primary', type: 'button', text: 'Tekrar gönder' });
            retry.addEventListener('click', () => submitRanked({
                meta: { run_id: body.run_id, token: body.token },
                inputs: body.inputs,
                result: payload.result,
            }));
            const check = el('button', { class: 'mg-btn mg-btn-ghost', type: 'button', text: 'Sonucu sorgula' });
            check.addEventListener('click', () => queryRun(body.run_id));
            pendingSubmit = body;
            submitting = false;
            messageOverlay(
                'Sonuç gönderilemedi',
                'Bağlantı sorunu nedeniyle tur doğrulanamadı. Aynı turu yeniden göndermek güvenlidir; ikinci kez ödül verilmez.',
                [retry, check]
            );
            return;
        }
        if (error.code === 'score_mismatch') {
            messageOverlay('Skor doğrulanamadı', 'Tur sunucu tarafında yeniden oynatıldı ve skor eşleşmedi. Bir sorun olduğunu düşünüyorsan destek ile iletişime geç.');
        } else if (error.code === 'impossible_timing') {
            messageOverlay('Tur reddedildi', 'Tur süresi sunucu kaydıyla uyuşmadı. Saat/uyku modu farkı olabilir; yeni bir tur başlat.');
        } else if (error.code === 'expired') {
            messageOverlay('Tur süresi doldu', 'Tur çok uzun sürdü ve sunucuda süresi doldu. Yeni tur başlat.');
        } else if (error.code === 'already_finalized' || error.code === 'rejected') {
            messageOverlay('Tur zaten sonuçlandı', error.message);
        } else {
            const retry = el('button', { class: 'mg-btn mg-btn-primary', type: 'button', text: 'Tekrar dene' });
            retry.addEventListener('click', () => submitRanked({
                meta: { run_id: body.run_id, token: body.token },
                inputs: body.inputs,
                result: payload.result,
            }));
            messageOverlay('Tur doğrulanamadı', error.message, [retry]);
        }
    } finally {
        submitting = false;
    }
}

async function queryRun(runId) {
    try {
        const data = await api('run.php?id=' + encodeURIComponent(runId));
        if (data.status === 'active') {
            toast('Tur henüz sunucuda işlenmedi. "Tekrar gönder" ile yeniden deneyebilirsin.', 'error');
            return;
        }
        pendingSubmit = null;
        if (data.status === 'verified') {
            resultOverlay({ result: data.result, rewards: data.rewards, status: 'verified' });
            refreshState();
        } else if (data.status === 'pending') {
            resultOverlay({ result: data.result, pending: true, status: 'pending' });
        } else {
            messageOverlay('Tur reddedildi', 'Bu turun sonucu sunucuda doğrulanamadı. Yeni bir tur başlatabilirsin.');
        }
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* ---------------- görünürlük / ağ ---------------- */

function onHidden() {
    if (!game) return;
    if (game.getState() === 'running' || game.getState() === 'countdown') {
        if (game.isRanked()) {
            game.abort();
            activeRunMeta = null;
            messageOverlay('Tur iptal edildi', 'Sekme arka plana geçtiği için sıralı tur iptal edildi. Adil rekabet için bu tur kaydedilmedi.');
        } else {
            game.pause();
        }
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        onHidden();
    } else if (game && game.getState() === 'paused') {
        game.resume();
    }
});

window.addEventListener('beforeunload', (event) => {
    if (!game) return;
    if (game.getState() === 'running' || game.getState() === 'countdown') {
        event.preventDefault();
        event.returnValue = '';
    }
});

window.addEventListener('offline', () => toast('İnternet bağlantısı kesildi.', 'error'));
window.addEventListener('online', () => toast('Bağlantı geri geldi.', 'good'));

/* ---------------- durum tazeleme ---------------- */

async function refreshState() {
    if (!logged) return;
    try {
        const data = await api('state.php');
        Object.assign(flags, data.flags || {});
        state.maintenance = data.maintenance || '';
        state.season = data.season;
        state.champions = data.champions;
        state.attempts = data.attempts;
        state.attempt_limit = data.attempt_limit;
        state.last_run = data.last_run || null;
        if (data.wallet) wallet = data.wallet;
        renderWallet();
        renderTasks();
        renderSeason();
        renderChampions();
    } catch (error) {
        /* sessiz: mevcut görünüm korunur */
    }
}

/* ---------------- ses / tam ekran / dokunma ---------------- */

const soundBtn = $('#mg-sound');
if (soundBtn) {
    const syncSound = () => {
        soundBtn.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
        soundBtn.textContent = soundOn ? '🔊' : '🔇';
        soundBtn.setAttribute('aria-label', soundOn ? 'Sesi kapat' : 'Sesi aç');
        if (game) game.setMuted(!soundOn);
    };
    syncSound();
    soundBtn.addEventListener('click', () => {
        soundOn = !soundOn;
        try {
            localStorage.setItem('dg-mg-sound', soundOn ? 'on' : 'off');
        } catch (error) {
            /* özel mod: ses tercihi saklanamaz */
        }
        syncSound();
    });
}

const fullBtn = $('#mg-fullscreen');
if (fullBtn) {
    fullBtn.addEventListener('click', async () => {
        const stage = $('#mg-stage');
        if (!stage) return;
        try {
            if (!document.fullscreenElement) {
                await stage.requestFullscreen();
            } else {
                await document.exitFullscreen();
            }
        } catch (error) {
            toast('Tam ekran bu tarayıcıda kullanılamıyor.', 'error');
        }
    });
}

const jumpBtn = $('#mg-jump');
if (jumpBtn) {
    const press = (event) => {
        event.preventDefault();
        if (game) game.requestAction(1);
    };
    const release = (event) => {
        event.preventDefault();
        if (game) game.requestAction(2);
    };
    jumpBtn.addEventListener('pointerdown', press);
    jumpBtn.addEventListener('pointerup', release);
    jumpBtn.addEventListener('pointercancel', release);
    jumpBtn.addEventListener('pointerleave', (event) => {
        if (event.buttons) release(event);
    });
    jumpBtn.addEventListener('keydown', (event) => {
        if (event.code === 'Space' || event.code === 'Enter') {
            event.preventDefault();
            if (game) game.requestAction(1);
        }
    });
    jumpBtn.addEventListener('keyup', (event) => {
        if (event.code === 'Space' || event.code === 'Enter') {
            event.preventDefault();
            if (game) game.requestAction(2);
        }
    });
}

const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
motionQuery.addEventListener('change', (event) => {
    reducedMotion = event.matches;
    if (game) game.setReducedMotion(reducedMotion);
});
if (game) game.setReducedMotion(reducedMotion);

/* ---------------- başlangıç ---------------- */

renderWallet();
renderTasks();
renderSeason();
renderChampions();
renderShop();
renderWardrobe();
idleOverlay();

const initialTab = (location.hash || '').replace('#mg-panel-', '').replace('#', '');
if (['rekorlar', 'magaza', 'dolabim'].includes(initialTab)) {
    selectTab(initialTab);
}

if (boot.notice) {
    const notice = $('#mg-page-notice');
    if (notice) {
        notice.hidden = false;
        notice.replaceChildren(el('span', { text: boot.notice }));
    }
}

setInterval(() => {
    if (!document.hidden && logged) refreshState();
}, 90000);
