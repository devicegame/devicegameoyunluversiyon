/**
 * Eşlik fikstürü üretici (istemci motoru → JSON).
 *
 *   node tests/fixtures/make-vectors.mjs
 *
 * Üretilen `minigame-vectors.json`, PHP motorunun aynı girdilerle aynı
 * sonucu verdiğini doğrulamak için `tests/php/engine_test.php` tarafından
 * okunur. Bot girdileri bilinçli olarak "insan üstü" kayıtlar içerir.
 */
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { encodeInputs, simulate, CONFIG } from '../../assets/js/minigame/engine.js';
import { playBot } from '../../tools/minigame-bot.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const cases = [];

function addCase(name, seed, inputs, options) {
    const result = simulate(seed, inputs, options);
    cases.push({
        name,
        seed,
        max_ticks: (options && options.maxTicks) || CONFIG.maxTicks,
        inputs,
        encoded: encodeInputs(inputs),
        result,
    });
}

for (const seed of [1, 42, 7, 20240301, 2147483647, 4294967295, 305419896]) {
    const maxTicks = seed === 1 ? 1800 : 3600;
    const { inputs } = playBot(seed, { maxTicks });
    addCase('bot-' + seed, seed, inputs, { maxTicks });
}

addCase('bos-girdi', 12345, [], { maxTicks: 600 });
addCase('tek-zip', 98765, [[0, 1]], { maxTicks: 300 });
addCase('zip-birak', 98765, [[0, 1], [6, 2]], { maxTicks: 300 });
addCase('gec-zip', 555, [[31, 1], [45, 2]], { maxTicks: 600 });
addCase('cift-zip', 777, [[0, 1], [10, 2], [33, 1], [43, 2]], { maxTicks: 600 });
addCase('son-tick', 4242, [[0, 1], [Math.floor(CONFIG.maxTicks / 2), 1]], { maxTicks: 1200 });

const payload = {
    engine: CONFIG.version,
    generated_by: 'tests/fixtures/make-vectors.mjs',
    cases,
};

const out = join(here, 'minigame-vectors.json');
writeFileSync(out, JSON.stringify(payload, null, 2) + '\n');
console.log('yazıldı:', out, '· vaka:', cases.length);

for (const item of cases) {
    console.log(
        String(item.name).padEnd(22),
        'seed', String(item.seed).padStart(10),
        'tick', String(item.result.ticks).padStart(5),
        'skor', String(item.result.score).padStart(6),
        'hücre', String(item.result.cells).padStart(4),
        item.result.reason
    );
}
