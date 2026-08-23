<?php
// GenZHype | GROUNDED in-place re-draft for a slang/term page whose content got
// corrupted (e.g. the amogus->ma-got-pranked source-bleed bug). Re-fetches REAL
// sources with the fixed fetcher, rewrites the entry STRICTLY from them, re-gates,
// and pulls (noindex) any page that cannot be grounded rather than leaving rot live.
// It NEVER invents: if the sources don't establish an origin, it says so.
//   Usage: php app/term_redraft.php <slug> [slug...]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/draft_term.php';   // fetch_term_sources, term_clean, term_slugify
require_once __DIR__ . '/gate_term.php';     // gate_check_term
require_once __DIR__ . '/indexnow.php';
require_once __DIR__ . '/lanes.php';         // lane -> URL prefix (/slang/, /meme/, ...)

$pdo   = db();
$slugs = array_slice($argv, 1);
if (!$slugs) exit("usage: php app/term_redraft.php <slug> [slug...]\n");

$jd = fn($v) => json_decode($v ?? '[]', true) ?: [];
$is_stub = fn(string $ex) => trim($ex) === '' || mb_strlen(trim($ex)) < 80 || (bool)preg_match('/search results for/i', $ex);

foreach ($slugs as $slug) {
    echo "\n================ {$slug} ================\n";
    // SEO-BATCH-1: reconnect at the TOP of every iteration. Each term costs
    // minutes of AI + HTTP, and MySQL wait_timeout here is 300s — so by the
    // 3rd term this SELECT was firing on a dead handle and killing the whole
    // replay with an uncaught PDOException (it died on "gacha" twice, right
    // after printing the header). Fixing only the WRITE was not enough.
    $pdo = db_alive();
    $st = $pdo->prepare("SELECT t.*, p.id pid, p.slug, p.robots, p.summary psum, p.meta_desc pmeta
                         FROM terms t JOIN pages p ON p.id=t.page_id WHERE p.slug=? AND p.type='term'");
    $st->execute([$slug]);
    $row = $st->fetch();
    if (!$row) { echo "  not found\n"; continue; }
    $pageId = (int)$row['pid'];
    $term   = $row['term'];
    $lane   = $row['lane'] ?: 'slang';
    $oldOrigin = implode(' ', (array)$jd($row['origin'])) ?: (string)$row['origin'];
    echo "  OLD origin: " . mb_substr($oldOrigin, 0, 160) . "\n";

    // 1) fresh REAL sources — the shared fetcher now screens for topical fit
    //    itself (draft_term.php), so this path no longer runs its own copy.
    //    Deriving the sense HERE and passing it in avoids a second AI call.
    require_once __DIR__ . '/verify_term.php';
    // The sense hint must NOT come from the page's own stored short_def: for a
    // page whose definition was already corrupted that perpetuates the
    // corruption. `cracked` stored "Pirated or illegally modified version of a
    // game", so screening against it happily KEPT the malware and DRM articles.
    $mc0 = meaning_currency_check($term, (string)($row['short_def'] ?? ''), (string)($row['psum'] ?? ''));
    $senseHint = trim((string)($mc0['dominant_meaning'] ?? ''));
    if ($senseHint === '') $senseHint = "the {$lane} sense of \"{$term}\"";
    echo "  dominant sense: " . mb_substr($senseHint, 0, 110) . "\n";

    $sources = fetch_term_sources($term, 3, $lane, $senseHint);
    $srcBlock = ''; $real = 0;
    foreach ($sources as $i => $s0) {
        $ex = trim((string)($s0['excerpt'] ?? ''));
        $stub = $is_stub($ex);
        if (!$stub) $real++;
        $shown = $stub ? '(no article text retrieved - a search/index stub only; NOT a source of facts)' : $ex;
        $srcBlock .= "SOURCE " . ($i + 1) . ": publisher={$s0['publisher']} url={$s0['url']}\nEXCERPT: {$shown}\n\n";
    }
    echo "  on-topic grounding: {$real} real / " . count($sources) . " sources\n";
    if ($real < 1 || count($sources) < 2) {
        echo "  >> TOO FEW ON-TOPIC SOURCES - SKIPPED, page left exactly as it was.\n";
        continue;
    }

    // 2) cannot be grounded -> do NOT fabricate; pull the page from the index
    if ($real < 1) {
        if ($row['robots'] !== 'noindex')
            $pdo->prepare("UPDATE pages SET robots='noindex', updated_at=NOW() WHERE id=?")->execute([$pageId]);
        echo "  >> NO real source available - PULLED (noindex). Left content untouched; will not invent.\n";
        continue;
    }

    // 3) grounded rewrite of the WHOLE entry. The current origin is known-corrupted: discard it.
    $sys = "You are re-drafting a US Gen Z slang/culture encyclopedia entry for the term \"{$term}\" because its "
        . "existing text was CORRUPTED (facts bled in from a DIFFERENT topic). DISCARD the old text entirely and "
        . "rewrite the entry using ONLY the facts in the SOURCE excerpts below. HARD RULES: "
        . "(1) NEVER invent an origin, date, person, handle, platform, or any view/like/follower/subscriber COUNT the "
        . "sources do not state. If a specific is not in the excerpts, OMIT it. It is ALWAYS better to be shorter and "
        // SEO-BATCH-1: this instruction USED to read "if the sources do not
        // establish the origin, write that origins are debated / not well
        // documented" — the exact banned phrasing that produced the false pages,
        // and now an automatic gate failure. Origin is OPTIONAL: a page with no
        // provable origin says NOTHING about origin. Cambridge ranks #1 for
        // "delulu meaning" with no etymology at all.
        . "fully grounded than longer and padded. "
        . "(1b) ORIGIN IS OPTIONAL AND IS SUPPLIED TO YOU, NEVER RECALLED. If an ORIGIN ARTIFACT is given below, "
        . "you may write the origin from it. If none is given, return \"origin\": [] and \"first_seen\": \"\" and do "
        . "NOT discuss where the term came from anywhere in the entry — not in meaning, not in why_trending. Never "
        . "write that the origin is debated, unknown, unclear, undocumented or decentralised. Say nothing. "
        . "(2) Define the DOMINANT CURRENT usage among US Gen Z online; if an older/literal sense exists, cover it "
        . "inside 'meaning' clearly labeled as the older sense, never as the primary definition. "
        . "LIMITS: short_def ONE sentence <=180 chars; summary 120-300 chars answer-first; meta_desc 120-132 chars; "
        . "meaning 2-3 full paragraphs; at least 4 examples; at least 3 faqs; image_query = 3-6 plain words for a "
        . "photographable scene (never the slang word itself). "
        . 'STRICT JSON, exactly these keys: {"short_def":"..","summary":"..","meta_desc":"..","meaning":[".."],'
        . '"origin":[".."],"why_trending":[".."],"usage_note":"..","examples":[{"text":"..","context":".."}],'
        // SEO-BATCH-1: `citations` belongs INSIDE this list. It was appended
        // after it as "Also return citations…", which directly contradicted
        // "exactly these keys" — the model was told the list was closed and then
        // told to add to it, and the first replay term came back with
        // ">> rewrite returned no usable JSON".
        . '"faqs":[{"q":"..","a":".."}],"first_seen":"..","image_query":"..",'
        . '"citations":[{"platform":"TikTok|X|Reddit|YouTube|Twitch|Instagram","handle":"@name","date":"any precision","url":"post URL on that platform"},{"publication":"outlet name","date":"any precision","url":"article URL","title":"article headline","quote":"the sentence from the article where the term is USED"}]}'
        . ' CITATIONS: at least 3 dated sightings of the term IN USE. Two kinds are accepted: (a) a real social post - platform, handle, date, post URL; or (b) PUBLISHED USAGE - a publication quoting someone USING the term, with the exact quote copied verbatim from the source excerpt. The quote MUST contain the term and MUST show it being used, never defined or glossed. An article whose purpose is explaining what the term means is NOT a citation. Take everything only from the source excerpts; an empty list is correct and honest, a fabricated one is not.';
    // SEO-BATCH-1: origin comes from RETRIEVAL, never recall.
    $artifact = term_find_origin_artifact($term, (string)($row['lane'] ?? 'slang'));
    $sys .= $artifact
        ? " ORIGIN ARTIFACT (retrieved - use EXACTLY this, nothing else): url={$artifact['url']} date={$artifact['date']} type={$artifact['type']}."
        : " ORIGIN ARTIFACT: NONE FOUND. Return origin:[] and first_seen:\"\" and discuss origin nowhere.";
    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => "TERM: {$term}\nLANE: {$lane}\n\nSOURCES (the ONLY facts you may use):\n{$srcBlock}"],
    ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.35, 300);
    if (isset($res['error'])) { echo "  >> AI error: {$res['error']} - skipped, left as-is\n"; continue; }
    $j = ai_json($res['content']);
    if (!$j || empty($j['meaning']) || empty($j['short_def'])) { echo "  >> rewrite returned no usable JSON - skipped\n"; continue; }
    $j = term_clean($j);

    // length guards (no extra AI calls)
    if (mb_strlen($j['short_def']) > 180) $j['short_def'] = rtrim(mb_substr($j['short_def'], 0, 179), ' ,;.') . '.';
    if (isset($j['meta_desc']) && mb_strlen($j['meta_desc']) > 135) {
        $cut = mb_substr($j['meta_desc'], 0, 132);
        if (($sp = mb_strrpos($cut, ' ')) !== false && $sp > 110) $cut = mb_substr($cut, 0, $sp);
        $j['meta_desc'] = rtrim($cut, ' ,;.') . '.';
    }

    // 3b) DEPTH REPAIR: an honest rewrite often lands under the 380-word gate.
    //     One expansion pass adds REAL depth from the SAME sources (never invented);
    //     with the full-article Wikipedia grounding there is now genuine material to draw on.
    $bodyWords = function (array $f) {
        $t = '';
        foreach (['meaning', 'origin', 'why_trending'] as $k) foreach ((array)($f[$k] ?? []) as $p) $t .= ' ' . (is_string($p) ? $p : '');
        return str_word_count(strip_tags($t));
    };
    if ($bodyWords($j) < 420) {
        $r2 = ai_chat([
            ['role' => 'system', 'content' => "Expand a US Gen Z slang/culture entry using ONLY the SOURCE excerpts. Rewrite ONLY 'meaning', 'origin', 'why_trending' and 'examples' to add REAL depth. The sources typically document the spread, the notable VARIANTS/spin-offs, and how usage evolved: COVER those specific documented details (name the variants, the platforms, how it went mainstream) to reach meaning+origin+why_trending of at least 400 words. NEVER invent a person, date, or view/like/follower/subscriber count the sources do not state; if the sources stay thin on origin, deepen the MEANING and usage instead of padding the origin. 'examples' has at least 4 entries. Output STRICT JSON: {\"meaning\":[..],\"origin\":[..],\"why_trending\":[..],\"examples\":[{\"text\":..,\"context\":..}]}"],
            ['role' => 'user',   'content' => "SOURCES (the ONLY facts you may use):\n{$srcBlock}\nCURRENT (too short):\n" . json_encode(['meaning' => $j['meaning'] ?? [], 'origin' => $j['origin'] ?? [], 'why_trending' => $j['why_trending'] ?? [], 'examples' => $j['examples'] ?? []], JSON_UNESCAPED_SLASHES)],
        ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.5);
        if (!isset($r2['error'])) {
            $j2 = ai_json($r2['content']);
            if ($j2 && !empty($j2['meaning']) && $bodyWords($j2) > $bodyWords($j)) {
                $j2 = term_clean($j2);
                foreach (['meaning', 'origin', 'why_trending', 'examples'] as $f) if (!empty($j2[$f])) $j[$f] = $j2[$f];
            }
        }
        echo "  depth after expand: " . $bodyWords($j) . " words\n";
    }

    // 4) persist the corrected content + the real sources (with excerpts, so future audits see grounding)
    // SEO-BATCH-1: no artifact => the page asserts NOTHING about origin.
    if (!$artifact) { $j['origin'] = []; $j['first_seen'] = ''; }
    // SEO-BATCH-1: the AI draft + depth-expansion passes take minutes, and
    // MySQL wait_timeout on this host is 300s — the replay died silently at
    // exactly this point on term 3 (gacha), mid-write, with no error logged.
    // Same bug already fixed in draft_term.php; it was never applied here.
    $pdo = db_alive();
    gate_term_install($pdo);
    $citeStore = [];
    // SEO-BATCH-1 (7th sibling drift): this writer dropped `quote`, `publication`
    // and `title`. gate_term_valid_citations() rejects any published_usage
    // citation with an empty quote — so EVERY citation written by the redraft
    // path was guaranteed to score zero no matter how good the evidence was.
    // Kept field-for-field identical to draft_term.php's store loop on purpose.
    foreach ((array)($j['citations'] ?? []) as $c) { if (!is_array($c)) continue; $citeStore[] = [
        'platform'    => mb_substr((string)($c['platform'] ?? ''),0,40),
        'handle'      => mb_substr((string)($c['handle'] ?? ''),0,60),
        'publication' => mb_substr((string)($c['publication'] ?? ''),0,80),
        'title'       => mb_substr((string)($c['title'] ?? ''),0,200),
        'date'        => mb_substr((string)($c['date'] ?? ''),0,32),
        'url'         => mb_substr((string)($c['url'] ?? ''),0,400),
        'quote'       => mb_substr((string)($c['quote'] ?? ''),0,300)]; }
    // SEO-BATCH-1: same deterministic top-up as draft_term.php — shared helper,
    // only rows that independently clear the unchanged gate are appended.
    if (count(gate_term_valid_citations($citeStore, $term)) < 3) {
        foreach (term_harvest_citations($sources, $term, $citeStore) as $hc) {
            if (count(gate_term_valid_citations([$hc], $term)) === 1) $citeStore[] = $hc;
            if (count(gate_term_valid_citations($citeStore, $term)) >= 3) break;
        }
    }
    $pdo->prepare("UPDATE terms SET origin_url=?, origin_date=?, origin_date_src=?, origin_type=?, citations=? WHERE page_id=?")
        ->execute([$artifact['url'] ?? null, $artifact['date'] ?? null, $artifact['date_src'] ?? null,
                   $artifact['type'] ?? null, json_encode($citeStore, JSON_UNESCAPED_UNICODE), $pageId]);
    $pdo->prepare("UPDATE terms SET short_def=?, meaning=?, origin=?, why_trending=?, usage_note=?, examples=?, faqs=?, first_seen=?, sources=? WHERE page_id=?")
        ->execute([
            $j['short_def'],
            json_encode($j['meaning'], JSON_UNESCAPED_UNICODE),
            json_encode($j['origin'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($j['why_trending'] ?? [], JSON_UNESCAPED_UNICODE),
            $j['usage_note'] ?? '',
            json_encode($j['examples'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($j['faqs'] ?? [], JSON_UNESCAPED_UNICODE),
            $j['first_seen'] ?? '',
            json_encode($sources, JSON_UNESCAPED_SLASHES),
            $pageId,
        ]);
    if (!empty($j['meta_desc']) && mb_strlen($j['meta_desc']) >= 120 && mb_strlen($j['meta_desc']) <= 160) {
        $pdo->prepare("UPDATE pages SET meta_desc=?, summary=?, updated_at=NOW() WHERE id=?")->execute([$j['meta_desc'], $j['summary'], $pageId]);
    } else {
        $pdo->prepare("UPDATE pages SET summary=?, updated_at=NOW() WHERE id=?")->execute([$j['summary'], $pageId]);
    }
    if (function_exists('ai_log')) ai_log($pageId, 'verify', $res, ['type' => 'poison-redraft', 'grounding' => $real], true);

    $newOrigin = implode(' ', (array)($j['origin'] ?? []));
    echo "  NEW origin: " . mb_substr($newOrigin, 0, 200) . "\n";

    // 5) re-gate; keep indexed only if it passes
    $g = gate_check_term($pageId);
    if ($g['pass']) {
        if ($row['robots'] !== 'index') $pdo->prepare("UPDATE pages SET robots='index' WHERE id=?")->execute([$pageId]);
        echo "  GATE: PASS -> indexed\n";
        $prefix = lanes()[$lane]['prefix'] ?? '/slang/';   // term pages live at /{lane}/{slug}/, not /{slug}/
        echo "  " . indexnow_ping([url($prefix . $slug . '/')]) . "\n";
    } else {
        $pdo->prepare("UPDATE pages SET robots='noindex' WHERE id=?")->execute([$pageId]);
        $fails = array_column(array_filter($g['checks'], fn($c) => !$c['pass']), 'label');
        echo "  GATE: FAIL (" . implode(', ', $fails) . ") -> noindex (pulled until re-drafted)\n";
    }
}
echo "\ndone.\n";
