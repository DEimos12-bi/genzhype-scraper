<?php
// GenZHype | OUR READ OF THE EVIDENCE (owner rule 2026-09-25, a weak item: "your own verdict,
// with reasons"). About real people, so it rates the EVIDENCE, never the person:
//   rating   decided by code from what the page holds (vd_rating): the same rule for every story
//   reasons  2-3 sentences the AI writes from the page's own facts only, held by the fact guard
//            and a blocklist of words that judge a person (guilty, liar, fraud...)
// The page shows how the rating is decided.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/fact_guard.php';
require_once __DIR__ . '/gate.php';

const VD_METHOD = 'Our rating counts evidence, not opinions about anyone: events confirmed by a primary source '
                . '(the person\'s own post, their official site, a court or government record) weigh most, then how '
                . 'many independent outlets report the story.';

/** Idempotent; run outside a transaction (an ALTER commits an open one on MariaDB, r151). */
function vd_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE dramas ADD COLUMN verdict TEXT NULL"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/** What the page holds, and the rating it earns. [ours] thresholds, shown to readers as VD_METHOD. */
function vd_evidence(PDO $pdo, int $did): array {
    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.is_confirmed, e.confirmed_by, s.url, s.publisher FROM events e
                         LEFT JOIN sources s ON s.id=e.source_id WHERE e.drama_id=? AND e.video_only=0 ORDER BY e.sort_order");
    $ev->execute([$did]);
    $rows = $ev->fetchAll(PDO::FETCH_ASSOC);
    $domains = [];
    foreach ($rows as $r) {
        $h = preg_replace('/^www\./', '', strtolower((string)parse_url((string)$r['url'], PHP_URL_HOST)));
        if ($h !== '') $domains[$h] = 1;
    }
    $confirmed = array_values(array_filter($rows, fn($r) => (int)$r['is_confirmed'] === 1));
    $c = count($confirmed); $d = count($domains);
    $rating = ($c >= 2 || ($c >= 1 && $d >= 3)) ? 'Well documented' : (($c >= 1 || $d >= 3) ? 'Partly documented' : 'Mostly unverified');
    return ['events' => $rows, 'confirmed' => $confirmed, 'n' => count($rows), 'c' => $c, 'd' => $d, 'rating' => $rating];
}

/**
 * Write (and, when $save, store) the story's evidence read. ['rating', 'reasons'] or ['error' => ...]
 * (not stored: the next run tries again).
 */
