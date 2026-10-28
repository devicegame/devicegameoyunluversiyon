<?php
require_once __DIR__ . '/_common.php';

$username = trim((string)($_GET['kullanici'] ?? ''));
$profile = $username !== '' ? social_profile_by_username($username, false) : null;
$profileErrors = [];
$profileForm = null;

if ($profile && $socialUser && (int)$socialUser['id'] === (int)$profile['user_id'] && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['social_action'] ?? '') === 'profile_update') {
    $profileForm = $_POST;
    if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
        $profileErrors[] = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.';
    } else {
        try {
            $result = social_update_profile($socialUser, $_POST, $_FILES);
        } catch (Throwable $exception) {
            error_log('[social profile] ' . $exception->getMessage());
            $result = ['errors' => ['Profil kaydedilemedi. Depolama izinlerini kontrol edin.'], 'profile' => $profile];
        }
        if (!empty($result['errors'])) {
            $profileErrors = $result['errors'];
        } else {
            flash_set('Sosyal profilin güncellendi.');
            header('Location: ' . social_profile_url($result['profile']) . '#profil-duzenle');
            exit;
        }
    }
}

if (!$profile) {
    http_response_code(404);
    $page_title = 'Kullanıcı bulunamadı — DG Social';
    $page_meta_desc = 'Aradığın DG Social kullanıcısı bulunamadı.';
    $page_canonical = false;
    $page_robots = 'noindex,nofollow';
    $page_css = ['shop.css', 'social.css'];
    $body_class = 'shop-page social-page';
    include __DIR__ . '/../header.php';
    ?>
    <section class="social-shell is-narrow">
        <div class="social-empty">
            <p class="eyebrow">404</p>
            <h1>Bu kullanıcıyı bulamadık.</h1>
            <p>Kullanıcı adı değişmiş veya hesap kaldırılmış olabilir.</p>
            <a class="btn btn-primary" href="index.php">Akışa dön</a>
        </div>
    </section>
    <?php
    include __DIR__ . '/../footer.php';
    exit;
}

$profileId = (int)$profile['user_id'];
$isOwn = $socialUser && (int)$socialUser['id'] === $profileId;
$isAdmin = !empty($socialUser['is_admin']);
$profileUser = $profileId > 0 && function_exists('dg_find_user_by_id') ? dg_find_user_by_id($profileId) : null;
$targetFollows = social_follows($profileId);
$viewerFollows = $socialUser ? social_follows((int)$socialUser['id']) : ['following' => [], 'followers' => [], 'blocked' => []];
$blockedByTarget = $socialUser && in_array((int)$socialUser['id'], $targetFollows['blocked'], true);
$viewerBlockedTarget = $socialUser && in_array($profileId, $viewerFollows['blocked'], true);
$isFollowing = $socialUser && in_array($profileId, $viewerFollows['following'], true);
$canViewContent = $isAdmin || (!$blockedByTarget && !$viewerBlockedTarget);
$badges = social_profile_badges($profile, $profileUser);
$page = max(1, (int)($_GET['sayfa'] ?? 1));
$result = $canViewContent
    ? social_list_posts(['author_id' => $profileId, 'page' => $page, 'per_page' => 10, 'sort' => 'activity'], $socialUser)
    : ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
