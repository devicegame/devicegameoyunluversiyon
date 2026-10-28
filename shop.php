<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/dcoin-storefront.php';

$page_title = 'Mağaza';
$page_meta_desc = 'Oyun keyleri, premium hesaplar, abonelikler ve Device Game imzalı ekipmanlar. Sepetini oluştur, siparişini WhatsApp üzerinden saniyeler içinde tamamla.';
$page_css = ['shop.css', 'assets/css/dcoin.css'];
$body_class = 'shop-page dcoin-page';

$categories = shop_categories();
$active_category = isset($_GET['kategori']) ? (string)$_GET['kategori'] : '';
if ($active_category !== '' && !isset($categories[$active_category])) {
    $active_category = '';
}
$query = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sirala'] ?? '');
$all_products = shop_products();
$products = shop_catalog($active_category, $query, $sort);

$dg_login_params = array_filter([
    'kategori' => $active_category,
    'q' => $query,
    'sirala' => $sort,
], static fn($value): bool => $value !== '');
$dg_login_query = http_build_query($dg_login_params, '', '&', PHP_QUERY_RFC3986);
$dg_login_next = 'shop.php' . ($dg_login_query !== '' ? '?' . $dg_login_query : '');
$dg_context = dg_storefront_context($all_products, $dg_login_next);

$page_canonical = false;
if ($query === '' && $sort === '') {
    $page_canonical = 'shop.php' . ($active_category !== '' ? '?kategori=' . rawurlencode($active_category) : '');
}
$page_robots = ($query !== '' || $sort !== '') ? 'noindex,follow' : '';
$page_og_type = 'website';
$page_og_title = 'Mağaza';
$page_og_desc = $page_meta_desc;

include 'header.php';

$all_counts = [];
foreach ($all_products as $p) {
    $all_counts[$p['category']] = ($all_counts[$p['category']] ?? 0) + 1;
}
$is_filtered = $active_category !== '' || $query !== '';
?>

<section class="shop-hero">
    <div class="shop-hero-inner">
        <div>
            <p class="eyebrow seq" style="--d:.05s">Device Game Mağaza</p>
            <h1 class="seq" style="--d:.15s">Oyunu seç,<br>gerisini <em>bize bırak.</em></h1>
            <p class="shop-hero-desc seq" style="--d:.3s">
                Oyun keyleri, premium hesaplar ve stüdyo imzalı ekipmanlar.
                Sepetini oluşturup WhatsApp'ta onayla ya da D-Coin bakiyenle hemen al; teslimatı biz hallederiz.
            </p>
            <div class="shop-hero-actions seq" style="--d:.4s">
                <a href="#katalog" class="btn btn-primary">Kataloğa göz at</a>
                <a href="cart.php" class="btn btn-ghost">Sepetim<?php echo cart_count() > 0 ? ' (' . cart_count() . ')' : ''; ?></a>
            </div>
        </div>

        <div class="hero-stats seq" style="--d:.5s">
            <div class="hero-stat hero-stat-wide">
                <span class="live"><span class="pulse" aria-hidden="true"></span>Sipariş hattı açık</span>
                <span class="live-meta">Ortalama yanıt 4 dk</span>
            </div>
            <div class="hero-stat">
                <b><?php echo count(shop_products()); ?></b>
                <span>seçili ürün</span>
            </div>
            <div class="hero-stat">
                <b>4.9<span style="font-size:1.1rem;color:var(--signal)">/5</span></b>
                <span>müşteri puanı</span>
            </div>
            <div class="hero-stat">
                <b>&lt; 5 dk</b>
                <span>ortalama teslimat</span>
            </div>
            <div class="hero-stat">
                <b>7/24</b>
                <span>WhatsApp sipariş hattı</span>
            </div>
        </div>
    </div>

    <div class="ticker" aria-hidden="true">
        <div class="ticker-track">
            <span>Anında teslimat</span><span>WhatsApp ile sipariş</span><span>Güvenli ödeme</span>
            <span>7 gün destek</span><span>Stüdyo imzalı ürünler</span><span>Türkiye'de üretim</span>
            <span>Anında teslimat</span><span>WhatsApp ile sipariş</span><span>Güvenli ödeme</span>
            <span>7 gün destek</span><span>Stüdyo imzalı ürünler</span><span>Türkiye'de üretim</span>
        </div>
    </div>
