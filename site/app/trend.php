<?php
/**
 * THE TREND DETECTOR (owner 2026-10-01), memes and slang first; drama and gaming only after it works here.
 *
 * What was wrong (traced on live examples, 2026-10-01):
 *   - the listener's "burst" was "3 to 5 different people used the word, ever": every common word passed
 *     ("gamepad", "stamina", "rockets" became pages or glossary entries);
 *   - the same cached posts were counted again on every hourly run ("gamepad" in 76 posts was a few posts);
 *   - "new" was measured from the day OUR listener first heard a word, and the listener started on 2026-08-30;
 *   - demand was Wikipedia views over 3 months, which rewards old words and gives a week-old meme zero.
 *
 * What a trend is now (the owner's rules):
 *   1. RISING: its use in the last 3 days is far above its own average over the weeks before;
 *   2. NEW: not in our signals a month ago, and not an ordinary dictionary or gaming word;
 *   3. CROSS-CHECK: seen on 2+ platforms in those same days.
 * Each measure returns numbers (tr_measure), so the trend score for every idea (the owner's next step) reuses them.
 *
 * The day-by-day counts (term_daily) start the day this file went live: until TR_MIN_HISTORY_DAYS of them exist,
 * "rising" cannot be judged and the decision says so ("interim": new + 2 platforms + several people).
 * Switched on by the file app/TREND_ON (cli.php trend on|off); the counts are recorded either way.
 */

const TR_RECENT_DAYS      = 3;     // [owner] its use in the last 3 days...
const TR_BASE_DAYS        = 21;    // [owner] ...against its own average over the past weeks (the 3 weeks before those days)
const TR_RISE_FACTOR      = 3.0;   // [ours] "far above" = 3 times its own 3-day average
const TR_MIN_POSTS        = 5;     // [ours] at least 5 different posts in the 3 days
const TR_MIN_AUTHORS      = 3;     // [ours] by at least 3 different people
const TR_MIN_PLATFORMS    = 2;     // [owner] seen on 2+ platforms in the same days
const TR_NEW_DAYS         = 30;    // [owner] not in our signals a month ago
const TR_MIN_HISTORY_DAYS = 10;    // [ours] days of counts needed before "rising" can be judged at all
const TR_KEEP_DAYS        = 45;    // [ours] how long the daily counts are kept (a month back + the recent days)

function tr_on(): bool { return is_file(__DIR__ . '/TREND_ON'); }