$posts = $result['items'];
$socialStates = social_post_states(array_map(static fn(array $post): int => (int)$post['id'], $posts), $socialUser);
$recentComments = $canViewContent ? social_user_comments($profileId, $socialUser, 8) : [];
$awardStats = $canViewContent ? social_user_award_stats($profileId) : ['count' => 0, 'total' => 0];
$forumStats = $canViewContent ? social_user_forum_stats($profileId) : ['solutions' => 0, 'gg_received' => 0, 'views' => 0];
$activityScore = (int)$profile['post_count'] * 5 + (int)$profile['comment_count'] * 2 + (int)($forumStats['solutions'] ?? 0) * 10 + (int)($forumStats['gg_received'] ?? 0);
$dmCheck = (!$isOwn && $socialUser && $canViewContent) ? social_dm_can_message($socialUser, $profileId) : ['errors' => ['Mesaj kullanılamıyor.']];
$canSendDm = empty($dmCheck['errors']);
$followers = $canViewContent ? social_profiles_by_ids(array_slice($targetFollows['followers'], 0, 12)) : [];
$following = $canViewContent ? social_profiles_by_ids(array_slice($targetFollows['following'], 0, 12)) : [];
$returnUrl = 'profil.php?kullanici=' . rawurlencode((string)$profile['username']);
if ($profileForm === null) {
    $profileForm = [
        'display_name' => (string)$profile['display_name'],
        'bio' => (string)$profile['bio'],
        'platforms' => implode(', ', (array)$profile['platforms']),
        'favorite_games' => implode(', ', (array)$profile['favorite_games']),
        'signature' => (string)($profile['signature'] ?? ''),
        'show_balance' => !empty($profile['show_balance']),
        'show_online' => !isset($profile['show_online']) || !empty($profile['show_online']),
        'dm_policy' => (string)($profile['dm_policy'] ?? 'everyone'),
        'notify_messages' => social_notification_prefs($profile)['messages'],
        'notify_mentions' => social_notification_prefs($profile)['mentions'],
        'notify_follows' => social_notification_prefs($profile)['follows'],
        'notify_comments' => social_notification_prefs($profile)['comments'],
        'notify_replies' => social_notification_prefs($profile)['replies'],
        'notify_watching' => social_notification_prefs($profile)['watching'],
        'notify_awards' => social_notification_prefs($profile)['awards'],
        'notify_solutions' => social_notification_prefs($profile)['solutions'],
        'notify_auto_watch' => social_notification_prefs($profile)['auto_watch'],
        'notify_events' => social_notification_prefs($profile)['events'],
    ];
}

