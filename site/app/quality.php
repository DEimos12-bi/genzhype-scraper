<?php
// GenZHype | QUALITY controller. The judgment layer code can't do:
// does the page satisfy real search intent, Google's people-first bar,
// and GEO citability? Runs as its own stage; publish requires a pass.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/story_context.php';
require_once __DIR__ . '/creator_stats.php';
require_once __DIR__ . '/gate.php';

/**
 * 2026-09-27 THE EDITOR, CALIBRATED (owner decision after a test of 120 judgments: 9 top search
 * results for our stories, 5 known-bad pages, 10 of ours; 3 judges; 2 input formats). Groq's
 * gpt-oss-120b and Nemotron Super passed 0 of 9 top results (BBC and Polygon included) and told
 * good from bad by 0.7 points; Gemini, reading the page as a reader sees it, scored top results 8.6
 * and bad pages 5.4 and passed 8 of 9 and 2 of 5 on an average of 7+. The 2 bad ones are stopped
 * by the hard fails in code (gate_check_drama: dates), which block whatever the editor says.
 * PASS = average of the 5 scores 7+ AND trust 5+ (a 4 or under fails whatever the average, owner).
 * Red flags are reported, not counted (the rule the test measured).
 * WHO: Gemini 2.5 Flash or Flash-Lite, the two the test used, reserved for this job (ai.php
 * AI_JUDGE_RESERVED). When neither answers the page is HELD: no verdict is written and it is judged
 * again on a later run. Groq and Nvidia no longer judge (owner).
 */
const QUALITY_JUDGE_ORDER = ['gemini'];
const QUALITY_JUDGE_ALLOW = ['gemini/gemini-2.5-flash', 'gemini/gemini-flash-lite-latest'];
const QUALITY_PASS_AVERAGE = 7.0;
const QUALITY_TRUST_FLOOR = 5;
const QUALITY_DIMENSIONS = ['intent', 'people_first', 'geo', 'clarity', 'trust'];

/**
 * A story's data as the page shows it: each event with its source link, date and embedded post,
 * the FAQs and the page's own sections. Read by the status refresh (status_refresh.php). The editor
 * itself judges the rendered page (quality_page_view, 2026-09-27); it judged these fields until then.
 */