</section>

<?php include 'partials/slider.php'; ?>

<?php $spotlight = shop_product('ea-sports-fc-26'); if ($spotlight && !$is_filtered): ?>
<section class="shop-section" style="padding-top:clamp(2.5rem,5vw,4rem);padding-bottom:0;">
    <article class="spotlight">
        <div class="spotlight-art" data-pattern="<?php echo e($spotlight['pattern']); ?>"
             style="--c1:<?php echo e($spotlight['c1']); ?>;--c2:<?php echo e($spotlight['c2']); ?>;--accent:<?php echo e($spotlight['accent']); ?>">
            <?php echo poster_svg($spotlight, 'spot'); ?>
            <span class="spotlight-tag">Haftanın seçkisi</span>
            <span class="spotlight-glyph" aria-hidden="true"><?php echo e($spotlight['glyph']); ?></span>
            <div class="spotlight-art-foot">
                <span><?php echo e($spotlight['platform']); ?></span>
                <span><?php echo e($spotlight['delivery']); ?></span>
            </div>
        </div>
        <div class="spotlight-body">
            <p class="eyebrow"><?php echo e($categories[$spotlight['category']] ?? ''); ?> · <?php echo e($spotlight['platform']); ?></p>
            <h2><?php echo e($spotlight['name']); ?></h2>
            <p class="muted"><?php echo e($spotlight['desc']); ?></p>
            <ul class="spotlight-features">
                <?php foreach (array_slice($spotlight['features'], 0, 3) as $feature): ?>
                    <li>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                        <?php echo e($feature); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="spotlight-buy">
                <div class="price">
                    <b><?php echo e(money($spotlight['price'])); ?></b>
                    <?php if (!empty($spotlight['old_price'])): ?>
                        <s><?php echo e(money($spotlight['old_price'])); ?></s>
                    <?php endif; ?>
                </div>
                <form method="post" action="cart.php" class="add-form">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="slug" value="<?php echo e($spotlight['slug']); ?>">
                    <input type="hidden" name="qty" value="1">
                    <input type="hidden" name="redirect" value="shop.php">
                    <button type="submit" class="btn btn-primary">Sepete ekle</button>
                </form>
                <a class="btn btn-ghost" href="product.php?slug=<?php echo urlencode($spotlight['slug']); ?>">Ürünü incele</a>
            </div>
            <?php dg_storefront_render_buy(dg_storefront_item($dg_context, $spotlight), $dg_context, 'spotlight'); ?>
        </div>
    </article>
</section>
<?php endif; ?>

