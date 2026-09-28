<?php
// GenZHype | ACCURACY (owner 2026-09-27, after an outside site check): every sentence a reader sees is
// backed by a source, every timeline date is a real day that has passed, and a page that cannot meet that
// is held, whatever the editor says.
//   acc_fix_dates()   month-only, year-only and missing dates leave the timeline for the background ("In
//                     August 2026, ..."); an event dated after the page was written was a plan: still ahead,
//                     it moves to What happens next; its day passed unconfirmed, it leaves the page.
//   acc_tie()         each sentence the fact check or the code check (backing.php) nominates must be tied to words an AI copies
//                     from the sources, and code confirms those words are really there.
//   acc_remove()      takes out the sentences no source passage supports, and past plans written as still ahead; an event, FAQ or
//                     section left empty goes with them.
//   acc_run()         the pipeline step: dates, fact check, tie, remove, fact check again.
//   acc_hard_fails()  what holds a page (drama_index_block, gate_check_drama).
// Every change is copied to storage/accuracy/ first.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/backing.php';
require_once __DIR__ . '/story_context.php';

const ACC_SUMMARY_MIN = 120;   // a summary shorter than this after removals holds the page (the writer's own floor)

/** The day a page was written: its public date (content_updated_at moves only with a new dated event). */
function acc_written(array $p): string {
    return substr((string)max((string)$p['published_at'], (string)($p['content_updated_at'] ?? '')), 0, 10);
}

