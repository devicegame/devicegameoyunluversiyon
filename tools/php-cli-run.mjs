/** PHP betiğini (WASM) doğrudan çalıştırır. Geliştirme/test aracıdır; canlıda gerekmez. */
import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(here, '..');
const script = path.resolve(process.argv[2]);
const runtime = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } });
const php = new PHP(runtime);
php.mkdirTree('/www');
// Uygulama dosyalarını kopyala (storage hariç).
function copy(dir, rel = '') {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'storage', 'tools'].includes(e.name)) continue;
    if (e.name.startsWith('.')) continue;
    const full = path.join(dir, e.name);
    const relPath = path.posix.join(rel, e.name);
    if (e.isDirectory()) { copy(full, relPath); continue; }
    const target = path.posix.join('/www', relPath);
    php.mkdirTree(path.posix.dirname(target));
    php.writeFile(target, new Uint8Array(fs.readFileSync(full)));
  }
}
copy(ROOT);
php.mkdirTree('/www/storage');
// Betiği .php uzantılıysa olduğu gibi, değilse /www içine kopyala.
let target;
if (script.startsWith(ROOT + path.sep)) {
  target = '/www/' + path.relative(ROOT, script).split(path.sep).join('/');
} else {
  target = '/www/__test.php';
  php.writeFile(target, new Uint8Array(fs.readFileSync(script)));
}
let r;
try {
  r = await php.run({ scriptPath: target });
} catch (error) {
  // php-wasm, sıfırdan farklı çıkış kodunda hata fırlatır; çıktıyı yine de yaz.
  r = error && error.response ? error.response : null;
  if (!r) throw error;
}
process.stdout.write(new TextDecoder().decode(r.bytes));
if (r.errors) process.stderr.write(String(r.errors) + '\n');
process.exit(r.exitCode ?? 0);
