<?php
// GenZHype | DRAFT stage. AI structures PROVIDED sources into a timeline draft.
// Hard rule: the model may ONLY use the supplied source excerpts | never invent.
// Output lands in MySQL as status='draft', robots='noindex'. Gate decides the rest.

require_once __DIR__ . '/ai.php';

function draft_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(preg_replace('/-+/', '-', $s), '-');
}

/** Greedy word-wrap a headline into up to $maxLines lines of ~$max chars. */
function cover_wrap(string $s, int $max = 16, int $maxLines = 3): array {
    $words = preg_split('/\s+/', trim($s)); $lines = []; $cur = '';
    foreach ($words as $w) {
        if ($cur === '') { $cur = $w; }
        elseif (mb_strlen($cur . ' ' . $w) <= $max) { $cur .= ' ' . $w; }
        else { $lines[] = $cur; $cur = $w; if (count($lines) >= $maxLines) break; }
    }
    if ($cur !== '' && count($lines) < $maxLines) $lines[] = $cur;
    return array_slice($lines, 0, $maxLines);
}

/**
 * Premium branded drama cover (dark editorial card). Used whenever there is no
 * VERIFIED real photo of the people — honest (claims nothing it can't show) and
 * intentional (a title card, not a random stock photo). Mood drives the accent.
 */
function draft_make_cover(string $slug, string $big, string $sub, string $mood = 'neutral', string $status = 'Ongoing'): string {
    $accent = ['conflict' => '#E23B2E', 'scandal' => '#D79A2A', 'sad' => '#5E84B8',
               'funny' => '#3DA35D', 'hype' => '#B5468C', 'neutral' => '#C71F12'][$mood] ?? '#C71F12';
    $lines = cover_wrap($big, 17, 3);
    $size  = [1 => 104, 2 => 82, 3 => 62][count($lines)] ?? 62;
    $lh    = (int)round($size * 1.04);
    $blockH = $lh * count($lines);
    $startY = (int)round(330 - $blockH / 2 + $size * 0.74);   // vertically centre the title block
    $tspans = '';
    foreach ($lines as $i => $ln) {
        $y = $startY + $i * $lh;
        $tspans .= '<tspan x="88" y="' . $y . '">' . htmlspecialchars($ln, ENT_XML1) . '</tspan>';
    }
    $ruleY = $startY + ($lh * (count($lines) - 1)) + (int)round($size * 0.42);
    $subE  = htmlspecialchars(mb_substr($sub, 0, 64), ENT_XML1);
    $statusE = htmlspecialchars(strtoupper($status), ENT_XML1);
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-label="GenZHype drama">
  <defs><linearGradient id="bg" x1="0" y1="0" x2="0.5" y2="1">
    <stop offset="0" stop-color="#1c1813"/><stop offset="1" stop-color="#0d0b09"/></linearGradient></defs>
  <rect width="1200" height="630" fill="url(#bg)"/>
  <text x="1150" y="600" text-anchor="end" font-family="Georgia, serif" font-size="660" font-weight="900" fill="#ffffff" opacity="0.035">&#8221;</text>
  <text x="64" y="84" font-family="Georgia, serif" font-size="27" font-weight="800" fill="#F4F1EA">GENZ<tspan fill="{$accent}">HYPE</tspan></text>
  <text x="64" y="110" font-family="ui-monospace, monospace" font-size="13" letter-spacing="3" fill="#8C857A">DRAMA DESK &#183; THE TIMELINE</text>
  <circle cx="1058" cy="80" r="5" fill="{$accent}"/>
  <text x="1136" y="85" text-anchor="end" font-family="ui-monospace, monospace" font-size="14" letter-spacing="2" fill="#C9C2B6">{$statusE}</text>
  <rect x="64" y="218" width="6" height="{$blockH}" fill="{$accent}"/>
  <text font-family="Georgia, serif" font-weight="900" font-size="{$size}" fill="#F7F4ED">{$tspans}</text>
  <rect x="88" y="{$ruleY}" width="96" height="5" fill="{$accent}"/>
  <text x="64" y="586" font-family="system-ui, Arial, sans-serif" font-size="19" fill="#A39C8F">The receipts, not the gossip.</text>
  <text x="1136" y="586" text-anchor="end" font-family="ui-monospace, monospace" font-size="16" fill="#8C857A">genzhype.com</text>
</svg>
SVG;
    $path = dirname(__DIR__) . '/public_html/assets/covers/' . $slug . '.svg';
    file_put_contents($path, $svg);
    require_once __DIR__ . '/draft_term.php';
    return cover_rasterize($path) ?? ('/assets/covers/' . $slug . '.svg');
}

