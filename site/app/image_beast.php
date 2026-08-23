<?php
// GenZHype | IMAGE BEAST — reasoning-chain featured-image engine.
// UNDERSTAND -> STRATEGIZE -> FAN OUT -> JUDGE -> REFINE (<=2) -> FINALIZE.
// Lane-agnostic by design: concepts are classified by NATURE (real_entity /
// specific_meme / abstract_vibe / event_action), never by lane name, so any
// future lane works with zero code changes here.
// Free-first, reuse-first: Pexels + Wikimedia face path + img_process_hero
// (brand overlay) + vision plumbing are all the EXISTING modules. New here:
// the chain itself, the meme-DB source (Giphy/Tenor) and the generation
// backend (Pollinations FLUX, swappable via config image_gen.provider).
// Every external call is bounded at <=60s. Guaranteed image: the legacy
// featured_image_for() engine is the final net — this never returns blank
// while any image source on the internet is up.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/images.php';        // wm_person_candidates, img_process_hero
require_once __DIR__ . '/vision.php';        // vision_b64, vision_nvidia
require_once __DIR__ . '/fetch_sources.php'; // fs_http_get
require_once __DIR__ . '/featured.php';      // pexels_photos (reuse) + legacy fallback

const BEAST_FIT_OK      = 0.75;   // judge score that ends the chain happy
const BEAST_MAX_REFINES = 2;      // bounded loop: stays fast at 50 pages/day
const BEAST_MAX_CANDS   = 6;

/* ---------------------------------------------------------------- STEP 1 */

/** Classify the concept by NATURE (works for any lane, present or future). */
function beast_understand(array $page): array {
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            'You classify a web page\'s concept for image sourcing. Buckets (choose ONE by the concept\'s NATURE, never by site section):'
            . ' real_entity = a real person/group/place/product that photos exist of.'
            . ' specific_meme = a named internet meme/character with recognizable canonical imagery.'
            . ' abstract_vibe = a feeling/quality/aesthetic with no canonical image.'
            . ' event_action = a concrete physical action/situation a camera could capture.'
            . ' Also write visual_concept: ONE concrete photographable/drawable scene (max 14 words) capturing the meaning.'
            . ' STRICT JSON: {"bucket":"...","reasoning":"short","visual_concept":"..."}'],
        ['role' => 'user', 'content' =>
            "Page type: {$page['page_type']}. Subject: {$page['subject']}. Meaning: {$page['meaning']}"
            . (!empty($page['people']) ? ' People involved: ' . implode(', ', $page['people']) : '')],
    ], ['gemini', 'openrouter', 'nvidia'], 0.2);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    $ok = ['real_entity', 'specific_meme', 'abstract_vibe', 'event_action'];
    if (!$j || !in_array($j['bucket'] ?? '', $ok, true)) {
        return ['bucket' => 'abstract_vibe', 'reasoning' => 'classifier unavailable, safe default',
                'visual_concept' => $page['meaning']];
    }
    return ['bucket' => $j['bucket'], 'reasoning' => (string)($j['reasoning'] ?? ''),
            'visual_concept' => (string)($j['visual_concept'] ?: $page['meaning'])];
}

/* ---------------------------------------------------------------- STEP 2 */

/**
 * One vivid generation prompt + 2-3 stock queries. When the caller passes
 * $page['context'] (origin story, first-seen, example usage), the brief draws
 * setting/culture/era details from it - context-true images, not generic ones.
 */
function beast_strategy(array $page, array $u): array {
    $ctx = trim(mb_substr((string)($page['context'] ?? ''), 0, 900));
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            'You prepare image sourcing for a page. Write:'
            . ' gen_prompt: ONE structured text-to-image scene brief, 40-90 words: subject + exact pose/state; setting drawn from the page context when it makes the image truer (culture, platform, era, place); mood; lighting; style ("editorial photography" or "bold illustration" as fits). Never real people\'s names or brand logos.'
            . ' stock_queries: 2-3 stock-photo searches (3-6 plain words, EXACT physical pose/state, subjects young+modern).'
            . ' BAN words with clinical/business double meanings everywhere: couch, session, pitch, consultation, office, meeting, therapy.'
            . ' STRICT JSON: {"gen_prompt":"...","stock_queries":["..",".."]}'],
        ['role' => 'user', 'content' =>
            "Subject: {$page['subject']}. Meaning: {$page['meaning']}. Visual concept: {$u['visual_concept']}. Bucket: {$u['bucket']}"
            . ($ctx !== '' ? "\nPage context (origin, era, usage):\n{$ctx}" : '')
            . (!empty($page['hint']) ? "\nOPERATOR OVERRIDE (highest priority, follow exactly): " . mb_substr($page['hint'], 0, 300) : '')],
    ], ['gemini', 'openrouter', 'nvidia'], 0.4);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    $gen = trim((string)($j['gen_prompt'] ?? ''));
    if ($gen === '') $gen = $u['visual_concept'] . ', editorial photography, natural light, modern';
    $qs = array_slice(array_filter(array_map('trim', (array)($j['stock_queries'] ?? []))), 0, 3);
    if (!$qs) $qs = [$u['visual_concept']];
    return ['gen_prompt' => $gen, 'stock_queries' => $qs];
}

