<?php
// GenZHype | REACH INGEST (2026-08-05). Takes a checkout of the reach-drop
// branch (the 6-hourly runner harvest: reddit-ootl.json from rdt-cli,
// x-search.json from twitter-cli) and normalises it into app/reach_cache.json
// for discovery to read. The server cannot reach Reddit at all (403 even
// cookie'd — IP wall) and cannot search X, so this cache IS those platforms
// as far as the server is concerned.
//   Usage: php app/reach_ingest.php <dropdir>
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
$dir = rtrim((string)($argv[1] ?? ''), '/');
if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "usage: reach_ingest.php <dropdir>\n"); exit(2); }

$out = ['at' => date('c'), 'reddit_titles' => [], 'x_posts' => []];

// rdt-cli listing shape: {ok, data:{kind:Listing, data:{children:[{data:{title,...}}]}}}
$rj = json_decode((string)@file_get_contents($dir . '/reddit-ootl.json'), true);
foreach (($rj['data']['data']['children'] ?? []) as $ch) {
    $t = trim((string)($ch['data']['title'] ?? ''));
    if ($t !== '') $out['reddit_titles'][] = mb_substr($t, 0, 300);
}

// twitter-cli search shape: {ok, data:[{id,text,author:{screenName},metrics:{likes}}]}
$xj = json_decode((string)@file_get_contents($dir . '/x-search.json'), true);
foreach (($xj['data'] ?? []) as $tw) {
    $txt = trim((string)($tw['text'] ?? ''));
    if ($txt === '') continue;
    $out['x_posts'][] = [
        'text'   => mb_substr($txt, 0, 400),
        'author' => (string)($tw['author']['screenName'] ?? ''),
        'likes'  => (int)($tw['metrics']['likes'] ?? 0),
        'id'     => (string)($tw['id'] ?? ''),
    ];
}

file_put_contents(__DIR__ . '/reach_cache.json', json_encode($out, JSON_UNESCAPED_UNICODE));
printf("reach ingest: %d reddit title(s), %d x post(s) -> app/reach_cache.json\n",
       count($out['reddit_titles']), count($out['x_posts']));

