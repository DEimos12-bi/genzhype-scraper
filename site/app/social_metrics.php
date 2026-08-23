<?php
/* GenZHype | r115 — OUR OWN NUMBERS FROM INSTAGRAM, FACEBOOK AND TIKTOK.
 *
 * WHY THIS EXISTS. The scoreboard could see YouTube (34 reads) and TikTok
 * (22 reads) and was blind on Instagram and Facebook: 10 posts each, ZERO
 * measured. So the intelligence engine was reasoning about a channel whose
 * two newest platforms were invisible, and every rule it wrote came from
 * YouTube — the platform where we average 5 views.
 *
 * WHY BUFFER AND NOT META. The obvious route is Meta's Graph API. Ours is
 * dead: the app behind the stored ig/fb tokens answers
 * "Application has been deleted" (OAuthException 190), so every Graph call
 * we could make is a 400. Buffer already publishes to all three accounts on
 * our behalf, its GraphQL API is on the free plan, and it answers from this
 * server — measured, not assumed. That makes it the only working route to
 * our own numbers that needs nothing from the owner.
 *
 * WHAT IT GETS. Per POST (not just per account): views/impressions,
 * reactions, comments, shares, engagement rate — plus the post text, which
 * is what lets a Buffer post be matched back to the page it came from. That
 * is the whole learning loop for the three platforms that carry us.
 *
 * FAILS QUIET. No token, an API change, a rate limit: it logs and returns 0.
 * Nothing here is on the publishing path, so it can never block a post. */
declare(strict_types=1);

const SM_BUFFER_API = 'https://api.buffer.com';

/** Buffer service name -> the platform code the rest of our system uses. */
const SM_SERVICE_MAP = ['instagram' => 'ig', 'facebook' => 'fb', 'tiktok' => 'tt'];

/** Buffer metric display names -> our columns. Facebook reports Impressions
 *  where the video platforms report Views; both answer "how many saw it", so
 *  they land in the same column and the platform column keeps them apart. */
const SM_METRIC_MAP = [
    'Views'       => 'views',
    'Impressions' => 'views',
    'Reach'       => 'reach',
    'Reactions'   => 'likes',
    'Comments'    => 'comments',
    'Shares'      => 'shares',
];

function sm_log(string $msg): void {
    if (PHP_SAPI === 'cli') { echo $msg . "\n"; }
}

/** One GraphQL round trip. Returns the decoded body, or null on any failure. */
function sm_gql(string $query, array $vars = []): ?array {
    static $token = null;
    if ($token === null) {
        $cfg = $GLOBALS['CONFIG'] ?? null;
        if (!is_array($cfg)) { $cfg = require __DIR__ . '/config.php'; }
        $token = (string)($cfg['social_tokens']['buffer'] ?? '');
    }
    if ($token === '') { return null; }

    $payload = ['query' => $query];
    if ($vars) { $payload['variables'] = $vars; }
    $ch = curl_init(SM_BUFFER_API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token,
                                   'Content-Type: application/json'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw)) { return null; }
    $d = json_decode($raw, true);
    if (!is_array($d)) { return null; }
    if (isset($d['errors'][0]['message'])) {
        sm_log('  buffer API said: ' . substr((string)$d['errors'][0]['message'], 0, 120));
        return null;
    }
    return $d['data'] ?? null;
}

/** The organization every other query needs. Cached per process. */
function sm_org_id(): ?string {
    static $org = false;
    if ($org !== false) { return $org; }
    $d = sm_gql('{ account { organizations { id } } }');
    $org = $d['account']['organizations'][0]['id'] ?? null;
    return $org;
}

/** Connected channels as [buffer_channel_id => platform_code]. */
function sm_channels(): array {
    $org = sm_org_id();
    if (!$org) { return []; }
    $d = sm_gql('query($i:ChannelsInput!){ channels(input:$i){ id service name } }',
                ['i' => ['organizationId' => $org]]);
    $out = [];
    foreach (($d['channels'] ?? []) as $c) {
        $p = SM_SERVICE_MAP[(string)($c['service'] ?? '')] ?? null;
        if ($p) { $out[(string)$c['id']] = $p; }
    }
    return $out;
}

/** Collapse text to letters, digits and single spaces, lowercased.
 *  BOTH sides of the comparison must go through this. Skipping it on the
 *  haystack was a real bug: a title containing "In-Flight" normalised to
 *  "in flight" and was then searched for inside raw text that still said
 *  "in-flight", so every hyphenated or apostrophised story silently failed
 *  to match and its numbers were dropped. */
function sm_norm(string $s): string {
    $s = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? ''));
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/** The first words of a title — enough to recognise our own post inside a
 *  caption without depending on the exact wording the composer used. */
