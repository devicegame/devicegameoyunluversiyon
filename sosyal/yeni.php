<?php
require_once __DIR__ . '/_common.php';
social_require_login();

$editId = (int)($_GET['duzenle'] ?? $_POST['edit_id'] ?? 0);
$editingPost = $editId > 0 ? social_get_post($editId, true) : null;
if ($editId > 0 && (!$editingPost || $editingPost['status'] === 'deleted')) {
    flash_error_set('Düzenlenecek gönderi bulunamadı.');
    header('Location: index.php');
    exit;
}
if ($editingPost && empty($socialUser['is_admin']) && (int)$socialUser['id'] !== (int)$editingPost['user_id']) {
    flash_error_set('Bu gönderiyi düzenleyemezsin.');
    header('Location: ' . social_post_url($editId));
    exit;
}

$formErrors = [];
$form = [
    'topic_slug' => social_slugify((string)($_GET['konu'] ?? 'genel-sohbet')),
    'prefix' => '',
    'title' => '',
    'body' => '',
    'tags' => '',
    'lfg_game' => '',
    'lfg_platform' => '',
    'lfg_rank' => '',
    'lfg_mic' => '',
    'lfg_play_time' => '',
    'lfg_needed' => '',
    'event_game' => '',
    'event_platform' => '',
    'event_place' => '',
    'event_at' => '',
    'event_capacity' => '0',
    'poll_days' => '7',
];
$pollEnabled = false;
$pollFormOptions = ['', '', '', '', ''];
$pollLocked = false;
$introTemplate = !$editingPost && (string)($_GET['sablon'] ?? '') === 'tanisma';
if ($introTemplate) {
    $introName = (string)($socialProfile['display_name'] ?? $socialUser['username'] ?? 'Yeni üye');
    $form['topic_slug'] = 'genel-sohbet';
    $form['prefix'] = 'tartisma';
    $form['title'] = 'Merhaba, ben ' . $introName;
    $form['body'] = "Merhaba! Ben {$introName}.\n\nŞu sıralar oynadığım oyunlar: \nOynadığım platform: \nBurada daha çok oyun önerileri ve topluluk etkinlikleri hakkında konuşmak istiyorum.";
    $form['tags'] = '#tanisma';
}
if ($editingPost) {
    $form['topic_slug'] = (string)$editingPost['topic_slug'];
    $form['prefix'] = social_post_prefix($editingPost);
    $form['title'] = (string)$editingPost['title'];
    $form['body'] = (string)$editingPost['body'];
    $form['tags'] = implode(', ', array_map(static fn($tag): string => '#' . $tag, (array)$editingPost['tags']));
    foreach (['game', 'platform', 'rank', 'mic', 'play_time', 'needed'] as $key) {
        $form['lfg_' . $key] = (string)($editingPost['meta'][$key] ?? '');
    }
    $form['event_game'] = (string)($editingPost['meta']['game'] ?? '');
    $form['event_platform'] = (string)($editingPost['meta']['platform'] ?? '');
    $form['event_place'] = (string)($editingPost['meta']['event_place'] ?? '');
    $form['event_at'] = (int)($editingPost['meta']['event_at'] ?? 0) > 0 ? date('Y-m-d\TH:i', (int)$editingPost['meta']['event_at']) : '';
    $form['event_capacity'] = (string)(int)($editingPost['meta']['event_capacity'] ?? 0);
    $existingPoll = social_normalize_poll($editingPost['poll'] ?? null);
    if ($existingPoll) {
        $pollEnabled = true;
        $pollLocked = (int)$existingPoll['total_votes'] > 0;
        $form['poll_days'] = (int)$existingPoll['closes_at'] > 0 ? '7' : '0';
        foreach ((array)$existingPoll['options'] as $i => $option) {
            $pollFormOptions[$i] = (string)($option['label'] ?? '');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $field) {
        $form[$field] = (string)($_POST[$field] ?? $form[$field]);
    }
    $pollEnabled = !empty($_POST['poll_enabled']);
    $submittedPollOptions = is_array($_POST['poll_options'] ?? null) ? (array)$_POST['poll_options'] : [];
    $pollFormOptions = ['', '', '', '', ''];
    foreach (array_slice($submittedPollOptions, 0, 5) as $i => $option) {
        $pollFormOptions[$i] = is_array($option) ? (string)($option['label'] ?? '') : (string)$option;
    }
    if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
        $formErrors[] = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.';
    } else {
        try {
            $result = $editingPost
                ? social_edit_post($socialUser, $editId, $_POST, $_FILES)
                : social_create_post($socialUser, $_POST, $_FILES);
        } catch (Throwable $exception) {
            error_log('[social post save] ' . $exception->getMessage());
            $result = ['errors' => ['Gönderi kaydedilemedi. Depolama izinlerini kontrol edin.'], 'id' => $editId, 'status' => ''];
        }
        if (!empty($result['errors'])) {
            $formErrors = $result['errors'];
            if ($editingPost) $editingPost = social_get_post($editId, true) ?: $editingPost;
        } else {
            $savedId = (int)($result['id'] ?? $editId);
            flash_set(($result['status'] ?? '') === 'pending'
                ? 'Gönderin alındı ve moderasyon onayına gönderildi.'
                : ($editingPost ? 'Gönderin güncellendi.' : 'Gönderin yayınlandı.'));
            header('Location: ' . social_post_url($savedId));
            exit;
        }
    }
}

