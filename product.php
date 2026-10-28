<?php
require_once __DIR__ . '/config.php';

$slug = (string)($_GET['slug'] ?? '');
$product = shop_product($slug);

if (!$product) {
    http_response_code(404);
    $page_title = 'Ürün bulunamadı';
    $page_canonical = false;
    $page_robots = 'noindex,nofollow';
    $page_css = ['shop.css'];
    $body_class = 'shop-page';
    include 'header.php';
    ?>
    <section class="order-done">
        <p class="eyebrow" style="justify-content:center;">404</p>
        <h1>Bu ürünü rafta bulamadık.</h1>
        <p>Kaldırılmış ya da adresi değişmiş olabilir. Kataloğa dönüp yeniden göz atabilirsin.</p>
        <a class="btn btn-primary" href="shop.php">Mağazaya dön</a>
    </section>
    <?php
    include 'footer.php';
    exit;
}

require_once __DIR__ . '/partials/dcoin-storefront.php';
$current_url = 'product.php?slug=' . urlencode($product['slug']);
$dg_context = dg_storefront_context(shop_products(), $current_url);
$dg_item = dg_storefront_item($dg_context, $product);
$dg_price = $dg_item ? (int)($dg_item['price_dcoin'] ?? 0) : 0;

$page_title = $product['name'];
$page_meta_desc = $product['desc'];
$page_canonical = 'product.php?slug=' . rawurlencode((string)$product['slug']);
$page_og_type = 'product';
$page_og_title = (string)$product['name'];
$page_og_desc = (string)$product['desc'];
$page_og_image = !empty($product['image']) ? (string)$product['image'] : 'assets/img/devicegame-og.png';
$page_og_image_alt = (string)$product['name'];
$page_css = ['shop.css', 'assets/css/dcoin.css'];
$body_class = 'shop-page has-bar dcoin-page';
include 'header.php';

// Son bakılanlar (en fazla 4 ürün)
$viewed = array_values(array_filter($_SESSION['viewed'] ?? [], fn($s) => $s !== $product['slug']));
array_unshift($viewed, $product['slug']);
$_SESSION['viewed'] = array_slice(array_unique($viewed), 0, 4);

$categories = shop_categories();
$category_label = $categories[$product['category']] ?? '';
$discount = !empty($product['old_price']) && $product['old_price'] > $product['price']
    ? (int)round(100 - ($product['price'] / $product['old_price'] * 100))
    : 0;

$related = array_values(array_filter(shop_products(), fn($p) => $p['category'] === $product['category'] && $p['slug'] !== $product['slug']));
usort($related, fn($a, $b) => abs($a['price'] - $product['price']) <=> abs($b['price'] - $product['price']));
$related = array_slice($related, 0, 4);

$wa_question = 'Merhaba, "' . $product['name'] . '" hakkında bilgi almak istiyorum. (' . money($product['price']) . ')';
?>