/* ------------------------------------------------------ MEME DB (new src) */

/** GIPHY's media CDN serves an HTML block page to bot UAs; fetch like a browser. */
function beast_media_get(string $url, int $timeout = 20): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/jpeg,*/*'],
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && $raw) ? $raw : null;
}

/** Canonical meme imagery by name via Giphy/Tenor (free keys, skip if unset). */
function beast_meme_search(string $name, int $max = 3): array {
    global $CONFIG;
    $out = [];
    $gk = $CONFIG['giphy_key'] ?? '';
    if ($gk !== '') {
        $raw = fs_http_get('https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $gk, 'q' => $name, 'limit' => $max, 'rating' => 'pg-13']), 20);
        foreach ((json_decode($raw ?: '', true)['data'] ?? []) as $g) {
            $still = $g['images']['original_still']['url'] ?? ($g['images']['480w_still']['url'] ?? null);
            if (!$still) continue;
            // download NOW (browser UA) into a local file: judge + finalize read it from there
            $bytes = beast_media_get($still);
            if (!$bytes || !@imagecreatefromstring($bytes)) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'gzmeme_');
            file_put_contents($tmp, $bytes);
            $out[] = ['url' => $still, 'path' => $tmp, 'thumb' => null,
                      'credit' => 'Via GIPHY' . (!empty($g['username']) ? ' (@' . $g['username'] . ')' : ''),
                      'credit_url' => $g['url'] ?? 'https://giphy.com', 'source' => 'giphy'];
            if (count($out) >= $max) break;
        }
    }
    $tk = $CONFIG['tenor_key'] ?? '';
    if (count($out) < $max && $tk !== '') {
        $raw = fs_http_get('https://tenor.googleapis.com/v2/search?' . http_build_query([
            'key' => $tk, 'q' => $name, 'limit' => $max, 'contentfilter' => 'medium',
            'media_filter' => 'gifpreview,gif']), 20);
        foreach ((json_decode($raw ?: '', true)['results'] ?? []) as $t) {
            $still = $t['media_formats']['gifpreview']['url'] ?? null;
            if (!$still) continue;
            $bytes = beast_media_get($still);
            if (!$bytes || !@imagecreatefromstring($bytes)) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'gzmeme_');
            file_put_contents($tmp, $bytes);
            $out[] = ['url' => $still, 'path' => $tmp, 'thumb' => null,
                      'credit' => 'Via Tenor', 'credit_url' => $t['itemurl'] ?? 'https://tenor.com',
                      'source' => 'tenor'];
            if (count($out) >= $max) break;
        }
    }
    return $out;
}

/**
 * MEME-LANE images (2026-06-14 fix): we KNOW it's a meme from the lane, so don't
 * let the AI classifier guess (it matched 'cappuccina'->coffee). Search GIPHY by
 * the EXACT meme name -> the real meme as featured (whole, not cropped) + up to 3
 * gallery stills. Returns ['img','credit','credit_url','count'] or null if GIPHY
 * has nothing (caller then falls back to the normal beast).
 */
