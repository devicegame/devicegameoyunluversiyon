<?php
/**
 * Eski mağaza sayfaları için ortak D-Coin satın alma katmanı.
 *
 * shop.php ve product.php bu dosyayı yükleyip eski katalog ürünlerini
 * D-Coin mağazasındaki karşılıklarıyla eşleştirir. Böylece ana sayfadaki
 * satın alma, stok ve bakiye davranışı tüm mağaza sayfalarında aynı olur.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/shop.php';

if (!function_exists('dg_storefront_context')) {
    /**
     * Eski katalog ürünlerini D-Coin ürünleriyle slug üzerinden eşleştirir.
     */
    function dg_storefront_context(array $legacyProducts, string $loginNext = 'shop.php'): array
    {
        $whatsapp = preg_replace('/\D+/', '', (string)($GLOBALS['whatsapp'] ?? '')) ?: '';
        $context = [
            'user' => null,
            'is_logged' => false,
            'balance' => 0,
            'items_by_slug' => [],
            'storage_error' => null,
            'config' => [
                'csrf' => '',
                'isLogged' => false,
                'balance' => 0,
                'dailyLimit' => DG_DAILY_LIMIT_DEFAULT,
                'todayCount' => 0,
                'remaining' => 0,
                'ads' => [],
                'activeAdId' => null,
                'loginNext' => $loginNext,
                'whatsapp' => $whatsapp,
            ],
        ];

        try {
            // Kullanıcı doğrudan mağazaya/ürüne gelmişse eski katalog yine
            // D-Coin mağazasına eşitlenir; ana sayfaya gitmek gerekmez.
            if ($legacyProducts) {
                dg_sync_legacy_products($legacyProducts);
            }

            $data = dg_read_data(true);
            $user = current_user();
            $isLogged = $user !== null;
            $dailyLimit = max(1, (int)($data['settings']['daily_watch_limit'] ?? DG_DAILY_LIMIT_DEFAULT));
            $todayCount = $isLogged ? today_watch_count((int)$user['id'], $data) : 0;

            $activeItems = array_values(array_filter(
                $data['store_items'] ?? [],
                static fn(array $item): bool => (int)($item['is_active'] ?? 0) === 1
            ));
            $itemsBySlug = [];
            foreach (dg_enrich_store_items($data, $activeItems) as $item) {
                $slug = (string)($item['legacy_slug'] ?? '');
                if ($slug !== '') {
                    $itemsBySlug[$slug] = $item;
                }
            }

            $context['user'] = $user;
            $context['is_logged'] = $isLogged;
            $context['balance'] = $isLogged ? (int)$user['dcoin_balance'] : 0;
            $context['items_by_slug'] = $itemsBySlug;
            $context['config'] = array_merge($context['config'], [
                'isLogged' => $isLogged,
                'balance' => $context['balance'],
                'dailyLimit' => $dailyLimit,
                'todayCount' => $todayCount,
                'remaining' => max(0, $dailyLimit - $todayCount),
            ]);
        } catch (Throwable $exception) {
            $context['storage_error'] = $exception->getMessage();
            error_log('[dcoin storefront] ' . $exception->getMessage());
        }

        if (function_exists('csrf_token')) {
            $context['config']['csrf'] = csrf_token();
        }
        if (($context['config']['whatsapp'] ?? '') === '') {
            $context['config']['whatsapp'] = preg_replace('/\D+/', '', (string)($GLOBALS['whatsapp'] ?? '')) ?: '';
        }

        return $context;
    }

    function dg_storefront_item(array $context, array $product): ?array
    {
        $slug = (string)($product['slug'] ?? '');
        $item = $context['items_by_slug'][$slug] ?? null;
        return is_array($item) ? $item : null;
    }

    function dg_storefront_login_url(array $context): string
    {
        $next = (string)($context['config']['loginNext'] ?? 'shop.php');
        return 'login.php?next=' . rawurlencode($next !== '' ? $next : 'shop.php');
    }

    /**
     * Kart, vitrin, ürün detayı ve mobil çubuk için D-Coin satın alma alanı.
     */
    function dg_storefront_render_buy(?array $item, array $context, string $variant = 'card'): void
    {
        $variant = in_array($variant, ['card', 'spotlight', 'detail', 'mobile'], true) ? $variant : 'card';
        $isLogged = !empty($context['is_logged']);
        $balance = (int)($context['balance'] ?? 0);
        $price = $item ? (int)($item['price_dcoin'] ?? 0) : 0;
        $stock = $item ? (int)($item['keys_left'] ?? $item['stock'] ?? 0) : 0;
        $available = $item && $price > 0;

        if (!$available) {
            if ($variant === 'mobile') {
                return;
            }
            if ($variant === 'detail') {
                echo '<section class="dcoin-detail-panel is-unavailable" aria-label="D-Coin ile ödeme">';
                echo '<div class="dcoin-detail-copy"><p class="eyebrow">D-Coin ile ödeme</p>';
                echo '<strong class="dcoin-detail-title">Bu ürün şu an D-Coin ile alınamıyor</strong>';
                echo '<p>Ürünü TL ile sepete ekleyip WhatsApp üzerinden sipariş verebilirsiniz.</p></div>';
                echo '</section>';
                return;
            }
            echo '<div class="dcoin-buy-row' . ($variant === 'spotlight' ? ' is-spotlight' : '') . '">';
            echo '<span class="dcoin-unavailable">D-Coin ile alım bu üründe kapalı</span></div>';
            return;
        }

        $itemId = (int)$item['id'];
        $inStock = $stock > 0;
        $canAfford = $isLogged && $balance >= $price;
        $loginUrl = dg_storefront_login_url($context);

        if ($variant === 'mobile') {
            echo '<span class="dcoin-mobile-action" data-store-card data-item-id="' . $itemId . '" data-stock="' . $stock . '">';
            if (!$isLogged) {
                echo '<a class="btn btn-ghost" href="' . e($loginUrl) . '">D-Coin</a>';
            } elseif (!$inStock) {
                echo '<button type="button" class="btn btn-ghost dcoin-buy" data-purchase="' . $itemId . '" data-method="dcoin" data-price="' . $price . '" disabled>Tükendi</button>';
            } elseif (!$canAfford) {
                echo '<button type="button" class="btn btn-ghost dcoin-buy" data-purchase="' . $itemId . '" data-method="dcoin" data-price="' . $price . '" data-buy-label="D-Coin" disabled title="Bakiye yetersiz">Yetersiz</button>';
            } else {
                echo '<button type="button" class="btn btn-ghost dcoin-buy" data-purchase="' . $itemId . '" data-method="dcoin" data-price="' . $price . '" data-buy-label="D-Coin">D-Coin</button>';
            }
            echo '</span>';
            return;
        }

        $buyLabel = $variant === 'detail' ? 'D-Coin ile satın al' : 'D-Coin ile al';
        $action = '';
        if (!$isLogged) {
            $action = '<a href="' . e($loginUrl) . '" class="btn btn-primary dcoin-buy">Giriş yap ve al</a>';
        } elseif (!$inStock) {
            $action = '<button type="button" class="btn btn-primary dcoin-buy" data-purchase="' . $itemId . '" data-method="dcoin" data-price="' . $price . '" disabled>Stokta yok</button>';
        } elseif (!$canAfford) {
            $action = '<button type="button" class="btn btn-primary dcoin-buy" data-purchase="' . $itemId . '" data-method="dcoin" data-price="' . $price . '" data-buy-label="' . e($buyLabel) . '" disabled title="Bakiye yetersiz">Yetersiz bakiye</button>';
        } else {
            $action = '<button type="button" class="btn btn-primary dcoin-buy" data-purchase="' . $itemId . '" data-method="dcoin" data-price="' . $price . '" data-buy-label="' . e($buyLabel) . '">' . e($buyLabel) . '</button>';
        }

        $priceHtml = '<div class="dcoin-price-wrap">'
            . '<span class="dcoin-price">' . number_format($price, 0, ',', '.') . ' D-Coin</span>'
            . '<span class="dcoin-stock' . ($stock <= 0 ? ' is-empty' : ($stock <= 2 ? ' is-low' : '')) . '" data-stock-label>'
            . ($inStock ? $stock . ' stok' : 'Tükendi')
            . '</span></div>';

        if ($variant === 'detail') {
            if (!$isLogged) {
                $message = 'D-Coin ile almak için giriş yapın. Satın alma sonrası sipariş kodunuzu WhatsApp’tan bize gönderebilirsiniz.';
            } elseif (!$inStock) {
                $message = 'Bu ürünün D-Coin stoğu şu an tükendi. Bakiyeniz: ' . number_format($balance, 0, ',', '.') . ' D-Coin.';
            } elseif (!$canAfford) {
                $message = 'Bu ürün için ' . number_format($price, 0, ',', '.') . ' D-Coin gerekli; bakiyeniz '
                    . number_format($balance, 0, ',', '.') . ' D-Coin.';
            } else {
                $message = 'Bakiyeniz: ' . number_format($balance, 0, ',', '.') . ' D-Coin. Satın alma tek adet için yapılır; adet seçimi sepet içindir.';
            }

            echo '<section class="dcoin-detail-panel" data-store-card data-item-id="' . $itemId . '" data-stock="' . $stock . '" aria-label="D-Coin ile ödeme">';
            echo '<div class="dcoin-detail-copy"><p class="eyebrow">D-Coin ile ödeme</p>';
            echo '<strong class="dcoin-detail-title">Bakiyenle hemen al</strong>';
            echo '<p>' . e($message) . ' Satın alınca sipariş kodunuz ekranda çıkar ve tek tıkla WhatsApp’tan paylaşabilirsiniz.</p></div>';
            echo '<div class="dcoin-detail-action">' . $priceHtml . '<div class="dcoin-actions">' . $action . '</div></div>';
            echo '</section>';
            return;
        }

        echo '<div class="dcoin-buy-row' . ($variant === 'spotlight' ? ' is-spotlight' : '') . '" '
            . 'data-store-card data-item-id="' . $itemId . '" data-stock="' . $stock . '">'
            . $priceHtml
            . '<div class="dcoin-actions">' . $action . '</div>'
            . '</div>';
    }

    /**
     * Satın alma penceresi, yapılandırma ve istemci kodu.
     */
    function dg_storefront_render_assets(array $context): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        include __DIR__ . '/dcoin-purchase-modal.php';

        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        if (($config['whatsapp'] ?? '') === '') {
            $config['whatsapp'] = preg_replace('/\D+/', '', (string)($GLOBALS['whatsapp'] ?? '')) ?: '';
        }

        echo '<div class="dcoin-toast" id="dcoinToast" role="status" aria-live="polite"></div>';
        echo '<script id="dg-config" type="application/json">'
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
            . '</script>';
        echo '<script src="assets/js/dcoin.js" defer></script>';
    }
}
