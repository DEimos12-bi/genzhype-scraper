<?php
/**
 * STORY STALENESS WATCHER (r78) — the second arm of the outward-facing
 * intelligence engine.
 *
 * WHY IT EXISTS (proven on page 443 today): our WoW cheating-scandal video
 * ended on "Blizzard says it will act" while the real world had already fired
 * the developer two days after we scraped. Nothing in the pipeline ever went
 * back to ask "did this story move on?" — every stage runs exactly once, at
 * write time. This watcher asks that question daily.
 *
 * HOW IT DECIDES, calibrated against that real case:
 *   STALE  = at least STALE_MIN_DOMAINS distinct news domains have DATED,
 *            TOPICAL articles at least STALE_MIN_GAP_DAYS newer than our
 *            newest timeline event.
 *   - Pre-fix 443 (our newest: Jul 22): kotaku Jul 24 + gamespot Jul 24 +
 *     vgc Jul 27 = 3 domains >= 2 days newer  -> STALE. Correct.
 *   - Post-fix 443 (our newest: Jul 24): only vgc Jul 27 qualifies = 1
 *     domain -> FRESH. Correct: late coverage of an event we already carry
 *     is echo, not news. One late article can never flag a story alone.
 *
 * TOPICALITY: a result only counts if its title shares a distinctive term
 * with the story (people names, or rare title words) — the same lesson as
 * the r76 archive guard, where the word "World" matched Doctor Who. Stories
 * with no distinctive term at all (short meme titles) are SKIPPED, loudly:
 * we cannot match them safely, and meme pages have no "ending" to miss.
 *
 * WHAT IT DOES WITH A FINDING: writes evidence to story_watch and stops.
 * It never edits events, never rewrites scripts. The one automated effect is
 * ordering: video_social_next.php serves fresh stories before stale ones, so
 * we do not lead distribution with a story we KNOW is missing its ending.
 * Nothing is dropped — a stale story still posts if it is all we have.
 */
declare(strict_types=1);

require_once __DIR__ . '/reach.php';

const SW_MIN_GAP_DAYS  = 2;   // article must be this many days newer than us
const SW_MIN_DOMAINS   = 2;   // distinct domains required to call it stale
const SW_PER_RUN       = 12;  // stories checked per daily run (Exa is gentle)
const SW_WINDOW_DAYS   = 60;  // only stories published in this window
const SW_EVIDENCE_KEEP = 4;   // articles stored as proof

/** Distinctive terms for topical matching: people first, then rare title words. */
function sw_terms(string $title, string $peopleJson): array {
    $stop = ['the', 'and', 'with', 'from', 'that', 'this', 'over', 'into',
             'after', 'about', 'their', 'under', 'every', 'other', 'while',
             'where', 'world', 'games', 'gaming', 'video', 'young', 'years',
             'media', 'online', 'drama', 'scandal', 'controversy', 'backlash',
             'response', 'statement', 'update', 'timeline', 'internet',
             'tiktok', 'twitter', 'youtube', 'twitch', 'streamer', 'creator',
             'influencer', 'viral', 'community', 'against', 'between',
             'people', 'player', 'players', 'sparks', 'faces', 'calls'];
    $terms = [];
    foreach (json_decode($peopleJson ?: '[]', true) ?: [] as $p) {
        $n = mb_strtolower(trim((string)(is_array($p) ? ($p['name'] ?? '') : $p)));
        if ($n === '') continue;
        $terms[] = $n;                                   // full name phrase
        foreach (preg_split('/\s+/', $n) as $w) {
            if (mb_strlen($w) >= 4) $terms[] = $w;       // each name token
        }
    }
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)) ?: [] as $w) {
        if (mb_strlen($w) >= 5 && !in_array($w, $stop, true)) $terms[] = $w;
    }
    return array_values(array_unique($terms));
}

