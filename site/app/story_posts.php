<?php
/* GenZHype | story_posts.php — "Posts about this story" (r152, 2026-09-10).
 *
 * The video pipeline already finds real X posts per story and checks each one
 * against X's syndication CDN (post_cards.php writes {page_id}-recovered.json).
 * Story pages never showed them. This module turns those finds into a queue the
 * OWNER approves; the story template only prints what he approved.
 *
 * Why a human decides: the dry run on 2026-09-10 let 15 posts through keyword
 * filters, and about half relayed accusations about real named people ("So he
 * was lying", "faked graduating Duke - but an even bigger lie", "his mom
 * attacked him"). The owner's 2026-08-04 legal rule is that nothing asserting
 * wrongdoing about a real person goes out unhedged and unreviewed. Keywords
 * cannot tell a clean post from one that relays a claim, so they only do the
 * reliable part: throw out spam, profanity, slurs, off-topic posts and posts
 * that no longer exist. Everything else waits for a click.
 *
 *   story_posts table  every candidate + its status: pending | approved | hidden
 *   cache/story_posts/{page_id}.json   approved posts only, read by the template
 *   story_posts_block.txt              tweet ids never to show anywhere
 *
 * No transactions here, on purpose: sp_install() runs CREATE TABLE, and on
 * MariaDB that silently commits any open transaction (the r151 crash).
 */

const SP_MAX_PER_PAGE = 3;

function sp_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id INT UNSIGNED NOT NULL,
        tweet_id VARCHAR(25) NOT NULL,
        handle VARCHAR(40) NOT NULL DEFAULT '',
        author VARCHAR(120) NOT NULL DEFAULT '',
        likes INT NULL,
        text TEXT NULL,
        html MEDIUMTEXT NULL,
        relevance VARCHAR(160) NOT NULL DEFAULT '',
        risk VARCHAR(255) NOT NULL DEFAULT '',
        status ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending',
        decided_by VARCHAR(20) NOT NULL DEFAULT '',
        reason VARCHAR(160) NOT NULL DEFAULT '',
        verified_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        decided_at DATETIME NULL,
        UNIQUE KEY uniq_page_tweet (page_id, tweet_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sp_dir(): string {
    $d = __DIR__ . '/cache/story_posts';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function sp_blocklist(): array {
    $f = __DIR__ . '/story_posts_block.txt';
    if (!is_file($f)) return [];
    $ids = [];
    foreach (preg_split('/\s+/', (string)file_get_contents($f)) as $t) if (preg_match('/^\d{5,25}$/', $t)) $ids[$t] = true;
    return $ids;
}

/** Name tokens that are also ordinary words would match everything. */
function sp_common_words(): array {
    static $w = null;
    if ($w === null) $w = array_flip(['more','will','king','rich','young','black','white','grace','hope','brown','green',
        'gray','grey','rose','star','love','west','north','south','chase','hunter','cash','frank','price','power',
        'stone','wood','woods','bell','little','long','short','good','best','after','about','their','there','where',
        'which','other','first','great','world','money','drama','stream','streamer','twitch','youtube','tiktok',
        'video','viral','timeline','leak','leaks','data','source','code','payouts','story','internet','online',
        'official','update','news','game','games','gaming','users','fans','people','social','media','public',
        'controversy','backlash','apology','sparks','exposed','reveals','reveal','these','those','legal','battle']);
    return $w;
}

/** [bool, why] — does the post actually talk about THIS story? */
function sp_relevant(string $text, array $names, string $title): array {
    $t = mb_strtolower($text);
    $common = sp_common_words();
    foreach ($names as $n) {
        $n = mb_strtolower(trim((string)$n));
        if ($n === '') continue;
        if (mb_strlen($n) >= 4 && str_contains($t, $n)) return [true, "names \"{$n}\""];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $n) as $tok) {
            if (mb_strlen($tok) >= 5 && !isset($common[$tok])
                && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($tok, '/') . '(?![\p{L}\p{N}])/u', $t)) {
                return [true, "names \"{$tok}\""];
            }
        }
    }
    $hits = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)) as $tok) {
        if (mb_strlen($tok) >= 5 && !isset($common[$tok])
            && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($tok, '/') . '(?![\p{L}\p{N}])/u', $t)) $hits[$tok] = true;
    }
    if (count($hits) >= 2) return [true, 'title words: ' . implode(', ', array_keys($hits))];
    return [false, 'does not name anyone in the story'];
}

