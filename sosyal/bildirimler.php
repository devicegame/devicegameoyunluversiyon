<?php
require_once __DIR__ . '/_common.php';
social_require_login();

$filter = (string)($_GET['filtre'] ?? 'tumu');
$filterGroups = [
    'tumu' => null,
    'okunmamis' => null,
    'etkilesim' => ['comment', 'reply', 'mention', 'follow', 'solution'],
    'takip' => ['watch_comment', 'watch_topic'],
    'mesajlar' => ['direct_message', 'chat_mention'],
    'etkinlik' => ['event_rsvp', 'award'],
    'sistem' => ['warning', 'announcement', 'moderation'],
];
if (!array_key_exists($filter, $filterGroups)) $filter = 'tumu';
$filterLabels = [
    'tumu' => 'Tümü',
    'okunmamis' => 'Okunmamış',
    'etkilesim' => 'Etkileşimler',
    'takip' => 'Takip ettiklerim',
    'mesajlar' => 'Mesajlar',
    'etkinlik' => 'Etkinlik & ödül',
    'sistem' => 'Sistem',
];
$allNotifications = social_notifications((int)$socialUser['id']);
$unread = social_unread_notification_count((int)$socialUser['id']);
$filterCounts = [];
foreach ($filterGroups as $key => $types) {
    if ($key === 'tumu') {
        $filterCounts[$key] = count($allNotifications);
    } elseif ($key === 'okunmamis') {
        $filterCounts[$key] = $unread;
    } else {
        $filterCounts[$key] = count(array_filter($allNotifications, static fn(array $item): bool => in_array((string)($item['type'] ?? ''), (array)$types, true)));
    }
}
$notifications = array_values(array_filter($allNotifications, static function (array $notification) use ($filter, $filterGroups): bool {
    if ($filter === 'tumu') return true;
    if ($filter === 'okunmamis') return empty($notification['read']);
    return in_array((string)($notification['type'] ?? ''), (array)$filterGroups[$filter], true);
}));
$actorIds = array_map(static fn(array $item): int => (int)($item['actor_id'] ?? 0), $notifications);
$profiles = social_profiles_by_ids($actorIds);
$returnUrl = 'bildirimler.php' . ($filter !== 'tumu' ? '?filtre=' . rawurlencode($filter) : '');
$dmUnread = social_dm_unread_count((int)$socialUser['id']);

