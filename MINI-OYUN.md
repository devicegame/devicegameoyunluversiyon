# Overcharge: Kaçış — Mini Oyun (kurulum, yönetim, teslim notları)

Device Game sitesinin **içinde** çalışan tek oyunluk bir bölüm. Ayrı hesap,
ayrı bakiye, ayrı panel veya ayrı site **değildir**: mevcut oturum, D-Coin
cüzdanı, header/footer ve yönetim paneli kullanılır.

> **Durum: PROTOTİP.** D-Coin tutarları, kozmetik fiyatları ve ödüller
> **örnektir**. Varsayılan olarak ödüller (`rewards`), kozmetik satışı (`shop`)
> ve ödüllü yarışma (`season`) **kapalıdır**. İşletmeci onayı olmadan bunları
> açmayın (bkz. §11 “Yayın öncesi onay listesi”).

---

## 1. Giriş noktaları

| Ne | Yol |
|---|---|
| Oyuncu sayfası | `/mini-oyun.php` |
| JSON API | `/api/minigame/*.php` |
| Yönetim paneli | `/admin/minigame.php` (mevcut panel sekmesi: 🎮 Mini Oyun) |
| Bakım görevi (opsiyonel) | `php cron/minigame.php` |
| Oyun motoru (istemci) | `assets/js/minigame/engine.js` |
| Oyun motoru (sunucu) | `lib/minigame/engine.php` — **birebir aynı** kurallar |

Sayfa dört sekmeye ayrılır: **Oyna / Sıralamalar / Mağaza / Gardırop**.
Oyun varlıkları (CSS + JS) yalnızca bu sayfada yüklenir; diğer sayfaların
stilleri ve performansı etkilenmez (tüm seçiciler `.mg-page` altında).

---

## 2. Nasıl çalışır (özet)

1. **Tur başlatma** (`start.php`): sunucu koşu kimliği, tek kullanımlık token,
   seed, motor sürümü, yapılandırma özeti ve son kullanma zamanı üretir.
   Token yalnızca yanıtta döner; depoda yalnızca SHA-256 özeti tutulur.
2. **Oyun** tarayıcıda sabit 1/60 sn adımla oynanır. Fizik **tam sayı
   aritmetiği** kullanır (1 birim = 1/8 px), bu yüzden 30/60/120 Hz ekranlarda
   ve farklı tarayıcılarda aynı sonucu verir.
3. **Gönderim** (`submit.php`): istemci yalnızca sınırlı, sıralı girdi listesini
   (`[tick, aksiyon]`) yollar. Sunucu aynı seed + aynı sürümle turu **yeniden
   oynatır**, skoru kendisi hesaplar ve istemcinin bildirdiği skorla karşılaştırır.
4. **Sonuçlandırma** kilit altında **tek sefer** yapılır; tekrar gönderim
   (ağ hatası) aynı sonucu döndürür, ikinci kez ödül vermez.
5. **Ödüller** doğrulanmış turdan sonra günlük görev bazında, günde bir kez,
   tavan/bütçe kontrolüyle `dg_mutate()` içinde ödenir.

### Adalet kararları

- **Aynı gün herkes aynı düzeni oynar**: seed, İstanbul günü + motor sürümünden
  deterministik türetilir.
- **Ödüllü sezon** başladığında bölüm tohumu sezona **sabitlenir**; hafta boyunca
  herkes aynı düzeni oynar.
- Sekme arka plana geçerse **sıralı tur iptal edilir** (avantaj oluşmasın diye);
  antrenman turu duraklar.
- Sıralamalar motor sürümüne göre ayrılır (`v1:d-…`, `v1:w-…`, `v1:all`).
  Motor değişince eski skorlar yeni tabloya karışmaz.

---

## 3. Kurulum

Ek bağımlılık, derleme adımı, veritabanı sunucusu veya kuyruk **yoktur**.
Mevcut paylaşımlı PHP hosting yeterlidir (gereken: PHP 8.0+, `json`, `flock`,
`random_bytes` — hepsi standart).

1. Dosyaları site köküne kopyalayın (`mini-oyun.php`, `assets/`, `api/minigame/`,
   `lib/minigame/`, `admin/minigame.php`, `cron/`, `data/minigame/`).
