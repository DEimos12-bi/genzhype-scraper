<?php
/**
 * GenZHype | Competitor Intelligence DISTILLER — Phase 3 of the Competitor Engine.
 *
 * Reads everything the watcher harvested (comp_snapshot) and turns it into a versioned,
 * confidence-scored LIVING RULEBOOK (comp_rule) that the SEO gate, the drafter, and the
 * Citation Engine read. Deterministic-first: every rule here is a real statistic over real
 * competitor pages (no LLM guesswork) — median depth, schema adoption %, section patterns,
 * who they cite, publishing cadence. An optional Gemini pass (comp_distill_narrate) layers
 * a human-readable strategy summary on top, but the numbers stand on their own.
 *
 * Design principle (matches the site's "hardcoded vs AI-needed" split): the levers that can
 * be counted are counted; the LLM only ever INTERPRETS, never invents the underlying numbers.
 */

require_once __DIR__ . '/competitor.php';

/**
 * Build a short, prompt-ready "competitive bar" the drafters append to their system prompt.
 * This is how the rulebook reaches the LLM: the drafter literally tells the model the depth,
 * structure and sourcing bar to clear — derived live from real rival pages, not hardcoded.
 * Returns '' if no rules exist yet (drafter then runs exactly as before).
 */
function comp_brief_for_drafter(PDO $pdo): string {
    $depth = comp_rule_get($pdo, 'gate', 'depth_benchmark', []);
    $struct = comp_rule_get($pdo, 'drafter', 'structure_target', []);
    $src = comp_rule_get($pdo, 'drafter', 'source_shortlist', []);
    if (!is_array($depth) || !$depth) return '';

    $minw   = (int)($depth['recommend_min_words'] ?? 600);
    $median = (int)($depth['median_words'] ?? 0);
    $h2     = (int)($struct['recommend_h2_min'] ?? 7);
    $domains = implode(', ', array_slice((array)($src['top_domains'] ?? []), 0, 8));

    $b  = "\n\nCOMPETITIVE BAR (live intelligence from real rival pages — clear it to outrank them): ";
    $b .= $median ? "Rival pages on topics like this run THIN (median {$median} words). " : '';
    $b .= "Beat them with genuine substance: the background + event descriptions should total at least {$minw} words of real, specific detail (NEVER pad — depth must be earned by the sources). ";
    $b .= "Cover at least {$h2} distinct angles or beats. ";
    $b .= $domains ? "When a source is an original social post, cite the primary original (rivals most often source from: {$domains}). " : '';
    $b .= "Always include answer-first phrasing and FAQs — rivals overwhelmingly skip FAQ/speakable schema, so these are our AI-citation edge and must never be dropped.";
    return $b;
}

/** Percentile of a numeric array (p in 0..1). */
function _comp_pct(array $a, float $p): int {
    if (!$a) return 0;
    sort($a);
    $i = (int)floor($p * (count($a) - 1));
    return (int)$a[$i];
}

