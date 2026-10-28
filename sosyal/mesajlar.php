<?php
require_once __DIR__ . '/_common.php';
social_require_login();

$tab = (string)($_GET['sekme'] ?? 'gelen');
if (!in_array($tab, ['gelen', 'istekler', 'arsiv'], true)) $tab = 'gelen';
$query = trim((string)($_GET['q'] ?? ''));
$allConversations = social_dm_conversations($socialUser, $query, true);
$conversations = array_values(array_filter($allConversations, static function (array $conversation) use ($tab, $socialUser): bool {
    if ($tab === 'arsiv') return !empty($conversation['archived']);
    if ($tab === 'istekler') return empty($conversation['archived']) && ($conversation['status'] ?? '') === 'pending' && (int)$conversation['initiated_by'] !== (int)$socialUser['id'];
    return empty($conversation['archived']) && !(($conversation['status'] ?? '') === 'pending' && (int)$conversation['initiated_by'] !== (int)$socialUser['id']);
}));
$tabCounts = [
    'gelen' => count(array_filter($allConversations, static fn(array $item): bool => empty($item['archived']) && !(($item['status'] ?? '') === 'pending' && (int)$item['initiated_by'] !== (int)$socialUser['id']))),
    'istekler' => count(array_filter($allConversations, static fn(array $item): bool => empty($item['archived']) && ($item['status'] ?? '') === 'pending' && (int)$item['initiated_by'] !== (int)$socialUser['id'])),
    'arsiv' => count(array_filter($allConversations, static fn(array $item): bool => !empty($item['archived']))),
];

$requestedConversationId = (int)($_GET['sohbet'] ?? 0);
$targetUsername = trim((string)($_GET['kullanici'] ?? ''));
$targetProfile = null;
$selectedConversation = null;
$selectedSummary = null;
$messages = [];
$selectionError = '';

