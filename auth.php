<?php
/**
 * auth.php — Kayıt & Giriş POST işleyici (veritabansız)
 * Formlar action="auth.php" olarak gönderilir.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

function dg_safe_back(string $back, string $fallback = 'login.php'): string {
    return in_array($back, ['login.php', 'register.php'], true) ? $back : $fallback;
}

function dg_safe_next(string $next): string {
    $next = trim($next);
    if (
        $next === ''
        || preg_match('#^(?:https?:)?//#i', $next)
        || str_contains($next, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $next)
        || str_starts_with($next, '/')
        || str_starts_with($next, '../')
    ) {
        return 'index.php';
    }

    $fragment = '';
    if (str_contains($next, '#')) {
        [$next, $fragment] = explode('#', $next, 2);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $fragment)) return 'index.php';
    }

    $path = $next;
    $query = '';
    if (str_contains($next, '?')) {
        [$path, $query] = explode('?', $next, 2);
        if (!preg_match('/^[A-Za-z0-9_=&%+.,@:-]*$/', $query)) return 'index.php';
    }

    $allowedRootPages = [
        'index.php', 'shop.php', 'product.php', 'about.php', 'contact.php',
        'cart.php', 'checkout.php', 'dcoin.php', 'earn.php', 'orders.php',
        'mini-oyun.php',
    ];
    $allowed = in_array($path, $allowedRootPages, true)
        || in_array($path, ['blog/index.php', 'blog/yazi.php'], true)
        || preg_match('#^sosyal/(?:index|konular|gonderi|profil|bildirimler|yeni|etkinlikler|kurallar|mesajlar|sohbet|uyeler)\.php$#', $path)
        || preg_match('#^admin/[a-z0-9_-]+\.php$#i', $path);
    if (!$allowed) return 'index.php';

    return $path
        . ($query !== '' ? '?' . $query : '')
        . ($fragment !== '' ? '#' . $fragment : '');
}

function dg_auth_back_url(string $action, string $next): string {
    $page = $action === 'register' ? 'register.php' : 'login.php';
    return $next !== 'index.php' ? $page . '?next=' . rawurlencode($next) : $page;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authNext = dg_safe_next((string)($_POST['next'] ?? $_GET['next'] ?? 'index.php'));
    $backPage = dg_safe_back((string)($_POST['_back'] ?? ($action === 'register' ? 'register.php' : 'login.php')), $action === 'register' ? 'register.php' : 'login.php');
    $backUrl = $backPage . ($authNext !== 'index.php' ? '?next=' . rawurlencode($authNext) : '');
    if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
        flash_error_set('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
        header('Location: ' . $backUrl); exit;
    }

    try {
        // ---------- KAYIT ----------
        if ($action === 'register') {
            $username  = trim((string)($_POST['username'] ?? ''));
            $email     = trim((string)($_POST['email'] ?? ''));
            $password  = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password2'] ?? '');

            $err = validate_username($username) ?? validate_email($email) ?? validate_password($password);
            if ($err) { flash_error_set($err); header('Location: ' . $backUrl); exit; }
            if ($password !== $password2) { flash_error_set('Şifreler uyuşmuyor.'); header('Location: ' . $backUrl); exit; }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $result = dg_create_user($username, $email, $hash);
            if (empty($result['ok'])) {
                flash_error_set((string)($result['error'] ?? 'Kayıt oluşturulamadı.'));
                header('Location: ' . $backUrl); exit;
            }

            $user = $result['user'];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['dg_auth_v'] = DG_AUTH_VERSION;
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['is_admin'] = 0;
            $_SESSION['dcoin_balance'] = 0;
            flash_set('Hoş geldin, ' . $username . '! Hesabın oluşturuldu. DG Social başlangıç adımlarından topluluğa katılabilir, D-Coin için videoları izlemeye başlayabilirsin.');
            header('Location: ' . ($authNext !== 'index.php' ? $authNext : 'sosyal/index.php?hosgeldin=1')); exit;
        }

        // ---------- GİRİŞ ----------
        if ($action === 'login') {
            $login    = trim((string)($_POST['login'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($login === '' || $password === '') {
                flash_error_set('Tüm alanları doldurun.');
                header('Location: ' . $backUrl); exit;
            }

            $user = dg_find_user_for_login($login);
            if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
                flash_error_set('Kullanıcı adı/e-posta veya şifre hatalı.');
                header('Location: ' . $backUrl); exit;
            }
            if ((int)($user['is_banned'] ?? 0) === 1) {
                flash_error_set('Hesabınız engellenmiş. Destek ile iletişime geçin.');
                header('Location: ' . $backUrl); exit;
            }

            if (password_needs_rehash((string)$user['password_hash'], PASSWORD_BCRYPT)) {
                dg_update_user((int)$user['id'], [
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                ]);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['dg_auth_v'] = DG_AUTH_VERSION;
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);
            $_SESSION['dcoin_balance'] = (int)($user['dcoin_balance'] ?? 0);

            flash_set('Tekrar hoş geldin, ' . $user['username'] . '!');
            header('Location: ' . $authNext); exit;
        }
    } catch (Throwable $e) {
        error_log('[auth] ' . $e->getMessage());
        flash_error_set('Depolama hatası nedeniyle işlem tamamlanamadı. diagnose.php ile kontrol edin.');
        header('Location: ' . $backUrl); exit;
    }
}

header('Location: login.php'); exit;
