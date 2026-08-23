<?php
// GenZHype | r14 THE SEEING PASS (owner round-14: "the director doesn't really
// SEE what's going on"). Until now the Director chose images from OUR GUESSED
// text labels ("event thumbnail: ...", "article inline image") — it never saw
// the pixels. This module LOOKS: one batched Gemini-vision call per direction
// describes every pool image truthfully (who/what/setting/mood), and those
// SEEN descriptions replace the guessed labels in the Director's VISUALS list.
//
// Economics: descriptions are cached PER IMAGE URL (md5) FOREVER in
// app/visual_sight_cache.json — the images at these URLs are immutable, so
// each image costs vision ONCE ever. A page's direction call only sends the
// not-yet-seen URLs (max 16/call, one call per page per direction). Vision
// work is CLI-only (cron/direction); web requests read the cache. Every
// failure path returns whatever the cache already knows — the old guessed
// labels stand, never fatal.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/vision.php';   // vision_b64(): curl fetch + GD downscale -> base64 jpeg

const VISUAL_SIGHT_CACHE = __DIR__ . '/visual_sight_cache.json';
const VISUAL_SIGHT_BATCH = 16;          // hard cap: images per vision call

function visual_sight_cache_load(): array {
    $j = @json_decode((string)@file_get_contents(VISUAL_SIGHT_CACHE), true);
    return is_array($j) ? $j : [];
}

function visual_sight_cache_save(array $cache): void {
    $tmp = VISUAL_SIGHT_CACHE . '.tmp';
    if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_SLASHES)) !== false) {
        @rename($tmp, VISUAL_SIGHT_CACHE);
    }
}

/**
 * Truthful per-image descriptions for a page's visual pool.
 * Returns [url => ['desc' => '8-16 words of what is ACTUALLY in the image',
 *                  'text_heavy' => bool, 'faces' => int]] for every url it
 * knows (cache hits + anything newly seen this call). ONE batched
 * gemini-2.5-flash call covers all uncached urls (<=16); CLI-only — a web
 * request serves the cache and never fetches or spends quota. Any failure ->
 * partial/empty result and the caller's guessed labels stand.
 */
function visual_sight_describe(PDO $pdo, array $urls): array {
    $cache = visual_sight_cache_load();
    $out = []; $uncached = [];
    foreach ($urls as $u) {
        if (!is_string($u) || !preg_match('#^https?://#i', $u)) continue;
        $k = md5($u);
        if (isset($cache[$k]['desc']) && $cache[$k]['desc'] !== '') {
            $out[$u] = $cache[$k];
        } elseif (!in_array($u, $uncached, true)) {
            $uncached[] = $u;
        }
    }
    if (!$uncached || PHP_SAPI !== 'cli') return $out;   // web = cache only

    // Fetch + downscale (~384px, curl via fs_http_get inside vision_b64) each
    // uncached image; build ONE multimodal message. Unfetchable images are
    // simply skipped (not cached — a transient fetch miss must not stick).
    $batch = array_slice($uncached, 0, VISUAL_SIGHT_BATCH);
    $content = []; $sent = [];
    foreach ($batch as $u) {
        $b64 = vision_b64($u, 384);
        if (!$b64) continue;
        $content[] = ['type' => 'text', 'text' => 'IMAGE ' . count($sent) . ':'];
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $b64]];
        $sent[] = $u;
    }
    if (!$sent) return $out;
    $nImg = count($sent);
    array_unshift($content, ['type' => 'text', 'text' =>
        "You describe images for a video editor who cannot see them. For EACH of the {$nImg} numbered "
        . "images below, report truthfully what is ACTUALLY in the picture (who/what/setting/mood) in "
        . "8-16 plain words. Describe what you SEE — never invent names or events; a generic person is "
        . "'a man'/'a woman'. Also report text_heavy (true when words/text dominate the image: a card, "
        . "screenshot, poster, thumbnail with big caption text) and faces (count of clearly visible "
        . "human faces, 0 if none). STRICT JSON only, exactly one entry per image, i = image number: "
        . '{"images":[{"i":0,"desc":"...","text_heavy":false,"faces":1}]}']);

    $res = ai_chat([['role' => 'user', 'content' => $content]], ['gemini'], 0.1);
    if (isset($res['error'])) {
        error_log('visual_sight: vision call failed (' . json_encode($res['error']) . '); guessed labels stand');
        return $out;
    }
    $rows = visual_sight_rows((string)$res['content']);
    if (!$rows) {
        error_log('visual_sight: unusable vision JSON; guessed labels stand: ' . mb_substr((string)$res['content'], 0, 200));
        return $out;
    }
    $seen = 0;
    foreach ($rows as $e) {
        if (!is_array($e)) continue;
        $i = (int)($e['i'] ?? -1);
        $desc = trim(preg_replace('/\s+/u', ' ', (string)($e['desc'] ?? '')) ?? '');
        if ($i < 0 || $i >= $nImg || $desc === '') continue;
        $u = $sent[$i];
        $entry = [
            'url'        => mb_substr($u, 0, 300),
            'desc'       => mb_substr($desc, 0, 140),
            'text_heavy' => !empty($e['text_heavy']),
            'faces'      => max(0, (int)($e['faces'] ?? 0)),
            'at'         => date('c'),
        ];
        $cache[md5($u)] = $entry;
        $out[$u] = $entry;
        $seen++;
    }
    if ($seen) visual_sight_cache_save($cache);
    error_log("visual_sight: SAW {$seen}/{$nImg} image(s) in one {$res['provider']}/{$res['model']} call ("
              . (count($out) - $seen) . ' already cached, ' . count($uncached) . ' were uncached)');
    return $out;
}

