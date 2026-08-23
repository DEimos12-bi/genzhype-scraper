<?php
// GenZHype | TERM MEANING VERIFIER. Born from the crash-out case (2026-06-12):
// the page defined the OLD "fall asleep" sense while Gen Z's dominant usage is
// "lose your composure / melt down". Dramas always had an adversarial verifier
// (verify.php); terms had none. This module closes that hole:
//   meaning_currency_check()  - is the drafted definition the DOMINANT CURRENT
//                               usage? (adversarial: different provider order
//                               than the drafter). UNKNOWN never blocks - new
//                               coinages past model cutoffs must still publish.
//   meaning_correct_fields()  - rewrite the definition fields around the
//                               corrected sense (used at draft time + redraft).
//   term_meaning_redraft()    - apply a correction to a LIVE page + re-gate.

require_once __DIR__ . '/ai.php';

/** Dated crowd evidence for a term (Urban Dictionary, free/keyless). Newest first. */
function meaning_evidence(string $term, int $max = 6): string {
    require_once __DIR__ . '/fetch_sources.php';
    $raw = fs_http_get('https://api.urbandictionary.com/v0/define?term=' . urlencode($term), 15);
    $list = json_decode($raw ?: '', true)['list'] ?? [];
    if (!$list) return '';
    usort($list, fn($a, $b) => strcmp($b['written_on'] ?? '', $a['written_on'] ?? ''));
    $out = [];
    foreach (array_slice($list, 0, $max) as $d) {
        $def = trim(preg_replace('/\s+/', ' ', str_replace(['[', ']'], '', $d['definition'] ?? '')));
        if ($def === '') continue;
        $out[] = '- ' . substr($d['written_on'] ?? '?', 0, 10) . ': ' . mb_substr($def, 0, 160);
    }
    return implode("\n", $out);
}

/**
 * Verdict: current | outdated | wrong | unknown. FAILS (pass=false) only on a
 * CONFIDENT outdated/wrong (confidence >= 0.7) - the system must self-correct
 * on real misses without blocking fresh terms the models simply don't know.
 * Anti-anchor design: the auditor states the dominant CURRENT meaning ITSELF
 * (from its knowledge + dated crowd evidence) BEFORE comparing to our draft.
 */
/**
 * SEO-BATCH-1 (owner decision 2026-08-04): TOPICAL FIT ON EVERY SOURCE.
 *
 * `cracked` was rebuilt from a Lumma Stealer malware article, a Denuvo DRM piece
 * and the Wikipedia page for the humour magazine. In gaming the term means
 * "extremely skilled". All three sources were real and non-commodity, so they
 * cleared the source check, and the page was grounded in them — leaving it worse
 * than the fabrication it replaced.
 *
 * This is the same defect as camping -> Camping and Caravanning Club, but in
 * fetch_term_sources() rather than the Wikipedia matcher; the matcher was fixed
 * and its sibling was not.
 *
 * A source qualifies ONLY if it corroborates the term's dominant current sense —
 * containing the string is not enough. One AI call for the whole batch. On any
 * failure this returns ALL indexes (fail-open) because a retrieval outage must
 * not silently strip a page's grounding; the gate is the backstop.
 *
 * Returns the list of source indexes that survive.
 */
