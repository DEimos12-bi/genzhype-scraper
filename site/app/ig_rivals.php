<?php
/* GenZHype | r116 — INSTAGRAM RIVAL INTELLIGENCE, WITHOUT META.
 *
 * WHY THIS SHAPE. The engine only ever learned from YouTube rivals — the
 * platform where we average 5 views. The obvious fix was Meta's
 * business_discovery API; that door is shut, because the Facebook developer
 * account itself is blocked (developers.facebook.com/account_status/error,
 * which is also why our old app answers "Application has been deleted").
 *
 * So the data comes the only way left: OpenCLI driving the owner's own
 * logged-in Chrome on their own machine, reading public accounts exactly as
 * a person would. That machine is not our server, so this file never fetches
 * anything — it RECEIVES what the owner's laptop pushed, stores it, and does
 * the thinking here where the rest of the engine lives.
 *
 * THE ANALYSIS mirrors the YouTube arm deliberately: a rival's own median
 * separates their hits from their normal, because a big account out-performs
 * a small one on every post and raw counts would just measure follower
 * count. What we extract is the SHAPE of the captions that beat their own
 * account, which is the one thing we can act on.
 *
 * FAILS QUIET. No rows, one rival, a changed OpenCLI output: it writes no
 * rule rather than a weak one. A rule nobody can trust is worse than none. */
declare(strict_types=1);

/** Minimum evidence before any rule is written. Below this the "median" is
 *  one or two posts and every conclusion is noise. */
const IGR_MIN_POSTS_PER_RIVAL = 8;
const IGR_MIN_TOTAL_POSTS     = 24;

