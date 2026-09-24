<?php
declare(strict_types=1);
/**
 * NEXT — the one primary action (Hick's Law Step 1, owner go 2026-09-05).
 *
 * Every story and term page ends with ONE recommended page instead of a list
 * of ten. This picks it. Deliberately small and deterministic:
 *
 *   same lane          - the reader chose a door; keep them behind it
 *   not this page      - obviously
 *   not recently seen  - a cookie of the last slugs, so Next never loops
 *   live + indexable   - never recommend a page we hide from Google
 *   ranked by          - the Producer's DEMAND x QUALITY score where one exists
 *                        (62 of 543 pages today), then freshest first
 *
 * The gaming lane has two kinds of page (dictionary entries AND news stories);
 * Next draws from both, because to the reader they are one shelf.
 *
 * SEO note: this ADDS one internal link per page; nothing here removes one.
 * Rule-5 note: Next is a link, not content - the page's sections are untouched.
 */

require_once __DIR__ . '/lanes.php';

const NEXT_SEEN_COOKIE = 'gz_seen';
const NEXT_SEEN_MAX    = 30;

/** Slugs this visitor has seen recently (client cookie, best effort). */
function next_seen_slugs(): array {
    $raw = (string)($_COOKIE[NEXT_SEEN_COOKIE] ?? '');
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $s) {
        if (is_string($s) && preg_match('/^[a-z0-9-]{1,120}$/', $s)) $out[] = $s;
        if (count($out) >= NEXT_SEEN_MAX) break;
    }
    return $out;
}

/**
 * The one page to read next. Returns ['url','title','desc','kind'] or null.
 * $lane: slang | meme | gaming | hype | drama.  $excludePageId: the current page.
 */
function next_page_for(PDO $pdo, string $lane, int $excludePageId, ?array $seen = null): ?array {
    $seen  = $seen ?? next_seen_slugs();
    $seen  = array_values(array_unique(array_filter($seen, 'is_string')));
    $lanes = lanes();
    $isTermLane = isset($lanes[$lane]);
    $params = [];
    $notSeen = '';
    if ($seen) {
        $notSeen = ' AND p.slug NOT IN (' . implode(',', array_fill(0, count($seen), '?')) . ')';
    }

    // latest Producer score per page, if any
    $scoreSub = "(SELECT pp.score FROM producer_pick pp WHERE pp.page_id = p.id ORDER BY pp.run_date DESC LIMIT 1)";

    $cands = [];

    if ($isTermLane) {
        $sql = "SELECT p.id, p.slug, p.published_at, t.term AS title, t.short_def AS `desc`, 'term' AS kind,
                       {$scoreSub} AS score
                  FROM pages p JOIN terms t ON t.page_id = p.id
                 WHERE p.status='published' AND p.robots='index' AND t.lane = ? AND p.id <> ?{$notSeen}
                 ORDER BY score DESC, p.published_at DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$lane, $excludePageId], $seen));
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $r['url'] = ($lanes[$lane]['prefix'] ?? '/slang/') . $r['slug'] . '/';
            $cands[] = $r;
        }
    }

    // timeline pages: 'drama' lane, and 'gaming' stories live under /gaming/ too
    if ($lane === 'drama' || $lane === 'gaming') {
        $sql = "SELECT p.id, p.slug, p.published_at, d.title, p.summary AS `desc`, 'story' AS kind,
                       COALESCE(d.lane,'drama') AS dlane, {$scoreSub} AS score
                  FROM pages p JOIN dramas d ON d.page_id = p.id
                 WHERE p.type='drama' AND p.status='published' AND p.robots='index'
                   AND COALESCE(d.lane,'drama') = ? AND p.id <> ?{$notSeen}
                 ORDER BY score DESC, p.published_at DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$lane, $excludePageId], $seen));
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $r['url'] = timeline_url($r['slug'], $r['dlane']);
            $cands[] = $r;
        }
    }

    if (!$cands) return null;
    // two candidates only happens in the gaming lane: prefer the higher score,
    // then the fresher page
    usort($cands, function ($a, $b) {
        $sa = (float)($a['score'] ?? 0); $sb = (float)($b['score'] ?? 0);
        if ($sa !== $sb) return $sb <=> $sa;
        return strcmp((string)$b['published_at'], (string)$a['published_at']);
    });
    $pick = $cands[0];
    return [
        'url'   => $pick['url'],
        'title' => (string)$pick['title'],
        'desc'  => mb_substr(trim((string)($pick['desc'] ?? '')), 0, 110),
        'kind'  => $pick['kind'],
    ];
}