function meme_real_images(string $term, string $slug, string $meaning = ''): ?array {
    $cands = beast_meme_search($term, 6);
    if (!$cands) return null;
    // FEATURED pick: AI vision chooses the still that best shows the term (rejects
    // dull/empty frames a texture-score can't catch). Falls back to first if down.
    if ($meaning !== '' && count($cands) > 1) {
        $tr = [];
        $v = beast_judge($cands, ['subject' => $term, 'meaning' => $meaning], $tr);
        $bi = $v['best'] ?? -1;
        if ($bi > 0 && $bi < count($cands)) { $w = $cands[$bi]; array_splice($cands, $bi, 1); array_unshift($cands, $w); }
    }
    $win = $cands[0];
    $bytes = (!empty($win['path']) && is_file($win['path'])) ? (string)file_get_contents($win['path']) : null;
    if (!$bytes) return null;
    $out = dirname(__DIR__) . "/public_html/assets/covers/{$slug}-featured.webp";
    if (!img_face_hero($bytes, $out)) return null;          // whole meme on brand canvas
    // gallery: up to 3 more distinct stills
    $gdir = dirname(__DIR__) . '/public_html/assets/memes';
    @mkdir($gdir, 0755, true);
    $gal = []; $n = 0; $seen = [img_dhash($bytes)];
    foreach (array_slice($cands, 1) as $c) {
        if ($n >= 3) break;
        $gb = (!empty($c['path']) && is_file($c['path'])) ? (string)file_get_contents($c['path']) : null;
        if (!$gb) continue;
        $gh = img_dhash($gb); $dup = false;
        foreach ($seen as $sh) if ($gh && $sh && img_hamming($gh, $sh) <= 6) { $dup = true; break; }
        if ($dup) continue;
        $seen[] = $gh; $n++;
        $gp = "{$gdir}/{$slug}-g{$n}.webp";
        if (beast_gallery_webp($gb, $gp)) { img_renditions($gp); $gal[] = ['img' => "/assets/memes/{$slug}-g{$n}.webp", 'credit' => $c['credit'], 'credit_url' => $c['credit_url']]; }
    }
    if ($gal) file_put_contents("{$gdir}/{$slug}-gallery.json", json_encode($gal, JSON_UNESCAPED_SLASHES));
    return ['img' => "/assets/covers/{$slug}-featured.webp", 'credit' => $win['credit'],
            'credit_url' => $win['credit_url'], 'count' => 1 + count($gal)];
}

/**
 * UNIFIED DRAMA IMAGES (2026-06-14): dramas now get the same 3-4-real-image
 * treatment as terms. Featured = VS-card (2 verified faces in a feud) OR a
 * single verified creator face OR the best GIPHY still. Gallery = GIPHY stills
 * of the people/event. Faces are VERIFIED (wikidata_creator_photo) so a
 * historical namesake never lands on a real story. Returns
 * ['img','credit','credit_url','count'] or null (caller keeps branded card).
 */
function drama_images(array $people, string $subject, string $slug, string $meaning = '', string $mood = ''): ?array {
    require_once dirname(__FILE__) . '/images.php';
    require_once dirname(__FILE__) . '/vs_card.php';
    // allow single-word creator names (MrBeast, iDubbbz); wikidata_creator_photo
    // verifies each, so a wrong single-word match gets rejected anyway.
    $people = array_values(array_filter(array_map('trim', $people), fn($p) => mb_strlen($p) >= 3));
    // verified creator faces
    $faces = [];
    foreach (array_slice($people, 0, 3) as $p) {
        $f = wikidata_creator_photo($p);
        if ($f) $faces[$p] = $f;
    }
    $cover = null; $credit = null; $curl = null;
    // 2 verified faces in a feud -> VS card
    if (count($faces) >= 2 && in_array($mood, ['conflict', 'scandal'], true)) {
        $names = array_keys($faces); $fa = $faces[$names[0]]; $fb = $faces[$names[1]];
        $vs = make_vs_card(['url' => $fa['url'], 'name' => $names[0]], ['url' => $fb['url'], 'name' => $names[1]], $slug);
        if ($vs) { $cover = $vs; $credit = "Photos: {$fa['creator']} ({$fa['license']}), {$fb['creator']} ({$fb['license']}), via Wikimedia Commons"; $curl = $fa['foreign']; }
    }
    // single verified face -> face-safe hero
    if (!$cover && $faces) {
        $first = reset($faces);
        $h = wm_face_hero($first, $slug);
        if ($h) { $cover = $h['cover']; $credit = $h['credit']; $curl = $h['credit_url']; }
    }
    // NO verified person -> REFUSE to guess (GIPHY-by-name returns the wrong
    // "John Davis" too). A careful editor uses a placeholder, not a wrong face.
    if (!$cover) return null;                                   // -> branded card
    // gallery = GIPHY of the verified person (real content about them)
    $cands = beast_meme_search($people[0], 5);
    // build gallery from GIPHY stills (faces already used as featured)
    $gdir = dirname(__FILE__, 1) === false ? '' : dirname(__DIR__) . '/public_html/assets/memes';
    @mkdir($gdir, 0755, true);
    $gal = []; $n = 0;
    foreach ($cands as $c) {
        if ($n >= 3) break;
        $gb = (!empty($c['path']) && is_file($c['path'])) ? (string)file_get_contents($c['path']) : null;
        if (!$gb) continue;
        $n++; $gp = "{$gdir}/{$slug}-g{$n}.webp";
        if (beast_gallery_webp($gb, $gp)) { img_renditions($gp); $gal[] = ['img' => "/assets/memes/{$slug}-g{$n}.webp", 'credit' => $c['credit'], 'credit_url' => $c['credit_url']]; }
    }
    if ($gal) file_put_contents("{$gdir}/{$slug}-gallery.json", json_encode($gal, JSON_UNESCAPED_SLASHES));
    return ['img' => $cover, 'credit' => $credit, 'credit_url' => $curl, 'count' => 1 + count($gal)];
}

