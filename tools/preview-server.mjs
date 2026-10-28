/**
 * Device Game — yerel önizleme sunucusu
 *
 * Projede PHP kurulu olmasa da siteyi çalıştırmak için PHP'yi WebAssembly
 * içinde başlatır ve gelen HTTP isteklerini PHP'ye aktarır.
 * Canlı sunucuda buna gerek yoktur: `php -S 0.0.0.0:8000` yeterlidir.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadNodeRuntime } from '@php-wasm/node';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';

const here = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(here, '..');
const PORT = Number(process.env.PORT || 8000);
const DOC_ROOT = '/www';
const SKIP = new Set(['node_modules', '.git', 'storage', 'tools', '.arena', 'dist', 'build']);

console.log('[preview] PHP (wasm) yükleniyor…');
const runtime = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } });
const php = new PHP(runtime);

// PHPRequestHandler, Node ortam değişkenlerini kendiliğinden PHP'ye aktarmaz.
// Yalnızca panel için gereken değişkeni her PHP isteğine bellekten ilet.
if (process.env.DG_ADMIN_PASSWORD) {
    const adminPassword = process.env.DG_ADMIN_PASSWORD;
    const runStream = php.runStream.bind(php);
    php.runStream = (request) => runStream({
        ...request,
        env: { ...request.env, DG_ADMIN_PASSWORD: adminPassword },
    });
}
php.mkdirTree(DOC_ROOT);

const handler = new PHPRequestHandler({
    php,
    documentRoot: DOC_ROOT,
    absoluteUrl: `http://localhost:${PORT}`,
    cookieStore: false,
});

/** Proje dosyalarını PHP dosya sistemine kopyalar. */
const synced = new Map();
function syncDir(dir, rel = '') {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name.startsWith('.') && entry.name !== '.htaccess') continue;
        const full = path.join(dir, entry.name);
        const relPath = path.posix.join(rel, entry.name);
        if (entry.isDirectory()) {
            if (SKIP.has(entry.name)) continue;
            syncDir(full, relPath);
            continue;
        }
        const stat = fs.statSync(full);
        const prev = synced.get(relPath);
        if (prev === stat.mtimeMs) continue;
        const target = path.posix.join(DOC_ROOT, relPath);
        php.mkdirTree(path.posix.dirname(target));
        php.writeFile(target, new Uint8Array(fs.readFileSync(full)));
        synced.set(relPath, stat.mtimeMs);
    }
}

/**
 * Eski mağazanın repodaki aktif kataloğunu ilk açılışta sanal dosya sistemine al.
 * storage/dg/ özellikle kopyalanmaz; D-Coin verileri önizleme sürecinde oluşur.
 */
let storageSeeded = false;
function seedStorage() {
    if (storageSeeded) return;
    php.mkdirTree(path.posix.join(DOC_ROOT, 'storage'));
    for (const name of ['products.json', 'slides.json', 'blog.json', 'blog-categories.json', '.htaccess', 'index.html']) {
        const source = path.join(ROOT, 'storage', name);
        if (!fs.existsSync(source)) continue;
        php.writeFile(
            path.posix.join(DOC_ROOT, 'storage', name),
            new Uint8Array(fs.readFileSync(source))
        );
    }
    storageSeeded = true;
}

let queue = Promise.resolve();
function enqueue(task) {
    const run = queue.then(task, task);
    queue = run.catch(() => {});
    return run;
}

const MIME_FALLBACK = {
    '.css': 'text/css; charset=UTF-8',
    '.js': 'application/javascript; charset=UTF-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.webp': 'image/webp',
    '.woff2': 'font/woff2',
    '.ico': 'image/x-icon',
};

const server = http.createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = Buffer.concat(chunks);
    const url = new URL(req.url, `http://localhost:${PORT}`);

    try {
        syncDir(ROOT);
        seedStorage();

        const headers = {};
        for (const [key, value] of Object.entries(req.headers)) {
            if (value !== undefined) headers[key] = Array.isArray(value) ? value.join(', ') : String(value);
        }

        const response = await enqueue(() =>
            handler.request({
                url: url.pathname + url.search,
                method: req.method || 'GET',
                headers,
                body: body.length ? new Uint8Array(body) : undefined,
            })
        );

        let outHeaders = response.headers || {};
        if (!outHeaders['content-type'] && !outHeaders['Content-Type']) {
            const ext = path.extname(url.pathname).toLowerCase();
            if (MIME_FALLBACK[ext]) outHeaders = { ...outHeaders, 'content-type': [MIME_FALLBACK[ext]] };
        }
        // Önizleme iframe içinde açıldığı için çerçeve kısıtlarını kaldır.
        delete outHeaders['x-frame-options'];
        delete outHeaders['X-Frame-Options'];
        for (const key of Object.keys(outHeaders)) {
            if (key.toLowerCase() === 'content-security-policy') {
                delete outHeaders[key];
            }
        }

        res.writeHead(response.httpStatusCode || 200, outHeaders);
        res.end(Buffer.from(response.bytes));
        if (response.errors) console.error('[php]', response.errors);
    } catch (error) {
        console.error('[preview] hata:', error);
        res.writeHead(500, { 'content-type': 'text/plain; charset=UTF-8' });
        res.end('Önizleme sunucusu hata verdi: ' + (error?.message || error));
    }
});

server.listen(PORT, '0.0.0.0', () => {
    console.log(`[preview] Device Game hazır → http://localhost:${PORT}`);
});