2. Yazma izinleri:
   ```
   storage/dg/          (mevcut; database.json burada)
   storage/minigame/    (ilk istekte otomatik oluşur; 775 yeterli)
   ```
   `storage/minigame/` ilk kullanımda kendini oluşturur ve içine
   `.htaccess` + `index.html` koruması yazar. Yine de web kökünün dışında
   değilse Apache’de `storage/` erişiminin kapalı olduğundan emin olun.
3. Sayfayı bir kez açın: `storage/minigame/settings.json` ve `catalog.json`
   seed dosyalarından üretilir.
4. (Opsiyonel) cron — **zorunlu değildir**, yoksa da oyun çalışır:
   ```
   15 3 * * * /usr/bin/php /path/to/site/cron/minigame.php >> /path/to/site/storage/minigame/cron.log 2>&1
   ```
5. Admin panelinde bayrakları gözden geçirin: **Yönetim → 🎮 Mini Oyun → Ayarlar**.

### Veri şeması değişikliği (migration)

`storage/dg/database.json` içine **yalnızca ekleme** yapılır. Dört yeni koleksiyon
`dg_normalize_data()` tarafından ilk yazmada otomatik oluşturulur:

| Koleksiyon | Amaç | Tekillik |
|---|---|---|
| `minigame_ledger` | her D-Coin hareketi (+ödül / −satın alma) | `idempotency_key` |
| `minigame_entitlements` | kozmetik sahipliği | `(user_id, item_id)` |
| `minigame_loadouts` | kuşanılan görünüm | kullanıcı başına 1 satır |
| `minigame_task_claims` | günlük görev ödemeleri | `(user_id, period, task_id)` |

Mevcut `users`, `purchases`, `watch_logs` vb. şeması **değişmez**, eski kayıtlar
silinmez. Geri alma: `mini-oyun.php` kaldırılır + yeni koleksiyonlar boş kalır
(ledger’ı silmeyin — muhasebe kaydıdır).

### Yedekleme / geri alma

```bash
# yedek (yayına almadan ve şema değişikliğinden önce)
tar czf backup-$(date +%F).tgz storage/dg/database.json storage/minigame

# geri alma
# 1) bayrakları kapat: game=0, rewards=0, shop=0, season=0
# 2) dosyaları geri yükle (mini-oyun.php + api/minigame + lib/minigame kaldırılabilir)
# 3) storage/minigame/ silinebilir (yalnızca oyun verisi); storage/dg/database.json KORUNUR
```

Kod geri alınsa bile kullanıcı bakiyeleri ve ledger doğru kalır; mini oyun
koleksiyonları sadece kullanılmaz.

---

## 4. Yönetim paneli

`/admin/minigame.php` — mevcut panel kabuğu ve `is_admin` kontrolü içinde.

| Bölüm | Ne yapar |
|---|---|
| **Genel bakış** | Bayrak durumu, bugünkü dağıtım, bütçe, aktif sezon, ledger satır sayısı |
| **Ayarlar** | Bayraklar, limitler, ekonomi onayı/notu, bakım notu |
| **Görevler** | Günlük görevler: başlık, açıklama, metrik, hedef, ödül, sıra, etkin |
| **Kozmetikler** | Ad, fiyat, açıklama, renkler, satın alınabilir/yayında, yeni kozmetik |
| **İnceleme kuyruğu** | Şüpheli turlar: onayla/reddet (gerekçeli), skor düzeltme |
| **Sezonlar** | Taslak oluştur, düzenle, durum geçişleri, kesinleştir, teslim işaretle |
| **Ekonomi & denetim** | Dağıtılan/harcanan D-Coin, aktif oyuncu, bakiye düzeltmesi, denetim günlüğü |

### Bayraklar (birbirinden bağımsız)

| Bayrak | Kapatınca |
|---|---|
| `game` | Sıralı turlar başlatılamaz (antrenman etkilenmez) |
| `practice` | Antrenman (kayıtsız) tur kapalı |
| `rewards` | D-Coin ödemesi yok — **oyun oynanmaya devam eder**, görevler görünür |
| `shop` | Kozmetik satışı kapalı; sahiplik ve kuşanım korunur |
| `leaderboard` | Tablolar ve sıralama API’si kapalı (oyun etkilenmez) |
| `season` | Ödüllü yarışma başlatılamaz; aktif sezon yoksa vitrin boş |

