<?php
// GenZHype | IMAGE BRAIN — the in-house build of what Cloudinary (smart-crop /
// g_auto) and NIMA (aesthetic/technical scoring) charge for. Pure GD, no paid API.
//  - img_saliency_map(): per-pixel "importance" (edges + skin + saturation)
//  - img_smartcrop():    crop framed to the important region, not the center
//  - img_quality():      technical score (sharpness + exposure + colorfulness +
//                        entropy) so we pick the striking image, not a dull one
// This is how a human editor treats a photo: find the subject, frame it, judge it.

/** Build a small importance/saliency map (rows of floats) from raw bytes. */
function img_saliency_map($src, int $aw = 96): ?array {
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw < 4 || $sh < 4) return null;
    $ah = max(2, (int)round($sh * $aw / $sw));
    $s = imagecreatetruecolor($aw, $ah);
    imagecopyresampled($s, $src, 0, 0, 0, 0, $aw, $ah, $sw, $sh);
    // cache pixels
    $px = [];
    for ($y = 0; $y < $ah; $y++) for ($x = 0; $x < $aw; $x++) {
        $c = imagecolorat($s, $x, $y); $px[$y][$x] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }
    $map = array_fill(0, $ah, array_fill(0, $aw, 0.0));
    for ($y = 0; $y < $ah; $y++) for ($x = 0; $x < $aw; $x++) {
        [$r, $g, $b] = $px[$y][$x];
        $mx = max($r, $g, $b); $mn = min($r, $g, $b);
        $sat = $mx ? ($mx - $mn) / $mx : 0;
        $skin = ($r > 95 && $g > 40 && $b > 20 && $r > $g && $r > $b && abs($r - $g) > 12) ? 1.0 : 0.0;
        $edge = 0.0;
        if ($x + 1 < $aw) { $n = $px[$y][$x + 1]; $edge += abs($r - $n[0]) + abs($g - $n[1]) + abs($b - $n[2]); }
        if ($y + 1 < $ah) { $n = $px[$y + 1][$x]; $edge += abs($r - $n[0]) + abs($g - $n[1]) + abs($b - $n[2]); }
        $edge = min(1.0, $edge / 320);
        $map[$y][$x] = $edge * 0.6 + $sat * 0.25 + $skin * 0.7;
    }
    imagedestroy($s);
    return ['map' => $map, 'aw' => $aw, 'ah' => $ah, 'sw' => $sw, 'sh' => $sh];
}

/**
 * Smart-crop to a target aspect: find the window of the right aspect that captures
 * the most importance (the subject), with a gentle center bias — then crop full-res.
 * Returns cropped GD image at exactly $tw x $th, or null.
 */
function img_smartcrop_gd($src, int $tw, int $th) {
    $sal = img_saliency_map($src);
    if (!$sal) return null;
    ['map' => $M, 'aw' => $aw, 'ah' => $ah, 'sw' => $sw, 'sh' => $sh] = $sal;
    // integral image for O(1) window sums
    $I = array_fill(0, $ah + 1, array_fill(0, $aw + 1, 0.0));
    for ($y = 1; $y <= $ah; $y++) for ($x = 1; $x <= $aw; $x++)
        $I[$y][$x] = $M[$y - 1][$x - 1] + $I[$y - 1][$x] + $I[$y][$x - 1] - $I[$y - 1][$x - 1];
    $sum = fn($x0, $y0, $x1, $y1) => $I[$y1][$x1] - $I[$y0][$x1] - $I[$y1][$x0] + $I[$y0][$x0];

    $aspect = $tw / $th;
    // largest target-aspect window that fits the analysis image
    $W = $aw; $H = (int)round($aw / $aspect);
    if ($H > $ah) { $H = $ah; $W = (int)round($ah * $aspect); }
    $W = max(2, min($aw, $W)); $H = max(2, min($ah, $H));
    $cx = ($aw - $W) / 2; $cy = ($ah - $H) / 2;
    $maxDist = sqrt($cx * $cx + $cy * $cy) ?: 1;

    $best = -1; $bx = (int)$cx; $by = (int)$cy;
    $step = max(1, (int)round(min($aw, $ah) / 40));
    for ($y = 0; $y + $H <= $ah; $y += $step) for ($x = 0; $x + $W <= $aw; $x += $step) {
        $s = $sum($x, $y, $x + $W, $y + $H);
        // gentle center bias so equally-busy framings prefer centered subjects
        $d = sqrt((($x - $cx) ** 2) + (($y - $cy) ** 2)) / $maxDist;
        $score = $s * (1 - 0.18 * $d);
        if ($score > $best) { $best = $score; $bx = $x; $by = $y; }
    }
    // map window back to full-res
    $fx = (int)round($bx / $aw * $sw);
    $fy = (int)round($by / $ah * $sh);
    $fw = (int)round($W / $aw * $sw);
    $fh = (int)round($H / $ah * $sh);
    $fw = min($fw, $sw - $fx); $fh = min($fh, $sh - $fy);

    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, $fx, $fy, $tw, $th, $fw, $fh);
    return $dst;
}

