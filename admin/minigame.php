<?php
/**
 * Yönetim — Mini Oyun paneli.
 * Mevcut admin kabuğu ve rol sistemi içinde çalışır. Ayrı panel yoktur.
 */
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/minigame/bootstrap.php';

$adminId = (int)$user['id'];
$section = (string)($_GET['bolum'] ?? 'ozet');
$allowedSections = ['ozet', 'ayarlar', 'gorevler', 'katalog', 'inceleme', 'sezon', 'rapor'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'ozet';
}

/** datetime-local → UTC epoch (Istanbul saatiyle yorumlanır). */
function mg_admin_local_to_ts(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    try {
        $dt = new DateTimeImmutable($value, mg_timezone());
        return $dt->getTimestamp();
    } catch (Throwable $error) {
        return 0;
    }
}

function mg_admin_ts_to_local(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(mg_timezone())->format('Y-m-d\TH:i');
}

/* ------------------------------------------------------------------ *
 * POST işlemleri
 * ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
        flash_error_set('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
        header('Location: minigame.php?bolum=' . $section);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'save_settings':
                $settings = mg_settings(true);
                foreach (array_keys($settings['flags']) as $flag) {
                    $settings['flags'][$flag] = isset($_POST['flags'][$flag]) ? 1 : 0;
                }
                foreach (['max_ranked_runs_per_day', 'daily_user_reward_cap', 'daily_global_budget', 'run_ttl_seconds', 'min_valid_ticks', 'max_inputs', 'retention_days', 'timing_tolerance_ticks'] as $limit) {
                    if (isset($_POST['limits'][$limit])) {
                        $settings['limits'][$limit] = max(0, (int)$_POST['limits'][$limit]);
                    }
                }
                $settings['economy']['approved'] = isset($_POST['economy_approved']) ? 1 : 0;
                $settings['economy']['note'] = trim((string)($_POST['economy_note'] ?? ''));
                $settings['maintenance_note'] = trim((string)($_POST['maintenance_note'] ?? ''));
                mg_set_settings($settings, $adminId);
                flash_set('Mini oyun ayarları kaydedildi.');
                break;

            case 'save_tasks':
                $settings = mg_settings(true);
                $tasks = [];
                foreach ((array)($_POST['tasks'] ?? []) as $task) {
                    if (!is_array($task) || trim((string)($task['id'] ?? '')) === '') {
                        continue;
                    }
                    $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string)$task['id'])) ?: 'gorev';
                    $tasks[] = [
                        'id' => $id,
                        'title' => trim((string)($task['title'] ?? $id)),
                        'description' => trim((string)($task['description'] ?? '')),
                        'metric' => in_array($task['metric'] ?? '', ['valid_runs', 'best_score', 'best_cells', 'cells_total'], true) ? $task['metric'] : 'valid_runs',
                        'target' => max(1, (int)($task['target'] ?? 1)),
                        'reward' => max(0, (int)($task['reward'] ?? 0)),
                        'order' => (int)($task['order'] ?? 0),
                        'enabled' => isset($task['enabled']) ? 1 : 0,
                    ];
                }
                $settings['tasks'] = $tasks;
                mg_set_settings($settings, $adminId);
                mg_audit($adminId, 'tasks.update', 'settings', ['count' => count($tasks)]);
                flash_set('Görevler güncellendi.');
                break;

            case 'save_catalog':
                $items = [];
                foreach (mg_catalog(true) as $item) {
                    $id = $item['id'];
                    if (isset($_POST['items'][$id]) && is_array($_POST['items'][$id])) {
                        $row = $_POST['items'][$id];
                        $item['name'] = trim((string)($row['name'] ?? $item['name']));
                        $item['description'] = trim((string)($row['description'] ?? $item['description']));
                        $item['price'] = max(0, (int)($row['price'] ?? $item['price']));
                        $item['rarity'] = in_array($row['rarity'] ?? '', ['common', 'rare', 'epic', 'champion'], true) ? $row['rarity'] : $item['rarity'];
                        $item['purchasable'] = isset($row['purchasable']) ? 1 : 0;
                        $item['published'] = isset($row['published']) ? 1 : 0;
                        $item['visual']['primary'] = mg_safe_color($row['primary'] ?? $item['visual']['primary']);
                        $item['visual']['secondary'] = mg_safe_color($row['secondary'] ?? $item['visual']['secondary']);
                    }
                    $items[] = $item;
                }
                $newId = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_POST['new_id'] ?? ''))));
                if ($newId !== '') {
                    $items[] = [
                        'id' => $newId,
                        'name' => trim((string)($_POST['new_name'] ?? $newId)),
                        'category' => in_array($_POST['new_category'] ?? '', MG_LOADOUT_SLOTS, true) ? $_POST['new_category'] : 'color',
                        'description' => trim((string)($_POST['new_description'] ?? '')),
                        'price' => max(0, (int)($_POST['new_price'] ?? 0)),
                        'free' => 0,
                        'purchasable' => 1,
                        'published' => 0,
                        'rarity' => 'common',
                        'visual' => [
                            'body' => $_POST['new_category'] === 'character' ? 'runner' : null,
                            'primary' => mg_safe_color($_POST['new_primary'] ?? '#c5f46f'),
                            'secondary' => mg_safe_color($_POST['new_secondary'] ?? '#1b152c'),
                            'glow' => 0.45,
                        ],
                        'order' => 500,
                    ];
                }
                mg_catalog_save($items, $adminId);
                flash_set('Kozmetik kataloğu kaydedildi.');
                break;

            case 'review_run':
                $result = mg_admin_review_run(
                    trim((string)($_POST['run_id'] ?? '')),
                    (string)($_POST['decision'] ?? ''),
                    (string)($_POST['note'] ?? ''),
                    $adminId
                );
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'İnceleme kaydedilemedi.'));
                } else {
                    flash_set('İnceleme kaydedildi: ' . ($result['status'] ?? ''));
                }
                break;

            case 'override_score':
                $result = mg_admin_override_score(
                    trim((string)($_POST['run_id'] ?? '')),
                    (int)($_POST['score'] ?? 0),
                    (string)($_POST['reason'] ?? ''),
                    $adminId
                );
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'Skor düzeltilemedi.'));
                } else {
                    flash_set('Skor düzeltildi: ' . (int)$result['previous'] . ' → ' . (int)$result['score']);
                }
                break;

            case 'balance_adjust':
                $result = mg_admin_balance_adjust(
                    (int)($_POST['user_id'] ?? 0),
                    (int)($_POST['amount'] ?? 0),
                    (string)($_POST['reason'] ?? ''),
                    $adminId
                );
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'Düzeltme yapılamadı.'));
                } else {
                    flash_set('Bakiye düzeltmesi ledger kaydıyla yapıldı. Yeni bakiye: ' . (int)$result['balance']);
                }
                break;

            case 'season_create':
                $created = mg_season_create([
                    'title' => (string)($_POST['title'] ?? 'Haftalık Yarışma'),
                    'prize_name' => (string)($_POST['prize_name'] ?? ''),
                    'prize_note' => (string)($_POST['prize_note'] ?? ''),
                    'platform' => (string)($_POST['platform'] ?? ''),
                    'region' => (string)($_POST['region'] ?? ''),
                    'eligibility' => (string)($_POST['eligibility'] ?? ''),
                    'participation' => (string)($_POST['participation'] ?? ''),
                    'results_note' => (string)($_POST['results_note'] ?? ''),
                    'delivery_note' => (string)($_POST['delivery_note'] ?? ''),
                    'c1' => (string)($_POST['c1'] ?? '#2b2141'),
                    'c2' => (string)($_POST['c2'] ?? '#c5f46f'),
                    'start_ts' => mg_admin_local_to_ts((string)($_POST['start_local'] ?? '')) ?: time(),
                    'end_ts' => mg_admin_local_to_ts((string)($_POST['end_local'] ?? '')) ?: time() + 7 * 86400,
                ], $adminId);
                flash_set('Taslak sezon oluşturuldu (#' . (int)$created['season']['id'] . ').');
                break;

            case 'season_update':
                $seasonId = (int)($_POST['season_id'] ?? 0);
                $update = [
                    'title' => (string)($_POST['title'] ?? ''),
                    'prize_name' => (string)($_POST['prize_name'] ?? ''),
                    'platform' => (string)($_POST['platform'] ?? ''),
                    'region' => (string)($_POST['region'] ?? ''),
                    'eligibility' => (string)($_POST['eligibility'] ?? ''),
                    'participation' => (string)($_POST['participation'] ?? ''),
                    'results_note' => (string)($_POST['results_note'] ?? ''),
                    'delivery_note' => (string)($_POST['delivery_note'] ?? ''),
                    'c1' => (string)($_POST['c1'] ?? ''),
                    'c2' => (string)($_POST['c2'] ?? ''),
                ];
                $start = mg_admin_local_to_ts((string)($_POST['start_local'] ?? ''));
                $end = mg_admin_local_to_ts((string)($_POST['end_local'] ?? ''));
                if ($start > 0) {
                    $update['start_ts'] = $start;
                }
                if ($end > 0) {
                    $update['end_ts'] = $end;
                }
                $result = mg_season_update($seasonId, $update, $adminId);
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'Sezon güncellenemedi.'));
                } else {
                    flash_set('Sezon güncellendi.');
                }
                break;

            case 'season_transition':
                $result = mg_season_transition((int)($_POST['season_id'] ?? 0), (string)($_POST['to'] ?? ''), $adminId);
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'Durum değiştirilemedi.'));
                } else {
                    flash_set('Sezon durumu: ' . mg_season_public($result['season'])['status_label']);
                }
                break;

            case 'season_finalize':
                $winner = (int)($_POST['winner_user_id'] ?? 0);
                $result = mg_season_finalize((int)($_POST['season_id'] ?? 0), $adminId, $winner > 0 ? $winner : null);
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'Sezon kesinleştirilemedi.'));
                } else {
                    flash_set('Sezon kesinleşti. Kazanan: ' . (string)($result['final']['winner_username'] ?? '-'));
                }
                break;

            case 'season_deliver':
                $result = mg_season_deliver((int)($_POST['season_id'] ?? 0), $adminId, (string)($_POST['note'] ?? ''));
                if (empty($result['ok'])) {
                    flash_error_set((string)($result['error'] ?? 'Teslim işaretlenemedi.'));
                } else {
                    flash_set('Teslim kaydedildi.');
                }
                break;

            case 'prune':
                $removed = mg_prune(time(), mg_limit('retention_days', 30));
                mg_audit($adminId, 'prune', 'storage', $removed);
                flash_set('Temizlik tamamlandı. Silinen koşu dosyası: ' . (int)$removed['runs']);
                break;

            default:
                flash_error_set('Bilinmeyen işlem.');
        }
    } catch (Throwable $error) {
        error_log('[minigame-admin] ' . $error->getMessage());
        flash_error_set('İşlem tamamlanamadı. Ayrıntılar sunucu günlüğüne yazıldı.');
    }

    header('Location: minigame.php?bolum=' . $section);
    exit;
}

/* ------------------------------------------------------------------ *
 * Görünüm verisi
 * ------------------------------------------------------------------ */

