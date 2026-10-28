/**
 * Eşlik fikstürü üretici (istemci motoru → JSON).
 *
 *   node tests/fixtures/make-vectors.mjs
 *
 * Üretilen `minigame-vectors.json`, PHP motorunun aynı seed + aynı girdilerle
 * AYNI sonucu verdiğini doğrulamak içindir (`tests/php/engine_test.php`).
 *
 * Bot girdileri bilinçli olarak "insan üstü" kayıtlar içerir: uzun turlar,
 * boss dövüşü, eğilme ve ateş kullanımı. Bu, motorun tüm dallarını kapsar.
 */
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { CONFIG, encodeInputs, simulate } from '../../assets/js/minigame/engine.js';
import { playBot } from '../../tools/minigame-bot.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const cases = [];

function addCase(name, seed, inputs, maxTicks) {
    const result = simulate(seed, inputs, { maxTicks });
    cases.push({
        name,
        seed,
        max_ticks: maxTicks || CONFIG.maxTicks,
        inputs,
        encoded: encodeInputs(inputs),
        result,
    });
}

// Bot koşuları — uzun, boss'lu, ateş + eğilme + dash içeren
for (const [seed, maxTicks] of [[1, 5400], [42, 5400], [20240301, 3600], [7, CONFIG.maxTicks]]) {
    const { inputs } = playBot(seed, { maxTicks });
    addCase('bot-' + seed, seed, inputs, maxTicks);
}

// Kenar durumları — her aksiyon ayrı ayrı
addCase('bos-girdi', 12345, [], 900);
addCase('zip-birak', 777, [[0, 1], [10, 2], [40, 1], [50, 2]], 900);
addCase('egil-kalk', 555, [[20, 4], [60, 5], [90, 4], [200, 5]], 900);
addCase('ates-serisi', 321, [[5, 3], [50, 3], [95, 3], [140, 3]], 1200);
addCase('dash', 4242, [[30, 6], [60, 6], [95, 6]], 900);
addCase('uc-zip', 98765, [[0, 1], [8, 2], [33, 1], [41, 2], [66, 1], [74, 2]], 900);
addCase('uzun-bekleme', 24680, [[600, 1], [640, 2]], 1800);
addCase('karisik', 13579, [[10, 3], [20, 1], [30, 2], [45, 4], [120, 5], [150, 3], [200, 6]], 1800);

const payload = {
    engine: CONFIG.version,
    generated_by: 'tests/fixtures/make-vectors.mjs',
    cases,
};

const out = join(here, 'minigame-vectors.json');
writeFileSync(out, JSON.stringify(payload) + '\n');
console.log('yazıldı:', out, '· vaka:', cases.length);

for (const item of cases) {
    console.log(
        String(item.name).padEnd(18),
        'seed', String(item.seed).padStart(10),
        'tick', String(item.result.ticks).padStart(6),
        'skor', String(item.result.score).padStart(6),
        'hp', String(item.result.hp).padStart(3),
        'öldürme', String(item.result.kills).padStart(3),
        'boss', item.result.bossKilled ? 'evet' : 'hayır',
        item.result.reason
    );
}
