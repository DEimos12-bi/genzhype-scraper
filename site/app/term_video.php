<?php
/* GenZHype | r127 — TERM/MEME/GAMING VIDEOS, BY ADAPTER, NOT BY NEW FORMAT.
 *
 * THE OWNER'S RULE, FOLLOWED: find the best existing pieces and only write
 * configuration/glue. The research verdict first, honestly:
 *   - Know Your Meme: NO official API (community scrapers only — too fragile
 *     to deserve deployment);
 *   - Tenor: closed to new API clients since Jan 2026;
 *   - Giphy: stickers/GIFs with zero origin metadata, and GIF-compilation
 *     content is exactly what the platforms' unoriginal-content filters bury.
 *   => NOTHING external deserves deployment. The best existing machinery is
 *      OURS, already proven: the tpl=3 timeline engine (script, artifact
 *      joins, tweet cards, receipts, judge, the r126 hook), the posters, the
 *      lane gate.
 *
 * SO THIS FILE IS AN ADAPTER: it materializes a published TERM page's own
 * stored evidence — dated citations (the truth gate demanded them), the
 * embedded origin tweet, trend numbers — into the dramas+events shape the
 * timeline engine already consumes. Zero changes to the maker, the feed,
 * the writer or the posters. A slang/meme/gaming explainer becomes just
 * another timeline story: origin -> spread -> the ban/peak -> where it
 * stands today. Which also keeps us on the right side of the originality
 * filters: our narration over dated receipts, never a clip compilation.
 *
 * SUPPLY HONESTY: a term qualifies ONLY with >= 4 dated events (the writer's
 * own floor). Today that is a small set; every new term the truth gate
 * passes ships with a dated origin artifact, so the pool grows by itself. */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/video_factory.php';

/** Build drama+events for one published term page and generate its timeline
 *  script. Returns null on success, or a plain-language reason string. */
