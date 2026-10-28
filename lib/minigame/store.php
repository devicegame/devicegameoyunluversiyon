<?php
/**
 * Mini Oyun — dosya depolama, kilitleme, hız sınırı ve denetim günlüğü.
 *
 * KURALLAR
 * - Tüm oyun-yazma işlemleri `mg_lock()` altında yapılır. Kilit SIRASI:
 *   mini oyun kilidi → `dg_mutate()`. Asla ters yönde kilit alınmaz.
 * - Dosyalar tmp + rename ile atomik yazılır; bozuk JSON asla sessizce
 *   sıfırlanmaz (veri kaybını gizlememek için hata fırlatılır).
 * - `storage/minigame` web'den erişilemez (.htaccess + index.html).
 */
declare(strict_types=1);

const MG_DIR = __DIR__ . '/../../storage/minigame';

/** Depolama kökü + güvenlik dosyaları. */
function mg_ensure_storage(): void
{
    $dirs = [MG_DIR, MG_DIR . '/runs', MG_DIR . '/runs/active', MG_DIR . '/bests', MG_DIR . '/stats', MG_DIR . '/attempts', MG_DIR . '/ratelimit'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('storage/minigame oluşturulamadı. storage/ klasörüne yazma izni verin.');
        }
    }
    $guard = MG_DIR . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    if (!is_file(MG_DIR . '/index.html')) {
        @file_put_contents(MG_DIR . '/index.html', '');
    }
}

function mg_path(string $rel): string
{
    return MG_DIR . '/' . ltrim($rel, '/');
}

/** Dosya adı için güvenli anahtar (dizin kaçışı/özel karakter yok). */
function mg_safe_key(string $key): string
{
    return preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) ?? 'x';
}

function mg_read_json(string $rel, array $default = []): array
{
    $path = mg_path($rel);
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Mini oyun verisi okunamadı (' . mg_safe_key($rel) . '). Dosyayı yedekleyip kontrol edin.');
    }
    return $decoded;
}

/** Atomik yazma: geçici dosyaya yaz, rename ile değiştir. */
function mg_write_json(string $rel, array $data): void
{
    mg_ensure_storage();
    $path = mg_path($rel);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Mini oyun klasörü oluşturulamadı.');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Mini oyun verisi JSON olarak yazılamadı.');
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json . "\n") === false) {
        throw new RuntimeException('Mini oyun verisi yazılamadı. Depolama iznini kontrol edin.');
    }
    @chmod($tmp, 0664);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Mini oyun verisi güncellenemedi. Depolama iznini kontrol edin.');
    }
}

/**
 * Tüm oyun durumu için tek kilit. İç içe çağrı YAPILMAZ.
 * @template T
 * @param callable():T $callback
 * @return T
 */