/** Upsert a rule, bumping version only when the value actually changes. */
function comp_put_rule(PDO $pdo, string $scope, string $key, $value, int $confidence, string $evidence): void {
    $val = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
    $cur = $pdo->prepare("SELECT id, rule_value, version FROM comp_rule WHERE scope=? AND rule_key=?");
    $cur->execute([$scope, $key]);
    $row = $cur->fetch();
    if ($row) {
        $ver = ($row['rule_value'] === $val) ? (int)$row['version'] : (int)$row['version'] + 1;
        $pdo->prepare("UPDATE comp_rule SET rule_value=?, confidence=?, evidence=?, version=?, active=1, updated_at=NOW() WHERE id=?")
            ->execute([$val, $confidence, mb_substr($evidence, 0, 2000), $ver, $row['id']]);
    } else {
        $pdo->prepare("INSERT INTO comp_rule (scope,rule_key,rule_value,confidence,evidence,version,active,updated_at)
                       VALUES (?,?,?,?,?,1,1,NOW())")
            ->execute([$scope, $key, $val, $confidence, mb_substr($evidence, 0, 2000)]);
    }
}

/** Confidence from sample size: tiny n = low trust, big n = high. */
function _comp_conf(int $n): int {
    if ($n >= 40) return 88;
    if ($n >= 20) return 75;
    if ($n >= 8)  return 60;
    if ($n >= 3)  return 45;
    return 25;
}

/**
 * The distiller. Reads the latest snapshot per watched page + per sitemap, computes the
 * competitive benchmarks, and writes them as rules. Returns a summary of what it wrote.
 */
function comp_distill(PDO $pdo): array {
    competitor_init($pdo);

    // ---- load the latest snapshot per ARTICLE (no double-counting history), with the
    //      competitor domain so we can tell a real cross-site subtopic from one site's nav ----
    $arts = [];
    $sql = "SELECT c.domain dom, s.fields_json FROM comp_snapshot s
            JOIN comp_watch w ON w.id = s.watch_id
            LEFT JOIN competitors c ON c.id = w.competitor_id
            JOIN (SELECT watch_id, MAX(captured_at) mx FROM comp_snapshot GROUP BY watch_id) L
              ON L.watch_id = s.watch_id AND L.mx = s.captured_at
            WHERE w.watch_type = 'page'";
    foreach ($pdo->query($sql) as $r) {
        $f = json_decode($r['fields_json'], true);
        if (is_array($f)) $arts[] = ['dom' => (string)($r['dom'] ?? ''), 'f' => $f];
    }
    $n = count($arts);
    if ($n === 0) return ['ok' => false, 'reason' => 'no article snapshots yet'];

    // obvious site-chrome headings to never treat as article subtopics
    $navStop = ['latest','status','images','videos','trending','editorials','episodes','more',
                'comments','related','newsletter','advertisement','popular right now','meme encyclopedia',
                'try yourself','sign up','log in','follow us','share','tags','categories','search'];

    // ---- aggregate the corpus ----
    $words = $wordsSub = [];
    $faq = $spk = $vid = $person = $same = 0;
    $schema = $outbound = $h2counts = [];
    $h2byDom = [];     // phrase => [domain => count]  (cross-site recurrence = real subtopic)
    foreach ($arts as $a) {
        $f = $a['f']; $dom = $a['dom'];
        $wc = (int)($f['word_count'] ?? 0);
        if ($wc > 0) $words[] = $wc;
        // "substantial" = real articles, not thin wiki/tier-list stubs (those skew depth low)
        $isArticle = array_intersect(['Article', 'NewsArticle', 'BlogPosting', 'ReportageNewsArticle'], (array)($f['schema_types'] ?? []));
        if ($wc >= 300 && $isArticle) $wordsSub[] = $wc;

        $faq    += !empty($f['faqpage']);
        $spk    += !empty($f['speakable']);
        $vid    += !empty($f['videoobject']);
        $person += !empty($f['author_person']);
        $same   += !empty($f['sameAs']);

        foreach ((array)($f['schema_types'] ?? []) as $t) $schema[$t] = ($schema[$t] ?? 0) + 1;
        $h2s = (array)($f['h2_set'] ?? []);
        $h2counts[] = count($h2s);
        foreach ($h2s as $h) {
            $k = mb_strtolower(trim($h));
            $k = trim(preg_replace('/\s+/', ' ', $k));
            if ($k === '' || mb_strlen($k) < 4 || mb_strlen($k) > 90) continue;
            if (in_array($k, $navStop, true) || preg_match('/^(\S.*)\s+all \1/', $k)) continue;  // "editorials all editorials"
            $h2byDom[$k][$dom] = ($h2byDom[$k][$dom] ?? 0) + 1;
        }
        foreach ((array)($f['outbound_domains'] ?? []) as $d) $outbound[$d] = ($outbound[$d] ?? 0) + 1;
    }

    $pct = fn($p, $set = null) => _comp_pct($set ?? $words, $p);
    $conf = _comp_conf($n);
    $compCount = (int)$pdo->query("SELECT COUNT(*) FROM competitors")->fetchColumn();
    $evBase = "$n competitor articles across $compCount sites";

    arsort($schema); arsort($outbound);
    $topSchema = array_slice($schema, 0, 8, true);
    $avgH2 = $h2counts ? round(array_sum($h2counts) / count($h2counts), 1) : 0;
    $subMedian = $wordsSub ? _comp_pct($wordsSub, 0.5) : 0;
    $subP75    = $wordsSub ? _comp_pct($wordsSub, 0.75) : 0;

    // recurring section themes = headings used by >= 2 DIFFERENT competitors (a real shared
    // subtopic, not one site's chrome). Ranked by how many rivals use it, then total uses.
    $themeScore = [];
    foreach ($h2byDom as $phrase => $byDom) {
        $domCount = count($byDom);
        if ($domCount >= 2) $themeScore[$phrase] = ['sites' => $domCount, 'uses' => array_sum($byDom)];
    }
    uasort($themeScore, fn($a, $b) => ($b['sites'] <=> $a['sites']) ?: ($b['uses'] <=> $a['uses']));
    $themes = [];
    foreach (array_slice($themeScore, 0, 15, true) as $phrase => $m) $themes[$phrase] = $m['sites'] . ' sites';
    // who competitors source/link (our sourcing shortlist)
    $sources = array_slice(array_keys($outbound), 0, 15);

    $written = [];

    // ============================ GATE rules ============================
    $depth = [
        'n' => $n, 'median_words' => $pct(0.5), 'p75_words' => $pct(0.75), 'p90_words' => $pct(0.90),
        'substantial_n' => count($wordsSub), 'substantial_median' => $subMedian, 'substantial_p75' => $subP75,
        // to WIN we exceed their better-half — never just match the thin median
        'recommend_min_words' => max($subP75, $pct(0.75), 600),
    ];
    comp_put_rule($pdo, 'gate', 'depth_benchmark', $depth, $conf,
        "$evBase. Competitor median ".$pct(0.5)."w (substantial median {$subMedian}w). Floor set to beat their p75.");
    $written[] = 'gate/depth_benchmark';

    $schemaReq = [];
    foreach ($topSchema as $t => $c) $schemaReq[$t] = round(100 * $c / $n) . '%';
    comp_put_rule($pdo, 'gate', 'schema_baseline', $schemaReq, $conf,
        "$evBase. Schema @types competitors ship — we match or exceed these.");
    $written[] = 'gate/schema_baseline';

    // ============================ DRAFTER rules ============================
    comp_put_rule($pdo, 'drafter', 'structure_target',
        ['competitor_avg_h2' => $avgH2, 'recommend_h2_min' => max((int)ceil($avgH2) + 1, 4)], $conf,
        "$evBase. Competitors average {$avgH2} H2 sections; we target one more for fuller coverage.");
    $written[] = 'drafter/structure_target';

    comp_put_rule($pdo, 'drafter', 'subtopic_coverage', $themes ?: ['(no cross-site section pattern yet)' => 'n/a'],
        $themes ? min(80, 40 + 8 * count($themes)) : 20,
        "Section headings used by >=2 different competitors (nav/chrome filtered out). Drafter covers these shared angles; thin overlap is itself a signal rivals don't share structure.");
    $written[] = 'drafter/subtopic_coverage';

    $avgOutbound = round(array_sum(array_map(fn($a) => count((array)($a['f']['outbound_domains'] ?? [])), $arts)) / $n, 1);
    comp_put_rule($pdo, 'drafter', 'source_shortlist',
        ['competitor_avg_outbound' => $avgOutbound, 'top_domains' => $sources], $conf,
        "$evBase. External domains competitors most often cite/link — our primary-source shortlist.");
    $written[] = 'drafter/source_shortlist';

    // ============================ CITATION (GEO) rules — the moat ============================
    $moat = [
        'faqpage_pct'     => round(100 * $faq / $n),
        'speakable_pct'   => round(100 * $spk / $n),
        'sameAs_pct'      => round(100 * $same / $n),
        'videoobject_pct' => round(100 * $vid / $n),
    ];
    // how big a lead these signals give us = inverse of competitor adoption
    $moatGap = 100 - (int)round(($moat['faqpage_pct'] + $moat['speakable_pct'] + $moat['sameAs_pct']) / 3);
    $moat['edge_score'] = $moatGap;
    $moat['action'] = "Competitors barely use FAQ/speakable/sameAs ({$moat['faqpage_pct']}/{$moat['speakable_pct']}/{$moat['sameAs_pct']}%). KEEP shipping ours — this is our AI-citation lead.";
    comp_put_rule($pdo, 'citation', 'ai_schema_moat', $moat, max($conf, 80), "$evBase. AI-citation schema adoption among rivals.");
    $written[] = 'citation/ai_schema_moat';

    comp_put_rule($pdo, 'citation', 'author_signal',
        ['competitor_person_author_pct' => round(100 * $person / $n),
         'action' => 'Named Person author (Deimos EMK) keeps us at or above competitor E-E-A-T.'], $conf, $evBase);
    $written[] = 'citation/author_signal';

    // ============================ GLOBAL rules ============================
    // cadence + size from the latest sitemap snapshot per competitor
    $cadence = [];
    $sm = "SELECT c.domain, s.fields_json FROM comp_snapshot s
           JOIN comp_watch w ON w.id = s.watch_id
           JOIN competitors c ON c.id = w.competitor_id
           JOIN (SELECT watch_id, MAX(captured_at) mx FROM comp_snapshot GROUP BY watch_id) L
             ON L.watch_id = s.watch_id AND L.mx = s.captured_at
           WHERE w.watch_type = 'sitemap'";
    foreach ($pdo->query($sm) as $r) {
        $f = json_decode($r['fields_json'], true) ?: [];
        $cadence[$r['domain']] = ['urls' => (int)($f['url_count'] ?? 0), 'newest' => (string)($f['newest_lastmod'] ?? '')];
    }
    uasort($cadence, fn($a, $b) => strcmp($b['newest'], $a['newest']));
    comp_put_rule($pdo, 'global', 'cadence', $cadence, $conf,
        "Per-competitor catalogue size + freshest lastmod (publishing tempo).");
    $written[] = 'global/cadence';

    comp_put_rule($pdo, 'global', 'corpus',
        ['competitors' => $compCount, 'articles' => $n, 'substantial_articles' => count($wordsSub)], $conf,
        "Intelligence base the rules are distilled from.");
    $written[] = 'global/corpus';

    // a derived headline (deterministic, no LLM) — the one-line strategy
    $headline = "Rivals run thin (median ".$pct(0.5)."w) and skip AI-citation schema "
        . "(FAQ {$moat['faqpage_pct']}%, speakable {$moat['speakable_pct']}%, sameAs {$moat['sameAs_pct']}%). "
        . "Our wedge: deeper pages (>= {$depth['recommend_min_words']}w) + the citation schema they lack.";
    comp_put_rule($pdo, 'global', 'headline_insight', $headline, max($conf, 80), $evBase);
    $written[] = 'global/headline_insight';

    // IMAGE STRATEGY (owner-flagged gap): rivals ship rich people-photos because they have
    // press-photo access; a drama with no face reads as "low". This rule feeds the image
    // system + flags our card-count as the gap to close.
    $ourReal = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE type='drama' AND status='published' AND cover_credit IS NOT NULL AND cover_credit<>''")->fetchColumn();
    $ourCard = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE type='drama' AND status='published' AND (cover_credit IS NULL OR cover_credit='')")->fetchColumn();
    comp_put_rule($pdo, 'gate', 'image_strategy', [
        'rule' => 'Every drama is about people — lead with a real photo of the people involved, mood-matched; a branded card is a last-resort fallback only.',
        'our_real_photos' => $ourReal, 'our_branded_cards' => $ourCard,
        'constraint' => 'Free sources (Wikimedia/YouTube) only yield clean correct photos for famous, uniquely-named people; common names hit wrong-namesake risk. Closing the gap fully needs a paid editorial photo source.',
    ], 80, "Owner-flagged: faceless dramas read as low. Rivals ship people-photos; this is the gap.");
    $written[] = 'gate/image_strategy';

    return ['ok' => true, 'articles' => $n, 'competitors' => $compCount, 'rules_written' => count($written),
            'rules' => $written, 'headline' => $headline];
}

/** Recursively pull hex colors + font-family names out of a design-token bundle. List items
 *  inherit their parent key, so fonts nested under "fontFamily": [..] are still detected. */
function _design_scan($v, array &$colors, array &$fonts, string $key = ''): void {
    if (is_array($v)) {
        foreach ($v as $k => $x) _design_scan($x, $colors, $fonts, is_int($k) ? $key : (string)$k);
    } elseif (is_string($v)) {
        if (preg_match_all('/#[0-9a-fA-F]{6}\b/', $v, $m)) foreach ($m[0] as $c) { $c = strtolower($c); $colors[$c] = ($colors[$c] ?? 0) + 1; }
        $kl = strtolower($key);
        if ((str_contains($kl, 'font') || str_contains($kl, 'famil') || str_contains($kl, 'typeface'))
            && preg_match('/^[A-Za-z][A-Za-z0-9 ,\'\-]{2,60}$/', trim($v))) {
            $f = trim(explode(',', $v)[0], " '\"");
            $generic = ['sans-serif','serif','monospace','system-ui','inherit','initial','unset','ui-monospace','ui-sans-serif','ui-serif'];
            if ($f !== '' && !preg_match('/^\d/', $f) && !in_array(strtolower($f), $generic, true)) $fonts[$f] = ($fonts[$f] ?? 0) + 1;
        }
    }
}

/**
 * DESIGN distiller: diff rivals' design tokens (dembrandt output) vs ours into design rules
 * that guide the redesign. Robust to dembrandt's exact shape — scans the bundle for the real
 * signals (palette + fonts) wherever they live, and counts how many SITES share each.
 */
/** Extract REAL font-family names from a dembrandt bundle. The names live in typography.sources
 *  (styles[].family is often a CSS-var placeholder like "displayFontFace"/"bodyFontFace"). Prefer
 *  Google fonts, then clean self-hosted filenames, then real-looking families in the styles. */
function _design_fonts(array $t): array {
    $fonts = [];
    $src = $t['typography']['sources'] ?? [];
    foreach ((array)($src['googleFonts'] ?? []) as $f) { $f = trim((string)$f); if ($f !== '') $fonts[$f] = ($fonts[$f] ?? 0) + 5; }
    foreach (array_merge((array)($src['customFonts'] ?? []), (array)($src['selfHostedFonts'] ?? [])) as $file) {
        $name = preg_replace('/\.(woff2?|ttf|otf|eot)$/i', '', basename((string)$file));
        $name = preg_replace('/[_\-].*$/', '', $name);
        if (preg_match('/^[A-Z][A-Za-z]{2,30}$/', $name)) $fonts[$name] = ($fonts[$name] ?? 0) + 2;
    }
    $skip = ['sans-serif','serif','monospace','system-ui','inherit','initial','unset','ui-monospace','ui-sans-serif','ui-serif'];
    foreach ((array)($t['typography']['styles'] ?? []) as $st) {
        $f = trim(explode(',', (string)($st['family'] ?? ''))[0], " '\"");
        if ($f === '' || in_array(strtolower($f), $skip, true)) continue;
        if (preg_match('/fontface$/i', $f)) continue;                  // CSS-var placeholder
        if (strtolower($f) === $f && strpos($f, ' ') === false) continue;
        if (preg_match('/^[A-Za-z][A-Za-z0-9 ]{1,40}$/', $f)) $fonts[$f] = ($fonts[$f] ?? 0) + 1;
    }
    arsort($fonts);
    return $fonts;
}

function comp_distill_design(PDO $pdo): array {
    competitor_init($pdo);
    $rows = $pdo->query("SELECT domain,is_ours,tokens_json FROM comp_design")->fetchAll();
    if (!$rows) return ['ok' => false, 'reason' => 'no design data yet'];
    $rivalColors = []; $rivalFonts = []; $ours = ['colors' => [], 'fonts' => []]; $nRival = 0; $sites = [];
    foreach ($rows as $r) {
        $t = json_decode($r['tokens_json'], true); if (!is_array($t)) continue;
        $colors = []; $f0 = []; _design_scan($t, $colors, $f0);   // _design_scan for colours
        $fonts = _design_fonts($t);                                // dembrandt-aware for fonts
        arsort($colors);
        if ($r['is_ours']) {
            $ours = ['colors' => array_slice(array_keys($colors), 0, 10), 'fonts' => array_slice(array_keys($fonts), 0, 5)];
        } else {
            $nRival++; $sites[] = $r['domain'];
            foreach (array_slice(array_keys($colors), 0, 12) as $c) $rivalColors[$c] = ($rivalColors[$c] ?? 0) + 1;  // SITES using each
            foreach (array_slice(array_keys($fonts), 0, 6) as $f) $rivalFonts[$f] = ($rivalFonts[$f] ?? 0) + 1;
        }
    }
    arsort($rivalColors); arsort($rivalFonts);
    $topColors = array_slice($rivalColors, 0, 10, true);
    $topFonts = array_slice($rivalFonts, 0, 6, true);
    $conf = $nRival >= 4 ? 80 : ($nRival >= 2 ? 60 : 35);

    comp_put_rule($pdo, 'global', 'design_rival_palette',
        array_map(fn($c) => "$c (x{$topColors[$c]})", array_keys($topColors)) ?: ['(none yet)'],
        $conf, "Colors the most rivals share, across $nRival sites.");
    comp_put_rule($pdo, 'global', 'design_rival_fonts',
        array_map(fn($f) => "$f (x{$topFonts[$f]})", array_keys($topFonts)) ?: ['(none yet)'],
        $conf, "Fonts the most rivals share, across $nRival sites.");
    comp_put_rule($pdo, 'global', 'design_ours', $ours, $conf, "Our current palette + fonts (for the gap comparison).");
    comp_put_rule($pdo, 'global', 'design_gaps',
        ['fonts_rivals_use_we_dont' => array_values(array_diff(array_keys($topFonts), $ours['fonts'])), 'rivals_analyzed' => $sites],
        $conf, "Where our design diverges from the rival consensus — the redesign starting points.");

    comp_design_advice($pdo);   // turn the raw design data into prescriptive recommendations
    return ['ok' => true, 'rivals' => $nRival, 'top_colors' => array_keys($topColors), 'top_fonts' => array_keys($topFonts), 'ours' => $ours];
}

/** Saturation + lightness (0-1) of a #rrggbb — used to tell a real brand accent from greys/black/white. */
function _hex_sat(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [0.0, 0.0];
    $r = hexdec(substr($hex, 0, 2)) / 255; $g = hexdec(substr($hex, 2, 2)) / 255; $b = hexdec(substr($hex, 4, 2)) / 255;
    $mx = max($r, $g, $b); $mn = min($r, $g, $b); $l = ($mx + $mn) / 2;
    $s = ($mx == $mn) ? 0.0 : ($l > 0.5 ? ($mx - $mn) / (2 - $mx - $mn) : ($mx - $mn) / ($mx + $mn));
    return [$s, $l];
}

/**
 * SMART DESIGN REPORT — turns the rival design comparison into PRESCRIPTIVE recommendations
 * ("keep this because...", "change this because...") so the admin shows what to DO, not just data.
 * Deterministic + grounded in the real tokens; stored as rule global/design_advice, auto-refreshed
 * whenever the design distiller runs.
 */
function comp_design_advice(PDO $pdo): array {
    competitor_init($pdo);
    $rows = $pdo->query("SELECT domain,is_ours,tokens_json FROM comp_design")->fetchAll();
    if (!$rows) return ['ok' => false, 'reason' => 'no design data yet'];

    // WCAG 2.1 relative luminance + contrast ratio
    $lum = function (string $hex): ?float {
        $hex = ltrim($hex, '#'); if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return null;
        $ch = []; foreach ([0, 2, 4] as $i) { $v = hexdec(substr($hex, $i, 2)) / 255; $ch[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    };
    $contrast = function (string $a, string $b) use ($lum): float { $la = $lum($a); $lb = $lum($b); if ($la === null || $lb === null) return 0.0; return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05); };

    $ot = null; $ourColorsFull = []; $ourFonts = []; $rivColorCounts = []; $rivFontsAll = []; $rivAccent = 0; $nRival = 0;
    $ourShadows = 0; $ourFw = false; $rivShadows = []; $rivFw = 0;
    foreach ($rows as $r) {
        $t = json_decode($r['tokens_json'], true); if (!is_array($t)) continue;
        $cols = []; $f0 = []; _design_scan($t, $cols, $f0); arsort($cols);
        $fonts = array_keys(_design_fonts($t));
        $shadows = is_array($t['shadows'] ?? null) ? count($t['shadows'], COUNT_RECURSIVE) : 0;   // depth = SaaS look
        $hasFw = !empty($t['frameworks']);                                                          // Tailwind/Prime/Element = payload weight
        if ($r['is_ours']) { $ot = $t; $ourColorsFull = array_keys($cols); $ourFonts = array_slice($fonts, 0, 5); $ourShadows = $shadows; $ourFw = $hasFw; }
        else {
            $nRival++; $rivColorCounts[] = count($cols); $rivShadows[] = $shadows; if ($hasFw) $rivFw++;
            foreach (array_slice($fonts, 0, 6) as $ff) $rivFontsAll[$ff] = ($rivFontsAll[$ff] ?? 0) + 1;
            foreach (array_slice(array_keys($cols), 0, 8) as $c) { if (strcasecmp(ltrim($c, '#'), '0000ee') === 0) continue; [$s, $l] = _hex_sat($c); if ($s > 0.45 && $l > 0.25 && $l < 0.72) { $rivAccent++; break; } }
        }
    }
    if (!$ot) return ['ok' => false, 'reason' => 'no design data for our site yet'];

    // --- MEASURED METRICS (grounded in the expert frameworks) ---
    $accent = ''; $bestS = 0.35; foreach (array_slice($ourColorsFull, 0, 10) as $c) { [$s, $l] = _hex_sat($c); if ($s > $bestS && $l > 0.25 && $l < 0.78) { $bestS = $s; $accent = $c; } }
    $sig = array_values(array_diff($ourFonts, array_keys($rivFontsAll)));               // Ehrenberg-Bass uniqueness (fonts nobody shares)
    $fontOwners = 0; foreach ($ourFonts as $ff) $fontOwners = max($fontOwners, $rivFontsAll[$ff] ?? 0);
    sort($rivColorCounts); $rivColMed = $rivColorCounts ? $rivColorCounts[intdiv(count($rivColorCounts), 2)] : 0;  // Project Wallace consistency
    $ourColCount = count($ourColorsFull);
    $hex = array_values(array_filter($ourColorsFull, fn($k) => preg_match('/^#[0-9a-fA-F]{6}$/', $k)));           // WCAG contrast
    usort($hex, fn($a, $b) => $lum($a) <=> $lum($b));
    $cr = count($hex) >= 2 ? $contrast($hex[0], $hex[count($hex) - 1]) : 0.0;
    $crGrade = $cr >= 7 ? 'AAA' : ($cr >= 4.5 ? 'AA' : 'below AA');
    arsort($rivFontsAll); $rivFontNames = array_keys($rivFontsAll); $genericTop = implode(', ', array_slice($rivFontNames, 0, 3));
    sort($rivShadows); $rivShadowMed = $rivShadows ? $rivShadows[intdiv(count($rivShadows), 2)] : 0;   // rival depth benchmark

    // --- SCORECARD: you vs the rival benchmark, every cell a real number ---
    $scorecard = [
        ['dim' => 'Distinctive typeface', 'method' => 'Distinctive Brand Assets — uniqueness', 'ours' => ($sig ? implode(' / ', $sig) : '—'), 'bench' => "$fontOwners of $nRival rivals share it", 'verdict' => ($sig && $fontOwners === 0) ? 'lead' : 'even'],
        ['dim' => 'Brand colour', 'method' => 'Distinctive Brand Assets — uniqueness', 'ours' => ($accent ?: 'none'), 'bench' => "$rivAccent of $nRival rivals own a brand colour", 'verdict' => ($accent && $rivAccent === 0) ? 'lead' : ($accent ? 'even' : 'behind')],
        ['dim' => 'Palette discipline', 'method' => 'Design-system consistency (Project Wallace)', 'ours' => "$ourColCount distinct colours", 'bench' => "rival median $rivColMed", 'verdict' => $ourColCount < $rivColMed ? 'lead' : ($ourColCount <= $rivColMed + 2 ? 'even' : 'behind')],
        ['dim' => 'Accessibility', 'method' => 'WCAG 2.1 contrast', 'ours' => round($cr, 1) . ':1 (' . $crGrade . ')', 'bench' => 'AA needs 4.5:1', 'verdict' => $cr >= 7 ? 'lead' : ($cr >= 4.5 ? 'even' : 'behind')],
        ['dim' => 'Editorial flatness', 'method' => 'Stylistic distinctiveness (depth tokens)', 'ours' => "$ourShadows shadows", 'bench' => "rival median $rivShadowMed", 'verdict' => $ourShadows < $rivShadowMed ? 'lead' : ($ourShadows <= $rivShadowMed ? 'even' : 'behind')],
        ['dim' => 'Framework-free (speed)', 'method' => 'Performance / payload weight', 'ours' => ($ourFw ? 'ships a framework' : 'hand-written CSS'), 'bench' => "$rivFw of $nRival rivals ship one", 'verdict' => (!$ourFw && $rivFw > 0) ? 'lead' : 'even'],
    ];
    $leads = count(array_filter($scorecard, fn($s) => $s['verdict'] === 'lead'));

    $keep = [];
    if ($sig && $fontOwners === 0) $keep[] = ['what' => implode(' / ', $sig) . ' — you fully OWN it', 'why' => "0 of $nRival rivals use it (they run $genericTop). Your highest-uniqueness asset — protect it."];
    if ($accent && $rivAccent === 0) $keep[] = ['what' => "Your brand colour $accent — uncontested", 'why' => "0 of $nRival rivals own a brand colour. Uniqueness is maxed; the job now is Fame (below)."];
    if ($ourColCount < $rivColMed) $keep[] = ['what' => "A tighter palette ($ourColCount vs rival median $rivColMed)", 'why' => 'Fewer stray colours = a more disciplined, consistent system than the average rival.'];
    if ($cr >= 7) $keep[] = ['what' => "Text contrast $crGrade (" . round($cr, 1) . ':1)', 'why' => 'Clears WCAG AAA — more readable + accessible than the 4.5:1 minimum most sites just scrape.'];
    if ($ourShadows < $rivShadowMed) $keep[] = ['what' => "Flat editorial look ($ourShadows shadows vs rival median $rivShadowMed)", 'why' => 'Rivals lean on drop-shadows + gradients (the SaaS look); your flat hairline style reads as a real newspaper — a deliberate differentiator. Adding depth would make you look like them.'];
    if (!$ourFw && $rivFw > 0) $keep[] = ['what' => 'Hand-written CSS, no framework', 'why' => "$rivFw of $nRival rivals carry Tailwind / Prime / Element weight; you ship none — a real load-speed edge that also keeps the design fully yours."];

    $change = [];
    if ($accent) $change[] = ['what' => "Turn $accent into a system to build FAME", 'why' => 'Ehrenberg-Bass: an owned asset only pays off once the audience LINKS it to you — which takes relentless, consistent use. Put it on every link, section mark, the logo and key buttons so readers learn "red = GenZHype".'];
    $shared = array_values(array_intersect($ourFonts, $rivFontNames));
    if ($shared) $change[] = ['what' => 'Drop the generic fallbacks (' . implode(', ', $shared) . ')', 'why' => 'Your font stack lists the exact fonts rivals use — a failed webfont makes you render like them, weakening the uniqueness you own.'];
    $change[] = ['what' => 'Protect body-text readability', 'why' => 'You lead with a heavy display serif; keep long-article body a comfortable weight and size so the editorial look never costs reading ease.'];

    $notMeasured = [
        'Fame / recognition — whether readers actually link these assets to GenZHype. Ehrenberg-Bass measures it with a survey (show the unbranded asset, count who names you) — not derivable from code. You WIN it by the consistent use above, not by redesigning.',
        'Usability / task flow — needs real user testing (heuristic eval or moderated sessions), not token data.',
    ];

    $verdict = "On the parts that CAN be measured you lead $leads of " . count($scorecard) . ". Your " . ($sig ? implode('/', $sig) : 'serif') . " typeface is uniquely yours ($fontOwners of $nRival rivals use it), your palette is tighter ($ourColCount vs rival median $rivColMed), your contrast is $crGrade, you run flat ($ourShadows shadows vs rivals' $rivShadowMed) and framework-free. Brand colour is EVEN, not a win — you own $accent, but $rivAccent of $nRival rivals also run a brand colour (they only look grey in COMMON because each picks a different one), so it's the typeface + discipline that set you apart, not simply having a colour. The one thing data can't win is FAME — readers actually linking these assets to you — and that comes from using them relentlessly, not from a redesign.";

    $report = ['verdict' => $verdict, 'method' => 'Distinctive Brand Assets (Ehrenberg-Bass) + design-system consistency (Project Wallace) + WCAG', 'scorecard' => $scorecard, 'leads' => $leads, 'keep' => $keep, 'change' => $change, 'not_measured' => $notMeasured, 'rivals' => $nRival];
    comp_put_rule($pdo, 'global', 'design_advice', $report, $nRival >= 4 ? 88 : ($nRival >= 2 ? 55 : 35),
        "Measured design scorecard (uniqueness + consistency + WCAG) across $nRival rivals + prescriptive keep/change.");
    return ['ok' => true] + $report;
}

/** Is a referring domain NOT a realistic/safe backlink target? Strips (a) mega-platforms you can't
 *  pitch / that are nofollow (wikipedia, youtube, socials), and (b) obvious spam / PBN / link-farm
 *  domains (toxic — a link from those gets you PENALIZED). Keeps the to-do list actionable + safe. */
function comp_backlink_is_junk(string $d): bool {
    $d = strtolower(trim($d));
    if ($d === '') return true;
    static $deny = [
        'wikipedia.org','wikimedia.org','wikidata.org','youtube.com','youtu.be','facebook.com','fb.com','fb.me',
        'instagram.com','twitter.com','x.com','t.co','reddit.com','redd.it','tiktok.com','pinterest.com',
        'linkedin.com','lnkd.in','amazon.com','google.com','goo.gl','blogger.com','blogspot.com','wordpress.com',
        'medium.com','tumblr.com','telegra.ph','t.me','telegram.org','apple.com','apple.news','microsoft.com',
        'archive.org','gravatar.com','github.com','github.io','vimeo.com','soundcloud.com','spotify.com','imdb.com',
        'fandom.com','quora.com','yahoo.com','bing.com','flickr.com','twitch.tv','discord.com','discord.gg',
        'snapchat.com','threads.net','bsky.app','paypal.com','linktr.ee','bit.ly','tinyurl.com','feedburner.com',
        'whatsapp.com','wa.me','vk.com','ok.ru','weibo.com','line.me','rumble.com','dailymotion.com','wp.com',
        // hosting / infrastructure / free-builder domains — not real editorial sites you can pitch
        'amazonaws.com','cloudfront.net','googleusercontent.com','gstatic.com','translate.goog','herokuapp.com',
        'netlify.app','vercel.app','pages.dev','web.app','firebaseapp.com','neocities.org','weebly.com','wixsite.com',
        'godaddysites.com','square.site','cloudflare.com','readthedocs.io','surge.sh','glitch.me','repl.co',
        'beehiiv.com','mailchimp.com','convertkit.com','substack.com','patreon.com','gumroad.com',
        // Wikimedia sisters + search portals — non-pitchable / nofollow like wikipedia
        'wikiquote.org','wiktionary.org','wikinews.org','wikivoyage.org','wikibooks.org','wikisource.org','wikiversity.org',
        'yandex.ru','yandex.com','ya.ru','mail.ru','baidu.com','rt.com','dzen.ru','turbopages.org','ipleak.org','monica.so',
        'handwiki.org','disroot.org','earth-base.org','netstudies.org','kyokan.org',   // wiki-mirrors / tools / non-editorial
    ];
    foreach ($deny as $apex) if ($d === $apex || str_ends_with($d, '.' . $apex)) return true;
    // cheap/abused TLDs that are overwhelmingly spam in a link graph
    static $spamTld = ['sbs','top','buzz','click','icu','cyou','rest','monster','quest','cfd','bond','gdn','xyz',
        'fit','men','loan','win','date','racing','stream','download','review','science','party','gq','ml','ga','cf','tk',
        'shop','store','shopping','cheap','deals','space','world','life','fun','online','site','website'];  // cheap TLDs = mostly spam in a link graph
    $tld = ($p = strrchr($d, '.')) ? substr($p, 1) : '';
    if (in_array($tld, $spamTld, true)) return true;
    // low-relevance foreign-market ccTLDs for a US-English site (can't realistically pitch/earn a link)
    static $foreignTld = ['ru','ua','by','kz','cn'];
    if (in_array($tld, $foreignTld, true)) return true;
    // spam keywords anywhere in the domain
    static $spamKw = ['backlink','seo-','-seo','.seo','pbn','linkfarm','link-farm','casino','poker','betting',
        'gambling','porn','xxx','viagra','cialis','escort','cartel','dofollow','payday','adult-','-adult',
        'gov-civil','1xbet','1xmarketing','sportsbet','slot-','-slots','crypto-','vape'];
    foreach ($spamKw as $kw) if (strpos($d, $kw) !== false) return true;
    return false;
}

/**
 * BACKLINK distiller: benchmark rivals' authority vs ours and build the LINK-OPPORTUNITY list
 * — domains that link to multiple rivals but NOT us = exactly where to go earn backlinks.
 */
function comp_distill_backlinks(PDO $pdo): array {
    competitor_init($pdo);
    $rows = $pdo->query("SELECT domain,is_ours,authority,ref_count,ref_domains_json FROM comp_backlink")->fetchAll();
    if (!$rows) return ['ok' => false, 'reason' => 'no backlink data yet'];

    $rivalAuth = []; $ourAuth = null; $ourRefs = []; $oppCount = []; $nRival = 0; $rivalRefCount = []; $rivalSet = [];
    foreach ($rows as $r) {
        $refs = json_decode($r['ref_domains_json'] ?: '[]', true) ?: [];
        if ($r['is_ours']) { $ourAuth = $r['authority'] !== null ? (float)$r['authority'] : null; $ourRefs = $refs; }
        else {
            $nRival++; $rivalSet[strtolower($r['domain'])] = 1;   // the rivals themselves are not targets
            if ($r['authority'] !== null) $rivalAuth[] = (float)$r['authority'];
            $rivalRefCount[$r['domain']] = (int)$r['ref_count'];
            foreach ($refs as $rd) $oppCount[$rd] = ($oppCount[$rd] ?? 0) + 1;   // how many rivals each domain links to
        }
    }
    // link opportunities: domains linking to >=2 rivals that DON'T already link to us, aren't a rival, aren't junk
    $ourSet = array_flip($ourRefs);
    $opps = [];
    foreach ($oppCount as $d => $c) if ($c >= 2 && !isset($ourSet[$d]) && !isset($rivalSet[strtolower((string)$d)]) && !comp_backlink_is_junk((string)$d)) $opps[$d] = $c;
    arsort($opps);
    $oppList = [];
    foreach (array_slice($opps, 0, 30, true) as $d => $c) $oppList[] = ['domain' => $d, 'rivals_linked' => $c];

    sort($rivalAuth);
    $rivalMedian = $rivalAuth ? $rivalAuth[intdiv(count($rivalAuth), 2)] : null;
    $conf = $nRival >= 4 ? 80 : ($nRival >= 2 ? 60 : 35);

    comp_put_rule($pdo, 'global', 'backlink_authority',
        ['our_authority' => $ourAuth, 'rival_median_authority' => $rivalMedian,
         'rival_range' => $rivalAuth ? [min($rivalAuth), max($rivalAuth)] : [],
         'gap' => ($ourAuth !== null && $rivalMedian !== null) ? round($rivalMedian - $ourAuth, 2) : null],
        $conf, "Domain authority (0-100, from the Common Crawl link graph): ours vs the rival median. The gap to close.");
    comp_put_rule($pdo, 'global', 'backlink_opportunities', $oppList ?: ['(referring-domain data not collected yet)'],
        $conf, "Domains linking to >=2 rivals but NOT us — the prioritized list of sites to earn backlinks from.");
    comp_put_rule($pdo, 'global', 'backlink_ours',
        ['authority' => $ourAuth, 'referring_domains' => count($ourRefs)],
        $conf, "Our current authority + referring-domain count (track this climbing as we build links).");

    return ['ok' => true, 'rivals' => $nRival, 'our_authority' => $ourAuth, 'rival_median_authority' => $rivalMedian,
            'opportunities' => count($oppList), 'top_opportunities' => array_slice($oppList, 0, 10)];
}

/* ============================================================================
 * PER-RIVAL PROFILE DISTILLER — the walk-in dossier's data layer.
 * The corpus distiller (comp_distill) computes rich per-article stats then collapses
 * them into ONE set of aggregates. This runs the SAME real math but SCOPED PER RIVAL,
 * and persists one comp_profile blob per competitor (append-only => trends over time).
 * Zero new collection: every number comes from snapshots/changes we already store.
 * ========================================================================== */

/** Bucket an outbound domain into the kind of receipt it is. */
function comp_source_bucket(string $d): string {
    $d = strtolower($d);
    static $social = ['instagram.com','tiktok.com','youtube.com','youtu.be','twitter.com','x.com','reddit.com',
        'facebook.com','threads.net','bsky.app','snapchat.com','twitch.tv','pinterest.com','t.me'];
    static $ref = ['wikipedia.org','wikimedia.org','imdb.com','britannica.com','genius.com','discogs.com','spotify.com'];
    foreach ($social as $s) if ($d === $s || str_ends_with($d, '.' . $s)) return 'social';
    foreach ($ref as $s) if ($d === $s || str_ends_with($d, '.' . $s)) return 'reference';
    if (preg_match('/(news|times|post|guardian|reuters|cnn|bbc|tmz|variety|billboard|rollingstone|nbc|abc|cbs|fox|hollywood|insider|mirror|metro|independent)/', $d)) return 'news';
    return 'other';
}

/** Best-effort HTTP GET (for self-scanning OUR own pages' schema). Empty string on failure. */
function _comp_fetch(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'GenZHypeBot/1.0', 'follow_location' => 1]]);
    $h = @file_get_contents($url, false, $ctx);
    if ($h !== false && $h !== '') return $h;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_USERAGENT => 'GenZHypeBot/1.0']);
        $h = curl_exec($c); curl_close($c);
        return is_string($h) ? $h : '';
    }
    return '';
}

