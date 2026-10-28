<?php
/**
 * Overcharge: Kaçış — Device Game mini oyunu.
 *
 * Bu sayfa mevcut site kabuğunu (header/footer, oturum, D-Coin cüzdanı)
 * kullanır. Oyun varlıkları YALNIZCA bu sayfada yüklenir.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/minigame/bootstrap.php';

$user = is_logged_in() ? current_user() : null;
$state = mg_public_state($user);
$catalog = mg_catalog();
$owned = $user ? mg_owned_item_ids((int)$user['id']) : mg_free_item_ids();
$loadout = $user ? mg_loadout((int)$user['id']) : mg_default_loadout();
$visuals = mg_loadout_visuals($loadout);

$boot = [
    'state' => $state,
    'catalog' => array_values(array_filter($catalog, static fn(array $item): bool => $item['published'] === 1)),
    'owned' => $owned,
    'loadout' => $loadout,
    'visuals' => $visuals,
    'csrf' => $state['csrf'],
    'urls' => [
        'api' => 'api/minigame/',
        'login' => 'login.php?next=mini-oyun.php',
    ],
    'notice' => (int)($state['economy']['approved'] ?? 0) === 1 ? '' : 'Prototip sürüm: D-Coin tutarları ve kozmetik fiyatları örnektir.',
];

$page_title = 'Overcharge: Kaçış — Mini Oyun';
$page_meta_desc = 'Device Game mini oyunu Overcharge: Kaçış. Enerji hücrelerini topla, engellerden kaç, günlük D-Coin görevlerini tamamla.';
$page_css = ['assets/css/minigame.css'];
$body_class = 'page-mini-oyun';
$page_og_type = 'website';

require __DIR__ . '/header.php';
?>

<main id="main" class="mg-page">
    <section class="mg-hero">
        <div class="mg-hero-text">
            <span class="mg-eyebrow">Device Game · Mini oyun</span>
            <h1>Overcharge: <span>Kaçış</span></h1>
            <p>
                Enerji hücrelerini topla, düşmanları vur, baş üstü kirişlerin
                altından eğilerek geç ve 60. saniyede gelen üç fazlı boss'u devir.
                Turlar 1–3 dakika sürer; skorun sunucuda aynı seed ile yeniden
                hesaplanır. Kazandığın D-Coin mevcut cüzdanına eklenir.
            </p>
            <div class="mg-hero-tags">
                <span>Klavye: Boşluk zıpla · ↓ eğil · J ateş · Shift dash</span>
                <span>Dokunmatik: sol eğil · orta ateş · sağ zıpla</span>
                <span>30 / 60 / 120 Hz aynı hız</span>
            </div>
        </div>

        <div class="mg-wallet" aria-label="Cüzdan ve günlük kazanç">
            <div class="mg-wallet-row">
                <span class="mg-wallet-label">D-Coin bakiyesi</span>
                <strong id="mg-balance"><?php echo (int)($user['dcoin_balance'] ?? 0); ?></strong>
            </div>
            <div class="mg-wallet-row mg-wallet-cap">
                <span class="mg-wallet-label">Bugünkü kazanç</span>
                <span class="mg-wallet-values">
                    <b id="mg-earned"><?php echo (int)($state['wallet']['daily_earned'] ?? 0); ?></b>
                    <span>/</span>
                    <span id="mg-cap"><?php echo (int)($state['wallet']['daily_cap'] ?? 0); ?></span>
                </span>
            </div>
            <div class="mg-bar" role="progressbar" aria-label="Günlük kazanç" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="mg-cap-bar">
                <i id="mg-cap-fill"></i>
            </div>
            <p class="mg-sub" id="mg-cap-text">
                <?php if (!$user): ?>
                    Giriş yapınca günlük D-Coin kazanımı açılır.
                <?php elseif (($state['wallet']['daily_cap'] ?? 0) > 0): ?>
                    Günlük kişisel limit: <?php echo (int)$state['wallet']['daily_cap']; ?> D-Coin
                <?php else: ?>
                    Günlük kazanç limiti tanımlı değil.
                <?php endif; ?>
            </p>
            <p class="mg-sub" id="mg-attempts">
                <?php echo $user
                    ? 'Bugünkü sıralı tur: ' . (int)$state['attempts'] . ' / ' . (int)$state['attempt_limit']
                    : 'Sıralı turlar için giriş gerekir.'; ?>
            </p>
            <p class="mg-sub" id="mg-last-run" hidden></p>
            <div class="mg-notice" id="mg-wallet-note" hidden></div>
        </div>
    </section>

    <div class="mg-page-notice" id="mg-page-notice" <?php echo $boot['notice'] === '' ? 'hidden' : ''; ?>>
        <span><?php echo e($boot['notice']); ?></span>
    </div>

    <nav class="mg-tabs" role="tablist" aria-label="Mini oyun bölümleri">
        <button class="mg-tab" role="tab" id="mg-tab-oyna" aria-controls="mg-panel-oyna" aria-selected="true" tabindex="0" data-tab="oyna">Oyna</button>
        <button class="mg-tab" role="tab" id="mg-tab-rekorlar" aria-controls="mg-panel-rekorlar" aria-selected="false" tabindex="-1" data-tab="rekorlar">Sıralamalar</button>
        <button class="mg-tab" role="tab" id="mg-tab-magaza" aria-controls="mg-panel-magaza" aria-selected="false" tabindex="-1" data-tab="magaza">Mağaza</button>
        <button class="mg-tab" role="tab" id="mg-tab-dolabim" aria-controls="mg-panel-dolabim" aria-selected="false" tabindex="-1" data-tab="dolabim">Gardırop</button>
    </nav>

    <!-- ============ OYNA ============ -->
    <section class="mg-panel" id="mg-panel-oyna" role="tabpanel" aria-labelledby="mg-tab-oyna" tabindex="0">
        <div class="mg-stage" id="mg-stage">
            <canvas
                id="mg-canvas"
                width="960"
                height="540"
                role="img"
                aria-label="Overcharge: Kaçış oyun alanı. Boşluk zıpla, aşağı ok eğil, J ateş, Shift dash. Dokunmatikte sol bölge eğil, orta bölge ateş, sağ bölge zıpla."
            ></canvas>

            <div class="mg-overlay" id="mg-overlay">
                <div class="mg-overlay-card" id="mg-overlay-card"></div>
                <noscript>
                    <p class="mg-notice">Overcharge: Kaçış için JavaScript gerekir. Tarayıcında JavaScript'i açıp sayfayı yenile.</p>
                </noscript>
            </div>

            <div class="mg-stage-tools">
                <button type="button" class="mg-icon-btn" id="mg-sound" aria-pressed="true" aria-label="Sesi kapat">🔊</button>
                <button type="button" class="mg-icon-btn" id="mg-fullscreen" aria-label="Tam ekran">⛶</button>
            </div>

            <div class="mg-pad" id="mg-pad" role="group" aria-label="Oyun kontrolleri">
                <button type="button" class="mg-pad-btn" id="mg-crouch" aria-label="Eğil (basılı tut)" disabled>EĞİL</button>
                <button type="button" class="mg-pad-btn mg-pad-fire" id="mg-fire" aria-label="Ateş (basılı tut)" disabled>ATEŞ</button>
                <button type="button" class="mg-pad-btn mg-pad-dash" id="mg-dash" aria-label="Dash (kısa dokunulmazlık)" disabled>DASH</button>
                <button type="button" class="mg-pad-btn mg-pad-jump" id="mg-jump" aria-label="Zıpla" disabled>ZIPLA</button>
            </div>
        </div>

        <p class="mg-sub mg-stage-hint">
            Sıralı turda sunucu bölümü verir: aynı gün herkes aynı düzeni oynar. Antrenman kaydedilmez.
        </p>

        <div class="mg-grid-2">
            <section class="mg-card" aria-labelledby="mg-tasks-title">
                <header class="mg-card-head">
                    <h2 id="mg-tasks-title">Günlük görevler</h2>
                    <span class="mg-chip">Her biri günde 1 kez</span>
                </header>
                <div id="mg-tasks" class="mg-tasks"></div>
            </section>

            <section class="mg-card" aria-labelledby="mg-season-title">
                <header class="mg-card-head">
                    <h2 id="mg-season-title">Haftalık ödül</h2>
                    <span class="mg-chip" id="mg-season-chip"><?php echo mg_flag('season') ? 'Açık' : 'Kapalı'; ?></span>
                </header>
                <div id="mg-season" class="mg-season"></div>
                <h3 class="mg-sub-title">Şampiyonlar</h3>
                <div id="mg-champions" class="mg-champions"></div>
            </section>
        </div>

        <div class="mg-grid-2">
            <section class="mg-card mg-rules" aria-labelledby="mg-rules-title">
                <h2 id="mg-rules-title">Nasıl oynanır</h2>
                <ul>
                    <li><b>Zıpla:</b> Boşluk, ↑, W veya ZIPLA. Erken bırakırsan daha alçak atlar.</li>
                    <li><b>Eğil:</b> ↓, S ya da EĞİL düğmesi. Baş üstü kirişlerin altından yalnızca eğilerek geçilir.</li>
                    <li><b>Ateş:</b> J, F ya da ATEŞ. Blaster şarjı kendini yeniler; <b>plazma</b> düşmanlardan düşer.</li>
                    <li><b>Dash:</b> Shift ya da DASH. Kısa süre dokunulmazlık verir, bekleme süresi vardır.</li>
                    <li><b>Düşmanlar:</b> Muhafız temasta vurur, drone ve taret ateş eder. Her düşman <b>+40 puan</b>.</li>
                    <li><b>Boss:</b> 60. saniyede girer, üç fazda saldırır. Devirirsen <b>+500 puan</b> ve tamir kiti.</li>
                    <li><b>Can:</b> 100 HP. Tamir kiti +25 can, kalkan bir vuruşu emer.</li>
                    <li><b>Bitiş:</b> Canın biterse ya da 180 saniye dolunca tur biter.</li>
                </ul>
            </section>

            <section class="mg-card mg-policy" aria-labelledby="mg-policy-title">
                <h2 id="mg-policy-title">Adil oyun</h2>
                <ul>
                    <li>Skor sunucuda aynı seed ve girdilerle yeniden hesaplanır; istemci skoru otorite değildir.</li>
                    <li>Şüpheli turlar ödül almadan incelemeye alınır; onaylanınca sıralamaya işlenir.</li>
                    <li>Sekme arka plana geçerse sıralı tur iptal edilir (avantaj oluşmasın diye).</li>
                    <li>Kozmetikler yalnızca görseldir: hız, hitbox ve skoru değiştirmez.</li>
                    <li>Otomatik oynatıcılar (bot) geçerli girdi üretebilir; bu bilinen bir sınırdır ve inceleme ile yönetilir.</li>
                    <li>Silah, can ve dash değerleri motor sabitidir; kozmetik satın alımı bunları değiştirmez.</li>
                </ul>
            </section>
        </div>
    </section>

    <!-- ============ SIRALAMALAR ============ -->
    <section class="mg-panel" id="mg-panel-rekorlar" role="tabpanel" aria-labelledby="mg-tab-rekorlar" tabindex="0" hidden>
        <div class="mg-panel-head">
            <h2>Sıralamalar</h2>
            <div class="mg-seg" id="mg-board-seg" role="group" aria-label="Dönem seçimi">
                <button type="button" data-board="today" aria-pressed="true">Bugün</button>
                <button type="button" data-board="week" aria-pressed="false">Bu hafta</button>
                <button type="button" data-board="all" aria-pressed="false">Tüm zamanlar</button>
                <button type="button" data-board="season" aria-pressed="false">Sezon</button>
            </div>
        </div>
        <p class="mg-sub">
            Her kullanıcının o dönemdeki <b>en iyi doğrulanmış</b> skoru görünür. Beraberlikte önce ulaşan
            üstte yer alır. Beklemedeki ve reddedilen turlar listelenmez.
        </p>
        <div class="mg-table-wrap">
            <table class="mg-table">
                <caption class="mg-sr-only">Oyuncu sıralaması</caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Oyuncu</th>
                        <th scope="col">Skor</th>
                        <th scope="col">Tur</th>
                        <th scope="col">Tarih (TR)</th>
                    </tr>
                </thead>
                <tbody id="mg-board-body">
                    <tr><td colspan="5" class="mg-empty">Yükleniyor…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mg-board-foot" id="mg-board-me"></div>
    </section>

    <!-- ============ MAĞAZA ============ -->
    <section class="mg-panel" id="mg-panel-magaza" role="tabpanel" aria-labelledby="mg-tab-magaza" tabindex="0" hidden>
        <div class="mg-panel-head">
            <h2>Kozmetik mağazası</h2>
            <div class="mg-seg" id="mg-shop-filter" role="group" aria-label="Kategori">
                <button type="button" data-cat="all" aria-pressed="true">Tümü</button>
                <button type="button" data-cat="character" aria-pressed="false">Karakter</button>
                <button type="button" data-cat="color" aria-pressed="false">Renk</button>
                <button type="button" data-cat="trail" aria-pressed="false">İz</button>
                <button type="button" data-cat="frame" aria-pressed="false">Çerçeve</button>
            </div>
        </div>
        <div class="mg-notice" id="mg-shop-note" hidden></div>
        <p class="mg-sub">
            Kozmetikler yalnızca görünümü değiştirir. Satın alma tek adımlıdır ve onay ister;
            sunucu fiyatı ve sahipliği doğrular. Site mağazasındaki oyun ürünleriyle karışmaz.
        </p>
        <div class="mg-shop-grid" id="mg-shop-grid"></div>
    </section>

    <!-- ============ GARDIROP ============ -->
    <section class="mg-panel" id="mg-panel-dolabim" role="tabpanel" aria-labelledby="mg-tab-dolabim" tabindex="0" hidden>
        <div class="mg-panel-head">
            <h2>Gardırop</h2>
        </div>
        <div class="mg-loadout-preview" id="mg-loadout-preview"></div>
        <p class="mg-sub">Kuşandığın görünüm sıralı turlarda ve tabloda görünür. Oynanışı etkilemez.</p>
        <div id="mg-wardrobe" class="mg-wardrobe"></div>
    </section>

    <div id="mg-toasts" class="mg-toasts" aria-live="polite" aria-atomic="false"></div>
    <p id="mg-status" class="mg-sr-only" aria-live="polite"></p>

    <div class="mg-modal" id="mg-modal" hidden>
        <div class="mg-modal-backdrop"></div>
        <div class="mg-modal-card" id="mg-modal-card" role="dialog" aria-modal="true"></div>
    </div>

    <script type="application/json" id="mg-bootstrap"><?php echo mg_json_for_html($boot); ?></script>
    <script type="module" src="assets/js/minigame/app.js"></script>
</main>

<?php require __DIR__ . '/footer.php'; ?>
