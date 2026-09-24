<?php
// GenZHype | QUALITY controller. The judgment layer code can't do:
// does the page satisfy real search intent, Google's people-first bar,
// and GEO citability? Runs as its own stage; publish requires a pass.

require_once __DIR__ . '/ai.php';

const QUALITY_MIN_SCORE = 7; // every dimension must reach this

/**
 * 2026-09-24 JUDGE THE PAGE READERS SEE. The controller used to get every
 * timeline event stamped "[UNCONFIRMED]" and no sources at all, while the page
 * shows each event with the outlet that reported it and never prints an
 * "unconfirmed" label. Of 259 pages judged in 21 days 9 passed, and the
 * controller's own fix notes asked for "sources/evidence" 428 times. It now
 * gets each event with its attribution (primary-source confirmations named as
 * such), the list of sources the page cites, and the latest dated event next
 * to the status. The bar is unchanged: every score >= 7, no red flag.
 * $over replaces page fields (title_tag, h1, meta_desc, summary, background,
 * faqs) so a proposed rewrite can be judged before it is saved.
 */
function quality_page_fields(int $page_id): ?array {
    $pdo = db();
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.primary_kw, d.lifecycle, d.background
                        FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return null;
    $did = (int)$page['drama_id'];
    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.description, e.is_confirmed, s.url, s.publisher,
                                (e.embed_html IS NOT NULL AND e.embed_html <> '') embedded
                         FROM events e LEFT JOIN sources s ON s.id = e.source_id
                         WHERE e.drama_id=? AND e.video_only=0 ORDER BY e.sort_order, e.event_date");   // what the page shows (repo.php)
    $ev->execute([$did]);
    $events = []; $pubs = []; $last = '';
    foreach ($ev->fetchAll() as $e) {
        $host = preg_replace('/^www\./', '', (string)parse_url((string)($e['url'] ?? ''), PHP_URL_HOST));
        $who = trim((string)($e['publisher'] ?? '')) ?: $host;
        $events[] = ['date' => (string)$e['event_date'], 'title' => (string)$e['title'], 'desc' => (string)$e['description'],
                     'confirmed' => (int)$e['is_confirmed'] === 1, 'who' => $who, 'host' => $host,
                     'embedded' => (int)$e['embedded'] === 1];   // the original post is shown on the page
        if ($who !== '') $pubs[$who] = 1;
        if ((string)$e['event_date'] > $last) $last = (string)$e['event_date'];
    }
    $fq = $pdo->prepare("SELECT question, answer FROM faqs WHERE drama_id=? ORDER BY sort_order");
    $fq->execute([$did]);
    return ['page_id' => $page_id, 'drama_id' => $did, 'primary_kw' => (string)$page['primary_kw'],
            'title_tag' => (string)$page['title_tag'], 'h1' => (string)$page['h1'], 'meta_desc' => (string)$page['meta_desc'],
            'summary' => (string)$page['summary'], 'lifecycle' => (string)$page['lifecycle'],
            'background' => json_decode($page['background'] ?? '[]', true) ?: [],
            'faqs' => array_map(fn($f) => ['q' => (string)$f['question'], 'a' => (string)$f['answer']], $fq->fetchAll()),
            'events' => $events, 'sources' => array_keys($pubs), 'last_date' => $last];
}

/**
 * Judge a page's fields (from quality_page_fields, optionally with a rewrite merged in). No logging.
 * 2026-09-24 the judge is told today's date. Its models' training ends before
 * 2026, so without it they read every 2026 date as invented ("2026 is in the
 * future, making the entire page factually impossible", trust 0).
 * $opt: 'order'/'skip' pin a provider or model (calibration tests); 'today' => ''
 * omits the date line (the before arm of that test).
 */
