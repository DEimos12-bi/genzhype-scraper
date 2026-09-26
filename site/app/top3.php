<?php
// GenZHype | TOP-3 TEST (owner rule, 2026-09-25): "Would a reader learn something
// here that the top 3 results don't tell them?" and "a dated timeline with links
// to the original posts is strong if it's more complete than other sites' versions".
// For one story: search what people type, read the first 3 results that are about
// the story (articles we could read in full; a result that is itself a post counts as a
// post the results show), and list what our page carries that none of them do: dated
// developments (by date) and original posts (by post id). The result is stored;
// gate_original_value() reads it. Nothing here blocks publishing by itself.
//
// Search: Exa (reach.php, the owner's key) for new stories. Measured the same day from
// this server: the Bing feed answered decoys (spam sites for "Ethan Klein vs Frogan
// lawsuit", nothing for the $118k story) and DuckDuckGo did not answer, while Exa returned
// the court filing and the outlets that covered each story. Old pages use Tavily (owner
// 2026-09-25, top3_old_run), so each search service keeps its own monthly allowance. Neither
// ranks like Google: the 3 are the most relevant other pages, not Google's exact top 3.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fetch_sources.php';
require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/embeds.php';

const TOP3_NEW_DATES_STRONG = 2;   // [ours] dated developments none of the 3 mention, for a "more complete" timeline
const TOP3_OLD_PER_DAY = 30;       // owner 2026-09-25: Tavily's free plan is 1,000 searches a month


