<?php
// GenZHype | VS CARD composer. The tabloid feud visual: two real (free-licensed)
// faces in a split frame, red diagonal slash, names, VS badge. Built with GD from
// CC/PD photos, so the composite is legal (licenses allow derivatives; credited).

require_once __DIR__ . '/fetch_sources.php';   // fs_http_get

/** Load an image from a URL into a GD resource. */
function vsc_load(string $url) {
    $bytes = fs_http_get($url, 25);
    if (!$bytes) return null;
    $im = @imagecreatefromstring($bytes);
    return $im ?: null;
}

/** Cover-crop a GD image into a $w x $h box, biased toward the upper third (faces). */
function vsc_cover($src, int $w, int $h, string $biasX = 'center') {
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = max($w / $sw, $h / $sh);
    $nw = (int)round($sw * $scale); $nh = (int)round($sh * $scale);
    $ox = $biasX === 'left' ? 0 : ($biasX === 'right' ? $w - $nw : (int)(($w - $nw) / 2));
    // faces live in the upper part of portraits: bias crop upward
    $oy = (int)min(0, max($h - $nh, ($h - $nh) * 0.25));
    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $src, $ox, $oy, 0, 0, $nw, $nh, $sw, $sh);
    return $dst;
}

/** Best available display font file for GD text. */
function vsc_font(): ?string {
    foreach ([getenv('HOME') . '/.fonts/Fraunces-Italic.ttf', getenv('HOME') . '/.fonts/Fraunces.ttf',
              '/usr/share/fonts/google-droid-sans-fonts/DroidSansFallbackFull.ttf'] as $f) {
        if (is_file($f)) return $f;
    }
    return null;
}

/**
 * Compose the card. $a/$b = ['url'=>image url, 'name'=>display name].
 * Writes 1200x630 webp to covers/{slug}-vs.webp. Returns web path or null.
 */
function make_vs_card(array $a, array $b, string $slug): ?string {
    $A = vsc_load($a['url']); $B = vsc_load($b['url']);
    if (!$A || !$B) { if ($A) imagedestroy($A); if ($B) imagedestroy($B); return null; }
    $W = 1200; $H = 630;
    $card = imagecreatetruecolor($W, $H);

    $ink   = imagecolorallocate($card, 0x1A, 0x18, 0x14);
    $paper = imagecolorallocate($card, 0xFB, 0xFA, 0xF8);
    $red   = imagecolorallocate($card, 0xC7, 0x1F, 0x12);

    // two halves
    $half = vsc_cover($A, (int)($W / 2), $H, $a['bias'] ?? 'center');
    imagecopy($card, $half, 0, 0, 0, 0, (int)($W / 2), $H);
    imagedestroy($half);
    $half = vsc_cover($B, (int)($W / 2), $H, $b['bias'] ?? 'center');
    imagecopy($card, $half, (int)($W / 2), 0, 0, 0, (int)($W / 2), $H);
    imagedestroy($half);

    // red diagonal slash through the center
    $cx = $W / 2; $lean = 70; $thick = 14;
    imagefilledpolygon($card, [
        $cx + $lean - $thick, 0,  $cx + $lean + $thick, 0,
        $cx - $lean + $thick, $H, $cx - $lean - $thick, $H,
    ], $red);

    // top press rule + bottom name band
    imagefilledrectangle($card, 0, 0, $W, 10, $red);
    imagefilledrectangle($card, 0, $H - 78, $W, $H, $ink);
    imagefilledrectangle($card, 0, $H - 78, $W, $H - 74, $red);

    $font = vsc_font();
    if ($font) {
        // names left/right in the band
        $size = 30;
        $nameA = strtoupper($a['name']); $nameB = strtoupper($b['name']);
        imagettftext($card, $size, 0, 40, $H - 26, $paper, $font, $nameA);
        $bbox = imagettfbbox($size, 0, $font, $nameB);
        imagettftext($card, $size, 0, $W - 40 - ($bbox[2] - $bbox[0]), $H - 26, $paper, $font, $nameB);

        // VS badge: ink circle, red ring, white VS
        $bx = (int)$cx; $by = (int)($H / 2) - 40;
        imagefilledellipse($card, $bx, $by, 124, 124, $red);
        imagefilledellipse($card, $bx, $by, 108, 108, $ink);
        $vs = 'VS';
        $vb = imagettfbbox(34, 0, $font, $vs);
        imagettftext($card, 34, 0, $bx - (int)(($vb[2] - $vb[0]) / 2), $by + 16, $paper, $font, $vs);

        // tiny wordmark, band right.. keep band clean: wordmark top-left on photo
        imagettftext($card, 15, 0, 24, 38, $paper, $font, 'GENZHYPE');
    }

    $dir = dirname(__DIR__) . '/public_html/assets/covers';
    $path = "{$dir}/{$slug}-vs.webp";
    $ok = imagewebp($card, $path, 86);
    imagedestroy($card);
    return $ok ? "/assets/covers/{$slug}-vs.webp" : null;
}
