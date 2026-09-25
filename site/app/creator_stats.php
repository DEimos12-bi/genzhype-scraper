<?php
// GenZHype | OUR OWN NUMBERS (owner rule, 2026-09-25): "numbers you collected yourself,
// like a creator's follower count before and after the drama ... Nobody else has them."
// Once a day we read the YouTube channel of each person in our stories and keep the
// reading. A story page shows the change once two readings are 3+ days apart, and the
// gate counts it as the "numbers" strong item (gate_original_value).
//
// Which channel: the exact YouTube channel id in the person's identity links
// (people_json sameAs, from Wikidata: 97 links, 64 people on 2026-09-25), checked
// once per story by the same AI identity test the covers use (yt_channel_same_person),
// because some Wikidata matches were namesakes (r175). X, Instagram and TikTok follower
// counts need access we do not have (paid API, a throttled session, a blocked server),
// and Twitch needs app credentials; they can join the same table later.
// History starts the day tracking starts: nothing before it is shown or estimated.

require_once __DIR__ . '/db.php';

const CS_MIN_DAYS = 3;   // [ours] two readings at least this far apart before a change is shown

/** Idempotent; run outside a transaction (CREATE TABLE commits one on MariaDB, r151). */
function cs_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS creator_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(16) NOT NULL,
        account_id VARCHAR(64) NOT NULL,
        taken_on DATE NOT NULL,
        followers BIGINT NULL,
        views BIGINT NULL,
        videos INT NULL,
        UNIQUE KEY one_a_day (platform, account_id, taken_on)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_accounts (
        page_id INT NOT NULL,
        person VARCHAR(190) NOT NULL,
        platform VARCHAR(16) NOT NULL,
        account_id VARCHAR(64) NOT NULL,
        account_name VARCHAR(190) NULL,
        verified TINYINT NOT NULL DEFAULT 0,
        why VARCHAR(300) NULL,
        checked_at DATETIME NOT NULL,
        UNIQUE KEY one_link (page_id, platform, account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Channel facts for up to 50 ids per YouTube API call (1 quota unit each). [id => [...]] */
function cs_youtube_channels(array $ids): array {
    $key = (string)($GLOBALS['CONFIG']['youtube_key'] ?? '');
    if ($key === '' || !$ids) return [];
    require_once __DIR__ . '/fetch_sources.php';
    $out = [];
    foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
        $j = json_decode((string)fs_http_get('https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&maxResults=50&id='
            . implode(',', $chunk) . '&key=' . $key, 20), true);
        foreach ((array)($j['items'] ?? []) as $it) {
            $st = (array)($it['statistics'] ?? []);
            $out[(string)$it['id']] = [
                'title'  => (string)($it['snippet']['title'] ?? ''),
                'desc'   => (string)($it['snippet']['description'] ?? ''),
                'subs'   => !empty($st['hiddenSubscriberCount']) ? null : (isset($st['subscriberCount']) ? (int)$st['subscriberCount'] : null),
                'views'  => isset($st['viewCount']) ? (int)$st['viewCount'] : null,
                'videos' => isset($st['videoCount']) ? (int)$st['videoCount'] : null,
            ];
        }
    }
    return $out;
}

/**
 * Link the people of live stories to their YouTube channels, each link checked once per
 * story. At most $limit identity checks per run, none started after $maxSecs (each check
 * reads the channel's latest videos and asks an AI: about 30 s). A check with no verdict is
 * not recorded, so the next run asks again.
 */
function cs_link(PDO $pdo, int $limit = 20, int $maxSecs = 240): array {
    cs_install($pdo);
    require_once __DIR__ . '/drama_image.php';
    $t0 = time();
    $out = ['checked' => 0, 'linked' => 0, 'refused' => 0, 'unanswered' => 0];
    $rows = $pdo->query("SELECT p.id page_id, p.summary, d.primary_kw, d.people_json FROM pages p JOIN dramas d ON d.page_id=p.id
                         WHERE p.type='drama' AND p.status='published' AND d.people_json LIKE '%youtube.com/channel/%'
                         ORDER BY p.published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $done = $pdo->prepare("SELECT 1 FROM story_accounts WHERE page_id=? AND platform='youtube' AND account_id=?");
    $ins = $pdo->prepare("INSERT IGNORE INTO story_accounts (page_id, person, platform, account_id, account_name, verified, why, checked_at)
                          VALUES (?,?,'youtube',?,?,?,?,UTC_TIMESTAMP())");
    $todo = [];
    foreach ($rows as $r) foreach ((array)json_decode((string)$r['people_json'], true) as $pe)
        foreach ((array)($pe['sameAs'] ?? []) as $u) if (preg_match('#youtube\.com/channel/(UC[\w\-]{22})#', (string)$u, $m)) {
            $done->execute([(int)$r['page_id'], $m[1]]);
            if (!$done->fetchColumn()) $todo[] = ['page' => (int)$r['page_id'], 'cid' => $m[1], 'name' => (string)($pe['name'] ?? ''),
                                                  'context' => trim($r['primary_kw'] . '. ' . $r['summary'])];
        }
    $todo = array_slice($todo, 0, $limit);
    $info = cs_youtube_channels(array_column($todo, 'cid'));
    foreach ($todo as $t) {
        if (time() - $t0 > $maxSecs) break;
        $out['checked']++;
        $c = $info[$t['cid']] ?? null;
        if (!$c) { $ins->execute([$t['page'], $t['name'], $t['cid'], null, 0, 'channel not found by the YouTube API']); $out['refused']++; continue; }
        $item = ['id' => ['channelId' => $t['cid']], 'snippet' => ['title' => $c['title'], 'description' => $c['desc']]];
        $same = yt_channel_same_person($item, (int)($c['subs'] ?? 0), $t['name'], $t['context']);
        if ($same === null) { $out['unanswered']++; continue; }
        $ins->execute([$t['page'], $t['name'], $t['cid'], $c['title'], (int)$same, $same ? 'identity check passed' : 'identity check: not this person']);
        $same ? $out['linked']++ : $out['refused']++;
    }
    return $out;
}

/** Today's reading of every linked channel (one per channel per day). */
function cs_snapshot(PDO $pdo): array {
    cs_install($pdo);
    $ids = $pdo->query("SELECT DISTINCT account_id FROM story_accounts WHERE platform='youtube' AND verified=1")->fetchAll(PDO::FETCH_COLUMN);
    $info = cs_youtube_channels($ids);
    $ins = $pdo->prepare("INSERT IGNORE INTO creator_stats (platform, account_id, taken_on, followers, views, videos) VALUES ('youtube',?,UTC_DATE(),?,?,?)");
    $n = 0;
    foreach ($info as $cid => $c) { $ins->execute([$cid, $c['subs'], $c['views'], $c['videos']]); $n += $ins->rowCount(); }
    return ['channels' => count($ids), 'read' => count($info), 'saved' => $n];
}

/**
 * What a story can show: per linked channel, the first and latest readings when they are
 * CS_MIN_DAYS or more apart. [$pageId => [[person, account_name, from, to, from_followers, ...]]]
 * for the given pages ([] = all).
 */
function cs_numbers(PDO $pdo, array $pageIds = []): array {
    try {
        $where = $pageIds ? 'AND a.page_id IN (' . implode(',', array_map('intval', $pageIds)) . ')' : '';
        $rows = $pdo->query("SELECT a.page_id, a.person, a.account_name, a.account_id,
                   f.taken_on from_on, f.followers from_f, f.views from_v, l.taken_on to_on, l.followers to_f, l.views to_v
            FROM story_accounts a
            JOIN (SELECT account_id, MIN(taken_on) mn, MAX(taken_on) mx FROM creator_stats WHERE platform='youtube' GROUP BY account_id) r ON r.account_id=a.account_id
            JOIN creator_stats f ON f.platform='youtube' AND f.account_id=a.account_id AND f.taken_on=r.mn
            JOIN creator_stats l ON l.platform='youtube' AND l.account_id=a.account_id AND l.taken_on=r.mx
            WHERE a.platform='youtube' AND a.verified=1 AND DATEDIFF(r.mx, r.mn) >= " . CS_MIN_DAYS . " {$where}")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }   // tables not created yet
    $out = [];
    foreach ($rows as $r) $out[(int)$r['page_id']][] = $r;
    return $out;
}

/** One reader-facing sentence for a tracked channel. */
function cs_sentence(array $r): string {
    $fmt = function (?int $n): string {
        if ($n === null) return '';
        if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . ' million';
        if ($n >= 1000) return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
        return (string)$n;
    };
    $d = fn(string $x) => date('F j, Y', strtotime($x));
    $s = "{$r['person']}'s YouTube channel" . ($r['account_name'] ? " ({$r['account_name']})" : '');
    if ($r['from_f'] !== null && $r['to_f'] !== null)
        $s .= " had {$fmt((int)$r['from_f'])} subscribers on {$d($r['from_on'])} and {$fmt((int)$r['to_f'])} on {$d($r['to_on'])}";
    else
        $s .= " was tracked from {$d($r['from_on'])} to {$d($r['to_on'])}";
    if ($r['from_v'] !== null && $r['to_v'] !== null) {
        $gain = (int)$r['to_v'] - (int)$r['from_v'];
        $s .= $gain >= 0 ? ", and its videos gained {$fmt($gain)} views in that time" : '';
    }
    return $s . '.';
}
