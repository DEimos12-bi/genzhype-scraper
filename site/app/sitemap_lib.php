<?php
// GenZHype | sitemap generator. Only published + indexable pages enter.

/**
 * llms.txt (llmstxt.org standard): a markdown index of the site for AI
 * engines. Built from the DB exactly like the sitemap, and regenerated from
 * the same hook (called inside sitemap_build) so it can never go stale.
 */
function llms_build(): string {
    global $CONFIG;
    $pdo = db();
    $base = rtrim($CONFIG['base_url'], '/');
    $md  = "# GenZHype\n\n";
    $md .= "> Internet culture, documented. Gen Z slang, memes and gaming culture decoded as sourced encyclopedia entries; creator drama as dated, sourced timelines. Every claim is attributed; unverified items are labeled alleged. US-focused, updated daily.\n\n";
    $md .= "Editorial standards: {$base}/how-we-source/ | Corrections: {$base}/corrections/\n\n";
    $sections = [
        'Slang'  => "SELECT p.path, t.term n, t.short_def s FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.status='published' AND p.robots='index' AND t.lane='slang' ORDER BY t.term",
        'Memes'  => "SELECT p.path, t.term n, t.short_def s FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.status='published' AND p.robots='index' AND t.lane='meme' ORDER BY t.term",
        'Gaming' => "SELECT p.path, t.term n, t.short_def s FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.status='published' AND p.robots='index' AND t.lane='gaming' ORDER BY t.term",
        'Drama'  => "SELECT p.path, p.h1 n, p.summary s FROM pages p WHERE p.type='drama' AND p.status='published' AND p.robots='index' ORDER BY p.updated_at DESC",
    ];
    foreach ($sections as $title => $sql) {
        $rows = $pdo->query($sql)->fetchAll();
        if (!$rows) continue;
        $md .= "## {$title}\n\n";
        foreach ($rows as $r) {
            $line = trim(preg_replace('/\s+/', ' ', mb_substr($r['s'] ?? '', 0, 140)));
            $md .= "- [" . str_replace(['[', ']'], '', $r['n']) . "]({$base}{$r['path']}): {$line}\n";
        }
        $md .= "\n";
    }
    $out = dirname(__DIR__) . '/public_html/llms.txt';
    file_put_contents($out, $md);
    return $out;
}
/**
 * GOOGLE NEWS sitemap (schemas/sitemap-news/0.9). Google discovers news automatically now
 * (manual application closed Apr 2024) — a News sitemap just makes fresh articles surface FAST.
 * Rule: only articles from the last 48h, newest first, cap 1000. Regenerated with the main sitemap.
 */
function news_sitemap_build(): string {
    global $CONFIG;
    $pdo = db();
    $base = rtrim($CONFIG['base_url'], '/');
    $rows = $pdo->query("SELECT path, h1, published_at FROM pages
                         WHERE status='published' AND robots='index'
                           AND published_at >= (NOW() - INTERVAL 48 HOUR)
                         ORDER BY published_at DESC LIMIT 1000")->fetchAll();
    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:news=\"http://www.google.com/schemas/sitemap-news/0.9\">\n";
    foreach ($rows as $r) {
        $loc   = htmlspecialchars($base . $r['path'], ENT_XML1);
        $date  = date('c', strtotime($r['published_at']));
        $title = htmlspecialchars((string)$r['h1'], ENT_XML1);
        $xml .= "  <url><loc>{$loc}</loc><news:news><news:publication><news:name>GenZHype</news:name>"
              . "<news:language>en</news:language></news:publication>"
              . "<news:publication_date>{$date}</news:publication_date><news:title>{$title}</news:title></news:news></url>\n";
    }
    $xml .= "</urlset>\n";
    $out = dirname(__DIR__) . '/public_html/news-sitemap.xml';
    file_put_contents($out, $xml);
    return $out . ' (' . count($rows) . ' recent urls)';
}

function sitemap_build(): string {
    global $CONFIG;
    $pdo = db();
    $rows = $pdo->query("SELECT path, updated_at, h1, featured_img, cover FROM pages
                         WHERE status='published' AND robots='index'
                         ORDER BY updated_at DESC")->fetchAll();
    $base = rtrim($CONFIG['base_url'], '/');
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";
    // hubs: homepage + every lane hub that has published entries
    require_once __DIR__ . '/lanes.php';
    $hubs = ['/'];
    // /drama/ hub (added to kill orphan dramas) — include it once any drama is indexed
    if ((int)$pdo->query("SELECT COUNT(*) FROM pages WHERE type='drama' AND status='published' AND robots='index'")->fetchColumn() > 0) $hubs[] = '/drama/';
    $lanesLive = $pdo->query("SELECT DISTINCT t.lane FROM terms t JOIN pages p ON p.id=t.page_id
                              WHERE p.status='published' AND p.robots='index'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($lanesLive as $lk) if (isset(lanes()[$lk])) $hubs[] = lanes()[$lk]['prefix'];
    $now = date('c');
    foreach ($hubs as $hub) {
        $loc = htmlspecialchars($base . $hub, ENT_XML1);
        $xml .= "  <url><loc>{$loc}</loc><lastmod>{$now}</lastmod></url>\n";
    }
    foreach ($rows as $r) {
        $loc = htmlspecialchars($base . $r['path'], ENT_XML1);
        $mod = date('c', strtotime($r['updated_at']));
        // image-sitemap entry (Google image SEO): the page's featured image, raster only
        $img = $r['featured_img'] ?: ($r['cover'] ?? '');
        if ($img && !str_ends_with($img, '.svg')) {
            $iloc = htmlspecialchars($base . $img, ENT_XML1);
            $ititle = htmlspecialchars((string)$r['h1'], ENT_XML1);
            $xml .= "  <url><loc>{$loc}</loc><lastmod>{$mod}</lastmod><image:image><image:loc>{$iloc}</image:loc><image:title>{$ititle}</image:title></image:image></url>\n";
        } else {
            $xml .= "  <url><loc>{$loc}</loc><lastmod>{$mod}</lastmod></url>\n";
        }
    }
    $xml .= "</urlset>\n";
    $out = dirname(__DIR__) . '/public_html/sitemap.xml';
    file_put_contents($out, $xml);
    llms_build();          // llms.txt stays in lockstep with the sitemap, same hooks
    news_sitemap_build();  // Google News sitemap regenerates too, so fresh articles surface fast
    return $out . ' (' . (count($rows) + count($hubs)) . ' urls)';
}
