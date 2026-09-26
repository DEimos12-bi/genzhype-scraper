<?php
// GenZHype | OUR OWN NUMBERS (owner rule, 2026-09-25): "numbers you collected yourself,
// like a creator's follower count before and after the drama ... Nobody else has them."
// Once a day we read the accounts of the people in our stories and keep the reading. A
// story page shows the change once two readings are 3+ days apart, and the gate counts it
// as the "numbers" strong item (gate_original_value).
//
// Which accounts: the ones in the person's identity links (people_json sameAs, from
// Wikidata), each checked once per story by an AI identity test, because some Wikidata
// matches were namesakes (r175).
//   YouTube    read here through the YouTube API (cs_snapshot), channel checked on its
//              latest video titles (yt_channel_same_person)
//   TikTok     read by the runner (genzhype-repo creator_counts.py: Scrapling over
//   Instagram  CloakBrowser, logged out) and sent to api/creator_counts.php (cs_store_readings),
//              account checked on its display name and bio (cs_check_runner_links).
//              TikTok counts are exact; Instagram's embed page rounds from 10,000 up.
// X needs a session and Facebook links are not collected (entity.php has no P2013).
// History starts the day tracking starts: nothing before it is shown or estimated.

require_once __DIR__ . '/db.php';

const CS_MIN_DAYS = 3;   // [ours] two readings at least this far apart before a change is shown
// how an identity link names the account, per platform
const CS_LINK_RX = ['youtube' => '#youtube\.com/channel/(UC[\w\-]{22})#', 'tiktok' => '#tiktok\.com/@([\w.\-]+)#i',
                    'instagram' => '#instagram\.com/([\w.]+)#i'];
