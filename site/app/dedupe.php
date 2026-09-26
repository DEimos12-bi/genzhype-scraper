<?php
/**
 * GenZHype | THE TWIN CHECK — is this page a second telling of a story we already
 * published? (2026-08-23)
 * =============================================================================
 * WHY THIS EXISTS. Nine pages sat in 'review' being re-judged by the AI editor on
 * almost every tick, failing every time — 31 of the last 65 quality checks, 48%
 * of the machine's judging budget, spent on work already rejected. The obvious
 * reading was that quality had collapsed. It had not. Six of the nine were near
 * duplicates of a story ALREADY PUBLISHED under a slightly different slug:
 *
 *   held  twitch-data-leak                    already live as twitch-2021-data-leak-...
 *   held  youtube-vs-netflix-creator-deals    already live as ...-creator-deals-2026
 *   held  twitch-streamer-attacked-by-mom     already live as ...-by-mom-2026
 *   held  kai-cenat-s-la-peace-meme           already live as ...-meme-2026
 *
 * They fail the editor's "intent" score for a reason that is actually correct: a
 * second copy cannot answer a search better than the original. So they could
 * never pass, and nothing was allowed to retire them — the seven-day sweep that
 * the drain's comment calls "one consistent rule for every page" only ever
 * selected status='draft', so pages in 'review' had no exit at all.
 *
 * WHAT THIS IS. One cheap, local check — no AI call — that answers: does an
 * already-published page tell this same story? Run BEFORE the expensive editor,
 * so a duplicate costs nothing instead of costing a call every hour.
 *
 * THE LAW OF THIS FILE: A FALSE POSITIVE IS WORSE THAN A MISS. Saying "duplicate"
 * about a genuinely new story would retire real work. Every threshold here was
 * set by running the check over the whole live site and reading the flags, not by
 * choosing a number that sounded right. It is deliberately conservative: it
 * misses real duplicates whose slugs diverge (twitch-streamer-nitro-camden vs
 * twitch-streamer-attacked-by-mom is the same story and is NOT caught) and that
 * is the correct trade. A miss stays held; a false positive is destroyed.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Slug words that carry no story meaning and must not count towards a match. */
const DUP_STOPWORDS = [
    's', 'the', 'a', 'an', 'of', 'and', 'or', 'vs', 'to', 'in', 'on', 'for',
    'by', 'at', 'is', 'it', 'its', 'his', 'her', 'their', 'with', 'after',
    'over', 'from', 'as', 'new', 'latest', 'update', 'updates', 'explained',
];
/** Below this many meaningful words a slug is too thin to judge safely. */
const DUP_MIN_TOKENS   = 3;
/** Share of the shorter page's meaningful words that must appear in the other. */
const DUP_MIN_CONTAIN  = 0.85;

/**
 * The meaningful words of a slug. Trailing years are dropped ("...-meme-2026" and
 * "...-meme" are the same story), but a year INSIDE the slug is kept, because
 * "twitch-2021-data-leak" names which leak it is.
 */
function dup_tokens(string $slug): array {
    $parts = array_values(array_filter(explode('-', mb_strtolower(trim($slug)))));
    // drop a trailing year only — it is a uniqueness suffix, not part of the story
    while ($parts && preg_match('/^(19|20)\d\d$/', (string)end($parts))) array_pop($parts);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || in_array($p, DUP_STOPWORDS, true)) continue;
        if (mb_strlen($p) < 2) continue;
        $out[$p] = true;
    }
    // Cast back to string: PHP turns a numeric array key into an int, so "2021"
    // would come back as int 2021 and every strict comparison against it would
    // quietly fail. Years are real tokens here ("twitch-2021-data-leak"), so the
    // type has to stay honest.
    return array_map('strval', array_keys($out));
}

/**
 * How much of the shorter story is contained in the longer one, 0..1.
 * Containment rather than Jaccard on purpose: "twitch-data-leak" is entirely
 * inside "twitch-2021-data-leak-source-code-payouts-exposed", and that is exactly
 * the shape a duplicate takes here — the same story told at a different length.
 */
function dup_containment(array $a, array $b): float {
    if (!$a || !$b) return 0.0;
    $shared = count(array_intersect($a, $b));
    return $shared / min(count($a), count($b));
}

/**
 * The published page that already tells this page's story, or null.
 *
 * Only ever returns a page that is live: the whole point is that retiring the
 * held copy loses the reader nothing, because the story is already on the site.
 *
 * @return array{id:int, slug:string, containment:float, shared:array}|null
 */