/** Resize raw image bytes to <=900px wide (natural aspect) and save as webp. */
function beast_gallery_webp(string $bytes, string $absPath): bool {
    $img = @imagecreatefromstring($bytes);
    if (!$img) return false;
    // CRITICAL: GIF stills are PALETTE images; imagewebp writes 0 bytes from a
    // palette source. Always render onto a truecolor canvas first.
    $w = imagesx($img); $h = imagesy($img);
    $tw = min(900, $w); $th = (int)round($h * $tw / $w);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($img);
    imagewebp($dst, $absPath, 80);
    imagedestroy($dst);
    return is_file($absPath) && filesize($absPath) > 0;   // verify REAL bytes
}

/**
 * INLINE MEME GALLERY (the KYM gap): 2-3 REAL meme stills rendered in the
 * article body, natural aspect ratio (never hero-cropped), attributed.
 * Stored as /assets/memes/{slug}-gN.webp + {slug}-gallery.json sidecar that
 * the term template reads. $excludeUrls skips stills already used as the hero.
 */
function beast_meme_gallery(string $subject, string $slug, int $max = 3, array $excludeUrls = []): array {
    $cands = beast_meme_search($subject, $max + 3);
    $dir = dirname(__DIR__) . '/public_html/assets/memes';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $out = []; $seen = []; $i = 0;
    foreach ($cands as $c) {
        if (count($out) >= $max) break;
        if (in_array($c['credit_url'] ?? '', $excludeUrls, true)) continue;
        $bytes = (!empty($c['path']) && is_file($c['path'])) ? (string)file_get_contents($c['path']) : beast_media_get($c['url']);
        if (!$bytes) continue;
        $h = md5($bytes);
        if (isset($seen[$h])) continue;          // same still uploaded twice on GIPHY
        $seen[$h] = 1;
        $img = @imagecreatefromstring($bytes);
        if (!$img) continue;
        $w = imagesx($img); $hh = imagesy($img);
        if ($w > 900) {
            $nh = (int)($hh * 900 / $w);
            $dst = imagecreatetruecolor(900, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, 900, $nh, $w, $hh);
            imagedestroy($img); $img = $dst;
        }
        $i++;
        $file = "/assets/memes/{$slug}-g{$i}.webp";
        if (!imagewebp($img, dirname(__DIR__) . '/public_html' . $file, 80)) { imagedestroy($img); continue; }
        imagedestroy($img);
        $out[] = ['img' => $file, 'credit' => $c['credit'], 'credit_url' => $c['credit_url']];
    }
    $json = dirname(__DIR__) . "/public_html/assets/memes/{$slug}-gallery.json";
    if ($out) file_put_contents($json, json_encode($out, JSON_UNESCAPED_SLASHES));
    return $out;
}

/* --------------------------------------------- PHASE 2: generation backend */

/**
 * generate_image($prompt): swappable backend, config image_gen.provider.
 * Built: pollinations (free FLUX, HTTP GET, no key). Hooks only: hf_space,
 * gemini (return null until built — chain degrades gracefully).
 * Cached by prompt hash so refines/retries never regenerate duplicates.
 * Returns ['path'=>local file,'url'=>public url,'credit','credit_url','source'] or null.
 */
function generate_image(string $prompt): ?array {
    global $CONFIG;
    $provider = $CONFIG['image_gen']['provider'] ?? 'pollinations';
    $dir = dirname(__DIR__) . '/public_html/assets/gen';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $hash = sha1($provider . '|' . $prompt);
    $path = "$dir/$hash.jpg";
    if (is_file($path) && filesize($path) > 10000) {
        return ['path' => $path, 'url' => "/assets/gen/$hash.jpg",
                'credit' => 'AI-generated illustration (FLUX)', 'credit_url' => '', 'source' => 'gen'];
    }
    $bytes = null;
    if ($provider === 'pollinations') {
        // NEW API (2026): gen.pollinations.ai + Bearer token (enter.pollinations.ai).
        // The legacy image.pollinations.ai anon queue is permanently contended on
        // shared-host IPs, so the token is required, not optional.
        $tok = $CONFIG['image_gen']['token'] ?? '';
        if ($tok === '') return null;
        $ch = curl_init('https://gen.pollinations.ai/image/' . rawurlencode($prompt)
            . '?model=flux&width=1200&height=675');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'GenZHypeDesk/1.0 (+https://genzhype.com)',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
        ]);
        $bytes = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) $bytes = null;
    } elseif ($provider === 'hf_space') {
        // HOOK: future HuggingFace Space running FLUX (operator will provide endpoint)
        return null;
    } elseif ($provider === 'gemini') {
        // HOOK: future Gemini image generation
        return null;
    }
    // accept only real image bytes (Pollinations returns an error page on overload)
    if (!$bytes || strlen($bytes) < 10000 || !@imagecreatefromstring($bytes)) return null;
    file_put_contents($path, $bytes);
    return ['path' => $path, 'url' => "/assets/gen/$hash.jpg",
            'credit' => 'AI-generated illustration (FLUX)', 'credit_url' => '', 'source' => 'gen'];
}