function sources_topical_fit(string $term, string $lane, string $senseHint, array $sources): array {
    $all = array_keys($sources);
    if (!$sources) return [];
    $block = '';
    foreach ($sources as $i => $s) {
        $ex = trim((string)($s['excerpt'] ?? ''));
        // 2026-08-22 THE REAL OFF-TOPIC BUG. This passed the FIRST 900 chars
        // of the page, which on a news site is nav, cookie notice and
        // subscribe prompts — the article body never reached the screen, so
        // it judged "is this about skibidi?" on boilerplate and said no.
        // Measured: the Mirror's skibidi piece extracted 8,666 chars of text
        // containing the term, yet was discarded as OFF-TOPIC. Show the
        // window AROUND the term's first mention instead; fall back to the
        // head only when the term is genuinely absent (which is itself the
        // honest signal that the source does not cover it).
        $snippet = '(no text retrieved)';
        if ($ex !== '') {
            $pos = $term !== '' ? mb_stripos($ex, $term) : false;
            $snippet = $pos === false
                ? mb_substr($ex, 0, 900)
                : mb_substr($ex, max(0, $pos - 300), 900);
        }
        $block .= "[{$i}] url=" . ($s['url'] ?? '') . "\n"
                . "TEXT: " . $snippet . "\n\n";
    }
    $sense = trim($senseHint) !== '' ? $senseHint : "the {$lane} sense of \"{$term}\"";
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            "You are screening SOURCES for a slang/culture encyclopedia entry. The entry is about the term "
            . "\"{$term}\" in its {$lane} sense, specifically: {$sense}\n\n"
            . "A source QUALIFIES only if it is genuinely about THAT sense of the term — it discusses or uses the "
            . "term with that meaning. A source does NOT qualify merely because the word appears in it. Reject "
            . "sources about a DIFFERENT sense of the same word (e.g. for gaming \"cracked\" meaning extremely "
            . "skilled, an article about cracked/pirated software or a magazine called Cracked does NOT qualify), "
            . "and reject sources on an unrelated topic.\n\n"
            // 2026-08-22: this screen was rejecting the ONLY press coverage
            // slang actually gets. Measured on \"skibidi\": The Independent,
            // the Mirror, Hindustan Times and Free Press Journal were all
            // discarded as OFF-TOPIC, leaving 0 sources — because each covers
            // several new slang words in one article. A round-up that treats
            // the term correctly IS about the term; publications simply do not
            // write one article per slang word.
            . "IMPORTANT: a source that covers SEVERAL terms in one article (a "
            . "slang round-up, a dictionary-additions story, a year-in-language "
            . "piece) DOES qualify, as long as it treats \"{$term}\" in the sense "
            . "above. Do not reject a source for also discussing other words.\n"
            . "Reject for the WRONG SENSE or an unrelated topic — not for breadth.\n"
            . 'Respond ONLY JSON: {"keep":[list of qualifying index numbers]}'],
        ['role' => 'user', 'content' => $block],
    ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.0, 180);
    if (isset($res['error'])) return $all;                       // fail-open, gate backstops
    $j = ai_json($res['content'] ?? '');
    if (!is_array($j) || !isset($j['keep']) || !is_array($j['keep'])) return $all;
    $keep = [];
    foreach ($j['keep'] as $k) if (isset($sources[(int)$k])) $keep[] = (int)$k;
    return $keep;
}

function meaning_currency_check(string $term, string $shortDef, string $summary = ''): array {
    $today = date('F Y');
    $ev = meaning_evidence($term);
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            "You audit a Gen Z slang dictionary for MEANING ACCURACY. Today is {$today}. Slang evolves: older "
            . "literal senses get displaced (example: 'crash out' once meant falling asleep; the dominant Gen Z "
            . "usage became 'lose your composure / melt down / act recklessly'). "
            . "WORK IN THIS ORDER: (1) From your own knowledge PLUS the dated crowd-sourced evidence below (newest "
            . "entries first, weigh recent dates heaviest), state the DOMINANT CURRENT usage among US Gen Z online "
            . "in one sentence: current_meaning. (2) Only THEN compare the site's drafted definition to it. Verdicts: "
            . "current = drafted sense matches the dominant current usage. "
            . "outdated = a real but displaced older sense. wrong = not a real sense. "
            . "unknown = neither you nor the evidence reliably establish the current usage (NEVER guess). "
            . 'STRICT JSON: {"current_meaning":"..","verdict":"current|outdated|wrong|unknown","confidence":0.0,"note":"short"}'],
        ['role' => 'user', 'content' => "Term: {$term}\n"
            . ($ev !== '' ? "Dated crowd evidence (newest first):\n{$ev}\n" : "(no crowd evidence available)\n")
            . "\nThe site's drafted definition: {$shortDef}"
            . ($summary !== '' ? "\nDrafted summary: {$summary}" : '')],
    ], ['openrouter', 'nvidia', 'gemini'], 0.1);   // drafter is gemini-first: keep the auditor adversarial
    $j = isset($res['error']) ? null : ai_json($res['content']);
    if (!$j || !in_array($j['verdict'] ?? '', ['current', 'outdated', 'wrong', 'unknown'], true)) {
        return ['pass' => true, 'verdict' => 'unknown', 'dominant_meaning' => '', 'confidence' => 0.0,
                'note' => 'verifier unavailable (fail-open: drafter prompt carries the rule)', 'res' => $res];
    }
    $conf = max(0.0, min(1.0, (float)($j['confidence'] ?? 0)));
    $bad  = in_array($j['verdict'], ['outdated', 'wrong'], true) && $conf >= 0.7;
    return ['pass' => !$bad, 'verdict' => $j['verdict'],
            'dominant_meaning' => trim((string)($j['current_meaning'] ?? '')),
            'confidence' => $conf, 'note' => (string)($j['note'] ?? ''), 'res' => $res];
}

