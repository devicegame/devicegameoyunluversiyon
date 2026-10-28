<?php
require_once __DIR__ . '/../config.php';

$root = '../';
$allPosts = blog_posts();
$categorySummaries = blog_category_summaries();
$activeCategorySlug = blog_category_slugify((string)($_GET['kategori'] ?? ''));
$activeCategory = null;
foreach ($categorySummaries as $category) {
    if ((string)$category['slug'] === $activeCategorySlug) {
        $activeCategory = $category;
        break;
    }
}
$posts = $activeCategorySlug === ''
    ? $allPosts
    : array_values(array_filter($allPosts, static fn(array $post): bool => ($post['category'] ?? '') === $activeCategorySlug));
$featured = $posts ? $posts[0] : null;
$rest = array_slice($posts, 1);

$page_title = $activeCategory ? $activeCategory['name'] . ' Yazıları' : 'Blog';
$page_meta_desc = $activeCategory && trim((string)$activeCategory['description']) !== ''
    ? trim((string)$activeCategory['description'])
    : 'Device Game haberleri, geliştirici günlükleri ve stüdyo notları. Project: Nexus yolculuğu ve mağaza duyuruları.';
$page_canonical = false;
if ($activeCategorySlug === '') {
    $page_canonical = 'blog/index.php';
} elseif ($activeCategory && (int)($activeCategory['published_count'] ?? 0) > 0) {
    $page_canonical = 'blog/index.php?kategori=' . rawurlencode($activeCategorySlug);
}
$page_robots = $activeCategorySlug !== '' && (!$activeCategory || (int)($activeCategory['published_count'] ?? 0) <= 0) ? 'noindex,follow' : '';
$page_og_type = 'website';
$page_og_title = $page_title;
$page_og_desc = $page_meta_desc;
$page_css = ['shop.css', 'blog.css'];
$body_class = 'shop-page';
include __DIR__ . '/../header.php';
?>

<section class="shop-section blog-page">
    <header class="blog-head">
        <p class="eyebrow"><?php echo $activeCategory ? 'Blog · Kategori' : 'Blog'; ?></p>
        <h1><?php echo $activeCategory ? e($activeCategory['name']) . ' yazıları.' : 'Haberler &amp; geliştirici günlükleri.'; ?></h1>
        <p class="muted"><?php echo e($activeCategory && trim((string)$activeCategory['description']) !== '' ? $activeCategory['description'] : 'Stüdyodan notlar, proje duyuruları ve perde arkası. Yeni yazılardan haberdar olmak için ara sıra uğra.'); ?></p>
        <?php if ($allPosts): ?>
            <nav class="chips blog-categories" id="blog-kategoriler" style="scroll-margin-top:7rem" aria-label="Blog kategorileri">
                <a class="chip" href="index.php#blog-kategoriler"<?php echo $activeCategorySlug === '' ? ' aria-current="true"' : ''; ?>>Tümü <small><?php echo count($allPosts); ?></small></a>
                <?php foreach ($categorySummaries as $category): ?>
                    <?php if ((int)$category['published_count'] > 0 || (string)$category['slug'] === $activeCategorySlug): ?>
                        <a class="chip" href="index.php?kategori=<?php echo urlencode((string)$category['slug']); ?>#blog-kategoriler"<?php echo (string)$category['slug'] === $activeCategorySlug ? ' aria-current="true"' : ''; ?>><?php echo e($category['name']); ?> <small><?php echo (int)$category['published_count']; ?></small></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>

    <?php if (!$posts): ?>
        <div class="empty-state">
            <?php if ($activeCategorySlug !== ''): ?>
                <h3>Bu kategoride henüz yayınlanmış yazı yok.</h3>
                <p>Başka bir kategoriye bakabilir veya tüm blog yazılarını görüntüleyebilirsin.</p>
                <a class="btn btn-primary" href="index.php#blog-kategoriler">Tüm yazıları göster</a>
            <?php else: ?>
                <h3>Henüz yazı yayınlanmadı.</h3>
                <p>İlk yazılar yakında burada olacak. O zamana kadar mağazaya göz atabilirsin.</p>
                <a class="btn btn-primary" href="<?php echo e($root); ?>shop.php">Mağazaya git</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <a class="blog-feature" href="yazi.php?slug=<?php echo urlencode($featured['slug']); ?>">
            <div class="blog-cover" style="--c1:<?php echo e($featured['c1']); ?>;--c2:<?php echo e($featured['c2']); ?>">
                <?php if ($featured['image'] !== ''): ?>
                    <img class="blog-cover-img" src="<?php echo e(blog_image_url($featured['image'])); ?>" alt="" loading="lazy" decoding="async">
                <?php endif; ?>
                <span class="blog-cover-tag"><?php echo e($featured['category_name'] ?? 'Genel'); ?></span>
                <span class="blog-cover-mark" aria-hidden="true">DG</span>
            </div>
            <div class="blog-feature-body">
                <p class="blog-meta">
                    <span><?php echo e(date('d.m.Y', strtotime($featured['date']))); ?></span>
                    <span class="dot-sep" aria-hidden="true">·</span>
                    <span><?php echo e($featured['category_name'] ?? 'Genel'); ?></span>
                    <?php if ($featured['tag'] !== '' && blog_category_slugify((string)$featured['tag']) !== (string)($featured['category'] ?? '') && shop_fold((string)$featured['tag']) !== shop_fold((string)($featured['category_name'] ?? ''))): ?>
                        <span class="dot-sep" aria-hidden="true">·</span>
                        <span><?php echo e($featured['tag']); ?></span>
                    <?php endif; ?>
                </p>
                <h2><?php echo e($featured['title']); ?></h2>
                <p class="muted"><?php echo e($featured['excerpt']); ?></p>
                <span class="text-link">
                    Yazıyı oku
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </span>
            </div>
        </a>

        <?php if ($rest): ?>
            <div class="blog-grid">
                <?php foreach ($rest as $post): ?>
                    <a class="blog-card" href="yazi.php?slug=<?php echo urlencode($post['slug']); ?>">
                        <div class="blog-cover" style="--c1:<?php echo e($post['c1']); ?>;--c2:<?php echo e($post['c2']); ?>">
                            <?php if ($post['image'] !== ''): ?>
                                <img class="blog-cover-img" src="<?php echo e(blog_image_url($post['image'])); ?>" alt="" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <span class="blog-cover-tag"><?php echo e($post['category_name'] ?? 'Genel'); ?></span>
                        </div>
                        <div class="blog-card-body">
                            <p class="blog-meta">
                                <span><?php echo e(date('d.m.Y', strtotime($post['date']))); ?></span>
                                <span class="dot-sep" aria-hidden="true">·</span>
                                <span><?php echo e($post['category_name'] ?? 'Genel'); ?></span>
                                <?php if ($post['tag'] !== '' && blog_category_slugify((string)$post['tag']) !== (string)($post['category'] ?? '') && shop_fold((string)$post['tag']) !== shop_fold((string)($post['category_name'] ?? ''))): ?>
                                    <span class="dot-sep" aria-hidden="true">·</span>
                                    <span><?php echo e($post['tag']); ?></span>
                                <?php endif; ?>
                            </p>
                            <h3><?php echo e($post['title']); ?></h3>
                            <p class="muted"><?php echo e($post['excerpt']); ?></p>
                            <span class="text-link">Devamını oku</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../footer.php'; ?>