/** Compute one competitor's full profile blob from its article set + side data. */
function _comp_profile_compute(array $arts, array $cadence, array $changes, ?array $backlink, ?array $design): array {
    $navStop = ['latest','status','images','videos','trending','editorials','episodes','more','comments',
                'related','newsletter','advertisement','read more','more stories','stay in touch','popular right now'];
    $words = []; $sub = 0; $thin = 0; $schema = []; $outbound = []; $h2counts = []; $headings = [];
    $sig = ['faqpage' => 0, 'speakable' => 0, 'sameAs' => 0, 'videoobject' => 0, 'author_person' => 0, 'newsarticle' => 0, 'breadcrumb' => 0];
    $authors = []; $format = ['news' => 0, 'listicle' => 0, 'explainer' => 0, 'wiki' => 0, 'video' => 0];
    $socialArts = 0;
    foreach ($arts as $f) {
        $wc = (int)($f['word_count'] ?? 0);
        $types = (array)($f['schema_types'] ?? []);
        $isArt = (bool)array_intersect(['Article','NewsArticle','BlogPosting','ReportageNewsArticle'], $types);
        if ($wc > 0) $words[] = $wc;
        if ($wc >= 300 && $isArt) $sub++; elseif ($wc > 0 && $wc < 300) $thin++;
        foreach (['faqpage','speakable','sameAs','videoobject','author_person'] as $k) if (!empty($f[$k])) $sig[$k]++;
        if (in_array('NewsArticle', $types) || in_array('Article', $types)) $sig['newsarticle']++;
        if (in_array('BreadcrumbList', $types)) $sig['breadcrumb']++;
        foreach ($types as $t) $schema[$t] = ($schema[$t] ?? 0) + 1;
        $h2s = (array)($f['h2_set'] ?? []);
        $h2counts[] = count($h2s);
        foreach ($h2s as $h) {
            $k = trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($h))));
            if ($k === '' || mb_strlen($k) < 4 || mb_strlen($k) > 80 || in_array($k, $navStop, true)) continue;
            $headings[$k] = ($headings[$k] ?? 0) + 1;
        }
        $hadSocial = false;
        foreach ((array)($f['outbound_domains'] ?? []) as $d) {
            $outbound[$d] = ($outbound[$d] ?? 0) + 1;
            if (comp_source_bucket($d) === 'social') $hadSocial = true;
        }
        if ($hadSocial) $socialArts++;
        // format
        $title = (string)($f['title'] ?? $f['h1'] ?? '');
        if (!empty($f['videoobject']) || in_array('VideoObject', $types)) $format['video']++;
        elseif (in_array('ItemList', $types) || preg_match('/\b\d+\s+(things|ways|times|reasons|best|worst|moments|facts|signs)\b/i', $title)) $format['listicle']++;
        elseif (preg_match('/\b(what is|explained|how to|guide|meaning|why |who is)\b/i', $title)) $format['explainer']++;
        elseif (!$isArt && (($f['og_type'] ?? '') !== 'article')) $format['wiki']++;
        else $format['news']++;
        // author roster
        $an = trim((string)($f['author'] ?? ''));
        if ($an !== '') { $authors[$an] = ($authors[$an] ?? 0) + 1; }
    }
    $n = count($arts);
    $nn = max(1, $n);
    // author classification
    $roster = []; $named = 0;
    arsort($authors);
    foreach ($authors as $name => $c) {
        $nl = mb_strtolower($name);
        if (preg_match('/\b(staff|team|editor|editors|desk|newsroom|admin|contributor|guest|writers?|reporter)\b/', $nl)) $kind = 'desk';
        elseif (preg_match('/^\p{L}[\p{L}.\'-]+\s+\p{L}/u', $name)) { $kind = 'named'; $named++; }
        else $kind = 'desk';
        $roster[] = ['name' => mb_substr($name, 0, 40), 'count' => $c, 'kind' => $kind];
    }
    $roster = array_slice($roster, 0, 20);
    $namedPct = $authors ? round(100 * $named / count($authors)) : 0;

    arsort($schema); arsort($outbound);
    $topHead = []; arsort($headings);
    foreach (array_slice($headings, 0, 12, true) as $h => $c) $topHead[] = ['h' => $h, 'count' => $c];
    $topOut = [];
    foreach (array_slice($outbound, 0, 15, true) as $d => $c) $topOut[] = ['domain' => $d, 'count' => $c, 'bucket' => comp_source_bucket($d)];
    $platMix = ['social' => 0, 'news' => 0, 'reference' => 0, 'other' => 0];
    foreach ($outbound as $d => $c) $platMix[comp_source_bucket($d)] += $c;

    $pct = fn($p) => _comp_pct($words, $p);
    $richness = $n ? round(100 * array_sum($sig) / (7 * $n)) : 0;
    $receipts = $n ? round(100 * $socialArts / $n) : 0;
    $eeat = (int)round($namedPct * 0.5 + round(100 * $sig['author_person'] / $nn) * 0.3 + round(100 * $sig['sameAs'] / $nn) * 0.2);

    // humanize recent changes
    $human = function ($t, $field, $old, $new) {
        $field = (string)$field;
        if ($t === 'word_count') { $d = (int)$new - (int)$old; return ($d >= 0 ? 'Expanded a page +' : 'Trimmed a page ') . $d . ' words'; }
        if ($t === 'author' || $field === 'author') return 'Byline changed: ' . mb_substr((string)$old, 0, 28) . ' → ' . mb_substr((string)$new, 0, 28);
        if (strpos($field, 'faqpage') !== false) return $new ? 'Added FAQ markup' : 'Dropped FAQ markup';
        if (strpos($field, 'schema') !== false) return 'Changed structured-data labels';
        if (strpos($field, 'h2') !== false) return 'Reworked the section structure';
        if (strpos($field, 'outbound') !== false) return 'Changed who they cite';
        if ($t === 'url_set' || strpos($field, 'url') !== false) return 'Published / removed pages';
        if ($field === 'title' || $field === 'h1') return 'Rewrote a headline';
        if (strpos($field, 'word') !== false) return 'Edited page length';
        return ucfirst(str_replace('_', ' ', (string)$t)) . ' changed';
    };
    $recent = [];
    foreach (array_slice($changes, 0, 15) as $ch) {
        $recent[] = ['at' => (int)$ch['detected_at'], 'type' => $ch['change_type'],
            'human' => $human($ch['change_type'], $ch['field'], $ch['old_value'], $ch['new_value'])];
    }

    return [
        'n_articles' => $n,
        'depth' => ['min' => $words ? min($words) : 0, 'p25' => $pct(0.25), 'median' => $pct(0.5),
                    'p75' => $pct(0.75), 'p90' => $pct(0.90), 'max' => $words ? max($words) : 0,
                    'substantial' => $sub, 'thin' => $thin, 'dist' => $words],
        'structure' => ['avg_h2' => $h2counts ? round(array_sum($h2counts) / count($h2counts), 1) : 0, 'top_headings' => $topHead],
        'schema' => ['types' => array_slice($schema, 0, 12, true),
                     'signals' => ['faqpage' => round(100 * $sig['faqpage'] / $nn), 'speakable' => round(100 * $sig['speakable'] / $nn),
                                   'sameAs' => round(100 * $sig['sameAs'] / $nn), 'videoobject' => round(100 * $sig['videoobject'] / $nn),
                                   'author_person' => round(100 * $sig['author_person'] / $nn), 'newsarticle' => round(100 * $sig['newsarticle'] / $nn),
                                   'breadcrumb' => round(100 * $sig['breadcrumb'] / $nn)],
                     'richness' => $richness],
        'sourcing' => ['top_domains' => $topOut, 'platform_mix' => $platMix, 'receipts_score' => $receipts,
                       'avg_outbound' => $n ? round(array_sum($outbound) / $n, 1) : 0],
        'authors' => ['roster' => $roster, 'named_pct' => $namedPct, 'eeat_score' => $eeat],
        'format' => $format,
        'cadence' => ['urls' => (int)($cadence['urls'] ?? 0), 'newest' => (string)($cadence['newest'] ?? '')],
        'offpage' => $backlink ? ['authority' => $backlink['authority'] !== null ? (float)$backlink['authority'] : null,
                       'rank' => $backlink['rank_position'] !== null ? (int)$backlink['rank_position'] : null,
                       'ref_count' => (int)$backlink['ref_count']] : null,
        'has_design' => $design ? true : false,
        'recent_changes' => $recent,
    ];
}