function dup_twin(PDO $pdo, int $pageId, array $statuses = ['published'], bool $olderOnly = false): ?array {
    $st = $pdo->prepare("SELECT id, slug, type, created_at FROM pages WHERE id=?");
    $st->execute([$pageId]);
    $me = $st->fetch(PDO::FETCH_ASSOC);
    if (!$me) return null;

    $mine = dup_tokens((string)$me['slug']);
    if (count($mine) < DUP_MIN_TOKENS) return null;      // too thin to judge safely

    $statuses = array_values(array_filter($statuses, fn($s) => in_array($s, ['published', 'review', 'draft'], true)));
    if (!$statuses) return null;
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT id, slug, status, created_at FROM pages
             WHERE status IN ({$in}) AND type=? AND id<>?";
    // When two copies of one story are both unpublished, the FIRST one drafted is
    // the keeper and the later one is the redundant copy. Without this the pair
    // would each see the other as a twin and both could be retired.
    if ($olderOnly) $sql .= " AND created_at < ?";
    $q = $pdo->prepare($sql);
    $args = array_merge($statuses, [(string)$me['type'], $pageId]);
    if ($olderOnly) $args[] = (string)$me['created_at'];
    $q->execute($args);

    $best = null;
    foreach ($q as $row) {
        $theirs = dup_tokens((string)$row['slug']);
        if (count($theirs) < DUP_MIN_TOKENS) continue;
        $c = dup_containment($mine, $theirs);
        if ($c < DUP_MIN_CONTAIN) continue;
        if ($best === null || $c > $best['containment']) {
            $best = ['id' => (int)$row['id'], 'slug' => (string)$row['slug'],
                     'status' => (string)$row['status'],
                     'containment' => round($c, 3),
                     'shared' => array_values(array_intersect($mine, $theirs))];
        }
    }
    return $best;
}

/**
 * THE SAME CHECK, BEFORE THE PAGE EXISTS. draft.php needs to ask 'is this story
 * already covered?' while it still holds nothing but a proposed slug.
 *
 * This is where the duplicates were actually born. The old guard did:
 *     if (slug is taken) slug .= '-' . date('Y');
 * It detected the collision and then WORKED AROUND it, minting a second page for
 * the same story. That single line is how twitch-data-leak became
 * twitch-data-leak-2026, and how one Twitch story ended up on the site 15 times.
 *
 * @return array{id:int, slug:string, status:string, containment:float}|null
 */
function dup_twin_for_slug(PDO $pdo, string $slug, string $type = 'drama',
                           array $statuses = ['published', 'review', 'draft']): ?array {
    $mine = dup_tokens($slug);
    if (count($mine) < DUP_MIN_TOKENS) return null;
    $statuses = array_values(array_filter($statuses, fn($x) => in_array($x, ['published', 'review', 'draft'], true)));
    if (!$statuses) return null;
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $q = $pdo->prepare("SELECT id, slug, status FROM pages WHERE status IN ({$in}) AND type=?");
    $q->execute(array_merge($statuses, [$type]));
    $best = null;
    foreach ($q as $row) {
        $theirs = dup_tokens((string)$row['slug']);
        if (count($theirs) < DUP_MIN_TOKENS) continue;
        $c = dup_containment($mine, $theirs);
        if ($c < DUP_MIN_CONTAIN) continue;
        if ($best === null || $c > $best['containment']) {
            $best = ['id' => (int)$row['id'], 'slug' => (string)$row['slug'],
                     'status' => (string)$row['status'], 'containment' => round($c, 3)];
        }
    }
    return $best;
}

/** The already-live page telling this story, if there is one. */
function dup_published_twin(PDO $pdo, int $pageId): ?array {
    return dup_twin($pdo, $pageId, ['published'], false);
}

/**
 * Is this page a redundant copy of a story the site already has in hand?
 *
 * Two ways that happens, and both were happening at once:
 *   - the story is already PUBLISHED under another slug, or
 *   - an OLDER unpublished copy of the same story is already waiting.
 * The second case is why two Asmongold pages sat in review together, each
 * unable to pass, neither aware of the other.
 */
function dup_redundant_copy(PDO $pdo, int $pageId): ?array {
    $pub = dup_published_twin($pdo, $pageId);
    if ($pub) return $pub + ['reason' => 'already published'];
    $held = dup_twin($pdo, $pageId, ['review', 'draft'], true);
    if ($held) return $held + ['reason' => 'an older copy of this story is already waiting'];
    return null;
}

/**
 * SELF-TEST. Every case below is a real slug pair off the live site — the six
 * that must be caught, and the near-misses that must NOT be, including two pages
 * that really are the same story but whose slugs do not show it. Those stay as
 * permanent proof that this check is conservative on purpose.
 */
