<?php
// GenZHype | image sourcing. Tries a real Creative-Commons photo via Openverse
// (free, no key, attribution stored). Falls back to the branded SVG cover.
// Never hosts a copyrighted photo: only CC/public-domain results are downloaded.

const IMG_UA = 'GenZHypeDesk/1.0 (https://genzhype.com) image-sourcing';

/** Like openverse_find but returns ALL passing results (for relevance filtering). */
function openverse_find_all(string $query, int $max = 6): array {
    $out = [];
    $url = 'https://api.openverse.org/v1/images/?' . http_build_query([
        'q' => $query, 'license_type' => 'all', 'page_size' => 20, 'mature' => 'false', 'aspect_ratio' => 'wide',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => IMG_UA, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$raw) return [];
    $j = json_decode($raw, true);
    $ok = ['cc0','pdm','by','by-sa'];
    $photoSources = ['flickr','wikimedia','stocksnap','rawpixel','wordpress','nappy','spacex'];
    foreach (($j['results'] ?? []) as $r) {
        if (!in_array(strtolower($r['license'] ?? ''), $ok, true)) continue;
        if (empty($r['url']) || ($r['width'] ?? 0) < 600) continue;
        if (!in_array(strtolower($r['source'] ?? ''), $photoSources, true)) continue;
        if (preg_match('/\.(svg|gif)(\?|$)|sketchfab|model/', strtolower($r['url']))) continue;
        $out[] = [
            'url' => $r['url'], 'thumbnail' => $r['thumbnail'] ?? $r['url'],
            'license' => strtolower($r['license']), 'creator' => $r['creator'] ?? 'Unknown',
            'source' => $r['source'] ?? 'openverse', 'foreign' => $r['foreign_landing_url'] ?? $r['url'],
            'title' => $r['title'] ?? '',
        ];
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Search Openverse for a usable CC/PD image. Returns row or null. */
function openverse_find(string $query): ?array {
    $url = 'https://api.openverse.org/v1/images/?' . http_build_query([
        'q'             => $query,
        'license_type'  => 'all',          // commercial-ok handled by filtering below
        'page_size'     => 8,
        'mature'        => 'false',
        'aspect_ratio'  => 'wide',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => IMG_UA,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $j = json_decode($raw, true);
    // commercial-safe, modification-ok licenses only
    $ok = ['cc0','pdm','by','by-sa'];
    // photo providers only (skip 3D models, clip-art, icons, drawings)
    $photoSources = ['flickr','wikimedia','stocksnap','rawpixel','wordpress','nappy','spacex'];
    foreach (($j['results'] ?? []) as $r) {
        $lic = strtolower($r['license'] ?? '');
        if (!in_array($lic, $ok, true)) continue;
        if (empty($r['url'])) continue;
        if (($r['width'] ?? 0) < 600) continue;
        if (!in_array(strtolower($r['source'] ?? ''), $photoSources, true)) continue;
        $u = strtolower($r['url']);
        if (preg_match('/\.(svg|gif)(\?|$)|sketchfab|model/', $u)) continue;
        return [
            'url'        => $r['url'],
            'thumbnail'  => $r['thumbnail'] ?? $r['url'],
            'license'    => $lic,
            'creator'    => $r['creator'] ?? 'Unknown',
            'source'     => $r['source'] ?? 'openverse',
            'foreign'    => $r['foreign_landing_url'] ?? ($r['url'] ?? ''),
            'title'      => $r['title'] ?? '',
        ];
    }
    return null;
}

/** Download a CC image, normalize to a 1200x630 webp hero. Returns web path or null. */
function openverse_fetch_hero(array $img, string $slug): ?array {
    $ch = curl_init($img['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => IMG_UA,
        CURLOPT_RANGE          => '0-8000000',
    ]);
    $bytes = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (($code !== 200 && $code !== 206) || !$bytes) return null;

    $dir  = dirname(__DIR__) . '/public_html/assets/covers';
    $path = "{$dir}/{$slug}.webp";
    if (!img_process_hero($bytes, $path)) return null;
    return [
        'cover'      => "/assets/covers/{$slug}.webp",
        'credit'     => trim("Photo: {$img['creator']} ({$img['license']}), via {$img['source']}"),
        'credit_url' => $img['foreign'],
    ];
}

/**
 * Normalize raw image bytes into a 1200x630 webp hero with the brand red rule.
 * Uses Intervention Image (auto-rotates by EXIF, smarter crop) when available;
 * falls back to raw GD. Writes to $path, returns true on success.
 */
function img_process_hero(string $bytes, string $path): bool {
    require_once __DIR__ . '/img_brain.php';
    // 1) EXIF-orient first (sideways phone photos) via Intervention if present
    if (defined('GZH_LIBS') && GZH_LIBS && class_exists(\Intervention\Image\ImageManager::class)) {
        try {
            $m = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
            $img = $m->read($bytes);
            $img->orient();
            $bytes = (string)$img->toJpeg(92);   // hand the oriented pixels to the smart crop
        } catch (\Throwable $e) { /* keep original bytes */ }
    }
    // 2) SMART crop: frame the face when there's a person, the subject otherwise
    //    (replaces the old crude 15%-bias center-crop; validated by eye on portrait,
    //     2-person, cartoon-meme, and night-scene images).
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    $face = img_detect_face($src);
    $dst = $face ? img_facecrop_gd($src, 1200, 630, $face) : img_smartcrop_gd($src, 1200, 630);
    imagedestroy($src);
    if (!$dst) {                                 // smart crop failed -> plain cover-crop
        $src = @imagecreatefromstring($bytes);
        if (!$src) return false;
        $sw = imagesx($src); $sh = imagesy($src);
        $dst = imagecreatetruecolor(1200, 630);
        $scale = max(1200 / $sw, 630 / $sh);
        $nw = (int)ceil($sw * $scale); $nh = (int)ceil($sh * $scale);
        imagecopyresampled($dst, $src, (int)(((1200 - $nw)) / 2), (int)(((630 - $nh)) / 2), 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($src);
    }
    // 3) webp + responsive renditions (no brand stamp on sourced images)
    $ok = imagewebp($dst, $path, 82);
    imagedestroy($dst);
    if ($ok && is_file($path) && filesize($path) > 0) { img_renditions($path); return true; }
    return false;
}

/**
 * RESPONSIVE RENDITIONS (image SEO upgrade 4): 480w + 768w webp variants next
 * to a 1200w hero, so cards/rails stop downloading the full-size file.
 * Called automatically by img_process_hero; safe to re-run (overwrites).
 */
function img_renditions(string $absWebp): int {
    if (!is_file($absWebp) || !str_ends_with($absWebp, '.webp')) return 0;
    $src = @imagecreatefromstring((string)file_get_contents($absWebp));
    if (!$src) return 0;
    $w = imagesx($src); $h = imagesy($src); $n = 0;
    foreach ([480, 768] as $tw) {
        if ($w <= $tw) continue;
        $th = (int)round($h * $tw / $w);
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        if (imagewebp($dst, substr($absWebp, 0, -5) . "-{$tw}.webp", 78)) $n++;
        imagedestroy($dst);
    }
    imagedestroy($src);
    return $n;
}

/**
 * PERCEPTUAL HASH (image SEO upgrade 6, dependency-free dHash): catches
 * "same photo, different file" - the class behind the 5-identical-fallback
 * fiasco that byte-md5 missed. 64-bit hash as 16 hex chars.
 */
function img_dhash(string $bytes): ?string {
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    $g = imagecreatetruecolor(9, 8);
    imagecopyresampled($g, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
    imagedestroy($src);
    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        $prev = null;
        for ($x = 0; $x < 9; $x++) {
            $c = imagecolorat($g, $x, $y);
            $lum = (($c >> 16 & 0xFF) * 299 + ($c >> 8 & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000;
            if ($prev !== null) $bits .= ($lum > $prev) ? '1' : '0';
            $prev = $lum;
        }
    }
    imagedestroy($g);
    $hex = '';
    foreach (str_split($bits, 4) as $nib) $hex .= dechex(bindec($nib));
    return $hex;
}

/** Hamming distance between two img_dhash hex strings (lower = more similar). */
function img_hamming(string $a, string $b): int {
    if (strlen($a) !== strlen($b)) return 64;
    $d = 0;
    for ($i = 0; $i < strlen($a); $i++) $d += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
    return $d;
}

/**
 * "Interest" score for a frame (0-100, no AI): luminance spread + edge density.
 * A dull/empty still (bare hallway) scores low; a busy meme/face scores high.
 * Used to pick the liveliest GIPHY still as the featured image.
 */
function img_interest(string $bytes): float {
    $src = @imagecreatefromstring($bytes);
    if (!$src) return 0.0;
    $w = 48; $h = 48;
    $s = imagecreatetruecolor($w, $h);
    imagecopyresampled($s, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    imagedestroy($src);
    $lum = [];
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($s, $x, $y);
        $lum[$y][$x] = (($c >> 16 & 255) * 299 + ($c >> 8 & 255) * 587 + ($c & 255) * 114) / 1000;
    }
    // spread (std dev)
    $all = []; foreach ($lum as $row) foreach ($row as $v) $all[] = $v;
    $mean = array_sum($all) / count($all);
    $var = 0; foreach ($all as $v) $var += ($v - $mean) ** 2; $sd = sqrt($var / count($all));
    // edge density (neighbour差)
    $edge = 0; $n = 0;
    for ($y = 0; $y < $h - 1; $y++) for ($x = 0; $x < $w - 1; $x++) {
        $edge += abs($lum[$y][$x] - $lum[$y][$x + 1]) + abs($lum[$y][$x] - $lum[$y + 1][$x]); $n++;
    }
    imagedestroy($s);
    $edgeAvg = $n ? $edge / $n : 0;
    return min(100.0, $sd * 0.6 + $edgeAvg * 4.0);   // tuned: dull<25, lively>40
}

/**
 * FACE-SAFE hero (2026-06-14): for a person photo, never crop the face off.
 * Fit the WHOLE image centered on a 1200x630 brand-paper canvas (letterbox),
 * red top rule. Looks like a deliberate centered portrait. Returns true on ok.
 */
function img_face_hero(string $bytes, string $path): bool {
    require_once __DIR__ . '/img_brain.php';
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    $sw = imagesx($src); $sh = imagesy($src);
    $tw = 1200; $th = 630;
    // A clear character/person face (>=18% of the width, so no tiny false positives)
    // -> FRAME it full-bleed (more impact for Patrick/gigachad/real faces). No face
    // (text/panel/scene memes) -> CONTAIN on brand paper so the whole meme stays visible.
    $face = img_detect_face($src);
    if ($face && $face['w'] >= 0.18 * $sw) {
        $dst = img_facecrop_gd($src, $tw, $th, $face);
    } else {
        // CONTAIN, but fill the margins with a BLURRED cover of the same image
        // (richer than flat paper) so square/portrait memes look intentional on a
        // wide hero. The sharp whole meme sits centred on top.
        $dst = imagecreatetruecolor($tw, $th);
        $bgS = max($tw / $sw, $th / $sh);
        $bnw = (int)ceil($sw * $bgS); $bnh = (int)ceil($sh * $bgS);
        $bg = imagecreatetruecolor($bnw, $bnh);
        imagecopyresampled($bg, $src, 0, 0, 0, 0, $bnw, $bnh, $sw, $sh);
        // cheap strong blur: shrink to 1/16 and back, then a couple gaussian passes
        $tnw = max(1, (int)($bnw / 8)); $tnh = max(1, (int)($bnh / 8));
        $tiny = imagecreatetruecolor($tnw, $tnh);
        imagecopyresampled($tiny, $bg, 0, 0, 0, 0, $tnw, $tnh, $bnw, $bnh);
        imagecopyresampled($bg, $tiny, 0, 0, 0, 0, $bnw, $bnh, $tnw, $tnh);
        for ($i = 0; $i < 6; $i++) imagefilter($bg, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopy($dst, $bg, 0, 0, (int)(($bnw - $tw) / 2), (int)(($bnh - $th) / 2), $tw, $th);
        imagefilter($dst, IMG_FILTER_BRIGHTNESS, -45);         // darken so the sharp foreground pops
        imagedestroy($bg); imagedestroy($tiny);
        // sharp, whole meme centred on top
        $scale = min($tw / $sw, $th / $sh);
        $nw = (int)round($sw * $scale); $nh = (int)round($sh * $scale);
        imagecopyresampled($dst, $src, (int)(($tw - $nw) / 2), (int)(($th - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
    }
    imagedestroy($src);
    $ok = imagewebp($dst, $path, 82);
    imagedestroy($dst);
    if ($ok && is_file($path) && filesize($path) > 0) { img_renditions($path); return true; }
    return false;
}

/** Download a trusted person photo and render it face-safe. Returns hero row or null. */
function wm_face_hero(array $img, string $slug): ?array {
    $ch = curl_init($img['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => IMG_UA, CURLOPT_RANGE => '0-9000000']);
    $bytes = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (($code !== 200 && $code !== 206) || !$bytes) return null;
    $path = dirname(__DIR__) . "/public_html/assets/covers/{$slug}.webp";
    if (!img_face_hero($bytes, $path)) return null;
    return ['cover' => "/assets/covers/{$slug}.webp",
            'credit' => trim("Photo: {$img['creator']} ({$img['license']}), via {$img['source']}"),
            'credit_url' => $img['foreign']];
}

/** JSON GET against the Wikimedia Commons API. */
function wm_api(array $params): ?array {
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query($params + ['format' => 'json']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => IMG_UA]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/**
 * ALL free-licensed candidate photos of a named person on Wikimedia Commons.
 * License verified per-file via extmetadata; only true free licenses pass.
 * Each row is openverse_fetch_hero-compatible and carries a small 'thumb' URL.
 */
function wm_person_candidates(string $name, int $max = 6): array {
    $out = [];
    $seenTitles = [];
    // canonical Wikidata P18 photo first (the definitive portrait)
    $p18 = wikidata_p18($name);
    if ($p18) { $out[] = $p18; $seenTitles[strtolower($p18['title'])] = true; }
    $s = wm_api(['action' => 'query', 'list' => 'search', 'srnamespace' => 6, 'srlimit' => 14,
                 'srsearch' => $name . ' filetype:bitmap']);
    $lastName = strtolower(trim(substr(strrchr(' ' . $name, ' '), 1)));
    foreach (($s['query']['search'] ?? []) as $hit) {
        if (count($out) >= $max) break;
        if (isset($seenTitles[strtolower($hit['title'] ?? '')])) continue;
        $title = $hit['title'] ?? '';
        $tl = strtolower($title);
        // must look like a photo OF the person, not a logo/signature/chart
        if (strpos($tl, $lastName) === false) continue;
        if (preg_match('/\.(svg|gif|pdf|tiff?)$|signature|logo|autograph|emblem|icon/i', $tl)) continue;
        $info = wm_api(['action' => 'query', 'titles' => $title, 'prop' => 'imageinfo',
                        'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 480]);
        $pages = $info['query']['pages'] ?? [];
        $page = $pages ? reset($pages) : null;
        $ii = $page['imageinfo'][0] ?? null;
        if (!$ii) continue;
        if (($ii['width'] ?? 0) < 600) continue;
        if (!in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true)) continue;
        $meta = $ii['extmetadata'] ?? [];
        $lic = strtolower($meta['LicenseShortName']['value'] ?? '');
        // free licenses only: CC BY / BY-SA / CC0 / public domain
        if (!preg_match('/^(cc by(-sa)? \d|cc0|public domain)/', $lic)) continue;
        $artist = trim(strip_tags($meta['Artist']['value'] ?? 'Unknown'));
        $out[] = [
            'url'      => $ii['url'],
            'thumb'    => $ii['thumburl'] ?? $ii['url'],
            'thumbnail'=> $ii['thumburl'] ?? $ii['url'],
            'license'  => $lic,
            'creator'  => mb_substr($artist ?: 'Unknown', 0, 80),
            'source'   => 'Wikimedia Commons',
            'foreign'  => $ii['descriptionurl'] ?? ('https://commons.wikimedia.org/wiki/' . rawurlencode($title)),
            'title'    => $title,
        ];
    }
    return $out;
}

/** First candidate (legacy single-photo path). */
function wm_person_image(string $name): ?array {
    $c = wm_person_candidates($name, 1);
    return $c[0] ?? null;
}

/** Build the standard image row from a Commons File: title (license-verified). */
function wm_file_info(string $fileTitle): ?array {
    if (stripos($fileTitle, 'File:') !== 0) $fileTitle = 'File:' . $fileTitle;
    $info = wm_api(['action' => 'query', 'titles' => $fileTitle, 'prop' => 'imageinfo',
                    'iiprop' => 'url|size|mime|extmetadata']);
    $pages = $info['query']['pages'] ?? [];
    $page = $pages ? reset($pages) : null;
    $ii = $page['imageinfo'][0] ?? null;
    if (!$ii || ($ii['width'] ?? 0) < 500) return null;
    if (!in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true)) return null;
    $meta = $ii['extmetadata'] ?? [];
    $lic = strtolower($meta['LicenseShortName']['value'] ?? '');
    if (!preg_match('/^(cc by(-sa)? \d|cc0|public domain)/', $lic)) return null;
    return [
        'url'      => $ii['url'],
        'thumb'    => $ii['url'],
        'thumbnail'=> $ii['url'],
        'license'  => $lic,
        'creator'  => mb_substr(trim(strip_tags($meta['Artist']['value'] ?? 'Unknown')) ?: 'Unknown', 0, 80),
        'source'   => 'Wikimedia Commons',
        'foreign'  => $ii['descriptionurl'] ?? ('https://commons.wikimedia.org/wiki/' . rawurlencode($fileTitle)),
        'title'    => $fileTitle,
    ];
}

/**
 * VERIFIED creator photo (2026-06-14 fix for the "John Davis = 1550 explorer"
 * bug): only returns a face if Wikidata's entity description reads as a CURRENT
 * internet/media figure — never a historical namesake. Returns row or null.
 */
function wikidata_creator_photo(string $name): ?array {
    $name = trim($name);
    if (mb_strlen($name) < 3) return null;
    $s = wm_api_host('www.wikidata.org', ['action' => 'wbsearchentities', 'search' => $name, 'language' => 'en', 'limit' => 1]);
    $hit = $s['search'][0] ?? null;
    if (!$hit) return null;
    $d = strtolower($hit['description'] ?? '');
    $isCreator = (bool)preg_match('/stream|youtub|tiktok|influencer|rapper|musician|singer|internet celebrit|content creator|gamer|podcast|comedian|personalit|social media|twitch|actor|actress|\bmodel\b/i', $d);
    $isWrong  = (bool)preg_match('/\b1[0-8]\d\d\b|\b19[0-5]\d\b|explorer|navigator|congress|governor|navy|footballer|bishop|painter|composer|politician|governing body|association|\bcompany\b|software|\balbum\b|\bsong\b|\bfilm\b/i', $d);
    if (!$isCreator || $isWrong) return null;
    $c = wm_api_host('www.wikidata.org', ['action' => 'wbgetclaims', 'entity' => $hit['id'], 'property' => 'P18']);
    $file = $c['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
    return $file ? wm_file_info('File:' . $file) : null;
}

/**
 * Wikidata P18: the CANONICAL free photo of any named entity (person, place).
 * More reliable than keyword search, which sometimes returns group shots.
 */
function wikidata_p18(string $name): ?array {
    $s = wm_api_host('www.wikidata.org', ['action' => 'wbsearchentities', 'search' => $name,
                                          'language' => 'en', 'limit' => 1]);
    $qid = $s['search'][0]['id'] ?? null;
    if (!$qid) return null;
    $c = wm_api_host('www.wikidata.org', ['action' => 'wbgetclaims', 'entity' => $qid, 'property' => 'P18']);
    $file = $c['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
    if (!$file) return null;
    return wm_file_info('File:' . $file);
}

/** Generic MediaWiki/Wikidata API GET against an arbitrary host. */
function wm_api_host(string $host, array $params): ?array {
    $url = "https://{$host}/w/api.php?" . http_build_query($params + ['format' => 'json']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => IMG_UA]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/**
 * Free concept stock photo (Pexels, then Pixabay) if a key is configured.
 * Silent (returns null) when no key — keyless sources cover us regardless.
 */
function stock_photo(string $query): ?array {
    global $CONFIG;
    $pexels = $CONFIG['pexels_key'] ?? '';
    if ($pexels !== '') {
        $ch = curl_init('https://api.pexels.com/v1/search?per_page=3&orientation=landscape&query=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $pexels]]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && $raw) {
            $j = json_decode($raw, true);
            $p = $j['photos'][0] ?? null;
            if ($p) return [
                'url' => $p['src']['large2x'] ?? $p['src']['original'], 'thumb' => $p['src']['medium'] ?? '',
                'license' => 'Pexels licence', 'creator' => $p['photographer'] ?? 'Pexels',
                'source' => 'Pexels', 'foreign' => $p['url'] ?? 'https://pexels.com', 'title' => $query,
            ];
        }
    }
    $pix = $CONFIG['pixabay_key'] ?? '';
    if ($pix !== '') {
        $raw = @file_get_contents('https://pixabay.com/api/?image_type=photo&orientation=horizontal&per_page=3&key=' . urlencode($pix) . '&q=' . urlencode($query));
        $j = $raw ? json_decode($raw, true) : null;
        $h = $j['hits'][0] ?? null;
        if ($h) return [
            'url' => $h['largeImageURL'] ?? $h['webformatURL'], 'thumb' => $h['previewURL'] ?? '',
            'license' => 'Pixabay licence', 'creator' => $h['user'] ?? 'Pixabay',
            'source' => 'Pixabay', 'foreign' => $h['pageURL'] ?? 'https://pixabay.com', 'title' => $query,
        ];
    }
    return null;
}

/**
 * The Met Museum Open Access: a PUBLIC-DOMAIN artwork matching $query.
 * Keyless. Perfect for witty slang/concept motifs (a real painting, not a meme).
 */
function met_pd_art(string $query): ?array {
    $s = json_decode(@file_get_contents('https://collectionapi.metmuseum.org/public/collection/v1/search?hasImages=true&q=' . urlencode($query)) ?: 'null', true);
    foreach (array_slice($s['objectIDs'] ?? [], 0, 8) as $oid) {
        $o = json_decode(@file_get_contents("https://collectionapi.metmuseum.org/public/collection/v1/objects/{$oid}") ?: 'null', true);
        if (!$o || empty($o['isPublicDomain']) || empty($o['primaryImage'])) continue;
        return [
            'url'      => $o['primaryImage'],
            'thumb'    => $o['primaryImageSmall'] ?? $o['primaryImage'],
            'license'  => 'public domain',
            'creator'  => mb_substr(($o['artistDisplayName'] ?: ($o['culture'] ?: 'Unknown')), 0, 80),
            'source'   => 'The Met',
            'foreign'  => $o['objectURL'] ?? 'https://www.metmuseum.org',
            'title'    => $o['title'] ?? $query,
        ];
    }
    return null;
}

/** Solo-portrait-first ordering: photos titled "X and Y" go to the back. */
function wm_prefer_solo(array $cands): array {
    usort($cands, function ($a, $b) {
        $ga = preg_match('/\b(and|with|&)\b/i', $a['title']) ? 1 : 0;
        $gb = preg_match('/\b(and|with|&)\b/i', $b['title']) ? 1 : 0;
        return $ga <=> $gb;
    });
    return $cands;
}

/**
 * Best face for a card half: solo-preferred, vision-checked for clarity.
 * Mood is relaxed to 'neutral' (the VS framing itself carries the tension).
 */
function wm_person_face(string $name, string $mood = 'neutral'): ?array {
    $cands = wm_prefer_solo(wm_person_candidates($name, 6));
    if (!$cands) return null;
    if (count($cands) > 1) {
        require_once __DIR__ . '/vision.php';
        $pick = vision_pick_face($cands, $name, $mood);
        if ($pick !== null) return $cands[$pick['index']];
    }
    return $cands[0];
}

/**
 * MOOD-MATCHED person photo: gather candidates, let vision pick the face whose
 * expression fits the story mood. Falls back to the first candidate.
 */
function wm_person_image_mood(string $name, string $mood): ?array {
    $cands = wm_person_candidates($name, 6);
    if (!$cands) return null;
    if (count($cands) > 1) {
        require_once __DIR__ . '/vision.php';
        $pick = vision_pick_face($cands, $name, $mood);
        if ($pick !== null) return $cands[$pick['index']];
    }
    return $cands[0];
}

/**
 * Decide the hero for a drama. Real person photo from Wikimedia Commons first,
 * then Openverse with the people/topic; on success returns a real-photo cover
 * + credit; otherwise null so the caller keeps the branded card.
 */
function source_hero_image(array $people, string $topic, string $slug): ?array {
    // 1) real faces: Wikimedia Commons photo of an involved person
    foreach (array_slice($people, 0, 3) as $person) {
        $person = trim($person);
        if (mb_strlen($person) < 4 || !str_contains($person, ' ')) continue;
        $hit = wm_person_image($person);
        if ($hit) {
            $hero = openverse_fetch_hero($hit, $slug);
            if ($hero) return $hero;
        }
    }
    // 2) fallback: Openverse topical search
    $queries = [];
    if ($people) $queries[] = implode(' ', array_slice($people, 0, 2));
    $queries[] = $topic;
    foreach ($queries as $q) {
        $q = trim($q);
        if (mb_strlen($q) < 3) continue;
        $hit = openverse_find($q);
        if ($hit) {
            $hero = openverse_fetch_hero($hit, $slug);
            if ($hero) return $hero;
        }
    }
    return null;
}
