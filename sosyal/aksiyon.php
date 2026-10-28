<?php
require_once __DIR__ . '/_common.php';

function social_safe_return(string $return, string $fallback = 'index.php'): string
{
    $return = trim($return);
    if ($return === '' || preg_match('~^(?:https?:)?//~i', $return) || str_contains($return, '\\')) {
        return $fallback;
    }
    $pattern = '~^(?:\.\./admin/social\.php|(?:index|gonderi|profil|bildirimler|yeni|etkinlikler|kurallar|konular|uyeler|mesajlar|sohbet)\.php)(?:\?[^#\s]*)?(?:#[A-Za-z0-9_-]+)?$~';
    return preg_match($pattern, $return) ? $return : $fallback;
}

function social_action_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function social_action_finish(array $result, string $successMessage, string $return, bool $ajax, array $extra = []): void
{
    $errors = array_values(array_filter((array)($result['errors'] ?? []), static fn($value): bool => trim((string)$value) !== ''));
    if ($ajax) {
        social_action_json([
            'ok' => !$errors,
            'message' => $errors ? implode(' ', $errors) : $successMessage,
            'errors' => $errors,
        ] + $extra, $errors ? 422 : 200);
    }
    if ($errors) {
        flash_error_set(implode(' ', $errors));
    } else {
        flash_set($successMessage);
    }
    header('Location: ' . social_safe_return($return));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['islem'] ?? '') === 'bildirim_sayisi') {
    social_action_json([
        'ok' => true,
        'count' => $socialUser ? social_unread_notification_count((int)$socialUser['id']) : 0,
        'messages' => $socialUser ? social_dm_unread_count((int)$socialUser['id']) : 0,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['islem'] ?? '') === 'mesajlari_getir') {
    if (!$socialUser) social_action_json(['ok' => false, 'message' => 'Mesajları görmek için giriş yapmalısın.'], 401);
    $conversationId = (int)($_GET['sohbet'] ?? $_GET['conversation_id'] ?? 0);
    $afterId = max(0, (int)($_GET['son'] ?? $_GET['after_id'] ?? 0));
    $result = social_dm_messages($socialUser, $conversationId, $afterId, 100);
    if (!empty($result['errors'])) {
        social_action_json(['ok' => false, 'message' => implode(' ', $result['errors']), 'errors' => $result['errors']], 403);
    }
    if ($afterId > 0 && !empty($result['messages'])) {
        $last = $result['messages'][count($result['messages']) - 1];
        social_dm_mark_read($socialUser, $conversationId, (int)$last['id']);
        $result = social_dm_messages($socialUser, $conversationId, $afterId, 100);
    }
    $summary = (array)$result['conversation'];
    $peerId = (int)($summary['peer_id'] ?? 0);
    $peerPresence = $peerId > 0 ? social_presence($peerId) : null;
    $peerTyping = $peerPresence
        && (int)($peerPresence['typing_dm'] ?? 0) === $conversationId
        && (int)($peerPresence['typing_dm_at'] ?? 0) >= time() - 6;
    social_action_json([
        'ok' => true,
        'conversation' => $summary,
        'messages' => $result['messages'],
        'peer_online' => $peerId > 0 && social_is_online($peerId),
        'peer_typing' => (bool)$peerTyping,
        'unread_messages' => social_dm_unread_count((int)$socialUser['id']),
        'server_time' => time(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['islem'] ?? '') === 'sohbet_getir') {
    $channel = (string)($_GET['kanal'] ?? $_GET['channel'] ?? 'genel');
    $afterId = max(0, (int)($_GET['son'] ?? $_GET['after_id'] ?? 0));
    $result = social_chat_messages($channel, $socialUser, $afterId, $afterId > 0 ? 100 : 50);
    if (!empty($result['errors'])) {
        social_action_json(['ok' => false, 'message' => implode(' ', $result['errors']), 'errors' => $result['errors']], 404);
    }
    $online = [];
    foreach (social_online_users($socialUser, 300, 30) as $profile) {
        $online[] = [
            'user_id' => (int)$profile['user_id'],
            'username' => (string)$profile['username'],
            'display_name' => (string)$profile['display_name'],
            'avatar' => (string)($profile['avatar'] ?? ''),
            'profile_url' => social_profile_url($profile),
        ];
    }
    social_action_json([
        'ok' => true,
        'channel' => $result['channel'],
        'messages' => $result['messages'],
        'pinned' => $result['pinned'],
        'last_id' => (int)$result['last_id'],
        'online' => $online,
        'typers' => social_chat_typers((string)$result['channel']['slug'], $socialUser),
        'server_time' => time(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['islem'] ?? '') === 'benzer') {
    $query = trim((string)($_GET['q'] ?? ''));
    if (social_text_len($query) < 4) social_action_json(['ok' => true, 'items' => []]);
    $items = [];
    foreach (social_list_posts(['q' => $query, 'per_page' => 5, 'sort' => 'new'], $socialUser)['items'] as $post) {
        $items[] = [
            'id' => (int)$post['id'],
            'title' => (string)$post['title'],
            'url' => social_post_url((int)$post['id']),
            'topic_slug' => (string)$post['topic_slug'],
            'comment_count' => (int)$post['comment_count'],
        ];
    }
    social_action_json(['ok' => true, 'items' => $items]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$ajax = (string)($_POST['ajax'] ?? '') === '1'
    || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$return = social_safe_return((string)($_POST['return'] ?? 'index.php'));

if (!$socialUser) {
    if ($ajax) social_action_json(['ok' => false, 'message' => 'Bu işlem için giriş yapmalısın.'], 401);
    flash_error_set('Bu işlem için giriş yapmalısın.');
    header('Location: ' . social_login_url('sosyal/' . $return));
    exit;
}

if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
    social_action_finish(['errors' => ['Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.']], '', $return, $ajax);
}

$action = (string)($_POST['islem'] ?? '');
$postId = (int)($_POST['post_id'] ?? 0);
$commentId = (int)($_POST['comment_id'] ?? 0);
$targetId = (int)($_POST['target_id'] ?? $_POST['user_id'] ?? 0);

try {
    switch ($action) {
        case 'gg':
            $result = social_toggle_gg($socialUser, $postId);
            social_action_finish($result, !empty($result['active']) ? 'GG verildi.' : 'GG geri alındı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'count' => (int)($result['count'] ?? 0),
            ]);

        case 'kaydet':
            $result = social_toggle_save($socialUser, $postId);
            social_action_finish($result, !empty($result['active']) ? 'Gönderi kaydedildi.' : 'Kayıt kaldırıldı.', $return, $ajax, [
                'active' => !empty($result['active']),
            ]);

        case 'anket_oy':
            $result = social_vote_poll($socialUser, $postId, (string)($_POST['option_id'] ?? ''));
            social_action_finish($result, 'Oyun kaydedildi.', $return, $ajax, [
                'poll' => $result['poll'] ?? null,
                'selected' => (string)($result['selected'] ?? (($result['poll'] ?? [])['selected'] ?? '')),
            ]);

        case 'etkinlik_katil':
            $result = social_toggle_event_rsvp($socialUser, $postId);
            social_action_finish($result, !empty($result['active']) ? 'Etkinliğe katıldın.' : 'Etkinlik katılımın kaldırıldı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'count' => (int)($result['count'] ?? 0),
                'capacity' => (int)($result['capacity'] ?? 0),
                'event_at' => (int)($result['event_at'] ?? 0),
            ]);

        case 'odul_ver':
            $result = social_award_post($socialUser, $postId, (int)($_POST['amount'] ?? 0), (string)($_POST['reason'] ?? ''));
            social_action_finish($result, 'Editör Ödülü verildi ve D-Coin gönderi sahibine eklendi.', $return, $ajax, [
                'award' => $result['award'] ?? null,
                'balance' => (int)($result['balance'] ?? 0),
            ]);

        case 'yorum_gg':
            $result = social_toggle_comment_gg($socialUser, $postId, $commentId);
            social_action_finish($result, !empty($result['active']) ? 'Yoruma GG verildi.' : 'Yorum GG geri alındı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'count' => (int)($result['count'] ?? 0),
            ]);

        case 'cozum':
            $result = social_set_solution($socialUser, $postId, $commentId);
            social_action_finish($result, !empty($result['active']) ? 'Cevap kabul edilen çözüm seçildi.' : 'Çözüm işareti kaldırıldı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'solution_comment_id' => (int)($result['solution_comment_id'] ?? 0),
            ]);

        case 'gonderi_takip':
            $result = social_toggle_watch_post($socialUser, $postId);
            social_action_finish($result, !empty($result['active']) ? 'Konu takip ediliyor; yeni yorumlarda bildirim alacaksın.' : 'Konu takibi bırakıldı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'count' => (int)($result['count'] ?? 0),
            ]);

        case 'konu_takip':
            $mode = (string)($_POST['mode'] ?? 'watch') === 'mute' ? 'mute' : 'watch';
            $result = social_toggle_watch_topic($socialUser, (string)($_POST['topic_slug'] ?? ''), $mode);
            $message = $mode === 'mute'
                ? (!empty($result['muted']) ? 'Konu sessize alındı.' : 'Konu sessizden çıkarıldı.')
                : (!empty($result['active']) ? 'Konu takip ediliyor.' : 'Konu takibi bırakıldı.');
            social_action_finish($result, $message, $return, $ajax, [
                'active' => !empty($result['active']),
                'muted' => !empty($result['muted']),
            ]);

        case 'konu_sessiz':
            $result = social_toggle_watch_topic($socialUser, (string)($_POST['topic_slug'] ?? ''), 'mute');
            social_action_finish($result, !empty($result['muted']) ? 'Konu sessize alındı.' : 'Konu sessizden çıkarıldı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'muted' => !empty($result['muted']),
            ]);

        case 'etiket_sessiz':
            $result = social_toggle_muted_tag($socialUser, (string)($_POST['tag'] ?? ''));
            social_action_finish($result, !empty($result['muted']) ? 'Etiket sessize alındı.' : 'Etiket sessizden çıkarıldı.', $return, $ajax, [
                'active' => !empty($result['active']),
                'muted' => !empty($result['muted']),
                'tag' => (string)($result['tag'] ?? ''),
                'count' => (int)($result['count'] ?? 0),
            ]);

        case 'okundu_tumu':
            $result = social_mark_all_posts_read($socialUser);
            social_action_finish($result, 'Akıştaki konular okundu işaretlendi.', $return, $ajax, ['count' => (int)($result['count'] ?? 0)]);

        case 'onizleme':
            $previewBody = social_clean_text((string)($_POST['body'] ?? ''), 5000);
            social_action_finish(['errors' => []], 'Önizleme hazır.', $return, true, [
                'html' => $previewBody !== '' ? social_render_text($previewBody) : '<p class="muted">Önizleme için bir şeyler yaz.</p>',
            ]);

        case 'yaziyor':
            $typingType = (string)($_POST['type'] ?? '');
            if ($typingType === 'chat') {
                $channel = social_chat_channel((string)($_POST['channel'] ?? 'genel'));
                if ($channel) {
                    social_touch_presence($socialUser, [
                        'chat_channel' => (string)$channel['slug'],
                        'typing_chat' => !empty($_POST['typing']) ? (string)$channel['slug'] : '',
                    ], true);
                }
            } elseif ($typingType === 'dm') {
                $conversationId = (int)($_POST['conversation_id'] ?? 0);
                $conversation = social_dm_get_conversation($conversationId);
                if ($conversation && in_array((int)$socialUser['id'], $conversation['participants'], true)) {
                    social_touch_presence($socialUser, ['typing_dm' => !empty($_POST['typing']) ? $conversationId : 0], true);
                }
            }
            social_action_finish(['errors' => []], 'Yazıyor durumu güncellendi.', $return, true);

        case 'mesaj_gonder':
            $result = social_dm_send(
                $socialUser,
                (int)($_POST['conversation_id'] ?? 0),
                $targetId,
                (string)($_POST['body'] ?? '')
            );
            if (empty($result['errors']) && (int)($result['conversation_id'] ?? 0) > 0) {
                $return = 'mesajlar.php?sohbet=' . (int)$result['conversation_id'] . '#mesaj-son';
            }
            social_action_finish($result, 'Mesajın gönderildi.', $return, $ajax, [
                'conversation_id' => (int)($result['conversation_id'] ?? 0),
                'conversation' => $result['conversation'] ?? null,
                'message_item' => $result['message'] ?? null,
                'status' => (string)($result['status'] ?? ''),
            ]);

        case 'mesaj_oku':
            $result = social_dm_mark_read($socialUser, (int)($_POST['conversation_id'] ?? 0), (int)($_POST['message_id'] ?? 0));
            social_action_finish($result, 'Konuşma okundu işaretlendi.', $return, $ajax, [
                'unread_messages' => (int)($result['unread_count'] ?? 0),
                'read_message_id' => (int)($result['read_message_id'] ?? 0),
            ]);

        case 'mesaj_istek':
            $result = social_dm_set_request($socialUser, (int)($_POST['conversation_id'] ?? 0), (string)($_POST['decision'] ?? '') === 'accept');
            social_action_finish($result, ($result['status'] ?? '') === 'accepted' ? 'Mesaj isteği kabul edildi.' : 'Mesaj isteği reddedildi.', $return, $ajax, [
                'status' => (string)($result['status'] ?? ''),
                'active' => ($result['status'] ?? '') === 'accepted',
            ]);

        case 'mesaj_arsiv':
            $archived = (string)($_POST['archived'] ?? '1') !== '0';
            $result = social_dm_set_archived($socialUser, (int)($_POST['conversation_id'] ?? 0), $archived);
            social_action_finish($result, $archived ? 'Konuşma arşivlendi.' : 'Konuşma arşivden çıkarıldı.', $archived ? 'mesajlar.php' : $return, $ajax, [
                'archived' => !empty($result['archived']),
            ]);

        case 'mesaj_sil':
            $result = social_dm_delete_message($socialUser, (int)($_POST['conversation_id'] ?? 0), (int)($_POST['message_id'] ?? 0));
            social_action_finish($result, 'Mesaj silindi.', $return, $ajax, [
                'message_id' => (int)($result['message_id'] ?? 0),
                'status' => (string)($result['status'] ?? ''),
            ]);

        case 'mesaj_gizle':
            $result = social_dm_moderate_message($socialUser, (int)($_POST['conversation_id'] ?? 0), (int)($_POST['message_id'] ?? $targetId), (string)($_POST['status'] ?? 'hidden'));
            social_action_finish($result, 'Şikâyet edilen özel mesaj güncellendi.', $return, $ajax, ['status' => (string)($result['status'] ?? '')]);

        case 'sohbet_gonder':
            $result = social_chat_send($socialUser, (string)($_POST['channel'] ?? 'genel'), (string)($_POST['body'] ?? ''));
            social_touch_presence($socialUser, ['typing_chat' => ''], true);
            social_action_finish($result, 'Sohbet mesajın gönderildi.', $return, $ajax, [
                'message_item' => $result['message'] ?? null,
            ]);

        case 'sohbet_durum':
            $result = social_chat_set_status(
                $socialUser,
                (string)($_POST['channel'] ?? 'genel'),
                (int)($_POST['message_id'] ?? $targetId),
                (string)($_POST['status'] ?? $_POST['action'] ?? 'deleted')
            );
            social_action_finish($result, 'Sohbet mesajı güncellendi.', $return, $ajax, [
                'status' => (string)($result['status'] ?? ''),
                'pinned' => !empty($result['pinned']),
                'message_id' => (int)($_POST['message_id'] ?? $targetId),
            ]);

        case 'gonderi_tasi':
            $result = social_move_post($socialUser, $postId, (string)($_POST['topic_slug'] ?? ''));
            social_action_finish($result, 'Gönderi yeni konusuna taşındı.', $return, $ajax, ['topic_slug' => (string)($result['topic_slug'] ?? '')]);

        case 'uyari_ver':
            $result = social_warn_user(
                $socialUser,
                $targetId,
                (string)($_POST['reason'] ?? ''),
                (int)($_POST['mute_days'] ?? 0)
            );
            social_action_finish($result, 'Kullanıcıya moderasyon uyarısı gönderildi.', $return, $ajax, [
                'warning_count' => (int)(($result['profile'] ?? [])['warning_count'] ?? 0),
                'muted_until' => (int)(($result['profile'] ?? [])['muted_until'] ?? 0),
            ]);

        case 'sustur_kaldir':
            $result = social_clear_mute($socialUser, $targetId);
            social_action_finish($result, 'Kullanıcının susturması kaldırıldı.', $return, $ajax);

        case 'takip':
            $result = social_toggle_follow($socialUser, $targetId);
            social_action_finish($result, !empty($result['following']) ? 'Kullanıcı takip ediliyor.' : 'Takipten çıkıldı.', $return, $ajax, [
                'active' => !empty($result['following']),
            ]);

        case 'engelle':
            $result = social_toggle_block($socialUser, $targetId);
            $nextReturn = !empty($result['blocked']) ? 'index.php' : $return;
            social_action_finish($result, !empty($result['blocked']) ? 'Kullanıcı engellendi.' : 'Kullanıcının engeli kaldırıldı.', $nextReturn, $ajax, [
                'active' => !empty($result['blocked']),
            ]);

        case 'gonderi_sil':
            $result = social_set_post_status($socialUser, $postId, 'deleted');
            // Silinen detay sayfasına geri dönme; moderasyon ekranından gelindiyse orada kal.
            $deleteReturn = str_starts_with($return, '../admin/social.php') ? $return : 'index.php';
            social_action_finish($result, 'Gönderi silindi.', $deleteReturn, $ajax);

        case 'gonderi_onayla':
        case 'gonderi_yayinla':
            $result = social_set_post_status($socialUser, $postId, 'published');
            social_action_finish($result, 'Gönderi yayınlandı.', $return, $ajax);

        case 'gonderi_gizle':
            $result = social_set_post_status($socialUser, $postId, 'hidden');
            social_action_finish($result, 'Gönderi gizlendi.', $return, $ajax);

        case 'gonderi_sabitle':
            $result = social_set_post_flag($socialUser, $postId, 'pinned');
            social_action_finish($result, !empty($result['value']) ? 'Gönderi sabitlendi.' : 'Gönderi sabitten kaldırıldı.', $return, $ajax, ['active' => !empty($result['value'])]);

        case 'gonderi_kilitle':
            $result = social_set_post_flag($socialUser, $postId, 'locked');
            social_action_finish($result, !empty($result['value']) ? 'Konu yorumlara kilitlendi.' : 'Konu kilidi açıldı.', $return, $ajax, ['active' => !empty($result['value'])]);

        case 'yorum_sil':
            $result = social_set_comment_status($socialUser, $postId, $commentId, 'deleted');
            social_action_finish($result, 'Yorum silindi.', $return, $ajax);

        case 'yorum_onayla':
        case 'yorum_yayinla':
            $result = social_set_comment_status($socialUser, $postId, $commentId, 'published');
            social_action_finish($result, 'Yorum yayınlandı.', $return, $ajax);

        case 'yorum_gizle':
            $result = social_set_comment_status($socialUser, $postId, $commentId, 'hidden');
            social_action_finish($result, 'Yorum gizlendi.', $return, $ajax);

        case 'sikayet':
            $result = social_create_report($socialUser, $_POST);
            social_action_finish($result, 'Şikâyetin moderasyon ekibine gönderildi.', $return, $ajax);

        case 'bildirim_okundu':
            social_mark_notifications_read((int)$socialUser['id'], (int)($_POST['notification_id'] ?? 0));
            social_action_finish(['errors' => []], 'Bildirimler okundu işaretlendi.', $return, $ajax, ['count' => 0]);

        case 'bildirim_ac':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            $target = 'bildirimler.php';
            foreach (social_notifications((int)$socialUser['id']) as $notification) {
                if ((int)($notification['id'] ?? 0) === $notificationId) {
                    $target = social_safe_return((string)($notification['url'] ?? $target), $target);
                    break;
                }
            }
            social_mark_notifications_read((int)$socialUser['id'], $notificationId);
            header('Location: ' . $target);
            exit;

        case 'guven':
            $result = social_set_trust($targetId, (int)($_POST['trust_level'] ?? 0), $socialUser);
            social_action_finish($result, 'Kullanıcı güven seviyesi güncellendi.', $return, $ajax);

        default:
            social_action_finish(['errors' => ['Geçersiz sosyal işlem.']], '', $return, $ajax);
    }
} catch (Throwable $exception) {
    error_log('[social action] ' . $exception->getMessage());
    social_action_finish(['errors' => ['İşlem tamamlananamadı. Depolama izinlerini kontrol edip tekrar dene.']], '', $return, $ajax);
}
