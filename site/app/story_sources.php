<?php
// GenZHype | THE SOURCES A STORY WAS WRITTEN FROM, and the fact-check fix built on them (owner 2026-10-04:
// "fix the invented-details stop: remove the unsupported sentence, fact-check once more, hold only if still failing").
//
// Diagnosis 2026-10-04 (58 stories since the picker went on, 3 live): the writer is handed 3-5 articles, but a page only
// "had" the ones a timeline event cites (events.source_id, one per event). The fact check, the sentence tie, the code
// check and the "2 source domains" rule all read only those, so true facts from the other articles were called "not in
// the sources" (Metro Redux: written from 4 outlets, checked against 1).
//
//   ss_link()         the writer records every source it was given (drama_sources), always, switch or not.
//   ss_ids()          the sources a story's checks read: the ones its events cite; with the switch on, every source
//                     the story was written from as well. The page lists the same ones.
//   ss_date_backed()  is an event's day one its sources state, or the day one of them was published?
//   ss_date_plan()    a timeline day no source gives: the report's date when a day or two off, month-only when the 1st was invented.
//   ss_round_plan()   what a failed fact check sends to the next removal round (pure).
//
// Everything that changes a result is ON only with the file app/FACTFIX_ON (or FACTFIX=1 for a test run).

require_once __DIR__ . '/db.php';

const SS_ROUNDS = 3;   // remove -> fact check again, at most this many fact checks before the page is held

function ss_on(): bool {
    $env = getenv('FACTFIX');
    if ($env === '1') return true;
    if ($env === '0') return false;   // a test forces it off
    return is_file(__DIR__ . '/FACTFIX_ON');
}

/** drama_sources, created once and only when missing: DDL commits an open transaction (r151). */
function ss_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    if (!$pdo->query("SHOW TABLES LIKE 'drama_sources'")->fetchColumn())
        $pdo->exec("CREATE TABLE IF NOT EXISTS drama_sources (
            drama_id INT UNSIGNED NOT NULL,
            source_id INT UNSIGNED NOT NULL,
            added_at DATETIME NOT NULL,
            PRIMARY KEY (drama_id, source_id)
        ) ENGINE=InnoDB");
    $done = true;
}

/** The writer's sources for a story. No DDL here: it runs inside the writer's transaction (ss_install() goes before it). */
function ss_link(PDO $pdo, int $did, array $sourceIds): int {
    $n = 0;
    $st = $pdo->prepare("INSERT IGNORE INTO drama_sources (drama_id, source_id, added_at) VALUES (?,?,UTC_TIMESTAMP())");
    foreach (array_unique(array_map('intval', $sourceIds)) as $sid) if ($sid > 0) { $st->execute([$did, $sid]); $n += $st->rowCount(); }
    return $n;
}

/** Pure: the ids the checks read. */
function ss_union(array $eventIds, array $linkedIds, bool $on): array {
    $ids = array_map('intval', $eventIds);
    if ($on) foreach ($linkedIds as $l) $ids[] = (int)$l;
    $ids = array_values(array_unique(array_filter($ids)));
    sort($ids);
    return $ids;
}