function sm_title_key(string $title): string {
    $w = array_slice(explode(' ', sm_norm($title)), 0, 5);
    return implode(' ', array_filter($w));
}

/** Match one Buffer post back to the platform_videos row it came from.
 *
 *  Two independent signals must agree, because a wrong match writes another
 *  story's numbers onto this page and quietly poisons the learning:
 *    1. same platform, posted within SM_MATCH_HOURS of the Buffer send time
 *    2. the page's title opening actually appears in the post text
 *  Nothing matches -> we skip the post rather than guess.
 *
 *  The window is 36h, not 12h, because Buffer QUEUES: our poster hands a post
 *  over and Buffer publishes it in its own slot, measured up to 14h later.
 *  A tight window threw away real matches. Widening it is safe here because
 *  the title is what actually decides — time only orders the candidates. */
function sm_match_post(PDO $pdo, string $platform, string $sentAt, string $text): ?int {
    // `pages` has no `title` column — the human title is h1 (title_tag is the
    // SEO variant and carries suffixes the caption never repeats).
    $q = $pdo->prepare(
        "SELECT pv.id, p.h1 AS title
           FROM platform_videos pv
           JOIN pages p ON p.id = pv.page_id
          WHERE pv.platform = ?
            AND ABS(TIMESTAMPDIFF(HOUR, pv.posted_at, ?)) <= 36
          ORDER BY ABS(TIMESTAMPDIFF(MINUTE, pv.posted_at, ?)) ASC
          LIMIT 12");
    $q->execute([$platform, $sentAt, $sentAt]);
    $hay = sm_norm($text);
    foreach ($q->fetchAll() as $row) {
        $key = sm_title_key((string)$row['title']);
        if ($key !== '' && str_contains($hay, $key)) { return (int)$row['id']; }
    }
    return null;
}

/** Pull sent posts + their metrics and store them against our own registry.
 *  Returns [matched, stored, seen]. */
function sm_collect(PDO $pdo, int $first = 60): array {
    $org = sm_org_id();
    if (!$org) { sm_log('no buffer org (token missing or dead); nothing collected'); return [0, 0, 0]; }
    $chans = sm_channels();
    if (!$chans) { sm_log('no buffer channels; nothing collected'); return [0, 0, 0]; }
    sm_log('channels: ' . implode(', ', array_unique(array_values($chans))));

    $d = sm_gql(
        'query($i:PostsInput!,$n:Int){ posts(input:$i,first:$n){ edges{ node{
            id channelService status sentAt text metricsUpdatedAt
            metrics{ name value } } } } }',
        ['i' => ['organizationId' => $org, 'filter' => ['status' => ['sent']]],
         'n' => $first]);
    $edges = $d['posts']['edges'] ?? [];
    if (!$edges) { sm_log('buffer returned no sent posts'); return [0, 0, 0]; }

    $seen = 0; $matched = 0; $stored = 0;
    foreach ($edges as $e) {
        $n = $e['node'] ?? [];
        $seen++;
        $platform = SM_SERVICE_MAP[(string)($n['channelService'] ?? '')] ?? null;
        $sentAt = (string)($n['sentAt'] ?? '');
        if (!$platform || $sentAt === '') { continue; }

        // Metrics can be empty while a platform is still computing them
        // (Instagram routinely reports nothing for the first hours). An empty
        // row teaches the engine "this post got zero", which is a lie, so we
        // skip it and pick it up on a later run.
        $vals = [];
        foreach (($n['metrics'] ?? []) as $m) {
            $col = SM_METRIC_MAP[(string)($m['name'] ?? '')] ?? null;
            if ($col) { $vals[$col] = (int)round((float)($m['value'] ?? 0)); }
        }
        if (!$vals || array_sum($vals) === 0) { continue; }

        $sql = date('Y-m-d H:i:s', strtotime($sentAt) ?: time());
        $vid = sm_match_post($pdo, $platform, $sql, (string)($n['text'] ?? ''));
        if ($vid === null) { continue; }
        $matched++;

        // Remember which Buffer post this was, so a later run can tell the
        // registry row apart from a repost of the same story.
        $pdo->prepare("UPDATE platform_videos SET platform_video_id=?, matched_at=UTC_TIMESTAMP()
                        WHERE id=? AND (platform_video_id IS NULL OR platform_video_id='')")
            ->execute([substr((string)($n['id'] ?? ''), 0, 64), $vid]);

        // One reading per post per day: these are cumulative counters, so a
        // second reading in the same day is noise, but the day-over-day
        // series is how a video's life shows up.
        $dup = $pdo->prepare("SELECT COUNT(*) FROM platform_metrics
                               WHERE video_id=? AND DATE(fetched_at)=UTC_DATE()");
        $dup->execute([$vid]);
        if ((int)$dup->fetchColumn() > 0) { continue; }

        $pdo->prepare("INSERT INTO platform_metrics (video_id, views, likes, comments, shares, fetched_at)
                       VALUES (?,?,?,?,?,UTC_TIMESTAMP())")
            ->execute([$vid, $vals['views'] ?? 0, $vals['likes'] ?? 0,
                       $vals['comments'] ?? 0, $vals['shares'] ?? 0]);
        $stored++;
        // THE RECORD (organ 02): the same reading appended to the post's story.
        // Observer only — never let bookkeeping break collection.
        try {
            require_once __DIR__ . '/record.php';
            $pv = $pdo->prepare("SELECT page_id, platform FROM platform_videos WHERE id=?");
            $pv->execute([$vid]);
            if ($pvr = $pv->fetch(PDO::FETCH_ASSOC)) {
                record_touch($pdo, 'post', (int)$pvr['page_id'], (string)$pvr['platform'], 'outcome', [
                    'platform'  => (string)$pvr['platform'],
                    'snapshots' => [[
                        'at' => gmdate('c'),
                        'views' => (int)($vals['views'] ?? 0), 'likes' => (int)($vals['likes'] ?? 0),
                        'comments' => (int)($vals['comments'] ?? 0), 'shares' => (int)($vals['shares'] ?? 0),
                    ]],
                ], ['snapshots']);
            }
        } catch (Throwable $e) { error_log('record outcome hook: ' . $e->getMessage()); }
    }
    sm_log("seen {$seen} sent post(s), matched {$matched}, stored {$stored} new reading(s)");
    return [$matched, $stored, $seen];
}

/** r124 SPIKE WATCHER — the "act today, not Monday" gap, closed with what
 *  already runs: this executes inside the daily pull. A post counting at
 *  least 3x its platform's 30-day average AND >= 150 views is a spike; the
 *  owner gets ONE email per video (spike_alerted_at), because a spike is
 *  exactly the moment a human buyer would double down and Monday is too
 *  late. Detection is arithmetic, not AI — nothing to hallucinate. */
function sm_spike_watch(PDO $pdo): int {
    $alerts = 0;
    try {
        $rows = $pdo->query(
            "SELECT pv.id, pv.platform, p.h1 title,
                    (SELECT m2.views FROM platform_metrics m2
                      WHERE m2.video_id=pv.id ORDER BY m2.fetched_at DESC LIMIT 1) views,
                    (SELECT ROUND(AVG(m3.views)) FROM platform_metrics m3
                      JOIN platform_videos pv3 ON pv3.id=m3.video_id
                      WHERE pv3.platform=pv.platform
                        AND m3.fetched_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) plat_avg
               FROM platform_videos pv
               JOIN pages p ON p.id = pv.page_id
              WHERE pv.spike_alerted_at IS NULL")->fetchAll();
        foreach ($rows as $r) {
            $v = (int)($r['views'] ?? 0);
            $avg = max(1, (int)($r['plat_avg'] ?? 0));
            if ($v < 150 || $v < $avg * 3) { continue; }
            $pdo->prepare("UPDATE platform_videos SET spike_alerted_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([(int)$r['id']]);
            if (function_exists('strategist_notify')) {
                strategist_notify(
                    'SPIKE on ' . strtoupper((string)$r['platform']) . ' — act today',
                    "This post is running " . round($v / $avg, 1) . "x the platform's normal:\n\n"
                    . '  "' . (string)$r['title'] . "\"\n"
                    . '  ' . number_format($v) . " views (platform average: " . number_format($avg) . ")\n\n"
                    . "A human buyer would double down NOW: post the follow-up angle of this story, "
                    . "reply to every comment on it, and consider a small boost while it is hot.\n\n"
                    . "Scoreboard: https://genzhype.com/admin/?tab=scoreboard");
            }
            $alerts++;
        }
    } catch (Throwable $e) { /* alerting must never break collection */ }
    return $alerts;
}

/** r124 RETENTION — the metric that actually rules short-form, from the
 *  official YouTube Analytics API (the token already carries working scopes;
 *  the API just needed enabling in the Google console). Fails quiet until
 *  that switch is flipped, then fills retention_pct / avg_view_s on the
 *  newest yt readings automatically. */
function sm_yt_retention(PDO $pdo): int {
    try {
        $rot = ($GLOBALS['CONFIG'] ?? require __DIR__ . '/config.php')['ai_rotation'] ?? [];
        $tok = json_decode((string)@file_get_contents(__DIR__ . '/yt_token.json'), true);
        if (empty($tok['refresh_token']) || empty($rot['yt_client_id'])) { return 0; }
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => 1, CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $rot['yt_client_id'], 'client_secret' => $rot['yt_client_secret'],
                'refresh_token' => $tok['refresh_token'], 'grant_type' => 'refresh_token'])]);
        $access = json_decode((string)curl_exec($ch), true)['access_token'] ?? null;
        if (!$access) { return 0; }

        $u = 'https://youtubeanalytics.googleapis.com/v2/reports?ids=channel%3D%3DMINE'
           . '&startDate=' . gmdate('Y-m-d', time() - 45 * 86400) . '&endDate=' . gmdate('Y-m-d')
           . '&metrics=views,averageViewDuration,averageViewPercentage'
           . '&dimensions=video&sort=-views&maxResults=50';   // video reports REQUIRE a sort
        $ch2 = curl_init($u);
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access]]);
        $d = json_decode((string)curl_exec($ch2), true);
        $rows = $d['rows'] ?? null;
        if (!$rows) {
            if (isset($d['error'])) {
                sm_log('  yt retention: ' . substr((string)($d['error']['message'] ?? ''), 0, 100));
            }
            return 0;
        }
        // Two plain steps, not one clever UPDATE: the correlated-subquery
        // version was silently rejected by MariaDB and the catch swallowed it,
        // so "0 stored" looked like missing data instead of broken SQL.
        $find = $pdo->prepare(
            "SELECT MAX(m.id) FROM platform_metrics m
               JOIN platform_videos pv ON pv.id = m.video_id
              WHERE pv.platform='yt' AND pv.platform_video_id = ?");
        $upd = $pdo->prepare(
            "UPDATE platform_metrics SET avg_view_s=?, retention_pct=? WHERE id=?");
        $n = 0;
        foreach ($rows as $r) {
            [$vid, , $avgS, $pct] = [$r[0], $r[1], (float)$r[2], (float)$r[3]];
            $find->execute([(string)$vid]);
            $mid = (int)$find->fetchColumn();
            if ($mid > 0) { $upd->execute([$avgS, $pct, $mid]); $n++; }
        }
        sm_log("  yt retention: stored for $n video(s)");
        return $n;
    } catch (Throwable $e) { return 0; }
}

