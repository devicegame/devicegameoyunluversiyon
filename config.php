<?php
// Şirket ve Meta Ayarları
$company_name = "Device Game";
$slogan = "Oyun dünyasının anahtarı.";
$meta_desc = "Ucuz oyun keyleri, premium hesaplar, oyunculara özel tasarım mousepad ve baskılı ürünler Device Game'de. Vizyoner oyun stüdyomuzun projelerini hemen keşfet.";

// Hub Linkleri
$shop_page = "shop.php";
$shop_url = "shop.php";
$studio_url = "https://studio.devicegame.com";

// Gelecek proje (ana sayfada vitrin)
$featured_project_name = "Project: Overcharge";
$featured_project_status = "Geliştirme aşamasında";
$featured_project_desc = "Karanlık sokaklarda kendi yolunu bul. Yeni nesil RPG deneyimi.";

// İletişim & Sosyal
$email = "info@devicegame.com";
$whatsapp = "905519578350";
$city = "Torbalı, İzmir";
$hq_address = "Ayrancılar Mahallesi, 61. Sokak, No:1/B2 (Değirmen Caddesi kesişimi) PK: 35860 Torbalı / İzmir";
$year = date("Y");

// Sipariş paneli şifresini kaynak kodunda tutmayın.
// Sunucuda DG_ADMIN_PASSWORD ortam değişkenini ayarlayın.
$admin_password = getenv('DG_ADMIN_PASSWORD') ?: '';

// Mağaza altyapısı (oturum + yardımcılar)
require_once __DIR__ . '/lib/shop.php';
shop_boot();
