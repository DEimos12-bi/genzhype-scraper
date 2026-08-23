<?php
// GenZHype | FEATURED IMAGE module (programmatic-playbook approach, operator
// approved 2026-06-13 after 3-sample review). Every page gets an on-theme
// lifestyle image: vibe queries -> Pexels -> sanity-match check -> Openverse
// fallback -> generic fallback. ALWAYS returns an image when any source is up.
// The sanity check kills clear misses (therapy couch, film crews); it never
// produces imageless. Vision down => accept (image guaranteed).
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/images.php';     // img_process_hero, openverse_find_all
require_once __DIR__ . '/vision.php';     // vision_b64, vision_nvidia
require_once __DIR__ . '/fetch_sources.php';

const FEATURED_GENERIC = ['happy young friends outdoors candid', 'gen z lifestyle city friends', 'young person modern lifestyle'];

function pexels_photos(string $q, int $n = 6): array {
    $key = $GLOBALS['CONFIG']['pexels_key'] ?? '';
    if ($key === '') return [];
    $ch = curl_init('https://api.pexels.com/v1/search?' . http_build_query(['query' => $q, 'per_page' => $n, 'orientation' => 'landscape']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: ' . $key]]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$raw) return [];
    $out = [];
    foreach ((json_decode($raw, true)['photos'] ?? []) as $p) {
        $out[] = ['url' => $p['src']['large2x'] ?? $p['src']['original'], 'thumb' => $p['src']['medium'] ?? $p['src']['large'],
                  'w' => (int)($p['width'] ?? 0),
                  'credit' => 'Photo: ' . ($p['photographer'] ?? 'Pexels') . ', via Pexels',
                  'credit_url' => $p['url'] ?? 'https://www.pexels.com'];
    }
    return $out;
}

/** Tightened vibe queries: concrete physical states, no double-meaning words. */
function featured_queries(string $subject, string $definition): array {
    $res = ai_chat([
        ['role' => 'system', 'content' => 'You write stock-photo search queries. Give 3 short queries (3-6 words) for a PHOTOGRAPHABLE LIFESTYLE SCENE capturing the meaning. RULES: name the EXACT physical pose/state (asleep eyes closed, laughing with friends, dancing); concrete unambiguous words ONLY; AVOID words with clinical/business double meanings (couch, session, pitch, consultation, office, meeting); subjects young + modern; never the slang word itself. STRICT JSON: {"queries":["..","..",".."]}'],
        ['role' => 'user', 'content' => "Term: {$subject}. Meaning: {$definition}"],
    ], ['gemini', 'openrouter', 'nvidia'], 0.4);
    $j = ai_json($res['content'] ?? '');
    return array_slice(array_filter((array)($j['queries'] ?? [])), 0, 3);
}

/** ONE cheap check: does the image roughly match the query scene, no big unrelated elements? */
function featured_sanity(array $cand, string $q): bool {
    $b64 = vision_b64($cand['thumb'] ?? $cand['thumbnail'] ?? $cand['url'], 448);
    if (!$b64) return true;
    $p = "Search query was: \"{$q}\". Does this image ROUGHLY show that scene, with NO large unrelated elements (no film crew, no office/clinical setting, no equipment)? Loose match is fine. STRICT JSON: {\"match\":true|false,\"why\":\"short\"}";
    $r = ai_chat([['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $p], ['type' => 'image_url', 'image_url' => ['url' => $b64]],
    ]]], ['gemini'], 0.1);
    $j = isset($r['error']) ? null : ai_json($r['content']);
    if (!$j) $j = vision_nvidia($p, $b64);
    if (!$j || !array_key_exists('match', $j)) return true;    // vision down => accept
    return !empty($j['match']);
}

/**
 * The featured image for any page. Writes {slug}-featured.webp (1200x630, red
 * rule, EXIF-safe). Returns ['img','credit','credit_url'] — null ONLY if every
 * image source is unreachable.
 */
function featured_image_for(string $subject, string $definition, string $slug): ?array {
    $queries = featured_queries($subject, $definition);
    $rounds = array_merge($queries, FEATURED_GENERIC);
    $fallbackCand = null;
    foreach ($rounds as $i => $q) {
        // Pexels first, then Openverse for the same query
        $cands = pexels_photos($q, 5);
        foreach (openverse_find_all($q, 3) as $ov) {
            $cands[] = ['url' => $ov['url'], 'thumb' => $ov['thumbnail'] ?? $ov['url'], 'w' => 1300,
                        'credit' => trim("Photo: {$ov['creator']} ({$ov['license']}), via {$ov['source']}"),
                        'credit_url' => $ov['foreign']];
        }
        foreach ($cands as $cand) {
            if (($cand['w'] ?? 0) < 1200) continue;
            if (!$fallbackCand) $fallbackCand = $cand;          // guaranteed-image safety net
            if (featured_sanity($cand, $q)) {
                $bytes = fs_http_get($cand['url'], 25);
                $out = dirname(__DIR__) . "/public_html/assets/covers/{$slug}-featured.webp";
                if ($bytes && img_process_hero($bytes, $out)) {
                    return ['img' => "/assets/covers/{$slug}-featured.webp", 'credit' => $cand['credit'], 'credit_url' => $cand['credit_url']];
                }
            }
        }
    }
    // absolute last resort: first big candidate, unchecked (zero imageless)
    if ($fallbackCand) {
        $bytes = fs_http_get($fallbackCand['url'], 25);
        $out = dirname(__DIR__) . "/public_html/assets/covers/{$slug}-featured.webp";
        if ($bytes && img_process_hero($bytes, $out)) {
            return ['img' => "/assets/covers/{$slug}-featured.webp", 'credit' => $fallbackCand['credit'], 'credit_url' => $fallbackCand['credit_url']];
        }
    }
    return null;   // only if Pexels AND Openverse are both down
}
