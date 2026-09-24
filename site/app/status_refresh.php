<?php
// GenZHype | STATUS REFRESH (2026-09-24, owner: "fix the stale summary thing").
// drama_deepen and the bin drain ADD events to a live story but never touched
// its summary, status or FAQs, so the page contradicted its own timeline: page
// 1134 still said "The case remains ongoing" under a 2026-09-08 event reporting
// the lawsuit was dismissed. stale_status was the editor's most common red flag
// (198 of 259 pages in 21 days). Measured the same day: 183 live stories had a
// timeline newer than their summary (121 gained events after drafting, 125
// hold an event dated after the page was made).
//
// drama_status_refresh() rewrites ONLY what the timeline makes out of date
// (summary, lifecycle, the status FAQ, the meta if it contradicts), from the
// page's own facts, behind a fact-drift guard; keeps the old text in
// page_text_history; and re-judges the page, because it changed.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/quality.php';

const SR_SUMMARY_MIN = 120;
const SR_SUMMARY_MAX = 420;   // gate.php GATE_SUMMARY_MAX
const SR_META_MIN    = 110;   // gate.php GATE_META_MIN
const SR_META_MAX    = 160;   // gate.php GATE_META_MAX
const SR_STATUS_FAQ_RX = '/\b(status|still|over|latest|now|update|current|resolved|ongoing|next)\b/i';

/** Old page text kept for undo. Must run outside a transaction (CREATE TABLE commits one on MariaDB, see r151). */
function sr_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS page_text_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        page_id INT NOT NULL,
        field VARCHAR(40) NOT NULL,
        old_val MEDIUMTEXT NULL,
        new_val MEDIUMTEXT NULL,
        reason VARCHAR(80) NOT NULL,
        at DATETIME NOT NULL,
        KEY (page_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Words and numbers a rewrite adds that the page's own facts do not contain. */
function sr_drift(string $text, string $corpus, array $hosts = []): array {
    static $allow = ['january','february','march','april','may','june','july','august','september','october',
        'november','december','monday','tuesday','wednesday','thursday','friday','saturday','sunday','genzhype',
        'faq','youtube','twitch','tiktok','twitter','instagram','reddit','kick','according','reportedly','allegedly'];
    $lc = mb_strtolower($corpus);
    $bad = [];
    preg_match_all('/\d[\d,.]*\d|\d/u', $text, $m);
    foreach (array_unique($m[0]) as $n) {
        $n = rtrim($n, '.,');
        if (!str_contains($lc, $n) && !str_contains(str_replace(',', '', $lc), str_replace(',', '', $n))) $bad[] = $n;
    }
    // capitalised words that do not start a sentence must be in the facts; a shared
    // stem counts ("Rumors" when the facts say "rumored" was a false alarm on 1327)
    preg_match_all('/(?<![.!?:]\s)(?<!^)\b\p{Lu}[\p{L}\'’-]{2,}/u', $text, $m);
    foreach (array_unique($m[0]) as $w) {
        $wl = mb_strtolower(rtrim($w, "'’"));
        if (in_array($wl, $allow, true)) continue;
        if (preg_match('/\b' . preg_quote($wl, '/') . '/u', $lc)) continue;
        $stem = mb_substr($wl, 0, max(4, mb_strlen($wl) - 2));
        if (mb_strlen($wl) >= 5 && preg_match('/\b' . preg_quote($stem, '/') . '/u', $lc)) continue;
        $bare = preg_replace('/[^a-z0-9]/', '', $wl);   // an outlet's name as its web address spells it
        if (strlen($bare) >= 3) { foreach ($hosts as $h) if (str_contains($h, $bare)) continue 2; }
        $bad[] = $w;
    }
    return array_values(array_unique($bad));
}

/** Human date for "As of": YYYY-MM-DD, YYYY-MM-00 or YYYY-00-00. */
function sr_human_date(string $d): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return '';
    if ($m[2] === '00') return $m[1];
    $ts = strtotime("{$m[1]}-{$m[2]}-" . ($m[3] === '00' ? '01' : $m[3]));
    return $m[3] === '00' ? date('F Y', $ts) : date('F j, Y', $ts);
}

/**
 * Refresh one story's status text from its timeline.
 * $asOf: the date the status is true as of. Today when the caller has just searched
 * for new coverage (drama_deepen); otherwise leave '' and the latest event's date is used.
 */