/** The drafting prompt: [system, user]. Its own function so a draft can be tested without writing a page. */
function draft_drama_prompt(array $input): array {
    $srcBlock = '';
    foreach ($input['sources'] as $i => $s) {
        $n = $i + 1;
        $srcBlock .= "SOURCE {$n}: publisher={$s['publisher']} url={$s['url']} date={$s['date']}\nEXCERPT: {$s['excerpt']}\n\n";
    }

    $sys = "You are the GenZHype drafting desk. You turn PROVIDED sources into a neutral, dated drama timeline. ABSOLUTE RULES: 1) Use ONLY facts present in the provided source excerpts. NEVER invent names, dates, quotes or events. 2) Neutral tone; no clickbait. 3) Any claim not confirmed by an official/primary statement => is_confirmed=0 AND the description must frame it: name who says so with 'according to <outlet or person>', or use alleged/reportedly/claims when the claim is contested. Frame each event once; do not stack hedges, and do not hedge plain facts nobody disputes (a release date, a view count) beyond naming the source. 4) Title tag must be 50-60 characters. Meta description must be 120-132 characters, one complete sentence. Summary must be 120-420 characters, answer-first (what happened + current status). 5) One event per distinct dated development: a different person acting, or a different statement, post, filing or announcement, is its own event. Include every one the sources support; never split a single post, video or article into several events, never repeat a fact across events, never pad. 6) Each event cites the source number(s) it came from. 7) people = the REAL public figures/creators central to this story, FULL real names (e.g. 'Kai Cenat', 'Jimmy Donaldson'), most important first, max 4 — used to pull their real photos. Only names actually in the sources. Output STRICT JSON only, no commentary.";

    // Competitor Engine: append the live competitive bar (depth/structure/sourcing derived
    // from real rival pages) so each draft is written to outrank what competitors publish.
    require_once __DIR__ . '/distill.php';
    try { $sys .= comp_brief_for_drafter(db()); } catch (Throwable $e) { /* no rules yet -> draft as before */ }

    // 2026-09-24 EDITOR'S STANDARD. The editor (quality.php) failed most drafts on
    // stale status, unanswered core question, padding and clickbait titles; these
    // rules write to what it scores. They sit after the competitive bar so a word
    // or angle target can never force padding on a one-source story.
    // "As of" is the newest source's date. A TODAY line made the model stamp undated events with
    // today (Complexity, 2026-09-25: a sabbatical dated today that no source stated; verify caught it).
    $newest = max(array_merge([''], array_map(fn($s) => substr((string)($s['date'] ?? ''), 0, 10), $input['sources'])));
    $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $newest) ? date('F j, Y', strtotime($newest)) : gmdate('F j, Y');
    $sys .= "\n\nEDITOR'S STANDARD (an editor scores every page on search intent, people-first value, AI-citability, clarity and trust, and rejects it below 7/10 on any one; these rules win over any length or angle target above):"
          . "\nA) DATES: an event's date is the day it HAPPENED as a source states it. A source's own publish date is not the date of things it describes as happening earlier. An earlier development is an event when a source states its month or year (use the date format given in the JSON shape); backstory with no stated date goes in background, never in events. The report itself may be an event on the article's date (e.g. 'Dexerto reports ...'). List events oldest first."
          . "\nB) SUMMARY: sentence 1 answers what a searcher wants to know (who did what, when). The last sentence starts 'As of {$asOf},' and gives the current status, consistent with the latest event."
          . "\nC) LIFECYCLE: resolved only when a source reports an ending (ruling, settlement, dismissal, release, apology accepted); dormant when the latest event is over 30 days before the newest source's date and nothing is pending; otherwise ongoing."
          . "\nD) TITLE, H1, TITLE TAG: say plainly what happened. Use verbs like admits, exposes, confirms, slams or leaks only when a source shows exactly that; no teasers, no questions, nothing the sources do not carry."
          . "\nE) FAQS: 4 to 6 questions worded the way people search (for example 'Is the X lawsuit over?', 'What did X say about Y?', 'Why did X do Y?'). The first is about the current status and its answer starts 'As of {$asOf}'. Answers are 1 to 3 direct sentences, each claim attributed. No FAQ may just repeat the summary."
          . "\nF) BACKGROUND: who the people are and the context a newcomer needs."
          . "\nG) LENGTH FOLLOWS THE SOURCES: a story with one or two short sources gets a short, complete page. Depth comes only from facts in the sources. Never merge or drop distinct developments to make a page shorter."
          . "\nH) why_it_matters: 2-3 sentences on the concrete stakes the sources state (money, jobs, a ruling or precedent, a platform or policy change, who is affected and how). An empty string when the sources state no concrete stake; no general claims like 'one of the biggest'. Not a repeat of the summary."
          . "\nI) whats_next: up to 3 things the sources say are scheduled, pending or awaited (a hearing, a release, a promised reply, a deadline), each with its date when a source gives one, each attributed ('according to <outlet>'). An empty list when the sources say nothing about what comes next; never guess.";

    $user = "TOPIC: {$input['topic']}\n\n{$srcBlock}\nReturn JSON exactly in this shape:\n{\n \"title\": \"page H1\",\n \"title_tag\": \"50-60 chars\",\n \"meta_desc\": \"120-132 chars\",\n \"summary\": \"answer-first 120-420 chars\",\n \"lifecycle\": \"ongoing|resolved|dormant\",\n \"mood\": \"conflict|scandal|sad|funny|hype|neutral (the story's emotional register)\",\n \"cover_big\": \"2-4 word cover headline\",\n \"cover_sub\": \"short subtitle\",\n \"people\": [\"Real Full Name\"],\n \"background\": [\"para1\",\"para2\"],\n \"events\": [{\"date\":\"YYYY-MM-DD, or YYYY-MM-00 when the sources give only a month, or YYYY-00-00 when they give only a year — NEVER invent a day the sources do not state\",\"title\":\"...\",\"desc\":\"...\",\"source_nums\":[1],\"is_confirmed\":1}],\n \"faqs\": [{\"q\":\"...\",\"a\":\"...\"}],\n \"why_it_matters\": \"2-3 sentences\",\n \"whats_next\": [{\"date\":\"same format as event dates, or empty\",\"text\":\"...\"}]\n}";
    return [$sys, $user];
}

