/** Tüm PHP dosyalarını sözdizimi açısından tarar (php -l eşdeğeri, WASM). */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

const here = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(here, '..');
const files = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'storage', 'uploads', 'tools'].includes(e.name)) continue;
    if (e.name.startsWith('.') || e.name === 'about_php') continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full);
    else if (e.name.endsWith('.php')) files.push(path.relative(ROOT, full).split(path.sep).join('/'));
  }
})(ROOT);

const runtime = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } });
const php = new PHP(runtime);
const payload = JSON.stringify(files);
php.writeFile('/files.json', payload);
php.writeFile('/lint.php', `<?php
$files = json_decode(file_get_contents('/files.json'), true);
$bad = 0;
foreach ($files as $f) {
    $path = '/www/' . $f;
    if (!is_file($path)) { echo "EKSIK $f\\n"; $bad++; continue; }
    try { token_get_all(file_get_contents($path), TOKEN_PARSE); }
    catch (ParseError $e) { echo "HATA $f:" . $e->getLine() . " " . $e->getMessage() . "\\n"; $bad++; }
}
echo $bad === 0 ? "Tüm PHP dosyaları sözdizimi kontrolünden geçti (" . count($files) . ")\\n" : "Hatalı dosya: $bad\\n";
exit($bad === 0 ? 0 : 1);
`);
php.mkdirTree('/www');
function copy(dir, rel = '') {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'storage', 'uploads', 'tools'].includes(e.name)) continue;
    if (e.name.startsWith('.') || e.name === 'about_php') continue;
    const full = path.join(dir, e.name);
    const relPath = path.posix.join(rel, e.name);
    if (e.isDirectory()) { copy(full, relPath); continue; }
    if (!e.name.endsWith('.php')) continue;
    const target = path.posix.join('/www', relPath);
    php.mkdirTree(path.posix.dirname(target));
    php.writeFile(target, new Uint8Array(fs.readFileSync(full)));
  }
}
copy(ROOT);
const r = await php.run({ scriptPath: '/lint.php' });
process.stdout.write(new TextDecoder().decode(r.bytes));
if (r.errors) process.stderr.write(String(r.errors) + '\n');
process.exit(r.exitCode ?? 0);