$settings = mg_settings(true);
$catalog = mg_catalog(true);
$pending = $section === 'inceleme' ? mg_pending_runs(40) : [];
$seasons = mg_seasons();
$report = $section === 'rapor' ? mg_economy_report(14) : null;
$audit = $section === 'rapor' ? mg_audit_tail(40) : [];
$walletUsers = dg_read_data()['users'] ?? [];

admin_head('Mini Oyun');
?>
<div style="display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <p class="dg-kicker">ADMIN // MINI OYUN</p>
    <h1 style="font-family:Bricolage Grotesque,sans-serif;font-weight:800;font-size:26px;letter-spacing:-0.02em">Overcharge: Kaçış</h1>
    <p class="dg-muted" style="font-size:13px">Oyun bayrakları, görevler, kozmetikler, inceleme kuyruğu ve ekonomi raporu.</p>
  </div>
  <div class="dg-pill" style="font-size:12px">
    Motor <?php echo e(MG_ENGINE_VERSION); ?> · <?php echo count(mg_tasks()); ?> görev · <?php echo count($catalog); ?> kozmetik
  </div>
</div>

<nav class="admin-tabs" style="margin-bottom:18px">
  <?php foreach ([
      'ozet' => 'Genel bakış',
      'ayarlar' => 'Ayarlar',
      'gorevler' => 'Görevler',
      'katalog' => 'Kozmetikler',
      'inceleme' => 'İnceleme kuyruğu',
      'sezon' => 'Sezonlar',
      'rapor' => 'Ekonomi & denetim',
  ] as $key => $label): ?>
    <a href="minigame.php?bolum=<?php echo e($key); ?>" class="<?php echo $section === $key ? 'active' : ''; ?>"><?php echo e($label); ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($section === 'ozet'): ?>
  <?php
    $active = mg_active_season(time());
    $ledger = dg_read_data()['minigame_ledger'] ?? [];
    $today = mg_day_bounds(time());
    $todayPaid = mg_reward_sum(dg_read_data(), $today, null);
  ?>
  <div class="dg-grid-3" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <div class="dg-card" style="padding:16px">
      <p class="dg-muted" style="font-size:12px">Bayraklar</p>
      <p style="font-size:14px;line-height:1.9">
        Oyun: <b><?php echo mg_flag('game') ? 'açık' : 'kapalı'; ?></b><br>
        Ödüller: <b><?php echo mg_flag('rewards') ? 'açık' : 'kapalı'; ?></b><br>
        Mağaza: <b><?php echo mg_flag('shop') ? 'açık' : 'kapalı'; ?></b><br>
        Yarışma: <b><?php echo mg_flag('season') ? 'açık' : 'kapalı'; ?></b>
      </p>
    </div>
    <div class="dg-card" style="padding:16px">
      <p class="dg-muted" style="font-size:12px">Bugün</p>
      <p style="font-size:14px;line-height:1.9">
        Dağıtılan: <b><?php echo number_format($todayPaid); ?> D-Coin</b><br>
        Günlük bütçe: <b><?php echo mg_limit('daily_global_budget') > 0 ? number_format(mg_limit('daily_global_budget')) : 'sınırsız'; ?></b><br>
        Kişi tavanı: <b><?php echo mg_limit('daily_user_reward_cap') > 0 ? number_format(mg_limit('daily_user_reward_cap')) : 'sınırsız'; ?></b>
      </p>
    </div>
    <div class="dg-card" style="padding:16px">
      <p class="dg-muted" style="font-size:12px">Aktif sezon</p>
      <p style="font-size:14px"><?php echo $active ? e($active['title']) . ' · ' . e(MG_SEASON_STATUSES[$active['status']] ?? '') : 'Yok'; ?></p>
      <p class="dg-muted" style="font-size:12px;margin-top:6px">Ledger satırı: <?php echo count($ledger); ?></p>
    </div>
    <div class="dg-card" style="padding:16px">
      <p class="dg-muted" style="font-size:12px">Ekonomi onayı</p>
      <p style="font-size:14px"><b><?php echo (int)($settings['economy']['approved'] ?? 0) === 1 ? 'Onaylandı' : 'Prototip (onay bekliyor)'; ?></b></p>
      <p class="dg-muted" style="font-size:12px;margin-top:6px"><?php echo e((string)($settings['economy']['note'] ?? '')); ?></p>
    </div>
  </div>

  <div class="dg-card" style="padding:16px;margin-top:16px">
    <h2 style="font-size:15px;margin-bottom:8px">Hızlı kontrol</h2>
    <p class="dg-muted" style="font-size:13px;line-height:1.7">
      Ödülleri kapatmak oyunu kapatmaz. Mağazayı kapatmak sahiplikleri silmez. Sezon yalnızca yönetici
      tarafından başlatılır ve kazanan elle onaylanır. Bakiye düzeltmeleri eski kayıtları silmez,
      dengeleyici ledger girişi ekler.
    </p>
    <form method="post" style="margin-top:10px">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="prune">
      <button class="dg-btn dg-btn-ghost dg-btn-sm" type="submit">Saklama süresi temizliğini çalıştır (<?php echo (int)mg_limit('retention_days', 30); ?> gün)</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($section === 'ayarlar'): ?>
  <form method="post" class="dg-card" style="padding:18px">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save_settings">
    <h2 style="font-size:15px;margin-bottom:12px">Bayraklar (birbirinden bağımsız)</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
      <?php foreach ([
          'game' => 'Sıralı turlar (oyun)',
          'practice' => 'Antrenman turları',
          'rewards' => 'D-Coin ödülleri',
          'shop' => 'Kozmetik satışı',
          'leaderboard' => 'Sıralama tablosu',
          'season' => 'Ödüllü yarışma (onay gerekir)',
      ] as $flag => $label): ?>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px">
          <input type="checkbox" name="flags[<?php echo e($flag); ?>]" value="1" <?php echo (int)($settings['flags'][$flag] ?? 0) === 1 ? 'checked' : ''; ?>>
          <?php echo e($label); ?>
        </label>
      <?php endforeach; ?>
    </div>

    <h2 style="font-size:15px;margin:18px 0 12px">Limitler</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
      <?php foreach ([
          'max_ranked_runs_per_day' => 'Günlük sıralı tur (0 = sınırsız)',
          'daily_user_reward_cap' => 'Kişi başı günlük D-Coin (0 = sınırsız)',
          'daily_global_budget' => 'Global günlük bütçe (0 = sınırsız)',
          'run_ttl_seconds' => 'Koşu geçerlilik süresi (sn)',
          'min_valid_ticks' => 'Geçerli tur eşiği (tick, 60 = 1 sn)',
          'max_inputs' => 'Maksimum girdi olayı',
          'retention_days' => 'Koşu kaydı saklama (gün)',
          'timing_tolerance_ticks' => 'Zaman toleransı (tick)',
      ] as $key => $label): ?>
        <label style="font-size:13px;display:grid;gap:4px">
          <?php echo e($label); ?>
          <input class="dg-input" type="number" min="0" name="limits[<?php echo e($key); ?>]" value="<?php echo (int)($settings['limits'][$key] ?? 0); ?>">
        </label>
      <?php endforeach; ?>
    </div>

    <h2 style="font-size:15px;margin:18px 0 12px">Ekonomi / bakım notu</h2>
    <label style="font-size:13px;display:flex;gap:8px;align-items:center;margin-bottom:8px">
      <input type="checkbox" name="economy_approved" value="1" <?php echo (int)($settings['economy']['approved'] ?? 0) === 1 ? 'checked' : ''; ?>>
      D-Coin tutarları işletmeci tarafından onaylandı (işaretlenince "örnek" etiketi kalkar)
    </label>
    <label style="font-size:13px;display:grid;gap:4px;margin-bottom:10px">
      Ekonomi notu (kullanıcıya görünür)
      <input class="dg-input" type="text" name="economy_note" value="<?php echo e((string)($settings['economy']['note'] ?? '')); ?>">
    </label>
    <label style="font-size:13px;display:grid;gap:4px">
      Bakım notu (oyun kapalıyken gösterilir)
      <input class="dg-input" type="text" name="maintenance_note" value="<?php echo e((string)($settings['maintenance_note'] ?? '')); ?>">
    </label>
    <button class="dg-btn dg-btn-primary" style="margin-top:14px" type="submit">Ayarları kaydet</button>
  </form>