/** [bool, why] — the reliable part only: spam, profanity, slurs, sexual, self-harm, open accusations. */
function sp_safe(string $text): array {
    $t = mb_strtolower($text);
    $rules = [
        'profanity'   => '/\b(fuck\w*|shit\w*|bitch\w*|cunt\w*|dick\w*|pussy|whore\w*|slut\w*|asshole\w*|motherf\w*|wtf)\b/u',
        'slur'        => '/\b(nigg\w*|fag\w*|retard\w*|tranny\w*|kike\w*|spic\w*|chink\w*)\b/u',
        'sexual'      => '/\b(porn\w*|nude\w*|nudes|onlyfans|sex\s*tape|nsfw|xxx)\b/u',
        'self-harm'   => '/\b(kys|kill\s+(yo)?ursel(f|ves)|suicid\w*)\b/u',
        'accusation'  => '/\b(rapist\w*|rape[ds]?|raping|pedo\w*|paedo\w*|predator\w*|groom(er|ers|ing)|molest\w*|abuser\w*|scam(mer|mers|ming)?|fraudster\w*|criminal\w*|thie(f|ves)|liar\w*|lying|racist\w*|sexist\w*|misogyn\w*|lawsuit\s+against|sue\s+him|sue\s+her)\b/u',
        'crypto/spam' => '/(\$[a-z]{2,10}\b|\bairdrop\w*|\bpresale\b|\bgiveaway\w*|\bdm\s+me\b|t\.me\/|\bwhatsapp\b|\btelegram\b|\bmemecoin\w*|\bnfts?\b)/u',
    ];
    foreach ($rules as $why => $re) if (preg_match($re, $t, $m)) return [false, "{$why} (\"{$m[0]}\")"];
    if (preg_match_all('/#\w+/u', $t) >= 4) return [false, 'hashtag spam'];
    return [true, 'clean'];
}

/** Plain-language hints for the owner. They inform a decision; they never make one. */
function sp_risk_notes(string $text, array $names, string $relevance): array {
    $t = mb_strtolower($text);
    $n = [];
    $named = [];
    foreach ($names as $nm) if ($nm !== '' && str_contains($t, mb_strtolower($nm))) $named[] = $nm;
    if (preg_match('/\b(show(s|ed)? that|accus\w*|fak(e|ed|ing)|lie[sd]?|attack\w*|allegedly|alleg\w*|court|legal documents?|leaked|lawsuit|sue[sd]?|nda|threat\w*|pushing past|consent|accountab\w*|blam\w*|caught|arrest\w*|jail|prison|fraud\w*|harass\w*|assault\w*|abus\w*)\b/u', $t, $m)) {
        $n[] = 'makes or repeats a claim' . ($named ? ' about ' . implode(', ', $named) : '') . " (\"{$m[0]}\")";
    }
    if (preg_match('/\b(mom|mother|dad|father|sister|brother|wife|husband|girlfriend|boyfriend|son|daughter|family)\b/u', $t, $m)) {
        $n[] = "mentions a family member (\"{$m[0]}\")";
    }
    if (preg_match('/\b(israeli|jewish|muslim|palestinian|arab|indian|pakistani|mexican|chinese|asian|african|russian|ukrainian)\b/u', $t, $m)) {
        $n[] = "nationality or ethnicity framing (\"{$m[0]}\")";
    }
    if ($relevance !== '' && !str_starts_with($relevance, 'names')) $n[] = 'matched on title words only: check it is this story';
    return $n;
}

