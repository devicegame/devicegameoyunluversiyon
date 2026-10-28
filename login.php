<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if (is_logged_in()) { header('Location: index.php'); exit; }

$err = flash_error_get();
$flash = flash_get();
$next = (string)($_GET['next'] ?? 'index.php');

$page_title = 'Giriş yap';
$page_canonical = false;
$page_robots = 'noindex,nofollow';
$page_css = ['shop.css', 'assets/css/dcoin.css'];
$body_class = 'shop-page auth-page';
include __DIR__ . '/header.php';
?>

<section class="auth-section" aria-labelledby="login-title">
    <div class="auth-layout">
        <div class="auth-card seq" style="--d:.05s">
            <p class="eyebrow">Hesabım</p>
            <h1 id="login-title">Tekrar hoş geldin.</h1>
            <p class="auth-intro">D-Coin bakiyene, günlük izleme hakkına ve alışverişlerine kaldığın yerden devam et.</p>

            <?php if ($err): ?>
                <div class="auth-alert is-error" role="alert"><?php echo e($err); ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="auth-alert is-success" role="status"><?php echo e($flash['msg'] ?? ''); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['banned'])): ?>
                <div class="auth-alert is-error" role="alert">Hesabın engellenmiş. Destek ile iletişime geçebilirsin.</div>
            <?php endif; ?>

            <form method="post" action="auth.php" class="auth-form" autocomplete="on">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="_back" value="login.php">
                <input type="hidden" name="next" value="<?php echo e($next); ?>">

                <div class="field">
                    <label for="login">Kullanıcı adı veya e-posta</label>
                    <input id="login" type="text" name="login" required placeholder="oyuncu1 veya oyuncu1@mail.com" autofocus>
                </div>
                <div class="field">
                    <label for="password">Şifre</label>
                    <input id="password" type="password" name="password" required placeholder="Şifreniz" autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary btn-full">Giriş yap</button>
            </form>

            <div class="auth-foot">
                <p>Hesabın yok mu? <a href="register.php?next=<?php echo rawurlencode($next); ?>">Kayıt ol</a></p>
            </div>
        </div>

        <aside class="auth-side" aria-label="D-Coin bilgisi">
            <div class="auth-side-card is-light seq" style="--d:.18s">
                <p class="eyebrow">Mağaza</p>
                <h2>Kazandığını rafta harca.</h2>
                <p>Eski mağazamızdaki oyun keylerini TL ile sepete ekleyebilir, istersen D-Coin bakiyeni kullanabilirsin.</p>
            </div>
            <div class="auth-side-card seq" style="--d:.3s">
                <p class="eyebrow">D-Coin</p>
                <h2>Videoyu tamamla, bakiyeni büyüt.</h2>
                <p>İleri sarma kapalıdır; sekme değişirse video durur. Süre tamamlandığında ödül hesabına eklenir.</p>
            </div>
        </aside>
    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
