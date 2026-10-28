<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/shop.php';
require_once __DIR__ . '/../lib/admin.php';

function dg_blog_filter_params(array $source, bool $fromPost = false): array
{
    $qKey = $fromPost ? 'return_q' : 'q';
    $statusKey = $fromPost ? 'return_status' : 'status';
    $categoryKey = $fromPost ? 'return_category' : 'kategori';
    $params = [];
    $q = trim((string)($source[$qKey] ?? ''));
    $status = (string)($source[$statusKey] ?? 'all');
    $category = blog_category_slugify((string)($source[$categoryKey] ?? ''));
    if ($q !== '') {
        $params['q'] = $q;
    }
    if (in_array($status, ['published', 'draft'], true)) {
        $params['status'] = $status;
    }
    if ($category !== '') {
        $params['kategori'] = $category;
    }
    return $params;
}

function dg_blog_admin_url(array $params = []): string
{
    return 'blog.php' . ($params ? '?' . http_build_query($params) : '');
}

function dg_blog_summary(array $post, int $limit = 155): string
{
    $text = trim((string)($post['excerpt'] ?? ''));
    if ($text === '') {
        $text = trim(strip_tags((string)($post['content'] ?? '')));
    }
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (mb_strlen($text, 'UTF-8') > $limit) {
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
    }
    return $text;
}

function dg_blog_admin_image_src(string $image): string
{
    $url = blog_image_url($image);
    if ($url === '' || preg_match('~^https?://~i', $url)) {
        return $url;
    }
    return '../' . ltrim($url, '/');
}

function dg_blog_safe_color(string $color, string $fallback): string
{
    return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : $fallback;
}

$formErrors = [];
$formValues = null;
$editingPost = null;
$categoryFormErrors = [];
$categoryFormValues = null;
$editingCategory = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnParams = dg_blog_filter_params($_POST, true);

    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error_set('CSRF hatası. Sayfayı yenileyip tekrar deneyin.');
        header('Location: ' . dg_blog_admin_url($returnParams));
        exit;
    }

    if (isset($_POST['save_category'])) {
        $editCategorySlug = blog_category_slugify((string)($_POST['edit_category_slug'] ?? ''));
        $categoryFormValues = $_POST;

        try {
            $result = $editCategorySlug !== ''
                ? admin_edit_blog_category($editCategorySlug, $_POST)
                : admin_add_blog_category($_POST);
        } catch (Throwable $exception) {
            error_log('[admin blog category save] ' . $exception->getMessage());
            $result = [
                'errors' => ['Kategori kaydedilemedi. storage/blog-categories.json yazma izinlerini kontrol edin.'],
                'slug' => $editCategorySlug,
            ];
        }

        if (!empty($result['errors'])) {
            $categoryFormErrors = $result['errors'];
            if ($editCategorySlug !== '') {
                $editingCategory = ['slug' => $editCategorySlug];
            }
        } else {
            flash_set($editCategorySlug !== '' ? 'Blog kategorisi güncellendi.' : 'Blog kategorisi eklendi.');
            header('Location: ' . dg_blog_admin_url($returnParams) . '#blog-categories');
            exit;
        }
    }

    if (isset($_POST['delete_category'])) {
        $categorySlug = blog_category_slugify((string)($_POST['category_slug'] ?? ''));
        try {
            $result = admin_delete_blog_category($categorySlug);
        } catch (Throwable $exception) {
            error_log('[admin blog category delete] ' . $exception->getMessage());
            $result = ['errors' => ['Kategori silinemedi. Depolama izinlerini kontrol edin.'], 'moved' => 0];
        }

        if (!empty($result['errors'])) {
            flash_error_set(implode(' ', $result['errors']));
        } else {
            if (($returnParams['kategori'] ?? '') === $categorySlug) {
                unset($returnParams['kategori']);
            }
            flash_set($result['moved'] > 0
                ? 'Kategori silindi; ' . (int)$result['moved'] . ' yazı Genel kategorisine taşındı.'
                : 'Blog kategorisi silindi.');
        }
        header('Location: ' . dg_blog_admin_url($returnParams) . '#blog-categories');
        exit;
    }

    if (isset($_POST['save_post'])) {
        $editSlug = trim((string)($_POST['edit_slug'] ?? ''));
        $formValues = $_POST;

        try {
            $result = $editSlug !== ''
                ? admin_edit_post($editSlug, $_POST)
                : admin_add_post($_POST);
        } catch (Throwable $exception) {
            error_log('[admin blog save] ' . $exception->getMessage());
            $result = [
                'errors' => ['Yazı kaydedilemedi. storage/blog.json yazma izinlerini kontrol edin.'],
                'slug' => $editSlug,
            ];
        }

        if (!empty($result['errors'])) {
            $formErrors = $result['errors'];
            $editingPost = $editSlug !== '' ? blog_post($editSlug, false) : null;
        } else {
            flash_set($editSlug !== '' ? 'Blog yazısı güncellendi.' : 'Blog yazısı eklendi.');
            header('Location: ' . dg_blog_admin_url($returnParams));
            exit;
        }
    }

    if (isset($_POST['toggle_post'])) {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $post = $slug !== '' ? blog_post($slug, false) : null;
        if (!$post) {
            flash_error_set('Yazı bulunamadı.');
        } else {
            try {
                $saved = admin_toggle_post($slug);
            } catch (Throwable $exception) {
                error_log('[admin blog toggle] ' . $exception->getMessage());
                $saved = false;
            }
            if ($saved) {
                flash_set($post['published'] ? 'Yazı taslağa alındı.' : 'Yazı yayınlandı.');
            } else {
                flash_error_set('Yayın durumu güncellenemedi.');
            }
        }
        header('Location: ' . dg_blog_admin_url($returnParams));
        exit;
    }

    if (isset($_POST['delete_post'])) {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $post = $slug !== '' ? blog_post($slug, false) : null;
        if (!$post) {
            flash_error_set('Silinecek yazı bulunamadı.');
        } else {
            try {
                $deleted = admin_delete_post($slug);
            } catch (Throwable $exception) {
                error_log('[admin blog delete] ' . $exception->getMessage());
                $deleted = false;
            }
            if ($deleted) {
                flash_set('“' . $post['title'] . '” silindi.');
            } else {
                flash_error_set('Yazı silinemedi. Depolama izinlerini kontrol edin.');
            }
        }
        header('Location: ' . dg_blog_admin_url($returnParams));
        exit;
    }
}

