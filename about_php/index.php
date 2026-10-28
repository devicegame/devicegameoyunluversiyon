<?php
$page_title = '';
include 'header.php';
?>

<section class="hero" aria-labelledby="hero-title">
    <div class="hero-body">
        <h1 id="hero-title" class="hero-title seq" style="--d:.05s"><?php echo e($slogan); ?></h1>
        <p class="hero-desc seq" style="--d:.2s">Oyunlar yapıyor, oyuncular için seçip getiriyoruz. Stüdyomuzda yeni evrenler kuruyor, mağazamızda dijital kütüphaneni büyütüyoruz.</p>
        <div class="hero-actions seq" style="--d:.35s">
            <a href="<?php echo e($shop_url); ?>" class="btn btn-primary" target="_blank" rel="noopener">Mağazaya gir</a>
            <a href="<?php echo e($studio_url); ?>" class="btn btn-ghost" target="_blank" rel="noopener">Stüdyoyu keşfet</a>
        </div>
    </div>
    <div class="hero-strip seq" style="--d:.55s">
        <a href="#nexus" class="strip-item"><span class="pulse" aria-hidden="true"></span><?php echo e($featured_project_name); ?> &middot; <?php echo e($featured_project_status); ?></a>
        <span class="strip-item strip-place"><?php echo e($city); ?></span>
    </div>
</section>

<section class="ecosystem" aria-label="Stüdyo ve mağaza">
    <article class="pane pane-studio">
        <div class="pane-content">
            <h2>Stüdyo</h2>
            <p>Sınırları zorlayan hikayeler ve yeni nesil grafiklerle örülmüş evrenler inşa ediyoruz.</p>
            <a href="<?php echo e($studio_url); ?>" class="btn btn-primary" target="_blank" rel="noopener">Oyunları keşfet</a>
        </div>
    </article>
    <article class="pane pane-shop">
        <div class="pane-content">
            <h2>Mağaza</h2>
            <p>Binlerce oyun, premium hesaplar ve efsanevi indirimler. Dijital kütüphaneni genişlet.</p>
            <a href="<?php echo e($shop_url); ?>" class="btn btn-dark" target="_blank" rel="noopener">Mağazaya gir</a>
        </div>
    </article>
</section>

<section class="nexus" id="nexus" aria-labelledby="nexus-title">
    <div class="nexus-inner">
        <p class="status"><span class="pulse" aria-hidden="true"></span><?php echo e($featured_project_status); ?></p>
        <div class="nexus-main">
            <h2 id="nexus-title" class="nexus-title"><?php echo e($featured_project_name); ?></h2>
            <p class="nexus-desc"><?php echo e($featured_project_desc); ?></p>
            <a href="<?php echo e($studio_url); ?>" class="text-link" target="_blank" rel="noopener">Geliştirici günlüklerini oku</a>
        </div>
    </div>
</section>

<?php include 'footer.php'; ?>