function tr_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    // one row per term and day: how many different posts used it, by how many people, on which platforms, with what reach
    $pdo->exec("CREATE TABLE IF NOT EXISTS term_daily (
        term VARCHAR(80) NOT NULL,
        day DATE NOT NULL,
        posts INT UNSIGNED NOT NULL DEFAULT 0,
        authors INT UNSIGNED NOT NULL DEFAULT 0,
        p_reddit INT UNSIGNED NOT NULL DEFAULT 0,
        p_x INT UNSIGNED NOT NULL DEFAULT 0,
        p_youtube INT UNSIGNED NOT NULL DEFAULT 0,
        p_tiktok INT UNSIGNED NOT NULL DEFAULT 0,
        p_steam INT UNSIGNED NOT NULL DEFAULT 0,
        p_other INT UNSIGNED NOT NULL DEFAULT 0,
        reach BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (term, day), KEY idx_day (day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // a post is counted once, however many hourly runs read the same cached harvest
    $pdo->exec("CREATE TABLE IF NOT EXISTS scout_post_seen (
        h BINARY(16) NOT NULL PRIMARY KEY,
        day DATE NOT NULL, KEY idx_day (day)
    ) ENGINE=InnoDB");
    // is the term an ordinary dictionary word? (Wiktionary, asked once per term)
    $pdo->exec("CREATE TABLE IF NOT EXISTS term_dict (
        term VARCHAR(80) NOT NULL PRIMARY KEY,
        ordinary TINYINT NULL,
        label VARCHAR(120) NULL,
        checked_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** One post's identity: the platform, who wrote it and its first words. */
function tr_post_hash(array $p): string {
    return md5(mb_strtolower((string)($p['platform'] ?? '') . '|' . (string)($p['sub'] ?? '') . '|' . (string)($p['author'] ?? '') . '|'
        . mb_substr(preg_replace('/\s+/u', ' ', (string)($p['text'] ?? '')), 0, 140)), true);
}

/**
 * Add a harvest to the day-by-day counts. A post already counted (the listener re-reads its cache every hour) adds
 * nothing. $tokenize: the listener's own word splitter (scout_tokenize). ['posts' => new posts, 'terms' => terms touched]
 */
function tr_record(PDO $pdo, array $posts, callable $tokenize): array {
    tr_install($pdo);
    $today = gmdate('Y-m-d');
    $mark = $pdo->prepare("INSERT IGNORE INTO scout_post_seen (h, day) VALUES (?, ?)");
    $by = []; $new = 0;
    foreach ($posts as $p) {
        $text = trim((string)($p['text'] ?? ''));
        $auth = mb_strtolower(trim((string)($p['author'] ?? '')));
        $plat = mb_strtolower((string)($p['platform'] ?? ''));
        if ($text === '' || $auth === '' || $plat === '') continue;
        $mark->execute([tr_post_hash($p), $today]);
        if ($mark->rowCount() < 1) continue;   // counted on an earlier run
        $new++;
        $col = in_array($plat, ['reddit', 'x', 'youtube', 'tiktok', 'steam'], true) ? "p_{$plat}" : ($plat === 'twitter' ? 'p_x' : 'p_other');
        $reach = (int)($p['views'] ?? 0) ?: ((int)($p['likes'] ?? 0) + (int)($p['ups'] ?? 0));
        foreach ($tokenize($text) as $term) {
            if (mb_strlen($term) > 80) continue;
            $by[$term]['posts'] = ($by[$term]['posts'] ?? 0) + 1;
            $by[$term]['authors'][$auth] = true;
            $by[$term][$col] = ($by[$term][$col] ?? 0) + 1;
            $by[$term]['reach'] = ($by[$term]['reach'] ?? 0) + $reach;
        }
    }
    if ($by) {
        $up = $pdo->prepare("INSERT INTO term_daily (term, day, posts, authors, p_reddit, p_x, p_youtube, p_tiktok, p_steam, p_other, reach)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE posts=posts+VALUES(posts), authors=authors+VALUES(authors), p_reddit=p_reddit+VALUES(p_reddit),
                               p_x=p_x+VALUES(p_x), p_youtube=p_youtube+VALUES(p_youtube), p_tiktok=p_tiktok+VALUES(p_tiktok),
                               p_steam=p_steam+VALUES(p_steam), p_other=p_other+VALUES(p_other), reach=reach+VALUES(reach)");
        $own = !$pdo->inTransaction() && $pdo->beginTransaction();
        foreach ($by as $term => $d) {
            $up->execute([$term, $today, $d['posts'], count($d['authors']), $d['p_reddit'] ?? 0, $d['p_x'] ?? 0, $d['p_youtube'] ?? 0,
                          $d['p_tiktok'] ?? 0, $d['p_steam'] ?? 0, $d['p_other'] ?? 0, $d['reach'] ?? 0]);
        }
        if ($own) $pdo->commit();
    }
    // bounded clean-up: counts older than 45 days, post marks older than 10
    try {
        $pdo->exec("DELETE FROM term_daily WHERE day < CURDATE() - INTERVAL " . TR_KEEP_DAYS . " DAY LIMIT 20000");
        $pdo->exec("DELETE FROM scout_post_seen WHERE day < CURDATE() - INTERVAL 10 DAY LIMIT 20000");
    } catch (Throwable $e) {}
    return ['posts' => $new, 'terms' => count($by)];
}

/**
 * A term's numbers at $now: use in the last 3 days, its own 3-day average over the 3 weeks before, the platforms and
 * people of those 3 days, how far back our counts go and when we first heard it.
 */
function tr_measure(PDO $pdo, string $term, string $now = ''): array {
    tr_install($pdo);
    $today = $now !== '' ? substr($now, 0, 10) : gmdate('Y-m-d');
    $recentFrom = gmdate('Y-m-d', strtotime($today . ' UTC') - (TR_RECENT_DAYS - 1) * 86400);
    $baseFrom = gmdate('Y-m-d', strtotime($recentFrom . ' UTC') - TR_BASE_DAYS * 86400);
    $q = $pdo->prepare("SELECT day, posts, authors, p_reddit, p_x, p_youtube, p_tiktok, p_steam, p_other, reach FROM term_daily WHERE term=? AND day BETWEEN ? AND ? ORDER BY day");
    $q->execute([$term, $baseFrom, $today]);
    $recent = ['posts' => 0, 'authors' => 0, 'reach' => 0, 'platforms' => []]; $basePosts = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['day'] >= $recentFrom) {
            $recent['posts'] += (int)$r['posts']; $recent['authors'] += (int)$r['authors']; $recent['reach'] += (int)$r['reach'];
            foreach (['reddit', 'x', 'youtube', 'tiktok', 'steam', 'other'] as $pl) if ((int)$r["p_{$pl}"] > 0) $recent['platforms'][$pl] = true;
        } else $basePosts += (int)$r['posts'];
    }
    $first = $pdo->query("SELECT MIN(day) FROM term_daily")->fetchColumn();
    $history = $first ? max(0, (int)floor((strtotime($today . ' UTC') - strtotime($first . ' UTC')) / 86400)) : 0;
    $baseDays = max(1, min(TR_BASE_DAYS, $history - (TR_RECENT_DAYS - 1)));
    $fq = $pdo->prepare("SELECT first_seen FROM scout_vocab WHERE term=?");
    $fq->execute([$term]);
    $heard = (string)$fq->fetchColumn();
    $td = $pdo->prepare("SELECT MIN(day) FROM term_daily WHERE term=?");
    $td->execute([$term]);
    $firstDay = (string)$td->fetchColumn();
    return ['term' => $term, 'recent_posts' => $recent['posts'], 'recent_authors' => $recent['authors'], 'recent_reach' => $recent['reach'],
            'platforms' => array_keys($recent['platforms']),
            'baseline_3d' => round($basePosts / $baseDays * TR_RECENT_DAYS, 2),   // its own average over 3 days, from the weeks before
            'history_days' => $history,
            'first_heard' => $heard !== '' && ($firstDay === '' || $heard < $firstDay) ? substr($heard, 0, 10) : $firstDay,
            'now' => $today];
}

/**
 * Is the term an ordinary dictionary or gaming word? Wiktionary's English entry, asked once per term (term_dict).
 * ['ordinary' => true|false|null (null: could not ask), 'label' => what the dictionary says]
 * Ordinary = it has an English entry with a plain sense (or a video-game sense): "gamepad", "stamina", "rockets".
 * Not ordinary = no entry at all (a new meme's name), or an entry whose every sense is marked slang or a neologism.
 */
function tr_is_ordinary(PDO $pdo, string $term): array {
    tr_install($pdo);
    $key = mb_strtolower(trim($term));
    $c = $pdo->prepare("SELECT ordinary, label FROM term_dict WHERE term=? AND checked_at >= NOW() - INTERVAL 90 DAY");
    $c->execute([$key]);
    if ($row = $c->fetch(PDO::FETCH_ASSOC)) return ['ordinary' => $row['ordinary'] === null ? null : (bool)$row['ordinary'], 'label' => (string)$row['label']];
    // the entry's categories say what kind of word it is ("English slang", "en:Video games", "English lemmas")
    // a short single word is also read under its capital spelling: an acronym lives there ("oomf" is a plain noun, "OOMF"
    // is internet slang; 2026-10-04 the detector turned "oomf" down as an ordinary word)
    $caps = preg_match('/^[a-z]{2,6}$/', $key) ? strtoupper($key) : '';
    $ch = curl_init('https://en.wiktionary.org/w/api.php?action=query&prop=categories&cllimit=500&format=json&titles=' . rawurlencode($key) . ($caps !== '' ? '%7C' . $caps : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_USERAGENT => 'GenZHypeBot/1.0 (https://genzhype.com; contact@genzhype.com)']);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = $code === 200 ? json_decode((string)$raw, true) : null;
    if (!is_array($j) || !isset($j['query']['pages'])) return ['ordinary' => null, 'label' => "dictionary not reached (HTTP {$code})"];
    $cats = []; $capsCats = [];
    foreach ((array)$j['query']['pages'] as $pg) foreach ((array)($pg['categories'] ?? []) as $c) {
        if ($caps !== '' && (string)($pg['title'] ?? '') === $caps) $capsCats[] = preg_replace('/^Category:/', '', (string)$c['title']);
        else $cats[] = preg_replace('/^Category:/', '', (string)$c['title']);
    }
    $res = tr_dict_read($cats, $capsCats);
    $pdo->prepare("REPLACE INTO term_dict (term, ordinary, label, checked_at) VALUES (?,?,?,NOW())")->execute([$key, (int)$res['ordinary'], $res['label']]);
    return $res;
}

/**
 * A phrase our LISTENER picked out of posts (two words that stood side by side), not listed by the dictionary and made
 * only of ordinary words, is an ordinary phrase: "the fps", "progression system", "grind for" (2026-10-01 sample).
 * One word of it that is not ordinary keeps it open ("hidden mmr"). Never applied to a name a source gave a meme
 * ("Friend group separation meme" is KnowYourMeme's title, not two words we overheard).
 * ['ordinary' => bool|null, 'label']
 */
function tr_ordinary_phrase(PDO $pdo, string $term): array {
    $words = preg_split('/\s+/', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) < 2) return ['ordinary' => false, 'label' => ''];
    foreach ($words as $w) {
        if (isset(tr_common_words()[$w]) || mb_strlen($w) < 3) continue;
        $one = tr_is_ordinary($pdo, $w);
        if ($one['ordinary'] === null) return $one;          // could not ask: decide later
        if (!$one['ordinary']) return ['ordinary' => false, 'label' => ''];
    }
    return ['ordinary' => true, 'label' => 'an ordinary phrase (every word in it is an ordinary word)'];
}

/** The 10k most common English words the listener already skips (app/common_words.txt). */
function tr_common_words(): array {
    static $set = null;
    if ($set === null) { $set = []; foreach ((array)@file(__DIR__ . '/common_words.txt', FILE_IGNORE_NEW_LINES) as $w) $set[trim($w)] = true; }
    return $set;
}

/**
 * Reads the entry's categories (pure): ['ordinary' => bool, 'label'].
 *   no English entry                          -> not ordinary (a new meme's name, a creator's name)
 *   an English entry marked slang/neologism   -> not ordinary (dictionary slang: "rizz", "skibidi")
 *   an English entry in "Video games"         -> an ordinary gaming word ("nerf", "loadout")
 *   any other English entry                   -> an ordinary dictionary word ("gamepad", "stamina", "rockets")
 */
function tr_dict_read(array $cats, array $capsCats = []): array {
    // $capsCats: the categories of the word's capital spelling, when it has an entry of its own
    if ($capsCats) {
        $low = tr_dict_read($cats);
        if ($low['ordinary'] && tr_dict_read($capsCats)['label'] === 'in the dictionary as slang') return ['ordinary' => false, 'label' => 'in the dictionary as slang (under its capital spelling)'];
        return $low;
    }
    $english = false; $slang = false; $gaming = false; $properOnly = true;
    foreach ($cats as $c) {
        if (preg_match('/^English (lemmas|non-lemma forms|nouns|verbs|adjectives|adverbs|phrases|multiword terms|interjections|noun forms|verb forms)$/', $c)) $english = true;
        if (preg_match('/^English (nouns|verbs|adjectives|adverbs|interjections|noun forms|verb forms|phrases)$/', $c)) $properOnly = false;
        if (preg_match('/^English (internet |Generation Z |Generation Alpha |gaming |fandom |text messaging )?slang$|^English neologisms$|^English brainrot|^English internet memes$|^English 4chan slang$|^African-American Vernacular English$|^English leetspeak$/i', $c)) $slang = true;
        if (preg_match('/^en:Video games?$|^en:Video game genres$|^en:Gaming$/i', $c)) $gaming = true;
    }
    if (!$english) return ['ordinary' => false, 'label' => 'not in the dictionary'];
    if ($gaming) return ['ordinary' => true, 'label' => 'an ordinary gaming word (in the dictionary as video-game vocabulary)'];
    if ($slang) return ['ordinary' => false, 'label' => 'in the dictionary as slang'];
    if ($properOnly) return ['ordinary' => false, 'label' => 'in the dictionary as a name only'];
    return ['ordinary' => true, 'label' => 'an ordinary dictionary word'];
}

/**
 * THE DECISION (pure): numbers in, answer out. $m = tr_measure() + ['ordinary' => bool|null, 'label', 'listener_start'].
 * ['trend' => bool, 'interim' => bool, 'why', 'parts' => ['rising' => true|false|null, 'new' => bool, 'cross' => bool]]
 */
function tr_decide(array $m): array {
    $now = strtotime((string)$m['now'] . ' UTC');
    $parts = ['rising' => null, 'new' => false, 'cross' => count((array)$m['platforms']) >= TR_MIN_PLATFORMS];
    // NEW: not in our signals a month ago, and not an ordinary word
    $heard = (string)($m['first_heard'] ?? '');
    $start = (string)($m['listener_start'] ?? '');
    $ageDays = $heard !== '' ? (int)floor(($now - strtotime($heard . ' UTC')) / 86400) : 0;
    $sinceStart = $start !== '' && $heard !== '' && strtotime($heard . ' UTC') <= strtotime($start . ' UTC') + 2 * 86400;   // heard since the listener's first days: its age is unknown
    $parts['new'] = empty($m['ordinary']) && $ageDays <= TR_NEW_DAYS && !$sinceStart;
    // RISING: the last 3 days against its own 3-day average (only with enough days of counts)
    $enough = (int)$m['recent_posts'] >= TR_MIN_POSTS && (int)$m['recent_authors'] >= TR_MIN_AUTHORS;
    if ((int)$m['history_days'] >= TR_MIN_HISTORY_DAYS)
        $parts['rising'] = $enough && (float)$m['recent_posts'] >= TR_RISE_FACTOR * max((float)$m['baseline_3d'], 1.0);
    $no = fn(string $why) => ['trend' => false, 'interim' => false, 'why' => $why, 'parts' => $parts];
    if (!empty($m['ordinary'])) return $no((string)($m['label'] ?? '') ?: 'an ordinary dictionary word');
    if (!$parts['new']) return $no($sinceStart ? 'heard since our listener started: not new' : "in our signals for {$ageDays} days: not new");
    $np = count((array)$m['platforms']);
    if (!$parts['cross']) return $no('seen on ' . ($np ?: 'no') . ' platform' . ($np === 1 ? ' (' . $m['platforms'][0] . ')' : 's') . ' in the last ' . TR_RECENT_DAYS . ' days: needs ' . TR_MIN_PLATFORMS);
    if ($parts['rising'] === false)
        return $no("not rising: {$m['recent_posts']} post(s) by {$m['recent_authors']} people in " . TR_RECENT_DAYS . " days against its own average of {$m['baseline_3d']}");
    $what = "{$m['recent_posts']} posts by {$m['recent_authors']} people on " . implode(' and ', (array)$m['platforms']) . ' in ' . TR_RECENT_DAYS . ' days';
    if ($parts['rising'] === null) {
        // not enough days of counts yet: new + 2 platforms + several people, said as such
        if (!$enough) return $no("too few people yet: {$what}");
        if (str_starts_with((string)($m['label'] ?? ''), 'in the dictionary')) return $no((string)$m['label'] . ': it needs a measured rise, and we have only ' . (int)$m['history_days'] . ' day(s) of counts');
        return ['trend' => true, 'interim' => true, 'why' => "new and on 2+ platforms ({$what}); rising not judged yet, only {$m['history_days']} day(s) of counts", 'parts' => $parts];
    }
    return ['trend' => true, 'interim' => false, 'why' => "rising: {$what}, " . round((float)$m['recent_posts'] / max((float)$m['baseline_3d'], 1.0), 1) . ' times its own average', 'parts' => $parts];
}

/** Everything for one term at once: its numbers, the dictionary's answer and the decision. */
function tr_check(PDO $pdo, string $term, string $now = ''): array {
    $m = tr_measure($pdo, $term, $now);
    $d = tr_is_ordinary($pdo, $term);
    if ($d['ordinary'] === false && $d['label'] === 'not in the dictionary' && $m['first_heard'] !== '') {   // heard by our listener
        $ph = tr_ordinary_phrase($pdo, $term);
        if ($ph['ordinary'] !== false) $d = $ph;
    }
    $m['ordinary'] = $d['ordinary']; $m['label'] = $d['label'];
    $m['listener_start'] = substr((string)$pdo->query("SELECT MIN(first_seen) FROM scout_vocab")->fetchColumn(), 0, 10);
    return tr_decide($m) + ['numbers' => $m];
}

/* ---------------------------------------------------------------------------------------------------------------
 * PART 2 (owner 2026-10-03): the gate in front of the term writer, and the memes a source names
 *
 * Two kinds of term reach the writer:
 *   - a word or phrase OUR LISTENER heard in posts: judged by tr_check() above (rising, new, 2+ platforms, not ordinary);
 *   - a meme A SOURCE NAMED (KnowYourMeme, an outlet, a Reddit headline): our listener never hears its name, so it is
 *     judged on its own posts, the ones the naming page links (TikTok, X, YouTube). Those same posts are the dated,
 *     attributed posts the truth gate asks for, and the naming page is the first source the writer reads
 *     (2026-10-01: the writer was given only the name, searched the web, and wrote from Backrooms film articles).
 * ------------------------------------------------------------------------------------------------------------- */

const TR_NAMED_DAYS      = 30;   // [ours] a named meme's posts count when dated in the last 30 days
const TR_NAMED_MIN_POSTS = 3;    // [ours] at least 3 such posts
const TR_WAIT_DAYS       = 7;    // [ours] a term waiting for a second platform is dropped after 7 days

/** YouTube videos by id: channel, publish date, views (one request for up to 25 ids, our own API key). */
function tr_youtube_facts(array $ids): array {
    global $CONFIG;
    $key = (string)($CONFIG['youtube_key'] ?? '');
    $ids = array_values(array_unique(array_filter($ids)));
    if ($key === '' || !$ids) return [];
    $ch = curl_init('https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics&id=' . rawurlencode(implode(',', array_slice($ids, 0, 25))) . '&key=' . rawurlencode($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    $out = [];
    foreach ((array)($j['items'] ?? []) as $it)
        $out[(string)$it['id']] = ['channel' => (string)($it['snippet']['channelTitle'] ?? ''), 'date' => substr((string)($it['snippet']['publishedAt'] ?? ''), 0, 10),
                                   'title' => (string)($it['snippet']['title'] ?? ''), 'views' => (int)($it['statistics']['viewCount'] ?? 0)];
    return $out;
}

/**
 * What a naming page gives us: the page itself as the writer's first source, and the posts it links, each with its
 * platform, who posted it and its own date (X and TikTok carry the date in the post id; YouTube is asked).
 * ['sources' => [writer source rows], 'posts' => [['platform','handle','date' => Y-m-d,'url','views']]]
 */
function tr_source_posts(array $urls, int $max = 16): array {
    require_once __DIR__ . '/fetch_sources.php';
    require_once __DIR__ . '/story_picker.php';   // sp_post_time(), sp_kind()
    $sources = []; $posts = []; $yt = [];
    foreach (array_slice(array_values(array_unique($urls)), 0, 3) as $u) {
        if (str_starts_with($u, '/r/')) $u = 'https://www.reddit.com' . $u;
        if (!filter_var($u, FILTER_VALIDATE_URL) || sp_kind($u) === 'post') continue;
        $html = fs_http_get($u, 15);
        if (!$html) continue;
        $text = fs_extract_text($html);
        $title = preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $t) ? trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES, 'UTF-8')) : '';
        if (mb_strlen($text) >= 300)
            $sources[] = ['url' => $u, 'publisher' => fs_publisher($u), 'date' => fs_published_date($html), 'reliability' => 'reliable_outlet',
                          'excerpt' => mb_substr($text, 0, 3500), 'title' => mb_substr($title, 0, 200), 'topical_fit' => true];
        foreach (fs_harvest_social($html, $max) as $s) {
            $pu = (string)$s['url'];
            if (isset($posts[$pu])) continue;
            if ($s['provider'] === 'tiktok' && preg_match('#tiktok\.com/@([A-Za-z0-9_.]+)/video/#', $pu, $m))
                $posts[$pu] = ['platform' => 'TikTok', 'handle' => '@' . $m[1], 'date' => substr(sp_post_time($pu), 0, 10), 'url' => $pu, 'views' => 0];
            elseif ($s['provider'] === 'twitter' && preg_match('#(?:twitter|x)\.com/([A-Za-z0-9_]+)/status/#', $pu, $m))
                $posts[$pu] = ['platform' => 'X', 'handle' => '@' . $m[1], 'date' => substr(sp_post_time($pu), 0, 10), 'url' => $pu, 'views' => 0];
            elseif ($s['provider'] === 'youtube' && preg_match('#(?:v=|youtu\.be/|shorts/)([A-Za-z0-9_-]{6,})#', $pu, $m)) {
                $yt[$m[1]] = $pu;
                $posts[$pu] = ['platform' => 'YouTube', 'handle' => '', 'date' => '', 'url' => $pu, 'views' => 0];
            }
        }
    }
    foreach (tr_youtube_facts(array_keys($yt)) as $id => $f) {
        $pu = $yt[$id];
        $posts[$pu]['handle'] = $f['channel']; $posts[$pu]['date'] = $f['date']; $posts[$pu]['views'] = $f['views'];
    }
    // a post without a date or a name proves nothing about when or who
    return ['sources' => $sources, 'posts' => array_values(array_filter($posts, fn($p) => $p['date'] !== '' && $p['handle'] !== ''))];
}