/**
 * Parse the vision reply into rows. The prompt asks for {"images":[...]} but
 * gemini-2.5-flash frequently answers with the bare top-level ARRAY (observed
 * live 2026-07-17) — and ai_json() only extracts objects. Accept both.
 */
function visual_sight_rows(string $content): ?array {
    $j = ai_json($content);
    if (is_array($j['images'] ?? null)) return $j['images'];
    $c = trim($content);
    if (($tp = strripos($c, '</think>')) !== false) $c = trim(substr($c, $tp + 8));
    if (preg_match('/```(?:json)?\s*(.*?)```/s', $c, $m)) $c = trim($m[1]);
    $start = strpos($c, '[');
    $end   = strrpos($c, ']');
    if ($start === false || $end === false || $end <= $start) return null;
    $arr = json_decode(substr($c, $start, $end - $start + 1), true);
    return (is_array($arr) && $arr && is_array($arr[0] ?? null)) ? $arr : null;
}

/**
 * Short origin tag for a guessed visual title — kept in parentheses after the
 * SEEN description so the Director still knows WHERE each image comes from,
 * e.g. "SEEN: woman in red dress speaking on stage (Jun 25 report photo)".
 */
function visual_sight_origin_tag(string $title): string {
    $t = trim($title);
    if (preg_match('/\(([^()]*report photo)\)/i', $t, $m)) return $m[1];         // "Jun 25 report photo"
    if (preg_match('/^recent photo of\s+(.{1,40}?)\s*(?:\(|$)/iu', $t, $m)) return 'recent photo of ' . $m[1];
    if (stripos($t, 'cover photo') === 0) return 'story cover photo';
    if (stripos($t, 'event thumbnail') === 0) return 'event video thumbnail';
    if (stripos($t, 'article inline image') === 0) return 'article image';
    if (stripos($t, 'real photo of') === 0) return 'site cover render';
    if (strpos($t, 'render') !== false) return 'site render';
    return $t !== '' ? mb_substr($t, 0, 60) : 'story image';
}

/**
 * Rewrite the Director's VISUALS titles with the SEEN descriptions where
 * available: "SEEN: <what is actually in the image> (<origin tag>)". Titles
 * without a sighting keep their guessed label unchanged.
 */
function visual_sight_titles(array $urls, array $titles, array $sight): array {
    foreach ($titles as $i => $t) {
        $u = $urls[$i] ?? '';
        $d = (string)($sight[$u]['desc'] ?? '');
        if ($u !== '' && $d !== '') {
            $titles[$i] = 'SEEN: ' . $d . ' (' . visual_sight_origin_tag((string)$t) . ')';
        }
    }
    return $titles;
}
