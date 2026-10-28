<?php
// Admin layout helpers
function admin_head(string $title): void {
  $u = $GLOBALS['user'] ?? null;
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?=$title?> — Device Game Yönetim</title>
<script src="../assets/js/scroll-restore.js" defer></script>
<link rel="stylesheet" href="../assets/css/devicegame.css">
<style>
.admin-wrap{max-width:1280px;margin:0 auto;padding:20px 24px 60px}
.admin-topbar{position:sticky;top:0;z-index:30;background:rgba(10,26,21,0.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 24px;flex-wrap:wrap}
.admin-tabs{display:flex;gap:8px;flex-wrap:wrap}
.admin-tabs a{font:600 12px Instrument Sans,sans-serif;letter-spacing:0.04em;padding:9px 14px;border-radius:999px;border:1px solid var(--line);color:var(--muted);background:rgba(190,225,208,0.04);transition:.2s}
.admin-tabs a:hover{color:var(--text);border-color:rgba(190,225,208,0.32)}
.admin-tabs a.active{color:#0A1A15;background:var(--cyan);border-color:var(--cyan)}
</style>
<link rel="stylesheet" href="../assets/css/colorful-theme.css">
</head>
<body class="dg-body">
<div class="dg-grid-bg"></div>
<div class="admin-topbar">
  <a href="../index.php" class="dg-logo" style="font-size:17px">Device Game<span>.</span> <small style="font:600 10px Instrument Sans,sans-serif;letter-spacing:0.08em;background:var(--cyan);color:#0A1A15;padding:4px 8px;border-radius:999px;margin-left:8px">YÖNETİM</small></a>
  <nav class="admin-tabs">
    <a href="index.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='index.php'?'active':''?>">📊 Dashboard</a>
    <a href="ads.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='ads.php'?'active':''?>">🎬 Reklamlar</a>
    <a href="users.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='users.php'?'active':''?>">👥 Kullanıcılar</a>
    <a href="store.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='store.php'?'active':''?>">🛒 Mağaza</a>
    <a href="blog.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='blog.php'?'active':''?>">📰 Blog</a>
    <a href="social.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='social.php'?'active':''?>">💬 Sosyal</a>
    <a href="minigame.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='minigame.php'?'active':''?>">🎮 Mini Oyun</a>
    <a href="logs.php" class="<?=basename($_SERVER['SCRIPT_NAME'])==='logs.php'?'active':''?>">📜 Loglar</a>
  </nav>
  <div style="display:flex;gap:8px;align-items:center">
    <span class="dg-user-pill"><b><?=e($u['username']??'admin')?></b> <span class="dg-coin"><i>◈</i> <?=number_format((int)($u['dcoin_balance']??0))?></span></span>
    <a href="../index.php" class="dg-btn dg-btn-ghost dg-btn-sm">Siteye Dön</a>
    <a href="../logout.php" class="dg-btn dg-btn-ghost dg-btn-sm">Çıkış</a>
  </div>
</div>
<?php
  $f = flash_get(); $fe = flash_error_get();
  if($f) echo '<div style="max-width:1280px;margin:14px auto 0;padding:0 24px"><div class="dg-alert dg-alert-success">'.e($f['msg']).'</div></div>';
  if($fe) echo '<div style="max-width:1280px;margin:14px auto 0;padding:0 24px"><div class="dg-alert dg-alert-error">'.e($fe).'</div></div>';
 echo '<main class="admin-wrap">';
}
function admin_foot(): void {
  echo '</main><footer style="text-align:center;color:var(--dim);font:500 11px Instrument Sans,sans-serif;padding:18px">Device Game Yönetim • '.date('Y').'</footer></body></html>';
}
