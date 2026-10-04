<?php
// GenZHype | THE PERMANENT TEST SET (owner 2026-09-27: "for each rule, add a test case to the permanent test set that
// fails before the change and passes after"; #9: a permanent test set so the pipeline does not break silently).
// Each case builds a small made-up page in the database (slug zz-ruletest-*, never open to Google, removed right after
// the case), puts it through the same code the pipeline and the site use, and checks what a reader or Google would get.
// A 'fix' case proves a rule fires; a 'guard' case proves it stays quiet where it should (a false alarm is a bug too).
// Run: php app/cli.php ruletest. Every run is kept in storage/ruletest/.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/accuracy.php';
require_once __DIR__ . '/human_review.php';
require_once __DIR__ . '/repo.php';

const RT_PREFIX = 'zz-ruletest-';

function rt_day(int $daysAgo): string { return gmdate('Y-m-d', time() - $daysAgo * 86400); }

/**
 * A made-up story page, [page id]. $o: h1, title_tag, meta, summary, lifecycle, lane, why, verdict, next, people,
 * excerpt (extra source words), events [[days ago, title, description, source url], ...].
 */
function rt_story(PDO $pdo, string $key, array $o): int {
    $slug = RT_PREFIX . $key;
    $h1 = $o['h1'] ?? 'Alpha and Beta clash over a sponsorship deal';
    $pdo->prepare("INSERT INTO pages (type,slug,path,h1,title_tag,meta_desc,summary,status,robots,published_at,updated_at)
                   VALUES ('drama',?,?,?,?,?,?,'draft','noindex',?,NOW())")
        ->execute([$slug, "/drama/{$slug}/", $h1, $o['title_tag'] ?? mb_substr($h1, 0, 60),
                   $o['meta'] ?? 'Alpha and Beta disagree over who broke a sponsorship deal. Every dated step with its source, from the first post to the latest reply.',
                   $o['summary'] ?? 'Alpha said Beta broke their joint sponsorship deal, and Beta disputed it in a video reply, according to Dexerto. Here is every dated step.',
                   $o['published_at'] ?? gmdate('Y-m-d H:i:s')]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO dramas (page_id,title,lifecycle,background,people_json,lane,why_matters,whats_next,verdict) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$pid, $h1, $o['lifecycle'] ?? 'ongoing', json_encode($o['background'] ?? ['Alpha and Beta are streamers who signed a joint sponsorship deal in 2025.']),
                   json_encode($o['people'] ?? [['name' => 'Alpha'], ['name' => 'Beta']]), $o['lane'] ?? 'drama', $o['why'] ?? null,
                   json_encode($o['next'] ?? []), isset($o['verdict']) ? json_encode($o['verdict']) : null]);
    $did = (int)$pdo->lastInsertId();
    $src = $pdo->prepare("INSERT INTO sources (url,domain,publisher,title,reliability,retrieved_on,excerpt,published_on) VALUES (?,?,?,?,?,CURDATE(),?,?)");
    $ev = $pdo->prepare("INSERT INTO events (drama_id,event_date,title,description,source_id,is_confirmed,sort_order) VALUES (?,?,?,?,?,0,?)");
    $events = $o['events'] ?? [[20, 'Alpha posts about the deal', 'Alpha said Beta broke the deal, according to Dexerto.', 'https://www.dexerto.com/zz-ruletest/a']];
    foreach ($events as $i => [$ago, $title, $desc, $url]) {
        $host = preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
        $social = (bool)preg_match('/(^|\.)(x|twitter|tiktok|youtube|reddit|instagram)\.com$/', $host);
        $src->execute([$url, $host, $social ? 'X (original post)' : ucfirst(explode('.', $host)[0]), $title, $social ? 'primary' : 'reliable_outlet',
                       $desc . ' ' . ($o['excerpt'] ?? ''), rt_day($ago)]);
        $ev->execute([$did, rt_day($ago), $title, $desc, (int)$pdo->lastInsertId(), $i]);
    }
    // a passing fact check, so the rule under test is the only thing that can hold the page
    $pdo->prepare("INSERT INTO ai_reviews (page_id, stage, provider, model, verdict, passed) VALUES (?, 'verify', 'gemini', 'ruletest', '{}', 1)")->execute([$pid]);
    return $pid;
}

/** A made-up term page, [page id]. $o: lane, term, status, published_at, redirect_to, short_def, original (bool), demand (views a day),
 *  origin_date, citations, meaning. */
