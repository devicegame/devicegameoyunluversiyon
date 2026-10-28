# Overcharge: Kaçış — Keşif Raporu ve Entegrasyon Planı

> Bu belge **uygulamadan önce** hazırlanan keşif + plan + risk listesidir;
> uygulama sonrası durum için §11’e bakın. Kurulum, yönetim, API, test
> sonuçları ve yayın kontrol listesi `MINI-OYUN.md` içindedir.
>
> Durum: **prototip** — gerçek D-Coin tutarları, fiyatlar ve ödüller örnektir
> ve işletmeci onayı olmadan yayına alınmamalıdır.

## 1. Mevcut sistem (keşif bulguları)

| Alan | Bulgu | Mini oyun nasıl kullanır |
|---|---|---|
| Veri | `db.php`: `storage/dg/database.json`, `flock` + tmp + `rename` ile atomik, `dg_mutate()` tek yazma kapısı | Tüm D-Coin işlemleri `dg_mutate()` içinde; ayrı kilit açılmaz (kilit sırası: mini oyun → çekirdek) |
| Kullanıcı | `users[]` → `id, username, email, dcoin_balance, is_admin, is_banned` | Ayrı hesap yok; `current_user()` / `require_login()` |
| Bakiye | `dg_adjust_user_balance()` (negatife düşmeyi engeller) | Ekonomi katmanı bakiyeyi doğrudan `dg_mutate` içinde, ledger kaydıyla birlikte günceller (tek atomik işlem) |
| Oturum | `session_start`, `SameSite=Lax`, `HttpOnly`, `DG_AUTH_VERSION` | Mini oyun aynı oturumu kullanır; API'ler `is_logged_in()` + `csrf_verify()` |
| CSRF | `csrf_token()` / `csrf_verify()`; yeni API'lerde JSON gövdede de kabul edilir | Tüm POST'lar CSRF zorunlu; `X-CSRF-Token` başlığı da desteklenir |
| Admin | `admin/` klasörü, `require_admin()`, `admin/_layout.php` sekmeleri | Ayrı panel yok; `admin/minigame.php` mevcut kabuğa eklenir |
| Tasarım | `style.css` + `assets/css/colorful-theme.css`, `--bg/--ink/--lime/--lilac` tokenları | Mini oyun CSS'i `.mg-*` ön ekiyle izole edilir |
| Yönlendirme | `header.php` `nav_link()`, `footer.php`; `auth.php` `$allowedRootPages` | `mini-oyun.php` üç yere eklenir |
| Zaman | `db.php` `date_default_timezone_set('Europe/Istanbul')`; mevcut kayıtlar yerel saat | Mini oyun zaman damgaları **UTC epoch + ISO**; dönemler İstanbul saatine göre hesaplanır |
| Hosting | Paylaşımlı PHP + JSON dosya depolama; MySQL/Redis/cron zorunlu değil | Motor saf PHP (eklenti yok), kuyruk yok; prunning cron'u **opsiyonel** (yoksa da çalışır) |
| Test | CLI PHP yok; `tools/php-wasm` tabanlı sunucu var | Testler php-wasm üzerinde CLI + HTTP olarak koşulur |

## 2. Kapsam dışı (bilinçli)

- Ana sayfa, mağaza, DG Social, stüdyo sayfalarının yeniden tasarımı **yok**.
- İkinci hesap/ikinci bakiye/ayrı giriş **yok**.
- Loot box, şans kutusu, ücretli yarışma avantajı, rastgele para ödülü **yok**.
- Çok oyunlu portal/launcher **yok** — tek oyun.

## 3. Depolama tasarımı

### 3.1 Dosya tabanlı (oyun verisi) — `storage/minigame/`

