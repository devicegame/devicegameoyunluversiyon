<?php
require_once __DIR__ . '/config.php';

/**
 * Siparişi doğrular, kaydeder ve WhatsApp mesajına yönlendirir.
 * Sunucuda ödeme alınmaz; ödeme WhatsApp üzerinden birlikte netleştirilir.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

$items = cart_items();
$errors = [];

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));

$delivery_options = shop_delivery_options();
$payment_options = shop_payment_options();

// Tek teslimat şekli ve tek ödeme tercihi.
$delivery = array_key_first($delivery_options);
$payment = array_key_first($payment_options);

if (!csrf_valid()) {
    $errors[] = 'Oturum doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
}
if (!$items) {
    $errors[] = 'Sepetin boş görünüyor.';
}
if (mb_strlen($name) < 2) {
    $errors[] = 'Ad soyad alanını doldur.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'E-posta adresi geçerli görünmüyor.';
}
if (mb_strlen($note) > 600) {
    $errors[] = 'Sipariş notu çok uzun (en fazla 600 karakter).';
}

if ($errors) {
    $_SESSION['checkout_errors'] = $errors;
    $_SESSION['checkout_old'] = compact('name', 'email', 'note');
    header('Location: cart.php#siparis');
    exit;
}

$order_items = [];
foreach ($items as $item) {
    $order_items[] = [
        'slug' => $item['product']['slug'],
        'name' => $item['product']['name'],
        'subtitle' => $item['product']['subtitle'],
        'qty' => $item['qty'],
        'line' => $item['line'],
    ];
}

$order = [
    'id' => shop_order_id(),
    'created_at' => date('c'),
    'items' => $order_items,
    'subtotal' => cart_subtotal(),
    'delivery' => $delivery,
    'delivery_label' => $delivery_options[$delivery],
    'payment' => $payment_options[$payment],
    'name' => $name,
    'email' => $email,
    'note' => $note,
    'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
];

shop_save_order($order);

$_SESSION['last_order'] = $order;
cart_clear();

header('Location: ' . wa_link($whatsapp, shop_order_message($order)));
exit;
