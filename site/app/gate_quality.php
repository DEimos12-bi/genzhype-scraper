<?php
// GenZHype | QUALITY DEPARTMENT — the final gatekeeper before publish. A page must
// satisfy MANY departments, not just a length check. This file holds the algorithmic
// departments (no AI, can't false-fail, instant). The LLM departments (coverage,
// fact-backing, intent, holistic editor) live in gate_quality_ai.php and bolt on.
//
// Each check returns ['label','pass','detail','weight','hard'] — hard=true means a
// failure BLOCKS publish; soft checks feed a 0-100 score. Output groups by department.

require_once __DIR__ . '/db.php';

/* ---------- text helpers ---------- */

/** Approximate syllable count for an English word. */
function qg_syllables(string $w): int {
    $w = strtolower(preg_replace('/[^a-z]/i', '', $w));
    if ($w === '') return 0;
    if (mb_strlen($w) <= 3) return 1;
    $w = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $w);
    $w = preg_replace('/^y/', '', $w);
    preg_match_all('/[aeiouy]{1,2}/', $w, $m);
    return max(1, count($m[0]));
}

/** Flesch Reading Ease (higher = easier) + approx US grade level. */
function qg_readability(string $text): array {
    $sentences = max(1, preg_match_all('/[.!?]+(\s|$)/', $text));
    preg_match_all('/[A-Za-z\x27]+/', $text, $wm);
    $words = $wm[0]; $nw = max(1, count($words));
    $syl = 0; foreach ($words as $w) $syl += qg_syllables($w);
    $ease  = 206.835 - 1.015 * ($nw / $sentences) - 84.6 * ($syl / $nw);
    $grade = 0.39 * ($nw / $sentences) + 11.8 * ($syl / $nw) - 15.59;
    return ['ease' => round($ease, 1), 'grade' => round($grade, 1), 'words' => $nw, 'sentences' => $sentences];
}

/** Sentence-length variety (stdev of words/sentence) — monotone prose reads as AI/boring. */
function qg_sentence_variety(string $text): float {
    $parts = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $lens = array_map(fn($s) => str_word_count($s), $parts);
    $lens = array_filter($lens, fn($l) => $l > 0);
    $n = count($lens); if ($n < 3) return 0.0;
    $m = array_sum($lens) / $n; $v = 0.0;
    foreach ($lens as $l) $v += ($l - $m) ** 2;
    return round(sqrt($v / $n), 1);
}

/** Scan for the tired AI-writing tells. Returns the hits found. */
function qg_ai_tells(string $text): array {
    $tells = ['delve', 'tapestry', 'in conclusion', 'important to note', 'navigating the',
        'realm of', 'testament to', 'a myriad of', 'plethora', 'underscores', 'pivotal',
        'dive into', "let's explore", "let's dive", "in today's", 'ever-evolving',
        'landscape of', 'seamlessly', 'when it comes to', 'at the end of the day',
        'it goes without saying', 'needless to say', 'the world of', 'first and foremost',
        'in the realm', 'unlock the', 'game-changer', 'elevate your', 'embark on'];
    $low = mb_strtolower($text); $hits = [];
    foreach ($tells as $t) if (mb_strpos($low, $t) !== false) $hits[] = $t;
    if (mb_strpos($text, '—') !== false) $hits[] = 'em-dash';
    return $hits;
}