<section class="shop-section" id="katalog">
    <div class="shop-section-head">
        <div>
            <p class="eyebrow">Katalog</p>
            <h2>Rafı karıştır.</h2>
        </div>
        <p><?php echo $is_filtered ? 'Filtreye uyan ürünleri listeliyoruz.' : 'Her ürün tek tek test edildi. Fiyatlar KDV dahil, sürpriz yok.'; ?></p>
    </div>

    <form class="filters" method="get" action="shop.php" role="search">
        <div class="filter-row">
            <div class="chips">
                <a class="chip" href="<?php echo e(url_with(['q' => $query, 'sirala' => $sort])); ?>"
                   aria-current="<?php echo $active_category === '' ? 'true' : 'false'; ?>">
                    Tümü <small><?php echo count(shop_products()); ?></small>
                </a>
                <?php foreach ($categories as $key => $label): ?>
                    <a class="chip" href="<?php echo e(url_with(['kategori' => $key, 'q' => $query, 'sirala' => $sort])); ?>"
                       aria-current="<?php echo $active_category === $key ? 'true' : 'false'; ?>">
                        <?php echo e($label); ?> <small><?php echo (int)($all_counts[$key] ?? 0); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="filter-row">
            <label class="search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
                </svg>
                <input type="search" name="q" value="<?php echo e($query); ?>" placeholder="Ürün, platform ya da tür ara" aria-label="Mağazada ara">
                <?php if ($active_category !== ''): ?>
                    <input type="hidden" name="kategori" value="<?php echo e($active_category); ?>">
                <?php endif; ?>
                <?php if ($sort !== ''): ?>
                    <input type="hidden" name="sirala" value="<?php echo e($sort); ?>">
                <?php endif; ?>
                <button type="submit">Ara</button>
            </label>

            <div class="filter-tools">
                <span class="filter-summary"><strong><?php echo count($products); ?></strong> ürün listeleniyor</span>
                <div class="select-wrap">
                    <select name="sirala" aria-label="Sıralama" data-sort-select>
                        <option value="" <?php echo $sort === '' ? 'selected' : ''; ?>>Öne çıkanlar</option>
                        <option value="ucuz" <?php echo $sort === 'ucuz' ? 'selected' : ''; ?>>Fiyat: artan</option>
                        <option value="pahali" <?php echo $sort === 'pahali' ? 'selected' : ''; ?>>Fiyat: azalan</option>
                        <option value="yeni" <?php echo $sort === 'yeni' ? 'selected' : ''; ?>>En yeni</option>
                    </select>
                </div>
                <button type="submit" class="chip" data-sort-submit>Sırala</button>
            </div>
        </div>
    </form>

    <div class="p-grid">
        <?php if (!$products): ?>
            <div class="empty-state">
                <h3>Aradığını bulamadık.</h3>
                <p>Farklı bir kelime dene ya da WhatsApp'tan yaz; elimizde olmayanı da bulmaya çalışırız.</p>
                <a class="btn btn-primary" href="<?php echo e(wa_link($whatsapp, 'Merhaba, aradığım ürünü mağazada bulamadım. Yardımcı olur musunuz?')); ?>" target="_blank" rel="noopener">WhatsApp'tan sor</a>
            </div>
        <?php else: ?>
            <?php foreach ($products as $index => $product): ?>
                <?php include 'partials/product-card.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php
$viewed_products = [];
foreach (($_SESSION['viewed'] ?? []) as $viewed_slug) {
    $viewed_item = shop_product((string)$viewed_slug);
    if ($viewed_item) {
        $viewed_products[] = $viewed_item;
    }
}
if (count($viewed_products) >= 2):
?>
<section class="shop-section" style="padding-top:0;">
    <div class="shop-section-head">
        <div>
            <p class="eyebrow">Senin için</p>
            <h2>Son baktıkların.</h2>
        </div>
        <p>Kaldığın yerden devam et.</p>
    </div>
    <div class="p-grid">
        <?php foreach (array_slice($viewed_products, 0, 4) as $index => $viewed_card): ?>
            <?php $product = $viewed_card; include 'partials/product-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="trust-band">
    <div class="trust-grid">
        <div class="trust-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5Z"/></svg>
            <b>Anında teslimat</b>
            <span>Dijital ürünler ödeme onayından sonra dakikalar içinde elinde.</span>
        </div>
        <div class="trust-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v5.5c0 4.6 3.2 8.3 8 9.5 4.8-1.2 8-4.9 8-9.5V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
            <b>Güvenli sipariş</b>
            <span>Ödemeyi onaylamadan önce her detayı WhatsApp'ta konuşuyoruz.</span>
        </div>
        <div class="trust-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z"/></svg>
            <b>Gerçek insan destek</b>
            <span>Sipariş öncesi ve sonrası aynı ekip yanıt veriyor.</span>
        </div>
        <div class="trust-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <b>Şeffaf fiyat</b>
            <span>Katalogdaki fiyat neyse ödediğin de o. Gizli ücret yok.</span>
        </div>
    </div>
</section>