/** Free pure-PHP Haar-cascade face detect. Returns ['x','y','w'] (square) or null. */
function img_detect_face($src): ?array {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!class_exists('svay\\FaceDetector')) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'fd') . '.jpg';
    imagejpeg($src, $tmp, 90);
    try {
        $d = new \svay\FaceDetector();
        $d->faceDetect($tmp);
        $f = $d->getFace();
    } catch (\Throwable $e) { @unlink($tmp); return null; }
    @unlink($tmp);
    if (!$f || empty($f['w']) || $f['w'] < 24) return null;
    return ['x' => (float)$f['x'], 'y' => (float)$f['y'], 'w' => (float)$f['w']];
}

/** Crop framed to a detected face: face centred horizontally, eyes ~upper third. */
function img_facecrop_gd($src, int $tw, int $th, array $face) {
    $sw = imagesx($src); $sh = imagesy($src);
    $aspect = $tw / $th;
    $W = $sw; $H = (int)round($sw / $aspect);
    if ($H > $sh) { $H = $sh; $W = (int)round($sh * $aspect); }
    $W = max(2, min($sw, $W)); $H = max(2, min($sh, $H));
    $fcx = $face['x'] + $face['w'] / 2;
    $fcy = $face['y'] + $face['w'] / 2;
    $x0 = (int)round($fcx - $W / 2);
    $y0 = (int)round($fcy - $H * 0.42);          // face a touch above centre (rule of thirds)
    $x0 = max(0, min($sw - $W, $x0));
    $y0 = max(0, min($sh - $H, $y0));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, $x0, $y0, $tw, $th, $W, $H);
    return $dst;
}

/**
 * THE smart crop: face-aware when there's a person, saliency otherwise. Bytes in,
 * WebP out. This is the human-editor behaviour — frame the face, or frame the subject.
 */
function img_smartcrop(string $bytes, string $path, int $tw = 1200, int $th = 630, int $q = 82): bool {
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    $face = img_detect_face($src);
    $dst = $face ? img_facecrop_gd($src, $tw, $th, $face) : img_smartcrop_gd($src, $tw, $th);
    imagedestroy($src);
    if (!$dst) return false;
    $ok = imagewebp($dst, $path, $q);
    imagedestroy($dst);
    return $ok && is_file($path) && filesize($path) > 0;
}

/**
 * Technical quality 0-100: the NIMA-style "is this a good photo" — sharpness
 * (Laplacian variance), exposure (not too dark/blown), colorfulness, and entropy
 * (not a flat boring frame). Replaces the abandoned img_interest().
 */
function img_quality(string $bytes): float {
    $src = @imagecreatefromstring($bytes);
    if (!$src) return 0.0;
    $aw = 120; $sw = imagesx($src); $sh = imagesy($src);
    $ah = max(2, (int)round($sh * $aw / $sw));
    $s = imagecreatetruecolor($aw, $ah);
    imagecopyresampled($s, $src, 0, 0, 0, 0, $aw, $ah, $sw, $sh);
    imagedestroy($src);

    $lum = []; $hist = array_fill(0, 256, 0); $rg = []; $yb = [];
    for ($y = 0; $y < $ah; $y++) for ($x = 0; $x < $aw; $x++) {
        $c = imagecolorat($s, $x, $y); $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
        $l = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
        $lum[$y][$x] = $l; $hist[$l]++;
        $rg[] = $r - $g; $yb[] = 0.5 * ($r + $g) - $b;
    }
    // sharpness: variance of the Laplacian
    $lap = [];
    for ($y = 1; $y < $ah - 1; $y++) for ($x = 1; $x < $aw - 1; $x++)
        $lap[] = $lum[$y][$x] * 4 - $lum[$y - 1][$x] - $lum[$y + 1][$x] - $lum[$y][$x - 1] - $lum[$y][$x + 1];
    $sharp = $lap ? stats_var($lap) : 0;
    $sharpScore = min(100, $sharp / 8);                       // ~800 var = very sharp

    // exposure: penalize mean luma far from mid
    $mean = array_sum(array_map('array_sum', $lum)) / ($aw * $ah);
    $expoScore = 100 - min(100, abs($mean - 122) * 0.8);

    // colorfulness (Hasler-Susstrunk)
    $colorful = sqrt(stats_var($rg) + stats_var($yb)) + 0.3 * sqrt((array_sum($rg) / count($rg)) ** 2 + (array_sum($yb) / count($yb)) ** 2);
    $colorScore = min(100, $colorful * 1.6);

    // entropy: flat/boring frames score low
    $tot = $aw * $ah; $ent = 0.0;
    foreach ($hist as $h) { if ($h) { $p = $h / $tot; $ent -= $p * log($p, 2); } }
    $entScore = min(100, $ent / 7.5 * 100);                  // 7.5 bits ~ rich

    imagedestroy($s);
    return round(0.40 * $sharpScore + 0.20 * $expoScore + 0.20 * $colorScore + 0.20 * $entScore, 1);
}

