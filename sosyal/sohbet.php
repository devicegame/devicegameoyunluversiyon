<?php
require_once __DIR__ . '/_common.php';

$channels = social_chat_channels();
$channelSlug = social_slugify((string)($_GET['kanal'] ?? 'genel')) ?: 'genel';
if (!isset($channels[$channelSlug])) $channelSlug = 'genel';
$channel = $channels[$channelSlug];
$beforeId = max(0, (int)($_GET['once'] ?? 0));
if ($socialUser) social_touch_presence($socialUser, ['chat_channel' => $channelSlug], true);
$result = social_chat_messages($channelSlug, $socialUser, 0, 50, $beforeId);
$messages = (array)($result['messages'] ?? []);
$pinned = $result['pinned'] ?? null;
$onlineUsers = social_online_users($socialUser, 300, 30);
$profilesById = social_profiles_by_ids(array_map(static fn(array $message): int => (int)($message['user_id'] ?? 0), $messages));
$firstMessageId = $messages ? (int)$messages[0]['id'] : 0;
$lastId = (int)($result['last_id'] ?? ($messages ? $messages[count($messages) - 1]['id'] : 0));
$isAdmin = !empty($socialUser['is_admin']);
$canWrite = $socialUser && (empty($channel['official_only']) || $isAdmin);
$channelStats = [];
foreach ($channels as $slug => $item) {
    $preview = social_chat_messages((string)$slug, $socialUser, 0, 1);
    $lastItem = $preview['messages'][0] ?? null;
    $channelStats[$slug] = [
        'last_at' => $lastItem ? (int)$lastItem['created_at'] : 0,
        'last_label' => $lastItem ? social_time_ago((int)$lastItem['created_at']) : 'Henüz mesaj yok',
        'online' => count(array_filter($onlineUsers, static fn(array $profile): bool => ($profile['chat_channel'] ?? '') === $slug)),
    ];
}
$currentSeedPosts = social_list_posts(['topic' => $channelSlug === 'oyun-arkadasi' ? 'oyun-arkadasi' : ($channelSlug === 'etkinlikler' ? 'etkinlikler' : ($channelSlug === 'yardim' ? 'yardim' : '')), 'per_page' => 3, 'sort' => 'activity'], $socialUser)['items'];
$returnUrl = 'sohbet.php?kanal=' . rawurlencode($channelSlug) . '#sohbet-son';