/** Rotating pick of stories due a check: oldest-checked first. */
function sw_candidates(PDO $pdo, int $limit = SW_PER_RUN): array {
    $q = $pdo->prepare(
        "SELECT p.id page_id, d.id drama_id, v.title, d.people_json, d.lifecycle,
                MAX(e.event_date) our_latest, w.checked_at
         FROM pages p
         JOIN dramas d        ON d.page_id = p.id
         JOIN video_scripts v ON v.page_id = p.id
         JOIN events e        ON e.drama_id = d.id AND e.event_date IS NOT NULL
         LEFT JOIN story_watch w ON w.page_id = p.id
         WHERE p.status = 'published'
           AND d.lifecycle = 'ongoing'
           AND p.published_at > DATE_SUB(NOW(), INTERVAL " . SW_WINDOW_DAYS . " DAY)
         GROUP BY p.id
         ORDER BY (w.checked_at IS NULL) DESC, w.checked_at ASC
         LIMIT " . (int)$limit);
    $q->execute();
    return $q->fetchAll();
}

/**
 * Check one story against the live web. Returns a summary row; writes the
 * story_watch record either way so the rotation keeps moving.
 */
function sw_check(PDO $pdo, array $story): array {
    $pageId = (int)$story['page_id'];
    $title  = (string)$story['title'];
    $ours   = (string)$story['our_latest'];
    $terms  = sw_terms($title, (string)$story['people_json']);

    $save = function (string $status, string $webLatest, int $gap, array $evidence)
            use ($pdo, $pageId, $story, $ours) {
        $isStale = (int)($status === 'stale');
        $pdo->prepare(
            "INSERT INTO story_watch (page_id, drama_id, our_latest, web_latest,
                                      gap_days, status, evidence, checked_at, flagged_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(), IF(?=1,UTC_TIMESTAMP(),NULL))
             ON DUPLICATE KEY UPDATE
               our_latest=VALUES(our_latest), web_latest=VALUES(web_latest),
               gap_days=VALUES(gap_days), evidence=VALUES(evidence),
               checked_at=UTC_TIMESTAMP(),
               /* a dismissed flag stays dismissed unless the web moved PAST
                  the evidence the owner already saw and waved off */
               status = IF(status='dismissed'
                           AND (VALUES(web_latest) IS NULL OR web_latest IS NULL
                                OR VALUES(web_latest) <= web_latest),
                           'dismissed', VALUES(status)),
               flagged_at = IF(VALUES(status)='stale' AND status<>'stale',
                               UTC_TIMESTAMP(), flagged_at)")
            ->execute([$pageId, (int)$story['drama_id'], $ours ?: null,
                       $webLatest ?: null, $gap, $status,
                       json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                       $isStale]);
    };

    if (!$terms) {
        // Short meme-style titles carry no rare words — we cannot match
        // results safely, so we say so instead of guessing (no silent caps).
        $save('fresh', '', 0, ['skipped' => 'no distinctive terms to match on']);
        return ['page' => $pageId, 'status' => 'unmatchable'];
    }
    if ($ours === '') {
        $save('fresh', '', 0, ['skipped' => 'no dated events']);
        return ['page' => $pageId, 'status' => 'no-events'];
    }

    $hits = reach_exa_search($title . ' latest', 8);
    if (!$hits) {
        $save('fresh', '', 0, ['skipped' => 'search returned nothing (retry next rotation)']);
        return ['page' => $pageId, 'status' => 'no-results'];
    }

    $ourTs = strtotime($ours . ' 00:00:00');
    $newer = [];
    foreach ($hits as $h) {
        $pub = (string)($h['published'] ?? '');
        if ($pub === '' || strtotime($pub) === false) continue;
        $gapD = (int)floor((strtotime($pub) - $ourTs) / 86400);
        if ($gapD < SW_MIN_GAP_DAYS) continue;
        $tl = mb_strtolower((string)($h['title'] ?? ''));
        $topical = false;
        foreach ($terms as $t) {
            if ($t !== '' && str_contains($tl, $t)) { $topical = true; break; }
        }
        if (!$topical) continue;
        $newer[] = [
            'url'       => (string)$h['url'],
            'domain'    => parse_url((string)$h['url'], PHP_URL_HOST) ?: '',
            'title'     => (string)$h['title'],
            'published' => $pub,
            'gap_days'  => $gapD,
        ];
    }
    $domains = array_unique(array_column($newer, 'domain'));
    usort($newer, fn($a, $b) => strcmp($b['published'], $a['published']));
    $webLatest = $newer ? $newer[0]['published'] : '';
    $gap = $newer ? (int)$newer[0]['gap_days'] : 0;

    if (count($domains) >= SW_MIN_DOMAINS) {
        $save('stale', $webLatest, $gap, array_slice($newer, 0, SW_EVIDENCE_KEEP));
        return ['page' => $pageId, 'status' => 'STALE', 'gap' => $gap,
                'domains' => count($domains)];
    }
    $save('fresh', $webLatest, $gap, array_slice($newer, 0, SW_EVIDENCE_KEEP));
    return ['page' => $pageId, 'status' => 'fresh'];
}

/** Daily entry point. */
function sw_run(PDO $pdo, int $limit = SW_PER_RUN): array {
    $out = ['checked' => 0, 'stale' => 0, 'unmatchable' => 0, 'details' => []];
    foreach (sw_candidates($pdo, $limit) as $story) {
        $r = sw_check($pdo, $story);
        $out['checked']++;
        if ($r['status'] === 'STALE') $out['stale']++;
        if ($r['status'] === 'unmatchable') $out['unmatchable']++;
        $out['details'][] = $r;
    }
    return $out;
}
