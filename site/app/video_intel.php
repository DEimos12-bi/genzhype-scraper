<?php
/**
 * VIDEO INTELLIGENCE (r77) — the outward-facing arm of the Competitor
 * Intelligence Engine.
 *
 * The engine that already exists watches rival WEBSITES and learned 19 rules
 * about word counts, schema and backlinks. Not one of them is about video, and
 * nothing it learns reaches the video maker. This adds that arm.
 *
 * WHY IT LOOKS AT OUTLIERS, NOT VIEW COUNTS
 * A big channel's video always out-views a small channel's. Raw views measure
 * the channel, not the choice. What carries information is a video beating ITS
 * OWN channel's median — that is the audience saying "this one, more than your
 * usual". So every rival gets a personal baseline and we study only what
 * clears it.
 *
 * WHAT IT COSTS
 * playlistItems + videos.list are 1 quota unit per call, 50 ids per call, out
 * of 10,000/day. Watching a dozen channels daily costs single digits. It never
 * calls search.list, which is the endpoint capped at 100/day.
 *
 * WHAT IT PRODUCES
 * comp_rule rows with scope='video', each carrying its evidence and a sample
 * size, versioned and de-activatable exactly like the website rules — so a
 * rule that stops being true can be retired instead of silently rotting.
 */
declare(strict_types=1);

const VI_SHORT_MAX_S   = 180;   // YouTube's own Shorts ceiling
const VI_OUTLIER_MULT  = 2.5;   // beats its channel's median by this much
const VI_MIN_SAMPLE    = 12;    // never write a rule from less than this
const VI_MIN_RIVAL_SUBS = 50000; // below this a handle is an impostor, not a rival

function vi_access_token(): string {
    $cfg = $GLOBALS['CONFIG'];
    $tokFile = __DIR__ . '/yt_token.json';
    $t = json_decode((string)@file_get_contents($tokFile), true);
    if (empty($t['refresh_token'])) return '';
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $cfg['ai_rotation']['yt_client_id'] ?? '',
            'client_secret' => $cfg['ai_rotation']['yt_client_secret'] ?? '',
            'refresh_token' => $t['refresh_token'],
            'grant_type' => 'refresh_token'])]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return (string)($r['access_token'] ?? '');
}

