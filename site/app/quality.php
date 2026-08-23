<?php
// GenZHype | QUALITY controller. The judgment layer code can't do:
// does the page satisfy real search intent, Google's people-first bar,
// and GEO citability? Runs as its own stage; publish requires a pass.

require_once __DIR__ . '/ai.php';

const QUALITY_MIN_SCORE = 7; // every dimension must reach this

function quality_check_drama(int $page_id): array {
    $pdo = db();
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.primary_kw, d.lifecycle, d.background
                        FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return ['error' => 'drama page not found'];
    $did = (int)$page['drama_id'];

    $ev = $pdo->prepare("SELECT event_date, title, description, is_confirmed FROM events WHERE drama_id=? ORDER BY sort_order");
    $ev->execute([$did]);
    $evTxt = '';
    foreach ($ev->fetchAll() as $i => $e) {
        $evTxt .= ($i+1) . ". [{$e['event_date']}]" . ($e['is_confirmed'] ? '' : ' [UNCONFIRMED]') . " {$e['title']}: {$e['description']}\n";
    }
    $fq = $pdo->prepare("SELECT question, answer FROM faqs WHERE drama_id=? ORDER BY sort_order");
    $fq->execute([$did]);
    $fqTxt = '';
    foreach ($fq->fetchAll() as $f) $fqTxt .= "Q: {$f['question']}\nA: {$f['answer']}\n";
    $bg = implode("\n", json_decode($page['background'] ?? '[]', true) ?: []);

    $sys = "You are GenZHype's QUALITY CONTROLLER | the last editorial judge before a page can be published. Judge the page below on 5 dimensions, each scored 0-10:
1. intent: Does it fully answer what searchers of this topic actually want (what happened, who, why, current status, is it over)? Would they need to search again elsewhere?
2. people_first: Google's helpful-content bar | original value beyond restating sources, demonstrates effort/expertise, leaves the reader satisfied, not made-for-rankings filler.
3. geo: AI-citability | is the summary a self-contained quotable answer with names+dates? Are facts dated and attributed? Are FAQ questions phrased like real queries with direct answers?
4. clarity: Scannable, plain language, short paragraphs, timeline easy to follow, headline promise matches content.
5. trust: Neutral tone, alleged-framing on unproven claims, no clickbait, no overreach beyond sources.
Also list red_flags (booleans): clickbait_title, padding, core_question_unanswered, stale_status (status/current-state unclear or outdated).
Be a HARSH grader | 8+ means genuinely strong. Output STRICT JSON only:
{\"scores\":{\"intent\":n,\"people_first\":n,\"geo\":n,\"clarity\":n,\"trust\":n},\"red_flags\":{\"clickbait_title\":bool,\"padding\":bool,\"core_question_unanswered\":bool,\"stale_status\":bool},\"top_queries\":[\"what searchers type\"],\"fixes\":[\"concrete improvement\"],\"verdict\":\"one sentence\"}";

    $user = "TOPIC/KEYWORD: {$page['primary_kw']}\nTITLE TAG: {$page['title_tag']}\nH1: {$page['h1']}\nMETA: {$page['meta_desc']}\nTL;DR SUMMARY: {$page['summary']}\nSTATUS: {$page['lifecycle']}\n\nBACKGROUND:\n{$bg}\n\nTIMELINE:\n{$evTxt}\nFAQ:\n{$fqTxt}";

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], ['nvidia', 'gemini', 'openrouter'], 0.2); // third provider order = spreads free quota
    if (isset($res['error'])) return $res;

    $j = ai_json($res['content']);
    if (!$j || empty($j['scores'])) return ['error' => 'quality controller returned bad JSON'];

    $scores = array_map('intval', $j['scores']);
    $flags  = array_filter($j['red_flags'] ?? []);
    $pass   = min($scores) >= QUALITY_MIN_SCORE && count($flags) === 0;

    ai_log($page_id, 'quality', $res, $j, $pass);
    return [
        'pass'    => $pass,
        'scores'  => $scores,
        'flags'   => array_keys($flags),
        'queries' => $j['top_queries'] ?? [],
        'fixes'   => $j['fixes'] ?? [],
        'verdict' => $j['verdict'] ?? '',
        'provider'=> $res['provider'],
    ];
}
