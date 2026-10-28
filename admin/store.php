<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// Yönetim sayfası ilk kez ana sayfadan önce açılırsa da eski katalog görünsün.
try {
  dg_sync_legacy_products(dg_legacy_catalog_from_files());
} catch (Throwable $e) {
  error_log('[admin store legacy sync] ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { flash_error_set('CSRF hatası'); header('Location: store.php'); exit; }

  if (isset($_POST['save_item'])) {
    $id = (int)($_POST['id']??0);
    $name = trim($_POST['name']??'');
    $desc = trim($_POST['description']??'');
    $image_url = trim($_POST['image_url']??'');
    $price_tl = (float)($_POST['price_tl']??0);
    $price_dcoin = (int)($_POST['price_dcoin']??0);
    $is_active = isset($_POST['is_active'])?1:0;
    $stock_mode = (($_POST['stock_mode'] ?? 'keys') === 'quantity') ? 'quantity' : 'keys';
    $stock_qty = max(0, (int)($_POST['stock_qty'] ?? 0));
    $keys_raw = trim($_POST['keys']??'');

    if ($name==='') { flash_error_set('Ürün adı zorunlu'); header('Location: store.php'); exit; }
    if ($price_tl<0) $price_tl=0;
    if ($price_dcoin<0) $price_dcoin=0;

    $keys_list = [];
    if ($keys_raw!=='') {
      foreach (preg_split('/\r\n|\n|\r|,/', $keys_raw) ?: [] as $k) {
        $k = trim((string)$k);
        if ($k !== '') $keys_list[] = $k;
      }
    }

    try {
      $saved = dg_mutate(function (array &$data) use ($id, $name, $desc, $image_url, $price_tl, $price_dcoin, $is_active, $stock_mode, $stock_qty, $keys_list): bool {
        $now = dg_now();
        $itemId = $id;

        if ($id > 0) {
          $i = dg_index_of_id($data['store_items'], $id);
          if ($i === null) return false;
          $data['store_items'][$i]['name'] = $name;
          $data['store_items'][$i]['description'] = $desc;
          $data['store_items'][$i]['image_url'] = $image_url;
          $data['store_items'][$i]['price_tl'] = $price_tl;
          $data['store_items'][$i]['price_dcoin'] = $price_dcoin;
          $data['store_items'][$i]['is_active'] = $is_active;
          $data['store_items'][$i]['stock_mode'] = $stock_mode;
          if ($stock_mode === 'quantity') {
            $data['store_items'][$i]['stock'] = $stock_qty;
            // Elle girilen adet, eski katalogdaki güncel stoğa göre yeni
            // referans kabul edilir; sonraki katalog değişimi fark olarak işlenir.
            $legacy_slug = (string)($data['store_items'][$i]['legacy_slug'] ?? '');
            if ($legacy_slug !== '') {
              foreach (dg_legacy_catalog_from_files() as $legacy_product) {
                if ((string)($legacy_product['slug'] ?? '') === $legacy_slug) {
                  $data['store_items'][$i]['legacy_stock_snapshot'] = max(0, (int)($legacy_product['stock'] ?? 0));
                  break;
                }
              }
            }
          }
          $data['store_items'][$i]['updated_at'] = $now;
        } else {
          $itemId = dg_next_id($data, 'store_items');
          $data['store_items'][] = [
            'id' => $itemId,
            'name' => $name,
            'description' => $desc,
            'image_url' => $image_url,
            'price_tl' => $price_tl,
            'price_dcoin' => $price_dcoin,
            'stock' => $stock_mode === 'quantity' ? $stock_qty : 0,
            'stock_mode' => $stock_mode,
            'legacy_slug' => null,
            'is_active' => $is_active,
            'created_at' => $now,
            'updated_at' => $now,
          ];
        }

        foreach (($stock_mode === 'keys' ? $keys_list : []) as $code) {
          $data['store_keys'][] = [
            'id' => dg_next_id($data, 'store_keys'),
            'store_item_id' => $itemId,
            'key_code' => $code,
            'is_used' => 0,
            'used_by' => null,
            'used_at' => null,
            'created_at' => $now,
          ];
        }
        dg_refresh_store_stock($data);
        return true;
      });
      if ($saved) flash_set($id > 0 ? 'Ürün güncellendi.' : 'Ürün eklendi.');
      else flash_error_set('Ürün bulunamadı.');
    } catch (Throwable $e) {
      error_log('[admin store save] ' . $e->getMessage());
      flash_error_set('Ürün kaydedilemedi. Depolama izinlerini kontrol edin.');
    }
    header('Location: store.php'); exit;
  }
  if (isset($_POST['delete_item'])) {
    $id=(int)($_POST['id']??0);
    dg_mutate(function (array &$data) use ($id): void {
      $data['store_items'] = array_values(array_filter($data['store_items'], fn(array $i): bool => (int)$i['id'] !== $id));
      $data['store_keys'] = array_values(array_filter($data['store_keys'], fn(array $k): bool => (int)$k['store_item_id'] !== $id));
      // Satış geçmişi snapshot ile korunur.
    });
    flash_set('Ürün silindi.');
    header('Location: store.php'); exit;
  }
  if (isset($_POST['toggle_item'])) {
    $id=(int)($_POST['id']??0);
    dg_mutate(function (array &$data) use ($id): void {
      $i = dg_index_of_id($data['store_items'], $id);
      if ($i !== null) {
        $data['store_items'][$i]['is_active'] = 1 - (int)($data['store_items'][$i]['is_active'] ?? 0);
        $data['store_items'][$i]['updated_at'] = dg_now();
      }
    });
    header('Location: store.php'); exit;
  }
  if (isset($_POST['delete_key'])) {
    $kid=(int)($_POST['key_id']??0);
    $iid=(int)($_POST['item_id']??0);
    dg_mutate(function (array &$data) use ($kid): void {
      $data['store_keys'] = array_values(array_filter(
        $data['store_keys'],
        fn(array $k): bool => !((int)$k['id'] === $kid && (int)($k['is_used'] ?? 0) === 0)
      ));
      dg_refresh_store_stock($data);
    });
    header('Location: store.php?edit='.$iid); exit;
  }
}

$data = dg_read_data();
$items = dg_enrich_store_items($data, dg_sort_newest($data['store_items'] ?? []));
$edit=null;
$keys=[];
if (isset($_GET['edit'])) {
  $eid=(int)$_GET['edit'];
  $edit = dg_find_by_id($data['store_items'] ?? [], $eid);
  if ($edit) {
    $keys = array_values(array_filter($data['store_keys'] ?? [], fn(array $k): bool => (int)$k['store_item_id'] === $eid));
    usort($keys, fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
  }
}

admin_head('Mağaza Yönetimi');
?>

<div style="display:flex;align-items:end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <p class="dg-kicker">Mağaza</p>
    <h1 style="font-family:Bricolage Grotesque,sans-serif;font-weight:800;font-size:22px">Mağaza & Stok Yönetimi</h1>
    <p class="dg-muted" style="font-size:13px">Eski mağaza kataloğu otomatik gelir. Her ürün hem TL hem D-Coin fiyatına sahiptir; stok adet veya tek tek key ile takip edilebilir.</p>
  </div>
  <span class="dg-badge"><?=count($items)?> ÜRÜN</span>
</div>

<div style="display:grid;grid-template-columns:1fr 1.15fr;gap:16px;align-items:start">
  <!-- Form -->
  <div class="dg-card">
    <div class="dg-card-head"><h2><?= $edit ? '✏ Ürünü Düzenle' : '＋ Yeni Ürün Ekle' ?></h2><?php if($edit): ?><a href="store.php" class="dg-btn dg-btn-ghost dg-btn-sm">İptal</a><?php endif; ?></div>
    <div class="dg-card-body">
      <form method="post" class="dg-form">
        <?=csrf_field()?>
        <input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
        <label class="dg-field"><span>Ürün Adı *</span><input type="text" name="name" required maxlength="180" placeholder="Days Gone Steam (PC) - Steam CD Key - Global" value="<?=e($edit['name']??'')?>"></label>
        <label class="dg-field"><span>Açıklama</span><textarea name="description" rows="3" placeholder="Kısa açıklama — anında teslim, bölge TR vb."><?=e($edit['description']??'')?></textarea></label>
        <label class="dg-field"><span>Görsel URL</span><input type="url" name="image_url" placeholder="https://..." value="<?=e($edit['image_url']??'')?>"></label>
        <?php if ($edit && !empty($edit['legacy_slug'])): ?>
          <div style="background:rgba(79,224,160,0.08);border:1px solid rgba(79,224,160,0.22);border-radius:12px;padding:10px 12px;font-size:12px;color:var(--muted)">
            Bu ürün eski mağaza kataloğundan otomatik bağlandı. Ad, görsel ve TL fiyat katalogdan güncellenir; burada belirlediğiniz D-Coin fiyatı ve stok tipi korunur. Gerçek keyleriniz varsa stok tipini "Tek tek key" yapabilirsiniz.
          </div>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label class="dg-field"><span>TL Fiyatı (₺)</span><input type="number" step="0.01" min="0" name="price_tl" required value="<?=e($edit['price_tl']??'99.00')?>"></label>
          <label class="dg-field"><span>D-Coin Fiyatı</span><input type="number" min="0" name="price_dcoin" required value="<?= (int)($edit['price_dcoin']??50) ?>"></label>
        </div>
        <?php $edit_stock_mode = $edit ? dg_store_stock_mode($edit) : 'keys'; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label class="dg-field"><span>Stok Tipi</span>
            <select name="stock_mode" id="stockMode">
              <option value="keys" <?= $edit_stock_mode === 'keys' ? 'selected' : '' ?>>Tek tek key</option>
              <option value="quantity" <?= $edit_stock_mode === 'quantity' ? 'selected' : '' ?>>Adet stok</option>
            </select>
          </label>
          <label class="dg-field" id="stockQtyField"><span>Adet Stoğu</span><input type="number" min="0" name="stock_qty" value="<?= (int)($edit['stock'] ?? 10) ?>"></label>
        </div>
        <label class="dg-field" id="stockKeysField"><span>Stok / Key Kodları <small style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--dim)">— her satır 1 key. Virgül de olur. Düzenlerken sadece yeni keyler ekle.</small></span><textarea name="keys" rows="5" placeholder="XXXXX-XXXXX-0001&#10;XXXXX-XXXXX-0002&#10;XXXXX-XXXXX-0003"></textarea></label>
        <?php if($edit && $edit_stock_mode === 'keys' && !empty($keys)): ?>
          <div style="background:rgba(255,255,255,0.04);border:1px solid var(--line);border-radius:12px;padding:10px;max-height:180px;overflow:auto">
            <p style="font:700 10px Instrument Sans,sans-serif;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Mevcut Keyler (<?=count($keys)?>)</p>
            <?php foreach($keys as $k): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;background:<?= $k['is_used']?'rgba(224,96,122,0.08)':'rgba(79,224,160,0.08)' ?>;border:1px solid <?= $k['is_used']?'rgba(224,96,122,0.2)':'rgba(79,224,160,0.2)' ?>;border-radius:8px;padding:6px 10px;margin-bottom:6px;font:600 11px Instrument Sans,sans-serif">
                <span style="word-break:break-all"><?=e($k['key_code'])?> <?= $k['is_used']?'<small style="color:var(--magenta)">• KULLANILDI</small>':'<small style="color:#A7F3C9">• BOŞTA</small>' ?></span>
                <?php if(!(int)$k['is_used']): ?><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="item_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>"><button name="delete_key" style="background:none;border:none;color:var(--magenta);cursor:pointer;font-size:11px">✕</button></form><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label class="dg-check"><input type="checkbox" name="is_active" value="1" <?= !isset($edit['is_active'])|| (int)$edit['is_active']===1 ? 'checked':'' ?>> <span>Aktif (mağazada görünsün)</span></label>
        <button name="save_item" class="dg-btn dg-btn-primary dg-btn-block"><?= $edit ? 'Güncelle' : 'Ürünü Ekle' ?> →</button>
      </form>
    </div>
  </div>

  <!-- Liste -->
  <div class="dg-card">
    <div class="dg-card-head"><h2>Ürünler</h2></div>
    <?php if(!$items): ?><div class="dg-empty">Henüz ürün yok</div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px;padding:14px">
      <?php foreach($items as $it): ?>
        <div style="display:flex;gap:12px;align-items:center;background:var(--card2);border:1px solid <?= $it['is_active']?'rgba(79,224,160,0.18)':'var(--line)' ?>;border-radius:14px;padding:12px">
          <img src="<?=e($it['image_url'] ?: 'https://images.unsplash.com/photo-1511512578047-dfb367046420?w=200&q=80')?>" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0">
          <div style="flex:1;min-width:0">
            <b style="font-size:13px"><?=e($it['name'])?></b>
            <div style="font:600 11px Instrument Sans,sans-serif;color:var(--muted);margin-top:2px"><?=tl_format($it['price_tl'])?> • ◈ <?= (int)$it['price_dcoin'] ?> • <span style="color:<?= $it['avail']>0?'#A7F3C9':'var(--magenta)' ?>"><?= (int)$it['avail'] ?> stok</span> / <?= (int)$it['used'] ?> satıldı</div>
            <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap"><span class="dg-chip <?= $it['is_active']?'on':'' ?>"><?= $it['is_active']?'Aktif':'Pasif' ?></span><span class="dg-chip">ID #<?= (int)$it['id'] ?></span><span class="dg-chip"><?= dg_store_stock_mode($it) === 'quantity' ? 'Adet stok' : 'Key stoğu' ?></span><?php if(!empty($it['legacy_slug'])): ?><span class="dg-chip on">Eski katalog</span><?php endif; ?></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:6px">
            <a href="store.php?edit=<?= (int)$it['id'] ?>" class="dg-btn dg-btn-ghost dg-btn-sm" style="font-size:11px">Düzenle</a>
            <form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button name="toggle_item" class="dg-btn <?= $it['is_active']?'dg-btn-ghost':'dg-btn-primary' ?> dg-btn-sm" style="font-size:11px;width:100%"><?= $it['is_active']?'Pasife Al':'Aktifleştir' ?></button></form>
            <form method="post" style="margin:0" onsubmit="return confirm('Ürün ve tüm keyleri silinsin mi?')"><?=csrf_field()?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button name="delete_item" class="dg-btn dg-btn-ghost dg-btn-sm" style="font-size:11px;width:100%;color:var(--magenta);border-color:rgba(224,96,122,0.25)">Sil</button></form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var mode = document.getElementById('stockMode');
  var qty = document.getElementById('stockQtyField');
  var keys = document.getElementById('stockKeysField');
  if (!mode || !qty || !keys) return;
  function sync() {
    var isQuantity = mode.value === 'quantity';
    qty.style.display = isQuantity ? '' : 'none';
    keys.style.display = isQuantity ? 'none' : '';
  }
  mode.addEventListener('change', sync);
  sync();
})();
</script>

<?php admin_foot(); ?>
