/**
 * Test bot — "Overcharge: Kaçış" için geçerli girdi üretir.
 *
 * AMAÇ: doğrulama testleri ve eşlik (parity) fikstürleri için, oyunun
 * kurallarına UYAN girdi dizileri üretmek. Bu dosya istemciye GÖNDERİLMEZ.
 *
 * NOT: Bu bot, görev tanımındaki "bot geçerli girdi üretebilir" sınırının
 * somut kanıtıdır. Sunucu tarafındaki deterministik yeniden oynatma bu tür
 * girdileri ayırt EDEMEZ; bu yüzden risk incelemesi ve manuel kuyruk vardır.
 */
import { createState, step, resultOf, CONFIG } from '../assets/js/minigame/engine.js';

/**
 * @param {number} seed
 * @param {{maxTicks?:number, lookaheadTicks?:number, holdTicks?:number}} [options]
 */
export function playBot(seed, options = {}) {
    const maxTicks = Math.min(CONFIG.maxTicks, options.maxTicks || CONFIG.maxTicks);
    const lookaheadTicks = options.lookaheadTicks || 9;
    const holdTicks = options.holdTicks || 12;

    const state = createState(seed);
    const inputs = [];
    let releaseAt = -1;

    while (state.tick < maxTicks) {
        let action = 0;
        if (releaseAt >= 0 && state.tick >= releaseAt) {
            action = 2;
            releaseAt = -1;
        } else if (state.grounded === 1 && shouldJump(state, lookaheadTicks)) {
            action = 1;
            releaseAt = state.tick + holdTicks;
        }

        if (action) inputs.push([state.tick, action]);
        const status = step(state, action);
        if (status !== 'running') break;
    }

    return { inputs, result: resultOf(state), state };
}

function shouldJump(state, lookaheadTicks) {
    const playerRight = state.distance + CONFIG.playerX + CONFIG.playerW;
    let nearest = null;
    for (const obstacle of state.obstacles) {
        const left = obstacle.x;
        const right = obstacle.x + obstacle.w;
        if (right < playerRight) continue;
        if (!nearest || left < nearest.x) nearest = obstacle;
    }
    if (!nearest) return false;

    // Zıplama süresi ~ 2*jumpV/gravity ≈ 31 tick; tepe noktası ~ 15 tick.
    const trigger = state.speed * lookaheadTicks;
    const distance = nearest.x - playerRight;
    if (distance > trigger) return false;
    // Zaten üstündeyse geç kaldık; çarpmamak için yine de zıpla.
    return nearest.h <= 300;
}