/** Idempotent; run outside a transaction (CREATE TABLE commits one on MariaDB, r151). */
function top3_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS top3_checks (
        page_id INT NOT NULL PRIMARY KEY,
        query VARCHAR(255) NOT NULL,
        checked_at DATETIME NOT NULL,
        rivals MEDIUMTEXT NULL,
        our_dates INT NOT NULL DEFAULT 0,
        new_dates MEDIUMTEXT NULL,
        new_posts INT NOT NULL DEFAULT 0,
        timeline_strong TINYINT NOT NULL DEFAULT 0,
        learn_new TINYINT NOT NULL DEFAULT 0,
        verified TINYINT NOT NULL DEFAULT 0,
        engine VARCHAR(10) NOT NULL DEFAULT 'exa') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE top3_checks ADD COLUMN verified TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
    try { $pdo->exec("ALTER TABLE top3_checks ADD COLUMN engine VARCHAR(10) NOT NULL DEFAULT 'exa'"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/** What a reader would type: the editor's first top_query for this page, else the H1. */
function top3_query(PDO $pdo, int $pageId): string {
    $v = json_decode((string)$pdo->query("SELECT verdict FROM ai_reviews WHERE stage='quality' AND page_id=" . $pageId
                                         . " ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
    $q = trim((string)($v['top_queries'][0] ?? ''));
    if ($q === '') $q = (string)$pdo->query("SELECT h1 FROM pages WHERE id=" . $pageId)->fetchColumn();
    return mb_substr($q, 0, 200);
}

/** Month-day keys ("09-08") and month keys ("09") a text mentions (articles often omit the year). */
function top3_date_keys(string $text): array {
    static $mon = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
                   'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    $days = []; $months = [];
    $mrx = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';
    $key = fn($m, $d) => sprintf('%02d-%02d', $m, $d);
    if (preg_match_all('/\b' . $mrx . '\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $text, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) { $m = $mon[strtolower(substr($x[1], 0, 3))]; if ((int)$x[2] >= 1 && (int)$x[2] <= 31) $days[$key($m, (int)$x[2])] = 1; }
    if (preg_match_all('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mrx . '\b/i', $text, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) { $m = $mon[strtolower(substr($x[2], 0, 3))]; if ((int)$x[1] >= 1 && (int)$x[1] <= 31) $days[$key($m, (int)$x[1])] = 1; }
    if (preg_match_all('/\b20\d\d-(\d{2})-(\d{2})\b/', $text, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) if ((int)$x[1] >= 1 && (int)$x[1] <= 12 && (int)$x[2] >= 1) $days[$key((int)$x[1], (int)$x[2])] = 1;
    if (preg_match_all('#\b(\d{1,2})/(\d{1,2})/(?:20)?\d\d\b#', $text, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) if ((int)$x[1] >= 1 && (int)$x[1] <= 12 && (int)$x[2] >= 1 && (int)$x[2] <= 31) $days[$key((int)$x[1], (int)$x[2])] = 1;
    if (preg_match_all('/\b' . $mrx . '\b/i', $text, $mm)) foreach ($mm[1] as $x) $months[sprintf('%02d', $mon[strtolower(substr($x, 0, 3))])] = 1;
    return ['days' => $days, 'months' => $months];
}


/**
 * The search behind the test, remembered for the run: the re-check after step 3 adds posts
 * reads the same results without a second paid search. A failure is not remembered.
 * The hits, or ['error' => ...] when the service did not answer.
 */
function top3_search(string $engine, string $query): array {
    static $memo = [];
    $k = $engine . "\n" . $query;
    if (isset($memo[$k])) return $memo[$k];
    $hits = $engine === 'tavily' ? reach_tavily_search($query, 8) : reach_exa_search($query, 8);
    $err = $engine === 'tavily' ? reach_tavily_error() : reach_exa_error();
    if (!$hits && $err !== '') return ['error' => ucfirst($engine) . ' ' . $err];
    return $memo[$k] = $hits;
}

/**
 * Run the test for one story and store it. Returns the stored result, or ['error' => ...].
 * $engine: 'exa' (new stories, the build) or 'tavily' (old pages, top3_old_run).
 */
function top3_check(PDO $pdo, int $pageId, string $engine = 'exa'): array {
    top3_install($pdo);
    $d = $pdo->prepare("SELECT d.id FROM dramas d WHERE d.page_id=?");
    $d->execute([$pageId]);
    $did = (int)$d->fetchColumn();
    if (!$did) return ['error' => 'not a story page'];
    $query = top3_query($pdo, $pageId);
    if ($query === '') return ['error' => 'no query'];

    // the first 3 results that are about the story: at least half of the query's meaningful words, by
    // word start. Short names count ("gta"), generic words do not: "GTA 6 discovered shops timeline"
    // (page 1229) rejected every GTA 6 shop article for lacking "discovered" and "timeline".
    static $generic = ['timeline', 'discovered', 'explained', 'explain', 'update', 'updates', 'latest', 'news', 'what',
        'happened', 'drama', 'controversy', 'story', 'full', 'complete', 'guide', 'everything', 'details', 'reveal',
        'revealed', 'from', 'with', 'about', 'after', 'over', 'the', 'and', 'for', 'into', 'this', 'that', 'amid', 'why', 'how', 'who'];
    $words = array_values(array_unique(array_map(fn($w) => mb_substr($w, 0, 5), array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)),
        fn($w) => mb_strlen($w) >= 3 && !in_array($w, $generic, true)))));
    $about = fn(string $lc) => $words && count(array_filter($words, fn($w) => str_contains($lc, $w))) * 2 >= count($words);
    $rivals = []; $shown = [];
    $hits = top3_search($engine, $query);
    if (isset($hits['error'])) return ['error' => 'search unavailable: ' . $hits['error']];
    foreach ($hits as $hit) {
        if (count($rivals) >= 3) break;
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($hit['url'], PHP_URL_HOST)));
        if ($host === '' || str_ends_with($host, 'genzhype.com')) continue;
        // a result that is itself an original post is not an article to compare with: it is a post the
        // results show, which step 3 adds when we lack it (MrBeast's own X post ranked first for page 557)
        if ($pid = embed_post_id($hit['url'])) {
            if (!$about(mb_strtolower($hit['title'] . ' ' . $hit['text']))) continue;
            $plat = ['x' => 'twitter', 'tt' => 'tiktok', 'yt' => 'youtube', 'rd' => 'reddit'][strtok($pid, ':')];
            $url = $plat === 'youtube' ? 'https://www.youtube.com/watch?v=' . substr($pid, 3) : preg_replace('/[?#].*$/', '', $hit['url']);
            $handle = preg_match('#(?:x|twitter)\.com/(\w{1,15})/status/|tiktok\.com/@([\w.\-]+)/#', $url, $m) ? ($m[1] ?: $m[2]) : '';
            $shown[] = ['url' => $url, 'host' => $host, 'read' => 'post', 'chars' => 0, 'days' => 0,
                        'posts' => [['platform' => $plat, 'url' => $url, 'handle' => $handle]]];
            continue;
        }
        $html = fs_http_get($hit['url'], 20);
        $text = $html ? fs_extract_text($html) : '';
        $how = 'page';
        if (mb_strlen($text) < 600 && ($hit['raw'] ?? '') !== '') { $text = $hit['raw']; $how = 'search'; }   // Tavily's copy of the page
        elseif (mb_strlen($text) < 600 && $engine === 'exa') { $text = (string)reach_exa_fetch($hit['url']); $how = 'reader'; }
        // not read: a search snippet would make our page look more complete than it is (Tavily
        // sends no page text for Reddit or Facebook: 2 of the 3 "rivals" of page 999 were 130 characters)
        if (mb_strlen($text) < 600) continue;
        $lc = mb_strtolower($text . ' ' . $hit['title']);
        if (!$about($lc)) continue;          // not about this story
        $rivals[] = ['url' => $hit['url'], 'host' => $host, 'read' => $how, 'chars' => mb_strlen($text), 'text' => $lc, 'raw' => $text,
                     'dates' => top3_date_keys($text), 'posts' => $html ? embed_posts_in_article($html, $host) : []];   // real embeds only
    }
    if (!$rivals) {
        // stored as checked (no rivals, no credit): the old-page run pays for each page once
        $pdo->prepare("REPLACE INTO top3_checks (page_id, query, checked_at, rivals, engine) VALUES (?,?,UTC_TIMESTAMP(),'[]',?)")
            ->execute([$pageId, $query, $engine]);
        return ['error' => 'no result about this story (query: ' . $query . ')'];
    }

    // our page: dated developments and the original posts it shows
    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.embed_html, s.url FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? AND e.video_only=0");
    $ev->execute([$did]);
    $ourDays = []; $ourMonths = []; $ourPosts = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
        // keyed by the full date: "2025-08-01 TeamWater Launch" and "2026-08-01 TeamWater Progress Update"
        // were one "08-01" (page 557), and the AI reading was shown the update without its year
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$e['event_date'], $m) && $m[2] !== '00') {
            if ($m[3] !== '00') $ourDays[$m[0]] = (string)$e['title'];
            else $ourMonths["{$m[1]}-{$m[2]}"] = (string)$e['title'];
        }
        foreach ([(string)($e['embed_html'] ?? ''), (string)($e['url'] ?? '')] as $x) if ($x !== '' && ($pid = embed_post_id($x))) $ourPosts[$pid] = 1;
    }
    $new = [];
    foreach ([$ourDays, $ourMonths] as $set) foreach ($set as $k => $title) {
        $seen = false;
        foreach ($rivals as $r) if (top3_covered($r['text'], substr($k, 5), $title, $words)) { $seen = true; break; }   // articles often omit the year
        if (!$seen) $new[] = ['date' => $k, 'title' => $title];
    }
    // Word matching misses the same fact in other words ("selling their home" for "Home Sale
    // Considered", page 1232). An AI reading of the 3 pages may only REMOVE candidates: it must
    // quote where a page reports each one. If it cannot run, the result is unverified and the
    // timeline cannot count as strong.
    $verified = true; $proof = [];
    if ($new) {
        $keep = top3_ai_uncovered($new, $rivals, $proof);
        if ($keep === null) $verified = false; else $new = $keep;
    }
    $rivalPosts = [];
    foreach (array_merge($rivals, $shown) as $r) foreach ($r['posts'] as $p) if ($pid = embed_post_id($p['url'])) $rivalPosts[$pid] = 1;
    $newPosts = count(array_diff_key($ourPosts, $rivalPosts));

    $strong = $verified && top3_is_strong($pdo, $did, $new);
    $learn = count($new) > 0 || $newPosts > 0;
    $missing = count(array_diff_key($rivalPosts, $ourPosts));   // posts the rivals show and we do not (work for the maker)
    // unverified: the texts stay with the result, so top3_reverify() redoes the AI reading without a new search
    $store = array_map(fn($r) => ['url' => $r['url'], 'host' => $r['host'], 'read' => $r['read'], 'chars' => $r['chars'],
                                  'days' => count($r['dates']['days']), 'posts' => $r['posts']] + ($verified ? [] : ['raw' => $r['raw']]), $rivals);
    $store = array_merge($store, $shown);   // post lists: top3_add_missing_posts()
    $pdo->prepare("REPLACE INTO top3_checks (page_id, query, checked_at, rivals, our_dates, new_dates, new_posts, timeline_strong, learn_new, verified, engine)
                   VALUES (?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?)")
        ->execute([$pageId, $query, json_encode($store, JSON_UNESCAPED_SLASHES), count($ourDays) + count($ourMonths),
                   json_encode($new, JSON_UNESCAPED_UNICODE), $newPosts, (int)$strong, (int)$learn, (int)$verified, $engine]);
    return ['query' => $query, 'rivals' => $store, 'our_dates' => count($ourDays) + count($ourMonths), 'new_dates' => $new,
            'new_posts' => $newPosts, 'our_posts' => count($ourPosts), 'missing_posts' => $missing,
            'timeline_strong' => $strong, 'learn_new' => $learn, 'verified' => $verified, 'covered_by' => $proof];
}

/** The "timeline" strong item: original posts on our timeline AND enough dated developments none of the 3 report. */
function top3_is_strong(PDO $pdo, int $did, array $new): bool {
    require_once __DIR__ . '/gate.php';
    return (gate_original_value($pdo, $did)['receipts'] ?? 0) > 0 && count($new) >= TOP3_NEW_DATES_STRONG;
}

/**
 * Redo the AI reading of a stored unverified result from the rival texts kept with it (no search,
 * no credit): the AI did not answer for page 1421 on 2026-09-25 and answered a minute later.
 * The updated new developments, or null when there is nothing to redo or the AI did not answer again.
 */
function top3_reverify(PDO $pdo, int $pageId): ?array {
    $t = $pdo->query("SELECT t.rivals, t.new_dates, t.new_posts, t.verified, d.id did FROM top3_checks t JOIN dramas d ON d.page_id=t.page_id
                       WHERE t.page_id=" . $pageId)->fetch(PDO::FETCH_ASSOC);
    if (!$t || (int)$t['verified']) return null;
    $store = (array)json_decode((string)$t['rivals'], true);
    $rivals = array_values(array_filter($store, fn($r) => isset($r['raw'])));
    if (!$rivals) return null;
    $keep = top3_ai_uncovered((array)json_decode((string)$t['new_dates'], true), $rivals);
    if ($keep === null) return null;
    foreach ($store as &$r) unset($r['raw']);
    unset($r);
    $pdo->prepare("UPDATE top3_checks SET rivals=?, new_dates=?, timeline_strong=?, learn_new=?, verified=1 WHERE page_id=?")
        ->execute([json_encode($store, JSON_UNESCAPED_SLASHES), json_encode($keep, JSON_UNESCAPED_UNICODE),
                   (int)top3_is_strong($pdo, (int)$t['did'], $keep), (int)($keep || (int)$t['new_posts'] > 0), $pageId]);
    return $keep;
}

/**
 * The candidates no rival reports, per an AI reading that must prove each removal with a sentence
 * that is really in that article. Covered = the article reports the same action or outcome; the
 * same people or topic is not enough (Flash-Lite "covered" a police raid with a plot sentence about
 * a bank heist on page 1229, and a shared-word rule then let a mention of "Denims" cover "Frogan
 * hires Denims' legal team"). The reading can only remove candidates. $quotes collects the proof.
 * Returns null when the AI did not answer usably.
 */
function top3_ai_uncovered(array $cands, array $rivals, array &$quotes = []): ?array {
    // long pages: the passages around the candidates' words and months, not the first 5,000 characters
    $marks = [];
    foreach ($cands as $c) {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($c['title'])) as $w) if (mb_strlen($w) >= 5) $marks[] = $w;
        $marks[] = strtolower(date('F', mktime(0, 0, 0, (int)substr($c['date'], 5, 2), 1)));   // "YYYY-MM-DD" or "YYYY-MM"
    }
    $arts = '';
    foreach ($rivals as $i => $r) {
        $raw = preg_replace('/\s+/', ' ', $r['raw']);
        if (mb_strlen($raw) > 5000) {
            $lc = mb_strtolower($raw); $spans = [];
            foreach (array_unique($marks) as $w) {
                $off = 0;
                while (($pos = mb_strpos($lc, $w, $off)) !== false && count($spans) < 12) { $spans[] = [max(0, $pos - 500), $pos + 500]; $off = $pos + 1; }
            }
            usort($spans, fn($a, $b) => $a[0] <=> $b[0]);
            $txt = ''; $end = -1;
            foreach ($spans as [$a, $b]) { if ($a < $end) $a = $end; if ($a < $b) $txt .= ' ... ' . mb_substr($raw, $a, $b - $a); $end = max($end, $b); }
            $raw = mb_substr($txt !== '' ? $txt : $raw, 0, 6000);
        }
        $arts .= 'ARTICLE ' . ($i + 1) . " ({$r['host']}):\n{$raw}\n\n";
    }
    $evs = '';
    foreach ($cands as $i => $c) $evs .= ($i + 1) . ". [{$c['date']}] {$c['title']}\n";
    $res = ai_chat([
        ['role' => 'system', 'content' => 'For each numbered EVENT, decide whether ANY of the ARTICLES reports that same event: the same '
            . 'action or outcome (who did what), even without its date or in other words. Mentioning the same people or the same topic '
            . 'is NOT enough. If an article reports it, copy the exact sentence from that article that reports it. '
            . 'Output STRICT JSON only: {"events":[{"n":1,"covered":true,"article":1,"quote":"exact sentence"}]}'],
        ['role' => 'user', 'content' => "EVENTS:\n{$evs}\n{$arts}"],
    ], AI_READER_ORDER, 0.1, 90, AI_READER_SKIP);
    if (isset($res['error'])) return null;
    $j = ai_json((string)$res['content']);
    if (!is_array($j) || !isset($j['events']) || !is_array($j['events'])) return null;
    $norm = fn(string $t) => preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t));
    $covered = [];
    foreach ($j['events'] as $e) {
        $n = (int)($e['n'] ?? 0); $a = (int)($e['article'] ?? 0) - 1;
        if (empty($e['covered']) || !isset($cands[$n - 1], $rivals[$a])) continue;
        $q = trim($norm((string)($e['quote'] ?? '')));
        // the proof must really be in that article (its first 60 characters, punctuation-blind)
        if (mb_strlen($q) >= 20 && str_contains($norm($rivals[$a]['raw']), mb_substr($q, 0, 60))) {
            $covered[$n] = 1;
            $quotes[] = ['event' => $cands[$n - 1]['date'] . ' ' . $cands[$n - 1]['title'], 'host' => $rivals[$a]['host'], 'quote' => (string)$e['quote']];
        }
    }
    $answered = array_filter(array_map(fn($e) => (int)($e['n'] ?? 0), $j['events']));
    $keep = [];
    foreach ($cands as $i => $c) {
        if (!in_array($i + 1, $answered, true)) return null;   // an event left unanswered: not a usable reading
        if (!isset($covered[$i + 1])) $keep[] = $c;
    }
    return $keep;
}