function vd_write(PDO $pdo, int $pageId, bool $save = true): array {
    vd_install($pdo);
    $st = $pdo->prepare("SELECT d.id, p.h1, p.summary, d.both_sides, d.whats_next FROM dramas d JOIN pages p ON p.id=d.page_id WHERE d.page_id=?");
    $st->execute([$pageId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return ['error' => 'not a story page'];
    $e = vd_evidence($pdo, (int)$s['id']);
    if ($e['n'] < 1) return ['error' => 'no events'];

    $facts = "STORY: {$s['h1']}. {$s['summary']}\n\nEVIDENCE COUNTS: {$e['n']} timeline events, {$e['c']} confirmed by a primary source, "
           . ($e['n'] - $e['c']) . " resting on reports, {$e['d']} different outlets or sources.\n\nTIMELINE:\n";   // derived counts too, or the guard refuses them
    foreach ($e['events'] as $r) $facts .= "- [{$r['event_date']}] {$r['title']} (" . ((int)$r['is_confirmed'] === 1
        ? 'confirmed by ' . gate_proof_label($r['confirmed_by']) : 'reported by ' . ((string)$r['publisher'] !== '' ? $r['publisher'] : 'a source')) . ")\n";
    $sides = (array)json_decode((string)$s['both_sides'], true);
    // where each side's words come from: on 415 the read said "through the media" for two X posts
    if (count($sides) === 2) {
        $facts .= "\nBOTH SIDES ON THE RECORD:\n";
        foreach ($sides as $b) $facts .= "- {$b['who']}, " . (in_array($b['by'], ['X', 'Twitter', 'Tiktok', 'TikTok', 'Youtube', 'YouTube', 'Reddit'], true)
            ? "in a post on {$b['by']}" : "quoted by {$b['by']}") . ": \"{$b['quote']}\"\n";
    }
    $next = (array)json_decode((string)$s['whats_next'], true);
    if ($next) { $facts .= "\nPENDING:\n"; foreach ($next as $n) $facts .= "- " . ($n['date'] !== '' ? "{$n['date']}: " : '') . "{$n['text']}\n"; }

    $msgs = [
        ['role' => 'system', 'content' => "You write GenZHype's read of the EVIDENCE in a story about real people. Our rating is \"{$e['rating']}\". "
            . 'In 2-3 plain sentences explain why, from the FACTS only: what is confirmed and by what, what rests only on reports (name the '
            . 'outlets), whether both sides spoke on the record, and what is still pending. Judge the evidence, never the people: do not say '
            . 'or suggest anyone is guilty, innocent, lying, honest or at fault, and do not state any claim as true. No names, numbers or '
            . 'outlets that are not in the FACTS. Output only the sentences.'],
        ['role' => 'user', 'content' => $facts],
    ];
    // one corrective retry with the exact fault, as the status refresh does (page 99 came back at 751 characters once)
    $hosts = fact_hosts(array_column($e['events'], 'url'));
    for ($try = 0; ; $try++) {
        $res = ai_chat($msgs, AI_READER_ORDER, 0.1, 90, AI_READER_SKIP);
        if (isset($res['error'])) return ['error' => 'AI: ' . $res['error']];
        $reasons = trim(preg_replace('/\s+/', ' ', (string)ai_text((string)$res['content'])));
        $len = mb_strlen($reasons);
        $fault = '';
        if ($len < 120 || $len > 700) $fault = "That is {$len} characters; write it again in 120 to 600 characters, same rules.";
        // a verdict on the person, not the evidence, is never published
        elseif (preg_match('/\b(guilty|innocent|liars?|lied|lying|honest|dishonest|fraud\w*|scam\w*|criminal|crook|abuser|predator|racist|pedophile|groomer|'
                     . 'monster|evil|disgusting|shameful|deserves?|at fault|to blame|clearly (?:did|was|is))\b/i', $reasons, $m))
            $fault = "\"{$m[0]}\" judges a person; rate only the evidence. Write it again, same rules.";
        elseif ($drift = fact_drift($reasons, $facts . ' ' . $e['rating'], $hosts))
            $fault = 'These are not in the FACTS: ' . implode(', ', array_slice($drift, 0, 6)) . '. Write it again without them, same rules.';
        if ($fault === '') break;
        if ($try >= 1) return ['error' => $fault];
        $msgs[] = ['role' => 'assistant', 'content' => (string)$res['content']];
        $msgs[] = ['role' => 'user', 'content' => $fault];
    }
    $out = ['rating' => $e['rating'], 'reasons' => $reasons,
            'counts' => ['events' => $e['n'], 'confirmed' => $e['c'], 'sources' => $e['d'], 'both_sides' => count($sides) === 2 ? 1 : 0]];
    if ($save) {
        $pdo->prepare("UPDATE dramas SET verdict=? WHERE id=?")->execute([json_encode($out + ['at' => gmdate('c')], JSON_UNESCAPED_UNICODE), (int)$s['id']]);
        $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);   // a real change; rebuilds the page cache
    }
    return $out;
}

/**
 * Stories with no read yet, and those whose evidence changed since their read (events, confirmed
 * events, sources, both sides: a page touched for any other reason keeps its read); indexed first,
 * newest first; at most $limit AI calls and $maxSecs.
 */
function vd_run(PDO $pdo, int $limit = 20, int $maxSecs = 240): array {
    vd_install($pdo);
    $t0 = time();
    $out = ['written' => 0, 'failed' => 0, 'reasons' => []];
    $rows = $pdo->query("SELECT p.id, d.id did, d.verdict, d.both_sides FROM pages p JOIN dramas d ON d.page_id=p.id
                         WHERE p.type='drama' AND p.status='published' ORDER BY p.robots='index' DESC, p.published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($rows as $r) {
        if (count($ids) >= max(1, $limit)) break;
        if ($r['verdict'] === null) { $ids[] = (int)$r['id']; continue; }
        $was = (array)(json_decode((string)$r['verdict'], true)['counts'] ?? []);
        $e = vd_evidence($pdo, (int)$r['did']);
        $now = ['events' => $e['n'], 'confirmed' => $e['c'], 'sources' => $e['d'],
                'both_sides' => count((array)json_decode((string)$r['both_sides'], true)) === 2 ? 1 : 0];
        if ($was != $now) $ids[] = (int)$r['id'];
    }
    foreach ($ids as $pid) {
        if (time() - $t0 > $maxSecs) break;
        $r = vd_write($pdo, (int)$pid);
        if (isset($r['error'])) { $out['failed']++; $out['reasons'][] = "{$pid}: {$r['error']}"; } else $out['written']++;
    }
    return $out;
}
