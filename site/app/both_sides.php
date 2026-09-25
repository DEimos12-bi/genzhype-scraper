<?php
// GenZHype | BOTH SIDES (owner rule 2026-09-25, a weak item: "both sides' statements side by
// side"). For a story where two sides disagree, one verbatim quote from each, as our sources
// report it. The quotes come from quote_candidates() (helpers.php: real quoted text in the
// source excerpts, the dignity/accusation filter on) and keep only those the source attributes
// by name next to the quote (quote_speaker()); the AI only picks which two show the two sides,
// and the speaker shown is the source's, never the AI's. A first version let the AI name the
// speaker and checked only that the name appeared nearby: it credited iDubbbz's words to Klein.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/helpers.php';

/** Idempotent; run outside a transaction (an ALTER commits an open one on MariaDB, r151). */
function bs_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE dramas ADD COLUMN both_sides TEXT NULL"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/**
 * The story people's own posts as statements: a post's author is certain, no attribution to read.
 * From source rows of original posts ("Original tweet by NAME (@handle): TEXT", fs_social_excerpt)
 * and from X embeds on the timeline ("TEXT - NAME (@handle)"). Same length window and dignity
 * filter as article quotes. 2026-09-25: only 183 of 3,904 article quotes named their speaker next
 * to them, so posts are where both sides' own words mostly are.
 */
