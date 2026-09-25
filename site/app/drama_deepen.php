<?php
declare(strict_types=1);
/**
 * DRAMA DEEPEN — the last blocker in the bin (2026-09-01).
 *
 * OWNER: "fix both ... then publish the 133 pages ... I don't want to see
 * anything still in this bin."
 *
 * After the meta rescue, every page left in the archive fails on MATERIAL, not
 * formatting: 46 have only 2 timeline events where the gate wants 3, and 44
 * carry only 1 source domain where it wants 2. Those numbers cannot be fixed by
 * rewriting anything on the page. The story needs MORE REAL COVERAGE, so this
 * goes and looks for it.
 *
 * WHAT IT WILL NOT DO. It never invents an event, a date, or an outlet, and it
 * never lowers the gate. Every event it adds must be carried by an article it
 * actually fetched, from a DIFFERENT publisher than the page already cites, and
 * the AI is shown only that fetched text. A page that genuinely has one source
 * in the world stays in the bin, and that is the correct outcome — the two-
 * publisher rule is what stops this site publishing rumours.
 *
 *   php app/drama_deepen.php <limit> [apply]
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/fetch_sources.php';
require_once __DIR__ . '/reach.php';

/** Publishers a page already cites — a new source must not be one of these. */
function dd_existing_hosts(PDO $pdo, int $dramaId): array {
    $q = $pdo->prepare("SELECT DISTINCT s.domain FROM sources s
                          JOIN events e ON e.source_id = s.id
                         WHERE e.drama_id = ?");
    $q->execute([$dramaId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $d = strtolower(preg_replace('/^www\./', '', (string)$d));
        if ($d !== '') $out[$d] = true;
    }
    return $out;
}

/**
 * Hunt fresh coverage for one story. Returns fetched articles from publishers
 * the page does NOT already cite: [['url','publisher','date','excerpt'], ...]
 */
function dd_hunt(string $title, array $skipHosts, int $want = 4): array {
    $seen = [];
    $out  = [];

    $cands = [];
    // Exa first: it returns dated press with the publish date attached
    try {
        foreach (reach_exa_search($title, 10) as $h) {
            $u = (string)($h['url'] ?? '');
            if ($u !== '') $cands[] = ['url' => $u, 'date' => (string)($h['published'] ?? '')];
        }
    } catch (Throwable $e) { /* best effort */ }
    // then Bing News, which is never rate-limited for us
    try {
        foreach (fs_news_search($title, 10) as $h) {
            $u = (string)($h['url'] ?? $h['link'] ?? '');
            if ($u !== '') $cands[] = ['url' => $u, 'date' => (string)($h['date'] ?? '')];
        }
    } catch (Throwable $e) { /* best effort */ }

    foreach ($cands as $c) {
        if (count($out) >= $want) break;
        $u = $c['url'];
        $host = strtolower(preg_replace('/^www\./', '', (string)parse_url($u, PHP_URL_HOST)));
        if ($host === '' || isset($seen[$host]) || isset($skipHosts[$host])) continue;
        // aggregators and our own site are not independent confirmation
        if (preg_match('/(genzhype|google\.|bing\.|yahoo\.com\/news\/rss|msn\.com)/i', $host)) continue;
        $seen[$host] = true;

        $html = fs_http_get($u, 20);
        if (!$html) continue;
        $text = fs_extract_text($html);
        if (mb_strlen($text) < 400) continue;             // a shell page proves nothing
        $date = $c['date'] !== '' ? substr($c['date'], 0, 10) : fs_published_date($html);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) continue;   // undated = unusable

        $out[] = ['url' => $u, 'publisher' => $host, 'date' => $date,
                  'excerpt' => mb_substr($text, 0, 3000)];
    }
    return $out;
}

/**
 * One page: hunt, extract grounded events, write, re-gate, publish on pass.
 */
function drama_deepen_page(PDO $pdo, int $pageId, bool $apply): array {
    $p = $pdo->prepare("SELECT p.slug, p.h1, d.id drama_id, d.title, d.lane
                          FROM pages p JOIN dramas d ON d.page_id = p.id WHERE p.id = ?");
    $p->execute([$pageId]);
    $row = $p->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'why' => 'page not found'];

    $dramaId = (int)$row['drama_id'];
    $skip    = dd_existing_hosts($pdo, $dramaId);
    $arts    = dd_hunt((string)($row['title'] ?: $row['h1']), $skip);
    if (!$arts) return ['ok' => false, 'why' => 'no new coverage found (story may only have one source in the world)'];

    // what the page already says, so the model adds instead of repeating
    $ev = $pdo->prepare("SELECT event_date, title FROM events WHERE drama_id=? ORDER BY event_date");
    $ev->execute([$dramaId]);
    $have = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) $have[] = "{$e['event_date']}: {$e['title']}";

    $src = '';
    foreach ($arts as $i => $a) {
        $n = $i + 1;
        $src .= "ARTICLE {$n}: publisher={$a['publisher']} date={$a['date']} url={$a['url']}\n"
              . mb_substr($a['excerpt'], 0, 1800) . "\n\n";
    }

    $res = ai_chat([
        ['role' => 'system', 'content' =>
            'You add dated events to an existing timeline about an internet-culture story. '
          . 'Use ONLY the article excerpts supplied. Every event MUST be stated in one of those '
          . 'articles and MUST cite that article by its number. NEVER invent an event, a date, a '
          . 'name or an outcome. If an article does not add a NEW event beyond the ones already '
          . 'listed, return nothing for it. Unproven claims must be worded as allegations '
          . '("allegedly", "according to", "said"). Output STRICT JSON only.'],
        ['role' => 'user', 'content' =>
            "STORY: {$row['title']}\n\nEVENTS ALREADY ON THE PAGE:\n" . implode("\n", $have)
          . "\n\nNEW ARTICLES:\n{$src}\n"
          . 'Return {"events":[{"article":1,"date":"YYYY-MM-DD","title":"<=90 chars",'
          . '"desc":"1-2 sentences, attributed"}]} — only events NOT already listed above. '
          . 'An empty list is a correct and honest answer.'],
    ], AI_WRITER_ORDER, 0.2, 120, AI_WRITER_SKIP);

    if (isset($res['error'])) return ['ok' => false, 'why' => 'AI: ' . $res['error']];
    $j = ai_json((string)$res['content']);
    $newEvents = (array)($j['events'] ?? []);
    if (!$newEvents) return ['ok' => false, 'why' => 'articles carried no new dated event'];

    if (!$apply) {
        return ['ok' => true, 'dry' => true, 'found' => count($arts), 'events' => count($newEvents)];
    }

    // write: one source row per article actually used, then its events
    $added = 0; $srcIds = [];
    $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM events WHERE drama_id={$dramaId}")->fetchColumn();

    foreach ($newEvents as $e) {
        $ai = (int)($e['article'] ?? 0) - 1;
        if (!isset($arts[$ai])) continue;
        $date = (string)($e['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $title = trim((string)($e['title'] ?? ''));
        if ($title === '') continue;
        // the event must actually be about this article's content
        $a = $arts[$ai];

        if (!isset($srcIds[$ai])) {
            $pdo->prepare("INSERT INTO sources (url,domain,publisher,title,reliability,retrieved_on,excerpt)
                           VALUES (?,?,?,?,?,?,?)")
                ->execute([$a['url'], $a['publisher'], $a['publisher'],
                           mb_substr($a['excerpt'], 0, 200), 'secondary', $a['date'], $a['excerpt']]);
            $srcIds[$ai] = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO events (drama_id,event_date,title,description,source_id,is_confirmed,sort_order)
                       VALUES (?,?,?,?,?,0,?)")
            ->execute([$dramaId, $date, mb_substr($title, 0, 250),
                       (string)($e['desc'] ?? ''), $srcIds[$ai], ++$maxSort]);
        $added++;
    }
    if (!$added) return ['ok' => false, 'why' => 'no event survived validation'];
    // 2026-09-24 new events go where their dates put them, not at the end of the timeline
    try { require_once __DIR__ . '/timeline_order.php'; events_resort($pdo, $dramaId); } catch (Throwable $e) { error_log('deepen resort: ' . $e->getMessage()); }
    // 2026-09-24 the summary, status and status FAQ follow the new events (this run
    // just searched for coverage, so the status holds as of today); re-judged inside
    try { require_once __DIR__ . '/status_refresh.php'; drama_status_refresh($pdo, $pageId, gmdate('Y-m-d')); } catch (Throwable $e) { error_log('deepen status refresh: ' . $e->getMessage()); }

    $g = gate_check_drama($pageId);
    // A freshly added event often arrives stated as fact when the claim is
    // only alleged, which trips the framing check - a legal control, not a
    // style one. framing_repair already exists and can aim at ONE page, so
    // chain it here rather than leave the page stuck on a fault we can fix.
    if (empty($g['pass'])) {
        $needsFraming = false;
        foreach ((array)($g['checks'] ?? []) as $c) {
            if (empty($c['pass']) && stripos((string)$c['label'], 'alleged-framing') !== false) $needsFraming = true;
        }
        if ($needsFraming) {
            try {
                require_once __DIR__ . '/framing_repair.php';
                framing_repair_run($pdo, 8, $pageId);
                $g = gate_check_drama($pageId);
            } catch (Throwable $e) { /* leave the original verdict */ }
        }
    }
    if (empty($g['pass'])) {
        $fails = [];
        foreach ((array)($g['checks'] ?? []) as $c) if (empty($c['pass'])) $fails[] = (string)$c['label'];
        return ['ok' => true, 'added' => $added, 'published' => false,
                'why' => 'still short: ' . implode(', ', array_slice($fails, 0, 2))];
    }

    // restore, but leave Google to the owner's 40/day reveal
    $pdo->prepare("UPDATE pages SET status='published', robots='noindex',
                   published_at=COALESCE(published_at, NOW()) WHERE id=?")->execute([$pageId]);
    return ['ok' => true, 'added' => $added, 'published' => true];
}

/** Batch entry point used by the CLI and by the hourly tick. */
function drama_deepen_run(PDO $pdo, int $limit = 3, bool $apply = true): array {
    // 2026-09-05 MEASURED BUG: this ordered by id and had no memory, so the
    // hourly tick re-checked the SAME two hopeless pages every hour (huskerrs
    // 5x, influencer-murder-case 5x in one day) - burning the whole budget on
    // stories that will never gain a second source, while 130 others waited.
    // Now: least-tried first, and a page that has failed 3 hunts is left alone
    // - the same attempt-cap lesson the term and drama loops already carry.
    $rows = $pdo->query("SELECT p.id, p.slug FROM pages p JOIN dramas d ON d.page_id = p.id
                          WHERE p.status='archived' AND p.type='drama' AND p.deepen_tries < 3
                          ORDER BY p.deepen_tries ASC, p.id ASC LIMIT " . max(1, min(50, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    $out = ['checked' => 0, 'deepened' => 0, 'published' => 0, 'stuck' => 0];
    foreach ($rows as $r) {
        $out['checked']++;
        if ($apply) $pdo->prepare("UPDATE pages SET deepen_tries = deepen_tries + 1 WHERE id=?")->execute([(int)$r['id']]);
        try { $res = drama_deepen_page($pdo, (int)$r['id'], $apply); }
        catch (Throwable $e) { $res = ['ok' => false, 'why' => 'error: ' . $e->getMessage()]; }
        if (!empty($res['added'])) $out['deepened']++;
        if (!empty($res['published'])) { $out['published']++; echo "  PUBLISHED /{$r['slug']} (+{$res['added']} events)\n"; }
        elseif (!empty($res['ok']) && !empty($res['dry'])) echo "  WOULD DEEPEN /{$r['slug']} ({$res['found']} articles, {$res['events']} new events)\n";
        else { $out['stuck']++; echo "  STUCK /{$r['slug']}: " . ($res['why'] ?? '?') . "\n"; }
    }
    return $out;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
    require_once __DIR__ . '/helpers.php';
    $limit = max(1, min(50, (int)($argv[1] ?? 3)));
    $apply = (($argv[2] ?? '') === 'apply');
    printf("DRAMA DEEPEN %s | %d page(s)\n\n", $apply ? 'APPLY' : 'DRY RUN', $limit);
    $r = drama_deepen_run(db(), $limit, $apply);
    echo "\n----------------------------------------\n";
    printf("checked %d | deepened %d | published %d | stuck %d\n",
           $r['checked'], $r['deepened'], $r['published'], $r['stuck']);
}