```
settings.json        ayarlar (bayraklar, limitler, görevler, ekonomi notu)
catalog.json         kozmetik katalog (admin düzenler, seed: data/minigame/catalog.php)
seasons.json         ödüllü haftalık yarışma kayıtları
runs/<gün>/<id>.json sonuçlanmış koşu (girdi + sonuç + risk kanıtı)
runs/active.json     aktif koşular (token HASH, seed, süre)
bests/<ver>-<dönem>.json dönem başına kullanıcı-başına en iyi
audit.jsonl          admin işlem günlüğü (ekleme-only)
ratelimit/<hash>.json istek sayaçları
minigame.lock        tüm oyun-yazma işlemleri için tek kilit
ip-salt              IP hash tuzu (git'e girmez)
```

`runs/` altındaki dosyalar `retention_days` (varsayılan 30) sonunda cron ile
silinir; silme **sadece** mini oyun koşuları içindir, canlı kullanıcı verisine
dokunmaz.

### 3.2 Çekirdek veri (D-Coin) — `storage/dg/database.json`

`dg_normalize_data()` listesine eklenen dört koleksiyon:

| Koleksiyon | Amaç | Tekillik |
|---|---|---|
| `minigame_ledger` | her D-Coin hareketi (+ödül / −satın alma), `balance_after`, `status` | `idempotency_key` benzersiz |
| `minigame_entitlements` | kozmetik sahipliği | `(user_id, item_id)` benzersiz |
| `minigame_loadouts` | kuşanılmış kozmetikler | kullanıcı başına 1 satır |
| `minigame_task_claims` | günlük görev ödemeleri | `(user_id, period, task_id)` benzersiz |

Ek koleksiyon yok; mevcut `users`, `purchases`, `watch_logs` şeması değişmez.
Geri alma: koleksiyonlar boş kalır, kod kaldırılır; canlı veri silinmez.

## 4. Ekonomi kuralları

- Para birimi **tam sayı D-Coin**; float yok, yuvarlama yok.
- Ödül yalnızca **doğrulanmış** koşudan sonra, günlük görev bazında, **günde bir kez**.
- Kişi başı günlük kazanç tavanı (varsayılan 100) ve global günlük bütçe
  (varsayılan 5000; 0 = sınırsız) aynı `dg_mutate` içinde kontrol edilir.
- Tavan/bütçe dolduğunda oyun devam eder; **yalnızca** kazanç durur ve nedeni
  arayüzde görünür.
- Her ödeme ledger'a `status=paid`, `idempotency_key=mg:task:<gün>:<görev>:<uid>`
  ile yazılır. Aynı anahtar ikinci kez ödeme yapmaz (retry/eşzamanlılık güvenli).
- Satın alma: `mg:buy:<uid>:<item>` anahtarı; bakiye + sahiplik + ledger tek
  mutasyon. Bakiye negatife düşemez (`dg_adjust_user_balance` kuralı ile aynı).
- Şampiyon kozmetiği yalnızca sezon kazananına, sahiplik tablosundaki benzersiz
  `(user_id, item_id)` satırıyla **bir kez** verilir; satın alınamaz.
- Yönetici bakiye düzeltmesi **silme değil**, gerekçeli dengeleyici ledger
  kaydıdır (benzersiz `mg:admin:<admin_id>:<rastgele>` anahtarı).

## 5. Determinizm sözleşmesi (motor v1)

- Sabit adım: **60 tick/sn**. Fizik tamamen **tam sayı** (1 birim = 1/8 px).
- PRNG: **xorshift32**. Seed sunucudan gelir, istemci değiştiremez.
- Girdi: `[[tick, action], ...]`; `action ∈ {1: zıpla, 2: bırak}`; tick'ler
  kesin artan. Girdi tick'i, o tick'in fiziğinden **önce** uygulanır.
- Skor: `floor(mesafe / 160) + hücre * 15` (tam sayı). Mesafe, hızın tick
  başına toplamıdır; hız `34 + 3 * floor(tick / 600)`, üst sınır 76.
- Dünya üretimi **yalnızca** tick/mesafeye bağlıdır, oyuncu girdisine bağlı
  değildir → ölümden sonra üretim farkı oluşmaz.