$filterParams = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? dg_blog_filter_params($_POST, true)
    : dg_blog_filter_params($_GET);
$q = (string)($filterParams['q'] ?? '');
$status = (string)($filterParams['status'] ?? 'all');
$categoryFilter = (string)($filterParams['kategori'] ?? '');

$posts = blog_posts(false);
$categorySummaries = blog_category_summaries(false);
$categorySummariesBySlug = [];
foreach ($categorySummaries as $category) {
    $categorySummariesBySlug[(string)$category['slug']] = $category;
}

if ($categoryFormValues === null && isset($_GET['category_edit'])) {
    $categoryEditSlug = blog_category_slugify((string)$_GET['category_edit']);
    $editingCategory = $categorySummariesBySlug[$categoryEditSlug] ?? null;
    if (!$editingCategory) {
        flash_error_set('Düzenlenecek blog kategorisi bulunamadı.');
        header('Location: ' . dg_blog_admin_url($filterParams) . '#blog-categories');
        exit;
    }
}

if ($formValues === null && isset($_GET['edit'])) {
    $editingPost = blog_post(trim((string)$_GET['edit']), false);
    if (!$editingPost) {
        flash_error_set('Düzenlenecek blog yazısı bulunamadı.');
        header('Location: ' . dg_blog_admin_url($filterParams));
        exit;
    }
}

$form = [
    'slug' => '',
    'title' => '',
    'category' => isset($categorySummariesBySlug[$categoryFilter]) ? $categoryFilter : 'genel',
    'tag' => '',
    'date' => date('Y-m-d'),
    'excerpt' => '',
    'content' => '',
    'image' => '',
    'c1' => '#0F3B2C',
    'c2' => '#2FA377',
    'published' => true,
];

if (is_array($formValues)) {
    foreach (['slug', 'title', 'category', 'tag', 'date', 'excerpt', 'content', 'image', 'c1', 'c2'] as $field) {
        $form[$field] = (string)($formValues[$field] ?? $form[$field]);
    }
    $form['published'] = !empty($formValues['published']);
    if ($editingPost) {
        $form['slug'] = (string)$editingPost['slug'];
    }
} elseif ($editingPost) {
    $form = array_merge($form, $editingPost);
}

$form['c1'] = dg_blog_safe_color((string)$form['c1'], '#0F3B2C');
$form['c2'] = dg_blog_safe_color((string)$form['c2'], '#2FA377');
$isEditing = $editingPost !== null;

$postCategoryOptions = $categorySummaries;
$formCategorySlug = blog_category_slugify((string)($form['category'] ?? ''));
if ($formCategorySlug !== '' && !isset($categorySummariesBySlug[$formCategorySlug])) {
    $postCategoryOptions[] = blog_post_category(['category' => $formCategorySlug, 'tag' => (string)($form['tag'] ?? '')]) + [
        'count' => 0,
        'published_count' => 0,
        'draft_count' => 0,
    ];
}

