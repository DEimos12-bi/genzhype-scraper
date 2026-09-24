<?php
/* GenZHype | CLIP SUPPLY (r140, 2026-09-06) — measured, not claimed.
 *
 * OWNER: "you said it's done multiple times ... this time you really fix and I
 * see some changes." So the supply gets a meter. Every clip attempt on the
 * server writes a row (clip_supply_log), every route is probed daily against a
 * known-good URL (clip_route_probe), and the admin shows planned vs staged per
 * platform for the last 7 days. If the number is bad, it says so.
 *
 * WHAT WAS ACTUALLY WRONG (measured 2026-09-06, all four at once):
 *  1. The planner only looked for clips EMBEDDED INSIDE article HTML, and most
 *     articles refuse this server (403). Tonight's feed: 6 stories, 0 clips.
 *  2. Stories' own sources already hold platform links — 145 stories carry an
 *     X post, ~1 in 4 of those with video — and the planner skipped them.
 *  3. The server could fetch X video all along (syndication CDN, no key) and
 *     some YouTube (android_vr client); only TikTok had a server route.
 *  4. The bridge staged only shot-bound TikTok clips; everything else was left
 *     to the runner's yt-dlp, which YouTube and TikTok both wall off.
 */

function clip_platform(string $url): string {
    $u = mb_strtolower($url);
    if (str_contains($u, 'tiktok.com'))                          return 'tiktok';
    if (preg_match('#//(?:www\.|mobile\.)?(?:x|twitter)\.com/#', $u)) return 'x';
    if (str_contains($u, 'youtube.com') || str_contains($u, 'youtu.be')) return 'youtube';
    if (str_contains($u, 'twitch.tv'))                           return 'twitch';
    if (str_contains($u, 'kick.com'))                            return 'kick';
    if (str_contains($u, 'instagram.com'))                       return 'instagram';
    if (preg_match('#\.(mp4|m4v|mov|webm)(\?|$)#', $u))           return 'file';
    return 'other';
}

/* ------------------------------------------------------------------ r159
 * WHAT COUNTS AS A CLIP (2026-09-11).
 *
 * Measured fetch success per platform, all time: tiktok 56/56, x (syndication)
 * 9/9, direct file fine, YOUTUBE 1/5208 — and that single win was the Rick
 * Astley route probe, never a story clip. So a YouTube URL sitting in
 * footage_clips is not footage, it is a plan for footage that never arrives.
 *
 * Both eligibility tests in clip_replan() counted those walled links as clips:
 * the SELECT only took stories whose footage_clips was EMPTY, and the inner gate
 * asked count($clips) < 2. A story holding two YouTube links therefore looked
 * well supplied and was locked out of clip_hunt_tiktok() — the one hunt aimed at
 * a platform this server can actually download. Measured on 2026-09-11 over the
 * 120 published stories that have a script: 16 clip-poor by the old test, 60 by
 * this one. Page 125 (7 YouTube links, the video shipped 0 footage scenes and 11
 * stills) and page 557 (8 YouTube links) sat in that 44-story gap.
 */
/* ------------------------------------------------------------------ r160
 * TWITCH + KICK JOIN THE SET (2026-09-12). Measured on this server the same
 * night, no credentials of any kind:
 *   twitch  clips.twitch.tv/MushyLaconicHippoTinyFace-...   9.86 MB, seconds
 *   kick    kick.com/xqc/clips/clip_01M2183KJAP6DK975VYQ8X4XAJ  18 MB, 1.9s
 * Both arrive through yt-dlp's extractor (cf_ytdlp), which needs a python
 * module this host already has. Twitch's Helix API in app/twitch_clips.php
 * stays dormant: it wants an app token the owner cannot get (Twitch's 2FA
 * refuses Moroccan numbers) and this route does not need one.
 *
 * ONE list, read by everything. Before r160 the same four hardcoded
 * ['tiktok','x','file'] lists lived in clip_fetchable(), the slice expander and
 * two places in video_bridge.php — so a platform could be "fetchable" for the
 * planner and invisible to the thing that actually stages the file, which is
 * the only step that puts real footage in a video. */
function clip_fetchable_platforms(): array {
    return ['tiktok', 'x', 'file', 'twitch', 'kick'];
}

function clip_fetchable_platform(string $platform): bool {
    return in_array($platform, clip_fetchable_platforms(), true);
}

