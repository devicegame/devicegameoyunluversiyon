<?php
require_once __DIR__ . '/_common.php';

$query = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sirala'] ?? 'aktif');
$sortMap = [
    'aktif' => 'active',
    'yardimsever' => 'helpful',
    'takipci' => 'followers',
    'yeni' => 'newest',
    'odul' => 'awards',
    'cevrimici' => 'online',
];
if (!isset($sortMap[$sort])) $sort = 'aktif';
$onlineOnly = !empty($_GET['cevrimici']);
$members = social_member_directory([
    'q' => $query,
    'sort' => $sortMap[$sort],
    'online' => $onlineOnly,
    'limit' => 100,
], $socialUser);
$viewerFollows = $socialUser ? social_follows((int)$socialUser['id']) : ['following' => [], 'blocked' => []];
$onlineMembers = social_online_users($socialUser, 300, 8);
$helpfulMembers = social_member_directory(['sort' => 'helpful', 'limit' => 5], $socialUser);
$stats = social_stats(false);
$returnUrl = social_current_return_url();
$sortLabels = [
    'aktif' => 'En aktif',
    'yardimsever' => 'En yardımsever',
    'takipci' => 'Takipçi sayısı',
    'yeni' => 'En yeni üyeler',
    'odul' => 'Editör Ödülleri',
    'cevrimici' => 'Önce çevrimiçi',
];

