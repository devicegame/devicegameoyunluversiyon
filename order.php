<?php
require_once __DIR__ . '/config.php';

/**
 * Sipariş özeti / yedek onay sayfası.
 * checkout.php siparişi kaydedip doğrudan WhatsApp'a yönlendirir; kullanıcı
 * geri dönerse bu sayfadan mesajı yeniden açabilir.
 */
$order = $_SESSION['last_order'] ?? null;
if (!$order) {
    header('Location: cart.php');
    exit;
}

$page_title = 'Sipariş ' . $order['id'];
$page_canonical = false;
$page_robots = 'noindex,nofollow';
$page_css = ['shop.css'];
$body_class = 'shop-page';
include 'header.php';

$wa = wa_link($whatsapp, shop_order_message($order));
?>

<section class="order-done">
    <p class="eyebrow" style="justify-content:center;">Sipariş no · <?php echo e($order['id']); ?></p>
    <h1>Son bir adım kaldı.</h1>
    <p>
        Siparişin kaydedildi. WhatsApp'ta açılacak hazır mesajı gönderdiğinde
        ödeme (Havale / EFT) ve dijital teslimatı birlikte tamamlıyoruz.
    </p>

    <div class="receipt">
        <div class="receipt-head">
            <span><?php echo e($order['name']); ?></span>
            <span><?php echo e($order['delivery_label']); ?></span>
        </div>
        <?php foreach ($order['items'] as $item): ?>
            <div class="receipt-row">
                <span><?php echo (int)$item['qty']; ?> × <?php echo e($item['name']); ?></span>
                <b><?php echo e(money($item['line'])); ?></b>
            </div>
        <?php endforeach; ?>
        <div class="receipt-row receipt-meta">
            <span>Teslimat</span>
            <b><?php echo e($order['delivery_label']); ?></b>
        </div>
        <div class="receipt-row receipt-meta">
            <span>Ödeme tercihi</span>
            <b><?php echo e($order['payment']); ?></b>
        </div>
        <div class="receipt-total">
            <span>Ara toplam</span>
            <b><?php echo e(money($order['subtotal'])); ?></b>
        </div>
    </div>

    <div class="order-actions">
        <a class="btn btn-primary" href="<?php echo e($wa); ?>" target="_blank" rel="noopener">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="margin-right:.55rem"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm4.52 13.86c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.14-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
            WhatsApp'ta onayla
        </a>
        <a class="btn btn-ghost" href="shop.php">Mağazaya dön</a>
    </div>

    <p class="order-note">
        Sipariş numaran <b><?php echo e($order['id']); ?></b>. Bu sayfayı yer imlerine ekleyebilir
        ya da WhatsApp konuşmasında bu numarayı kullanabilirsin.
    </p>
</section>

<?php include 'footer.php'; ?>
