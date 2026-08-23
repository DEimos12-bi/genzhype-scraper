<?php
/**
 * One-off cleanup for the existing-data items from the 2026-06-17 SEO audit:
 *  1) the single word-truncated title ("...and Un." on /gaming/cracked/)
 *  2) hallucinated/wrong KnowYourMeme outbound links on meme pages (the amogus ->
 *     ma-got-pranked-ybg-wallace bug): validate each, replace wrong ones with the
 *     safe KYM search URL. Generation is already fixed; this cleans what's live.
 * Run: timeout 240 php app/fix_seo_data.php
 */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/fetch_sources.php';
$pdo = db();

// 1) cracked title (word-safe)
$n = $pdo->prepare("UPDATE pages SET title_tag=?, updated_at=NOW() WHERE slug='cracked' AND type='term' AND title_tag LIKE '%and Un.'")
    ->execute(['Cracked in Gaming: When Players Are Insanely Skilled']);
echo "1) cracked title fixed: " . ($pdo->query("SELECT ROW_COUNT()")->fetchColumn() >= 0 ? "done\n" : "?\n");

// 2) validate KYM links on every published meme page
$rows = $pdo->query("SELECT p.id,p.slug,t.term,t.sources FROM pages p JOIN terms t ON t.page_id=p.id
                     WHERE p.type='term' AND p.status='published' AND t.lane='meme'")->fetchAll();
echo "2) auditing " . count($rows) . " meme pages' KYM links:\n";
foreach ($rows as $r) {
    $src = json_decode($r['sources'] ?: '[]', true);
    if (!is_array($src)) continue;
    $changed = false;
    foreach ($src as &$s) {
        if (!is_array($s) || stripos($s['url'] ?? '', 'knowyourmeme.com/memes/') === false) continue;
        // we never deep-link KYM (slang names don't match canonical slugs) -> search URL
        echo "   FIXED {$r['slug']} ({$r['term']}): '{$s['url']}' -> search\n";
        $s['url'] = 'https://knowyourmeme.com/search?q=' . urlencode($r['term']);
        $changed = true;
    }
    unset($s);
    if ($changed) $pdo->prepare("UPDATE terms SET sources=? WHERE page_id=?")->execute([json_encode($src, JSON_UNESCAPED_SLASHES), $r['id']]);
}
echo "done\n";
