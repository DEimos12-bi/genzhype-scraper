<?php
declare(strict_types=1);
/* GenZHype | THE SCOUT — social-native discovery for the term lanes.
 * OWNER 2026-08-30 ("built the scout"): slang and memes are BORN on social —
 * "their main sources should be the social media... it should be smart enough
 * to know about it and what it is from itself, like if we hired a human
 * watching anything new for us to publish."
 *
 * So the Scout does what that hired human does:
 *   1. LISTENS — the reach-runner's listening harvest (broad Reddit subs +
 *      X meta-searches, delivered 6-hourly into reach_cache['scout_posts']).
 *   2. NOTICES — burst detection over the post stream: a word or phrase we
 *      have never published, suddenly used by MANY DIFFERENT PEOPLE on more
 *      than one platform, is a birth signal (the SONDY / Gnip trend-detection
 *      idea, sized to our stream: counting beats machine learning here).
 *   3. UNDERSTANDS — the AI reads the ACTUAL POSTS and infers the meaning
 *      from usage, the way a scroller learns a word — never from explainer
 *      articles (owner: "not keep looking for posts that explain it").
 *   4. HANDS OVER — a promoted term goes into `candidates` as SELECTED (the
 *      Scout's screen already judged it), and its posts go into
 *      scout_cache.json so the drafter cites them and reads usage from them.
 *      Everything downstream is unchanged: same drafter, same truth gate.
 *
 * Honest v1 limits (in writing, so nobody oversells it later):
 *   - a 2-word phrase made ONLY of common words ("skill issue") is invisible
 *     to the tokenizer; those still arrive via r/OutOfTheLoop question titles.
 *   - TikTok is not listened to yet (needs the owner's Creative Center
 *     cookies — slot reserved, see scout-posts.json 'tiktok' platform key).
 */

require_once __DIR__ . '/ai.php';

const SCOUT_MIN_AUTHORS       = 3;   // distinct people before it is a signal
const SCOUT_MIN_AUTHORS_MONO  = 5;   // stricter when only one platform saw it
const SCOUT_SAMPLE_POSTS      = 8;   // evidence posts kept per term
const SCOUT_CACHE             = __DIR__ . '/scout_cache.json';

function scout_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS scout_vocab (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        term VARCHAR(80) NOT NULL UNIQUE,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        n_posts INT NOT NULL DEFAULT 0,
        authors_json MEDIUMTEXT NULL,
        platforms_json TEXT NULL,
        posts_json MEDIUMTEXT NULL,
        status ENUM('watching','promoted','dismissed') NOT NULL DEFAULT 'watching',
        verdict VARCHAR(255) NULL
    ) DEFAULT CHARSET=utf8mb4");
}

/** The 10k most common English words (google-10000-english) + our own noise. */
function scout_common_words(): array {
    static $set = null;
    if ($set !== null) return $set;
    $set = [];
    foreach ((array)@file(__DIR__ . '/common_words.txt', FILE_IGNORE_NEW_LINES) as $w) $set[trim($w)] = true;
    foreach (['reddit', 'subreddit', 'tiktok', 'youtube', 'insta', 'instagram', 'twitter',
              'upvote', 'repost', 'dms', 'irl', 'imo', 'imho', 'tbh', 'ngl', 'lol', 'lmao',
              'wtf', 'omg', 'idk', 'btw', 'fyi', 'nsfw', 'ama', 'eli5', 'til', 'iirc',
              'gonna', 'wanna', 'gotta', 'kinda', 'sorta', 'yall', 'bruh', 'fr', 'rn',
              'pls', 'plz', 'thx', 'ur', 'ppl', 'bc', 'rly', 'srsly', 'w', 'l',
              // decades-old staples — vocabulary, but never a page
              'bro', 'dude', 'nah', 'yeah', 'yep', 'nope', 'hmm', 'okay', 'lmfao',
              'homie', 'dawg', 'chill', 'dang', 'damn', 'hella', 'lowkey', 'highkey',
              // the listening searches' own words echo in every X hit — and the
              // lane names themselves are never pages (measured, first live pass)
              'slang', 'memes', 'meme', 'gaming', 'gamer', 'viral', 'trending',
              'saying', 'everyone', 'someone', 'word', 'words', 'phrase', 'term'] as $w) $set[$w] = true;
    return $set;
}

