<?php
// GenZHype | SLANG discovery. Refills the term backlog automatically:
//  1) harvest candidate words from live sources (Urban Dictionary words-of-the-day,
//     Google Trends US daily searches)
//  2) one cheap AI call filters the batch: real searchable slang vs noise
//  3) survivors land in candidates (type=term, status=selected) for the builder.
// Every step is best-effort; a dead source is skipped, never fatal.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/fetch_sources.php';   // fs_http_get
require_once __DIR__ . '/draft_term.php';      // term_slugify

/** Urban Dictionary homepage = today's words of the day (high-signal slang). */
function dt_ud_words(): array {
    $html = fs_http_get('https://www.urbandictionary.com/', 15);
    if (!$html) return [];
    $words = [];
    // the featured words sit in <span class="word ..." id="...">Word</span> inside h1/h2
    if (preg_match_all('#<span class="word[^"]*"[^>]*>\s*([^<]{2,40}?)\s*</span>#s', $html, $m)) {
        foreach ($m[1] as $raw) {
            $w = trim(preg_replace('/\s+/', ' ', html_entity_decode($raw, ENT_QUOTES, 'UTF-8')));
            if ($w !== '' && mb_strlen($w) <= 30 && str_word_count($w) <= 4 && !preg_match('/[{}<>]/', $w)) {
                $words[mb_strtolower($w)] = true;
            }
        }
    }
    return array_slice(array_keys($words), 0, 15);
}

/**
 * r/OutOfTheLoop + r/NoStupidQuestions = people literally asking "what does X
 * mean?" about trending things the moment they go viral. Highest-signal free
 * trend radar; we extract the term and let the AI filter reject news/politics.
 * SOURCE = Reddit's own /new/.rss feed: CURRENT (today's posts) and datacenter-
 * reachable. (Reddit's .json API + search both block our server IP; the PullPush
 * archive we used before is ~13 MONTHS stale, so it caught year-old "trends" —
 * the .rss feed is the live fix.)
 */
