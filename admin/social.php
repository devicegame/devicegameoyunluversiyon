<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/social.php';

social_ensure_storage();
$view = (string)($_GET['gorunum'] ?? 'onay');
if (!in_array($view, ['onay', 'raporlar', 'gonderiler', 'konular', 'sohbet', 'gunluk', 'bakim'], true)) $view = 'onay';
$topicErrors = [];
$topicValues = null;
$editingTopic = null;

function dg_social_admin_url(array $params = []): string
{
    return 'social.php' . ($params ? '?' . http_build_query($params) : '');
}

function dg_social_return(array $params = []): string
{
    return '../admin/social.php' . ($params ? '?' . http_build_query($params) : '');
}

function dg_social_format_bytes(int $bytes): string
{
    $units = ['Bayt', 'KB', 'MB', 'GB'];
    $value = (float)max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnParams = ['gorunum' => (string)($_POST['return_view'] ?? $view)];
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error_set('CSRF hatası. Sayfayı yenileyip tekrar deneyin.');
        header('Location: ' . dg_social_admin_url($returnParams));
        exit;
    }

    try {
        if (isset($_POST['save_topic'])) {
            $topicValues = $_POST;
            $result = social_save_topic($user, $_POST);
            if (!empty($result['errors'])) {
                $topicErrors = $result['errors'];
                $view = 'konular';
                if (!empty($_POST['edit_slug'])) $editingTopic = social_topic((string)$_POST['edit_slug'], false);
            } else {
                flash_set(!empty($_POST['edit_slug']) ? 'Sosyal konusu güncellendi.' : 'Sosyal konusu eklendi.');
                header('Location: ' . dg_social_admin_url(['gorunum' => 'konular']) . '#konu-listesi');
                exit;
            }
        }

        if (isset($_POST['delete_topic'])) {
            $result = social_delete_topic($user, (string)($_POST['topic_slug'] ?? ''));
            if (!empty($result['errors'])) flash_error_set(implode(' ', $result['errors']));
            else flash_set('Sosyal konusu silindi.');
            header('Location: ' . dg_social_admin_url(['gorunum' => 'konular']) . '#konu-listesi');
            exit;
        }

        if (isset($_POST['report_status'])) {
            $result = social_set_report_status($user, (int)($_POST['report_id'] ?? 0), (string)($_POST['status'] ?? 'open'));
            if (!empty($result['errors'])) flash_error_set(implode(' ', $result['errors']));
            else flash_set('Şikâyet durumu güncellendi.');
            header('Location: ' . dg_social_admin_url(['gorunum' => 'raporlar']));
            exit;
        }

        if (isset($_POST['save_announcement'])) {
            $result = social_set_announcement($user, $_POST);
            if (!empty($result['errors'])) flash_error_set(implode(' ', $result['errors']));
            else flash_set(!empty($_POST['active']) ? 'Topluluk duyurusu yayınlandı.' : 'Topluluk duyurusu kaydedildi ve kapatıldı.');
            header('Location: ' . dg_social_admin_url(['gorunum' => 'bakim']) . '#topluluk-duyurusu');
            exit;
        }

        if (isset($_POST['chat_prune'])) {
            $chatChannel = (string)($_POST['channel'] ?? 'genel');
            $result = social_chat_prune($user, $chatChannel, (int)($_POST['keep'] ?? 1000));
            if (!empty($result['errors'])) flash_error_set(implode(' ', $result['errors']));
            else flash_set('Sohbet kanalı budandı; son ' . (int)$result['kept'] . ' mesaj tutuldu.');
            header('Location: ' . dg_social_admin_url(['gorunum' => 'sohbet', 'kanal' => social_slugify($chatChannel)]) . '#sohbet-kanali');
            exit;
        }

        if (isset($_POST['mark_backup'])) {
            $result = social_mark_backup($user);
            if (!empty($result['errors'])) flash_error_set(implode(' ', $result['errors']));
            else flash_set('Sosyal yedeği alındığı işaretlendi. Bu kayıt yalnızca bakım takibi içindir; gerçek klasör yedeğini ayrıca saklayın.');
            header('Location: ' . dg_social_admin_url(['gorunum' => 'bakim']) . '#yedek-durumu');
            exit;
        }

        if (isset($_POST['rebuild_indexes'])) {
            $result = social_rebuild_indexes();
            if (!empty($result['errors'])) flash_error_set(implode(' ', $result['errors']));
            else flash_set('Sosyal indeksleri yeniden oluşturuldu: ' . (int)$result['posts'] . ' gönderi, ' . (int)$result['comments'] . ' yorum.');
            header('Location: ' . dg_social_admin_url(['gorunum' => 'bakim']) . '#indeks-bakimi');
            exit;
        }
    } catch (Throwable $exception) {
        error_log('[admin social] ' . $exception->getMessage());
        flash_error_set('Sosyal işlemi tamamlanamadı. Depolama izinlerini kontrol edin.');
        header('Location: ' . dg_social_admin_url($returnParams));
        exit;
    }
}

if ($topicValues === null && isset($_GET['konu_duzenle'])) {
    $editingTopic = social_topic((string)$_GET['konu_duzenle'], false);
    if (!$editingTopic) {
        flash_error_set('Düzenlenecek sosyal konusu bulunamadı.');
        header('Location: ' . dg_social_admin_url(['gorunum' => 'konular']));
        exit;
    }
    $view = 'konular';
}
$topicForm = [
    'slug' => (string)($topicValues['slug'] ?? $editingTopic['slug'] ?? ''),
    'name' => (string)($topicValues['name'] ?? $editingTopic['name'] ?? ''),
    'group' => (string)($topicValues['group'] ?? $editingTopic['group'] ?? 'Topluluk'),
    'description' => (string)($topicValues['description'] ?? $editingTopic['description'] ?? ''),
    'icon' => (string)($topicValues['icon'] ?? $editingTopic['icon'] ?? '💬'),
    'order' => (int)($topicValues['order'] ?? $editingTopic['order'] ?? 99),
    'official_only' => $topicValues !== null ? !empty($topicValues['official_only']) : !empty($editingTopic['official_only']),
    'active' => $topicValues !== null ? !empty($topicValues['active']) : (!isset($editingTopic['active']) || !empty($editingTopic['active'])),
];