<?php endif; ?>

<?php if ($section === 'gorevler'): ?>
  <form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save_tasks">
    <?php foreach (mg_tasks(false) as $i => $task): ?>
      <div class="dg-card" style="padding:14px;margin-bottom:10px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px">
          <label style="font-size:12px">Kimlik<input class="dg-input" name="tasks[<?php echo $i; ?>][id]" value="<?php echo e($task['id']); ?>"></label>
          <label style="font-size:12px">Başlık<input class="dg-input" name="tasks[<?php echo $i; ?>][title]" value="<?php echo e($task['title']); ?>"></label>
          <label style="font-size:12px">Metrik
            <select class="dg-input" name="tasks[<?php echo $i; ?>][metric]">
              <?php foreach (['valid_runs' => 'Geçerli tur sayısı', 'best_score' => 'En iyi skor', 'best_cells' => 'En iyi hücre', 'cells_total' => 'Toplam hücre'] as $metric => $label): ?>
                <option value="<?php echo e($metric); ?>" <?php echo ($task['metric'] ?? '') === $metric ? 'selected' : ''; ?>><?php echo e($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label style="font-size:12px">Hedef<input class="dg-input" type="number" min="1" name="tasks[<?php echo $i; ?>][target]" value="<?php echo (int)$task['target']; ?>"></label>
          <label style="font-size:12px">Ödül (D-Coin)<input class="dg-input" type="number" min="0" name="tasks[<?php echo $i; ?>][reward]" value="<?php echo (int)$task['reward']; ?>"></label>
          <label style="font-size:12px">Sıra<input class="dg-input" type="number" name="tasks[<?php echo $i; ?>][order]" value="<?php echo (int)($task['order'] ?? 0); ?>"></label>
        </div>
        <label style="font-size:12px;display:grid;gap:4px;margin-top:8px">Açıklama
          <input class="dg-input" name="tasks[<?php echo $i; ?>][description]" value="<?php echo e($task['description']); ?>">
        </label>
        <label style="font-size:12px;display:flex;gap:8px;align-items:center;margin-top:8px">
          <input type="checkbox" name="tasks[<?php echo $i; ?>][enabled]" value="1" <?php echo (int)($task['enabled'] ?? 1) === 1 ? 'checked' : ''; ?>> Etkin
        </label>
      </div>
    <?php endforeach; ?>
    <button class="dg-btn dg-btn-primary" type="submit">Görevleri kaydet</button>
  </form>
<?php endif; ?>

<?php if ($section === 'katalog'): ?>
  <form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save_catalog">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
      <?php foreach ($catalog as $item): ?>
        <div class="dg-card" style="padding:14px">
          <p style="font-size:12px;color:var(--muted)"><?php echo e($item['id']); ?> · <?php echo e(MG_CATEGORY_LABELS[$item['category']] ?? $item['category']); ?><?php echo $item['free'] === 1 ? ' · ücretsiz' : ''; ?></p>
          <div style="display:grid;grid-template-columns:1fr 90px;gap:8px;margin-top:8px">
            <label style="font-size:12px">Ad<input class="dg-input" name="items[<?php echo e($item['id']); ?>][name]" value="<?php echo e($item['name']); ?>"></label>
            <label style="font-size:12px">Fiyat<input class="dg-input" type="number" min="0" name="items[<?php echo e($item['id']); ?>][price]" value="<?php echo (int)$item['price']; ?>"></label>
          </div>
          <label style="font-size:12px;display:grid;gap:4px;margin-top:8px">Açıklama
            <input class="dg-input" name="items[<?php echo e($item['id']); ?>][description]" value="<?php echo e($item['description']); ?>">
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
            <label style="font-size:12px">Ana renk<input class="dg-input" name="items[<?php echo e($item['id']); ?>][primary]" value="<?php echo e($item['visual']['primary']); ?>"></label>
            <label style="font-size:12px">İkincil renk<input class="dg-input" name="items[<?php echo e($item['id']); ?>][secondary]" value="<?php echo e($item['visual']['secondary']); ?>"></label>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:12px">
            <label><input type="checkbox" name="items[<?php echo e($item['id']); ?>][purchasable]" value="1" <?php echo $item['purchasable'] === 1 ? 'checked' : ''; ?>> Satın alınabilir</label>
            <label><input type="checkbox" name="items[<?php echo e($item['id']); ?>][published]" value="1" <?php echo $item['published'] === 1 ? 'checked' : ''; ?>> Yayında</label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="dg-card" style="padding:14px;margin-top:14px">
      <h2 style="font-size:14px;margin-bottom:8px">Yeni kozmetik (taslak olarak eklenir)</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px">
        <label style="font-size:12px">Kimlik<input class="dg-input" name="new_id" placeholder="char-yeni"></label>
        <label style="font-size:12px">Ad<input class="dg-input" name="new_name"></label>
        <label style="font-size:12px">Kategori
          <select class="dg-input" name="new_category">
            <?php foreach (MG_CATEGORY_LABELS as $key => $label): ?>
              <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="font-size:12px">Fiyat<input class="dg-input" type="number" min="0" name="new_price" value="0"></label>
        <label style="font-size:12px">Ana renk<input class="dg-input" name="new_primary" value="#c5f46f"></label>
        <label style="font-size:12px">İkincil renk<input class="dg-input" name="new_secondary" value="#1b152c"></label>
      </div>
      <label style="font-size:12px;display:grid;gap:4px;margin-top:8px">Açıklama
        <input class="dg-input" name="new_description">
      </label>
    </div>
    <button class="dg-btn dg-btn-primary" style="margin-top:14px" type="submit">Kataloğu kaydet</button>
  </form>
<?php endif; ?>

<?php if ($section === 'inceleme'): ?>
  <p class="dg-muted" style="font-size:13px;margin-bottom:12px">
    Bekleyen turlar ödül almaz ve sıralamaya girmez. Onayladığınızda doğrulanmış sayılır ve ödül
    idempotent biçimde ödenir. Karar ve gerekçe denetim günlüğüne yazılır.
  </p>
  <?php if (!$pending): ?>
    <div class="dg-card" style="padding:16px"><p class="dg-muted" style="font-size:13px">İnceleme bekleyen koşu yok.</p></div>
  <?php endif; ?>
  <?php foreach ($pending as $run): ?>
    <div class="dg-card" style="padding:14px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="font-size:13px;line-height:1.7">
          <b><?php echo e($run['username']); ?></b> · skor <b><?php echo number_format($run['score']); ?></b>
          · <?php echo number_format($run['ticks']); ?> tick · <?php echo (int)$run['cells']; ?> hücre<br>
          <span class="dg-muted" style="font-size:12px">
            <?php echo e($run['submitted_utc']); ?> · risk <?php echo (int)$run['risk']; ?>
            · girdi <?php echo e(substr($run['input_hash'], 0, 12)); ?>
            · ip-hash <?php echo e(substr($run['ip_hash'], 0, 8)); ?>
            · <?php echo e(implode(', ', $run['reasons'])); ?>
          </span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:start">
          <form method="post" style="display:flex;gap:6px;align-items:center">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="review_run">
            <input type="hidden" name="run_id" value="<?php echo e($run['run_id']); ?>">
            <input class="dg-input" name="note" placeholder="Gerekçe" style="width:140px">
            <button class="dg-btn dg-btn-primary dg-btn-sm" name="decision" value="approve" type="submit">Onayla</button>
            <button class="dg-btn dg-btn-ghost dg-btn-sm" name="decision" value="reject" type="submit">Reddet</button>
          </form>
        </div>
      </div>
      <form method="post" style="display:flex;gap:6px;align-items:center;margin-top:10px;flex-wrap:wrap">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="override_score">
        <input type="hidden" name="run_id" value="<?php echo e($run['run_id']); ?>">
        <span style="font-size:12px" class="dg-muted">Skor düzeltme:</span>
        <input class="dg-input" type="number" min="0" name="score" value="<?php echo (int)$run['score']; ?>" style="width:110px">
        <input class="dg-input" name="reason" placeholder="Gerekçe (zorunlu)" style="width:200px">
        <button class="dg-btn dg-btn-ghost dg-btn-sm" type="submit">Uygula</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($section === 'sezon'): ?>
  <div class="dg-card" style="padding:16px;margin-bottom:16px">
    <h2 style="font-size:15px;margin-bottom:8px">Yeni sezon (taslak)</h2>
    <p class="dg-muted" style="font-size:12px;margin-bottom:10px">
      Sezon aktifleştirildiğinde bölüm tohumu sabitlenir: hafta boyunca herkes aynı düzeni oynar.
      Ödüllü yarışmayı başlatmadan önce katılım koşullarını ve yasal gereklilikleri işletmeciyle onaylayın.
    </p>
    <form method="post" style="display:grid;gap:8px">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="season_create">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px">
        <label style="font-size:12px">Başlık<input class="dg-input" name="title" value="Haftalık Yarışma"></label>
        <label style="font-size:12px">Ödül adı<input class="dg-input" name="prize_name" placeholder="Örn. 1 aylık Game Pass (DEMO)"></label>
        <label style="font-size:12px">Platform<input class="dg-input" name="platform" placeholder="PC / konsol"></label>
        <label style="font-size:12px">Bölge<input class="dg-input" name="region" placeholder="Türkiye"></label>
        <label style="font-size:12px">Başlangıç (TR)<input class="dg-input" type="datetime-local" name="start_local" value="<?php echo e(mg_admin_ts_to_local(time())); ?>"></label>
        <label style="font-size:12px">Bitiş (TR)<input class="dg-input" type="datetime-local" name="end_local" value="<?php echo e(mg_admin_ts_to_local(time() + 7 * 86400)); ?>"></label>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px">
        <label style="font-size:12px">Katılım koşulu<input class="dg-input" name="eligibility" placeholder="Örn. 18 yaş üstü, tek hesap"></label>
        <label style="font-size:12px">Katılım açıklaması<input class="dg-input" name="participation" placeholder="Nasıl katılınır?"></label>
        <label style="font-size:12px">Sonuç notu<input class="dg-input" name="results_note" placeholder="Sonuçlar ne zaman açıklanır?"></label>
        <label style="font-size:12px">Teslim notu<input class="dg-input" name="delivery_note" placeholder="Ödül nasıl teslim edilir?"></label>
        <label style="font-size:12px">Kapak rengi 1<input class="dg-input" name="c1" value="#2b2141"></label>
        <label style="font-size:12px">Kapak rengi 2<input class="dg-input" name="c2" value="#c5f46f"></label>
      </div>
      <button class="dg-btn dg-btn-primary" type="submit">Taslak oluştur</button>
    </form>
  </div>

  <?php foreach (array_reverse($seasons) as $season): ?>
    <?php $pub = mg_season_public($season); ?>
    <div class="dg-card" style="padding:14px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <div style="font-size:13px">
          <b>#<?php echo (int)$season['id']; ?> · <?php echo e($season['title']); ?></b>
          <span class="dg-muted"> · <?php echo e($pub['status_label']); ?></span><br>
          <span class="dg-muted" style="font-size:12px">
            <?php echo e(mg_admin_ts_to_local((int)$season['start_ts'])); ?> → <?php echo e(mg_admin_ts_to_local((int)$season['end_ts'])); ?>
            · tohum <?php echo (int)$season['seed']; ?>
            <?php if (!empty($season['final']['winner_username'])): ?> · kazanan <?php echo e($season['final']['winner_username']); ?><?php endif; ?>
          </span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php foreach (MG_SEASON_TRANSITIONS[$season['status']] ?? [] as $to): ?>
            <form method="post" style="display:inline">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="season_transition">
              <input type="hidden" name="season_id" value="<?php echo (int)$season['id']; ?>">
              <input type="hidden" name="to" value="<?php echo e($to); ?>">
              <button class="dg-btn dg-btn-ghost dg-btn-sm" type="submit">→ <?php echo e(MG_SEASON_STATUSES[$to] ?? $to); ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (in_array($season['status'], ['review', 'active'], true) && empty($season['final']['winner_user_id'])): ?>
        <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="season_finalize">
          <input type="hidden" name="season_id" value="<?php echo (int)$season['id']; ?>">
          <span style="font-size:12px" class="dg-muted">Kazanan (boş = tablo lideri):</span>
          <select class="dg-input" name="winner_user_id" style="max-width:220px">
            <option value="0">Tablo lideri</option>
            <?php foreach (mg_season_snapshot_rows(array_slice(mg_board_sorted(mg_board_key('season', time(), $season)), 0, 10)) as $row): ?>
              <option value="<?php echo (int)$row['user_id']; ?>"><?php echo e($row['username']); ?> — <?php echo number_format($row['score']); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="dg-btn dg-btn-primary dg-btn-sm" type="submit">Kesinleştir ve şampiyonu ver</button>
        </form>
      <?php endif; ?>

      <?php if (($season['status'] ?? '') === 'finalized'): ?>
        <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="season_deliver">
          <input type="hidden" name="season_id" value="<?php echo (int)$season['id']; ?>">
          <span style="font-size:12px" class="dg-muted">Teslim notu:</span>
          <input class="dg-input" name="note" style="max-width:280px" placeholder="Ödül teslim edildi (tarih, kanal)">
          <button class="dg-btn dg-btn-ghost dg-btn-sm" type="submit">Teslim edildi olarak işaretle</button>
        </form>
      <?php endif; ?>

      <?php if (!empty($season['review_snapshot']['top'])): ?>
        <details style="margin-top:10px">
          <summary style="font-size:12px;cursor:pointer" class="dg-muted">İnceleme anlık görüntüsü (<?php echo count($season['review_snapshot']['top']); ?> satır)</summary>
          <ol style="font-size:12px;margin-top:6px">
            <?php foreach ($season['review_snapshot']['top'] as $row): ?>
              <li><?php echo e($row['username']); ?> — <?php echo number_format($row['score']); ?> (<?php echo e($row['run_id']); ?>)</li>
            <?php endforeach; ?>
          </ol>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($section === 'rapor'): ?>
  <?php if ($report): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
      <div class="dg-card" style="padding:14px"><p class="dg-muted" style="font-size:12px">Dağıtılan (<?php echo (int)$report['period_days']; ?> gün)</p><p style="font-size:20px;font-weight:700"><?php echo number_format($report['distributed']); ?></p></div>
      <div class="dg-card" style="padding:14px"><p class="dg-muted" style="font-size:12px">Harcanan (kozmetik)</p><p style="font-size:20px;font-weight:700"><?php echo number_format($report['spent']); ?></p></div>
      <div class="dg-card" style="padding:14px"><p class="dg-muted" style="font-size:12px">Satın alma</p><p style="font-size:20px;font-weight:700"><?php echo (int)$report['purchase_count']; ?></p></div>
      <div class="dg-card" style="padding:14px"><p class="dg-muted" style="font-size:12px">Aktif oyuncu</p><p style="font-size:20px;font-weight:700"><?php echo (int)$report['active_players']; ?></p></div>
      <div class="dg-card" style="padding:14px"><p class="dg-muted" style="font-size:12px">Bugün dağıtılan</p><p style="font-size:20px;font-weight:700"><?php echo number_format($report['today_rewarded']); ?></p><p class="dg-muted" style="font-size:12px">Bütçe: <?php echo $report['today_budget'] > 0 ? number_format($report['today_budget']) : 'sınırsız'; ?></p></div>
    </div>
    <p class="dg-muted" style="font-size:12px;margin:10px 0 16px"><?php echo e($report['note']); ?></p>

    <div class="dg-card" style="padding:14px;margin-bottom:16px">
      <h2 style="font-size:14px;margin-bottom:8px">Bakiye düzeltmesi (dengeleyici ledger girişi)</h2>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="balance_adjust">
        <select class="dg-input" name="user_id" style="max-width:220px">
          <?php foreach ($walletUsers as $u): ?>
            <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['username']); ?> (<?php echo number_format((int)$u['dcoin_balance']); ?>)</option>
          <?php endforeach; ?>
        </select>
        <input class="dg-input" type="number" name="amount" placeholder="+ / - D-Coin" style="width:130px">
        <input class="dg-input" name="reason" placeholder="Gerekçe (zorunlu)" style="width:240px">
        <button class="dg-btn dg-btn-ghost dg-btn-sm" type="submit">Düzelt</button>
      </form>
    </div>

    <div class="dg-card" style="padding:14px">
      <h2 style="font-size:14px;margin-bottom:8px">Denetim günlüğü (son 40)</h2>
      <div style="overflow-x:auto">
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead><tr><th style="text-align:left;padding:6px">UTC</th><th style="text-align:left;padding:6px">Yönetici</th><th style="text-align:left;padding:6px">İşlem</th><th style="text-align:left;padding:6px">Hedef</th></tr></thead>
          <tbody>
            <?php foreach ($audit as $row): ?>
              <tr>
                <td style="padding:6px;border-top:1px solid var(--line)"><?php echo e((string)($row['ts'] ?? '')); ?></td>
                <td style="padding:6px;border-top:1px solid var(--line)"><?php echo (int)($row['actor_id'] ?? 0); ?></td>
                <td style="padding:6px;border-top:1px solid var(--line)"><?php echo e((string)($row['action'] ?? '')); ?></td>
                <td style="padding:6px;border-top:1px solid var(--line)"><?php echo e((string)($row['target'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php admin_foot(); ?>
