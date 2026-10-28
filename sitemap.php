<?php
/** Dinamik XML sitemap — mağaza, blog ve DG Social herkese açık sayfaları. */
declare(strict_types=1);

require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/lib/social.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$seen = [];
$urlCount = 0;
$maxUrls = 49500;

$formatLastmod = static function ($value): string {
    if ($value === null || $value === '') return '';
    $timestamp = is_numeric($value) ? (int)$value : (strtotime((string)$value) ?: 0);
    return $timestamp > 0 ? date(DATE_ATOM, $timestamp) : '';
};

$addUrl = static function (string $path, $lastmod = null, string $changefreq = 'weekly', string $priority = '0.5', string $image = '') use (&$seen, &$urlCount, $maxUrls, $xml, $formatLastmod): bool {
    if ($urlCount >= $maxUrls) return false;
    $loc = dg_absolute_url($path);
    if (isset($seen[$loc])) return true;
    $seen[$loc] = true;
    $urlCount++;

    echo "  <url>\n";
    echo '    <loc>' . $xml($loc) . "</loc>\n";
    $lastmodText = $formatLastmod($lastmod);
    if ($lastmodText !== '') echo '    <lastmod>' . $xml($lastmodText) . "</lastmod>\n";
    echo '    <changefreq>' . $xml($changefreq) . "</changefreq>\n";
    echo '    <priority>' . $xml($priority) . "</priority>\n";
    $image = trim($image);
    if ($image !== '') {
        if (str_starts_with($image, '../')) $image = substr($image, 3);
        $imageLoc = preg_match('~^https?://~i', $image) ? $image : dg_absolute_url(ltrim($image, '/'));
        echo '    <image:image><image:loc>' . $xml($imageLoc) . "</image:loc></image:image>\n";
    }
    echo "  </url>\n";
    return true;
};

$fileTime = static function (string $relative): int {
    $path = __DIR__ . '/' . ltrim($relative, '/');
    return is_file($path) ? (int)@filemtime($path) : 0;
};

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

// Ana sayfalar
$addUrl('', $fileTime('index.php'), 'daily', '1.0');
$addUrl('shop.php', max($fileTime('shop.php'), is_file(shop_products_file()) ? (int)@filemtime(shop_products_file()) : 0), 'daily', '0.9');
$addUrl('mini-oyun.php', $fileTime('mini-oyun.php'), 'weekly', '0.8');
$addUrl('about.php', $fileTime('about.php'), 'monthly', '0.5');
$addUrl('contact.php', $fileTime('contact.php'), 'monthly', '0.5');

// Mağaza kategorileri ve ürünler
$products = function_exists('shop_products') ? shop_products() : [];
$productTime = is_file(shop_products_file()) ? (int)@filemtime(shop_products_file()) : 0;
foreach (shop_categories() as $categorySlug => $label) {
    $addUrl('shop.php?kategori=' . rawurlencode((string)$categorySlug), $productTime, 'weekly', '0.7');
}
foreach ($products as $product) {
    if (!is_array($product) || empty($product['slug'])) continue;
    $addUrl(
        'product.php?slug=' . rawurlencode((string)$product['slug']),
        $product['added_at'] ?? $productTime,
        'weekly',
        '0.8',
        (string)($product['image'] ?? '')
    );
}

// Blog ana sayfası, kategorileri ve yazıları
$blogPosts = function_exists('blog_posts') ? blog_posts(true) : [];
$latestBlogTime = 0;
$categoryTimes = [];
foreach ($blogPosts as $post) {
    $timestamp = strtotime((string)($post['date'] ?? '')) ?: 0;
    $latestBlogTime = max($latestBlogTime, $timestamp);
    $category = (string)($post['category'] ?? 'genel');
    $categoryTimes[$category] = max((int)($categoryTimes[$category] ?? 0), $timestamp);
}
$addUrl('blog/index.php', $latestBlogTime ?: $fileTime('blog/index.php'), 'weekly', '0.7');
if (function_exists('blog_category_summaries')) {
    foreach (blog_category_summaries(true) as $category) {
        if ((int)($category['published_count'] ?? 0) <= 0) continue;
        $slug = (string)$category['slug'];
        $addUrl('blog/index.php?kategori=' . rawurlencode($slug), $categoryTimes[$slug] ?? $latestBlogTime, 'weekly', '0.6');
    }
}
foreach ($blogPosts as $post) {
    if (!is_array($post) || empty($post['slug'])) continue;
    $addUrl(
        'blog/yazi.php?slug=' . rawurlencode((string)$post['slug']),
        $post['date'] ?? null,
        'monthly',
        '0.7',
        (string)($post['image'] ?? '')
    );
}

// DG Social: yalnızca yayınlanmış gönderi ve içerikli profiller indekslenir.
try {
    $socialPosts = social_sitemap_posts(45000);
    $socialProfiles = social_sitemap_profiles(5000);
    $socialTopics = social_topics(true);
    $latestSocialTime = 0;
    $topicTimes = [];
    foreach ($socialPosts as $post) {
        $timestamp = max((int)($post['updated_at'] ?? 0), (int)($post['last_activity_at'] ?? 0));
        $latestSocialTime = max($latestSocialTime, $timestamp);
        $topic = (string)($post['topic_slug'] ?? '');
        if ($topic !== '') $topicTimes[$topic] = max((int)($topicTimes[$topic] ?? 0), $timestamp);
    }

    $addUrl('sosyal/index.php', $latestSocialTime ?: $fileTime('sosyal/index.php'), 'daily', '0.8');
    $addUrl('sosyal/konular.php', $latestSocialTime ?: $fileTime('sosyal/konular.php'), 'daily', '0.8');
    $addUrl('sosyal/uyeler.php', $fileTime('sosyal/uyeler.php'), 'weekly', '0.6');
    $addUrl('sosyal/etkinlikler.php', $topicTimes['etkinlikler'] ?? $latestSocialTime, 'daily', '0.7');
    $addUrl('sosyal/kurallar.php', $fileTime('sosyal/kurallar.php'), 'yearly', '0.4');
    foreach ($socialTopics as $topic) {
        $slug = (string)($topic['slug'] ?? '');
        if ($slug === '') continue;
        $addUrl('sosyal/index.php?konu=' . rawurlencode($slug), $topicTimes[$slug] ?? $latestSocialTime, 'daily', '0.7');
    }
    foreach ($socialProfiles as $profile) {
        if (empty($profile['username'])) continue;
        $addUrl(
            'sosyal/profil.php?kullanici=' . rawurlencode((string)$profile['username']),
            $profile['updated_at'] ?? null,
            'weekly',
            !empty($profile['is_official']) ? '0.6' : '0.5'
        );
    }
    foreach ($socialPosts as $post) {
        if (!$addUrl(
            'sosyal/gonderi.php?id=' . (int)($post['id'] ?? 0),
            max((int)($post['updated_at'] ?? 0), (int)($post['last_activity_at'] ?? 0)),
            'weekly',
            !empty($post['pinned']) || !empty($post['award']) ? '0.7' : '0.6',
            (string)($post['image'] ?? '')
        )) {
            break;
        }
    }
} catch (Throwable $exception) {
    error_log('[sitemap social] ' . $exception->getMessage());
}

echo '</urlset>' . "\n";