- Maksimum tur: 7200 tick (120 sn). Normal tur 1–2 dk.
- Aynı seed + aynı girdi → **aynı sonuç** (JS ve PHP). Kanıt:
  `tests/fixtures/minigame-vectors.json` ve iki taraflı test.

> **Sınır (dokümante):** deterministik yeniden oynatım, istemcinin skorunu
> uydurmasını engeller; **botun geçerli girdi üretmesini engellemez**.
> Bu yüzden risk sezgileri + inceleme kuyruğu vardır.

### 5.1 Dönem / seed politikası

- Günlük tablo: o günün **tüm oyuncuları için aynı** seed
  (`sha256(version|gün|salt)` → uint32). Aynı gün adil karşılaştırma.
- Sezon (ödüllü haftalık yarışma): sezon açılışında **sabitlenen** seed.
  Sezon aktifken sıralı turlar bu seed'i kullanır → hafta boyunca herkes aynı
  bölümü oynar.
- Tüm zamanlar tablosu sürüm bazlıdır; farklı günlerin farklı düzeni
  karşılaştırıldığı için arayüzde bu açıkça yazar. Ödül bağlı değildir.
- Sıralama anahtarı: `<sürüm>:d-YYYY-MM-DD`, `<sürüm>:w-YYYY-\WW`,
  `<sürüm>:all`, `<sürüm>:s-<id>`. Sürüm değişirse tablolar karışmaz.

## 6. Anti-cheat katmanları

1. Sunucu **koşu kimliği + tek kullanımlık token (hash) + seed + sürüm +
   başlangıç/son geçerlilik** üretir; token yalnızca bir kez döner.
2. İstemci skor/duration/görev/para göndermez → sunucu aynı seed ile
   **yeniden oynatır**; skor sunucudan hesaplanır.
3. Süre tutarlılığı: `ticks ≤ (sunucu_now − started_at) * 60 + tolerans` ve
   `ticks ≥ 0`. Erken biten / saat hilesi yapan koşu reddedilir.
4. Girdi doğrulama: boyut, tick aralığı, sıkı artan sıra, geçerli aksiyon,
   `tick < ticks`, tek seferlik finalize.
5. Risk sezgileri → `pending` (ödül yok, tabloya girmez) → admin onay/ret.
   Eşikler istemciye **açıklanmaz**; hata mesajları genel.
6. Rate limit + payload limit + günlük sıralı tur limiti.
7. IP **tek başına** diskalifiye nedeni değil; yalnızca hash olarak, 30 gün
   saklanır ve inceleme kanıtıdır.

## 7. Arayüz

- Tek sayfa `mini-oyun.php`, dört iç sekme: **Oyna / Sıralamalar / Mağaza / Gardırop**.
- Sahne: canvas 16:9, üstte HUD (skor, süre, hücre), ortada overlay
  (başlat / 3-2-1 / sonuç), ses ve tam ekran düğmeleri.
- Mobil: dokun ve bırak, `touch-action: none` (sayfa kaymaz), dikeyde tam
  genişlik sahne, alt panelde tek elle erişilebilir düğme.
- Erişilebilirlik: `aria-live` duyurular, klavye ile zıplama, odak halkaları,
  `prefers-reduced-motion` → parçacık/ekran sarsıntısı kapalı, sayı sayacı
  sadeleşir.
- Durumlar: yükleniyor, boş, bakım, günlük limit, yetersiz bakiye, ağ hatası,
  incelemede (pending), sezon yok, mağaza kapalı.
- Sekme arka plana geçerse **sıralı tur iptal** edilir (avantaj yok), antrenman
  duraklar.

## 8. Admin

`admin/minigame.php` — mevcut panel kabuğu içinde sekmeler:
genel bakış, ayarlar (oyun/ödül/mağaza/yarışma bayrakları ayrı), görevler,
katalog CRUD, inceleme kuyruğu (onay/ret + gerekçe), sezonlar (durum akışı,
kazanan onayı, teslim takibi), ekonomi raporu, denetim günlüğü.
Yıkıcı işlem yok; silme yerine `published=0` (delist). Sahipler delist
edilen eşyayı kullanmaya devam eder.

