<?php
// Ana sayfa — eski Device Game tasarımı + veritabansız D-Coin sistemi.
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/lib/social.php';

$user = null;
$is_logged = false;
$daily_limit = DG_DAILY_LIMIT_DEFAULT;
$today_count = 0;
$remaining = 0;
$ads = [];
$active_ad = null;
$store_items = [];
$store_cards = [];
$my_purchases = [];
$legacy_products = [];
$legacy_categories = function_exists('shop_categories') ? shop_categories() : [];
$storage_error = null;

$excerpt = static function (string $text, int $limit = 135): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, $limit)) . '…' : $text;
};

// Eski sitenin gerçek kataloğu: storage/products.json (yoksa data/products.php).
try {
    $legacy_products = shop_catalog();
} catch (Throwable $e) {
    $storage_error = $e->getMessage();
    error_log('[index legacy catalog] ' . $e->getMessage());
}

// Eski ürünleri D-Coin mağazasına bağla. Katalog değişmedikçe yazma yapılmaz.
try {
    if ($legacy_products) {
        dg_sync_legacy_products($legacy_products);
    }
} catch (Throwable $e) {
    $storage_error = $storage_error ?: $e->getMessage();
    error_log('[index legacy sync] ' . $e->getMessage());
}

try {
    $data = dg_read_data(true);
    $user = current_user();
    $is_logged = $user !== null;
    $daily_limit = max(1, (int)($data['settings']['daily_watch_limit'] ?? DG_DAILY_LIMIT_DEFAULT));
    if ($is_logged) $today_count = today_watch_count((int)$user['id'], $data);
    $remaining = max(0, $daily_limit - $today_count);

    $ads = array_values(array_filter(
        dg_sort_newest($data['ads'] ?? []),
        fn(array $a): bool => (int)($a['is_active'] ?? 0) === 1
    ));
    $active_ad = $ads[0] ?? null;

    $active_items = array_values(array_filter(
        $data['store_items'] ?? [],
        fn(array $i): bool => (int)($i['is_active'] ?? 0) === 1
    ));
    $store_items = dg_enrich_store_items($data, $active_items);

    $dg_by_slug = [];
    foreach ($store_items as $item) {
        $legacy_slug = (string)($item['legacy_slug'] ?? '');
        if ($legacy_slug !== '') $dg_by_slug[$legacy_slug] = $item;
    }

    // Vitrin eski katalog sırasını korur; D-Coin'e özel ürünler sona eklenir.
    foreach ($legacy_products as $legacy_product) {
        $store_cards[] = [
            'legacy' => $legacy_product,
            'dg' => $dg_by_slug[(string)$legacy_product['slug']] ?? null,
        ];
    }
    foreach ($store_items as $item) {
        if (empty($item['legacy_slug'])) {
            $store_cards[] = ['legacy' => null, 'dg' => $item];
        }
    }

    if ($is_logged) {
        $items_by_id = dg_index_by_id($data['store_items'] ?? []);
        $mine = array_values(array_filter(
            $data['purchases'] ?? [],
            fn(array $p): bool => (int)($p['user_id'] ?? 0) === (int)$user['id']
        ));
        foreach (array_slice(dg_sort_newest($mine), 0, 3) as $purchase) {
            $purchase['item_name'] = $items_by_id[(int)$purchase['store_item_id']]['name']
                ?? ($purchase['item_name'] ?? 'Ürün');
            $my_purchases[] = $purchase;
        }
    }
} catch (Throwable $e) {
    $storage_error = $storage_error ?: $e->getMessage();
    error_log('[index storage] ' . $e->getMessage());
}

// D-Coin verisi okunamazsa bile eski mağaza ürünleri vitrinde kalsın.
if (!$store_cards && $legacy_products) {
    foreach ($legacy_products as $legacy_product) {
        $store_cards[] = ['legacy' => $legacy_product, 'dg' => null];
    }
}