function ss_ids(PDO $pdo, int $did): array {
    $ev = $pdo->query("SELECT DISTINCT source_id FROM events WHERE drama_id={$did} AND source_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $linked = [];
    if (ss_on()) {
        try { $linked = $pdo->query("SELECT source_id FROM drama_sources WHERE drama_id={$did}")->fetchAll(PDO::FETCH_COLUMN); }
        catch (Throwable $e) { $linked = []; }   // table not there yet: the cited ones only
    }
    return ss_union($ev, $linked, ss_on());
}

/** "12,15,19" for an IN (...) list; "0" when the story has none. */
function ss_in(PDO $pdo, int $did): string {
    return implode(',', ss_ids($pdo, $did)) ?: '0';
}

/**
 * The day an X or TikTok post was made, read from the post's own id (UTC); '' for anything else. The fact check said
 * "the tweet does not include a date" and held pages whose events were the posts themselves (2026-10-04 sample).
 */
function ss_post_date(string $url): string {
    $ts = 0;
    if (preg_match('#(?:x|twitter)\.com/[^/]+/status/(\d{15,20})#i', $url, $m)) $ts = intdiv(((int)$m[1] >> 22) + 1288834974657, 1000);
    elseif (preg_match('#tiktok\.com/.*/video/(\d{15,20})#i', $url, $m)) $ts = (int)$m[1] >> 32;
    return ($ts > 1262304000 && $ts < time() + 86400) ? gmdate('Y-m-d', $ts) : '';
}

/** A source's publish date: its own, else the date of the post it is. */
function ss_source_date(array $s): string {
    $d = substr((string)($s['published_on'] ?? ''), 0, 10);
    return $d !== '' && $d !== '0000-00-00' ? $d : ss_post_date((string)($s['url'] ?? ''));
}

/** A plan's date as the page shows it: a month-only or year-only date is a label, never "2026-12-00". */
function ss_plan_label(string $date): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return $date;
    if ($m[2] === '00') return $m[1];
    if ($m[3] === '00') return date('F Y', strtotime("{$m[1]}-{$m[2]}-01"));
    return $date;
}

/**
 * Pure: is this day one the sources give? True when a source was published that day, or a source's text names the day
 * ("October 2", "Oct. 2", "2 October", "2026-10-02"). $sources: [['excerpt' => text, 'published_on' => Y-m-d|null], ...]
 */
function ss_date_backed(string $date, array $sources): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || $m[3] === '00' || $m[2] === '00') return true;   // not a full day: not this rule's concern
    $ts = strtotime($date . ' 12:00:00 UTC');
    $day = (int)$m[3]; $full = date('F', $ts); $short = date('M', $ts);
    $names = $full === $short ? preg_quote($full, '/') : preg_quote($full, '/') . '|' . preg_quote($short, '/') . '\.?' . ($full === 'September' ? '|Sept\.?' : '');
    $rx = '/\b(?:' . $names . ')\s+0?' . $day . '(?!\d)(?:st|nd|rd|th)?|\b0?' . $day . '(?:st|nd|rd|th)?\s+(?:of\s+)?(?:' . $names . ')\b|' . preg_quote($date, '/') . '/i';
    foreach ($sources as $s) {
        if (substr((string)($s['published_on'] ?? ''), 0, 10) === $date) return true;
        if (preg_match($rx, (string)($s['excerpt'] ?? ''))) return true;
    }
    return false;
}

/**
 * Pure: what to do with a timeline event's day. $pub: the day its own source was published ('' = unknown).
 *   keep    a source names the day, or was published that day
 *   report  no source gives the day and it is within 2 days of the report: the event takes the report's date (the fact
 *           check's own rule: "an event that is the report itself may carry the source's published date"). The writer
 *           dated events a day off their article (Kotaku dated Oct 3, event Oct 2) and the whole page was held.
 *   month   the day is the 1st and the sources name only the month ("diagnosed in March 2022"): the 1st was invented,
 *           the date becomes month-only, and the accuracy step moves it to the background as "In March 2022, ..."
 *   leave   anything else: the fact check decides, and a faulted event date holds the page
 * 2026-10-04 sample: a rule that moved every unbacked date to the report's day put "diagnosed with ALS in March 2022"
 * on Oct 2, 2026; hence the 2-day limit and the month case.
 */
