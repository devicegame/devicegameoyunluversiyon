<?php
require_once __DIR__ . '/_common.php';

$postId = (int)($_GET['id'] ?? $_POST['post_id'] ?? 0);
$commentErrors = [];
$commentBody = '';
$commentParentId = 0;
$editingCommentId = 0;
$editingCommentBody = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['social_action'] ?? ''), ['comment_create', 'comment_edit'], true)) {
    social_require_login();
    $action = (string)$_POST['social_action'];
    $submittedBody = (string)($_POST['body'] ?? '');
    // Düzenleme hatası ana yorum kutusunu doldurmasın; metin ilgili düzenleme alanında kalsın.
    $commentBody = $action === 'comment_create' ? $submittedBody : '';
    $editingCommentId = $action === 'comment_edit' ? (int)($_POST['comment_id'] ?? 0) : 0;
    $editingCommentBody = $action === 'comment_edit' ? $submittedBody : '';
    $commentParentId = (int)($_POST['parent_id'] ?? 0);
    if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
        $commentErrors[] = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.';
    } else {
        try {
            if ($action === 'comment_create') {
                $result = social_create_comment($socialUser, $postId, $_POST);
            } else {
                $result = social_edit_comment($socialUser, $postId, $editingCommentId, $_POST);
            }
        } catch (Throwable $exception) {
            error_log('[social comment] ' . $exception->getMessage());
            $result = ['errors' => ['Yorum kaydedilemedi. Depolama izinlerini kontrol edin.'], 'id' => $editingCommentId, 'status' => ''];
        }
        if (!empty($result['errors'])) {
            $commentErrors = $result['errors'];
        } else {
            flash_set(($result['status'] ?? '') === 'pending'
                ? 'Yorumun alındı ve moderasyon onayına gönderildi.'
                : ($action === 'comment_edit' ? 'Yorumun güncellendi.' : 'Yorumun yayınlandı.'));
            $targetComment = $action === 'comment_edit' ? $editingCommentId : (int)($result['id'] ?? 0);
            header('Location: ' . social_post_url($postId, $targetComment));
            exit;
        }
    }
}

$post = $postId > 0 ? social_get_post($postId) : null;
$blockedIds = social_blocked_ids($socialUser);
if (!$post || !social_post_visible($post, $socialUser, $blockedIds)) {
    http_response_code(404);
    $page_title = 'Gönderi bulunamadı — DG Social';
    $page_meta_desc = 'Aradığın DG Social gönderisi bulunamadı veya yayından kaldırıldı.';
    $page_canonical = false;
    $page_robots = 'noindex,nofollow';
    $page_css = ['shop.css', 'social.css'];
    $body_class = 'shop-page social-page';
    include __DIR__ . '/../header.php';
    ?>
    <section class="social-shell is-narrow">
        <div class="social-empty">
            <p class="eyebrow">404</p>
            <h1>Bu gönderiyi bulamadık.</h1>
            <p>Gönderi silinmiş, gizlenmiş veya adresi değişmiş olabilir.</p>
            <a class="btn btn-primary" href="index.php">Akışa dön</a>
        </div>
    </section>
    <?php
    include __DIR__ . '/../footer.php';
    exit;
}

