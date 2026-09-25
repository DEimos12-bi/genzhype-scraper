<?php
// GenZHype | TOP-3 TEST (owner rule, 2026-09-25): "Would a reader learn something
// here that the top 3 results don't tell them?" and "a dated timeline with links
// to the original posts is strong if it's more complete than other sites' versions".
// For one story: search what people type, read the first 3 results that are about
// the story, and list what our page carries that none of them do: dated
// developments (by date) and original posts (by post id). The result is stored;
// gate_original_value() reads it. Nothing here blocks publishing by itself.
//
// Search: Exa (keyless, reach.php). Measured the same day from this server: the
// Bing feed answered decoys (spam sites for "Ethan Klein vs Frogan lawsuit",
// nothing for the $118k story) and DuckDuckGo did not answer, while Exa returned
// the court filing and the outlets that covered each story. Exa's order is not
// Google's: the 3 are the most relevant other pages, not Google's exact top 3.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fetch_sources.php';
require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/ai.php';

const TOP3_NEW_DATES_STRONG = 2;   // [ours] dated developments none of the 3 mention, for a "more complete" timeline

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
        verified TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE top3_checks ADD COLUMN verified TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
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

/** Month-day keys ("09-08") and month keys ("09") a text mentions. */
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

/** Post ids (X status, TikTok video, YouTube video, Reddit thread) found in a text or an embed. */
function top3_post_ids(string $s): array {
    $ids = [];
    if (preg_match_all('#(?:twitter|x)\.com/[^/\s"\']+/status(?:es)?/(\d{8,})#i', $s, $m)) foreach ($m[1] as $x) $ids['x:' . $x] = 1;
    if (preg_match_all('#tiktok\.com/@[^/\s"\']+/video/(\d{8,})#i', $s, $m)) foreach ($m[1] as $x) $ids['tt:' . $x] = 1;
    if (preg_match_all('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/|videoid=")([A-Za-z0-9_-]{11})#i', $s, $m)) foreach ($m[1] as $x) $ids['yt:' . $x] = 1;
    if (preg_match_all('#reddit\.com/r/[^/\s"\']+/comments/([a-z0-9]{5,})#i', $s, $m)) foreach ($m[1] as $x) $ids['rd:' . strtolower($x)] = 1;
    return array_keys($ids);
}

/** Run the test for one story and store it. Returns the stored result, or ['error' => ...]. */
function top3_check(PDO $pdo, int $pageId): array {
    top3_install($pdo);
    $d = $pdo->prepare("SELECT d.id FROM dramas d WHERE d.page_id=?");
    $d->execute([$pageId]);
    $did = (int)$d->fetchColumn();
    if (!$did) return ['error' => 'not a story page'];
    $query = top3_query($pdo, $pageId);
    if ($query === '') return ['error' => 'no query'];

    // the first 3 results that are about the story: at least half of the query's words (4+ letters) in the text
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)), fn($w) => mb_strlen($w) >= 4));
    $rivals = [];
    $hits = reach_exa_search($query, 8);
    if (!$hits && reach_exa_error() !== '') return ['error' => 'search unavailable: Exa ' . reach_exa_error()];
    foreach ($hits as $hit) {
        if (count($rivals) >= 3) break;
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($hit['url'], PHP_URL_HOST)));
        if ($host === '' || str_ends_with($host, 'genzhype.com')) continue;
        $html = fs_http_get($hit['url'], 20);
        $text = $html ? fs_extract_text($html) : '';
        $how = 'page';
        if (mb_strlen($text) < 600) { $text = (string)reach_exa_fetch($hit['url']); $how = 'reader'; }
        if (mb_strlen($text) < 600) { $text = (string)$hit['text']; $how = 'highlights'; }
        $lc = mb_strtolower($text . ' ' . $hit['title']);
        $hits = count(array_filter($words, fn($w) => str_contains($lc, $w)));
        if (!$words || $hits * 2 < count($words)) continue;          // not about this story
        $rivals[] = ['url' => $hit['url'], 'host' => $host, 'read' => $how, 'chars' => mb_strlen($text), 'text' => $lc, 'raw' => $text,
                     'dates' => top3_date_keys($text), 'posts' => top3_post_ids(($html ?: '') . ' ' . $text)];
    }
    if (!$rivals) return ['error' => 'no result about this story (query: ' . $query . ')'];

    // our page: dated developments and the original posts it shows
    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.embed_html, s.url FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? AND e.video_only=0");
    $ev->execute([$did]);
    $ourDays = []; $ourMonths = []; $ourPosts = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', (string)$e['event_date'], $m) && $m[1] !== '00') {
            if ($m[2] !== '00') $ourDays["{$m[1]}-{$m[2]}"] = (string)$e['title'];
            else $ourMonths[$m[1]] = (string)$e['title'];
        }
        if ((string)($e['embed_html'] ?? '') !== '') foreach (top3_post_ids((string)$e['embed_html'] . ' ' . (string)$e['url']) as $pid) $ourPosts[$pid] = 1;
    }
    $new = [];
    foreach ([$ourDays, $ourMonths] as $set) foreach ($set as $k => $title) {
        $seen = false;
        foreach ($rivals as $r) if (top3_covered($r['text'], $k, $title, $words)) { $seen = true; break; }
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
    foreach ($rivals as $r) foreach ($r['posts'] as $p) $rivalPosts[$p] = 1;
    $newPosts = count(array_diff_key($ourPosts, $rivalPosts));

    require_once __DIR__ . '/gate.php';
    $receipts = gate_original_value($pdo, $did)['receipts'] ?? 0;
    $strong = $verified && $receipts > 0 && count($new) >= TOP3_NEW_DATES_STRONG;
    $learn = count($new) > 0 || $newPosts > 0;
    $missing = count(array_diff_key($rivalPosts, $ourPosts));   // posts the rivals show and we do not (work for the maker)
    $store = array_map(fn($r) => ['url' => $r['url'], 'host' => $r['host'], 'read' => $r['read'], 'chars' => $r['chars'],
                                  'days' => count($r['dates']['days']), 'posts' => count($r['posts'])], $rivals);
    $pdo->prepare("REPLACE INTO top3_checks (page_id, query, checked_at, rivals, our_dates, new_dates, new_posts, timeline_strong, learn_new, verified)
                   VALUES (?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?)")
        ->execute([$pageId, $query, json_encode($store, JSON_UNESCAPED_SLASHES), count($ourDays) + count($ourMonths),
                   json_encode($new, JSON_UNESCAPED_UNICODE), $newPosts, (int)$strong, (int)$learn, (int)$verified]);
    return ['query' => $query, 'rivals' => $store, 'our_dates' => count($ourDays) + count($ourMonths), 'new_dates' => $new,
            'new_posts' => $newPosts, 'our_posts' => count($ourPosts), 'missing_posts' => $missing,
            'timeline_strong' => $strong, 'learn_new' => $learn, 'verified' => $verified, 'covered_by' => $proof];
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
        $marks[] = strtolower(date('F', mktime(0, 0, 0, (int)substr($c['date'], 0, 2), 1)));
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
    ], ['groq', 'nvidia', 'gemini'], 0.1, 90, ['gemini/gemma-4-31b-it', 'groq/qwen/qwen3.8-27b']);
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
 * Does a rival text already report our event? It must name the event's month (with or without
 * the day, "May 2026" counts for May 20) within 250 characters of a distinctive word from our
 * event title that is rare in that rival. A date alone is not enough: a long page (Wikipedia,
 * 148 dates) matched our dates by coincidence; and "in May 2026, a court clerk entered a default"
 * does cover "May 20: clerk enters default" (page 1134). When the only shared words are topic
 * words, or the title has none, the exact day must appear.
 */