/**
 * The one case where word matching may decide on its own that a rival already reports our event:
 * the exact day (either order: "November 19", "19 November") within 250 characters of a word from
 * our event title that is rare in that rival (1-3 mentions). Everything else goes to the AI reading
 * with quoted proof (top3_ai_uncovered). Looser matching failed every way it was tried on
 * 2026-09-25: a date anywhere (Wikipedia's 148 dates), a month near a common word ("legal" covered a
 * GoFundMe), a word count in a page that loaded short (the same GoFundMe again).
 */
function top3_covered(string $lcText, string $key, string $title, array $queryWords): bool {
    static $mn = ['01' => 'jan', '02' => 'feb', '03' => 'mar', '04' => 'apr', '05' => 'may', '06' => 'jun',
                  '07' => 'jul', '08' => 'aug', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dec'];
    static $generic = ['announces', 'announced', 'reports', 'reported', 'claims', 'claimed', 'timeline', 'update',
                       'reveals', 'revealed', 'responds', 'response', 'statement', 'addresses', 'confirms', 'after', 'about'];
    if (strlen($key) !== 5) return false;                     // month-only events: the AI reading decides
    $m = substr($key, 0, 2); $day = (int)substr($key, 3, 2);
    $rare = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)), fn($w) => mb_strlen($w) >= 5
        && !in_array($w, $queryWords, true) && !in_array($w, $generic, true)
        && ($c = substr_count($lcText, $w)) >= 1 && $c <= 3));
    if (!$rare) return false;
    $dayRx = '/\b' . $mn[$m] . '[a-z]*\.?\s+' . $day . '(?!\d)|(?<!\d)' . $day . '(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mn[$m] . '/u';
    if (!preg_match_all($dayRx, $lcText, $hits, PREG_OFFSET_CAPTURE)) return false;
    foreach ($hits[0] as [$str, $off]) {
        $win = substr($lcText, max(0, $off - 250), 500 + strlen($str));
        foreach ($rare as $w) if (str_contains($win, $w)) return true;
    }
    return false;
}

