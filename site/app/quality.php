<?php
// GenZHype | QUALITY controller. The judgment layer code can't do:
// does the page satisfy real search intent, Google's people-first bar,
// and GEO citability? Runs as its own stage; publish requires a pass.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/story_context.php';
require_once __DIR__ . '/creator_stats.php';
require_once __DIR__ . '/gate.php';

const QUALITY_MIN_SCORE = 7; // every dimension must reach this

/**
 * 2026-09-25 WHO JUDGES. The chain was nvidia -> gemini -> openrouter, and a page's verdict
 * depended on which one answered: Nemotron passed 0 of 1,538 pages in 21 days, while 58 of the
 * 59 passes came from Gemini Flash-Lite, reached only when Nvidia failed at that moment. Study
 * (16 pages, each judged twice by both): gpt-oss-120b on Groq and Nemotron gave the same verdict
 * on 15 of 16 pages, gpt-oss repeated its own verdict on 15 of 16 and its lowest score moved 0.5
 * on average (Nemotron 0.75), and both failed the known-bad pages every time; gpt-oss answers in
 * ~2 s instead of 20-40 s. The lenient fallback is gone: if Groq and Nvidia are both down, the
 * page waits for its next judgment rather than getting a lucky one. Qwen is not calibrated.
 */
const QUALITY_JUDGE_ORDER = ['groq', 'nvidia', 'openrouter'];
// Only the two calibrated models may judge, on whichever account serves them. An ALLOW list: the
// skip list it replaced let kimi-k3 (nvidia_b) pass a page on 2026-09-25, and every model added to a
// provider since (gpt-oss-20b for the drafter, OpenRouter's free router) would have judged too.
// Groq's free tier stops at 200,000 tokens a day (about 45 judgments), then Nemotron Super judges alone.
const QUALITY_JUDGE_ALLOW = ['groq/openai/gpt-oss-120b', 'nvidia/nvidia/nemotron-3-super-120b-a12b',
                             'nvidia_b/nvidia/nemotron-3-super-120b-a12b', 'openrouter/nvidia/nemotron-3-super-120b-a12b:free'];

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
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.primary_kw, d.lifecycle, d.background, d.why_matters, d.whats_next, d.both_sides, d.verdict
                        FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return null;
    $did = (int)$page['drama_id'];
    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.description, e.is_confirmed, e.confirmed_by, s.url, s.publisher,
                                (e.embed_html IS NOT NULL AND e.embed_html <> '') embedded
                         FROM events e LEFT JOIN sources s ON s.id = e.source_id
                         WHERE e.drama_id=? AND e.video_only=0 ORDER BY e.sort_order, e.event_date");   // what the page shows (repo.php)
    $ev->execute([$did]);
    $events = []; $pubs = []; $last = '';
    foreach ($ev->fetchAll() as $e) {
        $host = preg_replace('/^www\./', '', (string)parse_url((string)($e['url'] ?? ''), PHP_URL_HOST));
        $who = trim((string)($e['publisher'] ?? '')) ?: $host;
        $events[] = ['date' => (string)$e['event_date'], 'title' => (string)$e['title'], 'desc' => (string)$e['description'],
                     'confirmed' => (int)$e['is_confirmed'] === 1, 'proof' => gate_proof_label($e['confirmed_by'] ?? ''), 'who' => $who, 'host' => $host,
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
            'events' => $events, 'sources' => array_keys($pubs), 'last_date' => $last,
            ...story_context_shape($page['why_matters'] ?? null, $page['whats_next'] ?? null),
            'tracked' => array_map('cs_sentence', cs_numbers($pdo, [$page_id])[$page_id] ?? []),
            'both_sides' => (array)json_decode((string)($page['both_sides'] ?? ''), true),
            'verdict' => json_decode((string)($page['verdict'] ?? ''), true) ?: null];
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
        $attr = $e['confirmed'] ? " (confirmed by {$e['proof']})"
              : ($e['who'] !== '' ? " (source: {$e['who']})" : ' (no source)');
        $evTxt .= ($i + 1) . ". [{$e['date']}] {$e['title']}: {$e['desc']}{$attr}" . (!empty($e['embedded']) ? ' [the original post is embedded on the page]' : '') . "\n";
    }
    $fqTxt = '';
    foreach ($f['faqs'] as $q) $fqTxt .= "Q: {$q['q']}\nA: {$q['a']}\n";
    $bg = implode("\n", (array)$f['background']);
    $ctx = '';   // the page's own "Why it matters" / "What happens next" sections, when it has them
    if (($f['why_matters'] ?? '') !== '') $ctx .= "WHY IT MATTERS:\n{$f['why_matters']}\n\n";
    if (!empty($f['tracked'])) $ctx .= "NUMBERS WE TRACKED OURSELVES:\n- " . implode("\n- ", $f['tracked']) . "\n\n";
    if (!empty($f['verdict']['reasons'])) $ctx .= "OUR READ OF THE EVIDENCE: {$f['verdict']['rating']}. {$f['verdict']['reasons']}\n\n";
    if (count($f['both_sides'] ?? []) === 2) {
        $ctx .= "WHAT EACH SIDE SAYS:\n";
        foreach ($f['both_sides'] as $bs) $ctx .= "- {$bs['who']}: \"{$bs['quote']}\" (via {$bs['by']})\n";
        $ctx .= "\n";
    }
    if (!empty($f['whats_next'])) {
        $ctx .= "WHAT HAPPENS NEXT:\n";
        foreach ($f['whats_next'] as $nx) $ctx .= '- ' . ($nx['date'] !== '' ? story_context_date_label($nx['date']) . ': ' : '') . $nx['text'] . "\n";
        $ctx .= "\n";
    }

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
          . "STATUS: {$f['lifecycle']} (latest dated event: {$f['last_date']})\n\n{$ctx}BACKGROUND:\n{$bg}\n\nTIMELINE:\n{$evTxt}\n"
          . "SOURCES CITED ON THE PAGE (" . count($f['sources']) . "): " . implode(', ', $f['sources']) . "\n\nFAQ:\n{$fqTxt}";

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], $opt['order'] ?? QUALITY_JUDGE_ORDER, 0.2, 120, $opt['skip'] ?? ai_skip_except(QUALITY_JUDGE_ALLOW, $opt['order'] ?? QUALITY_JUDGE_ORDER));
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
