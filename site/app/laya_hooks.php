<?php
/**
 * GenZHype | r190 HOOK LEARNING LOOP - server side.
 *
 * Owner, 2026-09-24: "build the hook learning loop". Measured that day, Laya
 * (a fast text classifier running on GitHub, see .github/workflows/laya.yml)
 * scored our TikTok hooks against their real views at AUC 0.653 out of the box
 * - a real but modest instinct. Every posted video returns a view count, so
 * every hook becomes a free labelled example and the loop teaches itself:
 *
 *   export  (this file)  posted hooks + how they did, and the hooks of the
 *                        videos still waiting, -> branch laya-feed
 *   learn   (GitHub)     laya_hooks.py: Laya's answers become features, a
 *                        small model learns which predict views, tested on
 *                        hooks it never saw; scores the waiting hooks
 *   ingest  (this file)  scores come back from laya-drop into video_scripts;
 *                        a weak hook gets alternatives written, and the next
 *                        round swaps in the best one if it clearly beats it
 *
 * The on-screen hook is what changes. The spoken script does not, so a shot
 * list already written against the script's word positions stays valid.
 *
 * CLI (called by ~/genzhype-video-bridge.sh):
 *   php app/laya_hooks.php export <dir>   writes <dir>/feed.json
 *   php app/laya_hooks.php ingest <dir>   reads <dir>/hooks/scores.json
 */

const LAYA_HOOK_MIN_AGE_DAYS = 3;    // views on a younger video are not comparable yet
const LAYA_HOOK_SWAP_MARGIN  = 0.05; // an alternative must beat the current hook by this much
const LAYA_HOOK_ALT_PER_RUN  = 2;    // scripts that get alternatives written per ingest (AI cost)

function laya_hooks_install(PDO $pdo): void {
    foreach (["hook_score DECIMAL(5,3) NULL", "hook_candidates TEXT NULL", "hook_scored_at DATETIME NULL",
              "hook_original VARCHAR(255) NULL"] as $col) {
        try { $pdo->exec("ALTER TABLE video_scripts ADD COLUMN IF NOT EXISTS {$col}"); } catch (Throwable $e) {}
    }
}

/**
 * Training rows: one per hook, labelled with how the video did. Views are
 * turned into a rank WITHIN each platform (0 = worst, 1 = best), then averaged
 * over the platforms the video went to, so TikTok's larger numbers do not drown
 * the others and one platform's bad day does not decide the label.
 */