function drama_status_refresh(PDO $pdo, int $pageId, string $asOf = '', bool $save = true): array {
    $t0 = microtime(true);
    sr_install($pdo);
    $f = quality_page_fields($pageId);
    if (!$f) return ['ok' => false, 'why' => 'story page not found'];
    if (count($f['events']) < 1) return ['ok' => false, 'why' => 'no events'];
    // Timelines hold scheduled dates too (release days): on 1229 the first backfill wrote "As of
    // November 19, 2026, GTA 6 is reportedly released" on September 24. The status date is the
    // latest event that has HAPPENED, never later than today; future events go in marked as planned.
    $today = gmdate('Y-m-d');
    $lastPast = '';
    foreach ($f['events'] as $e) if ($e['date'] <= $today && $e['date'] > $lastPast && strncmp($e['date'], '0000', 4) !== 0) $lastPast = $e['date'];
    if ($asOf === '' || $asOf > $today) $asOf = $lastPast !== '' ? $lastPast : $today;
    $asOfTxt = sr_human_date($asOf);
    if ($asOfTxt === '') return ['ok' => false, 'why' => 'no usable date for the status line'];

    $events = $f['events'];
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    $tl = '';
    foreach ($events as $e) $tl .= "[{$e['date']}]" . ($e['date'] > $today ? ' (SCHEDULED: has not happened yet)' : '') . " {$e['title']}: {$e['desc']}" . ($e['who'] !== '' ? " (source: {$e['who']})" : '') . "\n";
    $fq = '';
    foreach ($f['faqs'] as $q) $fq .= "Q: {$q['q']}\nA: {$q['a']}\n";

    $sys = "You are GenZHype's status desk. New dated events reached this story's timeline after its summary was written, so the summary, status and FAQ may now be out of date or contradict the timeline. Update ONLY what the timeline makes out of date.\n"
         . "RULES:\n"
         . "1. Use ONLY facts in the TIMELINE and the CURRENT PAGE. Never add a name, number, date, quote, platform or claim they do not state.\n"
         . "2. summary: " . SR_SUMMARY_MIN . "-350 characters. Sentence 1 answers what happened and who. The last sentence starts 'As of {$asOfTxt},' and says where the story stands, from the LATEST dated events. Keep attribution ('according to <outlet>', 'reportedly', 'alleged') on any claim that is not a confirmed primary-source statement. If late events point different ways (e.g. a dismissal, then later activity), state each with its date and source instead of choosing; never call the story ongoing in text that reports it ended unless an event says it continues.\n"
         . "2b. An event marked SCHEDULED is a plan or announced date, not something that happened: say it is planned or expected, with its date.\n"
         . "3. lifecycle: resolved only when a timeline event reports an ending (ruling, dismissal, settlement, release, cancellation, accepted apology); dormant when the latest event is over 30 days old with nothing pending; otherwise ongoing.\n"
         . "4. meta_desc: " . SR_META_MIN . "-155 characters, one complete sentence. Return the current one unchanged unless it contradicts the latest events or reads cut off (not a complete sentence).\n"
         . "5. status_faq: a question people would search about where this stands now (e.g. 'Is the X lawsuit over?'); its answer starts 'As of {$asOfTxt},' in 1-2 sentences.\n"
         . "6. changed: false when the current summary already reflects the latest events and the right status; then return the current values.\n"
         . "Output STRICT JSON only: {\"summary\":\"...\",\"lifecycle\":\"ongoing|resolved|dormant\",\"meta_desc\":\"...\",\"status_faq\":{\"q\":\"...\",\"a\":\"...\"},\"changed\":true,\"why\":\"one short sentence\"}";
    $user = "TOPIC: {$f['primary_kw']}\nH1: {$f['h1']}\n\nCURRENT PAGE\nMETA: {$f['meta_desc']}\nSUMMARY: {$f['summary']}\nLIFECYCLE: {$f['lifecycle']}\nFAQ:\n{$fq}\nBACKGROUND:\n" . implode("\n", (array)$f['background'])
          . "\n\nTIMELINE (oldest first):\n{$tl}";

    $corpus = implode("\n", [$f['primary_kw'], $f['h1'], $f['title_tag'], $f['meta_desc'], $f['summary'], $fq, implode("\n", (array)$f['background']), $tl,
                            implode(' ', $f['sources']), $asOfTxt, $asOf, $f['last_date'], $today]);
    $hosts = [];
    foreach ($f['events'] as $e) foreach ([$e['host'], $e['who']] as $h) if ($h !== '') $hosts[] = preg_replace('/[^a-z0-9]/', '', mb_strtolower($h));
    // Two tries: a reply that breaks a rule is sent back once with the exact fault.
    $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]];
    for ($try = 0; ; $try++) {
        $res = ai_chat($msgs, ['gemini', 'openrouter', 'nvidia'], 0.2, 120, ['gemini/gemma-4-31b-it']);   // gemma took ~60 s a call here; flash-lite does this job
        if (isset($res['error'])) return ['ok' => false, 'why' => 'AI: ' . $res['error']];
        $j = ai_json((string)$res['content']);
        $fault = '';
        if (!is_array($j) || !isset($j['summary'])) {
            $fault = 'Your reply was not the JSON object asked for.';
        } else {
            if (isset($j['changed']) && !$j['changed']) return ['ok' => true, 'changed' => false, 'why' => (string)($j['why'] ?? 'already current'), 'secs' => round(microtime(true) - $t0)];
            $summary = trim((string)$j['summary']);
            $life = in_array($j['lifecycle'] ?? '', ['ongoing', 'resolved', 'dormant'], true) ? $j['lifecycle'] : $f['lifecycle'];
            // dormant means 30+ days without news (1226 was called dormant 3 days after its last event)
            if ($life === 'dormant' && $lastPast !== '' && strtotime(str_replace('-00', '-01', $lastPast)) > strtotime($today . ' -30 days')) $life = $f['lifecycle'];
            $meta = trim((string)($j['meta_desc'] ?? ''));
            $sq = trim((string)($j['status_faq']['q'] ?? '')); $sa = trim((string)($j['status_faq']['a'] ?? ''));
            $ml = mb_strlen($meta);
            if ($meta === '' || $ml < SR_META_MIN || $ml > SR_META_MAX) $meta = $f['meta_desc'];   // keep the old meta rather than a bad one
            $sl = mb_strlen($summary);
            $drift = sr_drift($summary . "\n" . $meta . "\n" . $sq . "\n" . $sa, $corpus, $hosts);
            if ($sl < SR_SUMMARY_MIN || $sl > SR_SUMMARY_MAX) $fault = "The summary is {$sl} characters; it must be " . SR_SUMMARY_MIN . '-350.';
            // the exact date: 1209 was written "As of September 24" (today) when its latest event was September 17
            elseif (mb_stripos($summary, "As of {$asOfTxt}") === false) $fault = "The summary's last sentence must start exactly 'As of {$asOfTxt},' (the latest event that has happened), not any other date.";
            elseif ($sa !== '' && mb_stripos($sa, "As of {$asOfTxt}") === false) $fault = "The status_faq answer must start exactly 'As of {$asOfTxt},', not any other date.";
            elseif ($drift) $fault = 'These are not in the timeline or the current page, so they may not appear: ' . implode(', ', array_slice($drift, 0, 8)) . '.';
        }
        if ($fault === '') break;
        if ($try >= 1) return ['ok' => false, 'why' => $fault, 'raw' => mb_substr((string)$res['content'], 0, 400)];
        $msgs[] = ['role' => 'assistant', 'content' => (string)$res['content']];
        $msgs[] = ['role' => 'user', 'content' => $fault . ' Return the corrected JSON only.'];
    }

    $out = ['ok' => true, 'changed' => true, 'why' => (string)($j['why'] ?? ''), 'summary' => $summary, 'lifecycle' => $life,
            'meta_desc' => $meta, 'status_faq' => ['q' => $sq, 'a' => $sa]];
    if (!$save) { $out['dry'] = true; $out['secs'] = round(microtime(true) - $t0); return $out; }

    $hist = $pdo->prepare("INSERT INTO page_text_history (page_id, field, old_val, new_val, reason, at) VALUES (?,?,?,?, 'status refresh', UTC_TIMESTAMP())");
    $pdo->beginTransaction();
    try {
        if ($summary !== $f['summary']) $hist->execute([$pageId, 'summary', $f['summary'], $summary]);
        if ($meta !== $f['meta_desc'])  $hist->execute([$pageId, 'meta_desc', $f['meta_desc'], $meta]);
        if ($life !== $f['lifecycle'])  $hist->execute([$pageId, 'lifecycle', $f['lifecycle'], $life]);
        $pdo->prepare("UPDATE pages SET summary=?, meta_desc=?, updated_at=NOW() WHERE id=?")->execute([$summary, $meta, $pageId]);
        $pdo->prepare("UPDATE dramas SET lifecycle=? WHERE id=?")->execute([$life, $f['drama_id']]);
        if ($sq !== '' && $sa !== '') {
            $fs = $pdo->prepare("SELECT id, question, answer, sort_order FROM faqs WHERE drama_id=? ORDER BY sort_order");
            $fs->execute([$f['drama_id']]);
            $all = $fs->fetchAll(PDO::FETCH_ASSOC);
            $hit = null;
            foreach ($all as $row) if (preg_match(SR_STATUS_FAQ_RX, (string)$row['question'])) { $hit = $row; break; }
            if ($hit) {
                $hist->execute([$pageId, 'faq:' . $hit['id'], "Q: {$hit['question']}\nA: {$hit['answer']}", "Q: {$sq}\nA: {$sa}"]);
                $pdo->prepare("UPDATE faqs SET question=?, answer=? WHERE id=?")->execute([mb_substr($sq, 0, 250), $sa, (int)$hit['id']]);
            } else {
                $first = $all ? (int)$all[0]['sort_order'] - 1 : 1;
                $pdo->prepare("INSERT INTO faqs (drama_id, question, answer, sort_order) VALUES (?,?,?,?)")->execute([$f['drama_id'], mb_substr($sq, 0, 250), $sa, $first]);
                $hist->execute([$pageId, 'faq:new', null, "Q: {$sq}\nA: {$sa}"]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
        return ['ok' => false, 'why' => 'save failed: ' . $e->getMessage()];
    }
    // the page changed, so its last editor verdict is about text that is gone
    $q = quality_check_drama($pageId);
    $out['judge'] = isset($q['error']) ? ['error' => $q['error']] : ['pass' => $q['pass'], 'scores' => $q['scores'], 'flags' => $q['flags']];
    $out['secs'] = round(microtime(true) - $t0);
    return $out;
}

/**
 * Stories whose timeline is newer than their summary and that have not been
 * refreshed since: more displayed events than the drafter wrote, or an event
 * dated after the page was made.
 */
function sr_stale_candidates(PDO $pdo, int $limit): array {
    sr_install($pdo);
    $rows = $pdo->query("SELECT p.id, d.id did, p.created_at,
          (SELECT r.verdict FROM ai_reviews r WHERE r.page_id=p.id AND r.stage='draft' ORDER BY r.id DESC LIMIT 1) dv,
          (SELECT COUNT(*) FROM events e WHERE e.drama_id=d.id AND e.video_only=0) n,
          (SELECT MAX(e.event_date) FROM events e WHERE e.drama_id=d.id AND e.video_only=0) last,
          (SELECT MAX(h.at) FROM page_text_history h WHERE h.page_id=p.id AND h.reason IN ('status refresh', 'status refresh: no change')) refreshed,
          (SELECT COUNT(*) FROM page_text_history h WHERE h.page_id=p.id AND h.reason='status refresh: refused') refusals
        FROM pages p JOIN dramas d ON d.page_id=p.id
        WHERE p.type='drama' AND p.status IN ('published','review')
        ORDER BY p.robots='index' DESC, p.published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        if ($r['refreshed'] || (int)$r['refusals'] >= 2) continue;   // done, or refused twice: leave it for a person
        $j = json_decode((string)$r['dv'], true);
        $grew = isset($j['events']) && (int)$r['n'] > (int)$j['events'];
        $newer = $r['last'] && strcmp((string)$r['last'], date('Y-m-d', strtotime((string)$r['created_at'] . ' +1 day'))) > 0;
        if ($grew || $newer) $out[] = (int)$r['id'];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** Batch entry (CLI + hourly tick). A "no change" answer is recorded so the page is not asked again. */
function sr_run(PDO $pdo, int $limit, int $maxSecs = 240, bool $save = true): array {
    $t0 = time();
    $stat = ['checked' => 0, 'refreshed' => 0, 'unchanged' => 0, 'refused' => 0, 'passed_editor' => 0];
    foreach (sr_stale_candidates($pdo, $limit) as $pid) {
        if (time() - $t0 > $maxSecs) break;
        $stat['checked']++;
        $r = drama_status_refresh($pdo, $pid, '', $save);
        if (!empty($r['changed'])) {
            $stat['refreshed']++;
            if (!empty($r['judge']['pass'])) $stat['passed_editor']++;
            echo "  status refresh {$pid}: " . mb_substr((string)$r['why'], 0, 110) . (isset($r['judge']['scores']) ? ' | editor ' . json_encode($r['judge']['scores']) : '') . "\n";
        } elseif (!empty($r['ok'])) {
            $stat['unchanged']++;
            if ($save) $pdo->prepare("INSERT INTO page_text_history (page_id, field, old_val, new_val, reason, at) VALUES (?, '(none)', NULL, NULL, 'status refresh: no change', UTC_TIMESTAMP())")->execute([$pid]);
        } else {
            $stat['refused']++;
            echo "  status refresh {$pid} kept the old text: {$r['why']}\n";
            if ($save) $pdo->prepare("INSERT INTO page_text_history (page_id, field, old_val, new_val, reason, at) VALUES (?, '(none)', NULL, ?, 'status refresh: refused', UTC_TIMESTAMP())")->execute([$pid, mb_substr((string)$r['why'], 0, 500)]);
        }
    }
    return $stat;
}