/* ---------------------------------------------------------------- STEP 3 */

/** Fan out to the bucket's sources; collect up to BEAST_MAX_CANDS candidates. */
function beast_candidates(array $page, array $u, array $strat, array &$trace): array {
    $cands = [];
    $add = function (array $c) use (&$cands) { if (count($cands) < BEAST_MAX_CANDS) $cands[] = $c; };

    if ($u['bucket'] === 'real_entity') {
        // REUSE the existing face/Wikimedia path
        $people = $page['people'] ?: [$page['subject']];
        foreach (array_slice($people, 0, 2) as $person) {
            foreach (wm_person_candidates($person, 3) as $c) {
                $add(['url' => $c['url'], 'thumb' => $c['thumb'] ?? $c['url'],
                      'credit' => "Photo: {$c['creator']} ({$c['license']}), via {$c['source']}",
                      'credit_url' => $c['foreign'], 'source' => 'wikimedia']);
            }
        }
        $trace[] = 'sources: wikimedia faces (' . count($cands) . ' cands)';
    }
    if ($u['bucket'] === 'specific_meme') {
        $memes = beast_meme_search($page['subject'], 3);
        foreach ($memes as $m) $add($m);
        $trace[] = 'sources: meme-db (' . count($memes) . ' cands' . ($memes ? '' : ' — no key or no hits') . ') + generation backup';
        $gen = generate_image($strat['gen_prompt']);
        if ($gen) $add($gen + ['thumb' => null]);
    }
    if ($u['bucket'] === 'abstract_vibe') {
        $gen = generate_image($strat['gen_prompt']);                     // PRIMARY
        if ($gen) $add($gen + ['thumb' => null]);
        $n = 0;
        foreach ($strat['stock_queries'] as $q) {                        // secondary
            foreach (pexels_photos($q, 2) as $p) {
                if (($p['w'] ?? 0) < 1200) continue;
                $add($p + ['source' => 'pexels']); $n++;
            }
            if (count($cands) >= BEAST_MAX_CANDS) break;
        }
        $trace[] = 'sources: generation' . ($gen ? '' : ' (FAILED)') . " + pexels ({$n} cands)";
    }
    if ($u['bucket'] === 'event_action') {
        $n = 0;
        foreach ($strat['stock_queries'] as $q) {                        // stock first
            foreach (pexels_photos($q, 2) as $p) {
                if (($p['w'] ?? 0) < 1200) continue;
                $add($p + ['source' => 'pexels']); $n++;
            }
            if (count($cands) >= BEAST_MAX_CANDS - 1) break;             // keep room for gen
        }
        $gen = generate_image($strat['gen_prompt']);
        if ($gen) $add($gen + ['thumb' => null]);
        $trace[] = "sources: pexels ({$n} cands) + generation" . ($gen ? '' : ' (FAILED)');
    }
    return $cands;
}

/* ---------------------------------------------------------------- STEP 4 */

