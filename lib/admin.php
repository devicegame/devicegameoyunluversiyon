<?php
/**
 * Sipariş yönetimi yardımcıları (yalnızca admin.php kullanır).
 */

if (!defined('DG_ADMIN')) {
    define('DG_ADMIN', true);

    function admin_storage_dir(): string
    {
        return __DIR__ . '/../storage';
    }

    /** Tüm siparişleri en yeniden eskiye döndürür. */
    function admin_orders(): array
    {
        $file = admin_storage_dir() . '/orders.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $orders = [];
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $order = json_decode($line, true);
            if (is_array($order) && isset($order['id'])) {
                $orders[] = $order;
            }
        }
        fclose($handle);

        return array_reverse($orders);
    }

    function admin_status_map(): array
    {
        $file = admin_storage_dir() . '/order-status.json';
        if (!is_file($file)) {
            return [];
        }
        $map = json_decode((string)@file_get_contents($file), true);
        return is_array($map) ? $map : [];
    }

    function admin_status_labels(): array
    {
        return [
            'new'         => 'Yeni',
            'preparing'   => 'Hazırlanıyor',
            'done'        => 'Tamamlandı',
            'cancelled'   => 'İptal',
        ];
    }

    function admin_set_status(string $id, string $status): bool
    {
        if (!isset(admin_status_labels()[$status])) {
            return false;
        }
        $map = admin_status_map();
        $map[$id] = $status;

        $dir = admin_storage_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($map, JSON_UNESCAPED_UNICODE);
        return $json !== false && @file_put_contents($dir . '/order-status.json', $json, LOCK_EX) !== false;
    }

    function admin_order_status(array $order, array $map): string
    {
        $status = $map[$order['id']] ?? 'new';
        return isset(admin_status_labels()[$status]) ? $status : 'new';
    }

    /** Arama: sipariş no, ad, telefon, e-posta, ürün adı. */
    function admin_match(array $order, string $query): bool
    {
        if ($query === '') {
            return true;
        }
        $haystack = mb_strtolower(implode(' ', [
            $order['id'] ?? '',
            $order['name'] ?? '',
            $order['phone'] ?? '',
            $order['email'] ?? '',
            implode(' ', array_column($order['items'] ?? [], 'name')),
        ]), 'UTF-8');

        return str_contains($haystack, mb_strtolower($query, 'UTF-8'));
    }

    function admin_stats(array $orders, array $status_map): array
    {
        $stats = ['total' => count($orders), 'revenue' => 0, 'new' => 0, 'today_count' => 0, 'today_revenue' => 0];
        $today = date('Y-m-d');
        foreach ($orders as $order) {
            $status = admin_order_status($order, $status_map);
            if ($status === 'cancelled') {
                continue;
            }
            $stats['revenue'] += (int)($order['subtotal'] ?? 0);
            if ($status === 'new') {
                $stats['new']++;
            }
            if (str_starts_with((string)($order['created_at'] ?? ''), $today)) {
                $stats['today_count']++;
                $stats['today_revenue'] += (int)($order['subtotal'] ?? 0);
            }
        }
        return $stats;
    }

    function admin_date(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date('d.m.Y H:i', $ts) : '—';
    }

    function admin_order_message(array $order): string
    {
        return 'Merhaba ' . ($order['name'] ?? '') . ', ' . ($order['id'] ?? '') . ' numaralı siparişiniz hakkında yazıyoruz.';
    }

    /* ------------------------------------------------------------------
     * Ürün yönetimi (storage/products.json)
     * ---------------------------------------------------------------- */

    /** Türkçe karakterleri sadeleştirip URL-dostu slug üretir. */
    function admin_slugify(string $text): string
    {
        $slug = shop_fold($text);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    /** İsimden kısa rozet harfi üretir (ör. "EA SPORTS FC" → "EAS"). */
    function admin_glyph(string $name): string
    {
        $letters = preg_replace('/[^A-Za-zÇĞİÖŞÜçğıöşü0-9 ]/u', '', $name) ?? '';
        $letters = preg_replace('/\s+/u', ' ', trim($letters)) ?? '';
        $out = '';
        foreach (array_slice(explode(' ', $letters), 0, 3) as $word) {
            if ($word !== '') {
                $out .= mb_substr($word, 0, 1, 'UTF-8');
            }
        }
        $out = mb_strtoupper($out, 'UTF-8');
        return $out !== '' ? mb_substr($out, 0, 3, 'UTF-8') : 'DG';
    }

    function admin_slug_unique(string $slug, array $products): string
    {
        $taken = array_column($products, 'slug');
        $base = $slug !== '' ? $slug : 'urun';
        $candidate = $base;
        $i = 2;
        while (in_array($candidate, $taken, true)) {
            $candidate = $base . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    function admin_save_products(array $products): bool
    {
        $saved = shop_write_json(shop_products_file(), $products);
        shop_products_flush();
        return $saved;
    }

    /**
     * Formdan ürün üretir/günceller. $existing verilirse o ürünün
     * slug/sıra/tarih bilgileri korunur (düzenleme modu).
     * Dönen değer: ['errors' => string[], 'product' => ?array]
     */
    function admin_product_from_input(array $input, ?array $existing = null): array
    {
        $errors = [];
        $name = trim((string)($input['name'] ?? ''));
        $subtitle = trim((string)($input['subtitle'] ?? ''));
        $category = (string)($input['category'] ?? '');
        $price = (int)($input['price'] ?? 0);
        $old_price = trim((string)($input['old_price'] ?? ''));
        $platform = trim((string)($input['platform'] ?? '')) ?: 'PC';
        $badge = trim((string)($input['badge'] ?? ''));
        $pattern = (string)($input['pattern'] ?? 'grid');
        $stock = (int)($input['stock'] ?? 10);
        $desc = trim((string)($input['desc'] ?? ''));
        $image = trim((string)($input['image'] ?? ''));
        $delivery = trim((string)($input['delivery'] ?? '')) ?: 'Anında teslimat';

        if (mb_strlen($name, 'UTF-8') < 2) {
            $errors[] = 'Ürün adı en az 2 karakter olmalı.';
        }
        if (!isset(shop_categories()[$category])) {
            $errors[] = 'Geçerli bir kategori seç.';
        }
        if ($price < 1) {
            $errors[] = 'Fiyat 1 TL\'den büyük olmalı.';
        }
        if ($old_price !== '' && (int)$old_price < 1) {
            $errors[] = 'Eski fiyat boş ya da 1\'den büyük olmalı.';
        }
        if ($stock < 0) {
            $stock = 0;
        }
        if ($errors) {
            return ['errors' => $errors, 'product' => null];
        }

        if ($existing !== null) {
            $slug = (string)($existing['slug'] ?? '');
            $order = (int)($existing['order'] ?? 0);
            $added_at = (string)($existing['added_at'] ?? date('Y-m-d'));
        } else {
            $products = shop_products();
            $slug = admin_slug_unique(admin_slugify(trim((string)($input['slug'] ?? '')) ?: $name), $products);
            $maxOrder = 0;
            foreach ($products as $item) {
                $maxOrder = max($maxOrder, (int)($item['order'] ?? 0));
            }
            $order = $maxOrder + 1;
            $added_at = date('Y-m-d');
        }

        $features = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($input['features'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $features[] = $line;
            }
        }

        $colors = [
            'c1'     => trim((string)($input['c1'] ?? '')),
            'c2'     => trim((string)($input['c2'] ?? '')),
            'accent' => trim((string)($input['accent'] ?? '')),
        ];
        foreach ($colors as $key => $value) {
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $colors[$key] = ['c1' => '#0F3B2C', 'c2' => '#2FA377', 'accent' => '#D6FFE9'][$key];
            }
        }

        $product = shop_normalize_product([
            'slug'      => $slug,
            'name'      => $name,
            'subtitle'  => $subtitle,
            'category'  => $category,
            'price'     => $price,
            'old_price' => $old_price === '' ? null : (int)$old_price,
            'platform'  => $platform,
            'badge'     => $badge === '' ? null : $badge,
            'glyph'     => admin_glyph($name),
            'c1'        => $colors['c1'],
            'c2'        => $colors['c2'],
            'accent'    => $colors['accent'],
            'pattern'   => $pattern,
            'delivery'  => $delivery,
            'stock'     => $stock,
            'order'     => $order,
            'added_at'  => $added_at,
            'desc'      => $desc,
            'features'  => $features,
            'image'     => $image,
        ]);

        return ['errors' => [], 'product' => $product];
    }

    /**
     * Formdan yeni ürün üretir ve kataloğa ekler.
     * Dönen değer: ['errors' => string[], 'slug' => string]
     */
    function admin_add_product(array $input): array
    {
        $result = admin_product_from_input($input);
        if ($result['errors'] || !$result['product']) {
            return ['errors' => $result['errors'] ?: ['Ürün oluşturulamadı.'], 'slug' => ''];
        }
        $products = shop_products();
        $products[] = $result['product'];
        if (!admin_save_products($products)) {
            return ['errors' => ['Ürün kaydedilemedi (storage/ yazma iznini kontrol et).'], 'slug' => ''];
        }
        return ['errors' => [], 'slug' => $result['product']['slug']];
    }

    /** Mevcut ürünü formdaki bilgilerle günceller (slug korunur). */
    function admin_edit_product(string $slug, array $input): array
    {
        $products = shop_products();
        $index = -1;
        foreach ($products as $i => $product) {
            if ($product['slug'] === $slug) {
                $index = $i;
                break;
            }
        }
        if ($index < 0) {
            return ['errors' => ['Ürün bulunamadı.'], 'slug' => $slug];
        }
        $result = admin_product_from_input($input, $products[$index]);
        if ($result['errors'] || !$result['product']) {
            return ['errors' => $result['errors'] ?: ['Ürün güncellenemedi.'], 'slug' => $slug];
        }
        $products[$index] = $result['product'];
        if (!admin_save_products($products)) {
            return ['errors' => ['Ürün kaydedilemedi (storage/ yazma iznini kontrol et).'], 'slug' => $slug];
        }
        return ['errors' => [], 'slug' => $slug];
    }

    /** Ürünü katalogdan siler. */
    function admin_delete_product(string $slug): bool
    {
        $products = shop_products();
        $filtered = array_values(array_filter($products, fn($p) => $p['slug'] !== $slug));
        if (count($filtered) === count($products)) {
            return false;
        }
        return admin_save_products($filtered);
    }

    /* ------------------------------------------------------------------
     * Slider yönetimi (storage/slides.json)
     * ---------------------------------------------------------------- */
    function admin_save_slides(array $slides): bool
    {
        $normalized = [];
        foreach (array_values($slides) as $i => $slide) {
            $slide = shop_normalize_slide($slide, $i + 1);
            $slide['order'] = $i + 1;
            $normalized[] = $slide;
        }
        return shop_write_json(shop_slides_file(), $normalized);
    }

    function admin_slide_index(array $slides, string $id): int
    {
        foreach ($slides as $i => $slide) {
            if ($slide['id'] === $id) {
                return $i;
            }
        }
        return -1;
    }

    /** Formdan yeni slayt üretir. */
    function admin_slide_from_input(array $input): array
    {
        $colors = [];
        foreach (['c1', 'c2'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            $colors[$key] = preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : ($key === 'c1' ? '#0F3B2C' : '#2FA377');
        }
        return shop_normalize_slide([
            'id'     => ((admin_slugify(trim((string)($input['title'] ?? ''))) ?: 'slide') . '-' . substr(bin2hex(random_bytes(3)), 0, 6)),
            'tag'    => trim((string)($input['tag'] ?? '')),
            'title'  => trim((string)($input['title'] ?? '')),
            'text'   => trim((string)($input['text'] ?? '')),
            'button' => trim((string)($input['button'] ?? '')),
            'link'   => trim((string)($input['link'] ?? '')),
            'image'  => trim((string)($input['image'] ?? '')),
            'c1'     => $colors['c1'],
            'c2'     => $colors['c2'],
            'active' => !empty($input['active']),
            'order'  => 999,
        ]);
    }

    /* ------------------------------------------------------------------
     * Blog yönetimi (storage/blog.json)
     * ---------------------------------------------------------------- */
    function admin_save_posts(array $posts): bool
    {
        $normalized = [];
        foreach (array_values($posts) as $i => $post) {
            $post = blog_normalize_post($post, $i + 1);
            $post['order'] = $i + 1;
            $normalized[] = $post;
        }
        return shop_write_json(blog_file(), $normalized);
    }

    function admin_save_blog_categories(array $categories): bool
    {
        $normalized = [];
        $seen = [];
        foreach (array_values($categories) as $i => $category) {
            if (!is_array($category)) {
                continue;
            }
            $category = blog_normalize_category($category, $i + 1);
            if (isset($seen[$category['slug']])) {
                continue;
            }
            $seen[$category['slug']] = true;
            $category['order'] = $i + 1;
            $normalized[] = $category;
        }
        if (!isset($seen['genel'])) {
            foreach (blog_default_categories() as $default) {
                if ($default['slug'] === 'genel') {
                    $normalized[] = blog_normalize_category($default, count($normalized) + 1);
                    break;
                }
            }
        }
        return shop_write_json(blog_categories_file(), $normalized);
    }

    /** Formdan kategori üretir. Düzenlemede mevcut slug korunur. */
    function admin_blog_category_from_input(array $input, ?array $existing = null): array
    {
        $errors = [];
        $name = trim((string)($input['category_name'] ?? $input['name'] ?? ''));
        $description = trim((string)($input['category_description'] ?? $input['description'] ?? ''));
        $slug = $existing !== null
            ? blog_category_slugify((string)($existing['slug'] ?? ''))
            : blog_category_slugify(trim((string)($input['category_slug'] ?? $input['slug'] ?? '')) ?: $name);

        if (mb_strlen($name, 'UTF-8') < 2) {
            $errors[] = 'Kategori adı en az 2 karakter olmalı.';
        }
        if (mb_strlen($name, 'UTF-8') > 60) {
            $errors[] = 'Kategori adı en fazla 60 karakter olabilir.';
        }
        if ($slug === '') {
            $errors[] = 'Kategori adresi (slug) oluşturulamadı; geçerli bir kategori adı kullanın.';
        }
        if (mb_strlen($description, 'UTF-8') > 240) {
            $errors[] = 'Kategori açıklaması en fazla 240 karakter olabilir.';
        }
        if ($errors) {
            return ['errors' => $errors, 'category' => null];
        }

        return [
            'errors' => [],
            'category' => blog_normalize_category([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'order' => (int)($existing['order'] ?? 999),
            ], 999),
        ];
    }

    function admin_add_blog_category(array $input): array
    {
        $result = admin_blog_category_from_input($input);
        if ($result['errors'] || !$result['category']) {
            return ['errors' => $result['errors'] ?: ['Kategori oluşturulamadı.'], 'slug' => ''];
        }
        $category = $result['category'];
        $categories = blog_categories();
        foreach ($categories as $existing) {
            if ($existing['slug'] === $category['slug'] || shop_fold($existing['name']) === shop_fold($category['name'])) {
                return ['errors' => ['Bu kategori adı veya adresi zaten kullanılıyor.'], 'slug' => ''];
            }
        }

        // Eski yazılardan gelen aynı slug burada kayıtlı kategoriye dönüşür.
        $category['order'] = count($categories) + 1;
        $categories[] = $category;
        if (!admin_save_blog_categories($categories)) {
            return ['errors' => ['Kategori kaydedilemedi (storage/ yazma iznini kontrol edin).'], 'slug' => ''];
        }
        return ['errors' => [], 'slug' => $category['slug']];
    }

    function admin_edit_blog_category(string $slug, array $input): array
    {
        $slug = blog_category_slugify($slug);
        $categories = blog_categories();
        $index = -1;
        foreach ($categories as $i => $category) {
            if ($category['slug'] === $slug) {
                $index = $i;
                break;
            }
        }

        $existing = $index >= 0 ? $categories[$index] : null;
        if ($existing === null) {
            foreach (blog_category_summaries(false) as $summary) {
                if (($summary['slug'] ?? '') === $slug) {
                    $existing = blog_normalize_category($summary, 999);
                    break;
                }
            }
        }
        if ($existing === null) {
            return ['errors' => ['Kategori bulunamadı.'], 'slug' => $slug];
        }

        $result = admin_blog_category_from_input($input, $existing);
        if ($result['errors'] || !$result['category']) {
            return ['errors' => $result['errors'] ?: ['Kategori güncellenemedi.'], 'slug' => $slug];
        }
        $updated = $result['category'];
        foreach ($categories as $category) {
            if ($category['slug'] !== $slug && shop_fold($category['name']) === shop_fold($updated['name'])) {
                return ['errors' => ['Bu kategori adı başka bir kategoride kullanılıyor.'], 'slug' => $slug];
            }
        }

        if ($index >= 0) {
            $categories[$index] = $updated;
        } else {
            $updated['order'] = count($categories) + 1;
            $categories[] = $updated;
        }
        if (!admin_save_blog_categories($categories)) {
            return ['errors' => ['Kategori kaydedilemedi (storage/ yazma iznini kontrol edin).'], 'slug' => $slug];
        }
        return ['errors' => [], 'slug' => $slug];
    }

    /** Kategoriyi siler; yazıları silmek yerine Genel kategorisine taşır. */
    function admin_delete_blog_category(string $slug): array
    {
        $slug = blog_category_slugify($slug);
        if ($slug === 'genel') {
            return ['errors' => ['Genel kategorisi güvenlik için silinemez.'], 'moved' => 0];
        }

        $posts = blog_posts(false);
        $moved = 0;
        foreach ($posts as $i => $post) {
            if (($post['category'] ?? '') === $slug) {
                $posts[$i]['category'] = 'genel';
                $moved++;
            }
        }

        $categories = blog_categories();
        $remaining = array_values(array_filter($categories, fn($category) => $category['slug'] !== $slug));
        $wasRegistered = count($remaining) !== count($categories);
        if (!$wasRegistered && $moved === 0) {
            return ['errors' => ['Kategori bulunamadı.'], 'moved' => 0];
        }
        if ($moved > 0 && !admin_save_posts($posts)) {
            return ['errors' => ['Kategorideki yazılar Genel kategorisine taşınamadı.'], 'moved' => 0];
        }
        if ($wasRegistered && !admin_save_blog_categories($remaining)) {
            return ['errors' => ['Kategori silinemedi (storage/ yazma iznini kontrol edin).'], 'moved' => $moved];
        }
        return ['errors' => [], 'moved' => $moved];
    }

    /**
     * Formdan blog yazısı üretir/günceller. $existing verilirse slug korunur.
     * Dönen değer: ['errors' => string[], 'post' => ?array]
     */
    function admin_post_from_input(array $input, ?array $existing = null): array
    {
        $errors = [];
        $title = trim((string)($input['title'] ?? ''));
        $tag = trim((string)($input['tag'] ?? ''));
        $category = '';
        if (array_key_exists('category', $input) && trim((string)$input['category']) !== '') {
            $category = blog_category_slugify((string)$input['category']);
            $knownCategories = [];
            foreach (blog_category_summaries(false) as $summary) {
                $knownCategories[(string)$summary['slug']] = true;
            }
            if ($existing !== null) {
                $knownCategories[blog_category_slugify((string)($existing['category'] ?? $existing['tag'] ?? ''))] = true;
            }
            if ($category === '' || !isset($knownCategories[$category])) {
                $errors[] = 'Geçerli bir blog kategorisi seçin.';
            }
        } elseif ($existing !== null) {
            // Eski form kategori alanı göndermezse yazının mevcut kategorisi korunur.
            $category = blog_category_slugify((string)($existing['category'] ?? $existing['tag'] ?? ''));
        } else {
            // Kategori alanı olmayan eski akışlarda etiket kategoriye dönüştürülür.
            $category = blog_category_slugify($tag);
        }
        if ($category === '') {
            $category = 'genel';
        }
        $date = trim((string)($input['date'] ?? ''));
        $excerpt = trim((string)($input['excerpt'] ?? ''));
        $content = trim((string)($input['content'] ?? ''));
        $image = trim((string)($input['image'] ?? ''));

        if (mb_strlen($title, 'UTF-8') < 2) {
            $errors[] = 'Yazı başlığı en az 2 karakter olmalı.';
        }
        if (mb_strlen($content, 'UTF-8') < 10) {
            $errors[] = 'Yazı içeriği en az 10 karakter olmalı.';
        }
        if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false)) {
            $errors[] = 'Tarih YYYY-AA-GG biçiminde olmalı.';
        }
        if ($errors) {
            return ['errors' => $errors, 'post' => null];
        }

        if ($existing !== null) {
            $slug = (string)($existing['slug'] ?? '');
            $order = (int)($existing['order'] ?? 0);
        } else {
            $slug = admin_slug_unique(admin_slugify(trim((string)($input['slug'] ?? '')) ?: $title), blog_posts(false));
            $order = 999;
        }

        $colors = [];
        foreach (['c1', 'c2'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            $colors[$key] = preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : ($key === 'c1' ? '#0F3B2C' : '#2FA377');
        }

        $post = blog_normalize_post([
            'slug'      => $slug,
            'title'     => $title,
            'category'  => $category,
            'tag'       => $tag,
            'date'      => $date !== '' ? $date : (($existing['date'] ?? '') ?: date('Y-m-d')),
            'excerpt'   => $excerpt,
            'content'   => $content,
            'image'     => $image,
            'c1'        => $colors['c1'],
            'c2'        => $colors['c2'],
            'published' => !empty($input['published']),
            'order'     => $order,
        ]);

        return ['errors' => [], 'post' => $post];
    }

    function admin_add_post(array $input): array
    {
        $result = admin_post_from_input($input);
        if ($result['errors'] || !$result['post']) {
            return ['errors' => $result['errors'] ?: ['Yazı oluşturulamadı.'], 'slug' => ''];
        }
        $posts = blog_posts(false);
        $posts[] = $result['post'];
        if (!admin_save_posts($posts)) {
            return ['errors' => ['Yazı kaydedilemedi (storage/ yazma iznini kontrol et).'], 'slug' => ''];
        }
        return ['errors' => [], 'slug' => $result['post']['slug']];
    }

    function admin_edit_post(string $slug, array $input): array
    {
        $posts = blog_posts(false);
        $index = -1;
        foreach ($posts as $i => $post) {
            if ($post['slug'] === $slug) {
                $index = $i;
                break;
            }
        }
        if ($index < 0) {
            return ['errors' => ['Yazı bulunamadı.'], 'slug' => $slug];
        }
        $result = admin_post_from_input($input, $posts[$index]);
        if ($result['errors'] || !$result['post']) {
            return ['errors' => $result['errors'] ?: ['Yazı güncellenemedi.'], 'slug' => $slug];
        }
        $posts[$index] = $result['post'];
        if (!admin_save_posts($posts)) {
            return ['errors' => ['Yazı kaydedilemedi (storage/ yazma iznini kontrol et).'], 'slug' => $slug];
        }
        return ['errors' => [], 'slug' => $slug];
    }

    function admin_delete_post(string $slug): bool
    {
        $posts = blog_posts(false);
        $filtered = array_values(array_filter($posts, fn($p) => $p['slug'] !== $slug));
        if (count($filtered) === count($posts)) {
            return false;
        }
        return admin_save_posts($filtered);
    }

    /** Blog yazısının yayın durumunu tersine çevirir. */
    function admin_toggle_post(string $slug): bool
    {
        $posts = blog_posts(false);
        foreach ($posts as $index => $post) {
            if ($post['slug'] === $slug) {
                $posts[$index]['published'] = !$post['published'];
                return admin_save_posts($posts);
            }
        }
        return false;
    }
}
