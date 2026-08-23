<?php
/**
 * GenZHype | SOCIAL STUDIO. Turns each fresh page (drama / slang term) into a batch of
 * ready-to-post social content — one tailored version PER PLATFORM (the Mixpost "post
 * versions" pattern) + a share image (reusing our own proven cover engine) + the link.
 * Posting stays MANUAL (the only safe way for reach/backlinks); this is the daily factory
 * + queue. The admin "Social" tab works the queue (copy, mark posted, streak).
 *
 * No new infra: uses our AI (ai_chat), our cover/card images, our DB.
 */
require_once __DIR__ . '/ai.php';

/** Per-platform rules (the proven conventions Mixpost/Postiz encode), used to shape each post. */
function social_platforms(): array {
    return [
        'x'         => ['label' => 'X / Twitter', 'limit' => 270, 'img' => 'wide',   'hint' => 'punchy hook, 1 line, 2-3 hashtags, the link'],
        'threads'   => ['label' => 'Threads',     'limit' => 480, 'img' => 'square', 'hint' => 'conversational, a hot take, light hashtags'],
        'instagram' => ['label' => 'Instagram',   'limit' => 2000,'img' => 'square', 'hint' => 'engaging caption + a question + 8-12 niche hashtags'],
        'tiktok'    => ['label' => 'TikTok',       'limit' => 2000,'img' => 'square', 'hint' => 'a video HOOK line + caption + trend hashtags (for a slideshow/talking post)'],
        'pinterest' => ['label' => 'Pinterest',    'limit' => 480, 'img' => 'tall',   'hint' => 'descriptive, keyword-rich, evergreen, the link'],
        'reddit'    => ['label' => 'Reddit',       'limit' => 300, 'img' => 'wide',   'hint' => 'a NEUTRAL, genuine post TITLE only (no hashtags, no hype) — body links the timeline; sound human, never salesy'],
    ];
}