$topic = $socialTopicMap[(string)$post['topic_slug']] ?? social_topic((string)$post['topic_slug'], false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $viewedPost = social_record_post_view($postId, $socialUser);
    if ($viewedPost) $post = $viewedPost;
    if ($socialUser) social_mark_post_read($socialUser, $post);
}
$commentSort = (string)($_GET['yorum_sira'] ?? 'eski');
if (!in_array($commentSort, ['eski', 'yeni', 'populer'], true)) $commentSort = 'eski';
$commentSortInternal = $commentSort === 'yeni' ? 'new' : ($commentSort === 'populer' ? 'top' : 'old');
$comments = social_comments($postId, $socialUser, $commentSortInternal);
$commentAuthorIds = [(int)$post['user_id']];
foreach ($comments as $comment) {
    $commentAuthorIds[] = (int)$comment['user_id'];
    foreach ((array)($comment['replies'] ?? []) as $reply) $commentAuthorIds[] = (int)$reply['user_id'];
}
$profiles = social_profiles_by_ids($commentAuthorIds);
$authorProfile = $profiles[(int)$post['user_id']] ?? [
    'user_id' => (int)$post['user_id'],
    'username' => (string)$post['author_username'],
    'display_name' => (string)$post['author_display'],
    'bio' => '',
    'follower_count' => 0,
    'post_count' => 0,
];
$authorUser = (int)$post['user_id'] > 0 && function_exists('dg_find_user_by_id') ? dg_find_user_by_id((int)$post['user_id']) : null;
$socialStates = social_post_states([$postId], $socialUser);
$postState = $socialStates[$postId] ?? ['gg' => false, 'saved' => false, 'poll_vote' => '', 'rsvp' => false];
$isAuthor = $socialUser && (int)$socialUser['id'] === (int)$post['user_id'];
$isAdmin = !empty($socialUser['is_admin']);
$isFollowingAuthor = false;
if ($socialUser && !$isAuthor) {
    $isFollowingAuthor = in_array((int)$post['user_id'], social_follows((int)$socialUser['id'])['following'], true);
}
$relatedResult = social_list_posts(['topic' => (string)$post['topic_slug'], 'per_page' => 8], $socialUser);
$relatedPosts = array_slice(array_values(array_filter($relatedResult['items'], static fn(array $item): bool => (int)$item['id'] !== $postId)), 0, 3);
$relatedStates = social_post_states(array_map(static fn(array $item): int => (int)$item['id'], $relatedPosts), $socialUser);
$returnUrl = 'gonderi.php?id=' . $postId . ($commentSort !== 'eski' ? '&yorum_sira=' . rawurlencode($commentSort) : '');
$topicWatchState = $socialUser ? social_watches((int)$socialUser['id']) : ['topics' => [], 'muted_topics' => []];
$watchingTopic = $topic && in_array((string)$topic['slug'], (array)$topicWatchState['topics'], true);

function social_detail_report_form(string $type, int $targetId, int $postId, string $returnUrl): string
{
    $html = '<details class="social-report"><summary>Şikâyet et</summary>';
    $html .= '<form method="post" action="aksiyon.php" data-social-ajax="report">' . csrf_field();
    $html .= '<input type="hidden" name="islem" value="sikayet">';
    $html .= '<input type="hidden" name="target_type" value="' . e($type) . '">';
    $html .= '<input type="hidden" name="target_id" value="' . $targetId . '">';
    $html .= '<input type="hidden" name="post_id" value="' . $postId . '">';
    $html .= '<input type="hidden" name="return" value="' . e($returnUrl) . '">';
    $html .= '<label>Sebep<select name="reason"><option>Spam</option><option>Hakaret / Taciz</option><option>Dolandırıcılık</option><option>Uygunsuz içerik</option><option>Telif</option><option>Diğer</option></select></label>';
    $html .= '<label>Açıklama<textarea name="details" maxlength="600" rows="3" placeholder="Moderatör için kısa not (isteğe bağlı)"></textarea></label>';
    $html .= '<button class="btn btn-ghost social-small-btn" type="submit">Şikâyeti gönder</button></form></details>';
    return $html;
}

