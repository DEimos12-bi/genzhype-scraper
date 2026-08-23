<?php
// GenZHype | the hard publish gate. A drama page cannot flip to indexable
// unless EVERY check passes. Pure PHP, no dependencies (shared hosting).
// Thresholds from the locked rulebook (our heuristics where Google gives none).

// OWNER DECISION 2026-08-22 — "option A". The floor was 6, and that single
// number is why the site posted 42-day-old news: a story needs WEEKS to
// accumulate six dated events, so only mature stories could ever publish and
// everything breaking died in draft (measured: 9 of 12 fresh drafts blocked
// on this check alone, with 3-5 events each, every one of them dated AND
// sourced). The 6 was never a Google rule — the header below still says it:
// "our heuristics where Google gives none".
// New rule: a story may publish from 3 dated events, but anything under
// GATE_FULL_EVENTS is labelled DEVELOPING on the page and in its schema, and
// keeps growing as the story does. Honest thinness beats silent staleness.
const GATE_MIN_EVENTS         = 3;  // publishable floor (developing stories)
const GATE_FULL_EVENTS        = 6;  // at/above this a story reads as complete
const GATE_MIN_SOURCE_DOMAINS = 2;
const GATE_TITLE_MAX          = 60;
const GATE_TITLE_MIN          = 15;
const GATE_META_MIN           = 110;  // tool-consensus mobile-safe range
const GATE_META_MAX           = 160;  // unified with the rendered SEO audit (was 135, which was stricter than the audit's 160 and silently blocked valid 136-160 metas)
const GATE_SUMMARY_MIN        = 120;
const GATE_SUMMARY_MAX        = 420;

/**
 * SEO-BATCH-1 HOLDING ACTION (2026-08-04): THE SINGLE PLACE A PAGE GOES LIVE.
 *
 * The drama lane may still draft and archive, but it may NOT publish to index.
 * Measured exposure at the time of this change:
 *   - 458 of 467 drama pages fail the alleged-framing (defamation) shield
 *   - 72.2% have no primary source anywhere in their chain
 *   - 91.3% of drama events cite a non-primary aggregator
 *   - 0 drama pages appear in Google or Bing data across three months
 * Maximum legal exposure, zero business return, on real named people under a
 * pseudonymous byline. So drama publishes as noindex until the lane has a
 * primary-source requirement at INGEST.
 *
 * NOT a deletion and NOT a 410: rows stay, pages stay reachable by direct URL,
 * and flipping this back is a one-line change. Returns true if the page went
 * live indexable.
 */