$stats = social_stats(true);
$storageStats = social_storage_stats();
$health = null;
$healthError = '';
if ($view === 'bakim') {
    try {
        $health = social_health_report();
        $storageStats = $health['totals'];
    } catch (Throwable $exception) {
        error_log('[admin social health] ' . $exception->getMessage());
        $healthError = 'Sağlık raporu oluşturulamadı. Depolama izinlerini ve JSON bütünlüğünü kontrol edin.';
    }
}
$pendingPosts = social_list_posts(['status' => 'pending', 'include_moderation' => true, 'sort' => 'new', 'per_page' => 50], $user)['items'];
$pendingComments = array_values(array_filter(social_moderation_comments(100), static fn(array $item): bool => ($item['status'] ?? '') === 'pending'));
$reports = social_reports();
$announcement = social_announcement();
$chatChannels = social_chat_channels();
$adminChatChannel = social_slugify((string)($_GET['kanal'] ?? 'genel')) ?: 'genel';
if (!isset($chatChannels[$adminChatChannel])) $adminChatChannel = 'genel';
$adminChatMessages = $view === 'sohbet' ? social_chat_moderation_messages($adminChatChannel, 100) : [];
$moderationLogs = $view === 'gunluk' ? social_moderation_logs(150) : [];
$warnedMembers = [];
if ($view === 'gunluk') {
    foreach (social_member_directory(['sort' => 'newest', 'limit' => 100], $user) as $memberProfile) {
        if ((int)($memberProfile['warning_count'] ?? 0) > 0 || (int)($memberProfile['muted_until'] ?? 0) > time()) $warnedMembers[] = $memberProfile;
    }
    usort($warnedMembers, static fn(array $a, array $b): int => [(int)($b['muted_until'] ?? 0), (int)($b['warning_count'] ?? 0)] <=> [(int)($a['muted_until'] ?? 0), (int)($a['warning_count'] ?? 0)]);
}
$postQuery = trim((string)($_GET['q'] ?? ''));
$postStatus = (string)($_GET['durum'] ?? 'all');
if (!in_array($postStatus, ['all', 'published', 'pending', 'hidden'], true)) $postStatus = 'all';
$postPage = max(1, (int)($_GET['sayfa'] ?? 1));
$postResult = social_list_posts([
    'q' => $postQuery,
    'status' => $postStatus === 'all' ? '' : $postStatus,
    'include_moderation' => true,
    'sort' => 'new',
    'page' => $postPage,
    'per_page' => 25,
], $user);
$topics = social_topics(false);
$currentReturn = dg_social_return(array_filter([
    'gorunum' => $view,
    'q' => $postQuery,
    'durum' => $postStatus !== 'all' ? $postStatus : '',
    'kanal' => $view === 'sohbet' ? $adminChatChannel : '',
    'sayfa' => $postPage > 1 ? $postPage : '',
], static fn($value): bool => $value !== ''));

admin_head('Sosyal Moderasyon');
?>

<div style="display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <p class="dg-kicker">DG SOCIAL // MODERATION</p>
    <h1 style="font-family:Bricolage Grotesque,sans-serif;font-weight:800;font-size:24px">Sosyal Moderasyon</h1>
    <p class="dg-muted" style="font-size:13px">Forum + akış içeriklerini, şikâyetleri ve konuları yönet. Veriler parçalı JSON/JSONL dosyalarında tutulur.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <span class="dg-badge"><?= (int)$stats['posts'] ?> YAYINDA</span>
    <span class="dg-badge"><?= (int)$stats['open_reports'] ?> AÇIK ŞİKÂYET</span>
    <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../sosyal/index.php" target="_blank" rel="noopener">DG Social’ı Aç ↗</a>
  </div>
</div>

<div class="dg-stats" style="margin-bottom:16px">
  <div class="dg-stat"><label>📰 Yayındaki Gönderi</label><strong><?= (int)$stats['posts'] ?></strong><small>Akışta görünen içerikler</small></div>
  <div class="dg-stat"><label>⏳ Onay Bekleyen</label><strong><?= count($pendingPosts) + count($pendingComments) ?></strong><small><?= count($pendingPosts) ?> gönderi · <?= count($pendingComments) ?> yorum</small></div>
  <div class="dg-stat"><label>🚩 Açık Şikâyet</label><strong><?= (int)$stats['open_reports'] ?></strong><small>İnceleme bekleyen bildirimler</small></div>
  <div class="dg-stat"><label>💾 Sosyal Veri</label><strong><?= e(dg_social_format_bytes((int)$storageStats['bytes'])) ?></strong><small><?= (int)$storageStats['files'] ?> dosya</small></div>
</div>

<div class="dg-stats" style="margin-bottom:16px">
  <div class="dg-stat"><label>🟢 Çevrimiçi Üye</label><strong><?= (int)($stats['online'] ?? 0) ?></strong><small>Son 5 dakikada aktif</small></div>
  <div class="dg-stat"><label>✉️ Özel Konuşma</label><strong><?= (int)($stats['dm_conversations'] ?? $stats['conversations'] ?? 0) ?></strong><small>İçerikler yalnız şikâyet bağlamında açılır</small></div>
  <div class="dg-stat"><label>💬 Sohbet Mesajı</label><strong><?= (int)($stats['chat_messages'] ?? 0) ?></strong><small>Yayındaki kanal mesajları</small></div>
  <div class="dg-stat"><label>✅ Kabul Edilen Çözüm</label><strong><?= (int)($stats['solutions'] ?? 0) ?></strong><small>Forum sorularındaki çözümler</small></div>
</div>

<div class="dg-stats" style="margin-bottom:16px">
  <div class="dg-stat"><label>📊 Anket</label><strong><?= (int)($stats['polls'] ?? 0) ?></strong><small>Yayındaki anketli gönderi</small></div>
  <div class="dg-stat"><label>🗓 Yaklaşan Etkinlik</label><strong><?= (int)($stats['upcoming_events'] ?? 0) ?></strong><small>Katılım açık etkinlik</small></div>
  <div class="dg-stat"><label>🏆 Editör Ödülü</label><strong><?= (int)($stats['awards'] ?? 0) ?></strong><small>Toplam <?= number_format((int)($stats['award_dcoin'] ?? 0), 0, ',', '.') ?> D-Coin</small></div>
  <div class="dg-stat"><label>👥 Sosyal Profil</label><strong><?= number_format((int)($stats['members'] ?? 0), 0, ',', '.') ?></strong><small><?= count($topics) ?> konu tanımlı</small></div>
</div>

<nav class="dg-social-admin-tabs" aria-label="Sosyal yönetim görünümleri">
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'onay'])) ?>" class="<?= $view === 'onay' ? 'is-active' : '' ?>">Onay kuyruğu <span><?= count($pendingPosts) + count($pendingComments) ?></span></a>
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'raporlar'])) ?>" class="<?= $view === 'raporlar' ? 'is-active' : '' ?>">Şikâyetler <span><?= (int)$stats['open_reports'] ?></span></a>
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'gonderiler'])) ?>" class="<?= $view === 'gonderiler' ? 'is-active' : '' ?>">Gönderiler</a>
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'konular'])) ?>" class="<?= $view === 'konular' ? 'is-active' : '' ?>">Konular</a>
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'sohbet', 'kanal' => $adminChatChannel])) ?>" class="<?= $view === 'sohbet' ? 'is-active' : '' ?>">Canlı Sohbet <span><?= (int)($stats['chat_messages'] ?? 0) ?></span></a>
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'gunluk'])) ?>" class="<?= $view === 'gunluk' ? 'is-active' : '' ?>">Moderasyon Günlüğü <span><?= (int)($stats['moderation_logs'] ?? 0) ?></span></a>
  <a href="<?= e(dg_social_admin_url(['gorunum' => 'bakim'])) ?>" class="<?= $view === 'bakim' ? 'is-active' : '' ?>">Duyuru & Bakım</a>
</nav>

