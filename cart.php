<?php
require_once __DIR__ . '/config.php';

/**
 * Sepet işlemleri (POST → yönlendirme)
 * Basit tutuldu: her işlem form gönderimi, ardından PRG ile geri dönüş.
 */
function safe_redirect(string $target): string
{
    $target = trim($target);
    $allowed = ['shop.php', 'cart.php', 'index.php', 'product.php', 'checkout.php'];
    $path = parse_url($target, PHP_URL_PATH) ?: '';
    if (!in_array(basename($path), $allowed, true) || str_contains($target, '//')) {
        return 'cart.php';
    }
    return $target;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $action = (string)($_POST['action'] ?? '');
    $slug = (string)($_POST['slug'] ?? '');
    $qty = (int)($_POST['qty'] ?? 1);
    $redirect = safe_redirect((string)($_POST['redirect'] ?? 'cart.php'));
    $action_flash = '';

    if (!csrf_valid()) {
        $action_flash = 'Oturum doğrulaması başarısız oldu, tekrar deneyin.';
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'count' => cart_count(), 'flash' => $action_flash], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['flash'] = $action_flash;
        header('Location: ' . $redirect);
        exit;
    }

    switch ($action) {
        case 'add':
            $product = shop_product($slug);
            if ($product) {
                cart_add($slug, $qty);
                $action_flash = $product['name'] . ' sepete eklendi.';
            }
            break;

        case 'buy':
            $product = shop_product($slug);
            if ($product) {
                cart_add($slug, $qty);
                $_SESSION['flash'] = $product['name'] . ' sepete eklendi, siparişini tamamlayabilirsin.';
            }
            header('Location: cart.php');
            exit;

        case 'set':
            cart_set($slug, $qty);
            $action_flash = 'Sepet güncellendi.';
            break;

        case 'inc':
        case 'dec':
            $current = (int)(cart_raw()[$slug] ?? 0);
            cart_set($slug, $action === 'inc' ? $current + 1 : $current - 1);
            break;

        case 'remove':
            $product = shop_product($slug);
            cart_remove($slug);
            $action_flash = $product ? $product['name'] . ' sepetten çıkarıldı.' : 'Ürün sepetten çıkarıldı.';
            break;

        case 'clear':
            cart_clear();
            $action_flash = 'Sepet boşaltıldı.';
            break;
    }

    // AJAX isteklerinde sayfa yenilenmez; bilgi JSON olarak döner.
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'count' => cart_count(), 'flash' => $action_flash], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action_flash !== '') {
        $_SESSION['flash'] = $action_flash;
    }
    header('Location: ' . $redirect);
    exit;
}

$page_title = 'Sepetim';
$page_meta_desc = 'Device Game sepetini gözden geçir, sipariş bilgilerini doldur ve WhatsApp üzerinden siparişini tamamla.';
$page_canonical = false;
$page_robots = 'noindex,nofollow';
$page_css = ['shop.css'];
$body_class = 'shop-page' . (cart_count() > 0 ? ' has-bar' : '');
include 'header.php';

$items = cart_items();
$subtotal = cart_subtotal();
$count = cart_count();
$errors = $_SESSION['checkout_errors'] ?? [];
$old = $_SESSION['checkout_old'] ?? [];
unset($_SESSION['checkout_errors'], $_SESSION['checkout_old']);

$delivery_options = shop_delivery_options();
$payment_options = shop_payment_options();
$delivery_key = array_key_first($delivery_options);
$payment_key = array_key_first($payment_options);

$field = function (string $key, string $fallback = '') use ($old): string {
    return e((string)($old[$key] ?? $fallback));
};
?>