if ($targetUsername !== '') {
    $targetProfile = social_profile_by_username(ltrim($targetUsername, '@'), false);
    if (!$targetProfile || (int)$targetProfile['user_id'] === (int)$socialUser['id']) {
        $selectionError = 'Mesaj gönderilecek kullanıcıyı bulamadık.';
        $targetProfile = null;
    } else {
        $existing = social_dm_find_conversation((int)$socialUser['id'], (int)$targetProfile['user_id']);
        if ($existing) {
            $selectedConversation = $existing;
            $requestedConversationId = (int)$existing['id'];
        }
    }
}
if ($requestedConversationId > 0) {
    $selectedConversation = social_dm_get_conversation($requestedConversationId);
    if (!$selectedConversation || !in_array((int)$socialUser['id'], (array)$selectedConversation['participants'], true)) {
        $selectedConversation = null;
        $selectionError = 'Bu konuşmaya erişemezsin.';
    } else {
        $peerId = social_dm_peer_id($selectedConversation, (int)$socialUser['id']);
        $targetProfile = social_get_profile($peerId) ?: $targetProfile;
        $beforeId = (int)($_GET['once'] ?? 0);
        $messageResult = social_dm_messages($socialUser, (int)$selectedConversation['id'], 0, 100, $beforeId);
        if (!empty($messageResult['errors'])) {
            $selectionError = (string)($messageResult['errors'][0] ?? 'Mesajlar yüklenemedi.');
        } else {
            $messages = (array)($messageResult['messages'] ?? []);
            $selectedSummary = $messageResult['conversation'] ?? social_dm_summary_for($selectedConversation, (int)$socialUser['id']);
            // Yer imini yalnız en yeni sayfa açılırken ilerlet; eski mesajlara bakmak yeni mesajı da okunmuş saymasın.
            if ($beforeId <= 0 && (int)$selectedSummary['last_message_id'] > 0) {
                social_dm_mark_read($socialUser, (int)$selectedConversation['id'], (int)$selectedSummary['last_message_id']);
                $selectedSummary['unread_count'] = 0;
                $conversations = array_map(static function (array $item) use ($selectedConversation): array {
                    if ((int)$item['id'] === (int)$selectedConversation['id']) $item['unread_count'] = 0;
                    return $item;
                }, $conversations);
            }
        }
    }
}
if (!$selectedConversation && $targetProfile) {
    $selectedSummary = [
        'id' => 0,
        'peer_id' => (int)$targetProfile['user_id'],
        'peer_username' => (string)$targetProfile['username'],
        'peer_display' => (string)$targetProfile['display_name'],
        'peer_avatar' => (string)($targetProfile['avatar'] ?? ''),
        'status' => 'new',
        'archived' => false,
        'read_message_id' => 0,
        'unread_count' => 0,
    ];
}
$dmCheck = $targetProfile ? social_dm_can_message($socialUser, (int)$targetProfile['user_id'], $selectedConversation) : ['errors' => []];
$dmError = (string)($dmCheck['errors'][0] ?? '');
$peerProfileForAvatar = $targetProfile ?: [
    'user_id' => (int)($selectedSummary['peer_id'] ?? 0),
    'username' => (string)($selectedSummary['peer_username'] ?? ''),
    'display_name' => (string)($selectedSummary['peer_display'] ?? 'Oyuncu'),
    'avatar' => (string)($selectedSummary['peer_avatar'] ?? ''),
];
$firstMessageId = $messages ? (int)$messages[0]['id'] : 0;
$lastMessageId = $messages ? (int)$messages[count($messages) - 1]['id'] : 0;
$profilesById = social_profiles_by_ids(array_merge([(int)$socialUser['id']], array_map(static fn(array $message): int => (int)($message['sender_id'] ?? 0), $messages)));
$isPendingForViewer = $selectedConversation && ($selectedConversation['status'] ?? '') === 'pending' && (int)$selectedConversation['initiated_by'] !== (int)$socialUser['id'];
$isPendingByViewer = $selectedConversation && ($selectedConversation['status'] ?? '') === 'pending' && (int)$selectedConversation['initiated_by'] === (int)$socialUser['id'];
$selectedReturn = $selectedConversation ? 'mesajlar.php?sohbet=' . (int)$selectedConversation['id'] . '#mesaj-son' : 'mesajlar.php';
$peerPresenceProfile = $targetProfile ?: $peerProfileForAvatar;