function rt_term(PDO $pdo, string $key, array $o): int {
    $slug = RT_PREFIX . $key;
    $lane = $o['lane'] ?? 'slang';
    $term = $o['term'] ?? 'zz ruletest word';
    $pdo->prepare("INSERT INTO pages (type,slug,path,h1,title_tag,meta_desc,summary,status,robots,published_at,updated_at,redirect_to)
                   VALUES ('term',?,?,?,?,?,?,?,'noindex',?,NOW(),?)")
        ->execute([$slug, "/{$lane}/{$slug}/", "What does {$term} mean?", "{$term} meaning", "What {$term} means.", "{$term} means a made-up test word.",
                   $o['status'] ?? 'draft', $o['published_at'] ?? gmdate('Y-m-d H:i:s'), $o['redirect_to'] ?? null]);
    $pid = (int)$pdo->lastInsertId();
    // by default a term that keeps its own page (an origin post and 50 views a day), so rule 4 leaves it alone
    $pdo->prepare("INSERT INTO terms (page_id, lane, term, short_def, citations, origin_url, origin_type, origin_date, meaning, demand_views, demand_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_DATE())")
        ->execute([$pid, $lane, $term, $o['short_def'] ?? 'A made-up word the test set uses.', json_encode($o['citations'] ?? []),
                   ($o['original'] ?? true) ? 'https://x.com/zzruletest/status/9' : null, ($o['original'] ?? true) ? 'social_post' : null,
                   $o['origin_date'] ?? null, json_encode($o['meaning'] ?? ['A made-up word the test set uses, for testing only.']), $o['demand'] ?? 50]);
    return $pid;
}

/** Everything a test made, gone (only rows the test set made: zz-ruletest pages and sources). */
function rt_cleanup(PDO $pdo): int {
    $n = 0;
    foreach ($pdo->query("SELECT id FROM pages WHERE slug LIKE 'zz-ruletest-%'")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $pid = (int)$pid;
        foreach ($pdo->query("SELECT id FROM dramas WHERE page_id={$pid}")->fetchAll(PDO::FETCH_COLUMN) as $did) {
            $pdo->exec("DELETE FROM events WHERE drama_id=" . (int)$did);
            $pdo->exec("DELETE FROM faqs WHERE drama_id=" . (int)$did);
        }
        foreach (['dramas', 'terms', 'ai_reviews'] as $t) $pdo->exec("DELETE FROM {$t} WHERE page_id={$pid}");
        if ($pdo->query("SHOW TABLES LIKE 'laya_log'")->fetchColumn()) $pdo->exec("DELETE FROM laya_log WHERE page_id={$pid}");   // a test page's log rows
        $pdo->exec("DELETE FROM pages WHERE id={$pid}");
        $n++;
    }
    $pdo->exec("DELETE FROM sources WHERE url LIKE '%zz-ruletest%' OR url LIKE '%zzruletest%'");
    return $n;
}

/** A page's hold reasons that come from one rule ('rule 5: ...'). */
function rt_rule_reasons(PDO $pdo, int $pid, int $rule): array {
    return array_values(preg_grep('/^rule ' . $rule . ':/', acc_hard_fails($pdo, $pid, false)));
}

/** The status badge a reader sees on a story page. */
function rt_badge(int $pid): string {
    preg_match('#<span class="status[^"]*">(.*?)</span>#s', (string)render_page_html($pid), $m);
    return trim(html_entity_decode(strip_tags($m[1] ?? '')));
}

/** "The short version" a reader sees on a story page. */
function rt_tldr(int $pid): string {
    preg_match('#<aside class="tldr"[^>]*>.*?<p>(.*?)</p>#s', (string)render_page_html($pid), $m);
    return trim(html_entity_decode(strip_tags($m[1] ?? '')));
}

/** A function the rule needs does not exist yet: the case fails, and says so. */
function rt_need(string $fn): void {
    if (!function_exists($fn)) throw new RuntimeException("no code check yet ({$fn} does not exist)");
}

/** The glossary page that holds an entry, as a reader sees it (25 entries a page since 2026-09-29; a "zz" test word
    sorts last, so it is on the last page, not the first). */
function rt_glossary_html(string $lane, string $slug): string {
    $g = repo_glossary($lane);
    $i = array_search($slug, array_column($g['entries'], 'slug'), true);
    $p = $i === false ? 1 : intdiv($i, LIST_PER_PAGE) + 1;
    $had = $_GET['page'] ?? null;
    if ($p > 1) $_GET['page'] = (string)$p; else unset($_GET['page']);
    try { return view('glossary', ['g' => $g]); }
    finally { if ($had === null) unset($_GET['page']); else $_GET['page'] = $had; }
}

/** [rule, id, 'fix'|'guard', what it checks, fn(PDO): [passed, what happened]]. */
function rt_cases(): array {
    $over = [[40, 'Alpha posts about the deal', 'Alpha said Beta broke the deal, according to Dexerto.', 'https://www.dexerto.com/zz-ruletest/a'],
             [25, 'Beta replies in a video', 'Beta said Alpha misread the contract, according to Dexerto.', 'https://www.dexerto.com/zz-ruletest/b'],
             [20, 'The sponsor comments', 'The sponsor said it was reviewing the deal, according to Kotaku.', 'https://kotaku.com/zz-ruletest/c']];
    $grave = ['h1' => 'Delta arrested after a convention fight, police say', 'title_tag' => 'Delta arrested after a convention fight, police say',
              'summary' => 'Police arrested streamer Delta after a fight at a gaming convention on the second day of the event, according to Dexerto. Delta has not commented.',
              'people' => [['name' => 'Delta', 'sameAs' => ['https://x.com/zzruletestdelta']]]];
    $graveEv = fn(string $url, string $desc) => [[3, 'Police arrest Delta at the convention', 'Police arrested Delta at the convention, according to Dexerto.', 'https://www.dexerto.com/zz-ruletest/g1'],
                                                   [2, 'An accusation about the fight', $desc, $url]];
    $hr = fn(string $t) => implode('; ', hr_reasons($t, '', ''));
    return [
        // RULE 1: the status matches today
        [1, 'badge-over', 'fix', 'A story whose last event was 20 days ago, with nothing ahead, is not labelled Ongoing or Developing', function (PDO $pdo) use ($over) {
            $b = rt_badge(rt_story($pdo, 'r1-badge', ['lifecycle' => 'developing', 'events' => $over]));
            return [$b !== '' && !preg_match('/\b(ongoing|developing)\b/i', $b), "the badge says \"{$b}\""];
        }],
        [1, 'badge-live', 'guard', 'A story with an event 2 days ago still says Ongoing', function (PDO $pdo) {
            $b = rt_badge(rt_story($pdo, 'r1-live', ['events' => [[2, 'Alpha posts about the deal', 'Alpha said Beta broke the deal, according to Dexerto.', 'https://www.dexerto.com/zz-ruletest/a']]]));
            return [(bool)preg_match('/\bongoing\b/i', $b), "the badge says \"{$b}\""];
        }],
        [1, 'text-over', 'fix', 'A summary saying "remains ongoing" on a story with nothing new in 20 days is caught', function (PDO $pdo) use ($over) {
            $pid = rt_story($pdo, 'r1-text', ['events' => $over, 'summary' => 'Alpha said Beta broke their joint sponsorship deal and Beta disputed it, according to Dexerto. As of ' . gmdate('F j, Y') . ', the feud remains ongoing with no resolution.']);
            $r = rt_rule_reasons($pdo, $pid, 1);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [1, 'text-fixed', 'fix', 'The pipeline rewrites that status sentence from the dates (no AI)', function (PDO $pdo) use ($over) {
            rt_need('rules_fix_page');
            $pid = rt_story($pdo, 'r1-fix', ['events' => $over, 'summary' => 'Alpha said Beta broke their joint sponsorship deal and Beta disputed it, according to Dexerto. As of ' . gmdate('F j, Y') . ', the feud remains ongoing with no resolution.']);
            rules_fix_page($pdo, $pid);
            $s = (string)$pdo->query("SELECT summary FROM pages WHERE id={$pid}")->fetchColumn();
            return [!preg_match('/\b(ongoing|developing)\b/i', $s) && rt_rule_reasons($pdo, $pid, 1) === [], "summary now: \"" . mb_substr($s, -110) . '"'];
        }],
        [1, 'announces-past', 'fix', 'A title saying "announces" about something that already happened is caught', function (PDO $pdo) {
            $pid = rt_story($pdo, 'r1-ann', ['h1' => 'Nintendo announces two Direct presentations for September', 'lane' => 'gaming',
                'events' => [[30, 'Nintendo announces two Directs', 'Nintendo said it would hold two Direct presentations, according to IGN.', 'https://www.ign.com/zz-ruletest/n1'],
                             [21, 'The first Direct airs', 'The first Direct aired with Zelda news, according to IGN.', 'https://www.ign.com/zz-ruletest/n2']],
                'next' => [['date' => rt_day(20), 'text' => 'The second Direct airs.']]]);
            $r = rt_rule_reasons($pdo, $pid, 1);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [1, 'announces-fresh', 'guard', '"Announces" stays allowed on news from yesterday with the thing still ahead', function (PDO $pdo) {
            $pid = rt_story($pdo, 'r1-ann2', ['h1' => 'Nintendo announces a Direct for next week', 'lane' => 'gaming',
                'events' => [[1, 'Nintendo announces a Direct', 'Nintendo said it would hold a Direct next week, according to IGN.', 'https://www.ign.com/zz-ruletest/n3']],
                'next' => [['date' => gmdate('Y-m-d', time() + 5 * 86400), 'text' => 'The Direct airs.']]]);
            $r = rt_rule_reasons($pdo, $pid, 1);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],
        [1, 'event-tense', 'fix', 'A past timeline entry titled "Nintendo announces ..." is put in the past tense', function (PDO $pdo) {
            rt_need('rules_fix_page');
            $pid = rt_story($pdo, 'r1-tense', ['events' => [[30, 'Nintendo announces two Directs', 'Nintendo said it would hold two Directs, according to IGN.', 'https://www.ign.com/zz-ruletest/n1']]]);
            rules_fix_page($pdo, $pid);
            $t = (string)$pdo->query("SELECT e.title FROM events e JOIN dramas d ON d.id=e.drama_id WHERE d.page_id={$pid}")->fetchColumn();
            return [$t === 'Nintendo announced two Directs', "the entry says \"{$t}\""];
        }],

        [1, 'asof-today', 'fix', 'A status stamped with today\'s date becomes the latest development, dated by its report (2026-09-28)', function (PDO $pdo) use ($over) {
            $t = rt_tldr(rt_story($pdo, 'r1-asof', ['events' => $over, 'summary' => 'Alpha said Beta broke their joint sponsorship deal and Beta disputed it, according to Dexerto. As of ' . gmdate('F j, Y') . ', the feud remains unresolved.']));
            return [!str_contains($t, 'As of ' . gmdate('F j, Y')) && str_contains($t, 'reported by'), "the reader sees: \"" . mb_substr($t, -120) . '"'];
        }],
        [1, 'asof-sourced', 'guard', 'A status dated by its source and naming it stays', function (PDO $pdo) use ($over) {
            $asOf = 'As of ' . date('F j, Y', strtotime(rt_day(20))) . ', per Kotaku, the sponsor was still reviewing the deal.';
            $t = rt_tldr(rt_story($pdo, 'r1-asof2', ['events' => $over, 'summary' => 'Alpha said Beta broke their joint sponsorship deal and Beta disputed it, according to Dexerto. ' . $asOf]));
            return [str_contains($t, $asOf), "the reader sees: \"" . mb_substr($t, -120) . '"'];
        }],
        [1, 'asof-stored', 'fix', 'The pipeline replaces a today-stamped status in the stored page too (no AI)', function (PDO $pdo) use ($over) {
            rt_need('rules_fix_page');
            $pid = rt_story($pdo, 'r1-asof3', ['events' => $over, 'summary' => 'Alpha said Beta broke their joint sponsorship deal and Beta disputed it, according to Dexerto. As of ' . gmdate('F j, Y') . ', the feud remains unresolved.']);
            rules_fix_page($pdo, $pid);
            $sm = (string)$pdo->query("SELECT summary FROM pages WHERE id={$pid}")->fetchColumn();
            return [!str_contains($sm, 'As of ' . gmdate('F j, Y')) && str_contains($sm, 'reported by'), "stored: \"" . mb_substr($sm, -120) . '"'];
        }],
        [1, 'asof-description', 'fix', 'A description that stamps a status with today\'s date and credits "the court" is caught', function (PDO $pdo) use ($over) {
            $pid = rt_story($pdo, 'r1-asof4', ['events' => $over, 'meta' => 'As of ' . gmdate('F j, Y') . ', the court says Alpha remains banned from the sponsorship program and Beta has not replied.']);
            $r = rt_rule_reasons($pdo, $pid, 1);
            return [(bool)preg_grep('/description/', $r), $r ? $r[0] : 'nothing caught it'];
        }],

        [1, 'summary-floor', 'fix', 'Removing unsupported sentences never leaves an empty summary: it is filled from the timeline (2026-09-28)', function (PDO $pdo) use ($over) {
            $sum = 'Alpha was banned from the sponsorship program for breaking the rules. As of ' . gmdate('F j, Y') . ', Alpha remains banned and has not appealed.';
            $pid = rt_story($pdo, 'r1-floor', ['events' => $over, 'summary' => $sum]);
            acc_remove($pdo, $pid, [['section' => 'summary', 'sentence' => 'Alpha was banned from the sponsorship program for breaking the rules.'], ['section' => 'summary', 'sentence' => 'As of ' . gmdate('F j, Y') . ', Alpha remains banned and has not appealed.']]);
            $sm = (string)$pdo->query("SELECT summary FROM pages WHERE id={$pid}")->fetchColumn();
            return [mb_strlen($sm) >= 80 && str_contains($sm, 'reported by') && !str_contains($sm, 'remains banned'), "summary now: \"" . mb_substr($sm, 0, 160) . '"'];
        }],

        // RULE 2: the publish date never changes
        [2, 'publish-again', 'fix', 'Publishing a live page again keeps its first publish date', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r2-pub', ['status' => 'published', 'published_at' => '2026-09-01 10:00:00']);
            ob_start(); page_publish_live($pdo, $pid); ob_end_clean();
            $d = (string)$pdo->query("SELECT published_at FROM pages WHERE id={$pid}")->fetchColumn();
            return [$d === '2026-09-01 10:00:00', "published date after: {$d}"];
        }],
        [2, 'publish-locked', 'fix', 'No code path can move it: a direct database change is refused', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r2-lock', ['status' => 'published', 'published_at' => '2026-09-01 10:00:00']);
            $pdo->exec("UPDATE pages SET published_at=NOW() WHERE id={$pid}");
            $d = (string)$pdo->query("SELECT published_at FROM pages WHERE id={$pid}")->fetchColumn();
            return [$d === '2026-09-01 10:00:00', "published date after: {$d}"];
        }],
        [2, 'first-publish', 'guard', 'A page going live for the first time gets today as its publish date', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r2-first', ['status' => 'draft', 'published_at' => '2026-09-01 10:00:00']);
            ob_start(); page_publish_live($pdo, $pid); ob_end_clean();
            $d = (string)$pdo->query("SELECT published_at FROM pages WHERE id={$pid}")->fetchColumn();
            return [substr($d, 0, 10) === gmdate('Y-m-d') || substr($d, 0, 10) === date('Y-m-d'), "published date: {$d}"];
        }],
        [2, 'redo-updated', 'fix', 'A redo moves "Updated" and nothing else', function (PDO $pdo) {
            rt_need('page_redone');
            $pid = rt_story($pdo, 'r2-redo', ['published_at' => '2026-09-01 10:00:00']);
            $pdo->exec("UPDATE pages SET status='published' WHERE id={$pid}");
            page_redone($pdo, $pid);
            $r = $pdo->query("SELECT published_at, content_updated_at FROM pages WHERE id={$pid}")->fetch(PDO::FETCH_ASSOC);
            return [$r['published_at'] === '2026-09-01 10:00:00' && substr((string)$r['content_updated_at'], 0, 10) >= gmdate('Y-m-d', time() - 86400),
                    "published {$r['published_at']}, updated {$r['content_updated_at']}"];
        }],

        // 10 (owner 2026-09-28): a page the site cannot show is never published
        [10, 'broken-not-published', 'fix', 'A page that fails to render is not published', function (PDO $pdo) {
            $pdo->exec("INSERT INTO pages (type,slug,path,h1,title_tag,meta_desc,summary,status,robots) VALUES ('drama','" . RT_PREFIX . "r10-broken','/drama/" . RT_PREFIX . "r10-broken/','x','x','x','x','draft','noindex')");
            $pid = (int)$pdo->lastInsertId();   // a story page with no story behind it: the site answers 404
            ob_start(); page_publish_live($pdo, $pid); ob_end_clean();
            $st = (string)$pdo->query("SELECT status FROM pages WHERE id={$pid}")->fetchColumn();
            return [$st === 'draft', "status after publish: {$st}"];
        }],
        [10, 'draft-preview', 'fix', 'A draft opens in the admin preview through the real front door (it crashed every time)', function (PDO $pdo) {
            rt_need('page_render_check');
            $r = page_render_check(rt_story($pdo, 'r10-preview', []));
            return [$r === '', $r === '' ? 'renders' : $r];
        }],

        // 11 (owner 2026-09-28): old past-event news is rewritten only from a source about the outcome, else held for good
        [11, 'past-news-held', 'fix', 'Past news with no newer source is not redone, and is marked so it is not retried', function (PDO $pdo) {
            rt_need('pr_redo_allowed');
            $pid = rt_story($pdo, 'r11-held', ['h1' => 'Nintendo announces two Direct presentations for September', 'lane' => 'gaming',
                'events' => [[30, 'Nintendo announces two Directs', 'Nintendo said it would hold two Directs, according to IGN.', 'https://www.ign.com/zz-ruletest/n1'],
                             [21, 'The first Direct airs', 'The first Direct aired, according to IGN.', 'https://www.ign.com/zz-ruletest/n2']]]);
            $why = pr_redo_allowed($pdo, $pid, [['date' => rt_day(25)], ['date' => rt_day(40)]]);
            $mark = (string)$pdo->query("SELECT COALESCE(retry_block, '') FROM pages WHERE id={$pid}")->fetchColumn();
            return [$why !== '' && $mark !== '', $why !== '' ? "refused: {$why}" : 'the redo was allowed'];
        }],
        [11, 'past-news-outcome', 'guard', 'Past news with a source newer than its last event may be rewritten as what happened', function (PDO $pdo) {
            rt_need('pr_redo_allowed');
            $pid = rt_story($pdo, 'r11-ok', ['h1' => 'Nintendo announces two Direct presentations for September', 'lane' => 'gaming',
                'events' => [[30, 'Nintendo announces two Directs', 'Nintendo said it would hold two Directs, according to IGN.', 'https://www.ign.com/zz-ruletest/n1'],
                             [21, 'The first Direct airs', 'The first Direct aired, according to IGN.', 'https://www.ign.com/zz-ruletest/n2']]]);
            $why = pr_redo_allowed($pdo, $pid, [['date' => rt_day(3)]]);
            return [$why === '', $why === '' ? 'allowed' : "refused: {$why}"];
        }],
        [11, 'live-story', 'guard', 'A story still moving is redone as usual', function (PDO $pdo) {
            rt_need('pr_redo_allowed');
            $pid = rt_story($pdo, 'r11-live', ['events' => [[2, 'Alpha posts about the deal', 'Alpha said Beta broke the deal, according to Dexerto.', 'https://www.dexerto.com/zz-ruletest/a']]]);
            $why = pr_redo_allowed($pdo, $pid, []);
            return [$why === '', $why === '' ? 'allowed' : "refused: {$why}"];
        }],

        // RULE 3: our take names a fact from the page, or it is dropped
        [3, 'generic-take', 'fix', 'A take with no name or number from the timeline is not shown', function (PDO $pdo) {
            $html = (string)render_page_html(rt_story($pdo, 'r3-gen', ['why' => 'This shows how quickly online disagreements can escalate, and why fans should wait for the full story before picking a side.']));
            return [!str_contains($html, '>Our take<'), str_contains($html, '>Our take<') ? 'the generic take is on the page' : 'no take on the page'];
        }],
        [3, 'specific-take', 'guard', 'A take that names people and facts from the timeline stays', function (PDO $pdo) {
            $html = (string)render_page_html(rt_story($pdo, 'r3-spec', ['why' => "Alpha's post came before any reply from Beta, so for most of this story readers heard only one side of the sponsorship deal."]));
            return [str_contains($html, '>Our take<'), str_contains($html, '>Our take<') ? 'the take is on the page' : 'the take was dropped'];
        }],

        // RULE 4: a plain definition goes to the glossary
        [4, 'route-plain', 'fix', 'A term with no original part goes to the glossary, even with demand', function (PDO $pdo) {
            rt_need('term_route');
            $r = term_route(false, 50.0);
            return [$r === 'glossary', "routed to: {$r}"];
        }],
        [4, 'route-no-demand', 'fix', 'A term with an original part but under 10 views a day goes to the glossary', function (PDO $pdo) {
            rt_need('term_route');
            $r = term_route(true, 2.0);
            return [$r === 'glossary', "routed to: {$r}"];
        }],
        [4, 'route-keep', 'guard', 'A term with an original part AND real demand keeps its own page', function (PDO $pdo) {
            rt_need('term_route');
            $r = term_route(true, 40.0);
            return [$r === 'page', "routed to: {$r}"];
        }],
        [4, 'publish-folds', 'fix', 'A plain definition sent to publish lands in the glossary, not on a page of its own', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r4-door', ['original' => false]);
            ob_start(); page_publish_live($pdo, $pid); ob_end_clean();
            $r = $pdo->query("SELECT status, redirect_to FROM pages WHERE id={$pid}")->fetch(PDO::FETCH_ASSOC);
            return [$r['status'] === 'archived' && str_starts_with((string)$r['redirect_to'], '/slang/glossary/#'), "status {$r['status']}, goes to " . ($r['redirect_to'] ?? 'nowhere')];
        }],
        // owner 2026-09-28: new beats demand; posts are "seen in use"; each label on its own link; why-now lines
        [4, 'new-term', 'fix', 'A term first seen 20 days ago gets its own page, whatever its Wikipedia views', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r4-new', ['original' => false, 'demand' => 0, 'origin_date' => rt_day(20)]);
            $r = term_route_page($pdo, $pid);
            return [$r === 'page', "routed to: {$r}"];
        }],
        [4, 'rising-posts', 'fix', 'A term in 3 different posts with 10k+ views each in the last 2 weeks gets its own page', function (PDO $pdo) {
            $posts = [];
            foreach (['alpha', 'beta', 'gamma'] as $i => $h) $posts[] = ['platform' => 'TikTok', 'handle' => "@zzruletest{$h}", 'publication' => '', 'date' => rt_day(3 + $i), 'url' => "https://www.tiktok.com/@zzruletest{$h}/video/{$i}", 'views' => 25000, 'quote' => 'zz ruletest word in use'];
            $pid = rt_term($pdo, 'r4-rising', ['original' => false, 'demand' => 0, 'origin_date' => '2019', 'citations' => $posts]);
            $r = term_route_page($pdo, $pid);
            return [$r === 'page', "routed to: {$r}"];
        }],
        [4, 'comments-not-rising', 'guard', '3 recent YouTube comments are "seen in use", not a trend: the term stays in the glossary', function (PDO $pdo) {
            $posts = [];
            foreach (['a', 'b', 'c'] as $i => $h) $posts[] = ['platform' => 'YouTube', 'handle' => "@zzruletestc{$h}", 'date' => rt_day(2 + $i), 'url' => "https://www.youtube.com/watch?v=zzruletest&lc=k{$i}", 'quote' => 'zz ruletest word'];
            $pid = rt_term($pdo, 'r4-comments', ['original' => false, 'demand' => 0, 'origin_date' => '2019', 'citations' => $posts]);
            $r = term_route_page($pdo, $pid);
            return [$r === 'glossary', "routed to: {$r}"];
        }],
        [4, 'outlet-coverage', 'fix', 'A term an outlet wrote about in the last 2 weeks gets its own page', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r4-outlet', ['original' => false, 'demand' => 0, 'origin_date' => '2019',
                'citations' => [['platform' => '', 'publication' => 'Dexerto', 'title' => 'What does zz ruletest word mean? The meme explained', 'date' => rt_day(5), 'url' => 'https://www.dexerto.com/zz-ruletest/w', 'quote' => 'zz ruletest word']]]);
            $r = term_route_page($pdo, $pid);
            return [$r === 'page', "routed to: {$r}"];
        }],
        [4, 'usage-not-coverage', 'guard', 'An article that only uses the word ("GTA 6 breaks records for pre-orders") is not coverage: the term stays in the glossary', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r4-usage', ['term' => 'zz preorder', 'original' => false, 'demand' => 0, 'origin_date' => '2019', 'citations' => [
                ['platform' => '', 'publication' => 'Insider Gaming', 'title' => "GTA 6 'Breaking Records' For XBOX zz preorders", 'date' => rt_day(3), 'url' => 'https://insider-gaming.com/zz-ruletest/p', 'quote' => 'zz preorder'],
                ['platform' => '', 'publication' => 'note.com', 'title' => 'Thoughts on the zz preorder meme', 'date' => rt_day(3), 'url' => 'https://note.com/zz-ruletest/n', 'quote' => 'zz preorder']]]);
            $r = term_route_page($pdo, $pid);
            return [$r === 'glossary', "routed to: {$r}"];
        }],
        [4, 'old-quiet', 'guard', 'An old term nobody posts or writes about now, with no demand, stays in the glossary', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r4-quiet', ['original' => false, 'demand' => 0, 'origin_date' => '2019',
                'citations' => [['platform' => 'YouTube', 'handle' => '@zzruletestold', 'date' => '2025-01-10', 'url' => 'https://www.youtube.com/watch?v=zzruletest&lc=old', 'quote' => 'zz ruletest word']]]);
            $r = term_route_page($pdo, $pid);
            return [$r === 'glossary', "routed to: {$r}"];
        }],
        [4, 'own-links', 'fix', 'Posts by different people never share one link (the writer copied one link onto several posts)', function (PDO $pdo) {
            rt_need('term_cites_clean');
            $same = 'https://www.youtube.com/watch?v=zzruletest&lc=one';
            $c = term_cites_clean([['platform' => 'YouTube', 'handle' => '@zzruletesta', 'url' => $same], ['platform' => 'YouTube', 'handle' => '@zzruletestb', 'url' => $same],
                                   ['publication' => 'Dexerto', 'url' => 'https://www.dexerto.com/zz-ruletest/x'], ['publication' => 'Dexerto', 'url' => 'https://www.dexerto.com/zz-ruletest/x']], 'zz ruletest word');
            $urls = array_column($c, 'url');
            return [count($urls) === count(array_unique($urls)) && !in_array($same, $urls, true), count($c) . ' kept, each on its own link: ' . implode(', ', $urls)];
        }],
        [4, 'made-up-post-link', 'fix', 'A post link the writer made up (a tweet id that is not a number) is never shown (2026-09-28: /slang/crit/)', function (PDO $pdo) {
            $c = term_cites_clean([['platform' => 'X', 'handle' => '@zzruletesta', 'url' => 'https://twitter.com/zzruletesta/status/gXxYoZjSfE'],
                                   ['platform' => 'X', 'handle' => '@zzruletestb', 'url' => 'https://x.com/zzruletestb/status/2090746493300801885']], 'zz ruletest word');
            $urls = array_column($c, 'url');
            return [$urls === ['https://x.com/zzruletestb/status/2090746493300801885'], 'kept: ' . implode(', ', $urls)];
        }],
        [4, 'seen-in-use', 'fix', 'On a term page a YouTube comment is shown as "seen in use", not among the sources', function (PDO $pdo) {
            $pid = rt_term($pdo, 'r4-seen', ['status' => 'published', 'citations' => [
                ['platform' => 'YouTube', 'handle' => '@zzruletestuser', 'publication' => '', 'date' => rt_day(4), 'url' => 'https://www.youtube.com/watch?v=zzruletest&lc=u1', 'quote' => 'this zz ruletest word is everywhere now'],
                ['platform' => '', 'publication' => 'Dexerto', 'title' => 'What zz ruletest word means', 'date' => rt_day(6), 'url' => 'https://www.dexerto.com/zz-ruletest/y', 'quote' => 'zz ruletest word is a made-up word people use']]]);
            $html = (string)render_page_html($pid);
            $seen = strpos($html, '>Seen in use<'); $yt = strpos($html, 'lc=u1');
            return [$seen !== false && $yt !== false && $yt > $seen, $seen === false ? 'no "Seen in use" section' : ($yt > $seen ? 'the comment is under "Seen in use"' : 'the comment is shown as a source')];
        }],
        [4, 'why-now-glossary', 'fix', 'A glossary entry an outlet wrote about lately says why it is around now, with the date and the outlet', function (PDO $pdo) {
            rt_need('repo_glossary');
            rt_term($pdo, 'r4-why', ['status' => 'archived', 'term' => 'zz ruletest why', 'redirect_to' => '/slang/glossary/#' . RT_PREFIX . 'r4-why', 'citations' => [
                ['platform' => '', 'publication' => 'Dexerto', 'title' => 'Why zz ruletest why is the meme everywhere', 'date' => rt_day(4), 'url' => 'https://www.dexerto.com/zz-ruletest/why', 'quote' => 'zz ruletest why']]]);
            $html = rt_glossary_html('slang', RT_PREFIX . 'r4-why');
            $ok = preg_match('#id="' . RT_PREFIX . 'r4-why".*?Why now: ([^<]*)#s', $html, $m);
            return [(bool)$ok, $ok ? 'Why now: ' . $m[1] : 'no why-now line'];
        }],
        [4, 'wiktionary-link', 'fix', 'Wiktionary named in an entry links to its page', function (PDO $pdo) {
            rt_need('repo_glossary');
            rt_term($pdo, 'r4-wikt', ['status' => 'archived', 'term' => 'zz ruletest wikt', 'redirect_to' => '/slang/glossary/#' . RT_PREFIX . 'r4-wikt',
                'meaning' => ['A made-up word for the test set, defined in its own way (Wiktionary) and used nowhere else.']]);
            $html = rt_glossary_html('slang', RT_PREFIX . 'r4-wikt');
            $ok = (bool)preg_match('#<a href="https://en\.wiktionary\.org/wiki/[^"]+"[^>]*>Wiktionary</a>#', $html);
            return [$ok, $ok ? 'linked' : 'Wiktionary is named without a link'];
        }],
        [4, 'dup-early', 'fix', 'A term that already has a page is refused at once, not after minutes of writing (2026-09-28)', function (PDO $pdo) {
            require_once __DIR__ . '/draft_term.php';
            rt_term($pdo, 'dupword', ['term' => 'zz ruletest dupword']);
            $t = microtime(true);
            $r = draft_term(['term' => 'zz ruletest dupword', 'lane' => 'slang']);
            $secs = round(microtime(true) - $t, 1);
            return [str_contains((string)($r['error'] ?? ''), 'already exists') && $secs < 5, "refused in {$secs} s: " . ($r['error'] ?? 'written')];
        }],
        [4, 'glossary-page', 'fix', 'The slang glossary shows a folded term in its own section', function (PDO $pdo) {
            rt_need('repo_glossary');
            rt_term($pdo, 'r4-fold', ['status' => 'archived', 'term' => 'zz ruletest word', 'redirect_to' => '/slang/glossary/#' . RT_PREFIX . 'r4-fold']);
            $html = rt_glossary_html('slang', RT_PREFIX . 'r4-fold');
            return [str_contains($html, 'id="' . RT_PREFIX . 'r4-fold"'), str_contains($html, 'zz ruletest word') ? 'the term has its section' : 'the term is missing'];
        }],

        // RULE 5: titles and descriptions may hook, never lie
        [5, 'death-fact', 'fix', 'A title stating a death as fact, with no attribution, is caught', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-death', ['h1' => 'Streamer Gamma dies after a livestream accident']), 5);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [5, 'crime-fact', 'fix', 'A description stating a crime as fact is caught', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-crime', ['meta' => 'Delta assaulted a fan at a gaming convention and was banned from Twitch. Every dated step of the story, with its source.']), 5);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [5, 'crime-attributed', 'guard', '"..., police say" is attribution: allowed', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-attr', ['h1' => 'Delta punched a fan at a convention, police say']), 5);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],
        [5, 'fake-quote', 'fix', 'A quote in the title that no source contains is caught', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-fake', ['h1' => 'Alpha says "I will sue Beta" in a new video']), 5);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [5, 'quote-no-speaker', 'fix', 'A real quote with nobody saying it is caught', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-nospk', ['h1' => 'Sponsorship feud: "I never broke the deal" goes viral', 'excerpt' => 'Alpha wrote: "I never broke the deal."']), 5);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [5, 'real-quote', 'guard', 'A real quote with its speaker is allowed', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-real', ['h1' => 'Alpha says "I never broke the deal" as Beta posts receipts', 'excerpt' => 'Alpha wrote: "I never broke the deal."']), 5);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],

        [5, 'work-title', 'guard', 'An album or meme name in quotes is not a quote that needs a speaker', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-work', ['h1' => 'Alpha defends his review of "The Great Impersonator" album', 'excerpt' => 'Alpha reviewed The Great Impersonator.']), 5);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],
        [5, 'threats-allegations', 'guard', '"Death threats" is not a death, and "abuse allegations" is attributed', function (PDO $pdo) {
            $r = rt_rule_reasons($pdo, rt_story($pdo, 'r5-thr', ['h1' => 'Alpha faces death threats over abuse allegations']), 5);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],

        // RULE 6: crime, abuse and death stories
        [6, 'grave-no-take', 'fix', 'A crime story shows no "Our take" and no "Our read of the evidence"', function (PDO $pdo) use ($grave, $graveEv) {
            $html = (string)render_page_html(rt_story($pdo, 'r6-take', $grave + ['events' => $graveEv('https://www.dexerto.com/zz-ruletest/g2', 'Delta was accused of starting the fight, according to Dexerto.'),
                'why' => "Delta's arrest came one day after the convention fight, and Delta has not commented on the police account.",
                'verdict' => ['rating' => 'Partly documented', 'reasons' => 'One event is confirmed by a primary source.']]));
            $left = array_filter(['Our take' => str_contains($html, '>Our take<'), 'Our read of the evidence' => str_contains($html, 'Our read of the evidence')]);
            return [$left === [], $left ? 'still on the page: ' . implode(', ', array_keys($left)) : 'neither is on the page'];
        }],
        [6, 'anonymous-accusation', 'fix', 'An accusation resting only on an anonymous post is caught', function (PDO $pdo) use ($grave, $graveEv) {
            $pid = rt_story($pdo, 'r6-anon', $grave + ['events' => $graveEv('https://x.com/zzruletestanon/status/1', 'An X account claimed Delta assaulted a fan before the fight.')]);
            $r = rt_rule_reasons($pdo, $pid, 6);
            return [$r !== [], $r ? $r[0] : 'nothing caught it'];
        }],
        [6, 'own-words', 'guard', "The person's own post is an allowed source", function (PDO $pdo) use ($grave, $graveEv) {
            $pid = rt_story($pdo, 'r6-own', $grave + ['events' => $graveEv('https://x.com/zzruletestdelta/status/2', 'Delta said he was arrested and apologized to the fan.')]);
            $r = rt_rule_reasons($pdo, $pid, 6);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],
        [6, 'outlet', 'guard', 'A news outlet is an allowed source', function (PDO $pdo) use ($grave, $graveEv) {
            $pid = rt_story($pdo, 'r6-outlet', $grave + ['events' => $graveEv('https://www.dexerto.com/zz-ruletest/g3', 'Delta was accused of assaulting a fan, according to Dexerto.')]);
            $r = rt_rule_reasons($pdo, $pid, 6);
            return [$r === [], $r ? $r[0] : 'no false alarm'];
        }],

        // RULE 7: the owner reads crime, abuse and death stories before they go live
        [7, 'word-assault', 'fix', '"assault" is caught', fn() => [($x = $hr('Epsilon faces assault claims after a convention')) !== '', $x ?: 'not caught']],
        [7, 'word-punched', 'fix', '"punched" is caught', fn() => [($x = $hr('Epsilon punched a fan at a convention')) !== '', $x ?: 'not caught']],
        [7, 'word-attacked', 'fix', '"attacked" is caught', fn() => [($x = $hr('Epsilon attacked by a fan during a livestream')) !== '', $x ?: 'not caught']],
        [7, 'word-abuse', 'fix', '"abuse" is caught', fn() => [($x = $hr('Epsilon accused of abuse by a former moderator')) !== '', $x ?: 'not caught']],
        [7, 'word-arrested', 'fix', '"arrested" is caught', fn() => [($x = $hr('Epsilon arrested after a convention fight')) !== '', $x ?: 'not caught']],
        [7, 'word-police', 'fix', '"police" is caught', fn() => [($x = $hr("Police called to Epsilon's stream house")) !== '', $x ?: 'not caught']],
        [7, 'word-charged', 'fix', '"charged" is caught', fn() => [($x = $hr('Epsilon charged after a convention fight')) !== '', $x ?: 'not caught']],
        [7, 'word-death', 'fix', '"death" is caught', fn() => [($x = $hr("Epsilon's death confirmed by family")) !== '', $x ?: 'not caught']],
        [7, 'word-killed', 'fix', '"killed" is caught', fn() => [($x = $hr('Epsilon killed in a car crash')) !== '', $x ?: 'not caught']],
        [7, 'game-lore', 'guard', 'Game lore ("the raid boss attacked and killed players") is not a crime story', fn() => [($x = $hr('The new raid boss attacked and killed players until they respawned')) === '', $x ?: 'no false alarm']],
        [7, 'twitch-raid', 'fix', 'A Twitch raid is not game lore: "attacked by mother during a raid" is caught', fn() => [($x = implode('; ', hr_reasons('Streamer attacked by his mother on stream', '', 'His mom attacked him during a 15,000-viewer raid.'))) !== '', $x ?: 'not caught']],
        [7, 'hold', 'fix', 'A new "punched" story is held for the owner instead of going live', function (PDO $pdo) {
            $pid = rt_story($pdo, 'r7-hold', ['h1' => 'Epsilon punched a fan at a convention, police say']);
            ob_start(); $live = page_publish_live($pdo, $pid); ob_end_clean();
            $r = $pdo->query("SELECT status, human_review FROM pages WHERE id={$pid}")->fetch(PDO::FETCH_ASSOC);
            return [$r['status'] === 'review' && $r['human_review'] === 'needed', "status {$r['status']}, human check: " . ($r['human_review'] ?? 'none')];
        }],
        // RULE 12: the story picker (owner 2026-09-30, story_picker.php): new, on its own topic, 2 outlets, sagas merged
        [12, 'old-story', 'fix', 'A story whose newest article is from April is dropped as old (the RaKai page, 6 months late)', fn() => rt_sp_case(
            [['url' => 'https://timesofindia.indiatimes.com/a', 'title' => 'RaKai targets Mari', 'date' => '2026-04-07 12:00:00', 'seed' => true]], 'drop', 'age')],
        [12, 'old-tiktok', 'fix', 'A site writing up a June TikTok in September: the TikTok dates the story, so it is old', fn() => rt_sp_case(
            [['url' => 'https://dailydot.com/a', 'title' => 'TikToker stops tipping', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.tiktok.com/@x/video/7516000000000000000', 'title' => 'the video', 'date' => '2026-06-15 10:00:00', 'kind' => 'post', 'on_topic' => true, 'from' => 'embed']], 'drop', 'age')],
        [12, 'off-topic-watch', 'fix', 'A new screenshot with only last month\'s leak articles found is not built: it waits (the crop duster post)', fn() => rt_sp_case(
            [['url' => 'https://www.reddit.com/r/GTA6/comments/x', 'title' => 'crop duster photo', 'date' => '2026-09-30 09:00:00', 'seed' => true, 'kind' => 'post'],
             ['url' => 'https://www.notebookcheck.net/a', 'title' => 'Second group claims access to GTA 6 build', 'date' => '2026-08-20 12:00:00', 'on_topic' => false, 'from' => 'search']], 'watch', 'sources')],
        [12, 'one-outlet-watch', 'fix', 'One outlet only (Xbox on Kotaku, AION on Insider Gaming): the watch list, not a page', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud', 'date' => '2026-09-30 09:00:00', 'seed' => true]], 'watch', 'sources')],
        [12, 'watch-drop', 'fix', 'Still one outlet 48 hours later: dropped', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud', 'date' => '2026-09-30 09:00:00', 'seed' => true]], 'drop', 'watch_expired', ['watch_since' => '2026-09-28 11:00:00'])],
        [12, 'two-outlets', 'guard', 'Two independent outlets from today: built', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud gaming is collapsing', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.theverge.com/b', 'title' => 'Microsoft trims cloud gaming hours', 'date' => '2026-09-30 08:00:00', 'on_topic' => true, 'from' => 'search']], 'build', 'ok')],
        [12, 'outlet-and-post', 'guard', 'One outlet + the story\'s own post from today: built', fn() => rt_sp_case(
            [['url' => 'https://dailydot.com/a', 'title' => 'TikToker says', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.tiktok.com/@x/video/1', 'title' => 'the video', 'date' => '2026-09-29 20:00:00', 'kind' => 'post', 'on_topic' => true, 'from' => 'embed']], 'build', 'ok')],
        [12, 'same-site', 'fix', 'Two articles from one site count as one outlet', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://kotaku.com/b', 'title' => 'More Xbox cloud news', 'date' => '2026-09-30 10:00:00', 'on_topic' => true, 'from' => 'search']], 'watch', 'sources')],
        [12, 'wikipedia', 'fix', 'Wikipedia is not an outlet', fn() => rt_sp_case(
            [['url' => 'https://insider-gaming.com/a', 'title' => 'AION 2 tops Steam', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://en.wikipedia.org/wiki/Aion_2', 'title' => 'Aion 2 - Wikipedia', 'date' => '2026-09-30 10:00:00', 'on_topic' => true, 'from' => 'search']], 'watch', 'sources')],
        [12, 'syndicated', 'fix', 'The same headline on another site (a syndicated copy) counts once', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox Cloud Gaming Is Collapsing', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.gamerant.com/b', 'title' => 'Xbox Cloud Gaming Is Collapsing - Game Rant', 'date' => '2026-09-30 10:00:00', 'on_topic' => true, 'from' => 'search']], 'watch', 'sources')],
        [12, 'old-outlet', 'fix', 'An article from 4 weeks ago is not coverage of today\'s story (Polygon, Sep 4, for a Sep 30 story)', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.polygon.com/b', 'title' => 'Xbox caps cloud hours', 'date' => '2026-09-04 10:00:00', 'on_topic' => true, 'from' => 'search']], 'watch', 'sources')],
        [12, 'search-thread', 'fix', 'A Reddit thread the search found is not the story\'s own post', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.reddit.com/r/xbox/comments/b', 'title' => 'thoughts?', 'date' => '', 'kind' => 'post', 'on_topic' => true, 'from' => 'search']], 'watch', 'sources')],
        [12, 'post-undated', 'fix', 'An undated post the article links does not make "1 outlet + the original post" (Xbox: Kotaku + a Reddit thread)', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Xbox cloud', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.reddit.com/r/xbox/comments/b', 'title' => 'thread', 'date' => '', 'kind' => 'post', 'on_topic' => true, 'from' => 'embed']], 'watch', 'sources')],
        [12, 'post-old', 'fix', 'A post from 5 days ago does not either, even when the article reports something new today', fn() => rt_sp_case(
            [['url' => 'https://kotaku.com/a', 'title' => 'Studio responds', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://x.com/a/status/1', 'title' => 'the first post', 'date' => '2026-09-25 10:00:00', 'kind' => 'post', 'on_topic' => true, 'from' => 'embed']], 'watch', 'sources', ['event_date' => '2026-09-30 09:00:00'])],
        [12, 'nothing-dated', 'fix', 'Nothing dated found (an empty search looks the same): it waits a day, it is not dropped at once', fn() => rt_sp_case(
            [['url' => 'https://www.pcgamer.com/a', 'title' => 'Gears of War specs', 'date' => '', 'seed' => true]], 'watch', 'age_unknown')],
        [12, 'nothing-dated-24h', 'guard', 'Still nothing dated a day later: dropped', fn() => rt_sp_case(
            [['url' => 'https://www.pcgamer.com/a', 'title' => 'Gears of War specs', 'date' => '', 'seed' => true]], 'drop', 'age', ['watch_since' => '2026-09-29 11:00:00'])],
        [12, 'own-post-newer', 'fix', 'The AI dates it to June, but the story\'s own post is from yesterday: it is new (Pikachu back from the ISS)', fn() => rt_sp_case(
            [['url' => 'https://www.dexerto.com/a', 'title' => 'Pikachu spent 67 days on the ISS', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://x.com/a/status/1', 'title' => 'the post', 'date' => '2026-09-29 18:00:00', 'kind' => 'post', 'on_topic' => true, 'from' => 'embed']], 'build', 'ok', ['event_date' => '2026-06-17 12:00:00'])],
        [12, 'old-second-reading', 'fix', 'Read as old while 2 outlets wrote about it today: one more reading in 24h before it is dropped', fn() => rt_sp_case(
            [['url' => 'https://www.pcgamer.com/a', 'title' => 'Court docs reveal', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://arstechnica.com/b', 'title' => 'Microsoft exec warned of AI doom loops', 'date' => '2026-09-30 08:00:00', 'on_topic' => true, 'from' => 'search']], 'watch', 'age_recheck', ['event_date' => '2026-09-20 12:00:00'])],
        [12, 'old-second-reading-24h', 'guard', 'Still read as old a day later: dropped', fn() => rt_sp_case(
            [['url' => 'https://www.pcgamer.com/a', 'title' => 'Court docs reveal', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://arstechnica.com/b', 'title' => 'Microsoft exec warned of AI doom loops', 'date' => '2026-09-30 08:00:00', 'on_topic' => true, 'from' => 'search']], 'drop', 'age', ['event_date' => '2026-09-20 12:00:00', 'watch_since' => '2026-09-29 11:00:00'])],
        [12, 'saga', 'fix', 'A new chapter of a story we already have goes on that page, never a new page (the GTA 6 leak saga)', fn() => rt_sp_case(
            [['url' => 'https://www.dexerto.com/a', 'title' => 'Take-Two files new subpoena', 'date' => '2026-09-30 09:00:00', 'seed' => true],
             ['url' => 'https://www.ign.com/b', 'title' => 'Take-Two goes after GTA 6 leakers again', 'date' => '2026-09-30 08:00:00', 'on_topic' => true, 'from' => 'search']], 'merge', 'saga', ['saga' => 799, 'saga_title' => 'GTA 6 Leak Controversy'])],
        [12, 'queue-expire', 'fix', 'A queue entry over 14 days old expires', fn() => rt_sp_case([], 'expire', 'queue_age', ['found_at' => '2026-09-15 10:00:00'])],
        [12, 'page-new-event', 'fix', 'A written page whose events are all 41 days old is not published', function () {
            rt_need('sp_events_fresh');
            $old = sp_events_fresh(['2026-08-19', '2026-08-20'], '2026-09-30 11:50:00');
            $new = sp_events_fresh(['2026-08-19', '2026-09-29'], '2026-09-30 11:50:00');
            return [!$old && $new, 'Aug 19-20 only: ' . ($old ? 'passes' : 'refused') . '; with Sep 29: ' . ($new ? 'passes' : 'refused')];
        }],
        [12, 'post-time', 'fix', 'A post on X is dated by its own id, not by the day we found it', function () {
            rt_need('sp_post_time');
            $d = sp_post_time('https://x.com/a/status/1600000000000000000');
            return [substr($d, 0, 10) === '2022-12-06', 'dated ' . ($d ?: 'nothing')];
        }],

        // RULE 14: the fact check reads every source the story was written from; remove, check again, hold if still failing
        // (owner 2026-10-04, story_sources.php; on with app/FACTFIX_ON)
        [14, 'all-sources', 'fix', 'A story written from 3 articles is checked against all 3, not only the one its timeline cites', function () {
            rt_need_ss();
            $ids = ss_union([5], [5, 6, 7], true);
            return [$ids === [5, 6, 7], 'the check reads sources ' . implode(', ', $ids)];
        }],
        [14, 'switch-off', 'guard', 'With the switch off nothing changes: only the sources the timeline cites', function () {
            rt_need_ss();
            $ids = ss_union([5], [5, 6, 7], false);
            return [$ids === [5], 'the check reads sources ' . implode(', ', $ids)];
        }],
        [14, 'date-stated', 'guard', 'An event dated a day its source names ("on October 2") keeps its date', function () {
            rt_need_ss();
            return [ss_date_backed('2026-10-02', [['excerpt' => 'Speaking on October 2, the director said the trilogy keeps its story.', 'published_on' => '2026-10-03']]) === true, 'the source names the day'];
        }],
        [14, 'date-published', 'guard', 'An event dated the day its source was published keeps its date', function () {
            rt_need_ss();
            return [ss_date_backed('2026-10-03', [['excerpt' => 'The director said the trilogy keeps its story.', 'published_on' => '2026-10-03']]) === true, 'the day of the report'];
        }],
        [14, 'date-invented', 'fix', 'An event dated Oct 2 from an article published Oct 3 that names no day: the date is not backed', function () {
            rt_need_ss();
            return [ss_date_backed('2026-10-02', [['excerpt' => 'The director said the trilogy keeps its story.', 'published_on' => '2026-10-03']]) === false, 'no source gives October 2'];
        }],
        [14, 'date-not-part', 'guard', '"October 21" in a source does not back October 2', function () {
            rt_need_ss();
            return [ss_date_backed('2026-10-02', [['excerpt' => 'The update arrives on October 21 and Oct. 29.', 'published_on' => '2026-09-30']]) === false, 'October 21 is another day'];
        }],
        [14, 'date-day-off', 'fix', 'An event dated a day before its article, with no day in any source, takes the article\'s date', function () {
            rt_need_ss();
            $p = ss_date_plan('2026-10-02', '2026-10-03', [['excerpt' => 'The director said the trilogy keeps its story.', 'published_on' => '2026-10-03']], '2026-10-04');
            return [$p['action'] === 'report' && $p['date'] === '2026-10-03', "{$p['action']}: {$p['date']}"];
        }],
        [14, 'date-month-only', 'fix', '"Diagnosed in March 2022" written as March 1: the day was invented, the date becomes month-only (never the report\'s day)', function () {
            rt_need_ss();
            $p = ss_date_plan('2022-03-01', '2026-10-02', [['excerpt' => 'She was diagnosed with ALS in March 2022 and documented it on TikTok.', 'published_on' => '2026-10-02']], '2026-10-04');
            return [$p['action'] === 'month' && $p['date'] === '2022-03-00', "{$p['action']}: {$p['date']}"];
        }],
        [14, 'date-far', 'guard', 'A day no source gives, weeks from the report, is left alone: the fact check decides and the page holds', function () {
            rt_need_ss();
            $p = ss_date_plan('2026-08-14', '2026-10-02', [['excerpt' => 'The studio confirmed the delay this week.', 'published_on' => '2026-10-02']], '2026-10-04');
            return [$p['action'] === 'leave' && $p['date'] === '2026-08-14', "{$p['action']}: {$p['date']}"];
        }],
        [14, 'date-first-real', 'guard', 'A real first of the month the source names ("on October 1") stays a full date', function () {
            rt_need_ss();
            $p = ss_date_plan('2026-10-01', '2026-10-03', [['excerpt' => 'Sony announced the feature on October 1 in a blog post.', 'published_on' => '2026-10-03']], '2026-10-04');
            return [$p['action'] === 'keep', "{$p['action']}: {$p['date']}"];
        }],
        [14, 'post-date', 'fix', 'An X post is dated by its own id, so an event that is the post has a date the check can see', function () {
            rt_need_ss();
            $d = ss_post_date('https://twitter.com/IRANinTJ/status/2041215767379878049');
            return [$d === '2026-04-06' && ss_post_date('https://kotaku.com/some-article-2000123456789012345') === '', "the post's day: {$d}"];
        }],
        [14, 'plan-label', 'fix', 'A plan dated only by month or year is shown to the fact check as "December 2026", not "2026-12-00"', function () {
            rt_need_ss();
            $a = ss_plan_label('2026-12-00'); $b = ss_plan_label('2027-00-00'); $c = ss_plan_label('2026-11-19');
            return [$a === 'December 2026' && $b === '2027' && $c === '2026-11-19', "{$a} / {$b} / {$c}"];
        }],
        [14, 'latest-wording', 'fix', 'Our "latest development" sentence no longer gives the event\'s day as the day of the report', function () {
            rt_need_ss();
            $t = rt_ss_env('1', fn() => pr_latest_sentence(['date' => '2026-09-25', 'by' => 'dexerto.com', 'title' => 'Rockstar unveiled $400 GTA 6 collector\'s edition']));
            return [!str_contains($t, 'dexerto.com on') && str_contains($t, '(Sep 25)') && str_contains($t, 'reported by dexerto.com'), $t];
        }],
        [14, 'latest-wording-off', 'guard', 'With the switch off the sentence is unchanged', function () {
            rt_need_ss();
            $t = rt_ss_env('0', fn() => pr_latest_sentence(['date' => '2026-09-25', 'by' => 'dexerto.com', 'title' => 'Rockstar unveiled $400 GTA 6 collector\'s edition']));
            return [str_contains($t, 'reported by dexerto.com on Sep 25'), $t];
        }],
        [14, 'round-tie', 'fix', 'A sentence the fact check calls unsupported must be shown in the sources, or it comes out', function () {
            $p = rt_ss_plan([['section' => 'background', 'type' => 'unsourced', 'sentence' => 'The studio lost half of its players after the last update shipped.']], []);
            return [count($p['tie']) === 1 && !$p['cut'], 'to the source tie: ' . count($p['tie']) . ', removed at once: ' . count($p['cut'])];
        }],
        [14, 'round-again', 'fix', 'A sentence the sources backed last round and the check faults again comes out (when in doubt, cut)', function () {
            $s = 'The studio lost half of its players after the last update shipped.';
            $p = rt_ss_plan([['section' => 'faq 2', 'type' => 'unsourced', 'sentence' => $s]], [acc_norm($s) => 1]);
            return [count($p['cut']) === 1 && !$p['tie'], 'removed at once: ' . count($p['cut'])];
        }],
        [14, 'round-plan-date', 'fix', 'A plan with a date no source gives comes out of What happens next', function () {
            $p = rt_ss_plan([['section' => 'what happens next', 'type' => 'dates', 'sentence' => '[2027-01-00] Start of the Blood Crystal Saga with the Song of Rebellion update, according to ign.com.']], []);
            return [count($p['cut']) === 1 && $p['cut'][0]['section'] === 'next' && !str_starts_with($p['cut'][0]['sentence'], '['), 'removed: ' . ($p['cut'][0]['sentence'] ?? 'nothing')];
        }],
        [14, 'round-event-date', 'guard', 'A wrong date on a timeline event is never "removed": the page holds', function () {
            $p = rt_ss_plan([['section' => 'event 1', 'type' => 'dates', 'sentence' => 'Sega Amusements reportedly posted a Short on its official YouTube channel.']], []);
            return [!$p['cut'] && !$p['tie'], 'nothing removed, the failed fact check holds the page'];
        }],
        [14, 'round-never-relax', 'guard', 'Missing framing, an accusation, a detail from another case or tone are never removed past: the page holds for a person', function () {
            $s = 'He assaulted a fan outside the venue in March of this year.';
            $p = rt_ss_plan([['section' => 'event 2', 'type' => 'framing', 'sentence' => $s], ['section' => 'summary', 'type' => 'legal', 'sentence' => $s],
                             ['section' => 'background', 'type' => 'unrelated', 'sentence' => $s], ['section' => 'summary', 'type' => 'tone', 'sentence' => $s]], []);
            return [!$p['cut'] && !$p['tie'], 'nothing removed, the failed fact check holds the page'];
        }],
        [14, 'round-title', 'guard', 'A faulted title or description is rewritten and checked again, never cut', function () {
            $p = rt_ss_plan([['section' => 'description', 'type' => 'unsourced', 'sentence' => 'Sega says it teased a new House of the Dead VR arcade game on Oct 1, 2026.'],
                             ['section' => 'title', 'type' => 'overreach', 'sentence' => 'Warhorse Co-Founder Hopes GTA 6 Will Normalize $80 Game Prices']], []);
            return [!$p['cut'] && !$p['tie'], 'left to the rewrite (acc_retitle)'];
        }],
        // RULE 13: the trend detector (owner 2026-10-01, trend.php): rising, new, 2+ platforms, not an ordinary word
        [13, 'ordinary-word', 'fix', '"gamepad" is an ordinary gaming word: never a trend, however often it is used', fn() => rt_tr_case(
            ['recent_posts' => 40, 'recent_authors' => 30, 'platforms' => ['reddit', 'youtube'], 'baseline_3d' => 2, 'ordinary' => true, 'label' => 'an ordinary gaming word'], false)],
        [13, 'dict-plain', 'fix', 'The dictionary lists "stamina" as a plain word: ordinary', function () {
            rt_need_tr();
            $r = tr_dict_read(['English lemmas', 'English nouns', 'English uncountable nouns']);
            return [$r['ordinary'] === true, $r['label']];
        }],
        [13, 'dict-gaming', 'fix', 'The dictionary lists "loadout" under video games: an ordinary gaming word', function () {
            rt_need_tr();
            $r = tr_dict_read(['English lemmas', 'English nouns', 'en:Video games']);
            return [$r['ordinary'] === true, $r['label']];
        }],
        [13, 'dict-slang', 'guard', 'The dictionary lists "rizz" as slang: not an ordinary word', function () {
            rt_need_tr();
            $r = tr_dict_read(['English lemmas', 'English nouns', 'English slang', 'English neologisms']);
            return [$r['ordinary'] === false, $r['label']];
        }],
        [13, 'dict-caps', 'fix', '"oomf" is a plain noun in small letters but internet slang as "OOMF": not an ordinary word', function () {
            rt_need_tr();
            $r = tr_dict_read(['English lemmas', 'English nouns', 'English countable nouns'], ['English acronyms', 'English internet slang', 'English lemmas', 'English nouns', 'en:Twitter']);
            return [$r['ordinary'] === false, $r['label']];
        }],
        [13, 'dict-caps-plain', 'guard', 'A plain word whose capital spelling is not slang stays ordinary ("cap" / "CAP")', function () {
            rt_need_tr();
            $r = tr_dict_read(['English lemmas', 'English nouns', 'English verbs'], ['English lemmas', 'English proper nouns', 'English initialisms']);
            return [$r['ordinary'] === true, $r['label']];
        }],
        [13, 'dict-none', 'guard', 'A new meme name is not in the dictionary: not an ordinary word', function () {
            rt_need_tr();
            $r = tr_dict_read([]);
            return [$r['ordinary'] === false, $r['label']];
        }],
        [13, 'old-term', 'fix', 'A term in our signals for 6 weeks is not new, even when it spikes', fn() => rt_tr_case(
            ['recent_posts' => 30, 'recent_authors' => 20, 'platforms' => ['reddit', 'x'], 'baseline_3d' => 2, 'first_heard' => '2026-08-19'], false)],
        [13, 'since-start', 'fix', 'A term heard since the day our listener started has an unknown age: not new', fn() => rt_tr_case(
            ['recent_posts' => 30, 'recent_authors' => 20, 'platforms' => ['reddit', 'x'], 'baseline_3d' => 2, 'first_heard' => '2026-09-01', 'listener_start' => '2026-08-31'], false)],
        [13, 'one-platform', 'fix', 'New and rising, but on one platform only: not a trend yet', fn() => rt_tr_case(
            ['recent_posts' => 30, 'recent_authors' => 20, 'platforms' => ['reddit'], 'baseline_3d' => 2], false)],
        [13, 'not-rising', 'fix', 'A word used as much as usual (6 posts against its own average of 5) is common, not rising', fn() => rt_tr_case(
            ['recent_posts' => 6, 'recent_authors' => 6, 'platforms' => ['reddit', 'youtube'], 'baseline_3d' => 5], false)],
        [13, 'rising', 'guard', 'New, on 2 platforms, 30 posts against its own average of 2: a trend', fn() => rt_tr_case(
            ['recent_posts' => 30, 'recent_authors' => 12, 'platforms' => ['reddit', 'x'], 'baseline_3d' => 2], true)],
        [13, 'interim', 'guard', 'With only 2 days of counts, new + 2 platforms + 6 posts by 4 people passes, and says "rising not judged yet"', function () {
            $r = rt_tr_decide(['recent_posts' => 6, 'recent_authors' => 4, 'platforms' => ['reddit', 'x'], 'baseline_3d' => 0, 'history_days' => 2]);
            return [$r['trend'] && $r['interim'], $r['why']];
        }],
        [13, 'dict-slang-interim', 'fix', 'A word the dictionary already lists as slang cannot pass before a rise can be measured ("washed", "diss")', fn() => rt_tr_case(
            ['recent_posts' => 8, 'recent_authors' => 6, 'platforms' => ['reddit', 'x'], 'baseline_3d' => 0, 'history_days' => 2, 'label' => 'in the dictionary as slang'], false)],
        [13, 'too-few', 'fix', 'Two posts are not a trend', fn() => rt_tr_case(
            ['recent_posts' => 2, 'recent_authors' => 2, 'platforms' => ['reddit', 'x'], 'baseline_3d' => 0, 'history_days' => 2], false)],
        [13, 'named-two-platforms', 'guard', 'A meme a source named, with recent posts of it on TikTok and X: a trend', function () {
            rt_need_tr(); rt_need('tr_decide_named');
            $r = tr_decide_named(['now' => '2026-10-01', 'named_by' => 'knowyourmeme.com', 'posts' => [
                ['platform' => 'TikTok', 'date' => '2026-09-25'], ['platform' => 'TikTok', 'date' => '2026-09-26'], ['platform' => 'X', 'date' => '2026-09-27']]]);
            return [$r['trend'] === true, $r['why']];
        }],
        [13, 'named-one-platform-outlet', 'guard', 'Recent posts on TikTok only, and KnowYourMeme wrote about it: the outlet is the second place (owner 2026-10-04)', function () {
            rt_need_tr(); rt_need('tr_decide_named');
            $p = [['platform' => 'TikTok', 'date' => '2026-09-25'], ['platform' => 'TikTok', 'date' => '2026-09-26'], ['platform' => 'TikTok', 'date' => '2026-09-27']];
            $r = tr_decide_named(['now' => '2026-10-01', 'named_by' => 'knowyourmeme.com', 'posts' => $p, 'outlet' => true]);
            return [$r['trend'] === true, $r['why']];
        }],
        [13, 'named-one-platform-thread', 'fix', 'Recent posts on one platform and only a Reddit thread naming it: not a trend yet', function () {
            rt_need_tr(); rt_need('tr_decide_named');
            $p = [['platform' => 'TikTok', 'date' => '2026-09-25'], ['platform' => 'TikTok', 'date' => '2026-09-26'], ['platform' => 'TikTok', 'date' => '2026-09-27']];
            $r = tr_decide_named(['now' => '2026-10-01', 'named_by' => 'reddit', 'posts' => $p, 'outlet' => false]);
            return [$r['trend'] === false, $r['why']];
        }],
        [13, 'named-old-posts', 'fix', 'A meme a source named whose posts are months old is not a trend now', function () {
            rt_need_tr(); rt_need('tr_decide_named');
            $r = tr_decide_named(['now' => '2026-10-01', 'named_by' => 'knowyourmeme.com', 'posts' => [
                ['platform' => 'TikTok', 'date' => '2024-12-18'], ['platform' => 'X', 'date' => '2026-04-10'], ['platform' => 'TikTok', 'date' => '2026-09-26']]]);
            return [$r['trend'] === false, $r['why']];
        }],
        [13, 'named-no-posts', 'fix', 'A name with no dated post of the meme behind it waits (a single Reddit headline)', function () {
            rt_need_tr(); rt_need('tr_decide_named');
            $r = tr_decide_named(['now' => '2026-10-01', 'named_by' => 'reddit', 'posts' => []]);
            return [$r['trend'] === false, $r['why']];
        }],
        [13, 'posts-as-citations', 'fix', 'The meme\'s own posts become citations the truth gate can check: platform, who, date, address', function () {
            rt_need_tr(); rt_need('tr_posts_as_citations');
            $c = tr_posts_as_citations([['platform' => 'TikTok', 'handle' => '@someone', 'date' => '2026-09-25', 'url' => 'https://www.tiktok.com/@someone/video/7687760919706799390']])[0];
            return [$c['platform'] === 'TikTok' && $c['handle'] === '@someone' && $c['date'] === 'September 25, 2026' && str_contains($c['url'], 'tiktok.com'), json_encode($c, JSON_UNESCAPED_SLASHES)];
        }],
        [13, 'post-once', 'fix', 'The same post read on two hourly runs is one post', function () {
            rt_need_tr();
            $p = ['platform' => 'reddit', 'sub' => 'gaming', 'author' => 'Someone', 'text' => "This  new gamepad is great"];
            $q = ['platform' => 'reddit', 'sub' => 'gaming', 'author' => 'someone', 'text' => 'This new gamepad is great'];
            return [tr_post_hash($p) === tr_post_hash($q) && tr_post_hash($p) !== tr_post_hash(['author' => 'other'] + $p), 'same post, same mark; another author, another mark'];
        }],
    ];
}

/** Rule 13: the trend detector's functions, loaded when the file exists. */
function rt_need_ss(): void {
    if (is_file(__DIR__ . '/story_sources.php')) require_once __DIR__ . '/story_sources.php';
    rt_need('ss_union');
    require_once __DIR__ . '/page_rules.php';
    require_once __DIR__ . '/accuracy.php';
}
/** Run $fn with the fact-check switch forced on ('1') or off ('0'), then put it back as it was. */
function rt_ss_env(string $v, callable $fn) {
    $was = getenv('FACTFIX');
    putenv('FACTFIX=' . $v);
    try { return $fn(); } finally { $was === false ? putenv('FACTFIX') : putenv('FACTFIX=' . $was); }
}
function rt_ss_plan(array $issues, array $tiedBefore): array {
    rt_need_ss();
    return ss_round_plan($issues, $tiedBefore, 'acc_norm', fn(string $q) => back_sentences($q) ?: ($q !== '' ? [$q] : []), 'acc_strip_line');
}
function rt_need_tr(): void {
    if (is_file(__DIR__ . '/trend.php')) require_once __DIR__ . '/trend.php';
    rt_need('tr_decide');
}
/** A trend decision on 2026-10-01 with 20 days of counts, for a term first heard 5 days ago, unless $m says otherwise. */
function rt_tr_decide(array $m): array {
    rt_need_tr();
    return tr_decide($m + ['now' => '2026-10-01', 'history_days' => 20, 'first_heard' => '2026-09-26', 'listener_start' => '2026-08-30',
                           'ordinary' => false, 'label' => '', 'recent_reach' => 0]);
}
function rt_tr_case(array $m, bool $wantTrend): array {
    $r = rt_tr_decide($m);
    return [$r['trend'] === $wantTrend, ($r['trend'] ? 'trend' : 'not a trend') . ': ' . $r['why']];
}

/** Rule 12: a story picker decision at a fixed moment (2026-09-30 12:00 UTC, found 10:00). */
function rt_sp_case(array $items, string $wantDecision, string $wantRule, array $extra = []): array {
    if (is_file(__DIR__ . '/story_picker.php')) require_once __DIR__ . '/story_picker.php';
    rt_need('sp_decide');
    $r = sp_decide(array_merge(['now' => '2026-09-30 12:00:00', 'found_at' => '2026-09-30 10:00:00', 'watch_since' => null,
                                'queue_expiry' => true, 'saga' => 0], $extra, ['items' => $items]));
    return [$r['decision'] === $wantDecision && $r['rule'] === $wantRule, "{$r['decision']} ({$r['rule']}): {$r['why']}"];
}

/** Every case, each on a clean slate. [['rule','id','kind','what','pass','detail'], ...] */
function rt_run(PDO $pdo, string $only = ''): array {
    hr_install($pdo);   // schema changes commit; done before any case
    require_once __DIR__ . '/page_rules.php';
    term_demand_install($pdo);
    if (function_exists('pr_install')) pr_install($pdo);
    rt_cleanup($pdo);
    $out = [];
    foreach (rt_cases() as [$rule, $id, $kind, $what, $fn]) {
        if ($only !== '' && $only !== (string)$rule && $only !== "{$rule}-{$id}") continue;
        try { [$pass, $detail] = $fn($pdo); }
        catch (Throwable $e) { [$pass, $detail] = [false, 'error: ' . $e->getMessage()]; }
        rt_cleanup($pdo);
        $out[] = ['rule' => $rule, 'id' => "{$rule}-{$id}", 'kind' => $kind, 'what' => $what, 'pass' => (bool)$pass, 'detail' => (string)$detail];
    }
    $dir = dirname(__DIR__) . '/storage/ruletest';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . gmdate('Y-m-d-His') . '.json', json_encode(['at' => gmdate('c'), 'results' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $out;
}