function dt_reddit_questions(): array {
    $titles = [];
    // REACH (2026-08-05): Reddit walls this server outright (403 even with a
    // logged-in cookie — IP reputation, measured). The reach-runner harvests
    // r/OutOfTheLoop on a GitHub runner every 6h and reach_ingest.php caches
    // it here; a cache younger than 24h IS Reddit for this function. The old
    // direct RSS fetch stays below as a free best-effort fallback.
    $cacheF = __DIR__ . '/reach_cache.json';
    if (is_file($cacheF)) {
        $c = json_decode((string)file_get_contents($cacheF), true);
        $fresh = isset($c['at']) && (time() - strtotime((string)$c['at'])) < 86400;
        if ($fresh && !empty($c['reddit_titles'])) {
            foreach ($c['reddit_titles'] as $t) if (is_string($t) && $t !== '') $titles[] = $t;
        }
    }
    foreach ($titles ? [] : ['OutOfTheLoop', 'NoStupidQuestions'] as $sub) {
        $raw = fs_http_get("https://www.reddit.com/r/$sub/new/.rss?limit=25", 15);
        if (!$raw) continue;
        $x = @simplexml_load_string($raw);
        if (!$x) continue;
        foreach ($x->entry ?? [] as $e) {
            $t = trim(html_entity_decode((string)$e->title, ENT_QUOTES, 'UTF-8'));
            if ($t !== '') $titles[] = $t;
        }
    }
    // pull the actual TERM out of the question wrapper. stopword-only fragments
    // ("you", "this", "the deal with") are dropped here so we don't pollute the
    // candidates table or waste the AI filter on obvious noise.
    $stop = ['you','it','this','that','these','those','they','them','the deal with',
             's the deal with','the','a','an','people','everyone','someone','stuff',
             'thing','things','it even','this teenage','correct its mistakes'];
    $words = [];
    foreach ($titles as $t) {
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        $cand = '';
        // 1) anything in quotes (straight or curly) is the cleanest signal
        if (preg_match('/[\x{201C}"\x{2018}\']([^\x{201D}"\x{2019}\']{2,40})[\x{201D}"\x{2019}\']/u', $t, $m)) {
            $cand = $m[1];
        // 2) strict "what does/is X mean" — must END on the mean/trend anchor
        } elseif (preg_match('/what(?:\x{2019}s|\'s| is| does| are)\s+(.{2,35}?)\s+(?:mean|meaning|trend)\b/iu', $t, $m)) {
            $cand = $m[1];
        // 2b) "what's going on/up with X?" — CONSUME the lead-in (the dominant
        //     r/OutOfTheLoop shape), capture X up to the end, no "on with" fragments
        } elseif (preg_match('/what(?:\x{2019}s|\'s|s| is| are)?\s+(?:going on with|up with|the deal with|the deal is with|happening with)\s+(.{2,40}?)\s*[?.!]*$/iu', $t, $m)) {
            $cand = $m[1];
        // 3) "people saying/writing X" viral-phrase pattern
        } elseif (preg_match('/people (?:saying|writing|posting|doing)\s+(.{2,35}?)\s*(?:everywhere|all the time|\?|$)/iu', $t, $m)) {
            $cand = $m[1];
        }
        $cand = trim(preg_replace('/\s+/', ' ', $cand), " \t\n\"'.,?!-");
        $low  = mb_strtolower($cand);
        // strip leading filler/question words token by token ("up with this rizz"
        // -> "rizz"). "on"/"of" are NOT fillers so "on god" / "eye of rah" survive.
        $filler = ['s','up','with','going','the','a','an','this','that','these','those','deal','about','whats','what'];
        $tok = preg_split('/\s+/', $low, -1, PREG_SPLIT_NO_EMPTY);
        while ($tok && in_array($tok[0], $filler, true)) array_shift($tok);
        $core = implode(' ', $tok);
        // keep only short, slang-shaped phrases; drop numbers, stopwords, noise
        if ($core !== '' && mb_strlen($core) >= 3 && mb_strlen($core) <= 35
            && str_word_count($core) <= 5 && !preg_match('/^\d+$/', $core)
            && strpos($core, 'on with') !== 0 && mb_strpos($core, '?') === false
            && !in_array($low, $stop, true) && !in_array($core, $stop, true)
            && !preg_match('/[{}<>\/\\\\@\[\]]/', $core)) {
            $words[$core] = true;
        }
    }
    return array_slice(array_keys($words), 0, 25);
}

/** Google Trends US daily trending searches (mostly news; the AI filter decides). */
function dt_trends_words(): array {
    $xml = fs_http_get('https://trends.google.com/trending/rss?geo=US', 15);
    if (!$xml) return [];
    $words = [];
    if (preg_match_all('#<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</title>#is', $xml, $m)) {
        foreach (array_slice($m[1], 1) as $t) {   // first <title> is the feed name
            $t = trim(html_entity_decode($t, ENT_QUOTES, 'UTF-8'));
            if ($t !== '' && mb_strlen($t) <= 40 && str_word_count($t) <= 4) $words[mb_strtolower($t)] = true;
        }
    }
    return array_slice(array_keys($words), 0, 20);
}

/**
 * Harvest + dedupe + AI-filter + queue. Returns a stats array.
 */
/**
 * SEO-BATCH-1 : AMBIGUITY / WINNABILITY CHECK.
 *
 * The discovery engine scored candidates on TREND HEAT only, and heat measures
 * popularity, not winnability. That is exactly how `unc` (University of North
 * Carolina), `lock-in` (vendor lock-in) and `mid` (mid-range, midfielder) got
 * published: for all three the slang sense is a minority meaning, Google
 * resolves the ambiguity toward the dominant entity, and a young site never wins
 * that query no matter how good the page is.
 *
 * Measured evidence for the rule: the pages that get impressions are not better
 * built than the ones that do not — same template, same publish week, and
 * internal linking is actually INVERTED (`mid` has the most inbound links and
 * performs worst). The difference is the query, not the page.
 *
 * So: reject a candidate whose head term is owned by a larger entity, whatever
 * its heat score. Cheap deterministic screens first (short acronyms, known
 * institutional collisions), then one AI judgement on the rest.
 *
 * Returns ['owned'=>bool,'owner'=>string].
 */