### Limitler (varsayılan)

| Ayar | Varsayılan | Not |
|---|---|---|
| `max_ranked_runs_per_day` | 20 | 0 = sınırsız |
| `daily_user_reward_cap` | 100 | Kişi başı günlük D-Coin tavanı |
| `daily_global_budget` | 5000 | Global günlük bütçe; 0 = sınırsız |
| `run_ttl_seconds` | 180 | Koşu token ömrü |
| `min_valid_ticks` | 600 | 10 sn altı turlar görev/tabloya sayılmaz |
| `max_inputs` | 600 | İstek başına girdi sınırı |
| `retention_days` | 30 | Koşu dosyası saklama |
| `timing_tolerance_ticks` | 6 | Sunucu saati toleransı |

### Yönetici akışları

- **Şüpheli tur onayı**: İnceleme kuyruğu → *Onayla* → tur doğrulanır, tabloya
  işlenir, ödül idempotent ödenir. *Reddet* → tabloya girmez, ödül yok.
- **Skor düzeltme**: gerekçe zorunlu; etkilenen tablolar kullanıcı için yeniden
  hesaplanır, denetim kaydı yazılır.
- **Bakiye düzeltmesi**: **dengeleyici ledger girişi** ekler (eski kayıt silinmez,
  bakiye negatife düşemez).
- **Sezon**: `Taslak → Aktif → İnceleme → Kesinleşti → Teslim edildi`
  (her aşamada iptal edilebilir; kesinleşmiş sezon değiştirilemez).
  Kazanan **yönetici onayıyla** belirlenir, şampiyon kozmetiği **bir kez** verilir.

---

## 5. Ekonomi (prototip değerleri)

Varsayılan görev seti (günde bir kez, toplam **90 D-Coin**; kişi tavanı 100):

| Görev | Koşul | Ödül |
|---|---|---|
| `gunluk-tur` | Geçerli bir tur (≥10 sn) tamamla | 15 |
| `skor-800` | Tek turda 800 puan | 25 |
| `hucre-15` | Tek turda 15 enerji hücresi | 20 |
| `uc-tur` | Bugün 3 geçerli tur | 30 |

Kozmetikler (12): `char-default` ücretsiz; 3 ücretsiz renk; standart
(`char-ember` 400, `color-sunset` 250, `trail-pulse` 300, `frame-neon` 350);
özel (`char-void` 900, `trail-comet` 750, `frame-circuit` 500); `frame-champion`
**satın alınamaz** (yalnızca sezon birincisi).

> Fiyatlar/ödüller **örnektir**. `economy.approved = 1` yapılana kadar arayüz
> “Prototip: tutarlar örnektir” etiketini gösterir.

**Kurallar:** tam sayı D-Coin; per-minute/otomatik kazanç yok; loot box yok;
kozmetikler oynanışı etkilemez; her hareket ledger’a yazılır; bakiye + ödül +
sahiplik tek atomik mutasyonda; idempotency anahtarları tekrar ödemeyi engeller.

---

## 6. Sıralamalar

- **Bugün / Bu hafta / Tüm zamanlar / Sezon** tabloları.
- Kullanıcı başına **en iyi doğrulanmış** skor; beraberlikte **önce ulaşan**,
  sonra koşu kimliği (deterministik).
- Beklemede/reddedilen turlar **girmez**.
- Dönemler İstanbul saatine göre **yarı açık** aralıktır: günlük 00:00–24:00,
  haftalık pazartesi 00:00’dan sonraki pazartesi 00:00’a kadar. Depolama UTC.
- Kendi sıran + ilk 10’a fark gösterilir. E-posta/IP asla dönmez.
- Tablolar motor sürümüne göre ayrı dosyalarda tutulur (dosya tabanlı, ek
  indeks gerekmez).

**Adalet notu:** Ödüllü sezon sırasında tohum sabittir. Sezon dışında her gün
farklı bir düzen oynanır; “tüm zamanlar” tablosu farklı günlerin düzenlerini
birleştirir (ödül bağlı değildir, arayüzde belirtilir).

---

## 7. API sözleşmeleri

