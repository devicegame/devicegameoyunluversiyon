<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// İşlemler
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { flash_error_set('CSRF hatası'); header('Location: users.php'); exit; }
  // D-Coin ekle/çıkar
  if (isset($_POST['adjust_coin'])) {
    $uid=(int)($_POST['user_id']??0);
    $amount=(int)($_POST['amount']??0);
    $reason=trim($_POST['reason']??'Admin işlemi');
    if ($uid<=0 || $amount===0) { flash_error_set('Geçersiz tutar'); header('Location: users.php'); exit; }
    if ($amount < -100000 || $amount > 100000) { flash_error_set('Tutár aralığı: -100000 .. 100000'); header('Location: users.php'); exit; }
    try {
      $result = dg_mutate(function (array &$data) use ($uid, $amount): array {
        $i = dg_index_of_id($data['users'], $uid);
        if ($i === null) return ['ok' => false, 'error' => 'Kullanıcı bulunamadı.'];
        $bal = (int)($data['users'][$i]['dcoin_balance'] ?? 0);
        if ($bal + $amount < 0) {
          return ['ok' => false, 'error' => "Bakiye yetersiz. Mevcut: $bal, çıkarmak istenen: " . abs($amount)];
        }
        $data['users'][$i]['dcoin_balance'] = $bal + $amount;
        $data['users'][$i]['updated_at'] = dg_now();
        return ['ok' => true];
      });
      if (empty($result['ok'])) flash_error_set((string)$result['error']);
      else flash_set(($amount>0?"+$amount":"$amount")." D-Coin uygulandı. Gerekçe: $reason");
    } catch (Throwable $e) {
      error_log('[admin users coin] ' . $e->getMessage());
      flash_error_set('Bakiye güncellenemedi. Depolama izinlerini kontrol edin.');
    }
    header('Location: users.php'); exit;
  }
  if (isset($_POST['toggle_ban'])) {
    $uid=(int)($_POST['user_id']??0);
    if ($uid === (int)$user['id']) { flash_error_set('Kendini banlayamazsın'); header('Location: users.php'); exit; }
    dg_mutate(function (array &$data) use ($uid): void {
      $i = dg_index_of_id($data['users'], $uid);
      if ($i !== null) {
        $data['users'][$i]['is_banned'] = 1 - (int)($data['users'][$i]['is_banned'] ?? 0);
        $data['users'][$i]['updated_at'] = dg_now();
      }
    });
    flash_set('Ban durumu değiştirildi.');
    header('Location: users.php'); exit;
  }
  if (isset($_POST['toggle_admin'])) {
    $uid=(int)($_POST['user_id']??0);
    if ($uid === (int)$user['id']) { flash_error_set('Kendi admin yetkinle oynama'); header('Location: users.php'); exit; }
    dg_mutate(function (array &$data) use ($uid): void {
      $i = dg_index_of_id($data['users'], $uid);
      if ($i !== null) {
        $data['users'][$i]['is_admin'] = 1 - (int)($data['users'][$i]['is_admin'] ?? 0);
        $data['users'][$i]['updated_at'] = dg_now();
      }
    });
    flash_set('Admin yetkisi değiştirildi.');
    header('Location: users.php'); exit;
  }
}