function clip_fetchable(string $url): bool {
    $base = explode('#t=', $url)[0];
    $plat = clip_platform($base);
    if (!clip_fetchable_platform($plat)) return false;
    // r160b: twitch and kick are fetchable only in CLIP shape. A channel page or
    // an /embed URL matches the HOST but the downloader refuses it, so counting
    // it as supply repeats the lie the YouTube links told before r159. Same
    // shapes the fetcher itself accepts (cf_ytdlp_target in clip_fetch.php).
    if ($plat === 'twitch' || $plat === 'kick') {
        return (bool)preg_match('#^https?://(?:www\.)?clips\.twitch\.tv/[A-Za-z0-9_-]{6,}#i', $base)
            || (bool)preg_match('#^https?://(?:www\.|m\.)?twitch\.tv/[^/]+/clip/[A-Za-z0-9_-]{6,}#i', $base)
            || (bool)preg_match('#^https?://(?:www\.)?kick\.com/[^/]+/clips/clip_[A-Za-z0-9]{6,}#i', $base);
    }
    return true;
}

/* How many DIFFERENT clips on this story the server can fetch. A slice (#t=NN)
 * of a clip already counted is the same footage, so it counts once. Accepts the
 * raw footage_clips JSON or a decoded array. */
function clip_fetchable_count($clips): int {
    if (!is_array($clips)) $clips = (array)json_decode((string)($clips ?: '[]'), true);
    $seen = [];
    foreach ($clips as $c) {
        $u = is_array($c) ? (string)($c['url'] ?? '') : (string)$c;
        if ($u === '') continue;
        $base = explode('#t=', $u)[0];
        // r159b: the same clip stored under two URLs (one carrying a query
        // string) was counted as two, so a story could look supplied on one clip.
        $key  = rtrim(strtok($base, '?'), '/');
        if (isset($seen[$key]) || !clip_fetchable($base)) continue;
        $seen[$key] = 1;
    }
    return count($seen);
}

/* Order clips so the ones the SERVER can actually deliver come first. The
 * maker tries the hook on the first three clips in the list and stops; on
 * 2026-09-06 page 33 listed three walled YouTube links first, tried exactly
 * those, and opened on a still while four staged TikToks sat unused.
 *
 * r160, the owner's rule in his words: "dont priortise none... all be the same
 * and denpending on from we got the best ones tht fit the vieos". So the rank
 * is now a two-value answer to one question — can this server fetch it? Every
 * fetchable platform ties at 0 and the old tiebreaks decide inside that tie:
 * the reporter's timestamped clip first, a hunted one after, then the order the
 * list already had. No platform is ahead of another by prejudice; only a walled
 * one is behind, and that is measurement, not preference. */
function clip_supply_sort(array $clips): array {
    $rank = ['instagram' => 1, 'youtube' => 2, 'other' => 3];   // fetchable = 0, see below
    $i = 0;
    foreach ($clips as &$c) { $c['__i'] = $i++; }
    unset($c);
    usort($clips, function ($a, $b) use ($rank) {
        $ka = (string)($a['platform'] ?? clip_platform((string)($a['url'] ?? '')));
        $kb = (string)($b['platform'] ?? clip_platform((string)($b['url'] ?? '')));
        $pa = clip_fetchable_platform($ka) ? 0 : ($rank[$ka] ?? 3);
        $pb = clip_fetchable_platform($kb) ? 0 : ($rank[$kb] ?? 3);
        // a reporter-embedded clip with a timestamp still outranks a hunted one inside the same platform
        $ea = (!empty($a['start']) ? 0 : 1) + (!empty($a['hunted']) ? 1 : 0);
        $eb = (!empty($b['start']) ? 0 : 1) + (!empty($b['hunted']) ? 1 : 0);
        return [$pa, $ea, $a['__i']] <=> [$pb, $eb, $b['__i']];
    });
    foreach ($clips as &$c) { unset($c['__i']); }
    unset($c);
    return $clips;
}

/* r148 (2026-09-09) MORE BEATS THAN CLIPS. Measured on p179: 15 beats, 4 clips,
 * 3 beats got footage and the maker froze one clip into a photo it then showed
 * three times. Rather than invent pictures, cut a second and third window out of
 * the clips we already hold. Only platforms this server can actually cut are
 * expanded; a slice that turns out to be past the end of its source simply fails
 * to stage and that beat falls back exactly as it does today. */