const CS_RUNNER_PLATFORMS = ['tiktok', 'instagram'];   // read by the runner, not the server

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
    // what the runner last saw on each account: the identity check reads it
    $pdo->exec("CREATE TABLE IF NOT EXISTS creator_profiles (
        platform VARCHAR(16) NOT NULL,
        account_id VARCHAR(64) NOT NULL,
        name VARCHAR(190) NULL,
        bio TEXT NULL,
        followers BIGINT NULL,
        read_at DATETIME NOT NULL,
        PRIMARY KEY (platform, account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * The identity links of live stories' people on $platforms, newest story first:
 * [page, platform, account, name, context]. $unchecked: only links with no story_accounts row.
 */
function cs_story_links(PDO $pdo, array $platforms, bool $unchecked = true): array {
    $rows = $pdo->query("SELECT p.id page_id, p.summary, d.primary_kw, d.people_json FROM pages p JOIN dramas d ON d.page_id=p.id
                         WHERE p.type='drama' AND p.status='published' AND d.people_json LIKE '%sameAs%'
                         ORDER BY p.published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $done = $pdo->prepare("SELECT 1 FROM story_accounts WHERE page_id=? AND platform=? AND account_id=?");
    $out = []; $seen = [];
    foreach ($rows as $r) foreach ((array)json_decode((string)$r['people_json'], true) as $pe)
        foreach ((array)($pe['sameAs'] ?? []) as $u) foreach ($platforms as $plat) {
            if (!preg_match(CS_LINK_RX[$plat], (string)$u, $m)) continue;
            $acc = $plat === 'youtube' ? $m[1] : mb_strtolower($m[1]);   // handles are case-blind, channel ids are not
            $k = "{$r['page_id']}|{$plat}|{$acc}";
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            if ($unchecked) { $done->execute([(int)$r['page_id'], $plat, $acc]); if ($done->fetchColumn()) continue; }
            $out[] = ['page' => (int)$r['page_id'], 'platform' => $plat, 'account' => $acc, 'name' => (string)($pe['name'] ?? ''),
                      'context' => trim($r['primary_kw'] . '. ' . $r['summary'])];
        }
    return $out;
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
    $ins = $pdo->prepare("INSERT IGNORE INTO story_accounts (page_id, person, platform, account_id, account_name, verified, why, checked_at)
                          VALUES (?,?,'youtube',?,?,?,?,UTC_TIMESTAMP())");
    $todo = array_slice(cs_story_links($pdo, ['youtube']), 0, $limit);
    $info = cs_youtube_channels(array_column($todo, 'account'));
    foreach ($todo as $t) {
        if (time() - $t0 > $maxSecs) break;
        $out['checked']++;
        $c = $info[$t['account']] ?? null;
        if (!$c) { $ins->execute([$t['page'], $t['name'], $t['account'], null, 0, 'channel not found by the YouTube API']); $out['refused']++; continue; }
        $item = ['id' => ['channelId' => $t['account']], 'snippet' => ['title' => $c['title'], 'description' => $c['desc']]];
        $same = yt_channel_same_person($item, (int)($c['subs'] ?? 0), $t['name'], $t['context']);
        if ($same === null) { $out['unanswered']++; continue; }
        $ins->execute([$t['page'], $t['name'], $t['account'], $c['title'], (int)$same, $same ? 'identity check passed' : 'identity check: not this person']);
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

/** The runner's work list: every TikTok/Instagram account of a live story's people, unless it was refused on every story that names it. */
function cs_runner_accounts(PDO $pdo): array {
    cs_install($pdo);
    $checked = [];
    foreach ($pdo->query("SELECT page_id, platform, account_id, verified FROM story_accounts") as $r)
        $checked["{$r['page_id']}|{$r['platform']}|{$r['account_id']}"] = (int)$r['verified'];
    $out = [];
    foreach (cs_story_links($pdo, CS_RUNNER_PLATFORMS, false) as $l) {
        if (($checked["{$l['page']}|{$l['platform']}|{$l['account']}"] ?? null) === 0) continue;   // refused on this story
        $out["{$l['platform']}|{$l['account']}"] = ['platform' => $l['platform'], 'handle' => $l['account']];
    }
    return array_values($out);
}

/**
 * Is this TikTok/Instagram account the story's person? Same rule as the YouTube check: the
 * profile must fit what the story says the person does; a name match alone proves nothing.
 * true/false, or null when the AI gave no verdict (not recorded: asked again next time).
 */
function cs_account_same_person(string $platform, string $account, array $prof, string $person, string $context): ?bool {
    require_once __DIR__ . '/ai.php';
    $res = ai_chat([['role' => 'user', 'content' =>
        "A news story says: \"" . mb_substr($context, 0, 900) . "\"\n\n"
        . "Candidate " . ucfirst($platform) . " account @{$account}: display name \"" . (string)$prof['name'] . "\", "
        . number_format((int)$prof['followers']) . " followers.\nBio: \"" . mb_substr((string)$prof['bio'], 0, 400) . "\"\n\n"
        . "Is this the account of the SAME real person as \"{$person}\" in the story (their own or official account)? "
        . "Compare what the story says the person does (job, field, game or scene, country, who they work with) with the account. "
        . "A name match alone proves nothing. Answer \"same\" when the account fits the story's person, \"different\" when it "
        . "clearly belongs to someone else (a namesake, a fan page, a brand), \"unsure\" otherwise. "
        . "STRICT JSON: {\"verdict\": \"same\"|\"different\"|\"unsure\", \"why\": \"<12 words\"}"]],
        AI_READER_ORDER, 0.0, 60, AI_READER_SKIP);
    $j = isset($res['error']) ? null : ai_json($res['content'] ?? '');
    if (!is_array($j) || !in_array($j['verdict'] ?? null, ['same', 'different', 'unsure'], true)) return null;
    return $j['verdict'] === 'same';
}

/**
 * The runner's readings (api/creator_counts.php): each account's profile and today's count.
 * Readings of unchecked accounts are kept; only verified links are shown.
 */
function cs_store_readings(PDO $pdo, array $rows): array {
    cs_install($pdo);
    $out = ['read' => 0, 'saved' => 0, 'errors' => 0];
    $prof = $pdo->prepare("REPLACE INTO creator_profiles (platform, account_id, name, bio, followers, read_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())");
    $stat = $pdo->prepare("INSERT IGNORE INTO creator_stats (platform, account_id, taken_on, followers, views, videos) VALUES (?,?,UTC_DATE(),?,NULL,?)");
    foreach ($rows as $r) {
        $plat = (string)($r['platform'] ?? '');
        $acc = mb_strtolower(trim((string)($r['handle'] ?? '')));
        if (!in_array($plat, CS_RUNNER_PLATFORMS, true) || !preg_match('/^[\w.\-]{1,64}$/', $acc)) continue;
        if (!isset($r['followers']) || !is_numeric($r['followers'])) { $out['errors']++; continue; }
        $out['read']++;
        $prof->execute([$plat, $acc, mb_substr((string)($r['name'] ?? ''), 0, 190), mb_substr((string)($r['bio'] ?? ''), 0, 2000), (int)$r['followers']]);
        $stat->execute([$plat, $acc, (int)$r['followers'], isset($r['posts']) && is_numeric($r['posts']) ? (int)$r['posts'] : null]);
        $out['saved'] += $stat->rowCount();
    }
    return $out;
}

/**
 * The identity check for story links to accounts the runner has read, none started after
 * $maxSecs; the rest wait for the next delivery. Run after the reply to the runner: the checks
 * take minutes and the web front cut the reply on 2026-09-26 (the runner saw curl 22 although
 * everything was stored).
 */
function cs_check_runner_links(PDO $pdo, int $maxSecs = 200): array {
    $t0 = time();
    $out = ['linked' => 0, 'refused' => 0, 'unanswered' => 0, 'left' => 0];
    $get = $pdo->prepare("SELECT name, bio, followers FROM creator_profiles WHERE platform=? AND account_id=?");
    $ins = $pdo->prepare("INSERT IGNORE INTO story_accounts (page_id, person, platform, account_id, account_name, verified, why, checked_at)
                          VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    foreach (cs_story_links($pdo, CS_RUNNER_PLATFORMS) as $l) {
        $get->execute([$l['platform'], $l['account']]);
        $p = $get->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;                                     // not read yet
        if (time() - $t0 > $maxSecs) { $out['left']++; continue; }
        $same = cs_account_same_person($l['platform'], $l['account'], $p, $l['name'], $l['context']);
        if ($same === null) { $out['unanswered']++; continue; }
        $ins->execute([$l['page'], $l['name'], $l['platform'], $l['account'], mb_substr((string)$p['name'], 0, 190), (int)$same,
                       $same ? 'identity check passed' : 'identity check: not this person']);
        $same ? $out['linked']++ : $out['refused']++;
    }
    return $out;
}

/**
 * What a story can show: per linked account, the first and latest readings when they are
 * CS_MIN_DAYS or more apart. [$pageId => [[platform, person, account_name, from, to, from_followers, ...]]]
 * for the given pages ([] = all).
 */
function cs_numbers(PDO $pdo, array $pageIds = []): array {
    try {
        $where = $pageIds ? 'AND a.page_id IN (' . implode(',', array_map('intval', $pageIds)) . ')' : '';
        $rows = $pdo->query("SELECT a.page_id, a.platform, a.person, a.account_name, a.account_id,
                   f.taken_on from_on, f.followers from_f, f.views from_v, l.taken_on to_on, l.followers to_f, l.views to_v
            FROM story_accounts a
            JOIN (SELECT platform, account_id, MIN(taken_on) mn, MAX(taken_on) mx FROM creator_stats GROUP BY platform, account_id) r
                 ON r.platform=a.platform AND r.account_id=a.account_id
            JOIN creator_stats f ON f.platform=a.platform AND f.account_id=a.account_id AND f.taken_on=r.mn
            JOIN creator_stats l ON l.platform=a.platform AND l.account_id=a.account_id AND l.taken_on=r.mx
            WHERE a.verified=1 AND DATEDIFF(r.mx, r.mn) >= " . CS_MIN_DAYS . " {$where}")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }   // tables not created yet
    $out = [];
    foreach ($rows as $r) $out[(int)$r['page_id']][] = $r;
    return $out;
}

/** One reader-facing sentence for a tracked account, with how exact the platform's count is. */
function cs_sentence(array $r): string {
    $plat = (string)($r['platform'] ?? 'youtube');
    $fmt = function (?int $n) use ($plat): string {
        if ($n === null) return '';
        if ($plat === 'tiktok') return number_format($n);   // TikTok gives the exact count
        if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . ' million';
        if ($n >= 1000) return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
        return (string)$n;
    };
    $d = fn(string $x) => date('F j, Y', strtotime($x));
    $what = ['youtube' => 'YouTube channel', 'tiktok' => 'TikTok account', 'instagram' => 'Instagram account'][$plat] ?? 'account';
    $unit = $plat === 'youtube' ? 'subscribers' : 'followers';
    $label = $plat === 'youtube' ? (string)$r['account_name'] : '@' . $r['account_id'];
    $s = "{$r['person']}'s {$what}" . ($label !== '' && $label !== '@' ? " ({$label})" : '');
    if ($r['from_f'] !== null && $r['to_f'] !== null)
        $s .= " had {$fmt((int)$r['from_f'])} {$unit} on {$d($r['from_on'])} and {$fmt((int)$r['to_f'])} on {$d($r['to_on'])}";
    else
        $s .= " was tracked from {$d($r['from_on'])} to {$d($r['to_on'])}";
    if ($r['from_v'] !== null && $r['to_v'] !== null) {
        $gain = (int)$r['to_v'] - (int)$r['from_v'];
        $s .= $gain >= 0 ? ", and its videos gained {$fmt($gain)} views in that time" : '';
    }
    $note = ['youtube' => ' YouTube shows subscriber counts rounded.', 'instagram' => ' Instagram shows follower counts from 10,000 up rounded.'][$plat] ?? '';
    return $s . '.' . $note;
}