function mg_lock(callable $callback)
{
    mg_ensure_storage();
    $handle = @fopen(MG_DIR . '/minigame.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('Mini oyun kilidi açılamadı. Depolama iznini kontrol edin.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Mini oyun kilidi alınamadı. Lütfen tekrar deneyin.');
        }
        return $callback();
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/* ------------------------------------------------------------------ *
 * Zaman yardımcıları
 * ------------------------------------------------------------------ */

function mg_now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function mg_iso(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/* ------------------------------------------------------------------ *
 * Hız sınırı (dosya tabanlı, çekirdek veriyi şişirmez)
 * ------------------------------------------------------------------ */

/**
 * Sabit pencere sayacı. Aşılırsa false döner.
 * @param string $scope  işlem adı (mg-start, mg-submit, ...)
 * @param string $identity kullanıcı kimliği (u12) veya IP hash
 */
function mg_rate_limit(string $scope, string $identity, int $limit, int $windowSeconds): bool
{
    if ($limit <= 0 || $windowSeconds <= 0) {
        return true;
    }
    mg_ensure_storage();
    $key = mg_safe_key($scope . '_' . substr(hash('sha256', $identity), 0, 24));
    $file = MG_DIR . '/ratelimit/' . $key . '.json';
    $now = time();

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return true; // kilit dosyası açılamıyorsa hizmeti kesmeyelim
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            return true;
        }
        $raw = stream_get_contents($handle);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || (int)($data['start'] ?? 0) + $windowSeconds <= $now) {
            $data = ['start' => $now, 'count' => 0];
        }
        $data['count'] = (int)$data['count'] + 1;
        $allowed = $data['count'] <= $limit;
        if ($allowed || $data['count'] === $limit + 1) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string)json_encode($data));
            fflush($handle);
        }
        return $allowed;
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/** IP'yi doğrudan saklamayız; tuzlu kısa hash yeterlidir. */
function mg_ip_hash(): string
{
    static $salt = null;
    if ($salt === null) {
        mg_ensure_storage();
        $path = MG_DIR . '/ip-salt';
        if (!is_file($path)) {
            @file_put_contents($path, bin2hex(random_bytes(24)), LOCK_EX);
            @chmod($path, 0600);
        }
        $salt = (string)@file_get_contents($path);
        if ($salt === '') {
            $salt = 'mg-fallback';
        }
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return substr(hash('sha256', $salt . '|' . $ip), 0, 24);
}

/* ------------------------------------------------------------------ *
 * Denetim günlüğü (ekleme-only JSONL)
 * ------------------------------------------------------------------ */

function mg_audit(?int $actorId, string $action, string $target, array $meta = []): void
{
    mg_ensure_storage();
    $line = [
        'ts' => mg_now_utc(),
        'ts_unix' => time(),
        'actor_id' => $actorId,
        'action' => $action,
        'target' => $target,
        'meta' => $meta,
        'ip_hash' => mg_ip_hash(),
    ];
    $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    @file_put_contents(MG_DIR . '/audit.jsonl', $json . "\n", FILE_APPEND | LOCK_EX);
}

/** Son N denetim kaydı (en yeni önce). */
function mg_audit_tail(int $limit = 50): array
{
    $path = MG_DIR . '/audit.jsonl';
    if (!is_file($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
        if (count($out) >= max(1, $limit)) {
            break;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Saklama / temizlik
 * ------------------------------------------------------------------ */

/**
 * Süresi geçmiş koşuları ve eski günlük dosyaları temizler.
 * Sadece mini oyunun kendi çalışma verisine dokunur; canlı kullanıcı
 * verisi, ledger veya kozmetik sahipliği SİLİNMEZ.
 */
function mg_prune(int $now, int $retentionDays = 30): array
{
    $removed = ['runs' => 0, 'active' => 0, 'stats' => 0, 'ratelimit' => 0];
    $cutoff = $now - max(1, $retentionDays) * 86400;

    $runDirs = glob(MG_DIR . '/runs/*', GLOB_ONLYDIR) ?: [];
    foreach ($runDirs as $dir) {
        if (basename($dir) === 'active') {
            continue;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file) ?: 0;
            if ($mtime > 0 && $mtime < $cutoff && @unlink($file)) {
                $removed['runs']++;
            }
        }
        $left = glob($dir . '/*') ?: [];
        if (!$left) {
            @rmdir($dir);
        }
    }

    foreach (glob(MG_DIR . '/stats/*.json') ?: [] as $file) {
        if ((@filemtime($file) ?: 0) < $cutoff && @unlink($file)) {
            $removed['stats']++;
        }
    }
    foreach (glob(MG_DIR . '/ratelimit/*.json') ?: [] as $file) {
        if ((@filemtime($file) ?: 0) < $now - 86400 && @unlink($file)) {
            $removed['ratelimit']++;
        }
    }

    // Süresi dolmuş aktif koşular
    if (is_file(MG_DIR . '/runs/active.json')) {
        mg_lock(static function () use ($now, &$removed): void {
            $active = mg_read_json('runs/active.json', []);
            foreach ($active as $id => $run) {
                if ((int)($run['expires_ts'] ?? 0) < $now) {
                    unset($active[$id]);
                    $removed['active']++;
                }
            }
            mg_write_json('runs/active.json', $active);
        });
    }

    return $removed;
}