/** Spelling via hunspell, tolerant of slang (returns misspelled-word ratio %). */
function qg_spelling_ratio(string $text): float {
    if (!function_exists('shell_exec')) return -1.0;      // disabled on shared hosting
    $words = preg_match_all('/[A-Za-z\x27]{3,}/', $text, $m) ? $m[0] : [];
    if (count($words) < 20) return 0.0;
    $tmp = tempnam(sys_get_temp_dir(), 'sp');
    file_put_contents($tmp, implode("\n", $words));
    $out = @shell_exec('hunspell -l -d en_US ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    if ($out === null) return -1.0;                       // hunspell unavailable
    $bad = array_filter(explode("\n", trim($out)));
    return round(count($bad) / count($words) * 100, 1);
}

/**
 * AI-DETECTION heuristic (our free Originality.ai-style signal). NOT a perfect
 * detector — it scores how ROBOTIC the prose reads: uniform sentence length,
 * repeated openers, AI-tell phrases, no contractions, hedge-word density. We WANT
 * this LOW (our content is AI-written; the goal is it shouldn't READ as AI). 0-100.
 */
function qg_ai_likelihood(string $text): int {
    $sents = array_values(array_filter(array_map('trim', preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY)), fn($s) => str_word_count($s) >= 3));
    $n = count($sents); if ($n < 6) return 0;
    $score = 0;
    if (($v = qg_sentence_variety($text)) < 3) $score += 25; elseif ($v < 5) $score += 12;   // uniform length
    $op = []; foreach ($sents as $s) { $w = strtolower(preg_split('/\s+/', trim($s))[0] ?? ''); $op[$w] = ($op[$w] ?? 0) + 1; }
    $maxOp = $op ? max($op) / $n : 0;
    if ($maxOp > 0.3) $score += 20; elseif ($maxOp > 0.2) $score += 10;                       // repeated openers
    $score += min(25, count(qg_ai_tells($text)) * 8);                                          // AI-tell phrases
    $contr = preg_match_all('/\b\w+\x27(t|s|re|ll|ve|d|m)\b/i', $text);
    $per100 = $contr / max(1, str_word_count($text) / 100);
    if ($per100 < 0.5) $score += 15; elseif ($per100 < 1.2) $score += 7;                       // too formal (no contractions)
    $score += min(15, preg_match_all('/\b(various|numerous|significant|essentially|generally|moreover|furthermore|additionally|notably|particularly|substantial|comprehensive)\b/i', $text) * 3);
    return min(100, $score);
}

/**
 * WEB-PLAGIARISM lite (our free partial Copyscape). Takes the most distinctive
 * sentences and quoted-searches the web; a verbatim hit on another site = the AI
 * copied a source instead of writing original prose. Best-effort, online.
 */
function qg_web_plagiarism(string $text, string $own = 'genzhype.com'): array {
    require_once __DIR__ . '/fetch_sources.php';
    $sents = array_filter(array_map('trim', preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY)),
        fn($s) => str_word_count($s) >= 9 && str_word_count($s) <= 26);
    usort($sents, fn($a, $b) => str_word_count($b) <=> str_word_count($a));
    $picks = array_slice(array_values($sents), 0, 2);
    $matches = []; $checked = 0;
    foreach ($picks as $s) {
        $checked++;
        $urls = fs_search('"' . mb_substr($s, 0, 120) . '"', 5);
        foreach ($urls as $u) { if (stripos($u, $own) === false) { $matches[] = parse_url($u, PHP_URL_HOST) ?: $u; break; } }
    }
    return ['checked' => $checked, 'matches' => array_values(array_unique($matches))];
}

/* ---------- the gate ---------- */

