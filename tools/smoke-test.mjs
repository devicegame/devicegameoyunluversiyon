/* Önizleme sunucusu çalışırken: cd tools && npm test */
import assert from 'node:assert/strict';

const base = new URL(process.env.PREVIEW_URL || 'http://127.0.0.1:8000/');
if (!base.pathname.endsWith('/')) base.pathname += '/';
const themeUrl = new URL('assets/css/colorful-theme.css', base);

async function get(path) {
    const response = await fetch(new URL(path, base));
    assert.equal(response.status, 200, `${path || '/'} HTTP ${response.status}`);
    return response;
}

for (const path of ['', 'shop.php', 'login.php', 'register.php', 'about.php', 'contact.php', 'blog/index.php', 'sosyal/index.php', 'admin.php']) {
    const response = await fetch(new URL(path, base));
    const html = await response.text();
    if (path === 'admin.php' && response.status === 503) {
        assert.match(html, /DG_ADMIN_PASSWORD/, 'Yapılandırılmamış panel açıklama göstermiyor');
        console.log('✓ admin.php (şifre yapılandırılmadığı için kapalı)');
        continue;
    }
    assert.equal(response.status, 200, `${path || '/'} HTTP ${response.status}`);
    assert.match(html, /<h1\b/i, `${path || '/'} başlık içermiyor`);
    assert.doesNotMatch(html, /(?:Fatal error|Parse error|Warning):/, `${path || '/'} PHP hatası içeriyor`);
    const theme = html.match(/href="([^"]*assets\/css\/colorful-theme\.css)"/);
    assert.ok(theme, `${path || '/'} ortak temayı yüklemiyor`);
    assert.equal(new URL(theme[1], response.url).pathname, themeUrl.pathname, `${path || '/'} tema yolu yanlış`);
    if (!path) {
        assert.match(html, /class="hero-art"/, 'Ana sayfa görseli eksik');
        assert.match(html, /id="storeSearch"/, 'Mağaza araması eksik');
        assert.match(html, /id="dcoin"/, 'D-Coin bölümü eksik');
    }
    console.log(`✓ ${path || '/'}`);
}

const css = await (await get('assets/css/colorful-theme.css')).text();
assert.match(css, /--gradient-brand\s*:/, 'Marka geçişi eksik');
for (const path of [
    'assets/img/gradient-game-hero.webp',
    'assets/fonts/bricolage-grotesque-latin-wght-normal.woff2',
    'assets/fonts/bricolage-grotesque-latin-ext-wght-normal.woff2',
    'assets/fonts/instrument-sans-latin-wght-normal.woff2',
    'assets/fonts/instrument-sans-latin-ext-wght-normal.woff2',
]) {
    const response = await get(path);
    assert.ok((await response.arrayBuffer()).byteLength > 1000, `${path} boş`);
    console.log(`✓ ${path}`);
}
console.log('Tema ve sayfa kontrolleri başarılı.');