/** Run the per-rival distiller for ALL competitors + compute our own baseline. Persists comp_profile rows. */
function comp_distill_per_rival(PDO $pdo): array {
    competitor_init($pdo);
    // latest article snapshot per watch, grouped by competitor
    $byComp = [];
    $sql = "SELECT c.id cid, c.domain dom, s.fields_json
            FROM comp_snapshot s JOIN comp_watch w ON w.id=s.watch_id JOIN competitors c ON c.id=w.competitor_id
            JOIN (SELECT watch_id, MAX(captured_at) mx FROM comp_snapshot GROUP BY watch_id) L
              ON L.watch_id=s.watch_id AND L.mx=s.captured_at
            WHERE w.watch_type='page'";
    foreach ($pdo->query($sql) as $r) {
        $f = json_decode($r['fields_json'], true);
        if (is_array($f)) $byComp[(int)$r['cid']]['arts'][] = $f;
        $byComp[(int)$r['cid']]['dom'] = $r['dom'];
    }
    // sitemap cadence per competitor
    $cad = [];
    $sm = "SELECT c.id cid, s.fields_json FROM comp_snapshot s JOIN comp_watch w ON w.id=s.watch_id JOIN competitors c ON c.id=w.competitor_id
           JOIN (SELECT watch_id, MAX(captured_at) mx FROM comp_snapshot GROUP BY watch_id) L ON L.watch_id=s.watch_id AND L.mx=s.captured_at
           WHERE w.watch_type='sitemap'";
    foreach ($pdo->query($sm) as $r) { $f = json_decode($r['fields_json'], true) ?: []; $cad[(int)$r['cid']] = ['urls' => (int)($f['url_count'] ?? 0), 'newest' => (string)($f['newest_lastmod'] ?? '')]; }
    // recent changes per competitor
    $chg = [];
    foreach ($pdo->query("SELECT c.id cid, ch.detected_at, ch.change_type, ch.field, ch.old_value, ch.new_value
                          FROM comp_change ch JOIN comp_watch w ON w.id=ch.watch_id JOIN competitors c ON c.id=w.competitor_id
                          ORDER BY ch.detected_at DESC") as $r) { $chg[(int)$r['cid']][] = $r; }
    // side data keyed by domain
    $bl = []; foreach ($pdo->query("SELECT domain,authority,rank_position,ref_count FROM comp_backlink WHERE is_ours=0") as $r) $bl[$r['domain']] = $r;
    $dz = []; foreach ($pdo->query("SELECT domain FROM comp_design WHERE is_ours=0") as $r) $dz[$r['domain']] = true;

    $now = time(); $wrote = 0;
    $ins = $pdo->prepare("INSERT INTO comp_profile (competitor_id,domain,is_ours,profile_json,n_articles,computed_at) VALUES (?,?,0,?,?,?)");
    foreach ($byComp as $cid => $d) {
        $dom = $d['dom']; $arts = $d['arts'] ?? [];
        if (!$arts) continue;
        $prof = _comp_profile_compute($arts, $cad[$cid] ?? [], $chg[$cid] ?? [], $bl[$dom] ?? null, isset($dz[$dom]) ? ['x' => 1] : null);
        $ins->execute([$cid, $dom, json_encode($prof, JSON_UNESCAPED_SLASHES), $prof['n_articles'], $now]);
        $wrote++;
    }

    // ---- OUR baseline (honest, self-scanned where possible) ----
    $base = rtrim($GLOBALS['CONFIG']['base_url'] ?? 'https://genzhype.com', '/');
    $paths = $pdo->query("SELECT path FROM pages WHERE status='published' AND robots='index' ORDER BY published_at DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
    $usSig = ['faqpage' => 0, 'speakable' => 0, 'sameAs' => 0, 'videoobject' => 0, 'author_person' => 0, 'newsarticle' => 0, 'breadcrumb' => 0]; $usN = 0;
    foreach ($paths as $p) {
        $html = _comp_fetch($base . $p);
        if ($html === '') continue;
        // string-match the whole JSON-LD blob (robust to @graph nesting that jsonld_types may not flatten)
        $blob = json_encode(extract_jsonld($html));
        if ($blob === '' || $blob === '[]') continue;
        $usN++;
        if (stripos($blob, 'FAQPage') !== false) $usSig['faqpage']++;
        if (stripos($blob, 'speakable') !== false) $usSig['speakable']++;
        if (stripos($blob, '"sameAs"') !== false) $usSig['sameAs']++;
        if (stripos($blob, 'VideoObject') !== false) $usSig['videoobject']++;
        if (stripos($blob, 'Person') !== false) $usSig['author_person']++;
        if (stripos($blob, 'NewsArticle') !== false || stripos($blob, '"Article"') !== false) $usSig['newsarticle']++;
        if (stripos($blob, 'BreadcrumbList') !== false) $usSig['breadcrumb']++;
    }
    $depthRule = comp_rule_get($pdo, 'gate', 'depth_benchmark', []);
    $floor = (int)($depthRule['recommend_min_words'] ?? 600);
    $ourBl = []; foreach ($pdo->query("SELECT authority,rank_position,ref_count FROM comp_backlink WHERE is_ours=1 LIMIT 1") as $r) $ourBl = $r;
    $usSchemaPct = [];
    foreach ($usSig as $k => $v) $usSchemaPct[$k] = $usN ? round(100 * $v / $usN) : null;
    $pubWeek = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='published' AND published_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
    $usProfile = [
        'self_scanned' => $usN, 'depth_floor' => $floor,
        'schema_signals' => $usSchemaPct,
        'authors' => ['named_pct' => 100, 'note' => 'Every page bylined to a named human (Deimos EMK)'],
        'offpage' => ['authority' => isset($ourBl['authority']) ? (float)$ourBl['authority'] : 0, 'ref_count' => (int)($ourBl['ref_count'] ?? 0)],
        'velocity_week' => $pubWeek,
    ];
    $pdo->prepare("INSERT INTO comp_profile (competitor_id,domain,is_ours,profile_json,n_articles,computed_at) VALUES (NULL,?,1,?,?,?)")
        ->execute(['genzhype.com', json_encode($usProfile, JSON_UNESCAPED_SLASHES), $usN, $now]);

    return ['ok' => true, 'rivals_profiled' => $wrote, 'us_pages_scanned' => $usN, 'computed_at' => $now];
}