/** b64 data-URL for a candidate (local generated file OR remote url). */
function beast_b64(array $cand): ?string {
    if (!empty($cand['path']) && is_file($cand['path'])) {
        $src = @imagecreatefromstring((string)file_get_contents($cand['path']));
        if (!$src) return null;
        $w = imagesx($src); $h = imagesy($src);
        if ($w > 448) {
            $nh = (int)($h * 448 / $w);
            $dst = imagecreatetruecolor(448, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, 448, $nh, $w, $h);
            imagedestroy($src); $src = $dst;
        }
        ob_start(); imagejpeg($src, null, 80); $jpeg = ob_get_clean(); imagedestroy($src);
        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
    return vision_b64($cand['thumb'] ?? $cand['url'], 448);
}

/**
 * The fit gate. Captions what is ACTUALLY in each image and scores fit vs the
 * MEANING (0-1). One Gemini batch call; NVIDIA per-image fallback. Returns
 * ['best'=>idx|-1,'fit'=>float,'caption'=>..,'reject_reasons'=>[..]].
 */
function beast_judge(array $cands, array $page, array &$trace): array {
    if (!$cands) return ['best' => -1, 'fit' => 0.0, 'caption' => '', 'reject_reasons' => ['no candidates']];
    $head = "Featured-image audition for a page about \"{$page['subject']}\" — meaning: {$page['meaning']}. "
          . "For EACH image IN ORDER: caption what is ACTUALLY shown (8-15 words, brutally literal), then score fit 0.0-1.0 "
          . "(1.0 = instantly evokes the meaning; 0.0 = absurdly off like a therapist for a sleep term). "
          . "Penalize clinical/business/staged-stock looks. Also set unsafe=true if the image contains sexual content, "
          . "gore, or visible slurs (general-audience site). STRICT JSON: "
          . '{"items":[{"caption":"..","fit":0.0,"why":"short","unsafe":false}]}';
    $content = [['type' => 'text', 'text' => $head]];
    $loaded = [];
    foreach ($cands as $i => $c) {
        $b64 = beast_b64($c);
        if ($b64) { $content[] = ['type' => 'image_url', 'image_url' => ['url' => $b64]]; $loaded[] = $i; }
        else { $content[] = ['type' => 'text', 'text' => "(image " . count($loaded) . " failed to load: caption 'unloadable', fit 0)"]; }
    }
    if (!$loaded) return ['best' => -1, 'fit' => 0.0, 'caption' => '', 'reject_reasons' => ['no candidate loadable']];
    $res = ai_chat([['role' => 'user', 'content' => $content]], ['gemini'], 0.1);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    if ($j && !empty($j['items'])) {
        $bestI = -1; $bestF = -1; $bestCap = ''; $rej = [];
        foreach (array_values($j['items']) as $k => $it) {
            $idx = $loaded[$k] ?? null;
            if ($idx === null) break;
            $f = max(0.0, min(1.0, (float)($it['fit'] ?? 0)));
            if (!empty($it['unsafe'])) { $f = 0.0; $rej[] = 'UNSAFE: ' . ($it['caption'] ?? '?'); }   // safety gate
            $cands[$idx]['_fit'] = $f;
            if ($f > $bestF) { $bestF = $f; $bestI = $idx; $bestCap = (string)($it['caption'] ?? ''); }
            if ($f < BEAST_FIT_OK) $rej[] = trim(($it['caption'] ?? '?') . ' — ' . ($it['why'] ?? ''));
        }
        $trace[] = 'judge: gemini batch, ' . count($loaded) . ' scored, best ' . round($bestF, 2);
        return ['best' => $bestI, 'fit' => max(0, $bestF), 'caption' => $bestCap, 'reject_reasons' => array_slice($rej, 0, 4)];
    }
    // fallback: NVIDIA scores each (<=4 to bound time)
    $bestI = -1; $bestF = -1; $bestCap = ''; $rej = [];
    foreach (array_slice($loaded, 0, 4) as $idx) {
        $b64 = beast_b64($cands[$idx]);
        if (!$b64) continue;
        $v = vision_nvidia("Page meaning: {$page['meaning']}. Caption what is ACTUALLY shown (10 words), score fit 0.0-1.0, and set unsafe=true for sexual content/gore/visible slurs. STRICT JSON: {\"caption\":\"..\",\"fit\":0.0,\"unsafe\":false}", $b64);
        if (!$v) continue;
        $f = !empty($v['unsafe']) ? 0.0 : max(0.0, min(1.0, (float)($v['fit'] ?? 0)));
        if ($f > $bestF) { $bestF = $f; $bestI = $idx; $bestCap = (string)($v['caption'] ?? ''); }
        if ($f < BEAST_FIT_OK) $rej[] = (string)($v['caption'] ?? '?');
    }
    $trace[] = 'judge: nvidia per-image fallback, best ' . round(max(0, $bestF), 2);
    return ['best' => $bestI, 'fit' => max(0, $bestF), 'caption' => $bestCap, 'reject_reasons' => array_slice($rej, 0, 4)];
}

/* ---------------------------------------------------------------- STEP 5 */

/** Rewrite the generation prompt SMARTER from the judge's rejection reasons. */
function beast_refine_prompt(string $oldPrompt, array $rejectReasons, array $page): string {
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            'An image-generation prompt produced rejected images. Rewrite it to FIX the listed failures (e.g. "too clinical" -> homely/candid; "wrong subject" -> name the subject\'s exact pose/state). Keep 20-40 words, keep the editorial/illustration style, never real names/logos. Reply with ONLY the new prompt.'],
        ['role' => 'user', 'content' =>
            "Meaning to convey: {$page['meaning']}\nOld prompt: {$oldPrompt}\nJudge rejections: " . implode(' | ', $rejectReasons)],
    ], ['gemini', 'openrouter', 'nvidia'], 0.5);
    $new = trim($res['content'] ?? '', " \n\"'");
    return ($new !== '' && mb_strlen($new) > 15) ? $new : $oldPrompt;
}

