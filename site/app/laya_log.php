<?php
// GenZHype | WHAT LAYA WILL LEARN FROM (owner 2026-09-28: "start logging for Laya: my approve/reject decisions in Human
// check with the reason, and for every new story the numbers at publish (source-post reach, creator size, outlets
// covering it) plus results after 2 and 7 days"). Logging only: nothing here decides anything. Laya is trained on GitHub
// runners, never on this host (owner rule); this table is what an export will hand it.
//   kind 'human_check': one row per decision (approve / reject), with the reason typed in admin > Human check
//   kind 'story':       one row per new-pipeline story when it first goes live; day2 / day7 filled by the hourly tick

require_once __DIR__ . '/db.php';

/** Outside a transaction (DDL commits an open one, r151); only when missing. */
function laya_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if ($pdo->query("SHOW TABLES LIKE 'laya_log'")->fetchColumn()) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS laya_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(20) NOT NULL, page_id INT UNSIGNED NOT NULL,
                data MEDIUMTEXT NOT NULL, day2 TEXT NULL, day7 TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
                KEY idx_kind_page (kind, page_id), KEY idx_kind_created (kind, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** A Human check decision: what the owner decided, why, and what he was looking at. */
function laya_log_decision(PDO $pdo, int $pageId, string $decision, string $reason, string $reviewer): void {
    try {
        laya_install($pdo);
        require_once __DIR__ . '/human_review.php';
        $p = $pdo->query("SELECT p.h1, p.summary, p.path, p.status, d.lane FROM pages p LEFT JOIN dramas d ON d.page_id=p.id WHERE p.id=" . $pageId)->fetch(PDO::FETCH_ASSOC) ?: [];
        $data = ['decision' => $decision, 'reason' => trim($reason), 'reviewer' => $reviewer, 'title' => (string)($p['h1'] ?? ''), 'summary' => (string)($p['summary'] ?? ''),
                 'path' => (string)($p['path'] ?? ''), 'lane' => (string)($p['lane'] ?? ''), 'flagged_for' => hr_page_reasons($pdo, $pageId)];
        $pdo->prepare("INSERT INTO laya_log (kind, page_id, data, created_at) VALUES ('human_check', ?, ?, UTC_TIMESTAMP())")
            ->execute([$pageId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) { error_log('laya_log_decision: ' . $e->getMessage()); }
}

/** Likes / views of a story's source posts, as far as each platform serves them (X: likes and replies; YouTube: views). */
function laya_post_reach(string $provider, string $html): ?array {
    global $CONFIG;
    if ($provider === 'twitter' && preg_match('#/status(?:es)?/(\d+)#', $html, $m)) {
        require_once __DIR__ . '/post_cards.php';
        $t = pc_syndication($m[1]);
        return $t ? ['platform' => 'x', 'likes' => $t['likes'], 'replies' => $t['replies'], 'views' => null] : null;
    }
    if ($provider === 'youtube' && preg_match('/videoid="([A-Za-z0-9_-]{11})"/', $html, $m) && ($key = (string)($CONFIG['youtube_key'] ?? '')) !== '') {
        $j = json_decode((string)@file_get_contents('https://www.googleapis.com/youtube/v3/videos?part=statistics&id=' . $m[1] . '&key=' . urlencode($key), false,
                          stream_context_create(['http' => ['timeout' => 8]])), true);
        $s = $j['items'][0]['statistics'] ?? null;
        return $s ? ['platform' => 'youtube', 'views' => (int)($s['viewCount'] ?? 0), 'likes' => isset($s['likeCount']) ? (int)$s['likeCount'] : null, 'replies' => null] : null;
    }
    return null;   // TikTok, Reddit, Instagram: no count we can read without an account
}

/** A new-pipeline story going live: the numbers it had at that moment. Once per page. */
function laya_log_publish(PDO $pdo, int $pageId): void {
    try {
        require_once __DIR__ . '/sitemap_lib.php';
        $p = $pdo->query("SELECT p.id, p.type, p.h1, p.path, p.robots, p.created_at, p.published_at, d.id did, d.lane FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=" . $pageId)->fetch(PDO::FETCH_ASSOC);
        if (!$p || (string)$p['created_at'] < NEW_PIPELINE_SINCE) return;
        laya_install($pdo);
        if ($pdo->query("SELECT COUNT(*) FROM laya_log WHERE kind='story' AND page_id=" . $pageId)->fetchColumn()) return;
        $did = (int)$p['did'];
        $posts = [];
        foreach ($pdo->query("SELECT embed_provider, embed_html FROM events WHERE drama_id={$did} AND embed_provider IS NOT NULL AND embed_provider<>'' LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $e)
            $posts[] = ['provider' => (string)$e['embed_provider']] + (laya_post_reach((string)$e['embed_provider'], (string)$e['embed_html']) ?? ['views' => null, 'likes' => null]);
        $outlets = []; $srcDates = [];
        foreach ($pdo->query("SELECT DISTINCT s.domain, s.publisher, s.published_on FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id={$did}")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $host = preg_replace('/^www\./', '', (string)$s['domain']);
            if ($s['published_on']) $srcDates[] = (string)$s['published_on'];
            if ($host !== '' && !preg_match('/(^|\.)(x|twitter|tiktok|youtube|youtu|instagram|facebook|threads|reddit|twitch|kick|bsky)\.(com|be|tv|net|app)$/', $host)) $outlets[$host] = (string)$s['publisher'];
        }
        $creators = [];
        foreach ($pdo->query("SELECT a.person, a.platform, (SELECT c.followers FROM creator_stats c WHERE c.platform=a.platform AND c.account_id=a.account_id ORDER BY c.taken_on DESC LIMIT 1) followers
                              FROM story_accounts a WHERE a.page_id={$pageId}")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $creators[] = ['person' => (string)$c['person'], 'platform' => (string)$c['platform'], 'followers' => $c['followers'] !== null ? (int)$c['followers'] : null];
        $q = json_decode((string)$pdo->query("SELECT verdict FROM ai_reviews WHERE page_id={$pageId} AND stage='quality' ORDER BY id DESC LIMIT 1")->fetchColumn(), true) ?: [];
        $v = $pdo->query("SELECT passed FROM ai_reviews WHERE page_id={$pageId} AND stage='verify' ORDER BY id DESC LIMIT 1")->fetchColumn();
        sort($srcDates);
        $data = ['title' => (string)$p['h1'], 'path' => (string)$p['path'], 'lane' => (string)$p['lane'], 'open_to_google' => $p['robots'] === 'index',
                 'published_at' => (string)$p['published_at'], 'events' => (int)$pdo->query("SELECT COUNT(*) FROM events WHERE drama_id={$did} AND video_only=0")->fetchColumn(),
                 'source_posts' => $posts, 'creators' => $creators, 'outlets' => array_values($outlets), 'outlet_count' => count($outlets),
                 'first_source' => $srcDates[0] ?? null, 'newest_source' => $srcDates ? end($srcDates) : null,
                 'editor' => $q['scores'] ?? null, 'fact_check_passed' => $v === false ? null : (int)$v === 1];
        $pdo->prepare("INSERT INTO laya_log (kind, page_id, data, created_at) VALUES ('story', ?, ?, UTC_TIMESTAMP())")
            ->execute([$pageId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) { error_log('laya_log_publish: ' . $e->getMessage()); }
}

/** How a page did from $from to $to: Google's index state, impressions, clicks, position (Search Console) and views (GA4). */
function laya_results(PDO $pdo, int $pageId, string $path, string $from, string $to): array {
    require_once __DIR__ . '/ga4.php';
    $out = ['from' => $from, 'to' => $to, 'impressions' => null, 'clicks' => null, 'position' => null, 'views' => null, 'index' => null];
    $url = 'https://genzhype.com' . $path;
    if ($tok = ga4_access_token()) {
        $ch = curl_init('https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode('https://genzhype.com/') . '/searchAnalytics/query');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['startDate' => $from, 'endDate' => $to, 'dimensions' => ['page'],
                'dimensionFilterGroups' => [['filters' => [['dimension' => 'page', 'operator' => 'equals', 'expression' => $url]]]]])]);
        $j = json_decode((string)curl_exec($ch), true) ?: [];
        curl_close($ch);
        $row = $j['rows'][0] ?? null;
        $out['impressions'] = (int)($row['impressions'] ?? 0); $out['clicks'] = (int)($row['clicks'] ?? 0);
        $out['position'] = $row ? round((float)$row['position'], 1) : null;
        $ins = gsc_inspect($pdo, $pageId, $url);
        $out['index'] = $ins['coverage'] ?? null;   // gsc_inspect (ga4.php): "Submitted and indexed", "Crawled - currently not indexed", ...
    }
    $g = ga4_report(['dateRanges' => [['startDate' => $from, 'endDate' => $to]], 'dimensions' => [['name' => 'pagePath']], 'metrics' => [['name' => 'screenPageViews']],
                     'dimensionFilter' => ['filter' => ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'EXACT', 'value' => $path]]]]);
    if (empty($g['error'])) $out['views'] = (int)($g['rows'][0][1][0] ?? 0);
    return $out;
}

/** The hourly tick: stories 2 and 7 days after going live get their results (a few per run). */
function laya_followups(PDO $pdo, int $max = 6): int {
    laya_install($pdo);
    $n = 0;
    foreach (['day2' => 2, 'day7' => 7] as $col => $days) {
        $rows = $pdo->query("SELECT l.id, l.page_id, l.created_at, p.path FROM laya_log l JOIN pages p ON p.id=l.page_id
                             WHERE l.kind='story' AND l.{$col} IS NULL AND l.created_at <= UTC_TIMESTAMP() - INTERVAL {$days} DAY ORDER BY l.id LIMIT {$max}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $from = substr((string)$r['created_at'], 0, 10);
            $res = laya_results($pdo, (int)$r['page_id'], (string)$r['path'], $from, gmdate('Y-m-d', strtotime($from . " +{$days} days")));
            $pdo->prepare("UPDATE laya_log SET {$col}=?, updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([json_encode($res), (int)$r['id']]);
            $n++;
        }
    }
    return $n;
}
