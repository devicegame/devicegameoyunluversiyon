# DG Social Forum Lansmanı

Bu sürüm, DG Social akışını veritabanı sunucusu kullanmadan çalışan tam özellikli bir oyuncu forumuna genişletir. Veriler mevcut çekirdek JSON dışında, `storage/social/` altında parçalı JSON/JSONL dosyalarında ve kilitli/atomik yazmalarla tutulur.

## Yayınlanan alanlar

- **Forum panosu:** Kategori grupları, konu/yorum/görüntülenme sayıları, son aktivite, kategori takibi ve sessize alma, okunmadı rozetleri.
- **Akış:** Keşfet, takip edilen kişiler/kategoriler, izlenen konular, okunmamış ve kaydedilen görünümler; konu türü, çözüm, içerik özelliği, dönem ve yazar filtreleri; yorum ve profil arama.
- **Konu detayları:** Konu türleri, görüntülenme, kabul edilen çözüm, yorum GG'si, alıntı, imza, yorum sıralaması, konu izleme, kategori taşıma ve zengin metin araçları.
- **Özel mesajlar:** Gelenler/istekler/arşiv, okundu durumu, yazıyor göstergesi, engel ve gizlilik politikaları, mesaj silme ve şikâyet.
- **Canlı sohbet:** Genel, Oyun Arkadaşı, Etkinlikler, Yardım ve resmî Duyurular kanalları; polling, sabit mesajlar, çevrimiçi/yazıyor listesi, yavaş mod ve moderasyon.
- **Üyeler ve profiller:** Üye dizini, yardımsever sıralaması, avatar, imza, çevrimiçi görünürlük, DM ve bildirim tercihleri.
- **Moderasyon ve işletme:** Gönderi, yorum, profil, DM ve sohbet şikâyetleri; DM için ±5 mesaj bağlamı; duyuru yayını, uyarı/susturma, sohbet budama, sağlık/yedek durumu ve moderasyon günlüğü.
- **Dağıtım:** RSS akışları, forum/üye sitemap bağlantıları ve sosyal sayfalarda RSS otomatik keşfi.

## Kaydırma düzeltmesi

Eski `#akis` bağlantıları kaldırıldı. Akış filtreleri, sekmeleri, etiketleri ve sayfalaması desteklenen tarayıcılarda yerinde AJAX ile güncellenir. AJAX çalışmazsa yeni kaydırma geri yükleme, yalnızca ham Y koordinatını değil hedef adrese en yakın görünür öğe çapasını ve viewport sapmasını kullanarak konumu geri getirir. Yorum veya kanal sonu gibi bilinçli çapalar çalışmaya devam eder.

## Yayın öncesi kontrol

1. `storage/social/` ve `uploads/social/` klasörlerini birlikte yedekleyin; genel geri yükleme için `storage/dg/database.json` dosyasını da saklayın.
2. Yönetim panelinde **Sosyal Moderasyon → Duyuru & Bakım → Sağlık Durumu** bölümünden kritik sorun olmadığını doğrulayın.
3. İki test hesabıyla şu akışları deneyin: konu açma/yorum/çözüm, kategori ve konu takibi, DM isteğini kabul etme, canlı sohbet, bildirimler, şikâyet ve susturma.
4. Filtre, sekme, sayfalama ve GG/kaydet/takip tıklamalarında sayfanın üste atmadığını masaüstü ve mobilde doğrulayın.
5. `sitemap.php` içinde `sosyal/konular.php` ve `sosyal/uyeler.php` adreslerinin üretildiğini, `sosyal/rss.php` çıktısının geçerli XML olduğunu kontrol edin.
6. Canlı sohbet ve DM polling'inin paylaşımlı hosting kaynak sınırları içinde kaldığını ilk günlerde izleyin.

## Teknik notlar

- WebSocket sunucusu gerekmez; DM ve sohbet görünürlük duyarlı HTTP polling kullanır.
- Özel mesaj içerikleri yönetim panelinde toplu listelenmez. Moderatör yalnız ilgili şikâyet için hedef mesajın çevresindeki sınırlı bağlamı açabilir.
- “Sosyal yedeğini aldım olarak işaretle” düğmesi dosya indirmez; yalnızca gerçek klasör yedeğinin alındığını bakım kaydı olarak işaretler.
