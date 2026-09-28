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
            $html = view('glossary', ['g' => repo_glossary('slang')]);
            $ok = preg_match('#id="' . RT_PREFIX . 'r4-why".*?Why now: ([^<]*)#s', $html, $m);
            return [(bool)$ok, $ok ? 'Why now: ' . $m[1] : 'no why-now line'];
        }],
        [4, 'wiktionary-link', 'fix', 'Wiktionary named in an entry links to its page', function (PDO $pdo) {
            rt_need('repo_glossary');
            rt_term($pdo, 'r4-wikt', ['status' => 'archived', 'term' => 'zz ruletest wikt', 'redirect_to' => '/slang/glossary/#' . RT_PREFIX . 'r4-wikt',
                'meaning' => ['A made-up word for the test set, defined in its own way (Wiktionary) and used nowhere else.']]);
            $html = view('glossary', ['g' => repo_glossary('slang')]);
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
            $html = view('glossary', ['g' => repo_glossary('slang')]);
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
    ];
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
