<?php
/**
 * BACKTEST of the story picker rules (owner 2026-09-30: "run the new rules on the last 14 days of candidates, show me
 * how many would be built, merged, put on the watch list or dropped, plus 10 examples of dropped ones").
 *
 * Every story the picker's editor approved in the last 14 days is replayed at the moment we found it: its own article
 * and posts are read, the news search is run once (today) and only what was published by then counts. A story short
 * of sources is replayed 24h and 48h later the same way (the watch list). Pages it would have been merged into must
 * have existed at that moment. Nothing in the queue or on the site is written: results go to storage/picker-backtest/.
 * Resumable: each run reads until its time budget ends; a shard (k of n) lets two runs share the list.
 */
require_once __DIR__ . '/story_picker.php';

const SPB_DIR = __DIR__ . '/../storage/picker-backtest';
/** A second run keeps its own results: SPB_RUN=v2 -> storage/picker-backtest-v2 (the sample of 100 after the owner's answers, 2026-10-01). */
function spb_dir(): string { $r = preg_replace('/[^a-z0-9]/', '', (string)getenv('SPB_RUN')); return SPB_DIR . ($r !== '' ? '-' . $r : ''); }
/** The owner's four flagged stories (crop duster, Xbox, AION, TikToker): always in a sample run. */
const SPB_FLAGGED = [15900, 16024, 16012, 15947];
// the 14 days before the owner's request (2026-09-30 ~21:00 UTC), fixed so the counts do not drift as the clock moves
const SPB_FROM = '2026-09-16 21:00:00';
const SPB_TO   = '2026-09-30 21:30:00';