function social_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS social_posts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        page_id INT NOT NULL,
        source_type VARCHAR(12) NOT NULL,
        platform VARCHAR(16) NOT NULL,
        content MEDIUMTEXT NULL,
        image_path VARCHAR(255) NULL,
        link VARCHAR(400) NULL,
        status ENUM('queued','posted','skipped') NOT NULL DEFAULT 'queued',
        created_at DATETIME NOT NULL,
        posted_at DATETIME NULL,
        UNIQUE KEY uniq_pp (page_id, platform), KEY st (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Render a DESIGNED branded share card (Satori, node) at the platform shape. Returns rel path
 *  or null (caller falls back to the cover). PHP -> node via proc_open (shell_exec is disabled). */
function social_card_render(string $headline, string $shape, string $accent, string $slug, ?string $photoAbs = null): ?string {
    $node = '/opt/alt/alt-nodejs22/root/usr/bin/node';
    $script = __DIR__ . '/social_card/social_card.mjs';
    if (!is_file($script) || !is_file($node) || !function_exists('proc_open')) return null;
    $rel = '/assets/covers/' . $slug . "-social-{$shape}.png";
    $abs = dirname(__DIR__) . '/public_html' . $rel;
    // when a real photo exists, crop it to the exact shape as a temp PNG -> the card's full-bleed background
    $photoTmp = null;
    if ($photoAbs && is_file($photoAbs) && class_exists('Imagick')) {
        [$W, $H] = ['wide' => [1200, 630], 'square' => [1080, 1080], 'tall' => [1000, 1500]][$shape] ?? [1080, 1080];
        try {
            $pi = new Imagick($photoAbs);
            if (method_exists($pi, 'autoOrient')) $pi->autoOrient();
            $pi->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $pi->cropThumbnailImage($W, $H);                 // cover-crop, centered
            $pi->setImageFormat('png');
            $photoTmp = sys_get_temp_dir() . '/gzsc-' . md5($slug . $shape) . '.png';
            $pi->writeImage($photoTmp); $pi->clear();
        } catch (\Throwable $e) { $photoTmp = null; }
    }
    $payload = json_encode(['headline' => $headline, 'kicker' => 'GenZHype · Drama Desk', 'accent' => $accent, 'photo' => $photoTmp]);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([$node, $script, $shape, $abs], $desc, $pipes, dirname($script));
    if (!is_resource($proc)) { if ($photoTmp) @unlink($photoTmp); return null; }
    fwrite($pipes[0], $payload); fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($photoTmp) @unlink($photoTmp);
    return ($code === 0 && is_file($abs) && filesize($abs) > 2000) ? $rel : null;
}

/** True when a page image is a REAL photo (drama -hero / term -featured), not a branded text card. */
function social_is_real_photo(?string $rel): bool {
    return $rel ? (bool)preg_match('#-(hero|featured)\.(webp|jpe?g|png)$#i', $rel) : false;
}

/** Make a square (1080) and/or tall (1000x1500) version of a page's cover, reusing GD. Returns rel path. */
function social_image_variant(string $coverRel, string $shape): ?string {
    $abs = dirname(__DIR__) . '/public_html' . $coverRel;
    if (!is_file($abs)) return $coverRel ?: null;      // fall back to the original cover
    if ($shape === 'wide') return $coverRel;            // 1200x630 cover is already wide
    [$tw, $th] = $shape === 'tall' ? [1000, 1500] : [1080, 1080];
    $src = @imagecreatefromstring(@file_get_contents($abs));
    if (!$src) return $coverRel;
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = max($tw / $sw, $th / $sh);
    $nw = (int)ceil($sw * $scale); $nh = (int)ceil($sh * $scale);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, (int)(($tw - $nw) / 2), (int)(($th - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);
    $rel = preg_replace('/\.(webp|png|jpg|jpeg)$/i', '', $coverRel) . "-{$shape}.webp";
    $out = dirname(__DIR__) . '/public_html' . $rel;
    $ok = imagewebp($dst, $out, 86); imagedestroy($dst);
    return $ok ? $rel : $coverRel;
}

/** Generate the full per-platform post set for one published page. Returns count stored. */
function social_generate(PDO $pdo, int $pageId): array {
    social_init($pdo);
    $p = $pdo->prepare("SELECT p.type, p.h1, p.summary, p.path, p.cover, p.featured_img, d.mood
                        FROM pages p LEFT JOIN dramas d ON d.page_id=p.id WHERE p.id=? AND p.status='published'");
    $p->execute([$pageId]);
    $pg = $p->fetch();
    if (!$pg) return ['error' => 'page not found / not published'];
    $base = rtrim($GLOBALS['CONFIG']['base_url'] ?? 'https://genzhype.com', '/');
    $link = $base . $pg['path'];
    $cover = $pg['featured_img'] ?: $pg['cover'];
    $slug = basename(rtrim((string)$pg['path'], '/'));
    $accent = ['conflict' => '#E23B2E', 'scandal' => '#D79A2A', 'sad' => '#5E84B8', 'funny' => '#3DA35D',
               'hype' => '#B5468C', 'neutral' => '#C71F12'][$pg['mood'] ?? 'neutral'] ?? '#C71F12';
    $platforms = social_platforms();

    // ONE AI call -> all platform versions (the cheap path)
    $specs = '';
    foreach ($platforms as $k => $v) $specs .= "- $k (<= {$v['limit']} chars): {$v['hint']}\n";
    $sys = "You are the GenZHype social desk. Turn ONE story into native posts for each platform. Match each platform's culture exactly. Gen Z voice: confident, not cringe, never clickbait-lying. Use ONLY facts in the story. Output STRICT JSON: an object keyed by platform, each value a ready-to-paste string (include hashtags inline where noted; do NOT include the URL — it's added automatically except where the hint says 'the link', then end with {LINK}).";
    $user = "STORY ({$pg['type']}): \"{$pg['h1']}\"\nSUMMARY: " . mb_substr((string)$pg['summary'], 0, 400) . "\n\nWrite a post for each:\n$specs\nReturn JSON: {\"x\":\"...\",\"threads\":\"...\",\"instagram\":\"...\",\"tiktok\":\"...\",\"pinterest\":\"...\",\"reddit\":\"...\"}";
    $res = ai_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], ['gemini', 'openrouter', 'nvidia'], 0.7);
    if (isset($res['error'])) return $res;
    $j = ai_json($res['content'] ?? '');
    if (!is_array($j)) return ['error' => 'model did not return JSON'];

    $ins = $pdo->prepare("INSERT INTO social_posts (page_id,source_type,platform,content,image_path,link,status,created_at)
                          VALUES (?,?,?,?,?,?, 'queued', NOW())
                          ON DUPLICATE KEY UPDATE content=VALUES(content), image_path=VALUES(image_path), status='queued'");
    // real photo (drama -hero / term -featured) becomes the card background; card-only pages stay solid
    $photoAbs = social_is_real_photo($cover) ? dirname(__DIR__) . '/public_html' . $cover : null;
    $imgCache = []; $n = 0;
    foreach ($platforms as $k => $v) {
        $txt = trim((string)($j[$k] ?? ''));
        if ($txt === '') continue;
        $txt = str_replace('{LINK}', $link, $txt);
        if (strpos($v['hint'], 'the link') !== false && strpos($txt, $link) === false) $txt .= "\n" . $link;
        // real-photo branded card per shape; fall back to a resized cover if node is down
        $img = $imgCache[$v['img']] ?? ($imgCache[$v['img']] =
            social_card_render($pg['h1'], $v['img'], $accent, $slug, $photoAbs) ?: social_image_variant((string)$cover, $v['img']));
        $ins->execute([$pageId, $pg['type'], $k, mb_substr($txt, 0, 4000), $img, $link]);
        $n++;
    }
    return ['ok' => true, 'page_id' => $pageId, 'platforms' => $n, 'h1' => $pg['h1']];
}

/** Daily factory: socialize the freshest published pages not yet in the queue. */
function social_studio_daily(PDO $pdo, int $limit = 3): array {
    social_init($pdo);
    $rows = $pdo->query("SELECT p.id FROM pages p
        LEFT JOIN (SELECT page_id, COUNT(*) c FROM social_posts GROUP BY page_id) s ON s.page_id=p.id
        WHERE p.status='published' AND p.robots='index' AND s.page_id IS NULL
        ORDER BY p.published_at DESC LIMIT " . (int)$limit)->fetchAll();
    $made = 0;
    foreach ($rows as $r) { $g = social_generate($pdo, (int)$r['id']); if (!empty($g['ok'])) $made++; }
    return ['ok' => true, 'pages_socialized' => $made];
}

/** Re-render the SHARE IMAGES for already-queued pages (real-photo cards) without touching captions
 *  or calling the AI. Used to upgrade the existing queue after an image-pipeline change. */
function social_reimage_all(PDO $pdo, int $limit = 500): array {
    social_init($pdo);
    $platforms = social_platforms();
    $pages = $pdo->query("SELECT DISTINCT page_id FROM social_posts WHERE status='queued'")->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare("UPDATE social_posts SET image_path=? WHERE page_id=? AND platform=?");
    $done = 0; $withPhoto = 0;
    foreach (array_slice($pages, 0, $limit) as $pid) {
        $p = $pdo->prepare("SELECT p.h1, p.path, p.cover, p.featured_img, d.mood
                            FROM pages p LEFT JOIN dramas d ON d.page_id=p.id
                            WHERE p.id=? AND p.status='published'");
        $p->execute([(int)$pid]); $pg = $p->fetch();
        if (!$pg) continue;
        $cover = $pg['featured_img'] ?: $pg['cover'];
        $slug = basename(rtrim((string)$pg['path'], '/'));
        $accent = ['conflict' => '#E23B2E', 'scandal' => '#D79A2A', 'sad' => '#5E84B8', 'funny' => '#3DA35D',
                   'hype' => '#B5468C', 'neutral' => '#C71F12'][$pg['mood'] ?? 'neutral'] ?? '#C71F12';
        $isPhoto = social_is_real_photo($cover);
        $photoAbs = $isPhoto ? dirname(__DIR__) . '/public_html' . $cover : null;
        $cache = [];
        foreach ($platforms as $k => $v) {
            $img = $cache[$v['img']] ?? ($cache[$v['img']] =
                social_card_render($pg['h1'], $v['img'], $accent, $slug, $photoAbs) ?: social_image_variant((string)$cover, $v['img']));
            if ($img) $upd->execute([$img, (int)$pid, $k]);
        }
        $done++; if ($isPhoto) $withPhoto++;
    }
    return ['ok' => true, 'reimaged' => $done, 'with_photo' => $withPhoto];
}