function clip_supply_expand_slices(array $clips, int $beats, array $offsets = [12, 24]): array {
    $out = $clips;
    if (!$clips || count($clips) >= $beats) return $out;
    foreach ($clips as $c) {
        $u = (string)($c['url'] ?? '');
        if ($u === '' || str_contains($u, '#t=')) continue;
        $plat = (string)($c['platform'] ?? clip_platform($u));
        if (!clip_fetchable_platform($plat)) continue;   // server-cuttable only (r160: twitch + kick too)
        foreach ($offsets as $off) {
            if (count($out) >= $beats) break 2;
            $s = $c;
            $s['url'] = $u . '#t=' . (int)$off;
            $s['start'] = 0;
            $s['slice_of'] = $u;
            $out[] = $s;
        }
    }
    return $out;
}

function clip_supply_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS clip_supply_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        url VARCHAR(500) NOT NULL,
        platform VARCHAR(16) NOT NULL,
        route VARCHAR(40) NOT NULL,
        ok TINYINT(1) NOT NULL,
        bytes INT NOT NULL DEFAULT 0,
        ms INT NOT NULL DEFAULT 0,
        error VARCHAR(200) NOT NULL DEFAULT '',
        KEY idx_at (at), KEY idx_plat (platform, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clip_route_probe (
        route VARCHAR(40) PRIMARY KEY,
        ok TINYINT(1) NOT NULL,
        detail VARCHAR(200) NOT NULL DEFAULT '',
        ms INT NOT NULL DEFAULT 0,
        probed_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clip_stage_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        page_id INT UNSIGNED NOT NULL,
        platform VARCHAR(16) NOT NULL,
        planned INT NOT NULL DEFAULT 0,
        staged INT NOT NULL DEFAULT 0,
        mb INT NOT NULL DEFAULT 0,
        KEY idx_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE video_scripts ADD COLUMN clips_replanned_at DATETIME NULL"); } catch (Throwable $e) { /* exists */ }
}

/* Daily route doctor. Known-good URLs, one per route; a route that fails here
 * is a route that is down, not a story with no video. */
function clip_probe_routes(PDO $pdo): array {
    require_once __DIR__ . '/clip_fetch.php';
    clip_supply_install($pdo);
    $tests = [
        'tiktok'  => 'https://www.tiktok.com/@boughta/video/7674476288685493535',
        'x'       => 'https://x.com/turtlesoupy/status/2095885429828382955',
        // an ORDINARY video, on purpose: the famous one (dQw4w9WgXcQ) passes the
        // bot wall while every real story video answered "Sign in to confirm
        // you're not a bot" (measured 2026-09-06, 3/3). A green light on the
        // famous one would be a lie.
        'youtube' => 'https://www.youtube.com/watch?v=jZgG4CvcZso',
        // r160: the two routes that came in with no credentials. Both were
        // downloaded by hand from this server on 2026-09-12 before being added.
        'twitch'  => 'https://clips.twitch.tv/MushyLaconicHippoTinyFace-LAtVkyDMxwBMzBj6',
        'kick'    => 'https://kick.com/xqc/clips/clip_01M2183KJAP6DK975VYQ8X4XAJ',
    ];
    $out = [];
    foreach ($tests as $route => $url) {
        $t0 = microtime(true);
        $p = cf_path($url);
        @unlink($p);                                   // force a real fetch
        $path = null; $err = '';
        // r150: bypass the dead-letter memory here. This probe exists precisely to
        // find out whether a route is up today; reading our own past failures back
        // to ourselves would turn the route doctor into an echo.
        $GLOBALS['__cf_force'] = true;
        try { $path = cf_fetch($url, 0); } catch (Throwable $e) { $err = $e->getMessage(); }
        $GLOBALS['__cf_force'] = false;
        $ok = $path && is_file($path) && filesize($path) > 100000;
        $detail = $ok ? round(filesize($path) / 1048576, 1) . ' MB' : ($err ?: 'no file');
        $pdo->prepare("INSERT INTO clip_route_probe (route, ok, detail, ms, probed_at) VALUES (?,?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE ok=VALUES(ok), detail=VALUES(detail), ms=VALUES(ms), probed_at=NOW()")
            ->execute([$route, $ok ? 1 : 0, mb_substr($detail, 0, 200), (int)((microtime(true) - $t0) * 1000)]);
        $out[$route] = $ok;
    }
    // Instagram: session-gated; report the session state honestly
    try {
        require_once __DIR__ . '/reach_ig.php';
        $ig = ig_available();
        $pdo->prepare("INSERT INTO clip_route_probe (route, ok, detail, ms, probed_at) VALUES ('instagram',?,?,0,NOW())
                       ON DUPLICATE KEY UPDATE ok=VALUES(ok), detail=VALUES(detail), probed_at=NOW()")
            ->execute([$ig ? 1 : 0, $ig ? 'session file present (media calls answered 404 on 2026-09-06: re-export cookies)' : 'no session cookies']);
    } catch (Throwable $e) {}
    return $out;
}

/* The meter: last 7 days, per platform. */
function clip_supply_stats(PDO $pdo, int $days = 7): array {
    clip_supply_install($pdo);
    $out = ['fetch' => [], 'stage' => [], 'probe' => []];
    foreach ($pdo->query("SELECT platform, COUNT(*) tries, SUM(ok) ok, ROUND(SUM(bytes)/1048576) mb, MAX(at) last
                          FROM clip_supply_log WHERE at >= NOW() - INTERVAL $days DAY GROUP BY platform ORDER BY tries DESC") as $r) $out['fetch'][] = $r;
    // one row per story per day (the bridge runs every 2h and re-stages the same clips;
    // summing every run counted the same 16 clips twelve times — measured 2026-09-06)
    foreach ($pdo->query("SELECT platform, SUM(planned) planned, SUM(staged) staged, SUM(mb) mb, COUNT(DISTINCT page_id) stories FROM (
                            SELECT platform, page_id, DATE(at) d, MAX(planned) planned, MAX(staged) staged, MAX(mb) mb
                            FROM clip_stage_log WHERE at >= NOW() - INTERVAL $days DAY GROUP BY platform, page_id, DATE(at)) x
                          GROUP BY platform ORDER BY planned DESC") as $r) $out['stage'][] = $r;
    foreach ($pdo->query("SELECT * FROM clip_route_probe ORDER BY route") as $r) $out['probe'][] = $r;
    $out['last_errors'] = $pdo->query("SELECT at, platform, route, LEFT(error,120) error, LEFT(url,80) url FROM clip_supply_log WHERE ok=0 AND at >= NOW() - INTERVAL $days DAY ORDER BY at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

/* r159 MERGE, NEVER REPLACE. footage_clips_gather() OVERWRITES footage_clips
 * with whatever it can re-derive from the sources today. Under the old
 * empty-only rule there was never anything to lose; this rule also re-plans
 * stories that already hold one good clip, and an article that answers 403 this
 * week would silently delete a TikTok we already own. Same records, same format,
 * deduped by URL, sorted deliverable-first and capped so the list cannot grow
 * without end (the walled tail is what the cap drops). */
function clip_replan_merge(array $before, array $after, int $cap = 12): array {
    $out = []; $seen = [];
    foreach (array_merge($before, $after) as $c) {
        if (!is_array($c)) continue;
        $u = (string)($c['url'] ?? '');
        if ($u === '' || isset($seen[$u])) continue;   // the older record wins: it carries the reporter's start time
        $seen[$u] = 1;
        $out[] = $c;
    }
    return array_slice(clip_supply_sort($out), 0, $cap);
}

/* Re-plan clips for stories the server cannot currently deliver footage for (the
 * r140 planner reads the story's own sources, then hunts TikTok).
 *
 * Bounded per tick by BOTH a row count and a wall clock: one gather can spend a
 * minute on article HTTP, so raising the row limit needs a hard stop, not a
 * hope. Returns [checked, planned, gained]. */
function clip_replan(PDO $pdo, int $limit = 3, int $maxSeconds = 240): array {
    require_once __DIR__ . '/fetch_sources.php';
    $t0 = time();
    /* ELIGIBILITY (r159). SQL cannot count fetchable platforms, so SQL does the
     * cheap half — status, the SHORT cooldown, and an order that rotates the
     * queue — and PHP counts. The pool is bounded (limit x 25, max 300 rows) so
     * this stays one indexed read however high the limit goes.
     *
     * COOLDOWN, two speeds. A story that STILL holds no fetchable clip after an
     * attempt was a miss and comes back in 12 hours; one that gained a clip (from
     * its sources or from the hunt) waits the full 7 days. The old code stamped
     * NOW() even when the hunt found nothing, so a single bad night locked a
     * story out for a week: page 114 was stamped 2026-09-06 01:01 holding two
     * YouTube links and nothing had looked at it since.
     *
     * ORDER: pending before ready, never-tried before tried, then longest-
     * waiting. Newest-first would let the same few heads of the queue eat every
     * tick now that a 12-hour retry exists. */
    $pool = min(300, max(50, $limit * 25));
    $rows = $pdo->query("SELECT v.page_id, d.id did, v.footage_clips, v.clips_replanned_at
                         FROM video_scripts v JOIN dramas d ON d.page_id=v.page_id
                         WHERE v.video_status IN ('pending','ready')
                           AND (v.clips_replanned_at IS NULL OR v.clips_replanned_at < NOW() - INTERVAL 12 HOUR)
                         ORDER BY v.video_status='pending' DESC, (v.clips_replanned_at IS NULL) DESC,
                                  v.clips_replanned_at ASC, v.created_at DESC
                         LIMIT " . (int)$pool)->fetchAll(PDO::FETCH_ASSOC);
    $checked = 0; $planned = 0; $gained = 0; $rebound = 0;
    foreach ($rows as $r) {
        if ($checked >= $limit || time() - $t0 > $maxSeconds) break;
        $before = (array)json_decode((string)($r['footage_clips'] ?: '[]'), true);
        $have   = clip_fetchable_count($before);
        if ($have >= 2) {                                             // not clip-poor: the server can already shoot this one
            // r159b: stamp the attempt, or this row keeps sorting to the head of
            // the queue (ORDER BY clips_replanned_at ASC) and is re-read every tick.
            $pdo->prepare("UPDATE video_scripts SET clips_replanned_at=NOW() WHERE page_id=?")
                ->execute([(int)$r['page_id']]);
            continue;
        }
        if ($have >= 1 && $r['clips_replanned_at'] !== null
            && strtotime((string)$r['clips_replanned_at']) > time() - 7 * 86400) continue;   // last attempt found something: full cooldown
        $checked++;
        $clips = [];
        try {
            $clips = footage_clips_gather((int)$r['did']);
            // still nothing the SERVER can fetch? HUNT: most stories come from
            // articles that carry no platform link at all (5 of 6 in the
            // 2026-09-06 feed), and the ones that do carry mostly YouTube. The
            // hunt searches TikTok by topic and the server downloads what fits.
            if (clip_fetchable_count($clips) < 2) $clips = clip_hunt_clips($pdo, (int)$r['did'], 4);
        } catch (Throwable $e) { error_log('clip_replan: ' . $e->getMessage()); }
        $clips = clip_replan_merge($before, (array)$clips);
        $now = clip_fetchable_count($clips);
        $planned += $now;
        if ($now > $have) $gained++;
        $pdo->prepare("UPDATE video_scripts SET footage_clips=?, clips_replanned_at=NOW() WHERE page_id=?")
            ->execute([json_encode($clips, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$r['page_id']]);
        // r145 RE-BIND. A shot plan written before the clips arrived points at no
        // clip (page 33: 7 clips listed, 0 footage scenes, judge rejected it for
        // repetition). A PENDING timeline script that just gained clips gets its
        // plan rewritten once, so the beats can join the clips. One per tick.
        // r159: only when it gained footage the server can FETCH — rewriting a
        // plan around fresh YouTube links buys nothing and costs tick time.
        if ($now > $have && $rebound < 1) {
            try {
                $v = $pdo->query("SELECT video_status, tpl, shotlist FROM video_scripts WHERE page_id=" . (int)$r['page_id'])->fetch(PDO::FETCH_ASSOC);
                $hasClipShot = $v && str_contains((string)$v['shotlist'], 'clip_url');
                if ($v && $v['video_status'] === 'pending' && (int)$v['tpl'] >= 3 && !$hasClipShot) {
                    require_once __DIR__ . '/video_factory.php';
                    $pdo = db_alive();
                    if (video_write_timeline_script($pdo, (int)$r['page_id'])) { $rebound++; error_log("clip_replan: page {$r['page_id']} shot plan rewritten to bind " . count($clips) . " clip(s)"); }
                    $pdo = db_alive();
                }
            } catch (Throwable $e) { error_log('clip_replan rebind: ' . $e->getMessage()); }
        }
    }
    return [$checked, $planned, $gained];
}

/* ------------------------------------------------------------------ r160
 * THE LEGS. One search per platform this server can actually download from,
 * and the list is derived from clip_fetchable_platforms() so a platform can
 * never be hunted for and then be unstageable, or be stageable and never
 * hunted for. Each leg is [search query, the URL shape a real clip has].
 *
 * MEASURED 2026-09-12 from this server:
 *   site:clips.twitch.tv asmongold  -> 8/8 real clip pages
 *   site:kick.com <channel> clips   -> individual clip_… pages among the hits
 * The X leg was probed on 2026-09-06: "site:x.com <topic>" returned 0 URLs on
 * three topics because Exa does not index posts. X stays a source we READ out
 * of a story's own links (cf_resolve_x), never one we search. */
function clip_hunt_legs(string $q): array {
    $legs = [
        'tiktok' => ['site:tiktok.com ' . $q,      '#^https?://(?:www\.)?tiktok\.com/@[^/]+/video/\d+#i'],
        'twitch' => ['site:clips.twitch.tv ' . $q, '#^https?://(?:(?:www\.)?clips\.twitch\.tv/[A-Za-z0-9_-]{6,}|(?:www\.)?twitch\.tv/[^/]+/clip/[A-Za-z0-9_-]{6,})#i'],
        'kick'   => ['site:kick.com clips ' . $q,  '#^https?://(?:www\.)?kick\.com/[^/]+/clips/clip_[A-Za-z0-9]{6,}#i'],
    ];
    foreach (array_keys($legs) as $plat) if (!clip_fetchable_platform($plat)) unset($legs[$plat]);
    return $legs;
}

/* THE HUNT (r140, all platforms r160). Exa's keyless search honours
 * "site:<platform> <topic>" (measured 2026-09-06: 6 of 8 hits were real TikTok
 * videos for the Skibidi Toilet story, 4 of 8 for "pretty privilege") and this
 * server can download what it finds. So a story with no platform link in its
 * sources gets one anyway. Relevance: a hit must share a distinctive word with
 * the story's title or people, and the runner's own footage_is_relevant judge
 * still screens the file before it plays. Hunted clips are marked so they never
 * outrank a reporter-embedded one.
 *
 * NO PLATFORM ORDER. Every leg runs and keeps up to $max of its own; the
 * results are then INTERLEAVED one per leg before the cap is applied. The old
 * loop stopped the moment $max was full, so whichever leg ran first ate every
 * slot — a ranking by accident of code order, which is exactly what the owner
 * said not to do. Which clip actually plays is still decided per story by fit
 * (clip_supply_sort, then the maker's own judge), not by platform. */
function clip_hunt_clips(PDO $pdo, int $dramaId, int $max = 4, int $maxSeconds = 90): array {
    require_once __DIR__ . '/reach.php';
    require_once __DIR__ . '/fetch_sources.php';
    $m = $pdo->query("SELECT p.id pid, p.h1, d.people_json, v.footage_clips FROM dramas d JOIN pages p ON p.id=d.page_id
                      LEFT JOIN video_scripts v ON v.page_id=p.id WHERE d.id=" . (int)$dramaId)->fetch(PDO::FETCH_ASSOC);
    if (!$m) return [];
    $existing = (array)json_decode((string)($m['footage_clips'] ?: '[]'), true);
    $people = [];
    foreach ((array)json_decode((string)$m['people_json'], true) as $p) { $n = is_array($p) ? (string)($p['name'] ?? '') : (string)$p; if ($n !== '') $people[] = $n; }
    $title = trim(preg_replace('/^(what does|the)\s+|[\'"‘’“”?]|\s+(mean|explained)(\s+in\s+\w+)?$/iu', ' ', (string)$m['h1']));
    $title = trim(preg_replace('/\s+/', ' ', $title));
    $q = trim(implode(' ', array_slice($people, 0, 2)) . ' ' . $title);
    if ($q === '') return $existing;
    // distinctive words the hit must echo (people names count double)
    $need = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) as $w) if (mb_strlen($w) >= 5) $need[$w] = 1;
    $names = [];   // r160b: a name is worth more than any other word
    foreach ($people as $p) foreach (preg_split('/\s+/', mb_strtolower($p)) as $w) if (mb_strlen($w) >= 3) { $need[$w] = 1; $names[$w] = 1; }
    $seen = []; foreach ($existing as $c) if (!empty($c['url'])) $seen[$c['url']] = 1;
    // r143 THE BRAIN: how strict the match must be is a lever (1 loose, 2 strict)
    // r160b: ONE word let a random Twitch clip titled "jackeh apologizes" bind
    // to the Vinicius Junior story on the single word "apologizes" (measured
    // 2026-09-12), and it displaced two real TikToks of the person the story is
    // about. Two distinctive words is the floor now; the lever can only tighten.
    $needHits = 2;
    try { require_once __DIR__ . '/brain.php'; $needHits = max(2, (int)round(brain_lever('hunt_relevance_words', 1.0))); } catch (Throwable $e) {}

    $t0 = time();
    $perLeg = []; $log = [];
    foreach (clip_hunt_legs($q) as $plat => [$query, $shape]) {
        if (time() - $t0 > $maxSeconds) { $log[] = $plat . '=skipped(time)'; continue; }
        $hits = reach_exa_search($query, 8);
        $kept = [];
        foreach ($hits as $h) {
            if (count($kept) >= $max) break;                 // per-leg cap; the real cap is applied after interleaving
            if (!preg_match($shape, (string)$h['url'], $um)) continue;
            $u = $um[0];                                     // canonical clip URL, query string dropped
            if (isset($seen[$u])) continue;
            $hay = mb_strtolower($h['title'] . ' ' . $h['text']);
            $hit = 0; $nm = 0;
            foreach ($need as $w => $_) if (str_contains($hay, $w)) { $hit++; if (isset($names[$w])) $nm++; }
            // r160b: measured on four clip-poor stories, a clip whose title carries
            // the person's name is on topic far more reliably than two generic
            // words, and it finds more of them (6,6,5,2 vs 2,12,7,1). So either
            // gate opens the door, and a name ranks the clip above word matches.
            if ($hit < $needHits && $nm < 1) continue;
            $seen[$u] = 1;
            $kept[] = ['platform' => $plat, 'url' => $u, 'embed' => false, 'start' => 0,
                       'author' => fs_clip_author($u), 'src' => 'hunt:exa', 'hunted' => true, 'hits' => $hit + 2 * $nm,
                       'title' => mb_substr((string)$h['title'], 0, 120), 'published' => (string)$h['published']];
        }
        if ($kept) $perLeg[$plat] = $kept;
        $log[] = sprintf('%s=%d/%d', $plat, count($kept), count($hits));
    }
    // r160b RANK BEFORE THE CAP. Interleaving alone made the cap platform-neutral
    // but blind: a one-word match could take a slot from a clip of the actual
    // person. Strength of match decides first, the round robin breaks ties, so
    // no platform is ahead by prejudice and the best fit still wins.
    $byHits = [];
    foreach ($perLeg as $plat => $kept) foreach ($kept as $k) $byHits[(int)$k['hits']][$plat][] = $k;
    krsort($byHits);
    $found = [];
    foreach ($byHits as $group) {
        for ($i = 0; count($found) < $max; $i++) {
            $took = false;
            foreach ($group as $kept) {
                if (!isset($kept[$i])) continue;
                $found[] = $kept[$i];
                $took = true;
                if (count($found) >= $max) break;
            }
            if (!$took) break;
        }
        if (count($found) >= $max) break;
    }
    foreach ($found as &$__f) unset($__f['hits']);
    unset($__f);
    error_log(sprintf('clip_hunt: page %d "%s" legs[%s] kept %d of %d candidate(s)',
        (int)$m['pid'], $q, implode(' ', $log), count($found), array_sum(array_map('count', $perLeg))));
    if (!$found) return $existing;
    $all = clip_supply_sort(array_merge($existing, $found));
    $pdo->prepare("UPDATE video_scripts SET footage_clips=? WHERE page_id=?")->execute([json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$m['pid']]);
    $by = [];
    foreach ($found as $f) $by[$f['platform']] = ($by[$f['platform']] ?? 0) + 1;
    $parts = []; foreach ($by as $p => $n) $parts[] = "$n $p";
    error_log("clip_hunt: page {$m['pid']} +" . count($found) . ' hunted clip(s) (' . implode(', ', $parts) . ") for \"$q\"");
    return $all;
}

/* r160: the hunt is no longer TikTok-only, but the old name still answers so
 * every existing call site keeps working unchanged (cli.php "clips hunt"). */
function clip_hunt_tiktok(PDO $pdo, int $dramaId, int $max = 4): array {
    return clip_hunt_clips($pdo, $dramaId, $max);
}