Tümü JSON döner: `{"ok":true,…}` veya `{"ok":false,"code":"…","error":"…"}`.
POST’larda `csrf` alanı (veya `X-CSRF-Token` başlığı) zorunludur.
Hız sınırı: start 30/10dk, submit 60/10dk, buy 20/10dk, equip 60/10dk,
state/leaderboard/catalog/season 120–180/5dk. Gövde sınırı **256 KB**.

| Uç nokta | Metot | Girdi | Çıktı (özet) |
|---|---|---|---|
| `state.php` | GET | — | `flags, season, champions, wallet{tasks…}, attempts, last_run, csrf` |
| `start.php` | POST | `{csrf}` | `{run_id, token, seed, version, expires_ts, attempts}` |
| `submit.php` | POST | `{csrf, run_id, token, inputs[], score?}` | `{status, pending, result, rewards{paid, claims[], balance}}` |
| `run.php` | GET | `?id=run_…` | `{status: active\|verified\|pending\|rejected, result, rewards}` |
| `claim.php` | POST | `{csrf, run_id}` | ödenmemiş ödülü idempotent yeniden dener |
| `leaderboard.php` | GET | `?board=today\|week\|all\|season&page=` | `{rows[…], page, pages, me{rank, gap_to_top10}}` |
| `catalog.php` | GET | — | `{items[], owned[], loadout, visuals, balance}` |
| `buy.php` | POST | `{csrf, item_id}` | `{already_owned, charged, balance}` |
| `equip.php` | POST | `{csrf, slot, item_id}` (``→çıkar) | `{loadout, visuals}` |
| `season.php` | GET | — | `{active, past[], champions[], enabled}` |

**Hata kodları:** `auth, banned, csrf, rate_limited, method_not_allowed,
bad_json, payload_too_large, bad_request, bad_run, bad_token, forbidden,
expired, not_active, version_mismatch, impossible_timing, score_mismatch,
invalid_input, already_finalized, rejected, not_purchasable, insufficient_funds,
not_owned, bad_category, shop_disabled, not_found, leaderboard_disabled,
server_error`.

**Ağ hatası kuralı:** istemci aynı `run_id` + `token` + girdi listesiyle yeniden
gönderir (idempotent) veya `run.php?id=…` ile durumu sorar; **körlemesine yeni
tur başlatmaz**.

---

## 8. Güvenlik ve anti-cheat

- İstemcinin gönderdiği skor/süre/fiyat/coin **asla otorite değildir**.
- Token tek kullanımlıdır, ait olduğu kullanıcıya ve koşuya bağlıdır, SHA-256
  özeti saklanır, süresi dolunca reddedilir.
- Girdiler: sınırlı sayı, tam sayı tick, sıkı artan sıra, geçerli aksiyon,
  ölümden sonrası yasak, sunucu süresiyle uyum, imkânsız hız reddi.
- Koşu **bir kez** sonuçlanır; eşzamanlı gönderimler kilit altında sıralanır.
- **Risk incelemesi:** aşırı düzenli girdi, mükemmel hücre toplama, yüksek
  girdi oranı vb. → tur `pending` durumuna alınır (ödül yok, tabloya girmez) ve
  yönetici kuyruğuna düşer. Eşikler istemciye **açıklanmaz**.
- IP yalnızca tuzlu hash olarak, hız sınırı/istismar için tutulur; **tek başına
  diskalifiye nedeni değildir**. Cihaz parmak izi toplanmaz.
- Üretimde yığın izi, dosya yolu, token veya anahtar istemciye dönmez
  (`mg_api_handle` genel mesaj döner, ayrıntı sunucu günlüğüne gider).
- XSS: tüm kullanıcı verisi `textContent`/`e()` ile yazılır; gömülü JSON
  `JSON_HEX_TAG` ile kaçırılır. CSRF tüm POST’larda zorunlu; nesne düzeyinde
  yetki (`run.php` yalnızca kendi koşunu döndürür).
- Saklama: koşu dosyaları 30 gün, hız sınırı dosyaları 1 gün, denetim günlüğü
  son 5000 satır. Ledger ve sahiplik **silinmez**.

### Bilinen sınır (dokümanda açıkça kabul edilir)