$topicOptions = $socialTopics;
if ($editingPost && !isset($socialTopicMap[(string)$editingPost['topic_slug']])) {
    $currentTopic = social_topic((string)$editingPost['topic_slug'], false);
    if ($currentTopic) $topicOptions[] = $currentTopic;
}
$canUpload = $socialProfile ? social_can_upload_image($socialProfile, $socialUser) : false;
$existingPoll = $editingPost ? social_normalize_poll($editingPost['poll'] ?? null) : null;
$pollLocked = $existingPoll && (int)$existingPoll['total_votes'] > 0;

$page_title = $editingPost ? 'Gönderiyi Düzenle — DG Social' : 'Yeni Gönderi — DG Social';
$page_meta_desc = 'DG Social’da yeni bir forum konusu veya topluluk gönderisi oluştur.';
$page_canonical = false;
$page_robots = 'noindex,follow';
$page_css = ['shop.css', 'social.css'];
$body_class = 'shop-page social-page';
include __DIR__ . '/../header.php';
?>

<section class="social-shell is-narrow">
    <header class="social-page-head">
        <p class="eyebrow">DG Social</p>
        <h1><?= $editingPost ? 'Gönderiyi düzenle.' : 'Yeni gönderi aç.' ?></h1>
        <p class="muted">Herkes gönderini okuyabilir. Yeni üyelerin ilk içerikleri spam’i önlemek için kısa bir moderasyondan geçebilir.</p>
    </header>

    <?= social_subnav('new') ?>
    <?= social_flash_html() ?>

    <?php if ($formErrors): ?>
        <div class="social-alert is-error" role="alert">
            <strong>Gönderi kaydedilemedi:</strong>
            <ul>
                <?php foreach ($formErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($introTemplate && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="social-alert is-info">Tanışma şablonunu hazırladık. Boş alanları kendine göre düzenleyip paylaşabilirsin.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="social-form-card" data-social-post-form>
        <?= csrf_field() ?>
        <input type="hidden" name="edit_id" value="<?= $editingPost ? (int)$editingPost['id'] : 0 ?>">

        <div class="social-form-grid">
            <div class="field">
                <label for="social-topic">Konu *</label>
                <select id="social-topic" name="topic_slug" required data-social-topic-select>
                    <?php foreach ($topicOptions as $topic): ?>
                        <?php $disabled = (!empty($topic['official_only']) && empty($socialUser['is_admin'])) || (empty($topic['active']) && (!$editingPost || $editingPost['topic_slug'] !== $topic['slug'])); ?>
                        <option value="<?= e($topic['slug']) ?>"<?= $form['topic_slug'] === (string)$topic['slug'] ? ' selected' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                            <?= e($topic['icon']) ?> <?= e($topic['name']) ?><?= !empty($topic['official_only']) ? ' — yalnızca ekip' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="social-prefix">Konu türü</label>
                <select id="social-prefix" name="prefix" data-social-prefix-select>
                    <?php foreach (social_post_prefixes() as $prefixSlug => $prefixLabel): ?>
                        <option value="<?= e($prefixSlug) ?>"<?= $form['prefix'] === $prefixSlug ? ' selected' : '' ?>><?= e($prefixLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-note">Soru, rehber, ekip ve etkinlik konuları daha kolay bulunur.</p>
            </div>
        </div>

        <div class="field">
            <label for="social-title">Başlık *</label>
            <input id="social-title" type="text" name="title" required minlength="4" maxlength="140" value="<?= e($form['title']) ?>" placeholder="Tartışmanın başlığı">
            <div class="social-similar-posts" data-similar-posts hidden></div>
        </div>

        <div class="field">
            <label for="social-body">İçerik *</label>
            <?= social_editor_toolbar_html('social-body') ?>
            <textarea id="social-body" name="body" required minlength="10" maxlength="5000" rows="10" data-character-count placeholder="Düşünceni, soru veya rehberini yaz. @kullanici ve #etiket kullanabilirsin."><?= e($form['body']) ?></textarea>
            <div class="social-form-meta"><span>**kalın**, *italik*, `kod`, alıntı, liste ve ||gizli|| desteklenir; HTML kabul edilmez.</span><span data-count-for="social-body">0 / 5000</span></div>
            <div class="social-draft-row"><span data-draft-status>Yazdıkların bu tarayıcıda taslak olarak saklanır.</span><button class="social-text-button" type="button" data-draft-discard>Taslağı sil</button></div>
        </div>

        <fieldset class="social-lfg-fields" data-lfg-fields data-topic-fields="oyun-arkadasi">
            <legend>Oyun arkadaşı bilgileri</legend>
            <p class="muted">Bu alanlar yalnızca Oyun Arkadaşı konusunda kullanılır.</p>
            <div class="social-form-grid">
                <div class="field">
                    <label for="lfg-game">Oyun</label>
                    <input id="lfg-game" type="text" name="lfg_game" maxlength="60" value="<?= e($form['lfg_game']) ?>" placeholder="Örn. Valorant">
                </div>
                <div class="field">
                    <label for="lfg-platform">Platform</label>
                    <input id="lfg-platform" type="text" name="lfg_platform" maxlength="60" value="<?= e($form['lfg_platform']) ?>" placeholder="PC, PS5, Xbox…">
                </div>
                <div class="field">
                    <label for="lfg-rank">Rank / seviye</label>
                    <input id="lfg-rank" type="text" name="lfg_rank" maxlength="60" value="<?= e($form['lfg_rank']) ?>" placeholder="Örn. Altın">
                </div>
                <div class="field">
                    <label for="lfg-mic">Mikrofon</label>
                    <select id="lfg-mic" name="lfg_mic">
                        <option value="">Seç</option>
                        <?php foreach (['Var', 'Yok', 'İsteğe bağlı'] as $option): ?>
                            <option value="<?= e($option) ?>"<?= $form['lfg_mic'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="lfg-time">Oyun saati</label>
                    <input id="lfg-time" type="text" name="lfg_play_time" maxlength="60" value="<?= e($form['lfg_play_time']) ?>" placeholder="Örn. Hafta içi 20.00 sonrası">
                </div>
                <div class="field">
                    <label for="lfg-needed">Aranan</label>
                    <input id="lfg-needed" type="text" name="lfg_needed" maxlength="60" value="<?= e($form['lfg_needed']) ?>" placeholder="Örn. 2 oyuncu">
                </div>
            </div>
        </fieldset>

        <fieldset class="social-lfg-fields" data-topic-fields="etkinlikler">
            <legend>Etkinlik bilgileri</legend>
            <p class="muted">Etkinlik sahibi otomatik olarak ilk katılımcı olur. Tarih ve saat site saatine göre gösterilir.</p>
            <div class="social-form-grid">
                <div class="field">
                    <label for="event-game">Oyun *</label>
                    <input id="event-game" type="text" name="event_game" maxlength="60" value="<?= e($form['event_game']) ?>" placeholder="Örn. Project Overcharge">
                </div>
                <div class="field">
                    <label for="event-platform">Platform</label>
                    <input id="event-platform" type="text" name="event_platform" maxlength="60" value="<?= e($form['event_platform']) ?>" placeholder="PC, PS5, Xbox…">
                </div>
                <div class="field">
                    <label for="event-place">Buluşma yeri *</label>
                    <input id="event-place" type="text" name="event_place" maxlength="60" value="<?= e($form['event_place']) ?>" placeholder="Örn. oyun içi lobi">
                </div>
                <div class="field">
                    <label for="event-at">Tarih ve saat *</label>
                    <input id="event-at" type="datetime-local" name="event_at" value="<?= e($form['event_at']) ?>">
                </div>
                <div class="field">
                    <label for="event-capacity">Kapasite</label>
                    <input id="event-capacity" type="number" name="event_capacity" min="0" max="1000" step="1" value="<?= e($form['event_capacity']) ?>">
                    <p class="form-note">0 = sınırsız katılım.</p>
                </div>
            </div>
        </fieldset>

        <fieldset class="social-lfg-fields social-poll-editor">
            <legend>Anket</legend>
            <label class="social-check"><input type="checkbox" name="poll_enabled" value="1" data-poll-toggle<?= $pollEnabled ? ' checked' : '' ?><?= $pollLocked ? ' disabled' : '' ?>> Bu gönderiye anket ekle</label>
            <?php if ($pollLocked): ?><input type="hidden" name="poll_enabled" value="1"><?php endif; ?>
            <div data-poll-fields data-poll-locked="<?= $pollLocked ? '1' : '0' ?>">
                <p class="muted">En az 2, en fazla 5 farklı seçenek yaz. Üyeler tek oy kullanır ve kapanışa kadar oyunu değiştirebilir.</p>
                <?php if ($existingPoll): ?>
                    <p class="form-note">
                        Mevcut anket: <?= (int)$existingPoll['total_votes'] ?> oy<?= (int)$existingPoll['closes_at'] > 0 ? ' · Kapanış: ' . e(social_format_date((int)$existingPoll['closes_at'])) : ' · Süresiz' ?>.
                        <?php if ($pollLocked): ?>Oy kullanıldığı için seçenekler ve kapanış kilitlendi.<?php endif; ?>
                    </p>
                <?php endif; ?>
                <div class="social-poll-editor-grid">
                    <?php foreach ($pollFormOptions as $index => $optionLabel): ?>
                        <div class="field">
                            <label for="poll-option-<?= $index + 1 ?>">Seçenek <?= $index + 1 ?><?= $index < 2 ? ' *' : '' ?></label>
                            <input id="poll-option-<?= $index + 1 ?>" type="text" name="poll_options[]" maxlength="60" value="<?= e($optionLabel) ?>" placeholder="<?= $index === 0 ? 'Örn. PC' : ($index === 1 ? 'Örn. Konsol' : 'İsteğe bağlı') ?>"<?= $pollLocked ? ' readonly' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                    <div class="field">
                        <label for="poll-days">Oylama süresi</label>
                        <select id="poll-days" name="poll_days"<?= $existingPoll ? ' disabled' : '' ?>>
                            <?php foreach ([1 => '1 gün', 3 => '3 gün', 7 => '7 gün', 14 => '14 gün', 30 => '30 gün', 0 => 'Süresiz'] as $days => $label): ?>
                                <option value="<?= $days ?>"<?= (int)$form['poll_days'] === $days ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($existingPoll): ?><p class="form-note">Mevcut anketin süresi düzenleme sırasında değiştirilemez.</p><?php endif; ?>
                    </div>
                </div>
            </div>
        </fieldset>

        <div class="field">
            <label for="social-tags">Etiketler</label>
            <input id="social-tags" type="text" name="tags" maxlength="180" value="<?= e($form['tags']) ?>" placeholder="#rehber, #indirim, #lfg">
            <p class="form-note">En fazla 5 etiket kullanabilirsin.</p>
        </div>

        <div class="field">
            <label for="social-image">Görsel <?= $canUpload ? '' : '<small>(güvenilir üyelikten sonra açılır)</small>' ?></label>
            <?php if ($editingPost && !empty($editingPost['image'])): ?>
                <div class="social-current-image">
                    <img src="<?= e(social_image_url((string)$editingPost['image'])) ?>" alt="Mevcut gönderi görseli">
                    <label class="social-check"><input type="checkbox" name="remove_image" value="1"> Mevcut görseli kaldır</label>
                </div>
            <?php endif; ?>
            <input id="social-image" type="file" name="image" accept="image/jpeg,image/png,image/webp"<?= $canUpload ? '' : ' disabled' ?>>
            <p class="form-note">JPG, PNG veya WebP; en fazla 4 MB. Görsel otomatik küçültülür.</p>
        </div>

        <div class="social-form-actions">
            <a class="btn btn-ghost" href="<?= $editingPost ? e(social_post_url((int)$editingPost['id'])) : ($form['topic_slug'] === 'etkinlikler' ? 'etkinlikler.php' : 'index.php') ?>">Vazgeç</a>
            <button class="btn btn-primary" type="submit"><?= $editingPost ? 'Değişiklikleri kaydet' : 'Gönderiyi paylaş' ?></button>
        </div>
    </form>
</section>

<script src="../assets/js/social.js" defer></script>
<?php include __DIR__ . '/../footer.php'; ?>