function laya_hooks_training(PDO $pdo): array {
    $rows = $pdo->query("SELECT v.page_id, v.platform, s.hook, MAX(m.views) views
                         FROM platform_videos v
                         JOIN platform_metrics m ON m.video_id = v.id
                         JOIN video_scripts s ON s.page_id = v.page_id
                         WHERE v.posted_at < NOW() - INTERVAL " . LAYA_HOOK_MIN_AGE_DAYS . " DAY
                           AND CHAR_LENGTH(s.hook) > 5
                         GROUP BY v.page_id, v.platform, s.hook")->fetchAll(PDO::FETCH_ASSOC);
    $byPlat = [];
    foreach ($rows as $r) $byPlat[$r['platform']][] = $r;
    $ranks = [];
    foreach ($byPlat as $plat => $list) {
        usort($list, fn($a, $b) => (int)$a['views'] <=> (int)$b['views']);
        $n = count($list);
        foreach ($list as $i => $r) {
            $ranks[(int)$r['page_id']]['hook'] = trim((string)$r['hook']);
            $ranks[(int)$r['page_id']]['ranks'][$plat] = $n > 1 ? round($i / ($n - 1), 4) : 0.5;
            $ranks[(int)$r['page_id']]['views'][$plat] = (int)$r['views'];
        }
    }
    $out = [];
    foreach ($ranks as $pid => $x) {
        $out[] = ['page_id' => $pid, 'hook' => $x['hook'],
                  'label' => round(array_sum($x['ranks']) / count($x['ranks']), 4),
                  'platforms' => count($x['ranks']), 'views' => $x['views']];
    }
    return $out;
}

/**
 * r190b TEACHER = WINNING CREATORS, not us (owner, same day: "our data should
 * not be the reference... train it on the best videos across the platforms").
 * Our best hook had 4,763 views; the tracked rivals average 6.27 million. But
 * a model shown ONLY winners cannot learn what made them win (it learns "sounds
 * like a big account"), so each rival's post is ranked against THAT creator's
 * own posts - the creator-analytics "outlier" method: same account, audience
 * and niche, so the difference is mostly the hook. YouTube: video title (a
 * Short's title is its hook), ranked by views. Instagram: the caption's first
 * line, ranked by likes (views exist only on some posts). Creators with fewer
 * than 20 posts are left out - too few to know what "their normal" is.
 */
function laya_hooks_rivals(PDO $pdo): array {
    $groups = [];
    foreach ($pdo->query("SELECT rival_id, title, views FROM vid_rival_post WHERE views > 0 AND CHAR_LENGTH(title) > 8") as $r) {
        $groups['yt:' . $r['rival_id']][] = ['hook' => trim((string)$r['title']), 'v' => (int)$r['views']];
    }
    foreach ($pdo->query("SELECT rival, caption, likes FROM ig_rival_post WHERE likes > 0 AND CHAR_LENGTH(caption) > 8") as $r) {
        $first = trim((string)preg_split('/\R|(?<=[.!?])\s/u', trim((string)$r['caption']))[0]);
        $first = trim(preg_replace('/(\s#\S+)+$/u', '', $first));          // trailing hashtags are not the hook
        $words = preg_split('/\s+/u', $first);
        if (count($words) > 20) $first = implode(' ', array_slice($words, 0, 20));
        if (mb_strlen($first) >= 8) $groups['ig:' . $r['rival']][] = ['hook' => $first, 'v' => (int)$r['likes']];
    }
    $out = [];
    foreach ($groups as $creator => $list) {
        if (count($list) < 20) continue;
        usort($list, fn($a, $b) => $a['v'] <=> $b['v']);
        $n = count($list);
        foreach ($list as $i => $x) {
            $out[] = ['creator' => $creator, 'hook' => $x['hook'], 'label' => round($i / ($n - 1), 4), 'metric' => $x['v']];
        }
    }
    return $out;
}

/** Hooks waiting to be scored: every pending script's current hook + its alternatives. */
function laya_hooks_to_score(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT page_id, hook, hook_candidates FROM video_scripts
                          WHERE video_status = 'pending' AND CHAR_LENGTH(hook) > 5") as $r) {
        $out[] = ['page_id' => (int)$r['page_id'], 'kind' => 'current', 'hook' => trim((string)$r['hook'])];
        foreach ((array)json_decode((string)$r['hook_candidates'], true) as $c) {
            if (is_string($c) && trim($c) !== '') {
                $out[] = ['page_id' => (int)$r['page_id'], 'kind' => 'candidate', 'hook' => trim($c)];
            }
        }
    }
    return $out;
}

function laya_hooks_export(PDO $pdo, string $dir): array {
    laya_hooks_install($pdo);
    $feed = ['generated' => date('c'), 'min_age_days' => LAYA_HOOK_MIN_AGE_DAYS,
             'train' => laya_hooks_rivals($pdo),          // r190b: winning creators teach
             'check' => laya_hooks_training($pdo),        // our own posts: a transfer check only
             'score' => laya_hooks_to_score($pdo)];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // the timestamp is left out of the change check, so an unchanged feed is not re-pushed
    $body = $feed; unset($body['generated']);
    file_put_contents($dir . '/feed.json', json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($dir . '/content.md5', md5(json_encode($body, JSON_UNESCAPED_UNICODE)));
    return ['train' => count($feed['train']), 'check' => count($feed['check']), 'score' => count($feed['score'])];
}

function laya_hooks_log(string $line): void {
    @file_put_contents(__DIR__ . '/laya_hooks.log', date('c') . ' ' . $line . "\n", FILE_APPEND);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
    require __DIR__ . '/db.php';
    $cmd = $argv[1] ?? '';
    $dir = rtrim((string)($argv[2] ?? ''), '/');
    if ($cmd === 'export' && $dir !== '') {
        $n = laya_hooks_export(db(), $dir);
        echo "laya feed: {$n['train']} rival hook(s) to learn from, {$n['check']} of ours to check against, {$n['score']} to score\n";
    } elseif ($cmd === 'ingest' && $dir !== '') {
        require_once __DIR__ . '/laya_hooks_ingest.php';
        echo json_encode(laya_hooks_ingest(db(), $dir)), "\n";
    } else {
        fwrite(STDERR, "usage: php laya_hooks.php export|ingest <dir>\n");
        exit(2);
    }
}