function term_video_materialize(PDO $pdo, int $pageId): ?string {
    $row = $pdo->prepare(
        "SELECT p.id, p.slug, p.h1, p.cover, p.status, p.type, t.*
           FROM pages p JOIN terms t ON t.page_id = p.id
          WHERE p.id = ?");
    $row->execute([$pageId]);
    $t = $row->fetch();
    if (!$t) { return 'not a term page'; }
    if ($t['status'] !== 'published') { return 'page not published'; }
    if ((string)$t['cover'] === '') { return 'no cover image'; }

    // ---- collect DATED material (the engine runs on dates or not at all) --
    $items = [];
    $od = trim((string)$t['origin_date']);
    if ($od !== '' && strtotime($od)) {
        $items[] = [
            'date' => date('Y-m-d', strtotime($od)),
            'title' => "'" . $t['term'] . "' first appears",
            'desc' => mb_substr(trim((string)$t['origin'] ?: (string)$t['short_def']), 0, 400),
            'url' => (string)$t['origin_url'],
            'pub' => 'origin artifact',
        ];
    }
    foreach ((array)json_decode((string)$t['citations'], true) as $c) {
        if (!is_array($c)) { continue; }
        $cd = trim((string)($c['date'] ?? ''));
        if ($cd === '' || !strtotime($cd)) { continue; }
        $items[] = [
            'date' => date('Y-m-d', strtotime($cd)),
            'title' => mb_substr(trim((string)($c['title'] ?? '')), 0, 180),
            'desc' => mb_substr(trim((string)($c['title'] ?? '')), 0, 400),
            'url' => (string)($c['url'] ?? ''),
            'pub' => (string)($c['publication'] ?? ($c['platform'] ?? '')),
        ];
    }
    if (count($items) < 3) {
        return 'only ' . count($items) . ' dated event(s) — the timeline floor is 4 '
             . '(today-status adds one). Terms passing the current truth gate ship dated.';
    }

    // Present-day status from the trend engine's own numbers — deterministic,
    // no AI, nothing invented.
    $dj = (array)json_decode((string)$t['data_json'], true);
    $statusBits = array_filter([
        (string)$t['status_label'] ? 'status: ' . $t['status_label'] : '',
        isset($dj['direction']) ? 'mentions ' . $dj['direction'] : '',
        isset($dj['growth_pct']) ? ((int)$dj['growth_pct'] >= 0 ? '+' : '') . (int)$dj['growth_pct'] . '% recently' : '',
    ]);
    $items[] = [
        'date' => gmdate('Y-m-d'),
        'title' => "Where '" . $t['term'] . "' stands today",
        'desc' => 'Today, ' . ($statusBits ? implode(', ', $statusBits) : 'the term is still in circulation') . '.',
        'url' => '',
        'pub' => 'GenZHype trend tracker',
    ];

    usort($items, fn($a, $b) => strcmp($a['date'], $b['date']));

    // ---- materialize (idempotent: re-running refreshes events) -----------
    $did = $pdo->prepare("SELECT id FROM dramas WHERE page_id = ?");
    $did->execute([$pageId]);
    $dramaId = (int)$did->fetchColumn();
    if (!$dramaId) {
        $pdo->prepare(
            "INSERT INTO dramas (page_id, title, lifecycle, started_on, primary_kw, background, mood, people_json)
             VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$pageId,
                       "How '" . $t['term'] . "' took over the internet",
                       'resolved', $items[0]['date'], (string)$t['term'],
                       json_encode([mb_substr((string)$t['short_def'], 0, 400)]), 'neutral', '[]']);
        $dramaId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("DELETE FROM events WHERE drama_id = ?")->execute([$dramaId]);
    }

    $srcIns = $pdo->prepare(
        "INSERT INTO sources (url, domain, publisher, title, reliability, retrieved_on)
         VALUES (?,?,?,?,2,CURDATE())");
    $evIns = $pdo->prepare(
        "INSERT INTO events (drama_id, event_date, title, description, source_id,
                             is_confirmed, sort_order, embed_html, embed_provider, video_only)
         VALUES (?,?,?,?,?,1,?,?,?,0)");

    // The term's stored origin tweet is a REAL artifact (rc_tweet_id reads
    // embed_html) — it rides the earliest event, where an origin tweet belongs.
    $tweetHtml = ((string)$t['scene_embed_provider'] === 'twitter')
               ? (string)$t['scene_embed_html'] : '';

    foreach ($items as $i => $it) {
        $sid = null;
        if ($it['url'] !== '') {
            $srcIns->execute([$it['url'],
                              parse_url($it['url'], PHP_URL_HOST) ?: '',
                              mb_substr($it['pub'], 0, 120),
                              mb_substr($it['title'], 0, 250)]);
            $sid = (int)$pdo->lastInsertId();
        }
        $evIns->execute([$dramaId, $it['date'], $it['title'], $it['desc'], $sid,
                         $i, ($i === 0 && $tweetHtml !== '') ? $tweetHtml : null,
                         ($i === 0 && $tweetHtml !== '') ? 'twitter' : null]);
    }

    // ---- video_scripts stub, then the EXISTING writer does everything ----
    $pdo->prepare(
        "INSERT INTO video_scripts (page_id, slug, title, hook, script, image, tpl, gravity)
         VALUES (?,?,?,?,?,?,3,'normal')
         ON DUPLICATE KEY UPDATE tpl=3")
        ->execute([$pageId, (string)$t['slug'],
                   "How '" . $t['term'] . "' took over the internet",
                   '', '', url((string)$t['cover'])]);

    $payload = video_write_timeline_script($pdo, $pageId);
    if (!$payload) {
        return 'the timeline writer declined (check error log — usually visual supply)';
    }
    return null;
}

/** All published terms that currently qualify. */
function term_video_candidates(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query(
        "SELECT t.page_id, t.term, t.citations, t.origin_date
           FROM terms t JOIN pages p ON p.id = t.page_id
          WHERE p.status='published' AND p.cover<>''
            AND NOT EXISTS (SELECT 1 FROM video_scripts v
                             WHERE v.page_id = t.page_id AND v.video_status='ready')") as $r) {
        $n = 0;
        foreach ((array)json_decode((string)$r['citations'], true) as $c) {
            if (!empty($c['date']) && strtotime((string)$c['date'])) { $n++; }
        }
        if (!empty($r['origin_date']) && strtotime((string)$r['origin_date'])) { $n++; }
        if ($n >= 3) { $out[] = ['page_id' => (int)$r['page_id'],
                                 'term' => (string)$r['term'], 'dated' => $n]; }
    }
    return $out;
}
