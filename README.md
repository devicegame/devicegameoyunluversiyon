# Device Game — Mağaza

> Projenin çalışır kaynakları depo köküne çıkarılmıştır; `about_php.zip`
> değişmeden saklanan orijinal arşivdir. Yayına alırken ZIP yerine kökteki
> güncel dosyaları kullanın. `storage/dg/database.json` ve `storage/social/`
> kullanıcı verileridir ve Git'e eklenmez; mevcut sunucudaki bu verileri
> koruyun veya gerekirse orijinal arşivden geri yükleyin. `tools/` önizlemesi
> bu kişisel verileri kopyalamaz, geçici bir veri alanı kullanır.
>
> **Güvenlik:** Orijinal arşiv eski bir panel şifresi ve çalışma verileri
> içerir. Eski şifreyi kullanmayın; tüm kurulumlarda değiştirin ve herkese
> açık arşiv ile Git geçmişinin erişimini ayrıca gözden geçirin.

Siteyle aynı tasarım dilinde, **saf PHP** ile yazılmış mağaza katmanı.
Veritabanı, framework, build adımı yok. Sepet oturumda tutulur, sipariş
WhatsApp'ta hazır bir mesaj olarak açılır.

> **D-Coin sistemi de veritabansızdır:** Kullanıcılar, reklam ödülleri,
> bakiyeler, tek çatı mağaza ve loglar `storage/dg/database.json` içinde
> tutulur. Kurulum ve yedekleme adımları için **[KURULUM.md](KURULUM.md)**
> dosyasına bakın. MySQL/phpMyAdmin gerekmez. D-Coin arayüzleri bu
> sitenin mevcut tasarımını ve `config.php` içindeki SEO bilgilerini
> kullanır; `storage/products.json` içindeki mağaza ürünleri D-Coin
> vitrinine otomatik eşitlenir. **DG Social** forum + akış platformu da
> aynı hesabı kullanır; sosyal veriler çekirdek JSON’dan ayrı olarak
> `storage/social/` altında parçalı JSON/JSONL dosyalarında tutulur.

## Gereksinim

- PHP 8.1 veya üzeri (mbstring, session) — kod 8.0 ile de uyumlu
- Yazma izni (sipariş kaydı için `storage/` klasörü otomatik oluşturulur)

Paylaşımlı hostinge kurulum adımları için **[DEPLOY.md](DEPLOY.md)** dosyasına bak.

## Çalıştırma

```bash
php -S 0.0.0.0:8000
```

Ardından `http://localhost:8000/shop.php` adresini aç.
Apache/Nginx üzerinde klasörü doğrudan yayına almak da yeterli.

### PHP kurulu değilse (yerel önizleme)

`tools/` altında PHP'yi WebAssembly içinde çalıştıran küçük bir önizleme
sunucusu var. Bu **sadece geliştirme içindir**, canlı sunucuda gerekmez:

```bash
cd tools && npm install && npm start
```

Sunucu çalışırken başka bir terminalde `cd tools && npm test` ile sayfaların,
ortak temanın ve tema dosyalarının erişilebilirliğini kontrol edebilirsin.

## Dosyalar

