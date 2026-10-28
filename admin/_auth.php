<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

require_login();
$user = current_user(); // güncel yetki/bakiye/ban bilgisi storage'dan okunur

if (!$user || (int)($user['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    die('<h1>403 — Yetkisiz erişim</h1><p>Bu alana sadece yöneticiler girebilir.</p><a href="../index.php">Ana sayfaya dön</a>');
}
