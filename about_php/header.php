<?php
include_once __DIR__ . '/config.php';

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$current = basename($_SERVER['SCRIPT_NAME']);
$full_title = isset($page_title) && $page_title !== ''
    ? $page_title . ' | ' . $company_name
    : $company_name . ' | ' . $slogan;

function nav_link($file, $label, $current) {
    $active = ($current === $file) ? ' aria-current="page"' : '';
    echo '<a href="' . $file . '" class="nav-item"' . $active . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($full_title); ?></title>
    <meta name="description" content="<?php echo e($meta_desc); ?>">
    <meta name="theme-color" content="#0A1626">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo e($full_title); ?>">
    <meta property="og:description" content="<?php echo e($meta_desc); ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230A1626'/%3E%3Ccircle cx='16' cy='16' r='6' fill='%234DA3FF'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<a class="skip-link" href="#main">İçeriğe geç</a>

<header id="site-nav">
    <div class="nav-container">
        <a href="index.php" class="brand-logo"><?php echo e($company_name); ?><span class="dot">.</span></a>
        <nav class="nav-links" aria-label="Ana menü">
            <?php nav_link('index.php', 'Ana sayfa', $current); ?>
            <?php nav_link('about.php', 'Vizyonumuz', $current); ?>
            <?php nav_link('contact.php', 'İletişim', $current); ?>
            <a href="<?php echo e($shop_url); ?>" class="nav-cta" target="_blank" rel="noopener">Mağaza</a>
        </nav>
    </div>
</header>

<main id="main">