$page_title = 'Üyeler — DG Social';
$page_meta_desc = 'DeviceGame topluluk üyelerini keşfet; oyuncuları takip et, canlı sohbete katıl ve özel mesaj gönder.';
$page_canonical = ($query === '' && !$onlineOnly && $sort === 'aktif') ? 'sosyal/uyeler.php' : false;
$page_robots = ($query !== '' || $onlineOnly) ? 'noindex,follow' : '';
$page_og_type = 'website';
$page_og_title = $page_title;
$page_og_desc = $page_meta_desc;
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <header class="social-page-head">
        <p class="eyebrow">DG Social · Topluluk</p>
        <h1>Oyuncular.</h1>
        <p class="muted">Aynı oyunları oynayan kişileri bul, yardımsever üyeleri takip et ve ekip arkadaşlarınla iletişim kur.</p>
    </header>

    <?= social_subnav('members') ?>
    <?= social_flash_html() ?>

    <div class="social-members-layout">
        <div class="social-main">
            <section class="social-panel social-member-tools" id="uye-arama">
                <form method="get" action="uyeler.php" class="social-member-search" role="search">
                    <div class="field">
                        <label for="member-q">Üye, platform veya favori oyun ara</label>
                        <input id="member-q" type="search" name="q" value="<?= e($query) ?>" placeholder="örn. PC, Valorant, kullanıcı adı">
                    </div>
                    <div class="field">
                        <label for="member-sort">Sıralama</label>
                        <select id="member-sort" name="sirala" onchange="this.form.submit()">
                            <?php foreach ($sortLabels as $value => $label): ?><option value="<?= e($value) ?>"<?= $sort === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <label class="social-check"><input type="checkbox" name="cevrimici" value="1"<?= $onlineOnly ? ' checked' : '' ?>> Yalnızca çevrimiçi üyeler</label>
                    <button class="btn btn-primary social-small-btn" type="submit">Ara</button>
                    <?php if ($query !== '' || $onlineOnly || $sort !== 'aktif'): ?><a class="btn btn-ghost social-small-btn" href="uyeler.php" data-preserve-scroll>Tüm üyeleri göster</a><?php endif; ?>
                </form>
                <p class="muted"><?= count($members) ?> üye gösteriliyor · <?= (int)($stats['online'] ?? 0) ?> kişi çevrimiçi. Aramayı daraltırsan daha eski veya az aktif üyeler de bulunabilir.</p>
            </section>

            <?php if ($members): ?>
                <div class="social-member-grid">
                    <?php foreach ($members as $profile): ?>
                        <?php
                        $profileId = (int)$profile['user_id'];
                        $isSelf = $socialUser && (int)$socialUser['id'] === $profileId;
                        $isFollowing = $socialUser && in_array($profileId, (array)$viewerFollows['following'], true);
                        $isBlocked = $socialUser && in_array($profileId, (array)$viewerFollows['blocked'], true);
                        $forumStats = (array)($profile['forum_stats'] ?? []);
                        $activityScore = (int)$profile['post_count'] * 5 + (int)$profile['comment_count'] * 2 + (int)($forumStats['solutions'] ?? 0) * 10 + (int)($forumStats['gg_received'] ?? 0);
                        $badges = social_profile_badges($profile, null);
                        ?>
                        <article class="social-panel social-member-card" id="uye-<?= $profileId ?>">
                            <a class="social-author is-member" href="<?= e(social_profile_url($profile)) ?>">
                                <?= social_avatar_html($profile, 'is-large') ?>
                                <span><strong><?= e($profile['display_name']) ?></strong><small>@<?= e($profile['username']) ?></small><small><?= e(social_last_seen_label($profile, $socialUser)) ?></small></span>
                            </a>
                            <?php if ($badges): ?><div class="social-badges"><?php foreach (array_slice($badges, 0, 3) as $badge): ?><span><?= e($badge) ?></span><?php endforeach; ?></div><?php endif; ?>
                            <p><?= trim((string)$profile['bio']) !== '' ? e(social_excerpt((string)$profile['bio'], 170)) : 'Henüz biyografi eklenmemiş.' ?></p>
                            <?php if (trim((string)($profile['signature'] ?? '')) !== ''): ?><div class="social-comment-signature"><?= social_render_text((string)$profile['signature']) ?></div><?php endif; ?>
                            <?php if (!empty($profile['platforms']) || !empty($profile['favorite_games'])): ?>
                                <div class="social-tags">
                                    <?php foreach (array_slice(array_merge((array)$profile['platforms'], (array)$profile['favorite_games']), 0, 6) as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <dl class="social-member-stats">
                                <div><dt>Konu</dt><dd><?= (int)$profile['post_count'] ?></dd></div>
                                <div><dt>Yorum</dt><dd><?= (int)$profile['comment_count'] ?></dd></div>
                                <div><dt>Çözüm</dt><dd><?= (int)($forumStats['solutions'] ?? 0) ?></dd></div>
                                <div><dt>GG</dt><dd><?= (int)($forumStats['gg_received'] ?? 0) ?></dd></div>
                                <div><dt>Takipçi</dt><dd><?= (int)$profile['follower_count'] ?></dd></div>
                                <div><dt>Aktivite</dt><dd><?= number_format($activityScore, 0, ',', '.') ?></dd></div>
                            </dl>
                            <?php if ((int)($forumStats['award_count'] ?? 0) > 0): ?><p class="social-member-award">🏆 <?= (int)$forumStats['award_count'] ?> Editör Ödülü · +<?= number_format((int)$forumStats['award_total'], 0, ',', '.') ?> D-Coin</p><?php endif; ?>
                            <div class="social-member-actions">
                                <a class="btn btn-ghost social-small-btn" href="<?= e(social_profile_url($profile)) ?>">Profil</a>
                                <?php if ($socialUser && !$isSelf && !$isBlocked): ?>
                                    <form method="post" action="aksiyon.php" data-social-ajax="follow">
                                        <?= csrf_field() ?><input type="hidden" name="islem" value="takip"><input type="hidden" name="target_id" value="<?= $profileId ?>"><input type="hidden" name="return" value="<?= e($returnUrl . '#uye-' . $profileId) ?>">
                                        <button class="btn <?= $isFollowing ? 'btn-ghost' : 'btn-primary' ?> social-small-btn" type="submit" aria-pressed="<?= $isFollowing ? 'true' : 'false' ?>"><?= $isFollowing ? 'Takip ediliyor' : 'Takip et' ?></button>
                                    </form>
                                    <a class="btn btn-ghost social-small-btn" href="<?= e(social_message_url($profile)) ?>">Mesaj</a>
                                <?php elseif ($isSelf): ?>
                                    <a class="btn btn-primary social-small-btn" href="<?= e(social_profile_url($profile)) ?>#profil-duzenle">Profilimi düzenle</a>
                                <?php elseif (!$socialUser): ?>
                                    <a class="btn btn-ghost social-small-btn" href="<?= e(social_login_url('sosyal/uyeler.php')) ?>">Takip için giriş yap</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="social-empty">
                    <h2>Üye bulunamadı.</h2>
                    <p>Farklı bir kullanıcı adı, platform veya oyun adı dene.</p>
                    <a class="btn btn-primary" href="uyeler.php" data-preserve-scroll>Aramayı temizle</a>
                </div>
            <?php endif; ?>
        </div>

        <aside class="social-sidebar social-sidebar-right">
            <section class="social-panel">
                <div class="social-panel-head"><h2>Şu an çevrimiçi</h2><span><?= count($onlineMembers) ?></span></div>
                <?php if ($onlineMembers): ?>
                    <div class="social-user-list">
                        <?php foreach ($onlineMembers as $profile): ?><a href="<?= e(social_profile_url($profile)) ?>"><?= social_avatar_html($profile) ?><span><strong><?= e($profile['display_name']) ?></strong><small>@<?= e($profile['username']) ?></small></span></a><?php endforeach; ?>
                    </div>
                <?php else: ?><p class="muted">Şu an çevrimiçi görünen üye yok.</p><?php endif; ?>
                <a class="btn btn-ghost btn-full" href="sohbet.php">Canlı sohbete git</a>
            </section>

            <section class="social-panel social-leaderboard">
                <h2>Yardımseverler</h2>
                <p class="muted">Çözümler, yorumlar ve alınan GG puanlarına göre sıralanır.</p>
                <?php if ($helpfulMembers): ?>
                    <ol>
                        <?php foreach ($helpfulMembers as $index => $profile): ?>
                            <li>
                                <span class="social-rank"><?= $index + 1 ?></span>
                                <a href="<?= e(social_profile_url($profile)) ?>"><?= social_avatar_html($profile) ?><span><strong><?= e($profile['display_name']) ?></strong><small><?= (int)($profile['forum_stats']['solutions'] ?? 0) ?> çözüm · <?= (int)($profile['forum_stats']['gg_received'] ?? 0) ?> GG</small></span></a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?><p class="muted">Henüz sıralama oluşmadı.</p><?php endif; ?>
            </section>

            <section class="social-panel">
                <h2>Ekip arkadaşı mı arıyorsun?</h2>
                <p>Oyun, platform ve oyun saatini belirten bir Oyun Arkadaşı konusu aç; uygun kişiler sana forum veya özel mesajla ulaşabilir.</p>
                <a class="btn btn-primary btn-full" href="yeni.php?konu=oyun-arkadasi">Oyun arkadaşı ara</a>
            </section>
        </aside>
    </div>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
