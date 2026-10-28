# Yayına alma (hosting) rehberi

Proje **saf PHP** olduğu için paylaşımlı hostinglerde (cPanel / Plesk, Apache veya
Nginx) ek kurulum gerektirmeden çalışır. Composer, Node.js, veritabanı ya da
build adımı gerekmez.

## 1. Gereksinimler

| Gereksinim | Not |
| --- | --- |
| PHP **8.1+** | Kodun tamamı 8.0 ile de uyumlu; güvenlik desteği için 8.1+ önerilir |
| `mbstring` | Türkçe karakter ve arama için (çoğu hostingde açıktır) |
| `session` | Sepet oturumda tutulur |
| Yazma izni | `storage/` (siparişler + D-Coin verisi) ve `uploads/` (reklam videoları) için |
| HTTPS | Önerilir; WhatsApp yönlendirmesi ve çerezler için daha güvenli |

PHP sürümünü cPanel → **MultiPHP Manager**, Plesk → **PHP Settings**
bölümünden seçebilirsin.

## 2. Dosyaları yükle

1. Depoyu indir (Code → Download ZIP) ya da `git clone` yap.
2. **Yüklenmeyecekler:** `tools/` (yalnızca yerel önizleme içindir),
   `node_modules/`, `.git/`, `about_php.zip`.
3. Geri kalan her şeyi `public_html` (ya da alan adının kök klasörü) içine at.
   Alt klasör kullanacaksan adresler göreli olduğu için `site.com/magaza/`
   şeklinde de çalışır.

Dosya yöneticisi yerine FTP/SFTP kullanıyorsan `data/`, `lib/`, `partials/`,
`storage/`, `img/` klasörlerinin de yüklendiğinden emin ol.

## 3. İzinler

```
storage/        775  (bazı hostinglerde 755 yeterli)
storage/dg/     775  (D-Coin JSON verisi)
uploads/        775  (admin panelinden yüklenen reklam videoları)
diğer dosyalar  644
klasörler       755
```

`storage/` yazılamazsa siparişler WhatsApp'a yine gider, ancak panele
kaydedilmez. Yazma izni yoksa panel boş görünür.

## 4. Ayarlar — `config.php`

```php
$whatsapp       = "905519578350";          // ülke kodlu, boşluksuz
$email          = "info@devicegame.com";
$hq_address     = "...";
$admin_password = getenv('DG_ADMIN_PASSWORD') ?: '';
```

- Hosting panelinde veya sunucu yapılandırmasında `DG_ADMIN_PASSWORD` ortam
  değişkenini güçlü ve benzersiz bir şifreyle tanımla; şifreyi depoya yazma.
- Değişken tanımlanmamışsa sipariş paneli (`admin.php`) 503 yanıtı verir;
  sitenin diğer bölümleri çalışmaya devam eder.
- Orijinal arşivde bulunan eski panel şifresini tüm kurulumlarda değiştir;
  arşiv ve Git geçmişi herkese açık olduğu sürece eski değer gizli sayılmaz.

## 5. Apache / Nginx

- **Apache:** Ek bir şey gerekmez. `storage/.htaccess` klasörü dışarıya kapatır.
- **Nginx:** `.htaccess` çalışmaz. Sunucu bloğuna şunu ekle:

  ```nginx
  location ^~ /storage/ { deny all; return 404; }
  ```

  Alternatif olarak `storage/` klasörünü web kökünün dışına taşıyıp
  `lib/shop.php` ve `lib/admin.php` içindeki yolu güncelle.

## 6. Kurulum sonrası kontrol listesi

- [ ] `https://alanadin.com/` ana sayfa açılıyor
- [ ] `/shop.php` ürünleri listeliyor, arama ve filtre çalışıyor
- [ ] Bir ürün sepete ekleniyor, sepet sayısı menüde artıyor
- [ ] Sepette bilgiler doldurulup "WhatsApp ile siparişi tamamla" doğru numaraya
      doğru metinle gidiyor
- [ ] `/admin.php` şifresiyle giriliyor, test siparişi görünüyor
- [ ] `https://alanadin.com/storage/orders.jsonl` **açılmıyor** (403/404)
- [ ] `DG_ADMIN_PASSWORD` güvenli biçimde tanımlandı ve eski şifre değiştirildi
- [ ] `display_errors` kapalı (canlıda hata mesajları kullanıcıya görünmemeli)

## 7. Sık karşılaşılanlar

**"Sipariş kaydedilmiyor / panel boş"**
`storage/` yazılamıyor. İzinleri 775 yap; gerekirse klasörü elle oluştur ve
içine `.htaccess` + boş `index.html` koy.

**"Türkçe karakterler bozuk"**
Dosyalar UTF-8 olarak yüklenmeli (çoğu FTP istemcisi varsayılan olarak doğru
yapar). `mbstring` uzantısı kapalıysa hosting desteğinden açmasını iste.

**"Sepet sıfırlanıyor"**
PHP oturum klasörü yazılamıyor olabilir; hosting desteğine `session.save_path`
ayarını sor. Ayrıca site mutlaka **tek alan adı** üzerinden açılmalı
(`www` ile `www` olmadan karışık kullanım oturumu böler).

**"WhatsApp açılmıyor"**
Numara ülke kodlu ve boşluksuz olmalı (`9055...`, `+` veya `0` yok).

**"Panel şifresini unuttum"**
Sunucudaki `DG_ADMIN_PASSWORD` ortam değişkenini değiştir ve gerekirse PHP
sürecini yeniden başlat. Sonraki girişte yeni şifre gerekir.

## 8. Yedekleme

Önemli dosyalar:

- `storage/dg/database.json` — D-Coin kullanıcıları, bakiyeler, reklamlar, ürün/keyler ve loglar
- `storage/orders.jsonl` — eski mağaza siparişleri (satır başına bir JSON)
- `storage/order-status.json` — eski mağaza panelinde verilen durumlar

Bunları düzenli olarak indir. Hosting yedeği alıyorsa `storage/` klasörünün
dahil olduğunu doğrula.