function social_render_comment(array $comment, bool $isReply = false): void
{
    global $post, $profiles, $socialUser, $isAdmin, $returnUrl, $editingCommentId, $editingCommentBody;
    $commentId = (int)$comment['id'];
    $profile = $profiles[(int)$comment['user_id']] ?? [
        'user_id' => (int)$comment['user_id'],
        'username' => (string)$comment['author_username'],
        'display_name' => (string)$comment['author_display'],
    ];
    $isOwn = $socialUser && (int)$socialUser['id'] === (int)$comment['user_id'];
    ?>
    <article id="yorum-<?= $commentId ?>" class="social-comment<?= $isReply ? ' is-reply' : '' ?><?= !empty($comment['is_solution']) ? ' is-solution' : '' ?>">
        <div class="social-comment-head">
            <a class="social-author" href="<?= e(social_profile_url($profile)) ?>">
                <?= social_avatar_html($profile) ?>
                <span><strong><?= e($profile['display_name']) ?></strong><small>@<?= e($profile['username']) ?> · <time title="<?= e(social_format_date((int)$comment['created_at'])) ?>"><?= e(social_time_ago((int)$comment['created_at'])) ?></time></small></span>
            </a>
            <?php if ((int)$comment['user_id'] === (int)$post['user_id']): ?><span class="social-mini-badge is-author">Konu sahibi</span><?php endif; ?>
            <?php if (!empty($comment['is_solution'])): ?><span class="social-mini-badge is-solution" data-solution-badge>✓ Kabul edilen çözüm</span><?php endif; ?>
            <?php if ((string)$comment['status'] !== 'published'): ?><span class="social-mini-badge is-warning"><?= e(social_status_label((string)$comment['status'])) ?></span><?php endif; ?>
        </div>
        <div class="social-rich-text"><?= social_render_text((string)$comment['body']) ?></div>
        <?php if (trim((string)($profile['signature'] ?? '')) !== ''): ?><div class="social-comment-signature"><?= social_render_text((string)$profile['signature']) ?></div><?php endif; ?>
        <?php if (!empty($comment['edited_at'])): ?><p class="social-edited">Düzenlendi · <?= e(social_format_date((int)$comment['edited_at'])) ?></p><?php endif; ?>

        <div class="social-comment-actions">
            <?php if ($socialUser): ?>
                <form method="post" action="aksiyon.php" data-social-ajax="comment-gg">
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="yorum_gg">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl . '#yorum-' . $commentId) ?>">
                    <button type="submit" class="social-text-button<?= !empty($comment['viewer_gg']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($comment['viewer_gg']) ? 'true' : 'false' ?>">GG <span data-comment-gg-count><?= (int)($comment['gg_count'] ?? 0) ?></span></button>
                </form>
            <?php else: ?>
                <span class="social-text-button is-static">GG <?= (int)($comment['gg_count'] ?? 0) ?></span>
            <?php endif; ?>
            <button type="button" class="social-text-button" data-copy-link data-copy-url="<?= e(social_post_url((int)$post['id'], $commentId)) ?>">Bağlantı</button>
            <?php if ($socialUser && empty($post['locked'])): ?>
                <button type="button" class="social-text-button" data-quote-button data-parent-id="<?= $isReply ? (int)$comment['parent_id'] : $commentId ?>" data-author="<?= e($profile['display_name']) ?>" data-quote="<?= e(social_excerpt((string)$comment['body'], 320)) ?>">Alıntıla</button>
            <?php endif; ?>
            <?php if ($socialUser && !$isReply && empty($post['locked'])): ?>
                <button type="button" class="social-text-button" data-reply-button data-parent-id="<?= $commentId ?>" data-author="@<?= e($profile['username']) ?>">Cevapla</button>
            <?php endif; ?>
            <?php if ($socialUser && !$isReply && ((int)$socialUser['id'] === (int)$post['user_id'] || $isAdmin) && (int)$comment['user_id'] !== (int)$post['user_id'] && (string)$comment['status'] === 'published'): ?>
                <form method="post" action="aksiyon.php" data-social-ajax="solution">
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="cozum">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl . '#yorum-' . $commentId) ?>">
                    <button type="submit" class="social-text-button<?= !empty($comment['is_solution']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($comment['is_solution']) ? 'true' : 'false' ?>" data-label-active="Çözümü kaldır" data-label-inactive="Çözüm seç"><?= !empty($comment['is_solution']) ? 'Çözümü kaldır' : 'Çözüm seç' ?></button>
                </form>
            <?php endif; ?>
            <?php if ($socialUser && ($isOwn || $isAdmin)): ?>
                <details class="social-inline-edit"<?= $editingCommentId === $commentId ? ' open' : '' ?>>
                    <summary>Düzenle</summary>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="social_action" value="comment_edit">
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                        <textarea name="body" maxlength="1500" rows="4" required><?= e($editingCommentId === $commentId && $editingCommentBody !== '' ? $editingCommentBody : (string)$comment['body']) ?></textarea>
                        <button class="btn btn-primary social-small-btn" type="submit">Kaydet</button>
                    </form>
                </details>
                <form method="post" action="aksiyon.php" data-confirm="Bu yorum silinsin mi?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="yorum_sil">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl . '#yorumlar') ?>">
                    <button class="social-text-button is-danger" type="submit">Sil</button>
                </form>
            <?php endif; ?>
            <?php if ($isAdmin && (string)$comment['status'] !== 'published'): ?>
                <form method="post" action="aksiyon.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="yorum_onayla">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl . '#yorum-' . $commentId) ?>">
                    <button class="social-text-button" type="submit">Yayınla</button>
                </form>
            <?php endif; ?>
            <?php if ($isAdmin && (string)$comment['status'] === 'published'): ?>
                <form method="post" action="aksiyon.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="yorum_gizle">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl . '#yorum-' . $commentId) ?>">
                    <button class="social-text-button" type="submit">Gizle</button>
                </form>
            <?php endif; ?>
            <?php if ($socialUser && !$isOwn): ?>
                <?= social_detail_report_form('comment', $commentId, (int)$post['id'], $returnUrl . '#yorum-' . $commentId) ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($comment['replies'])): ?>
            <div class="social-replies">
                <?php foreach ((array)$comment['replies'] as $reply) social_render_comment($reply, true); ?>
            </div>
        <?php endif; ?>
    </article>
    <?php
}