<?php if ($view === 'onay'): ?>
  <div class="dg-social-admin-grid">
    <section class="dg-card" id="bekleyen-gonderiler" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>Bekleyen Gönderiler</h2><span class="dg-badge"><?= count($pendingPosts) ?></span></div>
      <?php if (!$pendingPosts): ?>
        <div class="dg-empty">Onay bekleyen gönderi yok.</div>
      <?php else: ?>
        <div class="dg-social-admin-list">
          <?php foreach ($pendingPosts as $post): ?>
            <?php $fullPost = social_get_post((int)$post['id'], true) ?: $post; ?>
            <article class="dg-social-admin-item">
              <div>
                <a class="dg-social-admin-title" href="../sosyal/gonderi.php?id=<?= (int)$post['id'] ?>" target="_blank" rel="noopener"><?= e($post['title']) ?></a>
                <p><?= e(social_excerpt((string)$fullPost['body'], 280)) ?></p>
                <div class="dg-social-admin-meta">
                  <span class="dg-chip">@<?= e($post['author_username']) ?></span>
                  <span class="dg-chip"><?= e($post['topic_slug']) ?></span>
                  <span class="dg-chip"><?= e(date('d.m.Y H:i', (int)$post['created_at'])) ?></span>
                  <?php if (!empty($fullPost['poll'])): ?><span class="dg-chip">📊 <?= count((array)$fullPost['poll']['options']) ?> seçenekli anket</span><?php endif; ?>
                  <?php if (social_is_event_post($fullPost)): ?><span class="dg-chip">🗓 <?= e(date('d.m.Y H:i', (int)($fullPost['meta']['event_at'] ?? 0))) ?> · <?= e($fullPost['meta']['event_place'] ?? '') ?></span><?php endif; ?>
                  <?php if (!empty($fullPost['image'])): ?><span class="dg-chip">Görsel var</span><?php endif; ?>
                </div>
              </div>
              <div class="dg-social-admin-actions">
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_onayla"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'onay'])) ?>"><button class="dg-btn dg-btn-primary dg-btn-sm">Yayınla</button></form>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_gizle"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'onay'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm">Gizle</button></form>
                <form method="post" action="../sosyal/aksiyon.php" onsubmit="return confirm('Bu gönderi silinsin mi?')"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_sil"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'onay'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm dg-social-danger">Sil</button></form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="dg-card" id="bekleyen-yorumlar" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>Bekleyen Yorumlar</h2><span class="dg-badge"><?= count($pendingComments) ?></span></div>
      <?php if (!$pendingComments): ?>
        <div class="dg-empty">Onay bekleyen yorum yok.</div>
      <?php else: ?>
        <div class="dg-social-admin-list">
          <?php foreach ($pendingComments as $summary): ?>
            <?php $comment = social_get_comment((int)$summary['post_id'], (int)$summary['id']); ?>
            <article class="dg-social-admin-item">
              <div>
                <a class="dg-social-admin-title" href="../sosyal/gonderi.php?id=<?= (int)$summary['post_id'] ?>#yorum-<?= (int)$summary['id'] ?>" target="_blank" rel="noopener"><?= e($summary['post_title']) ?></a>
                <p><?= e($comment['body'] ?? $summary['excerpt']) ?></p>
                <div class="dg-social-admin-meta">
                  <span class="dg-chip">@<?= e($summary['author_username']) ?></span>
                  <span class="dg-chip"><?= e(date('d.m.Y H:i', (int)$summary['created_at'])) ?></span>
                </div>
              </div>
              <div class="dg-social-admin-actions">
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="yorum_onayla"><input type="hidden" name="post_id" value="<?= (int)$summary['post_id'] ?>"><input type="hidden" name="comment_id" value="<?= (int)$summary['id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'onay'])) ?>"><button class="dg-btn dg-btn-primary dg-btn-sm">Yayınla</button></form>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="yorum_gizle"><input type="hidden" name="post_id" value="<?= (int)$summary['post_id'] ?>"><input type="hidden" name="comment_id" value="<?= (int)$summary['id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'onay'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm">Gizle</button></form>
                <form method="post" action="../sosyal/aksiyon.php" onsubmit="return confirm('Bu yorum silinsin mi?')"><?= csrf_field() ?><input type="hidden" name="islem" value="yorum_sil"><input type="hidden" name="post_id" value="<?= (int)$summary['post_id'] ?>"><input type="hidden" name="comment_id" value="<?= (int)$summary['id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'onay'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm dg-social-danger">Sil</button></form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>