<section class="shop-section">
    <div class="shop-section-head">
        <div>
            <p class="eyebrow">Nasıl işliyor?</p>
            <h2>Sipariş üç adım.</h2>
        </div>
        <p>Hesap açmak, form doldurmak, onay beklemek yok. Sepeti tamamla, WhatsApp açılsın.</p>
    </div>
    <div class="p-grid cols-3">
        <article class="p-card" style="padding:1.75rem;">
            <p class="eyebrow" style="margin-bottom:1rem;">01</p>
            <h3 class="p-name" style="font-size:1.35rem;margin-bottom:.6rem;">Sepetini doldur</h3>
            <p class="muted" style="font-size:.92rem;">Beğendiğin ürünleri sepete ekle, adetleri düzenle. Sepet oturum boyunca seninle kalır.</p>
        </article>
        <article class="p-card" style="padding:1.75rem;">
            <p class="eyebrow" style="margin-bottom:1rem;">02</p>
            <h3 class="p-name" style="font-size:1.35rem;margin-bottom:.6rem;">WhatsApp'ta onayla</h3>
            <p class="muted" style="font-size:.92rem;">Sipariş özeti hazır bir mesaj olarak WhatsApp'ta açılır. Göndermen yeterli.</p>
        </article>
        <article class="p-card" style="padding:1.75rem;">
            <p class="eyebrow" style="margin-bottom:1rem;">03</p>
            <h3 class="p-name" style="font-size:1.35rem;margin-bottom:.6rem;">Teslimatı al</h3>
            <p class="muted" style="font-size:.92rem;">Ödemeyi Havale / EFT ile tamamla; dijital teslimatın dakikalar içinde WhatsApp'ına gelsin.</p>
        </article>
    </div>
</section>

<section class="shop-section" style="padding-top:0;">
    <div class="shop-section-head">
        <div>
            <p class="eyebrow">Sık sorulanlar</p>
            <h2>Aklında kalan olmasın.</h2>
        </div>
    </div>
    <div class="faq-list">
        <details class="faq-item">
            <summary>Ödemeyi nasıl yapıyorum?</summary>
            <p>Ödeme tek yöntemle yapılır: Havale / EFT. WhatsApp'ta siparişi onayladıktan sonra banka bilgilerimizi paylaşıyoruz; ödeme onayı gelmeden teslimat yapılmaz, böylece iki taraf da güvende olur.</p>
        </details>
        <details class="faq-item">
            <summary>Key ve hesaplar ne kadar sürede geliyor?</summary>
            <p>Çalışma saatleri içinde dijital teslimatlar ortalama 5 dakika sürüyor, en geç çalışma saati sonunda iletilir. Abonelik kodları stoktan anında gönderilir.</p>
        </details>
        <details class="faq-item">
            <summary>Hesap alırken şifremi vermem gerekiyor mu?</summary>
            <p>Hayır. Hesap ürünlerinde sana kullanıcı adı ve şifre iletilir; mail değişimi sende kalır. VP yüklemesi gibi işlemlerde yalnızca Riot kullanıcı adı istenir.</p>
        </details>
        <details class="faq-item">
            <summary>Ekipman ve baskı ürünleri nasıl teslim ediliyor?</summary>
            <p>Teslimat şeklimiz dijitaldir; key ve hesaplar ödeme onayından sonra WhatsApp üzerinden iletilir. Ekipman ve baskı siparişlerinde kargo detaylarını (adres ve süre) yine WhatsApp konuşmasında birlikte netleştiriyoruz.</p>
        </details>
        <details class="faq-item">
            <summary>İade ve destek nasıl işliyor?</summary>
            <p>Dijital key ve hesaplarda aktivasyon öncesi iade hakkın var. Fiziksel ürünlerde ayıplı ürün için 14 gün içinde değişim yapıyoruz. Her durumda aynı WhatsApp hattından bize ulaşabilirsin.</p>
        </details>
    </div>
</section>

<section class="cta-band">
    <div>
        <p class="eyebrow" style="margin-bottom:1rem;">Karar veremedin mi?</p>
        <h2>Ne alacağını birlikte bulalım.</h2>
        <p>Bütçeni ve oynadığın platformu söyle; kısa yoldan en doğru seçeneği önerelim.</p>
    </div>
    <div class="cta-band-actions">
        <a class="btn btn-primary" href="<?php echo e(wa_link($whatsapp, 'Merhaba! Bütçeme uygun bir oyun/ürün arıyorum, yardımcı olur musunuz?')); ?>" target="_blank" rel="noopener">WhatsApp'tan danış</a>
        <a class="btn btn-ghost" href="contact.php">İletişim sayfası</a>
    </div>
</section>

<?php dg_storefront_render_assets($dg_context); ?>
<?php include 'footer.php'; ?>