function igr_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_rival_post (
        id          BIGINT AUTO_INCREMENT PRIMARY KEY,
        rival       VARCHAR(64)  NOT NULL,
        post_id     VARCHAR(64)  NOT NULL,
        permalink   VARCHAR(300) NULL,
        caption     TEXT         NULL,
        media_type  VARCHAR(24)  NULL,
        likes       INT          NOT NULL DEFAULT 0,
        comments    INT          NOT NULL DEFAULT 0,
        views       INT          NOT NULL DEFAULT 0,
        posted_at   DATETIME     NULL,
        raw         MEDIUMTEXT   NULL,
        fetched_at  DATETIME     NOT NULL,
        UNIQUE KEY uq_rival_post (rival, post_id),
        KEY k_rival (rival)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Pull a number out of whatever key the upstream tool happened to use.
 *  OpenCLI's exact field names are not contracted anywhere, so every reader
 *  accepts the plausible spellings instead of guessing one and silently
 *  storing zeros. The raw payload is kept as well, so a mapping that misses
 *  can be fixed from stored data without asking for another pull. */
function igr_pick($row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (is_array($row) && array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function igr_int($v): int {
    if (is_int($v) || is_float($v)) { return (int)$v; }
    if (is_string($v)) {
        // "12.3K" / "1,234" / "2.1M" — display strings, not numbers
        $s = strtoupper(trim(str_replace(',', '', $v)));
        if (preg_match('/^([\d.]+)\s*([KM])?$/', $s, $m)) {
            $n = (float)$m[1];
            if (($m[2] ?? '') === 'K') { $n *= 1000; }
            if (($m[2] ?? '') === 'M') { $n *= 1000000; }
            return (int)round($n);
        }
    }
    return 0;
}

/** Store one pull. Returns [stored, skipped, keys_seen].
 *
 *  r116d: the first real pull delivered 39 posts and stored ZERO, because no
 *  post carried any of the id spellings guessed here and every row was
 *  dropped. Dropping data we already paid a browser session to fetch is the
 *  wrong trade: the payload is now ALWAYS stored (keyed by a hash of itself
 *  when it has no id we recognise), and the observed field names come back in
 *  the response so the mapping can be corrected from evidence instead of
 *  another guess. Raw is kept regardless, so a corrected mapping can be
 *  replayed over stored rows without asking for a fresh pull. */
function igr_ingest(PDO $pdo, string $rival, array $posts): array {
    igr_schema($pdo);
    $keysSeen = [];
    $ins = $pdo->prepare(
        "INSERT INTO ig_rival_post
           (rival, post_id, permalink, caption, media_type, likes, comments, views, posted_at, raw, fetched_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE likes=VALUES(likes), comments=VALUES(comments),
                                 views=VALUES(views), caption=VALUES(caption),
                                 raw=VALUES(raw), fetched_at=UTC_TIMESTAMP()");
    // r117: the deep pull carries Instagram's own post id, while the first
    // (OpenCLI) pulls had none and were keyed by a content fingerprint. The
    // SAME post arriving under a real id would be stored a second time and
    // counted twice in every median. So when a pull brings real ids, this
    // rival's fingerprint-keyed rows are retired — but only once the real
    // rows have arrived, so a failed pull never costs us data.
    $hasRealIds = false;
    foreach ($posts as $p) {
        if (is_array($p) && (string)igr_pick($p, ['id', 'pk', 'post_id'], '') !== '') {
            $hasRealIds = true;
            break;
        }
    }

    $stored = 0; $skipped = 0;
    foreach ($posts as $p) {
        if (!is_array($p)) { $skipped++; continue; }
        // GraphQL-shaped payloads wrap the real object one level down.
        if (isset($p['node']) && is_array($p['node'])) { $p = $p['node']; }
        foreach (array_keys($p) as $k) { $keysSeen[$k] = true; }

        $pid = (string)igr_pick($p, ['id', 'post_id', 'shortcode', 'code', 'pk',
                                     'postId', 'shortCode', 'media_id'], '');
        if ($pid === '') {
            // No id we know: key it by its own content so the row still lands
            // and re-pulling the same post UPDATES rather than duplicates.
            //
            // The hash must cover only what cannot change. OpenCLI's payload
            // is {caption, comments, date, index, likes, type} — no id at all
            // — and hashing the whole thing would fold in the like count and
            // the feed position, both of which move between pulls. The same
            // post would then land again next week under a new key, and the
            // "median" every conclusion rests on would be computed over the
            // same post counted several times at different ages.
            $stable = mb_strtolower((string)igr_pick($p, ['caption', 'text', 'description', 'title'], ''))
                    . '|' . (string)igr_pick($p, ['taken_at', 'timestamp', 'posted_at', 'created_at', 'date'], '');
            $pid = 'h_' . substr(sha1($stable), 0, 24);
        }
        $ts = igr_pick($p, ['taken_at', 'timestamp', 'posted_at', 'created_at', 'date']);
        $when = null;
        if ($ts !== null) {
            $when = is_numeric($ts) ? date('Y-m-d H:i:s', (int)$ts)
                                    : (($t = strtotime((string)$ts)) ? date('Y-m-d H:i:s', $t) : null);
        }
        $ins->execute([
            $rival,
            substr($pid, 0, 64),
            substr((string)igr_pick($p, ['permalink', 'url', 'link'], ''), 0, 300) ?: null,
            (string)igr_pick($p, ['caption', 'text', 'description', 'title'], ''),
            substr((string)igr_pick($p, ['media_type', 'type', 'product_type'], ''), 0, 24) ?: null,
            igr_int(igr_pick($p, ['like_count', 'likes', 'likeCount'], 0)),
            igr_int(igr_pick($p, ['comment_count', 'comments', 'commentCount'], 0)),
            igr_int(igr_pick($p, ['play_count', 'view_count', 'views', 'videoViewCount'], 0)),
            $when,
            json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $stored++;
    }
    if ($hasRealIds && $stored > 0) {
        $pdo->prepare("DELETE FROM ig_rival_post WHERE rival = ? AND post_id LIKE 'h\\_%'")
            ->execute([$rival]);
    }
    return [$stored, $skipped, array_keys($keysSeen)];
}

/** Traits of one caption, as the yes/no facts we can actually act on. */
function igr_traits(string $caption): array {
    $first = trim(explode("\n", $caption)[0] ?? '');
    return [
        'chars'       => mb_strlen($caption),
        'first_line'  => mb_strlen($first),
        'hashtags'    => preg_match_all('/#\w+/u', $caption),
        'mentions'    => preg_match_all('/@\w+/u', $caption),
        'has_number'  => preg_match('/\d/', $caption) ? 1 : 0,
        'has_question'=> str_contains($caption, '?') ? 1 : 0,
        'has_quote'   => (str_contains($caption, '"') || str_contains($caption, '“')) ? 1 : 0,
        // a name-shaped opener: two capitalised words at the very start
        'name_first'  => preg_match('/^\p{Lu}[\p{L}\'’-]+\s+\p{Lu}/u', $first) ? 1 : 0,
    ];
}

function igr_median(array $n) {
    if (!$n) { return 0; }
    sort($n);
    return $n[intdiv(count($n), 2)];
}

/** Compare each rival's hits against their own normal, and write the shape
 *  difference as rules. Returns the report, or null when the evidence is
 *  too thin to say anything honest. */
function igr_analyze(PDO $pdo): ?array {
    igr_schema($pdo);
    $rows = $pdo->query("SELECT rival, caption, likes, comments, views FROM ig_rival_post")->fetchAll();
    if (count($rows) < IGR_MIN_TOTAL_POSTS) { return null; }

    $by = [];
    foreach ($rows as $r) { $by[(string)$r['rival']][] = $r; }

    $hits = []; $base = []; $rivalStats = [];
    foreach ($by as $rival => $posts) {
        if (count($posts) < IGR_MIN_POSTS_PER_RIVAL) { continue; }
        // engagement, not raw likes: it is what survives across account sizes
        $eng = array_map(fn($p) => (int)$p['likes'] + (int)$p['comments'], $posts);
        $med = igr_median($eng);
        if ($med <= 0) { continue; }
        $h = 0;
        foreach ($posts as $p) {
            $e = (int)$p['likes'] + (int)$p['comments'];
            if ($e >= $med * 2) { $hits[] = $p; $h++; } else { $base[] = $p; }
        }
        $rivalStats[$rival] = ['posts' => count($posts), 'median_eng' => $med, 'hits' => $h];
    }
    if (count($hits) < 5 || count($base) < 10) { return null; }

    $avg = function (array $set, string $key) {
        $v = array_map(fn($p) => igr_traits((string)$p['caption'])[$key], $set);
        return $v ? round(array_sum($v) / count($v), 1) : 0;
    };
    $pct = function (array $set, string $key) {
        $v = array_map(fn($p) => igr_traits((string)$p['caption'])[$key], $set);
        return $v ? (int)round(100 * array_sum($v) / count($v)) : 0;
    };

    $shape = [
        'outlier' => [
            'caption_chars'  => $avg($hits, 'chars'),
            'first_line'     => $avg($hits, 'first_line'),
            'hashtags'       => $avg($hits, 'hashtags'),
            'name_first'     => $pct($hits, 'name_first'),
            'has_number'     => $pct($hits, 'has_number'),
            'has_question'   => $pct($hits, 'has_question'),
        ],
        'baseline' => [
            'caption_chars'  => $avg($base, 'chars'),
            'first_line'     => $avg($base, 'first_line'),
            'hashtags'       => $avg($base, 'hashtags'),
            'name_first'     => $pct($base, 'name_first'),
            'has_number'     => $pct($base, 'has_number'),
            'has_question'   => $pct($base, 'has_question'),
        ],
        'rivals'   => $rivalStats,
        'n_hits'   => count($hits),
        'n_base'   => count($base),
    ];
    $shape['biggest_gaps_pp'] = [];
    foreach (['name_first', 'has_number', 'has_question'] as $k) {
        $shape['biggest_gaps_pp'][$k] = $shape['outlier'][$k] - $shape['baseline'][$k];
    }

    $conf = count($hits) >= 20 ? 80 : (count($hits) >= 10 ? 70 : 60);
    $pdo->prepare(
        "INSERT INTO comp_rule (scope, rule_key, rule_value, confidence, evidence, version, active, updated_at)
         VALUES ('video','ig_caption_shape',?,?,?,1,1,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value), confidence=VALUES(confidence),
                                 evidence=VALUES(evidence), active=1, updated_at=UTC_TIMESTAMP()")
        ->execute([json_encode($shape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                   $conf, (string)count($rows)]);

    // The receipts, so the admin can read the actual winners rather than
    // trust a summary — the same contract as the YouTube top_outliers rule.
    usort($hits, fn($a, $b) => (((int)$b['likes'] + (int)$b['comments'])
                              <=> ((int)$a['likes'] + (int)$a['comments'])));
    $top = [];
    foreach (array_slice($hits, 0, 8) as $p) {
        $top[] = [
            'rival'    => (string)$p['rival'],
            'eng'      => (int)$p['likes'] + (int)$p['comments'],
            'caption'  => mb_substr((string)$p['caption'], 0, 200),
        ];
    }
    $pdo->prepare(
        "INSERT INTO comp_rule (scope, rule_key, rule_value, confidence, evidence, version, active, updated_at)
         VALUES ('video','ig_top_outliers',?,?,?,1,1,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value), confidence=VALUES(confidence),
                                 evidence=VALUES(evidence), active=1, updated_at=UTC_TIMESTAMP()")
        ->execute([json_encode($top, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                   $conf, (string)count($top)]);

    return $shape;
}