$ads_for_js = [];
foreach ($ads as $ad) {
    $ads_for_js[] = [
        'id' => (int)$ad['id'],
        'title' => (string)($ad['title'] ?? ''),
        'video_url' => (string)($ad['video_url'] ?? ''),
        'thumbnail_url' => (string)($ad['thumbnail_url'] ?? ''),
        'reward_dcoin' => (int)($ad['reward_dcoin'] ?? 0),
        'duration_sec' => (int)($ad['duration_sec'] ?? 0),
    ];
}

$dg_config = [
    'csrf' => csrf_token(),
    'isLogged' => $is_logged,
    'balance' => $is_logged ? (int)$user['dcoin_balance'] : 0,
    'dailyLimit' => $daily_limit,
    'todayCount' => $today_count,
    'remaining' => $remaining,
    'ads' => $ads_for_js,
    'activeAdId' => $active_ad ? (int)$active_ad['id'] : null,
    'loginNext' => 'index.php',
];

$social_latest = [];
$social_events_home = [];
$social_topics_home = [];
try {
    $social_latest = social_list_posts(['sort' => 'activity', 'per_page' => 3], $user)['items'] ?? [];
    $social_events_home = social_upcoming_events($user, 3);
    $social_topics_home = array_column(social_topics(), 'name', 'slug');
} catch (Throwable $e) {
    error_log('[index social latest] ' . $e->getMessage());
}

$flash = flash_get();
$flash_err = flash_error_get();

// SEO ve üst bilgi eski ana sayfadaki gibi config.php + header.php'den gelir.
$page_title = '';
$page_canonical = '';
$page_og_type = 'website';
$page_og_title = 'Device Game | Oyun dünyasının anahtarı.';
$page_css = ['shop.css', 'social.css', 'assets/css/dcoin.css'];
$body_class = 'shop-page home-page dcoin-page';
include __DIR__ . '/header.php';
?>

<?php if ($flash || $flash_err): ?>
<div class="dcoin-flash-stack" aria-live="polite">
    <?php if ($flash): ?>
        <div class="dcoin-flash" role="status" data-dg-flash><?php echo e($flash['msg'] ?? ''); ?></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div class="dcoin-flash is-error" role="alert" data-dg-flash><?php echo e($flash_err); ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Son kelimeyi vurgularken sloganı config.php'den yönetilebilir tut.