function quality_page_fields(int $page_id): ?array {
    $pdo = db();
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.primary_kw, d.lifecycle, d.background, d.why_matters, d.whats_next, d.both_sides, d.verdict
                        FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return null;
    $did = (int)$page['drama_id'];
    sources_install($pdo);   // sources.published_on (db.php)
    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.description, e.is_confirmed, e.confirmed_by, s.url, s.publisher, s.published_on, e.embed_html
                         FROM events e LEFT JOIN sources s ON s.id = e.source_id
                         WHERE e.drama_id=? AND e.video_only=0 ORDER BY e.sort_order, e.event_date");   // what the page shows (repo.php)
    $ev->execute([$did]);
    $events = []; $pubs = []; $last = '';
    foreach ($ev->fetchAll() as $e) {
        $host = preg_replace('/^www\./', '', (string)parse_url((string)($e['url'] ?? ''), PHP_URL_HOST));
        $who = trim((string)($e['publisher'] ?? '')) ?: $host;
        $events[] = ['date' => (string)$e['event_date'], 'title' => (string)$e['title'], 'desc' => (string)$e['description'],
                     'confirmed' => (int)$e['is_confirmed'] === 1, 'proof' => gate_proof_label($e['confirmed_by'] ?? ''), 'who' => $who, 'host' => $host,
                     'embedded' => (string)($e['embed_html'] ?? '') !== '',   // the original post is shown on the page
                     // 2026-09-27 the editor saw only host names ("Dexerto is the only source" on a page linking Riot's
                     // own site and embedding @riotgames' post): it now gets the links a reader gets
                     'url' => (string)($e['url'] ?? ''), 'published' => (string)($e['published_on'] ?? ''),
                     'embed_url' => preg_match('#https?://(?:www\.)?(?:twitter\.com|x\.com|tiktok\.com|instagram\.com|youtube\.com|youtu\.be|reddit\.com|twitch\.tv|threads\.net|bsky\.app)/[^\s"\'<>]+#i', (string)($e['embed_html'] ?? ''), $em) ? $em[0] : ''];
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

/** The editor's rulebook (system prompt), shared by quality_judge() and calibration tests. */
function quality_rubric(): string {
    return "You are GenZHype's QUALITY CONTROLLER | the last editorial judge before a page can be published. Judge the page below on 5 dimensions, each scored 0-10:
1. intent: Does it fully answer what searchers of this topic actually want (what happened, who, why, current status, is it over)? Would they need to search again elsewhere?
2. people_first: Google's helpful-content bar | original value beyond restating sources, demonstrates effort/expertise, leaves the reader satisfied, not made-for-rankings filler.
3. geo: AI-citability | is the summary a self-contained quotable answer with names+dates? Are facts dated and attributed? Are FAQ questions phrased like real queries with direct answers?
4. clarity: Scannable, plain language, short paragraphs, timeline easy to follow, headline promise matches content.
5. trust: Neutral tone; each timeline event names the outlet that reported it, and a claim without a primary source (the person's own post, an official statement, a court record) must use alleged/reportedly framing; no clickbait, no overreach beyond sources.
Also list red_flags (booleans): clickbait_title, padding, core_question_unanswered, stale_status (status/current-state unclear or outdated).
Be a HARSH grader | 8+ means genuinely strong. Output STRICT JSON only:
{\"scores\":{\"intent\":n,\"people_first\":n,\"geo\":n,\"clarity\":n,\"trust\":n},\"red_flags\":{\"clickbait_title\":bool,\"padding\":bool,\"core_question_unanswered\":bool,\"stale_status\":bool},\"top_queries\":[\"what searchers type\"],\"fixes\":[\"concrete improvement\"],\"verdict\":\"one sentence\"}";
}

/**
 * The page as a reader sees it: the published render's main text, the links in it and the embedded
 * posts (the calibration test's input). null when the page does not render.
 */
function quality_page_view(int $pageId): ?array {
    $html = render_page_html($pageId);   // gate.php: the render the SEO audit reads, any status
    if (!$html) return null;
    $p = db()->prepare("SELECT path FROM pages WHERE id=?");
    $p->execute([$pageId]);
    $url = rtrim((string)$GLOBALS['CONFIG']['base_url'], '/') . (string)$p->fetchColumn();
    preg_match('#<title[^>]*>(.*?)</title>#is', $html, $t);
    $block = preg_match('#<main\b.*?</main>#is', $html, $m) ? $m[0] : $html;
    $block = preg_replace('#<(script|style|nav|header|footer|aside|form|noscript|svg)\b.*?</\1>#is', ' ', $block);
    $links = []; $embeds = [];
    if (preg_match_all('#<a\b[^>]*href="(https?://[^"]+)"[^>]*>(.*?)</a>#is', $block, $mm, PREG_SET_ORDER))
        foreach ($mm as $a) {
            $u = html_entity_decode($a[1]);
            $host = preg_replace('/^www\./', '', (string)parse_url($u, PHP_URL_HOST));
            if ($host === '' || $host === 'genzhype.com') continue;
            if (preg_match('#(twitter\.com|x\.com|tiktok\.com|instagram\.com|youtube\.com|youtu\.be)/.+/(status|video|p|reel|watch)#i', $u) || str_contains($u, 'youtube.com/watch')) $embeds[$u] = 1;
            $links[$u] = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($a[2])))) ?: '(no text)';
        }
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(br|p|li|h\d|div|tr)\b#i', "\n<$1", $block)), ENT_QUOTES)));
    return ['url' => $url, 'title' => trim(html_entity_decode(strip_tags($t[1] ?? ''), ENT_QUOTES)), 'text' => mb_substr($text, 0, 12000),
            'cut' => mb_strlen($text) > 12000, 'links' => array_slice($links, 0, 30, true), 'embeds' => array_keys($embeds)];
}

/**
 * Judge a page as a reader sees it (quality_page_view). No logging. ['error'] when the judge does not
 * answer or its reply is unreadable: the page is held, never failed and never passed by anything else.
 * The judge is told today's date (2026-09-24: without it, models read every 2026 date as invented).
 */
function quality_judge(array $v): array {
    $user = "TODAY'S DATE: " . gmdate('Y-m-d') . ". Every date on or before it is in the past: do not treat a 2025 or 2026 date as future or invented because it is later than your training data. Do check that dates are consistent (for example, backstory events stamped with the day they were reported).\n\n"
          . "PAGE: {$v['url']}\nTITLE: {$v['title']}\n\nPAGE TEXT (as a reader sees it" . ($v['cut'] ? ', first 12,000 characters' : '') . "):\n{$v['text']}\n\n"
          . "LINKS IN THE PAGE:\n" . ($v['links'] ? implode("\n", array_map(fn($u, $a) => "- $a: $u", array_keys($v['links']), $v['links'])) : '(none)') . "\n\n"
          . "EMBEDDED POSTS:\n" . ($v['embeds'] ? '- ' . implode("\n- ", $v['embeds']) : '(none)');
    $GLOBALS['__ai_judge'] = true;   // the reserved Gemini models answer only this call (ai.php)
    try {
        $res = ai_chat([['role' => 'system', 'content' => quality_rubric()], ['role' => 'user', 'content' => $user]],
                       QUALITY_JUDGE_ORDER, 0.2, 120, ai_skip_except(QUALITY_JUDGE_ALLOW, QUALITY_JUDGE_ORDER));
    } finally { unset($GLOBALS['__ai_judge']); }
    if (isset($res['error'])) return ['error' => 'editor unavailable, page held until Gemini answers: ' . $res['error']];
    $j = ai_json($res['content']);
    $scores = [];
    foreach (QUALITY_DIMENSIONS as $d) if (isset($j['scores'][$d]) && is_numeric($j['scores'][$d])) $scores[$d] = (int)$j['scores'][$d];
    if (count($scores) !== count(QUALITY_DIMENSIONS)) return ['error' => 'editor reply unreadable, page held'];
    $avg = round(array_sum($scores) / count($scores), 2);
    $pass = $avg >= QUALITY_PASS_AVERAGE && $scores['trust'] >= QUALITY_TRUST_FLOOR;
    $flags = array_keys(array_filter((array)($j['red_flags'] ?? [])));
    return [
        'pass'    => $pass,
        'scores'  => $scores,
        'average' => $avg,
        'flags'   => $flags,
        'queries' => $j['top_queries'] ?? [],
        'fixes'   => $j['fixes'] ?? [],
        'verdict' => $j['verdict'] ?? '',
        'provider'=> $res['provider'],
        'model'   => $res['model'],
        '_res'    => $res,
        '_json'   => $j + ['average' => $avg, 'model' => $res['model'], 'rule' => 'reader view; average 7+ and trust 5+'],
    ];
}

function quality_check_drama(int $page_id): array {
    $v = quality_page_view($page_id);
    if (!$v) return ['error' => 'drama page did not render'];
    $r = quality_judge($v);
    if (isset($r['error'])) return $r;
    ai_log($page_id, 'quality', $r['_res'], $r['_json'], $r['pass']);
    unset($r['_res'], $r['_json']);
    return $r;
}