/** Everything the curator needs about one story page. */
function sp_story(PDO $pdo, int $pageId): ?array {
    $st = $pdo->prepare("SELECT p.id, p.slug, p.path, p.status, p.h1, d.id drama_id, d.title, d.people_json, v.reach_posts
                         FROM pages p JOIN dramas d ON d.page_id=p.id
                         LEFT JOIN video_scripts v ON v.page_id=p.id
                         WHERE p.id=? AND p.type='drama'");
    $st->execute([$pageId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $names = [];
    foreach ((json_decode((string)$r['people_json'], true) ?: []) as $pe) if (!empty($pe['name'])) $names[] = (string)$pe['name'];
    $likes = [];
    $rp = json_decode((string)$r['reach_posts'], true);
    foreach ((array)($rp['posts'] ?? []) as $p) if (!empty($p['id'])) $likes[(string)$p['id']] = (int)($p['likes'] ?? 0);
    $e = $pdo->prepare("SELECT GROUP_CONCAT(embed_html SEPARATOR ' ') FROM events WHERE drama_id=?");
    $e->execute([(int)$r['drama_id']]);
    return ['page_id' => (int)$r['id'], 'slug' => (string)$r['slug'], 'path' => (string)$r['path'], 'status' => (string)$r['status'],
            'title' => (string)($r['title'] ?: $r['h1']), 'names' => $names, 'likes' => $likes,
            'timeline_html' => (string)$e->fetchColumn()];
}

/** Live check through the site's own X embed builder. [html|null, live text]. */
function sp_verify_live(string $tweetId, string $handle): array {
    require_once __DIR__ . '/embeds.php';
    $emb = embed_twitter_syndication('https://twitter.com/' . ($handle !== '' ? $handle : 'i') . '/status/' . $tweetId);
    if (!$emb || empty($emb['html'])) return [null, ''];
    $live = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$emb['html']), ENT_QUOTES, 'UTF-8')));
    return [(string)$emb['html'], $live];
}

/**
 * Queue new finds for one page, and re-check the posts it is showing.
 * Owner decisions are never overwritten.
 */
