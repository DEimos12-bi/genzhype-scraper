<?php
// GenZHype | VERIFY stage. A DIFFERENT model adversarially audits the draft
// against its own sources: unsourced claims, missing alleged-framing, tone,
// source mismatch. Pass => status 'draft' -> 'review'. Never auto-publishes.

require_once __DIR__ . '/ai.php';

function verify_drama(int $page_id): array {
    $pdo = db();
    $p = $pdo->prepare("SELECT p.*, d.id drama_id, d.background FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$page_id]);
    $page = $p->fetch();
    if (!$page) return ['error' => 'drama page not found'];
    $did = (int)$page['drama_id'];

    $ev = $pdo->prepare("SELECT e.event_date, e.title, e.description, e.is_confirmed, e.source_id, s.url, s.publisher
                         FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? ORDER BY e.sort_order");
    $ev->execute([$did]);
    $events = $ev->fetchAll();

    // Full source excerpts so the verifier judges against the ACTUAL material.
    $sq = $pdo->prepare("SELECT DISTINCT s.id, s.publisher, s.excerpt
                         FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=?");
    $sq->execute([$did]);
    $srcBlock = "SOURCE MATERIAL (full excerpts):\n";
    foreach ($sq->fetchAll() as $s) {
        $srcBlock .= "[S{$s['id']}] {$s['publisher']}: " . ($s['excerpt'] ?: '(no excerpt stored)') . "\n\n";
    }

    $body = $srcBlock . "DRAFT UNDER AUDIT:\nTITLE: {$page['h1']}\nSUMMARY: {$page['summary']}\n\nEVENTS:\n";
    foreach ($events as $i => $e) {
        $n = $i + 1;
        $conf = $e['is_confirmed'] ? 'confirmed' : 'UNCONFIRMED';
        $src  = $e['source_id'] ? "S{$e['source_id']} ({$e['publisher']})" : 'NO SOURCE ATTACHED';
        $body .= "{$n}. [{$e['event_date']}] [{$conf}] {$e['title']}: {$e['description']}\n   cites: {$src}\n";
    }

    $sys = "You are an adversarial fact-check editor for a drama publication. You are given the FULL SOURCE MATERIAL and a DRAFT. Audit STRICTLY but fairly: an event is properly sourced if its claim appears anywhere in the full source material, even if it cites a different source number. Flag ONLY real violations: 1) a claim that appears NOWHERE in the source material, 2) an UNCONFIRMED event whose description lacks alleged/reportedly/claims framing, 3) an event with no source attached, 4) clickbait/accusatory tone, 5) crime accusations stated as fact without an official action in the sources. Output STRICT JSON only: {\"ok\": true|false, \"issues\": [{\"event\": n|null, \"type\": \"unsourced|framing|overreach|tone|legal\", \"detail\": \"...\"}]}. ok=true ONLY if zero issues.";

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $body],
    ], ['openrouter', 'nvidia', 'gemini']); // different default provider than draft
    if (isset($res['error'])) return $res;

    $v = ai_json($res['content']);
    if (!$v || !array_key_exists('ok', $v)) return ['error' => 'verifier did not return valid JSON', 'raw' => substr($res['content'], 0, 400)];

    $passed = (bool)$v['ok'];
    ai_log($page_id, 'verify', $res, $v, $passed);

    if ($passed && $page['status'] === 'draft') {
        $pdo->prepare("UPDATE pages SET status='review' WHERE id=?")->execute([$page_id]);
    }
    return ['pass' => $passed, 'issues' => $v['issues'] ?? [], 'provider' => $res['provider'], 'status' => $passed ? 'review' : $page['status']];
}
