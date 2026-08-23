<?php
// GenZHype | v10 EXACT-MOMENT IMAGERY (owner round-10, from competitor study):
// "if the person is in a special party they bring the exact same party [photo] —
// the caption translates what's in the image." Every news article about an event
// carries its own photo of that exact moment (og:image). This module harvests
// og:image + og:title per event source URL, cached on disk, so the Director can
// pin THE moment's photo to the words describing it (via the existing visual_i
// machinery — og-images join video_event_visuals()'s numbered list).
// SAFETY: network fetches run ONLY from CLI (cron/Director time); web requests
// (video_next.php fallback feed) read the cache and never fetch.

const EVENT_OG_CACHE = __DIR__ . '/event_og_cache.json';
const EVENT_OG_TTL   = 2592000;   // 30d positive
const EVENT_OG_NEG   = 604800;    // 7d negative retry

function event_og_cache_load(): array {
    $j = @json_decode((string)@file_get_contents(EVENT_OG_CACHE), true);
    return is_array($j) ? $j : [];
}

function event_og_cache_save(array $c): void {
    @file_put_contents(EVENT_OG_CACHE, json_encode($c, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Fetch og:image/og:title from an article URL (CLI only; ~100KB head is enough). */
function event_og_fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_RANGE => '0-120000',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (($code !== 200 && $code !== 206) || $html === '') return ['ok' => false];
    $img = ''; $title = '';
    if (preg_match('/<meta[^>]+property=["\']og:image(?::url)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
     || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::url)?["\']/i', $html, $m)) {
        $img = html_entity_decode(trim($m[1]), ENT_QUOTES);
    }
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
     || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES);
    }
    if ($img === '' || !preg_match('#^https?://#i', $img)) return ['ok' => false];
    // og:image must be a real content photo, not a logo/default sprite
    if (preg_match('/logo|favicon|default|placeholder|og-image-default/i', $img)) return ['ok' => false];
    return ['ok' => true, 'img' => $img, 'title' => mb_substr($title, 0, 160)];
}

/**
 * Per-event source intelligence for one drama page:
 * [{i, date, title, source_url, og_image}] — og_image null when unresolvable.
 * Event order matches video_event_visuals()/receipt cards (sort_order, date, id).
 */
function event_sources_for_page(PDO $pdo, int $pageId): array {
    $did = (int)$pdo->query("SELECT id FROM dramas WHERE page_id=" . (int)$pageId)->fetchColumn();
    if (!$did) return [];
    $rows = $pdo->query("SELECT e.id, e.event_date, e.title, s.url
                         FROM events e LEFT JOIN sources s ON s.id = e.source_id
                         WHERE e.drama_id={$did}
                         ORDER BY e.sort_order, e.event_date, e.id LIMIT 10")->fetchAll();
    $cache = event_og_cache_load();
    $cli = (PHP_SAPI === 'cli');
    $out = []; $dirty = false; $budget = 6;   // max live fetches per call
    foreach ($rows as $i => $r) {
        $url = trim((string)$r['url']);
        $og  = null;
        // platform links are handled by post_cards (tweets); og-harvest news only
        $isNews = $url !== '' && !preg_match('#(twitter\.com|x\.com|t\.co|tiktok\.com|instagram\.com|youtube\.com|youtu\.be|reddit\.com)/#i', $url);
        if ($isNews) {
            $k = 'og:' . md5($url);
            $hit = $cache[$k] ?? null;
            $fresh = $hit && (time() - (int)($hit['t'] ?? 0)) < (($hit['img'] ?? '') !== '' ? EVENT_OG_TTL : EVENT_OG_NEG);
            if ($fresh) {
                $og = ($hit['img'] ?? '') !== '' ? $hit['img'] : null;
            } elseif ($cli && $budget > 0) {
                $budget--;
                $f = event_og_fetch($url);
                $cache[$k] = ['t' => time(), 'img' => $f['ok'] ? $f['img'] : ''];
                $dirty = true;
                $og = $f['ok'] ? $f['img'] : null;
            }
        }
        $out[] = [
            'i' => $i,
            'date' => (string)$r['event_date'],
            'title' => mb_substr(trim((string)$r['title']), 0, 140),
            'source_url' => $isNews ? $url : '',
            'og_image' => $og,
        ];
    }
    if ($dirty) event_og_cache_save($cache);
    return $out;
}