/** When a post was made: X and TikTok from the id (both embed a timestamp), YouTube from its API
 *  (the youtube_key drama_image.php already uses, 1 quota unit); '' when unknown (Reddit). */
function top3_post_date(string $url): string {
    if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/)([\w\-]{11})#', $url, $m)) {
        require_once __DIR__ . '/drama_image.php';
        $at = (string)(yt_video_snippet($m[1])['publishedAt'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $at) ? substr($at, 0, 10) : '';
    }
    if (preg_match('#(?:twitter|x)\.com/[A-Za-z0-9_]{1,15}/status/(\d{10,})#', $url, $m))
        return gmdate('Y-m-d', intdiv(((int)$m[1] >> 22) + 1288834974657, 1000));   // X snowflake: ms since Twitter's epoch
    if (preg_match('#tiktok\.com/@[\w.\-]+/video/(\d{10,})#', $url, $m))
        return gmdate('Y-m-d', (int)$m[1] >> 32);                                    // TikTok: seconds in the top 32 bits
    return '';
}

/**
 * STEP 3 (owner, 2026-09-25): the original posts the top-3 pages show and we do not become dated
 * events on our timeline, each showing the post. X, TikTok and YouTube, whose posting date is
 * exact (top3_post_date); Reddit is left out (no date). Each post must still be up
 * (embed_for_url), be about this story (its text or author names a story person or query word),
 * and its event text must pass the fact guard against the post itself and carry attribution.
 * Reads the stored top-3 result; run top3_check() again afterwards to update the credit.
 * $save false = preview: returns the events it would add ('would_add') and writes nothing.
 */