/**
 * Rewrite the definition-bearing fields around the corrected dominant sense.
 * $fields = the drafter's JSON (or a DB-row equivalent). Returns the corrected
 * subset (same shapes the drafter produces) or null on failure.
 */
function meaning_correct_fields(string $term, array $fields, string $dominant): ?array {
    $current = json_encode([
        'short_def' => $fields['short_def'] ?? '', 'summary' => $fields['summary'] ?? '',
        'meta_desc' => $fields['meta_desc'] ?? '', 'meaning' => $fields['meaning'] ?? [],
        'origin' => $fields['origin'] ?? [], 'why_trending' => $fields['why_trending'] ?? [],
        'usage_note' => $fields['usage_note'] ?? '', 'examples' => $fields['examples'] ?? [],
        'faqs' => $fields['faqs'] ?? [], 'first_seen' => $fields['first_seen'] ?? '',
        'image_query' => $fields['image_query'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            "A slang entry was drafted around an outdated sense. The DOMINANT CURRENT US Gen Z meaning is: "
            . "\"{$dominant}\". Rewrite the entry so THIS sense is the primary definition throughout. If the old "
            . "sense is genuinely also used, cover it inside 'meaning' in ONE short paragraph explicitly labeled "
            . "as the older sense; drop it entirely if it would confuse. Keep the confident, non-cringe GenZHype "
            // SEO-BATCH-1: this line USED to read "Honest origin: if you cannot
            // ground the current sense's origin in the provided text, say
            // origins are debated rather than inventing dates/people." It read
            // as the SAFE option, which is why it survived two rounds of fixes
            // in draft_term.php and term_redraft.php — but it is the exact
            // phrasing that produced the false pages, and it is now an automatic
            // gate failure. Worse, this runs BETWEEN draft and gate, so it could
            // inject "origins are debated" into a page the redrafter had
            // correctly written with NO origin, and that page would then die on
            // the banned-phrase check — killed by an instruction, not by missing
            // evidence. ORIGIN IS OPTIONAL AND SUPPLIED, NEVER RECALLED.
            . "voice. ORIGIN: do not recall or reconstruct one. Preserve whatever origin the entry already carries "
            . "and change nothing about it; if the entry has none, return \"origin\": [] and \"first_seen\": \"\" and "
            . "say NOTHING anywhere about where the term came from. NEVER write that the origin is debated, unknown, "
            . "unclear, undocumented, untraceable or decentralised, and never invent a date, person or platform. "
            . "LIMITS: short_def ONE sentence <=180 chars; "
            . "summary 120-300 chars answer-first; meta_desc 120-132 chars; meaning 2-3 full paragraphs; "
            . "at least 4 examples; at least 3 faqs; image_query = 3-6 plain words for a photographable scene of "
            . "the CURRENT sense (never the slang word itself). "
            . 'STRICT JSON, exactly these keys: {"short_def":"..","summary":"..","meta_desc":"..","meaning":[".."],"origin":[".."],"why_trending":[".."],"usage_note":"..","examples":[{"text":"..","context":".."}],"faqs":[{"q":"..","a":".."}],"first_seen":"..","image_query":".."}'],
        ['role' => 'user', 'content' => "Term: {$term}\nCurrent (outdated-sense) entry JSON:\n{$current}"],
    ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.4);
    if (isset($res['error'])) return null;
    $j = ai_json($res['content']);
    if (!$j || empty($j['short_def']) || empty($j['meaning'])) return null;
    if (function_exists('term_clean')) $j = term_clean($j);
    // hard length guards (no extra AI calls; the SEO gate re-judges on publish)
    if (mb_strlen($j['short_def']) > 180) $j['short_def'] = rtrim(mb_substr($j['short_def'], 0, 179), ' ,;.') . '.';
    // both gates must hold: content gate caps meta at 135, SEO gate floors it at 120
    if (isset($j['meta_desc']) && mb_strlen($j['meta_desc']) > 135) {
        $cut = mb_substr($j['meta_desc'], 0, 132);
        if (($sp = mb_strrpos($cut, ' ')) !== false && $sp > 110) $cut = mb_substr($cut, 0, $sp);
        $j['meta_desc'] = rtrim($cut, ' ,;.') . '.';
    }
    $j['res'] = $res;
    return $j;
}

/**
 * Correct a LIVE term page in place (slug/title/h1 untouched: the question the
 * page answers is the same; only the answer changes). Re-runs the content gate
 * after the update. Returns a report array.
 */
function term_meaning_redraft(int $pageId, string $dominant): array {
    require_once __DIR__ . '/draft_term.php';   // term_clean
    require_once __DIR__ . '/gate_term.php';
    $pdo = db();
    $st = $pdo->prepare("SELECT t.*, p.slug, p.meta_desc pm, p.summary psum FROM terms t JOIN pages p ON p.id=t.page_id WHERE t.page_id=?");
    $st->execute([$pageId]);
    $row = $st->fetch();
    if (!$row) return ['error' => 'page not found'];
    $jd = fn($v) => json_decode($v ?? '[]', true) ?: [];
    $fields = [
        'short_def' => $row['short_def'], 'summary' => $row['psum'], 'meta_desc' => $row['pm'],
        'meaning' => $jd($row['meaning']), 'origin' => $jd($row['origin']),
        'why_trending' => $jd($row['why_trending']), 'usage_note' => $row['usage_note'],
        'examples' => $jd($row['examples']), 'faqs' => $jd($row['faqs']),
        'first_seen' => $row['first_seen'], 'image_query' => '',
    ];
    $fix = meaning_correct_fields($row['term'], $fields, $dominant);
    if (!$fix) return ['error' => 'correction draft failed'];
    $res = $fix['res']; unset($fix['res']);
    $pdo->prepare("UPDATE terms SET short_def=?, meaning=?, origin=?, why_trending=?, usage_note=?, examples=?, faqs=?, first_seen=? WHERE page_id=?")
        ->execute([$fix['short_def'],
                   json_encode($fix['meaning'], JSON_UNESCAPED_UNICODE), json_encode($fix['origin'], JSON_UNESCAPED_UNICODE),
                   json_encode($fix['why_trending'], JSON_UNESCAPED_UNICODE), $fix['usage_note'] ?? '',
                   json_encode($fix['examples'], JSON_UNESCAPED_UNICODE), json_encode($fix['faqs'], JSON_UNESCAPED_UNICODE),
                   $fix['first_seen'] ?? ($row['first_seen'] ?? ''), $pageId]);
    if (!empty($fix['meta_desc']) && mb_strlen($fix['meta_desc']) >= 120 && mb_strlen($fix['meta_desc']) <= 160) {
        $pdo->prepare("UPDATE pages SET meta_desc=?, summary=? WHERE id=?")->execute([$fix['meta_desc'], $fix['summary'], $pageId]);
    } else {
        $pdo->prepare("UPDATE pages SET summary=? WHERE id=?")->execute([$fix['summary'], $pageId]);
    }
    ai_log($pageId, 'verify', $res, ['type' => 'meaning', 'action' => 'redraft', 'dominant' => mb_substr($dominant, 0, 200)], true);

    // DEPTH REPAIR: a sense-rewrite often lands thinner than the 380-word gate.
    // One expansion pass strictly within the corrected sense (never the old one).
    $bodyWords = function (array $f) {
        $t = '';
        foreach (['meaning', 'origin', 'why_trending'] as $k) foreach ((array)($f[$k] ?? []) as $p2) $t .= ' ' . (is_string($p2) ? $p2 : '');
        return str_word_count(strip_tags($t));
    };
    if ($bodyWords($fix) < 400) {
        $r2 = ai_chat([
            ['role' => 'system', 'content' => "Expand a slang entry. The term's CURRENT dominant meaning is: \"{$dominant}\" - stay STRICTLY within this sense (the older sense may appear only as one short labeled aside). Rewrite ONLY 'meaning', 'origin', 'why_trending' and 'examples' so meaning+origin+why_trending total AT LEAST 420 words of specific, non-filler substance (nuance, usage contexts, how it spread); 'examples' has at least 4 entries demonstrating the CURRENT sense. Do NOT touch the origin: leave the 'origin' field exactly as given, and if it is empty leave it empty and say nothing anywhere about where the term came from. Never write that the origin is debated, unknown, unclear or undocumented, and never invent dates or people. Reach the word count through MEANING and USAGE depth only. Output STRICT JSON: {\"meaning\":[..],\"origin\":[..],\"why_trending\":[..],\"examples\":[{\"text\":..,\"context\":..}]}"],
            ['role' => 'user', 'content' => "Term: {$row['term']}\nCurrent (too short):\n" . json_encode(['meaning' => $fix['meaning'], 'origin' => $fix['origin'], 'why_trending' => $fix['why_trending'], 'examples' => $fix['examples']], JSON_UNESCAPED_SLASHES)],
        ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.5);
        if (!isset($r2['error'])) {
            $j2 = ai_json($r2['content']);
            if ($j2 && !empty($j2['meaning']) && $bodyWords($j2) > $bodyWords($fix)) {
                if (function_exists('term_clean')) $j2 = term_clean($j2);
                $pdo->prepare("UPDATE terms SET meaning=?, origin=?, why_trending=?, examples=? WHERE page_id=?")
                    ->execute([json_encode($j2['meaning'], JSON_UNESCAPED_UNICODE), json_encode($j2['origin'] ?? $fix['origin'], JSON_UNESCAPED_UNICODE),
                               json_encode($j2['why_trending'] ?? $fix['why_trending'], JSON_UNESCAPED_UNICODE),
                               json_encode($j2['examples'] ?? $fix['examples'], JSON_UNESCAPED_UNICODE), $pageId]);
            }
        }
    }

    $g = gate_check_term($pageId);
    return ['ok' => true, 'slug' => $row['slug'], 'gate' => $g['pass'] ? 'PASS' : 'FAIL',
            'gate_fails' => $g['pass'] ? [] : array_column(array_filter($g['checks'], fn($c) => !$c['pass']), 'label'),
            'new_short_def' => $fix['short_def'], 'new_image_query' => $fix['image_query'] ?? ''];
}

/**
 * TOPICAL-FIT gate (added after the count-binface miss: a UK novelty politician slipped
 * into the slang lane). Is this entry genuinely a US Gen Z internet-culture term for its
 * lane, or an off-lane topic (a specific named real person, place, company/product, sports
 * team, political figure, or general news event) dressed up like a dictionary word?
 * Adversarial + FAIL-OPEN: rejects (pass=false) only on a CONFIDENT off-lane verdict
 * (>=0.7) so genuine new coinages the model doesn't know still publish.
 */
function topical_fit_check(string $term, string $lane, string $shortDef = ''): array {
    $laneDesc = ['slang'=>'Gen Z / internet slang word or phrase', 'meme'=>'internet meme',
                 'gaming'=>'gaming term or slang', 'music'=>'music / hype trend'][$lane] ?? 'Gen Z internet slang or culture term';
    $res = ai_chat([
        ['role'=>'system','content'=>
            "You gate entries for a US Gen Z internet-culture dictionary. Decide if this entry BELONGS. "
            . "It BELONGS if '{$term}' is a genuine {$laneDesc} that US Gen Z actually says or references online. "
            . "It DOES NOT belong if it is really a specific named real person, a place, a company/product, a sports "
            . "team, a political figure or political topic, or a general news event that was merely written up like a "
            . "dictionary term. "
            . 'STRICT JSON: {"belongs":true|false,"confidence":0.0,"why":"short"}. Only answer belongs=false if you are '
            . 'confident; if unsure, belongs=true (real new coinages the model may not know must still pass).'],
        ['role'=>'user','content'=>"Term: {$term}\nLane: {$lane}" . ($shortDef !== '' ? "\nDrafted definition: {$shortDef}" : '')],
    ], ['openrouter','nvidia','gemini'], 0.1);   // adversarial: different provider order than the gemini-first drafter
    $j = isset($res['error']) ? null : ai_json($res['content']);
    if (!$j || !array_key_exists('belongs', $j)) return ['pass'=>true, 'reason'=>'checker unavailable (fail-open)'];
    $conf = max(0.0, min(1.0, (float)($j['confidence'] ?? 0)));
    $off  = ($j['belongs'] === false) && $conf >= 0.7;
    return ['pass'=>!$off, 'belongs'=>(bool)$j['belongs'], 'confidence'=>$conf, 'why'=>(string)($j['why'] ?? '')];
}