$hero_slogan_words = preg_split('/\s+/u', trim($slogan)) ?: [];
$hero_slogan_highlight = count($hero_slogan_words) > 1 ? array_pop($hero_slogan_words) : '';
?>
<section class="hero" aria-labelledby="hero-title">
    <div class="hero-inner">
        <div class="hero-body">
            <p class="hero-kicker seq" style="--d:.02s"><span aria-hidden="true">✦</span> OYUNCULAR İÇİN, OYUNCULARLA</p>
            <h1 id="hero-title" class="hero-title seq" style="--d:.05s"><?php echo e(implode(' ', $hero_slogan_words)); ?><?php if ($hero_slogan_highlight !== ''): ?> <span><?php echo e($hero_slogan_highlight); ?></span><?php endif; ?></h1>
            <p class="hero-desc seq" style="--d:.2s">Oyunlar yapıyor, oyuncular için seçip getiriyoruz. Stüdyomuzda yeni evrenler kuruyor, mağazamızda dijital kütüphaneni büyütüyoruz.</p>
            <div class="hero-actions seq" style="--d:.35s">
                <a href="<?php echo e($shop_url); ?>" class="btn btn-primary">Mağazayı keşfet <span aria-hidden="true">↗</span></a>
                <a href="<?php echo e($studio_url); ?>" class="btn btn-ghost" target="_blank" rel="noopener">Stüdyoyu keşfet <span aria-hidden="true">↗</span></a>
            </div>
            <a href="#nexus" class="hero-project seq" style="--d:.45s"><span class="pulse" aria-hidden="true"></span><span><?php echo e($featured_project_name); ?> <span class="hero-project-separator">·</span> <?php echo e($featured_project_status); ?></span><span aria-hidden="true">↗</span></a>
        </div>

        <div class="hero-visual seq" style="--d:.2s">
            <a href="<?php echo e($shop_url); ?>" class="hero-art" aria-label="Mağazadaki oyunları keşfet">
                <img src="assets/img/gradient-game-hero.webp" width="1584" height="672" alt="" fetchpriority="high">
                <span class="hero-art-tag"><span aria-hidden="true">✦</span> DEVICE GAME DÜNYASI</span>
                <span class="hero-art-caption"><span><small>YENİ MACERALAR KEŞFET</small><strong>Bir sonraki oyun<br>burada başlıyor.</strong></span><span class="hero-art-arrow" aria-hidden="true">↗</span></span>
            </a>
            <span class="hero-visual-spark hero-visual-spark-one" aria-hidden="true">✳</span>
            <span class="hero-visual-spark hero-visual-spark-two" aria-hidden="true">✦</span>
        </div>
    </div>

    <nav class="hero-shortcuts seq" style="--d:.55s" aria-label="Device Game dünyasını keşfet">
        <a class="hero-shortcut" href="<?php echo e($shop_url); ?>">
            <span class="shortcut-icon shortcut-icon-purple" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 8h11c1.7 0 2.8 1.2 3.2 2.8l.8 3.8c.5 2.4-1.8 3.8-3.5 2.3l-1.8-1.5H7.8L6 16.9c-1.7 1.5-4 .1-3.5-2.3l.8-3.8C3.7 9.2 4.8 8 6.5 8Z"/><path d="M7.5 10.5v3M6 12h3M16.5 11.5h.01M18 13h.01"/></svg></span>
            <span><strong>Oyunlar</strong><small>Yeni dünyalar keşfet</small></span><span class="shortcut-arrow" aria-hidden="true">↗</span>
        </a>
        <a class="hero-shortcut" href="#dcoin">
            <span class="shortcut-icon shortcut-icon-yellow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="m12 7 3 5-3 5-3-5 3-5Z"/></svg></span>
            <span><strong>D-Coin</strong><small>İzle, kazan, harca</small></span><span class="shortcut-arrow" aria-hidden="true">↗</span>
        </a>
        <a class="hero-shortcut" href="sosyal/index.php">
            <span class="shortcut-icon shortcut-icon-green" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20v-1.5a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4V20M15 4.5a3.5 3.5 0 0 1 0 6.8M20 20v-1.5a4 4 0 0 0-2.5-3.7"/><circle cx="10" cy="7.5" r="3.5"/></svg></span>
            <span><strong>Topluluk</strong><small>Birlikte daha güzel</small></span><span class="shortcut-arrow" aria-hidden="true">↗</span>
        </a>
    </nav>
</section>

<?php include __DIR__ . '/partials/slider.php'; ?>

<section class="ecosystem" aria-label="Stüdyo ve mağaza">
    <article class="pane pane-studio">
        <div class="pane-content">
            <span class="pane-label">01 / HAYAL ET</span>
            <h2>Stüdyo<span class="pane-dot">.</span></h2>
            <p>Sınırları zorlayan hikayeler ve yeni nesil grafiklerle örülmüş evrenler inşa ediyoruz.</p>
            <a href="<?php echo e($studio_url); ?>" class="pane-link" target="_blank" rel="noopener">Oyunları keşfet <span aria-hidden="true">↗</span></a>
        </div>
    </article>
    <article class="pane pane-shop">
        <div class="pane-content">
            <span class="pane-label">02 / KEŞFET</span>
            <h2>Mağaza<span class="pane-dot">.</span></h2>
            <p>Oyunlar, premium hesaplar ve oyunculara özel ürünler. Dijital kütüphaneni genişlet.</p>
            <a href="<?php echo e($shop_url); ?>" class="pane-link">Mağazaya gir <span aria-hidden="true">↗</span></a>
        </div>
    </article>
</section>

<?php if ($storage_error): ?>
<div class="dcoin-alert" role="alert">
    D-Coin depolaması şu an okunamıyor; eski mağaza kataloğu gösterilmeye devam ediyor.
    <a href="diagnose.php">diagnose.php ile kontrol edin</a>.
</div>
<?php endif; ?>