function page_publish_live(PDO $pdo, int $pageId): bool {
    $t = $pdo->prepare("SELECT type FROM pages WHERE id=?");
    $t->execute([$pageId]);
    $isDrama = ((string)$t->fetchColumn() === 'drama');

    // OWNER DECISION 2026-08-22: the blanket drama noindex hold is LIFTED —
    // but per page, never per lane. The August hold was blunt because there
    // was no per-page test: 458 of 467 pages failed the defamation shield and
    // nothing could tell the safe ones apart. There is a test now, and the
    // exposure is still real: 1,353 of 2,383 unconfirmed claims about named
    // people (57%) were STILL unframed when this was measured. So a drama page
    // earns 'index' only if EVERY unconfirmed claim on it carries hedging or
    // attribution; anything else publishes noindex exactly as before, and the
    // daily framing repair keeps eating the backlog until it qualifies.
    if ($isDrama) {
        $unframed = 0;
        try {
            require_once __DIR__ . '/framing_repair.php';
            $q = $pdo->prepare("SELECT COUNT(*) FROM events e
                                  JOIN dramas d ON d.id = e.drama_id
                                 WHERE d.page_id = ? AND e.is_confirmed = 0
                                   AND e.description NOT REGEXP ?");
            $q->execute([$pageId, FR_FRAMING_RX]);
            $unframed = (int)$q->fetchColumn();
        } catch (Throwable $e) {
            $unframed = 1;   // cannot verify => treat as unsafe, fail closed
        }
        if ($unframed > 0) {
            $pdo->prepare("UPDATE pages SET status='published', robots='noindex',
                           published_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$pageId]);
            echo "  DRAMA HOLD: published as NOINDEX ({$unframed} unframed claim(s) about real people)\n";
            return false;
        }
        // the source-count bar lives HERE now: one source is enough to
        // publish, two independent ones are what we ask before inviting
        // Google to rank the claim.
        $dq = $pdo->prepare("SELECT COUNT(DISTINCT s.domain) FROM sources s
                               JOIN events e ON e.source_id = s.id
                               JOIN dramas d ON d.id = e.drama_id
                              WHERE d.page_id = ?");
        $dq->execute([$pageId]);
        if ((int)$dq->fetchColumn() < GATE_MIN_SOURCE_DOMAINS) {
            $pdo->prepare("UPDATE pages SET status='published', robots='noindex',
                           published_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$pageId]);
            echo "  PUBLISHED (noindex): single-source story — live on the site, not offered to Google\n";
            return false;
        }
        echo "  DRAMA CLEARED: framed + multi-sourced; publishing INDEXABLE\n";
    }
    // TERM/MEME/GAMING pages get the same treatment the drama lane now gets
    // (owner rule 2026-08-22): a single-source explainer still goes LIVE, it
    // just is not offered to Google until a second independent source backs
    // it. Counted from the page's own citations.
    if (!$isDrama) {
        $srcN = 0;
        try {
            $cq = $pdo->prepare("SELECT citations FROM terms WHERE page_id=?");
            $cq->execute([$pageId]);
            $doms = [];
            foreach ((array)json_decode((string)$cq->fetchColumn(), true) as $c) {
                $u = is_array($c) ? (string)($c['url'] ?? '') : '';
                $h = $u !== '' ? parse_url($u, PHP_URL_HOST) : '';
                if ($h) { $doms[strtolower($h)] = 1; }
            }
            $srcN = count($doms);
        } catch (Throwable $e) { $srcN = 0; }
        if ($srcN < GATE_MIN_SOURCE_DOMAINS) {
            $pdo->prepare("UPDATE pages SET status='published', robots='noindex',
                           published_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$pageId]);
            echo "  PUBLISHED (noindex): {$srcN} source domain(s) — live on the site, not offered to Google\n";
            return false;
        }
    }
    $pdo->prepare("UPDATE pages SET status='published', robots='index',
                   published_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$pageId]);
    return true;
}

/**
 * SEO-BATCH-1 (2026-08-04): audit trail for confirming a claim about a real
 * person. Idempotent; safe to call repeatedly.
 */
function gate_events_install(PDO $pdo): void {
    foreach ([
        "ADD COLUMN confirmed_by VARCHAR(80) NULL",     // who promoted it
        "ADD COLUMN confirmed_at DATETIME NULL",        // when
        "ADD COLUMN confirmed_src VARCHAR(500) NULL",   // the PRIMARY source relied on
    ] as $alter) {
        try { $pdo->exec("ALTER TABLE events {$alter}"); } catch (Throwable $e) {}
    }
}

/**
 * Hosts that can PROMOTE a claim about a real named person to "confirmed":
 * courts, government, and the primary parties' own platforms. Aggregators
 * reporting on each other (dexerto, dailydot, theshaderoom, knowyourmeme) can
 * never promote — they are the tier that repeats a claim, not the tier that
 * establishes it.
 */
function gate_event_source_is_primary(string $url): bool {
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($h === '') return false;
    foreach (['courtlistener.com', 'pacer.gov', 'uscourts.gov', 'justice.gov',
              'sec.gov', 'ftc.gov', 'supremecourt.gov', 'documentcloud.org',
              'x.com', 'twitter.com', 'instagram.com', 'youtube.com', 'youtu.be',
              'tiktok.com', 'twitch.tv', 'facebook.com'] as $d) {
        if ($h === $d || str_ends_with($h, '.' . $d)) return true;
    }
    if (str_ends_with($h, '.gov') || str_ends_with($h, '.gov.uk')) return true;
    return false;
}

/**
 * The ONLY sanctioned way an event becomes is_confirmed=1.
 * Either a recorded human action, or a primary source. Never a model, and never
 * an aggregator. Returns true when promoted.
 */
