<?php
/**
 * Device Game Social — veritabansız forum + akış çekirdeği.
 *
 * Veriler tek bir dev JSON dosyasında tutulmaz:
 * - Profiller, gönderiler ve tepkiler küçük JSON belgeleridir.
 * - Yorumlar ve şikâyetler gönderi/ay bazlı JSONL kayıtlarıdır.
 * - Akışlar, özet indeks dosyalarından beslenir.
 *
 * Bütün yazma işlemleri tek bir sosyal kilidi altında yapılır; JSON belgeleri
 * geçici dosyaya yazılıp rename() ile atomik olarak değiştirilir.
 */
if (!defined('DG_SOCIAL')) {
    define('DG_SOCIAL', true);

    function social_base_dir(): string
    {
        return dirname(__DIR__);
    }

    function social_storage_dir(): string
    {
        return social_base_dir() . '/storage/social';
    }

    function social_upload_dir(): string
    {
        return social_base_dir() . '/uploads/social';
    }

    function social_dir(string $name = ''): string
    {
        return social_storage_dir() . ($name !== '' ? '/' . trim($name, '/') : '');
    }

    function social_lock_file(): string
    {
        return social_storage_dir() . '/social.lock';
    }

    function social_meta_file(): string
    {
        return social_storage_dir() . '/meta.json';
    }

    function social_topics_file(): string
    {
        return social_storage_dir() . '/topics.json';
    }

    function social_bucket(int $id): string
    {
        return str_pad((string)(abs($id) % 100), 2, '0', STR_PAD_LEFT);
    }

    function social_profile_file(int $userId): string
    {
        return social_dir('profiles') . '/' . $userId . '.json';
    }

    function social_post_file(int $postId): string
    {
        return social_dir('posts') . '/' . social_bucket($postId) . '/' . $postId . '.json';
    }

    function social_comments_file(int $postId): string
    {
        return social_dir('comments') . '/' . social_bucket($postId) . '/' . $postId . '.jsonl';
    }

    function social_reactions_file(int $postId): string
    {
        return social_dir('reactions') . '/' . social_bucket($postId) . '/' . $postId . '.json';
    }

    function social_follows_file(int $userId): string
    {
        return social_dir('follows') . '/' . $userId . '.json';
    }

    function social_saves_file(int $userId): string
    {
        return social_dir('saves') . '/' . $userId . '.json';
    }

    function social_notifications_file(int $userId): string
    {
        return social_dir('notifications') . '/' . $userId . '.json';
    }

    function social_rate_file(int $userId): string
    {
        return social_dir('ratelimits') . '/' . $userId . '.json';
    }

    function social_reports_file(int $timestamp): string
    {
        return social_dir('reports') . '/' . date('Y-m', $timestamp) . '.jsonl';
    }

    function social_index_file(string $name): string
    {
        $name = preg_replace('/[^a-z0-9_.-]+/i', '-', $name) ?? 'index';
        return social_dir('indexes') . '/' . trim($name, '-') . '.json';
    }

    function social_watches_file(int $userId): string
    {
        return social_dir('watches') . '/' . $userId . '.json';
    }

    function social_reads_file(int $userId): string
    {
        return social_dir('reads') . '/' . $userId . '.json';
    }

    function social_presence_file(int $userId): string
    {
        return social_dir('presence') . '/' . $userId . '.json';
    }

    function social_dm_meta_file(int $conversationId): string
    {
        return social_dir('messages') . '/meta/' . social_bucket($conversationId) . '/' . $conversationId . '.json';
    }

    function social_dm_messages_file(int $conversationId): string
    {
        return social_dir('messages') . '/' . social_bucket($conversationId) . '/' . $conversationId . '.jsonl';
    }

    function social_dm_index_file(int $userId): string
    {
        return social_index_file('dm-user-' . $userId);
    }

    function social_chat_file(string $channel): string
    {
        return social_dir('chats') . '/' . (social_slugify($channel) ?: 'genel') . '.jsonl';
    }

    function social_moderation_log_file(int $timestamp): string
    {
        return social_dir('logs') . '/' . date('Y-m', $timestamp) . '.jsonl';
    }

    function social_fold(string $text): string
    {
        $text = strtr($text, [
            'İ' => 'i', 'I' => 'ı', 'Ğ' => 'ğ', 'Ü' => 'ü', 'Ş' => 'ş', 'Ö' => 'ö', 'Ç' => 'ç',
        ]);
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    function social_slugify(string $text): string
    {
        $slug = social_fold(trim($text));
        $slug = strtr($slug, [
            'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ı' => 'i', 'ö' => 'o', 'ç' => 'c',
            'â' => 'a', 'î' => 'i', 'û' => 'u',
        ]);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    function social_text_len(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    function social_text_sub(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($text, $start, null, 'UTF-8') : mb_substr($text, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }

    function social_clean_text(string $text, int $maxLength): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        if (social_text_len($text) > $maxLength) {
            $text = rtrim(social_text_sub($text, 0, $maxLength));
        }
        return $text;
    }

    function social_excerpt(string $text, int $limit = 240): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/^>\s?/mu', '', $text) ?? $text;
        $text = str_replace(['**', '__', '||', '`'], '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (social_text_len($text) > $limit) {
            return rtrim(social_text_sub($text, 0, $limit)) . '…';
        }
        return $text;
    }

    function social_time_ago(int $timestamp): string
    {
        $diff = max(0, time() - $timestamp);
        if ($diff < 60) return 'şimdi';
        if ($diff < 3600) return (int)floor($diff / 60) . ' dk önce';
        if ($diff < 86400) return (int)floor($diff / 3600) . ' sa önce';
        if ($diff < 604800) return (int)floor($diff / 86400) . ' g önce';
        return date('d.m.Y', $timestamp);
    }

    function social_format_date(int $timestamp): string
    {
        return date('d.m.Y H:i', $timestamp);
    }

    function social_time_until(int $timestamp): string
    {
        $diff = $timestamp - time();
        if ($diff <= 0) return 'başladı';
        if ($diff < 3600) return max(1, (int)ceil($diff / 60)) . ' dk sonra';
        if ($diff < 86400) return (int)ceil($diff / 3600) . ' sa sonra';
        if ($diff < 604800) return (int)ceil($diff / 86400) . ' g sonra';
        return date('d.m.Y', $timestamp);
    }

    function social_create_dirs(): void
    {
        $dirs = [
            social_storage_dir(),
            social_dir('profiles'),
            social_dir('posts'),
            social_dir('comments'),
            social_dir('reactions'),
            social_dir('follows'),
            social_dir('saves'),
            social_dir('notifications'),
            social_dir('reports'),
            social_dir('indexes'),
            social_dir('ratelimits'),
            social_dir('messages'),
            social_dir('messages') . '/meta',
            social_dir('chats'),
            social_dir('watches'),
            social_dir('reads'),
            social_dir('presence'),
            social_dir('logs'),
            social_upload_dir(),
            social_upload_dir() . '/avatars',
        ];
        for ($i = 0; $i < 100; $i++) {
            $bucket = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $dirs[] = social_dir('posts') . '/' . $bucket;
            $dirs[] = social_dir('comments') . '/' . $bucket;
            $dirs[] = social_dir('reactions') . '/' . $bucket;
            $dirs[] = social_dir('messages') . '/' . $bucket;
            $dirs[] = social_dir('messages') . '/meta/' . $bucket;
        }
        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Sosyal depolama klasörü oluşturulamadı: ' . $dir);
            }
        }
        $guard = social_storage_dir() . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\n");
        }
        $index = social_storage_dir() . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
        $uploadIndex = social_upload_dir() . '/index.html';
        if (!is_file($uploadIndex)) {
            @file_put_contents($uploadIndex, '');
        }
    }

    function social_with_lock(callable $callback)
    {
        static $depth = 0;
        if ($depth > 0) {
            return $callback();
        }
        if (!is_dir(social_storage_dir())) {
            social_create_dirs();
        }
        $lock = @fopen(social_lock_file(), 'c+');
        if ($lock === false) {
            throw new RuntimeException('Sosyal veri kilidi oluşturulamadı. storage/social yazma iznini kontrol edin.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Sosyal veri dosyası kilitlenemedi. Lütfen tekrar deneyin.');
            }
            $depth++;
            return $callback();
        } finally {
            $depth = max(0, $depth - 1);
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    function social_read_json(string $file, array $default = []): array
    {
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Sosyal veri dosyası okunamadı: ' . basename($file));
        }
        if (trim($raw) === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Sosyal veri dosyası bozuk görünüyor: ' . basename($file));
        }
        return $data;
    }

    function social_write_json_atomic(string $file, array $data, bool $pretty = true): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Sosyal veri klasörü oluşturulamadı.');
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0);
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new RuntimeException('Sosyal verisi JSON olarak yazılamadı: ' . json_last_error_msg());
        }
        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json . "\n") === false) {
            throw new RuntimeException('Sosyal geçici dosyası yazılamadı.');
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Sosyal veri dosyası güncellenemedi: ' . basename($file));
        }
    }

    function social_append_jsonl(string $file, array $record): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Sosyal kayıt klasörü oluşturulamadı.');
        }
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false || @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Sosyal kaydı eklenemedi.');
        }
        @chmod($file, 0664);
    }

    function social_read_jsonl(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Sosyal kayıt dosyası okunamadı: ' . basename($file));
        }
        $rows = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (is_array($row)) $rows[] = $row;
            }
        } finally {
            @fclose($handle);
        }
        return $rows;
    }

    function social_write_jsonl_atomic(string $file, array $records): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Sosyal kayıt klasörü oluşturulamadı.');
        }
        $lines = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) throw new RuntimeException('Sosyal kaydı JSONL olarak yazılamadı.');
            $lines[] = $line;
        }
        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $lines ? implode("\n", $lines) . "\n" : '') === false) {
            throw new RuntimeException('Sosyal geçici kayıt dosyası yazılamadı.');
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Sosyal kayıt dosyası güncellenemedi: ' . basename($file));
        }
    }

    function social_default_topics(): array
    {
        return [
            ['slug' => 'genel-sohbet', 'name' => 'Genel Sohbet', 'group' => 'Topluluk', 'description' => 'Oyun gündemi ve günlük sohbet.', 'icon' => '💬', 'official_only' => false, 'active' => true, 'order' => 1],
            ['slug' => 'duyurular', 'name' => 'Duyurular', 'group' => 'DeviceGame Stüdyo', 'description' => 'DeviceGame ekibinden resmî haberler.', 'icon' => '📣', 'official_only' => true, 'active' => true, 'order' => 2],
            ['slug' => 'oyun-onerileri', 'name' => 'Oyun Önerileri', 'group' => 'Topluluk', 'description' => 'Ne oynasam diyenlere topluluk önerileri.', 'icon' => '🎮', 'official_only' => false, 'active' => true, 'order' => 3],
            ['slug' => 'oyun-arkadasi', 'name' => 'Oyun Arkadaşı', 'group' => 'Topluluk', 'description' => 'Birlikte oynayacak ekip arkadaşları bul.', 'icon' => '🤝', 'official_only' => false, 'active' => true, 'order' => 4],
            ['slug' => 'yardim', 'name' => 'Yardım & Destek', 'group' => 'Destek ve Bilgi', 'description' => 'Teknik sorular, hesap ve mağaza yardımı.', 'icon' => '🛠', 'official_only' => false, 'active' => true, 'order' => 5],
            ['slug' => 'project-overcharge', 'name' => 'Project: Overcharge', 'group' => 'DeviceGame Stüdyo', 'description' => 'Stüdyo projemiz hakkında gelişmeler ve fikirler.', 'icon' => '🚀', 'official_only' => false, 'active' => true, 'order' => 6],
            ['slug' => 'rehberler', 'name' => 'Rehberler', 'group' => 'Destek ve Bilgi', 'description' => 'Topluluk tarafından hazırlanan oyun rehberleri.', 'icon' => '📚', 'official_only' => false, 'active' => true, 'order' => 7],
            ['slug' => 'magaza-firsatlari', 'name' => 'Mağaza & Fırsatlar', 'group' => 'Mağaza', 'description' => 'İndirimler, stoklar ve D-Coin fırsatları.', 'icon' => '🏷', 'official_only' => false, 'active' => true, 'order' => 8],
            ['slug' => 'etkinlikler', 'name' => 'Etkinlikler', 'group' => 'Topluluk', 'description' => 'Oyun geceleri, topluluk buluşmaları ve haftalık etkinlik planları.', 'icon' => '🗓', 'official_only' => false, 'active' => true, 'order' => 9],
        ];
    }

    function social_topic_group_for_slug(string $slug): string
    {
        return match ($slug) {
            'duyurular', 'project-overcharge' => 'DeviceGame Stüdyo',
            'yardim', 'rehberler' => 'Destek ve Bilgi',
            'magaza-firsatlari' => 'Mağaza',
            default => 'Topluluk',
        };
    }

    function social_normalize_topic(array $topic, int $fallbackOrder = 99): array
    {
        $slug = social_slugify((string)($topic['slug'] ?? $topic['name'] ?? ''));
        if ($slug === '') $slug = 'genel-sohbet';
        $name = trim((string)($topic['name'] ?? ''));
        if ($name === '') $name = ucwords(str_replace('-', ' ', $slug));
        $group = social_clean_text((string)($topic['group'] ?? ''), 50);
        if ($group === '') $group = social_topic_group_for_slug($slug);
        return [
            'slug' => $slug,
            'name' => social_clean_text($name, 60),
            'group' => $group,
            'description' => social_clean_text((string)($topic['description'] ?? ''), 180),
            'icon' => social_clean_text((string)($topic['icon'] ?? '💬'), 12) ?: '💬',
            'official_only' => !empty($topic['official_only']),
            'active' => !isset($topic['active']) || !empty($topic['active']),
            'order' => (int)($topic['order'] ?? $fallbackOrder),
        ];
    }

    function social_default_meta(): array
    {
        $now = time();
        return [
            'schema_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'counters' => ['posts' => 1, 'comments' => 1, 'reports' => 1, 'notifications' => 1, 'conversations' => 1, 'direct_messages' => 1, 'chat_messages' => 1, 'modlogs' => 1],
            'migrations' => [],
            'last_backup_at' => 0,
            'last_backup_by' => 0,
            'announcement' => ['text' => '', 'url' => '', 'active' => false, 'updated_at' => 0, 'updated_by' => 0],
        ];
    }

    function social_official_profile(): array
    {
        $now = time();
        return social_normalize_profile([
            'user_id' => 0,
            'username' => 'devicegame',
            'display_name' => 'DeviceGame',
            'bio' => 'DeviceGame resmî topluluk hesabı. Duyurular, geliştirici günlükleri ve topluluk etkinlikleri burada.',
            'platforms' => ['PC'],
            'favorite_games' => ['Project: Overcharge'],
            'show_balance' => false,
            'trust_level' => 2,
            'is_official' => true,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    function social_ensure_storage(): void
    {
        static $done = false;
        static $running = false;
        if ($done || $running) return;
        $running = true;
        try {
            social_create_dirs();
            social_with_lock(static function (): void {
                $metaExists = is_file(social_meta_file());
                $hasExistingPosts = !empty(glob(social_dir('posts') . '/*/*.json') ?: []);
                // Meta dosyası yanlışlıkla silinmişse başlangıç gönderileriyle mevcut kayıtların üzerine yazma.
                $first = !$metaExists && !$hasExistingPosts;
                if (!$metaExists) {
                    social_write_json_atomic(social_meta_file(), social_default_meta());
                }
                if (!is_file(social_topics_file())) {
                    social_write_json_atomic(social_topics_file(), social_default_topics());
                }
                $meta = social_meta_locked();
                if (!is_array($meta['migrations'] ?? null)) $meta['migrations'] = [];
                if (empty($meta['migrations']['events_topic_v1'])) {
                    $topics = [];
                    foreach (social_read_json(social_topics_file(), social_default_topics()) as $i => $topic) {
                        if (is_array($topic)) $topics[] = social_normalize_topic($topic, $i + 1);
                    }
                    $hasEventsTopic = false;
                    foreach ($topics as $topic) if ((string)$topic['slug'] === 'etkinlikler') $hasEventsTopic = true;
                    if (!$hasEventsTopic) {
                        foreach (social_default_topics() as $topic) {
                            if ((string)$topic['slug'] === 'etkinlikler') $topics[] = social_normalize_topic($topic, 9);
                        }
                        usort($topics, static fn(array $a, array $b): int => [$a['order'], social_fold($a['name'])] <=> [$b['order'], social_fold($b['name'])]);
                        social_write_json_atomic(social_topics_file(), $topics);
                    }
                    if (!is_file(social_index_file('topic-etkinlikler'))) {
                        social_write_json_atomic(social_index_file('topic-etkinlikler'), [], false);
                    }
                    $meta['migrations']['events_topic_v1'] = time();
                    $meta['updated_at'] = time();
                    social_write_json_atomic(social_meta_file(), $meta);
                }
                foreach (['latest', 'moderation-comments'] as $index) {
                    $file = social_index_file($index);
                    if (!is_file($file)) social_write_json_atomic($file, [], false);
                }
                if (!is_file(social_index_file('users'))) {
                    social_write_json_atomic(social_index_file('users'), ['map' => [], 'summaries' => []], false);
                }
                if (!is_file(social_profile_file(0))) {
                    $official = social_official_profile();
                    social_write_json_atomic(social_profile_file(0), $official);
                    social_update_users_index_locked($official);
                }
                if ($first) {
                    $now = time();
                    $seeds = [
                        [
                            'topic_slug' => 'duyurular',
                            'title' => 'DG Social’a hoş geldin!',
                            'body' => "DeviceGame’in kendi topluluk alanı açıldı.\n\nBurada oyun önerileri paylaşabilir, oyun arkadaşı bulabilir, Project: Overcharge hakkında fikir verebilir ve diğer oyuncularla sohbet edebilirsin.\n\nİlk olarak kendini tanıtmak ister misin? Hangi platformda oynuyorsun ve şu sıralar hangi oyundasın?",
                            'tags' => ['hos-geldin', 'topluluk'],
                            'pinned' => true,
                            'created_at' => $now,
                        ],
                        [
                            'topic_slug' => 'genel-sohbet',
                            'title' => 'Hafta sonu ne oynuyorsun?',
                            'body' => "Hafta sonu planı belli mi?\n\nYeni bir oyuna mı başlayacaksın, yarım kalan hikâyeyi mi bitireceksin, yoksa arkadaşlarla rekabetçi maçlar mı var? Oynadığın oyunu ve şu ana kadarki izlenimini yaz.",
                            'tags' => ['hafta-sonu', 'sohbet'],
                            'pinned' => false,
                            'created_at' => $now - 60,
                        ],
                        [
                            'topic_slug' => 'oyun-arkadasi',
                            'title' => 'Oyun arkadaşı ilanı nasıl açılır?',
                            'body' => "İyi bir ekip ilanı için oyunu, platformunu, rankını, mikrofon durumunu ve genelde hangi saatlerde oynadığını yaz.\n\nBu konudaki şablonu yeni gönderi formunda doldurman yeterli. Kişisel telefon numarası veya şifre gibi özel bilgilerini asla paylaşma.",
                            'tags' => ['lfg', 'rehber'],
                            'pinned' => false,
                            'created_at' => $now - 120,
                            'meta' => ['game' => 'Tüm oyunlar', 'platform' => 'PC / Konsol', 'rank' => 'Herkes', 'mic' => 'İsteğe bağlı', 'play_time' => 'Akşam', 'needed' => 'Ekip'],
                        ],
                    ];
                    foreach ($seeds as $seed) {
                        social_seed_post_locked($seed);
                    }
                }
            });
            $done = true;
        } finally {
            $running = false;
        }
    }

    function social_meta_locked(): array
    {
        $meta = social_read_json(social_meta_file(), social_default_meta());
        $meta += social_default_meta();
        if (!is_array($meta['counters'] ?? null)) $meta['counters'] = [];
        $meta['counters'] += ['posts' => 1, 'comments' => 1, 'reports' => 1, 'notifications' => 1, 'conversations' => 1, 'direct_messages' => 1, 'chat_messages' => 1, 'modlogs' => 1];
        if (!is_array($meta['migrations'] ?? null)) $meta['migrations'] = [];
        $meta['last_backup_at'] = max(0, (int)($meta['last_backup_at'] ?? 0));
        $meta['last_backup_by'] = max(0, (int)($meta['last_backup_by'] ?? 0));
        $announcement = is_array($meta['announcement'] ?? null) ? $meta['announcement'] : [];
        $meta['announcement'] = [
            'text' => social_clean_text((string)($announcement['text'] ?? ''), 240),
            'url' => social_clean_text((string)($announcement['url'] ?? ''), 300),
            'active' => !empty($announcement['active']),
            'updated_at' => max(0, (int)($announcement['updated_at'] ?? 0)),
            'updated_by' => max(0, (int)($announcement['updated_by'] ?? 0)),
        ];
        return $meta;
    }

    function social_next_id_locked(string $type): int
    {
        $meta = social_meta_locked();
        $id = max(1, (int)($meta['counters'][$type] ?? 1));
        $meta['counters'][$type] = $id + 1;
        $meta['updated_at'] = time();
        social_write_json_atomic(social_meta_file(), $meta);
        return $id;
    }

    function social_topics(bool $activeOnly = true): array
    {
        social_ensure_storage();
        $topics = [];
        foreach (social_read_json(social_topics_file(), social_default_topics()) as $i => $topic) {
            if (!is_array($topic)) continue;
            $topic = social_normalize_topic($topic, $i + 1);
            if ($activeOnly && empty($topic['active'])) continue;
            $topics[] = $topic;
        }
        usort($topics, static fn(array $a, array $b): int => [$a['order'], social_fold($a['name'])] <=> [$b['order'], social_fold($b['name'])]);
        return $topics;
    }

    function social_topic_map(bool $activeOnly = false): array
    {
        $map = [];
        foreach (social_topics($activeOnly) as $topic) $map[$topic['slug']] = $topic;
        return $map;
    }

    function social_topic(string $slug, bool $activeOnly = false): ?array
    {
        return social_topic_map($activeOnly)[social_slugify($slug)] ?? null;
    }

    function social_default_notification_prefs(): array
    {
        return [
            'comments' => true,
            'replies' => true,
            'mentions' => true,
            'follows' => true,
            'events' => true,
            'awards' => true,
            'messages' => true,
            'watching' => true,
            'solutions' => true,
            'auto_watch' => true,
        ];
    }

    function social_normalize_notification_prefs($value): array
    {
        $prefs = social_default_notification_prefs();
        if (!is_array($value)) return $prefs;
        foreach ($prefs as $key => $default) {
            if (array_key_exists($key, $value)) $prefs[$key] = !empty($value[$key]);
        }
        return $prefs;
    }

    function social_notification_prefs(array $profile): array
    {
        return social_normalize_notification_prefs($profile['notification_prefs'] ?? []);
    }

    function social_normalize_profile(array $profile, ?array $user = null): array
    {
        $userId = (int)($profile['user_id'] ?? $user['id'] ?? 0);
        $username = trim((string)($profile['username'] ?? $user['username'] ?? ''));
        if ($username === '' && $userId === 0) $username = 'devicegame';
        $display = trim((string)($profile['display_name'] ?? ''));
        if ($display === '') $display = $username !== '' ? $username : 'DeviceGame Oyuncusu';
        $joined = (int)($profile['joined_at'] ?? 0);
        if ($joined <= 0 && !empty($user['created_at'])) {
            $parsed = strtotime((string)$user['created_at']);
            $joined = $parsed ?: time();
        }
        if ($joined <= 0) $joined = time();
        $cleanList = static function ($value, int $limit, int $length): array {
            if (is_string($value)) $value = preg_split('/[\n,]+/u', $value) ?: [];
            if (!is_array($value)) return [];
            $out = [];
            foreach ($value as $item) {
                $item = social_clean_text((string)$item, $length);
                if ($item !== '' && !in_array($item, $out, true)) $out[] = $item;
                if (count($out) >= $limit) break;
            }
            return $out;
        };
        $avatar = ltrim(str_replace('\\', '/', trim((string)($profile['avatar'] ?? ''))), '/');
        if (!preg_match('~^uploads/social/avatars/[A-Za-z0-9_./-]+$~', $avatar) || str_contains($avatar, '..')) $avatar = '';
        $dmPolicy = (string)($profile['dm_policy'] ?? 'everyone');
        if (!in_array($dmPolicy, ['everyone', 'following', 'nobody'], true)) $dmPolicy = 'everyone';
        return [
            'schema_version' => 2,
            'user_id' => $userId,
            'username' => $username,
            'display_name' => social_clean_text($display, 50),
            'bio' => social_clean_text((string)($profile['bio'] ?? ''), 500),
            'signature' => social_clean_text((string)($profile['signature'] ?? ''), 240),
            'avatar' => $avatar,
            'platforms' => $cleanList($profile['platforms'] ?? [], 6, 30),
            'favorite_games' => $cleanList($profile['favorite_games'] ?? [], 10, 60),
            'show_balance' => !empty($profile['show_balance']),
            'show_online' => !isset($profile['show_online']) || !empty($profile['show_online']),
            'dm_policy' => $dmPolicy,
            'notification_prefs' => social_normalize_notification_prefs($profile['notification_prefs'] ?? []),
            'trust_level' => max(0, min(2, (int)($profile['trust_level'] ?? 0))),
            'muted_until' => max(0, (int)($profile['muted_until'] ?? 0)),
            'mute_reason' => social_clean_text((string)($profile['mute_reason'] ?? ''), 240),
            'warning_count' => max(0, (int)($profile['warning_count'] ?? 0)),
            'last_warning_at' => max(0, (int)($profile['last_warning_at'] ?? 0)),
            'is_official' => !empty($profile['is_official']) || $userId === 0,
            'follower_count' => max(0, (int)($profile['follower_count'] ?? 0)),
            'following_count' => max(0, (int)($profile['following_count'] ?? 0)),
            'post_count' => max(0, (int)($profile['post_count'] ?? 0)),
            'comment_count' => max(0, (int)($profile['comment_count'] ?? 0)),
            'approved_posts' => max(0, (int)($profile['approved_posts'] ?? $profile['post_count'] ?? 0)),
            'approved_comments' => max(0, (int)($profile['approved_comments'] ?? $profile['comment_count'] ?? 0)),
            'joined_at' => $joined,
            'created_at' => (int)($profile['created_at'] ?? $joined),
            'updated_at' => (int)($profile['updated_at'] ?? time()),
        ];
    }

    function social_default_profile(array $user): array
    {
        return social_normalize_profile([
            'user_id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'display_name' => (string)($user['username'] ?? ''),
            'bio' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ], $user);
    }

    function social_update_users_index_locked(array $profile): void
    {
        $file = social_index_file('users');
        $index = social_read_json($file, ['map' => [], 'summaries' => []]);
        if (!is_array($index['map'] ?? null)) $index['map'] = [];
        if (!is_array($index['summaries'] ?? null)) $index['summaries'] = [];
        $id = (int)$profile['user_id'];
        foreach ($index['map'] as $name => $mappedId) {
            if ((int)$mappedId === $id && social_fold((string)$name) !== social_fold((string)$profile['username'])) {
                unset($index['map'][$name]);
            }
        }
        $index['map'][social_fold((string)$profile['username'])] = $id;
        $index['summaries'][(string)$id] = [
            'user_id' => $id,
            'username' => (string)$profile['username'],
            'display_name' => (string)$profile['display_name'],
            'bio' => social_excerpt((string)$profile['bio'], 160),
            'avatar' => (string)($profile['avatar'] ?? ''),
            'is_official' => !empty($profile['is_official']),
            'trust_level' => (int)$profile['trust_level'],
            'follower_count' => (int)$profile['follower_count'],
            'post_count' => (int)$profile['post_count'],
        ];
        social_write_json_atomic($file, $index, false);
    }

    function social_users_index(): array
    {
        social_ensure_storage();
        $index = social_read_json(social_index_file('users'), ['map' => [], 'summaries' => []]);
        if (!is_array($index['map'] ?? null)) $index['map'] = [];
        if (!is_array($index['summaries'] ?? null)) $index['summaries'] = [];
        return $index;
    }

    function social_get_profile(int $userId, ?array $user = null): ?array
    {
        social_ensure_storage();
        if ($userId === 0) {
            $profile = social_read_json(social_profile_file(0), []);
            return $profile ? social_normalize_profile($profile) : social_official_profile();
        }
        if ($user === null && function_exists('dg_find_user_by_id')) {
            $user = dg_find_user_by_id($userId);
        }
        $file = social_profile_file($userId);
        if (is_file($file)) {
            $profile = social_normalize_profile(social_read_json($file), $user ?: []);
            if ($user && (string)($user['username'] ?? '') !== '') {
                $profile['username'] = (string)$user['username'];
            }
            return $profile;
        }
        return $user ? social_default_profile($user) : null;
    }

    function social_ensure_profile(array $user): array
    {
        social_ensure_storage();
        return social_with_lock(static function () use ($user): array {
            $id = (int)($user['id'] ?? 0);
            if ($id === 0) return social_official_profile();
            $file = social_profile_file($id);
            $exists = is_file($file);
            $profile = $exists
                ? social_normalize_profile(social_read_json($file), $user)
                : social_default_profile($user);
            $username = (string)($user['username'] ?? $profile['username']);
            $trust = !empty($user['is_admin']) ? max(1, (int)$profile['trust_level']) : (int)$profile['trust_level'];
            if (!$exists || $profile['username'] !== $username || (int)$profile['trust_level'] !== $trust) {
                $profile['username'] = $username;
                $profile['trust_level'] = $trust;
                $profile['updated_at'] = time();
                social_write_json_atomic($file, $profile);
                social_update_users_index_locked($profile);
            }
            return $profile;
        });
    }

    function social_profile_by_username(string $username, bool $create = false): ?array
    {
        social_ensure_storage();
        $key = social_fold(trim($username));
        if ($key === '') return null;
        $index = social_users_index();
        $id = isset($index['map'][$key]) ? (int)$index['map'][$key] : null;
        if ($id !== null) {
            return social_get_profile($id);
        }
        if (function_exists('dg_all')) {
            foreach (dg_all('users') as $user) {
                if (social_fold((string)($user['username'] ?? '')) === $key) {
                    $public = function_exists('dg_public_user') ? dg_public_user($user) : $user;
                    return $create ? social_ensure_profile($public) : social_get_profile((int)$public['id'], $public);
                }
            }
        }
        return null;
    }

    function social_profiles_by_ids(array $ids): array
    {
        $users = [];
        if (function_exists('dg_all')) {
            foreach (dg_all('users') as $user) {
                $users[(int)($user['id'] ?? 0)] = function_exists('dg_public_user') ? dg_public_user($user) : $user;
            }
        }
        $profiles = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $profile = social_get_profile($id, $users[$id] ?? null);
            if ($profile) $profiles[$id] = $profile;
        }
        return $profiles;
    }

    function social_profile_badges(array $profile, ?array $user = null): array
    {
        $badges = [];
        if (!empty($profile['is_official'])) $badges[] = 'Resmî Hesap';
        if (!empty($user['is_admin'])) $badges[] = 'Yönetici';
        if ((int)($profile['user_id'] ?? 0) > 0 && (int)$profile['user_id'] <= 100) $badges[] = 'Erken Üye';
        if ((int)($profile['trust_level'] ?? 0) >= 1) $badges[] = 'Güvenilir Üye';
        if ((int)($profile['approved_posts'] ?? 0) >= 5) $badges[] = 'İçerik Üreticisi';
        if ((int)($profile['approved_posts'] ?? 0) >= 25) $badges[] = 'Forum Yazarı';
        if ((int)($profile['approved_comments'] ?? 0) >= 25) $badges[] = 'Yardımsever';
        if ((int)($profile['approved_comments'] ?? 0) >= 100) $badges[] = 'Topluluk Sesi';
        if ((int)($profile['follower_count'] ?? 0) >= 25) $badges[] = 'Tanınan Oyuncu';
        return array_values(array_unique($badges));
    }

    function social_is_trusted(array $profile, ?array $user = null): bool
    {
        return !empty($user['is_admin'])
            || (int)($profile['trust_level'] ?? 0) >= 1
            || (int)($profile['approved_posts'] ?? 0) >= 3
            || (int)($profile['approved_comments'] ?? 0) >= 5;
    }

    function social_user_is_muted(?array $profile, ?array $user = null): bool
    {
        if (!$profile || !empty($user['is_admin'])) return false;
        return (int)($profile['muted_until'] ?? 0) > time();
    }

    function social_muted_error(?array $profile, ?array $user = null): string
    {
        if (!social_user_is_muted($profile, $user)) return '';
        $reason = trim((string)($profile['mute_reason'] ?? ''));
        return 'Hesabın ' . social_format_date((int)$profile['muted_until']) . ' tarihine kadar DG Social’da yeni içerik paylaşımına kapalı.'
            . ($reason !== '' ? ' Sebep: ' . $reason : '');
    }

    function social_maybe_promote_locked(array $profile): array
    {
        if ((int)$profile['trust_level'] < 1 && ((int)$profile['approved_posts'] >= 3 || (int)$profile['approved_comments'] >= 5)) {
            $profile['trust_level'] = 1;
        }
        $profile['updated_at'] = time();
        social_write_json_atomic(social_profile_file((int)$profile['user_id']), $profile);
        social_update_users_index_locked($profile);
        return $profile;
    }

    function social_update_profile(array $user, array $input, array $files = []): array
    {
        social_ensure_storage();
        return social_with_lock(static function () use ($user, $input, $files): array {
            $profile = social_get_profile((int)$user['id'], $user) ?: social_default_profile($user);
            $errors = [];
            $display = social_clean_text((string)($input['display_name'] ?? ''), 50);
            if (social_text_len($display) < 2) $errors[] = 'Görünen ad en az 2 karakter olmalı.';
            if (empty($user['is_admin']) && social_slugify($display) === 'devicegame') $errors[] = 'Bu görünen ad DeviceGame resmî hesabı için ayrılmıştır.';
            $signature = social_clean_text((string)($input['signature'] ?? $profile['signature'] ?? ''), 240);
            if (empty($user['is_admin']) && !social_is_trusted($profile, $user) && social_url_count($signature) > 0) {
                $errors[] = 'Güvenilir üye olana kadar imzanda bağlantı kullanamazsın.';
            }
            if ($errors) return ['errors' => $errors, 'profile' => $profile];

            $oldAvatar = (string)($profile['avatar'] ?? '');
            $avatar = $oldAvatar;
            if (!empty($input['remove_avatar'])) {
                if ($avatar !== '') social_delete_upload($avatar);
                $avatar = '';
            }
            if (!empty($files['avatar']) && is_array($files['avatar']) && (string)($files['avatar']['name'] ?? '') !== '') {
                $avatarResult = social_handle_avatar_image((array)$files['avatar'], (int)$user['id']);
                if (($avatarResult['error'] ?? '') !== '') return ['errors' => [(string)$avatarResult['error']], 'profile' => $profile];
                if ((string)($avatarResult['path'] ?? '') !== '') {
                    if ($avatar !== '' && $avatar !== (string)$avatarResult['path']) social_delete_upload($avatar);
                    $avatar = (string)$avatarResult['path'];
                }
            }

            $profile['display_name'] = $display;
            $profile['bio'] = social_clean_text((string)($input['bio'] ?? ''), 500);
            $profile['signature'] = $signature;
            $profile['avatar'] = $avatar;
            $profile['platforms'] = social_normalize_profile(['platforms' => (string)($input['platforms'] ?? '')])['platforms'];
            $profile['favorite_games'] = social_normalize_profile(['favorite_games' => (string)($input['favorite_games'] ?? '')])['favorite_games'];
            $profile['show_balance'] = !empty($input['show_balance']);
            if (!empty($input['settings_present'])) {
                $profile['show_online'] = !empty($input['show_online']);
                $dmPolicy = (string)($input['dm_policy'] ?? 'everyone');
                $profile['dm_policy'] = in_array($dmPolicy, ['everyone', 'following', 'nobody'], true) ? $dmPolicy : 'everyone';
                $rawPrefs = is_array($input['notification_prefs'] ?? null) ? (array)$input['notification_prefs'] : [];
                $prefs = [];
                foreach (array_keys(social_default_notification_prefs()) as $key) {
                    $prefs[$key] = !empty($rawPrefs[$key]) || !empty($input['notify_' . $key]);
                }
                $profile['notification_prefs'] = $prefs;
            }
            $profile['updated_at'] = time();
            $profile = social_normalize_profile($profile, $user);
            social_write_json_atomic(social_profile_file((int)$user['id']), $profile);
            social_update_users_index_locked($profile);
            social_sync_author_summaries_locked($profile);
            return ['errors' => [], 'profile' => $profile];
        });
    }

    function social_set_trust(int $userId, int $level, array $admin): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $user = function_exists('dg_find_user_by_id') ? dg_find_user_by_id($userId) : null;
        if (!$user) return ['errors' => ['Kullanıcı bulunamadı.']];
        $profile = social_ensure_profile($user);
        return social_with_lock(static function () use ($admin, $profile, $level): array {
            $profile['trust_level'] = max(0, min(2, $level));
            $profile['updated_at'] = time();
            $profile = social_normalize_profile($profile);
            social_write_json_atomic(social_profile_file((int)$profile['user_id']), $profile);
            social_update_users_index_locked($profile);
            social_log_moderation_locked($admin, 'set_trust', 'user', (int)$profile['user_id'], 0, 'Güven seviyesi: ' . (int)$profile['trust_level']);
            return ['errors' => [], 'profile' => $profile];
        });
    }

    function social_normalize_presence(array $presence): array
    {
        return [
            'user_id' => max(0, (int)($presence['user_id'] ?? 0)),
            'username' => social_clean_text((string)($presence['username'] ?? ''), 40),
            'display_name' => social_clean_text((string)($presence['display_name'] ?? ''), 60),
            'show_online' => !isset($presence['show_online']) || !empty($presence['show_online']),
            'last_seen_at' => max(0, (int)($presence['last_seen_at'] ?? 0)),
            'chat_channel' => social_slugify((string)($presence['chat_channel'] ?? '')),
            'typing_chat' => social_slugify((string)($presence['typing_chat'] ?? '')),
            'typing_chat_at' => max(0, (int)($presence['typing_chat_at'] ?? 0)),
            'typing_dm' => max(0, (int)($presence['typing_dm'] ?? 0)),
            'typing_dm_at' => max(0, (int)($presence['typing_dm_at'] ?? 0)),
        ];
    }

    function social_presence(int $userId): ?array
    {
        if ($userId <= 0) return null;
        social_ensure_storage();
        $file = social_presence_file($userId);
        if (!is_file($file)) return null;
        try {
            return social_normalize_presence(social_read_json($file, []));
        } catch (Throwable) {
            return null;
        }
    }

    function social_touch_presence(array $user, array $context = [], bool $force = false): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return;
        social_ensure_storage();
        $now = time();
        $existing = social_presence($userId) ?? [];
        $hasTyping = array_key_exists('typing_chat', $context) || array_key_exists('typing_dm', $context);
        if (!$force && !$hasTyping && (int)($existing['last_seen_at'] ?? 0) > $now - 45) return;
        $profile = social_get_profile($userId, $user);
        $presence = social_normalize_presence(array_merge($existing, [
            'user_id' => $userId,
            'username' => (string)($profile['username'] ?? $user['username'] ?? ''),
            'display_name' => (string)($profile['display_name'] ?? $user['username'] ?? ''),
            'show_online' => !isset($profile['show_online']) || !empty($profile['show_online']),
            'last_seen_at' => $now,
        ]));
        if (array_key_exists('chat_channel', $context)) {
            $presence['chat_channel'] = social_slugify((string)$context['chat_channel']);
        }
        if (array_key_exists('typing_chat', $context)) {
            $presence['typing_chat'] = social_slugify((string)$context['typing_chat']);
            $presence['typing_chat_at'] = $presence['typing_chat'] !== '' ? $now : 0;
        }
        if (array_key_exists('typing_dm', $context)) {
            $presence['typing_dm'] = max(0, (int)$context['typing_dm']);
            $presence['typing_dm_at'] = $presence['typing_dm'] > 0 ? $now : 0;
        }
        try {
            social_write_json_atomic(social_presence_file($userId), $presence, false);
        } catch (Throwable $exception) {
            error_log('[social presence] ' . $exception->getMessage());
        }
    }

    function social_is_online(int $userId, int $withinSeconds = 300): bool
    {
        $presence = social_presence($userId);
        return $presence && (int)$presence['last_seen_at'] >= time() - max(30, $withinSeconds);
    }

    function social_online_users(?array $viewer = null, int $withinSeconds = 300, int $limit = 50): array
    {
        social_ensure_storage();
        $blocked = social_blocked_ids($viewer);
        $viewerId = $viewer ? (int)$viewer['id'] : 0;
        $isAdmin = !empty($viewer['is_admin']);
        $items = [];
        foreach (glob(social_dir('presence') . '/*.json') ?: [] as $file) {
            try {
                $presence = social_normalize_presence(social_read_json($file, []));
            } catch (Throwable) {
                continue;
            }
            $id = (int)$presence['user_id'];
            if ($id <= 0 || (int)$presence['last_seen_at'] < time() - max(30, $withinSeconds)) continue;
            if (!$isAdmin && $id !== $viewerId && empty($presence['show_online'])) continue;
            if (!$isAdmin && in_array($id, $blocked, true)) continue;
            $profile = social_get_profile($id);
            if (!$profile) continue;
            $profile['last_seen_at'] = (int)$presence['last_seen_at'];
            $profile['is_online'] = true;
            $profile['chat_channel'] = (string)$presence['chat_channel'];
            $items[] = $profile;
        }
        usort($items, static fn(array $a, array $b): int => [(int)$b['last_seen_at'], social_fold((string)$a['display_name'])] <=> [(int)$a['last_seen_at'], social_fold((string)$b['display_name'])]);
        return array_slice($items, 0, max(1, $limit));
    }

    function social_last_seen_label(array $profile, ?array $viewer = null): string
    {
        $userId = (int)($profile['user_id'] ?? 0);
        if ($userId <= 0) return 'Resmî hesap';
        $presence = social_presence($userId);
        $isSelfOrAdmin = $viewer && ((int)$viewer['id'] === $userId || !empty($viewer['is_admin']));
        if (!$presence || (empty($profile['show_online']) && !$isSelfOrAdmin)) return 'Son görülme gizli';
        if ((int)$presence['last_seen_at'] >= time() - 300) return 'Şu an çevrimiçi';
        if ((int)$presence['last_seen_at'] > 0) return 'Son görülme: ' . social_time_ago((int)$presence['last_seen_at']);
        return 'Son görülme bilinmiyor';
    }

    function social_announcement(): array
    {
        social_ensure_storage();
        return (array)(social_meta_locked()['announcement'] ?? ['text' => '', 'url' => '', 'active' => false, 'updated_at' => 0, 'updated_by' => 0]);
    }

    function social_set_announcement(array $admin, array $input): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $text = social_clean_text((string)($input['text'] ?? ''), 240);
        $url = trim((string)($input['url'] ?? ''));
        if (!empty($input['active']) && social_text_len($text) < 5) {
            return ['errors' => ['Aktif duyuru metni en az 5 karakter olmalı.']];
        }
        if ($url !== '' && !preg_match('~^(?:https?://|/|\.\./|[a-z0-9_./-]+\.php(?:\?[^#\s]*)?(?:#[a-z0-9_-]+)?$)~i', $url)) {
            return ['errors' => ['Duyuru bağlantısı geçerli bir site içi adres veya HTTPS bağlantısı olmalı.']];
        }
        return social_with_lock(static function () use ($admin, $input, $text, $url): array {
            $meta = social_meta_locked();
            $meta['announcement'] = [
                'text' => $text,
                'url' => $url,
                'active' => !empty($input['active']),
                'updated_at' => time(),
                'updated_by' => (int)$admin['id'],
            ];
            $meta['updated_at'] = time();
            social_write_json_atomic(social_meta_file(), $meta);
            social_log_moderation_locked($admin, 'announcement', 'site', 0, 0, !empty($input['active']) ? 'Topluluk duyurusu yayınlandı.' : 'Topluluk duyurusu güncellendi/kapatıldı.');
            return ['errors' => [], 'announcement' => $meta['announcement']];
        });
    }

    function social_normalize_moderation_log(array $row): array
    {
        $created = (int)($row['created_at'] ?? time());
        return [
            'id' => max(0, (int)($row['id'] ?? 0)),
            'actor_id' => max(0, (int)($row['actor_id'] ?? 0)),
            'actor_username' => social_clean_text((string)($row['actor_username'] ?? ''), 40),
            'action' => social_slugify((string)($row['action'] ?? 'action')) ?: 'action',
            'target_type' => social_slugify((string)($row['target_type'] ?? 'content')) ?: 'content',
            'target_id' => max(0, (int)($row['target_id'] ?? 0)),
            'post_id' => max(0, (int)($row['post_id'] ?? 0)),
            'details' => social_clean_text((string)($row['details'] ?? ''), 500),
            'created_at' => $created,
        ];
    }

    function social_log_moderation_locked(array $actor, string $action, string $targetType, int $targetId, int $postId = 0, string $details = ''): void
    {
        if (empty($actor['is_admin'])) return;
        try {
            $row = social_normalize_moderation_log([
                'id' => social_next_id_locked('modlogs'),
                'actor_id' => (int)($actor['id'] ?? 0),
                'actor_username' => (string)($actor['username'] ?? ''),
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'post_id' => $postId,
                'details' => $details,
                'created_at' => time(),
            ]);
            social_append_jsonl(social_moderation_log_file(time()), $row);
        } catch (Throwable $exception) {
            error_log('[social moderation log] ' . $exception->getMessage());
        }
    }

    function social_moderation_logs(int $limit = 100): array
    {
        social_ensure_storage();
        $files = glob(social_dir('logs') . '/*.jsonl') ?: [];
        rsort($files);
        $items = [];
        foreach ($files as $file) {
            $rows = social_read_jsonl($file);
            for ($i = count($rows) - 1; $i >= 0; $i--) {
                $items[] = social_normalize_moderation_log($rows[$i]);
                if (count($items) >= max(1, $limit)) return $items;
            }
        }
        return $items;
    }

    function social_warn_user(array $admin, int $targetId, string $reason, int $muteDays = 0): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        if ($targetId <= 0 || $targetId === (int)$admin['id']) return ['errors' => ['Geçerli bir kullanıcı seç.']];
        $targetUser = function_exists('dg_find_user_by_id') ? dg_find_user_by_id($targetId) : null;
        if (!$targetUser) return ['errors' => ['Kullanıcı bulunamadı.']];
        if (!empty($targetUser['is_admin'])) return ['errors' => ['Bir yönetici DG Social üzerinden uyarılamaz.']];
        $reason = social_clean_text($reason, 240);
        if (social_text_len($reason) < 3) return ['errors' => ['Uyarı sebebini kısaca yaz.']];
        $muteDays = max(0, min(365, $muteDays));
        social_ensure_profile($targetUser);
        return social_with_lock(static function () use ($admin, $targetUser, $targetId, $reason, $muteDays): array {
            $profile = social_get_profile($targetId, $targetUser) ?: social_default_profile($targetUser);
            $profile['warning_count'] = (int)($profile['warning_count'] ?? 0) + 1;
            $profile['last_warning_at'] = time();
            if ($muteDays > 0) {
                $profile['muted_until'] = max((int)($profile['muted_until'] ?? 0), time() + ($muteDays * 86400));
                $profile['mute_reason'] = $reason;
            }
            $profile['updated_at'] = time();
            $profile = social_normalize_profile($profile, $targetUser);
            social_write_json_atomic(social_profile_file($targetId), $profile);
            social_update_users_index_locked($profile);
            $message = 'DG Social moderasyon uyarısı: ' . $reason;
            if ($muteDays > 0) $message .= ' Hesabın ' . social_format_date((int)$profile['muted_until']) . ' tarihine kadar yeni içerik paylaşımına kapalı.';
            social_notify_locked($targetId, (int)$admin['id'], 'warning', 0, 0, $message, 'kurallar.php#moderasyon', true);
            social_log_moderation_locked($admin, $muteDays > 0 ? 'warn_mute' : 'warn', 'user', $targetId, 0, $reason . ($muteDays > 0 ? ' (' . $muteDays . ' gün susturma)' : ''));
            return ['errors' => [], 'profile' => $profile];
        });
    }

    function social_clear_mute(array $admin, int $targetId): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $targetUser = $targetId > 0 && function_exists('dg_find_user_by_id') ? dg_find_user_by_id($targetId) : null;
        if (!$targetUser) return ['errors' => ['Kullanıcı bulunamadı.']];
        social_ensure_profile($targetUser);
        return social_with_lock(static function () use ($admin, $targetUser, $targetId): array {
            $profile = social_get_profile($targetId, $targetUser) ?: social_default_profile($targetUser);
            $profile['muted_until'] = 0;
            $profile['mute_reason'] = '';
            $profile['updated_at'] = time();
            $profile = social_normalize_profile($profile, $targetUser);
            social_write_json_atomic(social_profile_file($targetId), $profile);
            social_update_users_index_locked($profile);
            social_notify_locked($targetId, (int)$admin['id'], 'warning', 0, 0, 'DG Social susturman kaldırıldı. Topluluk kurallarına uyarak paylaşmaya devam edebilirsin.', 'kurallar.php#moderasyon', true);
            social_log_moderation_locked($admin, 'unmute', 'user', $targetId, 0, 'Kullanıcının susturması kaldırıldı.');
            return ['errors' => [], 'profile' => $profile];
        });
    }

    function social_watches(int $userId): array
    {
        social_ensure_storage();
        $data = social_read_json(social_watches_file($userId), []);
        $posts = array_values(array_unique(array_filter(array_map('intval', (array)($data['posts'] ?? [])), static fn(int $id): bool => $id > 0)));
        $cleanTopics = static function ($value): array {
            $out = [];
            foreach ((array)$value as $slug) {
                $slug = social_slugify((string)$slug);
                if ($slug !== '' && !in_array($slug, $out, true)) $out[] = $slug;
            }
            return $out;
        };
        $cleanTags = static function ($value): array {
            $out = [];
            foreach ((array)$value as $tag) {
                $tag = social_slugify((string)$tag);
                if ($tag !== '' && !in_array($tag, $out, true)) $out[] = $tag;
            }
            return $out;
        };
        return [
            'posts' => array_slice($posts, 0, 1000),
            'topics' => $cleanTopics($data['topics'] ?? []),
            'muted_topics' => $cleanTopics($data['muted_topics'] ?? []),
            'muted_tags' => $cleanTags($data['muted_tags'] ?? []),
            'updated_at' => (int)($data['updated_at'] ?? 0),
        ];
    }

    function social_write_watches_locked(int $userId, array $watches): void
    {
        $watches['updated_at'] = time();
        social_write_json_atomic(social_watches_file($userId), $watches, false);
    }

    function social_watch_post_locked(int $userId, int $postId, bool $active): bool
    {
        if ($userId <= 0 || $postId <= 0) return false;
        $watches = social_watches($userId);
        $wasActive = in_array($postId, $watches['posts'], true);
        if ($active && !$wasActive) array_unshift($watches['posts'], $postId);
        if (!$active && $wasActive) $watches['posts'] = array_values(array_filter($watches['posts'], static fn(int $id): bool => $id !== $postId));
        $watches['posts'] = array_slice(array_values(array_unique($watches['posts'])), 0, 1000);
        social_write_watches_locked($userId, $watches);

        $reactions = social_reactions($postId);
        $watchers = (array)($reactions['watchers'] ?? []);
        $wasWatcher = in_array($userId, $watchers, true);
        if ($active && !$wasWatcher) $watchers[] = $userId;
        if (!$active && $wasWatcher) $watchers = array_values(array_filter($watchers, static fn(int $id): bool => $id !== $userId));
        $reactions['watchers'] = array_values(array_unique(array_map('intval', $watchers)));
        social_write_json_atomic(social_reactions_file($postId), $reactions, false);
        $post = social_get_post($postId, true);
        if ($post && (int)($post['watch_count'] ?? 0) !== count($reactions['watchers'])) {
            $post['watch_count'] = count($reactions['watchers']);
            $post = social_normalize_post($post);
            social_write_json_atomic(social_post_file($postId), $post, false);
            social_index_upsert_locked(social_post_summary($post));
        }
        return $wasActive !== $active;
    }

    function social_toggle_watch_post(array $user, int $postId): array
    {
        $post = social_get_post($postId);
        if (!$post || !social_post_visible($post, $user, social_blocked_ids($user))) {
            return ['errors' => ['Takip edilecek gönderi bulunamadı.'], 'active' => false, 'count' => 0];
        }
        social_ensure_profile($user);
        return social_with_lock(static function () use ($user, $postId): array {
            $active = !in_array($postId, social_watches((int)$user['id'])['posts'], true);
            social_watch_post_locked((int)$user['id'], $postId, $active);
            return ['errors' => [], 'active' => $active, 'count' => count(social_reactions($postId)['watchers'] ?? [])];
        });
    }

    function social_toggle_watch_topic(array $user, string $slug, string $mode = 'watch'): array
    {
        $slug = social_slugify($slug);
        $topic = social_topic($slug, false);
        if (!$topic) return ['errors' => ['Konu bulunamadı.'], 'active' => false, 'muted' => false];
        social_ensure_profile($user);
        return social_with_lock(static function () use ($user, $slug, $mode): array {
            $userId = (int)$user['id'];
            $watches = social_watches($userId);
            if ($mode === 'mute') {
                $active = !in_array($slug, $watches['muted_topics'], true);
                if ($active) {
                    $watches['muted_topics'][] = $slug;
                    $watches['topics'] = array_values(array_filter($watches['topics'], static fn(string $item): bool => $item !== $slug));
                } else {
                    $watches['muted_topics'] = array_values(array_filter($watches['muted_topics'], static fn(string $item): bool => $item !== $slug));
                }
                social_write_watches_locked($userId, $watches);
                // Sessize alınan konu için ters bildirim indeksinden de çıkar.
                $file = social_index_file('watch-topic-' . $slug);
                $ids = array_values(array_filter(array_map('intval', (array)(social_read_json($file, [])['user_ids'] ?? [])), static fn(int $id): bool => $id !== $userId));
                social_write_json_atomic($file, ['user_ids' => $ids, 'updated_at' => time()], false);
                return ['errors' => [], 'active' => false, 'muted' => $active];
            }
            $active = !in_array($slug, $watches['topics'], true);
            if ($active) {
                $watches['topics'][] = $slug;
                $watches['muted_topics'] = array_values(array_filter($watches['muted_topics'], static fn(string $item): bool => $item !== $slug));
            } else {
                $watches['topics'] = array_values(array_filter($watches['topics'], static fn(string $item): bool => $item !== $slug));
            }
            social_write_watches_locked($userId, $watches);
            $file = social_index_file('watch-topic-' . $slug);
            $ids = array_values(array_unique(array_map('intval', (array)(social_read_json($file, [])['user_ids'] ?? []))));
            if ($active && !in_array($userId, $ids, true)) $ids[] = $userId;
            if (!$active) $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $userId));
            social_write_json_atomic($file, ['user_ids' => $ids, 'updated_at' => time()], false);
            return ['errors' => [], 'active' => $active, 'muted' => in_array($slug, $watches['muted_topics'], true)];
        });
    }

    function social_toggle_muted_tag(array $user, string $tag): array
    {
        $tag = social_slugify(ltrim(trim($tag), '#'));
        if ($tag === '') return ['errors' => ['Sessize alınacak etiketi yaz.'], 'active' => false, 'muted' => false, 'tag' => ''];
        social_ensure_profile($user);
        return social_with_lock(static function () use ($user, $tag): array {
            $userId = (int)$user['id'];
            $watches = social_watches($userId);
            $muted = !in_array($tag, $watches['muted_tags'], true);
            if ($muted) {
                $watches['muted_tags'][] = $tag;
            } else {
                $watches['muted_tags'] = array_values(array_filter($watches['muted_tags'], static fn(string $item): bool => $item !== $tag));
            }
            $watches['muted_tags'] = array_slice(array_values(array_unique($watches['muted_tags'])), 0, 100);
            social_write_watches_locked($userId, $watches);
            return ['errors' => [], 'active' => $muted, 'muted' => $muted, 'tag' => $tag, 'count' => count($watches['muted_tags'])];
        });
    }

    function social_notify_topic_watchers_locked(array $post, int $actorId): void
    {
        $data = social_read_json(social_index_file('watch-topic-' . (string)$post['topic_slug']), []);
        $ids = array_values(array_unique(array_map('intval', (array)($data['user_ids'] ?? []))));
        $actor = social_get_profile($actorId);
        $name = (string)($actor['display_name'] ?? 'Bir oyuncu');
        foreach ($ids as $targetId) {
            if ($targetId <= 0 || $targetId === $actorId) continue;
            $follows = social_follows($targetId);
            if (in_array($actorId, $follows['blocked'], true)) continue;
            social_notify_locked($targetId, $actorId, 'watch_topic', (int)$post['id'], 0, $name . ' takip ettiğin konuda yeni gönderi açtı: “' . (string)$post['title'] . '”', 'gonderi.php?id=' . (int)$post['id']);
        }
    }

    function social_notify_post_watchers_locked(array $post, array $comment, array $excludeIds = []): void
    {
        $actorId = (int)$comment['user_id'];
        $exclude = array_fill_keys(array_map('intval', $excludeIds), true);
        $exclude[$actorId] = true;
        $actorName = (string)($comment['author_display'] ?: $comment['author_username']);
        $url = 'gonderi.php?id=' . (int)$post['id'] . '&yorum=' . (int)$comment['id'] . '#yorum-' . (int)$comment['id'];
        foreach ((array)(social_reactions((int)$post['id'])['watchers'] ?? []) as $targetId) {
            $targetId = (int)$targetId;
            if ($targetId <= 0 || isset($exclude[$targetId])) continue;
            social_notify_locked($targetId, $actorId, 'watch_comment', (int)$post['id'], (int)$comment['id'], $actorName . ' takip ettiğin konuya yorum yaptı: “' . (string)$post['title'] . '”', $url);
        }
    }

    function social_read_map(int $userId): array
    {
        social_ensure_storage();
        $data = social_read_json(social_reads_file($userId), []);
        $out = [];
        foreach ((array)($data['posts'] ?? []) as $postId => $row) {
            $postId = (int)$postId;
            if ($postId <= 0 || !is_array($row)) continue;
            $out[$postId] = [
                'at' => max(0, (int)($row['at'] ?? 0)),
                'activity' => max(0, (int)($row['activity'] ?? 0)),
                'comment_id' => max(0, (int)($row['comment_id'] ?? 0)),
            ];
        }
        return $out;
    }

    function social_post_is_unread(array $post, ?array $viewer, ?array $readMap = null): bool
    {
        if (!$viewer || (int)($post['user_id'] ?? 0) === (int)$viewer['id']) return false;
        $readMap = $readMap ?? social_read_map((int)$viewer['id']);
        $record = $readMap[(int)($post['id'] ?? 0)] ?? null;
        $activity = max((int)($post['last_activity_at'] ?? 0), (int)($post['created_at'] ?? 0));
        if ($record) return $activity > (int)$record['activity'];
        $profile = social_get_profile((int)$viewer['id'], $viewer);
        $joined = (int)($profile['joined_at'] ?? 0);
        return $activity >= max($joined, time() - (30 * 86400));
    }

    function social_mark_post_read(array $user, array $post): void
    {
        $userId = (int)($user['id'] ?? 0);
        $postId = (int)($post['id'] ?? 0);
        if ($userId <= 0 || $postId <= 0) return;
        social_with_lock(static function () use ($userId, $postId, $post): void {
            $map = social_read_map($userId);
            $latestCommentId = 0;
            foreach (social_comment_map($postId) as $comment) {
                if (($comment['status'] ?? '') === 'published') $latestCommentId = max($latestCommentId, (int)$comment['id']);
            }
            $map[$postId] = [
                'at' => time(),
                'activity' => max((int)($post['last_activity_at'] ?? 0), (int)($post['created_at'] ?? 0)),
                'comment_id' => $latestCommentId,
            ];
            uasort($map, static fn(array $a, array $b): int => (int)$b['at'] <=> (int)$a['at']);
            $map = array_slice($map, 0, 1000, true);
            social_write_json_atomic(social_reads_file($userId), ['posts' => $map, 'updated_at' => time()], false);
        });
    }

    function social_mark_all_posts_read(array $user): array
    {
        social_ensure_profile($user);
        return social_with_lock(static function () use ($user): array {
            $map = social_read_map((int)$user['id']);
            $now = time();
            foreach (social_read_json(social_index_file('latest'), []) as $item) {
                if (!is_array($item) || ($item['status'] ?? '') !== 'published') continue;
                $map[(int)$item['id']] = [
                    'at' => $now,
                    'activity' => max((int)($item['last_activity_at'] ?? 0), (int)($item['created_at'] ?? 0)),
                    'comment_id' => (int)($map[(int)$item['id']]['comment_id'] ?? 0),
                ];
            }
            uasort($map, static fn(array $a, array $b): int => (int)$b['at'] <=> (int)$a['at']);
            $map = array_slice($map, 0, 1000, true);
            social_write_json_atomic(social_reads_file((int)$user['id']), ['posts' => $map, 'updated_at' => $now], false);
            return ['errors' => [], 'count' => count($map)];
        });
    }

    function social_record_post_view(int $postId, ?array $viewer = null): ?array
    {
        $post = social_get_post($postId);
        if (!$post || ($post['status'] ?? '') !== 'published') return $post;
        if ($viewer && ((int)$viewer['id'] === (int)$post['user_id'] || !empty($viewer['is_admin']))) return $post;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $key = 'dg_social_view_' . $postId;
            if ((int)($_SESSION[$key] ?? 0) > time() - 3600) return $post;
            $_SESSION[$key] = time();
        }
        return social_with_lock(static function () use ($postId): ?array {
            $fresh = social_get_post($postId, true);
            if (!$fresh || ($fresh['status'] ?? '') !== 'published') return $fresh;
            $fresh['view_count'] = (int)($fresh['view_count'] ?? 0) + 1;
            $fresh = social_normalize_post($fresh);
            social_write_json_atomic(social_post_file($postId), $fresh, false);
            social_index_upsert_locked(social_post_summary($fresh));
            return $fresh;
        });
    }

    function social_move_post(array $admin, int $postId, string $topicSlug): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $topic = social_topic(social_slugify($topicSlug), false);
        if (!$topic) return ['errors' => ['Taşınacak konu bulunamadı.']];
        $post = social_get_post($postId, true);
        if (!$post || ($post['status'] ?? '') === 'deleted') return ['errors' => ['Gönderi bulunamadı.']];
        return social_with_lock(static function () use ($admin, $post, $topic): array {
            $oldTopic = (string)$post['topic_slug'];
            $post['topic_slug'] = (string)$topic['slug'];
            if ((string)$topic['slug'] !== 'etkinlikler') {
                $post['meta']['event_at'] = 0;
                $post['meta']['event_place'] = '';
                $post['meta']['event_capacity'] = 0;
                $post['rsvp_count'] = 0;
                if (($post['prefix'] ?? '') === 'etkinlik') $post['prefix'] = social_default_post_prefix((string)$topic['slug']);
            }
            $post['updated_at'] = time();
            $post = social_normalize_post($post);
            social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            social_index_upsert_locked(social_post_summary($post));
            social_log_moderation_locked($admin, 'move_post', 'post', (int)$post['id'], (int)$post['id'], $oldTopic . ' → ' . (string)$topic['slug']);
            return ['errors' => [], 'topic_slug' => (string)$topic['slug']];
        });
    }

    function social_user_forum_stats(int $userId): array
    {
        social_ensure_storage();
        $gg = $views = $solutions = 0;
        foreach (social_read_json(social_index_file('user-' . $userId), []) as $item) {
            if (!is_array($item) || ($item['status'] ?? '') !== 'published') continue;
            $gg += (int)($item['gg_count'] ?? 0);
            $views += (int)($item['view_count'] ?? 0);
        }
        foreach (social_read_json(social_index_file('user-comments-' . $userId), []) as $item) {
            if (is_array($item) && !empty($item['is_solution']) && ($item['status'] ?? '') === 'published') $solutions++;
        }
        $awards = social_user_award_stats($userId);
        return [
            'gg_received' => $gg,
            'views' => $views,
            'solutions' => $solutions,
            'award_count' => (int)$awards['count'],
            'award_total' => (int)$awards['total'],
        ];
    }

    function social_topic_board_stats(?array $viewer = null): array
    {
        social_ensure_storage();
        $blocked = social_blocked_ids($viewer);
        $readMap = $viewer ? social_read_map((int)$viewer['id']) : [];
        $watches = $viewer ? social_watches((int)$viewer['id']) : ['topics' => [], 'muted_topics' => []];
        $out = [];
        foreach (social_topics(false) as $topic) {
            $slug = (string)$topic['slug'];
            $posts = 0;
            $comments = 0;
            $views = 0;
            $unread = 0;
            $latest = null;
            foreach (social_read_json(social_index_file('topic-' . $slug), []) as $item) {
                if (!is_array($item) || ($item['status'] ?? '') !== 'published') continue;
                $item = social_post_summary($item);
                if ($viewer && empty($viewer['is_admin']) && in_array((int)$item['user_id'], $blocked, true)) continue;
                $posts++;
                $comments += (int)$item['comment_count'];
                $views += (int)$item['view_count'];
                if ($viewer && social_post_is_unread($item, $viewer, $readMap)) $unread++;
                if (!$latest || (int)$item['last_activity_at'] > (int)$latest['last_activity_at']) $latest = $item;
            }
            $topic['post_count'] = $posts;
            $topic['comment_count'] = $comments;
            $topic['view_count'] = $views;
            $topic['unread_count'] = $unread;
            $topic['latest_post'] = $latest;
            $topic['is_watched'] = in_array($slug, $watches['topics'] ?? [], true);
            $topic['is_muted'] = in_array($slug, $watches['muted_topics'] ?? [], true);
            $out[] = $topic;
        }
        return $out;
    }

    function social_member_directory(array $options = [], ?array $viewer = null): array
    {
        social_ensure_storage();
        $query = social_fold(trim((string)($options['q'] ?? '')));
        $sort = (string)($options['sort'] ?? 'active');
        $onlineOnly = !empty($options['online']);
        $limit = max(1, min(100, (int)($options['limit'] ?? 60)));
        $blocked = social_blocked_ids($viewer);
        $ids = [0 => true];
        $coreUsers = [];
        if (function_exists('dg_all')) {
            foreach (dg_all('users') as $coreUser) {
                if (!is_array($coreUser) || (int)($coreUser['is_banned'] ?? 0) === 1) continue;
                $id = (int)($coreUser['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                    $coreUsers[$id] = function_exists('dg_public_user') ? dg_public_user($coreUser) : $coreUser;
                }
            }
        }
        foreach ((array)(social_users_index()['summaries'] ?? []) as $summary) {
            if (is_array($summary) && isset($summary['user_id'])) $ids[(int)$summary['user_id']] = true;
        }
        $onlineIds = [];
        if ($onlineOnly) {
            foreach (social_online_users($viewer, 300, 500) as $onlineProfile) $onlineIds[(int)$onlineProfile['user_id']] = true;
        }
        $items = [];
        foreach (array_keys($ids) as $id) {
            $id = (int)$id;
            if (!$viewer || empty($viewer['is_admin'])) {
                if (in_array($id, $blocked, true)) continue;
            }
            if ($onlineOnly && !isset($onlineIds[$id])) continue;
            $profile = social_get_profile($id, $coreUsers[$id] ?? null);
            if (!$profile) continue;
            $haystack = social_fold(implode(' ', [
                (string)$profile['username'],
                (string)$profile['display_name'],
                (string)$profile['bio'],
                implode(' ', (array)$profile['platforms']),
                implode(' ', (array)$profile['favorite_games']),
            ]));
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $stats = social_user_forum_stats($id);
            $presence = social_presence($id);
            $profile['forum_stats'] = $stats;
            $profile['last_seen_at'] = (int)($presence['last_seen_at'] ?? 0);
            $profile['is_online'] = $presence && (int)$presence['last_seen_at'] >= time() - 300 && (!empty($profile['show_online']) || !empty($viewer['is_admin']) || ($viewer && (int)$viewer['id'] === $id));
            $profile['_score_active'] = (int)$profile['post_count'] * 3 + (int)$profile['comment_count'] + (int)$stats['gg_received'];
            $profile['_score_helpful'] = (int)$stats['solutions'] * 12 + (int)$stats['gg_received'] * 2 + (int)$profile['comment_count'] + (int)$stats['award_count'] * 10;
            $items[] = $profile;
        }
        usort($items, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'helpful' => [(int)$b['_score_helpful'], (int)$b['comment_count'], (int)$b['user_id']] <=> [(int)$a['_score_helpful'], (int)$a['comment_count'], (int)$a['user_id']],
                'followers' => [(int)$b['follower_count'], (int)$b['_score_active']] <=> [(int)$a['follower_count'], (int)$a['_score_active']],
                'newest' => [(int)$b['joined_at'], (int)$b['user_id']] <=> [(int)$a['joined_at'], (int)$a['user_id']],
                'awards' => [(int)($b['forum_stats']['award_count'] ?? 0), (int)($b['forum_stats']['award_total'] ?? 0)] <=> [(int)($a['forum_stats']['award_count'] ?? 0), (int)($a['forum_stats']['award_total'] ?? 0)],
                'online' => [(int)!empty($b['is_online']), (int)$b['last_seen_at'], (int)$b['_score_active']] <=> [(int)!empty($a['is_online']), (int)$a['last_seen_at'], (int)$a['_score_active']],
                default => [(int)$b['_score_active'], (int)$b['post_count'], (int)$b['user_id']] <=> [(int)$a['_score_active'], (int)$a['post_count'], (int)$a['user_id']],
            };
        });
        $items = array_slice($items, 0, $limit);
        foreach ($items as $i => $item) {
            unset($items[$i]['_score_active'], $items[$i]['_score_helpful']);
        }
        return array_values($items);
    }

    function social_search_comments(string $query, ?array $viewer = null, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '' || str_starts_with($query, '#')) return [];
        social_ensure_storage();
        $fold = social_fold($query);
        $blocked = social_blocked_ids($viewer);
        $items = [];
        $scannedPosts = 0;
        foreach (social_read_json(social_index_file('latest'), []) as $summary) {
            if (!is_array($summary) || $scannedPosts >= 250) continue;
            $summary = social_post_summary($summary);
            if (!social_post_visible($summary, $viewer, $blocked)) continue;
            $scannedPosts++;
            foreach (social_comment_map((int)$summary['id']) as $comment) {
                if (!social_comment_visible($comment, $viewer, $blocked) || ($comment['status'] ?? '') !== 'published') continue;
                $haystack = social_fold((string)$comment['body'] . ' ' . (string)$comment['author_username'] . ' ' . (string)$comment['author_display']);
                if (!str_contains($haystack, $fold)) continue;
                $comment['post_title'] = (string)$summary['title'];
                $comment['excerpt'] = social_excerpt((string)$comment['body'], 220);
                $items[] = $comment;
            }
        }
        usort($items, static fn(array $a, array $b): int => [(int)$b['created_at'], (int)$b['id']] <=> [(int)$a['created_at'], (int)$a['id']]);
        return array_slice($items, 0, max(1, $limit));
    }

    function social_follows(int $userId): array
    {
        social_ensure_storage();
        $data = social_read_json(social_follows_file($userId), []);
        $clean = static function ($value): array {
            if (!is_array($value)) return [];
            return array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id >= 0)));
        };
        return [
            'following' => $clean($data['following'] ?? []),
            'followers' => $clean($data['followers'] ?? []),
            'blocked' => $clean($data['blocked'] ?? []),
            'blocked_by' => $clean($data['blocked_by'] ?? []),
            'updated_at' => (int)($data['updated_at'] ?? 0),
        ];
    }

    function social_write_follows_locked(int $userId, array $follows): void
    {
        $follows['updated_at'] = time();
        social_write_json_atomic(social_follows_file($userId), $follows, false);
        $profile = social_get_profile($userId);
        if ($profile) {
            $profile['following_count'] = count($follows['following']);
            $profile['follower_count'] = count($follows['followers']);
            $profile['updated_at'] = time();
            social_write_json_atomic(social_profile_file($userId), $profile);
            social_update_users_index_locked($profile);
        }
    }

    function social_blocked_ids(?array $user): array
    {
        if (!$user) return [];
        $follows = social_follows((int)$user['id']);
        // Engelleme çift yönlü görünürlük uygular: engellediklerim ve beni engelleyenler.
        return array_values(array_unique(array_map('intval', array_merge($follows['blocked'], $follows['blocked_by']))));
    }

    function social_toggle_follow(array $actor, int $targetId): array
    {
        social_ensure_storage();
        $actorId = (int)$actor['id'];
        if ($actorId === $targetId) return ['errors' => ['Kendini takip edemezsin.'], 'following' => false];
        $targetUser = $targetId === 0 ? ['id' => 0, 'username' => 'devicegame'] : (function_exists('dg_find_user_by_id') ? dg_find_user_by_id($targetId) : null);
        if (!$targetUser) return ['errors' => ['Kullanıcı bulunamadı.'], 'following' => false];
        social_ensure_profile($actor);
        if ($targetId > 0) social_ensure_profile($targetUser);
        return social_with_lock(static function () use ($actor, $actorId, $targetId): array {
            $actorFollows = social_follows($actorId);
            $targetFollows = social_follows($targetId);
            if (in_array($targetId, $actorFollows['blocked'], true)) return ['errors' => ['Önce bu kullanıcının engelini kaldır.'], 'following' => false];
            if (in_array($actorId, $targetFollows['blocked'], true)) return ['errors' => ['Bu kullanıcı takip edilemiyor.'], 'following' => false];
            $following = in_array($targetId, $actorFollows['following'], true);
            if ($following) {
                $actorFollows['following'] = array_values(array_filter($actorFollows['following'], static fn(int $id): bool => $id !== $targetId));
                $targetFollows['followers'] = array_values(array_filter($targetFollows['followers'], static fn(int $id): bool => $id !== $actorId));
            } else {
                $actorFollows['following'][] = $targetId;
                $targetFollows['followers'][] = $actorId;
            }
            social_write_follows_locked($actorId, $actorFollows);
            social_write_follows_locked($targetId, $targetFollows);
            if (!$following && $targetId > 0) {
                social_notify_locked($targetId, $actorId, 'follow', 0, 0, '@' . (string)$actor['username'] . ' seni takip etmeye başladı.', 'profil.php?kullanici=' . rawurlencode((string)$actor['username']));
            }
            return ['errors' => [], 'following' => !$following];
        });
    }

    function social_toggle_block(array $actor, int $targetId): array
    {
        $actorId = (int)$actor['id'];
        if ($actorId === $targetId || $targetId === 0) return ['errors' => ['Bu kullanıcı engellenemez.'], 'blocked' => false];
        $targetUser = function_exists('dg_find_user_by_id') ? dg_find_user_by_id($targetId) : null;
        if (!$targetUser) return ['errors' => ['Kullanıcı bulunamadı.'], 'blocked' => false];
        social_ensure_profile($actor);
        social_ensure_profile($targetUser);
        return social_with_lock(static function () use ($actorId, $targetId): array {
            $actorFollows = social_follows($actorId);
            $targetFollows = social_follows($targetId);
            $blocked = in_array($targetId, $actorFollows['blocked'], true);
            if ($blocked) {
                $actorFollows['blocked'] = array_values(array_filter($actorFollows['blocked'], static fn(int $id): bool => $id !== $targetId));
                $targetFollows['blocked_by'] = array_values(array_filter($targetFollows['blocked_by'], static fn(int $id): bool => $id !== $actorId));
            } else {
                $actorFollows['blocked'][] = $targetId;
                $targetFollows['blocked_by'][] = $actorId;
                $actorFollows['following'] = array_values(array_filter($actorFollows['following'], static fn(int $id): bool => $id !== $targetId));
                $actorFollows['followers'] = array_values(array_filter($actorFollows['followers'], static fn(int $id): bool => $id !== $targetId));
                $targetFollows['following'] = array_values(array_filter($targetFollows['following'], static fn(int $id): bool => $id !== $actorId));
                $targetFollows['followers'] = array_values(array_filter($targetFollows['followers'], static fn(int $id): bool => $id !== $actorId));
            }
            social_write_follows_locked($actorId, $actorFollows);
            social_write_follows_locked($targetId, $targetFollows);
            return ['errors' => [], 'blocked' => !$blocked];
        });
    }

    function social_rate_limit_locked(int $userId, string $bucket, array $limits, bool $record = true): bool
    {
        if ($userId <= 0) return true;
        $file = social_rate_file($userId);
        $data = social_read_json($file, []);
        $now = time();
        $times = [];
        foreach ((array)($data[$bucket] ?? []) as $time) {
            $time = (int)$time;
            if ($time > $now - 86400) $times[] = $time;
        }
        foreach ($limits as [$window, $max]) {
            $count = 0;
            foreach ($times as $time) if ($time > $now - (int)$window) $count++;
            if ($count >= (int)$max) {
                $data[$bucket] = $times;
                social_write_json_atomic($file, $data, false);
                return false;
            }
        }
        if ($record) $times[] = $now;
        $data[$bucket] = $times;
        $data['updated_at'] = $now;
        social_write_json_atomic($file, $data, false);
        return true;
    }

    function social_parse_tags(string $input): array
    {
        $parts = preg_split('/[\s,]+/u', $input) ?: [];
        $tags = [];
        foreach ($parts as $part) {
            $tag = social_slugify(ltrim(trim((string)$part), '#'));
            if ($tag !== '' && !in_array($tag, $tags, true)) $tags[] = social_text_sub($tag, 0, 30);
            if (count($tags) >= 5) break;
        }
        return $tags;
    }

    function social_url_count(string $text): int
    {
        return (int)preg_match_all('~https?://[^\s<]+~iu', $text);
    }

    function social_normalize_poll($poll): ?array
    {
        if (!is_array($poll) || !is_array($poll['options'] ?? null)) return null;
        $options = [];
        $seenLabels = [];
        $seenIds = [];
        foreach ((array)$poll['options'] as $rawOption) {
            $label = is_array($rawOption)
                ? social_clean_text((string)($rawOption['label'] ?? ''), 60)
                : social_clean_text((string)$rawOption, 60);
            $folded = social_fold($label);
            if ($label === '' || isset($seenLabels[$folded])) continue;
            $id = is_array($rawOption) ? strtolower(trim((string)($rawOption['id'] ?? ''))) : '';
            if (!preg_match('/^o[1-5]$/', $id) || isset($seenIds[$id])) {
                $id = 'o' . (count($options) + 1);
                while (isset($seenIds[$id]) && count($options) < 5) {
                    $id = 'o' . ((int)substr($id, 1) + 1);
                }
            }
            if (isset($seenIds[$id])) continue;
            $seenLabels[$folded] = true;
            $seenIds[$id] = true;
            $options[] = [
                'id' => $id,
                'label' => $label,
                'votes' => is_array($rawOption) ? max(0, (int)($rawOption['votes'] ?? 0)) : 0,
            ];
            if (count($options) >= 5) break;
        }
        if (count($options) < 2) return null;
        $total = 0;
        foreach ($options as $option) $total += (int)$option['votes'];
        return [
            'options' => $options,
            'closes_at' => max(0, (int)($poll['closes_at'] ?? 0)),
            'total_votes' => $total,
            'allow_change' => !isset($poll['allow_change']) || !empty($poll['allow_change']),
        ];
    }

    function social_poll_closed(array $poll): bool
    {
        return (int)($poll['closes_at'] ?? 0) > 0 && time() >= (int)$poll['closes_at'];
    }

    function social_poll_with_counts(array $poll, array $reactions): array
    {
        $poll = social_normalize_poll($poll) ?: $poll;
        $counts = [];
        foreach ((array)($poll['options'] ?? []) as $option) {
            if (is_array($option)) $counts[(string)$option['id']] = 0;
        }
        foreach ((array)($reactions['poll_votes'] ?? []) as $optionId) {
            $optionId = (string)$optionId;
            if (isset($counts[$optionId])) $counts[$optionId]++;
        }
        $total = 0;
        foreach ((array)($poll['options'] ?? []) as $i => $option) {
            if (!is_array($option)) continue;
            $poll['options'][$i]['votes'] = (int)($counts[(string)$option['id']] ?? 0);
            $total += (int)$poll['options'][$i]['votes'];
        }
        $poll['total_votes'] = $total;
        return $poll;
    }

    function social_poll_payload(array $post, ?array $viewer = null, ?array $reactions = null): ?array
    {
        $poll = social_normalize_poll($post['poll'] ?? null);
        if (!$poll) return null;
        if ($reactions !== null) $poll = social_poll_with_counts($poll, $reactions);
        $total = max(0, (int)($poll['total_votes'] ?? 0));
        foreach ($poll['options'] as $i => $option) {
            $poll['options'][$i]['percent'] = $total > 0 ? (int)round(((int)$option['votes'] * 100) / $total) : 0;
        }
        $selected = '';
        if ($viewer && $reactions !== null) {
            $selected = (string)(($reactions['poll_votes'] ?? [])[(string)(int)$viewer['id']] ?? '');
        }
        $poll['selected'] = $selected;
        $poll['closed'] = social_poll_closed($poll);
        $poll['can_vote'] = $viewer && ($post['status'] ?? '') === 'published' && !$poll['closed'];
        return $poll;
    }

    function social_normalize_award($award): ?array
    {
        if (!is_array($award)) return null;
        $amount = (int)($award['amount'] ?? 0);
        if ($amount <= 0 || $amount > 100000) return null;
        return [
            'amount' => $amount,
            'reason' => social_clean_text((string)($award['reason'] ?? ''), 180),
            'awarded_by' => max(0, (int)($award['awarded_by'] ?? 0)),
            'awarded_at' => max(0, (int)($award['awarded_at'] ?? 0)),
        ];
    }

    function social_is_event_post(array $post): bool
    {
        return (string)($post['topic_slug'] ?? '') === 'etkinlikler'
            || (int)(($post['meta'] ?? [])['event_at'] ?? 0) > 0;
    }

    function social_event_is_open(array $post): bool
    {
        if (!social_is_event_post($post)) return false;
        $startsAt = (int)(($post['meta'] ?? [])['event_at'] ?? 0);
        return $startsAt > 0 && ($startsAt + 21600) >= time();
    }

    function social_post_prefixes(): array
    {
        return [
            'tartisma' => 'Tartışma',
            'soru' => 'Soru',
            'rehber' => 'Rehber',
            'inceleme' => 'İnceleme',
            'haber' => 'Haber',
            'duyuru' => 'Duyuru',
            'ekip' => 'Ekip Arıyorum',
            'etkinlik' => 'Etkinlik',
            'yardim' => 'Yardım',
            'oneriler' => 'Öneri',
        ];
    }

    function social_default_post_prefix(string $topicSlug): string
    {
        return match ($topicSlug) {
            'yardim' => 'soru',
            'rehberler' => 'rehber',
            'oyun-arkadasi' => 'ekip',
            'etkinlikler' => 'etkinlik',
            'duyurular' => 'duyuru',
            'oyun-onerileri' => 'oneriler',
            default => 'tartisma',
        };
    }

    function social_normalize_post_prefix($prefix, string $topicSlug): string
    {
        $prefix = social_slugify((string)$prefix);
        return isset(social_post_prefixes()[$prefix]) ? $prefix : social_default_post_prefix($topicSlug);
    }

    function social_post_prefix(array $post): string
    {
        return social_normalize_post_prefix($post['prefix'] ?? '', (string)($post['topic_slug'] ?? ''));
    }

    function social_normalize_post(array $post): array
    {
        $created = (int)($post['created_at'] ?? time());
        $meta = [];
        if (is_array($post['meta'] ?? null)) {
            foreach (['game', 'platform', 'rank', 'mic', 'play_time', 'needed', 'event_place'] as $key) {
                $meta[$key] = social_clean_text((string)($post['meta'][$key] ?? ''), 60);
            }
            $meta['event_at'] = max(0, (int)($post['meta']['event_at'] ?? 0));
            $meta['event_capacity'] = max(0, min(1000, (int)($post['meta']['event_capacity'] ?? 0)));
        }
        $topicSlug = social_slugify((string)($post['topic_slug'] ?? 'genel-sohbet')) ?: 'genel-sohbet';
        $authorAvatar = ltrim(str_replace('\\', '/', trim((string)($post['author_avatar'] ?? ''))), '/');
        if (!preg_match('~^uploads/social/avatars/[A-Za-z0-9_./-]+$~', $authorAvatar) || str_contains($authorAvatar, '..')) $authorAvatar = '';
        return [
            'schema_version' => 2,
            'id' => (int)($post['id'] ?? 0),
            'user_id' => (int)($post['user_id'] ?? 0),
            'author_username' => (string)($post['author_username'] ?? ''),
            'author_display' => (string)($post['author_display'] ?? ''),
            'author_avatar' => $authorAvatar,
            'topic_slug' => $topicSlug,
            'prefix' => social_normalize_post_prefix($post['prefix'] ?? '', $topicSlug),
            'title' => social_clean_text((string)($post['title'] ?? ''), 140),
            'body' => social_clean_text((string)($post['body'] ?? ''), 5000),
            'excerpt' => social_excerpt((string)($post['excerpt'] ?? $post['body'] ?? ''), 240),
            'tags' => array_values(array_filter(array_map(static fn($tag): string => social_slugify((string)$tag), (array)($post['tags'] ?? [])))),
            'meta' => $meta,
            'poll' => social_normalize_poll($post['poll'] ?? null),
            'award' => social_normalize_award($post['award'] ?? null),
            'rsvp_count' => max(0, (int)($post['rsvp_count'] ?? 0)),
            'image' => trim((string)($post['image'] ?? '')),
            'status' => in_array(($post['status'] ?? 'published'), ['published', 'pending', 'hidden', 'deleted'], true) ? (string)$post['status'] : 'published',
            'pinned' => !empty($post['pinned']),
            'locked' => !empty($post['locked']),
            'comment_count' => max(0, (int)($post['comment_count'] ?? 0)),
            'gg_count' => max(0, (int)($post['gg_count'] ?? 0)),
            'view_count' => max(0, (int)($post['view_count'] ?? 0)),
            'watch_count' => max(0, (int)($post['watch_count'] ?? 0)),
            'solution_comment_id' => max(0, (int)($post['solution_comment_id'] ?? 0)),
            'mentions_notified' => !empty($post['mentions_notified']),
            'topic_notified' => !empty($post['topic_notified']),
            'created_at' => $created,
            'updated_at' => (int)($post['updated_at'] ?? $created),
            'last_activity_at' => (int)($post['last_activity_at'] ?? $created),
            'edited_at' => (int)($post['edited_at'] ?? 0),
        ];
    }

    function social_post_summary(array $post): array
    {
        $post = social_normalize_post($post);
        return [
            'id' => $post['id'],
            'user_id' => $post['user_id'],
            'author_username' => $post['author_username'],
            'author_display' => $post['author_display'],
            'author_avatar' => $post['author_avatar'],
            'topic_slug' => $post['topic_slug'],
            'prefix' => $post['prefix'],
            'title' => $post['title'],
            'excerpt' => $post['excerpt'],
            'tags' => $post['tags'],
            'meta' => $post['meta'],
            'poll' => $post['poll'],
            'award' => $post['award'],
            'rsvp_count' => $post['rsvp_count'],
            'image' => $post['image'],
            'status' => $post['status'],
            'pinned' => $post['pinned'],
            'locked' => $post['locked'],
            'comment_count' => $post['comment_count'],
            'gg_count' => $post['gg_count'],
            'view_count' => $post['view_count'],
            'watch_count' => $post['watch_count'],
            'solution_comment_id' => $post['solution_comment_id'],
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
            'last_activity_at' => $post['last_activity_at'],
        ];
    }

    function social_sort_summaries(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $pin = (int)!empty($b['pinned']) <=> (int)!empty($a['pinned']);
            if ($pin !== 0) return $pin;
            $activity = (int)($b['last_activity_at'] ?? 0) <=> (int)($a['last_activity_at'] ?? 0);
            return $activity !== 0 ? $activity : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        });
        return array_values($items);
    }

    function social_index_upsert_one_locked(string $file, array $summary, bool $insert, int $cap = 0): void
    {
        $items = social_read_json($file, []);
        $items = array_values(array_filter($items, static fn($item): bool => is_array($item) && (int)($item['id'] ?? 0) !== (int)$summary['id']));
        if ($insert) $items[] = $summary;
        $items = social_sort_summaries($items);
        if ($cap > 0) $items = array_slice($items, 0, $cap);
        social_write_json_atomic($file, $items, false);
    }

    function social_index_upsert_locked(array $summary): void
    {
        $insert = ($summary['status'] ?? '') !== 'deleted';
        social_index_upsert_one_locked(social_index_file('latest'), $summary, $insert, 1000);
        social_index_upsert_one_locked(social_index_file('month-' . date('Y-m', (int)$summary['created_at'])), $summary, $insert);
        social_index_upsert_one_locked(social_index_file('user-' . (int)$summary['user_id']), $summary, $insert, 1000);
        foreach (social_topics(false) as $topic) {
            $slug = (string)$topic['slug'];
            social_index_upsert_one_locked(
                social_index_file('topic-' . $slug),
                $summary,
                $insert && $slug === (string)$summary['topic_slug'],
                1000
            );
        }
    }

    function social_sync_profile_post_counts_locked(int $userId): void
    {
        $profile = social_get_profile($userId);
        if (!$profile) return;
        $items = social_read_json(social_index_file('user-' . $userId), []);
        $published = 0;
        foreach ($items as $item) if (($item['status'] ?? '') === 'published') $published++;
        $profile['post_count'] = $published;
        $profile['approved_posts'] = $published;
        social_maybe_promote_locked($profile);
    }

    function social_sync_author_summaries_locked(array $profile): void
    {
        $items = social_read_json(social_index_file('user-' . (int)$profile['user_id']), []);
        foreach ($items as $summary) {
            $post = social_get_post((int)($summary['id'] ?? 0), true);
            if (!$post) continue;
            $post['author_username'] = (string)$profile['username'];
            $post['author_display'] = (string)$profile['display_name'];
            $post['author_avatar'] = (string)($profile['avatar'] ?? '');
            $post = social_normalize_post($post);
            social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            social_index_upsert_locked(social_post_summary($post));
        }
    }

    function social_seed_post_locked(array $seed): void
    {
        $official = social_official_profile();
        $id = social_next_id_locked('posts');
        $created = (int)($seed['created_at'] ?? time());
        $post = social_normalize_post([
            'id' => $id,
            'user_id' => 0,
            'author_username' => $official['username'],
            'author_display' => $official['display_name'],
            'topic_slug' => (string)$seed['topic_slug'],
            'title' => (string)$seed['title'],
            'body' => (string)$seed['body'],
            'tags' => (array)($seed['tags'] ?? []),
            'meta' => (array)($seed['meta'] ?? []),
            'status' => 'published',
            'pinned' => !empty($seed['pinned']),
            'mentions_notified' => true,
            'created_at' => $created,
            'updated_at' => $created,
            'last_activity_at' => $created,
        ]);
        social_write_json_atomic(social_post_file($id), $post, false);
        social_write_json_atomic(social_reactions_file($id), ['gg' => [], 'poll_votes' => [], 'rsvp' => []], false);
        social_index_upsert_locked(social_post_summary($post));
        social_sync_profile_post_counts_locked(0);
    }

    function social_get_post(int $postId, bool $includeDeleted = false): ?array
    {
        social_ensure_storage();
        if ($postId <= 0) return null;
        $file = social_post_file($postId);
        if (!is_file($file)) return null;
        $post = social_normalize_post(social_read_json($file));
        if (!$includeDeleted && $post['status'] === 'deleted') return null;
        return $post;
    }

    function social_post_visible(array $post, ?array $viewer, array $blockedIds = []): bool
    {
        if (($post['status'] ?? '') === 'deleted') return false;
        $isAdmin = !empty($viewer['is_admin']);
        if (!$isAdmin && in_array((int)($post['user_id'] ?? -1), $blockedIds, true)) return false;
        if (($post['status'] ?? '') === 'published') return true;
        if ($isAdmin) return true;
        return $viewer && (int)$viewer['id'] === (int)($post['user_id'] ?? 0);
    }

    function social_extract_mentions(string $text): array
    {
        if (!preg_match_all('/(?<![A-Za-z0-9_.-])@([A-Za-z0-9_.-]{3,32})/', $text, $matches)) return [];
        $index = social_users_index();
        $ids = [];
        $coreMap = null;
        foreach ($matches[1] as $username) {
            $key = social_fold(rtrim((string)$username, '.-'));
            if (isset($index['map'][$key])) {
                $ids[] = (int)$index['map'][$key];
                continue;
            }
            // Sosyal sayfayı henüz açmamış bir DeviceGame hesabı da bildirim alabilsin.
            if ($coreMap === null) {
                $coreMap = [];
                if (function_exists('dg_all')) {
                    foreach (dg_all('users') as $coreUser) {
                        if (is_array($coreUser)) $coreMap[social_fold((string)($coreUser['username'] ?? ''))] = (int)($coreUser['id'] ?? 0);
                    }
                }
            }
            if (isset($coreMap[$key])) $ids[] = (int)$coreMap[$key];
        }
        return array_values(array_unique($ids));
    }

    function social_notify_locked(int $targetId, int $actorId, string $type, int $postId, int $commentId, string $message, string $url, bool $force = false): void
    {
        if ($targetId <= 0 || $targetId === $actorId) return;
        $targetFollows = social_follows($targetId);
        if (in_array($actorId, $targetFollows['blocked'], true)) return;
        if (!$force) {
            $prefKey = match ($type) {
                'comment' => 'comments',
                'reply' => 'replies',
                'mention', 'chat_mention' => 'mentions',
                'follow' => 'follows',
                'event_rsvp' => 'events',
                'award' => 'awards',
                'direct_message' => 'messages',
                'watch_comment', 'watch_topic' => 'watching',
                'solution' => 'solutions',
                default => '',
            };
            if ($prefKey !== '') {
                $targetProfile = social_get_profile($targetId);
                $prefs = social_normalize_notification_prefs($targetProfile['notification_prefs'] ?? []);
                if (empty($prefs[$prefKey])) return;
            }
        }
        $file = social_notifications_file($targetId);
        $items = social_read_json($file, []);
        array_unshift($items, [
            'id' => social_next_id_locked('notifications'),
            'type' => $type,
            'actor_id' => $actorId,
            'post_id' => $postId,
            'comment_id' => $commentId,
            'message' => social_clean_text($message, 240),
            'url' => $url,
            'read' => false,
            'created_at' => time(),
        ]);
        $items = array_slice(array_values($items), 0, 100);
        social_write_json_atomic($file, $items, false);
    }

    function social_notify_mentions_locked(array $post, int $actorId, string $text, int $commentId = 0): void
    {
        $actor = social_get_profile($actorId);
        $actorName = $actor['display_name'] ?? 'Bir oyuncu';
        foreach (social_extract_mentions($text) as $targetId) {
            $url = 'gonderi.php?id=' . (int)$post['id'] . ($commentId > 0 ? '&yorum=' . $commentId . '#yorum-' . $commentId : '');
            social_notify_locked($targetId, $actorId, 'mention', (int)$post['id'], $commentId, $actorName . ' senden bahsetti: “' . (string)$post['title'] . '”', $url);
        }
    }

    function social_notifications(int $userId): array
    {
        social_ensure_storage();
        $items = social_read_json(social_notifications_file($userId), []);
        return array_values(array_filter($items, 'is_array'));
    }

    function social_unread_notification_count(int $userId): int
    {
        $count = 0;
        foreach (social_notifications($userId) as $item) if (empty($item['read'])) $count++;
        return $count;
    }

    function social_mark_notifications_read(int $userId, int $notificationId = 0): void
    {
        social_with_lock(static function () use ($userId, $notificationId): void {
            $items = social_notifications($userId);
            foreach ($items as $i => $item) {
                if ($notificationId === 0 || (int)($item['id'] ?? 0) === $notificationId) $items[$i]['read'] = true;
            }
            social_write_json_atomic(social_notifications_file($userId), $items, false);
        });
    }

    function social_parse_poll_input(array $input, ?array $existing = null): array
    {
        $existingPoll = $existing ? social_normalize_poll($existing['poll'] ?? null) : null;
        $hasPollInput = array_key_exists('poll_enabled', $input)
            || array_key_exists('poll_options', $input)
            || array_key_exists('poll_days', $input);
        if (!$hasPollInput) {
            return ['errors' => [], 'poll' => $existingPoll];
        }
        if (empty($input['poll_enabled'])) {
            if ($existingPoll && (int)$existingPoll['total_votes'] > 0) {
                return ['errors' => ['Oy kullanılan anket kaldırılamaz.'], 'poll' => $existingPoll];
            }
            return ['errors' => [], 'poll' => null];
        }

        $rawOptions = $input['poll_options'] ?? [];
        if (is_string($rawOptions)) {
            $rawOptions = preg_split('/\r\n|\r|\n/', $rawOptions) ?: [];
        }
        if (!is_array($rawOptions)) $rawOptions = [];
        $labels = [];
        $seen = [];
        $duplicate = false;
        foreach ($rawOptions as $rawOption) {
            $label = social_clean_text(is_array($rawOption) ? (string)($rawOption['label'] ?? '') : (string)$rawOption, 60);
            if ($label === '') continue;
            $folded = social_fold($label);
            if (isset($seen[$folded])) {
                $duplicate = true;
                continue;
            }
            $seen[$folded] = true;
            $labels[] = $label;
        }
        $errors = [];
        if ($duplicate) $errors[] = 'Anket seçenekleri birbirinden farklı olmalı.';
        if (count($labels) < 2) $errors[] = 'Anket için en az 2 seçenek yaz.';
        if (count($labels) > 5) $errors[] = 'Anket en fazla 5 seçenek içerebilir.';
        if ($errors) return ['errors' => $errors, 'poll' => $existingPoll];

        if ($existingPoll) {
            $existingLabels = array_map(static fn(array $option): string => social_fold((string)$option['label']), (array)$existingPoll['options']);
            $submittedLabels = array_map(static fn(string $label): string => social_fold($label), $labels);
            if ((int)$existingPoll['total_votes'] > 0 && $existingLabels !== $submittedLabels) {
                return ['errors' => ['Oy kullanılan anket seçenekleri değiştirilemez.'], 'poll' => $existingPoll];
            }
            $options = [];
            foreach ($labels as $i => $label) {
                $options[] = [
                    'id' => 'o' . ($i + 1),
                    'label' => $label,
                    'votes' => (int)(($existingPoll['options'][$i] ?? [])['votes'] ?? 0),
                ];
            }
            return ['errors' => [], 'poll' => social_normalize_poll([
                'options' => $options,
                'closes_at' => (int)$existingPoll['closes_at'],
                'allow_change' => true,
            ])];
        }

        $days = (int)($input['poll_days'] ?? 7);
        if (!in_array($days, [0, 1, 3, 7, 14, 30], true)) $days = 7;
        $options = [];
        foreach ($labels as $i => $label) {
            $options[] = ['id' => 'o' . ($i + 1), 'label' => $label, 'votes' => 0];
        }
        return ['errors' => [], 'poll' => social_normalize_poll([
            'options' => $options,
            'closes_at' => $days > 0 ? time() + ($days * 86400) : 0,
            'allow_change' => true,
        ])];
    }

    function social_validate_post_input(array $input, array $user, ?array $existing = null): array
    {
        $errors = [];
        $topicSlug = social_slugify((string)($input['topic_slug'] ?? $input['topic'] ?? ''));
        $topic = social_topic($topicSlug, true);
        if (!$topic) {
            $errors[] = 'Geçerli bir forum konusu seç.';
            $topic = ['slug' => $topicSlug, 'official_only' => false];
        }
        if (!empty($topic['official_only']) && empty($user['is_admin'])) {
            $errors[] = 'Duyurular konusunda yalnızca DeviceGame ekibi gönderi açabilir.';
        }
        $title = social_clean_text((string)($input['title'] ?? ''), 140);
        $body = social_clean_text((string)($input['body'] ?? ''), 5000);
        if (social_text_len($title) < 4) $errors[] = 'Başlık en az 4 karakter olmalı.';
        if (social_text_len($body) < 10) $errors[] = 'Gönderin en az 10 karakter olmalı.';
        $tags = social_parse_tags((string)($input['tags'] ?? ''));
        $meta = [];
        if ($topicSlug === 'oyun-arkadasi') {
            foreach (['game', 'platform', 'rank', 'mic', 'play_time', 'needed'] as $key) {
                $meta[$key] = social_clean_text((string)($input['lfg_' . $key] ?? $input[$key] ?? ''), 60);
            }
            if ($meta['game'] === '') $errors[] = 'Oyun arkadaşı ilanında oyun adını yaz.';
            if ($meta['platform'] === '') $errors[] = 'Oyun arkadaşı ilanında platformu seç/yaz.';
        }
        if ($topicSlug === 'etkinlikler') {
            $meta['game'] = social_clean_text((string)($input['event_game'] ?? $input['lfg_game'] ?? $input['game'] ?? ''), 60);
            $meta['platform'] = social_clean_text((string)($input['event_platform'] ?? $input['lfg_platform'] ?? $input['platform'] ?? ''), 60);
            $meta['event_place'] = social_clean_text((string)($input['event_place'] ?? ''), 60);
            $meta['event_capacity'] = max(0, min(1000, (int)($input['event_capacity'] ?? 0)));
            $rawEventAt = trim((string)($input['event_at'] ?? ''));
            $eventAt = 0;
            if ($rawEventAt !== '') {
                $eventAt = is_numeric($rawEventAt) ? (int)$rawEventAt : (strtotime($rawEventAt) ?: 0);
            }
            $meta['event_at'] = $eventAt;
            $existingEventAt = $existing ? (int)(($existing['meta'] ?? [])['event_at'] ?? 0) : 0;
            if ($meta['game'] === '') $errors[] = 'Etkinlikte oynanacak oyunu yaz.';
            if ($meta['event_place'] === '') $errors[] = 'Etkinliğin buluşma yerini yaz.';
            if ($eventAt <= 0) {
                $errors[] = 'Geçerli bir etkinlik tarih ve saati seç.';
            } elseif ($eventAt < time() - 3600 && $eventAt !== $existingEventAt) {
                $errors[] = 'Etkinlik tarihi geçmişte olamaz.';
            } elseif ($eventAt > time() + (366 * 86400)) {
                $errors[] = 'Etkinlik en fazla bir yıl ileriye planlanabilir.';
            }
        }
        $pollResult = social_parse_poll_input($input, $existing);
        $errors = array_merge($errors, (array)$pollResult['errors']);
        return [
            'errors' => $errors,
            'data' => [
                'topic_slug' => $topicSlug,
                'prefix' => social_normalize_post_prefix($input['prefix'] ?? ($existing['prefix'] ?? ''), $topicSlug),
                'title' => $title,
                'body' => $body,
                'tags' => $tags,
                'meta' => $meta,
                'poll' => $pollResult['poll'],
            ],
        ];
    }

    function social_can_upload_image(array $profile, array $user): bool
    {
        return !empty($user['is_admin']) || (int)($profile['trust_level'] ?? 0) >= 1 || (int)($profile['approved_posts'] ?? 0) >= 3;
    }

    function social_handle_post_image(array $file, int $postId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (string)($file['name'] ?? '') === '') {
            return ['path' => '', 'error' => ''];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['path' => '', 'error' => 'Görsel yüklenemedi. Daha küçük bir dosyayla tekrar dene.'];
        }
        if ((int)($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return ['path' => '', 'error' => 'Görsel en fazla 4 MB olabilir.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['path' => '', 'error' => 'Görsel dosyası doğrulanamadı.'];
        }
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            return ['path' => '', 'error' => 'Yalnızca JPG, PNG veya WebP görsel yükleyebilirsin.'];
        }
        $sourceWidth = is_array($info) ? (int)($info[0] ?? 0) : 0;
        $sourceHeight = is_array($info) ? (int)($info[1] ?? 0) : 0;
        if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth > 8000 || $sourceHeight > 8000 || $sourceWidth * $sourceHeight > 40000000) {
            return ['path' => '', 'error' => 'Görsel çözünürlüğü çok yüksek. En fazla 8000×8000 ve 40 megapiksel yükleyebilirsin.'];
        }
        $relativeDir = date('Y/m');
        $dir = social_upload_dir() . '/' . $relativeDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['path' => '', 'error' => 'Görsel klasörü oluşturulamadı.'];
        }
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
        $base = 'post-' . $postId . '-' . bin2hex(random_bytes(6));
        $saved = false;
        $extension = $extensions[$mime];
        $destination = $dir . '/' . $base . '.' . $extension;

        if (function_exists('imagecreatetruecolor')) {
            $source = null;
            if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $source = @imagecreatefromjpeg($tmp);
            if ($mime === 'image/png' && function_exists('imagecreatefrompng')) $source = @imagecreatefrompng($tmp);
            if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $source = @imagecreatefromwebp($tmp);
            if ($source) {
                $width = imagesx($source);
                $height = imagesy($source);
                $scale = min(1, 1600 / max(1, $width), 1400 / max(1, $height));
                $newWidth = max(1, (int)round($width * $scale));
                $newHeight = max(1, (int)round($height * $scale));
                $canvas = imagecreatetruecolor($newWidth, $newHeight);
                if ($canvas) {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
                    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    if (function_exists('imagewebp')) {
                        $extension = 'webp';
                        $destination = $dir . '/' . $base . '.webp';
                        $saved = @imagewebp($canvas, $destination, 84);
                    } elseif (function_exists('imagejpeg')) {
                        $extension = 'jpg';
                        $destination = $dir . '/' . $base . '.jpg';
                        $saved = @imagejpeg($canvas, $destination, 86);
                    }
                    imagedestroy($canvas);
                }
                imagedestroy($source);
            }
        }
        if (!$saved) {
            $destination = $dir . '/' . $base . '.' . $extensions[$mime];
            $saved = @move_uploaded_file($tmp, $destination);
            $extension = $extensions[$mime];
        }
        if (!$saved) {
            return ['path' => '', 'error' => 'Görsel kaydedilemedi. uploads/social yazma iznini kontrol et.'];
        }
        @chmod($destination, 0664);
        return ['path' => 'uploads/social/' . $relativeDir . '/' . $base . '.' . $extension, 'error' => ''];
    }

    function social_handle_avatar_image(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (string)($file['name'] ?? '') === '') {
            return ['path' => '', 'error' => ''];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['path' => '', 'error' => 'Profil fotoğrafı yüklenemedi. Daha küçük bir dosyayla tekrar dene.'];
        }
        if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
            return ['path' => '', 'error' => 'Profil fotoğrafı en fazla 2 MB olabilir.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['path' => '', 'error' => 'Profil fotoğrafı doğrulanamadı.'];
        }
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            return ['path' => '', 'error' => 'Profil fotoğrafı JPG, PNG veya WebP olmalı.'];
        }
        $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
        if ($width < 32 || $height < 32 || $width > 5000 || $height > 5000 || $width * $height > 20000000) {
            return ['path' => '', 'error' => 'Profil fotoğrafı en az 32×32, en fazla 5000×5000 olmalı.'];
        }
        $dir = social_upload_dir() . '/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['path' => '', 'error' => 'Profil fotoğrafı klasörü oluşturulamadı.'];
        }
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
        $base = 'user-' . $userId . '-' . bin2hex(random_bytes(6));
        $extension = $extensions[$mime];
        $destination = $dir . '/' . $base . '.' . $extension;
        $saved = false;
        if (function_exists('imagecreatetruecolor')) {
            $source = null;
            if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $source = @imagecreatefromjpeg($tmp);
            if ($mime === 'image/png' && function_exists('imagecreatefrompng')) $source = @imagecreatefrompng($tmp);
            if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $source = @imagecreatefromwebp($tmp);
            if ($source) {
                $side = min($width, $height);
                $target = min(512, $side);
                $srcX = (int)floor(($width - $side) / 2);
                $srcY = (int)floor(($height - $side) / 2);
                $canvas = imagecreatetruecolor($target, $target);
                if ($canvas) {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $target, $target, $transparent);
                    imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $target, $target, $side, $side);
                    if (function_exists('imagewebp')) {
                        $extension = 'webp';
                        $destination = $dir . '/' . $base . '.webp';
                        $saved = @imagewebp($canvas, $destination, 86);
                    } elseif (function_exists('imagejpeg')) {
                        $extension = 'jpg';
                        $destination = $dir . '/' . $base . '.jpg';
                        $saved = @imagejpeg($canvas, $destination, 88);
                    }
                    imagedestroy($canvas);
                }
                imagedestroy($source);
            }
        }
        if (!$saved) {
            $extension = $extensions[$mime];
            $destination = $dir . '/' . $base . '.' . $extension;
            $saved = @move_uploaded_file($tmp, $destination);
        }
        if (!$saved) return ['path' => '', 'error' => 'Profil fotoğrafı kaydedilemedi. uploads/social/avatars yazma iznini kontrol et.'];
        @chmod($destination, 0664);
        return ['path' => 'uploads/social/avatars/' . $base . '.' . $extension, 'error' => ''];
    }

    function social_delete_upload(string $path): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($path, 'uploads/social/')) return;
        $full = social_base_dir() . '/' . $path;
        if (is_file($full)) @unlink($full);
    }

    function social_create_post(array $user, array $input, array $files = []): array
    {
        social_ensure_storage();
        $profile = social_ensure_profile($user);
        if (($muteError = social_muted_error($profile, $user)) !== '') return ['errors' => [$muteError], 'id' => 0, 'status' => ''];
        $validation = social_validate_post_input($input, $user);
        if ($validation['errors']) return ['errors' => $validation['errors'], 'id' => 0, 'status' => ''];
        $hasImage = !empty($files['image']['name']);
        if ($hasImage && !social_can_upload_image($profile, $user)) {
            return ['errors' => ['Görsel yükleme, güvenilir üye olduktan sonra açılır. İlk gönderilerini metin olarak paylaş.'], 'id' => 0, 'status' => ''];
        }
        return social_with_lock(static function () use ($user, $profile, $validation, $files): array {
            $userId = (int)$user['id'];
            if (empty($user['is_admin']) && !social_rate_limit_locked($userId, 'posts', [[3600, 5], [86400, 20]])) {
                return ['errors' => ['Çok hızlı gönderi açıyorsun. Biraz bekleyip tekrar dene.'], 'id' => 0, 'status' => ''];
            }
            $data = $validation['data'];
            $postId = social_next_id_locked('posts');
            $imagePath = '';
            if (!empty($files['image'])) {
                $image = social_handle_post_image((array)$files['image'], $postId);
                if ($image['error'] !== '') return ['errors' => [$image['error']], 'id' => 0, 'status' => ''];
                $imagePath = (string)$image['path'];
            }
            $trusted = social_is_trusted($profile, $user);
            $status = (!empty($user['is_admin']) || ($trusted && social_url_count((string)$data['body']) < 3)) ? 'published' : 'pending';
            $now = time();
            $isEvent = ($data['topic_slug'] ?? '') === 'etkinlikler' || (int)(($data['meta'] ?? [])['event_at'] ?? 0) > 0;
            $post = social_normalize_post([
                'id' => $postId,
                'user_id' => $userId,
                'author_username' => (string)$profile['username'],
                'author_display' => (string)$profile['display_name'],
                'author_avatar' => (string)($profile['avatar'] ?? ''),
                'topic_slug' => $data['topic_slug'],
                'prefix' => $data['prefix'] ?? social_default_post_prefix((string)$data['topic_slug']),
                'title' => $data['title'],
                'body' => $data['body'],
                'tags' => $data['tags'],
                'meta' => $data['meta'],
                'poll' => $data['poll'] ?? null,
                'rsvp_count' => $isEvent ? 1 : 0,
                'image' => $imagePath,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
                'last_activity_at' => $now,
            ]);
            social_write_json_atomic(social_post_file($postId), $post, false);
            social_write_json_atomic(social_reactions_file($postId), [
                'gg' => [],
                'comment_gg' => [],
                'poll_votes' => [],
                'rsvp' => $isEvent ? [$userId] : [],
                'watchers' => [$userId],
            ], false);
            $post['watch_count'] = 1;
            $post = social_normalize_post($post);
            social_write_json_atomic(social_post_file($postId), $post, false);
            $watches = social_watches($userId);
            if (!in_array($postId, $watches['posts'], true)) {
                array_unshift($watches['posts'], $postId);
                social_write_watches_locked($userId, $watches);
            }
            social_index_upsert_locked(social_post_summary($post));
            social_sync_profile_post_counts_locked($userId);
            if ($status === 'published') {
                social_notify_mentions_locked($post, $userId, (string)$post['body']);
                social_notify_topic_watchers_locked($post, $userId);
                $post['mentions_notified'] = true;
                $post['topic_notified'] = true;
                social_write_json_atomic(social_post_file($postId), $post, false);
            }
            return ['errors' => [], 'id' => $postId, 'status' => $status];
        });
    }

    function social_edit_post(array $actor, int $postId, array $input, array $files = []): array
    {
        $post = social_get_post($postId, true);
        if (!$post || $post['status'] === 'deleted') return ['errors' => ['Gönderi bulunamadı.'], 'id' => $postId, 'status' => ''];
        $isAdmin = !empty($actor['is_admin']);
        if (!$isAdmin && (int)$actor['id'] !== (int)$post['user_id']) return ['errors' => ['Bu gönderiyi düzenleyemezsin.'], 'id' => $postId, 'status' => (string)$post['status']];
        if (!$isAdmin && $post['status'] === 'hidden') return ['errors' => ['Gizlenmiş gönderi düzenlenemez.'], 'id' => $postId, 'status' => 'hidden'];
        $validation = social_validate_post_input($input, $actor, $post);
        if ($validation['errors']) return ['errors' => $validation['errors'], 'id' => $postId, 'status' => (string)$post['status']];
        $author = $post['user_id'] === 0 ? social_official_profile() : social_get_profile((int)$post['user_id']);
        if (!$isAdmin && $author && ($muteError = social_muted_error($author, $actor)) !== '') return ['errors' => [$muteError], 'id' => $postId, 'status' => (string)$post['status']];
        $hasImage = !empty($files['image']['name']);
        if ($hasImage && $author && !social_can_upload_image($author, $actor)) {
            return ['errors' => ['Bu hesap henüz görsel yükleyemiyor.'], 'id' => $postId, 'status' => (string)$post['status']];
        }
        return social_with_lock(static function () use ($actor, $post, $validation, $files, $input, $author, $isAdmin): array {
            $data = $validation['data'];
            $oldStatus = (string)$post['status'];
            $reactions = social_reactions((int)$post['id']);
            $eventAfter = ($data['topic_slug'] ?? '') === 'etkinlikler' || (int)(($data['meta'] ?? [])['event_at'] ?? 0) > 0;
            $rsvpCount = 0;
            if ($eventAfter) {
                $authorId = (int)$post['user_id'];
                $prospectiveRsvps = $reactions['rsvp'];
                if ($authorId > 0 && !in_array($authorId, $prospectiveRsvps, true)) $prospectiveRsvps[] = $authorId;
                $capacity = (int)(($data['meta'] ?? [])['event_capacity'] ?? 0);
                if ($capacity > 0 && count($prospectiveRsvps) > $capacity) {
                    return ['errors' => ['Etkinlik kapasitesi, mevcut katılımcı sayısının altında olamaz.'], 'id' => (int)$post['id'], 'status' => $oldStatus];
                }
                if ($prospectiveRsvps !== $reactions['rsvp']) {
                    $reactions['rsvp'] = $prospectiveRsvps;
                    social_write_json_atomic(social_reactions_file((int)$post['id']), $reactions, false);
                }
                $rsvpCount = count($prospectiveRsvps);
            }
            $imagePath = (string)$post['image'];
            if (!empty($input['remove_image'])) {
                social_delete_upload($imagePath);
                $imagePath = '';
            }
            if (!empty($files['image'])) {
                $image = social_handle_post_image((array)$files['image'], (int)$post['id']);
                if ($image['error'] !== '') return ['errors' => [$image['error']], 'id' => (int)$post['id'], 'status' => $oldStatus];
                if ((string)$image['path'] !== '') {
                    if ($imagePath !== '') social_delete_upload($imagePath);
                    $imagePath = (string)$image['path'];
                }
            }
            $status = $oldStatus;
            if (!$isAdmin && $oldStatus === 'published' && $author && !social_is_trusted($author, $actor) && social_url_count((string)$data['body']) > 0) {
                $status = 'pending';
            }
            if ($author) $post['author_avatar'] = (string)($author['avatar'] ?? '');
            $post = social_normalize_post(array_merge($post, $data, [
                'rsvp_count' => $rsvpCount,
                'image' => $imagePath,
                'status' => $status,
                'updated_at' => time(),
                'edited_at' => time(),
                'last_activity_at' => max((int)$post['last_activity_at'], time()),
            ]));
            social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            social_index_upsert_locked(social_post_summary($post));
            social_sync_profile_post_counts_locked((int)$post['user_id']);
            if ($status === 'published' && (empty($post['mentions_notified']) || empty($post['topic_notified']))) {
                if (empty($post['mentions_notified'])) social_notify_mentions_locked($post, (int)$actor['id'], (string)$post['body']);
                if (empty($post['topic_notified'])) social_notify_topic_watchers_locked($post, (int)$post['user_id']);
                $post['mentions_notified'] = true;
                $post['topic_notified'] = true;
                social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            }
            return ['errors' => [], 'id' => (int)$post['id'], 'status' => $status];
        });
    }

    function social_set_post_status(array $actor, int $postId, string $status): array
    {
        if (!in_array($status, ['published', 'pending', 'hidden', 'deleted'], true)) return ['errors' => ['Geçersiz gönderi durumu.']];
        $post = social_get_post($postId, true);
        if (!$post) return ['errors' => ['Gönderi bulunamadı.']];
        $isAdmin = !empty($actor['is_admin']);
        $isAuthor = (int)$actor['id'] === (int)$post['user_id'];
        if (!$isAdmin && !($isAuthor && $status === 'deleted')) return ['errors' => ['Bu işlem için yetkin yok.']];
        return social_with_lock(static function () use ($actor, $post, $status): array {
            $wasPublished = $post['status'] === 'published';
            if ($status === 'deleted' && (string)($post['image'] ?? '') !== '') {
                social_delete_upload((string)$post['image']);
                $post['image'] = '';
            }
            $post['status'] = $status;
            $post['updated_at'] = time();
            if ($status === 'published') $post['last_activity_at'] = max((int)$post['last_activity_at'], time());
            $post = social_normalize_post($post);
            social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            social_index_upsert_locked(social_post_summary($post));
            social_sync_profile_post_counts_locked((int)$post['user_id']);
            if (!$wasPublished && $status === 'published' && (empty($post['mentions_notified']) || empty($post['topic_notified']))) {
                // Onaylayan yönetici değil, gönderinin yazarı bahsetmiş sayılır.
                if (empty($post['mentions_notified'])) social_notify_mentions_locked($post, (int)$post['user_id'], (string)$post['body']);
                if (empty($post['topic_notified'])) social_notify_topic_watchers_locked($post, (int)$post['user_id']);
                $post['mentions_notified'] = true;
                $post['topic_notified'] = true;
                social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            }
            if (!empty($actor['is_admin'])) {
                social_log_moderation_locked($actor, 'post_status_' . $status, 'post', (int)$post['id'], (int)$post['id'], 'Gönderi durumu: ' . $status);
            }
            return ['errors' => [], 'status' => $status];
        });
    }

    function social_set_post_flag(array $actor, int $postId, string $flag): array
    {
        if (empty($actor['is_admin']) || !in_array($flag, ['pinned', 'locked'], true)) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.'], 'value' => false];
        $post = social_get_post($postId, true);
        if (!$post || $post['status'] === 'deleted') return ['errors' => ['Gönderi bulunamadı.'], 'value' => false];
        return social_with_lock(static function () use ($actor, $post, $flag): array {
            $post[$flag] = empty($post[$flag]);
            $post['updated_at'] = time();
            $post = social_normalize_post($post);
            social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
            social_index_upsert_locked(social_post_summary($post));
            social_log_moderation_locked($actor, 'post_' . $flag . '_' . (!empty($post[$flag]) ? 'on' : 'off'), 'post', (int)$post['id'], (int)$post['id'], 'Gönderi işareti güncellendi: ' . $flag);
            return ['errors' => [], 'value' => (bool)$post[$flag]];
        });
    }

    function social_award_post(array $admin, int $postId, int $amount, string $reason): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $amount = (int)$amount;
        $reason = social_clean_text($reason, 180);
        if ($amount < 5 || $amount > 1000) return ['errors' => ['Ödül 5 ile 1000 D-Coin arasında olmalı.']];
        if (social_text_len($reason) < 3) return ['errors' => ['Ödül gerekçesini kısaca yaz.']];
        $post = social_get_post($postId, true);
        if (!$post || $post['status'] !== 'published') return ['errors' => ['Yalnızca yayındaki bir gönderiye ödül verilebilir.']];
        if ((int)$post['user_id'] <= 0) return ['errors' => ['Resmî hesaba D-Coin ödülü verilemez.']];
        if ((int)$admin['id'] === (int)$post['user_id']) return ['errors' => ['Kendi gönderine ödül veremezsin.']];
        if (!empty($post['award'])) return ['errors' => ['Bu gönderi zaten Editör Ödülü aldı.']];
        $authorUser = function_exists('dg_find_user_by_id') ? dg_find_user_by_id((int)$post['user_id']) : null;
        if (!$authorUser || (int)($authorUser['is_banned'] ?? 0) === 1) {
            return ['errors' => ['Gönderi sahibi ödül almaya uygun değil.']];
        }
        if (!function_exists('dg_adjust_user_balance')) {
            return ['errors' => ['D-Coin çekirdeği yüklenemedi.']];
        }

        return social_with_lock(static function () use ($admin, $postId, $amount, $reason): array {
            $fresh = social_get_post($postId, true);
            if (!$fresh || $fresh['status'] !== 'published' || !empty($fresh['award'])) {
                return ['errors' => ['Bu gönderi artık ödüle uygun değil veya zaten ödül aldı.']];
            }
            $balanceResult = dg_adjust_user_balance((int)$fresh['user_id'], $amount);
            if (empty($balanceResult['ok'])) {
                return ['errors' => [(string)($balanceResult['error'] ?? 'Bakiye güncellenemedi.')]];
            }
            try {
                $award = [
                    'amount' => $amount,
                    'reason' => $reason,
                    'awarded_by' => (int)$admin['id'],
                    'awarded_at' => time(),
                ];
                $fresh['award'] = $award;
                $fresh['updated_at'] = time();
                $fresh = social_normalize_post($fresh);
                social_write_json_atomic(social_post_file($postId), $fresh, false);
                social_index_upsert_locked(social_post_summary($fresh));
                social_notify_locked(
                    (int)$fresh['user_id'],
                    (int)$admin['id'],
                    'award',
                    $postId,
                    0,
                    'Tebrikler! “' . (string)$fresh['title'] . '” Editör Ödülü aldı ve hesabına +' . $amount . ' D-Coin eklendi.',
                    'gonderi.php?id=' . $postId
                );
                return ['errors' => [], 'award' => $fresh['award'], 'balance' => (int)($balanceResult['balance'] ?? 0)];
            } catch (Throwable $exception) {
                try {
                    dg_adjust_user_balance((int)$fresh['user_id'], -$amount);
                } catch (Throwable) {
                    // Geri alma denemesi başarısızsa asıl hata loglanır; tekrar denemek
                    // çift ödüle yol açabileceği için işlem burada durdurulur.
                }
                error_log('[social award] ' . $exception->getMessage());
                return ['errors' => ['Ödül kaydedilemedi; D-Coin işlemi geri alındı.']];
            }
        });
    }

    function social_user_award_stats(int $userId): array
    {
        social_ensure_storage();
        $count = 0;
        $total = 0;
        foreach (social_read_json(social_index_file('user-' . $userId), []) as $item) {
            if (!is_array($item) || ($item['status'] ?? '') !== 'published') continue;
            $award = social_normalize_award($item['award'] ?? null);
            if (!$award) continue;
            $count++;
            $total += (int)$award['amount'];
        }
        return ['count' => $count, 'total' => $total];
    }

    function social_list_posts(array $options = [], ?array $viewer = null): array
    {
        social_ensure_storage();
        $page = max(1, (int)($options['page'] ?? 1));
        $perPage = max(1, min(50, (int)($options['per_page'] ?? 15)));
        $topic = social_slugify((string)($options['topic'] ?? ''));
        $authorId = isset($options['author_id']) ? (int)$options['author_id'] : null;
        $feed = (string)($options['feed'] ?? 'latest');
        $query = trim((string)($options['q'] ?? ''));
        $sort = (string)($options['sort'] ?? 'activity');
        $includeModeration = !empty($options['include_moderation']) && !empty($viewer['is_admin']);
        $blocked = $includeModeration ? [] : social_blocked_ids($viewer);
        $followingIds = null;
        if ($feed === 'following' && $viewer) {
            $followingIds = social_follows((int)$viewer['id'])['following'];
            $followingIds[] = (int)$viewer['id'];
        }
        $savedIds = ($feed === 'saved' && $viewer) ? social_saved_ids((int)$viewer['id']) : null;
        $viewerWatches = $viewer ? social_watches((int)$viewer['id']) : ['posts' => [], 'topics' => [], 'muted_topics' => [], 'muted_tags' => []];
        $watchedIds = ($feed === 'watched' && $viewer) ? (array)$viewerWatches['posts'] : null;
        $watchedTopics = ($feed === 'watched_topics' && $viewer) ? (array)$viewerWatches['topics'] : null;
        $prefix = social_slugify((string)($options['prefix'] ?? ''));
        if ($prefix !== '' && !isset(social_post_prefixes()[$prefix])) $prefix = '__invalid__';
        $solvedFilter = $options['solved'] ?? null;
        $feature = social_slugify((string)($options['feature'] ?? ''));
        $sinceDays = max(0, (int)($options['since_days'] ?? 0));
        $readMap = $viewer ? social_read_map((int)$viewer['id']) : [];
        $items = [];

        if ($feed === 'watched' && $viewer) {
            foreach ((array)$viewerWatches['posts'] as $id) {
                $post = social_get_post((int)$id);
                if ($post) $items[] = social_post_summary($post);
            }
        } elseif ($feed === 'saved' && $viewer) {
            foreach (social_saved_ids((int)$viewer['id']) as $id) {
                $post = social_get_post((int)$id);
                if ($post) $items[] = social_post_summary($post);
            }
        } elseif ($query !== '') {
            $monthFiles = glob(social_dir('indexes') . '/month-*.json') ?: [];
            rsort($monthFiles);
            foreach ($monthFiles as $file) {
                foreach (social_read_json($file, []) as $item) if (is_array($item)) $items[] = $item;
            }
            if (!$items) $items = social_read_json(social_index_file('latest'), []);
        } elseif ($authorId !== null) {
            $items = social_read_json(social_index_file('user-' . $authorId), []);
        } elseif ($topic !== '') {
            $items = social_read_json(social_index_file('topic-' . $topic), []);
        } else {
            $items = social_read_json(social_index_file('latest'), []);
            if ($feed === 'following' && $viewer) {
                $following = social_follows((int)$viewer['id'])['following'];
                $following[] = (int)$viewer['id'];
                $items = array_values(array_filter($items, static fn($item): bool => is_array($item) && in_array((int)($item['user_id'] ?? -1), $following, true)));
            }
        }

        $foldQuery = social_fold($query);
        $isTagQuery = str_starts_with($query, '#');
        if ($isTagQuery) $foldQuery = social_slugify($query);
        $scannedBodies = 0;
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $item = social_post_summary($item);
            if (!$includeModeration && !social_post_visible($item, $viewer, $blocked)) continue;
            if ($includeModeration && $item['status'] === 'deleted') continue;
            if (!empty($options['status']) && $item['status'] !== (string)$options['status']) continue;
            // Kaynak indeks hangi öncelikle seçilirse seçilsin filtreler kesişim olarak uygulanır.
            if ($topic !== '' && (string)$item['topic_slug'] !== $topic) continue;
            if ($authorId !== null && (int)$item['user_id'] !== $authorId) continue;
            if ($followingIds !== null && !in_array((int)$item['user_id'], $followingIds, true) && !in_array((string)$item['topic_slug'], (array)$viewerWatches['topics'], true)) continue;
            if ($savedIds !== null && !in_array((int)$item['id'], $savedIds, true)) continue;
            if ($watchedIds !== null && !in_array((int)$item['id'], $watchedIds, true)) continue;
            if ($watchedTopics !== null && !in_array((string)$item['topic_slug'], $watchedTopics, true)) continue;
            if ($feed === 'unread' && $viewer && !social_post_is_unread($item, $viewer, $readMap)) continue;
            if (!empty($options['unread_only']) && $viewer && !social_post_is_unread($item, $viewer, $readMap)) continue;
            if ($prefix !== '' && (string)($item['prefix'] ?? '') !== $prefix) continue;
            if ($solvedFilter !== null && $solvedFilter !== '') {
                $isSolved = (int)($item['solution_comment_id'] ?? 0) > 0;
                if ((bool)$solvedFilter !== $isSolved) continue;
            }
            if ($feature === 'anket' && empty($item['poll'])) continue;
            if ($feature === 'etkinlik' && !social_is_event_post($item)) continue;
            if ($feature === 'odul' && empty($item['award'])) continue;
            if ($feature === 'gorsel' && trim((string)($item['image'] ?? '')) === '') continue;
            if ($feature === 'yorumsuz' && (int)$item['comment_count'] > 0) continue;
            if ($feature === 'cozuldu' && (int)($item['solution_comment_id'] ?? 0) <= 0) continue;
            if ($sinceDays > 0 && (int)$item['created_at'] < time() - ($sinceDays * 86400)) continue;
            if ($viewer && !$includeModeration && empty($viewer['is_admin']) && (int)$item['user_id'] !== (int)$viewer['id']) {
                if ($topic === '' && in_array((string)$item['topic_slug'], (array)$viewerWatches['muted_topics'], true)) continue;
                if (!$isTagQuery && array_intersect((array)$item['tags'], (array)$viewerWatches['muted_tags'])) continue;
            }
            if ($query !== '') {
                $matched = false;
                if ($isTagQuery) {
                    $matched = in_array($foldQuery, $item['tags'], true);
                    if (!$matched && $scannedBodies < 750) {
                        $scannedBodies++;
                        $full = social_get_post((int)$item['id']);
                        if ($full && preg_match_all('/#([A-Za-z0-9_\-\x{00C0}-\x{024F}]+)/u', (string)$full['body'], $tagMatches)) {
                            foreach ($tagMatches[1] as $bodyTag) {
                                if (social_slugify((string)$bodyTag) === $foldQuery) { $matched = true; break; }
                            }
                        }
                    }
                } else {
                    $pollLabels = [];
                    foreach ((array)(($item['poll'] ?? [])['options'] ?? []) as $option) {
                        if (is_array($option)) $pollLabels[] = (string)($option['label'] ?? '');
                    }
                    $haystack = social_fold(implode(' ', [
                        $item['title'],
                        $item['excerpt'],
                        $item['author_username'],
                        $item['author_display'],
                        implode(' ', $item['tags']),
                        implode(' ', array_map('strval', (array)$item['meta'])),
                        implode(' ', $pollLabels),
                        (string)(social_post_prefixes()[(string)($item['prefix'] ?? '')] ?? ''),
                        (string)(($item['award'] ?? [])['reason'] ?? ''),
                    ]));
                    $matched = str_contains($haystack, $foldQuery);
                    if (!$matched && $scannedBodies < 750) {
                        $scannedBodies++;
                        $full = social_get_post((int)$item['id']);
                        if ($full) $matched = str_contains(social_fold((string)$full['body']), $foldQuery);
                    }
                }
                if (!$matched) continue;
            }
            $filtered[] = $item;
        }

        if ($sort === 'new') {
            usort($filtered, static fn(array $a, array $b): int => [(int)!empty($b['pinned']), (int)$b['created_at'], (int)$b['id']] <=> [(int)!empty($a['pinned']), (int)$a['created_at'], (int)$a['id']]);
        } elseif ($sort === 'popular') {
            $score = static function (array $item): float {
                $ageHours = max(0, (time() - (int)$item['created_at']) / 3600);
                $engagement = (int)$item['gg_count'] * 4
                    + (int)$item['comment_count'] * 3
                    + (int)(($item['poll'] ?? [])['total_votes'] ?? 0) * 2
                    + (int)($item['rsvp_count'] ?? 0) * 2
                    + (!empty($item['award']) ? 6 : 0)
                    + 1;
                return $engagement / pow($ageHours + 2, 0.35);
            };
            usort($filtered, static fn(array $a, array $b): int => [$score($b), (int)$b['last_activity_at']] <=> [$score($a), (int)$a['last_activity_at']]);
        } elseif ($sort === 'comments') {
            usort($filtered, static fn(array $a, array $b): int => [(int)$b['comment_count'], (int)$b['last_activity_at'], (int)$b['id']] <=> [(int)$a['comment_count'], (int)$a['last_activity_at'], (int)$a['id']]);
        } elseif ($sort === 'views') {
            usort($filtered, static fn(array $a, array $b): int => [(int)$b['view_count'], (int)$b['last_activity_at'], (int)$b['id']] <=> [(int)$a['view_count'], (int)$a['last_activity_at'], (int)$a['id']]);
        } elseif ($sort === 'gg') {
            usort($filtered, static fn(array $a, array $b): int => [(int)$b['gg_count'], (int)$b['comment_count'], (int)$b['last_activity_at']] <=> [(int)$a['gg_count'], (int)$a['comment_count'], (int)$a['last_activity_at']]);
        } else {
            $filtered = social_sort_summaries($filtered);
        }

        $total = count($filtered);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        return [
            'items' => array_values($slice),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    function social_normalize_comment(array $comment): array
    {
        $created = (int)($comment['created_at'] ?? time());
        return [
            'schema_version' => 1,
            'id' => (int)($comment['id'] ?? 0),
            'post_id' => (int)($comment['post_id'] ?? 0),
            'parent_id' => (int)($comment['parent_id'] ?? 0),
            'user_id' => (int)($comment['user_id'] ?? 0),
            'author_username' => (string)($comment['author_username'] ?? ''),
            'author_display' => (string)($comment['author_display'] ?? ''),
            'body' => social_clean_text((string)($comment['body'] ?? ''), 1500),
            'status' => in_array(($comment['status'] ?? 'published'), ['published', 'pending', 'hidden', 'deleted'], true) ? (string)$comment['status'] : 'published',
            'gg_count' => max(0, (int)($comment['gg_count'] ?? 0)),
            'notified' => !empty($comment['notified']),
            'created_at' => $created,
            'updated_at' => (int)($comment['updated_at'] ?? $created),
            'edited_at' => (int)($comment['edited_at'] ?? 0),
        ];
    }

    function social_comment_map(int $postId): array
    {
        $map = [];
        foreach (social_read_jsonl(social_comments_file($postId)) as $row) {
            $comment = social_normalize_comment($row);
            if ($comment['id'] > 0 && $comment['post_id'] === $postId) $map[$comment['id']] = $comment;
        }
        return $map;
    }

    function social_comment_summary(array $comment, array $post): array
    {
        return [
            'id' => (int)$comment['id'],
            'post_id' => (int)$comment['post_id'],
            'post_title' => (string)($post['title'] ?? ''),
            'parent_id' => (int)$comment['parent_id'],
            'user_id' => (int)$comment['user_id'],
            'author_username' => (string)$comment['author_username'],
            'author_display' => (string)$comment['author_display'],
            'excerpt' => social_excerpt((string)$comment['body'], 220),
            'gg_count' => max(0, (int)($comment['gg_count'] ?? 0)),
            'is_solution' => (int)($post['solution_comment_id'] ?? 0) === (int)$comment['id'],
            'status' => (string)$comment['status'],
            'created_at' => (int)$comment['created_at'],
            'updated_at' => (int)$comment['updated_at'],
        ];
    }

    function social_comment_index_upsert_locked(array $comment, array $post): void
    {
        $summary = social_comment_summary($comment, $post);
        $remove = $comment['status'] === 'deleted';
        $userFile = social_index_file('user-comments-' . (int)$comment['user_id']);
        $items = array_values(array_filter(social_read_json($userFile, []), static fn($item): bool => is_array($item) && (int)($item['id'] ?? 0) !== (int)$comment['id']));
        if (!$remove) $items[] = $summary;
        usort($items, static fn(array $a, array $b): int => [(int)$b['created_at'], (int)$b['id']] <=> [(int)$a['created_at'], (int)$a['id']]);
        social_write_json_atomic($userFile, array_slice($items, 0, 500), false);

        $modFile = social_index_file('moderation-comments');
        $items = array_values(array_filter(social_read_json($modFile, []), static fn($item): bool => is_array($item) && (int)($item['id'] ?? 0) !== (int)$comment['id']));
        if (in_array($comment['status'], ['pending', 'hidden'], true)) $items[] = $summary;
        usort($items, static fn(array $a, array $b): int => [(int)$b['created_at'], (int)$b['id']] <=> [(int)$a['created_at'], (int)$a['id']]);
        social_write_json_atomic($modFile, array_slice($items, 0, 1000), false);
    }

    function social_sync_profile_comment_counts_locked(int $userId): void
    {
        $profile = social_get_profile($userId);
        if (!$profile) return;
        $items = social_read_json(social_index_file('user-comments-' . $userId), []);
        $published = 0;
        foreach ($items as $item) if (($item['status'] ?? '') === 'published') $published++;
        $profile['comment_count'] = $published;
        $profile['approved_comments'] = $published;
        social_maybe_promote_locked($profile);
    }

    function social_refresh_post_comment_count_locked(array $post): array
    {
        $count = 0;
        $latest = (int)$post['created_at'];
        foreach (social_comment_map((int)$post['id']) as $comment) {
            if ($comment['status'] === 'published') {
                $count++;
                $latest = max($latest, (int)$comment['created_at']);
            }
        }
        $post['comment_count'] = $count;
        if ($count > 0) $post['last_activity_at'] = max((int)$post['last_activity_at'], $latest);
        $post = social_normalize_post($post);
        social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
        social_index_upsert_locked(social_post_summary($post));
        return $post;
    }

    function social_get_comment(int $postId, int $commentId): ?array
    {
        social_ensure_storage();
        return social_comment_map($postId)[$commentId] ?? null;
    }

    function social_comment_visible(array $comment, ?array $viewer, array $blockedIds = []): bool
    {
        if (($comment['status'] ?? '') === 'deleted') return false;
        $isAdmin = !empty($viewer['is_admin']);
        if (!$isAdmin && in_array((int)($comment['user_id'] ?? -1), $blockedIds, true)) return false;
        if (($comment['status'] ?? '') === 'published') return true;
        if ($isAdmin) return true;
        return $viewer && (int)$viewer['id'] === (int)($comment['user_id'] ?? 0);
    }

    function social_comments(int $postId, ?array $viewer = null, string $sortMode = 'old'): array
    {
        social_ensure_storage();
        $blocked = social_blocked_ids($viewer);
        $post = social_get_post($postId, true);
        $solutionId = (int)($post['solution_comment_id'] ?? 0);
        $reactions = social_reactions($postId);
        $viewerId = $viewer ? (int)$viewer['id'] : 0;
        $all = [];
        foreach (social_comment_map($postId) as $comment) {
            if (!social_comment_visible($comment, $viewer, $blocked)) continue;
            $commentId = (int)$comment['id'];
            $comment['gg_count'] = count((array)(($reactions['comment_gg'] ?? [])[(string)$commentId] ?? []));
            $comment['viewer_gg'] = $viewer && in_array($viewerId, (array)(($reactions['comment_gg'] ?? [])[(string)$commentId] ?? []), true);
            $comment['is_solution'] = $commentId === $solutionId;
            $all[$commentId] = $comment;
        }
        $roots = [];
        $replies = [];
        foreach ($all as $comment) {
            $parentId = (int)$comment['parent_id'];
            if ($parentId > 0 && isset($all[$parentId]) && (int)$all[$parentId]['parent_id'] === 0) {
                $replies[$parentId][] = $comment;
            } else {
                $comment['parent_id'] = 0;
                $roots[] = $comment;
            }
        }
        $oldSort = static fn(array $a, array $b): int => [(int)$a['created_at'], (int)$a['id']] <=> [(int)$b['created_at'], (int)$b['id']];
        $rootSort = match ($sortMode) {
            'new' => static fn(array $a, array $b): int => [(int)!empty($b['is_solution']), (int)$b['created_at'], (int)$b['id']] <=> [(int)!empty($a['is_solution']), (int)$a['created_at'], (int)$a['id']],
            'top' => static fn(array $a, array $b): int => [(int)!empty($b['is_solution']), (int)$b['gg_count'], (int)$a['created_at'], (int)$a['id']] <=> [(int)!empty($a['is_solution']), (int)$a['gg_count'], (int)$b['created_at'], (int)$b['id']],
            default => static fn(array $a, array $b): int => [(int)!empty($b['is_solution']), (int)$a['created_at'], (int)$a['id']] <=> [(int)!empty($a['is_solution']), (int)$b['created_at'], (int)$b['id']],
        };
        usort($roots, $rootSort);
        foreach ($roots as $i => $root) {
            $items = $replies[(int)$root['id']] ?? [];
            usort($items, $oldSort);
            $roots[$i]['replies'] = $items;
        }
        return $roots;
    }

    function social_notify_comment_locked(array $post, array $comment): void
    {
        $actorId = (int)$comment['user_id'];
        $actorName = (string)($comment['author_display'] ?: $comment['author_username']);
        $url = 'gonderi.php?id=' . (int)$post['id'] . '&yorum=' . (int)$comment['id'] . '#yorum-' . (int)$comment['id'];
        $parentAuthorId = 0;
        if ((int)$comment['parent_id'] > 0) {
            $parent = social_get_comment((int)$post['id'], (int)$comment['parent_id']);
            if ($parent) {
                $parentAuthorId = (int)$parent['user_id'];
                social_notify_locked($parentAuthorId, $actorId, 'reply', (int)$post['id'], (int)$comment['id'], $actorName . ' yorumuna cevap verdi: “' . (string)$post['title'] . '”', $url);
            }
        }
        // Cevap verilen kişi gönderi sahibiyse aynı olay için ikinci bildirim üretme.
        if ((int)$post['user_id'] !== $parentAuthorId) {
            social_notify_locked((int)$post['user_id'], $actorId, 'comment', (int)$post['id'], (int)$comment['id'], $actorName . ' gönderine yorum yaptı: “' . (string)$post['title'] . '”', $url);
        }
        $mentionedIds = social_extract_mentions((string)$comment['body']);
        social_notify_mentions_locked($post, $actorId, (string)$comment['body'], (int)$comment['id']);
        social_notify_post_watchers_locked($post, $comment, array_merge([(int)$post['user_id'], $parentAuthorId], $mentionedIds));
    }

    function social_create_comment(array $user, int $postId, array $input): array
    {
        social_ensure_storage();
        $post = social_get_post($postId);
        if (!$post) return ['errors' => ['Gönderi bulunamadı.'], 'id' => 0, 'status' => ''];
        if (!social_post_visible($post, $user, social_blocked_ids($user))) return ['errors' => ['Bu gönderiye yorum yapamazsın.'], 'id' => 0, 'status' => ''];
        if (!empty($post['locked']) && empty($user['is_admin'])) return ['errors' => ['Bu konu yorumlara kilitlenmiş.'], 'id' => 0, 'status' => ''];
        $body = social_clean_text((string)($input['body'] ?? ''), 1500);
        if (social_text_len($body) < 1) return ['errors' => ['Yorum boş olamaz.'], 'id' => 0, 'status' => ''];
        $parentId = (int)($input['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parent = social_get_comment($postId, $parentId);
            if (!$parent || !social_comment_visible($parent, $user, social_blocked_ids($user))) {
                return ['errors' => ['Cevap verilecek yorum bulunamadı.'], 'id' => 0, 'status' => ''];
            }
            if ((int)$parent['parent_id'] > 0) $parentId = (int)$parent['parent_id'];
        }
        $profile = social_ensure_profile($user);
        if (($muteError = social_muted_error($profile, $user)) !== '') return ['errors' => [$muteError], 'id' => 0, 'status' => ''];
        return social_with_lock(static function () use ($user, $profile, $post, $body, $parentId): array {
            $userId = (int)$user['id'];
            if (empty($user['is_admin']) && !social_rate_limit_locked($userId, 'comments', [[3600, 30], [86400, 150]])) {
                return ['errors' => ['Çok hızlı yorum yapıyorsun. Biraz bekleyip tekrar dene.'], 'id' => 0, 'status' => ''];
            }
            $trusted = social_is_trusted($profile, $user) || (int)$profile['approved_comments'] >= 5;
            $status = (!empty($user['is_admin']) || ($trusted && social_url_count($body) < 3)) ? 'published' : 'pending';
            $now = time();
            $comment = social_normalize_comment([
                'id' => social_next_id_locked('comments'),
                'post_id' => (int)$post['id'],
                'parent_id' => $parentId,
                'user_id' => $userId,
                'author_username' => (string)$profile['username'],
                'author_display' => (string)$profile['display_name'],
                'body' => $body,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            social_append_jsonl(social_comments_file((int)$post['id']), $comment);
            social_comment_index_upsert_locked($comment, $post);
            social_sync_profile_comment_counts_locked($userId);
            $prefs = social_normalize_notification_prefs($profile['notification_prefs'] ?? []);
            if (!empty($prefs['auto_watch'])) social_watch_post_locked($userId, (int)$post['id'], true);
            $freshPost = social_refresh_post_comment_count_locked(social_get_post((int)$post['id'], true) ?: $post);
            if ($status === 'published') {
                social_notify_comment_locked($freshPost, $comment);
                $comment['notified'] = true;
                $comment['updated_at'] = time();
                social_append_jsonl(social_comments_file((int)$post['id']), $comment);
                social_comment_index_upsert_locked($comment, $freshPost);
            }
            return ['errors' => [], 'id' => (int)$comment['id'], 'status' => $status];
        });
    }

    function social_edit_comment(array $actor, int $postId, int $commentId, array $input): array
    {
        $comment = social_get_comment($postId, $commentId);
        $post = social_get_post($postId, true);
        if (!$comment || !$post || $comment['status'] === 'deleted') return ['errors' => ['Yorum bulunamadı.'], 'status' => ''];
        $isAdmin = !empty($actor['is_admin']);
        if (!$isAdmin && (int)$actor['id'] !== (int)$comment['user_id']) return ['errors' => ['Bu yorumu düzenleyemezsin.'], 'status' => (string)$comment['status']];
        if (!$isAdmin) {
            $actorProfile = social_get_profile((int)$actor['id'], $actor);
            if ($actorProfile && ($muteError = social_muted_error($actorProfile, $actor)) !== '') return ['errors' => [$muteError], 'status' => (string)$comment['status']];
        }
        $body = social_clean_text((string)($input['body'] ?? ''), 1500);
        if (social_text_len($body) < 1) return ['errors' => ['Yorum boş olamaz.'], 'status' => (string)$comment['status']];
        return social_with_lock(static function () use ($comment, $post, $body, $isAdmin, $actor): array {
            $status = (string)$comment['status'];
            if (!$isAdmin && $status === 'published') {
                $profile = social_get_profile((int)$comment['user_id']);
                if ($profile && !social_is_trusted($profile, $actor) && social_url_count($body) > 0) $status = 'pending';
            }
            $comment['body'] = $body;
            $comment['status'] = $status;
            $comment['updated_at'] = time();
            $comment['edited_at'] = time();
            $comment = social_normalize_comment($comment);
            social_append_jsonl(social_comments_file((int)$post['id']), $comment);
            social_comment_index_upsert_locked($comment, $post);
            social_sync_profile_comment_counts_locked((int)$comment['user_id']);
            social_refresh_post_comment_count_locked(social_get_post((int)$post['id'], true) ?: $post);
            return ['errors' => [], 'status' => $status];
        });
    }

    function social_set_comment_status(array $actor, int $postId, int $commentId, string $status): array
    {
        if (!in_array($status, ['published', 'pending', 'hidden', 'deleted'], true)) return ['errors' => ['Geçersiz yorum durumu.']];
        $comment = social_get_comment($postId, $commentId);
        $post = social_get_post($postId, true);
        if (!$comment || !$post) return ['errors' => ['Yorum bulunamadı.']];
        $isAdmin = !empty($actor['is_admin']);
        $isAuthor = (int)$actor['id'] === (int)$comment['user_id'];
        if (!$isAdmin && !($isAuthor && $status === 'deleted')) return ['errors' => ['Bu işlem için yetkin yok.']];
        return social_with_lock(static function () use ($actor, $comment, $post, $status): array {
            $wasPublished = $comment['status'] === 'published';
            $comment['status'] = $status;
            $comment['updated_at'] = time();
            $comment = social_normalize_comment($comment);
            social_append_jsonl(social_comments_file((int)$post['id']), $comment);
            if ($status !== 'published' && (int)($post['solution_comment_id'] ?? 0) === (int)$comment['id']) {
                $post['solution_comment_id'] = 0;
                $post['updated_at'] = time();
                $post = social_normalize_post($post);
                social_write_json_atomic(social_post_file((int)$post['id']), $post, false);
                social_index_upsert_locked(social_post_summary($post));
            }
            social_comment_index_upsert_locked($comment, $post);
            social_sync_profile_comment_counts_locked((int)$comment['user_id']);
            $freshPost = social_refresh_post_comment_count_locked(social_get_post((int)$post['id'], true) ?: $post);
            if (!$wasPublished && $status === 'published' && empty($comment['notified'])) {
                social_notify_comment_locked($freshPost, $comment);
                $comment['notified'] = true;
                $comment['updated_at'] = time();
                social_append_jsonl(social_comments_file((int)$post['id']), $comment);
                social_comment_index_upsert_locked($comment, $freshPost);
            }
            if (!empty($actor['is_admin'])) {
                social_log_moderation_locked($actor, 'comment_status_' . $status, 'comment', (int)$comment['id'], (int)$post['id'], 'Yorum durumu: ' . $status);
            }
            return ['errors' => [], 'status' => $status];
        });
    }

    function social_reactions(int $postId): array
    {
        social_ensure_storage();
        $data = social_read_json(social_reactions_file($postId), ['gg' => [], 'comment_gg' => [], 'poll_votes' => [], 'rsvp' => [], 'watchers' => []]);
        $gg = array_values(array_unique(array_map('intval', (array)($data['gg'] ?? []))));
        $commentGg = [];
        foreach ((array)($data['comment_gg'] ?? []) as $commentId => $ids) {
            $commentId = (int)$commentId;
            if ($commentId <= 0 || !is_array($ids)) continue;
            $clean = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
            if ($clean) $commentGg[(string)$commentId] = $clean;
        }
        $pollVotes = [];
        foreach ((array)($data['poll_votes'] ?? []) as $userId => $optionId) {
            $userId = (int)$userId;
            $optionId = strtolower(trim((string)$optionId));
            if ($userId > 0 && preg_match('/^o[1-5]$/', $optionId)) {
                $pollVotes[(string)$userId] = $optionId;
            }
        }
        $rsvp = array_values(array_unique(array_filter(
            array_map('intval', (array)($data['rsvp'] ?? [])),
            static fn(int $id): bool => $id >= 0
        )));
        $watchers = array_values(array_unique(array_filter(
            array_map('intval', (array)($data['watchers'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        return ['gg' => $gg, 'comment_gg' => $commentGg, 'poll_votes' => $pollVotes, 'rsvp' => $rsvp, 'watchers' => $watchers];
    }

    function social_toggle_gg(array $user, int $postId): array
    {
        $post = social_get_post($postId);
        if (!$post || !social_post_visible($post, $user, social_blocked_ids($user))) return ['errors' => ['Gönderi bulunamadı.'], 'active' => false, 'count' => 0];
        if ((int)$user['id'] === (int)$post['user_id']) return ['errors' => ['Kendi gönderine GG veremezsin.'], 'active' => false, 'count' => (int)$post['gg_count']];
        social_ensure_profile($user);
        return social_with_lock(static function () use ($user, $post): array {
            $userId = (int)$user['id'];
            $reactions = social_reactions((int)$post['id']);
            $active = in_array($userId, $reactions['gg'], true);
            $reactions['gg'] = $active
                ? array_values(array_filter($reactions['gg'], static fn(int $id): bool => $id !== $userId))
                : array_merge($reactions['gg'], [$userId]);
            social_write_json_atomic(social_reactions_file((int)$post['id']), $reactions, false);
            $fresh = social_get_post((int)$post['id'], true) ?: $post;
            $fresh['gg_count'] = count($reactions['gg']);
            $fresh = social_normalize_post($fresh);
            social_write_json_atomic(social_post_file((int)$post['id']), $fresh, false);
            social_index_upsert_locked(social_post_summary($fresh));
            return ['errors' => [], 'active' => !$active, 'count' => count($reactions['gg'])];
        });
    }

    function social_toggle_comment_gg(array $user, int $postId, int $commentId): array
    {
        $post = social_get_post($postId);
        $comment = social_get_comment($postId, $commentId);
        if (!$post || !$comment || !social_post_visible($post, $user, social_blocked_ids($user)) || !social_comment_visible($comment, $user, social_blocked_ids($user))) {
            return ['errors' => ['Yorum bulunamadı.'], 'active' => false, 'count' => 0];
        }
        if (($comment['status'] ?? '') !== 'published') return ['errors' => ['Yalnızca yayındaki yoruma GG verilebilir.'], 'active' => false, 'count' => 0];
        if ((int)$user['id'] === (int)$comment['user_id']) return ['errors' => ['Kendi yorumuna GG veremezsin.'], 'active' => false, 'count' => 0];
        social_ensure_profile($user);
        return social_with_lock(static function () use ($user, $post, $comment, $commentId): array {
            $userId = (int)$user['id'];
            $reactions = social_reactions((int)$post['id']);
            $ids = (array)(($reactions['comment_gg'] ?? [])[(string)$commentId] ?? []);
            $active = in_array($userId, $ids, true);
            $ids = $active
                ? array_values(array_filter($ids, static fn(int $id): bool => $id !== $userId))
                : array_merge($ids, [$userId]);
            if ($ids) $reactions['comment_gg'][(string)$commentId] = $ids;
            else unset($reactions['comment_gg'][(string)$commentId]);
            social_write_json_atomic(social_reactions_file((int)$post['id']), $reactions, false);
            $comment['gg_count'] = count($ids);
            $comment['updated_at'] = time();
            $comment = social_normalize_comment($comment);
            social_append_jsonl(social_comments_file((int)$post['id']), $comment);
            social_comment_index_upsert_locked($comment, social_get_post((int)$post['id'], true) ?: $post);
            return ['errors' => [], 'active' => !$active, 'count' => count($ids)];
        });
    }

    function social_set_solution(array $actor, int $postId, int $commentId): array
    {
        $post = social_get_post($postId);
        $comment = social_get_comment($postId, $commentId);
        if (!$post || !$comment) return ['errors' => ['Çözüm olarak işaretlenecek yorum bulunamadı.'], 'active' => false];
        $isAdmin = !empty($actor['is_admin']);
        $isAuthor = (int)$actor['id'] === (int)$post['user_id'];
        if (!$isAdmin && !$isAuthor) return ['errors' => ['Çözümü yalnızca konu sahibi veya yönetici işaretleyebilir.'], 'active' => false];
        if (($comment['status'] ?? '') !== 'published') return ['errors' => ['Yalnızca yayındaki bir yorum çözüm olabilir.'], 'active' => false];
        if ((int)$comment['user_id'] === (int)$post['user_id'] && !$isAdmin) return ['errors' => ['Kendi cevabını çözüm olarak işaretleyemezsin.'], 'active' => false];
        return social_with_lock(static function () use ($actor, $postId, $commentId): array {
            $fresh = social_get_post($postId, true);
            $freshComment = social_get_comment($postId, $commentId);
            if (!$fresh || !$freshComment || ($freshComment['status'] ?? '') !== 'published') {
                return ['errors' => ['Yorum artık çözüm olarak işaretlenemiyor.'], 'active' => false];
            }
            $active = (int)($fresh['solution_comment_id'] ?? 0) !== $commentId;
            $previousSolutionId = (int)($fresh['solution_comment_id'] ?? 0);
            $fresh['solution_comment_id'] = $active ? $commentId : 0;
            $fresh['updated_at'] = time();
            $fresh = social_normalize_post($fresh);
            social_write_json_atomic(social_post_file($postId), $fresh, false);
            social_index_upsert_locked(social_post_summary($fresh));
            if ($previousSolutionId > 0 && $previousSolutionId !== $commentId) {
                $previous = social_get_comment($postId, $previousSolutionId);
                if ($previous) social_comment_index_upsert_locked($previous, $fresh);
            }
            $freshComment['gg_count'] = count((array)(social_reactions($postId)['comment_gg'][(string)$commentId] ?? []));
            social_comment_index_upsert_locked($freshComment, $fresh);
            if ($active) {
                social_notify_locked(
                    (int)$freshComment['user_id'],
                    (int)$actor['id'],
                    'solution',
                    $postId,
                    $commentId,
                    'Cevabın kabul edilen çözüm seçildi: “' . (string)$fresh['title'] . '”',
                    'gonderi.php?id=' . $postId . '&yorum=' . $commentId . '#yorum-' . $commentId
                );
            }
            return ['errors' => [], 'active' => $active, 'solution_comment_id' => $active ? $commentId : 0];
        });
    }

    function social_saved_ids(int $userId): array
    {
        social_ensure_storage();
        $data = social_read_json(social_saves_file($userId), []);
        return array_values(array_unique(array_map('intval', (array)($data['post_ids'] ?? []))));
    }

    function social_toggle_save(array $user, int $postId): array
    {
        $post = social_get_post($postId);
        if (!$post || !social_post_visible($post, $user, social_blocked_ids($user))) return ['errors' => ['Gönderi bulunamadı.'], 'active' => false];
        return social_with_lock(static function () use ($user, $postId): array {
            $ids = social_saved_ids((int)$user['id']);
            $active = in_array($postId, $ids, true);
            $ids = $active ? array_values(array_filter($ids, static fn(int $id): bool => $id !== $postId)) : array_merge([$postId], $ids);
            social_write_json_atomic(social_saves_file((int)$user['id']), ['post_ids' => array_slice($ids, 0, 500), 'updated_at' => time()], false);
            return ['errors' => [], 'active' => !$active];
        });
    }

    function social_vote_poll(array $user, int $postId, string $optionId): array
    {
        $optionId = strtolower(trim($optionId));
        $post = social_get_post($postId);
        if (!$post || !social_post_visible($post, $user, social_blocked_ids($user))) {
            return ['errors' => ['Gönderi bulunamadı.'], 'poll' => null];
        }
        $poll = social_normalize_poll($post['poll'] ?? null);
        if (!$poll) return ['errors' => ['Bu gönderide anket yok.'], 'poll' => null];
        if (($post['status'] ?? '') !== 'published') return ['errors' => ['Anket yayına girdikten sonra oy kullanılabilir.'], 'poll' => social_poll_payload($post, $user, social_reactions($postId))];
        if (social_poll_closed($poll)) return ['errors' => ['Bu anket kapandı.'], 'poll' => social_poll_payload($post, $user, social_reactions($postId))];
        $valid = false;
        foreach ($poll['options'] as $option) if ((string)$option['id'] === $optionId) $valid = true;
        if (!$valid) return ['errors' => ['Geçerli bir anket seçeneği seç.'], 'poll' => social_poll_payload($post, $user, social_reactions($postId))];
        social_ensure_profile($user);

        return social_with_lock(static function () use ($user, $postId, $optionId): array {
            $fresh = social_get_post($postId, true);
            if (!$fresh || ($fresh['status'] ?? '') !== 'published') return ['errors' => ['Anket şu anda oy kullanmaya kapalı.'], 'poll' => null];
            $freshPoll = social_normalize_poll($fresh['poll'] ?? null);
            $reactions = social_reactions($postId);
            if (!$freshPoll || social_poll_closed($freshPoll)) {
                return ['errors' => ['Bu anket kapandı.'], 'poll' => $freshPoll ? social_poll_payload($fresh, $user, $reactions) : null];
            }
            $valid = false;
            foreach ($freshPoll['options'] as $option) if ((string)$option['id'] === $optionId) $valid = true;
            if (!$valid) return ['errors' => ['Geçerli bir anket seçeneği seç.'], 'poll' => social_poll_payload($fresh, $user, $reactions)];

            $userKey = (string)(int)$user['id'];
            $previous = (string)($reactions['poll_votes'][$userKey] ?? '');
            if ($previous !== $optionId) {
                if (empty($user['is_admin']) && !social_rate_limit_locked((int)$user['id'], 'poll_votes', [[3600, 60], [86400, 300]])) {
                    return ['errors' => ['Çok sık oy değiştirdin. Biraz bekleyip tekrar dene.'], 'poll' => social_poll_payload($fresh, $user, $reactions)];
                }
                $reactions['poll_votes'][$userKey] = $optionId;
                social_write_json_atomic(social_reactions_file($postId), $reactions, false);
                $fresh['poll'] = social_poll_with_counts($freshPoll, $reactions);
                $fresh = social_normalize_post($fresh);
                social_write_json_atomic(social_post_file($postId), $fresh, false);
                social_index_upsert_locked(social_post_summary($fresh));
            }
            return ['errors' => [], 'poll' => social_poll_payload($fresh, $user, $reactions), 'selected' => $optionId];
        });
    }

    function social_event_attendee_ids(int $postId): array
    {
        return social_reactions($postId)['rsvp'];
    }

    function social_event_attendees(int $postId, ?array $viewer = null, int $limit = 12): array
    {
        $blocked = !empty($viewer['is_admin']) ? [] : social_blocked_ids($viewer);
        $ids = array_values(array_filter(
            social_event_attendee_ids($postId),
            static fn(int $id): bool => !in_array($id, $blocked, true)
        ));
        $profiles = social_profiles_by_ids(array_slice($ids, 0, max(1, $limit)));
        $out = [];
        foreach (array_slice($ids, 0, max(1, $limit)) as $id) {
            if (isset($profiles[$id])) $out[] = $profiles[$id];
        }
        return $out;
    }

    function social_toggle_event_rsvp(array $user, int $postId): array
    {
        $post = social_get_post($postId);
        if (!$post || !social_post_visible($post, $user, social_blocked_ids($user))) {
            return ['errors' => ['Etkinlik bulunamadı.'], 'active' => false, 'count' => 0];
        }
        if (!social_is_event_post($post)) return ['errors' => ['Bu gönderi bir etkinlik değil.'], 'active' => false, 'count' => 0];
        if (($post['status'] ?? '') !== 'published') return ['errors' => ['Etkinlik yayına girdikten sonra katılım açılır.'], 'active' => false, 'count' => (int)$post['rsvp_count']];
        social_ensure_profile($user);

        return social_with_lock(static function () use ($user, $postId): array {
            $fresh = social_get_post($postId, true);
            if (!$fresh || !social_is_event_post($fresh) || ($fresh['status'] ?? '') !== 'published') {
                return ['errors' => ['Etkinlik şu anda katılıma kapalı.'], 'active' => false, 'count' => 0];
            }
            $userId = (int)$user['id'];
            $reactions = social_reactions($postId);
            $active = in_array($userId, $reactions['rsvp'], true);
            $capacity = (int)(($fresh['meta'] ?? [])['event_capacity'] ?? 0);
            if (!$active) {
                if (!social_event_is_open($fresh)) {
                    return ['errors' => ['Bu etkinliğin katılım süresi doldu.'], 'active' => false, 'count' => count($reactions['rsvp'])];
                }
                if ($capacity > 0 && count($reactions['rsvp']) >= $capacity) {
                    return ['errors' => ['Etkinlik kapasitesi doldu.'], 'active' => false, 'count' => count($reactions['rsvp'])];
                }
                if (empty($user['is_admin']) && !social_rate_limit_locked($userId, 'rsvps', [[3600, 30], [86400, 100]])) {
                    return ['errors' => ['Çok sık katılım değiştirdin. Biraz bekleyip tekrar dene.'], 'active' => false, 'count' => count($reactions['rsvp'])];
                }
                $reactions['rsvp'][] = $userId;
            } else {
                $reactions['rsvp'] = array_values(array_filter($reactions['rsvp'], static fn(int $id): bool => $id !== $userId));
            }
            social_write_json_atomic(social_reactions_file($postId), $reactions, false);
            $fresh['rsvp_count'] = count($reactions['rsvp']);
            $fresh = social_normalize_post($fresh);
            social_write_json_atomic(social_post_file($postId), $fresh, false);
            social_index_upsert_locked(social_post_summary($fresh));
            if (!$active && (int)$fresh['user_id'] > 0 && (int)$fresh['user_id'] !== $userId) {
                $profile = social_get_profile($userId, $user);
                $name = (string)($profile['display_name'] ?? $user['username'] ?? 'Bir oyuncu');
                social_notify_locked((int)$fresh['user_id'], $userId, 'event_rsvp', $postId, 0, $name . ' etkinliğine katılıyor: “' . (string)$fresh['title'] . '”', 'gonderi.php?id=' . $postId . '#etkinlik');
            }
            return [
                'errors' => [],
                'active' => !$active,
                'count' => count($reactions['rsvp']),
                'capacity' => $capacity,
                'event_at' => (int)(($fresh['meta'] ?? [])['event_at'] ?? 0),
            ];
        });
    }

    function social_post_states(array $postIds, ?array $viewer): array
    {
        $states = [];
        $saved = $viewer ? array_fill_keys(social_saved_ids((int)$viewer['id']), true) : [];
        $readMap = $viewer ? social_read_map((int)$viewer['id']) : [];
        $watched = $viewer ? array_fill_keys(social_watches((int)$viewer['id'])['posts'], true) : [];
        foreach ($postIds as $id) {
            $id = (int)$id;
            $reactions = $viewer ? social_reactions($id) : ['gg' => [], 'poll_votes' => [], 'rsvp' => [], 'watchers' => []];
            $viewerId = $viewer ? (int)$viewer['id'] : 0;
            $summary = social_get_post($id, true);
            $states[$id] = [
                'gg' => $viewer ? in_array($viewerId, $reactions['gg'], true) : false,
                'saved' => isset($saved[$id]),
                'poll_vote' => $viewer ? (string)($reactions['poll_votes'][(string)$viewerId] ?? '') : '',
                'rsvp' => $viewer ? in_array($viewerId, $reactions['rsvp'], true) : false,
                'watching' => isset($watched[$id]) || ($viewer && in_array($viewerId, (array)($reactions['watchers'] ?? []), true)),
                'unread' => $summary ? social_post_is_unread($summary, $viewer, $readMap) : false,
            ];
        }
        return $states;
    }

    function social_user_comments(int $userId, ?array $viewer = null, int $limit = 20): array
    {
        social_ensure_storage();
        $blocked = social_blocked_ids($viewer);
        $out = [];
        foreach (social_read_json(social_index_file('user-comments-' . $userId), []) as $item) {
            if (!is_array($item) || ($item['status'] ?? '') === 'deleted') continue;
            $isOwner = $viewer && (int)$viewer['id'] === $userId;
            $isAdmin = !empty($viewer['is_admin']);
            $post = social_get_post((int)($item['post_id'] ?? 0), $isAdmin || $isOwner);
            if (!$post || ($post['status'] ?? '') === 'deleted') continue;
            if (!$isOwner && !$isAdmin) {
                if (($item['status'] ?? '') !== 'published') continue;
                if (in_array($userId, $blocked, true)) continue;
                // Gizlenmiş/engellenmiş bir gönderinin başlığını ve yorum özetini profilden sızdırma.
                if (!social_post_visible($post, $viewer, $blocked)) continue;
            }
            $out[] = $item;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    function social_moderation_comments(int $limit = 100): array
    {
        social_ensure_storage();
        return array_slice(array_values(array_filter(social_read_json(social_index_file('moderation-comments'), []), 'is_array')), 0, $limit);
    }

    function social_dm_normalize_conversation(array $conversation): array
    {
        $participants = array_values(array_unique(array_filter(array_map('intval', (array)($conversation['participants'] ?? [])), static fn(int $id): bool => $id > 0)));
        sort($participants);
        $participants = array_slice($participants, 0, 2);
        $reads = [];
        $unread = [];
        foreach ($participants as $id) {
            $reads[(string)$id] = max(0, (int)(($conversation['reads'] ?? [])[(string)$id] ?? ($conversation['reads'] ?? [])[$id] ?? 0));
            $unread[(string)$id] = max(0, (int)(($conversation['unread'] ?? [])[(string)$id] ?? ($conversation['unread'] ?? [])[$id] ?? 0));
        }
        $archived = array_values(array_intersect(array_unique(array_map('intval', (array)($conversation['archived'] ?? []))), $participants));
        $created = (int)($conversation['created_at'] ?? time());
        $status = (string)($conversation['status'] ?? 'accepted');
        if (!in_array($status, ['accepted', 'pending', 'declined'], true)) $status = 'accepted';
        return [
            'id' => max(0, (int)($conversation['id'] ?? 0)),
            'participants' => $participants,
            'initiated_by' => max(0, (int)($conversation['initiated_by'] ?? ($participants[0] ?? 0))),
            'status' => $status,
            'message_count' => max(0, (int)($conversation['message_count'] ?? 0)),
            'last_message_id' => max(0, (int)($conversation['last_message_id'] ?? 0)),
            'last_sender_id' => max(0, (int)($conversation['last_sender_id'] ?? 0)),
            'last_excerpt' => social_excerpt((string)($conversation['last_excerpt'] ?? ''), 180),
            'last_at' => max($created, (int)($conversation['last_at'] ?? $created)),
            'reads' => $reads,
            'unread' => $unread,
            'archived' => $archived,
            'created_at' => $created,
            'updated_at' => (int)($conversation['updated_at'] ?? $created),
        ];
    }

    function social_dm_normalize_message(array $message): array
    {
        $created = (int)($message['created_at'] ?? time());
        return [
            'id' => max(0, (int)($message['id'] ?? 0)),
            'conversation_id' => max(0, (int)($message['conversation_id'] ?? 0)),
            'sender_id' => max(0, (int)($message['sender_id'] ?? 0)),
            'sender_username' => social_clean_text((string)($message['sender_username'] ?? ''), 40),
            'sender_display' => social_clean_text((string)($message['sender_display'] ?? ''), 60),
            'body' => social_clean_text((string)($message['body'] ?? ''), 2000),
            'status' => in_array(($message['status'] ?? 'published'), ['published', 'hidden', 'deleted'], true) ? (string)$message['status'] : 'published',
            'created_at' => $created,
            'updated_at' => (int)($message['updated_at'] ?? $created),
            'edited_at' => max(0, (int)($message['edited_at'] ?? 0)),
        ];
    }

    function social_dm_get_conversation(int $conversationId): ?array
    {
        if ($conversationId <= 0) return null;
        social_ensure_storage();
        $file = social_dm_meta_file($conversationId);
        if (!is_file($file)) return null;
        $conversation = social_dm_normalize_conversation(social_read_json($file, []));
        return count($conversation['participants']) === 2 ? $conversation : null;
    }

    function social_dm_peer_id(array $conversation, int $userId): int
    {
        foreach ((array)$conversation['participants'] as $id) {
            if ((int)$id !== $userId) return (int)$id;
        }
        return 0;
    }

    function social_dm_summary_for(array $conversation, int $userId): array
    {
        $peerId = social_dm_peer_id($conversation, $userId);
        $peer = $peerId > 0 ? social_get_profile($peerId) : null;
        return [
            'id' => (int)$conversation['id'],
            'peer_id' => $peerId,
            'peer_username' => (string)($peer['username'] ?? ''),
            'peer_display' => (string)($peer['display_name'] ?? 'Oyuncu'),
            'peer_avatar' => (string)($peer['avatar'] ?? ''),
            'status' => (string)$conversation['status'],
            'initiated_by' => (int)$conversation['initiated_by'],
            'message_count' => (int)$conversation['message_count'],
            'last_message_id' => (int)$conversation['last_message_id'],
            'last_sender_id' => (int)$conversation['last_sender_id'],
            'last_excerpt' => (string)$conversation['last_excerpt'],
            'last_at' => (int)$conversation['last_at'],
            'read_message_id' => (int)(($conversation['reads'] ?? [])[(string)$userId] ?? 0),
            'unread_count' => (int)(($conversation['unread'] ?? [])[(string)$userId] ?? 0),
            'archived' => in_array($userId, (array)$conversation['archived'], true),
            'created_at' => (int)$conversation['created_at'],
            'updated_at' => (int)$conversation['updated_at'],
        ];
    }

    function social_dm_write_indexes_locked(array $conversation): void
    {
        $conversation = social_dm_normalize_conversation($conversation);
        foreach ((array)$conversation['participants'] as $userId) {
            $userId = (int)$userId;
            $file = social_dm_index_file($userId);
            $items = array_values(array_filter(
                social_read_json($file, []),
                static fn($item): bool => is_array($item) && (int)($item['id'] ?? 0) !== (int)$conversation['id']
            ));
            $items[] = social_dm_summary_for($conversation, $userId);
            usort($items, static fn(array $a, array $b): int => [(int)$b['last_at'], (int)$b['id']] <=> [(int)$a['last_at'], (int)$a['id']]);
            social_write_json_atomic($file, array_slice($items, 0, 500), false);
        }
    }

    function social_dm_find_conversation(int $userA, int $userB): ?array
    {
        if ($userA <= 0 || $userB <= 0 || $userA === $userB) return null;
        social_ensure_storage();
        foreach (social_read_json(social_dm_index_file($userA), []) as $item) {
            if (!is_array($item) || (int)($item['peer_id'] ?? 0) !== $userB) continue;
            $conversation = social_dm_get_conversation((int)($item['id'] ?? 0));
            if ($conversation && in_array($userA, $conversation['participants'], true) && in_array($userB, $conversation['participants'], true)) {
                return $conversation;
            }
        }
        return null;
    }

    function social_dm_conversations(array $user, string $query = '', bool $includeArchived = false): array
    {
        $userId = (int)$user['id'];
        social_ensure_storage();
        $items = social_read_json(social_dm_index_file($userId), []);
        if (!$items) {
            $scanned = [];
            foreach (glob(social_dir('messages') . '/meta/*/*.json') ?: [] as $file) {
                try {
                    $conversation = social_dm_normalize_conversation(social_read_json($file, []));
                } catch (Throwable) {
                    continue;
                }
                if (in_array($userId, $conversation['participants'], true)) $scanned[] = social_dm_summary_for($conversation, $userId);
            }
            usort($scanned, static fn(array $a, array $b): int => [(int)$b['last_at'], (int)$b['id']] <=> [(int)$a['last_at'], (int)$a['id']]);
            $items = $scanned;
        }
        $fold = social_fold(trim($query));
        $blocked = social_blocked_ids($user);
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $conversation = social_dm_get_conversation((int)($item['id'] ?? 0));
            if (!$conversation || !in_array($userId, $conversation['participants'], true)) continue;
            $summary = social_dm_summary_for($conversation, $userId);
            if (!$includeArchived && !empty($summary['archived'])) continue;
            $summary['peer_blocked'] = in_array((int)$summary['peer_id'], $blocked, true);
            $summary['peer_online'] = social_is_online((int)$summary['peer_id']);
            if ($fold !== '') {
                $haystack = social_fold(implode(' ', [(string)$summary['peer_username'], (string)$summary['peer_display'], (string)$summary['last_excerpt']]));
                if (!str_contains($haystack, $fold)) continue;
            }
            $out[] = $summary;
        }
        usort($out, static fn(array $a, array $b): int => [(int)$b['last_at'], (int)$b['id']] <=> [(int)$a['last_at'], (int)$a['id']]);
        return $out;
    }

    function social_dm_unread_count(int $userId): int
    {
        if ($userId <= 0) return 0;
        social_ensure_storage();
        $count = 0;
        foreach (social_read_json(social_dm_index_file($userId), []) as $item) {
            if (is_array($item)) $count += max(0, (int)($item['unread_count'] ?? 0));
        }
        return $count;
    }

    function social_dm_can_message(array $actor, int $targetId, ?array $existing = null): array
    {
        $actorId = (int)($actor['id'] ?? 0);
        if ($targetId <= 0 || $targetId === $actorId) {
            return ['errors' => ['Mesaj gönderilecek geçerli bir kullanıcı seç.'], 'request' => false, 'target_user' => null, 'target_profile' => null, 'actor_profile' => null];
        }
        $targetUser = function_exists('dg_find_user_by_id') ? dg_find_user_by_id($targetId) : null;
        if (!$targetUser || (int)($targetUser['is_banned'] ?? 0) === 1) {
            return ['errors' => ['Bu kullanıcıya şu anda mesaj gönderilemiyor.'], 'request' => false, 'target_user' => null, 'target_profile' => null, 'actor_profile' => null];
        }
        $actorProfile = social_ensure_profile($actor);
        $targetProfile = social_ensure_profile($targetUser);
        if (($muteError = social_muted_error($actorProfile, $actor)) !== '') {
            return ['errors' => [$muteError], 'request' => false, 'target_user' => $targetUser, 'target_profile' => $targetProfile, 'actor_profile' => $actorProfile];
        }
        $actorFollows = social_follows($actorId);
        $targetFollows = social_follows($targetId);
        if (in_array($targetId, $actorFollows['blocked'], true) || in_array($actorId, $targetFollows['blocked'], true)) {
            return ['errors' => ['Engelleme nedeniyle bu kullanıcıyla mesajlaşılamıyor.'], 'request' => false, 'target_user' => $targetUser, 'target_profile' => $targetProfile, 'actor_profile' => $actorProfile];
        }
        $existingAccepted = $existing && ($existing['status'] ?? '') === 'accepted';
        $targetFollowsActor = in_array($actorId, $targetFollows['following'], true);
        if (!$existingAccepted && empty($actor['is_admin'])) {
            if (($targetProfile['dm_policy'] ?? 'everyone') === 'nobody') {
                return ['errors' => ['Bu kullanıcı özel mesaj kabul etmiyor.'], 'request' => false, 'target_user' => $targetUser, 'target_profile' => $targetProfile, 'actor_profile' => $actorProfile];
            }
            if (($targetProfile['dm_policy'] ?? 'everyone') === 'following' && !$targetFollowsActor) {
                return ['errors' => ['Bu kullanıcı yalnızca takip ettiği kişilerden özel mesaj kabul ediyor.'], 'request' => false, 'target_user' => $targetUser, 'target_profile' => $targetProfile, 'actor_profile' => $actorProfile];
            }
        }
        $request = !$existing
            && empty($actor['is_admin'])
            && !$targetFollowsActor
            && !social_is_trusted($actorProfile, $actor);
        return ['errors' => [], 'request' => $request, 'target_user' => $targetUser, 'target_profile' => $targetProfile, 'actor_profile' => $actorProfile];
    }

    function social_dm_message_map(int $conversationId): array
    {
        $map = [];
        foreach (social_read_jsonl(social_dm_messages_file($conversationId)) as $row) {
            $message = social_dm_normalize_message($row);
            if ($message['id'] > 0 && $message['conversation_id'] === $conversationId) $map[$message['id']] = $message;
        }
        ksort($map);
        return $map;
    }

    function social_dm_message_payload(array $message, int $viewerId, array $profiles = [], bool $moderation = false): array
    {
        $message = social_dm_normalize_message($message);
        $senderId = (int)$message['sender_id'];
        $profile = $profiles[$senderId] ?? null;
        $status = (string)$message['status'];
        $body = (string)$message['body'];
        if (!$moderation && $status !== 'published') {
            $body = $status === 'deleted' ? 'Bu mesaj silindi.' : 'Bu mesaj moderasyon tarafından gizlendi.';
        }
        return [
            'id' => (int)$message['id'],
            'conversation_id' => (int)$message['conversation_id'],
            'sender_id' => $senderId,
            'sender_username' => (string)($profile['username'] ?? $message['sender_username']),
            'sender_display' => (string)($profile['display_name'] ?? $message['sender_display']),
            'sender_avatar' => (string)($profile['avatar'] ?? ''),
            'body' => $body,
            'html' => $status === 'published' || $moderation ? social_render_text($body) : '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>',
            'status' => $status,
            'mine' => $senderId === $viewerId,
            'created_at' => (int)$message['created_at'],
            'time_label' => social_time_ago((int)$message['created_at']),
            'date_label' => social_format_date((int)$message['created_at']),
            'edited_at' => (int)$message['edited_at'],
        ];
    }

    function social_dm_message_payloads(array $messages, int $viewerId, bool $moderation = false): array
    {
        $ids = [];
        foreach ($messages as $message) $ids[] = (int)($message['sender_id'] ?? 0);
        $profiles = social_profiles_by_ids($ids);
        return array_values(array_map(static fn(array $message): array => social_dm_message_payload($message, $viewerId, $profiles, $moderation), $messages));
    }

    function social_dm_messages(array $actor, int $conversationId, int $afterId = 0, int $limit = 100, int $beforeId = 0): array
    {
        $conversation = social_dm_get_conversation($conversationId);
        if (!$conversation || !in_array((int)$actor['id'], $conversation['participants'], true)) {
            return ['errors' => ['Bu konuşmaya erişemezsin.'], 'conversation' => null, 'messages' => []];
        }
        $messages = array_values(social_dm_message_map($conversationId));
        if ($afterId > 0) {
            $messages = array_values(array_filter($messages, static fn(array $message): bool => (int)$message['id'] > $afterId));
            $messages = array_slice($messages, 0, max(1, min(200, $limit)));
        } elseif ($beforeId > 0) {
            $messages = array_values(array_filter($messages, static fn(array $message): bool => (int)$message['id'] < $beforeId));
            $messages = array_slice($messages, -max(1, min(200, $limit)));
        } else {
            $messages = array_slice($messages, -max(1, min(200, $limit)));
        }
        return [
            'errors' => [],
            'conversation' => social_dm_summary_for($conversation, (int)$actor['id']),
            'messages' => social_dm_message_payloads($messages, (int)$actor['id']),
        ];
    }

    function social_dm_send(array $actor, int $conversationId, int $targetId, string $body): array
    {
        $actorId = (int)$actor['id'];
        $body = social_clean_text($body, 2000);
        if (social_text_len($body) < 1) return ['errors' => ['Mesaj boş olamaz.'], 'conversation_id' => $conversationId, 'message' => null];
        $existing = null;
        if ($conversationId > 0) {
            $existing = social_dm_get_conversation($conversationId);
            if (!$existing || !in_array($actorId, $existing['participants'], true)) {
                return ['errors' => ['Bu konuşmaya mesaj gönderemezsin.'], 'conversation_id' => 0, 'message' => null];
            }
            $targetId = social_dm_peer_id($existing, $actorId);
        } elseif ($targetId > 0) {
            $existing = social_dm_find_conversation($actorId, $targetId);
            if ($existing) $conversationId = (int)$existing['id'];
        }
        $permission = social_dm_can_message($actor, $targetId, $existing);
        if (!empty($permission['errors'])) return ['errors' => $permission['errors'], 'conversation_id' => $conversationId, 'message' => null];
        $actorProfile = $permission['actor_profile'];
        $isFirstOrRequest = !$existing || ($existing['status'] ?? '') === 'pending';
        if (empty($actor['is_admin']) && $isFirstOrRequest && !social_is_trusted($actorProfile, $actor) && social_url_count($body) > 0) {
            return ['errors' => ['Güvenilir üye olana kadar ilk özel mesajında bağlantı gönderemezsin.'], 'conversation_id' => $conversationId, 'message' => null];
        }

        return social_with_lock(static function () use ($actor, $actorId, $actorProfile, $conversationId, $targetId, $body, $permission): array {
            $conversation = $conversationId > 0 ? social_dm_get_conversation($conversationId) : social_dm_find_conversation($actorId, $targetId);
            if ($conversation && !in_array($actorId, $conversation['participants'], true)) {
                return ['errors' => ['Bu konuşmaya mesaj gönderemezsin.'], 'conversation_id' => 0, 'message' => null];
            }
            if ($conversation && ($conversation['status'] ?? '') === 'declined') {
                return ['errors' => ['Bu mesaj isteği reddedildi.'], 'conversation_id' => (int)$conversation['id'], 'message' => null];
            }
            if ($conversation && ($conversation['status'] ?? '') === 'pending' && (int)$conversation['initiated_by'] === $actorId && (int)$conversation['message_count'] >= 3) {
                return ['errors' => ['Mesaj isteğin kabul edilmeden en fazla 3 mesaj gönderebilirsin.'], 'conversation_id' => (int)$conversation['id'], 'message' => null];
            }
            if (empty($actor['is_admin']) && !social_rate_limit_locked($actorId, 'direct_messages', [[60, 20], [3600, 300], [86400, 1000]])) {
                return ['errors' => ['Çok hızlı mesaj gönderiyorsun. Biraz bekleyip tekrar dene.'], 'conversation_id' => (int)($conversation['id'] ?? 0), 'message' => null];
            }
            if (!$conversation && empty($actor['is_admin']) && !social_rate_limit_locked($actorId, 'direct_conversations', [[3600, 5], [86400, 20]])) {
                return ['errors' => ['Kısa sürede çok sayıda yeni konuşma başlattın. Biraz bekleyip tekrar dene.'], 'conversation_id' => 0, 'message' => null];
            }

            $now = time();
            if (!$conversation) {
                $conversationId = social_next_id_locked('conversations');
                $participants = [$actorId, $targetId];
                sort($participants);
                $conversation = social_dm_normalize_conversation([
                    'id' => $conversationId,
                    'participants' => $participants,
                    'initiated_by' => $actorId,
                    'status' => !empty($permission['request']) ? 'pending' : 'accepted',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_at' => $now,
                ]);
                social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
            } else {
                $conversationId = (int)$conversation['id'];
                if (($conversation['status'] ?? '') === 'pending' && (int)$conversation['initiated_by'] !== $actorId) {
                    $conversation['status'] = 'accepted';
                }
            }
            $recipientId = social_dm_peer_id($conversation, $actorId);
            $recipientUnreadBefore = (int)(($conversation['unread'] ?? [])[(string)$recipientId] ?? 0);
            $message = social_dm_normalize_message([
                'id' => social_next_id_locked('direct_messages'),
                'conversation_id' => $conversationId,
                'sender_id' => $actorId,
                'sender_username' => (string)($actorProfile['username'] ?? $actor['username'] ?? ''),
                'sender_display' => (string)($actorProfile['display_name'] ?? $actor['username'] ?? ''),
                'body' => $body,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            social_append_jsonl(social_dm_messages_file($conversationId), $message);
            $conversation['message_count'] = (int)$conversation['message_count'] + 1;
            $conversation['last_message_id'] = (int)$message['id'];
            $conversation['last_sender_id'] = $actorId;
            $conversation['last_excerpt'] = social_excerpt($body, 180);
            $conversation['last_at'] = $now;
            $conversation['updated_at'] = $now;
            $conversation['reads'][(string)$actorId] = (int)$message['id'];
            $conversation['unread'][(string)$actorId] = 0;
            $conversation['unread'][(string)$recipientId] = $recipientUnreadBefore + 1;
            $conversation['archived'] = array_values(array_filter((array)$conversation['archived'], static fn(int $id): bool => $id !== $actorId && $id !== $recipientId));
            $conversation = social_dm_normalize_conversation($conversation);
            social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
            social_dm_write_indexes_locked($conversation);
            if ($recipientUnreadBefore === 0) {
                $name = (string)($actorProfile['display_name'] ?? $actor['username'] ?? 'Bir oyuncu');
                social_notify_locked($recipientId, $actorId, 'direct_message', 0, (int)$message['id'], $name . ' sana özel mesaj gönderdi.', 'mesajlar.php?sohbet=' . $conversationId . '#mesaj-son');
            }
            return [
                'errors' => [],
                'conversation_id' => $conversationId,
                'conversation' => social_dm_summary_for($conversation, $actorId),
                'message' => social_dm_message_payload($message, $actorId, social_profiles_by_ids([$actorId])),
                'status' => (string)$conversation['status'],
            ];
        });
    }

    function social_dm_mark_read(array $actor, int $conversationId, int $upToId = 0): array
    {
        $actorId = (int)$actor['id'];
        return social_with_lock(static function () use ($actorId, $conversationId, $upToId): array {
            $conversation = social_dm_get_conversation($conversationId);
            if (!$conversation || !in_array($actorId, $conversation['participants'], true)) {
                return ['errors' => ['Bu konuşmaya erişemezsin.'], 'unread_count' => 0];
            }
            $target = $upToId > 0 ? min($upToId, (int)$conversation['last_message_id']) : (int)$conversation['last_message_id'];
            $current = (int)(($conversation['reads'] ?? [])[(string)$actorId] ?? 0);
            if ($target > $current) {
                $remaining = 0;
                foreach (social_dm_message_map($conversationId) as $message) {
                    if ((int)$message['id'] > $target && (int)$message['sender_id'] !== $actorId && ($message['status'] ?? '') === 'published') $remaining++;
                }
                $conversation['reads'][(string)$actorId] = $target;
                $conversation['unread'][(string)$actorId] = $remaining;
                $conversation['updated_at'] = time();
                $conversation = social_dm_normalize_conversation($conversation);
                social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
                social_dm_write_indexes_locked($conversation);
            }
            return ['errors' => [], 'unread_count' => social_dm_unread_count($actorId), 'read_message_id' => $target];
        });
    }

    function social_dm_set_request(array $actor, int $conversationId, bool $accept): array
    {
        $actorId = (int)$actor['id'];
        return social_with_lock(static function () use ($actorId, $conversationId, $accept): array {
            $conversation = social_dm_get_conversation($conversationId);
            if (!$conversation || !in_array($actorId, $conversation['participants'], true) || (int)$conversation['initiated_by'] === $actorId) {
                return ['errors' => ['Bu mesaj isteğini yönetemezsin.'], 'status' => (string)($conversation['status'] ?? '')];
            }
            $conversation['status'] = $accept ? 'accepted' : 'declined';
            $conversation['updated_at'] = time();
            $conversation = social_dm_normalize_conversation($conversation);
            social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
            social_dm_write_indexes_locked($conversation);
            return ['errors' => [], 'status' => (string)$conversation['status']];
        });
    }

    function social_dm_set_archived(array $actor, int $conversationId, bool $archived): array
    {
        $actorId = (int)$actor['id'];
        return social_with_lock(static function () use ($actorId, $conversationId, $archived): array {
            $conversation = social_dm_get_conversation($conversationId);
            if (!$conversation || !in_array($actorId, $conversation['participants'], true)) {
                return ['errors' => ['Bu konuşmaya erişemezsin.'], 'archived' => false];
            }
            $list = array_values(array_filter((array)$conversation['archived'], static fn(int $id): bool => $id !== $actorId));
            if ($archived) $list[] = $actorId;
            $conversation['archived'] = $list;
            $conversation['updated_at'] = time();
            $conversation = social_dm_normalize_conversation($conversation);
            social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
            social_dm_write_indexes_locked($conversation);
            return ['errors' => [], 'archived' => $archived];
        });
    }

    function social_dm_delete_message(array $actor, int $conversationId, int $messageId): array
    {
        $actorId = (int)$actor['id'];
        $conversation = social_dm_get_conversation($conversationId);
        $message = social_dm_message_map($conversationId)[$messageId] ?? null;
        if (!$conversation || !$message || !in_array($actorId, $conversation['participants'], true)) {
            return ['errors' => ['Silinecek mesaj bulunamadı.']];
        }
        if ((int)$message['sender_id'] !== $actorId && empty($actor['is_admin'])) return ['errors' => ['Yalnızca kendi mesajını silebilirsin.']];
        if (empty($actor['is_admin']) && (int)$message['created_at'] < time() - 86400) return ['errors' => ['Mesajlar yalnızca ilk 24 saat içinde silinebilir.']];
        return social_with_lock(static function () use ($conversation, $message, $conversationId): array {
            $message['status'] = 'deleted';
            $message['updated_at'] = time();
            $message = social_dm_normalize_message($message);
            social_append_jsonl(social_dm_messages_file($conversationId), $message);
            if ((int)$conversation['last_message_id'] === (int)$message['id']) {
                $conversation['last_excerpt'] = 'Mesaj silindi.';
                $conversation['updated_at'] = time();
                $conversation = social_dm_normalize_conversation($conversation);
                social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
                social_dm_write_indexes_locked($conversation);
            }
            return ['errors' => [], 'message_id' => (int)$message['id'], 'status' => 'deleted'];
        });
    }

    function social_dm_moderate_message(array $admin, int $conversationId, int $messageId, string $status): array
    {
        if (empty($admin['is_admin']) || !in_array($status, ['published', 'hidden', 'deleted'], true)) {
            return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        }
        $hasReport = false;
        foreach (social_reports() as $report) {
            if (($report['target_type'] ?? '') === 'direct_message' && (int)$report['target_id'] === $messageId && (int)($report['conversation_id'] ?? 0) === $conversationId) $hasReport = true;
        }
        if (!$hasReport) return ['errors' => ['Özel mesajlar yalnızca şikâyet üzerinden moderasyona açılabilir.']];
        $message = social_dm_message_map($conversationId)[$messageId] ?? null;
        if (!$message) return ['errors' => ['Mesaj bulunamadı.']];
        return social_with_lock(static function () use ($admin, $message, $conversationId, $status): array {
            $message['status'] = $status;
            $message['updated_at'] = time();
            $message = social_dm_normalize_message($message);
            social_append_jsonl(social_dm_messages_file($conversationId), $message);
            $conversation = social_dm_get_conversation($conversationId);
            if ($conversation && (int)$conversation['last_message_id'] === (int)$message['id'] && $status !== 'published') {
                $conversation['last_excerpt'] = $status === 'deleted' ? 'Mesaj silindi.' : 'Mesaj moderasyon tarafından gizlendi.';
                $conversation['updated_at'] = time();
                $conversation = social_dm_normalize_conversation($conversation);
                social_write_json_atomic(social_dm_meta_file($conversationId), $conversation, false);
                social_dm_write_indexes_locked($conversation);
            }
            social_log_moderation_locked($admin, 'direct_message_' . $status, 'direct_message', (int)$message['id'], 0, 'Şikâyet edilen özel mesaj durumu: ' . $status);
            return ['errors' => [], 'status' => $status];
        });
    }

    function social_dm_report_context(array $admin, int $conversationId, int $messageId): array
    {
        if (empty($admin['is_admin'])) return [];
        $hasReport = false;
        foreach (social_reports() as $report) {
            if (($report['target_type'] ?? '') === 'direct_message' && (int)$report['target_id'] === $messageId && (int)($report['conversation_id'] ?? 0) === $conversationId) $hasReport = true;
        }
        if (!$hasReport) return [];
        $messages = array_values(social_dm_message_map($conversationId));
        $index = null;
        foreach ($messages as $i => $message) if ((int)$message['id'] === $messageId) $index = $i;
        if ($index === null) return [];
        $slice = array_slice($messages, max(0, $index - 5), 11);
        return social_dm_message_payloads($slice, (int)$admin['id'], true);
    }

    function social_dm_rebuild_indexes_locked(): int
    {
        $byUser = [];
        $count = 0;
        $maxConversationId = 0;
        $maxMessageId = 0;
        foreach (glob(social_dir('messages') . '/meta/*/*.json') ?: [] as $file) {
            try {
                $conversation = social_dm_normalize_conversation(social_read_json($file, []));
            } catch (Throwable) {
                continue;
            }
            if ($conversation['id'] <= 0 || count($conversation['participants']) !== 2) continue;
            $count++;
            $maxConversationId = max($maxConversationId, (int)$conversation['id']);
            foreach (social_dm_message_map((int)$conversation['id']) as $message) $maxMessageId = max($maxMessageId, (int)$message['id']);
            foreach ($conversation['participants'] as $userId) $byUser[(int)$userId][] = social_dm_summary_for($conversation, (int)$userId);
        }
        foreach (glob(social_dir('indexes') . '/dm-user-*.json') ?: [] as $file) @unlink($file);
        foreach ($byUser as $userId => $items) {
            usort($items, static fn(array $a, array $b): int => [(int)$b['last_at'], (int)$b['id']] <=> [(int)$a['last_at'], (int)$a['id']]);
            social_write_json_atomic(social_dm_index_file((int)$userId), array_slice($items, 0, 500), false);
        }
        $meta = social_meta_locked();
        $meta['counters']['conversations'] = max((int)($meta['counters']['conversations'] ?? 1), $maxConversationId + 1);
        $meta['counters']['direct_messages'] = max((int)($meta['counters']['direct_messages'] ?? 1), $maxMessageId + 1);
        $meta['updated_at'] = time();
        social_write_json_atomic(social_meta_file(), $meta);
        return $count;
    }

    function social_chat_channels(): array
    {
        return [
            'genel' => ['slug' => 'genel', 'name' => 'Genel Sohbet', 'icon' => '💬', 'description' => 'Oyun gündemi ve günlük sohbet.', 'official_only' => false],
            'oyun-arkadasi' => ['slug' => 'oyun-arkadasi', 'name' => 'Oyun Arkadaşı', 'icon' => '🤝', 'description' => 'Şu an ekip arayan oyuncular.', 'official_only' => false],
            'etkinlikler' => ['slug' => 'etkinlikler', 'name' => 'Etkinlikler', 'icon' => '🗓', 'description' => 'Oyun geceleri ve buluşma planları.', 'official_only' => false],
            'yardim' => ['slug' => 'yardim', 'name' => 'Yardım Masası', 'icon' => '🛠', 'description' => 'Kısa sorular ve hızlı yardımlaşma.', 'official_only' => false],
            'duyurular' => ['slug' => 'duyurular', 'name' => 'Duyurular', 'icon' => '📣', 'description' => 'Yalnızca DeviceGame ekibi yazabilir.', 'official_only' => true],
        ];
    }

    function social_chat_channel(string $slug): ?array
    {
        return social_chat_channels()[social_slugify($slug)] ?? null;
    }

    function social_chat_normalize_message(array $message): array
    {
        $created = (int)($message['created_at'] ?? time());
        return [
            'id' => max(0, (int)($message['id'] ?? 0)),
            'channel' => social_slugify((string)($message['channel'] ?? 'genel')) ?: 'genel',
            'user_id' => max(0, (int)($message['user_id'] ?? 0)),
            'author_username' => social_clean_text((string)($message['author_username'] ?? ''), 40),
            'author_display' => social_clean_text((string)($message['author_display'] ?? ''), 60),
            'body' => social_clean_text((string)($message['body'] ?? ''), 500),
            'status' => in_array(($message['status'] ?? 'published'), ['published', 'hidden', 'deleted'], true) ? (string)$message['status'] : 'published',
            'pinned' => !empty($message['pinned']),
            'created_at' => $created,
            'updated_at' => (int)($message['updated_at'] ?? $created),
            'edited_at' => max(0, (int)($message['edited_at'] ?? 0)),
        ];
    }

    function social_chat_message_map(string $channel): array
    {
        $channel = social_slugify($channel) ?: 'genel';
        $map = [];
        foreach (social_read_jsonl(social_chat_file($channel)) as $row) {
            $message = social_chat_normalize_message($row);
            if ($message['id'] > 0 && $message['channel'] === $channel) $map[$message['id']] = $message;
        }
        ksort($map);
        return $map;
    }

    function social_chat_message_payload(array $message, ?array $viewer, array $profiles = []): array
    {
        $message = social_chat_normalize_message($message);
        $authorId = (int)$message['user_id'];
        $profile = $profiles[$authorId] ?? null;
        $status = (string)$message['status'];
        $body = $status === 'published' ? (string)$message['body'] : ($status === 'deleted' ? 'Bu mesaj silindi.' : 'Bu mesaj moderasyon tarafından gizlendi.');
        return [
            'id' => (int)$message['id'],
            'channel' => (string)$message['channel'],
            'user_id' => $authorId,
            'author_username' => (string)($profile['username'] ?? $message['author_username']),
            'author_display' => (string)($profile['display_name'] ?? $message['author_display']),
            'author_avatar' => (string)($profile['avatar'] ?? ''),
            'body' => $body,
            'html' => $status === 'published' ? social_render_text($body) : '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>',
            'status' => $status,
            'pinned' => !empty($message['pinned']),
            'mine' => $viewer && (int)$viewer['id'] === $authorId,
            'created_at' => (int)$message['created_at'],
            'time_label' => social_time_ago((int)$message['created_at']),
            'date_label' => social_format_date((int)$message['created_at']),
        ];
    }

    function social_chat_payloads(array $messages, ?array $viewer): array
    {
        $ids = [];
        foreach ($messages as $message) $ids[] = (int)($message['user_id'] ?? 0);
        $profiles = social_profiles_by_ids($ids);
        return array_values(array_map(static fn(array $message): array => social_chat_message_payload($message, $viewer, $profiles), $messages));
    }

    function social_chat_messages(string $channel, ?array $viewer = null, int $afterId = 0, int $limit = 50, int $beforeId = 0): array
    {
        $channelData = social_chat_channel($channel);
        if (!$channelData) return ['errors' => ['Sohbet kanalı bulunamadı.'], 'channel' => null, 'messages' => [], 'pinned' => null];
        social_ensure_storage();
        $blocked = !empty($viewer['is_admin']) ? [] : social_blocked_ids($viewer);
        $all = [];
        $pinned = null;
        foreach (social_chat_message_map((string)$channelData['slug']) as $message) {
            if (($message['status'] ?? '') !== 'published') continue;
            if (in_array((int)$message['user_id'], $blocked, true)) continue;
            $all[] = $message;
            if (!empty($message['pinned'])) $pinned = $message;
        }
        $messages = $all;
        if ($afterId > 0) {
            $messages = array_values(array_filter($messages, static fn(array $message): bool => (int)$message['id'] > $afterId));
            $messages = array_slice($messages, 0, max(1, min(200, $limit)));
        } elseif ($beforeId > 0) {
            $messages = array_values(array_filter($messages, static fn(array $message): bool => (int)$message['id'] < $beforeId));
            $messages = array_slice($messages, -max(1, min(200, $limit)));
        } else {
            $messages = array_slice($messages, -max(1, min(200, $limit)));
        }
        return [
            'errors' => [],
            'channel' => $channelData,
            'messages' => social_chat_payloads($messages, $viewer),
            'pinned' => $pinned ? social_chat_message_payload($pinned, $viewer, social_profiles_by_ids([(int)$pinned['user_id']])) : null,
            'last_id' => $all ? (int)$all[count($all) - 1]['id'] : 0,
        ];
    }

    function social_chat_moderation_messages(string $channel, int $limit = 100): array
    {
        if (!social_chat_channel($channel)) return [];
        $messages = array_values(array_filter(social_chat_message_map($channel), static fn(array $message): bool => ($message['status'] ?? '') !== 'deleted'));
        return array_slice($messages, -max(1, min(200, $limit)));
    }

    function social_chat_send(array $user, string $channel, string $body): array
    {
        $channelData = social_chat_channel($channel);
        if (!$channelData) return ['errors' => ['Sohbet kanalı bulunamadı.'], 'message' => null];
        if (!empty($channelData['official_only']) && empty($user['is_admin'])) {
            return ['errors' => ['Bu kanala yalnızca DeviceGame ekibi yazabilir.'], 'message' => null];
        }
        $body = social_clean_text($body, 500);
        if (social_text_len($body) < 1) return ['errors' => ['Mesaj boş olamaz.'], 'message' => null];
        $profile = social_ensure_profile($user);
        if (($muteError = social_muted_error($profile, $user)) !== '') return ['errors' => [$muteError], 'message' => null];
        if (empty($user['is_admin']) && !social_is_trusted($profile, $user) && social_url_count($body) > 0) {
            return ['errors' => ['Güvenilir üye olana kadar canlı sohbette bağlantı paylaşamazsın.'], 'message' => null];
        }
        return social_with_lock(static function () use ($user, $profile, $channelData, $body): array {
            $userId = (int)$user['id'];
            if (empty($user['is_admin']) && !social_rate_limit_locked($userId, 'chat_messages', [[3, 1], [60, 20], [3600, 200], [86400, 1000]])) {
                return ['errors' => ['Sohbet yavaş modda: biraz bekleyip tekrar dene.'], 'message' => null];
            }
            $now = time();
            $message = social_chat_normalize_message([
                'id' => social_next_id_locked('chat_messages'),
                'channel' => (string)$channelData['slug'],
                'user_id' => $userId,
                'author_username' => (string)$profile['username'],
                'author_display' => (string)$profile['display_name'],
                'body' => $body,
                'status' => 'published',
                'pinned' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $file = social_chat_file((string)$channelData['slug']);
            social_append_jsonl($file, $message);
            if (is_file($file) && (int)@filesize($file) > 750000) {
                $keep = array_slice(array_values(social_chat_message_map((string)$channelData['slug'])), -1000);
                social_write_jsonl_atomic($file, $keep);
            }
            $mentions = array_slice(social_extract_mentions($body), 0, 3);
            foreach ($mentions as $targetId) {
                if ($targetId <= 0 || $targetId === $userId) continue;
                social_notify_locked($targetId, $userId, 'chat_mention', 0, (int)$message['id'], (string)$profile['display_name'] . ' canlı sohbette senden bahsetti.', 'sohbet.php?kanal=' . rawurlencode((string)$channelData['slug']) . '#sohbet-son');
            }
            return ['errors' => [], 'message' => social_chat_message_payload($message, $user, social_profiles_by_ids([$userId]))];
        });
    }

    function social_chat_set_status(array $actor, string $channel, int $messageId, string $action): array
    {
        $channelData = social_chat_channel($channel);
        if (!$channelData || !in_array($action, ['published', 'hidden', 'deleted', 'pin', 'unpin'], true)) {
            return ['errors' => ['Geçersiz sohbet işlemi.'], 'status' => '', 'pinned' => false];
        }
        $message = social_chat_message_map((string)$channelData['slug'])[$messageId] ?? null;
        if (!$message) return ['errors' => ['Sohbet mesajı bulunamadı.'], 'status' => '', 'pinned' => false];
        $isAdmin = !empty($actor['is_admin']);
        $isOwner = (int)$actor['id'] === (int)$message['user_id'];
        if (!$isAdmin && !($isOwner && $action === 'deleted')) return ['errors' => ['Bu işlem için yetkin yok.'], 'status' => (string)$message['status'], 'pinned' => !empty($message['pinned'])];
        if (!$isAdmin && $action === 'deleted' && (int)$message['created_at'] < time() - 1800) {
            return ['errors' => ['Sohbet mesajları yalnızca ilk 30 dakika içinde silinebilir.'], 'status' => (string)$message['status'], 'pinned' => !empty($message['pinned'])];
        }
        return social_with_lock(static function () use ($actor, $channelData, $message, $action): array {
            if ($action === 'pin' || $action === 'unpin') {
                if ($action === 'pin') {
                    foreach (social_chat_message_map((string)$channelData['slug']) as $other) {
                        if (!empty($other['pinned']) && (int)$other['id'] !== (int)$message['id']) {
                            $other['pinned'] = false;
                            $other['updated_at'] = time();
                            social_append_jsonl(social_chat_file((string)$channelData['slug']), social_chat_normalize_message($other));
                        }
                    }
                }
                $message['pinned'] = $action === 'pin';
            } else {
                $message['status'] = $action;
                if ($action !== 'published') $message['pinned'] = false;
            }
            $message['updated_at'] = time();
            $message = social_chat_normalize_message($message);
            social_append_jsonl(social_chat_file((string)$channelData['slug']), $message);
            if (!empty($actor['is_admin'])) {
                social_log_moderation_locked($actor, 'chat_' . $action, 'chat_message', (int)$message['id'], 0, 'Kanal: ' . (string)$channelData['slug']);
            }
            return ['errors' => [], 'status' => (string)$message['status'], 'pinned' => !empty($message['pinned']), 'message' => $message];
        });
    }

    function social_chat_prune(array $admin, string $channel, int $keep = 1000): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.'], 'kept' => 0];
        $channelData = social_chat_channel($channel);
        if (!$channelData) return ['errors' => ['Sohbet kanalı bulunamadı.'], 'kept' => 0];
        $keep = max(100, min(5000, $keep));
        return social_with_lock(static function () use ($admin, $channelData, $keep): array {
            $messages = array_slice(array_values(social_chat_message_map((string)$channelData['slug'])), -$keep);
            social_write_jsonl_atomic(social_chat_file((string)$channelData['slug']), $messages);
            social_log_moderation_locked($admin, 'chat_prune', 'chat_channel', 0, 0, (string)$channelData['slug'] . ' kanalında son ' . count($messages) . ' mesaj tutuldu.');
            return ['errors' => [], 'kept' => count($messages)];
        });
    }

    function social_chat_typers(string $channel, ?array $viewer = null): array
    {
        $channel = social_slugify($channel);
        $viewerId = $viewer ? (int)$viewer['id'] : 0;
        $blocked = social_blocked_ids($viewer);
        $names = [];
        foreach (glob(social_dir('presence') . '/*.json') ?: [] as $file) {
            try {
                $presence = social_normalize_presence(social_read_json($file, []));
            } catch (Throwable) {
                continue;
            }
            $id = (int)$presence['user_id'];
            if ($id <= 0 || $id === $viewerId || in_array($id, $blocked, true)) continue;
            if (($presence['typing_chat'] ?? '') !== $channel || (int)$presence['typing_chat_at'] < time() - 6) continue;
            $names[] = (string)($presence['display_name'] ?: $presence['username'] ?: 'Bir oyuncu');
            if (count($names) >= 3) break;
        }
        return $names;
    }

    function social_normalize_report(array $report): array
    {
        $created = (int)($report['created_at'] ?? time());
        return [
            'id' => (int)($report['id'] ?? 0),
            'reporter_id' => (int)($report['reporter_id'] ?? 0),
            'reporter_username' => (string)($report['reporter_username'] ?? ''),
            'target_type' => in_array(($report['target_type'] ?? 'post'), ['post', 'comment', 'profile', 'direct_message', 'chat_message'], true) ? (string)$report['target_type'] : 'post',
            'target_id' => (int)($report['target_id'] ?? 0),
            'post_id' => (int)($report['post_id'] ?? 0),
            'conversation_id' => max(0, (int)($report['conversation_id'] ?? 0)),
            'channel' => social_slugify((string)($report['channel'] ?? '')),
            'target_author_id' => (int)($report['target_author_id'] ?? 0),
            'target_title' => social_clean_text((string)($report['target_title'] ?? ''), 180),
            'target_excerpt' => social_excerpt((string)($report['target_excerpt'] ?? ''), 260),
            'reason' => social_clean_text((string)($report['reason'] ?? 'Diğer'), 60),
            'details' => social_clean_text((string)($report['details'] ?? ''), 600),
            'status' => in_array(($report['status'] ?? 'open'), ['open', 'resolved', 'dismissed'], true) ? (string)$report['status'] : 'open',
            'created_at' => $created,
            'updated_at' => (int)($report['updated_at'] ?? $created),
        ];
    }

    function social_reports(): array
    {
        social_ensure_storage();
        $map = [];
        foreach (glob(social_dir('reports') . '/*.jsonl') ?: [] as $file) {
            foreach (social_read_jsonl($file) as $row) {
                $report = social_normalize_report($row);
                if ($report['id'] > 0) $map[$report['id']] = $report;
            }
        }
        $items = array_values($map);
        usort($items, static fn(array $a, array $b): int => [(int)($b['status'] === 'open'), (int)$b['created_at'], (int)$b['id']] <=> [(int)($a['status'] === 'open'), (int)$a['created_at'], (int)$a['id']]);
        return $items;
    }

    function social_create_report(array $user, array $input): array
    {
        social_ensure_storage();
        $type = (string)($input['target_type'] ?? 'post');
        $postId = (int)($input['post_id'] ?? 0);
        $targetId = (int)($input['target_id'] ?? ($type === 'post' ? $postId : 0));
        $conversationId = max(0, (int)($input['conversation_id'] ?? 0));
        $channel = social_slugify((string)($input['channel'] ?? ''));
        $targetAuthor = 0;
        $targetTitle = '';
        $targetExcerpt = '';
        if ($type === 'profile') {
            $targetUser = $targetId > 0 && function_exists('dg_find_user_by_id') ? dg_find_user_by_id($targetId) : null;
            $targetProfile = $targetUser ? social_ensure_profile($targetUser) : null;
            if (!$targetProfile) return ['errors' => ['Şikâyet edilecek profil bulunamadı.']];
            $postId = 0;
            $targetAuthor = $targetId;
            $targetTitle = '@' . (string)$targetProfile['username'] . ' profili';
            $targetExcerpt = trim((string)$targetProfile['bio'] . ' ' . (string)($targetProfile['signature'] ?? ''));
        } elseif ($type === 'direct_message') {
            $conversation = social_dm_get_conversation($conversationId);
            $message = $conversationId > 0 ? (social_dm_message_map($conversationId)[$targetId] ?? null) : null;
            if (!$conversation || !$message || !in_array((int)$user['id'], $conversation['participants'], true)) {
                return ['errors' => ['Şikâyet edilecek özel mesaj bulunamadı.']];
            }
            $postId = 0;
            $targetAuthor = (int)$message['sender_id'];
            $targetTitle = 'Özel mesaj konuşması #' . $conversationId;
            $targetExcerpt = (string)$message['body'];
        } elseif ($type === 'chat_message') {
            $channelData = social_chat_channel($channel);
            $message = $channelData ? (social_chat_message_map((string)$channelData['slug'])[$targetId] ?? null) : null;
            if (!$channelData || !$message || ($message['status'] ?? '') === 'deleted') {
                return ['errors' => ['Şikâyet edilecek sohbet mesajı bulunamadı.']];
            }
            $postId = 0;
            $channel = (string)$channelData['slug'];
            $targetAuthor = (int)$message['user_id'];
            $targetTitle = 'Canlı sohbet · ' . (string)$channelData['name'];
            $targetExcerpt = (string)$message['body'];
        } elseif ($type === 'post' || $type === 'comment') {
            $post = social_get_post($postId, true);
            if (!$post) return ['errors' => ['Şikâyet edilecek içerik bulunamadı.']];
            $targetAuthor = (int)$post['user_id'];
            $targetTitle = (string)$post['title'];
            $targetExcerpt = (string)$post['excerpt'];
            if ($type === 'comment') {
                $comment = social_get_comment($postId, $targetId);
                if (!$comment) return ['errors' => ['Şikâyet edilecek yorum bulunamadı.']];
                $targetAuthor = (int)$comment['user_id'];
                $targetExcerpt = (string)$comment['body'];
            }
        } else {
            return ['errors' => ['Geçersiz şikâyet türü.']];
        }
        if ($targetAuthor === (int)$user['id']) return ['errors' => ['Kendi içeriğini şikâyet etmene gerek yok; düzenleyebilir veya silebilirsin.']];
        $reason = social_clean_text((string)($input['reason'] ?? 'Diğer'), 60);
        if (!in_array($reason, ['Spam', 'Hakaret / Taciz', 'Dolandırıcılık', 'Uygunsuz içerik', 'Telif', 'Diğer'], true)) $reason = 'Diğer';
        $details = social_clean_text((string)($input['details'] ?? ''), 600);
        return social_with_lock(static function () use ($user, $type, $postId, $targetId, $conversationId, $channel, $targetAuthor, $targetTitle, $targetExcerpt, $reason, $details): array {
            if (empty($user['is_admin']) && !social_rate_limit_locked((int)$user['id'], 'reports', [[3600, 10], [86400, 30]])) {
                return ['errors' => ['Çok fazla şikâyet gönderdin. Biraz bekleyip tekrar dene.']];
            }
            foreach (social_reports() as $report) {
                if ($report['status'] === 'open' && (int)$report['reporter_id'] === (int)$user['id'] && $report['target_type'] === $type && (int)$report['target_id'] === $targetId && (int)($report['conversation_id'] ?? 0) === $conversationId && (string)($report['channel'] ?? '') === $channel) {
                    return ['errors' => ['Bu içerik için zaten açık bir şikâyetin var.']];
                }
            }
            $now = time();
            $report = social_normalize_report([
                'id' => social_next_id_locked('reports'),
                'reporter_id' => (int)$user['id'],
                'reporter_username' => (string)$user['username'],
                'target_type' => $type,
                'target_id' => $targetId,
                'post_id' => $postId,
                'conversation_id' => $conversationId,
                'channel' => $channel,
                'target_author_id' => $targetAuthor,
                'target_title' => $targetTitle,
                'target_excerpt' => $targetExcerpt,
                'reason' => $reason,
                'details' => $details,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            social_append_jsonl(social_reports_file($now), $report);
            return ['errors' => [], 'id' => (int)$report['id']];
        });
    }

    function social_set_report_status(array $admin, int $reportId, string $status): array
    {
        if (empty($admin['is_admin']) || !in_array($status, ['open', 'resolved', 'dismissed'], true)) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $found = null;
        foreach (social_reports() as $report) if ((int)$report['id'] === $reportId) $found = $report;
        if (!$found) return ['errors' => ['Şikâyet bulunamadı.']];
        return social_with_lock(static function () use ($admin, $found, $status): array {
            $found['status'] = $status;
            $found['updated_at'] = time();
            social_append_jsonl(social_reports_file(time()), social_normalize_report($found));
            social_log_moderation_locked($admin, 'report_' . $status, 'report', (int)$found['id'], (int)($found['post_id'] ?? 0), (string)($found['target_type'] ?? '') . ' şikâyeti: ' . $status);
            return ['errors' => []];
        });
    }

    function social_save_topic(array $admin, array $input): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.'], 'slug' => ''];
        social_ensure_storage();
        return social_with_lock(static function () use ($input): array {
            $existingSlug = social_slugify((string)($input['edit_slug'] ?? ''));
            $topics = [];
            foreach (social_read_json(social_topics_file(), social_default_topics()) as $i => $topic) {
                if (is_array($topic)) $topics[] = social_normalize_topic($topic, $i + 1);
            }
            $index = null;
            foreach ($topics as $i => $topic) if ($topic['slug'] === $existingSlug) $index = $i;
            $name = social_clean_text((string)($input['name'] ?? ''), 60);
            if (social_text_len($name) < 2) return ['errors' => ['Konu adı en az 2 karakter olmalı.'], 'slug' => $existingSlug];
            $slug = $existingSlug !== '' ? $existingSlug : social_slugify((string)($input['slug'] ?? '') ?: $name);
            if ($slug === '') return ['errors' => ['Konu adresi oluşturulamadı.'], 'slug' => ''];
            foreach ($topics as $i => $topic) {
                if ($i !== $index && ($topic['slug'] === $slug || social_fold($topic['name']) === social_fold($name))) {
                    return ['errors' => ['Bu konu adı veya adresi kullanılıyor.'], 'slug' => $existingSlug];
                }
            }
            $topic = social_normalize_topic([
                'slug' => $slug,
                'name' => $name,
                'group' => (string)($input['group'] ?? ($index !== null ? ($topics[$index]['group'] ?? '') : '')),
                'description' => (string)($input['description'] ?? ''),
                'icon' => (string)($input['icon'] ?? '💬'),
                'official_only' => !empty($input['official_only']),
                'active' => !empty($input['active']),
                'order' => (int)($input['order'] ?? ($index !== null ? $topics[$index]['order'] : count($topics) + 1)),
            ], count($topics) + 1);
            if ($index !== null) $topics[$index] = $topic; else $topics[] = $topic;
            usort($topics, static fn(array $a, array $b): int => [$a['order'], social_fold($a['name'])] <=> [$b['order'], social_fold($b['name'])]);
            social_write_json_atomic(social_topics_file(), $topics);
            if (!is_file(social_index_file('topic-' . $slug))) social_write_json_atomic(social_index_file('topic-' . $slug), [], false);
            return ['errors' => [], 'slug' => $slug];
        });
    }

    function social_delete_topic(array $admin, string $slug): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        $slug = social_slugify($slug);
        if ($slug === 'genel-sohbet') return ['errors' => ['Genel Sohbet konusu silinemez.']];
        return social_with_lock(static function () use ($slug): array {
            $items = social_read_json(social_index_file('topic-' . $slug), []);
            if ($items) return ['errors' => ['İçinde gönderi bulunan konu silinemez; önce pasife alabilirsin.']];
            $topics = [];
            $found = false;
            foreach (social_read_json(social_topics_file(), social_default_topics()) as $i => $topic) {
                if (!is_array($topic)) continue;
                $topic = social_normalize_topic($topic, $i + 1);
                if ($topic['slug'] === $slug) { $found = true; continue; }
                $topics[] = $topic;
            }
            if (!$found) return ['errors' => ['Konu bulunamadı.']];
            social_write_json_atomic(social_topics_file(), $topics);
            $index = social_index_file('topic-' . $slug);
            if (is_file($index)) @unlink($index);
            return ['errors' => []];
        });
    }

    function social_search_profiles(string $query, ?array $viewer = null, int $limit = 8): array
    {
        $query = social_fold(trim($query));
        if ($query === '') return [];
        $blocked = social_blocked_ids($viewer);
        $candidates = [];
        $official = social_official_profile();
        $candidates[0] = $official;
        if (function_exists('dg_all')) {
            foreach (dg_all('users') as $user) {
                $public = function_exists('dg_public_user') ? dg_public_user($user) : $user;
                $profile = social_get_profile((int)$public['id'], $public);
                if ($profile) $candidates[(int)$public['id']] = $profile;
            }
        }
        $out = [];
        foreach ($candidates as $id => $profile) {
            if (in_array((int)$id, $blocked, true)) continue;
            $haystack = social_fold(implode(' ', [$profile['username'], $profile['display_name'], $profile['bio'], implode(' ', $profile['favorite_games'])]));
            if (str_contains($haystack, $query)) $out[] = $profile;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    function social_trending_tags(int $limit = 10): array
    {
        $counts = [];
        foreach (social_read_json(social_index_file('latest'), []) as $item) {
            if (!is_array($item) || ($item['status'] ?? '') !== 'published') continue;
            foreach ((array)($item['tags'] ?? []) as $tag) {
                $tag = social_slugify((string)$tag);
                if ($tag !== '') $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    }

    function social_suggested_profiles(?array $viewer = null, int $limit = 4): array
    {
        social_ensure_storage();
        $viewerId = $viewer ? (int)$viewer['id'] : -1;
        $blocked = social_blocked_ids($viewer);
        $following = $viewer ? social_follows($viewerId)['following'] : [];
        $candidates = [];
        foreach ((array)(social_users_index()['summaries'] ?? []) as $summary) {
            if (!is_array($summary)) continue;
            $id = (int)($summary['user_id'] ?? -1);
            if ($id === $viewerId || in_array($id, $blocked, true) || in_array($id, $following, true)) continue;
            $profile = social_get_profile($id);
            if ($profile) $candidates[] = $profile;
        }
        usort($candidates, static function (array $a, array $b): int {
            $official = (int)!empty($b['is_official']) <=> (int)!empty($a['is_official']);
            if ($official !== 0) return $official;
            return [
                (int)($b['trust_level'] ?? 0),
                (int)($b['follower_count'] ?? 0),
                (int)($b['post_count'] ?? 0),
                (int)($b['comment_count'] ?? 0),
            ] <=> [
                (int)($a['trust_level'] ?? 0),
                (int)($a['follower_count'] ?? 0),
                (int)($a['post_count'] ?? 0),
                (int)($a['comment_count'] ?? 0),
            ];
        });
        return array_slice($candidates, 0, max(1, $limit));
    }

    function social_onboarding_status(array $user, ?array $profile = null): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['steps' => [], 'completed' => 0, 'total' => 0, 'percent' => 0, 'complete' => true, 'should_show' => false];
        }
        $profile = $profile ?: social_ensure_profile($user);
        $profileComplete = trim((string)($profile['bio'] ?? '')) !== ''
            && (!empty($profile['platforms']) || !empty($profile['favorite_games']));
        $followsOfficial = in_array(0, social_follows($userId)['following'], true);
        $ownPosts = social_list_posts(['author_id' => $userId, 'per_page' => 1, 'sort' => 'new'], $user);
        $hasPost = (int)($ownPosts['total'] ?? 0) > 0;
        $hasComment = !empty(social_user_comments($userId, $user, 1));
        $steps = [
            [
                'key' => 'profile',
                'label' => 'Profilini tamamla',
                'description' => 'Kısa bir biyografi ile platform veya favori oyunlarını ekle.',
                'complete' => $profileComplete,
                'url' => 'profil.php?kullanici=' . rawurlencode((string)$profile['username']) . '#profil-duzenle',
                'action' => 'Profili düzenle',
            ],
            [
                'key' => 'official',
                'label' => 'DeviceGame’i takip et',
                'description' => 'Duyurular ve topluluk etkinliklerini kaçırma.',
                'complete' => $followsOfficial,
                'url' => 'profil.php?kullanici=devicegame',
                'action' => 'Resmî hesabı aç',
            ],
            [
                'key' => 'intro',
                'label' => 'Kendini tanıt',
                'description' => 'Hangi platformda, son zamanlarda hangi oyunu oynuyorsun?',
                'complete' => $hasPost,
                'url' => 'yeni.php?konu=genel-sohbet&sablon=tanisma',
                'action' => 'Tanışma gönderisi',
            ],
            [
                'key' => 'comment',
                'label' => 'İlk yorumunu yap',
                'description' => 'Bir tartışmaya katılıp toplulukla tanış.',
                'complete' => $hasComment,
                'url' => 'index.php',
                'action' => 'Akışa git',
            ],
        ];
        $completed = count(array_filter($steps, static fn(array $step): bool => !empty($step['complete'])));
        $complete = $completed === count($steps);
        $isRecent = (int)($profile['joined_at'] ?? time()) >= time() - (90 * 86400);
        $lowActivity = ((int)($profile['approved_posts'] ?? 0) + (int)($profile['approved_comments'] ?? 0)) < 3;
        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'percent' => count($steps) ? (int)round(($completed * 100) / count($steps)) : 100,
            'complete' => $complete,
            'should_show' => !$complete && empty($user['is_admin']) && ($isRecent || $lowActivity),
        ];
    }

    function social_events(?array $viewer = null, int $limit = 100): array
    {
        $items = [];
        $page = 1;
        $pages = 1;
        do {
            $result = social_list_posts([
                'topic' => 'etkinlikler',
                'per_page' => 50,
                'page' => $page,
                'sort' => 'new',
            ], $viewer);
            foreach ((array)$result['items'] as $item) {
                if (is_array($item) && social_is_event_post($item)) $items[] = $item;
            }
            $pages = (int)($result['pages'] ?? 1);
            $page++;
        } while (count($items) < $limit && $page <= $pages && $page <= 4);

        $upcoming = [];
        $past = [];
        foreach ($items as $item) {
            if (social_event_is_open($item)) $upcoming[] = $item;
            else $past[] = $item;
        }
        usort($upcoming, static fn(array $a, array $b): int => [
            (int)(($a['meta'] ?? [])['event_at'] ?? 0), (int)($a['id'] ?? 0),
        ] <=> [
            (int)(($b['meta'] ?? [])['event_at'] ?? 0), (int)($b['id'] ?? 0),
        ]);
        usort($past, static fn(array $a, array $b): int => [
            (int)(($b['meta'] ?? [])['event_at'] ?? 0), (int)($b['id'] ?? 0),
        ] <=> [
            (int)(($a['meta'] ?? [])['event_at'] ?? 0), (int)($a['id'] ?? 0),
        ]);
        return [
            'upcoming' => array_slice($upcoming, 0, max(1, $limit)),
            'past' => array_slice($past, 0, max(1, $limit)),
        ];
    }

    function social_upcoming_events(?array $viewer = null, int $limit = 3): array
    {
        return array_slice(social_events($viewer, max(10, $limit))['upcoming'], 0, max(1, $limit));
    }

    function social_weekly_highlights(?array $viewer = null, int $limit = 3): array
    {
        social_ensure_storage();
        $blocked = social_blocked_ids($viewer);
        $since = time() - (7 * 86400);
        $items = [];
        foreach (social_read_json(social_index_file('latest'), []) as $item) {
            if (!is_array($item)) continue;
            $item = social_post_summary($item);
            if (!social_post_visible($item, $viewer, $blocked)) continue;
            if ((int)$item['last_activity_at'] < $since && (int)$item['created_at'] < $since) continue;
            $score = (int)$item['gg_count'] * 4
                + (int)$item['comment_count'] * 3
                + (int)(($item['poll'] ?? [])['total_votes'] ?? 0) * 2
                + (int)$item['rsvp_count'] * 2
                + (!empty($item['award']) ? 6 : 0);
            if ($score <= 0) continue;
            $item['_highlight_score'] = $score;
            $items[] = $item;
        }
        usort($items, static fn(array $a, array $b): int => [
            (int)($b['_highlight_score'] ?? 0), (int)($b['last_activity_at'] ?? 0), (int)($b['id'] ?? 0),
        ] <=> [
            (int)($a['_highlight_score'] ?? 0), (int)($a['last_activity_at'] ?? 0), (int)($a['id'] ?? 0),
        ]);
        $items = array_slice($items, 0, max(1, $limit));
        foreach ($items as $i => $item) unset($items[$i]['_highlight_score']);
        return array_values($items);
    }

    function social_sitemap_posts(int $limit = 5000): array
    {
        social_ensure_storage();
        $byId = [];
        $monthFiles = glob(social_dir('indexes') . '/month-*.json') ?: [];
        rsort($monthFiles);
        foreach ($monthFiles as $file) {
            foreach (social_read_json($file, []) as $item) {
                if (!is_array($item)) continue;
                $summary = social_post_summary($item);
                if (($summary['status'] ?? '') === 'published' && (int)$summary['id'] > 0) {
                    $byId[(int)$summary['id']] = $summary;
                }
            }
        }
        if (!$byId) {
            foreach (social_read_json(social_index_file('latest'), []) as $item) {
                if (!is_array($item)) continue;
                $summary = social_post_summary($item);
                if (($summary['status'] ?? '') === 'published' && (int)$summary['id'] > 0) {
                    $byId[(int)$summary['id']] = $summary;
                }
            }
        }
        $items = array_values($byId);
        usort($items, static fn(array $a, array $b): int => [
            max((int)$b['updated_at'], (int)$b['last_activity_at']), (int)$b['id'],
        ] <=> [
            max((int)$a['updated_at'], (int)$a['last_activity_at']), (int)$a['id'],
        ]);
        return array_slice($items, 0, max(1, $limit));
    }

    function social_sitemap_profiles(int $limit = 5000): array
    {
        social_ensure_storage();
        $profiles = [];
        foreach ((array)(social_users_index()['summaries'] ?? []) as $summary) {
            if (!is_array($summary)) continue;
            $id = (int)($summary['user_id'] ?? -1);
            if ($id < 0) continue;
            $profile = social_get_profile($id);
            if (!$profile) continue;
            $hasContent = !empty($profile['is_official'])
                || (int)($profile['post_count'] ?? 0) > 0
                || (int)($profile['comment_count'] ?? 0) > 0
                || trim((string)($profile['bio'] ?? '')) !== '';
            if ($hasContent) $profiles[] = $profile;
        }
        usort($profiles, static fn(array $a, array $b): int => [
            (int)($b['updated_at'] ?? 0), (int)($b['post_count'] ?? 0), (int)($b['user_id'] ?? 0),
        ] <=> [
            (int)($a['updated_at'] ?? 0), (int)($a['post_count'] ?? 0), (int)($a['user_id'] ?? 0),
        ]);
        return array_slice($profiles, 0, max(1, $limit));
    }

    function social_stats(bool $admin = false): array
    {
        social_ensure_storage();
        $latest = social_read_json(social_index_file('latest'), []);
        $published = $pending = $hidden = 0;
        $polls = $upcomingEvents = $awards = $awardDcoin = 0;
        $comments = $views = $solutions = 0;
        foreach ($latest as $item) {
            if (!is_array($item)) continue;
            if (($item['status'] ?? '') === 'published') {
                $published++;
                $summary = social_post_summary($item);
                $comments += (int)$summary['comment_count'];
                $views += (int)$summary['view_count'];
                if ((int)$summary['solution_comment_id'] > 0) $solutions++;
                if (!empty($summary['poll'])) $polls++;
                if (social_event_is_open($summary)) $upcomingEvents++;
                if (!empty($summary['award'])) {
                    $awards++;
                    $awardDcoin += (int)$summary['award']['amount'];
                }
            }
            if (($item['status'] ?? '') === 'pending') $pending++;
            if (($item['status'] ?? '') === 'hidden') $hidden++;
        }
        $users = social_users_index();
        $memberCount = 0;
        foreach ((array)($users['summaries'] ?? []) as $summary) {
            if (is_array($summary) && (int)($summary['user_id'] ?? 0) > 0) $memberCount++;
        }
        // Sosyal sayfayı henüz hiç açmamış DeviceGame hesapları da üyedir.
        // Çekirdek kullanıcı listesi okunabiliyorsa gerçek hesap sayısını esas al.
        if (function_exists('dg_read_data')) {
            try {
                $coreData = dg_read_data(true);
                $coreMembers = 0;
                foreach ((array)($coreData['users'] ?? []) as $coreUser) {
                    if (is_array($coreUser) && (int)($coreUser['is_banned'] ?? 0) !== 1) $coreMembers++;
                }
                $memberCount = max($memberCount, $coreMembers);
            } catch (Throwable) {
                // Çekirdek depolama okunamazsa sosyal indeks sayısı kullanılır.
            }
        }
        $stats = [
            'topics' => count(social_topics(true)),
            'posts' => $published,
            'comments' => $comments,
            'views' => $views,
            'solutions' => $solutions,
            'online' => count(social_online_users(null, 300, 500)),
            'members' => $memberCount,
            'pending_posts' => $pending,
            'hidden_posts' => $hidden,
            'pending_comments' => 0,
            'open_reports' => 0,
            'polls' => $polls,
            'upcoming_events' => $upcomingEvents,
            'awards' => $awards,
            'award_dcoin' => $awardDcoin,
        ];
        if ($admin) {
            foreach (social_moderation_comments(1000) as $comment) if (($comment['status'] ?? '') === 'pending') $stats['pending_comments']++;
            foreach (social_reports() as $report) if (($report['status'] ?? '') === 'open') $stats['open_reports']++;
            $stats['conversations'] = count(glob(social_dir('messages') . '/meta/*/*.json') ?: []);
            $chatMessages = 0;
            foreach (array_keys(social_chat_channels()) as $channel) {
                foreach (social_chat_message_map((string)$channel) as $message) {
                    if (($message['status'] ?? '') === 'published') $chatMessages++;
                }
            }
            $stats['chat_messages'] = $chatMessages;
            $stats['moderation_logs'] = count(social_moderation_logs(500));
        }
        return $stats;
    }

    function social_rebuild_indexes(): array
    {
        social_ensure_storage();
        return social_with_lock(static function (): array {
            $latest = [];
            $topics = [];
            $users = [];
            $months = [];
            $moderationComments = [];
            $userComments = [];
            $postCount = 0;
            $maxPostId = 0;
            $maxCommentId = 0;
            foreach (glob(social_dir('posts') . '/*/*.json') ?: [] as $file) {
                $post = social_normalize_post(social_read_json($file));
                if ($post['id'] <= 0) continue;
                $maxPostId = max($maxPostId, (int)$post['id']);
                foreach (social_comment_map((int)$post['id']) as $knownComment) {
                    $maxCommentId = max($maxCommentId, (int)($knownComment['id'] ?? 0));
                }
                if ($post['status'] === 'deleted') continue;
                $commentCount = 0;
                $latestComment = (int)$post['created_at'];
                $reactions = social_reactions((int)$post['id']);
                $commentMap = social_comment_map((int)$post['id']);
                $solutionId = (int)($post['solution_comment_id'] ?? 0);
                if ($solutionId > 0 && (($commentMap[$solutionId]['status'] ?? '') !== 'published')) {
                    $post['solution_comment_id'] = 0;
                }
                foreach ($commentMap as $comment) {
                    $comment['gg_count'] = count((array)(($reactions['comment_gg'] ?? [])[(string)(int)$comment['id']] ?? []));
                    if ($comment['status'] === 'published') {
                        $commentCount++;
                        $latestComment = max($latestComment, (int)$comment['created_at']);
                    }
                    if ($comment['status'] !== 'deleted') {
                        $summary = social_comment_summary($comment, $post);
                        $userComments[(int)$comment['user_id']][] = $summary;
                        if (in_array($comment['status'], ['pending', 'hidden'], true)) $moderationComments[] = $summary;
                    }
                }
                $post['comment_count'] = $commentCount;
                $reactions = social_reactions((int)$post['id']);
                $post['gg_count'] = count($reactions['gg']);
                if (!empty($post['poll'])) $post['poll'] = social_poll_with_counts((array)$post['poll'], $reactions);
                $post['rsvp_count'] = social_is_event_post($post) ? count($reactions['rsvp']) : 0;
                $post['watch_count'] = count((array)($reactions['watchers'] ?? []));
                if ($commentCount > 0) $post['last_activity_at'] = max((int)$post['last_activity_at'], $latestComment);
                $post = social_normalize_post($post);
                social_write_json_atomic($file, $post, false);
                $summary = social_post_summary($post);
                $latest[] = $summary;
                $topics[(string)$post['topic_slug']][] = $summary;
                $users[(int)$post['user_id']][] = $summary;
                $months[date('Y-m', (int)$post['created_at'])][] = $summary;
                $postCount++;
            }
            $latest = social_sort_summaries($latest);
            social_write_json_atomic(social_index_file('latest'), array_slice($latest, 0, 1000), false);
            foreach (glob(social_dir('indexes') . '/topic-*.json') ?: [] as $file) @unlink($file);
            foreach (glob(social_dir('indexes') . '/user-*.json') ?: [] as $file) @unlink($file);
            foreach (glob(social_dir('indexes') . '/month-*.json') ?: [] as $file) @unlink($file);
            foreach ($topics as $slug => $items) social_write_json_atomic(social_index_file('topic-' . $slug), array_slice(social_sort_summaries($items), 0, 1000), false);
            foreach ($users as $id => $items) social_write_json_atomic(social_index_file('user-' . $id), array_slice(social_sort_summaries($items), 0, 1000), false);
            foreach ($months as $month => $items) social_write_json_atomic(social_index_file('month-' . $month), social_sort_summaries($items), false);
            foreach ($userComments as $id => $items) {
                usort($items, static fn(array $a, array $b): int => [(int)$b['created_at'], (int)$b['id']] <=> [(int)$a['created_at'], (int)$a['id']]);
                social_write_json_atomic(social_index_file('user-comments-' . $id), array_slice($items, 0, 500), false);
            }
            usort($moderationComments, static fn(array $a, array $b): int => [(int)$b['created_at'], (int)$b['id']] <=> [(int)$a['created_at'], (int)$a['id']]);
            social_write_json_atomic(social_index_file('moderation-comments'), array_slice($moderationComments, 0, 1000), false);
            // Kullanıcı indeksini de profil dosyalarından sıfırdan kur; böylece
            // silinmiş/eski özetler kalmaz ve içeriksiz profillerin sayaçları da sıfırlanır.
            $profileIds = [];
            foreach (glob(social_dir('profiles') . '/*.json') ?: [] as $profileFile) {
                $profileId = basename($profileFile, '.json');
                if (ctype_digit($profileId)) $profileIds[] = (int)$profileId;
            }
            sort($profileIds);
            social_write_json_atomic(social_index_file('users'), ['map' => [], 'summaries' => []], false);
            foreach ($profileIds as $id) {
                social_sync_profile_post_counts_locked((int)$id);
                social_sync_profile_comment_counts_locked((int)$id);
            }
            $maxReportId = 0;
            foreach (social_reports() as $report) $maxReportId = max($maxReportId, (int)($report['id'] ?? 0));
            $maxNotificationId = 0;
            foreach (glob(social_dir('notifications') . '/*.json') ?: [] as $notificationFile) {
                foreach (social_read_json($notificationFile, []) as $notification) {
                    if (is_array($notification)) $maxNotificationId = max($maxNotificationId, (int)($notification['id'] ?? 0));
                }
            }
            // Konu takip ters indekslerini kullanıcı takip dosyalarından yeniden kur.
            foreach (glob(social_dir('indexes') . '/watch-topic-*.json') ?: [] as $file) @unlink($file);
            $topicWatchers = [];
            foreach (glob(social_dir('watches') . '/*.json') ?: [] as $watchFile) {
                $watchUserId = basename($watchFile, '.json');
                if (!ctype_digit($watchUserId)) continue;
                foreach ((array)(social_read_json($watchFile, [])['topics'] ?? []) as $watchedTopic) {
                    $watchedTopic = social_slugify((string)$watchedTopic);
                    if ($watchedTopic !== '') $topicWatchers[$watchedTopic][] = (int)$watchUserId;
                }
            }
            foreach ($topicWatchers as $watchedTopic => $ids) {
                social_write_json_atomic(social_index_file('watch-topic-' . $watchedTopic), ['user_ids' => array_values(array_unique($ids)), 'updated_at' => time()], false);
            }
            $conversationCount = social_dm_rebuild_indexes_locked();
            $maxChatMessageId = 0;
            foreach (array_keys(social_chat_channels()) as $channel) {
                foreach (social_chat_message_map((string)$channel) as $message) $maxChatMessageId = max($maxChatMessageId, (int)$message['id']);
            }
            $maxModlogId = 0;
            foreach (social_moderation_logs(1000) as $log) $maxModlogId = max($maxModlogId, (int)$log['id']);
            $meta = social_meta_locked();
            $meta['counters']['posts'] = max((int)($meta['counters']['posts'] ?? 1), $maxPostId + 1);
            $meta['counters']['comments'] = max((int)($meta['counters']['comments'] ?? 1), $maxCommentId + 1);
            $meta['counters']['reports'] = max((int)($meta['counters']['reports'] ?? 1), $maxReportId + 1);
            $meta['counters']['notifications'] = max((int)($meta['counters']['notifications'] ?? 1), $maxNotificationId + 1);
            $meta['counters']['chat_messages'] = max((int)($meta['counters']['chat_messages'] ?? 1), $maxChatMessageId + 1);
            $meta['counters']['modlogs'] = max((int)($meta['counters']['modlogs'] ?? 1), $maxModlogId + 1);
            $meta['updated_at'] = time();
            social_write_json_atomic(social_meta_file(), $meta);
            return ['errors' => [], 'posts' => $postCount, 'comments' => array_sum(array_map('count', $userComments)), 'conversations' => $conversationCount];
        });
    }

    function social_storage_stats(): array
    {
        social_ensure_storage();
        $files = 0;
        $bytes = 0;
        foreach ([social_storage_dir(), social_upload_dir()] as $dir) {
            if (!is_dir($dir)) continue;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files++;
                    $bytes += (int)$file->getSize();
                }
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    function social_directory_stats(string $dir): array
    {
        $files = 0;
        $bytes = 0;
        $latestMtime = 0;
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $files++;
                $bytes += (int)$file->getSize();
                $latestMtime = max($latestMtime, (int)$file->getMTime());
            }
        }
        return ['files' => $files, 'bytes' => $bytes, 'latest_mtime' => $latestMtime];
    }

    function social_health_report(): array
    {
        social_ensure_storage();
        $checks = [];
        $addCheck = static function (string $label, bool $ok, string $detail, string $level = 'error') use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'level' => $ok ? 'ok' : $level];
        };

        $storageDir = social_storage_dir();
        $uploadDir = social_upload_dir();
        $addCheck('Sosyal veri klasörü', is_dir($storageDir) && is_writable($storageDir), is_dir($storageDir) ? (is_writable($storageDir) ? 'storage/social yazılabilir.' : 'storage/social yazılamıyor.') : 'storage/social bulunamıyor.');
        $addCheck('Görsel klasörü', is_dir($uploadDir) && is_writable($uploadDir), is_dir($uploadDir) ? (is_writable($uploadDir) ? 'uploads/social yazılabilir.' : 'uploads/social yazılamıyor.') : 'uploads/social bulunamıyor.');
        $lockFile = social_lock_file();
        $addCheck('Veri kilidi', is_file($lockFile) ? is_writable($lockFile) : is_writable($storageDir), is_file($lockFile) ? 'social.lock mevcut.' : 'Kilit ilk yazmada oluşur.');

        $meta = [];
        try {
            $meta = social_meta_locked();
            $addCheck('Meta dosyası', true, 'meta.json okunabiliyor.');
        } catch (Throwable $exception) {
            $addCheck('Meta dosyası', false, $exception->getMessage());
        }

        $topicCount = 0;
        try {
            $topicCount = count(social_topics(false));
            $addCheck('Konu listesi', $topicCount > 0, $topicCount . ' konu tanımlı.');
        } catch (Throwable $exception) {
            $addCheck('Konu listesi', false, $exception->getMessage());
        }

        $indexEntries = 0;
        try {
            $indexEntries = count(social_read_json(social_index_file('latest'), []));
            $addCheck('Akış indeksi', true, $indexEntries . ' özet kaydı var.');
        } catch (Throwable $exception) {
            $addCheck('Akış indeksi', false, $exception->getMessage());
        }

        $postFiles = glob(social_dir('posts') . '/*/*.json') ?: [];
        $invalidPosts = 0;
        $referencedImages = [];
        foreach ($postFiles as $file) {
            $raw = @file_get_contents($file);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (!is_array($decoded)) {
                $invalidPosts++;
                continue;
            }
            $post = social_normalize_post($decoded);
            if (($post['status'] ?? '') !== 'deleted' && trim((string)$post['image']) !== '') {
                $referencedImages[ltrim(str_replace('\\', '/', (string)$post['image']), '/')] = true;
            }
        }
        foreach (glob(social_dir('profiles') . '/*.json') ?: [] as $profileFile) {
            try {
                $profile = social_read_json($profileFile, []);
                if (trim((string)($profile['avatar'] ?? '')) !== '') {
                    $referencedImages[ltrim(str_replace('\\', '/', (string)$profile['avatar']), '/')] = true;
                }
            } catch (Throwable) {
                // Bozuk profil, gönderi bütünlük kontrolünden ayrı olarak yedek incelemesinde ele alınır.
            }
        }
        $addCheck('Gönderi JSON bütünlüğü', $invalidPosts === 0, count($postFiles) . ' gönderi dosyası, ' . $invalidPosts . ' bozuk kayıt.');
        $messagingWritable = is_dir(social_dir('messages')) && is_writable(social_dir('messages')) && is_dir(social_dir('chats')) && is_writable(social_dir('chats'));
        $addCheck('Mesajlaşma ve canlı sohbet', $messagingWritable, $messagingWritable ? 'messages/ ve chats/ yazılabilir.' : 'messages/ veya chats/ yazılamıyor.');

        $uploadFiles = [];
        $orphanImages = 0;
        $orphanBytes = 0;
        if (is_dir($uploadDir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getFilename() === 'index.html') continue;
                $relative = 'uploads/social/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploadDir) + 1));
                $uploadFiles[$relative] = (int)$file->getSize();
                if (!isset($referencedImages[$relative])) {
                    $orphanImages++;
                    $orphanBytes += (int)$file->getSize();
                }
            }
        }
        $missingImages = 0;
        foreach (array_keys($referencedImages) as $relative) {
            if (!isset($uploadFiles[$relative]) && !is_file(social_base_dir() . '/' . $relative)) $missingImages++;
        }
        $addCheck('Görsel başvuruları', $missingImages === 0, $missingImages . ' eksik, ' . $orphanImages . ' sahipsiz görsel.', $missingImages > 0 ? 'error' : 'warning');

        $freeBytes = @disk_free_space($storageDir);
        $freeBytes = $freeBytes === false ? 0 : (int)$freeBytes;
        $addCheck('Boş disk', $freeBytes === 0 || $freeBytes > 10 * 1024 * 1024, $freeBytes > 0 ? number_format($freeBytes / 1048576, 1, ',', '.') . ' MB boş alan.' : 'Boş alan okunamadı.', 'warning');

        $lastBackupAt = (int)($meta['last_backup_at'] ?? 0);
        $backupAgeDays = $lastBackupAt > 0 ? (int)floor((time() - $lastBackupAt) / 86400) : null;
        $addCheck(
            'Yedek işareti',
            $lastBackupAt > 0 && $backupAgeDays !== null && $backupAgeDays <= 7,
            $lastBackupAt > 0 ? 'Son işaret: ' . date('d.m.Y H:i', $lastBackupAt) . ' (' . $backupAgeDays . ' gün önce).' : 'Henüz yedek alındığı işaretlenmemiş.',
            'warning'
        );

        $breakdown = [];
        foreach (['profiles', 'posts', 'comments', 'reactions', 'follows', 'saves', 'notifications', 'reports', 'indexes', 'ratelimits', 'messages', 'chats', 'watches', 'reads', 'presence', 'logs'] as $name) {
            $breakdown['storage/social/' . $name] = social_directory_stats(social_dir($name));
        }
        $breakdown['uploads/social'] = social_directory_stats($uploadDir);

        $healthy = true;
        foreach ($checks as $check) {
            if (!$check['ok'] && $check['level'] === 'error') $healthy = false;
        }
        return [
            'healthy' => $healthy,
            'checks' => $checks,
            'breakdown' => $breakdown,
            'totals' => social_storage_stats(),
            'topics' => $topicCount,
            'post_files' => count($postFiles),
            'invalid_posts' => $invalidPosts,
            'index_entries' => $indexEntries,
            'missing_images' => $missingImages,
            'orphan_images' => $orphanImages,
            'orphan_bytes' => $orphanBytes,
            'free_bytes' => $freeBytes,
            'last_backup_at' => $lastBackupAt,
            'last_backup_by' => (int)($meta['last_backup_by'] ?? 0),
            'meta_updated_at' => (int)($meta['updated_at'] ?? 0),
            'latest_index_mtime' => is_file(social_index_file('latest')) ? (int)@filemtime(social_index_file('latest')) : 0,
        ];
    }

    function social_mark_backup(array $admin): array
    {
        if (empty($admin['is_admin'])) return ['errors' => ['Bu işlem için yönetici yetkisi gerekir.']];
        social_ensure_storage();
        return social_with_lock(static function () use ($admin): array {
            $meta = social_meta_locked();
            $meta['last_backup_at'] = time();
            $meta['last_backup_by'] = (int)$admin['id'];
            $meta['updated_at'] = time();
            social_write_json_atomic(social_meta_file(), $meta);
            return ['errors' => [], 'last_backup_at' => (int)$meta['last_backup_at']];
        });
    }

    function social_render_text(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $codeBlocks = [];
        $text = preg_replace_callback('/```[A-Za-z0-9_-]*\n?(.*?)```/su', static function (array $m) use (&$codeBlocks): string {
            $index = count($codeBlocks);
            $codeBlocks[] = (string)$m[1];
            return "\n\u{E010}" . $index . "\u{E011}\n";
        }, $text) ?? $text;
        $codeSpans = [];
        $text = preg_replace_callback('/`([^`\n]{1,240})`/u', static function (array $m) use (&$codeSpans): string {
            $index = count($codeSpans);
            $codeSpans[] = (string)$m[1];
            return "\u{E000}" . $index . "\u{E001}";
        }, $text) ?? $text;

        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        static $users = null;
        if ($users === null) {
            $users = social_users_index()['map'] ?? [];
            // Profili henüz indekslenmemiş mevcut DeviceGame hesaplarının bahsetmelerini de bağla.
            if (function_exists('dg_all')) {
                foreach (dg_all('users') as $coreUser) {
                    if (!is_array($coreUser)) continue;
                    $key = social_fold((string)($coreUser['username'] ?? ''));
                    if ($key !== '' && !isset($users[$key])) $users[$key] = (int)($coreUser['id'] ?? 0);
                }
            }
        }
        $linked = preg_replace_callback(
            '~(https?://[^\s<]+)|(?<![A-Za-z0-9_.-])@([A-Za-z0-9_.-]{3,32})|(?<![A-Za-z0-9_])#([A-Za-z0-9_\-\x{00C0}-\x{024F}]{2,30})~u',
            static function (array $m) use ($users): string {
                if (($m[1] ?? '') !== '') {
                    $url = $m[1];
                    return '<a href="' . $url . '" target="_blank" rel="nofollow ugc noopener">' . $url . '</a>';
                }
                if (($m[2] ?? '') !== '') {
                    $username = rtrim((string)$m[2], '.-');
                    if (isset($users[social_fold($username)])) {
                        return '<a href="profil.php?kullanici=' . rawurlencode($username) . '">@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</a>' . htmlspecialchars(substr((string)$m[2], strlen($username)), ENT_QUOTES, 'UTF-8');
                    }
                    return $m[0];
                }
                if (($m[3] ?? '') !== '') {
                    $tag = social_slugify((string)$m[3]);
                    return '<a href="index.php?q=' . rawurlencode('#' . $tag) . '">#' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '</a>';
                }
                return $m[0];
            },
            $escaped
        ) ?? $escaped;

        $inline = static function (string $value) use ($codeSpans): string {
            $value = preg_replace('/\*\*([^*\n<>]{1,400})\*\*/u', '<strong>$1</strong>', $value) ?? $value;
            $value = preg_replace('/(?<!\*)\*([^*\n<>]{1,400})\*(?!\*)/u', '<em>$1</em>', $value) ?? $value;
            $value = preg_replace('/~~([^~\n<>]{1,400})~~/u', '<del>$1</del>', $value) ?? $value;
            $value = preg_replace('/\|\|([^|\n<>]{1,400})\|\|/u', '<span class="social-spoiler" tabindex="0" title="Spoileri göster">$1</span>', $value) ?? $value;
            $value = preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', static function (array $m) use ($codeSpans): string {
                return '<code>' . htmlspecialchars((string)($codeSpans[(int)$m[1]] ?? ''), ENT_QUOTES, 'UTF-8') . '</code>';
            }, $value) ?? $value;
            return $value;
        };

        $lines = preg_split('/\n/', trim($linked)) ?: [];
        $html = [];
        $paragraph = [];
        $quote = [];
        $list = [];
        $listType = '';
        $flushParagraph = static function () use (&$html, &$paragraph, $inline): void {
            if (!$paragraph) return;
            $html[] = '<p>' . $inline(implode("<br>\n", $paragraph)) . '</p>';
            $paragraph = [];
        };
        $flushQuote = static function () use (&$html, &$quote, $inline): void {
            if (!$quote) return;
            $html[] = '<blockquote>' . $inline(implode("<br>\n", $quote)) . '</blockquote>';
            $quote = [];
        };
        $flushList = static function () use (&$html, &$list, &$listType, $inline): void {
            if (!$list) return;
            $tag = $listType === 'ol' ? 'ol' : 'ul';
            $items = '';
            foreach ($list as $item) $items .= '<li>' . $inline($item) . '</li>';
            $html[] = '<' . $tag . '>' . $items . '</' . $tag . '>';
            $list = [];
            $listType = '';
        };
        $flushAll = static function () use ($flushParagraph, $flushQuote, $flushList): void {
            $flushParagraph();
            $flushQuote();
            $flushList();
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushAll();
                continue;
            }
            if (preg_match('/^\x{E010}(\d+)\x{E011}$/u', $trimmed, $m)) {
                $flushAll();
                $html[] = '<pre><code>' . htmlspecialchars(trim((string)($codeBlocks[(int)$m[1]] ?? '')), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                continue;
            }
            if (preg_match('/^&gt;\s?(.*)$/u', $trimmed, $m)) {
                $flushParagraph();
                $flushList();
                $quote[] = (string)$m[1];
                continue;
            }
            if (preg_match('/^[-•]\s+(.+)$/u', $trimmed, $m)) {
                $flushParagraph();
                $flushQuote();
                if ($listType !== '' && $listType !== 'ul') $flushList();
                $listType = 'ul';
                $list[] = (string)$m[1];
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $m)) {
                $flushParagraph();
                $flushQuote();
                if ($listType !== '' && $listType !== 'ol') $flushList();
                $listType = 'ol';
                $list[] = (string)$m[1];
                continue;
            }
            if (preg_match('/^#{2,3}\s+(.+)$/u', $trimmed, $m)) {
                $flushAll();
                $tag = str_starts_with($trimmed, '###') ? 'h4' : 'h3';
                $html[] = '<' . $tag . '>' . $inline((string)$m[1]) . '</' . $tag . '>';
                continue;
            }
            $flushQuote();
            $flushList();
            $paragraph[] = $line;
        }
        $flushAll();
        return implode("\n", $html);
    }

    function social_avatar_initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') return 'DG';
        $parts = preg_split('/\s+/u', $name) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($part, 0, 1));
        }
        return $initials ?: 'DG';
    }

    function social_avatar_hue(int $userId): int
    {
        return (abs($userId) * 47 + 145) % 360;
    }
}