<section class="shop-section" id="magaza" aria-labelledby="store-title">
    <div class="shop-section-head">
        <div>
            <p class="eyebrow">Mağaza</p>
            <h2 id="store-title">Rafı karıştır.</h2>
        </div>
        <p>Eski mağazamızdaki ürünler yine aynı rafta. TL ile sepete ekleyebilir ya da bakiyen yetiyorsa D-Coin ile alabilirsin.</p>
    </div>

    <div class="store-tools">
        <label class="search-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
            </svg>
            <input id="storeSearch" type="search" placeholder="Ürün, platform ya da tür ara" aria-label="Ürün ara">
        </label>
        <p class="store-count"><strong data-visible-count><?php echo count($store_cards); ?></strong> ürün listeleniyor</p>
    </div>

    <div class="p-grid" id="storeGrid">
        <?php if (!$store_cards): ?>
            <div class="empty-state">
                <h3>Rafta ürün görünmüyor.</h3>
                <p>Mağaza kataloğu yüklenemedi. WhatsApp üzerinden bize yazın, yardımcı olalım.</p>
                <a class="btn btn-primary" href="<?php echo e(wa_link($whatsapp, 'Merhaba, mağaza ürünleri hakkında bilgi almak istiyorum.')); ?>" target="_blank" rel="noopener">WhatsApp'tan sor</a>
            </div>
        <?php else: ?>
            <?php foreach ($store_cards as $index => $card): ?>
                <?php
                $legacy = $card['legacy'];
                $dg_item = $card['dg'];
                if ($legacy) {
                    $product = $legacy;
                    $product_name = (string)$legacy['name'];
                    $category_label = $legacy_categories[$legacy['category']] ?? '';
                    $product_sub = $excerpt((string)($legacy['subtitle'] !== '' ? $legacy['subtitle'] : $legacy['desc']));
                    $detail_url = 'product.php?slug=' . urlencode((string)$legacy['slug']);
                    $in_cart = (int)(cart_raw()[$legacy['slug']] ?? 0);
                } else {
                    $product_name = (string)($dg_item['name'] ?? 'Dijital ürün');
                    $initials = '';
                    foreach (preg_split('/\s+/', trim($product_name)) ?: [] as $word) {
                        if ($word === '') continue;
                        $initials .= function_exists('mb_substr') ? mb_substr($word, 0, 1, 'UTF-8') : substr($word, 0, 1);
                        if (strlen($initials) >= 3) break;
                    }
                    $product = [
                        'slug' => 'dcoin-' . (int)($dg_item['id'] ?? $index),
                        'pattern' => 'grid',
                        'c1' => '#0F3B2C',
                        'c2' => '#2FA377',
                        'accent' => '#D6FFE9',
                        'image' => (string)($dg_item['image_url'] ?? ''),
                        'badge' => null,
                        'platform' => 'Dijital',
                        'glyph' => $initials !== '' ? strtoupper($initials) : 'DG',
                    ];
                    $category_label = 'Dijital Ürünler';
                    $product_sub = $excerpt((string)($dg_item['description'] ?? ''));
                    $detail_url = '#magaza';
                    $in_cart = 0;
                }

                $badge_class = 'p-badge';
                if (($product['badge'] ?? null) === 'Stüdyo imzası') $badge_class .= ' signature';
                elseif (($product['badge'] ?? null) === 'Sınırlı') $badge_class .= ' limited';

                $dg_price = $dg_item ? (int)($dg_item['price_dcoin'] ?? 0) : 0;
                $dg_stock = $dg_item ? (int)($dg_item['keys_left'] ?? $dg_item['stock'] ?? 0) : 0;
                $dg_in_stock = $dg_item && $dg_stock > 0;
                $can_coin = $is_logged && $dg_in_stock && $dg_price > 0 && (int)$user['dcoin_balance'] >= $dg_price;
                $search_blob = $product_name . ' ' . $product_sub . ' ' . $category_label . ' ' . (string)($product['platform'] ?? '');
                if ($legacy) $search_blob .= ' ' . (string)($legacy['desc'] ?? '');
                $index_label = str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
                ?>
                <article class="p-card dcoin-card"
                         data-store-card
                         data-item-id="<?php echo $dg_item ? (int)$dg_item['id'] : 0; ?>"
                         data-stock="<?php echo $dg_stock; ?>"
                         data-name="<?php echo e(function_exists('mb_strtolower') ? mb_strtolower($search_blob, 'UTF-8') : strtolower($search_blob)); ?>">
                    <?php if ($legacy): ?>
                        <a class="p-card-media" href="<?php echo e($detail_url); ?>" aria-label="<?php echo e($product_name); ?> detayları">
                    <?php else: ?>
                        <div class="p-card-media">
                    <?php endif; ?>
                        <div class="poster" data-pattern="<?php echo e($product['pattern']); ?>"
                             style="--c1:<?php echo e($product['c1']); ?>;--c2:<?php echo e($product['c2']); ?>;--accent:<?php echo e($product['accent']); ?>">
                            <?php echo poster_svg($product); ?>
                            <?php if (!empty($product['image']) && (str_starts_with((string)$product['image'], 'http') || is_file(__DIR__ . '/' . $product['image']))): ?>
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
                    <?php if ($legacy): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>

                    <div class="p-card-body">
                        <p class="p-kicker"><?php echo e($category_label); ?></p>
                        <h3 class="p-name">
                            <?php if ($legacy): ?>
                                <a href="<?php echo e($detail_url); ?>"><?php echo e($product_name); ?></a>
                            <?php else: ?>
                                <?php echo e($product_name); ?>
                            <?php endif; ?>
                        </h3>
                        <p class="p-sub"><?php echo e($product_sub); ?></p>

                        <div class="p-card-foot">
                            <div class="price">
                                <b><?php echo e(money($legacy ? (float)$legacy['price'] : (float)($dg_item['price_tl'] ?? 0))); ?></b>
                                <?php if ($legacy && !empty($legacy['old_price'])): ?>
                                    <s><?php echo e(money((float)$legacy['old_price'])); ?></s>
                                <?php endif; ?>
                            </div>
                            <?php if ($legacy): ?>
                                <form method="post" action="cart.php" class="add-form">
                                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="slug" value="<?php echo e($legacy['slug']); ?>">
                                    <input type="hidden" name="qty" value="1">
                                    <input type="hidden" name="redirect" value="<?php echo e($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">
                                    <button type="submit" class="add-btn<?php echo $in_cart > 0 ? ' is-done' : ''; ?>"
                                            aria-label="<?php echo e($product_name); ?> sepete ekle" title="Sepete ekle">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M12 5v14M5 12h14"/>
                                        </svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="dcoin-buy-row">
                            <?php if ($dg_item): ?>
                                <div class="dcoin-price-wrap">
                                    <span class="dcoin-price"><?php echo number_format($dg_price, 0, ',', '.'); ?> D-Coin</span>
                                    <span class="dcoin-stock<?php echo $dg_stock <= 0 ? ' is-empty' : ($dg_stock <= 2 ? ' is-low' : ''); ?>" data-stock-label>
                                        <?php echo $dg_in_stock ? $dg_stock . ' stok' : 'Tükendi'; ?>
                                    </span>
                                </div>
                                <div class="dcoin-actions">
                                    <?php if (!$is_logged): ?>
                                        <a href="login.php?next=index.php" class="btn btn-primary dcoin-buy">Giriş yap ve al</a>
                                    <?php elseif (!$dg_in_stock): ?>
                                        <button type="button" class="btn btn-primary dcoin-buy" disabled>Stokta yok</button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-primary dcoin-buy"
                                                data-purchase="<?php echo (int)$dg_item['id']; ?>"
                                                data-method="dcoin"
                                                data-price="<?php echo $dg_price; ?>"
                                                <?php echo $can_coin ? '' : 'disabled title="Bakiye yetersiz"'; ?>>
                                            <?php echo $can_coin ? 'D-Coin ile al' : 'Yetersiz bakiye'; ?>
                                        </button>
                                        <?php if (!$legacy): ?>
                                            <button type="button" class="btn btn-ghost dcoin-cash"
                                                    data-purchase="<?php echo (int)$dg_item['id']; ?>"
                                                    data-method="cash">Nakit al</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="dcoin-unavailable">D-Coin ile alım bu üründe kapalı</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="dcoin-section" id="dcoin" aria-labelledby="dcoin-title">
    <div class="shop-section">
        <div class="shop-section-head">
            <div>
                <p class="eyebrow">D-Coin</p>
                <h2 id="dcoin-title">İzle, kazan, harca.</h2>
            </div>
            <p>Seçtiğin videoyu sonuna kadar izle; ödül bakiyene eklensin. Kazandığın D-Coin'i yukarıdaki mağaza ürünlerinde kullanabilirsin.</p>
        </div>

        <div class="dcoin-layout">
            <div class="dcoin-panel">
                <?php if ($ads): ?>
                    <div class="dcoin-player" id="playerWrap">
                        <div class="dcoin-seek-guard" id="seekGuard">İleri sarma kapalı</div>
                        <video id="dgVideo" playsinline preload="metadata" controlsList="nodownload noremoteplayback" disablePictureInPicture></video>
                        <div class="dcoin-play-overlay" id="playOverlay">
                            <button type="button" class="dcoin-play-btn" id="bigPlay" aria-label="Videoyu oynat">
                                <svg width="27" height="27" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg>
                            </button>
                        </div>
                        <div class="dcoin-player-bar">
                            <div class="dcoin-progress" aria-hidden="true"><div class="dcoin-progress-fill" id="progressFill"></div></div>
                            <div class="dcoin-time" id="timeLabel">00:00 / 00:00</div>
                            <div class="dcoin-reward-chip" id="rewardChip">+0 D-Coin</div>
                        </div>
                        <div class="dcoin-controls">
                            <button type="button" class="btn btn-ghost dcoin-control-btn" id="pauseBtn">Oynat</button>
                            <button type="button" class="btn btn-ghost dcoin-control-btn" id="muteBtn">Ses açık</button>
                            <span class="dcoin-status" id="statusText">Hazır</span>
                            <span hidden id="playerStatus">Hazır</span>
                        </div>
                    </div>
                    <div class="dcoin-watch-result">
                        <div id="watchResult" hidden></div>
                    </div>
                    <div class="dcoin-player-notes" aria-label="İzleme durumu">
                        <span class="dcoin-chip" id="limitChip"><strong><?php echo $today_count; ?> / <?php echo $daily_limit; ?></strong> izlendi</span>
                        <span class="dcoin-chip" id="tabChip">Sekme: <strong>Aktif</strong></span>
                        <span class="dcoin-chip">İleri sarma: <strong>Kapalı</strong></span>
                        <span class="dcoin-chip">Süre kontrolü: <strong>Sunucuda</strong></span>
                    </div>
                <?php else: ?>
                    <div class="dcoin-empty">
                        <h3>Şu an izlenecek video yok.</h3>
                        <p>Yeni videolar eklendiğinde D-Coin kazanmaya buradan devam edebilirsin.</p>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="dcoin-side">
                <div class="dcoin-panel dcoin-account">
                    <?php if ($is_logged): ?>
                        <div class="dcoin-account-top">
                            <div>
                                <p class="dcoin-account-label">Hesabım</p>
                                <h3><?php echo e($user['username']); ?></h3>
                            </div>
                            <div class="dcoin-balance-big">
                                <span data-live-balance><?php echo number_format((int)$user['dcoin_balance'], 0, ',', '.'); ?></span>
                                <small>D-Coin bakiyen</small>
                            </div>
                        </div>
                        <p class="dcoin-account-text">
                            <?php if ($remaining > 0): ?>
                                Bugün <strong data-remaining-count><?php echo $remaining; ?></strong> video daha izleyebilirsin.
                            <?php else: ?>
                                Günlük izleme limitin doldu. Yarın yeniden kazanabilirsin.
                            <?php endif; ?>
                        </p>
                        <div class="dcoin-daily-head">
                            <span>Günlük ilerleme</span>
                            <strong><span data-today-count><?php echo $today_count; ?></span> / <?php echo $daily_limit; ?></strong>
                        </div>
                        <div class="dcoin-daily-bar" aria-hidden="true">
                            <div class="dcoin-daily-fill" data-daily-fill style="width:<?php echo $daily_limit ? min(100, round($today_count / $daily_limit * 100)) : 0; ?>%"></div>
                        </div>
                        <?php if ($my_purchases): ?>
                            <div class="dcoin-recent">
                                <h4>Son alışverişlerin</h4>
                                <?php foreach ($my_purchases as $purchase): ?>
                                    <div class="dcoin-recent-item">
                                        <span><?php echo e($purchase['item_name']); ?></span>
                                        <span><?php echo ($purchase['payment_method'] ?? '') === 'dcoin'
                                            ? e(((int)($purchase['price_paid_dcoin'] ?? 0)) . ' D-Coin')
                                            : e(tl_format((float)($purchase['price_paid_tl'] ?? 0))); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="dcoin-account-label">D-Coin hesabı</p>
                        <h3>Bakiyen seni bekliyor.</h3>
                        <p class="dcoin-account-text">Günde <?php echo $daily_limit; ?> videoya kadar izleyip D-Coin kazanın. Ödüller yalnızca videoyu sonuna kadar izleyen hesaplara verilir.</p>
                        <div style="display:flex;gap:.65rem;flex-wrap:wrap">
                            <a href="login.php?next=index.php%23dcoin" class="btn btn-primary">Giriş yap</a>
                            <a href="register.php?next=index.php%23dcoin" class="btn btn-ghost">Kayıt ol</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="dcoin-panel dcoin-ads">
                    <div class="dcoin-ads-head">
                        <h3>Videolar</h3>
                        <span><?php echo count($ads); ?> aktif</span>
                    </div>
                    <?php if ($ads): ?>
                        <div class="dcoin-ads-list" id="adsList">
                            <?php foreach ($ads as $ad): ?>
                                <button type="button" class="dcoin-ad<?php echo (int)$ad['id'] === (int)($active_ad['id'] ?? 0) ? ' is-active' : ''; ?>" data-ad-id="<?php echo (int)$ad['id']; ?>">
                                    <span class="dcoin-ad-thumb">
                                        <?php if (!empty($ad['thumbnail_url'])): ?>
                                            <img src="<?php echo e($ad['thumbnail_url']); ?>" alt="" loading="lazy">
                                        <?php else: ?>
                                            <video src="<?php echo e($ad['video_url']); ?>#t=0.5" muted preload="metadata" aria-hidden="true"></video>
                                        <?php endif; ?>
                                        <span><?php echo (int)$ad['duration_sec']; ?> sn</span>
                                    </span>
                                    <span class="dcoin-ad-meta">
                                        <span class="dcoin-ad-title"><?php echo e($ad['title']); ?></span>
                                        <p><?php echo (int)$ad['duration_sec']; ?> saniye · tamamlayana ödül</p>
                                    </span>
                                    <span class="dcoin-ad-reward">+<?php echo (int)$ad['reward_dcoin']; ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="dcoin-empty" style="padding:1.2rem">Henüz aktif video yok.</p>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</section>

