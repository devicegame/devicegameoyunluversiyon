<?php
/**
 * Mini Oyun bakım işleri (cron mantığı).
 *
 * `cron/minigame.php` yalnızca CLI'dan çağırır. Bu dosya test edilebilir
 * olsun diye ayrı tutulmuştur; web isteklerinden doğrudan çağrılmaz.
 */
declare(strict_types=1);

/**
 * Idempotent bakım: saklama temizliği + süresi biten sezonları incelemeye alma.
 * Hiçbir canlı kayıt (ledger, sahiplik, kullanıcı) SİLİNMEZ.
 *
 * @return array{runs:int,active:int,stats:int,ratelimit:int,seasons:int,rewarded_today:int}
 */
function mg_cron_run(?int $now = null): array
{
    $now = $now ?? time();
    $pruned = mg_prune($now, mg_limit('retention_days', 30));

    $closedSeasons = 0;
    if (mg_flag('season')) {
        $store = mg_seasons_store();
        foreach ($store['seasons'] as $index => $season) {
            if (($season['status'] ?? '') !== 'active') {
                continue;
            }
            if ((int)($season['end_ts'] ?? 0) > $now) {
                continue;
            }
            $season['status'] = 'review';
            $season['updated_utc'] = mg_now_utc();
            $season['review_snapshot'] = [
                'taken_utc' => mg_now_utc(),
                'taken_ts' => $now,
                'top' => mg_season_snapshot_rows(array_slice(mg_board_sorted(mg_board_key('season', $now, $season)), 0, 10)),
            ];
            $store['seasons'][$index] = $season;
            $closedSeasons++;
            mg_audit(null, 'season.auto_review', 'season:' . (int)$season['id'], ['cron' => true]);
        }
        if ($closedSeasons > 0) {
            mg_write_json('seasons.json', $store);
        }
    }

    // Denetim günlüğünü son 5000 satırla sınırla (ekleme-only büyümeyi engelle).
    $auditPath = MG_DIR . '/audit.jsonl';
    if (is_file($auditPath)) {
        $lines = @file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > 5000) {
            @file_put_contents($auditPath, implode("\n", array_slice($lines, -5000)) . "\n", LOCK_EX);
        }
    }

    $day = mg_day_bounds($now);
    $rewarded = mg_reward_sum(dg_read_data(true), $day, null);

    mg_audit(null, 'cron.prune', 'minigame', ['pruned' => $pruned, 'seasons' => $closedSeasons]);

    return [
        'runs' => (int)$pruned['runs'],
        'active' => (int)$pruned['active'],
        'stats' => (int)$pruned['stats'],
        'ratelimit' => (int)$pruned['ratelimit'],
        'seasons' => $closedSeasons,
        'rewarded_today' => $rewarded,
    ];
}
