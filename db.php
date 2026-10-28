<?php
/**
 * DEVICEGAME — Veritabansız Uygulama Çekirdeği
 * db.php v3.0 — JSON dosya depolama (MySQL/PDO yok)
 *
 * Her sayfanın en üstünde: require_once __DIR__ . '/db.php';
 *
 * Veriler: storage/dg/database.json
 * - İlk açılışta otomatik oluşturulur ve örnek verilerle doldurulur.
 * - flock() kilidi + geçici dosyaya yazıp rename() ile atomik güncelleme yapılır.
 */
declare(strict_types=1);

// Çift yükleme koruması
if (defined('DG_APP_LOADED')) { return; }
define('DG_APP_LOADED', true);

// ——— DEBUG MODU ———
// Ortam değişkeni DG_DEBUG=1 veya URL parametresi ?debug=1
$__dg_debug_env = getenv('DG_DEBUG') ?: ($_ENV['DG_DEBUG'] ?? '');
$__dg_debug = ($__dg_debug_env === '1') || (isset($_GET['debug']) && $_GET['debug'] === '1');
if ($__dg_debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

// Site Türkiye saatine göre günlük limit hesaplasın.
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Europe/Istanbul');
}

// ——— Oturum — güvenli çerez ———
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') === '443')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    @session_start();
}

// ——— Sabitler ———
define('DG_DAILY_LIMIT_DEFAULT', 5);
define('DG_AUTH_VERSION', 3); // Eski MySQL sürümünün oturum ID'leri yeni JSON kayıtlarıyla karışmasın
define('DG_UPLOAD_DIR', __DIR__ . '/uploads');
define('DG_UPLOAD_URL', 'uploads/');
define('DG_STORAGE_DIR', __DIR__ . '/storage/dg');
define('DG_DATA_FILE', DG_STORAGE_DIR . '/database.json');
define('DG_LOCK_FILE', DG_STORAGE_DIR . '/database.lock');

// ——— Genel yardımcılar ———
if (!function_exists('e')) {
    function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// lib/shop.php de csrf_token() tanımlar — redeclare olmaması için guard'lı.
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token): bool {
        return !empty($_SESSION['csrf']) && !empty($token) && hash_equals($_SESSION['csrf'], (string)$token);
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id'])
        && (int)($_SESSION['dg_auth_v'] ?? 0) === DG_AUTH_VERSION;
}
function is_admin(): bool { return !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1; }
function current_user_id(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }

/** Alt klasörden (admin/api/blog/sosyal) de doğru login adresini üretir. */
function dg_login_url(): string {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    foreach (['/admin', '/api', '/blog', '/sosyal'] as $subdir) {
        if (substr($dir, -strlen($subdir)) === $subdir) return '../login.php';
    }
    return 'login.php';
}

function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['flash_error'] = 'Devam etmek için giriş yapmalısın.';
        header('Location: ' . dg_login_url()); exit;
    }
}
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('<h1>403 — Yetkisiz erişim</h1><p>Bu alana sadece yöneticiler girebilir.</p><a href="../index.php">Ana sayfaya dön</a>');
    }
}

/** Para formatı */
function tl_format($n): string {
    return number_format((float)$n, 2, ',', '.') . ' ₺';
}

/**
 * Flash mesajlar.
 * Eski mağaza $_SESSION['flash'] alanını düz metin olarak kullanır; D-Coin
 * tarafı bu alanla çakışmaması için kendi dg_flash anahtarında çalışır.
 */
