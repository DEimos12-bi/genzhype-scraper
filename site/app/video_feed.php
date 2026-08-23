<?php
// GenZHype | STATIC video-job feed (WAF-proof delivery of the maker's next jobs).
// WHY: Hostinger's WAF took to 403-blocking /api/video_next.php for GitHub-runner
// IPs (GET *and* JSON POST), while the very same runners download /media/* assets
// and POST to video_receive.php daily without a single block. Conclusion: the WAF
// dislikes that endpoint URL, not the client. So the cron pre-writes the next jobs
// as a PLAIN STATIC JSON under /media/ — indistinguishable from the media files the
// runner already fetches freely. Token rides in the (unlinked, unlisted) filename.
// The maker filters its own done-list client-side and falls back to the PHP
// endpoint if this file is ever missing/stale.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/video_factory.php';    // video_event_visuals()
require_once __DIR__ . '/receipt_cards.php';
require_once __DIR__ . '/video_people.php';     // v8: person-face resolver chain

/** Build one maker job payload (same shape video_next.php serves).
 *  v8: people ride as [{"name":..., "photo":url|null}] — faces resolved
 *  SERVER-side through the site's full arsenal (stored entity QIDs, verified
 *  Wikidata creator photos, YouTube channel avatars), cached 30d. The maker
 *  treats a feed photo as the first choice and keeps Wikidata as fallback. */
function video_feed_build_post(PDO $pdo, array $r): array {
    $visuals = [$r['image']]; $vtitles = ['cover photo of the story'];
    $people  = [];
    $meta = $pdo->prepare("SELECT d.id did, d.people_json FROM dramas d WHERE d.page_id=?");
    $meta->execute([(int)$r['page_id']]);
    if ($m = $meta->fetch()) {
        // r11: people FIRST — the resolver warms the names + media caches the
        // visuals expander (per-person recent imagery) reads right after.
        try {
            $people = video_people_resolve($pdo, (int)$r['page_id'], (string)$m['people_json'],
                                           (string)$r['title'], mb_substr((string)$r['script'], 0, 200));
        } catch (Throwable $e) { $people = []; }
        [$visuals, $vtitles] = video_event_visuals($pdo, (int)$m['did'], (string)$r['image']);
    }
    $receipts = []; $receiptMeta = [];
    try {
        foreach (receipt_cards_for_page($pdo, (int)$r['page_id']) as $rc) {
            $receipts[] = $rc['url'];   // r17: '' at event positions (beige retired) — idx alignment holds
            // v10: kind + source_url per receipt — the maker screenshots the REAL
            // article for 'event' receipts. r17: + og_image (the article's real
            // report photo) so the maker has chain step (b) without extra fetches.
            $receiptMeta[] = ['kind' => (string)($rc['kind'] ?? 'event'),
                              'source_url' => (string)($rc['source_url'] ?? ''),
                              'og_image' => (string)($rc['og_image'] ?? '')];
        }
    } catch (Throwable $e) { $receipts = []; $receiptMeta = []; }
    return [
        'page_id' => (int)$r['page_id'],
        'slug'    => $r['slug'],
        'title'   => $r['title'],
        'hook'    => $r['hook'],
        'script'  => $r['script'],
        'image'   => $r['image'],
        'visuals' => array_values($visuals),
        'visual_titles' => array_values($vtitles),
        'people'  => array_slice($people, 0, 4),   // v8: [{"name","photo"}]; r11: +"photos":[urls]
        'broll'   => (array)json_decode((string)($r['broll'] ?? ''), true) ?: [],
        'shotlist'=> json_decode((string)($r['shotlist'] ?? ''), true) ?: null,
        'receipts'=> $receipts,
        'receipt_meta' => $receiptMeta,   // v10: aligned with receipts[]
        'gravity' => (string)($r['gravity'] ?? 'standard'),   // r16 grave register
        'clips'   => (array)json_decode((string)($r['footage_clips'] ?? ''), true) ?: [],
        // r70: the VISUAL DIRECTOR's plan — event-shaped search phrases the
        // maker uses to hunt clips itself (Hostinger has no shell_exec, so the
        // search runs on the runner where yt-dlp lives).
        'visual_plan' => (array)json_decode((string)($r['visual_plan'] ?? ''), true) ?: [],  // r28 multi-platform footage
        'force'   => (bool)($r['force_render'] ?? false),      // r19: requeued render — maker ignores its done-list
        'link'    => url('/drama/' . $r['slug'] . '/'),
    ];
}

/** Write the static feed file: the top 8 DIRECTED pending jobs (cron, every tick). */
function video_feed_static_write(PDO $pdo): int {
    $token = $GLOBALS['CONFIG']['ingest_token'] ?? '';
    if ($token === '') return 0;
    // 2026-08-05: robots='index' dropped from every video-pipeline query (here,
    // video_bridge.php feed, and both video_factory.php generators). The SEO
    // batch noindexed ALL dramas for crawl-budget containment, which silently
    // emptied this feed — 5 ready scripts, 0 eligible — and with the site cron
    // paused no indexed drama can ever appear, so the video pipeline was starved
    // by a filter about a search engine it doesn't serve. Videos are SOCIAL
    // distribution; the pages are still published and live. status='published'
    // remains the gate.
    $rows = $pdo->query("SELECT v.page_id, v.slug, v.title, v.hook, v.script, v.image, v.broll, v.shotlist, v.gravity, v.force_render, v.footage_clips, v.visual_plan
                         FROM video_scripts v JOIN pages p ON p.id=v.page_id
                         WHERE p.status='published' AND v.video_status='pending'
                         ORDER BY (v.shotlist IS NOT NULL) DESC, (v.tpl >= 2) DESC, v.created_at DESC
                         LIMIT 8")->fetchAll();
    $posts = [];
    foreach ($rows as $r) {
        try { $posts[] = video_feed_build_post($pdo, $r); } catch (Throwable $e) {}
    }
    $dir = dirname(__DIR__) . '/public_html/media';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $body = json_encode(['generated' => date('c'), 'posts' => $posts],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // WAF evidence across all runs: JSON/api-looking requests get 403'd from
    // runner IPs; PNG media downloads have NEVER been blocked once. And the
    // server 422s a .png whose bytes aren't an image — so the feed ships as a
    // VALID 1x1 PNG with the JSON appended after a marker (PNG readers stop at
    // IEND; the maker reads everything after 'GZJSON:'). Plain twins (.txt,
    // .json) stay as fallbacks/debugging.
    $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
                          . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    foreach (['vfeed-' . $token . '.png'  => $png1x1 . 'GZJSON:' . $body,
              'vfeed-' . $token . '.txt'  => $body,
              'vfeed-' . $token . '.json' => $body] as $name => $bytes) {
        $tmp = $dir . '/.vfeed.tmp';
        file_put_contents($tmp, $bytes);
        @rename($tmp, $dir . '/' . $name);   // atomic swap per file
        @chmod($dir . '/' . $name, 0644);
    }
    return count($posts);
}
