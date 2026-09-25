<?php
require_once __DIR__ . '/lanes.php';   // timeline_url() is used from line ~116 onward (2026-08-31)
// GenZHype | DB read-layer. Assembles the same $DATA shape app/data.php used,
// so templates need no changes. Small site = load-all is fine; optimize later.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/story_context.php';
require_once __DIR__ . '/creator_stats.php';
require_once __DIR__ . '/gate.php';   // gate_proof_label()
require_once __DIR__ . '/verdict.php';   // VD_METHOD on the page

/* r146 (2026-09-07) THE 2-SECOND PAGE. Measured on the live host: every request
 * ran repo_load_all() from scratch — 3,617 queries, 14 MB of assembled content,
 * 1.9 s wall for 0.27 s CPU (the rest = socket round trips to MySQL) — on the
 * home page, on every story, for every Googlebot hit. Static files answer in
 * 20 ms. Google's crawl doc: "if the site slows down ... the limit goes down";
 * 497 pages sat in "Discovered - currently not indexed" ("Google wanted to crawl
 * the URL but this was expected to overload the site; therefore Google
 * rescheduled the crawl").
 * FIX: the assembled content is cached to one file and reused until the content
 * changes. Version = newest page update + published count + newest event/source/
 * faq ids + tag-link count (two cheap queries), plus a 10-minute safety TTL.
 * Rebuild under a lock (no stampede); a stale copy is served while another
 * process rebuilds. Atomic write. One rebuild (~2 s) per content change. */
const REPO_CACHE_FILE = __DIR__ . '/cache/data.cache';
const REPO_CACHE_TTL  = 600;