/** Turn the readings into the one rule the engine cannot get anywhere else:
 *  which platform actually pays for the same video. Written to comp_rule so
 *  the admin, the wires and any future consumer all read it the same way. */
function sm_write_platform_yield(PDO $pdo): ?array {
    $rows = $pdo->query(
        "SELECT pv.platform,
                COUNT(DISTINCT pv.id)  AS posts,
                COUNT(m.id)            AS readings,
                ROUND(AVG(m.views))    AS avg_views,
                MAX(m.views)           AS best_views,
                ROUND(AVG(m.likes), 1) AS avg_likes
           FROM platform_videos pv
           JOIN platform_metrics m ON m.video_id = pv.id
          GROUP BY pv.platform
          HAVING readings >= 3")->fetchAll();
    if (count($rows) < 2) { return null; }   // nothing to compare = no rule

    $by = [];
    foreach ($rows as $r) {
        $by[(string)$r['platform']] = [
            'posts'      => (int)$r['posts'],
            'readings'   => (int)$r['readings'],
            'avg_views'  => (int)$r['avg_views'],
            'best_views' => (int)$r['best_views'],
            'avg_likes'  => (float)$r['avg_likes'],
        ];
    }
    uasort($by, fn($a, $b) => $b['avg_views'] <=> $a['avg_views']);
    $best = array_key_first($by);
    $value = ['platforms' => $by, 'best' => $best,
              'measured_at' => gmdate('Y-m-d')];

    // Confidence tracks the evidence: a platform judged on 3 readings is not
    // the same claim as one judged on 30.
    $minReadings = min(array_column($by, 'readings'));
    $conf = $minReadings >= 20 ? 85 : ($minReadings >= 10 ? 75 : 65);

    $pdo->prepare(
        "INSERT INTO comp_rule (scope, rule_key, rule_value, confidence, evidence, version, active, updated_at)
         VALUES ('video','platform_yield',?,?,?,1,1,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value), confidence=VALUES(confidence),
                                 evidence=VALUES(evidence), active=1, updated_at=UTC_TIMESTAMP()")
        ->execute([json_encode($value, JSON_UNESCAPED_SLASHES),
                   $conf, (string)array_sum(array_column($by, 'readings'))]);
    return $value;
}