/** Words/phrases the site already knows — published terms, queue, drama names. */
function scout_known_terms(PDO $pdo): array {
    $known = [];
    foreach ($pdo->query("SELECT LOWER(term) t FROM terms") as $r) $known[$r['t']] = true;
    foreach ($pdo->query("SELECT LOWER(name) t FROM candidates") as $r) $known[$r['t']] = true;
    return $known;
}

/** Candidate tokens from one post: hashtags, rare 1-grams, half-rare 2-grams. */
function scout_tokenize(string $text): array {
    $common = scout_common_words();
    $out = [];
    $t = mb_strtolower($text);
    $t = str_replace(['’', '‘'], "'", $t);   // curly quotes split words ("doesn’t" -> "doesn")
    $t = preg_replace('#https?://\S+#', ' ', $t);
    // hashtags are self-declared vocabulary — always candidates
    if (preg_match_all('/#([a-z][a-z0-9]{2,29})\b/u', $t, $m)) {
        foreach ($m[1] as $h) if (!isset($common[$h])) $out[$h] = true;
    }
    $t = preg_replace('/[@#][\w.]+/u', ' ', $t);
    if (!preg_match_all("/[a-z][a-z0-9'-]{2,24}/u", $t, $m)) return array_keys($out);
    $words = $m[0];
    foreach ($words as $i => $w) {
        // contractions/possessives are never slang births ("i've", "what's",
        // "don't" all reached the AI screen on the first live pass) — and a
        // possessive of a common word is that common word
        if (strpos($w, "'") !== false) {
            $base = preg_replace("/'(s|t|re|ve|ll|d|m)$/", '', $w);
            if ($base !== $w || isset($common[$base])) continue;
        }
        $rare = !isset($common[$w]) && !is_numeric($w);
        if ($rare && mb_strlen($w) >= 4) $out[$w] = true;
        // 2-gram: keep when at least one half is rare (v1 limit stated above);
        // a contraction half poisons the pair ("i've been") — skip those too
        if ($i > 0) {
            $prev = $words[$i - 1];
            if (strpos($prev, "'") !== false || strpos($w, "'") !== false) continue;
            if (($rare || !isset($common[$prev])) && mb_strlen($prev) >= 3 && mb_strlen($w) >= 3) {
                $bi = $prev . ' ' . $w;
                if (mb_strlen($bi) <= 40) $out[$bi] = true;
            }
        }
    }
    return array_keys($out);
}

/**
 * YouTube gaming ear: trending gaming videos + top comments, via our own API
 * key (works from this server — verified live 2026-08-30). ~10 quota units
 * per run of a 10k/day budget; answers cached 1h so the hourly tick costs one
 * fetch. Comments carry a citation-ready id: watch?v=VIDEO&lc=COMMENT.
 */
function scout_youtube_posts(): array {
    global $CONFIG;
    $key = (string)($CONFIG['youtube_key'] ?? '');
    if ($key === '') return [];
    $cacheFile = __DIR__ . '/cache/scout_yt.json';
    @mkdir(__DIR__ . '/cache', 0755);
    if (is_file($cacheFile) && time() - (int)filemtime($cacheFile) < 3600) {
        $j = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($j)) return $j;
    }
    $posts = [];
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $v = json_decode((string)@file_get_contents(
        'https://www.googleapis.com/youtube/v3/videos?part=snippet&chart=mostPopular'
        . '&videoCategoryId=20&regionCode=US&maxResults=10&key=' . $key, false, $ctx), true);
    // MEME ear (2026-08-30): category 23 = Comedy. The gaming category alone
    // fed only the gaming lane; meme vocabulary lives in comedy comments.
    $v2 = json_decode((string)@file_get_contents(
        'https://www.googleapis.com/youtube/v3/videos?part=snippet&chart=mostPopular'
        . '&videoCategoryId=23&regionCode=US&maxResults=10&key=' . $key, false, $ctx), true);
    if (!empty($v2['items'])) $v['items'] = array_merge((array)($v['items'] ?? []), $v2['items']);
    foreach ((array)($v['items'] ?? []) as $i => $vid) {
        $vidId = (string)($vid['id'] ?? '');
        $title = (string)($vid['snippet']['title'] ?? '');
        $chan  = (string)($vid['snippet']['channelTitle'] ?? '');
        if ($vidId === '' || $title === '') continue;
        $posts[] = ['platform' => 'youtube', 'author' => $chan, 'text' => mb_substr($title, 0, 300),
                    'video' => $vidId, 'published' => (string)($vid['snippet']['publishedAt'] ?? '')];
        if ($i >= 14) continue;   // comments per run (quota courtesy; 2 categories now)
        $c = json_decode((string)@file_get_contents(
            'https://www.googleapis.com/youtube/v3/commentThreads?part=snippet&videoId=' . $vidId
            . '&maxResults=20&order=relevance&textFormat=plainText&key=' . $key, false, $ctx), true);
        foreach ((array)($c['items'] ?? []) as $th) {
            $s = $th['snippet']['topLevelComment']['snippet'] ?? [];
            $txt = trim((string)($s['textOriginal'] ?? ''));
            if ($txt === '' || mb_strlen($txt) < 15) continue;
            $posts[] = ['platform' => 'youtube',
                        'author'  => ltrim((string)($s['authorDisplayName'] ?? ''), '@'),
                        'text'    => mb_substr($txt, 0, 500),
                        'video'   => $vidId,
                        'comment_id' => (string)($th['snippet']['topLevelComment']['id'] ?? ''),
                        'published'  => (string)($s['publishedAt'] ?? ''),
                        'likes'   => (int)($s['likeCount'] ?? 0)];
        }
    }
    @file_put_contents($cacheFile, json_encode($posts, JSON_UNESCAPED_UNICODE));
    if ($posts) echo '  scout: youtube ear heard ' . count($posts) . " titles+comments\n";
    return $posts;
}

