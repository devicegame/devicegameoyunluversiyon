<?php
require_once __DIR__ . '/../config.php';

$root = '../';
$slug = (string)($_GET['slug'] ?? '');
$post = blog_post($slug);

if (!$post) {
    http_response_code(404);
    $page_title = 'Yazı bulunamadı';
    $page_canonical = false;
    $page_robots = 'noindex,nofollow';
    $page_css = ['shop.css', 'blog.css'];
    $body_class = 'shop-page';
    include __DIR__ . '/../header.php';
    ?>
    <section class="order-done">
        <p class="eyebrow" style="justify-content:center;">404</p>
        <h1>Bu yazıyı bulamadık.</h1>
        <p>Yayından kaldırılmış ya da adresi değişmiş olabilir. Blog'a dönüp diğer yazılara göz atabilirsin.</p>
        <a class="btn btn-primary" href="index.php">Blog'a dön</a>
    </section>
    <?php
    include __DIR__ . '/../footer.php';
    exit;
}

$postCategory = blog_post_category($post);
$page_title = $post['title'];
$page_meta_desc = $post['excerpt'] !== '' ? $post['excerpt'] : mb_substr(strip_tags($post['content']), 0, 155);
$page_canonical = 'blog/yazi.php?slug=' . rawurlencode((string)$post['slug']);
$page_og_type = 'article';
$page_og_title = (string)$post['title'];
$page_og_desc = $page_meta_desc;
$page_og_image = !empty($post['image']) ? (string)$post['image'] : 'assets/img/devicegame-og.png';
$page_og_image_alt = (string)$post['title'];
$page_published_time = (string)$post['date'];
$page_modified_time = (string)$post['date'];
$page_article_section = (string)$postCategory['name'];
$page_article_tags = array_filter([(string)($post['tag'] ?? '')]);
$page_css = ['shop.css', 'blog.css'];
$body_class = 'shop-page';
include __DIR__ . '/../header.php';
$sameCategory = [];
$remainingPosts = [];
foreach (blog_posts() as $item) {
    if ($item['slug'] === $post['slug']) {
        continue;
    }
    if (($item['category'] ?? '') === $postCategory['slug']) {
        $sameCategory[] = $item;
    } else {
        $remainingPosts[] = $item;
    }
}
$others = array_slice(array_merge($sameCategory, $remainingPosts), 0, 3);
?>

<section class="shop-section blog-page">
    <nav class="breadcrumb blog-breadcrumb" aria-label="Konum">
        <a href="<?php echo e($root); ?>index.php">Ana sayfa</a>
        <span class="sep">/</span>
        <a href="index.php">Blog</a>
        <span class="sep">/</span>
        <a href="index.php?kategori=<?php echo urlencode($postCategory['slug']); ?>#blog-kategoriler"><?php echo e($postCategory['name']); ?></a>
        <span class="sep">/</span>
        <span class="muted"><?php echo e($post['title']); ?></span>
    </nav>

    <article class="post">
        <header class="post-head">
            <p class="blog-meta">
                <a class="post-tag" href="index.php?kategori=<?php echo urlencode($postCategory['slug']); ?>#blog-kategoriler"><?php echo e($postCategory['name']); ?></a>
                <?php if ($post['tag'] !== '' && blog_category_slugify((string)$post['tag']) !== $postCategory['slug'] && shop_fold((string)$post['tag']) !== shop_fold((string)$postCategory['name'])): ?>
                    <span class="dot-sep" aria-hidden="true">·</span>
                    <span><?php echo e($post['tag']); ?></span>
                <?php endif; ?>
                <span class="dot-sep" aria-hidden="true">·</span>
                <span><?php echo e(date('d.m.Y', strtotime($post['date']))); ?></span>
            </p>
            <h1><?php echo e($post['title']); ?></h1>
            <?php if ($post['excerpt'] !== ''): ?>
                <p class="post-lead"><?php echo e($post['excerpt']); ?></p>
            <?php endif; ?>
        </header>

        <div class="blog-cover post-cover" style="--c1:<?php echo e($post['c1']); ?>;--c2:<?php echo e($post['c2']); ?>">
            <?php if ($post['image'] !== ''): ?>
                <img class="blog-cover-img" src="<?php echo e(blog_image_url($post['image'])); ?>" alt="">
            <?php endif; ?>
            <span class="blog-cover-mark" aria-hidden="true">DG</span>
        </div>

        <div class="post-body">
            <?php echo blog_render($post['content']); ?>
        </div>

        <footer class="post-foot">
            <a class="btn btn-ghost" href="index.php?kategori=<?php echo urlencode($postCategory['slug']); ?>#blog-kategoriler"><?php echo e($postCategory['name']); ?> yazıları</a>
            <a class="btn btn-ghost" href="index.php">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                Tüm yazılar
            </a>
            <a class="btn btn-primary" href="<?php echo e(wa_link($whatsapp, 'Merhaba! Blog yazınız hakkında bir sorum var.')); ?>" target="_blank" rel="noopener">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Z"/></svg>
                Soru sor
            </a>
        </footer>
    </article>

    <?php if ($others): ?>
        <aside class="blog-more" aria-labelledby="blog-more-title">
            <div class="shop-section-head" style="margin-bottom:1.75rem;">
                <div>
                    <p class="eyebrow">Devamı</p>
                    <h2 id="blog-more-title"><?php echo $sameCategory ? e($postCategory['name']) . ' kategorisinden.' : 'Diğer yazılar.'; ?></h2>
                </div>
                <a class="text-link" href="<?php echo $sameCategory ? 'index.php?kategori=' . urlencode($postCategory['slug']) . '#blog-kategoriler' : 'index.php'; ?>">Tümünü gör</a>
            </div>
            <div class="blog-grid">
                <?php foreach ($others as $item): ?>
                    <a class="blog-card" href="yazi.php?slug=<?php echo urlencode($item['slug']); ?>">
                        <div class="blog-cover" style="--c1:<?php echo e($item['c1']); ?>;--c2:<?php echo e($item['c2']); ?>">
                            <?php if ($item['image'] !== ''): ?>
                                <img class="blog-cover-img" src="<?php echo e(blog_image_url($item['image'])); ?>" alt="" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <span class="blog-cover-tag"><?php echo e($item['category_name'] ?? 'Genel'); ?></span>
                        </div>
                        <div class="blog-card-body">
                            <p class="blog-meta">
                                <span><?php echo e(date('d.m.Y', strtotime($item['date']))); ?></span>
                                <span class="dot-sep" aria-hidden="true">·</span>
                                <span><?php echo e($item['category_name'] ?? 'Genel'); ?></span>
                            </p>
                            <h3><?php echo e($item['title']); ?></h3>
                            <p class="muted"><?php echo e($item['excerpt']); ?></p>
                            <span class="text-link">Devamını oku</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../footer.php'; ?>