/**
 * A meme a source named (pure): a trend when its own posts, dated in the last 30 days, are at least 3 and sit on 2+
 * platforms. $f: now (Y-m-d), posts [['platform','date']], named_by.
 * ['trend' => bool, 'why', 'recent' => n, 'platforms' => [...]]
 */
function tr_decide_named(array $f): array {
    $now = strtotime((string)$f['now'] . ' UTC');
    $recent = array_values(array_filter((array)$f['posts'], fn($p) => ($p['date'] ?? '') !== '' && $now - strtotime($p['date'] . ' UTC') <= TR_NAMED_DAYS * 86400 && strtotime($p['date'] . ' UTC') <= $now + 86400));
    $pl = array_values(array_unique(array_map(fn($p) => (string)$p['platform'], $recent)));
    $by = (string)($f['named_by'] ?? 'a source');
    $res = ['recent' => count($recent), 'platforms' => $pl];
    if (!(array)$f['posts']) return $res + ['trend' => false, 'why' => "named by {$by}, but its page links no dated post of the meme"];
    if (count($recent) < TR_NAMED_MIN_POSTS)
        return $res + ['trend' => false, 'why' => "named by {$by}: " . count($recent) . ' post(s) of it dated in the last ' . TR_NAMED_DAYS . ' days (needs ' . TR_NAMED_MIN_POSTS . '); the rest are older'];
    // owner 2026-10-04: with posts on one platform only, the outlet that wrote about the meme counts as the second place
    // it was seen (KnowYourMeme, a news site: a page we could read). A Reddit thread or a bare post is never that outlet.
    if (count($pl) < TR_MIN_PLATFORMS && empty($f['outlet']))
        return $res + ['trend' => false, 'why' => "named by {$by}: " . count($recent) . ' recent posts, all on ' . ($pl[0] ?? '?') . ' (needs ' . TR_MIN_PLATFORMS . ' platforms, or an outlet writing about it)'];
    return $res + ['trend' => true, 'why' => "named by {$by}: " . count($recent) . ' posts of it in the last ' . TR_NAMED_DAYS . ' days on ' . implode(' and ', $pl)
                                              . (count($pl) < TR_MIN_PLATFORMS ? ", and {$by} wrote about it" : '')];
}