function quality_judge(array $f, array $opt = []): array {
    $today = array_key_exists('today', $opt) ? (string)$opt['today'] : gmdate('Y-m-d');
    $evTxt = '';
    foreach ($f['events'] as $i => $e) {
        $attr = $e['confirmed'] ? " (confirmed by a primary source: {$e['host']})"
              : ($e['who'] !== '' ? " (source: {$e['who']})" : ' (no source)');
        $evTxt .= ($i + 1) . ". [{$e['date']}] {$e['title']}: {$e['desc']}{$attr}" . (!empty($e['embedded']) ? ' [the original post is embedded on the page]' : '') . "\n";
    }
    $fqTxt = '';
    foreach ($f['faqs'] as $q) $fqTxt .= "Q: {$q['q']}\nA: {$q['a']}\n";
    $bg = implode("\n", (array)$f['background']);

    $sys = "You are GenZHype's QUALITY CONTROLLER | the last editorial judge before a page can be published. Judge the page below on 5 dimensions, each scored 0-10:
1. intent: Does it fully answer what searchers of this topic actually want (what happened, who, why, current status, is it over)? Would they need to search again elsewhere?
2. people_first: Google's helpful-content bar | original value beyond restating sources, demonstrates effort/expertise, leaves the reader satisfied, not made-for-rankings filler.
3. geo: AI-citability | is the summary a self-contained quotable answer with names+dates? Are facts dated and attributed? Are FAQ questions phrased like real queries with direct answers?
4. clarity: Scannable, plain language, short paragraphs, timeline easy to follow, headline promise matches content.
5. trust: Neutral tone; each timeline event names the outlet that reported it, and a claim without a primary source (the person's own post, an official statement, a court record) must use alleged/reportedly framing; no clickbait, no overreach beyond sources.
Also list red_flags (booleans): clickbait_title, padding, core_question_unanswered, stale_status (status/current-state unclear or outdated).
Be a HARSH grader | 8+ means genuinely strong. Output STRICT JSON only:
{\"scores\":{\"intent\":n,\"people_first\":n,\"geo\":n,\"clarity\":n,\"trust\":n},\"red_flags\":{\"clickbait_title\":bool,\"padding\":bool,\"core_question_unanswered\":bool,\"stale_status\":bool},\"top_queries\":[\"what searchers type\"],\"fixes\":[\"concrete improvement\"],\"verdict\":\"one sentence\"}";

    $user = ($today !== '' ? "TODAY'S DATE: {$today}. Every date on or before it is in the past: do not treat a 2025 or 2026 date as future or invented because it is later than your training data. Do check that dates are consistent (for example, backstory events stamped with the day they were reported).\n\n" : '')
          . "TOPIC/KEYWORD: {$f['primary_kw']}\nTITLE TAG: {$f['title_tag']}\nH1: {$f['h1']}\nMETA: {$f['meta_desc']}\nTL;DR SUMMARY: {$f['summary']}\n"
          . "STATUS: {$f['lifecycle']} (latest dated event: {$f['last_date']})\n\nBACKGROUND:\n{$bg}\n\nTIMELINE:\n{$evTxt}\n"
          . "SOURCES CITED ON THE PAGE (" . count($f['sources']) . "): " . implode(', ', $f['sources']) . "\n\nFAQ:\n{$fqTxt}";

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], $opt['order'] ?? ['nvidia', 'gemini', 'openrouter'], 0.2, 120, $opt['skip'] ?? []); // third provider order = spreads free quota
    if (isset($res['error'])) return $res;

    $j = ai_json($res['content']);
    if (!$j || empty($j['scores'])) return ['error' => 'quality controller returned bad JSON'];

    $scores = array_map('intval', $j['scores']);
    $flags  = array_filter($j['red_flags'] ?? []);
    $pass   = min($scores) >= QUALITY_MIN_SCORE && count($flags) === 0;
    return [
        'pass'    => $pass,
        'scores'  => $scores,
        'flags'   => array_keys($flags),
        'queries' => $j['top_queries'] ?? [],
        'fixes'   => $j['fixes'] ?? [],
        'verdict' => $j['verdict'] ?? '',
        'provider'=> $res['provider'],
        '_res'    => $res, '_json' => $j,
    ];
}

function quality_check_drama(int $page_id): array {
    $f = quality_page_fields($page_id);
    if (!$f) return ['error' => 'drama page not found'];
    $r = quality_judge($f);
    if (isset($r['error'])) return $r;
    ai_log($page_id, 'quality', $r['_res'], $r['_json'], $r['pass']);
    unset($r['_res'], $r['_json']);
    return $r;
}