## 9. Riskler ve önlemler

| Risk | Etki | Önlem |
|---|---|---|
| Bot geçerli girdi üretir | Haksız skor | Risk sezgileri + inceleme + günlük tur limiti; sınır dokümante |
| JSON dosya büyümesi (koşu kayıtları) | Disk/IO | 30 gün saklama + cron pruning + `runs/<gün>` bölümleme |
| Eşzamanlı submit | Çift ödül | Tek kilit + `status` guard + ledger `idempotency_key` |
| Global bütçe yarışı | Bütçe aşımı | Bütçe kontrolü aynı `dg_mutate` içinde |
| PHP sürüm farkı (64-bit varsayımı) | Yanlış skor | Tüm aritmetik 32-bit maskeli; parity testi |
| Kurulumda `storage/` yazılamaz | Oyun çalışmaz | Anlaşılır hata + kurulum kontrol listesi |
| Yasal (yarışma/kişisel veri) | Uyum riski | Ödüllü sezon varsayılan **kapalı**; yayın öncesi işletmeci onayı zorunlu |
| Gerçek para/ödül yanlış yayına çıkması | Mali risk | Tüm tutarlar `data/minigame/*` içinde "DEMO" işaretli; `economy.approved=0` iken mağaza fiyatları etiketli |
| Mobil tarayıcıda kare hızı farkı | Farklı fizik | Sabit 1/60 akümülatör; 30/60/120 Hz testi |
| Mağaza ile karışma | Kullanıcı kafa karışıklığı | Ayrı sayfa, ayrı görsel dil, "oyun içi kozmetik" etiketi |

## 10. Uygulama sırası

1. Depolama + ayarlar + katalog seed
2. Motor (JS + PHP) + parity fixture'ları
3. Cüzdan/ledger, sıralama, koşu yaşam döngüsü, sezon
4. API uç noktaları + arayüz (sayfa, CSS, canvas, uygulama denetleyicisi)
5. Admin + cron + dokümantasyon
6. Testler: motor parity, entegrasyon (ödül idempotency, çift ödeme yok,
   sahipsiz kuşanma, sınırlar), HTTP akışı, mobil/sekme davranışı (elle
   doğrulanamayanlar açıkça raporlanır)

## 11. Uygulama durumu (teslim sonrası)

| Adım | Durum | Kanıt |
|---|---|---|
| Depolama, ayarlar, katalog seed | ✅ | `storage/minigame/` + `data/minigame/*` |
| Motor (JS + PHP) | ✅ | `engine_test.php` → 46/46; `game_test.mjs` → 14/14 |
| Cüzdan/ledger, sıralama, koşu, sezon | ✅ | `integration_test.php` → 133/133 |
| API + arayüz | ✅ | `minigame-http-test.mjs` → 78/78; `app_test.mjs` → 30/30 |
| Admin + cron + dokümantasyon | ✅ | `admin/minigame.php`, `cron/minigame.php`, `MINI-OYUN.md` |
| Gerçek tarayıcı + mobil | ⚠️ doğrulanmadı | Sandbox’ta tarayıcı yok; elle test gerekli |
| Gerçek hosting yükü | ⚠️ doğrulanmadı | `flock` davranışı canlıda ölçülmeli |

**Bilinçli sapmalar**

- Kozmetik görselleri **prosedürel** (canvas). Bu nedenle kozmetik dosyası
  yükleme ucu yoktur; yükleme saldırı yüzeyi hiç açılmamıştır. Görsel yükleme
  gerekiyorsa ayrı bir güvenli yükleme işi olarak planlanmalıdır.
- Antrenman turları **istemci tarafında** çalışır (sunucuya gitmez), böylece
  sıralı olmayan turlar için sunucu yükü ve kötüye kullanım yüzeyi oluşmaz.
- Sezon ödülü **elle** teslim edilir; sistem ödül kodunu/anahtarını saklamaz.
