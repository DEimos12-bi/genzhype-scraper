<?php
// GenZHype | TIKTOK PHOTO CAROUSEL byproduct (format pivot 2026-08-06).
// The format study's surprise finding: TikTok photo posts get +81%
// engagement / 2.9x comments vs video (Fanpage Karma + TikTok's own data),
// and a swipeable timeline carousel needs ZERO video intelligence. This
// renders the SAME timeline beats as static cards: hook card -> one dated
// receipt card per beat -> CTA card. 1080x1350 (4:5 — works on TikTok photo
// mode and IG). Server-asset-only sources: cover/person photos, real post
// card PNGs, article og:image report photos, the promo card. Output:
// public_html/media/carousel/<page_id>/card-N.jpg + manifest.json.
// Posting stays MANUAL (owner's social strategy).
//   Usage: php app/carousel.php <page_id>

if (PHP_SAPI !== 'cli' && !defined('GZ_CAROUSEL_LIB')) { http_response_code(403); exit('cli only'); }

/** Vision gate for og-photo cards (2026-08-06, owner: the Jul-27 card showed
 *  a random celebrity because the source article's thumbnail was of HER, not
 *  the beat's subject). One rotation-tier call: does the photo depict the
 *  sentence? Anything but a clear yes -> the card is skipped. FAIL-CLOSED. */