$categoryForm = [
    'slug' => '',
    'name' => '',
    'description' => '',
];
if (is_array($categoryFormValues)) {
    $categoryForm = [
        'slug' => (string)($editingCategory['slug'] ?? ($categoryFormValues['category_slug'] ?? '')),
        'name' => (string)($categoryFormValues['category_name'] ?? ''),
        'description' => (string)($categoryFormValues['category_description'] ?? ''),
    ];
} elseif ($editingCategory) {
    $categoryForm = [
        'slug' => (string)$editingCategory['slug'],
        'name' => (string)($editingCategory['name'] ?? ''),
        'description' => (string)($editingCategory['description'] ?? ''),
    ];
}
$isEditingCategory = $editingCategory !== null;

$filteredPosts = array_values(array_filter($posts, static function (array $post) use ($q, $status, $categoryFilter): bool {
    if ($categoryFilter !== '' && ($post['category'] ?? '') !== $categoryFilter) {
        return false;
    }
    if ($status === 'published' && empty($post['published'])) {
        return false;
    }
    if ($status === 'draft' && !empty($post['published'])) {
        return false;
    }
    if ($q === '') {
        return true;
    }
    $haystack = dg_casefold(implode(' ', [
        (string)$post['title'],
        (string)($post['category_name'] ?? ''),
        (string)($post['category'] ?? ''),
        (string)$post['tag'],
        (string)$post['slug'],
        (string)$post['excerpt'],
        strip_tags((string)$post['content']),
    ]));
    return strpos($haystack, dg_casefold($q)) !== false;
}));

$publishedCount = count(array_filter($posts, static fn(array $post): bool => !empty($post['published'])));
$draftCount = count($posts) - $publishedCount;
$latestPost = $posts[0] ?? null;
$cancelUrl = dg_blog_admin_url($filterParams);

admin_head('Blog Yönetimi');
?>

<div style="display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <p class="dg-kicker">BLOG // CONTENT MANAGEMENT</p>
    <h1 style="font-family:Bricolage Grotesque,sans-serif;font-weight:800;font-size:24px">Blog Yönetimi</h1>
    <p class="dg-muted" style="font-size:13px">Yazı ve kategori ekle, düzenle, taslak olarak sakla veya yayınla. Yazılar eski panelle ortak <code>storage/blog.json</code>, kategoriler <code>storage/blog-categories.json</code> dosyasında tutulur.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <span class="dg-badge"><?=count($posts)?> YAZI</span>
    <span class="dg-badge"><?=count($categorySummaries)?> KATEGORİ</span>
    <a href="#blog-categories" class="dg-btn dg-btn-ghost dg-btn-sm">Kategorileri Yönet</a>
    <a href="../blog/index.php" target="_blank" rel="noopener" class="dg-btn dg-btn-ghost dg-btn-sm">Blogu Görüntüle ↗</a>
    <?php if ($isEditing): ?><a href="<?=e($cancelUrl)?>#blog-editor" class="dg-btn dg-btn-primary dg-btn-sm">＋ Yeni Yazı</a><?php endif; ?>
  </div>
</div>

<div class="dg-stats" style="margin-bottom:16px">
  <div class="dg-stat"><label>📰 Toplam Yazı</label><strong><?=count($posts)?></strong><small>Tüm blog kayıtları</small></div>
  <div class="dg-stat" style="border-color:rgba(79,224,160,0.3)"><label>● Yayında</label><strong style="color:var(--cyan)"><?=$publishedCount?></strong><small>Ziyaretçiler görebilir</small></div>
  <div class="dg-stat" style="border-color:rgba(244,193,93,0.3)"><label>○ Taslak</label><strong style="color:var(--amber)"><?=$draftCount?></strong><small>Henüz gizli yazılar</small></div>
  <div class="dg-stat"><label>🕘 En Güncel Yazı</label><strong style="font-size:17px"><?=$latestPost ? e(date('d.m.Y', strtotime((string)$latestPost['date']) ?: time())) : '—'?></strong><small style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=$latestPost ? e($latestPost['title']) : 'Henüz yazı yok'?></small></div>
</div>

