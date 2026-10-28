<?php
// inc/auth_helpers — ek yardımcılar (db.php zaten yüklü olmalı)
declare(strict_types=1);

function redirect(string $url): void { header("Location: $url"); exit; }

function validate_username(string $u): ?string {
    $u = trim($u);
    if (strlen($u) < 3) return 'Kullanıcı adı en az 3 karakter olmalı.';
    if (strlen($u) > 32) return 'Kullanıcı adı en fazla 32 karakter olabilir.';
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $u)) return 'Kullanıcı adı sadece harf, rakam, _ . - içerebilir.';
    // DG Social'daki resmî @devicegame hesabı taklit edilemesin.
    $normalized = strtolower(str_replace(['_', '.', '-'], '', $u));
    if ($normalized === 'devicegame') return 'Bu kullanıcı adı DeviceGame resmî hesabı için ayrılmıştır.';
    return null;
}
function validate_email(string $e): ?string {
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return 'Geçerli bir e-posta girin.';
    if (strlen($e) > 190) return 'E-posta çok uzun.';
    return null;
}
function validate_password(string $p): ?string {
    if (strlen($p) < 6) return 'Şifre en az 6 karakter olmalı.';
    if (strlen($p) > 72) return 'Şifre en fazla 72 karakter olabilir.';
    return null;
}