/** AI alt text: literal <=120-char description of the WINNING image (a11y + image SEO). */
function beast_alt(array $win, string $subject): ?string {
    $b64 = beast_b64($win);
    if (!$b64) return null;
    $p = "Write alt text for this image, used on a page about \"{$subject}\". Literal description of what is shown, max 120 characters, no \"image of\"/\"picture of\". STRICT JSON: {\"alt\":\"..\"}";
    $r = ai_chat([['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $p], ['type' => 'image_url', 'image_url' => ['url' => $b64]],
    ]]], ['gemini'], 0.2);
    $j = isset($r['error']) ? null : ai_json($r['content']);
    if (!$j) $j = vision_nvidia($p, $b64);
    $alt = trim((string)($j['alt'] ?? ''));
    return ($alt !== '' && mb_strlen($alt) >= 10) ? mb_substr($alt, 0, 125) : null;
}

/* ------------------------------------------------------- STEP 6 + PUBLIC */

/**
 * PUBLIC ENTRY. $page = ['page_type','subject','meaning','slug','people'=>[]].
 * $opts: 'suffix' (default '-featured') for test runs.
 * Returns ['img','credit','credit_url','source','fit_score','reason','bucket',
 *          'refines','trace','secs'] — guaranteed an image (legacy engine is
 *          the final net), or null only if every source on the internet is down.
 */