| Dosya | Görev |
| --- | --- |
| `shop.php` | Mağaza vitrini: haftanın seçkisi, arama, kategori filtreleri, sıralama, son bakılanlar, SSS |
| `product.php` | Ürün detayı (`?slug=...`), adet seçimi, benzer ürünler |
| `cart.php` | Sepet, adet güncelleme, sipariş formu, sepete ekleme işlemleri |
| `checkout.php` | Siparişi doğrular, kaydeder, WhatsApp mesajına yönlendirir |
| `order.php` | Sipariş özeti: numara, kalemler, mesajı yeniden açma |
| `blog/index.php` | **Blog** — yazı listesi (`/blog`) |
| `blog/yazi.php` | Blog yazısı (`?slug=...`) |
| `blog.css` | Blog sayfalarına özel stiller |
| `sosyal/index.php` | **DG Social** — herkese açık forum + akış, konu filtreleri ve arama |
| `sosyal/gonderi.php` | Sosyal gönderi detayı, yorumlar ve tek seviyeli cevaplar |
| `sosyal/yeni.php` | Gönderi oluşturma/düzenleme (giriş gerekli) |
| `sosyal/profil.php` | Sosyal profil, takip/engelleme ve kullanıcı içerikleri |
| `sosyal/bildirimler.php` | Yorum, takip ve bahsetme bildirimleri |
| `sosyal/aksiyon.php` | GG, kaydetme, takip, şikâyet ve moderasyon işlemleri |
| `lib/social.php` | Parçalı JSON/JSONL sosyal veri ve moderasyon katmanı |
| `social.css` + `assets/js/social.js` | Sosyal sayfaların stilleri ve aşamalı JavaScript iyileştirmeleri |
| `partials/product-card.php` | Tek ürün kartı (vitrin, arama, benzer ürünler) |
| `partials/slider.php` | Mağaza vitrini slider'ı (ana sayfa + mağaza) |
| `partials/dcoin-storefront.php` | Mağaza/ürün sayfalarında ortak D-Coin fiyatı ve satın alma düğmeleri |
| `partials/dcoin-purchase-modal.php` | Satın alma sonucu, sipariş kodu ve WhatsApp paylaşım penceresi |
| `data/products.php` | **Varsayılan ürün kataloğu** (ilk açılışta `storage/products.json`'a kopyalanır) |
| `lib/shop.php` | Sepet, fiyat, CSRF, sipariş, slider ve WhatsApp yardımcıları |
| `shop.css` | Mağazaya özel stiller (mevcut `style.css` üzerine) |
| `config.php` | WhatsApp numarası, adres ve meta bilgileri; panel şifresi ortam değişkeninden alınır |
| `admin.php` | Yönetim paneli (şifreli): siparişler, **ürün ekle/sil**, **slider yönetimi**, CSV |
| `lib/admin.php` | Panel yardımcıları: ürün/slider kayıtları, sipariş durumları |
| `lib/art.php` | Ürün posterleri için prosedürel SVG kapak sanatı |

## Sipariş akışı

1. Ürün sepete eklenir (oturum tabanlı, `$_SESSION['cart']`).
2. `cart.php` içindeki formda ad ve e-posta alınır. **Teslimat şekli tektir:
   dijital teslimat.** **Ödeme tercihi tektir: Havale / EFT.**
3. `checkout.php` girdileri ve CSRF jetonunu doğrular.
4. Sipariş `storage/orders.jsonl` dosyasına yazılır (tek satır = tek sipariş).
5. Kullanıcı, sipariş özetini içeren hazır mesajla WhatsApp'a yönlendirilir.

Ödeme sunucuda alınmaz; Havale / EFT bilgileri WhatsApp konuşmasında paylaşılır,
teslimat dijital olarak aynı konuşma üzerinden yapılır.
Kullanıcı WhatsApp'a dönmek isterse `order.php` son siparişin özetini ve
hazır mesaj bağlantısını gösterir.

## Yönetim paneli

`admin.php` adresine, sunucuda tanımlanan `DG_ADMIN_PASSWORD` ortam
değişkenindeki şifreyle girilir. Şifre tanımlanmadan panel açılmaz. Dört bölüm vardır:

- **Siparişler** — liste, durum güncelleme, arama, CSV indirme.
- **Ürünler** — kataloğa ürün ekleme, düzenleme ve silme. Kayıtlar
  `storage/products.json` dosyasında tutulur; `data/products.php` yalnızca
  ilk açılıştaki varsayılan katalogdur.
- **Slider** — mağaza sayfasındaki vitrin slider'ı: slayt ekleme, düzenleme,
  sıralama, yayına alma/gizleme ve silme. Kayıtlar `storage/slides.json`
  dosyasındadır.
- **Blog** — `/blog` adresinde yayınlanan yazılar: ekleme, düzenleme,
  yayına alma/gizleme ve silme. Yazılar `storage/blog.json`, kategoriler
  `storage/blog-categories.json` dosyasında tutulur. Kategori filtreleri
  `/blog/index.php?kategori=...` adresini kullanır. Metin biçimi: boş satır
  = paragraf, `## ` = başlık, `- ` = liste. Aynı yazılar D-Coin yönetim
  panelindeki `/admin/blog.php` bölümünden zengin metin editörüyle ve
  kategori yöneticisiyle de yönetilebilir; iki panel aynı veriyi kullanır.

Mobilde ürün ve sepet sayfalarında sabit bir işlem çubuğu (fiyat + sepete
ekle / siparişi onayla) görünür; WhatsApp balonu bu sayfalarda gizlenir.
`storage/` klasörü `.gitignore` içindedir ve Apache için `.htaccess` ile
dışarıya kapatılır. Nginx kullanıyorsan `location ^~ /storage/ { deny all; }`
satırını ekle ya da klasörü web kökünün dışına taşı.

## DG Social (forum + akış)

`/sosyal/index.php` herkesin okuyabildiği Device Game topluluğudur.
Gönderi, yorum ve etkileşim için mevcut Device Game hesabıyla giriş
gerekir. Profiller, forum konuları, gönderiler, tek seviyeli yorum
cevapları, GG, kaydetme, takip, bildirim, etiket/arama, şikâyet ve
engelleme bulunur. **Oyun Arkadaşı (LFG)** konusu oyun, platform, rank,
mikrofon, oyun saati ve aranan kişi sayısını ayrıca toplar.

Sosyal veriler `storage/dg/database.json` içine yazılmaz:

| Yol | İçerik |
| --- | --- |
| `storage/social/` | Profiller, gönderiler, yorum/şikâyet JSONL dosyaları, takip/bildirim verileri ve hazır akış indeksleri |
| `uploads/social/` | Güvenilir üyelerin gönderi görselleri |

Yazmalar kilit ve geçici dosya + `rename()` ile atomik yapılır. Akış
listeleri `indexes/` altındaki özetleri kullanır. Yedek alırken hem
`storage/social/` hem de `uploads/social/` klasörlerini birlikte
kopyalayın. İndeksler bozulursa yönetimde **Sosyal → Bakım →
İndeksleri yeniden oluştur** kullanılabilir.

Güvenlik ve moderasyon, `/admin/social.php` ekranından yönetilir:
onay kuyruğu, şikâyetler, yayınla/gizle/sil, sabitleme, kilitleme,
konu yönetimi ve indeks bakımı. Yeni veya çok bağlantılı içerikler onay
kuyruğuna düşebilir; kullanıcı bağlantıları `nofollow ugc noopener`
olarak basılır. Görsel yükleme güvenilir üyeler/adminlerle sınırlıdır
(JPG/PNG/WebP, en fazla 4 MB). Bu dosya tabanlı MVP’de özel mesaj,
canlı sohbet, video yükleme ve algoritmik öneri akışı yoktur.

## Sipariş paneli

`admin.php` adresini aç, şifreyi gir.

- Sunucuda `DG_ADMIN_PASSWORD` ortam değişkenini güçlü ve benzersiz bir
  şifreyle tanımla. Kaynak kodunda varsayılan bir panel şifresi yoktur;
  değişken tanımlanmamışsa panel 503 yanıtı verir.
- Panel; toplam sipariş, bekleyen sipariş, ciro ve günün özetini gösterir.
- Her sipariş kartında müşteri bilgisi, ürün kalemleri, teslimat/ödeme tercihi
  ve not bulunur.
- Durumlar: **Yeni → Hazırlanıyor → Tamamlandı / İptal**. Durumlar
  `storage/order-status.json` içinde tutulur, sipariş kaydı değiştirilmez.
- `CSV indir` ile tüm siparişler Excel uyumlu (UTF-8 BOM) dosya olarak iner.
- Sayfa `noindex` ile işaretlidir ve oturum çerezi `HttpOnly` + `SameSite=Lax`.

## Ürün görselleri

Ürünler varsayılan olarak `lib/art.php` ile üretilen SVG kapak sanatını
kullanır (dış dosya gerekmez). Gerçek key-art eklemek istersen:

1. Görseli `img/` klasörüne koy (örn. `img/ea-sports-fc-26.jpg`).
2. `data/products.php` içindeki kayda `'image' => 'img/ea-sports-fc-26.jpg'` ekle.

Dosya yoksa otomatik olarak SVG sanatına geri döner, yani kırık görsel oluşmaz.

## Ürün eklemek / düzenlemek

`data/products.php` içindeki diziye yeni bir kayıt ekle:

```php
[
    'slug'      => 'yeni-urun',
    'name'      => 'Ürün adı',
    'subtitle'  => 'Kısa açıklama',
    'category'  => 'key',        // key | account | gear
    'price'     => 899,           // TL, tam sayı
    'old_price' => 1099,          // yoksa null
    'platform'  => 'Steam',
    'badge'     => 'Yeni',        // yoksa null
    'glyph'     => 'YN',          // poster üzerindeki kısa harf
    'c1'        => '#0F3B2C',     // poster gradyan başlangıcı
    'c2'        => '#2FA377',     // poster gradyan bitişi
    'accent'    => '#D6FFE9',
    'pattern'   => 'grid',        // grid | dots | lines | rings
    'delivery'  => 'Anında teslimat',
    'stock'     => 10,
    'order'     => 13,
    'added_at'  => '2026-10-20',
    'desc'      => 'Uzun açıklama.',
    'features'  => ['Madde 1', 'Madde 2'],
    // 'image'  => 'img/yeni-urun.jpg', // opsiyonel
],
```

WhatsApp numarası `config.php` içindeki `$whatsapp` değişkeninde
(`905519578350`, ülke koduyla, boşluksuz).

## Notlar

- Tüm fiyatlar KDV dahil ve TL olarak tam sayı tutulur (`money()` biçimlendirir).
- Sepette ürün başına üst sınır 10 adet.
- Formlar CSRF jetonu ile korunur; oturum çerezi `HttpOnly` + `SameSite=Lax`.
- JavaScript kapalıyken de sepet ve sipariş akışı çalışır (form gönderimleri).
