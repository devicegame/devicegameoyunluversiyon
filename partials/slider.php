<?php
/**
 * Mağaza vitrini slider'ı (admin panelden yönetilir).
 * Beklenen: yok — shop_slides() kendisi okur.
 */
$slides = shop_slides();
if (!$slides) {
    return;
}
?>
<section class="shop-section promo-wrap" aria-label="Öne çıkanlar">
    <div class="promo-slider" data-slider data-count="<?php echo count($slides); ?>">
        <div class="promo-stage">
            <?php foreach ($slides as $index => $slide): ?>
                <article class="promo-slide<?php echo $index === 0 ? ' is-active' : ''; ?>"
                         style="--s1:<?php echo e($slide['c1']); ?>;--s2:<?php echo e($slide['c2']); ?>"
                         <?php echo $index === 0 ? '' : 'aria-hidden="true"'; ?>>
                    <?php if ($slide['image'] !== ''): ?>
                        <img class="promo-img" src="<?php echo e($slide['image']); ?>" alt="" loading="lazy" decoding="async">
                    <?php endif; ?>
                    <div class="promo-body">
                        <?php if ($slide['tag'] !== ''): ?>
                            <p class="eyebrow"><?php echo e($slide['tag']); ?></p>
                        <?php endif; ?>
                        <h2><?php echo e($slide['title']); ?></h2>
                        <?php if ($slide['text'] !== ''): ?>
                            <p><?php echo e($slide['text']); ?></p>
                        <?php endif; ?>
                        <?php if ($slide['button'] !== '' && $slide['link'] !== ''): ?>
                            <a class="btn btn-primary" href="<?php echo e($slide['link']); ?>"><?php echo e($slide['button']); ?></a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1): ?>
            <button type="button" class="promo-arrow promo-prev" data-slider-prev aria-label="Önceki slayt">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg>
            </button>
            <button type="button" class="promo-arrow promo-next" data-slider-next aria-label="Sonraki slayt">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg>
            </button>
            <div class="promo-dots" role="tablist" aria-label="Slayt seçimi">
                <?php foreach ($slides as $index => $slide): ?>
                    <button type="button" class="promo-dot<?php echo $index === 0 ? ' is-active' : ''; ?>"
                            data-slider-dot="<?php echo $index; ?>"
                            role="tab" aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                            aria-label="<?php echo $index + 1; ?>. slayt"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
