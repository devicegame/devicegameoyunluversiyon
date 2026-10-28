<?php
if (!isset($post) || !is_array($post)) return;
$socialPostId = (int)($post['id'] ?? 0);
$socialCardTopic = $socialTopicMap[(string)($post['topic_slug'] ?? '')] ?? null;
$socialCardProfile = [
    'user_id' => (int)($post['user_id'] ?? 0),
    'username' => (string)($post['author_username'] ?? ''),
    'display_name' => (string)($post['author_display'] ?? $post['author_username'] ?? 'Oyuncu'),
    'avatar' => (string)($post['author_avatar'] ?? ''),
];
$socialCardState = $socialStates[$socialPostId] ?? ['gg' => false, 'saved' => false, 'poll_vote' => '', 'rsvp' => false, 'watching' => false, 'unread' => false];
$socialReturn = social_current_return_url('gonderi-' . $socialPostId);
?>
<article id="gonderi-<?= $socialPostId ?>" class="social-post-card<?= !empty($post['pinned']) ? ' is-pinned' : '' ?><?= !empty($post['award']) ? ' is-awarded' : '' ?><?= !empty($socialCardState['unread']) ? ' is-unread' : '' ?>" aria-labelledby="post-title-<?= $socialPostId ?>">
    <div class="social-post-top">
        <a class="social-author" href="<?= e(social_profile_url($socialCardProfile)) ?>">
            <?= social_avatar_html($socialCardProfile) ?>
            <span>
                <strong><?= e($socialCardProfile['display_name']) ?></strong>
                <small>@<?= e($socialCardProfile['username']) ?> · <?= e(social_time_ago((int)($post['created_at'] ?? time()))) ?></small>
            </span>
        </a>
        <div class="social-post-badges">
            <?php if ($socialCardTopic): ?>
                <a class="social-topic-badge" href="<?= e(social_feed_url(['konu' => (string)$socialCardTopic['slug']])) ?>"><?= e($socialCardTopic['icon'] ?? '💬') ?> <?= e($socialCardTopic['name']) ?></a>
            <?php endif; ?>
            <?= social_prefix_badge_html($post) ?>
            <?php if (!empty($socialCardState['unread'])): ?><span class="social-mini-badge is-unread" data-unread-badge>Yeni</span><?php endif; ?>
            <?php if ((int)($post['solution_comment_id'] ?? 0) > 0): ?><span class="social-mini-badge is-solution">✓ Çözüldü</span><?php endif; ?>
            <?= social_award_badge_html($post) ?>
            <?php if (!empty($post['poll'])): ?><span class="social-mini-badge">Anket</span><?php endif; ?>
            <?php if (social_is_event_post($post)): ?><span class="social-mini-badge">Etkinlik</span><?php endif; ?>
            <?php if (!empty($post['pinned'])): ?><span class="social-mini-badge">Sabit</span><?php endif; ?>
            <?php if (!empty($post['locked'])): ?><span class="social-mini-badge">Kilitli</span><?php endif; ?>
            <?php if (($post['status'] ?? 'published') !== 'published'): ?><span class="social-mini-badge is-warning"><?= e(social_status_label((string)$post['status'])) ?></span><?php endif; ?>
        </div>
    </div>

    <h3 id="post-title-<?= $socialPostId ?>" class="social-post-title">
        <a href="<?= e(social_post_url($socialPostId)) ?>"><?= e($post['title'] ?? '') ?></a>
    </h3>
    <?php if (trim((string)($post['excerpt'] ?? '')) !== ''): ?>
        <p class="social-post-excerpt"><?= e($post['excerpt']) ?></p>
    <?php endif; ?>
    <div class="social-post-stats" aria-label="Konu istatistikleri">
        <span>👁 <?= number_format((int)($post['view_count'] ?? 0), 0, ',', '.') ?> görüntülenme</span>
        <span>💬 <?= (int)($post['comment_count'] ?? 0) ?> yorum</span>
        <?php if ((int)($post['watch_count'] ?? 0) > 0): ?><span>🔔 <?= (int)$post['watch_count'] ?> takipçi</span><?php endif; ?>
        <span>Son aktivite <?= e(social_time_ago((int)($post['last_activity_at'] ?? $post['created_at'] ?? time()))) ?></span>
    </div>

    <?php if (!empty($post['image'])): ?>
        <a class="social-post-media" href="<?= e(social_post_url($socialPostId)) ?>" aria-label="<?= e($post['title'] ?? 'Gönderiyi aç') ?>">
            <img src="<?= e(social_image_url((string)$post['image'])) ?>" alt="" loading="lazy" decoding="async" onerror="this.closest('.social-post-media')?.remove()">
        </a>
    <?php endif; ?>

    <?php if (!empty($post['meta']) && !social_is_event_post($post) && array_filter((array)$post['meta'], static fn($value): bool => trim((string)$value) !== '')): ?>
        <div class="social-lfg-chips" aria-label="Oyun arkadaşı bilgileri">
            <?php foreach ((array)$post['meta'] as $key => $value): ?>
                <?php $labelValue = social_meta_value_label((string)$key, $value); ?>
                <?php if ($labelValue !== ''): ?><span><b><?= e(social_lfg_label((string)$key)) ?>:</b> <?= e($labelValue) ?></span><?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= social_event_html($post, $socialUser, $socialCardState, $socialReturn) ?>
    <?= social_poll_html($post, $socialUser, $socialCardState, $socialReturn) ?>

    <?php if (!empty($post['tags'])): ?>
        <div class="social-tags">
            <?php foreach ((array)$post['tags'] as $tag): ?>
                <a href="index.php?q=<?= rawurlencode('#' . (string)$tag) ?>" data-preserve-scroll>#<?= e($tag) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="social-post-actions">
        <?php if ($socialUser): ?>
            <form method="post" action="aksiyon.php" data-social-ajax="gg">
                <?= csrf_field() ?>
                <input type="hidden" name="islem" value="gg">
                <input type="hidden" name="post_id" value="<?= $socialPostId ?>">
                <input type="hidden" name="return" value="<?= e($socialReturn) ?>">
                <button type="submit" class="social-action<?= !empty($socialCardState['gg']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($socialCardState['gg']) ? 'true' : 'false' ?>">GG <span data-gg-count><?= (int)($post['gg_count'] ?? 0) ?></span></button>
            </form>
        <?php else: ?>
            <a class="social-action" href="<?= e(social_login_url('sosyal/' . $socialReturn)) ?>">GG <span><?= (int)($post['gg_count'] ?? 0) ?></span></a>
        <?php endif; ?>

        <a class="social-action" href="<?= e(social_post_url($socialPostId)) ?>#yorumlar">💬 <?= (int)($post['comment_count'] ?? 0) ?> yorum</a>

        <?php if ($socialUser): ?>
            <form method="post" action="aksiyon.php" data-social-ajax="save">
                <?= csrf_field() ?>
                <input type="hidden" name="islem" value="kaydet">
                <input type="hidden" name="post_id" value="<?= $socialPostId ?>">
                <input type="hidden" name="return" value="<?= e($socialReturn) ?>">
                <button type="submit" class="social-action<?= !empty($socialCardState['saved']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($socialCardState['saved']) ? 'true' : 'false' ?>"><?= !empty($socialCardState['saved']) ? 'Kaydedildi' : 'Kaydet' ?></button>
            </form>
            <form method="post" action="aksiyon.php" data-social-ajax="watch">
                <?= csrf_field() ?>
                <input type="hidden" name="islem" value="gonderi_takip">
                <input type="hidden" name="post_id" value="<?= $socialPostId ?>">
                <input type="hidden" name="return" value="<?= e($socialReturn) ?>">
                <button type="submit" class="social-action<?= !empty($socialCardState['watching']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($socialCardState['watching']) ? 'true' : 'false' ?>" data-label-active="Takip ediliyor" data-label-inactive="Takip et"><?= !empty($socialCardState['watching']) ? 'Takip ediliyor' : 'Takip et' ?></button>
            </form>
        <?php endif; ?>
    </div>
</article>
