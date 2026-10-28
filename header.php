<?php
include_once __DIR__ . '/config.php';

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Alt klasör sayfaları (ör. blog/) bu değeri '../' olarak ayarlar.
$root = $root ?? '';
$current = ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$full_title = isset($page_title) && $page_title !== ''
    ? $page_title . ' | ' . $company_name
    : $company_name . ' | ' . $slogan;

$page_meta_desc = $page_meta_desc ?? $meta_desc;

// Sayfa bazlı canonical / Open Graph / Twitter bilgileri. Sayfalar uygulama
// köküne göreli yol verebilir: $page_canonical = 'sosyal/gonderi.php?id=1';
$script_parts = array_values(array_filter(explode('/', trim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')), '/')), static fn($part): bool => $part !== ''));
$default_canonical_path = '';
if ($root === '../' && count($script_parts) >= 2) {
    $candidate = implode('/', array_slice($script_parts, -2));
    $default_canonical_path = $candidate === 'index.php' ? '' : $candidate;
} elseif ($script_parts) {
    $candidate = (string)end($script_parts);
    $default_canonical_path = $candidate === 'index.php' ? '' : $candidate;
}
$canonical_url = '';
if (!isset($page_canonical) || $page_canonical !== false) {
    $canonical_path = isset($page_canonical) && is_string($page_canonical) ? $page_canonical : $default_canonical_path;
    if (str_starts_with($canonical_path, '../')) $canonical_path = substr($canonical_path, 3);
    $canonical_url = function_exists('dg_absolute_url') ? dg_absolute_url($canonical_path) : $canonical_path;
}
$page_robots = isset($page_robots) && is_string($page_robots) ? trim($page_robots) : '';
$page_og_type = isset($page_og_type) && is_string($page_og_type) && $page_og_type !== '' ? $page_og_type : 'website';
$page_og_title = isset($page_og_title) && is_string($page_og_title) && $page_og_title !== '' ? $page_og_title : $full_title;
$page_og_desc = isset($page_og_desc) && is_string($page_og_desc) && $page_og_desc !== '' ? $page_og_desc : $page_meta_desc;
$page_og_image_value = array_key_exists('page_og_image', get_defined_vars()) ? $page_og_image : 'assets/img/devicegame-og.png';
$page_og_image_url = '';
if (is_string($page_og_image_value) && trim($page_og_image_value) !== '') {
    $image_path = trim($page_og_image_value);
    if (str_starts_with($image_path, '../')) $image_path = substr($image_path, 3);
    $page_og_image_url = function_exists('dg_absolute_url') ? dg_absolute_url($image_path) : $image_path;
}
$page_og_image_alt = isset($page_og_image_alt) && is_string($page_og_image_alt) && $page_og_image_alt !== ''
    ? $page_og_image_alt
    : 'Device Game — Oyun dünyasının anahtarı.';
$page_twitter_card = isset($page_twitter_card) && is_string($page_twitter_card) && $page_twitter_card !== ''
    ? $page_twitter_card
    : ($page_og_image_url !== '' ? 'summary_large_image' : 'summary');
$meta_time = static function ($value): string {
    if ($value === null || $value === '') return '';
    if (is_numeric($value)) return date(DATE_ATOM, (int)$value);
    $timestamp = strtotime((string)$value);
    return $timestamp ? date(DATE_ATOM, $timestamp) : (string)$value;
};
$page_published_time = $meta_time($page_published_time ?? null);
$page_modified_time = $meta_time($page_modified_time ?? null);
$page_rss_path = '';
if (isset($page_rss_url) && is_string($page_rss_url) && trim($page_rss_url) !== '') {
    $page_rss_path = trim($page_rss_url);
    if (str_starts_with($page_rss_path, '../')) $page_rss_path = substr($page_rss_path, 3);
} elseif (str_starts_with($current, 'sosyal/')) {
    $page_rss_path = 'sosyal/rss.php';
}
$page_rss_href = $page_rss_path !== '' && function_exists('dg_absolute_url') ? dg_absolute_url($page_rss_path) : $page_rss_path;

$cart_total_count = function_exists('cart_count') ? cart_count() : 0;

// D-Coin hesabı eski menünün içinde gösterilir. db.php yüklenmeden açılan
// eski sayfalarda da oturumdaki güncel bakiye bilgisi yeterli olur.
$dg_auth_version = defined('DG_AUTH_VERSION') ? (int)DG_AUTH_VERSION : 3;
$dg_logged = !empty($_SESSION['user_id'])
    && (int)($_SESSION['dg_auth_v'] ?? 0) === $dg_auth_version;
$dg_username = $dg_logged ? (string)($_SESSION['username'] ?? 'Hesabım') : '';
$dg_balance = $dg_logged ? (int)($_SESSION['dcoin_balance'] ?? 0) : 0;
$dg_is_admin = $dg_logged && (int)($_SESSION['is_admin'] ?? 0) === 1;

function nav_link($file, $label, $current, $root = '') {
    $active = ($current === $file) ? ' aria-current="page"' : '';
    echo '<a href="' . htmlspecialchars($root . $file, ENT_QUOTES, 'UTF-8') . '" class="nav-item"' . $active . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($full_title); ?></title>
    <meta name="description" content="<?php echo e($page_meta_desc); ?>">
    <?php if ($page_robots !== ''): ?><meta name="robots" content="<?php echo e($page_robots); ?>"><?php endif; ?>
    <?php if ($canonical_url !== ''): ?><link rel="canonical" href="<?php echo e($canonical_url); ?>"><?php endif; ?>
    <?php if ($page_rss_href !== ''): ?><link rel="alternate" type="application/rss+xml" title="DG Social RSS" href="<?php echo e($page_rss_href); ?>"><?php endif; ?>
    <meta name="theme-color" content="#120b24">
    <meta property="og:site_name" content="<?php echo e($company_name); ?>">
    <meta property="og:locale" content="tr_TR">
    <meta property="og:type" content="<?php echo e($page_og_type); ?>">
    <?php if ($canonical_url !== ''): ?><meta property="og:url" content="<?php echo e($canonical_url); ?>"><?php endif; ?>
    <meta property="og:title" content="<?php echo e($page_og_title); ?>">
    <meta property="og:description" content="<?php echo e($page_og_desc); ?>">
    <?php if ($page_og_image_url !== ''): ?>
        <meta property="og:image" content="<?php echo e($page_og_image_url); ?>">
        <meta property="og:image:alt" content="<?php echo e($page_og_image_alt); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?php echo e($page_twitter_card); ?>">
    <meta name="twitter:title" content="<?php echo e($page_og_title); ?>">
    <meta name="twitter:description" content="<?php echo e($page_og_desc); ?>">
    <?php if ($page_og_image_url !== ''): ?>
        <meta name="twitter:image" content="<?php echo e($page_og_image_url); ?>">
        <meta name="twitter:image:alt" content="<?php echo e($page_og_image_alt); ?>">
    <?php endif; ?>
    <?php if ($page_og_type === 'article'): ?>
        <?php if ($page_published_time !== ''): ?><meta property="article:published_time" content="<?php echo e($page_published_time); ?>"><?php endif; ?>
        <?php if ($page_modified_time !== ''): ?><meta property="article:modified_time" content="<?php echo e($page_modified_time); ?>"><?php endif; ?>
        <?php if (!empty($page_article_author)): ?><meta property="article:author" content="<?php echo e($page_article_author); ?>"><?php endif; ?>
        <?php if (!empty($page_article_section)): ?><meta property="article:section" content="<?php echo e($page_article_section); ?>"><?php endif; ?>
        <?php foreach ((array)($page_article_tags ?? []) as $article_tag): ?><meta property="article:tag" content="<?php echo e($article_tag); ?>"><?php endforeach; ?>
    <?php endif; ?>
    <script src="<?php echo e($root); ?>assets/js/scroll-restore.js" defer></script>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g'%3E%3Cstop stop-color='%23b983ff'/%3E%3Cstop offset='.55' stop-color='%23ffe67a'/%3E%3Cstop offset='1' stop-color='%23b8f46d'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='9' fill='%23120b24'/%3E%3Cpath d='M8 16h16M16 8v16' stroke='url(%23g)' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?php echo e($root); ?>style.css">
    <?php if (!empty($page_css)): ?>
        <?php foreach ((array)$page_css as $css): ?>
            <link rel="stylesheet" href="<?php echo e($root . $css); ?>">
            <?php if ($css === 'social.css'): ?><link rel="stylesheet" href="<?php echo e($root . 'social-forum.css'); ?>"><?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo e($root); ?>assets/css/colorful-theme.css">
</head>
<body class="<?php echo e($body_class ?? ''); ?>">
<a class="skip-link" href="#main">İçeriğe geç</a>

<header id="site-nav">
    <div class="nav-container">
        <a href="<?php echo e($root); ?>index.php" class="brand-logo">
<img src="https://pbs.twimg.com/profile_images/2112837103956414464/0-_JmQF__400x400.jpg" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">
            <span><?php echo e($company_name); ?><span class="dot">.</span></span>
        </a>
        <nav class="nav-links" aria-label="Ana menü">
            <?php nav_link('index.php', 'Ana sayfa', $current, $root); ?>
            <?php nav_link('shop.php', 'Mağaza', $current, $root); ?>
            <?php nav_link('mini-oyun.php', 'Mini oyun', $current, $root); ?>
            <a href="<?php echo e($root); ?>index.php#dcoin" class="nav-item">D-Coin</a>
            <?php nav_link('blog/index.php', 'Blog', $current, $root); ?>
            <?php nav_link('sosyal/index.php', 'Sosyal', $current, $root); ?>
            <?php nav_link('about.php', 'Vizyonumuz', $current, $root); ?>
            <?php nav_link('contact.php', 'İletişim', $current, $root); ?>
            <?php if ($dg_logged): ?>
                <?php if ($dg_is_admin): ?>
                    <a href="<?php echo e($root); ?>admin/index.php" class="nav-item">Yönetim</a>
                <?php endif; ?>
                <span class="nav-account">
                    <span class="nav-user"><?php echo e($dg_username); ?></span>
                    <span class="nav-balance"><span data-live-balance><?php echo number_format($dg_balance, 0, ',', '.'); ?></span> D-Coin</span>
                </span>
                <a href="<?php echo e($root); ?>logout.php" class="nav-item">Çıkış</a>
            <?php else: ?>
                <a href="<?php echo e($root); ?>login.php" class="nav-item nav-login">Giriş</a>
                <a href="<?php echo e($root); ?>register.php" class="nav-item nav-register">Kayıt ol</a>
            <?php endif; ?>
        </nav>
        <a href="<?php echo e($root); ?>cart.php" class="nav-cta nav-cart<?php echo $current === 'cart.php' ? ' is-current' : ''; ?>">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
            Sepet
            <span class="nav-cart-count" data-cart-count<?php echo $cart_total_count > 0 ? '' : ' hidden'; ?>><?php echo (int)$cart_total_count; ?></span>
        </a>
    </div>
</header>

<?php if (!empty($_SESSION['flash'])): ?>
    <?php
        $flash_value = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $flash_text = is_array($flash_value)
            ? (string)($flash_value['msg'] ?? '')
            : (string)$flash_value;
    ?>
    <div class="flash" role="status" data-flash>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
        <?php echo e($flash_text); ?>
    </div>
<?php endif; ?>

<main id="main">