<div class="dg-blog-layout">
  <section class="dg-card" id="blog-editor">
    <div class="dg-card-head">
      <h2><?= $isEditing ? '✏ Yazıyı Düzenle' : '＋ Yeni Blog Yazısı' ?></h2>
      <?php if ($isEditing): ?><a href="<?=e($cancelUrl)?>" class="dg-btn dg-btn-ghost dg-btn-sm">İptal</a><?php endif; ?>
    </div>
    <div class="dg-card-body">
      <?php if ($formErrors): ?>
        <div class="dg-alert dg-alert-error" style="margin-bottom:14px">
          <b>Yazı kaydedilemedi:</b>
          <ul style="margin:6px 0 0 18px;padding:0">
            <?php foreach ($formErrors as $error): ?><li><?=e($error)?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" class="dg-form" data-blog-form>
        <?=csrf_field()?>
        <input type="hidden" name="edit_slug" value="<?=e($editingPost['slug'] ?? '')?>">
        <input type="hidden" name="return_q" value="<?=e($q)?>">
        <input type="hidden" name="return_status" value="<?=e($status)?>">
        <input type="hidden" name="return_category" value="<?=e($categoryFilter)?>">

        <label class="dg-field">
          <span>Başlık *</span>
          <input type="text" name="title" required maxlength="180" placeholder="Yazı başlığı" value="<?=e($form['title'])?>">
        </label>

        <label class="dg-field">
          <span>URL Uzantısı (Slug) <?php if (!$isEditing): ?><small style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--dim)">— boş bırakırsan başlıktan üretilir</small><?php endif; ?></span>
          <input type="text" name="slug" maxlength="120" placeholder="ornek-yazi-basligi" value="<?=e($form['slug'])?>" <?= $isEditing ? 'readonly' : '' ?>>
        </label>
        <?php if ($isEditing): ?><p class="dg-mini" style="margin-top:-8px">Bağlantıların bozulmaması için mevcut slug düzenleme sırasında korunur.</p><?php endif; ?>

        <div class="dg-blog-form-grid">
          <label class="dg-field">
            <span>Kategori *</span>
            <select name="category" required>
              <?php foreach ($postCategoryOptions as $categoryOption): ?>
                <option value="<?=e($categoryOption['slug'])?>" <?= blog_category_slugify((string)$form['category']) === (string)$categoryOption['slug'] ? 'selected' : '' ?>><?=e($categoryOption['name'])?> (<?=(int)($categoryOption['count'] ?? 0)?>)<?= empty($categoryOption['is_registered']) ? ' — mevcut yazılardan' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="dg-field">
            <span>Tarih</span>
            <input type="date" name="date" value="<?=e($form['date'])?>">
          </label>
        </div>
        <label class="dg-field">
          <span>Etiket <small style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--dim)">— isteğe bağlı kısa rozet</small></span>
          <input type="text" name="tag" maxlength="60" placeholder="Örn. Güncelleme, Video, Röportaj…" value="<?=e($form['tag'])?>">
        </label>

        <label class="dg-field">
          <span>Özet</span>
          <textarea name="excerpt" maxlength="600" placeholder="Blog listesinde ve arama sonuçlarında görünecek kısa özet…"><?=e($form['excerpt'])?></textarea>
        </label>

        <div class="dg-blog-editor-field">
          <span class="dg-blog-editor-label">İçerik *</span>
          <div class="dg-rich-editor" data-dg-rich-editor>
            <div class="dg-rich-toolbar" aria-label="Metin biçimlendirme araçları">
              <button type="button" data-command="bold"><b>Kalın</b></button>
              <button type="button" data-command="italic"><i>İtalik</i></button>
              <button type="button" data-command="underline"><u>Altı çizili</u></button>
              <span class="dg-rich-separator" aria-hidden="true"></span>
              <button type="button" data-block="h2">Başlık</button>
              <button type="button" data-block="p">Paragraf</button>
              <button type="button" data-block="blockquote">Alıntı</button>
              <span class="dg-rich-separator" aria-hidden="true"></span>
              <button type="button" data-command="insertUnorderedList">• Liste</button>
              <button type="button" data-command="insertOrderedList">1. Liste</button>
              <button type="button" data-command="createLink">🔗 Bağlantı</button>
              <button type="button" data-command="removeFormat">Biçimi temizle</button>
            </div>
            <div id="blogEditor" class="dg-rich-editable" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Blog yazı içeriği"></div>
            <textarea id="blogContent" class="dg-blog-content-source" name="content" hidden><?=e($form['content'])?></textarea>
            <div class="dg-rich-footer">
              <span>Kalın/italik metin, başlık, liste, alıntı ve bağlantı desteklenir.</span>
              <span data-word-count>0 kelime</span>
            </div>
          </div>
          <noscript><style>.dg-rich-toolbar,.dg-rich-editable,.dg-rich-footer{display:none!important}.dg-blog-content-source{display:block!important;width:100%;min-height:280px}</style></noscript>
        </div>

        <label class="dg-field">
          <span>Kapak Görseli <small style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--dim)">— örn. img/blog.jpg veya uploads/gorsel.webp</small></span>
          <input type="text" name="image" maxlength="300" placeholder="Boş bırakırsan renkli kapak kullanılır" value="<?=e($form['image'])?>">
        </label>

        <div class="dg-blog-form-grid">
          <label class="dg-field">
            <span>Kapak Rengi 1</span>
            <input class="dg-color-input" type="color" name="c1" value="<?=e($form['c1'])?>">
          </label>
          <label class="dg-field">
            <span>Kapak Rengi 2</span>
            <input class="dg-color-input" type="color" name="c2" value="<?=e($form['c2'])?>">
          </label>
        </div>

        <label class="dg-check">
          <input type="checkbox" name="published" value="1" <?= !empty($form['published']) ? 'checked' : '' ?>>
          <span>Yayınla <small style="color:var(--dim)">(işareti kaldırırsan taslak olarak saklanır)</small></span>
        </label>

        <button name="save_post" value="1" class="dg-btn dg-btn-primary dg-btn-block"><?= $isEditing ? 'Yazıyı Güncelle' : 'Yazıyı Kaydet' ?> →</button>

        <?php if ($isEditing && !empty($editingPost['published'])): ?>
          <a class="dg-btn dg-btn-ghost dg-btn-block" href="../blog/yazi.php?slug=<?=rawurlencode((string)$editingPost['slug'])?>" target="_blank" rel="noopener">Yayındaki Yazıyı Görüntüle ↗</a>
        <?php endif; ?>
      </form>
    </div>
  </section>

  <section class="dg-card">
    <div class="dg-card-head">
      <h2>Blog Yazıları</h2>
      <span class="dg-badge"><?=count($filteredPosts)?> / <?=count($posts)?></span>
    </div>

    <form method="get" class="dg-blog-filter">
      <?php if ($isEditing): ?><input type="hidden" name="edit" value="<?=e($editingPost['slug'])?>"><?php endif; ?>
      <?php if ($isEditingCategory): ?><input type="hidden" name="category_edit" value="<?=e($editingCategory['slug'])?>"><?php endif; ?>
      <div class="dg-search"><input type="search" name="q" value="<?=e($q)?>" placeholder="Başlık, kategori, etiket veya içerik ara..."></div>
      <select name="kategori" aria-label="Blog kategorisi">
        <option value="">Tüm kategoriler</option>
        <?php foreach ($categorySummaries as $category): ?>
          <option value="<?=e($category['slug'])?>" <?= $categoryFilter === (string)$category['slug'] ? 'selected' : '' ?>><?=e($category['name'])?> (<?=(int)$category['count']?>)</option>
        <?php endforeach; ?>
      </select>
      <select name="status" aria-label="Yayın durumu">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Tümü</option>
        <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Yayında</option>
        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Taslak</option>
      </select>
      <button class="dg-btn dg-btn-ghost dg-btn-sm">Filtrele</button>
      <?php if ($q !== '' || $status !== 'all' || $categoryFilter !== ''): ?><a href="<?=e(dg_blog_admin_url(array_filter([$isEditing ? 'edit' : '' => $isEditing ? $editingPost['slug'] : '', $isEditingCategory ? 'category_edit' : '' => $isEditingCategory ? $editingCategory['slug'] : ''])))?>" class="dg-btn dg-btn-ghost dg-btn-sm">Temizle</a><?php endif; ?>
    </form>

    <?php if (!$filteredPosts): ?>
      <div class="dg-empty"><?= $posts ? 'Filtreye uyan blog yazısı bulunamadı.' : 'Henüz blog yazısı yok. Soldaki formdan ilk yazını ekleyebilirsin.' ?></div>
    <?php else: ?>
      <div class="dg-blog-list">
        <?php foreach ($filteredPosts as $post): ?>
          <?php
            $isCurrent = $isEditing && $editingPost['slug'] === $post['slug'];
            $imageSrc = dg_blog_admin_image_src((string)$post['image']);
            $coverC1 = dg_blog_safe_color((string)$post['c1'], '#0F3B2C');
            $coverC2 = dg_blog_safe_color((string)$post['c2'], '#2FA377');
            $dateText = date('d.m.Y', strtotime((string)$post['date']) ?: time());
            $editParams = array_merge($filterParams, ['edit' => $post['slug']]);
          ?>
          <article class="dg-blog-row<?= $isCurrent ? ' is-editing' : '' ?>">
            <div class="dg-blog-cover" style="background:linear-gradient(135deg, <?=e($coverC1)?>, <?=e($coverC2)?>)">
              <?php if ($imageSrc !== ''): ?>
                <img src="<?=e($imageSrc)?>" alt="" loading="lazy" onerror="this.remove()">
              <?php else: ?>
                <span>DG</span>
              <?php endif; ?>
            </div>

            <div class="dg-blog-row-main">
              <?php if (!empty($post['published'])): ?>
                <a class="dg-blog-row-title" href="../blog/yazi.php?slug=<?=rawurlencode((string)$post['slug'])?>" target="_blank" rel="noopener"><?=e($post['title'])?></a>
              <?php else: ?>
                <span class="dg-blog-row-title"><?=e($post['title'])?></span>
              <?php endif; ?>
              <p><?=e(dg_blog_summary($post))?></p>
              <div class="dg-blog-meta">
                <span class="dg-chip <?= !empty($post['published']) ? 'on' : '' ?>"><?= !empty($post['published']) ? '● Yayında' : '○ Taslak' ?></span>
                <span class="dg-chip">📂 <?=e($post['category_name'] ?? 'Genel')?></span>
                <?php if (trim((string)$post['tag']) !== '' && blog_category_slugify((string)$post['tag']) !== (string)($post['category'] ?? '') && shop_fold((string)$post['tag']) !== shop_fold((string)($post['category_name'] ?? ''))): ?><span class="dg-chip"><?=e($post['tag'])?></span><?php endif; ?>
                <span class="dg-chip"><?=$dateText?></span>
                <code>/<?=e($post['slug'])?></code>
                <?php if ($isCurrent): ?><span class="dg-chip on">Düzenleniyor</span><?php endif; ?>
              </div>
            </div>

            <div class="dg-blog-actions">
              <?php if (!empty($post['published'])): ?>
                <a href="../blog/yazi.php?slug=<?=rawurlencode((string)$post['slug'])?>" target="_blank" rel="noopener" class="dg-btn dg-btn-ghost dg-btn-sm">Görüntüle</a>
              <?php endif; ?>
              <a href="<?=e(dg_blog_admin_url($editParams))?>#blog-editor" class="dg-btn dg-btn-ghost dg-btn-sm">Düzenle</a>
              <form method="post">
                <?=csrf_field()?>
                <input type="hidden" name="slug" value="<?=e($post['slug'])?>">
                <input type="hidden" name="return_q" value="<?=e($q)?>">
                <input type="hidden" name="return_status" value="<?=e($status)?>">
                <input type="hidden" name="return_category" value="<?=e($categoryFilter)?>">
                <button name="toggle_post" value="1" class="dg-btn <?= !empty($post['published']) ? 'dg-btn-ghost' : 'dg-btn-primary' ?> dg-btn-sm"><?= !empty($post['published']) ? 'Gizle' : 'Yayınla' ?></button>
              </form>
              <form method="post" onsubmit="return confirm('Bu blog yazısı kalıcı olarak silinsin mi?')">
                <?=csrf_field()?>
                <input type="hidden" name="slug" value="<?=e($post['slug'])?>">
                <input type="hidden" name="return_q" value="<?=e($q)?>">
                <input type="hidden" name="return_status" value="<?=e($status)?>">
                <input type="hidden" name="return_category" value="<?=e($categoryFilter)?>">
                <button name="delete_post" value="1" class="dg-btn dg-btn-ghost dg-btn-sm dg-blog-delete">Sil</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="dg-card" id="blog-categories" style="margin-top:16px">
  <div class="dg-card-head">
    <h2>📂 Blog Kategorileri</h2>
    <span class="dg-badge"><?=count($categorySummaries)?> KATEGORİ</span>
  </div>
  <div class="dg-blog-category-layout">
    <div class="dg-card-body">
      <h3 style="margin-bottom:6px;font-size:16px"><?= $isEditingCategory ? 'Kategoriyi Düzenle' : 'Yeni Kategori Ekle' ?></h3>
      <p class="dg-muted" style="font-size:12.5px;margin-bottom:14px">Kategoriler blog listesinde filtre olarak görünür. Düzenleme sırasında kategori adresi (slug), mevcut bağlantılar bozulmasın diye korunur.</p>

      <?php if ($categoryFormErrors): ?>
        <div class="dg-alert dg-alert-error" style="margin-bottom:14px">
          <b>Kategori kaydedilemedi:</b>
          <ul style="margin:6px 0 0 18px;padding:0">
            <?php foreach ($categoryFormErrors as $error): ?><li><?=e($error)?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="#blog-categories" class="dg-form">
        <?=csrf_field()?>
        <input type="hidden" name="edit_category_slug" value="<?= $isEditingCategory ? e($categoryForm['slug']) : '' ?>">
        <input type="hidden" name="return_q" value="<?=e($q)?>">
        <input type="hidden" name="return_status" value="<?=e($status)?>">
        <input type="hidden" name="return_category" value="<?=e($categoryFilter)?>">

        <label class="dg-field">
          <span>Kategori Adı *</span>
          <input type="text" name="category_name" required maxlength="60" placeholder="Örn. Etkinlik, İnceleme, Röportaj…" value="<?=e($categoryForm['name'])?>">
        </label>

        <label class="dg-field">
          <span>Kategori Adresi (Slug) <?php if (!$isEditingCategory): ?><small style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--dim)">— boş bırakırsan addan üretilir</small><?php endif; ?></span>
          <input type="text" name="category_slug" maxlength="80" placeholder="ornek-kategori" value="<?=e($categoryForm['slug'])?>" <?= $isEditingCategory ? 'readonly' : '' ?>>
        </label>

        <label class="dg-field">
          <span>Açıklama</span>
          <textarea name="category_description" maxlength="240" placeholder="Bu kategoride hangi yazılar yer alacak?"><?=e($categoryForm['description'])?></textarea>
        </label>

        <?php if ($isEditingCategory && empty($categorySummariesBySlug[$categoryForm['slug']]['is_registered'])): ?>
          <p class="dg-mini">Bu kategori eski yazılardaki etiketten geliyor; kaydedersen resmi kategori listesine eklenecek.</p>
        <?php endif; ?>

        <button name="save_category" value="1" class="dg-btn dg-btn-primary dg-btn-block"><?= $isEditingCategory ? 'Kategoriyi Güncelle' : 'Kategori Ekle' ?> →</button>
        <?php if ($isEditingCategory): ?>
          <a class="dg-btn dg-btn-ghost dg-btn-block" href="<?=e(dg_blog_admin_url(array_merge($filterParams, $isEditing ? ['edit' => $editingPost['slug']] : [])))?>#blog-categories">Düzenlemeyi İptal Et</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="dg-blog-category-list">
      <?php foreach ($categorySummaries as $category): ?>
        <?php
          $isCurrentCategory = $isEditingCategory && (string)$editingCategory['slug'] === (string)$category['slug'];
          $categoryEditParams = array_merge($filterParams, $isEditing ? ['edit' => $editingPost['slug']] : [], ['category_edit' => $category['slug']]);
          $deleteConfirm = (int)$category['count'] > 0
            ? 'Bu kategori silinsin mi? İçindeki ' . (int)$category['count'] . ' yazı Genel kategorisine taşınacak.'
            : 'Bu boş kategori silinsin mi?';
        ?>
        <article class="dg-blog-category-row<?= $isCurrentCategory ? ' is-editing' : '' ?>">
          <div class="dg-blog-category-main">
            <strong><?=e($category['name'])?></strong>
            <div class="dg-blog-meta">
              <span class="dg-chip <?= (int)$category['count'] > 0 ? 'on' : '' ?>"><?=(int)$category['count']?> yazı</span>
              <span class="dg-chip"><?=(int)$category['published_count']?> yayında</span>
              <?php if ((int)$category['draft_count'] > 0): ?><span class="dg-chip"><?=(int)$category['draft_count']?> taslak</span><?php endif; ?>
              <?php if (empty($category['is_registered'])): ?><span class="dg-chip">Eski yazılardan geldi</span><?php endif; ?>
              <code>?kategori=<?=e($category['slug'])?></code>
              <?php if ($isCurrentCategory): ?><span class="dg-chip on">Düzenleniyor</span><?php endif; ?>
            </div>
            <p><?=e(trim((string)$category['description']) !== '' ? $category['description'] : 'Açıklama yok.')?></p>
          </div>
          <div class="dg-blog-actions">
            <?php if ((int)$category['published_count'] > 0): ?>
              <a class="dg-btn dg-btn-ghost dg-btn-sm" href="../blog/index.php?kategori=<?=rawurlencode((string)$category['slug'])?>" target="_blank" rel="noopener">Blogda Gör</a>
            <?php endif; ?>
            <a class="dg-btn dg-btn-ghost dg-btn-sm" href="<?=e(dg_blog_admin_url($categoryEditParams))?>#blog-categories">Düzenle</a>
            <?php if ((string)$category['slug'] === 'genel'): ?>
              <button type="button" class="dg-btn dg-btn-ghost dg-btn-sm" disabled title="Genel kategorisi silinemez">Silinemez</button>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('<?=e($deleteConfirm)?>')">
                <?=csrf_field()?>
                <input type="hidden" name="category_slug" value="<?=e($category['slug'])?>">
                <input type="hidden" name="return_q" value="<?=e($q)?>">
                <input type="hidden" name="return_status" value="<?=e($status)?>">
                <input type="hidden" name="return_category" value="<?=e($categoryFilter)?>">
                <button name="delete_category" value="1" class="dg-btn dg-btn-ghost dg-btn-sm dg-blog-delete">Sil</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<script>