$page_title = 'Özel Mesajlar — DG Social';
$page_meta_desc = 'DG Social özel mesaj kutun.';
$page_canonical = false;
$page_robots = 'noindex,nofollow';
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <header class="social-page-head">
        <p class="eyebrow">DG Social</p>
        <h1>Özel mesajlar.</h1>
        <p class="muted">Konuşmaların yalnızca sana ve konuştuğun kişiye görünür. Güvenlik için şifreni, ödeme bilgini veya ürün keyini kimseyle paylaşma.</p>
    </header>

    <?= social_subnav('messages') ?>
    <?= social_flash_html() ?>

    <?php if ($selectionError !== ''): ?><div class="social-alert is-error" role="alert"><?= e($selectionError) ?></div><?php endif; ?>

    <div class="social-dm-layout" data-dm-root data-conversation-id="<?= (int)($selectedConversation['id'] ?? 0) ?>" data-last-id="<?= $lastMessageId ?>" data-user-id="<?= (int)$socialUser['id'] ?>">
        <aside class="social-panel social-dm-sidebar" id="konusmalar">
            <div class="social-panel-head">
                <h2>Konuşmalar</h2>
                <a href="uyeler.php">Yeni sohbet</a>
            </div>
            <form method="get" action="mesajlar.php" class="social-dm-search" role="search">
                <input type="hidden" name="sekme" value="<?= e($tab) ?>">
                <label class="social-sr-only" for="dm-q">Kişi veya mesaj ara</label>
                <input id="dm-q" type="search" name="q" value="<?= e($query) ?>" placeholder="Kişi veya son mesaj ara">
                <button class="btn btn-ghost social-small-btn" type="submit">Ara</button>
            </form>
            <nav class="social-dm-tabs" aria-label="Mesaj kutusu">
                <a href="mesajlar.php?sekme=gelen<?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>"<?= $tab === 'gelen' ? ' aria-current="page"' : '' ?>>Gelenler <span><?= (int)$tabCounts['gelen'] ?></span></a>
                <a href="mesajlar.php?sekme=istekler<?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>"<?= $tab === 'istekler' ? ' aria-current="page"' : '' ?>>İstekler <span><?= (int)$tabCounts['istekler'] ?></span></a>
                <a href="mesajlar.php?sekme=arsiv<?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>"<?= $tab === 'arsiv' ? ' aria-current="page"' : '' ?>>Arşiv <span><?= (int)$tabCounts['arsiv'] ?></span></a>
            </nav>
            <?php if ($conversations): ?>
                <div class="social-dm-list">
                    <?php foreach ($conversations as $conversation): ?>
                        <?php
                        $peerProfile = [
                            'user_id' => (int)$conversation['peer_id'],
                            'username' => (string)$conversation['peer_username'],
                            'display_name' => (string)$conversation['peer_display'],
                            'avatar' => (string)$conversation['peer_avatar'],
                        ];
                        $isActive = $selectedConversation && (int)$selectedConversation['id'] === (int)$conversation['id'];
                        ?>
                        <a class="social-dm-list-item<?= $isActive ? ' is-active' : '' ?><?= (int)$conversation['unread_count'] > 0 ? ' is-unread' : '' ?>" href="mesajlar.php?sohbet=<?= (int)$conversation['id'] ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                            <?= social_avatar_html($peerProfile) ?>
                            <span class="social-dm-list-main">
                                <span class="social-dm-list-top"><strong><?= e($conversation['peer_display']) ?></strong><small><?= e(social_time_ago((int)$conversation['last_at'])) ?></small></span>
                                <small>@<?= e($conversation['peer_username']) ?><?= !empty($conversation['peer_online']) ? ' · <i class="social-presence-dot is-online" aria-hidden="true"></i> çevrimiçi' : '' ?></small>
                                <span><?= e(social_excerpt((string)$conversation['last_excerpt'], 110) ?: 'Henüz mesaj yok') ?></span>
                                <?php if (($conversation['status'] ?? '') === 'pending'): ?><em>Mesaj isteği</em><?php endif; ?>
                            </span>
                            <?php if ((int)$conversation['unread_count'] > 0): ?><span class="social-count"><?= (int)$conversation['unread_count'] ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="social-empty is-small">
                    <h3><?= $tab === 'istekler' ? 'Bekleyen istek yok.' : ($tab === 'arsiv' ? 'Arşivin boş.' : 'Henüz konuşman yok.') ?></h3>
                    <p>Bir üyenin profilindeki “Mesaj gönder” düğmesini kullanabilirsin.</p>
                    <a class="btn btn-primary social-small-btn" href="uyeler.php">Üyeleri keşfet</a>
                </div>
            <?php endif; ?>
        </aside>

        <section class="social-panel social-dm-main" id="konusma" aria-label="Seçili konuşma">
            <?php if ($targetProfile || $selectedSummary): ?>
                <header class="social-dm-header">
                    <a class="social-author" href="<?= e(social_profile_url($peerProfileForAvatar)) ?>">
                        <?= social_avatar_html($peerProfileForAvatar, 'is-large') ?>
                        <span>
                            <strong><?= e($peerProfileForAvatar['display_name']) ?></strong>
                            <small>@<?= e($peerProfileForAvatar['username']) ?></small>
                            <span class="social-dm-peer-status" data-dm-peer-status data-fallback="<?= e(social_last_seen_label($peerPresenceProfile, $socialUser)) ?>"><?= social_is_online((int)$peerProfileForAvatar['user_id']) ? 'Şu an çevrimiçi' : e(social_last_seen_label($peerPresenceProfile, $socialUser)) ?></span>
                        </span>
                    </a>
                    <div class="social-dm-header-actions">
                        <span class="social-chat-status" data-dm-status><?= $selectedConversation ? 'Canlı bağlantı açık' : 'Yeni konuşma' ?></span>
                        <a class="btn btn-ghost social-small-btn" href="<?= e(social_profile_url($peerProfileForAvatar)) ?>">Profil</a>
                        <?php if ($selectedConversation): ?>
                            <form method="post" action="aksiyon.php" data-social-ajax="dm-archive">
                                <?= csrf_field() ?>
                                <input type="hidden" name="islem" value="mesaj_arsiv">
                                <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                <input type="hidden" name="archived" value="<?= !empty($selectedSummary['archived']) ? '0' : '1' ?>">
                                <input type="hidden" name="return" value="<?= e($selectedReturn) ?>">
                                <button class="btn btn-ghost social-small-btn" type="submit"><?= !empty($selectedSummary['archived']) ? 'Arşivden çıkar' : 'Arşivle' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($isPendingForViewer): ?>
                    <div class="social-alert is-warning">
                        <strong>Bu kişi seninle ilk kez iletişime geçiyor.</strong>
                        <p>İsteği kabul edene kadar sınırlı sayıda mesaj gönderebilir. Kişisel bilgi veya ürün keyi paylaşma.</p>
                        <div class="social-action-row">
                            <form method="post" action="aksiyon.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="islem" value="mesaj_istek">
                                <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                <input type="hidden" name="decision" value="accept">
                                <input type="hidden" name="return" value="<?= e($selectedReturn) ?>">
                                <button class="btn btn-primary social-small-btn" type="submit">İsteği kabul et</button>
                            </form>
                            <form method="post" action="aksiyon.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="islem" value="mesaj_istek">
                                <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                <input type="hidden" name="decision" value="decline">
                                <input type="hidden" name="return" value="mesajlar.php?sekme=istekler">
                                <button class="btn btn-ghost social-small-btn" type="submit">Reddet</button>
                            </form>
                            <form method="post" action="aksiyon.php" data-confirm="Bu kullanıcıyı engellemek istiyor musun?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="islem" value="engelle">
                                <input type="hidden" name="target_id" value="<?= (int)$targetProfile['user_id'] ?>">
                                <input type="hidden" name="return" value="mesajlar.php">
                                <button class="btn btn-ghost social-small-btn" type="submit">Engelle</button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($isPendingByViewer): ?>
                    <div class="social-alert is-info">Mesaj isteğin karşı tarafın kabulünü bekliyor. Kabul edilene kadar en fazla 3 kısa mesaj gönderebilir ve bağlantı paylaşamazsın.</div>
                <?php elseif (($selectedConversation['status'] ?? '') === 'declined'): ?>
                    <div class="social-alert is-warning">Bu mesaj isteği reddedildi. Yeni mesaj gönderilemez.</div>
                <?php endif; ?>

                <?php if ($selectedConversation && $firstMessageId > 0 && count($messages) >= 100): ?>
                    <div class="social-chat-history"><a class="btn btn-ghost social-small-btn" href="mesajlar.php?sohbet=<?= (int)$selectedConversation['id'] ?>&once=<?= $firstMessageId ?>">Daha eski mesajları yükle</a></div>
                <?php endif; ?>

                <div class="social-dm-messages" data-dm-messages tabindex="0" aria-live="polite">
                    <?php if ($messages): ?>
                        <?php foreach ($messages as $message): ?>
                            <?php
                            $senderProfile = $profilesById[(int)$message['sender_id']] ?? [
                                'user_id' => (int)$message['sender_id'],
                                'username' => (string)$message['sender_username'],
                                'display_name' => (string)$message['sender_display'],
                                'avatar' => (string)($message['sender_avatar'] ?? ''),
                            ];
                            $isMine = !empty($message['mine']);
                            $isRead = !$isMine || (int)$message['id'] <= (int)(($selectedConversation['reads'] ?? [])[(string)($targetProfile['user_id'] ?? 0)] ?? 0);
                            ?>
                            <article class="social-dm-message<?= $isMine ? ' is-mine' : '' ?><?= ($message['status'] ?? 'published') !== 'published' ? ' is-muted-message' : '' ?>" data-message-id="<?= (int)$message['id'] ?>">
                                <a href="<?= e(social_profile_url($senderProfile)) ?>"><?= social_avatar_html($senderProfile) ?></a>
                                <div class="social-dm-bubble">
                                    <div class="social-dm-meta">
                                        <strong><?= e($message['sender_display']) ?></strong>
                                        <time title="<?= e($message['date_label']) ?>"><?= e($message['time_label']) ?></time>
                                        <?php if ($isMine && $isRead): ?><span class="social-read-label">Okundu</span><?php endif; ?>
                                    </div>
                                    <div class="social-rich-text"><?= $message['html'] ?></div>
                                    <div class="social-message-tools">
                                        <?php if ($isMine && ($message['status'] ?? 'published') === 'published'): ?>
                                            <form method="post" action="aksiyon.php" data-social-ajax="dm-delete" data-confirm="Bu mesaj herkes için silinsin mi?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="islem" value="mesaj_sil">
                                                <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                                <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>">
                                                <input type="hidden" name="return" value="<?= e($selectedReturn) ?>">
                                                <button class="social-text-button is-danger" type="submit">Sil</button>
                                            </form>
                                        <?php elseif (!$isMine && ($message['status'] ?? 'published') === 'published'): ?>
                                            <?= social_generic_report_form('direct_message', (int)$message['id'], $selectedReturn, ['conversation_id' => (int)$selectedConversation['id']]) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="social-empty is-small" data-dm-empty><h3>İlk mesajı sen gönder.</h3><p>Saygılı bir selamla başla; mağaza işlemleri için ürün keylerini burada isteme veya paylaşma.</p></div>
                    <?php endif; ?>
                    <span id="mesaj-son" class="social-sr-only"></span>
                </div>
                <button type="button" class="btn btn-primary social-small-btn social-chat-new" data-dm-new hidden>Yeni mesaj ↓</button>
                <p class="social-chat-typing" data-dm-typing hidden></p>

                <?php if ($dmError !== ''): ?>
                    <div class="social-alert is-warning"><?= e($dmError) ?></div>
                <?php elseif (($selectedConversation['status'] ?? 'new') !== 'declined'): ?>
                    <form method="post" action="aksiyon.php" class="social-dm-form" data-dm-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="mesaj_gonder">
                        <input type="hidden" name="conversation_id" value="<?= (int)($selectedConversation['id'] ?? 0) ?>">
                        <input type="hidden" name="target_id" value="<?= (int)$targetProfile['user_id'] ?>">
                        <input type="hidden" name="return" value="<?= e($selectedReturn) ?>">
                        <label class="social-sr-only" for="dm-body">Mesajın</label>
                        <textarea id="dm-body" name="body" maxlength="2000" rows="3" required data-character-count placeholder="Mesajını yaz… Enter gönderir, Shift+Enter yeni satır."></textarea>
                        <div class="social-chat-form-side">
                            <span data-count-for="dm-body">0 / 2000</span>
                            <button class="btn btn-primary" type="submit">Gönder</button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="social-empty social-dm-welcome">
                    <p class="eyebrow">Mesaj kutusu</p>
                    <h2>Bir konuşma seç veya yeni sohbet başlat.</h2>
                    <p>Özel mesajlar yalnızca katılımcılara görünür. Bir sorun bildirirsen moderatör yalnız ilgili mesaj çevresindeki sınırlı bağlamı inceleyebilir.</p>
                    <div class="social-action-row">
                        <a class="btn btn-primary" href="uyeler.php">Üyeleri keşfet</a>
                        <a class="btn btn-ghost" href="sohbet.php">Canlı sohbete katıl</a>
                    </div>
                    <?php if ((int)$tabCounts['istekler'] > 0): ?><p><a class="text-link" href="mesajlar.php?sekme=istekler"><?= (int)$tabCounts['istekler'] ?> bekleyen mesaj isteğin var.</a></p><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<script src="../assets/js/social.js" defer></script>
<script src="../assets/js/social-messages.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
