<?php
$page_title = 'Vizyonumuz';
$page_canonical = 'about.php';
$page_og_type = 'website';
$page_og_title = 'Vizyonumuz';
include 'header.php';
?>

<section class="page-head">
    <h1 class="page-title seq" style="--d:.05s">Biz kimiz?</h1>
</section>

<section class="story">
    <div class="story-inner">
        <h2 class="story-heading">Oyunun kurallarını değiştiriyoruz.</h2>
        <div class="story-body">
            <p class="lead"><strong><?php echo e($company_name); ?></strong>, tutkulu oyuncular ve vizyoner geliştiricilerden oluşan global bir güçtür.</p>

            <div class="story-block">
                <h3>Stüdyo</h3>
                <p>Sanat ve kodu birleştirerek, oyuncuların içinde yaşamaktan keyif alacağı AAA kalitesinde evrenler tasarlıyoruz.</p>
                <a href="<?php echo e($studio_url); ?>" class="text-link" target="_blank" rel="noopener">Stüdyoyu keşfet</a>
            </div>

            <div class="story-block">
                <h3>Mağaza</h3>
                <p>Oyun dünyasının devasa kütüphanesini, güvenilir ve anında teslimat altyapımızla en uygun şartlarda oyunculara sunuyoruz.</p>
                <a href="shop.php" class="text-link">Mağazaya git</a>
            </div>
        </div>
    </div>
</section>

<?php include 'footer.php'; ?>
