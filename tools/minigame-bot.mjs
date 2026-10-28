/**
 * Test botu — "Overcharge: Kaçış" v2 için geçerli girdi üretir.
 *
 * AMAÇ: motor eşliği ve denge ölçümü için kurallara UYAN girdi dizisi üretmek.
 * Bu dosya istemciye GÖNDERİLMEZ.
 *
 * NOT: Bu bot, "bot geçerli girdi üretebilir" sınırının somut kanıtıdır.
 * Sunucudaki deterministik yeniden oynatma bu tür girdileri ayırt EDEMEZ;
 * bu yüzden risk incelemesi ve manuel kuyruk vardır.
 */
import {
    ACTIONS, CONFIG, WEAPONS, createState, step, resultOf, playerBox,
} from '../assets/js/minigame/engine.js';

const C = CONFIG;

/**
 * @param {number} seed
 * @param {{maxTicks?:number, aggression?:number}} [options]
 */
export function playBot(seed, options = {}) {
    const maxTicks = Math.min(C.maxTicks, options.maxTicks || C.maxTicks);
    const aggression = options.aggression ?? 1;
    const state = createState(seed);
    const inputs = [];
    let holdUntil = -1;

    while (state.tick < maxTicks) {
        const action = decide(state, holdUntil, aggression);
        if (action === ACTIONS.JUMP) holdUntil = state.tick + 12;
        if (action !== ACTIONS.NONE) inputs.push([state.tick, action]);
        const status = step(state, action);
        if (status !== 'running') break;
    }

    return { inputs, result: resultOf(state), state };
}

function decide(state, holdUntil, aggression) {
    const box = playerBox(state);
    const grounded = state.grounded === 1;
    const speed = state.speed;

    // 1) Yaklaşan düşman mermisi — en yakın tehdit
    let threat = null;
    for (const b of state.bullets) {
        if (b.own !== 0) continue;
        const gap = b.x - box.x1;
        if (gap < -30 || gap > 9 * speed) continue;
        if (!threat || gap < threat.gap) threat = { b, gap };
    }
    if (threat) {
        const high = threat.b.y >= C.crouchH;
        if (high) {
            if (state.crouch === 0 && grounded) return ACTIONS.CROUCH;
        } else if (grounded) {
            return ACTIONS.JUMP;
        } else if (state.dashReadyAt <= state.tick) {
            return ACTIONS.DASH;
        }
    }

    // 2) Baş üstü kiriş — eğilerek geçilir; yaklaşırken ZIPLAMA
    let ceiling = null;
    let ceilingNear = null;
    for (const cl of state.ceilings) {
        if (cl.x + cl.w < box.x1) continue;
        const gap = cl.x - box.x2;
        if (gap > 22 * speed) break;
        if (!ceilingNear) ceilingNear = cl;
        if (gap <= 10 * speed) ceiling = cl;
        break;
    }
    if (ceiling && grounded && state.crouch === 0) return ACTIONS.CROUCH;

    // 3) Zemin engeli / yer düşmanı — zıpla
    let blocker = null;
    for (const o of state.obstacles) {
        if (o.x + o.w < box.x1) continue;
        const gap = o.x - box.x2;
        if (gap > 10 * speed) break;
        blocker = { x: o.x, gap };
        break;
    }
    if (!blocker) {
        for (const e of state.enemies) {
            if (e.hp <= 0 || e.type === 'drone') continue;
            if (e.x + e.w < box.x1) continue;
            const gap = e.x - box.x2;
            if (gap > 10 * speed) break;
            blocker = { x: e.x, gap };
            break;
        }
    }
    if (blocker) {
        if (state.crouch === 1) {
            // Eğikken zıplanmaz: önce kalk (kiriş üstümüzde değilse motor izin verir)
            if (!ceilingNear) return ACTIONS.STAND;
        } else if (grounded && !ceilingNear && blocker.gap < 9 * speed) {
            return ACTIONS.JUMP;
        }
    } else if (state.crouch === 1) {
        return ACTIONS.STAND;
    }

    // 4) Havada: tepe noktasına yakın bırak (kontrollü iniş)
    if (!grounded && state.vy > 0 && state.vy <= C.cutV + 8 && holdUntil > 0 && state.tick >= holdUntil - 1) {
        return ACTIONS.RELEASE;
    }

    // 5) Ateş: namlu hizasında hedef varsa
    if (state.shots > 0) {
        const weapon = WEAPONS[state.weapon] || WEAPONS.blaster;
        const muzzle = Math.floor((box.y1 + box.y2) / 2);
        let target = null;
        for (const e of state.enemies) {
            if (e.hp <= 0) continue;
            const gap = e.x - box.x2;
            if (gap < 0 || gap > 1500) continue;
            if (muzzle < e.y || muzzle > e.y + e.h) continue;
            if (!target || gap < target.gap) target = { gap };
        }
        if (state.boss && state.boss.hp > 0) {
            const bb = state.boss;
            const gap = bb.x - box.x2;
            if (gap > 0 && gap < 4000 && muzzle >= bb.y && muzzle <= bb.y + bb.h) {
                if (!target || gap < target.gap) target = { gap };
            }
        }
        if (target) {
            const flight = Math.max(1, Math.ceil(target.gap / (weapon.speed + speed)));
            if (flight <= 20 * aggression) return ACTIONS.FIRE;
        }
    }

    return ACTIONS.NONE;
}