function top3_covered(string $lcText, string $key, string $title, array $queryWords): bool {
    static $mn = ['01' => 'jan', '02' => 'feb', '03' => 'mar', '04' => 'apr', '05' => 'may', '06' => 'jun',
                  '07' => 'jul', '08' => 'aug', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dec'];
    static $generic = ['announces', 'announced', 'reports', 'reported', 'claims', 'claimed', 'timeline', 'update',
                       'reveals', 'revealed', 'responds', 'response', 'statement', 'addresses', 'confirms', 'after', 'about'];
    $m = substr($key, 0, 2);
    $day = strlen($key) === 5 ? (int)substr($key, 3, 2) : 0;
    $tw = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)),
        fn($w) => mb_strlen($w) >= 5 && !in_array($w, $queryWords, true) && !in_array($w, $generic, true)));
    $monthRx = '/\b' . $mn[$m] . '[a-z]*\.?|\b20\d\d-' . $m . '-\d\d\b|\b' . (int)$m . '\/\d{1,2}\/(?:20)?\d\d\b/u';
    // the exact day in either order ("November 19", "19 November": Wikipedia wrote the latter, page 1229)
    $dayRx = '/\b' . $mn[$m] . '[a-z]*\.?\s+' . $day . '(?!\d)|(?<!\d)' . $day . '(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mn[$m] . '/u';
    $near = function (string $rx, array $words) use ($lcText): bool {
        if (!$words || !preg_match_all($rx, $lcText, $hits, PREG_OFFSET_CAPTURE)) return false;
        foreach ($hits[0] as [$str, $off]) {
            $win = substr($lcText, max(0, $off - 250), 500 + strlen($str));
            foreach ($words as $w) if (str_contains($win, $w)) return true;
        }
        return false;
    };
    if (!$tw) return $day > 0 && preg_match($dayRx, $lcText) === 1;   // a title with no distinctive word
    // a title word marks THIS event only when it is rare in the rival (1-3 mentions): "legal" runs all
    // through a lawsuit article and matched the GoFundMe event by accident; "gofundme", "clerk" do not
    $present = array_values(array_filter($tw, fn($w) => substr_count($lcText, $w) >= 1));
    $rare = array_values(array_filter($present, fn($w) => substr_count($lcText, $w) <= 3));
    if ($rare) return $near($monthRx, $rare);
    // only topic words in common ("police" x7 on Wikipedia): the exact day must sit next to one of them,
    // or a page with 148 dates covers ours by coincidence
    return $day > 0 && $near($dayRx, $present);
}