<?php if ($view === 'raporlar'): ?>
  <section class="dg-card">
    <div class="dg-card-head"><h2>Şikâyetler</h2><span class="dg-badge"><?= count($reports) ?> kayıt</span></div>
    <?php if (!$reports): ?>
      <div class="dg-empty">Henüz şikâyet yok.</div>
    <?php else: ?>
      <div class="dg-social-admin-list">
        <?php foreach ($reports as $report): ?>
          <?php
            $reportType = (string)$report['target_type'];
            $reportAuthorId = (int)($report['target_author_id'] ?? 0);
            $reportAuthorProfile = $reportAuthorId > 0 ? social_get_profile($reportAuthorId) : null;
            $reportTypeLabel = match ($reportType) {
              'comment' => 'Yorum',
              'profile' => 'Profil',
              'direct_message' => 'Özel mesaj',
              'chat_message' => 'Sohbet mesajı',
              default => 'Gönderi',
            };
            $reportUrl = '../sosyal/gonderi.php?id=' . (int)$report['post_id'] . ($reportType === 'comment' ? '#yorum-' . (int)$report['target_id'] : '');
            if ($reportType === 'profile' && $reportAuthorProfile) $reportUrl = '../sosyal/profil.php?kullanici=' . rawurlencode((string)$reportAuthorProfile['username']);
            if ($reportType === 'chat_message') $reportUrl = '../sosyal/sohbet.php?kanal=' . rawurlencode((string)($report['channel'] ?? 'genel')) . '#sohbet-son';
            if ($reportType === 'direct_message') $reportUrl = '#sikayet-' . (int)$report['id'];
            $chatReportMessage = $reportType === 'chat_message' ? (social_chat_message_map((string)($report['channel'] ?? 'genel'))[(int)$report['target_id']] ?? null) : null;
            $dmReportMessage = $reportType === 'direct_message' ? (social_dm_message_map((int)($report['conversation_id'] ?? 0))[(int)$report['target_id']] ?? null) : null;
            $showDmContext = $reportType === 'direct_message' && (int)($_GET['baglam'] ?? 0) === (int)$report['id'];
            $dmContext = $showDmContext ? social_dm_report_context($user, (int)$report['conversation_id'], (int)$report['target_id']) : [];
          ?>
          <article class="dg-social-admin-item" id="sikayet-<?= (int)$report['id'] ?>" style="scroll-margin-top:110px">
            <div>
              <?php if ($reportType === 'direct_message'): ?>
                <span class="dg-social-admin-title">🔒 <?= e($report['target_title'] ?: 'Özel mesaj şikâyeti') ?></span>
              <?php else: ?>
                <a class="dg-social-admin-title" href="<?= e($reportUrl) ?>" target="_blank" rel="noopener"><?= e($report['target_title'] ?: 'Şikâyet edilen içerik') ?></a>
              <?php endif; ?>
              <p><b><?= e($report['reason']) ?>:</b> <?= e($report['target_excerpt']) ?></p>
              <?php if (trim((string)$report['details']) !== ''): ?><p><b>Not:</b> <?= e($report['details']) ?></p><?php endif; ?>
              <?php if ($reportType === 'direct_message'): ?>
                <p class="dg-muted" style="font-size:12px;line-height:1.6">Gizlilik nedeniyle yalnız bu şikâyetin hedef mesajı özetlenir. Gerekirse en fazla ±5 mesajlık bağlamı açıkça açabilirsin.</p>
                <?php if (!$showDmContext): ?>
                  <a class="dg-btn dg-btn-ghost dg-btn-sm" href="<?= e(dg_social_admin_url(['gorunum' => 'raporlar', 'baglam' => (int)$report['id']])) ?>#sikayet-<?= (int)$report['id'] ?>">Şikâyet bağlamını aç (±5)</a>
                <?php elseif ($dmContext): ?>
                  <div class="dg-social-context">
                    <?php foreach ($dmContext as $contextMessage): ?>
                      <div class="<?= (int)$contextMessage['id'] === (int)$report['target_id'] ? 'is-target' : '' ?>">
                        <b><?= e($contextMessage['sender_display']) ?></b> <small><?= e($contextMessage['date_label']) ?> · <?= e($contextMessage['status']) ?></small>
                        <div><?= $contextMessage['html'] ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?><p class="dg-muted">Bağlam artık bulunamadı.</p><?php endif; ?>
              <?php endif; ?>
              <div class="dg-social-admin-meta">
                <span class="dg-chip <?= $report['status'] === 'open' ? 'on' : '' ?>"><?= e($report['status']) ?></span>
                <span class="dg-chip"><?= e($reportTypeLabel) ?></span>
                <span class="dg-chip">Şikâyet eden: @<?= e($report['reporter_username']) ?></span>
                <?php if ($reportAuthorProfile): ?><a class="dg-chip" href="../sosyal/profil.php?kullanici=<?= rawurlencode((string)$reportAuthorProfile['username']) ?>#yonetici-araclari" target="_blank" rel="noopener">Hedef: @<?= e($reportAuthorProfile['username']) ?></a><?php endif; ?>
                <?php if ($reportType === 'chat_message'): ?><span class="dg-chip">Kanal: <?= e($report['channel'] ?? '') ?> · Durum: <?= e($chatReportMessage['status'] ?? 'bulunamadı') ?></span><?php endif; ?>
                <?php if ($reportType === 'direct_message'): ?><span class="dg-chip">Mesaj durumu: <?= e($dmReportMessage['status'] ?? 'bulunamadı') ?></span><?php endif; ?>
                <span class="dg-chip"><?= e(date('d.m.Y H:i', (int)$report['created_at'])) ?></span>
              </div>

              <?php if ($reportAuthorId > 0 && $reportAuthorId !== (int)$user['id']): ?>
                <details class="dg-social-admin-warning" style="margin-top:12px">
                  <summary>Kullanıcıyı uyar / sustur</summary>
                  <form method="post" action="../sosyal/aksiyon.php" class="dg-form" style="margin-top:10px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="islem" value="uyari_ver">
                    <input type="hidden" name="target_id" value="<?= $reportAuthorId ?>">
                    <input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'raporlar'])) ?>">
                    <label class="dg-field"><span>Kullanıcıya iletilecek sebep</span><textarea name="reason" required maxlength="240" placeholder="Şikâyet #<?= (int)$report['id'] ?> hakkında kısa açıklama"></textarea></label>
                    <label class="dg-field"><span>Süre</span><select name="mute_days"><option value="0">Yalnızca uyarı</option><option value="1">1 gün sustur</option><option value="7">7 gün sustur</option><option value="30">30 gün sustur</option></select></label>
                    <button class="dg-btn dg-btn-ghost dg-btn-sm">Uyarıyı gönder</button>
                  </form>
                </details>
              <?php endif; ?>
            </div>
            <div class="dg-social-admin-actions">
              <?php if ($reportType === 'post'): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_gizle"><input type="hidden" name="post_id" value="<?= (int)$report['post_id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'raporlar'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm">Gönderiyi gizle</button></form>
              <?php elseif ($reportType === 'comment'): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="yorum_gizle"><input type="hidden" name="post_id" value="<?= (int)$report['post_id'] ?>"><input type="hidden" name="comment_id" value="<?= (int)$report['target_id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'raporlar'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm">Yorumu gizle</button></form>
              <?php elseif ($reportType === 'chat_message' && $chatReportMessage): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($report['channel'] ?? 'genel') ?>"><input type="hidden" name="message_id" value="<?= (int)$report['target_id'] ?>"><input type="hidden" name="status" value="<?= ($chatReportMessage['status'] ?? '') === 'published' ? 'hidden' : 'published' ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'raporlar'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm"><?= ($chatReportMessage['status'] ?? '') === 'published' ? 'Sohbet mesajını gizle' : 'Sohbet mesajını yayınla' ?></button></form>
              <?php elseif ($reportType === 'direct_message' && $dmReportMessage): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="mesaj_gizle"><input type="hidden" name="conversation_id" value="<?= (int)$report['conversation_id'] ?>"><input type="hidden" name="message_id" value="<?= (int)$report['target_id'] ?>"><input type="hidden" name="status" value="<?= ($dmReportMessage['status'] ?? '') === 'published' ? 'hidden' : 'published' ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'raporlar'])) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm"><?= ($dmReportMessage['status'] ?? '') === 'published' ? 'Özel mesajı gizle' : 'Özel mesajı yayınla' ?></button></form>
              <?php elseif ($reportType === 'profile' && $reportAuthorProfile): ?>
                <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../sosyal/profil.php?kullanici=<?= rawurlencode((string)$reportAuthorProfile['username']) ?>#yonetici-araclari" target="_blank" rel="noopener">Profili ve araçları aç</a>
              <?php endif; ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="status" value="resolved"><button name="report_status" value="1" class="dg-btn dg-btn-primary dg-btn-sm">Çözüldü</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="status" value="dismissed"><button name="report_status" value="1" class="dg-btn dg-btn-ghost dg-btn-sm">Reddet</button></form>
              <?php if ($report['status'] !== 'open'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="status" value="open"><button name="report_status" value="1" class="dg-btn dg-btn-ghost dg-btn-sm">Yeniden aç</button></form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($view === 'gonderiler'): ?>
  <section class="dg-card" id="gonderi-listesi" style="scroll-margin-top:110px">
    <div class="dg-card-head"><h2>Tüm Gönderiler</h2><span class="dg-badge"><?= (int)$postResult['total'] ?></span></div>
    <form method="get" action="social.php#gonderi-listesi" class="dg-social-admin-filter">
      <input type="hidden" name="gorunum" value="gonderiler">
      <div class="dg-search"><input type="search" name="q" value="<?= e($postQuery) ?>" placeholder="Başlık, içerik, etiket veya kullanıcı ara"></div>
      <select name="durum" aria-label="Durum">
        <option value="all"<?= $postStatus === 'all' ? ' selected' : '' ?>>Tüm durumlar</option>
        <option value="published"<?= $postStatus === 'published' ? ' selected' : '' ?>>Yayında</option>
        <option value="pending"<?= $postStatus === 'pending' ? ' selected' : '' ?>>Onay bekliyor</option>
        <option value="hidden"<?= $postStatus === 'hidden' ? ' selected' : '' ?>>Gizli</option>
      </select>
      <button class="dg-btn dg-btn-ghost dg-btn-sm">Filtrele</button>
      <?php if ($postQuery !== '' || $postStatus !== 'all'): ?><a class="dg-btn dg-btn-ghost dg-btn-sm" href="<?= e(dg_social_admin_url(['gorunum' => 'gonderiler'])) ?>#gonderi-listesi">Temizle</a><?php endif; ?>
    </form>
    <?php if (!$postResult['items']): ?>
      <div class="dg-empty">Filtreye uyan gönderi bulunamadı.</div>
    <?php else: ?>
      <div class="dg-social-admin-list">
        <?php foreach ($postResult['items'] as $post): ?>
          <article class="dg-social-admin-item" id="admin-gonderi-<?= (int)$post['id'] ?>" style="scroll-margin-top:110px">
            <div>
              <a class="dg-social-admin-title" href="../sosyal/gonderi.php?id=<?= (int)$post['id'] ?>" target="_blank" rel="noopener"><?= e($post['title']) ?></a>
              <p><?= e($post['excerpt']) ?></p>
              <div class="dg-social-admin-meta">
                <span class="dg-chip <?= $post['status'] === 'published' ? 'on' : '' ?>"><?= e($post['status']) ?></span>
                <span class="dg-chip">@<?= e($post['author_username']) ?></span>
                <span class="dg-chip"><?= e($post['topic_slug']) ?></span>
                <?php if (trim((string)($post['prefix'] ?? '')) !== ''): ?><span class="dg-chip"><?= e(social_post_prefixes()[(string)$post['prefix']] ?? $post['prefix']) ?></span><?php endif; ?>
                <span class="dg-chip">GG <?= (int)$post['gg_count'] ?></span>
                <span class="dg-chip"><?= (int)$post['comment_count'] ?> yorum</span>
                <span class="dg-chip">👁 <?= number_format((int)($post['view_count'] ?? 0), 0, ',', '.') ?></span>
                <?php if ((int)($post['solution_comment_id'] ?? 0) > 0): ?><span class="dg-chip on">✓ Çözüldü</span><?php endif; ?>
                <?php if (!empty($post['poll'])): ?><span class="dg-chip">📊 <?= (int)($post['poll']['total_votes'] ?? 0) ?> oy</span><?php endif; ?>
                <?php if (social_is_event_post($post)): ?><span class="dg-chip">🗓 <?= (int)($post['rsvp_count'] ?? 0) ?> katılımcı</span><?php endif; ?>
                <?php if (!empty($post['award'])): ?><span class="dg-chip on">🏆 +<?= number_format((int)$post['award']['amount'], 0, ',', '.') ?> D-Coin</span><?php endif; ?>
                <?php if (!empty($post['pinned'])): ?><span class="dg-chip on">Sabit</span><?php endif; ?>
                <?php if (!empty($post['locked'])): ?><span class="dg-chip">Kilitli</span><?php endif; ?>
              </div>
            </div>
            <div class="dg-social-admin-actions">
              <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../sosyal/yeni.php?duzenle=<?= (int)$post['id'] ?>" target="_blank" rel="noopener">Düzenle</a>
              <form method="post" action="../sosyal/aksiyon.php" class="dg-social-admin-move">
                <?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_tasi"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>">
                <label class="dg-sr-only" for="move-topic-<?= (int)$post['id'] ?>">Taşınacak konu</label>
                <select id="move-topic-<?= (int)$post['id'] ?>" name="topic_slug">
                  <?php foreach ($topics as $moveTopic): ?><option value="<?= e($moveTopic['slug']) ?>"<?= (string)$moveTopic['slug'] === (string)$post['topic_slug'] ? ' selected' : '' ?>><?= e($moveTopic['icon']) ?> <?= e($moveTopic['name']) ?></option><?php endforeach; ?>
                </select>
                <button class="dg-btn dg-btn-ghost dg-btn-sm">Taşı</button>
              </form>
              <?php if (($post['status'] ?? '') === 'published' && empty($post['award']) && (int)($post['user_id'] ?? 0) > 0 && (int)$post['user_id'] !== (int)$user['id']): ?>
                <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../sosyal/gonderi.php?id=<?= (int)$post['id'] ?>#gonderi-detay" target="_blank" rel="noopener">🏆 Ödül ver</a>
              <?php endif; ?>
              <?php if ($post['status'] !== 'published'): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_onayla"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-primary dg-btn-sm">Yayınla</button></form>
              <?php else: ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_gizle"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm">Gizle</button></form>
              <?php endif; ?>
              <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_sabitle"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm"><?= !empty($post['pinned']) ? 'Sabitten kaldır' : 'Sabitle' ?></button></form>
              <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_kilitle"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm"><?= !empty($post['locked']) ? 'Kilidi aç' : 'Kilitle' ?></button></form>
              <form method="post" action="../sosyal/aksiyon.php" onsubmit="return confirm('Bu gönderi silinsin mi?')"><?= csrf_field() ?><input type="hidden" name="islem" value="gonderi_sil"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm dg-social-danger">Sil</button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ((int)$postResult['pages'] > 1): ?>
        <div class="dg-social-admin-pagination">
          <?php if ($postPage > 1): ?><a href="<?= e(dg_social_admin_url(['gorunum' => 'gonderiler', 'q' => $postQuery, 'durum' => $postStatus, 'sayfa' => $postPage - 1])) ?>#gonderi-listesi">← Önceki</a><?php endif; ?>
          <span><?= $postPage ?> / <?= (int)$postResult['pages'] ?></span>
          <?php if ($postPage < (int)$postResult['pages']): ?><a href="<?= e(dg_social_admin_url(['gorunum' => 'gonderiler', 'q' => $postQuery, 'durum' => $postStatus, 'sayfa' => $postPage + 1])) ?>#gonderi-listesi">Sonraki →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($view === 'konular'): ?>
  <div class="dg-social-admin-grid">
    <section class="dg-card" id="konu-formu" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2><?= $editingTopic ? 'Konuyu Düzenle' : 'Yeni Konu' ?></h2><?php if ($editingTopic): ?><a class="dg-btn dg-btn-ghost dg-btn-sm" href="<?= e(dg_social_admin_url(['gorunum' => 'konular'])) ?>">İptal</a><?php endif; ?></div>
      <div class="dg-card-body">
        <?php if ($topicErrors): ?>
          <div class="dg-alert dg-alert-error"><b>Konu kaydedilemedi:</b><ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($topicErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" class="dg-form">
          <?= csrf_field() ?>
          <input type="hidden" name="edit_slug" value="<?= $editingTopic ? e($editingTopic['slug']) : '' ?>">
          <label class="dg-field"><span>Konu adı *</span><input type="text" name="name" required maxlength="60" value="<?= e($topicForm['name']) ?>" placeholder="Örn. Turnuvalar"></label>
          <label class="dg-field"><span>Forum grubu</span><input type="text" name="group" maxlength="50" list="topic-groups" value="<?= e($topicForm['group']) ?>" placeholder="Topluluk"><datalist id="topic-groups"><?php foreach (array_unique(array_map(static fn(array $topic): string => (string)($topic['group'] ?? ''), $topics)) as $topicGroup): if ($topicGroup !== ''): ?><option value="<?= e($topicGroup) ?>"><?php endif; endforeach; ?></datalist></label>
          <label class="dg-field"><span>Adres (slug)<?= $editingTopic ? ' — düzenlerken korunur' : '' ?></span><input type="text" name="slug" maxlength="80" value="<?= e($topicForm['slug']) ?>" placeholder="turnuvalar"<?= $editingTopic ? ' readonly' : '' ?>></label>
          <label class="dg-field"><span>Açıklama</span><textarea name="description" maxlength="180"><?= e($topicForm['description']) ?></textarea></label>
          <div class="dg-blog-form-grid">
            <label class="dg-field"><span>İkon</span><input type="text" name="icon" maxlength="12" value="<?= e($topicForm['icon']) ?>"></label>
            <label class="dg-field"><span>Sıra</span><input type="number" name="order" min="1" max="999" value="<?= (int)$topicForm['order'] ?>"></label>
          </div>
          <label class="dg-check"><input type="checkbox" name="official_only" value="1"<?= !empty($topicForm['official_only']) ? ' checked' : '' ?>><span>Yalnızca ekip gönderi açabilir</span></label>
          <label class="dg-check"><input type="checkbox" name="active" value="1"<?= !empty($topicForm['active']) ? ' checked' : '' ?>><span>Aktif</span></label>
          <button name="save_topic" value="1" class="dg-btn dg-btn-primary dg-btn-block"><?= $editingTopic ? 'Konuyu Güncelle' : 'Konu Ekle' ?></button>
        </form>
      </div>
    </section>

    <section class="dg-card" id="konu-listesi" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>Forum Konuları</h2><span class="dg-badge"><?= count($topics) ?></span></div>
      <div class="dg-social-admin-list">
        <?php foreach ($topics as $topic): ?>
          <article class="dg-social-admin-item">
            <div>
              <strong><?= e($topic['icon']) ?> <?= e($topic['name']) ?></strong>
              <p><?= e($topic['description'] ?: 'Açıklama yok.') ?></p>
              <div class="dg-social-admin-meta">
                <span class="dg-chip <?= !empty($topic['active']) ? 'on' : '' ?>"><?= !empty($topic['active']) ? 'Aktif' : 'Pasif' ?></span>
                <span class="dg-chip">Grup: <?= e($topic['group'] ?: 'Topluluk') ?></span>
                <?php if (!empty($topic['official_only'])): ?><span class="dg-chip">Yalnızca ekip</span><?php endif; ?>
                <code><?= e($topic['slug']) ?></code>
              </div>
            </div>
            <div class="dg-social-admin-actions">
              <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../sosyal/index.php?konu=<?= rawurlencode((string)$topic['slug']) ?>" target="_blank" rel="noopener">Görüntüle</a>
              <a class="dg-btn dg-btn-ghost dg-btn-sm" href="<?= e(dg_social_admin_url(['gorunum' => 'konular', 'konu_duzenle' => (string)$topic['slug']])) ?>#konu-formu">Düzenle</a>
              <form method="post" onsubmit="return confirm('Bu konu silinsin mi? İçinde gönderi varsa silinmez.')"><?= csrf_field() ?><input type="hidden" name="topic_slug" value="<?= e($topic['slug']) ?>"><button name="delete_topic" value="1" class="dg-btn dg-btn-ghost dg-btn-sm dg-social-danger">Sil</button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
<?php endif; ?>

<?php if ($view === 'sohbet'): ?>
  <section class="dg-card" id="sohbet-kanali" style="scroll-margin-top:110px">
    <div class="dg-card-head"><h2>Canlı Sohbet Moderasyonu</h2><span class="dg-badge"><?= e($chatChannels[$adminChatChannel]['name']) ?></span></div>
    <div class="dg-card-body">
      <nav class="dg-social-admin-tabs" aria-label="Sohbet kanalları" style="margin-bottom:14px">
        <?php foreach ($chatChannels as $channel): ?>
          <a href="<?= e(dg_social_admin_url(['gorunum' => 'sohbet', 'kanal' => (string)$channel['slug']])) ?>#sohbet-kanali" class="<?= $adminChatChannel === $channel['slug'] ? 'is-active' : '' ?>"><?= e($channel['icon']) ?> <?= e($channel['name']) ?></a>
        <?php endforeach; ?>
        <a href="../sosyal/sohbet.php?kanal=<?= rawurlencode($adminChatChannel) ?>" target="_blank" rel="noopener">Kanalı aç ↗</a>
      </nav>
      <form method="post" onsubmit="return confirm('Kanal geçmişi budansın mı? Yedek almadan büyük budama yapmayın.')" class="dg-social-admin-filter">
        <?= csrf_field() ?>
        <input type="hidden" name="channel" value="<?= e($adminChatChannel) ?>">
        <label>Son <input type="number" name="keep" min="100" max="5000" step="100" value="1000"> mesajı tut</label>
        <button name="chat_prune" value="1" class="dg-btn dg-btn-ghost dg-btn-sm">Kanalı buda</button>
      </form>
      <p class="dg-muted" style="font-size:12px;line-height:1.6">Canlı kanal dosyası 750 KB'ı aşarsa sistem son 1000 mesaja da otomatik budar. Aşağıda seçili kanalın son mesajları ve gizlenmiş içerikler (silinenler hariç) görünür.</p>
    </div>
    <?php if (!$adminChatMessages): ?>
      <div class="dg-empty">Bu kanalda moderasyon için mesaj bulunmuyor.</div>
    <?php else: ?>
      <div class="dg-social-admin-list">
        <?php foreach (array_reverse($adminChatMessages) as $chatMessage): ?>
          <?php $chatAuthorProfile = social_get_profile((int)$chatMessage['user_id']); ?>
          <article class="dg-social-admin-item" id="chat-message-<?= (int)$chatMessage['id'] ?>" style="scroll-margin-top:110px">
            <div>
              <p><?= e($chatMessage['body']) ?></p>
              <div class="dg-social-admin-meta">
                <span class="dg-chip <?= ($chatMessage['status'] ?? '') === 'published' ? 'on' : '' ?>"><?= e($chatMessage['status'] ?? '') ?></span>
                <?php if (!empty($chatMessage['pinned'])): ?><span class="dg-chip on">📌 Sabit</span><?php endif; ?>
                <?php if ($chatAuthorProfile): ?><a class="dg-chip" href="../sosyal/profil.php?kullanici=<?= rawurlencode((string)$chatAuthorProfile['username']) ?>#yonetici-araclari" target="_blank" rel="noopener">@<?= e($chatAuthorProfile['username']) ?> araçları</a><?php else: ?><span class="dg-chip">@<?= e($chatMessage['author_username']) ?></span><?php endif; ?>
                <span class="dg-chip">#<?= (int)$chatMessage['id'] ?> · <?= e(date('d.m.Y H:i', (int)$chatMessage['created_at'])) ?></span>
              </div>
            </div>
            <div class="dg-social-admin-actions">
              <?php if (($chatMessage['status'] ?? '') === 'published'): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($adminChatChannel) ?>"><input type="hidden" name="message_id" value="<?= (int)$chatMessage['id'] ?>"><input type="hidden" name="status" value="hidden"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm">Gizle</button></form>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($adminChatChannel) ?>"><input type="hidden" name="message_id" value="<?= (int)$chatMessage['id'] ?>"><input type="hidden" name="status" value="<?= !empty($chatMessage['pinned']) ? 'unpin' : 'pin' ?>"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm"><?= !empty($chatMessage['pinned']) ? 'Sabitten kaldır' : 'Sabitle' ?></button></form>
              <?php elseif (($chatMessage['status'] ?? '') === 'hidden'): ?>
                <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($adminChatChannel) ?>"><input type="hidden" name="message_id" value="<?= (int)$chatMessage['id'] ?>"><input type="hidden" name="status" value="published"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-primary dg-btn-sm">Yayınla</button></form>
              <?php endif; ?>
              <form method="post" action="../sosyal/aksiyon.php" onsubmit="return confirm('Bu sohbet mesajı silinsin mi?')"><?= csrf_field() ?><input type="hidden" name="islem" value="sohbet_durum"><input type="hidden" name="channel" value="<?= e($adminChatChannel) ?>"><input type="hidden" name="message_id" value="<?= (int)$chatMessage['id'] ?>"><input type="hidden" name="status" value="deleted"><input type="hidden" name="return" value="<?= e($currentReturn) ?>"><button class="dg-btn dg-btn-ghost dg-btn-sm dg-social-danger">Sil</button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($view === 'gunluk'): ?>
  <div class="dg-social-admin-grid">
    <section class="dg-card" id="moderasyon-gunlugu" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>Son Moderasyon İşlemleri</h2><span class="dg-badge"><?= count($moderationLogs) ?></span></div>
      <?php if (!$moderationLogs): ?>
        <div class="dg-empty">Henüz moderasyon kaydı yok. Yeni işlemler aylık JSONL dosyalarına yazılır.</div>
      <?php else: ?>
        <div class="dg-social-admin-list">
          <?php foreach ($moderationLogs as $log): ?>
            <article class="dg-social-admin-item">
              <div>
                <strong><?= e($log['action']) ?></strong>
                <?php if (trim((string)$log['details']) !== ''): ?><p><?= e($log['details']) ?></p><?php endif; ?>
                <div class="dg-social-admin-meta">
                  <span class="dg-chip">Moderatör: @<?= e($log['actor_username']) ?></span>
                  <span class="dg-chip">Hedef: <?= e($log['target_type']) ?><?= (int)$log['target_id'] > 0 ? ' #' . (int)$log['target_id'] : '' ?></span>
                  <?php if ((int)$log['post_id'] > 0): ?><a class="dg-chip" href="../sosyal/gonderi.php?id=<?= (int)$log['post_id'] ?>" target="_blank" rel="noopener">Konu #<?= (int)$log['post_id'] ?></a><?php endif; ?>
                  <span class="dg-chip"><?= e(date('d.m.Y H:i', (int)$log['created_at'])) ?></span>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="dg-card" id="uyarilan-uyeler" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>Uyarı / Susturma Kaydı Olan Üyeler</h2><span class="dg-badge"><?= count($warnedMembers) ?></span></div>
      <?php if (!$warnedMembers): ?>
        <div class="dg-empty">İlk 100 yeni üye arasında aktif uyarı veya susturma kaydı bulunmuyor.</div>
      <?php else: ?>
        <div class="dg-social-admin-list">
          <?php foreach ($warnedMembers as $warnedProfile): ?>
            <?php $isMuted = (int)($warnedProfile['muted_until'] ?? 0) > time(); ?>
            <article class="dg-social-admin-item">
              <div>
                <a class="dg-social-admin-title" href="../sosyal/profil.php?kullanici=<?= rawurlencode((string)$warnedProfile['username']) ?>#yonetici-araclari" target="_blank" rel="noopener"><?= e($warnedProfile['display_name']) ?></a>
                <?php if ($isMuted && trim((string)($warnedProfile['mute_reason'] ?? '')) !== ''): ?><p><?= e($warnedProfile['mute_reason']) ?></p><?php endif; ?>
                <div class="dg-social-admin-meta">
                  <span class="dg-chip">@<?= e($warnedProfile['username']) ?></span>
                  <span class="dg-chip <?= $isMuted ? 'on' : '' ?>"><?= $isMuted ? 'Susturulmuş · ' . e(date('d.m.Y H:i', (int)$warnedProfile['muted_until'])) : 'Aktif susturma yok' ?></span>
                  <span class="dg-chip">Uyarı: <?= (int)($warnedProfile['warning_count'] ?? 0) ?></span>
                  <?php if ((int)($warnedProfile['last_warning_at'] ?? 0) > 0): ?><span class="dg-chip">Son uyarı <?= e(date('d.m.Y H:i', (int)$warnedProfile['last_warning_at'])) ?></span><?php endif; ?>
                </div>
              </div>
              <div class="dg-social-admin-actions">
                <?php if ($isMuted): ?>
                  <form method="post" action="../sosyal/aksiyon.php"><?= csrf_field() ?><input type="hidden" name="islem" value="sustur_kaldir"><input type="hidden" name="target_id" value="<?= (int)$warnedProfile['user_id'] ?>"><input type="hidden" name="return" value="<?= e(dg_social_return(['gorunum' => 'gunluk'])) ?>"><button class="dg-btn dg-btn-primary dg-btn-sm">Susturmayı kaldır</button></form>
                <?php endif; ?>
                <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../sosyal/profil.php?kullanici=<?= rawurlencode((string)$warnedProfile['username']) ?>#yonetici-araclari" target="_blank" rel="noopener">Kullanıcı araçları</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="dg-card-body"><p class="dg-muted" style="font-size:12px;line-height:1.6">Yeni uyarı/susturma, ilgili şikâyetten veya kullanıcının profilindeki Yönetici araçlarından uygulanır. Özel mesaj içeriklerinin tamamı bu panelde listelenmez.</p></div>
    </section>
  </div>
<?php endif; ?>

<?php if ($view === 'bakim'): ?>
  <?php if ($healthError !== ''): ?>
    <div class="dg-alert dg-alert-error" style="margin-bottom:16px"><?= e($healthError) ?></div>
  <?php endif; ?>

  <section class="dg-card" id="topluluk-duyurusu" style="scroll-margin-top:110px;margin-bottom:16px">
    <div class="dg-card-head"><h2>Topluluk Duyurusu</h2><span class="dg-badge"><?= !empty($announcement['active']) ? 'AKTİF' : 'KAPALI' ?></span></div>
    <div class="dg-card-body">
      <p class="dg-muted" style="font-size:13px;line-height:1.65">Aktif duyuru bütün sosyal sayfaların üstünde gösterilir. Bağlantı için <code>sohbet.php</code>, <code>../sosyal/index.php</code> gibi site içi adres veya HTTPS bağlantısı kullanın.</p>
      <?php if (!empty($announcement['active']) && trim((string)$announcement['text']) !== ''): ?><div class="dg-alert" style="margin:12px 0"><b>Önizleme:</b> <?= e($announcement['text']) ?><?php if (trim((string)$announcement['url']) !== ''): ?><br><small><?= e($announcement['url']) ?></small><?php endif; ?></div><?php endif; ?>
      <form method="post" class="dg-form" style="margin-top:14px">
        <?= csrf_field() ?>
        <label class="dg-field"><span>Duyuru metni</span><textarea name="text" maxlength="240" placeholder="Örn. Cuma 21.00'de canlı sohbette oyun gecesi var."><?= e($announcement['text'] ?? '') ?></textarea></label>
        <label class="dg-field"><span>Duyuru bağlantısı (isteğe bağlı)</span><input type="text" name="url" maxlength="500" value="<?= e($announcement['url'] ?? '') ?>" placeholder="sohbet.php?kanal=etkinlikler"></label>
        <label class="dg-check"><input type="checkbox" name="active" value="1"<?= !empty($announcement['active']) ? ' checked' : '' ?>><span>Duyuruyu göster</span></label>
        <?php if ((int)($announcement['updated_at'] ?? 0) > 0): ?><p class="dg-muted" style="font-size:12px">Son güncelleme: <?= e(date('d.m.Y H:i', (int)$announcement['updated_at'])) ?><?= (int)($announcement['updated_by'] ?? 0) > 0 ? ' · kullanıcı #' . (int)$announcement['updated_by'] : '' ?></p><?php endif; ?>
        <button name="save_announcement" value="1" class="dg-btn dg-btn-primary">Duyuruyu kaydet</button>
      </form>
    </div>
  </section>

  <div class="dg-social-admin-grid">
    <section class="dg-card" id="saglik-durumu" style="scroll-margin-top:110px">
      <div class="dg-card-head">
        <h2>Sağlık Durumu</h2>
        <?php if ($health): ?><span class="dg-badge"><?= !empty($health['healthy']) ? 'KRİTİK SORUN YOK' : 'KONTROL GEREKLİ' ?></span><?php endif; ?>
      </div>
      <?php if ($health): ?>
        <div class="dg-social-admin-list">
          <?php foreach ((array)$health['checks'] as $check): ?>
            <article class="dg-social-admin-item">
              <div>
                <strong><?= !empty($check['ok']) ? '✅' : (($check['level'] ?? '') === 'warning' ? '⚠️' : '⛔') ?> <?= e($check['label']) ?></strong>
                <p><?= e($check['detail']) ?></p>
              </div>
              <span class="dg-chip <?= !empty($check['ok']) ? 'on' : '' ?>"><?= !empty($check['ok']) ? 'Tamam' : e($check['level']) ?></span>
            </article>
          <?php endforeach; ?>
        </div>
        <div class="dg-card-body" style="padding-top:0">
          <p class="dg-muted" style="font-size:13px;line-height:1.7">
            <b><?= (int)$health['post_files'] ?></b> gönderi dosyası · <b><?= (int)$health['index_entries'] ?></b> akış özeti ·
            <b><?= (int)$health['invalid_posts'] ?></b> bozuk JSON · <b><?= (int)$health['missing_images'] ?></b> eksik görsel ·
            <b><?= (int)$health['orphan_images'] ?></b> sahipsiz görsel (<?= e(dg_social_format_bytes((int)$health['orphan_bytes'])) ?>)
          </p>
          <p class="dg-muted" style="font-size:12px;line-height:1.6">Sahipsiz görseller otomatik silinmez. Önce yedek alıp dosyayı manuel doğrulayın; indeks yeniden oluşturma görselleri temizlemez.</p>
        </div>
      <?php else: ?>
        <div class="dg-empty">Sağlık özeti alınamadı.</div>
      <?php endif; ?>
    </section>

    <section class="dg-card" id="yedek-durumu" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>Yedek Durumu</h2><span class="dg-badge">MANUEL İŞARET</span></div>
      <div class="dg-card-body">
        <p class="dg-muted" style="font-size:13px;line-height:1.65">DG Social yedeği için şu iki klasörü <b>birlikte</b> indirin:</p>
        <div class="dg-divider"></div>
        <p><code>storage/social/</code></p>
        <p><code>uploads/social/</code></p>
        <p class="dg-muted" style="font-size:12px;line-height:1.6">Hesaplar ve D-Coin bakiyelerinin de geri yüklenebilmesi için genel site yedeğinde <code>storage/dg/database.json</code> dosyasını da saklayın.</p>
        <div class="dg-divider"></div>
        <?php if ($health && (int)$health['last_backup_at'] > 0): ?>
          <p><b>Son işaret:</b> <?= e(date('d.m.Y H:i', (int)$health['last_backup_at'])) ?><?= (int)$health['last_backup_by'] > 0 ? ' · kullanıcı #' . (int)$health['last_backup_by'] : '' ?></p>
        <?php else: ?>
          <p><b>Son işaret:</b> Henüz işaretlenmemiş</p>
        <?php endif; ?>
        <ol class="dg-muted" style="font-size:13px;line-height:1.7;margin:10px 0 0 18px;padding:0">
          <li>Hosting dosya yöneticisi veya FTP ile iki sosyal klasörü indirin.</li>
          <li>Arşivin açılabildiğini ve tarihli bir kopyasını sakladığınızı doğrulayın.</li>
          <li>Ardından aşağıdaki düğmeyle bakım işaretini güncelleyin.</li>
        </ol>
        <form method="post" style="margin-top:16px" onsubmit="return confirm('İki sosyal klasörün gerçek yedeğini aldınız mı? Bu düğme yalnızca işaret koyar, dosya indirmez.')">
          <?= csrf_field() ?>
          <button name="mark_backup" value="1" class="dg-btn dg-btn-primary">Sosyal yedeğini aldım olarak işaretle</button>
        </form>
      </div>
    </section>
  </div>

  <div class="dg-social-admin-grid" style="margin-top:16px">
    <section class="dg-card">
      <div class="dg-card-head"><h2>Depolama Kırılımı</h2><span class="dg-badge">JSON / JSONL</span></div>
      <div class="dg-card-body">
        <p><b><?= (int)$storageStats['files'] ?></b> dosya · <b><?= e(dg_social_format_bytes((int)$storageStats['bytes'])) ?></b></p>
        <p class="dg-muted" style="font-size:12px;line-height:1.6">Veriler çekirdek JSON dışında profiller, gönderi belgeleri, yorum JSONL dosyaları, tepkiler ve hazır indeksler olarak tutulur.</p>
        <?php if ($health && !empty($health['breakdown'])): ?>
          <div class="dg-table-wrap" style="margin-top:14px">
            <table class="dg-table" style="min-width:0">
              <thead><tr><th>Klasör</th><th>Dosya</th><th>Boyut</th><th>Son değişim</th></tr></thead>
              <tbody>
                <?php foreach ((array)$health['breakdown'] as $path => $item): ?>
                  <tr>
                    <td><code><?= e($path) ?></code></td>
                    <td><?= (int)$item['files'] ?></td>
                    <td><?= e(dg_social_format_bytes((int)$item['bytes'])) ?></td>
                    <td><?= (int)$item['latest_mtime'] > 0 ? e(date('d.m.Y H:i', (int)$item['latest_mtime'])) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="dg-card" id="indeks-bakimi" style="scroll-margin-top:110px">
      <div class="dg-card-head"><h2>İndeks Bakımı</h2></div>
      <div class="dg-card-body">
        <p class="dg-muted" style="font-size:13px;line-height:1.65">Akış, konu, profil ve moderasyon indekslerini kanonik gönderi/yorum dosyalarından yeniden oluşturur; yorum, GG, anket oyu ve etkinlik katılım sayaçlarını doğrular.</p>
        <?php if ($health && (int)$health['latest_index_mtime'] > 0): ?>
          <p style="margin-top:12px"><b>Akış indeksi son değişim:</b> <?= e(date('d.m.Y H:i:s', (int)$health['latest_index_mtime'])) ?></p>
        <?php endif; ?>
        <form method="post" style="margin-top:16px" onsubmit="return confirm('Sosyal indeksleri yeniden oluşturulsun mu?')">
          <?= csrf_field() ?>
          <button name="rebuild_indexes" value="1" class="dg-btn dg-btn-primary">İndeksleri yeniden oluştur</button>
        </form>
        <p class="dg-muted" style="font-size:12px;line-height:1.6;margin-top:12px">Büyük topluluklarda işlem birkaç saniye sürebilir. Önce yedek almak en güvenli yoldur.</p>
      </div>
    </section>
  </div>
<?php endif; ?>

<?php admin_foot(); ?>