$page_title = $channel['name'] . ' — Canlı Sohbet | DG Social';
$page_meta_desc = 'DeviceGame topluluğunun canlı sohbet kanalları.';
$page_canonical = false;
$page_robots = 'noindex,follow';
$page_og_type = 'website';
$page_og_title = $page_title;
$page_og_desc = $page_meta_desc;
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <header class="social-page-head">
        <p class="eyebrow">DG Social · Canlı alan</p>
        <h1>Canlı sohbet.</h1>
        <p class="muted">Herkes okuyabilir, giriş yapan üyeler yazabilir. Uzun konular, rehberler ve destek ayrıntıları için forum konusunu da kullan.</p>
    </header>

    <?= social_subnav('chat') ?>
    <?= social_flash_html() ?>

    <div class="social-chat-layout" data-chat-root data-channel="<?= e($channelSlug) ?>" data-last-id="<?= $lastId ?>" data-user-id="<?= $socialUser ? (int)$socialUser['id'] : 0 ?>" data-is-admin="<?= $isAdmin ? '1' : '0' ?>" data-csrf="<?= e(csrf_token()) ?>">
        <aside class="social-sidebar social-sidebar-left">
            <section class="social-panel">
                <div class="social-panel-head"><h2>Kanallar</h2><span><?= count($onlineUsers) ?> çevrimiçi</span></div>
                <nav class="social-chat-channel-list" aria-label="Sohbet kanalları">
                    <?php foreach ($channels as $item): ?>
                        <a class="social-chat-channel<?= $channelSlug === (string)$item['slug'] ? ' is-active' : '' ?>" href="sohbet.php?kanal=<?= rawurlencode((string)$item['slug']) ?>" data-chat-channel-link data-channel="<?= e($item['slug']) ?>"<?= $channelSlug === (string)$item['slug'] ? ' aria-current="page"' : '' ?>>
                            <span aria-hidden="true"><?= e($item['icon']) ?></span>
                            <span><strong><?= e($item['name']) ?></strong><small><?= e($item['description']) ?></small></span>
                            <small class="social-channel-meta"><?= e($channelStats[$item['slug']]['last_label']) ?><?= (int)$channelStats[$item['slug']]['online'] > 0 ? ' · ' . (int)$channelStats[$item['slug']]['online'] . ' burada' : '' ?></small>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </section>

            <section class="social-panel social-rules">
                <h2>Sohbet kuralları</h2>
                <ul>
                    <li>Kısa, saygılı ve konuyla ilgili yaz.</li>
                    <li>Peş peşe spam mesaj gönderme; yavaş mod aktif.</li>
                    <li>Şifre, ürün keyi ve kişisel bilgi paylaşma.</li>
                    <li>Sorunlu mesajı cevaplamak yerine şikâyet et.</li>
                </ul>
                <a class="text-link" href="kurallar.php">Topluluk kuralları</a>
            </section>
        </aside>

        <section class="social-panel social-chat-main" id="canli-sohbet" aria-label="<?= e($channel['name']) ?>">
            <header class="social-chat-header">
                <div>
                    <h2 data-chat-title><?= e($channel['icon']) ?> <?= e($channel['name']) ?></h2>
                    <p class="muted" data-chat-description><?= e($channel['description']) ?></p>
                </div>
                <span class="social-chat-status" data-chat-status>Canlı bağlantı açık</span>
            </header>

            <div class="social-chat-pinned" data-chat-pinned<?= !$pinned ? ' hidden' : '' ?>>
                <?php if ($pinned): ?>
                    <strong>📌 Sabit mesaj</strong>
                    <div class="social-rich-text"><?= $pinned['html'] ?></div>
                    <small><?= e($pinned['author_display']) ?> · <?= e($pinned['date_label']) ?></small>
                <?php endif; ?>
            </div>

            <?php if ($firstMessageId > 0 && count($messages) >= 50): ?>
                <div class="social-chat-history"><a class="btn btn-ghost social-small-btn" href="sohbet.php?kanal=<?= rawurlencode($channelSlug) ?>&once=<?= $firstMessageId ?>">Daha eski mesajları göster</a></div>
            <?php endif; ?>

            <div class="social-chat-messages" data-chat-messages tabindex="0" aria-live="polite">
                <?php if ($messages): ?>
                    <?php foreach ($messages as $message): ?>
                        <?php
                        $authorProfile = $profilesById[(int)$message['user_id']] ?? [
                            'user_id' => (int)$message['user_id'],
                            'username' => (string)$message['author_username'],
                            'display_name' => (string)$message['author_display'],
                            'avatar' => (string)$message['author_avatar'],
                        ];
                        $mine = !empty($message['mine']);
                        ?>
                        <article class="social-chat-message<?= $mine ? ' is-mine' : '' ?><?= !empty($message['pinned']) ? ' is-pinned' : '' ?>" data-message-id="<?= (int)$message['id'] ?>">
                            <a href="<?= e(social_profile_url($authorProfile)) ?>"><?= social_avatar_html($authorProfile) ?></a>
                            <div class="social-chat-bubble">
                                <div class="social-dm-meta">
                                    <strong><?= e($message['author_display']) ?></strong>
                                    <small>@<?= e($message['author_username']) ?></small>
                                    <time title="<?= e($message['date_label']) ?>"><?= e($message['time_label']) ?></time>
                                    <?php if (!empty($message['pinned'])): ?><span class="social-mini-badge">Sabit</span><?php endif; ?>
                                </div>
                                <div class="social-rich-text"><?= $message['html'] ?></div>
                                <?php if ($socialUser && (($mine && (int)$message['created_at'] >= time() - 1800) || $isAdmin || !$mine)): ?>
                                    <details class="social-chat-actions">
                                        <summary>İşlemler</summary>
                                        <?php if ($mine && (int)$message['created_at'] >= time() - 1800 || $isAdmin): ?>
                                            <form method="post" action="aksiyon.php" data-social-ajax="chat-state" data-confirm="Bu sohbet mesajı silinsin mi?">
                                                <?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($channelSlug) ?>"><input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>"><input type="hidden" name="status" value="deleted"><input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                                                <button class="social-text-button is-danger" type="submit">Sil</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($isAdmin): ?>
                                            <form method="post" action="aksiyon.php" data-social-ajax="chat-state">
                                                <?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($channelSlug) ?>"><input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>"><input type="hidden" name="status" value="<?= !empty($message['pinned']) ? 'unpin' : 'pin' ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                                                <button class="social-text-button" type="submit"><?= !empty($message['pinned']) ? 'Sabitten kaldır' : 'Sabitle' ?></button>
                                            </form>
                                            <form method="post" action="aksiyon.php" data-social-ajax="chat-state">
                                                <?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($channelSlug) ?>"><input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>"><input type="hidden" name="status" value="hidden"><input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                                                <button class="social-text-button" type="submit">Gizle</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$mine): ?>
                                            <?= social_generic_report_form('chat_message', (int)$message['id'], $returnUrl, ['channel' => $channelSlug]) ?>
                                            <a class="social-text-button" href="<?= e(social_message_url($authorProfile)) ?>">Özel mesaj gönder</a>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="social-empty is-small" data-chat-empty><h3>Bu kanalda henüz mesaj yok.</h3><p>İlk mesajı sen gönder.</p></div>
                <?php endif; ?>
                <span id="sohbet-son" class="social-sr-only"></span>
            </div>
            <button type="button" class="btn btn-primary social-small-btn social-chat-new" data-chat-new hidden>Yeni mesajlar ↓</button>
            <p class="social-chat-typing" data-chat-typing hidden></p>

            <?php if (!$socialUser): ?>
                <div class="social-locked-note">Sohbete yazmak için <a href="<?= e(social_login_url('sosyal/sohbet.php?kanal=' . $channelSlug)) ?>">giriş yap</a>.</div>
            <?php elseif (!$canWrite): ?>
                <div class="social-locked-note">Bu kanala yalnızca DeviceGame ekibi yazabilir. Diğer kanallarda sohbete katılabilirsin.</div>
            <?php else: ?>
                <form method="post" action="aksiyon.php" class="social-chat-form" data-chat-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="sohbet_gonder">
                    <input type="hidden" name="channel" value="<?= e($channelSlug) ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                    <label class="social-sr-only" for="chat-body">Sohbet mesajın</label>
                    <textarea id="chat-body" name="body" maxlength="500" rows="2" required data-character-count placeholder="Mesajını yaz… @kullanici ile bahsedebilirsin"></textarea>
                    <div class="social-chat-form-side">
                        <span data-count-for="chat-body">0 / 500</span>
                        <button class="btn btn-primary" type="submit">Gönder</button>
                    </div>
                    <p class="social-form-note">Enter gönderir, Shift+Enter yeni satır açar. Yavaş mod: 3 saniyede bir mesaj.</p>
                </form>
            <?php endif; ?>
        </section>

        <aside class="social-sidebar social-sidebar-right">
            <section class="social-panel">
                <div class="social-panel-head"><h2>Çevrimiçi üyeler</h2><span><?= count($onlineUsers) ?></span></div>
                <div class="social-user-list" data-chat-online>
                    <?php if ($onlineUsers): ?>
                        <?php foreach ($onlineUsers as $profile): ?>
                            <a href="<?= e(social_profile_url($profile)) ?>"><?= social_avatar_html($profile) ?><span><strong><?= e($profile['display_name']) ?></strong><small>@<?= e($profile['username']) ?> · çevrimiçi</small></span></a>
                        <?php endforeach; ?>
                    <?php else: ?><p class="muted">Şu an görünür çevrimiçi üye yok.</p><?php endif; ?>
                </div>
            </section>

            <?php if ($currentSeedPosts): ?>
                <section class="social-panel">
                    <div class="social-panel-head"><h2>İlgili forum konuları</h2><a href="index.php">Akış</a></div>
                    <div class="social-related-links">
                        <?php foreach ($currentSeedPosts as $relatedPost): ?>
                            <a href="<?= e(social_post_url((int)$relatedPost['id'])) ?>"><strong><?= e($relatedPost['title']) ?></strong><small><?= (int)$relatedPost['comment_count'] ?> yorum · <?= e(social_time_ago((int)$relatedPost['last_activity_at'])) ?></small></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="social-panel">
                <h2>Uzun konu mu?</h2>
                <p>Rehber, soru veya kalıcı tartışma için forumda konu aç; sohbet akışı hızlı ilerler.</p>
                <a class="btn btn-ghost btn-full" href="yeni.php">Forum konusu aç</a>
            </section>
        </aside>
    </div>
</section>

<script src="../assets/js/social.js" defer></script>
<script src="../assets/js/social-chat.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