function carousel_photo_fits(string $imgUrl, string $sentence): bool {
    $key = $GLOBALS['CONFIG']['ai']['gemini_key'] ?? '';
    if ($key === '') return false;
    $bytes = @file_get_contents($imgUrl, false, stream_context_create(
        ['http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0\r\n"]]));
    if (strlen((string)$bytes) < 2000) return false;
    $body = json_encode(['contents' => [['parts' => [
        ['text' => 'Fact-check for a news timeline card. The card\'s text: "'
                 . mb_substr($sentence, 0, 220) . '". Does this photo plausibly '
                 . 'DEPICT that fact (the person or moment it describes)? A photo '
                 . 'of a DIFFERENT person, or generic stock, is a NO. Respond ONLY '
                 . 'JSON: {"fits": true|false}'],
        ['inline_data' => ['mime_type' => 'image/jpeg',
                           'data' => base64_encode($bytes)]]]]]]);
    foreach (['gemma-4-31b-it', 'gemini-3.5-flash-lite'] as $model) {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) continue;
        $txt = '';
        foreach ((json_decode((string)$res, true)['candidates'][0]['content']['parts'] ?? []) as $p) {
            $txt .= (string)($p['text'] ?? '');
        }
        if (preg_match('/\{[^{}]*"fits"[^{}]*\}/', $txt, $m)
                && is_array($j = json_decode($m[0], true))) {
            return (bool)($j['fits'] ?? false);
        }
    }
    return false;   // no verdict = no card
}

function carousel_for_page(PDO $pdo, int $pageId): array {
    $W = 1080; $H = 1350;
    $row = $pdo->prepare("SELECT v.title, v.image, v.shotlist, v.script, v.tpl
                          FROM video_scripts v WHERE v.page_id=?");
    $row->execute([$pageId]);
    $v = $row->fetch();
    if (!$v || (int)$v['tpl'] !== 3) return [];
    $sl = json_decode((string)$v['shotlist'], true);
    $shots = (array)($sl['shots'] ?? []);
    if (!$shots) return [];
    $words = preg_split('/\s+/', trim((string)$v['script']), -1, PREG_SPLIT_NO_EMPTY);

    require_once __DIR__ . '/receipt_cards.php';
    $receipts = receipt_cards_for_page($pdo, $pageId);   // urls for post/promo
    $rDir = dirname(__DIR__) . '/public_html/assets/receipts/video';

    $dir = dirname(__DIR__) . '/public_html/media/carousel/' . $pageId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // the site's own brand fonts (social_card kit) — house consistency
    $fontBold = __DIR__ . '/social_card/fonts/display.ttf';
    if (!is_file($fontBold)) $fontBold = __DIR__ . '/social_card/fonts/body.ttf';
    if (!is_file($fontBold)) {
        $fontBold = dirname(__DIR__)
            . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
    }
    $accent = '#E8483F';

    $canvas = function () use ($W, $H): Imagick {
        $im = new Imagick();
        $im->newImage($W, $H, '#101013', 'jpeg');
        return $im;
    };
    $text = function (Imagick $im, string $s, int $size, int $y, string $color,
                      int $maxW) use ($W, $fontBold): int {
        $d = new ImagickDraw();
        $d->setFont($fontBold); $d->setFontSize($size);
        $d->setFillColor($color); $d->setTextAlignment(Imagick::ALIGN_CENTER);
        // greedy wrap
        $lines = []; $cur = '';
        foreach (preg_split('/\s+/', $s) as $w) {
            $try = trim($cur . ' ' . $w);
            $m = $im->queryFontMetrics($d, $try);
            if ($m['textWidth'] > $maxW && $cur !== '') { $lines[] = $cur; $cur = $w; }
            else $cur = $try;
        }
        if ($cur !== '') $lines[] = $cur;
        foreach ($lines as $ln) {
            $im->annotateImage($d, $W / 2, $y, 0, $ln);
            $y += (int)($size * 1.25);
        }
        return $y;
    };
    $chip = function (Imagick $im, string $label, int $x, int $y) use ($fontBold, $accent): void {
        $d = new ImagickDraw();
        $d->setFont($fontBold); $d->setFontSize(40); $d->setFillColor('#FFFFFF');
        $m = $im->queryFontMetrics($d, $label);
        $w = (int)$m['textWidth'] + 52; $h = 74;
        $bg = new ImagickDraw();
        $bg->setFillColor('#1A1A1E');
        $bg->roundRectangle($x, $y, $x + $w, $y + $h, 14, 14);
        $bg->setFillColor($accent);
        $bg->rectangle($x, $y, $x + 10, $y + $h);
        $im->drawImage($bg);
        $d->setTextAlignment(Imagick::ALIGN_LEFT);
        $im->annotateImage($d, $x + 32, $y + 52, 0, $label);
    };
    $paste = function (Imagick $im, string $src, int $topY, int $maxH) use ($W): bool {
        try {
            $ph = new Imagick();
            if (preg_match('#^https?://#', $src)) {
                $bytes = @file_get_contents($src, false,
                    stream_context_create(['http' => ['timeout' => 20,
                        'header' => "User-Agent: Mozilla/5.0\r\n"]]));
                if (strlen((string)$bytes) < 2000) return false;
                $ph->readImageBlob($bytes);
            } elseif (is_file($src)) {
                $ph->readImage($src);
            } else return false;
            $ph->setImageFormat('jpeg');
            // blur-fill band behind the artifact — small/odd-ratio sources
            // no longer float tiny on flat black (the low-quality look);
            // the band reads designed, the artifact stays its true size.
            $bg = clone $ph;
            $bg->resizeImage($W, $maxH, Imagick::FILTER_LANCZOS, 1, false);
            $bg->blurImage(0, 30);
            $bg->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.4,
                               Imagick::CHANNEL_ALL);
            $im->compositeImage($bg, Imagick::COMPOSITE_OVER, 0, $topY);
            $ph->thumbnailImage($W - 120, $maxH - 40, true);
            $im->compositeImage($ph, Imagick::COMPOSITE_OVER,
                (int)(($W - $ph->getImageWidth()) / 2),
                $topY + (int)max(0, ($maxH - $ph->getImageHeight()) / 2));
            return true;
        } catch (Throwable $e) { return false; }
    };

    // Per-beat artifact JOIN (2026-08-06 fix — the first version consumed
    // article photos IN ORDER, which pasted the WRONG story photo onto
    // beats, the exact disease the timeline contract exists to kill):
    //   1. real frames of the beat's OWN clip (clipframe-<pid>-<event_id>-*,
    //      shipped by the maker) — a video beat shows THAT video;
    //   2. the beat's own post card (receipt_i);
    //   3. the beat's own article og photo (joined by ITS src_url);
    //   4. NOTHING -> the card is SKIPPED. Fewer true cards beat wrong ones.
    require_once __DIR__ . '/event_sources.php';
    $ogBySrc = [];
    try {
        foreach (event_sources_for_page($pdo, $pageId) as $es) {
            if (!empty($es['og_image']) && !empty($es['source_url'])) {
                $ogBySrc[(string)$es['source_url']] = (string)$es['og_image'];
            }
        }
    } catch (Throwable $e) {}
    $postCards = [];
    foreach ($receipts as $rc) {
        if (($rc['kind'] ?? '') === 'post') {
            $postCards[(int)$rc['idx']] = $rDir . '/' . basename(parse_url((string)$rc['url'], PHP_URL_PATH));
        }
    }

    $made = []; $n = 0; $ogK = 0;
    // ---- card 0: HOOK ----
    // The site cover is DESIGNED thematic art (the cover engine picked a
    // robot-toy stock photo for an "AI actress" story) — on a receipts
    // carousel every image implies evidence, so the hook image faces the
    // same vision gate as the beats: cover first, then the beats' article
    // photos; nothing passes -> clean typography-only hook (honest > wrong).
    $im = $canvas();
    $text($im, 'THE FULL TIMELINE', 64, 150, $accent, $W - 160);
    $text($im, mb_strtoupper((string)$v['title']), 56, 240, '#FFFFFF', $W - 160);
    $hookImg = '';
    // real clip frames outrank everything (no gate needed — they ARE the
    // story's own footage, shipped by the renderer per beat)
    $hookFrames = glob($rDir . "/clipframe-{$pageId}-*-0.jpg") ?: [];
    if ($hookFrames) {
        $hookImg = $hookFrames[0];
    } else {
        foreach (array_merge([(string)$v['image']], array_values($ogBySrc)) as $cand) {
            if ($cand !== '' && carousel_photo_fits($cand, (string)$v['title'])) {
                $hookImg = $cand;
                break;
            }
        }
    }
    if ($hookImg !== '') {
        $paste($im, $hookImg, 430, 640);
    } else {
        $text($im, '—', 90, 700, $accent, $W - 200);   // typographic divider
        error_log("carousel: page $pageId hook is typography-only (no image passed the gate)");
    }
    $text($im, 'WITH RECEIPTS  —  SWIPE', 44, 1200, '#9A9AA2', $W - 200);
    $im->setImageCompressionQuality(88);
    $im->writeImage($dir . "/card-0.jpg"); $made[] = "card-0.jpg"; $n++;

    // ---- one card per dated beat (skipped when it owns no artifact) ----
    $skipped = 0;
    foreach ($shots as $s) {
        if (empty($s['date'])) continue;
        if ($n >= 9) break;                    // TikTok carousel sweet spot
        $sentence = implode(' ', array_slice($words, (int)$s['w_in'],
                             (int)$s['w_out'] - (int)$s['w_in'] + 1));
        $eid = (int)($s['event_id'] ?? 0);
        $srcUrl = (string)($s['src_url'] ?? '');
        $frames = $eid ? (glob($rDir . "/clipframe-{$pageId}-{$eid}-*.jpg") ?: []) : [];
        $ri = $s['receipt_i'] ?? null;

        $im = $canvas();
        $chip($im, mb_strtoupper((string)$s['date']), 60, 60);
        $ok = false;
        if ($frames) {
            // the beat's OWN video: 2-frame strip of the actual footage
            $half = (int)(($W - 140) / 2);
            $x = 60;
            foreach (array_slice($frames, 0, 2) as $ff) {
                try {
                    $ph = new Imagick($ff);
                    $ph->thumbnailImage($half, 820, true);
                    $im->compositeImage($ph, Imagick::COMPOSITE_OVER, $x, 200);
                    $x += $half + 20;
                    $ok = true;
                } catch (Throwable $e) {}
            }
        } elseif ($ri !== null && isset($postCards[(int)$ri])
                  && is_file($postCards[(int)$ri])) {
            $ok = $paste($im, $postCards[(int)$ri], 200, 780);
        } elseif ($srcUrl !== '' && isset($ogBySrc[$srcUrl])
                  && carousel_photo_fits($ogBySrc[$srcUrl], $sentence)) {
            $ok = $paste($im, $ogBySrc[$srcUrl], 200, 780);
        }
        if (!$ok) { $skipped++; continue; }    // no artifact -> NO card
        $text($im, $sentence, 44, 1080, '#FFFFFF', $W - 160);
        $im->setImageCompressionQuality(88);
        $im->writeImage($dir . "/card-{$n}.jpg"); $made[] = "card-{$n}.jpg"; $n++;
    }
    if ($skipped) error_log("carousel: page $pageId skipped $skipped beat(s) with no owned artifact");

    // ---- last card: CTA ----
    $im = $canvas();
    $text($im, 'GenZHype', 84, 560, '#FFFFFF', $W - 200);
    $text($im, 'EVERY SOURCE. EVERY DATE.', 48, 700, $accent, $W - 200);
    $text($im, 'genzhype.com', 52, 820, '#9A9AA2', $W - 200);
    $im->setImageCompressionQuality(88);
    $im->writeImage($dir . "/card-{$n}.jpg"); $made[] = "card-{$n}.jpg"; $n++;

    foreach ($made as $f) @chmod($dir . '/' . $f, 0644);
    // prune stale cards from earlier (larger) generations
    foreach (glob($dir . '/card-*.jpg') ?: [] as $old) {
        if (!in_array(basename($old), $made, true)) @unlink($old);
    }
    @file_put_contents($dir . '/manifest.json', json_encode(
        ['page_id' => $pageId, 'title' => $v['title'], 'cards' => $made,
         'at' => date('c')], JSON_UNESCAPED_SLASHES));
    return $made;
}

if (PHP_SAPI === 'cli' && basename((string)($argv[0] ?? '')) === 'carousel.php') {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
    require __DIR__ . '/helpers.php';
    require __DIR__ . '/db.php';
    $pid = (int)($argv[1] ?? 0);
    if ($pid <= 0) { fwrite(STDERR, "usage: carousel.php <page_id>\n"); exit(2); }
    $cards = carousel_for_page(db(), $pid);
    printf("carousel: %d card(s) -> media/carousel/%d/\n", count($cards), $pid);
}