> Deterministik yeniden oynatma, **botun kurallara uygun girdi üretmesini
> engellemez**. Otomatik oynatıcı, gerçek zamanlı oynayan biri gibi geçerli
> girdi üretebilir (bu depoda `tools/minigame-bot.mjs` bunun kanıtıdır).
> Savunma katmanı: hız/limitler, risk sezgileri, inceleme kuyruğu, ödül
> tavanı/bütçesi ve manuel onay. Bu, hile yapıldığını **kanıtlayan** bir sistem
> değildir; ekonomik hasarı sınırlayan bir sistemdir.

---

## 9. Testler ve gerçekten koşulan sonuçlar

PHP sandbox’ta kurulu değildir; testler **php-wasm** üzerinde çalıştırıldı
(`tools/php-cli-run.mjs`). Aşağıdaki sonuçlar bu depoda **fiilen koşulmuştur**.

| Test | Komut | Sonuç |
|---|---|---|
| Motor eşliği (JS ↔ PHP) | `cd tools && node php-cli-run.mjs ../tests/php/engine_test.php` | **46 kontrol, 0 hata** |
| Entegrasyon (ekonomi, mağaza, sezon, admin) | `cd tools && node php-cli-run.mjs ../tests/php/integration_test.php` | **133 kontrol, 0 hata** |
| Oyun sahnesi (kare hızı, girdi, sonuç) | `node tests/js/game_test.mjs` | **14 kontrol, 0 hata** |
| Arayüz (jsdom + gerçek sayfa HTML’i) | `node tests/js/app_test.mjs` | **30 kontrol, 0 hata** |
| HTTP uçtan uca (giriş, API, admin) | `cd tools && node minigame-http-test.mjs` | **78 kontrol, 0 hata** |
| PHP sözdizimi | `cd tools && node php-lint.mjs` | **89 dosya temiz** |

Kapsanan başlıklar: aynı seed+girdi → aynı skor; sahte skor/yanlış token/yabancı
koşu/tekrar gönderim/süresi dolmuş/bozuk girdi reddi; tavan ve bütçe aşımı;
çift ücretlendirme yok; sahip olunmayan kozmetik kuşanılamaz; kozmetik fiziği
değiştirmez; İstanbul gün/hafta sınırları; beraberlik sırası; beklemedekilerin
tabloya girmemesi; sezon kesinleştirmenin idempotentliği; yönetici olmayanın
panele girmemesi; mağaza/oyun/bakiye regresyonu.

### Doğrulanamayanlar (dürüst liste)

- **Gerçek tarayıcı ve gerçek mobil cihaz testi yapılmadı.** Sandbox’ta tarayıcı
  yok; arayüz jsdom + canvas stub ile, sayfa php-wasm HTTP sunucusuyla test
  edildi. Dokunmatik jestler, iOS Safari adres çubuğu/dvh, gerçek ses, tam ekran
  ve 120 Hz panel davranışı **elle test edilmelidir**.
- **Gerçek hostingde eşzamanlılık yükü** (çok sayıda eşzamanlı submit, NFS/agents
  kilit davranışı) ölçülmedi. Kilitler `flock`’a dayanır; paylaşımlı hostingde
  dosya kilidi desteklenmelidir.
- **E-posta/SMS/ödül teslimi** yoktur; sezon ödülü elle teslim edilir ve panelden
  “teslim edildi” olarak işaretlenir.
- **Hukuki uygunluk** doğrulanmadı (bkz. §11).

---

## 10. Bilinen sınırlar / tasarım kararları

- **Tek oyun**: portal, launcher, çok oyunlu yapı yok.
- **Kozmetik görselleri prosedürel** (canvas’ta çizilir). Bu yüzden kozmetik
  dosyası yükleme ucu **yoktur** — yükleme yüzeyi hiç açılmaz. Görsel eklemek
  isteyen işletmeci için öneri: önce ayrı bir “güvenli görsel yükleme” işi
  planlayın (MIME + boyut + yeniden adlandırma + dış klasör).
- **Antrenman turları istemci tarafında** oynanır (sunucuya gitmez): kayıt,
  ödül ve tablo yoktur; bu yüzden sunucu yükü de yoktur.