function stats_var(array $a): float {
    $n = count($a); if ($n < 2) return 0.0;
    $m = array_sum($a) / $n; $v = 0.0;
    foreach ($a as $x) $v += ($x - $m) ** 2;
    return $v / $n;
}

/**
 * VISION ART DIRECTOR — the actual human-like judgment. Hands the candidate images
 * to the vision model and asks it to pick the most STRIKING + on-brand + safe one
 * (not merely relevant), and to say where the subject sits so we crop well. The
 * img_quality() heuristic is a cheap pre-filter; this is the taste. Returns
 * ['index','focal','reason','scores'] or null (none good enough).
 */
function img_art_director(array $cands, string $term, string $meaning = ''): ?array {
    require_once __DIR__ . '/vision.php';
    $intro = "You are the photo editor for a Gen Z culture & meme dictionary. Choose the SINGLE BEST image to "
        . "feature for the entry \"$term\"" . ($meaning ? " (meaning: " . mb_substr($meaning, 0, 120) . ")" : "") . ". "
        . "Judge each on VISUAL IMPACT (striking, sharp, well-composed, NOT dull/blurry/generic stock), RELEVANCE to the term, "
        . "and BRAND-SAFETY (reject nsfw, gore, hate, heavy politics, watermarks, logos). Note where the main subject sits. "
        . "Rate EVERY image 0-10. Output STRICT JSON only: "
        . "{\"scores\":[..],\"best\":<0-based index>,\"focal\":\"left|center|right|top|bottom\",\"reason\":\"short\"}. "
        . "If none scores 6+, set best to -1.";
    $content = [['type' => 'text', 'text' => $intro]];
    $sent = 0;
    foreach ($cands as $c) {
        $b64 = vision_b64($c['thumb'] ?? $c['url']);
        if (!$b64) { $content[] = ['type' => 'text', 'text' => "(image $sent failed to load: score it 0)"]; $sent++; continue; }
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $b64]];
        $sent++;
    }
    if ($sent === 0) return null;
    // primary: Gemini judges all images in one call
    $res = ai_chat([['role' => 'user', 'content' => $content]], ['gemini'], 0.1);
    if (!isset($res['error'])) {
        $j = ai_json($res['content']);
        if ($j && isset($j['best'])) {
            $best = (int)$j['best'];
            if ($best >= 0 && $best < count($cands))
                return ['index' => $best, 'focal' => $j['focal'] ?? 'center', 'reason' => mb_substr($j['reason'] ?? '', 0, 200), 'scores' => $j['scores'] ?? []];
            return null;   // genuine "none fits" verdict
        }
    }
    // FALLBACK (Gemini vision rate-limited): FAST heuristic, no API. Pick the best
    // technical quality among candidates. Less smart than the AI on vibe, but it
    // never hangs and reliably avoids the blurry/blown-out ones.
    require_once __DIR__ . '/fetch_sources.php';
    $bestIdx = -1; $bestQ = -1.0;
    foreach (array_slice($cands, 0, 6) as $i => $cc) {
        $bytes = fs_http_get($cc['thumb'] ?? $cc['url'], 15);
        if (!$bytes) continue;
        $q = img_quality($bytes);
        if ($q > $bestQ) { $bestQ = $q; $bestIdx = $i; }
    }
    if ($bestIdx >= 0) return ['index' => $bestIdx, 'focal' => 'center', 'reason' => "quality heuristic (vision rate-limited)", 'scores' => []];
    return null;
}

/** Crop biased to a named focal region from the art director (for non-face subjects). */
function img_focalcrop_gd($src, int $tw, int $th, string $focal) {
    $sw = imagesx($src); $sh = imagesy($src);
    $aspect = $tw / $th;
    $W = $sw; $H = (int)round($sw / $aspect);
    if ($H > $sh) { $H = $sh; $W = (int)round($sh * $aspect); }
    $W = max(2, min($sw, $W)); $H = max(2, min($sh, $H));
    $bx = [ 'left' => 0, 'center' => ($sw - $W) / 2, 'right' => $sw - $W ];
    $by = [ 'top' => 0, 'center' => ($sh - $H) / 2, 'bottom' => $sh - $H ];
    $x0 = (int)round($bx[$focal] ?? ($sw - $W) / 2);
    $y0 = (int)round($by[$focal] ?? ($sh - $H) / 2);
    $x0 = max(0, min($sw - $W, $x0)); $y0 = max(0, min($sh - $H, $y0));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, $x0, $y0, $tw, $th, $W, $H);
    return $dst;
}