function top3_add_missing_posts(PDO $pdo, int $pageId, int $max = 4, bool $save = true): array {
    require_once __DIR__ . '/fetch_sources.php';
    require_once __DIR__ . '/fact_guard.php';
    require_once __DIR__ . '/framing_repair.php';
    require_once __DIR__ . '/timeline_order.php';
    $out = ['added' => 0, 'attached' => 0, 'skipped' => []];
    $row = $pdo->prepare("SELECT t.rivals, t.query, d.id did, d.people_json, d.primary_kw, p.summary FROM top3_checks t
                          JOIN dramas d ON d.page_id=t.page_id JOIN pages p ON p.id=t.page_id WHERE t.page_id=?");
    $row->execute([$pageId]);
    $t = $row->fetch(PDO::FETCH_ASSOC);
    if (!$t) return $out + ['error' => 'no top-3 result stored: run top3_check first'];
    $did = (int)$t['did'];

    // posts already on our page, and our timeline (numbered: a post may be the one behind an event we have)
    $ours = []; $timeline = ''; $tl = [];
    $ev = $pdo->prepare("SELECT e.id, e.event_date, e.title, e.embed_html, e.video_only, s.url FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? ORDER BY e.sort_order");
    $ev->execute([$did]);
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
        foreach ([(string)($e['embed_html'] ?? ''), (string)($e['url'] ?? '')] as $x) if ($x !== '' && ($pid = embed_post_id($x))) $ours[$pid] = 1;
        if ((int)$e['video_only']) continue;
        $tl[] = ['id' => (int)$e['id'], 'has_post' => (string)($e['embed_html'] ?? '') !== ''];
        $timeline .= count($tl) . ". [{$e['event_date']}] {$e['title']}\n";
    }

    // what makes a post "about this story": a story person's name or a query word (4+ letters)
    $tokens = [];
    foreach ((array)json_decode((string)$t['people_json'], true) as $name)
        foreach (preg_split('/\s+/', mb_strtolower((string)$name)) as $w) if (mb_strlen($w) >= 4) $tokens[$w] = 1;
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$t['query'])) as $w) if (mb_strlen($w) >= 4 && !in_array($w, ['timeline', 'lawsuit', 'drama', 'controversy'], true)) $tokens[$w] = 1;

    $cands = []; $seen = [];
    foreach ((array)json_decode((string)$t['rivals'], true) as $r) foreach ((array)($r['posts'] ?? []) as $p) {
        $pid = embed_post_id((string)$p['url']);
        if (!$pid || isset($ours[$pid]) || isset($seen[$pid])) continue;
        $seen[$pid] = 1;
        if (!in_array($p['platform'], ['twitter', 'tiktok', 'youtube'], true)) { $out['skipped'][] = "{$p['url']}: {$p['platform']} posts carry no date"; continue; }
        $cands[$pid] = $p + ['from' => $r['host']];
    }
    $posts = [];
    foreach ($cands as $pid => $p) {
        if (count($posts) >= $max) break;
        $date = top3_post_date($p['url']);
        if ($date === '') { $out['skipped'][] = "{$p['url']}: posting date not found"; continue; }
        $emb = embed_for_url($p['url']);
        if (!$emb) { $out['skipped'][] = "{$p['url']}: no longer online"; continue; }
        $text = fs_social_excerpt($p['platform'], $p['url']);
        if ($text === '') { $out['skipped'][] = "{$p['url']}: no text"; continue; }
        $lc = mb_strtolower($text . ' ' . $p['handle']);
        $about = false;
        foreach (array_keys($tokens) as $w) if (str_contains($lc, $w)) { $about = true; break; }
        if (!$about) { $out['skipped'][] = "{$p['url']}: not about this story"; continue; }
        $posts[] = ['url' => $p['url'], 'platform' => $p['platform'], 'handle' => $p['handle'], 'date' => $date, 'text' => $text, 'embed' => $emb, 'from' => $p['from']];
    }
    if (!$posts) return $out;

    // one AI call writes each post as a timeline event; the fact guard holds it to the post's own words
    $list = '';
    $plat = fn(string $x) => ['twitter' => 'X', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'][$x] ?? $x;
    foreach ($posts as $i => $p) $list .= ($i + 1) . ". date {$p['date']}, " . $plat($p['platform']) . ' post' . ($p['handle'] !== '' ? " by @{$p['handle']}" : '') . ": {$p['text']}\n";
    $res = ai_chat([
        // With the story and our numbered timeline in view (without them one run wrote the kid's
        // channel-ending video as an event and the next skipped it), each post is either the one
        // behind an event we already have (same_as: that event shows it), a new development, or off-topic.
        ['role' => 'system', 'content' => "For each POST about the STORY below: if it is the original post behind an event already in TIMELINE, "
            . "set same_as to that event's number. If it is a new development, set same_as to 0 and write title (at most 90 characters, "
            . "sentence case: capitals only for the first word and names) and desc (1-2 sentences starting 'According to a post by <author> "
            . "on <platform>,'), using ONLY what the post says: no other names, numbers or claims. The title says what happened, never "
            . "'post by' or 'original post'. Set skip to true only when the post "
            . 'is not about this story. Output STRICT JSON only: {"events":[{"n":1,"skip":false,"same_as":0,"title":"...","desc":"..."}]}'],
        ['role' => 'user', 'content' => "STORY: {$t['summary']}\n\nTIMELINE:\n{$timeline}\nPOSTS:\n{$list}"],
    ], AI_READER_ORDER, 0.0, 90, AI_READER_SKIP);
    $j = isset($res['error']) ? null : ai_json((string)$res['content']);
    if (!is_array($j) || !isset($j['events'])) return $out + ['error' => 'AI did not write the events: ' . ($res['error'] ?? 'bad JSON')];
    $out['model'] = (string)($res['model'] ?? $res['provider'] ?? '');
    $answered = [];

    $insSrc = $pdo->prepare("INSERT INTO sources (url, domain, publisher, title, reliability, retrieved_on, excerpt) VALUES (?,?,?,?,?,?,?)");
    $insEv = $pdo->prepare("INSERT INTO events (drama_id, event_date, title, description, source_id, is_confirmed, sort_order, embed_html, embed_provider)
                            VALUES (?,?,?,?,?,0,9999,?,?)");
    $attach = $pdo->prepare("UPDATE events SET embed_html=?, embed_provider=? WHERE id=? AND (embed_html IS NULL OR embed_html='')");
    foreach ($j['events'] as $e) {
        $p = $posts[(int)($e['n'] ?? 0) - 1] ?? null;
        if ($p) $answered[(int)$e['n']] = 1;
        if (!$p || !empty($e['skip'])) { if ($p) $out['skipped'][] = "{$p['url']}: not about this story"; continue; }
        $same = (int)($e['same_as'] ?? 0);
        if ($same > 0) {   // the post behind an event we have: that event now shows it
            $target = $tl[$same - 1] ?? null;
            if (!$target) { $out['skipped'][] = "{$p['url']}: matched an event number that does not exist"; continue; }
            if ($target['has_post']) { $out['skipped'][] = "{$p['url']}: its event already shows a post"; continue; }
            if (!$save) { $out['would_attach'][] = ['event' => $same, 'url' => $p['url'], 'from' => $p['from']]; continue; }
            $attach->execute([$p['embed']['html'], $p['embed']['provider'], $target['id']]);
            $out['attached'] += $attach->rowCount();
            continue;
        }
        $title = mb_substr(trim((string)($e['title'] ?? '')), 0, 90);
        $desc = trim((string)($e['desc'] ?? ''));
        $human = $p['date'] !== '' ? date('F j, Y', strtotime($p['date'])) : '';
        $drift = fact_drift($title . "\n" . $desc, $p['text'] . "\n@{$p['handle']} {$p['handle']} {$human} {$p['date']} X TikTok YouTube\n" . $t['primary_kw'], []);
        if ($title === '' || $desc === '' || $drift) { $out['skipped'][] = "{$p['url']}: event text not held to the post" . ($drift ? ' (' . implode(', ', array_slice($drift, 0, 3)) . ')' : ''); continue; }
        if (preg_match('/\b(post by|original post)\b/i', $title)) { $out['skipped'][] = "{$p['url']}: title does not say what happened ({$title})"; continue; }
        if (!preg_match('/' . FR_FRAMING_RX . '/i', $desc)) { $out['skipped'][] = "{$p['url']}: event text lacks attribution"; continue; }
        if (!$save) { $out['would_add'][] = ['date' => $p['date'], 'title' => $title, 'desc' => $desc, 'url' => $p['url'], 'from' => $p['from']]; continue; }
        $insSrc->execute([$p['url'], preg_replace('/^www\./', '', (string)parse_url($p['url'], PHP_URL_HOST)), $plat($p['platform']) . ' (original post)',
                          mb_substr($p['text'], 0, 200), 'primary', $p['date'], $p['text']]);
        $insEv->execute([$did, $p['date'], $title, $desc, (int)$pdo->lastInsertId(), $p['embed']['html'], $p['embed']['provider']]);
        $out['added']++;
    }
    foreach ($posts as $i => $p) if (!isset($answered[$i + 1]))   // the start of the raw answer, so the next case explains itself
        $out['skipped'][] = "{$p['url']}: the AI gave no answer for it (" . mb_substr(preg_replace('/\s+/', ' ', (string)$res['content']), 0, 120) . ')';
    if ($out['added'] || $out['attached']) {
        events_resort($pdo, $did);
        if ($out['added']) page_content_touched($pdo, $pageId);   // new dated events: the public date moves (db.php)
        else $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);   // a post on an event we had: cache only
    }
    return $out;
}

