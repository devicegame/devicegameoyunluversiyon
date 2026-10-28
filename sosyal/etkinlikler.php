<?php
require_once __DIR__ . '/_common.php';

$eventsData = social_events($socialUser, 100);
$upcomingEvents = $eventsData['upcoming'];
$pastEvents = array_slice($eventsData['past'], 0, 12);
$eventStatesItems = array_merge($upcomingEvents, $pastEvents);
$socialStates = social_post_states(array_map(static fn(array $post): int => (int)$post['id'], $eventStatesItems), $socialUser);
$eventFilter = (string)($_GET['filtre'] ?? 'tumu');
if (!in_array($eventFilter, ['tumu', 'katildiklarim', 'benim'], true)) $eventFilter = 'tumu';
if ($socialUser && $eventFilter !== 'tumu') {
    $matchesEventFilter = static function (array $post) use ($socialStates, $socialUser, $eventFilter): bool {
        if ($eventFilter === 'benim') return (int)$post['user_id'] === (int)$socialUser['id'];
        return (int)$post['user_id'] === (int)$socialUser['id'] || !empty($socialStates[(int)$post['id']]['rsvp']);
    };
    $upcomingEvents = array_values(array_filter($upcomingEvents, $matchesEventFilter));
    $pastEvents = array_values(array_filter($pastEvents, $matchesEventFilter));
}
$totalRsvp = 0;
foreach ($upcomingEvents as $eventPost) $totalRsvp += (int)($eventPost['rsvp_count'] ?? 0);

$page_title = 'Etkinlikler — DG Social';
$page_meta_desc = 'DeviceGame topluluğunun yaklaşan oyun geceleri ve etkinlikleri. Bir etkinliğe katıl veya kendi oyun buluşmanı planla.';
$page_canonical = 'sosyal/etkinlikler.php';
$page_og_type = 'website';
$page_og_title = 'DG Social Etkinlikleri';
$page_og_desc = $page_meta_desc;
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <header class="social-hero">
        <div>
            <p class="eyebrow">DG Social · Etkinlikler</p>
            <h1>Birlikte oyun gecesi planlayalım.</h1>
            <p>Yaklaşan topluluk buluşmalarını gör, “Katılıyorum” diyerek yerini işaretle veya kendi etkinliğini aç. Etkinlik sahibi otomatik olarak katılımcılar arasında görünür.</p>
            <div class="social-hero-actions">
                <?php if ($socialUser): ?>
                    <a class="btn btn-primary social-small-btn" href="yeni.php?konu=etkinlikler">Etkinlik aç</a>
                <?php else: ?>
                    <a class="btn btn-primary social-small-btn" href="<?= e(social_login_url('sosyal/yeni.php?konu=etkinlikler')) ?>">Etkinlik açmak için giriş yap</a>
                <?php endif; ?>
                <a class="btn btn-ghost social-small-btn" href="sohbet.php?kanal=etkinlikler">Etkinlik sohbeti</a>
                <a class="btn btn-ghost social-small-btn" href="kurallar.php#etkinlik-anket">Etkinlik kuralları</a>
            </div>
        </div>
        <div class="social-hero-stats" aria-label="Etkinlik istatistikleri">
            <div><strong><?= count($upcomingEvents) ?></strong><span>yaklaşan</span></div>
            <div><strong><?= number_format($totalRsvp, 0, ',', '.') ?></strong><span>katılım</span></div>
            <div><strong><?= count($pastEvents) ?></strong><span>tamamlanan</span></div>
        </div>
    </header>

    <?= social_subnav('events') ?>
    <?= social_flash_html() ?>

    <nav class="social-event-tabs" aria-label="Etkinlik bölümleri">
        <a href="etkinlikler.php"<?= $eventFilter === 'tumu' ? ' aria-current="page"' : '' ?> data-preserve-scroll>Tüm etkinlikler</a>
        <?php if ($socialUser): ?>
            <a href="etkinlikler.php?filtre=katildiklarim"<?= $eventFilter === 'katildiklarim' ? ' aria-current="page"' : '' ?> data-preserve-scroll>Katıldıklarım</a>
            <a href="etkinlikler.php?filtre=benim"<?= $eventFilter === 'benim' ? ' aria-current="page"' : '' ?> data-preserve-scroll>Benim etkinliklerim</a>
        <?php endif; ?>
        <a href="#yaklasan-etkinlikler">Yaklaşan</a>
        <a href="#gecmis-etkinlikler">Geçmiş</a>
        <a href="<?= e(social_feed_url(['konu' => 'etkinlikler'])) ?>">Akışta göster</a>
        <a href="sohbet.php?kanal=etkinlikler">Canlı etkinlik sohbeti</a>
    </nav>

    <section id="yaklasan-etkinlikler" class="social-events-section">
        <div class="social-section-title">
            <h2>Yaklaşan etkinlikler</h2>
            <span><?= count($upcomingEvents) ?> etkinlik</span>
        </div>
        <?php if ($upcomingEvents): ?>
            <div class="social-feed">
                <?php foreach ($upcomingEvents as $post): ?>
                    <?php include __DIR__ . '/_post-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="social-empty">
                <h2>Henüz yaklaşan etkinlik yok.</h2>
                <p>İlk oyun gecesini planlayıp topluluğu bir araya getirebilirsin.</p>
                <?php if ($socialUser): ?>
                    <a class="btn btn-primary" href="yeni.php?konu=etkinlikler">Etkinlik aç</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="<?= e(social_login_url('sosyal/yeni.php?konu=etkinlikler')) ?>">Giriş yap ve planla</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="gecmis-etkinlikler" class="social-events-section">
        <div class="social-section-title">
            <h2>Geçmiş etkinlikler</h2>
            <span>Son <?= count($pastEvents) ?> kayıt</span>
        </div>
        <?php if ($pastEvents): ?>
            <div class="social-feed">
                <?php foreach ($pastEvents as $post): ?>
                    <?php include __DIR__ . '/_post-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="social-empty is-small">
                <h3>Tamamlanmış etkinlik yok.</h3>
                <p>Zamanı geçen etkinlikler burada arşivlenir.</p>
            </div>
        <?php endif; ?>
    </section>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
