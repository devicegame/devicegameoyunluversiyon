<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/admin.php';

/**
 * Basit sipariş paneli.
 * Şifre DG_ADMIN_PASSWORD ortam değişkeninden alınır.
 */

if (empty($admin_password)) {
    http_response_code(503);
    exit('Panel şifresi tanımlı değil. Sunucuda DG_ADMIN_PASSWORD ortam değişkenini ayarlayın.');
}

$login_error = '';
$is_admin = !empty($_SESSION['admin_authenticated']);

/* ---------------- Giriş / çıkış ---------------- */
if (($_POST['action'] ?? '') === 'login') {
    if (!csrf_valid()) {
        $login_error = 'Oturum doğrulaması başarısız oldu, sayfayı yenileyip tekrar dene.';
    } elseif (hash_equals($admin_password, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = time();
        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['admin_failures'] = (int)($_SESSION['admin_failures'] ?? 0) + 1;
        if ($_SESSION['admin_failures'] >= 3) {
            sleep(1);
        }
        $login_error = 'Şifre hatalı.';
        usleep(200000);
    }
}

if (($_POST['action'] ?? '') === 'logout') {
    unset($_SESSION['admin_authenticated']);
    session_regenerate_id(true);
    header('Location: admin.php');
    exit;
}

$is_admin = $is_admin && (($_POST['action'] ?? '') !== 'login');

/* ---------------- Durum güncelle ---------------- */
if ($is_admin && ($_POST['action'] ?? '') === 'status') {
    if (csrf_valid()) {
        admin_set_status((string)($_POST['id'] ?? ''), (string)($_POST['status'] ?? ''));
        $_SESSION['admin_flash'] = 'Sipariş durumu güncellendi.';
    }
    $back = 'admin.php?durum=' . urlencode((string)($_POST['filter'] ?? '')) . '&q=' . urlencode((string)($_POST['q'] ?? ''));
    header('Location: ' . $back);
    exit;
}

/* ---------------- Ürün ekle / sil ---------------- */
if ($is_admin && ($_POST['action'] ?? '') === 'product-add') {
    if (csrf_valid()) {
        $result = admin_add_product($_POST);
        if ($result['errors']) {
            $_SESSION['admin_flash'] = 'Ürün eklenemedi: ' . implode(' ', $result['errors']);
        } else {
            $_SESSION['admin_flash'] = '"' . trim((string)($_POST['name'] ?? '')) . '" kataloğa eklendi.';
        }
    }
    header('Location: admin.php?bolum=urunler');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'product-update') {
    if (csrf_valid()) {
        $result = admin_edit_product((string)($_POST['edit_slug'] ?? ''), $_POST);
        $_SESSION['admin_flash'] = $result['errors']
            ? 'Ürün güncellenemedi: ' . implode(' ', $result['errors'])
            : '"' . trim((string)($_POST['name'] ?? '')) . '" güncellendi.';
    }
    header('Location: admin.php?bolum=urunler');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'product-delete') {
    if (csrf_valid()) {
        $deleted_name = '';
        foreach (shop_products() as $p) {
            if ($p['slug'] === (string)($_POST['slug'] ?? '')) {
                $deleted_name = $p['name'];
                break;
            }
        }
        $_SESSION['admin_flash'] = admin_delete_product((string)($_POST['slug'] ?? ''))
            ? '"' . $deleted_name . '" katalogdan silindi.'
            : 'Ürün bulunamadı.';
    }
    header('Location: admin.php?bolum=urunler');
    exit;
}

/* ---------------- Slider yönetimi ---------------- */
if ($is_admin && ($_POST['action'] ?? '') === 'slide-save') {
    if (csrf_valid()) {
        $slides = shop_slides_all();
        $edit_id = trim((string)($_POST['edit_id'] ?? ''));
        $slide = admin_slide_from_input($_POST);
        $idx = $edit_id !== '' ? admin_slide_index($slides, $edit_id) : -1;
        if ($idx >= 0) {
            $slide['id'] = $edit_id;
            $slides[$idx] = $slide;
            admin_save_slides($slides);
            $_SESSION['admin_flash'] = 'Slayt güncellendi.';
        } else {
            if ($slide['title'] === '') {
                $_SESSION['admin_flash'] = 'Slayt başlığı boş olamaz.';
            } else {
                $slides[] = $slide;
                admin_save_slides($slides);
                $_SESSION['admin_flash'] = 'Slayt eklendi.';
            }
        }
    }
    header('Location: admin.php?bolum=slider');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'slide-delete') {
    if (csrf_valid()) {
        $slides = shop_slides_all();
        $idx = admin_slide_index($slides, (string)($_POST['id'] ?? ''));
        if ($idx >= 0) {
            array_splice($slides, $idx, 1);
            admin_save_slides($slides);
            $_SESSION['admin_flash'] = 'Slayt silindi.';
        }
    }
    header('Location: admin.php?bolum=slider');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'slide-toggle') {
    if (csrf_valid()) {
        $slides = shop_slides_all();
        $idx = admin_slide_index($slides, (string)($_POST['id'] ?? ''));
        if ($idx >= 0) {
            $slides[$idx]['active'] = !$slides[$idx]['active'];
            admin_save_slides($slides);
            $_SESSION['admin_flash'] = $slides[$idx]['active'] ? 'Slayt yayına alındı.' : 'Slayt gizlendi.';
        }
    }
    header('Location: admin.php?bolum=slider');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'slide-move') {
    if (csrf_valid()) {
        $slides = shop_slides_all();
        $idx = admin_slide_index($slides, (string)($_POST['id'] ?? ''));
        $swap = ((string)($_POST['dir'] ?? '') === 'up') ? $idx - 1 : $idx + 1;
        if ($idx >= 0 && $swap >= 0 && $swap < count($slides)) {
            $tmp = $slides[$idx];
            $slides[$idx] = $slides[$swap];
            $slides[$swap] = $tmp;
            admin_save_slides($slides);
            $_SESSION['admin_flash'] = 'Sıra güncellendi.';
        }
    }
    header('Location: admin.php?bolum=slider');
    exit;
}

/* ---------------- Blog yönetimi ---------------- */
if ($is_admin && ($_POST['action'] ?? '') === 'blog-save') {
    if (csrf_valid()) {
        $edit_slug = trim((string)($_POST['edit_slug'] ?? ''));
        $result = $edit_slug !== '' ? admin_edit_post($edit_slug, $_POST) : admin_add_post($_POST);
        $_SESSION['admin_flash'] = $result['errors']
            ? 'Yazı kaydedilemedi: ' . implode(' ', $result['errors'])
            : '"' . trim((string)($_POST['title'] ?? '')) . '" kaydedildi.';
    }
    header('Location: admin.php?bolum=blog');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'blog-delete') {
    if (csrf_valid()) {
        $_SESSION['admin_flash'] = admin_delete_post((string)($_POST['slug'] ?? ''))
            ? 'Yazı silindi.'
            : 'Yazı bulunamadı.';
    }
    header('Location: admin.php?bolum=blog');
    exit;
}

if ($is_admin && ($_POST['action'] ?? '') === 'blog-toggle') {
    if (csrf_valid()) {
        $slug = (string)($_POST['slug'] ?? '');
        $post = blog_post($slug, false);
        if ($post) {
            $post['published'] = !$post['published'];
            $posts = blog_posts(false);
            foreach ($posts as $i => $item) {
                if ($item['slug'] === $slug) {
                    $posts[$i] = $post;
                    break;
                }
            }
            admin_save_posts($posts);
            $_SESSION['admin_flash'] = $post['published'] ? 'Yazı yayına alındı.' : 'Yazı gizlendi.';
        }
    }
    header('Location: admin.php?bolum=blog');
    exit;
}

/* ---------------- CSV dışa aktarım ---------------- */
if ($is_admin && isset($_GET['export'])) {
    $orders = admin_orders();
    $map = admin_status_map();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="devicegame-siparisler-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel için UTF-8 BOM
    fputcsv($out, ['Sipariş', 'Tarih', 'Durum', 'Ad', 'E-posta', 'Teslimat', 'Ödeme', 'Ürünler', 'Tutar (TL)', 'Not']);
    foreach ($orders as $order) {
        $items = [];
        foreach ($order['items'] ?? [] as $item) {
            $items[] = $item['qty'] . ' x ' . $item['name'];
        }
        fputcsv($out, [
            $order['id'] ?? '',
            admin_date((string)($order['created_at'] ?? '')),
            admin_status_labels()[admin_order_status($order, $map)],
            $order['name'] ?? '',
            $order['email'] ?? '',
            $order['delivery_label'] ?? '',
            $order['payment'] ?? '',
            implode(' | ', $items),
            (int)($order['subtotal'] ?? 0),
            $order['note'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$orders = $is_admin ? admin_orders() : [];
$status_map = $is_admin ? admin_status_map() : [];
$stats = $is_admin ? admin_stats($orders, $status_map) : [];
$labels = admin_status_labels();

$section = (string)($_GET['bolum'] ?? 'siparisler');
if (!in_array($section, ['siparisler', 'urunler', 'slider', 'blog'], true)) {
    $section = 'siparisler';
}
$admin_products = $is_admin ? shop_products() : [];
$admin_slides = $is_admin ? shop_slides_all() : [];
$admin_posts = $is_admin ? blog_posts(false) : [];
$admin_blog_categories = $is_admin ? blog_category_summaries(false) : [];
$edit_product = null;
if ($is_admin && isset($_GET['duzenle']) && $section === 'urunler') {
    $edit_product = shop_product((string)$_GET['duzenle']);
}
$edit_slide = null;
if ($is_admin && isset($_GET['duzenle']) && $section === 'slider') {
    $edit_index = admin_slide_index($admin_slides, (string)$_GET['duzenle']);
    if ($edit_index >= 0) {
        $edit_slide = $admin_slides[$edit_index];
    }
}
$edit_post = null;
if ($is_admin && isset($_GET['duzenle']) && $section === 'blog') {
    $edit_post = blog_post((string)$_GET['duzenle'], false);
}

$filter_status = (string)($_GET['durum'] ?? '');
if ($filter_status !== '' && !isset($labels[$filter_status])) {
    $filter_status = '';
}
$search = trim((string)($_GET['q'] ?? ''));

$visible = [];
foreach ($orders as $order) {
    $status = admin_order_status($order, $status_map);
    if ($filter_status !== '' && $status !== $filter_status) {
        continue;
    }
    if (!admin_match($order, $search)) {
        continue;
    }
    $visible[] = $order;
}

$flash = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Siparişler · <?php echo e($company_name); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#120b24">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230A1A15'/%3E%3Ccircle cx='16' cy='16' r='6' fill='%234FE0A0'/%3E%3C/svg%3E">
    <script src="assets/js/scroll-restore.js" defer></script>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <link rel="stylesheet" href="assets/css/colorful-theme.css">
</head>
<body class="admin-body">
<?php if (!$is_admin): ?>
    <main class="login-wrap">
        <form class="login-card" method="post" action="admin.php">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="login">
            <a href="index.php" class="brand-logo"><?php echo e($company_name); ?><span class="dot">.</span></a>
            <h1>Sipariş paneli</h1>
            <p class="muted">Devam etmek için panel şifresini gir.</p>
            <?php if ($login_error): ?>
                <div class="login-error" role="alert"><?php echo e($login_error); ?></div>
            <?php endif; ?>
            <div class="field">
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Giriş yap</button>
            <p class="login-note">Bu sayfa arama motorlarına kapalıdır. Şifre sunucudaki <code>DG_ADMIN_PASSWORD</code> ortam değişkeniyle tanımlanır.</p>
        </form>
    </main>
<?php else: ?>
    <header class="admin-top">
        <div class="admin-top-inner">
            <div class="admin-brand">
                <a href="index.php" class="brand-logo"><?php echo e($company_name); ?><span class="dot">.</span></a>
                <span class="admin-tag">Sipariş paneli</span>
            </div>
            <div class="admin-top-actions">
                <a href="shop.php" class="chip">Mağazayı gör</a>
                <a href="admin.php?export=csv" class="chip">CSV indir</a>
                <form method="post" action="admin.php">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="chip chip-quiet">Çıkış</button>
                </form>
            </div>
        </div>
    </header>

    <nav class="admin-tabs" aria-label="Panel bölümleri">
        <a class="admin-tab" href="admin.php?bolum=siparisler"
           aria-current="<?php echo $section === 'siparisler' ? 'page' : 'false'; ?>">Siparişler</a>
        <a class="admin-tab" href="admin.php?bolum=urunler"
           aria-current="<?php echo $section === 'urunler' ? 'page' : 'false'; ?>">Ürünler <small><?php echo count($admin_products); ?></small></a>
        <a class="admin-tab" href="admin.php?bolum=slider"
           aria-current="<?php echo $section === 'slider' ? 'page' : 'false'; ?>">Slider <small><?php echo count($admin_slides); ?></small></a>
        <a class="admin-tab" href="admin.php?bolum=blog"
           aria-current="<?php echo $section === 'blog' ? 'page' : 'false'; ?>">Blog <small><?php echo count($admin_posts); ?></small></a>
    </nav>

    <main class="admin-main">
        <?php if ($admin_password === 'devicegame'): ?>
            <div class="admin-warning" role="alert">
                <b>Güvensiz şifre kullanılıyor.</b>
                <span>Yayına almadan önce <code>DG_ADMIN_PASSWORD</code> değerini güçlü ve benzersiz bir şifreyle değiştir.</span>
            </div>
        <?php endif; ?>
        <?php if ($flash): ?>
            <div class="admin-flash" role="status"><?php echo e($flash); ?></div>
        <?php endif; ?>

        <?php if ($section === 'siparisler'): ?>
        <section class="admin-stats" aria-label="Özet">
            <div class="stat-card">
                <span>Toplam sipariş</span>
                <b><?php echo (int)$stats['total']; ?></b>
            </div>
            <div class="stat-card">
                <span>Bekleyen</span>
                <b class="accent"><?php echo (int)$stats['new']; ?></b>
            </div>
            <div class="stat-card">
                <span>Ciro (iptal hariç)</span>
                <b><?php echo e(money($stats['revenue'])); ?></b>
            </div>
            <div class="stat-card">
                <span>Bugün</span>
                <b><?php echo (int)$stats['today_count']; ?> <small><?php echo e(money($stats['today_revenue'])); ?></small></b>
            </div>
        </section>

        <form class="admin-filters" method="get" action="admin.php">
            <div class="chips">
                <a class="chip" href="admin.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>"
                   aria-current="<?php echo $filter_status === '' ? 'true' : 'false'; ?>">Tümü</a>
                <?php foreach ($labels as $key => $label): ?>
                    <a class="chip" href="admin.php?durum=<?php echo urlencode($key); ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>"
                       aria-current="<?php echo $filter_status === $key ? 'true' : 'false'; ?>"><?php echo e($label); ?></a>
                <?php endforeach; ?>
            </div>
            <label class="search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="Sipariş no, ad, e-posta, ürün" aria-label="Sipariş ara">
                <?php if ($filter_status !== ''): ?>
                    <input type="hidden" name="durum" value="<?php echo e($filter_status); ?>">
                <?php endif; ?>
                <button type="submit">Ara</button>
            </label>
        </form>

        <?php if (!$visible): ?>
            <div class="admin-empty">
                <h2>Henüz sipariş yok.</h2>
                <p>Bu filtrede görüntülenecek sipariş bulunmuyor. Siparişler site üzerinden WhatsApp onayına geçildiğinde burada listelenir.</p>
                <a class="btn btn-ghost" href="shop.php">Mağazayı aç</a>
            </div>
        <?php else: ?>
            <div class="order-list">
                <?php foreach ($visible as $order): ?>
                    <?php $status = admin_order_status($order, $status_map); ?>
                    <article class="order-card-admin status-<?php echo e($status); ?>">
                        <div class="order-head">
                            <div>
                                <p class="order-id"><?php echo e($order['id']); ?></p>
                                <p class="order-date"><?php echo e(admin_date((string)($order['created_at'] ?? ''))); ?></p>
                            </div>
                            <span class="order-status"><?php echo e($labels[$status]); ?></span>
                        </div>

                        <div class="order-body">
                            <div class="order-customer">
                                <strong><?php echo e($order['name']); ?></strong>
                                <?php if (!empty($order['email'])): ?>
                                    <a href="mailto:<?php echo e($order['email']); ?>"><?php echo e($order['email']); ?></a>
                                <?php endif; ?>
                                <span class="muted">Dijital teslimat · <?php echo e($order['payment'] ?? 'Havale / EFT'); ?></span>
                            </div>

                            <div class="order-items">
                                <?php foreach ($order['items'] as $item): ?>
                                    <div class="order-item">
                                        <span><?php echo (int)$item['qty']; ?> × <?php echo e($item['name']); ?></span>
                                        <b><?php echo e(money($item['line'])); ?></b>
                                    </div>
                                <?php endforeach; ?>
                                <div class="order-item total">
                                    <span>Ara toplam</span>
                                    <b><?php echo e(money($order['subtotal'])); ?></b>
                                </div>
                            </div>

                            <div class="order-meta">
                                <p><span>Teslimat</span><?php echo e($order['delivery_label'] ?? '—'); ?></p>
                                <p><span>Ödeme</span><?php echo e($order['payment'] ?? '—'); ?></p>
                                <?php if (!empty($order['note'])): ?>
                                    <p class="order-note"><span>Not</span><?php echo nl2br(e($order['note'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="order-actions-admin">
                            <?php foreach ($labels as $key => $label): ?>
                                <?php if ($key === $status) continue; ?>
                                <form method="post" action="admin.php">
                                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?php echo e($order['id']); ?>">
                                    <input type="hidden" name="status" value="<?php echo e($key); ?>">
                                    <input type="hidden" name="filter" value="<?php echo e($filter_status); ?>">
                                    <input type="hidden" name="q" value="<?php echo e($search); ?>">
                                    <button type="submit" class="status-btn"><?php echo e($label); ?></button>
                                </form>
                            <?php endforeach; ?>
                            <a class="status-btn wa" href="<?php echo e(wa_link($whatsapp, admin_order_message($order))); ?>" target="_blank" rel="noopener">WhatsApp</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'urunler'): ?>
            <div class="admin-cols">
                <section class="admin-panel" aria-labelledby="product-add-title">
                    <h2 id="product-add-title"><?php echo $edit_product ? 'Ürünü düzenle' : 'Yeni ürün ekle'; ?></h2>
                    <p class="panel-lead"><?php echo $edit_product ? 'Bilgileri güncelleyip kaydet. Ürün adresi (slug) değişmez.' : 'Eklediğin ürün anında mağaza vitrininde görünür. Yıldızlı alanlar zorunludur.'; ?></p>
                    <form method="post" action="admin.php?bolum=urunler" class="panel-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="<?php echo $edit_product ? 'product-update' : 'product-add'; ?>">
                        <?php if ($edit_product): ?>
                            <input type="hidden" name="edit_slug" value="<?php echo e($edit_product['slug']); ?>">
                        <?php endif; ?>

                        <div class="field">
                            <label for="p-name">Ürün adı *</label>
                            <input type="text" id="p-name" name="name" required
                                   value="<?php echo e($edit_product['name'] ?? ''); ?>" placeholder="EA SPORTS FC 26">
                        </div>
                        <div class="field">
                            <label for="p-subtitle">Kısa açıklama</label>
                            <input type="text" id="p-subtitle" name="subtitle" value="<?php echo e($edit_product['subtitle'] ?? ''); ?>" placeholder="Standart Sürüm · PS5">
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="p-category">Kategori *</label>
                                <select id="p-category" name="category" required>
                                    <?php foreach (shop_categories() as $key => $label): ?>
                                        <option value="<?php echo e($key); ?>" <?php echo ($edit_product['category'] ?? '') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="p-platform">Platform</label>
                                <input type="text" id="p-platform" name="platform" value="<?php echo e($edit_product['platform'] ?? ''); ?>" placeholder="Steam, PS5, PC…">
                            </div>
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="p-price">Fiyat (₺) *</label>
                                <input type="number" id="p-price" name="price" min="1" step="1" required
                                       value="<?php echo $edit_product !== null ? (int)$edit_product['price'] : ''; ?>" placeholder="899">
                            </div>
                            <div class="field">
                                <label for="p-old">Eski fiyat (₺)</label>
                                <input type="number" id="p-old" name="old_price" min="1" step="1"
                                       value="<?php echo $edit_product !== null && $edit_product['old_price'] !== null ? (int)$edit_product['old_price'] : ''; ?>" placeholder="1249">
                            </div>
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="p-badge">Rozet</label>
                                <input type="text" id="p-badge" name="badge" value="<?php echo e($edit_product['badge'] ?? ''); ?>" placeholder="Çok satan, Fırsat…">
                            </div>
                            <div class="field">
                                <label for="p-stock">Stok</label>
                                <input type="number" id="p-stock" name="stock" min="0" step="1"
                                       value="<?php echo $edit_product !== null ? (int)$edit_product['stock'] : '10'; ?>">
                            </div>
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="p-pattern">Kapak deseni</label>
                                <select id="p-pattern" name="pattern">
                                    <?php foreach (['grid' => 'Grid', 'lines' => 'Çizgiler', 'rings' => 'Halkalar', 'dots' => 'Noktalar'] as $key => $label): ?>
                                        <option value="<?php echo e($key); ?>" <?php echo ($edit_product['pattern'] ?? 'grid') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="p-delivery">Teslimat yazısı</label>
                                <input type="text" id="p-delivery" name="delivery" value="<?php echo e($edit_product['delivery'] ?? 'Anında teslimat'); ?>">
                            </div>
                        </div>
                        <div class="form-3">
                            <div class="field">
                                <label for="p-c1">Renk 1</label>
                                <input type="color" id="p-c1" name="c1" value="<?php echo e($edit_product['c1'] ?? '#0F3B2C'); ?>" class="color-input">
                            </div>
                            <div class="field">
                                <label for="p-c2">Renk 2</label>
                                <input type="color" id="p-c2" name="c2" value="<?php echo e($edit_product['c2'] ?? '#2FA377'); ?>" class="color-input">
                            </div>
                            <div class="field">
                                <label for="p-accent">Vurgu</label>
                                <input type="color" id="p-accent" name="accent" value="<?php echo e($edit_product['accent'] ?? '#D6FFE9'); ?>" class="color-input">
                            </div>
                        </div>
                        <div class="field">
                            <label for="p-image">Kapak görseli <small>(URL ya da img/… yolu, opsiyonel)</small></label>
                            <input type="text" id="p-image" name="image" value="<?php echo e($edit_product['image'] ?? ''); ?>" placeholder="https://… veya img/urun.png">
                        </div>
                        <div class="field">
                            <label for="p-desc">Açıklama</label>
                            <textarea id="p-desc" name="desc" rows="3" placeholder="Ürünün uzun açıklaması…"><?php echo e($edit_product['desc'] ?? ''); ?></textarea>
                        </div>
                        <div class="field">
                            <label for="p-features">Özellikler <small>(satır başına bir tane)</small></label>
                            <textarea id="p-features" name="features" rows="4" placeholder="Tam sürüm dijital key&#10;TR bölgesi uyumlu&#10;Anında teslimat"><?php echo e($edit_product !== null ? implode("\n", $edit_product['features']) : ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-full"><?php echo $edit_product ? 'Ürünü güncelle' : 'Ürünü kataloğa ekle'; ?></button>
                        <?php if ($edit_product): ?>
                            <a class="btn btn-ghost btn-full" href="admin.php?bolum=urunler">Vazgeç</a>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="admin-panel" aria-labelledby="product-list-title">
                    <h2 id="product-list-title">Katalog <small class="panel-count"><?php echo count($admin_products); ?> ürün</small></h2>
                    <p class="panel-lead">Silmek istediğin ürünün "Sil" düğmesine bas. Sepette olan ürünler de katalogdan kalkar.</p>
                    <?php if (!$admin_products): ?>
                        <div class="admin-empty">
                            <h2>Katalog boş.</h2>
                            <p>Soldaki formdan ilk ürünü ekleyebilirsin.</p>
                        </div>
                    <?php else: ?>
                        <div class="manage-list">
                            <?php foreach ($admin_products as $p): ?>
                                <div class="manage-row">
                                    <div class="manage-swatch" style="--c1:<?php echo e($p['c1']); ?>;--c2:<?php echo e($p['c2']); ?>;" aria-hidden="true"><?php echo e($p['glyph']); ?></div>
                                    <div class="manage-info">
                                        <strong><?php echo e($p['name']); ?></strong>
                                        <span><?php echo e(shop_categories()[$p['category']] ?? ''); ?> · <?php echo e($p['platform']); ?><?php echo $p['badge'] !== null ? ' · ' . e($p['badge']) : ''; ?></span>
                                    </div>
                                    <div class="manage-meta">
                                        <b><?php echo e(money($p['price'])); ?></b>
                                        <span>Stok: <?php echo (int)$p['stock']; ?></span>
                                    </div>
                                    <div class="manage-actions">
                                        <a class="status-btn" href="admin.php?bolum=urunler&duzenle=<?php echo urlencode($p['slug']); ?>">Düzenle</a>
                                        <form method="post" action="admin.php?bolum=urunler"
                                              onsubmit="return confirm('<?php echo e($p['name']); ?> ürününü silmek istediğine emin misin?');">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="product-delete">
                                            <input type="hidden" name="slug" value="<?php echo e($p['slug']); ?>">
                                            <button type="submit" class="status-btn danger">Sil</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($section === 'slider'): ?>
            <div class="admin-cols">
                <section class="admin-panel" aria-labelledby="slide-form-title">
                    <h2 id="slide-form-title"><?php echo $edit_slide ? 'Slaytı düzenle' : 'Yeni slayt ekle'; ?></h2>
                    <p class="panel-lead">Mağaza sayfasındaki slider bu slaytlardan oluşur. Sıralama, görünürlük ve içerik buradan yönetilir.</p>
                    <form method="post" action="admin.php?bolum=slider" class="panel-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="slide-save">
                        <?php if ($edit_slide): ?>
                            <input type="hidden" name="edit_id" value="<?php echo e($edit_slide['id']); ?>">
                        <?php endif; ?>

                        <div class="field">
                            <label for="s-title">Başlık *</label>
                            <input type="text" id="s-title" name="title" required
                                   value="<?php echo e($edit_slide['title'] ?? ''); ?>" placeholder="FC 26 sahaya çıkmaya hazır">
                        </div>
                        <div class="field">
                            <label for="s-tag">Etiket</label>
                            <input type="text" id="s-tag" name="tag" value="<?php echo e($edit_slide['tag'] ?? ''); ?>" placeholder="Çok satan, Fırsat…">
                        </div>
                        <div class="field">
                            <label for="s-text">Açıklama</label>
                            <textarea id="s-text" name="text" rows="3" placeholder="Slayt metni…"><?php echo e($edit_slide['text'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="s-button">Buton yazısı</label>
                                <input type="text" id="s-button" name="button" value="<?php echo e($edit_slide['button'] ?? ''); ?>" placeholder="Ürüne göz at">
                            </div>
                            <div class="field">
                                <label for="s-link">Buton linki</label>
                                <input type="text" id="s-link" name="link" value="<?php echo e($edit_slide['link'] ?? ''); ?>" placeholder="shop.php?kategori=account">
                            </div>
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="s-c1">Renk 1</label>
                                <input type="color" id="s-c1" name="c1" value="<?php echo e($edit_slide['c1'] ?? '#0F3B2C'); ?>" class="color-input">
                            </div>
                            <div class="field">
                                <label for="s-c2">Renk 2</label>
                                <input type="color" id="s-c2" name="c2" value="<?php echo e($edit_slide['c2'] ?? '#2FA377'); ?>" class="color-input">
                            </div>
                        </div>
                        <div class="field">
                            <label for="s-image">Arka plan görseli <small>(URL ya da img/… yolu, opsiyonel)</small></label>
                            <input type="text" id="s-image" name="image" value="<?php echo e($edit_slide['image'] ?? ''); ?>" placeholder="https://… veya img/slider.png">
                        </div>
                        <label class="check-line">
                            <input type="checkbox" name="active" value="1" <?php echo ($edit_slide === null || !empty($edit_slide['active'])) ? 'checked' : ''; ?>>
                            Yayında (mağazada gösterilsin)
                        </label>
                        <button type="submit" class="btn btn-primary btn-full"><?php echo $edit_slide ? 'Slaydı güncelle' : 'Slaydı ekle'; ?></button>
                        <?php if ($edit_slide): ?>
                            <a class="btn btn-ghost btn-full" href="admin.php?bolum=slider">Vazgeç</a>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="admin-panel" aria-labelledby="slide-list-title">
                    <h2 id="slide-list-title">Slaytlar <small class="panel-count"><?php echo count($admin_slides); ?> slayt</small></h2>
                    <p class="panel-lead">Oklarla sırayı değiştir, göz ikonuyla yayına al/gizle.</p>
                    <?php if (!$admin_slides): ?>
                        <div class="admin-empty">
                            <h2>Slider boş.</h2>
                            <p>Soldaki formdan ilk slaytı ekleyebilirsin.</p>
                        </div>
                    <?php else: ?>
                        <div class="manage-list">
                            <?php foreach ($admin_slides as $index => $slide): ?>
                                <div class="manage-row slide-row<?php echo $slide['active'] ? '' : ' is-off'; ?>">
                                    <div class="manage-swatch" style="--c1:<?php echo e($slide['c1']); ?>;--c2:<?php echo e($slide['c2']); ?>;" aria-hidden="true"><?php echo $index + 1; ?></div>
                                    <div class="manage-info">
                                        <strong><?php echo e($slide['title']); ?></strong>
                                        <span><?php echo e($slide['tag'] !== '' ? $slide['tag'] . ' · ' : ''); ?><?php echo e($slide['link'] !== '' ? $slide['link'] : 'Bağlantı yok'); ?> · <?php echo $slide['active'] ? 'Yayında' : 'Gizli'; ?></span>
                                    </div>
                                    <div class="manage-actions">
                                        <form method="post" action="admin.php?bolum=slider">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="slide-move">
                                            <input type="hidden" name="id" value="<?php echo e($slide['id']); ?>">
                                            <input type="hidden" name="dir" value="up">
                                            <button type="submit" class="status-btn" <?php echo $index === 0 ? 'disabled' : ''; ?> aria-label="Yukarı taşı">↑</button>
                                        </form>
                                        <form method="post" action="admin.php?bolum=slider">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="slide-move">
                                            <input type="hidden" name="id" value="<?php echo e($slide['id']); ?>">
                                            <input type="hidden" name="dir" value="down">
                                            <button type="submit" class="status-btn" <?php echo $index === count($admin_slides) - 1 ? 'disabled' : ''; ?> aria-label="Aşağı taşı">↓</button>
                                        </form>
                                        <form method="post" action="admin.php?bolum=slider">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="slide-toggle">
                                            <input type="hidden" name="id" value="<?php echo e($slide['id']); ?>">
                                            <button type="submit" class="status-btn"><?php echo $slide['active'] ? 'Gizle' : 'Yayınla'; ?></button>
                                        </form>
                                        <a class="status-btn" href="admin.php?bolum=slider&duzenle=<?php echo urlencode($slide['id']); ?>">Düzenle</a>
                                        <form method="post" action="admin.php?bolum=slider"
                                              onsubmit="return confirm('Bu slaytı silmek istediğine emin misin?');">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="slide-delete">
                                            <input type="hidden" name="id" value="<?php echo e($slide['id']); ?>">
                                            <button type="submit" class="status-btn danger">Sil</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($section === 'blog'): ?>
            <div class="admin-cols">
                <section class="admin-panel" aria-labelledby="post-form-title">
                    <h2 id="post-form-title"><?php echo $edit_post ? 'Yazıyı düzenle' : 'Yeni yazı ekle'; ?></h2>
                    <p class="panel-lead">Yazılar <b>devicegame.com/blog</b> adresinde yayınlanır. Metinde boş satır yeni paragraf, "## " başlık, "- " liste başlatır.</p>
                    <form method="post" action="admin.php?bolum=blog" class="panel-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="blog-save">
                        <?php if ($edit_post): ?>
                            <input type="hidden" name="edit_slug" value="<?php echo e($edit_post['slug']); ?>">
                        <?php endif; ?>

                        <div class="field">
                            <label for="b-title">Başlık *</label>
                            <input type="text" id="b-title" name="title" required
                                   value="<?php echo e($edit_post['title'] ?? ''); ?>" placeholder="Project: Nexus'a hoş geldiniz">
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="b-category">Kategori *</label>
                                <select id="b-category" name="category" required>
                                    <?php foreach ($admin_blog_categories as $category): ?>
                                        <option value="<?php echo e($category['slug']); ?>" <?php echo blog_category_slugify((string)($edit_post['category'] ?? 'genel')) === (string)$category['slug'] ? 'selected' : ''; ?>><?php echo e($category['name']); ?> (<?php echo (int)$category['count']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Kategori listesi <a href="admin/blog.php#blog-categories">D-Coin yönetim panelinden</a> düzenlenir.</small>
                            </div>
                            <div class="field">
                                <label for="b-date">Tarih</label>
                                <input type="date" id="b-date" name="date"
                                       value="<?php echo e($edit_post['date'] ?? date('Y-m-d')); ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label for="b-tag">Etiket <small>(opsiyonel kısa rozet)</small></label>
                            <input type="text" id="b-tag" name="tag" value="<?php echo e($edit_post['tag'] ?? ''); ?>" placeholder="Güncelleme, Video, Röportaj…">
                        </div>
                        <div class="field">
                            <label for="b-excerpt">Özet <small>(liste sayfasında görünür)</small></label>
                            <textarea id="b-excerpt" name="excerpt" rows="2" placeholder="Kısa özet…"><?php echo e($edit_post['excerpt'] ?? ''); ?></textarea>
                        </div>
                        <div class="field">
                            <label for="b-editor">İçerik *</label>
                            <div class="rich-editor" data-rich-editor>
                                <div class="rich-toolbar" role="toolbar" aria-label="Yazı biçimlendirme araçları">
                                    <button type="button" data-command="bold" title="Kalın"><b>B</b></button>
                                    <button type="button" data-command="italic" title="İtalik"><i>I</i></button>
                                    <button type="button" data-command="underline" title="Altı çizili"><u>U</u></button>
                                    <span class="toolbar-separator"></span>
                                    <button type="button" data-block="h2" title="Başlık">Başlık</button>
                                    <button type="button" data-block="p" title="Normal metin">Paragraf</button>
                                    <button type="button" data-command="insertUnorderedList" title="Madde işaretli liste">• Liste</button>
                                    <button type="button" data-command="insertOrderedList" title="Numaralı liste">1. Liste</button>
                                    <button type="button" data-command="formatBlockquote" title="Alıntı">Alıntı</button>
                                    <button type="button" data-command="createLink" title="Bağlantı ekle">Bağlantı</button>
                                    <button type="button" data-command="removeFormat" title="Biçimi temizle">Biçimi temizle</button>
                                </div>
                                <div id="b-editor" class="rich-editable" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Blog yazısı içeriği"><p>Yazınızı buraya yazmaya başlayın…</p></div>
                                <textarea id="b-content" name="content" required hidden><?php echo e($edit_post['content'] ?? ''); ?></textarea>
                                <div class="editor-footer"><span>Kalın, italik, başlık, liste, alıntı ve bağlantı ekleyebilirsin.</span><span data-word-count>0 kelime</span></div>
                            </div>
                        </div>
                        <div class="form-2">
                            <div class="field">
                                <label for="b-c1">Renk 1</label>
                                <input type="color" id="b-c1" name="c1" value="<?php echo e($edit_post['c1'] ?? '#0F3B2C'); ?>" class="color-input">
                            </div>
                            <div class="field">
                                <label for="b-c2">Renk 2</label>
                                <input type="color" id="b-c2" name="c2" value="<?php echo e($edit_post['c2'] ?? '#2FA377'); ?>" class="color-input">
                            </div>
                        </div>
                        <div class="field">
                            <label for="b-image">Kapak görseli <small>(URL ya da img/… yolu, opsiyonel)</small></label>
                            <input type="text" id="b-image" name="image" value="<?php echo e($edit_post['image'] ?? ''); ?>" placeholder="https://… veya img/blog.png">
                        </div>
                        <label class="check-line">
                            <input type="checkbox" name="published" value="1" <?php echo ($edit_post === null || !empty($edit_post['published'])) ? 'checked' : ''; ?>>
                            Yayında (blogda görünsün)
                        </label>
                        <button type="submit" class="btn btn-primary btn-full"><?php echo $edit_post ? 'Yazıyı güncelle' : 'Yazıyı yayınla'; ?></button>
                        <?php if ($edit_post): ?>
                            <a class="btn btn-ghost btn-full" href="admin.php?bolum=blog">Vazgeç</a>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="admin-panel" aria-labelledby="post-list-title">
                    <h2 id="post-list-title">Yazılar <small class="panel-count"><?php echo count($admin_posts); ?> yazı</small></h2>
                    <p class="panel-lead">Yayın durumunu açıp kapatabilir, yazıları düzenleyebilir ya da silebilirsin.</p>
                    <?php if (!$admin_posts): ?>
                        <div class="admin-empty">
                            <h2>Henüz yazı yok.</h2>
                            <p>Soldaki formdan ilk blog yazını ekleyebilirsin.</p>
                        </div>
                    <?php else: ?>
                        <div class="manage-list">
                            <?php foreach ($admin_posts as $post): ?>
                                <div class="manage-row slide-row<?php echo $post['published'] ? '' : ' is-off'; ?>">
                                    <div class="manage-swatch" style="--c1:<?php echo e($post['c1']); ?>;--c2:<?php echo e($post['c2']); ?>;" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 10h16M4 15h10"/></svg>
                                    </div>
                                    <div class="manage-info">
                                        <strong><?php echo e($post['title']); ?></strong>
                                        <span><?php echo e(date('d.m.Y', strtotime($post['date']))); ?> · <?php echo e($post['category_name'] ?? 'Genel'); ?><?php echo $post['tag'] !== '' && blog_category_slugify((string)$post['tag']) !== (string)($post['category'] ?? '') && shop_fold((string)$post['tag']) !== shop_fold((string)($post['category_name'] ?? '')) ? ' · ' . e($post['tag']) : ''; ?> · <?php echo $post['published'] ? 'Yayında' : 'Taslak'; ?></span>
                                    </div>
                                    <div class="manage-actions">
                                        <form method="post" action="admin.php?bolum=blog">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="blog-toggle">
                                            <input type="hidden" name="slug" value="<?php echo e($post['slug']); ?>">
                                            <button type="submit" class="status-btn"><?php echo $post['published'] ? 'Gizle' : 'Yayınla'; ?></button>
                                        </form>
                                        <a class="status-btn" href="admin.php?bolum=blog&duzenle=<?php echo urlencode($post['slug']); ?>">Düzenle</a>
                                        <form method="post" action="admin.php?bolum=blog"
                                              onsubmit="return confirm('Bu yazıyı silmek istediğine emin misin?');">
                                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="blog-delete">
                                            <input type="hidden" name="slug" value="<?php echo e($post['slug']); ?>">
                                            <button type="submit" class="status-btn danger">Sil</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </main>
<?php endif; ?>
<script>
(() => {
  const wrap = document.querySelector('[data-rich-editor]');
  const form = wrap?.closest('form');
  const editor = document.getElementById('b-editor');
  const output = document.getElementById('b-content');
  if (!wrap || !form || !editor || !output) return;
  if (output.value.trim()) editor.innerHTML = output.value;
  const count = wrap.querySelector('[data-word-count]');
  const update = () => {
    output.value = editor.innerHTML;
    const text = editor.innerText.trim();
    count.textContent = (text ? text.split(/\s+/).length : 0) + ' kelime';
  };
  wrap.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => {
    editor.focus();
    const cmd = button.dataset.command;
    if (cmd === 'formatBlockquote') document.execCommand('formatBlock', false, 'blockquote');
    else if (cmd === 'createLink') {
      const url = prompt('Bağlantı adresini girin (https://…):');
      if (url && /^https?:\/\//i.test(url)) document.execCommand('createLink', false, url);
    } else document.execCommand(cmd, false, null);
    update();
  }));
  wrap.querySelectorAll('[data-block]').forEach(button => button.addEventListener('click', () => {
    editor.focus(); document.execCommand('formatBlock', false, button.dataset.block); update();
  }));
  editor.addEventListener('input', update);
  form.addEventListener('submit', event => {
    update();
    if (editor.innerText.trim().length < 10) { event.preventDefault(); alert('Yazı içeriği en az 10 karakter olmalı.'); editor.focus(); }
  });
  update();
})();
</script>
</body>
</html>