function gate_quality(int $page_id, bool $withAI = false): array {
    $pdo = db();
    $page = $pdo->query("SELECT * FROM pages WHERE id=" . (int)$page_id)->fetch();
    if (!$page) return ['pass' => false, 'score' => 0, 'departments' => [], 'note' => 'page not found'];
    $isTerm = ($page['type'] === 'term');
    $row = $pdo->query("SELECT * FROM " . ($isTerm ? 'terms' : 'dramas') . " WHERE page_id=" . (int)$page_id)->fetch() ?: [];
    $jd = fn($v) => is_array($x = json_decode($v ?? '[]', true)) ? $x : [];

    // assemble the body prose
    $body = '';
    foreach (['meaning', 'origin', 'why_trending', 'background'] as $f) {
        foreach ($jd($row[$f] ?? '') as $p) $body .= (is_string($p) ? $p : ($p['desc'] ?? '')) . ' ';
    }
    $body = trim($body);
    $kw = mb_strtolower($row['term'] ?? $row['title'] ?? $page['h1']);
    $read = qg_readability($body ?: $page['summary']);
    $tells = qg_ai_tells($body);
    $variety = qg_sentence_variety($body);
    $spell = qg_spelling_ratio($body);
    $title = $page['title_tag']; $meta = $page['meta_desc'];

    $dept = [];

    // DEPT 1 — Depth & Coverage
    $dept['Depth & Coverage'] = [
        ['label' => 'body >= 380 words', 'pass' => $read['words'] >= 380, 'detail' => "{$read['words']} words", 'weight' => 3, 'hard' => true],
        ['label' => 'ideal depth 550-1100', 'pass' => $read['words'] >= 550 && $read['words'] <= 1100, 'detail' => "{$read['words']} words", 'weight' => 2, 'hard' => false],
        ['label' => 'examples >= 2', 'pass' => count($jd($row['examples'] ?? '')) >= 2, 'detail' => count($jd($row['examples'] ?? '')) . '', 'weight' => 2, 'hard' => false],
        ['label' => 'FAQs >= 2', 'pass' => count($jd($row['faqs'] ?? '')) >= 2, 'detail' => count($jd($row['faqs'] ?? '')) . '', 'weight' => 2, 'hard' => true],
    ];

    // DEPT 2 — Readability & Writing
    $dept['Readability & Writing'] = [
        ['label' => 'Flesch ease 50-80 (clear)', 'pass' => $read['ease'] >= 45 && $read['ease'] <= 85, 'detail' => "ease {$read['ease']}", 'weight' => 2, 'hard' => false],
        ['label' => 'grade level <= 11', 'pass' => $read['grade'] <= 11, 'detail' => "grade {$read['grade']}", 'weight' => 2, 'hard' => false],
        ['label' => 'sentence variety >= 4', 'pass' => $variety >= 4, 'detail' => "stdev {$variety}", 'weight' => 1, 'hard' => false],
        ['label' => 'spelling clean (<6% off)', 'pass' => $spell < 6 || $spell < 0, 'detail' => ($spell < 0 ? 'hunspell n/a' : "{$spell}% flagged"), 'weight' => 1, 'hard' => false],
    ];

    // DEPT 3 — Originality & AI-tells
    require_once __DIR__ . '/gate_term.php';
    $overlap = (function_exists('term_max_overlap') && $isTerm)
        ? term_max_overlap($page_id, $row['lane'] ?? 'slang', $body)
        : ['pct' => 0];
    $aiLike = qg_ai_likelihood($body);
    $dept['Originality'] = [
        ['label' => 'unique vs our pages (<35%)', 'pass' => ($overlap['pct'] ?? 0) < 35, 'detail' => round($overlap['pct'] ?? 0, 1) . '% overlap', 'weight' => 3, 'hard' => true],
        ['label' => 'no AI-writing tells', 'pass' => count($tells) === 0, 'detail' => $tells ? implode(', ', array_slice($tells, 0, 4)) : 'clean', 'weight' => 2, 'hard' => false],
        ['label' => 'reads human (AI-score < 55)', 'pass' => $aiLike < 55, 'detail' => "{$aiLike}/100 robotic", 'weight' => 2, 'hard' => false],
    ];

    // DEPT 4 — SEO & Structure
    $low = mb_strtolower($body . ' ' . $page['h1']);
    $tt = mb_strlen($title); $md = mb_strlen($meta);
    $dept['SEO & Structure'] = [
        ['label' => 'title 40-60 chars', 'pass' => $tt >= 40 && $tt <= 60, 'detail' => "{$tt}", 'weight' => 2, 'hard' => false],
        ['label' => 'meta 110-135 chars', 'pass' => $md >= 110 && $md <= 135, 'detail' => "{$md}", 'weight' => 2, 'hard' => false],
        ['label' => 'keyword in title', 'pass' => mb_stripos($title, $kw) !== false, 'detail' => "\"{$kw}\"", 'weight' => 2, 'hard' => false],
        ['label' => 'keyword in first 100 words', 'pass' => mb_stripos(mb_substr($body, 0, 600), $kw) !== false, 'detail' => '', 'weight' => 1, 'hard' => false],
        ['label' => 'H1 present', 'pass' => mb_strlen($page['h1']) > 6, 'detail' => '', 'weight' => 1, 'hard' => true],
    ];

    // DEPT 5 — Trust & Sourcing (E-E-A-T)
    $sources = $jd($row['sources'] ?? '');
    $dept['Trust & Sourcing'] = [
        ['label' => '>= 2 cited sources', 'pass' => count($sources) >= 2, 'detail' => count($sources) . ' sources', 'weight' => 3, 'hard' => true],
        ['label' => 'published/updated dates', 'pass' => !empty($page['published_at']) && !empty($page['updated_at']), 'detail' => '', 'weight' => 1, 'hard' => false],
        ['label' => 'byline / author set', 'pass' => !empty($page['author_id']), 'detail' => '', 'weight' => 1, 'hard' => false],
    ];

    // DEPT 6 — Media
    $hasAlt = $isTerm; // images carry term alt by template; presence proven elsewhere
    $dept['Media'] = [
        ['label' => 'featured image present', 'pass' => !empty($page['featured_img']) || !empty($page['cover']), 'detail' => '', 'weight' => 2, 'hard' => false],
        ['label' => 'cover present', 'pass' => !empty($page['cover']), 'detail' => '', 'weight' => 1, 'hard' => true],
    ];

    // DEPT 7 — Safety & Brand
    $unsafe = preg_match('/\b(nigg|fagg|kike|chink|spic|kkk)\w*/i', $body);
    $dept['Safety & Brand'] = [
        ['label' => 'no slurs / ad-safe text', 'pass' => !$unsafe, 'detail' => $unsafe ? 'FLAGGED' : 'clean', 'weight' => 3, 'hard' => true],
        ['label' => 'no em-dashes (brand rule)', 'pass' => mb_strpos($body, '—') === false, 'detail' => '', 'weight' => 1, 'hard' => false],
    ];

    // DEPT 8 — Competitive Edge (driven LIVE by the Competitor Engine rulebook). Soft checks:
    // they don't block publish, they reward pages that actually beat what rivals ship. Absent
    // until the first distill, so the gate behaves exactly as before on a fresh install.
    require_once __DIR__ . '/competitor.php';
    $depthRule = comp_rule_get($pdo, 'gate', 'depth_benchmark', null);
    $moatRule  = comp_rule_get($pdo, 'citation', 'ai_schema_moat', null);
    if (is_array($depthRule) && !empty($depthRule['recommend_min_words'])) {
        $minW  = (int)$depthRule['recommend_min_words'];
        $rivMed = (int)($depthRule['median_words'] ?? 0);
        $faqN  = count($jd($row['faqs'] ?? ''));
        $rivFaq = is_array($moatRule) ? (int)($moatRule['faqpage_pct'] ?? 0) : 0;
        // Measure FULL on-page substance, comparable to rivals' whole-article word count:
        // a drama's real content is the timeline events + summary, not just the background field.
        $fullWords = $read['words'] + str_word_count((string)($page['summary'] ?? ''));
        if (!$isTerm && !empty($row['id'])) {
            $evText = '';
            foreach ($pdo->query("SELECT title, description FROM events WHERE drama_id=" . (int)$row['id']) as $e)
                $evText .= ' ' . ($e['title'] ?? '') . ' ' . ($e['description'] ?? '');
            $fullWords += str_word_count(strip_tags($evText));
        }
        $dept['Competitive Edge'] = [
            ['label' => "beats rival depth (>= {$minW}w)", 'pass' => $fullWords >= $minW,
             'detail' => "{$fullWords}w on-page" . ($rivMed ? " vs rival median {$rivMed}w" : ''), 'weight' => 2, 'hard' => false],
            ['label' => 'ships AI-citation schema rivals lack', 'pass' => $faqN >= 2,
             'detail' => "FAQ here {$faqN}; rivals at {$rivFaq}%", 'weight' => 2, 'hard' => false],
        ];
    }

    // ---- AI editor departments (best-effort; skipped if the LLM is rate-limited) ----
    if ($withAI) {
        require_once __DIR__ . '/gate_quality_ai.php';
        $aiDepts = gate_quality_ai_departments($page_id);
        if (empty($aiDepts['_ai_down'])) $dept = array_merge($dept, $aiDepts);
        // web-plagiarism (online, best-effort): a verbatim hit = the AI copied a source
        $plag = qg_web_plagiarism($body);
        if ($plag['checked'] > 0) {
            $dept['Plagiarism (web)'] = [
                ['label' => 'no verbatim web copies', 'pass' => empty($plag['matches']),
                 'detail' => $plag['matches'] ? 'found on: ' . implode(', ', $plag['matches']) : "checked {$plag['checked']}, clean",
                 'weight' => 3, 'hard' => true],
            ];
        }
    }

    // ---- aggregate ----
    $score = 0; $max = 0; $hardFails = []; $checks = 0; $passed = 0;
    foreach ($dept as $dname => $list) {
        foreach ($list as $c) {
            $checks++; $w = $c['weight'];
            $max += $w;
            if ($c['pass']) { $score += $w; $passed++; }
            elseif (!empty($c['hard'])) $hardFails[] = "{$dname}: {$c['label']}";
        }
    }
    $pct = $max ? (int)round($score / $max * 100) : 0;
    return [
        'pass'       => empty($hardFails) && $pct >= 70,
        'score'      => $pct,
        'hard_fails' => $hardFails,
        'checks'     => $checks,
        'passed'     => $passed,
        'departments'=> $dept,
        'readability'=> $read,
    ];
}
