<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$next = trim((string)($_GET['next'] ?? 'sosyal/index.php?hosgeldin=1'));
if (!preg_match('#^(?:index\.php|shop\.php|product\.php|blog/(?:index|yazi)\.php|sosyal/(?:index|konular|gonderi|profil|bildirimler|yeni|etkinlikler|kurallar|mesajlar|sohbet|uyeler)\.php)(?:\?[A-Za-z0-9_=&%+.,@:-]*)?(?:\#[A-Za-z0-9_-]+)?$#', $next)) {
    $next = 'sosyal/index.php?hosgeldin=1';
}
if (is_logged_in()) { header('Location: ' . $next); exit; }

$err = flash_error_get();
$flash = flash_get();

$page_title = 'Kayıt ol';
$page_canonical = false;
$page_robots = 'noindex,nofollow';
$page_css = ['shop.css', 'assets/css/dcoin.css'];
$body_class = 'shop-page auth-page';
include __DIR__ . '/header.php';
?>

<section class="auth-section" aria-labelledby="register-title">
    <div class="auth-layout">
        <div class="auth-card seq" style="--d:.05s">
            <p class="eyebrow">Hesabım</p>
            <h1 id="register-title">Aramıza katıl.</h1>
            <p class="auth-intro">Hesabını oluştur; D-Coin kazan, mağazada harca ve aynı hesabınla DG Social topluluğuna katıl.</p>

            <?php if ($err): ?>
                <div class="auth-alert is-error" role="alert"><?php echo e($err); ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="auth-alert is-success" role="status"><?php echo e($flash['msg'] ?? ''); ?></div>
            <?php endif; ?>

            <form method="post" action="auth.php" class="auth-form" autocomplete="on">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="_back" value="register.php">
                <input type="hidden" name="next" value="<?php echo e($next); ?>">

                <div class="field">
                    <label for="username">Kullanıcı adı</label>
                    <input id="username" type="text" name="username" required minlength="3" maxlength="32" placeholder="oyuncu_ismi" pattern="[a-zA-Z0-9_.-]+" autocomplete="username">
                </div>
                <div class="field">
                    <label for="email">E-posta</label>
                    <input id="email" type="email" name="email" required placeholder="sen@ornek.com" autocomplete="email">
                </div>
                <div class="field">
                    <label for="password">Şifre</label>
                    <input id="password" type="password" name="password" required minlength="6" placeholder="En az 6 karakter" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="password2">Şifre (tekrar)</label>
                    <input id="password2" type="password" name="password2" required placeholder="Şifrenizi doğrulayın" autocomplete="new-password">
                </div>

                <label class="auth-check">
                    <input type="checkbox" required>
                    <span>Kullanım koşullarını ve gizlilik metnini okudum, onaylıyorum.</span>
                </label>

                <button type="submit" class="btn btn-primary btn-full">Hesabı oluştur</button>
            </form>

            <div class="auth-foot">
                <p>Zaten hesabın var mı? <a href="login.php?next=<?php echo rawurlencode($next); ?>">Giriş yap</a></p>
            </div>
        </div>

        <aside class="auth-side" aria-label="D-Coin bilgisi">
            <div class="auth-side-card seq" style="--d:.18s">
                <p class="eyebrow">Stüdyo</p>
                <h2>Oyun dünyasının anahtarı.</h2>
                <p>Device Game; oyunlar yapan bir stüdyo ve oyuncular için seçtiği ürünleri aynı adreste buluşturan bir mağazadır.</p>
            </div>
            <div class="auth-side-card is-light seq" style="--d:.3s">
                <p class="eyebrow">D-Coin</p>
                <h2>İzledikçe kütüphaneni büyüt.</h2>
                <p>Günlük hakkın içinde videoları tamamla, bakiyeni biriktir ve eski mağaza kataloğundaki ürünlerde harca.</p>
            </div>
        </aside>
    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