function tr_install2(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    tr_install($pdo);
    $cols = array_column($pdo->query("SHOW COLUMNS FROM terms")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('trend', $cols, true))      $pdo->exec("ALTER TABLE terms ADD COLUMN trend TINYINT NULL COMMENT 'the trend detector verdict when it was written (trend.php)'");
    if (!in_array('trend_note', $cols, true)) $pdo->exec("ALTER TABLE terms ADD COLUMN trend_note VARCHAR(255) NULL");
    if (!in_array('trend_rebuilt_at', $cols, true)) $pdo->exec("ALTER TABLE terms ADD COLUMN trend_rebuilt_at DATETIME NULL");   // tr_rebuild_stuck, rebuild.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS trend_decisions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cand_id INT UNSIGNED NOT NULL,
        term VARCHAR(120) NOT NULL,
        action ENUM('write','skip','wait','drop') NOT NULL,
        trend TINYINT NOT NULL DEFAULT 0,
        why VARCHAR(400) NULL,
        decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_when (decided_at), KEY idx_cand (cand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * THE GATE in front of the term writer. Reads one queued term and says what to do with it (nothing is written here
 * unless $apply: then the decision is logged, a wait is stamped and a refused term is retired).
 *   write + trend     its own page (a trend)
 *   write, no trend   real slang that is not trending: a glossary entry, as before
 *   skip              an ordinary word or phrase: not written at all
 *   wait              not on 2 platforms yet: looked at again tomorrow, dropped after 7 days
 * ['action', 'trend' => bool, 'why', 'kind' => heard|named, 'sources' => [...], 'posts' => [...]]
 */
function tr_gate(PDO $pdo, array $cand, bool $apply = false, string $now = ''): array {
    if ($apply) tr_install2($pdo);
    $today = $now !== '' ? substr($now, 0, 10) : gmdate('Y-m-d');
    $name = trim((string)$cand['name']);
    $term = mb_strtolower($name);
    $sg = json_decode((string)($cand['signals'] ?? ''), true) ?: [];
    $urls = [];
    if (!empty($sg['url'])) $urls[] = (string)$sg['url'];
    foreach ((array)($sg['desk']['evidence'] ?? []) as $e) if (!empty($e['url'])) $urls[] = (string)$e['url'];
    $m = tr_measure($pdo, $term, $today);
    $heard = $m['first_heard'] !== '' || !empty($sg['scout']);
    $out = ['action' => 'wait', 'trend' => false, 'why' => '', 'kind' => $heard ? 'heard' : 'named', 'sources' => [], 'posts' => []];
    if ($heard) {
        $r = tr_check($pdo, $term, $today);
        if (!empty($r['numbers']['ordinary'])) $out = ['action' => 'skip', 'why' => (string)$r['numbers']['label']] + $out;
        elseif ($r['numbers']['ordinary'] === null) $out = ['action' => 'wait', 'why' => 'the dictionary could not be reached: looked at again later'] + $out;
        elseif ($r['trend']) $out = ['action' => 'write', 'trend' => true, 'why' => $r['why']] + $out;
        elseif (!$r['parts']['new'] || str_starts_with((string)$r['numbers']['label'], 'in the dictionary'))
            $out = ['action' => 'write', 'trend' => false, 'why' => 'real slang, not a trend (' . $r['why'] . '): a glossary entry'] + $out;
        else $out = ['action' => 'wait', 'why' => $r['why']] + $out;
    } else {
        $src = tr_source_posts($urls);
        $by = $src['sources'] ? (string)$src['sources'][0]['publisher'] : (string)preg_replace('/^(desk:)?(rss:|scout:)?/', '', (string)($sg['source'] ?? 'a source'));
        $d = tr_decide_named(['now' => $today, 'posts' => $src['posts'], 'named_by' => $by, 'outlet' => !empty($src['sources'])]);
        $out = ['action' => $d['trend'] ? 'write' : 'wait', 'trend' => $d['trend'], 'why' => $d['why'], 'sources' => $src['sources'], 'posts' => $src['posts']] + $out;
    }
    // a term that has waited a week is dropped
    $first = strtotime((string)($cand['created_at'] ?? $today));
    if ($out['action'] === 'wait' && $first && strtotime($today . ' UTC') - $first > TR_WAIT_DAYS * 86400)
        $out = ['action' => 'drop', 'why' => 'waited ' . TR_WAIT_DAYS . ' days: ' . $out['why']] + $out;
    if ($apply) {
        $pdo->prepare("INSERT INTO trend_decisions (cand_id, term, action, trend, why) VALUES (?,?,?,?,?)")
            ->execute([(int)$cand['id'], mb_substr($name, 0, 120), $out['action'], (int)$out['trend'], mb_substr($out['why'], 0, 400)]);
        if ($out['action'] === 'wait') $pdo->prepare("UPDATE candidates SET picker_checked_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$cand['id']]);
        if (in_array($out['action'], ['skip', 'drop'], true))
            $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute([mb_substr('trend: ' . $out['why'], 0, 255), (int)$cand['id']]);
    }
    return $out;
}

/** A named meme's posts as citations the truth gate can check (platform + who + date + the post's own address). */
function tr_posts_as_citations(array $posts): array {
    $out = [];
    foreach ($posts as $p)
        $out[] = ['platform' => (string)$p['platform'], 'handle' => (string)$p['handle'], 'publication' => '', 'title' => '',
                  'date' => date('F j, Y', strtotime((string)$p['date'] . ' UTC')), 'url' => (string)$p['url'], 'quote' => '', 'views' => (int)($p['views'] ?? 0)];
    return $out;
}

/** Does a Google Trends item also show in our own Reddit, X or YouTube signals of the last 3 days? (owner rule 4) */
function tr_in_signals(PDO $pdo, string $term): bool {
    tr_install($pdo);
    $t = mb_strtolower(trim($term));
    $q = $pdo->prepare("SELECT SUM(p_reddit + p_x + p_youtube) FROM term_daily WHERE term=? AND day >= CURDATE() - INTERVAL " . TR_RECENT_DAYS . " DAY");
    $q->execute([$t]);
    if ((int)$q->fetchColumn() > 0) return true;
    $q = $pdo->prepare("SELECT COUNT(*) FROM desk_signals WHERE seen_at >= NOW() - INTERVAL " . TR_RECENT_DAYS . " DAY AND origin NOT LIKE 'google%' AND (origin LIKE 'scout:reddit%' OR origin LIKE 'scout:x%' OR origin LIKE 'scout:youtube%') AND text LIKE ?");
    $q->execute(['%' . $t . '%']);
    return (int)$q->fetchColumn() > 0;
}

/**
 * STUCK TRENDS get written again (owner 2026-10-01: "the stuck real trends get built"; his rule for a strong idea that is
 * missing something: work harder, do not drop it). A term draft from the last 30 days whose gate verdict is "trend" is
 * rebuilt from the page that named it and its own posts (rebuild.php term_rebuild), at most once every 3 days and
 * $limit per call. The page keeps its address; nothing goes live unless the unchanged checks pass.
 * ['line' => what it did]
 */
function tr_rebuild_stuck(PDO $pdo, int $limit = 1): array {
    tr_install2($pdo);
    $cols = array_column($pdo->query("SHOW COLUMNS FROM terms")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('trend_rebuilt_at', $cols, true)) $pdo->exec("ALTER TABLE terms ADD COLUMN trend_rebuilt_at DATETIME NULL");
    $rows = $pdo->query("SELECT p.id, t.term FROM pages p JOIN terms t ON t.page_id=p.id
                         WHERE p.type='term' AND p.status='draft' AND p.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
                           AND (t.trend_rebuilt_at IS NULL OR t.trend_rebuilt_at < UTC_TIMESTAMP() - INTERVAL 3 DAY)
                         ORDER BY (t.trend = 1) DESC, p.id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    $cq = $pdo->prepare("SELECT * FROM candidates WHERE LOWER(name)=LOWER(?) ORDER BY id DESC LIMIT 1");
    $done = []; $ready = [];
    foreach ($rows as $r) {
        if (count($done) >= $limit) break;
        $cq->execute([(string)$r['term']]);
        $cand = $cq->fetch(PDO::FETCH_ASSOC);
        if (!$cand) continue;
        $cand['created_at'] = gmdate('Y-m-d');
        try { $g = tr_gate($pdo, $cand, false); } catch (Throwable $e) { continue; }
        if ($g['action'] !== 'write' || !$g['trend']) {   // not a trend (today): leave the draft, look again in 3 days
            $pdo->prepare("UPDATE terms SET trend_rebuilt_at=UTC_TIMESTAMP(), trend=0, trend_note=? WHERE page_id=?")->execute([mb_substr($g['why'], 0, 255), (int)$r['id']]);
            continue;
        }
        $pdo->prepare("UPDATE terms SET trend_rebuilt_at=UTC_TIMESTAMP() WHERE page_id=?")->execute([(int)$r['id']]);
        require_once __DIR__ . '/rebuild.php';
        try { $rr = term_rebuild($pdo, (int)$r['id'], 'all'); } catch (Throwable $e) { $rr = ['error' => get_class($e) . ': ' . $e->getMessage()]; }
        $done[] = '"' . $r['term'] . '" ' . (isset($rr['error']) ? 'not rebuilt (' . mb_substr((string)$rr['error'], 0, 90) . ')' : 'rebuilt, checks ' . (!empty($rr['ok']) ? 'PASS' : 'FAIL: ' . mb_substr(implode('; ', (array)($rr['fails'] ?? [])), 0, 140)));
        if (!isset($rr['error']) && !empty($rr['ok'])) $ready[] = (int)$r['id'];
    }
    return ['line' => $done ? 'stuck trends: ' . implode(' | ', $done) : '', 'ready' => $ready];
}
