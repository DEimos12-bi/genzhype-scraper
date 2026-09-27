<?php
// GenZHype | VERIFY stage. A DIFFERENT model adversarially audits the draft
// against its own sources: unsourced claims, missing alleged-framing, tone,
// source mismatch. Pass => status 'draft' -> 'review'. Never auto-publishes.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/framing_repair.php';   // FR_FRAMING_RX: the framing rule, owned by code

function verify_drama(int $page_id): array {
    $pdo = db();
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.background, d.why_matters, d.whats_next FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return ['error' => 'drama page not found'];
    $did = (int)$page['drama_id'];

    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.description, e.is_confirmed, e.source_id, s.url, s.publisher
                         FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? AND e.video_only=0 ORDER BY e.sort_order");
    $ev->execute([$did]);
    $events = $ev->fetchAll();

    // Full source excerpts so the verifier judges against the ACTUAL material.
    sources_install($pdo);   // sources.published_on (db.php)
    $sq = $pdo->prepare("SELECT DISTINCT s.id, s.publisher, s.excerpt, s.published_on
                         FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=?");
    $sq->execute([$did]);
    $srcBlock = "SOURCE MATERIAL (full excerpts):\n";
    foreach ($sq->fetchAll() as $s) {
        $srcBlock .= "[S{$s['id']}] {$s['publisher']}" . ($s['published_on'] ? " (published {$s['published_on']})" : '') . ': '
                   . ($s['excerpt'] ?: '(no excerpt stored)') . "\n\n";
    }

    // 2026-09-26 the checker was never told today's date (the editor has been since 09-24): it read "September 17"
    // as 2025 and called 2026 wrong. And it saw only article text, not the article's date, so an event dated by
    // its article ("Bustle reports ...") was "unsourced" (the Riot and TikTok rebuilds).
    $body = "TODAY'S DATE: " . gmdate('Y-m-d') . ". Every date on or before it is in the past: never treat a 2025 or 2026 date as future or invented because it is later than your training data.\n\n"
           . $srcBlock . "DRAFT UNDER AUDIT:\nTITLE: {$page['h1']}\nTITLE TAG: {$page['title_tag']}\nSUMMARY: {$page['summary']}\n\n";
    // 2026-09-27 every section a reader sees is checked, not only the timeline (owner: "Why it matters" was
    // unchecked AI analysis; the Rayman one put GTA 6 in October)
    if (trim((string)$page['why_matters']) !== '') $body .= "WHY IT MATTERS: {$page['why_matters']}\n\n";
    $next = (array)json_decode((string)$page['whats_next'], true);
    if ($next) { $body .= "WHAT HAPPENS NEXT:\n"; foreach ($next as $i => $nx) $body .= ($i + 1) . '. ' . (($nx['date'] ?? '') !== '' ? "[{$nx['date']}] " : '') . ($nx['text'] ?? '') . "\n"; $body .= "\n"; }
    $bg = array_filter((array)json_decode((string)$page['background'], true), 'is_string');
    if ($bg) $body .= "BACKGROUND:\n" . implode("\n", $bg) . "\n\n";
    $fq = $pdo->prepare("SELECT question, answer FROM faqs WHERE drama_id=? ORDER BY sort_order");
    $fq->execute([$did]);
    foreach ($fq->fetchAll() as $i => $q) $body .= ($i === 0 ? "FAQ:\n" : '') . 'Q' . ($i + 1) . ": {$q['question']}\nA" . ($i + 1) . ": {$q['answer']}\n";
    $body .= "\nEVENTS:\n";
    foreach ($events as $i => $e) {
        $n = $i + 1;
        $conf = $e['is_confirmed'] ? 'confirmed' : 'UNCONFIRMED';
        $src  = $e['source_id'] ? "S{$e['source_id']} ({$e['publisher']})" : 'NO SOURCE ATTACHED';
        $body .= "{$n}. [{$e['event_date']}] [{$conf}] {$e['title']}: {$e['description']}\n   cites: {$src}\n";
    }

    $sys = "You are an adversarial fact-check editor for a drama publication. You are given the FULL SOURCE MATERIAL and a DRAFT. Audit EVERY section (title, summary, why it matters, what happens next, background, FAQ answers, events) STRICTLY but fairly: a claim is properly sourced if it appears anywhere in the full source material, even if it cites a different source number. Flag ONLY real violations: 1) a claim that appears NOWHERE in the source material, including a detail, number, reason, channel or outcome the sources do not state, 2) an UNCONFIRMED event whose description neither names who says it ('according to <outlet or person>') nor uses alleged/reportedly/claims framing, 3) an event with no source attached, 4) clickbait/accusatory tone, 5) crime accusations stated as fact without an official action in the sources, 6) a detail, person or event from a DIFFERENT case or story than the draft's subject (type unrelated), 7) dates that contradict each other or the sources (type dates), 8) a TITLE or TITLE TAG that promises what the page does not give, for example 'Release Date' when the page gives no date, 'Explained' with no explanation, a number the page does not have (type title, section title). A source's '(published YYYY-MM-DD)' date is stated by that source: an event that is the report itself (for example '<outlet> reports ...') may carry it, and a relative day in its text ('on Monday', 'yesterday') is counted from it. For every issue copy the offending sentence from the draft WORD FOR WORD into \"sentence\" and name its section. Output STRICT JSON only: {\"ok\": true|false, \"issues\": [{\"section\": \"title|summary|why|next N|background|faq N|event N\", \"event\": n|null, \"type\": \"unsourced|framing|overreach|tone|legal|unrelated|dates\", \"sentence\": \"...\", \"detail\": \"...\"}]}. ok=true ONLY if zero issues.";

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $body],
    ], ['groq', 'nvidia'], 0.3, 75,   // 2026-09-27 Groq first (1 s), then Nemotron; OpenRouter's free models hung 120 s each
       ['groq/openai/gpt-oss-20b', 'groq/qwen/qwen3.8-27b', 'nvidia/nvidia/nemotron-3-nano-30b-a3b', 'nvidia_b/nvidia/nemotron-3-nano-30b-a3b']);   // not the writer (20b) or the small models
    if (isset($res['error'])) return $res;

    $v = ai_json($res['content']);
    if (!$v || !array_key_exists('ok', $v)) return ['error' => 'verifier did not return valid JSON', 'raw' => substr($res['content'], 0, 400)];

    // 2026-09-26 the framing rule belongs to code (FR_FRAMING_RX: framing_repair.php, drama_index_block): the checker
    // flagged Riot events framed 'according to Dexerto', which that rule accepts. Such a 'framing' issue is dropped.
    $issues = (array)($v['issues'] ?? []);
    $kept = array_values(array_filter($issues, function ($is) use ($events) {
        if (!is_array($is) || ($is['type'] ?? '') !== 'framing') return true;
        $e = $events[(int)($is['event'] ?? 0) - 1] ?? null;
        return !($e && preg_match('/' . FR_FRAMING_RX . '/i', (string)$e['description']));
    }));
    if (count($kept) < count($issues)) $v['dropped_framing'] = count($issues) - count($kept);
    $v['issues'] = $kept;
    $passed = (bool)$v['ok'] || ($issues && !$kept);
    ai_log($page_id, 'verify', $res, $v, $passed);

    if ($passed && $page['status'] === 'draft') {
        $pdo->prepare("UPDATE pages SET status='review' WHERE id=?")->execute([$page_id]);
    }
    return ['pass' => $passed, 'issues' => $v['issues'] ?? [], 'provider' => $res['provider'], 'status' => $passed ? 'review' : $page['status']];
}