<section class="social-home-section" id="topluluk" aria-labelledby="community-title">
    <div class="social-home-inner">
        <div class="social-home-head">
            <div>
                <p class="eyebrow">DG Social</p>
                <h2 id="community-title">Toplulukta neler oluyor?</h2>
            </div>
            <p>Herkes okuyabilir; gönderi ve yorumlar mevcut Device Game hesabınla. Oyun önerileri, rehberler, anketler, haftalık etkinlikler ve oyun arkadaşı ilanları tek akışta.</p>
            <a href="sosyal/index.php" class="btn btn-primary">Sosyal akışa git</a>
        </div>

        <?php if ($social_latest): ?>
            <div class="social-home-grid">
                <?php foreach ($social_latest as $social_post_home): ?>
                    <a class="social-home-card" href="sosyal/gonderi.php?id=<?php echo (int)$social_post_home['id']; ?>">
                        <small><?php echo e($social_topics_home[(string)$social_post_home['topic_slug']] ?? (string)$social_post_home['topic_slug']); ?> · @<?php echo e($social_post_home['author_username']); ?></small>
                        <h3><?php echo e($social_post_home['title']); ?></h3>
                        <p><?php echo e($social_post_home['excerpt']); ?></p>
                        <p>
                            <strong>GG <?php echo (int)$social_post_home['gg_count']; ?></strong> · <?php echo (int)$social_post_home['comment_count']; ?> yorum
                            <?php if (!empty($social_post_home['poll'])): ?> · 📊 <?php echo (int)($social_post_home['poll']['total_votes'] ?? 0); ?> oy<?php endif; ?>
                            <?php if (!empty($social_post_home['award'])): ?> · 🏆 +<?php echo number_format((int)$social_post_home['award']['amount'], 0, ',', '.'); ?> D-Coin<?php endif; ?>
                            <?php if (social_is_event_post($social_post_home)): ?> · 🗓 <?php echo (int)($social_post_home['rsvp_count'] ?? 0); ?> katılımcı<?php endif; ?>
                        </p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="social-home-grid">
                <a class="social-home-card" href="sosyal/index.php?konu=oyun-arkadasi"><small>Oyun Arkadaşı (LFG)</small><h3>Ekibini kur</h3><p>Oyun, platform, rank ve saat bilgisiyle sana uyan oyuncuları bul.</p></a>
                <a class="social-home-card" href="sosyal/index.php?konu=rehberler"><small>Rehberler &amp; Taktikler</small><h3>Bilgini paylaş</h3><p>Oyuncu rehberleri, ipuçları ve kurulum çözümleri toplulukta.</p></a>
                <a class="social-home-card" href="sosyal/etkinlikler.php"><small>Etkinlikler</small><h3>Oyun gecesi planla</h3><p>Tarih ve buluşma yerini belirle, topluluğun katılımını tek yerden takip et.</p></a>
            </div>
        <?php endif; ?>

        <?php if ($social_events_home): ?>
            <p class="eyebrow social-home-events-title">Yaklaşan etkinlikler</p>
            <div class="social-home-grid">
                <?php foreach ($social_events_home as $social_event_home): ?>
                    <a class="social-home-card" href="sosyal/gonderi.php?id=<?php echo (int)$social_event_home['id']; ?>#etkinlik">
                        <small>🗓 <?php echo e(social_format_date((int)($social_event_home['meta']['event_at'] ?? 0))); ?> · <?php echo e(social_time_until((int)($social_event_home['meta']['event_at'] ?? 0))); ?></small>
                        <h3><?php echo e($social_event_home['title']); ?></h3>
                        <p><?php echo e($social_event_home['excerpt']); ?></p>
                        <p><strong><?php echo (int)($social_event_home['rsvp_count'] ?? 0); ?> katılımcı</strong><?php echo !empty($social_event_home['meta']['event_place']) ? ' · ' . e($social_event_home['meta']['event_place']) : ''; ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="nexus" id="nexus" aria-labelledby="nexus-title">
    <div class="nexus-inner">
        <p class="status"><span class="pulse" aria-hidden="true"></span><?php echo e($featured_project_status); ?></p>
        <div class="nexus-main">
            <h2 id="nexus-title" class="nexus-title"><?php echo e($featured_project_name); ?></h2>
            <p class="nexus-desc"><?php echo e($featured_project_desc); ?></p>
            <a href="<?php echo e($studio_url); ?>" class="text-link" target="_blank" rel="noopener">Geliştirici günlüklerini oku</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/dcoin-purchase-modal.php'; ?>

<div class="dcoin-toast" id="dcoinToast" role="status" aria-live="polite"></div>

<?php $dg_config['whatsapp'] = preg_replace('/\D+/', '', (string)($whatsapp ?? '')); ?>
<script id="dg-config" type="application/json"><?php echo json_encode($dg_config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?></script>
<script src="assets/js/dcoin.js" defer></script>

<?php include __DIR__ . '/footer.php'; ?>
