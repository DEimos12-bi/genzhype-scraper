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
    $ch = curl_init('https://en.wiktionary.org/w/api.php?action=query&prop=categories&cllimit=300&format=json&titles=' . rawurlencode($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_USERAGENT => 'GenZHypeBot/1.0 (https://genzhype.com; contact@genzhype.com)']);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = $code === 200 ? json_decode((string)$raw, true) : null;
    if (!is_array($j) || !isset($j['query']['pages'])) return ['ordinary' => null, 'label' => "dictionary not reached (HTTP {$code})"];
    $cats = [];
    foreach ((array)$j['query']['pages'] as $pg) foreach ((array)($pg['categories'] ?? []) as $c) $cats[] = preg_replace('/^Category:/', '', (string)$c['title']);
    $res = tr_dict_read($cats);
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
function tr_dict_read(array $cats): array {
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
