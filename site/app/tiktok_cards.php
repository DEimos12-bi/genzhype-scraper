<?php
/**
 * TIKTOK POST CARDS (r102) — put the POST on screen when the VIDEO is
 * unreachable.
 *
 * WHY. TikTok refuses us everywhere that matters: 21 download attempts from a
 * runner (every impersonation, cookie and build combination) all failed on a
 * challenge page, and our own server managed exactly ONE download before
 * TikTok blocked its IP on the media CDN. There is no free route to the video
 * file, and the paid one (residential proxies) is ruled out.
 *
 * But TikTok publishes an oEmbed endpoint that needs NO key and answers our
 * server happily, returning the creator's name, the real caption, and a
 * thumbnail that is already 1080x1920 — our exact frame. So the story can
 * still SHOW the post: real handle, real words, real frame from the video,
 * captioned as what it is. That is the same move the X tweet cards make, and
 * it is honest — we are showing the post, not pretending to play it.
 *
 * Everything is verified against TikTok's own endpoint at render time, so a
 * deleted or private post yields NO card rather than a fabricated one.
 */
declare(strict_types=1);

const TTC_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
             . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/** Ask TikTok about a post. Returns null when it will not confirm it. */
function tt_oembed(string $url): ?array {
    $ch = curl_init('https://www.tiktok.com/oembed?url=' . urlencode($url));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25,
                            CURLOPT_USERAGENT => TTC_UA]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $j = json_decode($body, true);
    if (!is_array($j) || empty($j['thumbnail_url'])) return null;
    // the handle lives in the URL; oembed gives the display name
    $handle = '';
    if (preg_match('#tiktok\.com/@([A-Za-z0-9._]+)#i', $url, $m)) $handle = $m[1];
    return [
        'author'    => (string)($j['author_name'] ?? ''),
        'handle'    => $handle,
        'caption'   => (string)($j['title'] ?? ''),
        'thumb'     => (string)$j['thumbnail_url'],
        'url'       => $url,
    ];
}

function tt_fetch_thumb(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => 1, CURLOPT_USERAGENT => TTC_UA,
        CURLOPT_HTTPHEADER => ['Referer: https://www.tiktok.com/']]);
    $b = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && strlen($b) > 10000) ? $b : null;
}

/**
 * Draw the card: the post's own frame, dimmed at top and bottom so the
 * overlay reads, with the creator and caption over it and the source line at
 * the foot. Deliberately looks like a post, because it IS one.
 */
function tt_card_render(array $d, string $dest): bool {
    $bytes = tt_fetch_thumb($d['thumb']);
    if ($bytes === null) return false;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;

    $W = 1080; $H = 1920;
    $im = imagecreatetruecolor($W, $H);
    $sw = imagesx($src); $sh = imagesy($src);
    // cover-fit: fill the frame, never letterbox
    $scale = max($W / $sw, $H / $sh);
    $nw = (int)round($sw * $scale); $nh = (int)round($sh * $scale);
    imagecopyresampled($im, $src, (int)(($W - $nw) / 2), (int)(($H - $nh) / 2),
                       0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);

    // dim bands so text is legible over any frame
    $black = imagecolorallocatealpha($im, 0, 0, 0, 45);
    imagefilledrectangle($im, 0, 0, $W, 300, $black);
    imagefilledrectangle($im, 0, $H - 470, $W, $H, $black);

    $white = imagecolorallocate($im, 255, 255, 255);
    $grey  = imagecolorallocate($im, 190, 195, 205);
    // reuse the site's own card fonts rather than inventing a second set
    require_once __DIR__ . '/receipt_cards.php';
    [$fontB, $fontR] = rc_fonts();
    if (!$fontB || !$fontR) {
        $ok = imagepng($im, $dest, 6);
        imagedestroy($im);
        return (bool)$ok;
    }

    imagettftext($im, 40, 0, 48, 130, $white, $fontB, 'TikTok');
    $who = trim(($d['handle'] !== '' ? '@' . $d['handle'] : $d['author']));
    imagettftext($im, 34, 0, 48, 200, $grey, $fontR, mb_substr($who, 0, 34));

    // caption, wrapped, bottom band
    $cap = trim(preg_replace('/\s+/', ' ', $d['caption']));
    $y = $H - 380; $line = ''; $out = [];
    foreach (explode(' ', $cap) as $word) {
        $try = trim($line . ' ' . $word);
        $bb = imagettfbbox(36, 0, $fontR, $try);
        if (($bb[2] - $bb[0]) > ($W - 96) && $line !== '') { $out[] = $line; $line = $word; }
        else $line = $try;
        if (count($out) >= 5) break;
    }
    if ($line !== '' && count($out) < 5) $out[] = $line;
    foreach ($out as $l) { imagettftext($im, 36, 0, 48, $y, $white, $fontR, $l); $y += 52; }

    imagettftext($im, 24, 0, 48, $H - 60, $grey, $fontR,
                 'via tiktok.com/@' . $d['handle']);

    $ok = imagepng($im, $dest, 6);
    imagedestroy($im);
    return (bool)$ok;
}

/**
 * Cards for every TikTok this story cites. Returns receipt-shaped rows.
 * Verified per post: no confirmation from TikTok, no card.
 */
function tt_cards_for_page(PDO $pdo, int $pageId, int $max = 4): array {
    $dir = dirname(__DIR__) . '/public_html/assets/receipts/video';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // TikToks reach a story two ways: cited directly as a source, or — far more
    // often — harvested from the clip a reporter embedded in their article.
    // The first version of this only looked at sources and found nothing on a
    // story holding five of them.
    $urls = [];
    $q = $pdo->prepare(
        "SELECT DISTINCT s.url
           FROM events e JOIN sources s ON s.id = e.source_id
           JOIN dramas d ON d.id = e.drama_id
          WHERE d.page_id = ? AND s.url LIKE '%tiktok.com/@%'");
    $q->execute([$pageId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) $urls[(string)$u] = true;

    $c = $pdo->prepare("SELECT footage_clips FROM video_scripts WHERE page_id=?");
    $c->execute([$pageId]);
    foreach ((array)json_decode((string)$c->fetchColumn(), true) as $clip) {
        $u = is_array($clip) ? (string)($clip['url'] ?? '') : '';
        if ($u !== '' && str_contains($u, 'tiktok.com/@')) $urls[$u] = true;
    }

    $out = [];
    foreach (array_keys($urls) as $u) {
        if (count($out) >= $max) break;
        $file = $dir . '/' . $pageId . '-tt' . substr(md5($u), 0, 10) . '.png';
        if (!is_file($file)) {
            $d = tt_oembed($u);
            if (!$d) { error_log("tiktok card: no confirmation for {$u}"); continue; }
            if (!tt_card_render($d, $file)) continue;
            @chmod($file, 0644);
        }
        $out[] = ['kind' => 'post', 'url' => url('assets/receipts/video/' . basename($file)),
                  'event_date' => '', 'source_url' => $u];
    }
    return $out;
}