function flash_set(string $msg, string $type = 'success'): void { $_SESSION['dg_flash'] = ['msg' => $msg, 'type' => $type]; }
function flash_get(): ?array {
    $f = $_SESSION['dg_flash'] ?? null;
    unset($_SESSION['dg_flash']);
    if (is_array($f)) return $f;

    // Önceki sürümlerden kalan dizi biçimindeki flash kaydını da güvenle al.
    if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
function flash_error_set(string $msg): void { $_SESSION['flash_error'] = $msg; }
function flash_error_get(): ?string { $m = $_SESSION['flash_error'] ?? null; unset($_SESSION['flash_error']); return $m; }

/** Uygulamada kullanılan tarih formatı (eski DATETIME görünümüyle uyumlu). */
function dg_now(): string { return date('Y-m-d H:i:s'); }

/** Büyük/küçük harf duyarsız karşılaştırmalarda güvenli katlama. */
function dg_casefold(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** İlk kurulum verileri. Admin/oyuncu şifresi: Admin123! */
function dg_seed_data(): array {
    $now = dg_now();
    $hash = '$2y$10$4ZK5MehAkI2ZYLCA1tB5K.PgMrc8A7XLtbBzbIqYcbhXDILtI9Y9G';

    // Mağaza ürünleri uydurma örneklerle değil, eski sitenin gerçek
    // kataloğundan (storage/products.json) ilk açılışta içe aktarılır.
    $items = [];
    $keyRows = [];

    return [
        'meta' => ['version' => 1, 'created_at' => $now, 'updated_at' => $now],
        'counters' => [
            'users' => 3, 'ads' => 4, 'watch_logs' => 1, 'watch_sessions' => 1,
            'store_items' => 1, 'store_keys' => 1, 'purchases' => 1,
        ],
        'settings' => [
            'daily_watch_limit' => 5,
            'site_name' => 'Device Game',
            'legacy_catalog_hash' => '',
            'legacy_seed_cleanup_v1' => 0,
        ],
        'users' => [
            [
                'id' => 1, 'username' => 'admin', 'email' => 'admin@devicegame.com',
                'password_hash' => $hash, 'dcoin_balance' => 1000, 'is_admin' => 1, 'is_banned' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 2, 'username' => 'oyuncu1', 'email' => 'oyuncu1@devicegame.com',
                'password_hash' => $hash, 'dcoin_balance' => 250, 'is_admin' => 0, 'is_banned' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ],
        'ads' => [
            [
                'id' => 1, 'title' => 'Device Game — Tanıtım Videosu',
                'video_url' => 'https://storage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
                'video_path' => null, 'thumbnail_url' => null, 'reward_dcoin' => 15,
                'duration_sec' => 30, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 2, 'title' => 'Project: Overcharge — Oynanış Videosu',
                'video_url' => 'https://storage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
                'video_path' => null, 'thumbnail_url' => null, 'reward_dcoin' => 20,
                'duration_sec' => 45, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 3, 'title' => 'Mağaza Seçkisi — Kısa Tanıtım',
                'video_url' => 'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
                'video_path' => null, 'thumbnail_url' => null, 'reward_dcoin' => 10,
                'duration_sec' => 20, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
        ],
        'watch_logs' => [],
        'watch_sessions' => [],
        'store_items' => $items,
        'store_keys' => $keyRows,
        'purchases' => [],
    ];
}

/** Eksik üst düzey alanları tamamlar; mevcut kullanıcı verisini asla seed ile ezmez. */
function dg_normalize_data(array $data): array {
    $seed = dg_seed_data();
    // Mini oyun (bkz. MINI-OYUN.md): D-Coin defteri, kozmetik sahipliği,
    // kuşanım ve günlük görev talepleri. Hepsi aynı çekirdek kilit altında
    // yazılır; şema eklemeli olduğu için mevcut veri bozulmaz.
    $collections = [
        'users', 'ads', 'watch_logs', 'watch_sessions', 'store_items', 'store_keys', 'purchases',
        'minigame_ledger', 'minigame_entitlements', 'minigame_loadouts', 'minigame_task_claims',
    ];

    if (!isset($data['meta']) || !is_array($data['meta'])) $data['meta'] = [];
    $data['meta'] += ['version' => 1, 'created_at' => dg_now(), 'updated_at' => dg_now()];

    if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings'] = [];
    $data['settings'] += $seed['settings'];

    foreach ($collections as $name) {
        if (!isset($data[$name]) || !is_array($data[$name])) $data[$name] = [];
        $data[$name] = array_values($data[$name]);
    }

    if (!isset($data['counters']) || !is_array($data['counters'])) $data['counters'] = [];
    foreach ($collections as $name) {
        $max = 0;
        foreach ($data[$name] as $row) {
            if (isset($row['id'])) $max = max($max, (int)$row['id']);
        }
        $next = isset($data['counters'][$name]) ? (int)$data['counters'][$name] : 0;
        $data['counters'][$name] = max(1, $next, $max + 1);
    }

    return $data;
}

/** JSON verisini aynı klasöre atomik şekilde yazar. */
function dg_write_data(array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Veri JSON olarak yazılamadı: ' . json_last_error_msg());
    }

    $tmp = DG_DATA_FILE . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json . "\n") === false) {
        throw new RuntimeException('Geçici veri dosyası yazılamadı. storage/dg/ yazma izinlerini kontrol edin.');
    }
    @chmod($tmp, 0664);
    if (!@rename($tmp, DG_DATA_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('Veri dosyası güncellenemedi. storage/dg/database.json yazma izinlerini kontrol edin.');
    }
    $GLOBALS['DG_DATA_CACHE'] = $data;
}

/** Veri dosyasını okur. Dosya yoksa seed verisi döner (bootstrap normalde dosyayı oluşturur). */
function dg_read_data(bool $fresh = false): array {
    if (!$fresh && isset($GLOBALS['DG_DATA_CACHE']) && is_array($GLOBALS['DG_DATA_CACHE'])) {
        return $GLOBALS['DG_DATA_CACHE'];
    }

    if (!is_file(DG_DATA_FILE)) {
        $GLOBALS['DG_DATA_CACHE'] = dg_normalize_data(dg_seed_data());
        return $GLOBALS['DG_DATA_CACHE'];
    }

    $raw = @file_get_contents(DG_DATA_FILE);
    if ($raw === false) {
        throw new RuntimeException('storage/dg/database.json okunamadı. Dosya izinlerini kontrol edin.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('storage/dg/database.json bozuk görünüyor. Dosyayı yedekleyip JSON sözdizimini kontrol edin; sistem veriyi kendiliğinden silmez.');
    }

    $GLOBALS['DG_DATA_CACHE'] = dg_normalize_data($decoded);
    return $GLOBALS['DG_DATA_CACHE'];
}

/**
 * Kilitli ve atomik değişiklik çalıştırır.
 * Callback: function(array &$data) { ... return $result; }
 */
function dg_mutate(callable $callback) {
    if (!is_dir(DG_STORAGE_DIR) && !@mkdir(DG_STORAGE_DIR, 0775, true) && !is_dir(DG_STORAGE_DIR)) {
        throw new RuntimeException('storage/dg/ klasörü oluşturulamadı. storage/ yazma iznini 755/775 yapın.');
    }

    $lock = @fopen(DG_LOCK_FILE, 'c+');
    if ($lock === false) {
        throw new RuntimeException('storage/dg/database.lock oluşturulamadı. storage/dg/ yazma izinlerini kontrol edin.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Veri dosyası kilitlenemedi. Lütfen tekrar deneyin.');
        }
        $data = dg_read_data(true);
        $result = $callback($data);
        $data = dg_normalize_data($data);
        $data['meta']['updated_at'] = dg_now();
        dg_write_data($data);
        return $result;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/** İlk açılışta storage klasörünü ve veri dosyasını oluşturur. */
function dg_ensure_storage(): void {
    if (!is_dir(DG_STORAGE_DIR) && !@mkdir(DG_STORAGE_DIR, 0775, true) && !is_dir(DG_STORAGE_DIR)) {
        throw new RuntimeException('storage/dg/ klasörü oluşturulamadı. storage/ klasörüne yazma izni verin (755/775).');
    }
    if (!is_writable(DG_STORAGE_DIR)) {
        throw new RuntimeException('storage/dg/ yazılabilir değil. Klasör iznini 755/775 yapın.');
    }
    if (!is_dir(DG_UPLOAD_DIR)) @mkdir(DG_UPLOAD_DIR, 0775, true);

    $guard = DG_STORAGE_DIR . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    $index = DG_STORAGE_DIR . '/index.html';
    if (!is_file($index)) @file_put_contents($index, '');

    if (!is_file(DG_DATA_FILE)) {
        $legacyCatalog = dg_legacy_catalog_from_files();
        dg_mutate(function (array &$data) use ($legacyCatalog): void {
            // Dosya yoksa dg_read_data(true) seed döner; eski mağaza kataloğu
            // ilk veri dosyasıyla birlikte kilit altında içe aktarılır.
            if ($legacyCatalog) {
                dg_apply_legacy_catalog($data, $legacyCatalog);
            }
        });
    } elseif (!is_readable(DG_DATA_FILE) || !is_writable(DG_DATA_FILE)) {
        throw new RuntimeException('storage/dg/database.json okunabilir/yazılabilir değil. Dosya iznini 644/664 yapın.');
    }
}

/** Koleksiyonu okur. */
function dg_all(string $collection): array {
    $data = dg_read_data();
    return isset($data[$collection]) && is_array($data[$collection]) ? $data[$collection] : [];
}

/** ID ile kayıt bulur. */
function dg_find_by_id(array $rows, int $id): ?array {
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) === $id) return $row;
    }
    return null;
}

/** ID'nin dizi içindeki indeksini bulur. */
function dg_index_of_id(array $rows, int $id): ?int {
    foreach ($rows as $i => $row) {
        if ((int)($row['id'] ?? 0) === $id) return (int)$i;
    }
    return null;
}

/** ID → kayıt haritası üretir. */
function dg_index_by_id(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) $map[(int)$row['id']] = $row;
    }
    return $map;
}