function bs_post_statements(PDO $pdo, int $did): array {
    $out = []; $seen = [];
    $add = function (string $text, string $who, string $by, string $url) use (&$out, &$seen) {
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('#https?://\S+#', '', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'))));
        // the post's own signature is not part of what was said: "... Yung Miami (@YungMiami305) June 16, 2026"
        // (the name holds no sentence punctuation, or the strip eats the post itself)
        $t = trim(preg_replace('/(?:\s*[\x{2014}-]\s*|\s+)[^()@.!?\x{2026}\x{2014}]{1,40}\(@\w+\)\s+\p{L}+\.? \d{1,2}, \d{4}\s*$/u', '', $t));
        if (mb_strlen($t) > 280) $t = rtrim(mb_substr($t, 0, 277)) . '...';
        $key = mb_strtolower(mb_substr($t, 0, 60));
        if ($who === '' || mb_strlen($t) < 30 || isset($seen[$key]) || quote_is_unsafe($t)) return;
        $seen[$key] = 1;
        $out[] = ['quote' => $t, 'speaker' => trim($who), 'by' => $by, 'url' => $url];
    };
    $st = $pdo->prepare("SELECT DISTINCT s.url, s.excerpt FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=?
                         AND (s.excerpt LIKE 'Original tweet by %' OR s.excerpt LIKE 'Original % post by %')");
    $st->execute([$did]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
        if (preg_match('/^Original (?:tweet|(\w+) post) by (.+?)(?: \(@\w+\))?: (.+)$/s', (string)$r['excerpt'], $m))
            $add($m[3], $m[2], $m[1] !== '' ? ucfirst($m[1]) : 'X', (string)$r['url']);
    $st = $pdo->prepare("SELECT embed_html FROM events WHERE drama_id=? AND video_only=0 AND embed_provider='twitter' AND embed_html LIKE '%twitter-tweet%'");
    $st->execute([$did]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h)
        if (preg_match('#<p[^>]*>(.*?)</p>\s*&mdash;\s*([^(<]+?)\s*\(@\w+\)\s*<a href="([^"]+)"#s', (string)$h, $m)) $add($m[1], html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), 'X', $m[3]);
    return $out;
}

/**
 * Pick and check one quote per side. ['sides' => [[who, quote, by, url], [..]]] or
 * ['sides' => [], 'why' => reason]; ['error' => ...] when the AI did not answer (ask again later).
 */
function bs_find(PDO $pdo, int $pageId): array {
    $d = $pdo->prepare("SELECT d.id, p.summary, p.h1, d.primary_kw, d.people_json FROM dramas d JOIN pages p ON p.id=d.page_id WHERE d.page_id=?");
    $d->execute([$pageId]);
    $story = $d->fetch(PDO::FETCH_ASSOC);
    if (!$story) return ['error' => 'not a story page'];
    $src = $pdo->prepare("SELECT DISTINCT s.url, s.publisher, s.excerpt FROM events e JOIN sources s ON s.id=e.source_id
                          WHERE e.drama_id=? AND s.excerpt IS NOT NULL AND s.excerpt <> ''");
    $src->execute([(int)$story['id']]);
    // only quotes the source itself attributes by name ('"...," Klein said': quote_speaker()) and
    // the story people's own posts; the speaker must be named in the story itself, so a fan's post
    // or a bystander quoted in an article is never shown as one of the sides
    $storyText = mb_strtolower($story['h1'] . ' ' . $story['summary'] . ' ' . $story['primary_kw'] . ' '
        . implode(' ', array_column((array)json_decode((string)$story['people_json'], true), 'name')));
    // the whole name must be in the story: a last-word match let a news account "NUCLR GOLF" count
    // as a side of a golf story (page 283)
    $inStory = function (string $who) use ($storyText): bool {
        $w = mb_strtolower(trim($who));
        return mb_strlen($w) >= 3 && str_contains($storyText, $w);
    };
    $cands = array_merge(bs_post_statements($pdo, (int)$story['id']),
                         array_values(array_filter(quote_candidates($src->fetchAll(PDO::FETCH_ASSOC), true), fn($c) => $c['speaker'] !== '')));
    $cands = array_slice(array_values(array_filter($cands, fn($c) => $inStory($c['speaker']))), 0, 24);
    $speakers = [];
    foreach ($cands as $c) $speakers[mb_strtolower((string)array_slice(explode(' ', $c['speaker']), -1)[0])] = 1;
    if (count($speakers) < 2) return ['sides' => [], 'why' => 'the sources quote fewer than 2 named speakers'];

    $list = '';
    foreach ($cands as $i => $c) $list .= ($i + 1) . ". {$c['speaker']}: \"{$c['quote']}\"\n";
    $res = ai_chat([
        ['role' => 'system', 'content' => 'Below are QUOTES from a news story\'s sources, each with the person the source says spoke it. If two sides '
            . 'disagree in this story, pick the quote that best states each side\'s position, from two different speakers on opposite sides. '
            . 'If these quotes do not show two sides of a disagreement, return an empty list. Output STRICT JSON only: {"sides":[{"n":1},{"n":2}]}'],
        ['role' => 'user', 'content' => "STORY: {$story['summary']}\n\nQUOTES:\n{$list}"],
    ], AI_READER_ORDER, 0.0, 90, AI_READER_SKIP);
    $j = isset($res['error']) ? null : ai_json((string)$res['content']);
    if (trim((string)($res['content'] ?? '')) === '[]' || (is_array($j) && isset($j['sides']) && $j['sides'] === [])) return ['sides' => [], 'why' => 'no two sides in their own words'];
    if (!is_array($j) || !isset($j['sides']) || !is_array($j['sides'])) return ['error' => 'AI gave no usable answer: ' . ($res['error'] ?? mb_substr((string)$res['content'], 0, 80))];
    if (count($j['sides']) !== 2) return ['sides' => [], 'why' => 'no two sides in their own words'];
    $sides = [];
    foreach ($j['sides'] as $sd) {
        $c = $cands[(int)($sd['n'] ?? 0) - 1] ?? null;
        if (!$c) return ['sides' => [], 'why' => 'the answer named a quote that does not exist'];
        $sides[] = ['who' => $c['speaker'], 'quote' => $c['quote'], 'by' => $c['by'], 'url' => $c['url']];   // the source's attribution, not the AI's
    }
    $last = fn($w) => mb_strtolower((string)array_slice(explode(' ', $w), -1)[0]);
    if ($last($sides[0]['who']) === $last($sides[1]['who'])) return ['sides' => [], 'why' => 'both quotes are from the same speaker'];
    return ['sides' => $sides];
}
/** Store the result ('[]' = checked, none); a new pair marks the page updated (the cache rebuilds). */
function bs_save(PDO $pdo, int $pageId, array $sides): void {
    bs_install($pdo);
    $old = (string)$pdo->query("SELECT both_sides FROM dramas WHERE page_id=" . $pageId)->fetchColumn();
    $new = json_encode($sides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($old === $new) return;
    $pdo->prepare("UPDATE dramas SET both_sides=? WHERE page_id=?")->execute([$new, $pageId]);
    if ($sides) $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);
}

/** Live stories never checked, and those with none found that changed in the last day (new posts or
 *  events can bring the other side), newest first; at most $limit stories and $maxSecs. */
function bs_run(PDO $pdo, int $limit = 10, int $maxSecs = 240): array {
    bs_install($pdo);
    $t0 = time();
    $out = ['checked' => 0, 'found' => 0, 'none' => 0, 'no_answer' => 0];
    $ids = $pdo->query("SELECT p.id FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.type='drama' AND p.status='published'
                        AND (d.both_sides IS NULL OR (d.both_sides = '[]' AND p.updated_at > NOW() - INTERVAL 1 DAY))
                        ORDER BY p.published_at DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $pid) {
        if (time() - $t0 > $maxSecs) break;
        $out['checked']++;
        $r = bs_find($pdo, (int)$pid);
        if (isset($r['error'])) { $out['no_answer']++; continue; }   // not stored: asked again next run
        bs_save($pdo, (int)$pid, $r['sides']);
        $r['sides'] ? $out['found']++ : $out['none']++;
    }
    return $out;
}