function repo_data_version(PDO $pdo): string {
    try {
        $a = $pdo->query("SELECT COUNT(*) c, MAX(updated_at) u FROM pages WHERE status='published'")->fetch(PDO::FETCH_ASSOC);
        $b = $pdo->query("SELECT (SELECT MAX(id) FROM events) e, (SELECT MAX(id) FROM sources) s, (SELECT MAX(id) FROM faqs) f, (SELECT COUNT(*) FROM drama_tags) t,
                                 (SELECT MAX(id) FROM creator_stats) c")->fetch(PDO::FETCH_ASSOC);   // c: a new daily reading shows on the page
        return md5(json_encode([$a, $b]));
    } catch (Throwable $e) { return 'v-' . (int)(time() / REPO_CACHE_TTL); }
}

function repo_load_all_cached(): array {
    $pdo = db();
    $ver = repo_data_version($pdo);
    $file = REPO_CACHE_FILE;
    // r148: the VERSION decides, not the clock. The first version also required
    // the file to be younger than the TTL, so an unchanged site still rebuilt
    // every 10 minutes and one visitor an hour paid 2 seconds for nothing —
    // and that visitor can be Googlebot. The TTL survives only as the safety net
    // for the case where the version query itself failed (it returns a value
    // that rotates on its own).
    if (is_file($file)) {
        $raw = (string)@file_get_contents($file);
        if ($raw !== '' && str_starts_with($raw, $ver . "\n")) {
            $data = @unserialize(substr($raw, strlen($ver) + 1), ['allowed_classes' => false]);
            if (is_array($data) && isset($data['dramas'])) return $data;
        }
    }
    @mkdir(dirname($file), 0755, true);
    $lock = @fopen($file . '.lock', 'c');
    $have = $lock && flock($lock, LOCK_EX | LOCK_NB);
    if (!$have && is_file($file)) {           // another process is rebuilding: serve what exists
        $raw = (string)@file_get_contents($file);
        $data = $raw !== '' ? @unserialize(substr($raw, strpos($raw, "\n") + 1), ['allowed_classes' => false]) : null;
        if ($lock) fclose($lock);
        if (is_array($data) && isset($data['dramas'])) return $data;
    }
    $data = repo_load_all();
    try {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $ver . "\n" . serialize($data)) !== false) @rename($tmp, $file);
    } catch (Throwable $e) {}
    if ($lock) { if ($have) flock($lock, LOCK_UN); fclose($lock); }
    return $data;
}
function repo_load_all(): array {
    $pdo = db();
    $data = ['dramas' => [], 'creators' => [], 'terms' => []];

    // creators (profile pages)
    $rows = $pdo->query("SELECT p.slug, p.summary, c.name, c.aka, c.platforms, c.bio, p.meta_desc
                         FROM pages p JOIN creators c ON c.page_id = p.id
                         WHERE p.type='creator' AND p.status='published'")->fetchAll();
    foreach ($rows as $r) {
        $aka = json_decode($r['aka'] ?? '[]', true) ?: [];
        $platforms = [];
        foreach (json_decode($r['platforms'] ?? '[]', true) ?: [] as $pl) {
            $platforms[] = ['p' => $pl['p'] ?? '', 'followers' => $pl['followers'] ?? ''];
        }
        $data['creators'][$r['slug']] = [
            'slug'      => $r['slug'],
            'name'      => $r['name'],
            'handle'    => $aka[0] ?? ('@' . $r['slug']),
            'known_for' => $r['summary'] ?? '',
            'platforms' => $platforms,
            'bio'       => $r['bio'] ?? '',
            'meta_desc' => $r['meta_desc'] ?? '',
        ];
    }

    // dramas
    $rows = $pdo->query("SELECT p.id page_id, p.slug, p.h1, p.title_tag, p.meta_desc, p.summary, p.cover, p.featured_img,
                                p.published_at, p.updated_at, p.robots, p.cover_credit, p.cover_credit_url,
                                d.id drama_id, d.title, d.lifecycle, d.background, d.people_json, d.why_matters, d.whats_next, d.both_sides, d.verdict,
                                COALESCE(d.lane, 'drama') lane
                         FROM pages p JOIN dramas d ON d.page_id = p.id
                         WHERE p.type='drama' AND p.status='published'
                         ORDER BY p.updated_at DESC")->fetchAll();
    $tracked = cs_numbers($pdo);   // our own numbers, all stories in one query
    foreach ($rows as $r) {
        $did = (int)$r['drama_id'];

        $events = [];
        $ev = $pdo->prepare("SELECT event_date, title, description, source_id, is_confirmed, confirmed_by, embed_note, embed_html, embed_provider, why_matters
                             FROM events WHERE drama_id=? AND video_only=0 ORDER BY sort_order, event_date");
        $ev->execute([$did]);
        foreach ($ev->fetchAll() as $e) {
            $events[] = [
                'date_iso' => $e['event_date'],
                'date'     => date('M j, Y', strtotime($e['event_date'])),
                'title'    => $e['title'] ?? '',
                'desc'     => $e['description'],
                'sources'  => $e['source_id'] ? [(int)$e['source_id']] : [],
                'embed'    => $e['embed_note'],
                'embed_html' => $e['embed_html'] ?? null,
                'embed_provider' => $e['embed_provider'] ?? null,
                'why'      => $e['why_matters'],
                'confirmed' => (int)$e['is_confirmed'] === 1 ? gate_proof_label($e['confirmed_by'] ?? '') : '',   // '' = a claim
            ];
        }

        // PRIMARY: entity-resolved people (Citation Engine) — name + role + sameAs.
        $parties = [];
        foreach ((json_decode($r['people_json'] ?? '[]', true) ?: []) as $pe) {
            if (empty($pe['name'])) continue;
            $parties[] = ['name' => $pe['name'], 'role' => $pe['role'] ?? '', 'sameAs' => $pe['sameAs'] ?? [], 'slug' => null];
        }
        if (!$parties) {  // fallback to the legacy creator-pages parties table
            $pq = $pdo->prepare("SELECT cp.slug, c.name, cp2.summary role_text
                                 FROM parties pa JOIN creators c ON c.id = pa.creator_id
                                 JOIN pages cp ON cp.id = c.page_id JOIN pages cp2 ON cp2.id = c.page_id
                                 WHERE pa.drama_id=?");
            $pq->execute([$did]);
            foreach ($pq->fetchAll() as $p2)
                $parties[] = ['slug' => $p2['slug'], 'name' => $p2['name'], 'role' => $p2['role_text'] ?? '', 'sameAs' => []];
        }

        $faqs = [];
        $fq = $pdo->prepare("SELECT question, answer FROM faqs WHERE drama_id=? ORDER BY sort_order");
        $fq->execute([$did]);
        foreach ($fq->fetchAll() as $f) $faqs[] = ['q' => $f['question'], 'a' => $f['answer']];

        $sources = []; $srcExcerpts = [];
        $sq = $pdo->prepare("SELECT DISTINCT s.id, s.url, s.publisher, s.title, s.retrieved_on, s.excerpt
                             FROM events e JOIN sources s ON s.id = e.source_id
                             WHERE e.drama_id=? ORDER BY s.id");
        $sq->execute([$did]);
        foreach ($sq->fetchAll() as $s) {
            $sources[] = ['id' => (int)$s['id'], 'url' => $s['url'] ?? null, 'text' => trim(($s['publisher'] ?? '') . ', ' . ($s['title'] ?? '') . ', ' . date('M j, Y', strtotime($s['retrieved_on'] ?? 'now')) . '.')];
            if (!empty($s['excerpt'])) $srcExcerpts[] = ['excerpt' => $s['excerpt'], 'publisher' => $s['publisher'] ?? ''];
        }
        $pullQuote = find_pull_quote($srcExcerpts, true);   // safe=true filters grim/sensitive quotes per-quote

        $related = [];
        $tq = $pdo->prepare("SELECT t.slug, t.label FROM drama_tags dt JOIN tags t ON t.id=dt.tag_id WHERE dt.drama_id=?");
        $tq->execute([$did]);
        foreach ($tq->fetchAll() as $t) {
            $related[] = ['url' => '/topic/' . $t['slug'] . '/', 'title' => $t['label'], 'desc' => 'More situations in this topic'];
        }
        // sibling timelines — dramas had NO related, starving crawl paths + internal PageRank on the
        // deeper pages Google left "Discovered - not indexed". Prefer dramas sharing a creator; fall
        // back to recent ones so EVERY timeline links out to 4 others.
        $sib = $pdo->prepare("SELECT p.slug, d2.title, p.meta_desc, COALESCE(d2.lane,'drama') lane FROM dramas d2 JOIN pages p ON p.id=d2.page_id
            WHERE p.status='published' AND p.robots='index' AND d2.id<>?
              AND d2.id IN (SELECT drama_id FROM parties WHERE creator_id IN (SELECT creator_id FROM parties WHERE drama_id=?))
            ORDER BY p.published_at DESC LIMIT 4");
        $sib->execute([$did, $did]);
        $sibRows = $sib->fetchAll();
        if (count($sibRows) < 4) {
            $fill = $pdo->prepare("SELECT p.slug, d2.title, p.meta_desc, COALESCE(d2.lane,'drama') lane FROM dramas d2 JOIN pages p ON p.id=d2.page_id
                WHERE p.status='published' AND p.robots='index' AND d2.id<>? ORDER BY p.published_at DESC LIMIT 7");
            $fill->execute([$did]);
            $have = array_column($sibRows, 'slug');
            foreach ($fill->fetchAll() as $fr) { if (count($sibRows) >= 4) break; if (!in_array($fr['slug'], $have, true)) $sibRows[] = $fr; }
        }
        foreach ($sibRows as $sr) {
            $related[] = ['url' => timeline_url($sr['slug'], $sr['lane'] ?? 'drama'), 'title' => $sr['title'], 'desc' => mb_substr($sr['meta_desc'] ?: 'Related timeline', 0, 80)];
        }

        // 'developing' (option A, 2026-08-22): a story published from 3-5 dated
        // events while it is still unfolding. The badge is the promise that we
        // are not passing a thin page off as a finished one.
        $lifecycleMap = ['ongoing' => 'Ongoing', 'resolved' => 'Resolved', 'dormant' => 'Dormant', 'developing' => 'Developing'];
        $data['dramas'][$r['slug']] = [
            'slug'          => $r['slug'],
            'title'         => $r['title'],
            'title_tag'     => $r['title_tag'],
            'eyebrow'       => 'Creator Drama',
            'status'        => $lifecycleMap[$r['lifecycle']] ?? 'Ongoing',
            'published_iso' => date('c', strtotime($r['published_at'])),
            'published'     => date('M j, Y', strtotime($r['published_at'])),
            'updated_iso'   => date('c', strtotime($r['updated_at'])),
            'updated'       => date('M j, Y', strtotime($r['updated_at'])),
            'cover'         => $r['cover'] ?: '/assets/covers/default.svg',
            'featured_img'  => $r['featured_img'] ?? null,
            'cover_credit'  => $r['cover_credit'] ?? null,
            'cover_credit_url' => $r['cover_credit_url'] ?? null,
            'summary'       => $r['summary'] ?? '',
            'meta_desc'     => $r['meta_desc'] ?? '',
            'background'    => json_decode($r['background'] ?? '[]', true) ?: [],
            ...story_context_shape($r['why_matters'] ?? null, $r['whats_next'] ?? null),   // why it matters / what happens next
            'tracked'       => $tracked[(int)$r['page_id']] ?? [],
            'both_sides'    => (array)json_decode((string)($r['both_sides'] ?? ''), true),   // both_sides.php
            'verdict'       => json_decode((string)($r['verdict'] ?? ''), true) ?: null,   // verdict.php
            'events'        => $events,
            'parties'       => $parties,
            'faqs'          => $faqs,
            'sources'       => $sources,
            'pull_quote'    => $pullQuote,
            'related'       => $related,
            'robots'        => $r['robots'],
            'page_id'       => (int)$r['page_id'],
            // r151: the lane was selected above but never copied here, so
            // index.php's /gaming/ route (which needs lane === 'gaming') could
            // never match and all 8 published gaming stories rendered "Page not
            // found" while the sitemap sent Google to them.
            'lane'          => $r['lane'],
        ];
    }

    // terms (encyclopedic entries: slang, memes, gaming, music)
    $publishedSlugs = [];
    foreach ($pdo->query("SELECT p.slug, p.path FROM pages p WHERE p.type='term' AND p.status='published'")->fetchAll() as $row) {
        $publishedSlugs[strtolower($row['slug'])] = $row['path'];
    }
    $rows = $pdo->query("SELECT p.id page_id, p.slug, p.h1, p.title_tag, p.meta_desc, p.summary, p.cover, p.featured_img, p.cover_credit, p.cover_credit_url,
                                p.published_at, p.updated_at, p.robots, t.*
                         FROM pages p JOIN terms t ON t.page_id = p.id
                         WHERE p.type='term' AND p.status='published'
                         ORDER BY p.updated_at DESC")->fetchAll();
    // index published terms by lane for systematic hub-and-spoke cross-linking.
    // index-only: never link a pulled (noindex) term as a sibling.
    $byLane = [];
    foreach ($rows as $r) if (($r['robots'] ?? 'index') === 'index') $byLane[$r['lane'] ?? 'slang'][] = ['term' => $r['term'], 'slug' => $r['slug'], 'short_def' => $r['short_def'] ?? ''];
    foreach ($rows as $r) {
        $shape = repo_term_shape($r, $publishedSlugs);
        $shape['siblings'] = repo_pick_siblings($byLane[$r['lane'] ?? 'slang'] ?? [], $r['slug'], lanes()[$r['lane'] ?? 'slang']['prefix'] ?? '/slang/');
        $data['terms'][$r['slug']] = $shape;
    }

    return $data;
}

/** Up to 6 OTHER published entries in the same lane, for guaranteed internal links. */
function repo_pick_siblings(array $laneItems, string $excludeSlug, string $prefix, int $limit = 6): array {
    $out = [];
    foreach ($laneItems as $it) {
        if ($it['slug'] === $excludeSlug) continue;
        $out[] = ['term' => $it['term'], 'url' => $prefix . $it['slug'] . '/', 'short_def' => mb_substr($it['short_def'], 0, 80)];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** Build the template-shape array for an entry from a joined pages+terms row. */
function repo_term_shape(array $r, array $publishedSlugs = []): array {
    $jd = function($v){ $x = json_decode($v ?? '[]', true); return is_array($x) ? $x : []; };
    $lane = $r['lane'] ?? 'slang';
    require_once __DIR__ . '/lanes.php';
    $prefix = lanes()[$lane]['prefix'] ?? '/slang/';
    $related = [];
    foreach ($jd($r['related']) as $rel) {
        $name = is_array($rel) ? ($rel['term'] ?? '') : (string)$rel;
        if ($name === '') continue;
        $note = is_array($rel) ? ($rel['note'] ?? '') : '';
        $rslug = strtolower(trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($name))), '-'));
        $url = isset($publishedSlugs[$rslug]) ? $publishedSlugs[$rslug] : null;
        $related[] = ['term' => $name, 'note' => $note, 'url' => $url];
    }
    $sources = [];
    foreach ($jd($r['sources']) as $s) {
        $url = is_array($s) ? ($s['url'] ?? '') : (string)$s;
        if ($url === '') continue;
        $pub = is_array($s) ? ($s['publisher'] ?? (parse_url($url, PHP_URL_HOST) ?: 'source')) : (parse_url($url, PHP_URL_HOST) ?: 'source');
        $ttl = is_array($s) ? ($s['title'] ?? '') : '';
        $sources[] = ['url' => $url, 'text' => trim($pub . ($ttl ? ' — ' . $ttl : ''))];
    }
    return [
        'slug'          => $r['slug'],
        'lane'          => $lane,
        'siblings'      => [],
        'term'          => $r['term'],
        'title'         => $r['h1'],
        'title_tag'     => $r['title_tag'],
        'short_def'     => $r['short_def'] ?? '',
        'summary'       => $r['summary'] ?? '',
        'meta_desc'     => $r['meta_desc'] ?? '',
        'part_of_speech'=> $r['part_of_speech'] ?? '',
        'pronunciation' => $r['pronunciation'] ?? '',
        'category'      => $r['category'] ?? '',
        'also_known_as' => $jd($r['also_known_as']),
        'status_label'  => $r['status_label'] ?? 'mainstream',
        'first_seen'    => $r['first_seen'] ?? '',
        'origin'        => $jd($r['origin']),
        'meaning'       => $jd($r['meaning']),
        'why_trending'  => $jd($r['why_trending']),
        'usage_note'    => $r['usage_note'] ?? '',
        'examples'      => $jd($r['examples']),
        'related'       => $related,
        'faqs'          => $jd($r['faqs']),
        'sources'       => $sources,
        'sameAs'        => (function () use ($r) { require_once __DIR__ . '/entity.php'; return entity_cached(db(), 'term', $r['term']); })(),
        'pull_quote'    => find_pull_quote($jd($r['sources'])),
        // CLAIM-5 (2026-08-05): raw citations JSON for the receipts section.
        // This shaper is exactly where six earlier sibling drifts dropped
        // fields on the floor — the gate verified citations, the template
        // rendered them, and this line in between decided what survives.
        'citations'     => $r['citations'] ?? '[]',
        'cover'         => $r['cover'] ?: '/assets/covers/default.svg',
        'featured_img'  => $r['featured_img'] ?? null,
        'cover_credit'  => $r['cover_credit'] ?? null,
        'cover_credit_url' => $r['cover_credit_url'] ?? null,
        'embed_html'    => $r['embed_html'] ?? null,
        'embed_provider'=> $r['embed_provider'] ?? null,
        'embed_title'   => $r['embed_title'] ?? null,
        'embed_url'     => $r['embed_url'] ?? null,
        'scene_img'     => $r['scene_img'] ?? null,
        'scene_credit'  => $r['scene_credit'] ?? null,
        'scene_credit_url' => $r['scene_credit_url'] ?? null,
        'scene_embed_html' => $r['scene_embed_html'] ?? null,
        'scene_embed_provider' => $r['scene_embed_provider'] ?? null,
        'scene_embed_url' => $r['scene_embed_url'] ?? null,
        'data'          => !empty($r['data_json']) ? json_decode($r['data_json'], true) : null,
        'published_iso' => date('c', strtotime($r['published_at'])),
        'published'     => date('M j, Y', strtotime($r['published_at'])),
        'updated_iso'   => date('c', strtotime($r['updated_at'])),
        'updated'       => date('M j, Y', strtotime($r['updated_at'])),
        'robots'        => $r['robots'],
        'page_id'       => (int)$r['page_id'],
    ];
}

/** Load ONE term in template shape regardless of status (admin preview). */
function repo_load_term_any(string $slug): ?array {
    $pdo = db();
    $st = $pdo->prepare("SELECT p.id page_id, p.slug, p.h1, p.title_tag, p.meta_desc, p.summary, p.cover,
                                p.published_at, p.updated_at, p.robots, p.status, t.*
                         FROM pages p JOIN terms t ON t.page_id = p.id
                         WHERE p.slug = ? AND p.type='term' LIMIT 1");
    $st->execute([$slug]);
    $r = $st->fetch();
    if (!$r) return null;
    $publishedSlugs = [];
    foreach ($pdo->query("SELECT slug, path FROM pages WHERE type='term' AND status='published'")->fetchAll() as $row) {
        $publishedSlugs[strtolower($row['slug'])] = $row['path'];
    }
    $shape = repo_term_shape($r, $publishedSlugs);
    $shape['page_status'] = $r['status'];
    require_once __DIR__ . '/lanes.php';
    $lane = $r['lane'] ?? 'slang';
    $sib = $pdo->prepare("SELECT t.term, p.slug, t.short_def FROM pages p JOIN terms t ON t.page_id=p.id
                          WHERE p.type='term' AND p.status='published' AND t.lane=? AND p.slug<>? ORDER BY p.updated_at DESC LIMIT 6");
    $sib->execute([$lane, $slug]);
    $shape['siblings'] = repo_pick_siblings($sib->fetchAll(), $slug, lanes()[$lane]['prefix'] ?? '/slang/');
    return $shape;
}

/** Load ONE drama in template shape regardless of status (admin preview). */
function repo_load_drama_any(string $slug): ?array {
    $pdo = db();
    $st = $pdo->prepare("SELECT p.id page_id, p.slug, p.h1, p.title_tag, p.meta_desc, p.summary, p.cover,
                                p.published_at, p.updated_at, p.robots, p.cover_credit, p.cover_credit_url, p.status,
                                d.id drama_id, d.title, d.lifecycle, d.background, d.why_matters, d.whats_next, d.both_sides, d.verdict
                         FROM pages p JOIN dramas d ON d.page_id = p.id
                         WHERE p.slug = ? LIMIT 1");
    $st->execute([$slug]);
    $r = $st->fetch();
    if (!$r) return null;
    $did = (int)$r['drama_id'];

    $events = [];
    $ev = $pdo->prepare("SELECT event_date, title, description, source_id, is_confirmed, confirmed_by, embed_note, embed_html, embed_provider, why_matters
                         FROM events WHERE drama_id=? AND video_only=0 ORDER BY sort_order, event_date");
    $ev->execute([$did]);
    foreach ($ev->fetchAll() as $e) {
        $events[] = [
            'date_iso' => $e['event_date'],
            'date'     => date('M j, Y', strtotime($e['event_date'])),
            'title'    => $e['title'] ?? '',
            'desc'     => $e['description'],
            'sources'  => $e['source_id'] ? [(int)$e['source_id']] : [],
            'embed'    => $e['embed_note'],
                'embed_html' => $e['embed_html'] ?? null,
                'embed_provider' => $e['embed_provider'] ?? null,
            'why'      => $e['why_matters'],
            'confirmed' => (int)$e['is_confirmed'] === 1 ? gate_proof_label($e['confirmed_by'] ?? '') : '',   // '' = a claim
        ];
    }
    $parties = [];
    $pq = $pdo->prepare("SELECT cp.slug, c.name, cp.summary role_text FROM parties pa
                         JOIN creators c ON c.id=pa.creator_id JOIN pages cp ON cp.id=c.page_id WHERE pa.drama_id=?");
    $pq->execute([$did]);
    foreach ($pq->fetchAll() as $p2) $parties[] = ['slug'=>$p2['slug'],'name'=>$p2['name'],'role'=>$p2['role_text'] ?? ''];

    $faqs = [];
    $fq = $pdo->prepare("SELECT question, answer FROM faqs WHERE drama_id=? ORDER BY sort_order");
    $fq->execute([$did]);
    foreach ($fq->fetchAll() as $f) $faqs[] = ['q'=>$f['question'],'a'=>$f['answer']];

    $sources = [];
    $sq = $pdo->prepare("SELECT DISTINCT s.id, s.url, s.publisher, s.title, s.retrieved_on FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=? ORDER BY s.id");
    $sq->execute([$did]);
    foreach ($sq->fetchAll() as $s) $sources[] = ['id'=>(int)$s['id'],'url'=>$s['url'] ?? null,'text'=>trim(($s['publisher'] ?? '').', '.($s['title'] ?? '').', '.date('M j, Y', strtotime($s['retrieved_on'] ?? 'now')).'.')];

    $lifecycleMap = ['ongoing'=>'Ongoing','resolved'=>'Resolved','dormant'=>'Dormant'];
    return [
        'slug'=>$r['slug'], 'title'=>$r['title'], 'title_tag'=>$r['title_tag'], 'eyebrow'=>'Creator Drama',
        'status'=>$lifecycleMap[$r['lifecycle']] ?? 'Ongoing',
        'published_iso'=>date('c', strtotime($r['published_at'])), 'published'=>date('M j, Y', strtotime($r['published_at'])),
        'updated_iso'=>date('c', strtotime($r['updated_at'])), 'updated'=>date('M j, Y', strtotime($r['updated_at'])),
        'cover'=>$r['cover'] ?: '/assets/covers/default.svg',
        'summary'=>$r['summary'] ?? '', 'meta_desc'=>$r['meta_desc'] ?? '',
        'background'=>json_decode($r['background'] ?? '[]', true) ?: [],
        ...story_context_shape($r['why_matters'] ?? null, $r['whats_next'] ?? null),
        'tracked'=>cs_numbers($pdo, [(int)$r['page_id']])[(int)$r['page_id']] ?? [],
        'both_sides'=>(array)json_decode((string)($r['both_sides'] ?? ''), true),
        'verdict'=>json_decode((string)($r['verdict'] ?? ''), true) ?: null,
        'events'=>$events, 'parties'=>$parties, 'faqs'=>$faqs, 'sources'=>$sources,
        'related'=>[], 'robots'=>$r['robots'], 'page_id'=>(int)$r['page_id'], 'page_status'=>$r['status'],
    ];
}

/** Right-rail data for detail pages: fresh entries + latest dramas (design study 2026-06-12). */
function repo_rail(int $excludePageId = 0): array {
    $pdo = db();
    require_once __DIR__ . '/lanes.php';
    $terms = [];
    $st = $pdo->prepare("SELECT p.id, p.slug, p.featured_img, t.term, t.short_def, t.lane
                         FROM pages p JOIN terms t ON t.page_id=p.id
                         WHERE p.status='published' AND p.robots='index' AND p.id != ?
                         ORDER BY p.published_at DESC LIMIT 6");
    $st->execute([$excludePageId]);
    foreach ($st->fetchAll() as $r) {
        $pre = lanes()[$r['lane']]['prefix'] ?? '/slang/';
        $terms[] = ['url' => $pre . $r['slug'] . '/', 'term' => $r['term'],
                    'short_def' => $r['short_def'] ?? '', 'featured_img' => $r['featured_img']];
    }
    $dramas = [];
    $st = $pdo->prepare("SELECT p.id, p.slug, p.h1, p.summary, p.featured_img, p.cover, COALESCE(d.lane,'drama') lane
                         FROM pages p JOIN dramas d ON d.page_id=p.id
                         WHERE p.type='drama' AND p.status='published' AND p.robots='index' AND p.id != ?
                         ORDER BY p.updated_at DESC LIMIT 2");
    $st->execute([$excludePageId]);
    foreach ($st->fetchAll() as $r) {
        $dramas[] = ['url' => timeline_url($r['slug'], $r['lane'] ?? 'drama'), 'term' => $r['h1'],
                     'short_def' => $r['summary'] ?? '', 'featured_img' => $r['featured_img'] ?: $r['cover']];
    }
    // categories box (KYM pattern): lanes with live entry counts
    $cats = [];
    foreach ($pdo->query("SELECT t.lane, COUNT(*) n FROM terms t JOIN pages p ON p.id=t.page_id
                          WHERE p.status='published' AND p.robots='index' GROUP BY t.lane")->fetchAll() as $r) {
        if (!isset(lanes()[$r['lane']])) continue;
        $L = lanes()[$r['lane']];
        $cats[] = ['name' => html_entity_decode($L['crumb']), 'prefix' => $L['prefix'], 'count' => (int)$r['n']];
    }
    $dn = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE type='drama' AND status='published' AND robots='index'")->fetchColumn();
    if ($dn) $cats[] = ['name' => 'Drama', 'prefix' => '/drama/', 'count' => $dn];
    return ['terms' => $terms, 'dramas' => $dramas, 'cats' => $cats];
}

function repo_ticker(): array {
    $pdo = db();
    $d = $pdo->query("SELECT COUNT(*) FROM pages WHERE type='drama' AND status='published'")->fetchColumn();
    $t = $pdo->query("SELECT COUNT(*) FROM pages WHERE type='term' AND status='published'")->fetchColumn();
    $u = $pdo->query("SELECT MAX(updated_at) FROM pages WHERE status='published'")->fetchColumn();
    return ['dramas' => (int)$d, 'creators' => (int)$t, 'terms' => (int)$t, 'updated' => $u ? date('M j, H:i', strtotime($u)) : date('M j, H:i')];
}
