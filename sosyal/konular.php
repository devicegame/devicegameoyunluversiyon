<?php
require_once __DIR__ . '/_common.php';

$boardTopics = social_topic_board_stats($socialUser);
$groupedTopics = [];
foreach ($boardTopics as $topic) {
    $group = trim((string)($topic['group'] ?? '')) ?: 'Topluluk';
    $groupedTopics[$group][] = $topic;
}
$stats = social_stats(false);
$latestPosts = social_list_posts(['sort' => 'activity', 'per_page' => 6], $socialUser)['items'];
$onlineUsers = social_online_users($socialUser, 300, 12);
$newestMembers = social_member_directory(['sort' => 'newest', 'limit' => 5], $socialUser);
$topMembers = social_member_directory(['sort' => 'active', 'limit' => 5], $socialUser);
$watches = $socialUser ? social_watches((int)$socialUser['id']) : ['muted_tags' => []];
$mutedTags = (array)($watches['muted_tags'] ?? []);

$page_title = 'Forum Panosu — DG Social';
$page_meta_desc = 'DeviceGame forumu: oyun sohbeti, rehberler, yardım, oyun arkadaşları, etkinlikler ve stüdyo duyuruları.';
$page_canonical = 'sosyal/konular.php';
$page_og_type = 'website';
$page_og_title = $page_title;
$page_og_desc = $page_meta_desc;
$page_rss_url = 'sosyal/rss.php';
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <header class="social-page-head">
        <p class="eyebrow">DG Social · Forum</p>
        <h1>Forum panosu.</h1>
        <p class="muted">Kısa anlık sohbetler canlı kanallarda, kalıcı sorular ve rehberler burdaki konu başlıklarında. İstediğin kategoriyi takip edebilir veya akışında sessize alabilirsin.</p>
    </header>

    <?= social_subnav('boards') ?>
    <?= social_flash_html() ?>

    <div class="social-board-layout">
        <div class="social-main">
            <?php foreach ($groupedTopics as $group => $topics): ?>
                <section class="social-board-group" aria-labelledby="grup-<?= e(social_slugify($group)) ?>">
                    <div class="social-section-title"><h2 id="grup-<?= e(social_slugify($group)) ?>"><?= e($group) ?></h2><span><?= count($topics) ?> kategori</span></div>
                    <div class="social-board-table" role="list">
                        <div class="social-board-head" aria-hidden="true"><span>Kategori</span><span>Konu / Yorum</span><span>Son aktivite</span><span>Takip</span></div>
                        <?php foreach ($topics as $topic): ?>
                            <?php $latest = is_array($topic['latest_post'] ?? null) ? $topic['latest_post'] : null; ?>
                            <article class="social-board-row<?= !empty($topic['official_only']) ? ' is-official' : '' ?><?= !empty($topic['active']) ? '' : ' is-inactive' ?>" role="listitem">
                                <div class="social-board-topic">
                                    <span class="social-board-icon" aria-hidden="true"><?= e($topic['icon']) ?></span>
                                    <div>
                                        <h3><a href="<?= e(social_feed_url(['konu' => (string)$topic['slug']])) ?>"><?= e($topic['name']) ?></a></h3>
                                        <p><?= e($topic['description'] ?: 'Topluluk tartışmaları') ?></p>
                                        <div class="social-board-badges">
                                            <?php if (!empty($topic['official_only'])): ?><span class="social-mini-badge">Yalnızca ekip açabilir</span><?php endif; ?>
                                            <?php if ((int)($topic['unread_count'] ?? 0) > 0): ?><span class="social-mini-badge is-unread"><?= (int)$topic['unread_count'] ?> yeni</span><?php endif; ?>
                                            <?php if (!empty($topic['is_muted'])): ?><span class="social-mini-badge is-warning">Akışta sessize alındı</span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="social-board-counts" aria-label="Kategori sayıları">
                                    <strong><?= number_format((int)$topic['post_count'], 0, ',', '.') ?></strong><small>konu</small>
                                    <strong><?= number_format((int)$topic['comment_count'], 0, ',', '.') ?></strong><small>yorum</small>
                                    <small><?= number_format((int)$topic['view_count'], 0, ',', '.') ?> görüntülenme</small>
                                </div>
                                <div class="social-board-latest">
                                    <?php if ($latest): ?>
                                        <a href="<?= e(social_post_url((int)$latest['id'])) ?>"><?= e($latest['title']) ?></a>
                                        <small><?= e($latest['author_display']) ?> · <?= e(social_time_ago((int)$latest['last_activity_at'])) ?></small>
                                    <?php else: ?>
                                        <span class="muted">Henüz konu yok.</span>
                                        <?php if ($socialUser && (empty($topic['official_only']) || !empty($socialUser['is_admin']))): ?><a class="text-link" href="yeni.php?konu=<?= rawurlencode((string)$topic['slug']) ?>">İlk konuyu aç</a><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="social-board-actions">
                                    <a class="btn btn-ghost social-small-btn" href="<?= e(social_feed_url(['konu' => (string)$topic['slug']])) ?>">Konuları gör</a>
                                    <?php if ($socialUser): ?>
                                        <form method="post" action="aksiyon.php" data-social-ajax="topic-watch">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="islem" value="konu_takip">
                                            <input type="hidden" name="topic_slug" value="<?= e($topic['slug']) ?>">
                                            <input type="hidden" name="return" value="konular.php">
                                            <button class="social-action<?= !empty($topic['is_watched']) ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= !empty($topic['is_watched']) ? 'true' : 'false' ?>" data-label-active="Takip ediliyor" data-label-inactive="Takip et"><?= !empty($topic['is_watched']) ? 'Takip ediliyor' : 'Takip et' ?></button>
                                        </form>
                                        <form method="post" action="aksiyon.php" data-social-ajax="topic-mute">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="islem" value="konu_sessiz">
                                            <input type="hidden" name="topic_slug" value="<?= e($topic['slug']) ?>">
                                            <input type="hidden" name="return" value="konular.php">
                                            <button class="social-action<?= !empty($topic['is_muted']) ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= !empty($topic['is_muted']) ? 'true' : 'false' ?>" data-label-active="Sessizi kaldır" data-label-inactive="Sessize al"><?= !empty($topic['is_muted']) ? 'Sessizi kaldır' : 'Sessize al' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="social-text-button" href="rss.php?konu=<?= rawurlencode((string)$topic['slug']) ?>">RSS</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($socialUser): ?>
                <section class="social-panel social-muted-tags" id="sessiz-etiketler">
                    <div class="social-panel-head"><h2>Sessize alınan etiketler</h2><span><?= count($mutedTags) ?></span></div>
                    <p class="muted">Sessize aldığın etiketleri taşıyan konular keşif akışında gösterilmez; doğrudan bağlantıyla yine açabilirsin.</p>
                    <?php if ($mutedTags): ?>
                        <div class="social-tags">
                            <?php foreach ($mutedTags as $tag): ?><span>#<?= e($tag) ?></span><?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="muted">Henüz sessize alınmış etiket yok.</p><?php endif; ?>
                    <form method="post" action="aksiyon.php" class="social-inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="etiket_sessiz">
                        <input type="hidden" name="return" value="konular.php#sessiz-etiketler">
                        <label for="muted-tag">Etiket</label>
                        <input id="muted-tag" type="text" name="tag" maxlength="40" placeholder="spoiler">
                        <button class="btn btn-ghost social-small-btn" type="submit">Sessiz durumunu değiştir</button>
                    </form>
                </section>
            <?php endif; ?>
        </div>

        <aside class="social-sidebar social-sidebar-right">
            <section class="social-panel social-forum-stats" id="forum-ozeti">
                <h2>Forum özeti</h2>
                <dl>
                    <div><dt>Konu</dt><dd><?= number_format((int)$stats['posts'], 0, ',', '.') ?></dd></div>
                    <div><dt>Yorum</dt><dd><?= number_format((int)($stats['comments'] ?? 0), 0, ',', '.') ?></dd></div>
                    <div><dt>Görüntülenme</dt><dd><?= number_format((int)($stats['views'] ?? 0), 0, ',', '.') ?></dd></div>
                    <div><dt>Çözüm</dt><dd><?= number_format((int)($stats['solutions'] ?? 0), 0, ',', '.') ?></dd></div>
                    <div><dt>Üye</dt><dd><?= number_format((int)$stats['members'], 0, ',', '.') ?></dd></div>
                    <div><dt>Çevrimiçi</dt><dd><?= (int)($stats['online'] ?? 0) ?></dd></div>
                </dl>
                <?php if ($socialUser): ?>
                    <form method="post" action="aksiyon.php" data-social-ajax="mark-read">
                        <?= csrf_field() ?><input type="hidden" name="islem" value="okundu_tumu"><input type="hidden" name="return" value="konular.php#forum-ozeti">
                        <button class="btn btn-ghost btn-full" type="submit">Tüm forumu okundu işaretle</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="social-panel">
                <div class="social-panel-head"><h2>Son hareketler</h2><a href="index.php">Akış</a></div>
                <div class="social-related-links">
                    <?php foreach ($latestPosts as $latestPost): ?>
                        <a href="<?= e(social_post_url((int)$latestPost['id'])) ?>"><strong><?= e($latestPost['title']) ?></strong><small><?= e($socialTopicMap[(string)$latestPost['topic_slug']]['name'] ?? '') ?> · <?= e(social_time_ago((int)$latestPost['last_activity_at'])) ?> · <?= (int)$latestPost['comment_count'] ?> yorum</small></a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="social-panel">
                <div class="social-panel-head"><h2>Şu an çevrimiçi</h2><a href="sohbet.php">Sohbet</a></div>
                <?php if ($onlineUsers): ?>
                    <div class="social-user-list">
                        <?php foreach ($onlineUsers as $profile): ?><a href="<?= e(social_profile_url($profile)) ?>"><?= social_avatar_html($profile) ?><span><strong><?= e($profile['display_name']) ?></strong><small>@<?= e($profile['username']) ?></small></span></a><?php endforeach; ?>
                    </div>
                <?php else: ?><p class="muted">Görünür çevrimiçi üye yok.</p><?php endif; ?>
            </section>

            <?php
            $memberPanels = [
                ['title' => 'En yeni üyeler', 'items' => $newestMembers],
                ['title' => 'Aktif üyeler', 'items' => $topMembers],
            ];
            foreach ($memberPanels as $memberPanel): if ($memberPanel['items']):
            ?>
                <section class="social-panel">
                    <div class="social-panel-head"><h2><?= e($memberPanel['title']) ?></h2><a href="uyeler.php">Tümü</a></div>
                    <div class="social-user-list">
                        <?php foreach ($memberPanel['items'] as $profile): ?><a href="<?= e(social_profile_url($profile)) ?>"><?= social_avatar_html($profile) ?><span><strong><?= e($profile['display_name']) ?></strong><small>@<?= e($profile['username']) ?> · <?= (int)$profile['post_count'] ?> konu</small></span></a><?php endforeach; ?>
                    </div>
                </section>
            <?php endif; endforeach; ?>
        </aside>
    </div>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