// Arama & liste
$q = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page']??1));
$per = 20; $off=($page-1)*$per;
$all_users = dg_all('users');
usort($all_users, fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
if ($q!=='') {
  $needle = dg_casefold($q);
  $all_users = array_values(array_filter($all_users, function (array $u) use ($needle): bool {
    return strpos(dg_casefold((string)($u['username'] ?? '')), $needle) !== false
        || strpos(dg_casefold((string)($u['email'] ?? '')), $needle) !== false;
  }));
}
$total = count($all_users);
$users = array_slice($all_users, $off, $per);
$pages = (int)ceil($total/$per);

admin_head('Kullanıcı Yönetimi');
?>

<div style="display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <p class="dg-kicker">USERS // MANAGEMENT</p>
    <h1 style="font-family:Bricolage Grotesque,sans-serif;font-weight:800;font-size:22px">Kullanıcı Yönetimi</h1>
    <p class="dg-muted" style="font-size:13px">Ara, D-Coin ekle/çıkar, banla veya admin yap.</p>
  </div>
  <span class="dg-badge"><?=$total?> KULLANICI</span>
</div>

<div class="dg-card">
  <div class="dg-card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="get" style="display:flex;gap:8px;flex:1;min-width:260px">
      <div class="dg-search" style="flex:1"><input type="search" name="q" value="<?=e($q)?>" placeholder="Kullanıcı adı veya e-posta ara..."></div>
      <button class="dg-btn dg-btn-primary dg-btn-sm">Ara</button>
      <?php if($q!==''): ?><a href="users.php" class="dg-btn dg-btn-ghost dg-btn-sm">Temizle</a><?php endif; ?>
    </form>
    <span class="dg-chip">Sayfa <?=$page?> / <?=$pages?></span>
  </div>
</div>

<div class="dg-card" style="margin-top:14px;overflow:hidden">
  <div class="dg-table-wrap" style="border:none;border-radius:0">
  <table class="dg-table">
    <thead><tr><th>ID</th><th>Kullanıcı</th><th>E-posta</th><th>Bakiye</th><th>Durum</th><th>Üyelik</th><th style="min-width:260px">İşlemler</th></tr></thead>
    <tbody>
    <?php if(!$users): ?><tr><td colspan="7" class="dg-empty">Kullanıcı bulunamadı</td></tr><?php endif; ?>
    <?php foreach($users as $u): ?>
      <tr>
        <td><span class="dg-chip">#<?= (int)$u['id'] ?></span></td>
        <td><b><?=e($u['username'])?></b> <?= (int)$u['is_admin']?'<span style="background:linear-gradient(135deg,var(--cyan),var(--purple));color:#fff;font:700 9px Instrument Sans,sans-serif;padding:3px 7px;border-radius:999px">ADMIN</span>':'' ?></td>
        <td style="color:var(--muted);font-size:12px"><?=e($u['email'])?></td>
        <td><span style="background:linear-gradient(135deg,#F4C15D,#C4644A);color:#1a1200;font:800 12px Instrument Sans,sans-serif;padding:5px 10px;border-radius:999px"><?=number_format((int)$u['dcoin_balance'])?> ◈</span></td>
        <td><?php if((int)$u['is_banned']): ?><span style="background:rgba(224,96,122,0.15);color:#F0A6B7;font:700 10px Instrument Sans,sans-serif;padding:5px 9px;border-radius:999px;border:1px solid rgba(224,96,122,0.3)">BANLI</span><?php else: ?><span style="background:rgba(79,224,160,0.12);color:#A7F3C9;font:700 10px Instrument Sans,sans-serif;padding:5px 9px;border-radius:999px;border:1px solid rgba(79,224,160,0.2)">Aktif</span><?php endif; ?></td>
        <td><small class="dg-mini"><?=e($u['created_at'])?></small></td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <!-- D-Coin form -->
            <form method="post" style="display:flex;gap:4px;align-items:center;background:rgba(255,255,255,0.04);border:1px solid var(--line);border-radius:10px;padding:4px 6px">
              <?=csrf_field()?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="number" name="amount" placeholder="±" style="width:70px;background:rgba(255,255,255,0.06);border:1px solid var(--line);color:var(--text);padding:6px 8px;border-radius:8px;font:600 12px Instrument Sans,sans-serif">
              <input type="text" name="reason" placeholder="neden?" style="width:90px;background:rgba(255,255,255,0.06);border:1px solid var(--line);color:var(--text);padding:6px 8px;border-radius:8px;font:500 11px Instrument Sans,sans-serif">
              <button name="adjust_coin" class="dg-btn dg-btn-primary" style="padding:6px 10px;font-size:11px">Uygula</button>
            </form>
            <form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button name="toggle_ban" class="dg-btn dg-btn-ghost dg-btn-sm" style="font-size:11px;<?= (int)$u['is_banned']?'color:var(--green)':'color:var(--magenta)' ?>"><?= (int)$u['is_banned']?'Unban':'Ban' ?></button></form>
            <form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button name="toggle_admin" class="dg-btn dg-btn-ghost dg-btn-sm" style="font-size:11px"><?= (int)$u['is_admin']?'Admin Al':'Admin Yap' ?></button></form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if($pages>1): ?>
  <div style="display:flex;gap:6px;justify-content:center;padding:12px;border-top:1px solid var(--line)">
    <?php for($i=1;$i<=$pages;$i++): ?><a href="?<?=http_build_query(['q'=>$q,'page'=>$i])?>" class="dg-btn <?= $i===$page?'dg-btn-primary':'dg-btn-ghost' ?> dg-btn-sm"><?=$i?></a><?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php admin_foot(); ?>