/**
 * draft_drama: $input = [
 *   'topic'   => working title / angle,
 *   'sources' => [ ['url'=>, 'publisher'=>, 'date'=>YYYY-MM-DD, 'excerpt'=> text], ... ]  (>= 2)
 * ]
 * Returns ['page_id'=>..,'slug'=>..] or ['error'=>..].
 */
function draft_drama(array $input): array {
    // TIMELINE LANES (2026-08-31, owner: "put the gaming news under /gaming/").
    // The story engine used to hard-code "/drama/$slug/" as the URL, which is
    // why gaming news had no home: /gaming/ held only dictionary entries, and a
    // story could not be published anywhere else. The lane travels with the
    // candidate (fetch_sources_for_candidate reads it from the feed it came
    // from) and timeline_url() is the single source of truth for the path.
    require_once __DIR__ . '/lanes.php';
    $__lane = in_array(($input['lane'] ?? 'drama'), array_keys(timeline_lanes()), true)
            ? ($input['lane'] ?? 'drama') : 'drama';
    if (empty($input['topic']) || count($input['sources'] ?? []) < 2) {
        return ['error' => 'need a topic and >= 2 sources with excerpts'];
    }

    [$sys, $user] = draft_drama_prompt($input);

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], AI_WRITER_ORDER, 0.3, 120, AI_WRITER_SKIP);
    if (isset($res['error'])) return $res;

    $j = ai_json($res['content']);
    if (!$j) return ['error' => 'model did not return valid JSON', 'raw' => substr($res['content'], 0, 400)];
    foreach (['title','title_tag','meta_desc','summary','events','faqs'] as $k) {
        if (empty($j[$k])) return ['error' => "draft missing field: $k"];
    }

    // AUTO-REPAIR: models routinely overshoot exact char limits. One cheap
    // corrective call per off-spec field; hard-trim as a final fallback.
    $fix = function (string $text, string $what, int $min, int $max) {
        $len = mb_strlen($text);
        if ($len >= $min && $len <= $max) return $text;
        $r = ai_chat([
            ['role' => 'system', 'content' => "Rewrite the {$what} to be between {$min} and {$max} characters. Keep the same facts and neutral tone. Reply with ONLY the rewritten text, no quotes, no commentary."],
            ['role' => 'user',   'content' => $text],
        ], AI_WRITER_ORDER, 0.2, 120, AI_WRITER_SKIP);
        // r153: never store a reasoning model's notes as page text (see ai_text in ai.php).
        $clean = isset($r['content']) ? ai_text((string)$r['content']) : null;
        $out = $clean ?? $text;
        $l = mb_strlen($out);
        if ($l > $max) $out = truncate_words($out, $max);                       // word-safe: no "...and Un." mid-word cut
        if (mb_strlen($out) < $min && mb_strlen($text) >= $min) $out = truncate_words($text, $max);
        return $out;
    };
    $j['title_tag'] = $fix($j['title_tag'], 'title tag', 40, 60);
    $j['meta_desc'] = meta_tidy($fix($j['meta_desc'], 'meta description', 120, 132));
    $j['summary']   = $fix($j['summary'], 'answer-first summary', 120, 420);

    $pdo  = db();
    // Why it matters / what happens next, kept only where the sources support them.
    // The columns are ensured here, before the transaction below (an ALTER commits it).
    require_once __DIR__ . '/story_context.php';
    story_context_install($pdo);
    $ctx = story_context_from_draft($j, $input['sources'], (string)$input['topic']);
    $slug = draft_slugify($j['title']);
    // WHERE THE DUPLICATES WERE BORN. This used to read:
    //     if ($dup->fetch()) $slug .= '-' . date('Y');
    // It saw the collision and worked around it, minting a SECOND page for a
    // story the site already had. Measured 2026-08-25: 60 of 550 drama pages
    // were redundant, 16 of them published, and one Twitch story existed 15
    // times over. Those copies can never pass the editor's 'intent' score - a
    // second telling cannot answer a search better than the first - so they sat
    // in review being re-judged every hour forever.
    // Now it refuses. The wording matters: the tick already retires a candidate
    // whose error contains 'already exists', so this reuses that path rather
    // than adding one.
    $dup  = $pdo->prepare("SELECT id, slug FROM pages WHERE slug=?");
    $dup->execute([$slug]);
    if ($hit = $dup->fetch(PDO::FETCH_ASSOC)) {
        return ['error' => 'a page for slug "' . $slug . '" already exists'];
    }
    try {
        require_once __DIR__ . '/dedupe.php';
        if ($twin = dup_twin_for_slug($pdo, $slug, 'drama')) {
            return ['error' => 'near-duplicate of existing page "' . $twin['slug']
                             . '" (' . $twin['status'] . ') already exists'];
        }
    } catch (Throwable $e) { error_log('draft twin check: ' . $e->getMessage()); }

    // branded SVG cover is the guaranteed default
    $cover = draft_make_cover($slug, $j['cover_big'] ?? mb_substr($j['title'], 0, 18), $j['cover_sub'] ?? 'the timeline, explained');
    $credit = null; $credit_url = null;
    $mood = in_array($j['mood'] ?? '', ['conflict','scandal','sad','funny','hype','neutral']) ? $j['mood'] : 'neutral';
    // try to upgrade to REAL faces (free-licensed), emotionally matched to the story
    require_once __DIR__ . '/images.php';
    $people = [];
    foreach (($input['people'] ?? []) as $pp) if (str_contains(trim($pp), ' ')) $people[] = trim($pp);
    // FALLBACK (fix 2026-06-14): the candidate verdict stopped carrying people,
    // so derive them from the DRAFT itself — the AI reads the sources and names
    // the real public figures. Without this, dramas lose their real-face images.
    if (!$people) foreach (($j['people'] ?? []) as $pp) {
        $pp = trim((string)$pp);
        if (str_contains($pp, ' ') && !in_array($pp, $people, true)) $people[] = $pp;
    }
    // UNIFIED SMART IMAGES (2026-06-14): ONE engine for dramas. Verified creator
    // faces (VS-card for feuds / single face) + GIPHY of the people = 3-4 real
    // images, same quality as term pages. Unverifiable/wrong people -> branded
    // card (never a guessed face like a 1550 "John Davis"). card == hero.
    try {
        // PRIMARY (2026-06-17): context-aware multi-source picker. Searches the
        // full STORY (not just the name) -> pools verified faces + event thumbnails
        // -> vision picks the one showing the RIGHT people/event -> branded if none
        // safely fit. This is what fixed the wrong-person problem (Alicia, Ben S.).
        require_once __DIR__ . '/drama_image.php';
        $di = drama_image_smart($slug, $j['title'], $j['summary'] ?? '', $mood);
        // FALLBACK: legacy creator-face/GIPHY engine, only if smart found nothing.
        if (!$di) {
            require_once __DIR__ . '/image_beast.php';
            $di = drama_images($people, $input['topic'] ?? $j['title'], $slug, $j['summary'] ?? '', $mood);
        }
        if ($di) {
            $cover = $di['img']; $credit = $di['credit']; $credit_url = $di['credit_url'];
            $GLOBALS['__drama_featured'] = $cover;
        }
    } catch (Throwable $e) { /* branded card stays */ }

    // r151: source URLs to archive once the story is safely committed (see below).
    $toArchive = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO pages (type,slug,path,h1,title_tag,meta_desc,summary,status,robots,author_id,cover,cover_credit,cover_credit_url,published_at,updated_at)
                       VALUES ('drama',?,?,?,?,?,?,'draft','noindex',1,?,?,?,NOW(),NOW())")
            ->execute([$slug, timeline_url($slug, $__lane), $j['title'], $j['title_tag'], $j['meta_desc'], $j['summary'], $cover, $credit, $credit_url]);
        $pageId = (int)$pdo->lastInsertId();
        if (!empty($GLOBALS['__drama_featured'])) {
            $pdo->prepare("UPDATE pages SET featured_img=? WHERE id=?")->execute([$GLOBALS['__drama_featured'], $pageId]);
            unset($GLOBALS['__drama_featured']);
        }

        $pdo->prepare("INSERT INTO dramas (page_id,title,lifecycle,started_on,primary_kw,background,mood,lane,why_matters,whats_next)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $pageId, $j['title'],
                in_array($j['lifecycle'] ?? '', ['ongoing','resolved','dormant']) ? $j['lifecycle'] : 'ongoing',
                $j['events'][0]['date'] ?? null,
                mb_substr(strtolower($input['topic']), 0, 180),
                json_encode($j['background'] ?? [], JSON_UNESCAPED_UNICODE),
                $mood,
                $__lane,
                $ctx['why'] !== '' ? $ctx['why'] : null,
                $ctx['next'] ? json_encode($ctx['next'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        $dramaId = (int)$pdo->lastInsertId();

        // sources: insert provided sources, remember mapping source_num -> id
        $map = [];
        foreach ($input['sources'] as $i => $s) {
            $pdo->prepare("INSERT INTO sources (url,domain,publisher,title,reliability,retrieved_on,excerpt) VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $s['url'],
                    parse_url($s['url'], PHP_URL_HOST) ?: null,
                    $s['publisher'] ?? null,
                    mb_substr($s['excerpt'], 0, 200),
                    $s['reliability'] ?? 'primary',
                    ($s['date'] ?? '') !== '' ? $s['date'] : date('Y-m-d'),   // undated source: the day we fetched it
                    $s['excerpt'],
                ]);
            $map[$i + 1] = (int)$pdo->lastInsertId();
            // r93 ARCHIVE AT CAPTURE. This is the path that matters most: the
            // drafter hands over source URLs, and on 2026-08-10 thirteen of
            // those on published pages turned out to point at articles that
            // had moved or never existed at that address. Fetching each one
            // now both keeps a copy and makes an invented URL fail LOUDLY at
            // write time — you cannot archive a page that is not there.
            //
            // r151 (2026-09-10): the fetch now runs AFTER commit. Called here it ran
            // sa_install()'s CREATE TABLE inside this transaction, and on MariaDB a
            // CREATE TABLE silently commits whatever transaction is open. commit()
            // then threw "There is no active transaction", the bare rollBack() threw
            // a second time, nothing caught it, and the process died: every drama
            // drafted since 2026-08-10 lost its embed step (157 pages before, 31 with
            // embeds; 299 after, 0) and every hourly run that wrote a story ended
            // without reaching "tick done" (15 of 103 runs finished).
            $toArchive[] = [$map[$i + 1], (string)$s['url']];
        }

        $order = 1;
        foreach ($j['events'] as $ev) {
            $srcId = null;
            foreach (($ev['source_nums'] ?? []) as $n) { if (isset($map[$n])) { $srcId = $map[$n]; break; } }
            $pdo->prepare("INSERT INTO events (drama_id,event_date,title,description,source_id,is_confirmed,sort_order)
                           VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $dramaId,
                    $ev['date'] ?? date('Y-m-d'),
                    mb_substr($ev['title'] ?? '', 0, 250),
                    $ev['desc'] ?? '',
                    $srcId,
                    // ============================================================
                    // SEO-BATCH-1 (owner decision 2026-08-04): is_confirmed IS
                    // NEVER MODEL-SET. This is a legal control, not an SEO one.
                    //
                    // It used to be (int)($ev['is_confirmed'] ?? 0) — taken
                    // straight from the drafting model's own JSON. gate.php's
                    // defamation shield only inspects `WHERE is_confirmed=0`, so
                    // the model was deciding which allegations about real, named
                    // people counted as settled fact and therefore needed no
                    // "allegedly" framing. It had decided that for 1,970 of
                    // 2,227 events — 88% of the corpus was outside the shield.
                    //
                    // Now every event is hedged by default and the shield covers
                    // 100%. Promotion to 1 is a separate, audited action
                    // (see events.confirmed_by / confirmed_at) and requires a
                    // PRIMARY source — a court filing, an official statement, or
                    // the named person's own account. An aggregator can never
                    // promote a claim about a real person.
                    // ============================================================
                    0,
                    $order++,
                ]);
        }

        $fo = 1;
        foreach ($j['faqs'] as $f) {
            $pdo->prepare("INSERT INTO faqs (drama_id,question,answer,sort_order) VALUES (?,?,?,?)")
                ->execute([$dramaId, mb_substr($f['q'], 0, 250), $f['a'], $fo++]);
        }

        $pdo->commit();
        // 2026-09-24 the timeline reads oldest first whatever order the model wrote it in
        try { require_once __DIR__ . '/timeline_order.php'; events_resort($pdo, $dramaId); } catch (Throwable $e) { error_log('draft_drama resort: ' . $e->getMessage()); }
    } catch (Throwable $e) {
        // r151: the guard draft_term.php already had. A bare rollBack() on a
        // transaction the server already ended throws a SECOND exception.
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
        return ['error' => 'db insert failed: ' . $e->getMessage()];
    }

    // r93 archive-at-capture, moved out of the transaction by r151 (see above).
    // Same behaviour: keep a copy of every source, shout when one is dead.
    if ($toArchive) {
        require_once __DIR__ . '/source_archive.php';
        foreach ($toArchive as [$archSrcId, $archUrl]) {
            try {
                [$st, $note] = sa_capture($pdo, $archSrcId, $archUrl);
                if ($st === 'dead') error_log("SOURCE DEAD AT WRITE TIME ({$note}): {$archUrl}");
            } catch (Throwable $e) { /* never block drafting on the archive */ }
        }
    }

    // turn social-post sources into real embeds (cached in DB, lazy-rendered)
    require_once __DIR__ . '/embeds.php';
    // r151: this stage failed invisibly for five weeks. Report what it did.
    $embedStat = ['embeds' => 0, 'not_embeddable' => 0];
    try { $embedStat = embeds_build_for_drama($dramaId); }
    catch (Throwable $e) { error_log("draft_drama: embed build failed for drama {$dramaId}: " . $e->getMessage()); }
    // r157: then the real posts the cited articles embed (a news-only story showed none, 3% of September events)
    try { $embedStat['embeds'] += (int)(embeds_from_cited_articles($dramaId)['embeds'] ?? 0); }
    catch (Throwable $e) { error_log("draft_drama: article posts failed for drama {$dramaId}: " . $e->getMessage()); }

    ai_log($pageId, 'draft', $res, ['fields' => array_keys($j), 'events' => count($j['events'])], true);
    return ['page_id' => $pageId, 'slug' => $slug, 'events' => count($j['events']), 'provider' => $res['provider'],
            'embeds' => (int)($embedStat['embeds'] ?? 0),
            'context' => ['why' => $ctx['why'] !== '', 'next' => count($ctx['next']), 'dropped' => $ctx['dropped']]];
}