function fetch_featured_image(array $page, array $opts = []): ?array {
    $t0 = microtime(true);
    $page += ['people' => [], 'page_type' => 'term'];
    $suffix = $opts['suffix'] ?? '-featured';
    $trace = [];

    $u = beast_understand($page);                                          // STEP 1
    $trace[] = "bucket: {$u['bucket']} — {$u['reasoning']}";
    $strat = beast_strategy($page, $u);                                    // STEP 2
    $trace[] = "gen_prompt: {$strat['gen_prompt']}";
    $trace[] = 'stock_queries: ' . implode(' / ', $strat['stock_queries']);

    $cands = beast_candidates($page, $u, $strat, $trace);                  // STEP 3
    $trace[] = 'candidates: ' . count($cands);
    $verdict = beast_judge($cands, $page, $trace);                         // STEP 4

    $refines = 0;                                                          // STEP 5
    $genPrompt = $strat['gen_prompt'];
    while ($verdict['fit'] < BEAST_FIT_OK && $refines < BEAST_MAX_REFINES) {
        $genPrompt = beast_refine_prompt($genPrompt, $verdict['reject_reasons'], $page);
        $trace[] = 'refine ' . ($refines + 1) . ": {$genPrompt}";
        $gen = generate_image($genPrompt);
        if (!$gen) { $trace[] = 'refine: generation failed, stopping'; break; }
        $newCand = $gen + ['thumb' => null];
        $v2 = beast_judge([$newCand], $page, $trace);
        if ($v2['fit'] > $verdict['fit']) {
            $cands[] = $newCand;
            $verdict = ['best' => count($cands) - 1, 'fit' => $v2['fit'],
                        'caption' => $v2['caption'], 'reject_reasons' => $v2['reject_reasons']];
        }
        $refines++;
    }

    // STEP 6 — accept the best available, NEVER blank
    if ($verdict['best'] >= 0) {
        $win = $cands[$verdict['best']];
        $bytes = !empty($win['path']) && is_file($win['path'])
               ? (string)file_get_contents($win['path'])
               : fs_http_get($win['url'], 60);
        // NEAR-DUPLICATE GUARD (perceptual hash): never give two pages the same
        // photo. On collision, swap to the best non-duplicate candidate; if all
        // collide, keep the winner (never blank) and say so in the trace.
        $hashIdx = __DIR__ . '/imghash.json';
        $idx = is_file($hashIdx) ? (json_decode((string)file_get_contents($hashIdx), true) ?: []) : [];
        $isDup = function (?string $h) use ($idx, $page) {
            if (!$h) return false;
            foreach ($idx as $slug => $oh) if ($slug !== $page['slug'] && img_hamming($h, $oh) <= 6) return $slug;
            return false;
        };
        if ($bytes && ($dupOf = $isDup(img_dhash($bytes)))) {
            $trace[] = "dedup: winner is near-duplicate of '{$dupOf}', trying alternates";
            $swapped = false;
            foreach ($cands as $ci => $c) {
                if ($ci === $verdict['best'] || (($c['_fit'] ?? 0) < BEAST_FIT_OK * 0.8 && isset($c['_fit']))) continue;
                $b2 = !empty($c['path']) && is_file($c['path']) ? (string)file_get_contents($c['path']) : fs_http_get($c['url'], 60);
                if ($b2 && !$isDup(img_dhash($b2))) { $win = $c; $bytes = $b2; $swapped = true; $trace[] = "dedup: swapped to candidate {$ci}"; break; }
            }
            if (!$swapped) $trace[] = 'dedup: all candidates collide, keeping winner';
        }
        $out = dirname(__DIR__) . "/public_html/assets/covers/{$page['slug']}{$suffix}.webp";
        if ($bytes && img_process_hero($bytes, $out)) {                    // existing brand overlay
            $alt = beast_alt($win, $page['subject']);                      // a11y + image SEO
            // PERSIST the decision next to the file (WP-plugin pattern): prompt,
            // alt, verdict - reviewable + reusable at render time.
            $meta = ['alt' => $alt, 'prompt' => $genPrompt, 'source' => $win['source'],
                     'fit' => round($verdict['fit'], 2), 'caption' => $verdict['caption'],
                     'credit' => $win['credit'], 'at' => date('c')];
            @file_put_contents(str_replace('.webp', '-meta.json', $out), json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            // record the final image's perceptual hash for future dedup
            $winHash = img_dhash((string)file_get_contents($out));
            if ($winHash) {
                $idx[$page['slug']] = $winHash;
                @file_put_contents($hashIdx, json_encode($idx));
            }
            // SUPPORTING GALLERY (operator: 3 images/page). Reuse the already-
            // judged runner-up candidates (near-free). specific_meme uses real
            // stills via the caller's beast_meme_gallery instead. Fill only the
            // gap up to 2 gallery images, preserving any receipt entries.
            if (!empty($opts['gallery']) && $suffix === '-featured' && $u['bucket'] !== 'specific_meme') {
                $gdir = dirname(__DIR__) . '/public_html/assets/memes';
                $gf = "{$gdir}/{$page['slug']}-gallery.json";
                $existing = is_file($gf) ? (json_decode((string)file_get_contents($gf), true) ?: []) : [];
                $need = 2 - count($existing);
                if ($need > 0) {
                    @mkdir($gdir, 0755, true);
                    $seenH = [$winHash]; $gn = count($existing); $added = [];
                    foreach ($cands as $ci => $c) {
                        if (count($added) >= $need) break;
                        if ($ci === $verdict['best'] || ($c['_fit'] ?? 0) < 0.55) continue;
                        $gb = !empty($c['path']) && is_file($c['path']) ? (string)file_get_contents($c['path']) : fs_http_get($c['url'], 40);
                        if (!$gb) continue;
                        $gh = img_dhash($gb); $dup = false;
                        foreach ($seenH as $sh) if ($gh && $sh && img_hamming($gh, $sh) <= 6) { $dup = true; break; }
                        if ($dup) continue;
                        $seenH[] = $gh; $gn++;
                        $gpath = "{$gdir}/{$page['slug']}-g{$gn}.webp";
                        if (beast_gallery_webp($gb, $gpath)) {
                            img_renditions($gpath);
                            $added[] = ['img' => "/assets/memes/{$page['slug']}-g{$gn}.webp",
                                        'credit' => $c['credit'] ?? '', 'credit_url' => $c['credit_url'] ?? ''];
                        }
                    }
                    if ($added) {
                        file_put_contents($gf, json_encode(array_slice(array_merge($existing, $added), 0, 3), JSON_UNESCAPED_SLASHES));
                        $trace[] = 'gallery: +' . count($added) . ' supporting images';
                    }
                }
            }
            return ['img' => "/assets/covers/{$page['slug']}{$suffix}.webp",
                    'credit' => $win['credit'], 'credit_url' => $win['credit_url'],
                    'source' => $win['source'], 'fit_score' => round($verdict['fit'], 2),
                    'reason' => $verdict['caption'], 'alt' => $alt, 'bucket' => $u['bucket'],
                    'refines' => $refines, 'trace' => $trace,
                    'secs' => round(microtime(true) - $t0, 1)];
        }
        $trace[] = 'finalize: winner failed to download/process';
    }

    // GUARANTEED FALLBACK: the legacy always-image engine
    $trace[] = 'fallback: legacy featured_image_for()';
    // legacy writes {slug}-featured.webp; in test mode keep test filenames apart
    $legacySlug = ($suffix === '-featured') ? $page['slug'] : $page['slug'] . $suffix;
    $legacy = featured_image_for($page['subject'], $page['meaning'], $legacySlug);
    if ($legacy) {
        return $legacy + ['source' => 'legacy-fallback', 'fit_score' => null,
                          'reason' => 'beast chain exhausted', 'bucket' => $u['bucket'],
                          'refines' => $refines, 'trace' => $trace,
                          'secs' => round(microtime(true) - $t0, 1)];
    }
    return null;
}
