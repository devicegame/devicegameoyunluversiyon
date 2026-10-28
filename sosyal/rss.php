<?php
require_once __DIR__ . '/_common.php';

$mode = (string)($_GET['tur'] ?? 'gonderiler');
if (!in_array($mode, ['gonderiler', 'yorumlar'], true)) $mode = 'gonderiler';
$topicSlug = social_slugify((string)($_GET['konu'] ?? ''));
$topic = $topicSlug !== '' ? social_topic($topicSlug, false) : null;
$invalidTopic = $topicSlug !== '' && !$topic;
$authorUsername = trim((string)($_GET['kullanici'] ?? ''));
$authorProfile = $authorUsername !== '' ? social_profile_by_username(ltrim($authorUsername, '@'), false) : null;
$authorId = $authorProfile ? (int)$authorProfile['user_id'] : null;
$items = [];
$latestTimestamp = time();

if (!$invalidTopic && ($authorUsername === '' || $authorProfile)) {
    if ($mode === 'yorumlar') {
        $commentItems = [];
        $sourcePosts = $authorProfile ? [] : social_list_posts([
            'topic' => $topicSlug,
            'sort' => 'activity',
            'per_page' => 30,
        ], null)['items'];
        foreach ($sourcePosts as $post) {
            foreach (social_comments((int)$post['id'], null) as $comment) {
                $nested = [$comment];
                foreach ((array)($comment['replies'] ?? []) as $reply) $nested[] = $reply;
                foreach ($nested as $commentItem) {
                    if (($commentItem['status'] ?? '') !== 'published') continue;
                    $commentItems[] = [
                        'post' => $post,
                        'comment' => $commentItem,
                        'created_at' => (int)$commentItem['created_at'],
                    ];
                }
            }
        }
        if ($authorProfile) {
            foreach (social_user_comments((int)$authorProfile['user_id'], null, 50) as $commentItem) {
                if (($commentItem['status'] ?? '') !== 'published') continue;
                $commentPost = social_get_post((int)$commentItem['post_id']);
                if (!$commentPost || ($topicSlug !== '' && (string)$commentPost['topic_slug'] !== $topicSlug)) continue;
                $commentItems[] = [
                    'post' => $commentPost,
                    'comment' => $commentItem,
                    'created_at' => (int)$commentItem['created_at'],
                ];
            }
        }
        usort($commentItems, static fn(array $a, array $b): int => (int)$b['created_at'] <=> (int)$a['created_at']);
        $seenComments = [];
        foreach (array_slice($commentItems, 0, 80) as $row) {
            $key = (int)$row['comment']['post_id'] . ':' . (int)$row['comment']['id'];
            if (isset($seenComments[$key])) continue;
            $seenComments[$key] = true;
            $items[] = $row;
            $latestTimestamp = max($latestTimestamp, (int)$row['created_at']);
            if (count($items) >= 50) break;
        }
    } else {
        $posts = social_list_posts([
            'topic' => $topicSlug,
            'author_id' => $authorId,
            'sort' => 'activity',
            'per_page' => 50,
        ], null)['items'];
        foreach ($posts as $post) {
            $items[] = ['post' => $post, 'comment' => null, 'created_at' => (int)($post['last_activity_at'] ?? $post['created_at'])];
            $latestTimestamp = max($latestTimestamp, (int)($post['last_activity_at'] ?? $post['created_at']));
        }
    }
}

$channelTitle = 'DeviceGame DG Social';
if ($topic) $channelTitle .= ' — ' . $topic['name'];
if ($authorProfile) $channelTitle .= ' — @' . $authorProfile['username'];
if ($mode === 'yorumlar') $channelTitle .= ' yorumları';
$channelLink = function_exists('dg_absolute_url') ? dg_absolute_url('sosyal/index.php' . ($topic ? '?konu=' . rawurlencode($topicSlug) : '')) : 'index.php';
$selfParams = array_filter(['tur' => $mode !== 'gonderiler' ? $mode : '', 'konu' => $topicSlug, 'kullanici' => $authorUsername], static fn($value): bool => $value !== '');
$selfUrl = function_exists('dg_absolute_url') ? dg_absolute_url('sosyal/rss.php' . ($selfParams ? '?' . http_build_query($selfParams) : '')) : 'rss.php';

if (!headers_sent()) {
    if ($invalidTopic || ($authorUsername !== '' && !$authorProfile)) http_response_code(404);
    header('Content-Type: application/rss+xml; charset=UTF-8');
    header('X-Robots-Tag: noindex,follow');
    header('Cache-Control: public, max-age=300');
}
$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= $xml($channelTitle) ?></title>
    <link><?= $xml($channelLink) ?></link>
    <description><?= $xml($topic ? (string)$topic['description'] : 'DeviceGame oyuncu topluluğunun forum ve sosyal akışı.') ?></description>
    <language>tr-TR</language>
    <lastBuildDate><?= $xml(gmdate(DATE_RSS, $latestTimestamp)) ?></lastBuildDate>
    <atom:link href="<?= $xml($selfUrl) ?>" rel="self" type="application/rss+xml" />
<?php foreach ($items as $row): ?>
<?php
$post = $row['post'];
$comment = $row['comment'];
$itemUrl = function_exists('dg_absolute_url')
    ? dg_absolute_url('sosyal/' . social_post_url((int)$post['id'], $comment ? (int)$comment['id'] : 0))
    : social_post_url((int)$post['id'], $comment ? (int)$comment['id'] : 0);
$itemTitle = $comment ? (string)$comment['author_display'] . ' — ' . (string)$post['title'] : (string)$post['title'];
$itemDescription = $comment ? social_excerpt((string)($comment['body'] ?? ''), 320) : social_excerpt((string)($post['body'] ?? $post['excerpt'] ?? ''), 320);
$itemGuid = $itemUrl;
?>
    <item>
      <title><?= $xml($itemTitle) ?></title>
      <link><?= $xml($itemUrl) ?></link>
      <guid isPermaLink="true"><?= $xml($itemGuid) ?></guid>
      <pubDate><?= $xml(gmdate(DATE_RSS, (int)$row['created_at'])) ?></pubDate>
      <description><?= $xml($itemDescription) ?></description>
      <category><?= $xml((string)($socialTopicMap[(string)$post['topic_slug']]['name'] ?? $post['topic_slug'])) ?></category>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