function dt_query_ownership(string $term): array {
    $t = mb_strtolower(trim($term));

    // NOTE: a "reject all 2-4 letter tokens" rule was written here first and
    // REMOVED before it shipped — it would have rejected gg, pog, meta and kekw,
    // and gg + smurfing are two of the best-performing pages on the site. Length
    // is not the signal; OWNERSHIP is. So this stays a list of known collisions,
    // and the general judgement is asked of the AI filter (see discover_terms_run),
    // which already runs once per batch and therefore costs nothing extra.
    //
    // Ordinary-English compounds that already have an established industry sense.
    static $collisions = [
        'lock-in' => 'vendor lock-in (business/tech)',
        'lock in' => 'vendor lock-in (business/tech)',
        'mid'     => 'mid-range / midfielder / MID acronym',
        'unc'     => 'University of North Carolina',
        'ohio'    => 'the US state',
        'sus'     => 'suspicious (generic) / SUS scale',
        'npc'     => 'non-player character (generic games term)',
    ];
    if (isset($collisions[$t])) return ['owned' => true, 'owner' => $collisions[$t]];

    return ['owned' => false, 'owner' => ''];
}

/**
 * SEO-BATCH-1 : THE PRE-DRAFT SCREEN (owner decision 2026-08-04).
 *
 * Three properties, ALL knowable before a single draft token is spent, each one
 * discovered the hard way this week:
 *
 *   1. ownership-clear      the query is not owned by a bigger entity.
 *                           `clutch` -> automotive, `mid` -> mid-range. A term
 *                           you cannot rank for is not worth crawling.
 *   (a crawl-reachability screen was here and was REMOVED — see below.)
 *   2. citation-harvestable real press coverage exists that QUOTES the term in
 *                           use. Proven by contrast: `pogchamp` has Engadget and
 *                           Kotaku quoting it; `spawn camping` has only glossary
 *                           sites, so no honest citation can ever be built.
 *
 * Returns ['pass'=>bool,'failed'=>string|null,'detail'=>string]. The caller must
 * log WHICH screen failed — that is what stops us burning draft cycles on terms
 * that were never publishable.
 */
function term_prepublish_screen(string $term, string $lane, array $indexedSlugs = [], string $selfSlug = ''): array {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/gate_term.php';
    require_once __DIR__ . '/fetch_sources.php';
    $pdo = db();

    // --- screen 1: ownership, MEASURED not judged (owner decision 2026-08-05).
    //     The AI judgement answered "31 owned" then "24 owned" on identical
    //     input; ownership is a SERP fact. so_verdict() reads Bing page-1
    //     (mkt=en-US) deterministically and stores which results counted.
    //     OWNED (no slang-sense result on page 1) fails; CONTESTED/CLEAR pass;
    //     NO-DATA fails closed — a term we could not measure is not published
    //     on a guess. The static dt_query_ownership() list still short-circuits
    //     first: it is free and never wrong about its own entries. ---
    require_once __DIR__ . '/ownership_serp.php';
    $own = dt_query_ownership($term);
    if ($own['owned']) return ['pass' => false, 'failed' => 'ownership', 'detail' => $own['owner']];
    $sv = so_verdict($term);
    if ($sv['verdict'] === 'OWNED')   return ['pass' => false, 'failed' => 'ownership',
        'detail' => 'SERP: 0/' . $sv['total'] . ' slang-sense results — ' . $sv['owner_hint']];
    if ($sv['verdict'] === 'NO-DATA') return ['pass' => false, 'failed' => 'ownership',
        'detail' => 'SERP unmeasurable (engine served no usable page-1) — fail closed'];

    // --- screen 2 (crawl reachability) REMOVED 2026-08-04 ---
    // It required another INDEXED term page to mention the candidate. Verified
    // live and it was a phantom: /gaming/, /slang/ and /meme/ are all indexable,
    // in the sitemap, linked from the homepage, and between them link to 81 of
    // the 82 published terms. Every term already has home -> hub -> term. The 62
    // "Discovered - never crawled" pages are a CRAWL BUDGET problem (authority
    // and URL count), which internal linking cannot fix. The screen also let every
    // existing page pass by MENTIONING ITSELF, which is not a crawl path at all.

    // --- screen 3: citation harvestability ---
    $news = [];
    foreach ([$term, "$term " . ($lane === 'gaming' ? 'gaming' : 'meaning')] as $q) {
        foreach (fs_news_search($q, 5) as $u) if (!gate_term_is_commodity_source($u)) $news[$u] = 1;
        if (count($news) >= 3) break;
    }
    if (count($news) < 2) return ['pass' => false, 'failed' => 'citations',
                                  'detail' => count($news) . ' non-commodity press result(s)'];
    // ($reach died with screen 2 — referencing it here was a fatal on every pass verdict)
    return ['pass' => true, 'failed' => null,
            'detail' => 'serp: ' . $sv['verdict'] . ' ' . $sv['sense_hits'] . '/' . $sv['total'] . ' | press: ' . count($news)];
}