/** Yeni ID üretir ve sayacı ilerletir. Yalnızca dg_mutate içinde kullanın. */
function dg_next_id(array &$data, string $collection): int {
    $id = (int)($data['counters'][$collection] ?? 1);
    if ($id < 1) $id = 1;
    foreach ($data[$collection] ?? [] as $row) {
        $id = max($id, (int)($row['id'] ?? 0) + 1);
    }
    $data['counters'][$collection] = $id + 1;
    return $id;
}

/** Kayıtları created_at (yoksa verilen alan) ve ID'ye göre yeniden eskiye sıralar. */
function dg_sort_newest(array $rows, string $dateField = 'created_at'): array {
    usort($rows, function (array $a, array $b) use ($dateField): int {
        $cmp = strcmp((string)($b[$dateField] ?? ''), (string)($a[$dateField] ?? ''));
        return $cmp !== 0 ? $cmp : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    });
    return $rows;
}

/** Kullanıcı kaydının oturum/arayüzde kullanılacak güvenli alanları. */
function dg_public_user(array $u): array {
    return [
        'id' => (int)$u['id'],
        'username' => (string)($u['username'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'dcoin_balance' => (int)($u['dcoin_balance'] ?? 0),
        'is_admin' => (int)($u['is_admin'] ?? 0),
        'is_banned' => (int)($u['is_banned'] ?? 0),
        'created_at' => (string)($u['created_at'] ?? ''),
        'updated_at' => (string)($u['updated_at'] ?? ''),
    ];
}

function dg_find_user_by_id(int $id): ?array {
    $u = dg_find_by_id(dg_all('users'), $id);
    return $u ? dg_public_user($u) : null;
}

/** Giriş için hash dahil tam kullanıcı kaydı (yalnızca auth.php kullanır). */
function dg_find_user_for_login(string $login): ?array {
    $needle = dg_casefold(trim($login));
    foreach (dg_all('users') as $u) {
        if (dg_casefold((string)($u['username'] ?? '')) === $needle || dg_casefold((string)($u['email'] ?? '')) === $needle) {
            return $u;
        }
    }
    return null;
}

/** Kayıt işlemini kilit altında yapar; aynı kullanıcı adı/e-posta yarışını engeller. */
function dg_create_user(string $username, string $email, string $passwordHash): array {
    return dg_mutate(function (array &$data) use ($username, $email, $passwordHash): array {
        $uNeedle = dg_casefold($username);
        $eNeedle = dg_casefold($email);
        foreach ($data['users'] as $u) {
            if (dg_casefold((string)$u['username']) === $uNeedle || dg_casefold((string)$u['email']) === $eNeedle) {
                return ['ok' => false, 'error' => 'Bu kullanıcı adı veya e-posta zaten kayıtlı.'];
            }
        }

        $now = dg_now();
        $user = [
            'id' => dg_next_id($data, 'users'),
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'dcoin_balance' => 0,
            'is_admin' => 0,
            'is_banned' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $data['users'][] = $user;
        return ['ok' => true, 'user' => dg_public_user($user)];
    });
}

/** Kullanıcı alanlarını kilit altında günceller. */
function dg_update_user(int $id, array $fields): bool {
    return (bool)dg_mutate(function (array &$data) use ($id, $fields): bool {
        $i = dg_index_of_id($data['users'], $id);
        if ($i === null) return false;
        foreach ($fields as $k => $v) $data['users'][$i][$k] = $v;
        $data['users'][$i]['updated_at'] = dg_now();
        return true;
    });
}

/** D-Coin bakiyesini çekirdek kilidi altında güvenle artırır/azaltır. */
function dg_adjust_user_balance(int $id, int $amount): array {
    if ($id <= 0 || $amount === 0) {
        return ['ok' => false, 'error' => 'Geçersiz bakiye işlemi.', 'balance' => 0];
    }
    return dg_mutate(function (array &$data) use ($id, $amount): array {
        $i = dg_index_of_id($data['users'], $id);
        if ($i === null) return ['ok' => false, 'error' => 'Kullanıcı bulunamadı.', 'balance' => 0];
        $balance = (int)($data['users'][$i]['dcoin_balance'] ?? 0);
        if ($balance + $amount < 0) {
            return ['ok' => false, 'error' => 'Bakiye yetersiz.', 'balance' => $balance];
        }
        $data['users'][$i]['dcoin_balance'] = $balance + $amount;
        $data['users'][$i]['updated_at'] = dg_now();
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id) {
            $_SESSION['dcoin_balance'] = $balance + $amount;
        }
        return ['ok' => true, 'error' => '', 'balance' => $balance + $amount];
    });
}

/** Güncel kullanıcıyı veri dosyasından çeker (bakiye güncel olsun). */
function current_user(): ?array {
    if (!is_logged_in()) return null;

    $u = dg_find_user_by_id((int)$_SESSION['user_id']);
    if (!$u) {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['email'], $_SESSION['is_admin'], $_SESSION['dcoin_balance'], $_SESSION['dg_auth_v']);
        return null;
    }

    $_SESSION['username'] = $u['username'];
    $_SESSION['email'] = $u['email'];
    $_SESSION['dcoin_balance'] = (int)$u['dcoin_balance'];
    $_SESSION['is_admin'] = (int)$u['is_admin'];

    if ((int)$u['is_banned'] === 1) {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $isApi = strpos($script, '/api/') !== false;
        if (!$isApi) {
            $_SESSION = ['flash_error' => 'Hesabınız engellenmiş. Destek ile iletişime geçin.'];
            if (!headers_sent()) {
                header('Location: ' . dg_login_url() . '?banned=1'); exit;
            }
            return null;
        }
    }

    return $u;
}

/** Günlük izleme limitini ayar dosyasından al. */
function daily_watch_limit(): int {
    $data = dg_read_data();
    $v = $data['settings']['daily_watch_limit'] ?? DG_DAILY_LIMIT_DEFAULT;
    return is_numeric($v) ? max(1, (int)$v) : DG_DAILY_LIMIT_DEFAULT;
}

function dg_set_daily_watch_limit(int $limit): void {
    $limit = max(1, min(50, $limit));
    dg_mutate(function (array &$data) use ($limit): void {
        $data['settings']['daily_watch_limit'] = $limit;
    });
}

/** Kullanıcının bugün kaç video izlediği. */
function today_watch_count(int $user_id, ?array $data = null): int {
    $data = $data ?? dg_read_data();
    $today = date('Y-m-d');
    $count = 0;
    foreach ($data['watch_logs'] ?? [] as $log) {
        if ((int)($log['user_id'] ?? 0) === $user_id && substr((string)($log['watched_at'] ?? ''), 0, 10) === $today) {
            $count++;
        }
    }
    return $count;
}

/** Bir ürünün stok kipini döner: keys (tek tek key) veya quantity (adet). */
function dg_store_stock_mode(array $item): string {
    return (($item['stock_mode'] ?? 'keys') === 'quantity') ? 'quantity' : 'keys';
}

/**
 * Bir ürün için kullanılabilir/kullanılmış stok sayılarını hesaplar.
 * Key kipinde gerçek key satırları, adet kipinde ürünün stock alanı esastır.
 */
function dg_store_key_counts(array $data, int $itemId): array {
    $item = dg_find_by_id($data['store_items'] ?? [], $itemId);
    if ($item && dg_store_stock_mode($item) === 'quantity') {
        $used = 0;
        foreach ($data['purchases'] ?? [] as $purchase) {
            if ((int)($purchase['store_item_id'] ?? 0) === $itemId) $used++;
        }
        $avail = max(0, (int)($item['stock'] ?? 0));
        return ['avail' => $avail, 'used' => $used, 'keys_left' => $avail];
    }

    $avail = 0; $used = 0;
    foreach ($data['store_keys'] ?? [] as $key) {
        if ((int)($key['store_item_id'] ?? 0) !== $itemId) continue;
        if ((int)($key['is_used'] ?? 0) === 1) $used++; else $avail++;
    }
    return ['avail' => $avail, 'used' => $used, 'keys_left' => $avail];
}

/** Key kipindeki ürünlerin stock alanını gerçek key sayısına göre tazeler. */
function dg_refresh_store_stock(array &$data): void {
    foreach ($data['store_items'] as $i => $item) {
        if (dg_store_stock_mode($item) === 'quantity') {
            $data['store_items'][$i]['stock'] = max(0, (int)($item['stock'] ?? 0));
            continue;
        }
        $counts = dg_store_key_counts($data, (int)$item['id']);
        $data['store_items'][$i]['stock'] = $counts['avail'];
    }
}

/** Ürün listesine avail/used/keys_left ekler. */
function dg_enrich_store_items(array $data, array $items): array {
    $out = [];
    foreach ($items as $item) {
        $item += [
            'description' => '', 'image_url' => '', 'price_tl' => 0, 'price_dcoin' => 0,
            'is_active' => 0, 'stock' => 0, 'stock_mode' => 'keys', 'legacy_slug' => null,
        ];
        $item = array_merge($item, dg_store_key_counts($data, (int)$item['id']));
        $item['stock'] = $item['avail'];
        $out[] = $item;
    }
    return $out;
}

/** Satın alma kaydının WhatsApp/destek takibinde kullanılacak sipariş kodu. */
function dg_purchase_order_code(array $purchase): string {
    $code = trim((string)($purchase['order_code'] ?? ''));
    if ($code !== '') return $code;
    if (($purchase['delivery_type'] ?? '') === 'order_code') {
        $legacyCode = trim((string)($purchase['delivered_key'] ?? ''));
        if ($legacyCode !== '') return $legacyCode;
    }
    $id = (int)($purchase['id'] ?? 0);
    return $id > 0 ? 'DG-' . $id : '';
}

/** Eski mağaza ürünleri ilk kez içe aktarılırken kullanılacak D-Coin karşılığı. */
function dg_legacy_dcoin_price(float $priceTl): int {
    return max(1, (int)round($priceTl / 6));
}

/** Eski kataloğun değişip değişmediğini anlamak için içerik özeti. */
function dg_legacy_catalog_hash(array $products): string {
    $json = json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha256', $json === false ? '' : $json);
}

/**
 * Eski mağaza kataloğunu dosyalardan okur. lib/shop.php yüklenmemişken,
 * ilk kurulumda ve admin açılışında da kullanılabilmesi için bağımsızdır.
 */
function dg_legacy_catalog_from_files(): array {
    $jsonFile = __DIR__ . '/storage/products.json';
    if (is_file($jsonFile)) {
        $decoded = json_decode((string)@file_get_contents($jsonFile), true);
        if (is_array($decoded)) return $decoded;
    }

    $phpFile = __DIR__ . '/data/products.php';
    if (is_file($phpFile)) {
        $rows = include $phpFile;
        if (is_array($rows)) return $rows;
    }
    return [];
}

/**
 * Eski mağaza kataloğunu D-Coin mağazasına uygular.
 * - Ürün adı, açıklama, görsel ve TL fiyat eski katalogdan gelir.
 * - D-Coin fiyatı yalnızca ilk aktarımda önerilir; admin'in fiyatı ezilmez.
 * - Eski stok değişimi fark olarak uygulanır; satışla azalan stok geri sıçramaz.
 * - Eski katalogdan silinen ürün D-Coin vitrininden kaldırılır, kaydı silinmez.
 */
function dg_apply_legacy_catalog(array &$data, array $products): bool {
    $hash = dg_legacy_catalog_hash($products);
    $cleanupDone = !empty($data['settings']['legacy_seed_cleanup_v1']);
    if (($data['settings']['legacy_catalog_hash'] ?? '') === $hash && $cleanupDone) {
        return false;
    }

    $now = dg_now();
    $seenSlugs = [];

    foreach ($products as $product) {
        if (!is_array($product)) continue;
        $slug = trim((string)($product['slug'] ?? ''));
        $name = trim((string)($product['name'] ?? ''));
        if ($slug === '' || $name === '') continue;
        $seenSlugs[$slug] = true;

        $description = trim((string)($product['desc'] ?? $product['description'] ?? $product['subtitle'] ?? ''));
        $image = trim((string)($product['image'] ?? $product['image_url'] ?? ''));
        $priceTl = max(0, (float)($product['price'] ?? $product['price_tl'] ?? 0));
        $legacyStock = max(0, (int)($product['stock'] ?? 0));
        $oldPrice = $product['old_price'] ?? null;
        $legacyMeta = [
            'legacy_slug' => $slug,
            'legacy_subtitle' => (string)($product['subtitle'] ?? ''),
            'legacy_category' => (string)($product['category'] ?? 'key'),
            'legacy_platform' => (string)($product['platform'] ?? 'PC'),
            'legacy_old_price' => ($oldPrice === null || $oldPrice === '') ? null : (float)$oldPrice,
            'legacy_badge' => empty($product['badge']) ? null : (string)$product['badge'],
            'legacy_order' => (int)($product['order'] ?? 0),
        ];

        $itemIndex = null;
        foreach ($data['store_items'] as $i => $item) {
            if ((string)($item['legacy_slug'] ?? '') === $slug) {
                $itemIndex = (int)$i;
                break;
            }
        }
        if ($itemIndex === null) {
            foreach ($data['store_items'] as $i => $item) {
                if (empty($item['legacy_slug']) && dg_casefold((string)($item['name'] ?? '')) === dg_casefold($name)) {
                    $itemIndex = (int)$i;
                    break;
                }
            }
        }

        if ($itemIndex === null) {
            $data['store_items'][] = array_merge([
                'id' => dg_next_id($data, 'store_items'),
                'name' => $name,
                'description' => $description,
                'image_url' => $image,
                'price_tl' => $priceTl,
                'price_dcoin' => dg_legacy_dcoin_price($priceTl),
                'stock' => $legacyStock,
                'stock_mode' => 'quantity',
                'legacy_stock_snapshot' => $legacyStock,
                'legacy_price_snapshot' => $priceTl,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $legacyMeta);
            continue;
        }

        $item = $data['store_items'][$itemIndex];
        $previousPriceSnapshot = isset($item['legacy_price_snapshot']) ? (float)$item['legacy_price_snapshot'] : null;
        $currentDcoin = (int)($item['price_dcoin'] ?? 0);
        if ($currentDcoin <= 0) {
            $currentDcoin = dg_legacy_dcoin_price($priceTl);
        } elseif ($previousPriceSnapshot !== null
            && $currentDcoin === dg_legacy_dcoin_price($previousPriceSnapshot)) {
            // Admin özel bir D-Coin fiyatı vermemişse önerilen karşılığı koru.
            $currentDcoin = dg_legacy_dcoin_price($priceTl);
        }

        // İlk aktarım adet stokla başlar. Admin gerçek keylerle çalışmak için
        // key kipine geçtiyse katalog eşitlemesi bu seçimi geri almaz.
        $stockMode = array_key_exists('stock_mode', $item)
            ? dg_store_stock_mode($item)
            : 'quantity';
        if ($stockMode === 'quantity') {
            if (!array_key_exists('legacy_stock_snapshot', $item)) {
                $stock = $legacyStock;
            } else {
                $stockDelta = $legacyStock - (int)$item['legacy_stock_snapshot'];
                $stock = max(0, (int)($item['stock'] ?? 0) + $stockDelta);
            }
            $stockSnapshot = $legacyStock;
        } else {
            // Key kipinde kullanılabilir stok, dg_refresh_store_stock() ile
            // gerçek key satırlarından yeniden hesaplanır.
            $stock = (int)($item['stock'] ?? 0);
            $stockSnapshot = (int)($item['legacy_stock_snapshot'] ?? $legacyStock);
        }

        $data['store_items'][$itemIndex] = array_merge($item, $legacyMeta, [
            'name' => $name,
            'description' => $description,
            'image_url' => $image,
            'price_tl' => $priceTl,
            'price_dcoin' => $currentDcoin,
            'stock' => $stock,
            'stock_mode' => $stockMode,
            'legacy_stock_snapshot' => $stockSnapshot,
            'legacy_price_snapshot' => $priceTl,
            'updated_at' => $now,
        ]);
    }

    foreach ($data['store_items'] as $i => $item) {
        $slug = (string)($item['legacy_slug'] ?? '');
        if ($slug !== '' && empty($seenSlugs[$slug])) {
            $data['store_items'][$i]['is_active'] = 0;
            $data['store_items'][$i]['updated_at'] = $now;
        }
    }

    if (!$cleanupDone) {
        // Önceki sürümün örnek video başlıklarını sitenin kendi diliyle değiştir.
        $sampleAdTitles = [
            'Cyber Hunt — Tanıtım Fragmanı' => 'Device Game — Tanıtım Videosu',
            'Neon Drift — Oynanış Videosu' => 'Project: Overcharge — Oynanış Videosu',
            'Project Overcharge — Teaser' => 'Mağaza Seçkisi — Kısa Tanıtım',
        ];
        foreach ($data['ads'] as $i => $ad) {
            $title = (string)($ad['title'] ?? '');
            if (isset($sampleAdTitles[$title])) {
                $data['ads'][$i]['title'] = $sampleAdTitles[$title];
                $data['ads'][$i]['updated_at'] = $now;
            }
        }
        if (($data['settings']['site_name'] ?? '') === 'DeviceGame') {
            $data['settings']['site_name'] = 'Device Game';
        }

        // Önceki sürümün örnek ürünleri eski sitenin kataloğunda yoktu.
        // Satış geçmişini korumak için silmek yerine vitrinden kaldırılır.
        $sampleNames = [
            'Cyberpunk 2077 — Phantom Liberty (Steam)' => true,
            'Elden Ring — Deluxe Edition' => true,
            'Valorant 1000 VP' => true,
            'Discord Nitro — 1 Ay' => true,
            'SteelSeries Mousepad — Device Edition' => true,
        ];
        foreach ($data['store_items'] as $i => $item) {
            if (empty($item['legacy_slug']) && isset($sampleNames[(string)($item['name'] ?? '')])) {
                $data['store_items'][$i]['is_active'] = 0;
                $data['store_items'][$i]['retired_seed_item'] = 1;
                $data['store_items'][$i]['updated_at'] = $now;
            }
        }
    }

    $data['settings']['legacy_catalog_hash'] = $hash;
    $data['settings']['legacy_seed_cleanup_v1'] = 1;
    dg_refresh_store_stock($data);
    return true;
}

/** Eski kataloğu yalnızca değişmişse kilitli yazma ile eşitler. */
function dg_sync_legacy_products(array $products): bool {
    if (!$products) return false;
    $hash = dg_legacy_catalog_hash($products);
    $data = dg_read_data();
    if (($data['settings']['legacy_catalog_hash'] ?? '') === $hash
        && !empty($data['settings']['legacy_seed_cleanup_v1'])) {
        return false;
    }
    return (bool)dg_mutate(function (array &$fresh) use ($products): bool {
        return dg_apply_legacy_catalog($fresh, $products);
    });
}

// ——— İlk kurulum ———
try {
    dg_ensure_storage();
    unset($GLOBALS['DG_STORAGE_ERROR']);
} catch (Throwable $e) {
    $GLOBALS['DG_STORAGE_ERROR'] = $e->getMessage();
    error_log('[DG STORAGE] ' . $e->getMessage());

    if (defined('DG_DIAGNOSE') && DG_DIAGNOSE) {
        throw $e;
    }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(503);
        $safe = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        die('<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Depolama Ayarı — Device Game</title>'
            . '<style>body{margin:0;background:#0A1A15;color:#E8EBE4;font-family:system-ui;padding:24px;line-height:1.65}.card{max-width:760px;margin:40px auto;background:#112A21;border:1px solid rgba(190,225,208,.2);border-radius:18px;padding:24px}code{background:rgba(255,255,255,.08);padding:2px 7px;border-radius:6px}a{color:#4FE0A0}pre{white-space:pre-wrap;background:#0A1A15;padding:14px;border-radius:12px}</style></head><body><div class="card">'
            . '<h1>Device Game — Depolama klasörü hazır değil</h1>'
            . '<p>Bu sürüm MySQL kullanmaz. Sadece <code>storage/dg/</code> klasörünün PHP tarafından yazılabilmesi gerekir.</p>'
            . '<pre>' . $safe . '</pre>'
            . '<ol><li>File Manager/FTP ile <code>storage</code> ve varsa <code>storage/dg</code> izinlerini <b>755</b> veya <b>775</b> yapın.</li>'
            . '<li><code>storage/dg/database.json</code> varsa izinlerini <b>644</b> veya <b>664</b> yapın.</li>'
            . '<li><a href="diagnose.php">diagnose.php</a> sayfasını açıp kontrol edin.</li></ol>'
            . '</div></body></html>');
    }
}