$page_title = $post['title'] . ' — DG Social';
$page_meta_desc = social_excerpt((string)$post['body'], 155);
$isPublishedPost = ($post['status'] ?? '') === 'published';
$page_canonical = $isPublishedPost ? 'sosyal/gonderi.php?id=' . $postId : false;
$page_robots = $isPublishedPost ? '' : 'noindex,nofollow';
$page_og_type = 'article';
$page_og_title = (string)$post['title'];
$page_og_desc = $page_meta_desc;
$page_og_image = !empty($post['image']) ? (string)$post['image'] : 'assets/img/devicegame-og.png';
$page_og_image_alt = (string)$post['title'];
$page_published_time = (int)$post['created_at'];
$page_modified_time = max((int)$post['updated_at'], (int)($post['edited_at'] ?? 0), (int)$post['last_activity_at']);
$page_article_author = function_exists('dg_absolute_url') ? dg_absolute_url('sosyal/' . social_profile_url($authorProfile)) : '';
$page_article_section = $topic ? (string)$topic['name'] : 'DG Social';
$page_article_tags = (array)$post['tags'];
$shareUrl = function_exists('dg_absolute_url') ? dg_absolute_url('sosyal/gonderi.php?id=' . $postId) : $returnUrl;
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell">
    <nav class="social-breadcrumb" aria-label="Konum">
        <a href="index.php">DG Social</a><span>/</span>
        <?php if ($topic): ?><a href="<?= e(social_feed_url(['konu' => (string)$topic['slug']])) ?>"><?= e($topic['name']) ?></a><span>/</span><?php endif; ?>
        <span aria-current="page"><?= e($post['title']) ?></span>
    </nav>

    <?= social_subnav('feed') ?>
    <?= social_flash_html() ?>

    <?php if ((string)$post['status'] === 'pending'): ?>
        <div class="social-alert is-warning">Bu gönderi moderasyon onayını bekliyor. Şu anda yalnızca sen ve yöneticiler görebilir.</div>
    <?php elseif ((string)$post['status'] === 'hidden'): ?>
        <div class="social-alert is-error">Bu gönderi moderasyon tarafından gizlendi.</div>
    <?php endif; ?>

    <div class="social-detail-layout">
        <article id="gonderi-detay" class="social-detail-card">
            <div class="social-post-top">
                <a class="social-author" href="<?= e(social_profile_url($authorProfile)) ?>">
                    <?= social_avatar_html($authorProfile, 'is-large') ?>
                    <span><strong><?= e($authorProfile['display_name']) ?></strong><small>@<?= e($authorProfile['username']) ?> · <?= e(social_format_date((int)$post['created_at'])) ?></small></span>
                </a>
                <div class="social-post-badges">
                    <?php if ($topic): ?><a class="social-topic-badge" href="<?= e(social_feed_url(['konu' => (string)$topic['slug']])) ?>"><?= e($topic['icon']) ?> <?= e($topic['name']) ?></a><?php endif; ?>
                    <?= social_prefix_badge_html($post) ?>
                    <?php if ((int)($post['solution_comment_id'] ?? 0) > 0): ?><a class="social-mini-badge is-solution" href="#yorum-<?= (int)$post['solution_comment_id'] ?>">✓ Çözüldü</a><?php endif; ?>
                    <?= social_award_badge_html($post) ?>
                    <?php if (!empty($post['poll'])): ?><span class="social-mini-badge">Anket</span><?php endif; ?>
                    <?php if (social_is_event_post($post)): ?><span class="social-mini-badge">Etkinlik</span><?php endif; ?>
                    <?php if (!empty($post['pinned'])): ?><span class="social-mini-badge">Sabit</span><?php endif; ?>
                    <?php if (!empty($post['locked'])): ?><span class="social-mini-badge">Kilitli</span><?php endif; ?>
                </div>
            </div>

            <h1><?= e($post['title']) ?></h1>
            <div class="social-post-stats is-detail" aria-label="Konu istatistikleri">
                <span>👁 <?= number_format((int)($post['view_count'] ?? 0), 0, ',', '.') ?> görüntülenme</span>
                <span>💬 <?= (int)$post['comment_count'] ?> yorum</span>
                <span data-watch-wrap>🔔 <span data-watch-count><?= (int)($post['watch_count'] ?? 0) ?></span> izleyen</span>
                <span>Son aktivite <?= e(social_time_ago((int)$post['last_activity_at'])) ?></span>
                <?php if ($authorProfile): ?><span><?= social_presence_html($authorProfile, $socialUser) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($post['image'])): ?>
                <figure class="social-detail-media"><img src="<?= e(social_image_url((string)$post['image'])) ?>" alt="<?= e($post['title']) ?> görseli" onerror="this.closest('figure')?.remove()"></figure>
            <?php endif; ?>
            <div class="social-rich-text is-post"><?= social_render_text((string)$post['body']) ?></div>

            <?= social_event_html($post, $socialUser, $postState, $returnUrl . '#etkinlik', true) ?>
            <?= social_poll_html($post, $socialUser, $postState, $returnUrl . '#gonderi-detay') ?>

            <?php if (!empty($post['award'])): ?>
                <section class="social-award-callout" aria-label="Editör Ödülü">
                    <strong>🏆 Editör Ödülü</strong>
                    <p><?= e(trim((string)($post['award']['reason'] ?? '')) !== '' ? (string)$post['award']['reason'] : 'Bu içerik topluluğa katkısı için seçildi.') ?></p>
                    <span>Bu içerik +<?= number_format((int)$post['award']['amount'], 0, ',', '.') ?> D-Coin ile ödüllendirildi<?= (int)($post['award']['awarded_at'] ?? 0) > 0 ? ' · ' . e(social_format_date((int)$post['award']['awarded_at'])) : '' ?>.</span>
                </section>
            <?php endif; ?>

            <?php if (!empty($post['meta']) && !social_is_event_post($post) && array_filter((array)$post['meta'], static fn($value): bool => trim((string)$value) !== '')): ?>
                <div class="social-lfg-summary">
                    <?php foreach ((array)$post['meta'] as $key => $value): ?>
                        <?php $labelValue = social_meta_value_label((string)$key, $value); ?>
                        <?php if ($labelValue !== ''): ?><div><span><?= e(social_lfg_label((string)$key)) ?></span><strong><?= e($labelValue) ?></strong></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($post['tags'])): ?>
                <div class="social-tags is-large">
                    <?php foreach ((array)$post['tags'] as $tag): ?><a href="index.php?q=<?= rawurlencode('#' . (string)$tag) ?>">#<?= e($tag) ?></a><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($post['edited_at'])): ?><p class="social-edited">Son düzenleme: <?= e(social_format_date((int)$post['edited_at'])) ?></p><?php endif; ?>

            <div class="social-post-actions is-detail">
                <?php if ($socialUser): ?>
                    <form method="post" action="aksiyon.php" data-social-ajax="gg">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="gg">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button type="submit" class="social-action<?= !empty($postState['gg']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($postState['gg']) ? 'true' : 'false' ?>">GG <span data-gg-count><?= (int)$post['gg_count'] ?></span></button>
                    </form>
                    <form method="post" action="aksiyon.php" data-social-ajax="save">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="kaydet">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button type="submit" class="social-action<?= !empty($postState['saved']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($postState['saved']) ? 'true' : 'false' ?>"><?= !empty($postState['saved']) ? 'Kaydedildi' : 'Kaydet' ?></button>
                    </form>
                    <form method="post" action="aksiyon.php" data-social-ajax="watch" data-watch-wrap>
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="gonderi_takip">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button type="submit" class="social-action<?= !empty($postState['watching']) ? ' is-active' : '' ?>" aria-pressed="<?= !empty($postState['watching']) ? 'true' : 'false' ?>" data-label-active="Bildirim açık" data-label-inactive="Konuyu takip et"><?= !empty($postState['watching']) ? 'Bildirim açık' : 'Konuyu takip et' ?></button>
                    </form>
                    <?php if ($topic): ?>
                        <form method="post" action="aksiyon.php" data-social-ajax="topic-watch">
                            <?= csrf_field() ?>
                            <input type="hidden" name="islem" value="konu_takip">
                            <input type="hidden" name="topic_slug" value="<?= e($topic['slug']) ?>">
                            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                            <button type="submit" class="social-action<?= $watchingTopic ? ' is-active' : '' ?>" aria-pressed="<?= $watchingTopic ? 'true' : 'false' ?>" data-label-active="<?= e($topic['name']) ?> takipte" data-label-inactive="<?= e($topic['name']) ?> konusunu takip et"><?= $watchingTopic ? e($topic['name']) . ' takipte' : e($topic['name']) . ' konusunu takip et' ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$isAuthor): ?><a class="social-action" href="<?= e(social_message_url($authorProfile)) ?>">Yazara mesaj</a><?php endif; ?>
                <?php else: ?>
                    <a class="social-action" href="<?= e(social_login_url('sosyal/' . $returnUrl)) ?>">GG <?= (int)$post['gg_count'] ?></a>
                <?php endif; ?>
                <button type="button" class="social-action" data-copy-link>Bağlantıyı kopyala</button>
                <?php if ($isPublishedPost): ?>
                    <a class="social-action" href="https://twitter.com/intent/tweet?text=<?= rawurlencode((string)$post['title']) ?>&url=<?= rawurlencode($shareUrl) ?>" target="_blank" rel="noopener">X’te paylaş</a>
                    <a class="social-action" href="https://wa.me/?text=<?= rawurlencode((string)$post['title'] . ' — ' . $shareUrl) ?>" target="_blank" rel="noopener">WhatsApp</a>
                <?php endif; ?>
                <?php if ($isAuthor || $isAdmin): ?>
                    <a class="social-action" href="yeni.php?duzenle=<?= $postId ?>">Düzenle</a>
                    <form method="post" action="aksiyon.php" data-confirm="Bu gönderi silinsin mi?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="gonderi_sil">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <button class="social-action is-danger" type="submit">Sil</button>
                    </form>
                <?php endif; ?>
                <?php if ($socialUser && !$isAuthor): ?>
                    <?= social_detail_report_form('post', $postId, $postId, $returnUrl) ?>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
                <div class="social-admin-tools">
                    <strong>Moderasyon</strong>
                    <?php if (($post['status'] ?? '') !== 'published'): ?>
                        <form method="post" action="aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_onayla"><input type="hidden" name="post_id" value="<?= $postId ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><button type="submit">Yayınla</button></form>
                    <?php else: ?>
                        <form method="post" action="aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_gizle"><input type="hidden" name="post_id" value="<?= $postId ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><button type="submit">Gizle</button></form>
                    <?php endif; ?>
                    <form method="post" action="aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_sabitle"><input type="hidden" name="post_id" value="<?= $postId ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><button type="submit"><?= !empty($post['pinned']) ? 'Sabitten kaldır' : 'Sabitle' ?></button></form>
                    <form method="post" action="aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_kilitle"><input type="hidden" name="post_id" value="<?= $postId ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><button type="submit"><?= !empty($post['locked']) ? 'Kilidi aç' : 'Kilitle' ?></button></form>
                    <form method="post" action="aksiyon.php" class="social-move-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="gonderi_tasi">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <label>Konuya taşı
                            <select name="topic_slug">
                                <?php foreach (social_topics(false) as $moveTopic): ?>
                                    <option value="<?= e($moveTopic['slug']) ?>"<?= (string)$moveTopic['slug'] === (string)$post['topic_slug'] ? ' selected' : '' ?>><?= e($moveTopic['icon']) ?> <?= e($moveTopic['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit">Taşı</button>
                    </form>
                    <?php if ($isPublishedPost && (int)$post['user_id'] > 0 && !$isAuthor): ?>
                        <?php if (empty($post['award'])): ?>
                            <details class="social-award-form">
                                <summary>🏆 Editör Ödülü ver</summary>
                                <form method="post" action="aksiyon.php" data-confirm="Bu D-Coin ödülü gönderi sahibine eklensin mi?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="islem" value="odul_ver">
                                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                                    <input type="hidden" name="return" value="<?= e($returnUrl . '#gonderi-detay') ?>">
                                    <label>D-Coin miktarı<input type="number" name="amount" min="5" max="1000" step="5" value="50" required></label>
                                    <label>Gerekçe<input type="text" name="reason" maxlength="180" required placeholder="Örn. Kapsamlı ve güncel bir rehber"></label>
                                    <button type="submit">Ödülü gönder</button>
                                    <p>Her gönderiye bir kez, 5–1000 D-Coin arasında ödül verilebilir.</p>
                                </form>
                            </details>
                        <?php else: ?>
                            <span class="social-admin-award">🏆 +<?= number_format((int)$post['award']['amount'], 0, ',', '.') ?> D-Coin ödülü verildi.</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <section id="yorumlar" class="social-comments">
                <div class="social-section-title">
                    <h2>Yorumlar</h2>
                    <span><?= (int)$post['comment_count'] ?> yayınlanmış yorum</span>
                </div>
                <div class="social-comment-toolbar">
                    <form method="get" action="gonderi.php">
                        <input type="hidden" name="id" value="<?= $postId ?>">
                        <label for="comment-sort">Sırala</label>
                        <select id="comment-sort" name="yorum_sira" onchange="this.form.submit()" data-preserve-scroll-select>
                            <option value="eski"<?= $commentSort === 'eski' ? ' selected' : '' ?>>Eskiden yeniye</option>
                            <option value="yeni"<?= $commentSort === 'yeni' ? ' selected' : '' ?>>Yeniden eskiye</option>
                            <option value="populer"<?= $commentSort === 'populer' ? ' selected' : '' ?>>En çok GG alan</option>
                        </select>
                        <noscript><button class="btn btn-ghost social-small-btn" type="submit">Uygula</button></noscript>
                    </form>
                    <?php if ((int)($post['solution_comment_id'] ?? 0) > 0): ?><a class="btn btn-ghost social-small-btn" href="#yorum-<?= (int)$post['solution_comment_id'] ?>">Kabul edilen çözüme git</a><?php endif; ?>
                </div>

                <?php if ($commentErrors): ?>
                    <div class="social-alert is-error" role="alert">
                        <ul><?php foreach ($commentErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <?php if ($comments): ?>
                    <?php foreach ($comments as $comment) social_render_comment($comment); ?>
                <?php else: ?>
                    <div class="social-empty is-small"><h3>Henüz yorum yok.</h3><p>İlk cevabı sen yaz.</p></div>
                <?php endif; ?>

                <?php if ($socialUser && (empty($post['locked']) || $isAdmin)): ?>
                    <form method="post" class="social-comment-form" id="comment-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="social_action" value="comment_create">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <input type="hidden" name="parent_id" value="<?= (int)$commentParentId ?>" data-comment-parent>
                        <div class="social-reply-banner" data-reply-banner hidden>
                            <span><b data-reply-author></b> yorumuna cevap veriyorsun.</span>
                            <button type="button" data-reply-cancel>Vazgeç</button>
                        </div>
                        <label for="social-comment-body">Yorumun</label>
                        <?= social_editor_toolbar_html('social-comment-body') ?>
                        <textarea id="social-comment-body" name="body" maxlength="1500" rows="5" required data-character-count data-comment-draft="<?= $postId ?>" placeholder="Saygılı ve konuyla ilgili bir yorum yaz…"><?= e($commentBody) ?></textarea>
                        <div class="social-form-meta"><span>Taslak bu tarayıcıya kaydedilir. Yeni üyelerin yorumları onaydan geçebilir.</span><span data-count-for="social-comment-body">0 / 1500</span></div>
                        <label class="social-check"><input type="checkbox" name="watch" value="1"<?= !empty($postState['watching']) ? ' checked' : '' ?>> Yeni cevaplarda bana bildir</label>
                        <button class="btn btn-primary" type="submit">Yorumu gönder</button>
                    </form>
                <?php elseif (!empty($post['locked'])): ?>
                    <div class="social-locked-note">Bu konu yeni yorumlara kilitlendi.</div>
                <?php else: ?>
                    <div class="social-locked-note">Yorum yapmak için <a href="<?= e(social_login_url('sosyal/' . $returnUrl)) ?>">giriş yap</a>.</div>
                <?php endif; ?>
            </section>
        </article>

        <aside class="social-sidebar">
            <section class="social-panel social-author-panel">
                <a class="social-author" href="<?= e(social_profile_url($authorProfile)) ?>">
                    <?= social_avatar_html($authorProfile, 'is-large') ?>
                    <span><strong><?= e($authorProfile['display_name']) ?></strong><small>@<?= e($authorProfile['username']) ?></small></span>
                </a>
                <p class="social-presence-line"><?= social_presence_html($authorProfile, $socialUser) ?></p>
                <?php if (trim((string)($authorProfile['bio'] ?? '')) !== ''): ?><p><?= e($authorProfile['bio']) ?></p><?php endif; ?>
                <?php if (trim((string)($authorProfile['signature'] ?? '')) !== ''): ?><div class="social-comment-signature"><?= social_render_text((string)$authorProfile['signature']) ?></div><?php endif; ?>
                <div class="social-mini-stats">
                    <span><b><?= (int)($authorProfile['post_count'] ?? 0) ?></b> gönderi</span>
                    <span><b><?= (int)($authorProfile['follower_count'] ?? 0) ?></b> takipçi</span>
                </div>
                <?php if ($socialUser && !$isAuthor): ?>
                    <form method="post" action="aksiyon.php" data-social-ajax="follow">
                        <?= csrf_field() ?>
                        <input type="hidden" name="islem" value="takip">
                        <input type="hidden" name="target_id" value="<?= (int)$post['user_id'] ?>">
                        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                        <button class="btn <?= $isFollowingAuthor ? 'btn-ghost' : 'btn-primary' ?> btn-full" type="submit" aria-pressed="<?= $isFollowingAuthor ? 'true' : 'false' ?>"><?= $isFollowingAuthor ? 'Takip ediliyor' : 'Takip et' ?></button>
                    </form>
                    <a class="btn btn-ghost btn-full" href="<?= e(social_message_url($authorProfile)) ?>">Mesaj gönder</a>
                <?php endif; ?>
                <a class="text-link" href="<?= e(social_profile_url($authorProfile)) ?>">Profili görüntüle</a>
            </section>

            <?php if ($relatedPosts): ?>
                <section class="social-related">
                    <h2>Aynı konudan</h2>
                    <?php $socialStates = $relatedStates; ?>
                    <?php foreach ($relatedPosts as $post): ?>
                        <?php include __DIR__ . '/_post-card.php'; ?>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