function discover_terms_run(): array {
    $pdo = db();
    $harvest = [];
    // r/OutOfTheLoop FIRST: it catches viral trends days before the dictionaries do
    foreach (dt_reddit_questions() as $w) $harvest[$w] = 'reddit_ootl';
    foreach (dt_ud_words() as $w)     $harvest[$w] = $harvest[$w] ?? 'urbandictionary_wotd';
    foreach (dt_trends_words() as $w) $harvest[$w] = $harvest[$w] ?? 'google_trends';
    if (!$harvest) return ['harvested' => 0, 'new' => 0, 'selected' => 0, 'note' => 'all sources empty'];

    // dedupe against existing pages (by slug) and candidates (by name, any status)
    $fresh = [];
    foreach ($harvest as $w => $src) {
        $slug = term_slugify($w);
        if ($slug === '') continue;
        $q = $pdo->prepare("SELECT 1 FROM pages WHERE slug=? UNION SELECT 1 FROM candidates WHERE type='term' AND LOWER(name)=? LIMIT 1");
        $q->execute([$slug, mb_strtolower($w)]);
        if (!$q->fetch()) $fresh[$w] = $src;
    }
    if (!$fresh) return ['harvested' => count($harvest), 'new' => 0, 'selected' => 0, 'note' => 'nothing new'];

    // one AI call filters the whole batch: real slang vs news/brands/people
    $list = '';
    $i = 1;
    foreach ($fresh as $w => $src) { $list .= "$i. \"$w\" (source: $src)\n"; $i++; }
    $sys = 'You are the GenZHype slang desk editor. Your job is to catch internet/Gen Z culture EARLY, '
        . 'so LEAN TOWARD ACCEPTING anything that feels internet-native. '
        . 'ACCEPT (slang:true): Gen Z slang ("rizz", "delulu", "fanum tax"), meme phrases, TikTok trends and challenges '
        . '("walk like it\'s heavy", "chicken jockey"), viral catchphrases, reaction phrases, brainrot, copypasta and '
        . 'in-joke references — EVEN IF niche, brand-new, or you are not sure people search it yet. We want them early. '
        . 'When unsure but it feels internet-native, ACCEPT. '
        . 'ONLY REJECT (slang:false): clear news or politics (elections, governments, politicians, court cases), '
        . 'BARE person/celebrity names with no phrase attached, products/companies/brands, movie or show titles, '
        . 'and plain ordinary English words with no internet meaning. '
        . 'HARD REJECT ALWAYS (never accept, ad-safety): anything sexual-explicit, or a slur. '
        // SEO-BATCH-1 WINNABILITY: heat measures popularity, not whether we can
        // ever rank. Publishing on heat alone is what produced `unc` (University
        // of North Carolina), `lock-in` (vendor lock-in) and `mid` (mid-range /
        // midfielder) — queries where the slang sense is a minority meaning and
        // Google resolves toward the dominant entity.
        . 'ALSO REJECT (owned:true) any phrase whose SEARCH QUERY is dominated by a bigger entity or a non-slang sense: '
        . 'a university or company abbreviation (unc), an established business/tech term (lock-in), '
        . 'a common English word whose ordinary sense dominates (mid). Judge the QUERY, not the word: '
        . 'if someone googling this would overwhelmingly mean something else, it is owned. '
        . 'Gaming-native vocabulary that only exists in gaming (gg, pog, smurfing, griefing) is NOT owned — accept those. '
        . 'Output STRICT JSON only.';
    $user = $list . "\nReturn JSON: {\"terms\":[{\"name\":\"exact phrase\",\"slang\":true,\"owned\":false,\"owner\":\"who owns the query if owned\",\"heat\":0-100,\"reason\":\"short\"}]} "
        . "with one entry per input phrase. heat = how viral/searched you judge it. "
        . "Score established or clearly-viral phrases 60-90; promising-but-new internet phrases 50-65 (so they still qualify to build); "
        . "only genuinely weak/uncertain ones below 50.";
    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.2);
    if (isset($res['error'])) return ['harvested' => count($harvest), 'new' => count($fresh), 'selected' => 0, 'note' => 'AI filter failed: ' . $res['error']];
    $j = ai_json($res['content']);
    if (!$j || !isset($j['terms']) || !is_array($j['terms'])) {
        return ['harvested' => count($harvest), 'new' => count($fresh), 'selected' => 0, 'note' => 'AI filter returned no JSON'];
    }
    // an empty terms array is a VALID verdict: nothing qualified today

    $ins = $pdo->prepare("INSERT INTO candidates (type,name,angle,heat_score,era,status,signals,ai_verdict,reject_reason)
                          VALUES ('term',?,?,?,'present',?,?,?,?)");
    $sel = 0; $rej = 0;
    $verdicts = [];
    foreach ($j['terms'] as $t) {
        $name = mb_strtolower(trim($t['name'] ?? ''));
        if ($name !== '') $verdicts[$name] = $t;
    }
    // every fresh harvest gets stored (selected or rejected) so the hourly
    // tick never re-judges the same phrase twice
    foreach ($fresh as $name => $src) {
        $t = $verdicts[$name] ?? null;
        $isSlang = $t ? !empty($t['slang']) : false;
        $heat = max(0, min(100, (int)($t['heat'] ?? 50)));
        $reason = $t ? ($t['reason'] ?? '') : 'no verdict from filter';
        // SEO-BATCH-1: winnability gate. A query owned by a bigger entity is
        // rejected REGARDLESS of heat — trend score measures popularity, not
        // whether we can ever rank for it.
        if ($isSlang) {
            $own = dt_query_ownership($name);                 // known collisions
            if (!$own['owned'] && $t && !empty($t['owned'])) { // AI judgement
                $own = ['owned' => true, 'owner' => (string)($t['owner'] ?? 'a larger entity')];
            }
            if ($own['owned']) {
                $isSlang = false;
                $reason = 'query owned by a larger entity — ' . $own['owner'];
            }
        }
        $ins->execute([
            $name,
            'slang dictionary entry',
            $heat,
            $isSlang ? 'selected' : 'rejected',
            json_encode(['source' => $src, 'discovered' => date('Y-m-d')]),
            json_encode(['slang' => $isSlang, 'reason' => mb_substr($reason, 0, 180)], JSON_UNESCAPED_UNICODE),
            $isSlang ? null : mb_substr('not slang: ' . $reason, 0, 250),
        ]);
        $isSlang ? $sel++ : $rej++;
    }
    ai_log(null, 'selection', $res, ['discovered' => count($fresh), 'selected' => $sel], $sel > 0);
    return ['harvested' => count($harvest), 'new' => count($fresh), 'selected' => $sel, 'rejected' => $rej, 'provider' => $res['provider']];
}