$page_title = 'Bildirimler — DG Social';
$page_meta_desc = 'DG Social bildirimlerin: yorumlar, cevaplar, takipler, mesajlar ve bahsetmeler.';
$page_canonical = false;
$page_robots = 'noindex,nofollow';
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell is-narrow">
    <header class="social-page-head">
        <p class="eyebrow">DG Social</p>
        <h1>Bildirimler.</h1>
        <p class="muted"><?= $unread > 0 ? $unread . ' okunmamış bildirimin var.' : 'Okunmamış bildirimin yok.' ?><?= $dmUnread > 0 ? ' Ayrıca ' . $dmUnread . ' okunmamış özel mesajın bulunuyor.' : '' ?></p>
    </header>

    <?= social_subnav('notifications') ?>
    <?= social_flash_html() ?>

    <nav class="social-notification-filters" aria-label="Bildirim türleri">
        <?php foreach ($filterLabels as $key => $label): ?>
            <a href="<?= $key === 'tumu' ? 'bildirimler.php' : 'bildirimler.php?filtre=' . rawurlencode($key) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?> data-preserve-scroll><?= e($label) ?> <span><?= (int)$filterCounts[$key] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($allNotifications && $unread > 0): ?>
        <form method="post" action="aksiyon.php" class="social-notifications-tools" data-social-ajax="notifications-read">
            <?= csrf_field() ?>
            <input type="hidden" name="islem" value="bildirim_okundu">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <button class="btn btn-ghost social-small-btn" type="submit">Tümünü okundu işaretle</button>
        </form>
    <?php endif; ?>

    <?php if (!$notifications): ?>
        <div class="social-empty">
            <h2><?= $allNotifications ? 'Bu filtrede bildirim yok.' : 'Henüz bildirimin yok.' ?></h2>
            <p>Bir gönderine yorum yapıldığında, izlediğin konu hareketlendiğinde, biri seni takip ettiğinde veya özel mesaj aldığında burada görünecek.</p>
            <a class="btn btn-primary" href="index.php" data-preserve-scroll>Akışa git</a>
        </div>
    <?php else: ?>
        <div class="social-notification-list" id="bildirim-listesi">
            <?php $currentNotificationDay = ''; ?>
            <?php foreach ($notifications as $notification): ?>
                <?php
                $actorId = (int)($notification['actor_id'] ?? 0);
                $actor = $profiles[$actorId] ?? ['user_id' => $actorId, 'username' => 'oyuncu', 'display_name' => 'Bir oyuncu'];
                $type = (string)($notification['type'] ?? '');
                $icon = match ($type) {
                    'comment' => '💬',
                    'reply' => '↩',
                    'follow' => '👥',
                    'mention', 'chat_mention' => '@',
                    'event_rsvp' => '🗓',
                    'award' => '🏆',
                    'solution' => '✓',
                    'watch_comment', 'watch_topic' => '🔔',
                    'direct_message' => '✉',
                    'warning', 'moderation' => '⚠',
                    'announcement' => '📣',
                    default => '🔔',
                };
                $typeLabel = match ($type) {
                    'comment' => 'Yeni yorum',
                    'reply' => 'Cevap',
                    'follow' => 'Yeni takipçi',
                    'mention' => 'Bahsetme',
                    'chat_mention' => 'Sohbet bahsetmesi',
                    'event_rsvp' => 'Etkinlik katılımı',
                    'award' => 'Editör Ödülü',
                    'solution' => 'Kabul edilen çözüm',
                    'watch_comment' => 'Takip edilen konu',
                    'watch_topic' => 'Takip edilen kategori',
                    'direct_message' => 'Özel mesaj',
                    'warning', 'moderation' => 'Moderasyon',
                    'announcement' => 'Duyuru',
                    default => 'Bildirim',
                };
                $createdAt = (int)($notification['created_at'] ?? time());
                $dayLabel = date('Y-m-d', $createdAt) === date('Y-m-d') ? 'Bugün' : (date('Y-m-d', $createdAt) === date('Y-m-d', strtotime('-1 day')) ? 'Dün' : 'Daha önce');
                ?>
                <?php if ($dayLabel !== $currentNotificationDay): $currentNotificationDay = $dayLabel; ?><h2 class="social-notification-day"><?= e($dayLabel) ?></h2><?php endif; ?>
                <article class="social-notification<?= empty($notification['read']) ? ' is-unread' : '' ?>" id="bildirim-<?= (int)($notification['id'] ?? 0) ?>">
                    <span class="social-notification-icon" aria-hidden="true"><?= e($icon) ?></span>
                    <?= social_avatar_html($actor) ?>
                    <div>
                        <span class="social-notification-type"><?= e($typeLabel) ?></span>
                        <p><?= e($notification['message'] ?? '') ?></p>
                        <small>@<?= e($actor['username']) ?> · <time title="<?= e(social_format_date($createdAt)) ?>"><?= e(social_time_ago($createdAt)) ?></time><?= empty($notification['read']) ? ' · Okunmadı' : '' ?></small>
                    </div>
                    <form method="post" action="aksiyon.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="bildirim_ac">
                        <input type="hidden" name="notification_id" value="<?= (int)($notification['id'] ?? 0) ?>">
                        <button class="btn btn-ghost social-small-btn" type="submit">Aç</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="muted social-notification-footnote">En son 100 bildirim saklanır. Bildirim türlerini profil ayarlarından yönetebilirsin.</p>
    <?php endif; ?>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
