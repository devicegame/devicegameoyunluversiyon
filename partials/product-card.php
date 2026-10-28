<?php
/**
 * Ürün kartı parçası
 * Beklenen: $product (dizi), $index (opsiyonel sıra no)
 */
$product = $product ?? null;
if (!$product) {
    return;
}
$category_labels = shop_categories();
$category_label = $category_labels[$product['category']] ?? '';
$badge_class = 'p-badge';
if ($product['badge'] === 'Stüdyo imzası') {
    $badge_class .= ' signature';
} elseif ($product['badge'] === 'Sınırlı') {
    $badge_class .= ' limited';
}
$in_cart = (int)(cart_raw()[$product['slug']] ?? 0);
$index_label = isset($index) ? str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) : '';
$product_card_dg_item = (isset($dg_context) && is_array($dg_context) && function_exists('dg_storefront_item'))
    ? dg_storefront_item($dg_context, $product)
    : null;
?>
<article class="p-card<?php echo isset($dg_context) && is_array($dg_context) ? ' dcoin-card' : ''; ?>">
    <a class="p-card-media" href="product.php?slug=<?php echo urlencode($product['slug']); ?>" aria-label="<?php echo e($product['name']); ?> detayları">
        <div class="poster" data-pattern="<?php echo e($product['pattern']); ?>"
             style="--c1:<?php echo e($product['c1']); ?>;--c2:<?php echo e($product['c2']); ?>;--accent:<?php echo e($product['accent']); ?>">
            <?php echo poster_svg($product); ?>
            <?php if (!empty($product['image']) && (str_starts_with($product['image'], 'http') || is_file(__DIR__ . '/../' . $product['image']))): ?>
                <img class="poster-img" src="<?php echo e($product['image']); ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
            <?php if (!empty($product['badge'])): ?>
                <span class="<?php echo e($badge_class); ?>"><?php echo e($product['badge']); ?></span>
            <?php endif; ?>
            <div class="poster-top">
                <span class="poster-tag"><?php echo e($product['platform']); ?></span>
            </div>
            <span class="poster-glyph" aria-hidden="true"><?php echo e($product['glyph']); ?></span>
            <div class="poster-bottom">
                <span class="poster-meta"><?php echo e($category_label); ?></span>
                <span class="poster-index"><?php echo e($index_label); ?></span>
            </div>
        </div>
    </a>
    <div class="p-card-body">
        <p class="p-kicker"><?php echo e($category_label); ?></p>
        <h3 class="p-name"><a href="product.php?slug=<?php echo urlencode($product['slug']); ?>"><?php echo e($product['name']); ?></a></h3>
        <p class="p-sub"><?php echo e($product['subtitle']); ?></p>
        <div class="p-card-foot">
            <div class="price">
                <b><?php echo e(money($product['price'])); ?></b>
                <?php if (!empty($product['old_price'])): ?>
                    <s><?php echo e(money($product['old_price'])); ?></s>
                <?php endif; ?>
            </div>
            <form method="post" action="cart.php" class="add-form">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="slug" value="<?php echo e($product['slug']); ?>">
                <input type="hidden" name="qty" value="1">
                <input type="hidden" name="redirect" value="<?php echo e($_SERVER['REQUEST_URI'] ?? 'shop.php'); ?>">
                <button type="submit" class="add-btn<?php echo $in_cart > 0 ? ' is-done' : ''; ?>"
                        aria-label="<?php echo e($product['name']); ?> sepete ekle" title="Sepete ekle">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                </button>
            </form>
        </div>
        <?php if (isset($dg_context) && is_array($dg_context) && function_exists('dg_storefront_render_buy')): ?>
            <?php dg_storefront_render_buy($product_card_dg_item, $dg_context, 'card'); ?>
        <?php endif; ?>
    </div>
</article>