function dup_selftest(): array {
    $pass = 0; $fail = 0; $notes = [];
    $t = function (string $name, bool $ok, string $got = '') use (&$pass, &$fail, &$notes) {
        $ok ? $pass++ : $fail++;
        $notes[] = ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $got === '' ? '' : "  (got: {$got})");
    };
    $c = fn(string $x, string $y) => dup_containment(dup_tokens($x), dup_tokens($y));
    $dup = fn(string $x, string $y) => count(dup_tokens($x)) >= DUP_MIN_TOKENS
                                    && count(dup_tokens($y)) >= DUP_MIN_TOKENS
                                    && $c($x, $y) >= DUP_MIN_CONTAIN;

    // --- must be caught: real held pages against the live page telling that story
    $t('bare slug inside a longer one',
       $dup('twitch-data-leak-2026', 'twitch-2021-data-leak-source-code-payouts-exposed'),
       (string)$c('twitch-data-leak-2026', 'twitch-2021-data-leak-source-code-payouts-exposed'));
    $t('same slug, year suffix only',
       $dup('youtube-vs-netflix-creator-deals', 'youtube-vs-netflix-creator-deals-2026'));
    $t('same slug, year suffix only (2)',
       $dup('twitch-streamer-attacked-by-mom', 'twitch-streamer-attacked-by-mom-2026'));
    $t('same slug, year suffix only (3)',
       $dup('kai-cenat-s-la-peace-meme', 'kai-cenat-s-la-peace-meme-2026'));

    // --- must NOT be caught
    $t('a genuinely different twitch story is not a duplicate',
       !$dup('twitch-data-leak-2026', 'twitch-streamer-attacked-by-mom-2026'),
       (string)$c('twitch-data-leak-2026', 'twitch-streamer-attacked-by-mom-2026'));
    $t('two different creators are not duplicates',
       !$dup('kai-cenat-s-la-peace-meme', 'asmongold-s-personal-life'));
    $t('sharing one platform word is not a duplicate',
       !$dup('youtube-vs-netflix-creator-deals', 'youtube-shorts-monetisation-change'));
    $t('a thin slug is never judged',
       !$dup('twitch-leak', 'twitch-2021-data-leak-source-code-payouts-exposed'),
       'tokens=' . count(dup_tokens('twitch-leak')));

    // --- the deliberate miss: same story, slugs do not show it. Conservative by design.
    $t('same story with divergent slugs is deliberately MISSED',
       !$dup('twitch-streamer-nitro-camden', 'twitch-streamer-attacked-by-mom-2026'),
       (string)$c('twitch-streamer-nitro-camden', 'twitch-streamer-attacked-by-mom-2026'));

    // --- tokenising
    $t('trailing year dropped', dup_tokens('a-story-2026') === dup_tokens('a-story'));
    $t('inner year kept', in_array('2021', dup_tokens('twitch-2021-data-leak'), true));
    $t('stopwords dropped', !in_array('vs', dup_tokens('youtube-vs-netflix'), true));
    $t('containment of nothing is zero', dup_containment([], ['a']) === 0.0);

    return ['pass' => $pass, 'fail' => $fail, 'notes' => $notes];
}

/**
 * SAME STORY, NOT SAME SLUG (2026-09-26). An outside site check counted 36+ extra copies (16 pages
 * on the Twitch 2021 leak, 11 on Nitro Camden, 9 on Take-Two's GTA 6 leak case): their slugs differ,
 * so the slug check above never sees them, and the assignment editor (select.php) only sees the
 * newest titles. Measured on the live site the same day: two pages sharing 2+ event DAYS are mostly
 * one story, but every GTA 6 timeline shares the trailer dates; a shared name plus a similar title
 * is mostly one story, but Ethan Klein has two different lawsuits. So words and dates only pick the
 * suspects (dup_story_suspects) and an AI reading decides (dup_story_twin); only "same" counts.
 */
const DUP_STORY_MIN_DAYS    = 2;     // [ours] shared exact event days that make an existing story a suspect
const DUP_STORY_MIN_CONTAIN = 0.5;   // [ours] or this share of the shorter title's story words
/** Title words every story page uses; they say nothing about which story it is. */
const DUP_STORY_GENERIC = ['timeline', 'controversy', 'drama', 'backlash', 'full', 'detailed', 'story', 'sparks',
    'faces', 'amid', 'response', 'responds', 'viral', 'explained', 'fallout', 'reaction', 'reactions', 'details'];

/**
 * Existing stories (live, review or draft) that might tell this story, best first; $exceptPageId
 * leaves one page out (checking a page already on the site against the others).
 * [[page_id, slug, h1, summary, did, days, contain]]
 */
