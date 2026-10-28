<?php
/**
 * Mini Oyun bakım görevi (cron).
 *
 * ÖNERİLEN: günde bir kez, örn.
 *   15 3 * * *  /usr/bin/php /path/to/site/cron/minigame.php >> storage/minigame/cron.log 2>&1
 *
 * Yaptıkları (idempotent, tekrar çalıştırmak zarar vermez):
 *  - süresi dolmuş aktif koşuları kapatır
 *  - saklama süresini geçmiş koşu dosyalarını siler (ledger ve sahiplik SİLİNMEZ)
 *  - süresi bitmiş "active" sezonları "review" durumuna alır; kazananı
 *    YÖN ETİCİ onaylar (otomatik ödül verilmez)
 *
 * Web'den çağrılamaz. Ayrıntılar: MINI-OYUN.md → "Cron".
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Bu betik yalnızca komut satırından çalıştırılabilir.\n";
    exit;
}

require_once __DIR__ . '/../lib/minigame/bootstrap.php';

$startedAt = microtime(true);
$summary = mg_cron_run();

printf(
    "[%s] mini-oyun cron\n" .
    " - temizlik: %d koşu, %d süresi dolmuş aktif koşu, %d istatistik, %d hız sınırı dosyası\n" .
    " - incelemeye alınan sezon: %d\n" .
    " - bugün dağıtılan: %d D-Coin (bütçe: %s, kişi tavanı: %s)\n" .
    " - süre: %.0f ms\n",
    mg_now_utc(),
    $summary['runs'],
    $summary['active'],
    $summary['stats'],
    $summary['ratelimit'],
    $summary['seasons'],
    $summary['rewarded_today'],
    mg_limit('daily_global_budget') > 0 ? (string)mg_limit('daily_global_budget') : 'sınırsız',
    mg_limit('daily_user_reward_cap') > 0 ? (string)mg_limit('daily_user_reward_cap') : 'sınırsız',
    (microtime(true) - $startedAt) * 1000
);