- Sekme arka plana geçen **sıralı tur iptal edilir**; bu davranış bilinçlidir
  (arka planda oynayarak avantaj elde etmeyi engeller).
- Sıralama dosyaları dönem başına tek JSON’dur; çok yüksek eşzamanlı yazımda
  kilit nedeniyle yavaşlayabilir. Ölçek gerekirse SQL’e taşınmalıdır (motor ve
  API sözleşmesi değişmez).
- Günlük görevler “tamamlandı ama tavan doldu” durumunda **sonradan ödenmez**;
  kullanıcıya nedeni gösterilir.
- iOS’ta `AudioContext` yalnızca kullanıcı etkileşiminden sonra başlar; ses
  düğmesi bu yüzden varsayılan olarak ilk dokunuşta devreye girer.

---

## 11. Yayın öncesi onay listesi (işletmeci)

- [ ] D-Coin ödül tutarları, kişi tavanı ve global bütçe onaylandı mı?
      (`economy.approved = 1` yapılmadan “örnek” etiketi kalkmaz)
- [ ] Kozmetik fiyatları onaylandı mı? (`shop` bayrağı)
- [ ] Ödüllü yarışma için **katılım koşulları, ödül, teslim biçimi ve süre**
      hukuk/uyum onayından geçti mi? (`season` bayrağı ve sezon kaydı)
- [ ] Kişisel veri (IP hash, kullanıcı adı, skor) için **aydınlatma metni** ve
      saklama süresi (30 gün koşu / 5000 satır denetim) yayında mı?
- [ ] Vergi/ikramiye mevzuatı ve yaş sınırı gereksinimleri değerlendirildi mi?
- [ ] Destek süreci tanımlandı mı (itiraz, hatalı skor, teslim)?
- [ ] Yedekleme ve geri alma provası yapıldı mı?

> Bu belge hukuki uygunluk iddiası **içermez**. Ödüllü yarışma, kişisel veri ve
> yürürlükteki mevzuat için işletmeci/hukuk onayı zorunludur.

---

## 12. Değişen / eklenen dosyalar

**Yeni**
```
mini-oyun.php                       oyuncu sayfası
assets/css/minigame.css             kapsamlı stil (.mg-page)
assets/js/minigame/engine.js        deterministik motor (istemci)
assets/js/minigame/game.js          sahne, girdi, ses, HUD
assets/js/minigame/app.js           API istemcisi + sekmeler + mağaza
api/minigame/{state,start,submit,run,claim,leaderboard,catalog,buy,equip,season}.php
lib/minigame/{bootstrap,engine,store,config,catalog,stats,leaderboard,wallet,run,season,admin,api,maintenance}.php
admin/minigame.php                  yönetim paneli (mevcut kabuğa sekme)
cron/minigame.php                   bakım görevi (CLI)
data/minigame/settings.php          seed ayarlar (DEMO)
data/minigame/catalog.php           seed kozmetikler (DEMO)
tests/php/engine_test.php           motor eşliği
tests/php/integration_test.php      entegrasyon
tests/fixtures/make-vectors.mjs     fikstür üretici
tests/fixtures/minigame-vectors.json
tests/js/game_test.mjs              sahne testi
tests/js/app_test.mjs               arayüz (jsdom) testi
tools/minigame-bot.mjs              test botu (istemciye gönderilmez)
tools/minigame-http-test.mjs        HTTP uçtan uca test
tools/php-cli-run.mjs, php-lint.mjs, preview-server.mjs  (test altyapısı)
MINI-OYUN.md, MINI-OYUN-PLAN.md     belgeler
```

**Değişen (mevcut dosyalar — minimal)**
```
db.php                 +4 koleksiyon (minigame_ledger, _entitlements, _loadouts, _task_claims)
header.php             +"Mini oyun" menü bağlantısı
footer.php             +"Mini oyun" bağlantısı
auth.php               $allowedRootPages += mini-oyun.php (giriş sonrası dönüş)
admin/_layout.php      +🎮 Mini Oyun sekmesi
sitemap.php            +mini-oyun.php
.gitignore             storage/minigame/
```

Ana sayfa, mağaza, DG Social, stüdyo ve genel yerleşim **değiştirilmedi**.
`index.php`’ye tanıtım kartı **eklenmedi** (onay bekliyor).