function spb_population(PDO $pdo): array {
    $all = $pdo->query("SELECT * FROM candidates WHERE type='drama' AND created_at >= '" . SPB_FROM . "' AND created_at < '" . SPB_TO . "'
                        AND JSON_EXTRACT(ai_verdict, '$.build') = true ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $want = (int)getenv('SPB_SAMPLE');
    if ($want < 1 || $want >= count($all)) return $all;
    $pick = [];
    for ($k = 0; $k < $want; $k++) $pick[(int)floor($k * count($all) / $want)] = true;
    return array_values(array_filter($all, fn($c, $i) => isset($pick[$i]) || in_array((int)$c['id'], SPB_FLAGGED, true), ARRAY_FILTER_USE_BOTH));
}

function spb_state_file(int $k): string { return spb_dir() . "/state-{$k}.json"; }

/** The page a built candidate became (the writer keeps the candidate's angle as the page's key phrase). */
function spb_page_of(PDO $pdo, array $cand): ?array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ($pdo->query("SELECT p.id, p.slug, p.status, p.robots, p.created_at, p.path, d.id did, d.primary_kw FROM pages p JOIN dramas d ON d.page_id=p.id
                              WHERE p.type='drama' AND p.created_at >= NOW() - INTERVAL 20 DAY") as $r)
            $map[trim(mb_strtolower(rtrim((string)$r['primary_kw'], '. ')))][] = $r;
    }
    $v = json_decode((string)$cand['ai_verdict'], true) ?: [];
    foreach ([(string)($v['angle'] ?? ''), (string)$cand['angle']] as $a) {
        $k = trim(mb_strtolower(rtrim($a, '. ')));
        if ($k === '' || empty($map[$k])) continue;
        foreach ($map[$k] as $p) if ($p['created_at'] >= $cand['created_at']) return $p;
    }
    return null;
}

/** One candidate: its decision at the moment we found it, then 24h and 48h later while it waits on the watch list. */
function spb_one(PDO $pdo, array $cand): array {
    $t0 = strtotime((string)$cand['created_at']);
    $at = fn(int $h) => gmdate('Y-m-d H:i:s', $t0 + $h * 3600);
    $cand['item_date'] = null;   // replay as it arrived: the queue did not keep the article's date then
    $cand['watch_since'] = null;
    $items = sp_gather($pdo, $cand, true);
    $r = sp_evaluate($pdo, $cand, $at(0), $items, null, $at(0), $at(48));
    $path = [$r['decision']];
    $read = $r['read'] ?? null;
    if ($r['decision'] === 'watch') {
        $c2 = $cand; $c2['watch_since'] = $at(0);
        foreach ([24, 48] as $h) {
            $r = sp_evaluate($pdo, $c2, $at($h), $items, $read, $at($h));
            $path[] = $r['decision'] . "@{$h}h";
            if ($r['decision'] !== 'watch') break;
        }
    }
    $final = $r['decision'];
    $how = $final;
    if (count($path) > 1) $how = $final === 'drop' ? 'watched, then dropped' : "watched, then {$final} after " . substr(end($path), strpos(end($path), '@') + 1);
    // what really happened, and whether the page it became passes the after-writing check (a dated event in 72h)
    $page = spb_page_of($pdo, $cand);
    $pageFresh = null;
    if ($page) {
        $ev = $pdo->prepare("SELECT event_date FROM events WHERE drama_id=? AND video_only=0");
        $ev->execute([(int)$page['did']]);
        $pageFresh = sp_events_fresh($ev->fetchAll(PDO::FETCH_COLUMN), (string)$page['created_at']);
    }
    $onTopic = array_values(array_filter($r['items'] ?? $items, fn($i) => !empty($i['on_topic']) || !empty($i['seed'])));
    return [
        'id' => (int)$cand['id'], 'name' => mb_substr((string)$cand['name'], 0, 160), 'found' => (string)$cand['created_at'],
        'final' => $final, 'how' => $how, 'rule' => $r['rule'], 'why' => $r['why'], 'path' => $path,
        'outlets' => $r['outlets'] ?? null, 'posts' => $r['posts'] ?? null, 'age_h' => $r['age_h'] ?? null,
        'merge_into' => $r['page_id'] ?? null, 'topic' => (string)($read['topic'] ?? ''), 'ai' => $read !== null && !isset($read['error']),
        'seed_date' => (string)(($items[0]['seed'] ?? false) ? $items[0]['date'] : ''),
        'on_topic' => array_map(fn($i) => sp_host((string)$i['url']) . ' ' . substr((string)$i['date'], 0, 10), array_slice($onTopic, 0, 6)),
        'found_items' => count($items),
        'actual' => trim((string)$cand['status'] . ' ' . (string)$cand['reject_reason']),
        'page' => $page ? ['id' => (int)$page['id'], 'path' => $page['path'], 'status' => $page['status'] . '/' . $page['robots'], 'fresh_event' => $pageFresh] : null,
    ];
}

/** Read candidates until $seconds are spent. Shard $k of $n takes every n-th candidate. */
function spb_run(PDO $pdo, int $seconds, int $k = 0, int $n = 1): array {
    @mkdir(spb_dir(), 0775, true);
    $f = spb_state_file($k);
    $st = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $st += ['done' => [], 'errors' => []];
    $t0 = time(); $did = 0;
    $doneAny = spb_results();
    // each lane starts its AI readings on another provider (one slow provider must not hold up every lane)
    $orders = [['nvidia', 'groq', 'gemini', 'nvidia_director', 'openrouter'], ['gemini', 'nvidia', 'groq', 'nvidia_director', 'openrouter'],
               ['nvidia_director', 'groq', 'nvidia', 'gemini', 'openrouter'], ['groq', 'nvidia', 'gemini', 'nvidia_director', 'openrouter']];
    $GLOBALS['SP_AI_ORDER'] = getenv('SPB_GROQ_FIRST') ? ['groq', 'openrouter', 'nvidia', 'gemini', 'nvidia_director'] : $orders[$k % 4];
    foreach (spb_population($pdo) as $i => $cand) {
        if ($i % $n !== $k) continue;
        if (isset($st['done'][$cand['id']]) || isset($doneAny[$cand['id']])) continue;   // done by any lane
        if (time() - $t0 > $seconds) break;
        try {
            $res = spb_one($pdo, $cand);
            if ($res['final'] === 'hold') {   // no AI answer: try once more on a later run, then record it as unanswered
                $st['errors'][$cand['id']] = ($st['errors'][$cand['id']] ?? 0) + 1;
                if ($st['errors'][$cand['id']] < 3) continue;
            }
            $st['done'][$cand['id']] = $res;
        } catch (Throwable $e) {
            $st['errors'][$cand['id']] = ($st['errors'][$cand['id']] ?? 0) + 1;
            error_log('picker backtest #' . $cand['id'] . ': ' . $e->getMessage());
        }
        $did++;
        file_put_contents($f, json_encode($st, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        usleep(400000);
        $pdo = db_alive();
    }
    return ['read' => $did, 'done' => count($st['done'])];
}

/** All shards together. */
function spb_results(): array {
    $all = [];
    foreach (glob(spb_dir() . '/state-*.json') ?: [] as $f) foreach ((json_decode((string)file_get_contents($f), true)['done'] ?? []) as $id => $r) $all[(int)$id] = $r;
    ksort($all);
    return $all;
}