<section class="cart-page">
    <p class="eyebrow">Sipariş</p>
    <h1 class="cart-title">Sepetim<?php echo $count > 0 ? ' <span class="muted" style="font-size:.35em;letter-spacing:0;vertical-align:middle;">(' . $count . ' ürün)</span>' : ''; ?></h1>
    <p class="cart-lead">Siparişi burada netleştir, WhatsApp'ta tek mesajla onayla. Ödeme Havale / EFT ile yapılır, teslimat dijitaldir.</p>

    <?php if (!$items): ?>
        <div class="empty-state" style="text-align:left;">
            <h3>Sepetin şu an boş.</h3>
            <p>Katalogda key'ler, premium hesaplar ve stüdyo imzalı ekipmanlar seni bekliyor.</p>
            <a class="btn btn-primary" href="shop.php">Mağazaya git</a>
            <?php if (!empty($_SESSION['last_order'])): ?>
                <p style="margin-top:1.25rem;font-size:.88rem;color:var(--mist);">
                    Son siparişin <b style="color:var(--bone);"><?php echo e($_SESSION['last_order']['id']); ?></b> ·
                    <a class="text-link" href="order.php">WhatsApp'ta tekrar aç</a>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="cart-layout">
            <div>
                <div class="cart-list">
                    <?php foreach ($items as $item): ?>
                        <?php $p = $item['product']; ?>
                        <div class="cart-row">
                            <a class="cart-thumb" href="product.php?slug=<?php echo urlencode($p['slug']); ?>">
                                <div class="poster" data-pattern="<?php echo e($p['pattern']); ?>"
                                     style="--c1:<?php echo e($p['c1']); ?>;--c2:<?php echo e($p['c2']); ?>;--accent:<?php echo e($p['accent']); ?>">
                                    <div class="poster-top"></div>
                                    <span class="poster-glyph" aria-hidden="true"><?php echo e($p['glyph']); ?></span>
                                    <div class="poster-bottom"></div>
                                </div>
                            </a>
                            <div class="cart-info">
                                <h3><a href="product.php?slug=<?php echo urlencode($p['slug']); ?>"><?php echo e($p['name']); ?></a></h3>
                                <p><?php echo e($p['subtitle']); ?> · <?php echo e($p['platform']); ?></p>
                                <span class="cart-delivery"><span class="pulse" aria-hidden="true"></span><?php echo e($p['delivery']); ?></span>
                            </div>
                            <div class="cart-controls">
                                <span class="cart-line-total"><?php echo e(money($item['line'])); ?></span>
                                <div class="cart-actions">
                                    <form method="post" action="cart.php" class="cart-actions cart-qty-form">
                                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="slug" value="<?php echo e($p['slug']); ?>">
                                        <input type="hidden" name="redirect" value="cart.php">
                                        <input type="hidden" name="action" value="set">
                                        <div class="qty qty-sm">
                                            <button type="submit" name="action" value="dec" aria-label="Adet azalt">−</button>
                                            <input type="number" value="<?php echo (int)$item['qty']; ?>" min="1" max="10"
                                                   name="qty" aria-label="<?php echo e($p['name']); ?> adedi">
                                            <button type="submit" name="action" value="inc" aria-label="Adet artır">+</button>
                                        </div>
                                    </form>
                                    <form method="post" action="cart.php">
                                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="slug" value="<?php echo e($p['slug']); ?>">
                                        <input type="hidden" name="redirect" value="cart.php">
                                        <button type="submit" class="icon-btn" aria-label="<?php echo e($p['name']); ?> ürününü sepetten çıkar" title="Kaldır">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15M10 11v6M14 11v6"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cart-note">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                    <span>Tüm siparişlerde teslimat dijitaldir: ödeme onayından sonra ürünün WhatsApp üzerinden iletilir. Sipariş notuna eklemek istediğin her şeyi yazabilirsin.</span>
                </div>

                <form id="siparis" class="order-card" method="post" action="checkout.php" novalidate>
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <h2>Sipariş bilgileri</h2>
                    <p>Bu bilgiler yalnızca siparişinin hazırlanması için kullanılır.</p>

                    <?php if ($errors): ?>
                        <div class="form-errors" role="alert">
                            <b>Birkaç alanı kontrol edelim:</b>
                            <?php foreach ($errors as $error): ?>
                                <span>— <?php echo e($error); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="field">
                            <label for="order-name">Ad soyad</label>
                            <input type="text" id="order-name" name="name" value="<?php echo $field('name'); ?>"
                                   autocomplete="name" placeholder="Adın ve soyadın" required>
                        </div>
                        <div class="field">
                            <label for="order-email">E-posta <small>(opsiyonel)</small></label>
                            <input type="email" id="order-email" name="email" value="<?php echo $field('email'); ?>"
                                   autocomplete="email" placeholder="ornek@mail.com">
                        </div>

                        <div class="field full">
                            <label>Teslimat şekli</label>
                            <input type="hidden" name="delivery" value="<?php echo e($delivery_key); ?>">
                            <div class="seg">
                                <span class="seg-fixed">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                                    <?php echo e($delivery_options[$delivery_key]); ?>
                                </span>
                            </div>
                        </div>

                        <div class="field full">
                            <label>Ödeme tercihi</label>
                            <input type="hidden" name="payment" value="<?php echo e($payment_key); ?>">
                            <div class="seg">
                                <span class="seg-fixed">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                                    <?php echo e($payment_options[$payment_key]); ?>
                                </span>
                            </div>
                        </div>

                        <div class="field full">
                            <label for="order-note">Sipariş notu <small>(hesap kullanıcı adı, tasarım linki vb.)</small></label>
                            <textarea id="order-note" name="note" rows="3" placeholder="Eklemek istediklerin..."><?php echo $field('note'); ?></textarea>
                        </div>
                    </div>
                </form>
            </div>

            <aside class="summary" aria-label="Sipariş özeti">
                <div class="summary-card">
                    <div class="summary-head">
                        <h2>Özet</h2>
                        <span><?php echo $count; ?> ürün</span>
                    </div>
                    <div class="summary-lines">
                        <?php foreach ($items as $item): ?>
                            <div class="summary-line">
                                <span><?php echo $item['qty']; ?> × <?php echo e($item['product']['name']); ?></span>
                                <b><?php echo e(money($item['line'])); ?></b>
                            </div>
                        <?php endforeach; ?>
                        <div class="summary-line" style="padding-top:.7rem;border-top:1px solid var(--line);">
                            <span>Teslimat</span>
                            <b>Dijital · ücretsiz</b>
                        </div>
                    </div>
                    <div class="summary-total">
                        <span>Ara toplam</span>
                        <b><?php echo e(money($subtotal)); ?></b>
                    </div>
                    <div class="summary-body">
                        <button type="submit" form="siparis" class="btn btn-primary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="margin-right:.55rem"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm4.52 13.86c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.14-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
                            WhatsApp ile siparişi tamamla
                        </button>
                        <p class="wa-note">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                            <span>Sipariş özetin hazır bir mesaj olarak WhatsApp'ta açılır. Gönderdikten sonra Havale / EFT bilgilerini paylaşıyor, teslimatı hemen yapıyoruz.</span>
                        </p>
                        <div class="summary-badges">
                            <span>Güvenli</span><span>Hızlı</span><span>Gerçek destek</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</section>

<?php if ($items): ?>
    <div class="mobile-bar">
        <div class="mobile-bar-info">
            <span><?php echo $count; ?> ürün · Ara toplam</span>
            <b><?php echo e(money($subtotal)); ?></b>
        </div>
        <button type="submit" form="siparis" class="btn btn-primary">Siparişi onayla</button>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