function gate_event_confirm(PDO $pdo, int $eventId, string $by, string $srcUrl): bool {
    gate_events_install($pdo);
    $human = trim($by) !== '' && strtolower($by) !== 'model' && strtolower($by) !== 'ai';
    if (!$human && !gate_event_source_is_primary($srcUrl)) return false;
    $pdo->prepare("UPDATE events SET is_confirmed=1, confirmed_by=?, confirmed_at=NOW(), confirmed_src=? WHERE id=?")
        ->execute([mb_substr($by, 0, 80), mb_substr($srcUrl, 0, 500), $eventId]);
    return true;
}

function gate_check_drama(int $page_id): array {
    $pdo = db();
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.title dtitle, d.lifecycle
                        FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return ['pass' => false, 'checks' => [], 'error' => 'page not found or not a drama'];

    $did = (int)$page['drama_id'];
    $checks = [];
    $add = function (string $id, string $label, bool $ok, string $detail = '') use (&$checks) {
        $checks[] = ['id' => $id, 'label' => $label, 'pass' => $ok, 'detail' => $detail];
    };

    // 1. Title tag length
    $tl = mb_strlen($page['title_tag'] ?? '');
    $add('title', "Title tag {$tl} chars (need " . GATE_TITLE_MIN . "-" . GATE_TITLE_MAX . ")",
         $tl >= GATE_TITLE_MIN && $tl <= GATE_TITLE_MAX, $page['title_tag'] ?? '(empty)');

    // 2. Meta description length
    $ml = mb_strlen($page['meta_desc'] ?? '');
    $add('meta', "Meta description {$ml} chars (need " . GATE_META_MIN . "-" . GATE_META_MAX . ")",
         $ml >= GATE_META_MIN && $ml <= GATE_META_MAX);

    // 3. H1 present
    $add('h1', 'H1 present', !empty($page['h1']));

    // 4. Answer-first summary present
    $sl = mb_strlen($page['summary'] ?? '');
    $add('summary', "TL;DR summary {$sl} chars (need " . GATE_SUMMARY_MIN . "-" . GATE_SUMMARY_MAX . ")",
         $sl >= GATE_SUMMARY_MIN && $sl <= GATE_SUMMARY_MAX);

    // 5. Byline
    $add('byline', 'Author/byline set', !empty($page['author_id']));

    // 6. Cover image set
    $add('cover', 'Cover image set', !empty($page['cover']));

    // 7. Minimum sourced, dated events
    $n = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE drama_id={$did}")->fetchColumn();
    $add('events', "{$n} timeline events (need >= " . GATE_MIN_EVENTS . ")", $n >= GATE_MIN_EVENTS);
    // option A bookkeeping: a story below the full bar is marked DEVELOPING so
    // the reader is told it is still moving, rather than being handed a thin
    // page dressed as a finished one.
    if ($n >= GATE_MIN_EVENTS && $n < GATE_FULL_EVENTS) {
        try {
            $pdo->prepare("UPDATE dramas SET lifecycle='developing' WHERE id=? AND lifecycle<>'resolved'")
                ->execute([$did]);
        } catch (Throwable $e) { /* labelling must never block a gate run */ }
    }

    // 8. Every event carries a source (citation gate)
    $unsourced = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE drama_id={$did} AND source_id IS NULL")->fetchColumn();
    $add('citations', "Every event sourced ({$unsourced} unsourced)", $unsourced === 0);

    // 9. Source-domain diversity
    $dom = (int)$pdo->query("SELECT COUNT(DISTINCT s.domain) FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id={$did}")->fetchColumn();
    $add('domains', "{$dom} distinct source domains (need >= " . GATE_MIN_SOURCE_DOMAINS . ")", $dom >= GATE_MIN_SOURCE_DOMAINS);

    // 10. Unconfirmed claims use alleged-framing (defamation shield)
    $bad = 0;
    $q = $pdo->query("SELECT description FROM events WHERE drama_id={$did} AND is_confirmed=0");
    foreach ($q->fetchAll() as $e) {
        if (!preg_match('/alleg|reportedly|claims?|according to|unverified|appears to/i', $e['description'])) $bad++;
    }
    $add('alleged', "Unconfirmed events use alleged-framing ({$bad} violations)", $bad === 0);

    // 11. FAQ block present (snippet + FAQPage schema)
    $fq = (int)$pdo->query("SELECT COUNT(*) FROM faqs WHERE drama_id={$did}")->fetchColumn();
    $add('faq', "{$fq} FAQ entries (need >= 2)", $fq >= 2);

    // 12. Dates sane: both present and not in the future. (Do NOT require
    // published<=updated: publishing legitimately happens after the last content
    // edit, so that ordering check was de-indexing every freshly-published page.)
    $pt = strtotime((string)($page['published_at'] ?? ''));
    $ut = strtotime((string)($page['updated_at'] ?? ''));
    $datesOk = $pt && $ut && $pt <= time() + 172800 && $ut <= time() + 172800;
    $add('dates', 'Published/updated dates sane', $datesOk);

    // OWNER RULE 2026-08-22: "everything we use to build the page IS a source;
    // the source count should only be a GOOGLE gate." Correct on both counts.
    // The origin article is already stored as a source (verified: pages carry
    // bbc/dexerto/knowyourmeme etc.), and a single-source story is a
    // CONFIDENCE question, not a publishing one — it can still live on the
    // site and become a video. So the domain count no longer blocks
    // publishing; page_publish_live() uses it to decide INDEXABLE instead.
    // Everything else — framing, dates, byline, cover — still blocks.
    $advisory = ['domains'];
    $blocking = array_filter($checks, fn($c) => !in_array($c['id'], $advisory, true));
    $pass = !in_array(false, array_column($blocking, 'pass'), true);
    return ['pass' => $pass, 'checks' => $checks, 'page_id' => $page_id, 'slug' => $page['slug']];
}