function acc_page(PDO $pdo, int $pageId): ?array {
    $st = $pdo->prepare("SELECT p.id, p.h1, p.summary, p.published_at, p.content_updated_at, d.id did, d.background, d.why_matters, d.whats_next
                         FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=? AND p.type='drama'");
    $st->execute([$pageId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A copy of what acc_* is about to change. */
function acc_backup(PDO $pdo, int $pageId, string $why): void {
    try {
        require_once __DIR__ . '/rebuild.php';
        $dir = dirname(__DIR__) . '/storage/accuracy';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $src = rebuild_backup($pdo, $pageId, 'accuracy-tmp');
        rename($src, "{$dir}/{$pageId}-" . gmdate('Ymd-His') . "-{$why}.json");
    } catch (Throwable $e) { error_log("acc_backup {$pageId}: " . $e->getMessage()); }
}

/** Timeline dates put right. ['to_background' => n, 'to_next' => n, 'plans_removed' => n]. */
function acc_fix_dates(PDO $pdo, int $pageId): array {
    $out = ['to_background' => 0, 'to_next' => 0, 'plans_removed' => 0];
    $p = acc_page($pdo, $pageId);
    if (!$p) return $out;
    $written = acc_written($p); $today = gmdate('Y-m-d');
    $evs = $pdo->query("SELECT id, event_date, title, description FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0")->fetchAll(PDO::FETCH_ASSOC);
    $bad = array_filter($evs, fn($e) => str_ends_with((string)$e['event_date'], '-00') || (string)$e['event_date'] > $written);
    if (!$bad) return $out;
    acc_backup($pdo, $pageId, 'dates');
    $bg = array_values(array_filter((array)json_decode((string)$p['background'], true), 'is_string'));
    $next = (array)json_decode((string)$p['whats_next'], true);
    $del = $pdo->prepare("DELETE FROM events WHERE id=?");
    foreach ($bad as $e) {
        $d = (string)$e['event_date']; $desc = trim((string)$e['description']) ?: trim((string)$e['title']);
        $ym = substr($d, 0, 7);   // a month-only (or year-only) date still ahead is a plan, not background
        if (str_ends_with($d, '-00') && $d !== '0000-00-00' && (substr($d, 5, 2) === '00' ? substr($d, 0, 4) > substr($today, 0, 4) : $ym > substr($today, 0, 7))) {
            $next[] = ['date' => $d, 'text' => $desc];
            $out['to_next']++;
        } elseif (str_ends_with($d, '-00')) {
            [$label] = story_event_date($d);   // "Aug 2026", "2026" or '' (0000)
            $when = $label === '' ? '' : 'In ' . (preg_match('/^\d{4}$/', $label) ? $label : date('F Y', strtotime(substr($d, 0, 8) . '01'))) . ', ';
            $first = strtok($desc, ' ');
            if ($when !== '' && in_array($first, ['According', 'The', 'A', 'An', 'After', 'On', 'In', 'During', 'Reports', 'Reportedly'], true)) $desc = lcfirst($desc);
            $bg[] = $when . $desc;
            $out['to_background']++;
        } elseif ($d > $today) {
            $next[] = ['date' => $d, 'text' => $desc];
            $out['to_next']++;
        } else {
            $out['plans_removed']++;
        }
        $del->execute([(int)$e['id']]);
    }
    $pdo->prepare("UPDATE dramas SET background=?, whats_next=? WHERE id=?")
        ->execute([json_encode($bg, JSON_UNESCAPED_UNICODE), $next ? json_encode(array_values($next), JSON_UNESCAPED_UNICODE) : null, (int)$p['did']]);
    $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);
    return $out;
}

/** Text for comparing a quote with a source: lower case, straight quotes, single spaces. */
function acc_norm(string $t): string {
    $t = mb_strtolower(html_entity_decode($t, ENT_QUOTES));
    $t = str_replace(['’', '‘', '“', '”', '–', '—', "\u{00a0}"], ["'", "'", '"', '"', '-', '-', ' '], $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/**
 * The suspects (back_unbacked) an AI can tie to the sources by copying the supporting words, which code then
 * finds in the source text; the rest are returned as unsupported. ['unsupported' => [...], 'tied' => n] or
 * ['error' => ...] when no AI answered (the suspects stay open: the page holds until the tie runs).
 */
function acc_tie(PDO $pdo, int $pageId, array $suspects): array {
    if (!$suspects) return ['unsupported' => [], 'tied' => 0];
    $p = acc_page($pdo, $pageId);
    $src = $pdo->query("SELECT DISTINCT s.id, s.publisher, s.excerpt FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=" . (int)$p['did'])->fetchAll(PDO::FETCH_ASSOC);
    $block = ''; $corpus = '';
    foreach ($src as $s) { $block .= "[S{$s['id']}] {$s['publisher']}: {$s['excerpt']}\n\n"; $corpus .= ' ' . $s['excerpt']; }
    $corpus = acc_norm($corpus);
    $list = '';
    foreach (array_values($suspects) as $i => $x) $list .= ($i + 1) . '. ' . $x['sentence'] . "\n";
    $res = ai_chat([
        ['role' => 'system', 'content' => 'For each numbered SENTENCE, find the passage in the SOURCES that supports what it says. Copy that passage '
            . 'WORD FOR WORD, 8 to 40 words, exactly as it appears in the source. If no source supports the sentence, or only supports part '
            . 'of it, return an empty quote. Never paraphrase and never quote the sentence itself. Output STRICT JSON only: '
            . '{"items":[{"n":1,"quote":"..."}]}'],
        ['role' => 'user', 'content' => "SOURCES:\n{$block}\nSENTENCES:\n{$list}"],
    ], ['groq', 'nvidia'], 0.0, 75, ['groq/openai/gpt-oss-20b', 'groq/qwen/qwen3.8-27b', 'nvidia/nvidia/nemotron-3-nano-30b-a3b', 'nvidia_b/nvidia/nemotron-3-nano-30b-a3b']);
    if (isset($res['error'])) return ['error' => $res['error']];
    $j = ai_json((string)$res['content']);
    if (!$j || !isset($j['items'])) return ['error' => 'tie reply unreadable'];
    $quotes = [];
    foreach ((array)$j['items'] as $it) $quotes[(int)($it['n'] ?? 0)] = (string)($it['quote'] ?? '');
    $unsupported = []; $tied = 0; $backed = [];
    foreach (array_values($suspects) as $i => $x) {
        $q = acc_norm($quotes[$i + 1] ?? '');
        // the quote must really be in the sources, and be about the sentence (share its content words)
        $ok = mb_strlen($q) >= 30 && mb_strpos($corpus, $q) !== false;
        if ($ok) {
            $sw = array_unique(back_words(back_strip_dates($x['sentence'])));
            $qw = array_flip(back_words($q));
            $ok = $sw && count(array_filter($sw, fn($w) => isset($qw[$w]))) / count($sw) >= 0.3;
        }
        if (!$ok) { $unsupported[] = $x + ['why' => 'no source passage supports it']; continue; }
        $tied++;
        foreach ($src as $sr) if (mb_strpos(acc_norm((string)$sr['excerpt']), $q) !== false) { $backed[] = $x + ['publisher' => (string)$sr['publisher']]; break; }
    }
    return ['unsupported' => $unsupported, 'tied' => $tied, 'backed' => $backed, 'model' => $res['provider'] . '/' . $res['model']];
}

/** The fact check sometimes copies the whole timeline line it was shown: "2. [2026-09-14] [UNCONFIRMED] Title: text". */
function acc_strip_line(string $s): string {
    return trim(preg_replace('/^\s*\d+\.\s*\[[^\]]*\]\s*(?:\[[^\]]*\]\s*)?(?:[^:]{1,160}:\s+)?/u', '', $s));
}

/** Sentence-level removal from one text; the text with every given sentence taken out. */
function acc_cut(string $text, array $sentences): string {
    foreach ($sentences as $s) {
        $s = trim($s);
        if ($s === '') continue;
        $pos = mb_strpos($text, $s);
        if ($pos === false) {   // the AI's copy may differ in quotes or spacing: match sentence by sentence
            foreach (back_sentences($text) as $cand) if (acc_norm($cand) === acc_norm($s) || (mb_strlen($s) > 40 && str_contains(acc_norm($cand), acc_norm(mb_substr($s, 0, 60))))) { $s = $cand; $pos = mb_strpos($text, $cand); break; }
        }
        if ($pos !== false) $text = mb_substr($text, 0, $pos) . mb_substr($text, $pos + mb_strlen($s));
    }
    return trim(preg_replace('/\s{2,}/u', ' ', $text));
}

/**
 * Take the given sentences off the page. $items: ['section','key'?,'sentence'] with section summary|why|next|
 * background|faq|event (key = faq id / event id / list index), or the fact check's own section names
 * ("faq 2", "event 3", "next 1"; the number is the position on the page). ['removed' => n, 'events_dropped' => n, ...].
 */
/**
 * A summary built from the timeline alone, for when the cuts leave too little: what remains of it, then the first and the
 * latest development, each dated and named by the outlet that reported it (no AI, nothing the timeline does not hold).
 * $eventIds: the events still on the page.
 */
function acc_summary_from_timeline(PDO $pdo, int $did, string $left, array $eventIds): string {
    require_once __DIR__ . '/page_rules.php';
    if (!$eventIds) return trim($left);
    $rows = $pdo->query("SELECT e.event_date date, e.title, COALESCE(s.publisher, '') `by` FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.id IN (" . implode(',', array_map('intval', $eventIds)) . ") AND e.event_date <= UTC_DATE() AND e.event_date NOT LIKE '%-00'
                         ORDER BY e.event_date, e.sort_order")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return trim($left);
    $first = $rows[0]; $last = end($rows);
    $by = fn(array $r) => trim((string)preg_replace('/\s*\(original post\)\s*$/i', '', (string)$r['by']));
    $lines = [trim($left)];
    if ($first !== $last) $lines[] = 'It began on ' . pr_date_label((string)$first['date']) . ($by($first) !== '' ? ', as ' . $by($first) . ' reported' : '') . ': '
                                   . rtrim(pr_past_title((string)$first['title']), ' .') . '.';
    $lines[] = pr_latest_sentence($last);
    return trim(implode(' ', array_filter($lines)));
}

function acc_remove(PDO $pdo, int $pageId, array $items): array {
    $out = ['removed' => 0, 'events_dropped' => 0, 'faqs_dropped' => 0, 'why_dropped' => false, 'not_found' => 0];
    if (!$items) return $out;
    $p = acc_page($pdo, $pageId);
    if (!$p) return $out;
    acc_backup($pdo, $pageId, 'sentences');
    $did = (int)$p['did'];
    $evs = $pdo->query("SELECT id, description FROM events WHERE drama_id={$did} AND video_only=0 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    $faqs = $pdo->query("SELECT id, answer FROM faqs WHERE drama_id={$did} ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    $summary = (string)$p['summary']; $why = (string)$p['why_matters'];
    $bg = array_values(array_filter((array)json_decode((string)$p['background'], true), 'is_string'));
    $next = array_values((array)json_decode((string)$p['whats_next'], true));
    $evText = array_column($evs, 'description', 'id'); $faqText = array_column($faqs, 'answer', 'id');
    $seen = [];
    foreach ($items as $it) {
        $sec = strtolower(trim((string)($it['section'] ?? ''))); $s = (string)($it['sentence'] ?? '');
        $s = acc_strip_line($s);
        if ($s === '' || isset($seen[$k = acc_norm($s)])) continue;   // the same sentence from the fact check and the tie
        $seen[$k] = 1;
        $n = preg_match('/(\d+)/', $sec, $m) ? (int)$m[1] : 0;
        $before = [$summary, $why, json_encode($bg), json_encode($next), json_encode($evText), json_encode($faqText)];
        if (str_starts_with($sec, 'summary')) $summary = acc_cut($summary, [$s]);
        elseif (str_starts_with($sec, 'why')) $why = acc_cut($why, [$s]);
        elseif (str_starts_with($sec, 'background')) foreach ($bg as $i => $b) $bg[$i] = acc_cut($b, [$s]);
        elseif (str_starts_with($sec, 'next')) foreach ($next as $i => $nx) { if (acc_norm((string)($nx['text'] ?? '')) !== '' && ($t = acc_cut((string)$nx['text'], [$s])) !== $nx['text']) $next[$i]['text'] = $t; }
        elseif (str_starts_with($sec, 'faq')) {
            $id = isset($it['key']) && isset($faqText[$it['key']]) ? $it['key'] : ($faqs[$n - 1]['id'] ?? null);
            if ($id !== null) $faqText[$id] = acc_cut($faqText[$id], [$s]);
        } elseif (str_starts_with($sec, 'event')) {
            $id = isset($it['key']) && isset($evText[$it['key']]) ? $it['key'] : ($evs[$n - 1]['id'] ?? null);
            if ($id !== null) $evText[$id] = acc_cut($evText[$id], [$s]);
        }
        $after = [$summary, $why, json_encode($bg), json_encode($next), json_encode($evText), json_encode($faqText)];
        $before === $after ? $out['not_found']++ : $out['removed']++;
    }
    $bg = array_values(array_filter($bg, fn($b) => mb_strlen(trim($b)) >= 25));
    $next = array_values(array_filter($next, fn($nx) => mb_strlen(trim((string)($nx['text'] ?? ''))) >= 20));
    if (mb_strlen(trim($why)) < 40) { $out['why_dropped'] = trim((string)$p['why_matters']) !== ''; $why = ''; }
    // a summary the cuts leave too short is filled from the timeline itself, never left empty (2026-09-28: page 1760's
    // summary went from two sentences to nothing, and page 928's the same way the night before)
    if ($summary !== (string)$p['summary'] && mb_strlen(trim($summary)) < ACC_SUMMARY_MIN) {
        $kept = array_filter($evText, fn($t) => mb_strlen(trim((string)$t)) >= 20);
        $summary = acc_summary_from_timeline($pdo, $did, $summary, array_keys($kept));
    }
    $pdo->prepare("UPDATE pages SET summary=?, updated_at=NOW() WHERE id=?")->execute([$summary, $pageId]);
    $pdo->prepare("UPDATE dramas SET why_matters=?, background=?, whats_next=? WHERE id=?")
        ->execute([$why !== '' ? $why : null, json_encode($bg, JSON_UNESCAPED_UNICODE), $next ? json_encode($next, JSON_UNESCAPED_UNICODE) : null, $did]);
    $upE = $pdo->prepare("UPDATE events SET description=? WHERE id=?"); $delE = $pdo->prepare("DELETE FROM events WHERE id=?");
    foreach ($evText as $id => $t) if ($t !== ($evs[array_search($id, array_column($evs, 'id'))]['description'] ?? null)) {
        if (mb_strlen(trim($t)) < 20) { $delE->execute([$id]); $out['events_dropped']++; } else $upE->execute([$t, $id]);
    }
    $upF = $pdo->prepare("UPDATE faqs SET answer=? WHERE id=?"); $delF = $pdo->prepare("DELETE FROM faqs WHERE id=?");
    foreach ($faqText as $id => $t) if ($t !== ($faqs[array_search($id, array_column($faqs, 'id'))]['answer'] ?? null)) {
        if (mb_strlen(trim($t)) < 20) { $delF->execute([$id]); $out['faqs_dropped']++; } else $upF->execute([$t, $id]);
    }
    return $out;
}

/**
 * Sentences written as still ahead about a day that has already passed (owner #7: "no future tense for events
 * that already happened"): "a trailer is scheduled for September 22" read on September 27.
 */
function acc_outdated(PDO $pdo, int $pageId): array {
    $p = acc_page($pdo, $pageId);
    if (!$p) return [];
    $today = gmdate('Y-m-d'); $year = substr(acc_written($p), 0, 4);
    $future = '/\b(will|is scheduled|are scheduled|scheduled (?:for|to)|is set to|are set to|is expected to|are expected to|upcoming|plans to|is due|are due|is slated|are slated)\b/i';
    $parts = [['summary', 0, (string)$p['summary']], ['why', 0, (string)$p['why_matters']]];
    foreach ((array)json_decode((string)$p['background'], true) as $i => $bg) if (is_string($bg)) $parts[] = ['background', $i, $bg];
    foreach ($pdo->query("SELECT id, answer FROM faqs WHERE drama_id=" . (int)$p['did']) as $f) $parts[] = ['faq', (int)$f['id'], (string)$f['answer']];
    foreach ($pdo->query("SELECT id, description FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0") as $e) $parts[] = ['event', (int)$e['id'], (string)$e['description']];
    $out = [];
    foreach ($parts as [$section, $key, $text]) foreach (back_sentences($text) as $sent) {
        if (!preg_match($future, $sent)) continue;
        if (!preg_match_all('/\b' . BACK_MONTHS . '\s+(\d{1,2})(?!\d)(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?/i', $sent, $mm, PREG_SET_ORDER)) continue;
        $latest = '';   // every date in it has passed ("as of Sep 15, it is scheduled for Dec 3" is still right)
        foreach ($mm as $m) {
            $ts = strtotime(preg_replace('/[^A-Za-z]/', '', $m[0]) . ' ' . $m[1] . ' ' . (($m[2] ?? '') ?: $year));
            if ($ts) $latest = max($latest, date('Y-m-d', $ts));
        }
        if ($latest !== '' && $latest < $today) $out[] = ['section' => $section, 'key' => $key, 'sentence' => $sent, 'why' => 'written as still ahead about a day that has passed'];
    }
    return $out;
}

/**
 * Sentences that credit an outlet the page does not list as a source (owner 2026-09-27, #6: "every outlet named in the
 * text must be in the source list"): "according to ComicBook.com, ..." on a page that never cites ComicBook. An outlet
 * is a name our sources table knows as a site; a person or a company named that way is not checked.
 */
function acc_unlisted_outlets(PDO $pdo, int $pageId): array {
    static $known = null;
    $norm = fn(string $x) => preg_replace('/(com|net|org|couk|tv|gg)$/', '', preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/^the\s+/i', '', $x))));
    if ($known === null) {
        $known = [];
        foreach ($pdo->query("SELECT DISTINCT domain, publisher FROM sources WHERE domain IS NOT NULL AND domain<>''") as $r)
            foreach ([preg_replace('/^www\./', '', (string)$r['domain']), (string)$r['publisher']] as $x)
                if (!preg_match('/(original post|twitter|tiktok|youtube|instagram|reddit|facebook|threads|twitch|bluesky|x\.com)/i', $x) && strlen($k = $norm($x)) >= 3) $known[$k] = 1;
    }
    $p = acc_page($pdo, $pageId);
    if (!$p) return [];
    $mine = [];
    foreach ($pdo->query("SELECT DISTINCT s.domain, s.publisher FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=" . (int)$p['did']) as $r)
        foreach ([preg_replace('/^www\./', '', (string)$r['domain']), (string)$r['publisher']] as $x) if (($k = $norm($x)) !== '') $mine[$k] = 1;
    $parts = [['summary', 0, (string)$p['summary']], ['why', 0, (string)$p['why_matters']]];
    foreach ((array)json_decode((string)$p['background'], true) as $i => $bg) if (is_string($bg)) $parts[] = ['background', $i, $bg];
    foreach ($pdo->query("SELECT id, answer FROM faqs WHERE drama_id=" . (int)$p['did']) as $f) $parts[] = ['faq', (int)$f['id'], (string)$f['answer']];
    foreach ($pdo->query("SELECT id, description FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0") as $e) $parts[] = ['event', (int)$e['id'], (string)$e['description']];
    $name = "((?:[Tt]he\s+)?[A-Z][\w.&'’-]*(?:\s+[A-Z][\w.&'’-]*){0,4})";
    $out = [];
    foreach ($parts as [$section, $key, $text]) foreach (back_sentences($text) as $sent) {
        preg_match_all("/\b(?:according to|reported by|as reported by|per|via|told)\s+{$name}/u", $sent, $a);
        preg_match_all("/{$name}\s+(?:reported|reports|noted|notes|wrote|writes|published|cited)\b/u", $sent, $b);
        foreach (array_merge($a[1], $b[1]) as $cand) {
            $k = $norm($cand);
            if ($k === '' || !isset($known[$k])) continue;   // not a site we know: a person or a company, not checked
            $listed = false;
            foreach (array_keys($mine) as $m) if (str_contains($m, $k) || str_contains($k, $m)) { $listed = true; break; }
            if (!$listed) { $out[] = ['section' => $section, 'key' => $key, 'sentence' => $sent, 'outlet' => $cand, 'why' => "credits {$cand}, which the source list does not have"]; break; }
        }
    }
    return $out;
}

/**
 * A title the fact check faulted for promising what the page does not give (owner 2026-09-27, #5: Skyblivion's "Release
 * Date and Final Marketing Push Timeline" had no date), or that breaks the owner's title rules 1 and 5 (page_rules.php).
 * Title, title tag and description rewritten from the summary and timeline, same address; code re-checks the answer
 * against the rules and asks once more if it still breaks one. True when a new title was saved.
 */
function acc_retitle(PDO $pdo, int $pageId, string $problem): bool {
    require_once __DIR__ . '/page_rules.php';
    $p = acc_page($pdo, $pageId);
    if (!$p) return false;
    $meta = (string)$pdo->query("SELECT meta_desc FROM pages WHERE id={$pageId}")->fetchColumn();
    $lane = (string)$pdo->query("SELECT lane FROM dramas WHERE id=" . (int)$p['did'])->fetchColumn();
    $ev = implode("\n", $pdo->query("SELECT CONCAT(event_date, ': ', title) FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN));
    $src = implode("\n", $pdo->query("SELECT DISTINCT s.excerpt FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=" . (int)$p['did'])->fetchAll(PDO::FETCH_COLUMN));
    $st = pr_story_state($pdo, $pageId);
    $over = $st && pr_story_over($st['lifecycle'], $st['last'], $st['next']);
    [$srcDates] = pr_page_status_facts($pdo, (int)$p['did']);
    // the owner's title rules (1 and 5): hooks yes, lies no; code checks the answer before it is kept
    $sys = 'Rewrite a news page title and its search description so they say only what the page contains. A hook is allowed, a lie is not: '
         . 'no promise the page does not keep, no question, sentence case. A death, a crime or violence is never stated as fact: attribute it '
         . '("police say", "alleged", "<name> says", "clip shows") or quote the person. A quote is words a source really says, in quotation marks, '
         . 'with the speaker named next to it. The description states only what the sources say: a status in it names its source and that source\'s date, and nothing is credited to anyone the sources do not quote. '
         . ($over ? 'This story is over: write a recap of what happened, in the past tense; never "announces", "reveals", "will", "ongoing" or "developing". ' : '')
         . 'Output STRICT JSON only: {"h1":"40 to 70 characters","title_tag":"40 to 60 characters","meta_desc":"120 to 155 characters"}';
    $why = $problem;
    for ($try = 0; $try < 2; $try++) {
        $r = ai_chat([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => "CURRENT TITLE: {$p['h1']}\nCURRENT DESCRIPTION: {$meta}\nPROBLEM: {$why}\nSUMMARY: {$p['summary']}\nTIMELINE:\n{$ev}"],
        ], ['groq', 'nvidia'], 0.2, 75, ['groq/openai/gpt-oss-20b', 'groq/qwen/qwen3.8-27b', 'nvidia/nvidia/nemotron-3-nano-30b-a3b', 'nvidia_b/nvidia/nemotron-3-nano-30b-a3b']);
        $j = isset($r['error']) ? null : ai_json((string)$r['content']);
        $h1 = trim((string)($j['h1'] ?? '')); $tt = trim((string)($j['title_tag'] ?? '')); $md = trim((string)($j['meta_desc'] ?? ''));
        if (mb_strlen($h1) < 20 || mb_strlen($h1) > 90 || mb_strlen($tt) < 40 || mb_strlen($tt) > 60) continue;
        if (mb_strlen($md) < 110 || mb_strlen($md) > 160) $md = $meta;   // a description out of range: the old one stays, and is checked below
        $left = [];
        foreach (['title' => $h1, 'title tag' => $tt, 'description' => $md] as $where => $t) {
            foreach (pr_headline_problems($t, $src, $where, $lane) as $x) $left[] = $x;
            if ($over && preg_match(PR_ANNOUNCE_RX, $t, $m)) $left[] = "rule 1: the {$where} still says \"{$m[1]}\" about a story that is over";
            if ($over && preg_match(PR_ACTIVE_RX, $t, $m)) $left[] = "rule 1: the {$where} still calls the story \"{$m[1]}\"";
            foreach (back_sentences($t) ?: [$t] as $sent) if (preg_match('/\bAs of\b/', $sent) && !pr_asof_sourced($sent, $srcDates)) $left[] = "rule 1: the {$where} dates a status by no source it names";
        }
        if ($left) { $why = $problem . '; your last answer still broke a rule: ' . implode('; ', $left); continue; }
        acc_backup($pdo, $pageId, 'title');
        $pdo->prepare("UPDATE pages SET h1=?, title_tag=?, meta_desc=?, updated_at=NOW() WHERE id=?")->execute([$h1, $tt, $md, $pageId]);
        $pdo->prepare("UPDATE dramas SET title=? WHERE id=?")->execute([$h1, (int)$p['did']]);
        return true;
    }
    return false;   // nothing that passes the rules: the old title stays, and its hard fail holds the page
}

/**
 * A sentence a source backs but in other words names that source (owner 2026-09-27: "a sentence that is true but worded
 * differently gets 'according to [outlet]' instead of being deleted"). Events, FAQ answers and background only; the
 * summary stays clean and our take is ours. A sentence that already names who says it is left as it is.
 */
function acc_attribute(PDO $pdo, int $pageId, array $backed): int {
    $p = acc_page($pdo, $pageId);
    if (!$p || !$backed) return 0;
    $did = (int)$p['did']; $n = 0;
    $bg = array_values(array_filter((array)json_decode((string)$p['background'], true), 'is_string'));
    $bgChanged = false;
    foreach ($backed as $b) {
        $sent = (string)$b['sentence']; $pub = trim(preg_replace('/\s*\((original post)\)$/i', '', (string)($b['publisher'] ?? '')));
        if ($pub === '' || preg_match('/\b(according to|reported|reports|said|says|told|per|wrote|claims?|alleg|announced|confirmed|stated)\b/i', $sent)) continue;
        $with = preg_replace('/([.!?])?\s*$/u', ", according to {$pub}$1", rtrim($sent), 1);
        if ($b['section'] === 'event' && isset($b['key'])) {
            $st = $pdo->prepare("UPDATE events SET description=REPLACE(description, ?, ?) WHERE id=? AND drama_id=?");
            $st->execute([$sent, $with, (int)$b['key'], $did]); $n += $st->rowCount();
        } elseif ($b['section'] === 'faq' && isset($b['key'])) {
            $st = $pdo->prepare("UPDATE faqs SET answer=REPLACE(answer, ?, ?) WHERE id=? AND drama_id=?");
            $st->execute([$sent, $with, (int)$b['key'], $did]); $n += $st->rowCount();
        } elseif ($b['section'] === 'background') {
            foreach ($bg as $i => $para) if (str_contains($para, $sent)) { $bg[$i] = str_replace($sent, $with, $para); $bgChanged = true; $n++; break; }
        }
    }
    if ($bgChanged) $pdo->prepare("UPDATE dramas SET background=? WHERE id=?")->execute([json_encode($bg, JSON_UNESCAPED_UNICODE), $did]);
    return $n;
}

/** The latest stored fact check if it was made after the page last changed, as verify_drama() returns it; else null. */
function acc_fresh_verify(PDO $pdo, int $pageId): ?array {
    $st = $pdo->prepare("SELECT r.passed, r.verdict, r.provider FROM ai_reviews r JOIN pages p ON p.id=r.page_id
                         WHERE r.page_id=? AND r.stage='verify' AND r.created_at >= p.updated_at ORDER BY r.id DESC LIMIT 1");
    $st->execute([$pageId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $j = json_decode((string)$r['verdict'], true) ?: [];
    return ['pass' => (int)$r['passed'] === 1, 'issues' => $j['issues'] ?? [], 'provider' => $r['provider'], 'reused' => true];
}

/**
 * The accuracy step (story_checks.php, rebuilds, and the live-page sweep). Returns the report and the final
 * fact check ('verify'), which the caller uses as its fact-check result.
 */
function acc_run(PDO $pdo, int $pageId): array {
    require_once __DIR__ . '/verify.php';
    require_once __DIR__ . '/page_rules.php';
    $rep = ['dates' => acc_fix_dates($pdo, $pageId)];
    $rep['rules'] = rules_fix_page($pdo, $pageId)['changed'] ?? [];   // what the owner's rules fix by code alone, before the fact check reads the page
    $v = acc_fresh_verify($pdo, $pageId) ?? verify_drama($pageId);   // a run cut short resumes here
    // 2026-09-27 nothing is removed on the fact check's word alone: on Rayman it quoted a whole event for one
    // invented sentence and the event went. The fact check and the code check (backing.php) only NOMINATE
    // single sentences; a sentence is removed when no source passage can be found for it, and code checks
    // that passage is really in the sources (acc_tie). A detail from a different case is not removed: the
    // failed fact check holds the page for a person.
    $cand = [];
    foreach ((array)($v['issues'] ?? []) as $i) {
        if (!is_array($i) || !in_array($i['type'] ?? '', ['unsourced', 'overreach'], true)) continue;
        if (str_starts_with(strtolower((string)($i['section'] ?? '')), 'description')) continue;   // the description is rewritten below, not cut
        $q = acc_strip_line((string)($i['sentence'] ?? ''));
        foreach (back_sentences($q) ?: ($q !== '' ? [$q] : []) as $one) $cand[acc_norm($one)] = ['section' => (string)($i['section'] ?? ''), 'sentence' => $one];
    }
    foreach (back_unbacked($pdo, $pageId) as $u) $cand[acc_norm($u['sentence'])] ??= $u;
    $rep['nominated'] = count($cand);
    $tie = acc_tie($pdo, $pageId, array_values($cand));
    $rep['tie'] = isset($tie['error']) ? ['error' => $tie['error']] : ['tied' => $tie['tied'], 'unsupported' => count($tie['unsupported'])];
    $cut = $tie['unsupported'] ?? [];   // no tie answer: nothing is cut, the page holds until the next run
    // "Our take" (why_matters) is our analysis, labelled ours (owner 2026-09-27): it needs no quote, and the fact check judges
    // only the facts it states; a sentence stating a fact the sources lack or contradict goes (Rayman's "an October
    // dominated by GTA 6" against "games moved away from GTA 6"). The code check counts only new names and numbers in it.
    foreach ((array)($v['issues'] ?? []) as $i)
        if (is_array($i) && str_starts_with(strtolower((string)($i['section'] ?? '')), 'why') && trim((string)($i['sentence'] ?? '')) !== '')
            $cut[] = ['section' => 'why', 'sentence' => (string)$i['sentence']];
    $unl = acc_unlisted_outlets($pdo, $pageId);   // a claim credited to a source the page does not cite
    $rep['unlisted_outlets'] = count($unl);
    foreach ($unl as $u) $cut[] = $u;
    $old = acc_outdated($pdo, $pageId);
    $rep['outdated'] = count($old);
    foreach ($old as $o) $cut[] = $o;
    $rep['removal'] = acc_remove($pdo, $pageId, $cut);
    $rep['attributed'] = acc_attribute($pdo, $pageId, $tie['backed'] ?? []);
    $rep['retitled'] = false;
    $titleWhy = [];
    foreach ((array)($v['issues'] ?? []) as $i)
        if (is_array($i) && ($i['type'] ?? '') === 'title') { $titleWhy[] = (string)($i['detail'] ?? ''); break; }
    // owner 2026-09-28: the description Google shows is fact-checked like the body; a faulted one is rewritten with the title
    foreach ((array)($v['issues'] ?? []) as $i)
        if (is_array($i) && str_starts_with(strtolower((string)($i['section'] ?? '')), 'description')) $titleWhy[] = 'the description: ' . (string)($i['detail'] ?? $i['sentence'] ?? '');
    // rules 1 and 5 (owner 2026-09-27): a title or description that says "announces" about the past, calls an ended story
    // ongoing, or states a death or crime as fact is rewritten, and code checks the new one before it is kept
    foreach (pr_hard_fails($pdo, $pageId) as $r) if (preg_match('/^rule [15]: the (title|title tag|description|title or description)\b/', $r)) $titleWhy[] = $r;
    if ($titleWhy) $rep['retitled'] = acc_retitle($pdo, $pageId, implode('; ', $titleWhy));
    if ($rep['removal']['removed'] > 0 || array_sum($rep['dates']) > 0 || $rep['retitled']) $v = verify_drama($pageId);   // the page as it now stands
    // the final check can fault the title or the description when the first did not (2026-09-28: page 1760's "the court says
    // he remains incarcerated" was caught only here, after the one rewrite had passed): it gets that rewrite, then one check
    if (!$rep['retitled'] && ($v['pass'] ?? true) === false) {
        $late = [];
        foreach ((array)($v['issues'] ?? []) as $i)
            if (is_array($i) && (($i['type'] ?? '') === 'title' || str_starts_with(strtolower((string)($i['section'] ?? '')), 'description')))
                $late[] = (str_starts_with(strtolower((string)($i['section'] ?? '')), 'description') ? 'the description: ' : '') . (string)($i['detail'] ?? $i['sentence'] ?? '');
        if ($late && ($rep['retitled'] = acc_retitle($pdo, $pageId, implode('; ', $late)))) $v = verify_drama($pageId);
    }
    $rep['verify'] = $v;
    acc_log($pdo, $pageId, (int)$rep['removal']['removed']);
    return $rep;
}

/** One accuracy run: how many unsupported sentences came out (owner 2026-09-27: the weekly average per page should fall
 *  as the writer quotes its sources; admin > Editor check shows it). */
function acc_log(PDO $pdo, int $pageId, int $removed): void {
    static $ready = false;
    try {
        if (!$ready) { $pdo->exec("CREATE TABLE IF NOT EXISTS accuracy_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_id INT UNSIGNED NOT NULL,
                                  removed INT NOT NULL, run_at DATETIME NOT NULL, KEY idx_run (run_at)) ENGINE=InnoDB"); $ready = true; }
        $pdo->prepare("INSERT INTO accuracy_log (page_id, removed, run_at) VALUES (?,?,UTC_TIMESTAMP())")->execute([$pageId, $removed]);
    } catch (Throwable $e) { error_log('acc_log: ' . $e->getMessage()); }
}

/**
 * What holds a story whatever the editor says (owner 2026-09-27). [] = nothing; else the reasons.
 * $render: also check the rendered citations (costs a render; the publish door and the build pass true).
 */
function acc_hard_fails(PDO $pdo, int $pageId, bool $render = true): array {
    $p = acc_page($pdo, $pageId);
    if (!$p) return ['not a story page'];
    $why = [];
    $written = acc_written($p);
    $dates = $pdo->query("SELECT event_date FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0")->fetchAll(PDO::FETCH_COLUMN);
    if (array_filter($dates, fn($d) => str_ends_with((string)$d, '-00'))) $why[] = 'a timeline date that is not a real day (month-only, year-only or missing)';
    if (array_filter($dates, fn($d) => (string)$d > $written)) $why[] = 'a timeline event dated after the page was written (a plan, not a past event)';
    if (mb_strlen(trim((string)$p['summary'])) < ACC_SUMMARY_MIN) $why[] = 'the summary is under ' . ACC_SUMMARY_MIN . ' characters';
    if ($u = acc_unlisted_outlets($pdo, $pageId)) $why[] = 'the text credits ' . $u[0]['outlet'] . ', which the source list does not have';
    require_once __DIR__ . '/page_rules.php';
    foreach (pr_hard_fails($pdo, $pageId) as $r) $why[] = $r;   // the owner's rules 1, 5 and 6 (page_rules.php)
    $v = $pdo->query("SELECT passed FROM ai_reviews WHERE page_id={$pageId} AND stage='verify' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($v === false) $why[] = 'never fact-checked';
    elseif ((int)$v !== 1) $why[] = 'the latest fact check found unsupported or wrong statements';
    if ($render) {
        require_once __DIR__ . '/gate.php';
        $html = (string)render_page_html($pageId);
        if (preg_match('#<main\b.*?</main>#is', $html, $m)) {
            preg_match_all('#\[(\d+)\]#', strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $m[0])), $c);
            $listed = preg_match('#<(section|div|ol)[^>]*(id|class)="[^"]*source[^"]*"[^>]*>.*?</(section|ol)>#s', $m[0], $sl) ? preg_match_all('#<li#', $sl[0]) : 0;
            if ($c[1] && max(array_map('intval', $c[1])) > $listed) $why[] = 'a cited source number is missing from the source list';
        }
    }
    return $why;
}

/**
 * The live pages, put through the accuracy step a few at a time (cli.php accuracy sweep; the hourly run once the
 * owner resumes it). First the pages Google can see, then the rest; a page whose fact check is fresh and passing is
 * skipped. After each page the index rules decide (drama_index_block): it reopens only if everything passes.
 * $maxSecs: no page STARTS after this many seconds; one page can take ~5 minutes of AI (Skyblivion, 2026-09-27).
 */
function acc_sweep(PDO $pdo, int $maxSecs = 240, int $limit = 50): array {
    require_once __DIR__ . '/gate.php';
    $t0 = time(); $out = ['done' => 0, 'clean' => 0, 'held' => 0, 'reopened' => 0, 'removed' => 0, 'lines' => []];
    $ids = $pdo->query("SELECT p.id FROM pages p JOIN dramas d ON d.page_id=p.id
                        WHERE p.type='drama' AND p.status='published' AND p.retry_block IS NULL   -- held for good (page_rules.php pr_redo_allowed)
                          AND NOT EXISTS (SELECT 1 FROM ai_reviews r WHERE r.page_id=p.id AND r.stage='verify' AND r.passed=1 AND r.created_at >= p.updated_at)
                        ORDER BY COALESCE((SELECT r2.passed FROM ai_reviews r2 WHERE r2.page_id=p.id AND r2.stage='verify' ORDER BY r2.id DESC LIMIT 1), 0) ASC,   -- failed or never checked first (held ones)
                                 (p.robots='index') DESC, p.id DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $pid) {
        if (time() - $t0 > $maxSecs) break;
        $pdo = db_alive();
        $was = (string)$pdo->query("SELECT robots FROM pages WHERE id=" . (int)$pid)->fetchColumn();
        $r = acc_run($pdo, (int)$pid);
        $pdo = db_alive();
        $why = drama_index_block($pdo, (int)$pid);
        $now = $why === '' ? 'index' : 'noindex';
        if ($now !== $was) $pdo->prepare("UPDATE pages SET robots=?, updated_at=NOW() WHERE id=?")->execute([$now, (int)$pid]);
        $out['done']++; $out['removed'] += $r['removal']['removed'];
        if ($why === '') $out['clean']++;
        if ($was === 'index' && $now === 'noindex') $out['held']++;
        if ($was === 'noindex' && $now === 'index') $out['reopened']++;
        $line = "{$pid}: " . ($r['removal']['removed'] ? "{$r['removal']['removed']} sentence(s) out, " : '')
            . 'fact check ' . (isset($r['verify']['error']) ? 'not run' : (($r['verify']['pass'] ?? false) ? 'pass' : 'fail'))
            . " | Google: {$was} -> {$now}" . ($why !== '' ? ' (' . mb_substr($why, 0, 90) . ')' : '');
        $out['lines'][] = $line;
        echo "  {$line}\n";   // as it goes: a run cut short still shows what it did
    }
    return $out;
}

/**
 * THE RE-CHECK OF LIVE PAGES (owner 2026-09-27: "re-judge live pages in batches of 20 and report after each batch; don't
 * flip everything at once"). Each page gets the accuracy step and the calibrated editor, then the index rules decide
 * whether Google may see it. Order: allegation and death pages first (the owner's Human check list, live or waiting),
 * then the other live stories, newest first; new stories get all of it in the build. A batch of 20 is fixed when it
 * starts; after its 20th page a report is written to storage/recheck/batch-N.json (admin > Editor check shows it).
 * When the editor cannot answer (Gemini's daily quota) the page waits and the run stops: nothing is judged by another
 * model. No page starts after $maxSecs. Pages waiting for the owner (status review) are checked but not published.
 */
function acc_recheck(PDO $pdo, int $maxSecs = 900): array {
    require_once __DIR__ . '/gate.php';
    require_once __DIR__ . '/quality.php';
    $dir = dirname(__DIR__) . '/storage/recheck';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $sf = "{$dir}/state.json";
    $st = is_file($sf) ? (json_decode((string)file_get_contents($sf), true) ?: []) : [];
    $t0 = time(); $out = ['checked' => 0, 'batches_done' => [], 'stopped' => ''];
    while (time() - $t0 <= $maxSecs) {
        if (empty($st['pages'])) {   // a new batch of 20: pages not re-checked yet under the 2026-09-27 rules
            $ids = $pdo->query("SELECT p.id FROM pages p JOIN dramas d ON d.page_id=p.id
                WHERE p.type='drama' AND (p.status='published' OR (p.status='review' AND p.human_review='needed')) AND p.retry_block IS NULL
                  AND NOT EXISTS (SELECT 1 FROM accuracy_log a WHERE a.page_id=p.id)
                  AND NOT EXISTS (SELECT 1 FROM ai_reviews r WHERE r.page_id=p.id AND r.stage='quality' AND r.verdict LIKE '%reader view%'
                                  AND r.created_at >= '2026-09-27 17:00:00')
                ORDER BY (p.human_review IN ('needed','approved')) DESC, p.published_at DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) { $out['stopped'] = 'every live page is re-checked'; break; }
            $st = ['batch' => (int)($st['batch'] ?? 0) + 1, 'pages' => array_map('intval', $ids), 'done' => [], 'started' => gmdate('c')];
            file_put_contents($sf, json_encode($st, JSON_PRETTY_PRINT));
        }
        $todo = array_values(array_diff($st['pages'], array_map('intval', array_keys($st['done']))));
        if (!$todo) {   // the batch is complete: its report
            $rows = array_values($st['done']);
            $rep = ['batch' => $st['batch'], 'started' => $st['started'], 'finished' => gmdate('c'), 'pages' => count($rows),
                    'editor_pass' => count(array_filter($rows, fn($r) => $r['editor'] === 'pass')),
                    'fact_check_pass' => count(array_filter($rows, fn($r) => $r['fact_check'] === 'pass')),
                    'open_to_google' => count(array_filter($rows, fn($r) => $r['google'] === 'index')),
                    'reopened' => count(array_filter($rows, fn($r) => $r['was'] === 'noindex' && $r['google'] === 'index')),
                    'closed' => count(array_filter($rows, fn($r) => $r['was'] === 'index' && $r['google'] === 'noindex')),
                    'sentences_removed' => array_sum(array_column($rows, 'removed')), 'detail' => $st['done']];
            file_put_contents("{$dir}/batch-{$st['batch']}.json", json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $out['batches_done'][] = $rep;
            echo "  BATCH {$rep['batch']} DONE: {$rep['pages']} pages, editor pass {$rep['editor_pass']}, fact check pass {$rep['fact_check_pass']}, "
               . "open to Google {$rep['open_to_google']} (reopened {$rep['reopened']}, closed {$rep['closed']}), {$rep['sentences_removed']} sentences out\n";
            $st = ['batch' => $st['batch']];
            file_put_contents($sf, json_encode($st, JSON_PRETTY_PRINT));
            continue;
        }
        $pid = $todo[0];
        $pdo = db_alive();
        $p = $pdo->query("SELECT status, robots FROM pages WHERE id=" . $pid)->fetch(PDO::FETCH_ASSOC);
        $acc = acc_run($pdo, $pid);
        $pdo = db_alive();
        $q = quality_check_drama($pid);
        if (isset($q['error'])) { $out['stopped'] = 'editor unavailable, pages wait: ' . mb_substr($q['error'], 0, 120); break; }
        $pdo = db_alive();
        $now = $p['robots'];
        if ($p['status'] === 'published') {
            $now = drama_index_block($pdo, $pid) === '' ? 'index' : 'noindex';
            if ($now !== $p['robots']) $pdo->prepare("UPDATE pages SET robots=?, updated_at=NOW() WHERE id=?")->execute([$now, $pid]);
        }
        $st['done'][$pid] = ['was' => $p['robots'], 'google' => $p['status'] === 'published' ? $now : 'waiting for the owner',
                             'fact_check' => isset($acc['verify']['error']) ? 'not run' : (($acc['verify']['pass'] ?? false) ? 'pass' : 'fail'),
                             'editor' => $q['pass'] ? 'pass' : 'fail', 'average' => $q['average'], 'removed' => (int)$acc['removal']['removed']];
        file_put_contents($sf, json_encode($st, JSON_PRETTY_PRINT));
        $out['checked']++;
        echo "  {$pid}: fact check {$st['done'][$pid]['fact_check']}, editor {$st['done'][$pid]['editor']} ({$q['average']}), "
           . "{$st['done'][$pid]['removed']} sentence(s) out | Google {$p['robots']} -> {$st['done'][$pid]['google']}\n";
    }
    return $out;
}
