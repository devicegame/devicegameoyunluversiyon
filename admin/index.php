<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// İstatistikler — tamamı storage/dg/database.json dosyasından
$data = dg_read_data();
$users_all = $data['users'] ?? [];
$watch_logs_all = $data['watch_logs'] ?? [];
$purchases_all = $data['purchases'] ?? [];
$today = date('Y-m-d');

$total_users = count($users_all);
$total_admins = 0;
$total_banned = 0;
$total_dcoin_circulating = 0;
$today_users = 0;
foreach ($users_all as $u) {
  if ((int)($u['is_admin'] ?? 0) === 1) $total_admins++;
  if ((int)($u['is_banned'] ?? 0) === 1) $total_banned++;
  $total_dcoin_circulating += (int)($u['dcoin_balance'] ?? 0);
  if (substr((string)($u['created_at'] ?? ''), 0, 10) === $today) $today_users++;
}

$total_dcoin_distributed = 0;
$today_watches = 0;
foreach ($watch_logs_all as $log) {
  $total_dcoin_distributed += (int)($log['earned_dcoin'] ?? 0);
  if (substr((string)($log['watched_at'] ?? ''), 0, 10) === $today) $today_watches++;
}
$total_watches = count($watch_logs_all);
$total_purchases = count($purchases_all);
$total_revenue_tl = 0.0;
foreach ($purchases_all as $p) {
  if (($p['payment_method'] ?? '') === 'cash') $total_revenue_tl += (float)($p['price_paid_tl'] ?? 0);
}
$active_ads = count(array_filter($data['ads'] ?? [], fn(array $a): bool => (int)($a['is_active'] ?? 0) === 1));
$active_products = count(array_filter($data['store_items'] ?? [], fn(array $i): bool => (int)($i['is_active'] ?? 0) === 1));

// Son hareketler
$users_by_id = dg_index_by_id($users_all);
$ads_by_id = dg_index_by_id($data['ads'] ?? []);
$items_by_id = dg_index_by_id($data['store_items'] ?? []);
$recent_watches = [];
foreach (array_slice(dg_sort_newest($watch_logs_all, 'watched_at'), 0, 6) as $log) {
  $log['username'] = $users_by_id[(int)$log['user_id']]['username'] ?? ('#' . (int)$log['user_id']);
  $log['title'] = $ads_by_id[(int)$log['ad_id']]['title'] ?? ($log['ad_title'] ?? 'Silinmiş reklam');
  $recent_watches[] = $log;
}
$recent_purchases = [];
foreach (array_slice(dg_sort_newest($purchases_all, 'created_at'), 0, 6) as $purchase) {
  $purchase['username'] = $users_by_id[(int)$purchase['user_id']]['username'] ?? ('#' . (int)$purchase['user_id']);
  $purchase['name'] = $items_by_id[(int)$purchase['store_item_id']]['name'] ?? ($purchase['item_name'] ?? 'Silinmiş ürün');
  $recent_purchases[] = $purchase;
}

// Ayar: günlük limit güncelleme
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_limit'])) {
  if (!csrf_verify($_POST['csrf'] ?? '')) { flash_error_set('CSRF hatası'); header('Location: index.php'); exit; }
  $new = max(1, min(50, (int)($_POST['daily_limit'] ?? 5)));
  dg_set_daily_watch_limit($new);
  flash_set("Günlük limit $new olarak güncellendi.");
  header('Location: index.php'); exit;
}
$cur_limit = daily_watch_limit();

admin_head('Dashboard');
?>
<div style="display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <p class="dg-kicker">ADMIN // CONTROL CENTER</p>
    <h1 style="font-family:Bricolage Grotesque,sans-serif;font-weight:800;font-size:26px;letter-spacing:-0.02em">Genel Bakış</h1>
    <p class="dg-muted" style="font-size:13px">Sistemin kalbi burada atıyor. Tüm metrikler canlı.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="ads.php" class="dg-btn dg-btn-primary dg-btn-sm">+ Reklam Ekle</a>
    <a href="store.php" class="dg-btn dg-btn-ghost dg-btn-sm">+ Ürün Ekle</a>
    <a href="blog.php" class="dg-btn dg-btn-ghost dg-btn-sm">+ Yazı Ekle</a>
  </div>
</div>