<div class="pdp">
    <nav class="breadcrumb" aria-label="Konum">
        <a href="index.php">Ana sayfa</a>
        <span class="sep">/</span>
        <a href="shop.php">Mağaza</a>
        <span class="sep">/</span>
        <a href="shop.php?kategori=<?php echo urlencode($product['category']); ?>#katalog"><?php echo e($category_label); ?></a>
        <span class="sep">/</span>
        <span class="muted"><?php echo e($product['name']); ?></span>
    </nav>

    <div class="pdp-layout">
        <div class="pdp-gallery">
            <div class="pdp-poster">
                <div class="poster" data-pattern="<?php echo e($product['pattern']); ?>"
                     style="--c1:<?php echo e($product['c1']); ?>;--c2:<?php echo e($product['c2']); ?>;--accent:<?php echo e($product['accent']); ?>">
                    <?php echo poster_svg($product, 'pdp'); ?>
                    <?php if (!empty($product['image']) && (str_starts_with($product['image'], 'http') || is_file(__DIR__ . '/../' . $product['image']))): ?>
                        <img class="poster-img" src="<?php echo e($product['image']); ?>" alt="<?php echo e($product['name']); ?> kapak görseli">
                    <?php endif; ?>
                    <div class="poster-top">
                        <span class="poster-tag"><?php echo e($product['platform']); ?></span>
                        <?php if (!empty($product['badge'])): ?>
                            <span class="poster-tag" style="color:var(--pine);background:var(--accent);border-color:transparent;"><?php echo e($product['badge']); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="poster-glyph" aria-hidden="true"><?php echo e($product['glyph']); ?></span>
                    <div class="poster-bottom">
                        <span class="poster-meta"><?php echo e($category_label); ?> · <?php echo e($company_name); ?></span>
                        <span class="poster-index"><?php echo e(date('Y')); ?></span>
                    </div>
                </div>
            </div>
            <div class="pdp-thumbs" aria-hidden="true">

            </div>
        </div>

        <div class="pdp-info">
            <p class="eyebrow"><?php echo e($category_label); ?> · <?php echo e($product['platform']); ?></p>
            <h1 class="pdp-title"><?php echo e($product['name']); ?></h1>
            <p class="pdp-sub"><?php echo e($product['subtitle']); ?></p>

            <div class="pdp-price-row">
                <span class="pdp-price"><?php echo e(money($product['price'])); ?></span>
                <?php if (!empty($product['old_price'])): ?>
                    <span class="pdp-old"><?php echo e(money($product['old_price'])); ?></span>
                <?php endif; ?>
                <?php if ($discount > 0): ?>
                    <span class="pdp-save">%<?php echo $discount; ?> indirim</span>
                <?php endif; ?>
                <?php if ($dg_item && $dg_price > 0): ?>
                    <span class="pdp-dcoin-price">veya <b><?php echo number_format($dg_price, 0, ',', '.'); ?> D-Coin</b></span>
                <?php endif; ?>
                <span class="pdp-stock">
                    <span class="pulse" aria-hidden="true"></span>
                    <?php if (!empty($product['stock']) && $product['stock'] <= 10): ?>
                        Son <?php echo (int)$product['stock']; ?> adet · <?php echo e($product['delivery']); ?>
                    <?php else: ?>
                        Stokta · <?php echo e($product['delivery']); ?>
                    <?php endif; ?>
                </span>
            </div>

            <p class="pdp-desc"><?php echo e($product['desc']); ?></p>

            <ul class="pdp-features">
                <?php foreach ($product['features'] as $feature): ?>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                        <span><?php echo e($feature); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post" action="cart.php" class="buy-form" id="buy-form">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="slug" value="<?php echo e($product['slug']); ?>">
                <div class="buy-row">
                    <div class="qty" data-qty>
                        <button type="button" data-qty-down aria-label="Adedi azalt">−</button>
                        <input type="number" name="qty" value="1" min="1" max="10" inputmode="numeric" aria-label="Adet">
                        <button type="button" data-qty-up aria-label="Adedi artır">+</button>
                    </div>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="margin-right:.5rem"><path d="M6 6h15l-1.5 9h-12L6 6Zm0 0L5 3H2"/><circle cx="9.5" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>
                        Sepete ekle
                    </button>
                    <button type="submit" name="action" value="buy" class="btn btn-ghost">Hemen al</button>
                </div>
                <input type="hidden" name="redirect" value="<?php echo e($current_url); ?>">
            </form>

            <?php dg_storefront_render_buy($dg_item, $dg_context, 'detail'); ?>

            <dl class="pdp-meta">
                <div class="pdp-meta-row"><dt>Teslimat</dt><dd><?php echo e($product['delivery']); ?></dd></div>
                <div class="pdp-meta-row"><dt>Platform</dt><dd><?php echo e($product['platform']); ?></dd></div>
                <div class="pdp-meta-row"><dt>Ödeme</dt><dd>Havale / EFT<?php echo ($dg_item && $dg_price > 0) ? ' veya D-Coin' : ''; ?></dd></div>
                <div class="pdp-meta-row"><dt>Destek</dt><dd>WhatsApp · sipariş sonrası 7 gün</dd></div>
            </dl>

            <p class="pdp-assist">
                Emin olamadığın bir şey mi var?
                <a href="<?php echo e(wa_link($whatsapp, $wa_question)); ?>" target="_blank" rel="noopener">WhatsApp'tan sor</a>
                · genelde 10 dakika içinde yanıtlıyoruz.
            </p>
        </div>
    </div>
</div>

<?php if ($related): ?>
<section class="shop-section" style="padding-top:0;">
    <div class="shop-section-head">
        <div>
            <p class="eyebrow">Benzer ürünler</p>
            <h2>Bunlar da ilgini çekebilir.</h2>
        </div>
        <a class="text-link" href="shop.php?kategori=<?php echo urlencode($product['category']); ?>#katalog">Tümünü gör</a>
    </div>
    <div class="p-grid">
        <?php foreach ($related as $index => $related_product): ?>
            <?php $product_backup = $product; $product = $related_product; include 'partials/product-card.php'; $product = $product_backup; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="mobile-bar">
    <div class="mobile-bar-info">
        <span><?php echo e($product['name']); ?></span>
        <b><?php echo e(money($product['price'])); ?></b>
        <?php if ($dg_item && $dg_price > 0): ?>
            <small class="mobile-dcoin-price"><?php echo number_format($dg_price, 0, ',', '.'); ?> D-Coin</small>
        <?php endif; ?>
    </div>
    <?php dg_storefront_render_buy($dg_item, $dg_context, 'mobile'); ?>
    <button type="submit" form="buy-form" name="action" value="add" class="btn btn-primary">Sepete ekle</button>
</div>

<?php dg_storefront_render_assets($dg_context); ?>
<?php include 'footer.php'; ?>