function ss_date_plan(string $date, string $pub, array $sources, string $today): array {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || $m[2] === '00' || $m[3] === '00') return ['action' => 'keep', 'date' => $date];
    if (ss_date_backed($date, $sources)) return ['action' => 'keep', 'date' => $date];
    $pub = substr($pub, 0, 10);
    if ($pub !== '' && $pub <= $today && abs(strtotime($date . ' UTC') - strtotime($pub . ' UTC')) <= 2 * 86400) return ['action' => 'report', 'date' => $pub];
    if ($m[3] === '01') {
        $ts = strtotime($date . ' 12:00:00 UTC'); $full = date('F', $ts); $short = date('M', $ts);
        $names = $full === $short ? preg_quote($full, '/') : preg_quote($full, '/') . '|' . preg_quote($short, '/') . '\.' . ($full === 'September' ? '|Sept\.?' : '');
        foreach ($sources as $s) {
            if (!preg_match_all('/\b(?:' . $names . ')(?:,?\s+(\d{4}))?(?![\w.]*\s+\d{1,2}\b)/i', (string)($s['excerpt'] ?? ''), $mm, PREG_SET_ORDER)) continue;
            $srcYear = substr((string)($s['published_on'] ?? ''), 0, 4);
            foreach ($mm as $x) if ((($x[1] ?? '') !== '' && $x[1] === $m[1]) || (($x[1] ?? '') === '' && $srcYear === $m[1])) return ['action' => 'month', 'date' => "{$m[1]}-{$m[2]}-00"];
        }
    }
    return ['action' => 'leave', 'date' => $date];
}

/** ss_date_plan() applied to a story's timeline. Runs before acc_fix_dates(), which moves month-only dates to the background. */
function ss_fix_event_dates(PDO $pdo, int $pageId): array {
    $out = ['moved' => 0, 'list' => []];
    $did = (int)$pdo->query("SELECT id FROM dramas WHERE page_id=" . $pageId)->fetchColumn();
    if (!$did) return $out;
    $all = $pdo->query("SELECT id, url, excerpt, published_on FROM sources WHERE id IN (" . ss_in($pdo, $did) . ")")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $k => $src) $all[$k]['published_on'] = ss_source_date($src);   // a post is dated by its own id
    $byId = array_column($all, null, 'id');
    $today = gmdate('Y-m-d');
    $evs = $pdo->query("SELECT id, event_date, source_id FROM events WHERE drama_id={$did} AND video_only=0")->fetchAll(PDO::FETCH_ASSOC);
    $up = $pdo->prepare("UPDATE events SET event_date=? WHERE id=?");
    foreach ($evs as $e) {
        $d = (string)$e['event_date'];
        $p = ss_date_plan($d, (string)($byId[(int)$e['source_id']]['published_on'] ?? ''), $all, $today);
        if ($p['action'] !== 'report' && $p['action'] !== 'month') continue;
        $up->execute([$p['date'], (int)$e['id']]);
        $out['moved']++; $out['list'][] = "event {$e['id']}: {$d} -> {$p['date']} ({$p['action']})";
    }
    if ($out['moved']) $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);
    return $out;
}

/**
 * Pure: what a failed fact check sends to the next removal round.
 *   'tie'  sentences the sources must be shown to back (acc_tie), else they are removed
 *   'cut'  sentences removed at once: one the sources were found to back last round and the check faults again (when in
 *          doubt it goes), a fact in Our take, and a wrong date in a plan, the background, the summary or an answer
 * A title or description is rewritten elsewhere (acc_retitle), and a wrong date on a timeline event is never "removed":
 * the event keeps its place and the page holds.
 * $issues: the fact check's issues. $tiedBefore: [normalised sentence => 1]. $norm / $split: acc_norm, sentence splitter.
 */
function ss_round_plan(array $issues, array $tiedBefore, callable $norm, callable $split, callable $strip): array {
    $tie = []; $cut = [];
    $strip = fn(string $r): string => trim((string)preg_replace('/^\s*(\[[^\]]*\]\s*)+/', '', $strip($r)));   // "[2026-10-07] ..." without its number
    foreach ($issues as $i) {
        if (!is_array($i)) continue;
        $sec = strtolower(trim((string)($i['section'] ?? ''))); $type = (string)($i['type'] ?? '');
        if (str_starts_with($sec, 'what happens')) $sec = 'next';
        $raw = (string)($i['sentence'] ?? '');
        if ($raw === '' || str_starts_with($sec, 'description') || str_starts_with($sec, 'title')) continue;
        if (str_starts_with($sec, 'why') || str_starts_with($sec, 'our take')) { $cut[$norm($raw)] = ['section' => 'why', 'sentence' => $raw]; continue; }
        $isEvent = str_starts_with($sec, 'event');
        if ($type === 'dates') {
            if (!$isEvent) foreach ($split($strip($raw)) as $one) $cut[$norm($one)] = ['section' => $sec, 'sentence' => $one];
            continue;
        }
        if (!in_array($type, ['unsourced', 'overreach'], true)) continue;
        foreach ($split($strip($raw)) as $one) {
            $k = $norm($one);
            if (isset($tiedBefore[$k])) $cut[$k] = ['section' => $sec, 'sentence' => $one];
            else $tie[$k] = ['section' => $sec, 'sentence' => $one];
        }
    }
    foreach (array_keys($cut) as $k) unset($tie[$k]);
    return ['tie' => array_values($tie), 'cut' => array_values($cut)];
}