<div class="dg-stats">
  <div class="dg-stat" style="border-color:rgba(79,224,160,0.25)"><label>👥 Toplam Kullanıcı</label><strong><?=$total_users?></strong><small><?=$total_admins?> admin • <?=$total_banned?> banlı</small></div>
  <div class="dg-stat" style="border-color:rgba(244,193,93,0.35)"><label>◈ Dolaşımdaki D-Coin</label><strong style="color:var(--amber)"><?=number_format($total_dcoin_circulating)?></strong><small>Dağıtılan toplam: <?=number_format($total_dcoin_distributed)?></small></div>
  <div class="dg-stat" style="border-color:rgba(79,224,160,0.3)"><label>▶ İzlenen Reklam</label><strong style="color:var(--purple2)"><?=number_format($total_watches)?></strong><small>Bugün: <?=$today_watches?> izleme</small></div>
  <div class="dg-stat" style="border-color:rgba(79,224,160,0.25)"><label>🛒 Satılan Ürün</label><strong style="color:var(--green)"><?=number_format($total_purchases)?></strong><small>Nakit ciro: <?=tl_format($total_revenue_tl)?></small></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
  <div class="dg-card">
    <div class="dg-card-head"><h2>⚙ Hızlı Ayar</h2></div>
    <div class="dg-card-body">
      <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
        <?=csrf_field()?>
        <label class="dg-field" style="flex:1;min-width:160px"><span>Günlük İzleme Limiti</span><input type="number" name="daily_limit" min="1" max="50" value="<?=$cur_limit?>"></label>
        <button name="update_limit" value="1" class="dg-btn dg-btn-primary">Güncelle</button>
      </form>
      <p class="dg-mini" style="margin-top:8px">Kullanıcı başına günde maksimum video. Varsayılan 5.</p>
      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
        <span class="dg-chip on">Aktif reklam: <?=$active_ads?></span>
        <span class="dg-chip on">Aktif ürün: <?=$active_products?></span>
        <span class="dg-chip">Bugün kayıt: <?=$today_users?></span>
      </div>
    </div>
  </div>
  <div class="dg-card">
    <div class="dg-card-head"><h2>📈 Sistem Durumu</h2><span class="dg-badge dg-badge-live">CANLI</span></div>
    <div class="dg-card-body" style="display:grid;gap:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(79,224,160,0.06);border:1px solid var(--line);border-radius:12px;padding:10px 14px"><span style="font-size:13px">Dosya Depolama</span><span style="font:700 11px Instrument Sans,sans-serif;background:rgba(79,224,160,0.15);color:#A7F3C9;padding:5px 10px;border-radius:999px">● JSON Hazır</span></div>
      <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.03);border:1px solid var(--line);border-radius:12px;padding:10px 14px"><span style="font-size:13px">Anti-Cheat</span><span style="font:700 11px Instrument Sans,sans-serif;background:rgba(79,224,160,0.12);color:var(--cyan);padding:5px 10px;border-radius:999px">🛡️ Aktif</span></div>
      <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.03);border:1px solid var(--line);border-radius:12px;padding:10px 14px"><span style="font-size:13px">PHP Sürümü</span><span class="dg-chip"><?=PHP_VERSION?></span></div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
  <div class="dg-card">
    <div class="dg-card-head"><h2>Son İzlemeler</h2><a href="logs.php" class="dg-btn dg-btn-ghost dg-btn-sm">Tümü →</a></div>
    <div style="overflow:auto">
      <table class="dg-table" style="min-width:unset">
        <thead><tr><th>Kullanıcı</th><th>Reklam</th><th>+Coin</th><th>Zaman</th></tr></thead>
        <tbody>
        <?php if(!$recent_watches): ?><tr><td colspan="4" class="dg-empty">Henüz izleme yok</td></tr><?php endif; ?>
        <?php foreach($recent_watches as $r): ?>
          <tr><td><b><?=e($r['username'])?></b></td><td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=e($r['title'])?></td><td><span style="background:linear-gradient(135deg,#F4C15D,#C4644A);color:#1a1200;font:700 11px Instrument Sans,sans-serif;padding:4px 8px;border-radius:999px">+<?= (int)$r['earned_dcoin'] ?></span></td><td><small class="dg-mini"><?=e($r['watched_at'])?></small></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="dg-card">
    <div class="dg-card-head"><h2>Son Satışlar</h2><a href="logs.php#sat" class="dg-btn dg-btn-ghost dg-btn-sm">Tümü →</a></div>
    <div style="overflow:auto">
      <table class="dg-table" style="min-width:unset">
        <thead><tr><th>Kullanıcı</th><th>Ürün</th><th>Ödeme</th><th>Zaman</th></tr></thead>
        <tbody>
        <?php if(!$recent_purchases): ?><tr><td colspan="4" class="dg-empty">Henüz satış yok</td></tr><?php endif; ?>
        <?php foreach($recent_purchases as $r): ?>
          <tr><td><b><?=e($r['username'])?></b></td><td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=e($r['name'])?></td><td><?php if($r['payment_method']=='dcoin'): ?><span style="background:rgba(244,193,93,0.15);color:var(--amber);font:700 11px Instrument Sans,sans-serif;padding:4px 8px;border-radius:999px;border:1px solid rgba(244,193,93,0.25)">◈ <?= (int)$r['price_paid_dcoin'] ?></span><?php else: ?><span style="background:rgba(79,224,160,0.12);color:#A7F3C9;font:700 11px Instrument Sans,sans-serif;padding:4px 8px;border-radius:999px;border:1px solid rgba(79,224,160,0.2)">₺ <?=tl_format($r['price_paid_tl'])?></span><?php endif;?></td><td><small class="dg-mini"><?=e($r['created_at'])?></small></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