(() => {
  const wrap = document.querySelector('[data-dg-rich-editor]');
  const form = document.querySelector('[data-blog-form]');
  const editor = document.getElementById('blogEditor');
  const output = document.getElementById('blogContent');
  if (!wrap || !form || !editor || !output) return;

  const count = wrap.querySelector('[data-word-count]');
  const allowedTags = new Set(['P', 'H2', 'H3', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A', 'BR']);

  const escapeHtml = (value) => value.replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));

  const cleanHtml = (html) => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.body.querySelectorAll('script,style,iframe,object,embed,form').forEach((node) => node.remove());
    [...doc.body.querySelectorAll('*')].reverse().forEach((node) => {
      if (!allowedTags.has(node.tagName)) {
        node.replaceWith(...node.childNodes);
        return;
      }
      [...node.attributes].forEach((attribute) => {
        if (!(node.tagName === 'A' && attribute.name.toLowerCase() === 'href')) {
          node.removeAttribute(attribute.name);
        }
      });
      if (node.tagName === 'A') {
        const href = node.getAttribute('href') || '';
        if (!/^(https?:\/\/|mailto:|\/|#)/i.test(href)) node.removeAttribute('href');
      }
    });
    return doc.body.innerHTML;
  };

  const plainToHtml = (text) => {
    let html = '';
    let paragraph = [];
    let inList = false;
    const closeParagraph = () => {
      if (!paragraph.length) return;
      html += `<p>${escapeHtml(paragraph.join(' '))}</p>`;
      paragraph = [];
    };
    const closeList = () => {
      if (!inList) return;
      html += '</ul>';
      inList = false;
    };

    text.split(/\r\n|\r|\n/).forEach((line) => {
      const trimmed = line.trim();
      if (!trimmed) {
        closeParagraph();
        closeList();
        return;
      }
      if (trimmed.startsWith('## ')) {
        closeParagraph();
        closeList();
        html += `<h2>${escapeHtml(trimmed.slice(3).trim())}</h2>`;
        return;
      }
      if (trimmed.startsWith('- ')) {
        closeParagraph();
        if (!inList) {
          html += '<ul>';
          inList = true;
        }
        html += `<li>${escapeHtml(trimmed.slice(2).trim())}</li>`;
        return;
      }
      closeList();
      paragraph.push(trimmed);
    });
    closeParagraph();
    closeList();
    return html;
  };

  const serializeEditor = () => {
    const clone = editor.cloneNode(true);
    [...clone.querySelectorAll('div')].reverse().forEach((div) => {
      const paragraph = document.createElement('p');
      paragraph.replaceChildren(...div.childNodes);
      div.replaceWith(paragraph);
    });
    return clone.innerHTML;
  };

  const sync = () => {
    const text = editor.innerText.replace(/\u00a0/g, ' ').trim();
    output.value = text ? serializeEditor() : '';
    if (count) count.textContent = `${text ? text.split(/\s+/).length : 0} kelime`;
  };

  const rawContent = output.value.trim();
  if (rawContent) {
    editor.innerHTML = /<\/?(p|h2|h3|strong|b|em|i|u|ul|ol|li|blockquote|a|br)\b/i.test(rawContent)
      ? cleanHtml(rawContent)
      : plainToHtml(rawContent);
  }

  try {
    document.execCommand('styleWithCSS', false, false);
    document.execCommand('defaultParagraphSeparator', false, 'p');
  } catch (_) {}

  wrap.querySelectorAll('[data-command]').forEach((button) => {
    button.addEventListener('click', () => {
      const command = button.dataset.command;
      editor.focus();

      if (command === 'createLink') {
        const selection = window.getSelection();
        const range = selection && selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
        const url = prompt('Bağlantı adresini girin (https://…):');
        if (!url) return;
        if (!/^(https?:\/\/|mailto:|\/|#)/i.test(url.trim())) {
          alert('Bağlantı https://, mailto:, / veya # ile başlamalı.');
          return;
        }
        if (range && selection) {
          selection.removeAllRanges();
          selection.addRange(range);
        }
        document.execCommand('createLink', false, url.trim());
      } else {
        document.execCommand(command, false, null);
      }
      sync();
    });
  });

  wrap.querySelectorAll('[data-block]').forEach((button) => {
    button.addEventListener('click', () => {
      editor.focus();
      document.execCommand('formatBlock', false, button.dataset.block);
      sync();
    });
  });

  editor.addEventListener('input', sync);
  editor.addEventListener('paste', (event) => {
    event.preventDefault();
    const text = (event.clipboardData || window.clipboardData)?.getData('text/plain') || '';
    document.execCommand('insertText', false, text);
    sync();
  });

  form.addEventListener('submit', (event) => {
    sync();
    if (editor.innerText.trim().length < 10) {
      event.preventDefault();
      alert('Yazı içeriği en az 10 karakter olmalı.');
      editor.focus();
    }
  });

  sync();
})();
</script>

<?php admin_foot(); ?>