/** Render any page's full HTML in-process (works for drafts too). */
function render_page_html(int $pageId): ?string {
    global $CONFIG;
    $pdo = db();
    $row = $pdo->prepare("SELECT type, slug, path FROM pages WHERE id=?");
    $row->execute([$pageId]);
    $p = $row->fetch();
    if (!$p) return null;
    require_once __DIR__ . '/repo.php';
    $_SERVER['REQUEST_URI'] = $p['path'];
    if ($p['type'] === 'term') {
        $shape = repo_load_term_any($p['slug']);
        return $shape ? view('term', ['term' => $shape]) : null;
    }
    if ($p['type'] === 'drama') {
        $shape = repo_load_drama_any($p['slug']);
        return $shape ? view('drama', ['drama' => $shape]) : null;
    }
    return null;
}

/**
 * SEO audit of a page's RENDERED HTML. One engine for both the pre-publish
 * gate and the watchdog's drift snapshots. Returns ['pass','fails','snap'].
 */
function seo_audit_page(int $pageId): array {
    global $CONFIG;
    $pdo = db();
    $pr = $pdo->prepare("SELECT type, slug, path, featured_img, cover FROM pages WHERE id=?");
    $pr->execute([$pageId]);
    $pg = $pr->fetch();
    $html = $pg ? render_page_html($pageId) : null;
    if (!$html) return ['pass' => false, 'fails' => ['render failed'], 'snap' => []];
    $fails = [];
    $base = rtrim($CONFIG['base_url'], '/');

    preg_match('#<title>(.*?)</title>#s', $html, $m);  $title = html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES);
    preg_match('#name="description" content="([^"]*)"#', $html, $m); $meta = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    preg_match('#rel="canonical" href="([^"]*)"#', $html, $m); $canon = $m[1] ?? '';
    preg_match('#name="robots" content="([^"]*)"#', $html, $m); $robots = $m[1] ?? '';
    $h1 = preg_match_all('#<h1[\s>]#', $html);
    $imgs = preg_match_all('#<img\b[^>]*>#', $html, $imgTags);
    $noAlt = 0;
    foreach (($imgTags[0] ?? []) as $tag) if (!preg_match('#\balt="[^"]+"#', $tag)) $noAlt++;
    preg_match_all('#href="(/[a-z0-9\-/]*)"#', $html, $lm);
    $links = count(array_unique($lm[1] ?? []));
    $schemaTypes = [];
    if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $sm)) {
        foreach ($sm[1] as $blob) {
            $j = json_decode($blob, true);
            foreach (($j['@graph'] ?? [$j]) as $node) {
                if (!empty($node['@type'])) $schemaTypes[] = $node['@type'];
                foreach (['publisher', 'author'] as $sub) if (!empty($node[$sub]['@type'])) $schemaTypes[] = $node[$sub]['@type'];
                if (!empty($node['publisher']['logo']['@type'])) $schemaTypes[] = $node['publisher']['logo']['@type'];
                // entity-graph era (2026-07-08): publisher/author became @id REFS and the
                // logo ImageObject lives on the shared Organization node itself. Without
                // these, every drama failed "schema missing ImageObject" -> publish stall.
                if (!empty($node['logo']['@type']))  $schemaTypes[] = $node['logo']['@type'];
                if (!empty($node['image']['@type'])) $schemaTypes[] = $node['image']['@type'];
            }
        }
    }
    $schemaTypes = array_values(array_unique($schemaTypes));
    $ogOK  = true; foreach (['og:type','og:title','og:description','og:url','og:image','og:image:width','og:site_name'] as $k) if (!str_contains($html, 'property="' . $k . '"')) { $ogOK = false; $ogMiss = $k; }
    $twOK  = true; foreach (['twitter:card','twitter:title','twitter:description','twitter:image'] as $k) if (!str_contains($html, 'name="' . $k . '"')) { $twOK = false; $twMiss = $k; }
    $byline = (bool)preg_match('#href="/how-we-source/"#', $html);

    $tl = mb_strlen($title); $ml = mb_strlen($meta);
    if ($tl < GATE_TITLE_MIN || $tl > GATE_TITLE_MAX) $fails[] = "title length {$tl} (need " . GATE_TITLE_MIN . "-" . GATE_TITLE_MAX . ")";
    if ($ml < GATE_META_MIN || $ml > GATE_META_MAX)   $fails[] = "meta length {$ml} (need " . GATE_META_MIN . "-" . GATE_META_MAX . ")";
    if ($h1 !== 1)                          $fails[] = "h1 count {$h1} (need exactly 1)";
    if ($canon !== $base . $pg['path'])     $fails[] = "canonical '{$canon}' != self";
    if (!$ogOK)                             $fails[] = "OG incomplete (missing {$ogMiss})";
    if (!$twOK)                             $fails[] = "Twitter card incomplete (missing {$twMiss})";
    if ($noAlt > 0)                         $fails[] = "{$noAlt} img(s) missing alt";
    // A drama's hero/og:image is its `cover` (always set — branded card or real photo);
    // `featured_img` is only populated when a REAL photo was found. Accept either, so a
    // perfectly valid branded-card drama is no longer blocked from publishing forever.
    if (empty($pg['featured_img']) && empty($pg['cover'])) $fails[] = "featured image absent";
    if ($links < 10)                        $fails[] = "internal links {$links} (need >=10)";
    if (!in_array('Organization', $schemaTypes)) $fails[] = "schema missing Organization";
    if (!in_array('ImageObject', $schemaTypes))  $fails[] = "schema missing ImageObject";
    if ($pg['type'] === 'term') {
        // SEO-BATCH-1: FAQPage and VideoObject were REMOVED from the term
        // template on purpose (Google retired FAQ rich results for ordinary
        // sites in 2023; the VideoObject carried no required metadata). This
        // auditor kept demanding what the template deliberately no longer
        // emits, which blocked every new term from publishing — pogchamp
        // passed the full truth gate and then died here on two schema types
        // that SHOULD be absent.
        foreach (['DefinedTerm','Article','BreadcrumbList'] as $st)
            if (!in_array($st, $schemaTypes)) $fails[] = "schema missing {$st}";
        if (!$byline) $fails[] = "byline/how-we-source link absent";
    } elseif ($pg['type'] === 'drama') {
        foreach (['NewsArticle','FAQPage','BreadcrumbList'] as $st)
            if (!in_array($st, $schemaTypes)) $fails[] = "schema missing {$st}";
    }
    $snap = ['title' => $tl, 'meta' => $ml, 'canonical' => $canon, 'robots' => $robots,
             'schema' => $schemaTypes, 'h1' => $h1, 'links' => $links,
             'featured' => !empty($pg['featured_img']) || !empty($pg['cover']), 'byline' => $byline];
    return ['pass' => !$fails, 'fails' => $fails, 'snap' => $snap];
}