// ---------------------------------------------------------------------------
// REACH-POWERED VIDEO (2026-08-05): x-pages.json = per-pending-page X post
// candidates from the story-driven harvest. Written into
// video_scripts.reach_posts together with a per-story Exa article search
// (extra dated screenshot targets). receipt_cards.php turns both into
// receipts: articles as screenshot events, tweet ids as X cards verified
// against the syndication CDN (tombstone -> NO card, fail closed).
//
// Any page whose reach set CHANGED gets its shotlist re-directed RIGHT HERE:
// receipts are idx-addressed by the shotlist, so new receipts under a stale
// shotlist = wrong card on wrong shot — and the site cron (the usual
// director) is paused for the SEO experiment. Direction is ~2-3 min of AI
// per page, so a cap bounds the cron; deferred pages keep their OLD
// reach_posts (receipts stay aligned) and catch up on a later tick.
// Direction failure -> reach_posts restored (video_write_shotlist only
// overwrites the shotlist on success, so restore keeps both in step).
// ---------------------------------------------------------------------------
$xp = json_decode((string)@file_get_contents($dir . '/x-pages.json'), true);
$pagesIn = (array)($xp['pages'] ?? []);
if ($pagesIn) {
    try {
        $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
        require_once __DIR__ . '/helpers.php';
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/reach.php';
        require_once __DIR__ . '/video_factory.php';   // video_write_shotlist()
        require_once __DIR__ . '/video_feed.php';      // video_feed_static_write()
        $pdo = db();
        try { $pdo->exec("ALTER TABLE video_scripts ADD COLUMN reach_posts MEDIUMTEXT NULL"); }
        catch (Throwable $e) { /* column exists */ }

        $cap = 3; $directed = 0; $deferred = 0;
        foreach ($pagesIn as $pid => $posts) {
            $pid = (int)$pid;
            if ($pid <= 0 || !is_array($posts)) continue;
            $q = $pdo->prepare(
                "SELECT v.reach_posts, v.title, v.video_status, d.people_json
                 FROM video_scripts v LEFT JOIN dramas d ON d.page_id = v.page_id
                 WHERE v.page_id = ?");
            $q->execute([$pid]);
            $row = $q->fetch();
            if (!$row || (string)$row['video_status'] !== 'pending') continue;

            // shape hygiene only — the syndication check at card-render time
            // is the real gate on every id
            $clean = [];
            foreach ($posts as $p) {
                $tid = preg_replace('/\D+/', '', (string)($p['id'] ?? ''));
                if ($tid === '') continue;
                $clean[] = ['id' => $tid,
                            'author' => mb_substr((string)($p['author'] ?? ''), 0, 60),
                            'likes'  => (int)($p['likes'] ?? 0),
                            'text'   => mb_substr((string)($p['text'] ?? ''), 0, 400)];
                if (count($clean) >= 8) break;
            }

            $oldBlob = (string)($row['reach_posts'] ?? '');
            $old = (array)json_decode($oldBlob, true);
            $oldIds = array_column((array)($old['posts'] ?? []), 'id');
            $newIds = array_column($clean, 'id');
            sort($oldIds); sort($newIds);
            // process when the post set changed OR the page has never been
            // reach-processed (a 0-post page still deserves its one-time Exa
            // article enrichment; after that, unchanged = skip, so Exa churn
            // can't re-direct a stable page every 6h)
            if ($oldIds === $newIds && $oldBlob !== '') { echo "  page {$pid}: reach posts unchanged\n"; continue; }
            if ($directed >= $cap) { $deferred++; continue; }

            // per-story Exa search: dated press for extra article screenshots
            // (best-effort — free tier, every caller survives [])
            $arts = [];
            foreach (reach_exa_search((string)$row['title'], 6) as $h) {
                if (count($arts) >= 4) break;
                if (($h['published'] ?? '') === '') continue;   // dated press only
                if (!preg_match('#^https?://#', (string)$h['url'])) continue;
                $arts[] = ['url' => (string)$h['url'],
                           'title' => mb_substr((string)$h['title'], 0, 300),
                           'published' => (string)$h['published']];
            }

            $blob = json_encode(['at' => date('c'),
                                 'posts' => $clean, 'articles' => $arts],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare("UPDATE video_scripts SET reach_posts=? WHERE page_id=?")
                ->execute([$blob, $pid]);

            $ppl = [];
            foreach ((array)json_decode((string)($row['people_json'] ?? ''), true) as $pp) {
                $nm = trim((string)(is_array($pp) ? ($pp['name'] ?? '') : $pp));
                if ($nm !== '') $ppl[] = $nm;
            }
            $sl = null;
            try { $sl = video_write_shotlist($pdo, $pid, $ppl); }
            catch (Throwable $e) { echo "  page {$pid}: direction threw: {$e->getMessage()}\n"; }
            if ($sl) {
                $directed++;
                printf("  page %d: %d post(s) + %d article(s), re-directed (%d shots)\n",
                       $pid, count($clean), count($arts), count($sl));
            } else {
                $pdo->prepare("UPDATE video_scripts SET reach_posts=? WHERE page_id=?")
                    ->execute([$oldBlob !== '' ? $oldBlob : null, $pid]);
                echo "  page {$pid}: direction FAILED — reach_posts restored, old shotlist stands\n";
            }
        }
        if ($directed) {
            try { video_feed_static_write($pdo); echo "  static feed rewritten\n"; }
            catch (Throwable $e) { echo "  static feed rewrite failed: {$e->getMessage()}\n"; }
        }
        printf("reach video: %d page(s) re-directed, %d deferred to next tick\n", $directed, $deferred);
    } catch (Throwable $e) {
        // discovery cache above already landed — video enrichment must never
        // fail the whole ingest (DB-outage-survivable, same rule as the front)
        fwrite(STDERR, "reach video enrichment failed: {$e->getMessage()}\n");
    }
}