/** The old-page run's queue, in its order (see top3_old_run): [id, path, robots, coverage]. */
function top3_old_targets(PDO $pdo): array {
    top3_install($pdo);
    return $pdo->query("SELECT p.id, p.path, p.robots, g.coverage FROM pages p JOIN dramas d ON d.page_id=p.id
                         LEFT JOIN gsc_inspection g ON g.page_id=p.id LEFT JOIN top3_checks t ON t.page_id=p.id
                         WHERE p.type='drama' AND p.status='published' AND t.page_id IS NULL
                           AND p.published_at < UTC_TIMESTAMP() - INTERVAL 2 DAY AND (g.page_id IS NULL OR g.verdict <> 'PASS')
                         ORDER BY p.robots='index' DESC, g.coverage LIKE 'Discovered%' DESC, p.published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * OLD PAGES (owner 2026-09-25): the live stories Google has not indexed get the test once, on
 * Tavily (1 credit a page; Exa stays with new stories), then step 3 adds the posts the top 3
 * show and we lack. Each page is searched once: a stored result, "no rivals" included, is never
 * searched again. Before a credit is spent the page's Search Console status is read again
 * (free): a page Google has indexed since is left out. Indexable pages first, then the ones
 * Google has discovered, newest first; stories younger than 2 days are left to the build's own
 * test. At most TOP3_OLD_PER_DAY searches a UTC day; a refused search (credits used up, rate
 * limit, key) ends the run without spending anything. Readings the AI did not answer (provider
 * outages: NVIDIA HTTP 503 with every fallback down, 2026-09-25) are redone first, from the
 * texts kept with them (top3_reverify): no search.
 */
function top3_old_run(PDO $pdo, int $limit = 3, int $maxSecs = 360): array {
    top3_install($pdo);
    require_once __DIR__ . '/ga4.php';
    $t0 = time();
    $out = ['checked' => 0, 'no_rivals' => 0, 'in_google' => 0, 'strong' => 0, 'added' => 0, 'attached' => 0, 'reverified' => 0, 'stopped' => '', 'lines' => []];
    // first, readings the AI did not answer (any engine, the last 3 days): no search needed
    foreach ($pdo->query("SELECT page_id FROM top3_checks WHERE verified=0 AND rivals LIKE '%\"raw\"%'
                         AND checked_at > UTC_TIMESTAMP() - INTERVAL 3 DAY ORDER BY checked_at LIMIT 3")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $keep = top3_reverify($pdo, (int)$pid);
        if ($keep === null) continue;
        $out['reverified']++;
        $out['lines'][] = "{$pid}: AI reading redone, " . count($keep) . ' new dated development(s)';
    }
    $today = (int)$pdo->query("SELECT COUNT(*) FROM top3_checks WHERE engine='tavily' AND checked_at >= UTC_DATE()")->fetchColumn();
    if ($today >= TOP3_OLD_PER_DAY) { $out['stopped'] = "today's " . TOP3_OLD_PER_DAY . ' searches are done'; return $out; }
    $left = min($limit, TOP3_OLD_PER_DAY - $today);
    foreach (top3_old_targets($pdo) as $r) {
        if ($left <= 0 || time() - $t0 > $maxSecs) break;
        $pid = (int)$r['id'];
        $gs = gsc_inspect($pdo, $pid, rtrim((string)$GLOBALS['CONFIG']['base_url'], '/') . $r['path']);
        if ($gs && $gs['verdict'] === 'PASS') { $out['in_google']++; $out['lines'][] = "{$pid}: now in Google, left out"; continue; }
        $cov = $gs['coverage'] ?? ((string)$r['coverage'] !== '' ? $r['coverage'] : 'never inspected');
        $res = top3_check($pdo, $pid, 'tavily');
        if (isset($res['error']) && str_starts_with($res['error'], 'search unavailable')) { $out['stopped'] = $res['error']; break; }
        $left--;
        if (isset($res['error'])) { $out['no_rivals']++; $out['lines'][] = "{$pid} ({$cov}): {$res['error']}"; $pdo = db_alive(); continue; }
        $out['checked']++;
        $note = '';
        if ($res['missing_posts'] > 0) {
            $fp = top3_add_missing_posts($pdo, $pid);
            $out['added'] += $fp['added']; $out['attached'] += $fp['attached'];
            if ($fp['added'] || $fp['attached']) {
                $note = ", added {$fp['added']} post(s), attached {$fp['attached']}";
                $re = top3_check($pdo, $pid, 'tavily');   // same search results (top3_search), no second credit
                if (!isset($re['error'])) $res = $re;
            }
        }
        if (!empty($res['timeline_strong'])) $out['strong']++;
        $out['lines'][] = "{$pid} ({$cov}): vs " . implode(', ', array_map(fn($r) => $r['host'] . ($r['read'] === 'post' ? ' (a post)' : ''), $res['rivals'] ?? [])) . '; '
            . count($res['new_dates'] ?? []) . " new dated development(s), {$res['new_posts']} post(s) the 3 lack{$note}; timeline strong: "
            . (!empty($res['timeline_strong']) ? 'yes' : 'no') . (!empty($res['verified']) ? '' : ' (unverified)');
        $pdo = db_alive();   // minutes of fetching and AI: the handle may be dead
    }
    return $out;
}