/**
 * The stories written before the writer kept its list (2026-10-04): an unattached source belongs to the neighbouring
 * story (by id) whose title it shares 2+ words with. [drama id => [source ids]]; with $apply the links are saved.
 */
function ss_backfill(PDO $pdo, string $since, bool $apply = false): array {
    ss_install($pdo);
    $words = function (string $t): array {
        $t = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $t));
        $stop = array_flip(['https', 'http', 'www', 'com', 'net', 'org', 'news', 'html', 'with', 'from', 'that', 'this', 'after', 'over', 'into', 'will', 'says', 'game', 'games', 'gaming', 'about', 'their', 'have', 'been', 'more', 'what', 'when', 'your', 'full', 'timeline', 'article', 'articles', 'status', 'original', 'post']);
        return array_values(array_unique(array_filter(explode(' ', $t), fn($w) => strlen($w) >= 4 && !isset($stop[$w]) && !ctype_digit($w))));
    };
    $q = $pdo->prepare("SELECT p.id, p.h1, p.slug, dr.id did FROM pages p JOIN dramas dr ON dr.page_id=p.id WHERE p.type='drama' AND p.created_at >= ? ORDER BY p.id");
    $q->execute([$since]);
    $pages = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$pages) return [];
    $dids = implode(',', array_map(fn($p) => (int)$p['did'], $pages));
    $lo = (int)$pdo->query("SELECT MIN(source_id) FROM events WHERE drama_id IN ({$dids}) AND source_id > 0")->fetchColumn();
    if (!$lo) return [];
    $owner = [];
    foreach ($pdo->query("SELECT DISTINCT source_id, drama_id FROM events WHERE source_id >= " . ($lo - 10)) as $r) $owner[(int)$r['source_id']] ??= (int)$r['drama_id'];
    $src = $pdo->query("SELECT id, url, title FROM sources WHERE id >= " . ($lo - 10) . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($src, 'id');
    $pw = [];
    foreach ($pages as $p) $pw[(int)$p['did']] = array_flip($words($p['h1'] . ' ' . $p['slug']));
    $map = [];
    foreach ($src as $i => $s) {
        $sid = (int)$s['id'];
        if (isset($owner[$sid])) continue;
        $cands = [];
        for ($k = $i - 1; $k >= max(0, $i - 8); $k--) if (isset($owner[(int)$ids[$k]])) { $cands[] = $owner[(int)$ids[$k]]; break; }
        for ($k = $i + 1; $k <= min(count($ids) - 1, $i + 8); $k++) if (isset($owner[(int)$ids[$k]])) { $cands[] = $owner[(int)$ids[$k]]; break; }
        $w = $words($s['title'] . ' ' . parse_url((string)$s['url'], PHP_URL_PATH));
        $best = 0; $bestDid = 0;
        foreach (array_unique($cands) as $did) {
            if (!isset($pw[$did])) continue;
            $sh = count(array_filter($w, fn($x) => isset($pw[$did][$x])));
            if ($sh > $best) { $best = $sh; $bestDid = $did; }
        }
        if ($best >= 2) $map[$bestDid][] = $sid;
    }
    if ($apply) foreach ($map as $did => $sids) ss_link($pdo, (int)$did, $sids);
    return $map;
}