function vi_api(string $path, array $params, string $at): array {
    $ch = curl_init('https://www.googleapis.com/youtube/v3/' . $path . '?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $at]]);
    $d = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return is_array($d) ? $d : [];
}

/** Add a rival by @handle. Returns [ok, message]. */
function vi_add_rival(PDO $pdo, string $handle, string $at): array {
    $handle = ltrim(trim($handle), '@');
    $d = vi_api('channels', ['part' => 'snippet,contentDetails,statistics',
                             'forHandle' => $handle], $at);
    $i = $d['items'][0] ?? null;
    if (!$i) return [false, "no channel for @$handle"];
    // Handle-squatting is common: @CultureCrave resolved to a 4-subscriber
    // impostor, @PopBase to a 1-subscriber account. Learning "what wins" from
    // a dead channel would poison every rule, and the median-baseline maths
    // would happily produce confident nonsense. A rival must have a real
    // audience before it is allowed to teach us anything.
    $subs = (int)($i['statistics']['subscriberCount'] ?? 0);
    if ($subs < VI_MIN_RIVAL_SUBS) {
        return [false, sprintf('@%s has only %s subs — impostor/dead handle, refused',
                               $handle, number_format($subs))];
    }
    $pdo->prepare("INSERT INTO vid_rival (handle, channel_id, uploads_pl, title, subs, active, added_at)
                   VALUES (?,?,?,?,?,1,UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE handle=VALUES(handle), title=VALUES(title),
                                           subs=VALUES(subs), active=1")
        ->execute([$handle, $i['id'], $i['contentDetails']['relatedPlaylists']['uploads'],
                   $i['snippet']['title'] ?? $handle, (int)($i['statistics']['subscriberCount'] ?? 0)]);
    return [true, sprintf('%s (%s subs)', $i['snippet']['title'] ?? $handle,
                          number_format((int)($i['statistics']['subscriberCount'] ?? 0)))];
}

function vi_iso8601_seconds(string $iso): int {
    if (!preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m)) return 0;
    return ((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + (int)($m[3] ?? 0);
}

/** Pull each rival's latest uploads and their stats. Returns rows written. */
function vi_sync(PDO $pdo, string $at, int $per_channel = 25): int {
    $written = 0;
    $ins = $pdo->prepare(
        "INSERT INTO vid_rival_post (rival_id, video_id, title, published_at, duration_s,
                                     is_short, views, likes, comments, fetched_at)
         VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE views=VALUES(views), likes=VALUES(likes),
                                 comments=VALUES(comments), fetched_at=UTC_TIMESTAMP()");
    foreach ($pdo->query("SELECT id, uploads_pl FROM vid_rival WHERE active=1") as $riv) {
        $pl = vi_api('playlistItems', ['part' => 'contentDetails',
            'playlistId' => $riv['uploads_pl'], 'maxResults' => min(50, $per_channel)], $at);
        $ids = [];
        foreach (($pl['items'] ?? []) as $it) {
            $v = $it['contentDetails']['videoId'] ?? '';
            if ($v) $ids[] = $v;
        }
        if (!$ids) continue;
        foreach (array_chunk($ids, 50) as $chunk) {
            $vd = vi_api('videos', ['part' => 'snippet,statistics,contentDetails',
                                    'id' => implode(',', $chunk)], $at);
            foreach (($vd['items'] ?? []) as $v) {
                $dur = vi_iso8601_seconds($v['contentDetails']['duration'] ?? '');
                $ins->execute([
                    (int)$riv['id'], $v['id'],
                    mb_substr((string)($v['snippet']['title'] ?? ''), 0, 250),
                    gmdate('Y-m-d H:i:s', strtotime((string)($v['snippet']['publishedAt'] ?? 'now'))),
                    $dur, ($dur > 0 && $dur <= VI_SHORT_MAX_S) ? 1 : 0,
                    (int)($v['statistics']['viewCount'] ?? 0),
                    (int)($v['statistics']['likeCount'] ?? 0),
                    (int)($v['statistics']['commentCount'] ?? 0)]);
                $written++;
            }
        }
    }
    return $written;
}

/** Deterministic shape features of a title — no AI, so no quota, no drift. */
function vi_title_features(string $t): array {
    $words = preg_split('/\s+/', trim($t)) ?: [];
    $firstThree = implode(' ', array_slice($words, 0, 3));
    return [
        'chars'       => mb_strlen($t),
        'words'       => count($words),
        'has_number'  => (int)(bool)preg_match('/\d/', $t),
        'has_quote'   => (int)(bool)preg_match('/["\x{201C}\x{201D}\x{2018}\x{2019}\']/u', $t),
        'has_question'=> (int)(bool)str_contains($t, '?'),
        'has_caps'    => (int)(bool)preg_match('/\b[A-Z]{3,}\b/', $t),
        'name_first'  => (int)(bool)preg_match('/^[A-Z][\w\x27]+(\s+[A-Z][\w\x27]+)?/', $firstThree),
        'colon'       => (int)(bool)str_contains($t, ':'),
    ];
}

function vi_median(array $xs): float {
    if (!$xs) return 0.0;
    sort($xs);
    $n = count($xs);
    return $n % 2 ? (float)$xs[intdiv($n, 2)]
                  : ((float)$xs[$n / 2 - 1] + (float)$xs[$n / 2]) / 2.0;
}

function vi_put_rule(PDO $pdo, string $key, array $value, int $confidence, string $evidence): void {
    $cur = $pdo->prepare("SELECT id, version FROM comp_rule WHERE scope='video' AND rule_key=?");
    $cur->execute([$key]);
    $row = $cur->fetch();
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($row) {
        $pdo->prepare("UPDATE comp_rule SET rule_value=?, confidence=?, evidence=?,
                       version=version+1, active=1, updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$json, $confidence, $evidence, (int)$row['id']]);
    } else {
        $pdo->prepare("INSERT INTO comp_rule (scope, rule_key, rule_value, confidence,
                       evidence, version, active, updated_at)
                       VALUES ('video',?,?,?,?,1,1,UTC_TIMESTAMP())")
            ->execute([$key, $json, $confidence, $evidence]);
    }
}

/**
 * Study the outliers and write rules. Everything here is measured; where the
 * sample is too small to mean anything we write NOTHING rather than a
 * confident-looking guess.
 */
function vi_learn(PDO $pdo): array {
    $out = ['rivals' => 0, 'shorts' => 0, 'outliers' => 0, 'rules' => 0, 'skipped' => []];
    $outliers = []; $baseline = [];
    foreach ($pdo->query("SELECT id, handle FROM vid_rival WHERE active=1") as $riv) {
        $rows = $pdo->prepare("SELECT title, views, duration_s, published_at
                               FROM vid_rival_post WHERE rival_id=? AND is_short=1");
        $rows->execute([(int)$riv['id']]);
        $posts = $rows->fetchAll();
        if (count($posts) < 8) { $out['skipped'][] = $riv['handle'] . ' (thin)'; continue; }
        $out['rivals']++;
        $med = vi_median(array_map(fn($p) => (int)$p['views'], $posts));
        if ($med <= 0) continue;
        foreach ($posts as $p) {
            $out['shorts']++;
            $rec = $p + ['handle' => $riv['handle'], 'ratio' => (int)$p['views'] / $med];
            $baseline[] = $rec;
            if ((int)$p['views'] >= $med * VI_OUTLIER_MULT) $outliers[] = $rec;
        }
    }
    $out['outliers'] = count($outliers);
    if (count($outliers) < VI_MIN_SAMPLE) {
        $out['skipped'][] = sprintf('no rules written: %d outliers, need %d',
                                    count($outliers), VI_MIN_SAMPLE);
        return $out;
    }

    /* 1. title shape: what do over-performing titles do that ordinary ones don't? */
    $agg = function (array $set): array {
        $keys = ['chars', 'words', 'has_number', 'has_quote', 'has_question',
                 'has_caps', 'name_first', 'colon'];
        $acc = array_fill_keys($keys, []);
        foreach ($set as $r) {
            foreach (vi_title_features((string)$r['title']) as $k => $v) $acc[$k][] = $v;
        }
        $o = [];
        foreach ($keys as $k) {
            $o[$k] = in_array($k, ['chars', 'words'], true)
                ? round(vi_median($acc[$k]), 1)
                : round(100 * array_sum($acc[$k]) / max(1, count($acc[$k])));
        }
        return $o;
    };
    $ofeat = $agg($outliers); $bfeat = $agg($baseline);
    $lift = [];
    foreach ($ofeat as $k => $v) {
        if (in_array($k, ['chars', 'words'], true)) continue;
        $lift[$k] = $v - $bfeat[$k];        // percentage-point difference
    }
    arsort($lift);
    vi_put_rule($pdo, 'title_shape', [
        'outlier' => $ofeat, 'baseline' => $bfeat,
        'biggest_gaps_pp' => array_slice($lift, 0, 3, true),
        'median_chars_outlier' => $ofeat['chars'],
    ], 80, sprintf('%d outliers vs %d shorts across %d rival channels',
                   count($outliers), count($baseline), $out['rivals']));
    $out['rules']++;

    /* 2. length: how long is an over-performing short? */
    $od = array_map(fn($r) => (int)$r['duration_s'], $outliers);
    $bd = array_map(fn($r) => (int)$r['duration_s'], $baseline);
    vi_put_rule($pdo, 'duration_band', [
        'outlier_median_s' => round(vi_median($od)),
        'baseline_median_s' => round(vi_median($bd)),
        'ours_now_s' => '30-60',
    ], 75, sprintf('%d outliers', count($od)));
    $out['rules']++;

    /* 3. when they post — hour of day, UTC */
    $hours = [];
    foreach ($outliers as $r) {
        $h = (int)gmdate('G', strtotime((string)$r['published_at']));
        $hours[$h] = ($hours[$h] ?? 0) + 1;
    }
    arsort($hours);
    vi_put_rule($pdo, 'publish_hours_utc', [
        'top_hours' => array_slice($hours, 0, 4, true),
        'ours_now'  => [16, 23],
    ], 60, sprintf('hour of %d outlier uploads', count($outliers)));
    $out['rules']++;

    /* 4. the receipts: the actual titles, so a human can sanity-check the rule */
    usort($outliers, fn($a, $b) => $b['ratio'] <=> $a['ratio']);
    vi_put_rule($pdo, 'top_outliers', array_map(fn($r) => [
        'handle' => $r['handle'], 'x_median' => round($r['ratio'], 1),
        'views' => (int)$r['views'], 'title' => $r['title'],
    ], array_slice($outliers, 0, 10)), 90, 'raw evidence, not a recommendation');
    $out['rules']++;

    return $out;
}