function dup_story_suspects(PDO $pdo, string $title, array $eventDates, int $limit = 4, int $exceptPageId = 0): array {
    $mine = array_fill_keys(array_filter($eventDates, fn($d) => preg_match('/^\d{4}-\d{2}-(?!00)\d{2}$/', (string)$d)), 1);
    $tok = dup_story_words($title);
    $days = [];
    foreach ($pdo->query("SELECT e.drama_id, e.event_date FROM events e WHERE e.video_only=0 AND DAY(e.event_date) > 0") as $r)
        if (isset($mine[$r['event_date']])) $days[(int)$r['drama_id']][$r['event_date']] = 1;
    $out = [];
    foreach ($pdo->query("SELECT p.id, p.slug, p.h1, p.summary, d.id did FROM pages p JOIN dramas d ON d.page_id=p.id
                          WHERE p.type='drama' AND p.status IN ('published','review','draft')") as $r) {
        if ((int)$r['id'] === $exceptPageId) continue;
        $shared = count($days[(int)$r['did']] ?? []);
        $contain = $tok ? dup_containment($tok, dup_story_words((string)$r['h1'])) : 0.0;
        // days alone coincide (xQc, Path of Exile and Fishtank shared 2 dates with a Frogan lawsuit): 2 shared days
        // need a shared story word too, 3 stand alone
        $byDays = $shared >= DUP_STORY_MIN_DAYS + 1 || ($shared >= DUP_STORY_MIN_DAYS && $contain > 0);
        if (!$byDays && $contain < DUP_STORY_MIN_CONTAIN) continue;
        $out[] = ['page_id' => (int)$r['id'], 'slug' => $r['slug'], 'h1' => $r['h1'], 'summary' => (string)$r['summary'],
                  'did' => (int)$r['did'], 'days' => $shared, 'contain' => round($contain, 2)];
    }
    usort($out, fn($a, $b) => ($b['days'] * 2 + $b['contain'] * 3) <=> ($a['days'] * 2 + $a['contain'] * 3));
    return array_slice($out, 0, $limit);
}

/** The story words of a title: dup_tokens() of its words, without the words every story page uses. */
function dup_story_words(string $title): array {
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title)), '-');
    return array_values(array_diff(dup_tokens($slug), DUP_STORY_GENERIC));
}

/**
 * The existing story this draft retells, or null: each suspect in turn is read by an AI next to the
 * draft; "same" = the same people AND the same event or chain of events. $draft = [title, summary,
 * events => [[date, title], ...]]. ['page_id', 'slug', 'why'], or ['error' => ...] when the AI
 * never answered (the caller decides; nothing is blocked on a guess).
 */
function dup_story_twin(PDO $pdo, array $draft, int $exceptPageId = 0): ?array {
    require_once __DIR__ . '/ai.php';
    $dates = array_map(fn($e) => (string)($e['date'] ?? ''), (array)($draft['events'] ?? []));
    $suspects = dup_story_suspects($pdo, (string)$draft['title'], $dates, 4, $exceptPageId);
    if (!$suspects) return null;
    $side = function (string $title, string $summary, array $events): string {
        $s = "TITLE: {$title}\nSUMMARY: " . mb_substr($summary, 0, 500) . "\nEVENTS:\n";
        foreach (array_slice($events, 0, 10) as $e) $s .= "- [{$e['date']}] " . mb_substr((string)$e['title'], 0, 120) . "\n";
        return $s;
    };
    $new = $side((string)$draft['title'], (string)($draft['summary'] ?? ''), (array)$draft['events']);
    $ev = $pdo->prepare("SELECT event_date date, title FROM events WHERE drama_id=? AND video_only=0 ORDER BY sort_order");
    $unanswered = 0;
    foreach ($suspects as $s) {
        $ev->execute([$s['did']]);
        $res = ai_chat([['role' => 'user', 'content' =>
            "PAGE A (new draft):\n{$new}\nPAGE B (already on our site):\n" . $side($s['h1'], $s['summary'], $ev->fetchAll(PDO::FETCH_ASSOC))
            . "\nDo A and B tell the SAME story: the same people AND the same event or chain of events, so A would repeat B? "
            . "A different event involving the same person, or another incident of the same kind, is NOT the same story. "
            . "STRICT JSON: {\"verdict\": \"same\"|\"different\"|\"unsure\", \"why\": \"<15 words\"}"]],
            AI_READER_ORDER, 0.0, 60, AI_READER_SKIP);
        $j = isset($res['error']) ? null : ai_json((string)$res['content']);
        if (!is_array($j) || !in_array($j['verdict'] ?? null, ['same', 'different', 'unsure'], true)) { $unanswered++; continue; }
        if ($j['verdict'] === 'same') return ['page_id' => $s['page_id'], 'slug' => $s['slug'], 'why' => mb_substr((string)($j['why'] ?? ''), 0, 160)];
    }
    return $unanswered ? ['error' => "no AI answer for {$unanswered} of " . count($suspects) . ' suspect(s)'] : null;
}