/**
 * STEAM ear (2026-08-30, owner: "look where the games and they're gaming be
 * active a lot, those should be added"). Steam is where PC gamers write about
 * games in their own words, at volume, in public — and both endpoints answer
 * KEYLESS from this server (verified live):
 *   ISteamChartsService/GetMostPlayedGames  -> the live top-100 by players, so
 *     we always listen to what is actually hot instead of a hardcoded list
 *   store.steampowered.com/appreviews/{appid} -> recent English reviews
 * A review carries recommendationid + author.steamid + timestamp_created, so
 * it is CITABLE the same way a tweet is: steamcommunity.com/profiles/{id}/
 * recommended/{appid}/. Cached 3h; a handful of games per run keeps it polite.
 */
function scout_steam_posts(int $games = 6, int $perGame = 20): array {
    $cacheFile = __DIR__ . '/cache/scout_steam.json';
    @mkdir(__DIR__ . '/cache', 0755);
    if (is_file($cacheFile) && time() - (int)filemtime($cacheFile) < 10800) {
        $j = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($j)) return $j;
    }
    $get = function (string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36']);
        $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return $c === 200 ? json_decode((string)$b, true) : null;
    };
    $posts = [];
    $chart = $get('https://api.steampowered.com/ISteamChartsService/GetMostPlayedGames/v1/');
    $ranks = array_slice((array)($chart['response']['ranks'] ?? []), 0, $games);
    foreach ($ranks as $g) {
        $appid = (int)($g['appid'] ?? 0);
        if ($appid <= 0) continue;
        $rv = $get("https://store.steampowered.com/appreviews/{$appid}?json=1&filter=recent"
                 . "&language=english&num_per_page={$perGame}&purchase_type=all&review_type=all");
        foreach ((array)($rv['reviews'] ?? []) as $r) {
            $txt = trim((string)($r['review'] ?? ''));
            $auth = (array)($r['author'] ?? []);
            $sid  = (string)($auth['steamid'] ?? '');
            $ts   = (int)($r['timestamp_created'] ?? 0);
            if ($txt === '' || mb_strlen($txt) < 20 || $sid === '' || $ts <= 0) continue;
            $posts[] = [
                'platform'   => 'steam',
                'author'     => (string)($auth['personaname'] ?? ('steam:' . substr($sid, -6))),
                'text'       => mb_substr($txt, 0, 500),
                'appid'      => $appid,
                'steamid'    => $sid,
                'published'  => gmdate('Y-m-d', $ts),
                'likes'      => (int)($r['votes_up'] ?? 0),
            ];
        }
        usleep(400000);   // polite between games
    }
    @file_put_contents($cacheFile, json_encode($posts, JSON_UNESCAPED_UNICODE));
    if ($posts) echo '  scout: steam ear heard ' . count($posts) . ' reviews across ' . count($ranks) . " top games\n";
    return $posts;
}
/**
 * One Scout pass: absorb the latest listening harvest into scout_vocab,
 * then AI-screen up to $screenCap terms whose burst crossed the signal bar.
 * Talks in the log like the Producer does — every promotion AND every
 * dismissal states its reason.
 */