$page_title = $profile['display_name'] . ' (@' . $profile['username'] . ') — DG Social';
$page_meta_desc = trim((string)$profile['bio']) !== '' ? social_excerpt((string)$profile['bio'], 155) : $profile['display_name'] . ' kullanıcısının DeviceGame sosyal profili.';
$profileHasContent = $profileId === 0 || (int)$profile['post_count'] > 0 || (int)$profile['comment_count'] > 0 || trim((string)$profile['bio']) !== '';
$page_canonical = $profileHasContent ? 'sosyal/' . social_profile_url($profile) : false;
$page_robots = $profileHasContent ? '' : 'noindex,follow';
$page_og_type = 'profile';
$page_og_title = $profile['display_name'] . ' (@' . $profile['username'] . ')';
$page_og_desc = $page_meta_desc;
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <?= social_subnav($isOwn ? 'profile' : 'feed') ?>
    <?= social_flash_html() ?>

    <header class="social-profile-hero" style="--avatar-h:<?= social_avatar_hue($profileId) ?>">
        <div class="social-profile-main">
            <?= social_avatar_html($profile, 'is-xlarge') ?>
            <div>
                <p class="eyebrow">DG Social Profili</p>
                <h1><?= e($profile['display_name']) ?></h1>
                <p class="social-username">@<?= e($profile['username']) ?> · Katılım: <?= e(date('d.m.Y', (int)$profile['joined_at'])) ?></p>
                <p class="social-presence-line"><?= social_presence_html($profile, $socialUser) ?></p>
                <?php if (trim((string)($profile['signature'] ?? '')) !== ''): ?><div class="social-profile-signature"><?= social_render_text((string)$profile['signature']) ?></div><?php endif; ?>
                <?php if ($badges || (int)$awardStats['count'] > 0): ?>
                    <div class="social-badges">
                        <?php foreach ($badges as $badge): ?><span><?= e($badge) ?></span><?php endforeach; ?>
                        <?php if ((int)$awardStats['count'] > 0): ?><span>🏆 <?= (int)$awardStats['count'] ?> Editör Ödülü</span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="social-profile-actions">
            <?php if ($isOwn): ?>
                <a class="btn btn-primary social-small-btn" href="#profil-duzenle">Profili düzenle</a>
                <a class="btn btn-ghost social-small-btn" href="mesajlar.php">Özel mesajlarım</a>
                <a class="btn btn-ghost social-small-btn" href="sohbet.php">Canlı sohbet</a>
                <a class="btn btn-ghost social-small-btn" href="yeni.php">Yeni gönderi</a>
            <?php elseif ($socialUser): ?>
                <?php if ($viewerBlockedTarget): ?>
                    <form method="post" action="aksiyon.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="engelle">
                        <input type="hidden" name="target_id" value="<?= $profileId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button class="btn btn-primary social-small-btn" type="submit">Engeli kaldır</button>
                    </form>
                <?php elseif (!$blockedByTarget): ?>
                    <form method="post" action="aksiyon.php" data-social-ajax="follow">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="takip">
                        <input type="hidden" name="target_id" value="<?= $profileId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button class="btn <?= $isFollowing ? 'btn-ghost' : 'btn-primary' ?> social-small-btn" type="submit" aria-pressed="<?= $isFollowing ? 'true' : 'false' ?>"><?= $isFollowing ? 'Takip ediliyor' : 'Takip et' ?></button>
                    </form>
                    <?php if ($canSendDm): ?>
                        <a class="btn btn-ghost social-small-btn" href="<?= e(social_message_url($profile)) ?>">Mesaj gönder</a>
                    <?php else: ?>
                        <span class="social-action-note" title="<?= e(implode(' ', (array)$dmCheck['errors'])) ?>">Mesaj kapalı</span>
                    <?php endif; ?>
                    <?= social_generic_report_form('profile', $profileId, $returnUrl) ?>
                    <form method="post" action="aksiyon.php" data-confirm="Bu kullanıcıyı engellemek istiyor musun?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="engelle">
                        <input type="hidden" name="target_id" value="<?= $profileId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button class="btn btn-ghost social-small-btn" type="submit">Engelle</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn btn-primary social-small-btn" href="<?= e(social_login_url('sosyal/' . $returnUrl)) ?>">Takip etmek için giriş yap</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($isOwn && $socialUser && $socialProfile): ?>
        <?= social_onboarding_html($socialUser, $socialProfile, (string)($_GET['hosgeldin'] ?? '') === '1') ?>
    <?php endif; ?>

    <?php if ($blockedByTarget && !$isAdmin): ?>
        <div class="social-empty">
            <h2>Bu profil sana uygun değil.</h2>
            <p>Kullanıcının gönderileri ve yorumları sana gösterilmiyor.</p>
            <a class="btn btn-primary" href="index.php">Akışa dön</a>
        </div>
    <?php elseif ($viewerBlockedTarget && !$isAdmin): ?>
        <div class="social-empty">
            <h2>Bu kullanıcıyı engelledin.</h2>
            <p>İçeriklerini görmek için engeli kaldırabilirsin.</p>
        </div>
    <?php else: ?>
        <div class="social-profile-grid">
            <aside class="social-sidebar">
                <section class="social-panel">
                    <h2>Hakkında</h2>
                    <p><?= trim((string)$profile['bio']) !== '' ? e($profile['bio']) : 'Henüz bir biyografi yazılmamış.' ?></p>
                    <dl class="social-profile-facts">
                        <div><dt>Gönderi</dt><dd><?= (int)$profile['post_count'] ?></dd></div>
                        <div><dt>Yorum</dt><dd><?= (int)$profile['comment_count'] ?></dd></div>
                        <div><dt>Takipçi</dt><dd><?= (int)$profile['follower_count'] ?></dd></div>
                        <div><dt>Takip</dt><dd><?= (int)$profile['following_count'] ?></dd></div>
                        <div><dt>Kabul edilen çözüm</dt><dd><?= (int)($forumStats['solutions'] ?? 0) ?></dd></div>
                        <div><dt>Alınan GG</dt><dd><?= number_format((int)($forumStats['gg_received'] ?? 0), 0, ',', '.') ?></dd></div>
                        <div><dt>Konu görüntülenme</dt><dd><?= number_format((int)($forumStats['views'] ?? 0), 0, ',', '.') ?></dd></div>
                        <div><dt>Forum aktivite puanı</dt><dd><?= number_format($activityScore, 0, ',', '.') ?></dd></div>
                        <?php if ((int)$awardStats['count'] > 0): ?>
                            <div><dt>Editör ödülleri</dt><dd><?= (int)$awardStats['count'] ?> · +<?= number_format((int)$awardStats['total'], 0, ',', '.') ?> D-Coin</dd></div>
                        <?php endif; ?>
                        <?php if ($profileUser && (!empty($profile['show_balance']) || $isOwn || $isAdmin)): ?>
                            <div><dt>D-Coin</dt><dd><?= number_format((int)$profileUser['dcoin_balance'], 0, ',', '.') ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?php if (!empty($profile['platforms'])): ?>
                        <h3>Platformlar</h3>
                        <div class="social-tags"><?php foreach ((array)$profile['platforms'] as $platform): ?><span><?= e($platform) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($profile['favorite_games'])): ?>
                        <h3>Favori oyunlar</h3>
                        <div class="social-tags"><?php foreach ((array)$profile['favorite_games'] as $game): ?><span><?= e($game) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                </section>

                <?php if ($followers): ?>
                    <section class="social-panel">
                        <h2>Takipçiler <span class="social-heading-count"><?= (int)$profile['follower_count'] ?></span></h2>
                        <div class="social-user-list">
                            <?php foreach ($followers as $follower): ?>
                                <a href="<?= e(social_profile_url($follower)) ?>"><?= social_avatar_html($follower) ?><span><strong><?= e($follower['display_name']) ?></strong><small>@<?= e($follower['username']) ?></small></span></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($following): ?>
                    <section class="social-panel">
                        <h2>Takip ettikleri <span class="social-heading-count"><?= (int)$profile['following_count'] ?></span></h2>
                        <div class="social-user-list">
                            <?php foreach ($following as $followed): ?>
                                <a href="<?= e(social_profile_url($followed)) ?>"><?= social_avatar_html($followed) ?><span><strong><?= e($followed['display_name']) ?></strong><small>@<?= e($followed['username']) ?></small></span></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($isAdmin && !$isOwn && $profileId > 0): ?>
                    <section class="social-panel" id="yonetici-araclari">
                        <h2>Yönetici araçları</h2>
                        <form method="post" action="aksiyon.php" class="social-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="islem" value="guven">
                            <input type="hidden" name="target_id" value="<?= $profileId ?>">
                            <input type="hidden" name="return" value="<?= e($returnUrl . '#yonetici-araclari') ?>">
                            <label for="trust-level">Güven seviyesi</label>
                            <select id="trust-level" name="trust_level">
                                <option value="0"<?= (int)$profile['trust_level'] === 0 ? ' selected' : '' ?>>0 — Yeni / onaylı</option>
                                <option value="1"<?= (int)$profile['trust_level'] === 1 ? ' selected' : '' ?>>1 — Güvenilir</option>
                                <option value="2"<?= (int)$profile['trust_level'] === 2 ? ' selected' : '' ?>>2 — Ayrıcalıklı</option>
                            </select>
                            <button class="btn btn-ghost social-small-btn" type="submit">Güncelle</button>
                        </form>
                        <form method="post" action="aksiyon.php" class="social-admin-warning">
                            <?= csrf_field() ?>
                            <input type="hidden" name="islem" value="uyari_ver">
                            <input type="hidden" name="target_id" value="<?= $profileId ?>">
                            <input type="hidden" name="return" value="<?= e($returnUrl . '#yonetici-araclari') ?>">
                            <div class="field">
                                <label for="warning-reason">Uyarı / susturma sebebi</label>
                                <textarea id="warning-reason" name="reason" maxlength="500" rows="3" required placeholder="Kullanıcıya bildirilecek açıklama"></textarea>
                            </div>
                            <div class="field">
                                <label for="warning-days">Süre</label>
                                <select id="warning-days" name="mute_days">
                                    <option value="0">Yalnızca uyarı</option>
                                    <option value="1">1 gün sustur</option>
                                    <option value="7">7 gün sustur</option>
                                    <option value="30">30 gün sustur</option>
                                </select>
                            </div>
                            <button class="btn btn-ghost social-small-btn" type="submit">Uyarıyı gönder</button>
                        </form>
                        <?php if ((int)($profile['muted_until'] ?? 0) > time()): ?>
                            <form method="post" action="aksiyon.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="islem" value="sustur_kaldir">
                                <input type="hidden" name="target_id" value="<?= $profileId ?>">
                                <input type="hidden" name="return" value="<?= e($returnUrl . '#yonetici-araclari') ?>">
                                <p class="social-action-note">Susturma: <?= e(social_format_date((int)$profile['muted_until'])) ?> tarihine kadar</p>
                                <button class="btn btn-primary social-small-btn" type="submit">Susturmayı kaldır</button>
                            </form>
                        <?php endif; ?>
                        <p class="muted">Kullanıcıyı tamamen engellemek için yönetim panelindeki Kullanıcılar bölümünü kullan.</p>
                    </section>
                <?php endif; ?>
            </aside>

            <div class="social-main">
                <?php if ($isOwn): ?>
                    <section id="profil-duzenle" class="social-form-card">
                        <h2>Profili düzenle</h2>
                        <?php if ($profileErrors): ?>
                            <div class="social-alert is-error"><ul><?php foreach ($profileErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="social_action" value="profile_update">
                            <input type="hidden" name="settings_present" value="1">
                            <div class="field">
                                <label for="profile-display">Görünen ad *</label>
                                <input id="profile-display" type="text" name="display_name" required maxlength="50" value="<?= e($profileForm['display_name'] ?? '') ?>">
                            </div>
                            <div class="field social-avatar-editor">
                                <label for="profile-avatar">Profil fotoğrafı</label>
                                <div class="social-avatar-editor-row">
                                    <?= social_avatar_html($profile, 'is-large') ?>
                                    <div>
                                        <input id="profile-avatar" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                        <p class="form-note">JPG, PNG veya WebP; kareye kırpılır, en fazla 3 MB.</p>
                                        <label class="social-check"><input type="checkbox" name="remove_avatar" value="1"> Mevcut fotoğrafı kaldır ve baş harf avatarı kullan</label>
                                    </div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="profile-bio">Biyografi</label>
                                <textarea id="profile-bio" name="bio" maxlength="500" rows="4" data-character-count><?= e($profileForm['bio'] ?? '') ?></textarea>
                                <div class="social-form-meta"><span>Oyuncu kimliğini kısaca anlat.</span><span data-count-for="profile-bio">0 / 500</span></div>
                            </div>
                            <div class="field">
                                <label for="profile-signature">Forum imzası</label>
                                <?= social_editor_toolbar_html('profile-signature') ?>
                                <textarea id="profile-signature" name="signature" maxlength="240" rows="3" data-character-count placeholder="Kısa bir not, oynadığın platform veya ekip aradığın oyun"><?= e($profileForm['signature'] ?? '') ?></textarea>
                                <div class="social-form-meta"><span>Yorumlarının altında ve profilinde görünür.</span><span data-count-for="profile-signature">0 / 240</span></div>
                            </div>
                            <div class="social-form-grid">
                                <div class="field">
                                    <label for="profile-platforms">Platformlar</label>
                                    <input id="profile-platforms" type="text" name="platforms" value="<?= e($profileForm['platforms'] ?? '') ?>" placeholder="PC, PS5, Switch">
                                </div>
                                <div class="field">
                                    <label for="profile-games">Favori oyunlar</label>
                                    <input id="profile-games" type="text" name="favorite_games" value="<?= e($profileForm['favorite_games'] ?? '') ?>" placeholder="Oyunları virgülle ayır">
                                </div>
                            </div>
                            <fieldset class="social-settings-group">
                                <legend>Gizlilik ve özel mesaj</legend>
                                <label class="social-check"><input type="checkbox" name="show_balance" value="1"<?= !empty($profileForm['show_balance']) ? ' checked' : '' ?>> D-Coin bakiyemi profilimde göster</label>
                                <label class="social-check"><input type="checkbox" name="show_online" value="1"<?= !isset($profileForm['show_online']) || !empty($profileForm['show_online']) ? ' checked' : '' ?>> Çevrimiçi durumumu göster</label>
                                <div class="field">
                                    <label for="profile-dm-policy">Bana kim mesaj gönderebilir?</label>
                                    <select id="profile-dm-policy" name="dm_policy">
                                        <option value="everyone"<?= ($profileForm['dm_policy'] ?? 'everyone') === 'everyone' ? ' selected' : '' ?>>Herkes (yeni üyeler istek olarak gelir)</option>
                                        <option value="following"<?= ($profileForm['dm_policy'] ?? '') === 'following' ? ' selected' : '' ?>>Yalnızca takip ettiğim kişiler</option>
                                        <option value="nobody"<?= ($profileForm['dm_policy'] ?? '') === 'nobody' ? ' selected' : '' ?>>Hiç kimse</option>
                                    </select>
                                </div>
                            </fieldset>
                            <fieldset class="social-settings-group">
                                <legend>Bildirim tercihlerim</legend>
                                <?php
                                $profileNotifLabels = [
                                    'comments' => 'Gönderilerime gelen yorumlar',
                                    'replies' => 'Yorumlarıma gelen cevaplar',
                                    'mentions' => 'Bahsetmeler',
                                    'follows' => 'Yeni takipçiler',
                                    'events' => 'Etkinlik ve katılım hareketleri',
                                    'awards' => 'Editör Ödülleri',
                                    'messages' => 'Yeni özel mesajlar',
                                    'watching' => 'İzlediğim konu ve kategorilerdeki hareketler',
                                    'solutions' => 'Çözüm seçimleri',
                                    'auto_watch' => 'Yorum yaptığım konuları otomatik izle',
                                ];
                                $profileNotifPrefs = social_notification_prefs($profile);
                                foreach ($profileNotifLabels as $notifKey => $notifLabel):
                                    $notifField = 'notify_' . $notifKey;
                                    $notifChecked = array_key_exists($notifField, (array)$profileForm) ? !empty($profileForm[$notifField]) : !empty($profileNotifPrefs[$notifKey]);
                                ?>
                                    <label class="social-check"><input type="checkbox" name="<?= e($notifField) ?>" value="1"<?= $notifChecked ? ' checked' : '' ?>> <?= e($notifLabel) ?></label>
                                <?php endforeach; ?>
                                <p class="form-note">Resmî duyurular ve güvenlik bildirimleri her zaman gösterilir.</p>
                            </fieldset>
                            <button class="btn btn-primary" type="submit">Profili kaydet</button>
                        </form>
                    </section>
                <?php endif; ?>

                <section id="profil-gonderileri">
                    <div class="social-section-title">
                        <h2>Gönderiler</h2>
                        <span><?= (int)$result['total'] ?> içerik</span>
                    </div>
                    <?php if ($posts): ?>
                        <div class="social-feed">
                            <?php foreach ($posts as $post): ?>
                                <?php include __DIR__ . '/_post-card.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <?= social_pagination_html('profil.php', ['kullanici' => (string)$profile['username']], (int)$result['page'], (int)$result['pages'], 'profil-gonderileri') ?>
                    <?php else: ?>
                        <div class="social-empty is-small"><h3>Henüz gönderi yok.</h3><p>Bu kullanıcı henüz bir şey paylaşmamış.</p></div>
                    <?php endif; ?>
                </section>

                <?php if ($recentComments): ?>
                    <section class="social-panel social-recent-comments">
                        <h2>Son yorumlar</h2>
                        <?php foreach ($recentComments as $comment): ?>
                            <a href="<?= e(social_post_url((int)$comment['post_id'], (int)$comment['id'])) ?>">
                                <small><?= e($comment['post_title']) ?> · <?= e(social_time_ago((int)$comment['created_at'])) ?><?php if (($comment['status'] ?? '') !== 'published'): ?> · <?= e(social_status_label((string)$comment['status'])) ?><?php endif; ?></small>
                                <p><?= e($comment['excerpt']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