function sp_refresh_page(PDO $pdo, int $pageId): array {
    sp_install($pdo);
    $out = ['page_id' => $pageId, 'new_pending' => 0, 'new_hidden' => 0, 'approved_gone' => 0, 'approved_ok' => 0];
    $story = sp_story($pdo, $pageId);
    if (!$story || $story['status'] !== 'published') { sp_publish_page($pdo, $pageId); return $out; }
    $src = dirname(__DIR__) . '/public_html/assets/receipts/video/' . $pageId . '-recovered.json';
    $rec = is_file($src) ? (json_decode((string)file_get_contents($src), true) ?: []) : [];
    $known = [];
    $st = $pdo->prepare("SELECT tweet_id FROM story_posts WHERE page_id=?");
    $st->execute([$pageId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $known[(string)$k] = true;
    $block = sp_blocklist();
    $insPending = $pdo->prepare("INSERT INTO story_posts (page_id, tweet_id, handle, author, likes, text, html, relevance, risk, status, verified_at, created_at)
                                 VALUES (?,?,?,?,?,?,?,?,?,'pending',NOW(),NOW())");
    $insHidden  = $pdo->prepare("INSERT INTO story_posts (page_id, tweet_id, handle, author, likes, text, status, decided_by, reason, created_at, decided_at)
                                 VALUES (?,?,?,?,?,?,'hidden','filter',?,NOW(),NOW())");
    foreach ($rec as $id => $x) {
        $id = (string)$id;
        if (!preg_match('/^\d{5,25}$/', $id) || isset($known[$id])) continue;
        $handle = mb_substr((string)($x['handle'] ?? ''), 0, 40);
        $author = mb_substr((string)($x['author'] ?? ''), 0, 120);
        $likes  = $story['likes'][$id] ?? null;
        $text   = (string)($x['excerpt'] ?? '');
        $hide = null; $relev = '';
        if (empty($x['ok']))                                  $hide = 'not verified by X';
        elseif (isset($block[$id]))                           $hide = 'on the block list';
        elseif (str_contains($story['timeline_html'], $id))   $hide = 'already embedded in the timeline';
        else {
            [$ok, $relev] = sp_relevant($text, $story['names'], $story['title']);
            if (!$ok) $hide = $relev;
            else { [$safe, $sw] = sp_safe($text); if (!$safe) $hide = $sw; }
        }
        $html = null;
        if ($hide === null) {
            [$html, $live] = sp_verify_live($id, $handle);
            if ($html === null) $hide = 'no longer available on X';
            else {
                $text = $live;
                [$safe, $sw] = sp_safe($live);
                if (!$safe) $hide = 'live text: ' . $sw;
            }
        }
        if ($hide !== null) {
            $insHidden->execute([$pageId, $id, $handle, $author, $likes, $text, mb_substr($hide, 0, 160)]);
            $out['new_hidden']++;
        } else {
            $risk = implode(' | ', sp_risk_notes($text, $story['names'], $relev));
            $insPending->execute([$pageId, $id, $handle, $author, $likes, $text, $html, mb_substr($relev, 0, 160), mb_substr($risk, 0, 255)]);
            $out['new_pending']++;
        }
    }
    // A post that was deleted on X must leave the page.
    $ap = $pdo->prepare("SELECT id, tweet_id, handle FROM story_posts WHERE page_id=? AND status='approved'");
    $ap->execute([$pageId]);
    foreach ($ap->fetchAll(PDO::FETCH_ASSOC) as $row) {
        [$html, $live] = sp_verify_live((string)$row['tweet_id'], (string)$row['handle']);
        if ($html === null) {
            $pdo->prepare("UPDATE story_posts SET status='hidden', decided_by='filter', reason='deleted on X', decided_at=NOW() WHERE id=?")
                ->execute([(int)$row['id']]);
            $out['approved_gone']++;
        } else {
            $pdo->prepare("UPDATE story_posts SET html=?, text=?, verified_at=NOW() WHERE id=?")->execute([$html, $live, (int)$row['id']]);
            $out['approved_ok']++;
        }
    }
    sp_publish_page($pdo, $pageId);
    return $out;
}

/** Write the approved posts for one page (or remove the file when there are none). */
function sp_publish_page(PDO $pdo, int $pageId): int {
    sp_install($pdo);
    $st = $pdo->prepare("SELECT tweet_id, handle, html FROM story_posts
                         WHERE page_id=? AND status='approved' AND html IS NOT NULL AND html <> ''
                         ORDER BY likes IS NULL, likes DESC, id ASC LIMIT " . (int)SP_MAX_PER_PAGE);
    $st->execute([$pageId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $file = sp_dir() . '/' . $pageId . '.json';
    if (!$rows) { if (is_file($file)) @unlink($file); return 0; }
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode([
        'page_id' => $pageId, 'built_at' => gmdate('c'),
        'posts' => array_map(fn($r) => ['id' => (string)$r['tweet_id'], 'handle' => (string)$r['handle'], 'html' => (string)$r['html']], $rows),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
    return count($rows);
}

/** Approve / hide / send back to pending. Approving re-checks the post live first. */
function sp_set_status(PDO $pdo, int $pageId, string $tweetId, string $status, string $by = 'owner', string $reason = ''): array {
    sp_install($pdo);
    if (!in_array($status, ['approved', 'hidden', 'pending'], true)) return [false, 'unknown status'];
    $st = $pdo->prepare("SELECT id, handle FROM story_posts WHERE page_id=? AND tweet_id=?");
    $st->execute([$pageId, $tweetId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, "no post {$tweetId} queued for page {$pageId}"];
    if ($status === 'approved') {
        [$html, $live] = sp_verify_live($tweetId, (string)$row['handle']);
        if ($html === null) {
            $pdo->prepare("UPDATE story_posts SET status='hidden', decided_by='filter', reason='deleted on X', decided_at=NOW() WHERE id=?")
                ->execute([(int)$row['id']]);
            sp_publish_page($pdo, $pageId);
            return [false, 'that post no longer exists on X, so it was hidden instead'];
        }
        $pdo->prepare("UPDATE story_posts SET status='approved', decided_by=?, reason=?, html=?, text=?, verified_at=NOW(), decided_at=NOW() WHERE id=?")
            ->execute([mb_substr($by, 0, 20), mb_substr($reason, 0, 160), $html, $live, (int)$row['id']]);
    } elseif ($status === 'hidden') {
        $pdo->prepare("UPDATE story_posts SET status='hidden', decided_by=?, reason=?, decided_at=NOW() WHERE id=?")
            ->execute([mb_substr($by, 0, 20), mb_substr($reason, 0, 160), (int)$row['id']]);
    } else {
        $pdo->prepare("UPDATE story_posts SET status='pending', decided_by='', reason='', decided_at=NULL WHERE id=?")
            ->execute([(int)$row['id']]);
    }
    $shown = sp_publish_page($pdo, $pageId);
    return [true, "page {$pageId}: post {$tweetId} is now {$status}; the page shows {$shown} post(s)"];
}

/** Story pages that have saved X posts to consider. */
function sp_pages_with_candidates(PDO $pdo): array {
    $ids = [];
    foreach (glob(dirname(__DIR__) . '/public_html/assets/receipts/video/*-recovered.json') ?: [] as $f) $ids[] = (int)basename($f);
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    return array_map('intval', $pdo->query("SELECT id FROM pages WHERE id IN ($in) AND type='drama' AND status='published' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
}

/** The daily pass: bounded by a time budget so it can never eat the hourly run. */
function sp_refresh_all(PDO $pdo, int $budgetSec = 90): array {
    $t0 = time();
    $sum = ['pages' => 0, 'new_pending' => 0, 'new_hidden' => 0, 'approved_gone' => 0, 'stopped_early' => false];
    foreach (sp_pages_with_candidates($pdo) as $pid) {
        if (time() - $t0 > $budgetSec) { $sum['stopped_early'] = true; break; }
        $r = sp_refresh_page($pdo, $pid);
        $sum['pages']++;
        foreach (['new_pending', 'new_hidden', 'approved_gone'] as $k) $sum[$k] += $r[$k];
    }
    // r153 (step 6): with the time left, look inside the newest stories' own cited
    // articles for posts the reporters embedded (see sp_harvest_from_sources).
    $left = $budgetSec - (time() - $t0);
    if ($left > 15) {
        $h = sp_harvest_recent($pdo, 8, $left);
        $sum['new_pending'] += $h['new_pending'];
        $sum['new_hidden']  += $h['new_hidden'];
        $sum['harvested_pages'] = $h['pages'];
        if ($h['stopped_early']) $sum['stopped_early'] = true;
    }
    return $sum;
}

function sp_counts(PDO $pdo): array {
    sp_install($pdo);
    $c = ['pending' => 0, 'approved' => 0, 'hidden' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM story_posts GROUP BY status") as $r) $c[$r['status']] = (int)$r['n'];
    return $c;
}

/* ------------------------------------------------------------------ r153
 * STEP 6: MORE REAL POSTS PER STORY, FROM THE STORY'S OWN SOURCES.
 * The strongest candidates are posts a reporter embedded inside an article the
 * story already cites: that is provenance, not a loose search. They go into the
 * SAME approval queue and never into the timeline. (The retired r25 harvester,
 * tweets_backfill_for_drama, wrote them straight into events as confirmed, which
 * the owner's 2026-08-04 rule forbids.)
 */

/** A post embedded in the story's own article only has to touch the story once. */
function sp_relevant_loose(string $text, array $names, string $title): array {
    [$ok, $why] = sp_relevant($text, $names, $title);
    if ($ok) return [true, $why];
    $t = mb_strtolower($text);
    $common = sp_common_words();
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)) as $tok) {
        if (mb_strlen($tok) >= 5 && !isset($common[$tok])
            && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($tok, '/') . '(?![\p{L}\p{N}])/u', $t)) {
            return [true, "title word: {$tok}"];
        }
    }
    return [false, 'embedded in the article but does not mention the story'];
}

function sp_harvest_from_sources(PDO $pdo, int $pageId, int $maxArticles = 6, int $budgetSec = 45): array {
    sp_install($pdo);
    $out = ['page_id' => $pageId, 'articles' => 0, 'found' => 0, 'new_pending' => 0, 'new_hidden' => 0];
    $story = sp_story($pdo, $pageId);
    if (!$story || $story['status'] !== 'published') return $out;
    require_once __DIR__ . '/fetch_sources.php';
    $st = $pdo->prepare("SELECT DISTINCT s.url FROM events e JOIN dramas d ON d.id=e.drama_id JOIN sources s ON s.id=e.source_id
                         WHERE d.page_id=? AND s.url IS NOT NULL AND s.url NOT LIKE '%/status/%'
                         LIMIT " . (int)$maxArticles);
    $st->execute([$pageId]);
    $known = [];
    $k = $pdo->prepare("SELECT tweet_id FROM story_posts WHERE page_id=?");
    $k->execute([$pageId]);
    foreach ($k->fetchAll(PDO::FETCH_COLUMN) as $t) $known[(string)$t] = true;
    $block = sp_blocklist();
    $insPending = $pdo->prepare("INSERT INTO story_posts (page_id, tweet_id, handle, author, likes, text, html, relevance, risk, status, verified_at, created_at)
                                 VALUES (?,?,?,?,NULL,?,?,?,?,'pending',NOW(),NOW())");
    $insHidden  = $pdo->prepare("INSERT INTO story_posts (page_id, tweet_id, handle, author, likes, text, status, decided_by, reason, created_at, decided_at)
                                 VALUES (?,?,?,?,NULL,?,'hidden','filter',?,NOW(),NOW())");
    $t0 = time();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $artUrl) {
        if (time() - $t0 > $budgetSec) break;
        $html = fs_http_get((string)$artUrl, 12);
        $out['articles']++;
        if (!$html) continue;
        $host = preg_replace('/^www\./', '', (string)parse_url((string)$artUrl, PHP_URL_HOST));
        foreach (fs_harvest_social($html, 12) as $soc) {
            if (($soc['provider'] ?? '') !== 'twitter') continue;
            if (!preg_match('#/([A-Za-z0-9_]{1,20})/status/(\d+)#', (string)$soc['url'], $m)) continue;
            [$all, $handle, $id] = $m;
            if (isset($known[$id])) continue;
            $known[$id] = true;
            $out['found']++;
            $hide = null; $text = ''; $html2 = null; $author = ''; $relev = '';
            if (isset($block[$id]))                               $hide = 'on the block list';
            elseif (str_contains($story['timeline_html'], $id))   $hide = 'already embedded in the timeline';
            if ($hide === null) {
                [$html2, $live] = sp_verify_live($id, $handle);
                if ($html2 === null) $hide = 'no longer available on X';
                else {
                    $text = $live;
                    if (preg_match('/\x{2014}\s*(.+?)\s*\(@/u', $live, $am)) $author = mb_substr(trim($am[1]), 0, 120);
                    [$ok, $relev] = sp_relevant_loose($live, $story['names'], $story['title']);
                    if (!$ok) $hide = $relev;
                    else { [$safe, $sw] = sp_safe($live); if (!$safe) $hide = 'live text: ' . $sw; }
                }
            }
            if ($hide !== null) {
                $insHidden->execute([$pageId, $id, mb_substr($handle, 0, 40), $author, $text, mb_substr($hide, 0, 160)]);
                $out['new_hidden']++;
            } else {
                $why = "embedded in the story's source article on {$host}; " . $relev;
                $risk = implode(' | ', sp_risk_notes($text, $story['names'], ''));
                $insPending->execute([$pageId, $id, mb_substr($handle, 0, 40), $author, $text, $html2, mb_substr($why, 0, 160), mb_substr($risk, 0, 255)]);
                $out['new_pending']++;
            }
        }
    }
    return $out;
}

/** Newest published stories whose articles were not read in the last 7 days. */
function sp_harvest_recent(PDO $pdo, int $maxPages = 8, int $budgetSec = 60): array {
    $state = sp_dir() . '/harvest_state.json';
    $seen = is_file($state) ? (json_decode((string)file_get_contents($state), true) ?: []) : [];
    $t0 = time();
    $sum = ['pages' => 0, 'new_pending' => 0, 'new_hidden' => 0, 'stopped_early' => false];
    $ids = $pdo->query("SELECT id FROM pages WHERE type='drama' AND status='published' AND robots='index'
                        ORDER BY published_at DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $pid) {
        if ($sum['pages'] >= $maxPages) break;
        if (isset($seen[$pid]) && time() - (int)$seen[$pid] < 7 * 86400) continue;
        $left = $budgetSec - (time() - $t0);
        if ($left < 10) { $sum['stopped_early'] = true; break; }
        $r = sp_harvest_from_sources($pdo, (int)$pid, 6, $left);
        $seen[$pid] = time();
        $sum['pages']++;
        $sum['new_pending'] += $r['new_pending'];
        $sum['new_hidden']  += $r['new_hidden'];
    }
    $tmp = $state . '.tmp';
    file_put_contents($tmp, json_encode($seen));
    rename($tmp, $state);
    return $sum;
}