function scout_run(PDO $pdo, int $screenCap = 6): array {
    scout_install($pdo);
    $cache = json_decode((string)@file_get_contents(__DIR__ . '/reach_cache.json'), true);
    $posts = (array)($cache['scout_posts'] ?? []);
    $stats = ['posts' => count($posts), 'tracked' => 0, 'screened' => 0, 'promoted' => 0];
    if (!$posts) { echo "  scout: no listening harvest in cache yet\n"; return $stats; }

    $known = scout_known_terms($pdo);

    // YOUTUBE EAR (2026-08-30, owner: "the agent working on all 4"): trending
    // GAMING videos + their top comments, read live from the server with our
    // own API key — comments are where gaming vocabulary lives.
    $posts = array_merge($posts, scout_youtube_posts());
    // STEAM ear (2026-08-30, owner: "where the games and they're gaming be
    // active a lot"): the live Steam top-100's own player reviews. Gaming
    // vocabulary at volume, keyless, and each review is citable.
    try { $posts = array_merge($posts, scout_steam_posts()); }
    catch (Throwable $e) { echo "  scout: steam ear skipped: " . $e->getMessage() . "\n"; }
    $stats['posts'] = count($posts);

    // ---- 2. NOTICE: fold this harvest into the vocabulary ledger ----------
    $seen = [];   // term => ['authors'=>set,'platforms'=>set,'posts'=>[...]]
    foreach ($posts as $p) {
        $text = trim((string)($p['text'] ?? ''));
        $auth = mb_strtolower(trim((string)($p['author'] ?? '')));
        $plat = (string)($p['platform'] ?? '');
        if ($text === '' || $auth === '' || $plat === '') continue;
        // story subs (LivestreamFail etc.) feed DRAMA discovery, not the word
        // ledger — their headlines are people and events, not vocabulary
        if (in_array(mb_strtolower((string)($p['sub'] ?? '')),
                     ['livestreamfail', 'youtubedrama', 'gamingleaksandrumours'], true)) continue;
        foreach (scout_tokenize($text) as $term) {
            if (isset($known[$term])) continue;
            $seen[$term]['authors'][$auth] = true;
            $seen[$term]['platforms'][$plat] = true;
            if (count($seen[$term]['posts'] ?? []) < SCOUT_SAMPLE_POSTS) $seen[$term]['posts'][] = $p;
        }
    }

    $sel = $pdo->prepare("SELECT * FROM scout_vocab WHERE term=?");
    $ins = $pdo->prepare("INSERT INTO scout_vocab (term,first_seen,last_seen,n_posts,authors_json,platforms_json,posts_json,n_authors,n_platforms)
                          VALUES (?,NOW(),NOW(),?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE last_seen=NOW(), n_posts=n_posts+VALUES(n_posts),
                          authors_json=VALUES(authors_json), platforms_json=VALUES(platforms_json), posts_json=VALUES(posts_json),
                          n_authors=VALUES(n_authors), n_platforms=VALUES(n_platforms)");
    foreach ($seen as $term => $d) {
        $sel->execute([$term]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['status'] !== 'watching') continue;   // judged once already
        $authors   = array_keys($d['authors']);
        $platforms = array_keys($d['platforms']);
        $samples   = $d['posts'];
        if ($row) {   // merge with what earlier harvests saw
            $authors   = array_values(array_unique(array_merge($authors, (array)json_decode((string)$row['authors_json'], true))));
            $platforms = array_values(array_unique(array_merge($platforms, (array)json_decode((string)$row['platforms_json'], true))));
            $samples   = array_slice(array_merge((array)json_decode((string)$row['posts_json'], true), $samples), 0, SCOUT_SAMPLE_POSTS);
        }
        $ins->execute([$term, count($d['posts']),
                       json_encode($authors, JSON_UNESCAPED_UNICODE),
                       json_encode($platforms, JSON_UNESCAPED_UNICODE),
                       json_encode($samples, JSON_UNESCAPED_UNICODE),
                       count($authors), count($platforms)]);
    }
    $stats['tracked'] = count($seen);
    // RETENTION (2026-09-05, measured: 37,574 rows, +5,819/day, 26 MB/day, no
    // pruning at all = 5 GB by spring). A word that has not been heard for 14
    // days and never reached the burst bar is noise; forget it. Bounded DELETE
    // so one tick never holds the table.
    try {
        $pdo->exec("DELETE FROM scout_vocab WHERE status='watching' AND last_seen < NOW() - INTERVAL 14 DAY AND n_authors < " . SCOUT_MIN_AUTHORS . " LIMIT 5000");
        $pdo->exec("DELETE FROM scout_vocab WHERE status='dismissed' AND last_seen < NOW() - INTERVAL 30 DAY LIMIT 5000");
    } catch (Throwable $e) {}

    // ---- 3. UNDERSTAND: screen the terms whose burst crossed the bar ------
    // 2026-09-05 MEASURED: this used to take the top 300 terms BY POST COUNT and
    // only then test the burst bar. The loudest terms are mostly one prolific
    // author, so a cap of 20 still screened ONE term while 821 qualifying words
    // sat outside the window - and slang went to zero behind it. The bar now
    // lives in SQL (n_authors / n_platforms are maintained on every upsert and
    // indexed), and the STRONGEST bursts - most distinct people - go first.
    $q = $pdo->query("SELECT * FROM scout_vocab WHERE status='watching'
                         AND ((n_platforms >= 2 AND n_authors >= " . SCOUT_MIN_AUTHORS . ")
                              OR n_authors >= " . SCOUT_MIN_AUTHORS_MONO . ")
                       ORDER BY n_authors DESC, n_platforms DESC, last_seen DESC
                       LIMIT " . (int)$screenCap);
    $due = $q->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/desk.php';
    foreach ($due as $row) {
        $term = (string)$row['term'];
        $samples = (array)json_decode((string)$row['posts_json'], true);
        // THE DESK (2026-09-05): a burst is a signal for the whole site, not
        // only for the vocabulary screen below. One signal per sample post.
        foreach (array_slice($samples, 0, 5) as $sp) {
            $plat = mb_strtolower((string)($sp['platform'] ?? ''));
            $org  = 'scout:' . $plat . (!empty($sp['sub']) ? ':' . mb_strtolower((string)$sp['sub']) : '');
            desk_signal($pdo, $org, $term, (string)($sp['url'] ?? $sp['permalink'] ?? ''), (string)($sp['author'] ?? ''), $plat);
        }
        $lines = [];
        foreach ($samples as $p) {
            $lines[] = '- [' . ($p['platform'] ?? '?') . '] @' . ($p['author'] ?? '?') . ': '
                     . mb_substr(preg_replace('/\s+/u', ' ', (string)($p['text'] ?? '')), 0, 220);
        }
        $res = ai_chat([
            ['role' => 'system', 'content' =>
                'You are a scout for a Gen Z internet-culture site. You are shown REAL social posts '
              . 'in which a candidate word/phrase appears. Judge ONLY from these posts, like a person '
              . 'who learned the word by scrolling — do not use outside knowledge of whether it is '
              . '"officially" slang. Output STRICT JSON only.'],
            ['role' => 'user', 'content' =>
                "Candidate: \"$term\"\n\nPosts:\n" . implode("\n", $lines) . "\n\n"
              . 'Return JSON: {"is_vocab": true|false, "lane": "slang"|"meme"|"gaming", '
              . '"meaning": "what it means, inferred ONLY from these usages, <=160 chars", '
              . '"confidence": 0.0-1.0, "heat": 0-100, "reason": "<=120 chars"}. '
              . 'is_vocab=true ONLY for a word/phrase people USE with a shared meaning, AND that reads '
              . 'like CURRENT internet culture — an emerging or actively-lived term. Decades-old everyday '
              . 'words and long-settled slang (bro, dude, cool, awesome) are is_vocab=false: real vocabulary, '
              . 'but nothing a trends site should page. '
              . 'A person, brand, product, event, place, team or one-off caption is is_vocab=false. '
              . 'confidence reflects how clearly the posts show a SHARED meaning across different authors.'],
        ], ['nvidia', 'gemini', 'openrouter'], 0.2, 90);
        $pdo = db_alive();   // the screen is an AI call — never trust the old handle
        $stats['screened']++;

        $j = isset($res['content']) ? ai_json($res['content']) : null;
        if (!$j) {
            echo "  scout: \"$term\" — screen failed (" . ($res['error'] ?? 'bad JSON') . "), stays on watch\n";
            continue;
        }
        $isVocab = !empty($j['is_vocab']);
        $conf    = (float)($j['confidence'] ?? 0);
        $lane    = in_array(($j['lane'] ?? ''), ['slang', 'meme', 'gaming'], true) ? $j['lane'] : 'slang';
        $why     = mb_substr((string)($j['reason'] ?? ''), 0, 240);

        if (!$isVocab || $conf < 0.6) {
            $pdo->prepare("UPDATE scout_vocab SET status='dismissed', verdict=? WHERE id=?")
                ->execute([mb_substr(($isVocab ? 'low confidence: ' : 'not vocabulary: ') . $why, 0, 255), (int)$row['id']]);
            echo "  scout: turned down \"$term\" — $why\n";
            continue;
        }

        // ---- 4. HAND OVER --------------------------------------------------
        $type = $lane === 'slang' ? 'term' : $lane;   // candidates.type enum
        $heat = max(55, min(90, (int)($j['heat'] ?? 60)));
        $pdo->prepare("INSERT INTO candidates (type,name,angle,heat_score,era,status,signals,ai_verdict)
                       VALUES (?,?,?,?,'present','selected',?,?)")
            ->execute([$type, $term, mb_substr((string)($j['meaning'] ?? ''), 0, 255), $heat,
                       json_encode(['scout' => true,
                                    'authors' => count((array)json_decode((string)$row['authors_json'], true)),
                                    'platforms' => (array)json_decode((string)$row['platforms_json'], true),
                                    'posts' => (int)$row['n_posts']], JSON_UNESCAPED_UNICODE),
                       json_encode($j, JSON_UNESCAPED_UNICODE)]);
        // stash the evidence posts where the drafter's citation tier reads them
        $sc = (array)json_decode((string)@file_get_contents(SCOUT_CACHE), true);
        $entry = ['x' => [], 'reddit' => [], 'youtube' => [], 'steam' => []];
        foreach ($samples as $p) {
            if (($p['platform'] ?? '') === 'x' && !empty($p['id'])) {
                $entry['x'][] = ['id' => (string)$p['id'], 'text' => (string)$p['text'],
                                 'author' => (string)$p['author'], 'likes' => (int)($p['likes'] ?? 0)];
            } elseif (($p['platform'] ?? '') === 'reddit' && !empty($p['permalink'])) {
                $entry['reddit'][] = ['title' => (string)$p['text'], 'text' => '',
                                      'author' => (string)$p['author'], 'created_utc' => (int)($p['created_utc'] ?? 0),
                                      'permalink' => (string)$p['permalink'], 'ups' => (int)($p['ups'] ?? 0)];
            } elseif (($p['platform'] ?? '') === 'steam' && !empty($p['steamid'])) {
                $entry['steam'][] = ['steamid' => (string)$p['steamid'], 'appid' => (int)($p['appid'] ?? 0),
                                     'author' => (string)$p['author'], 'text' => (string)$p['text'],
                                     'published' => (string)($p['published'] ?? ''), 'likes' => (int)($p['likes'] ?? 0)];
            } elseif (($p['platform'] ?? '') === 'youtube' && !empty($p['video'])) {
                $entry['youtube'][] = ['video' => (string)$p['video'], 'comment_id' => (string)($p['comment_id'] ?? ''),
                                       'author' => (string)$p['author'], 'text' => (string)$p['text'],
                                       'published' => (string)($p['published'] ?? ''), 'likes' => (int)($p['likes'] ?? 0)];
            }
        }
        $sc[$term] = $entry;
        @file_put_contents(SCOUT_CACHE, json_encode($sc, JSON_UNESCAPED_UNICODE));
        $pdo->prepare("UPDATE scout_vocab SET status='promoted', verdict=? WHERE id=?")
            ->execute([mb_substr("$lane, conf $conf: $why", 0, 255), (int)$row['id']]);
        $stats['promoted']++;
        echo "  scout: PROMOTED \"$term\" ($lane, heat $heat) — " . ($j['meaning'] ?? '') . "\n";
    }
    return $stats;
}
