<?php
// GenZHype | QUALITY DEPARTMENT — the AI EDITOR. One LLM call that scores the
// "smart" dimensions the paid tools charge for (Clearscope coverage, MarketMuse
// topic depth, Originality.ai fact-check, search-intent match, human-feel). Bolts
// onto gate_quality() as two extra departments. Best-effort: if the LLM is rate-
// limited it returns null and the algorithmic gate still stands.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

/** Returns ['intent','coverage','facts','human','overall','issues'(array),'verdict'] or null. */
function gate_quality_ai(int $page_id): ?array {
    $pdo = db();
    $page = $pdo->query("SELECT * FROM pages WHERE id=" . (int)$page_id)->fetch();
    if (!$page) return null;
    $isTerm = ($page['type'] === 'term');
    $row = $pdo->query("SELECT * FROM " . ($isTerm ? 'terms' : 'dramas') . " WHERE page_id=" . (int)$page_id)->fetch() ?: [];
    $jd = fn($v) => is_array($x = json_decode($v ?? '[]', true)) ? $x : [];

    $body = '';
    foreach (['meaning', 'origin', 'why_trending', 'background'] as $f)
        foreach ($jd($row[$f] ?? '') as $p) $body .= (is_string($p) ? $p : ($p['desc'] ?? '')) . "\n";
    $kw = $row['term'] ?? $row['title'] ?? $page['h1'];
    $srcHosts = [];
    foreach ($jd($row['sources'] ?? '') as $s) { $u = is_array($s) ? ($s['url'] ?? '') : $s; if ($u) $srcHosts[] = parse_url($u, PHP_URL_HOST) ?: $u; }
    $body = mb_substr(trim($body), 0, 4000);

    $sys = 'You are the CHIEF EDITOR of a Gen Z culture site, doing the final review before publish. '
        . 'Be strict and honest, like a real editor. Score each dimension 0-10. Output STRICT JSON only.';
    $user = "KEYWORD / TITLE: \"$kw\"\nSOURCES (hosts): " . implode(', ', array_slice($srcHosts, 0, 6)) . "\n\nDRAFT BODY:\n$body\n\n"
        . "Score 0-10:\n"
        . "- intent: does this fully answer what someone searching \"$kw\" actually wants?\n"
        . "- coverage: does it cover the key facets (meaning, origin, why it's used, examples) with real depth, not filler?\n"
        . "- facts: do the claims look accurate and supportable by sources like those listed (no invented facts)?\n"
        . "- human: does it read like a sharp human wrote it, NOT generic AI filler?\n"
        . "- overall: your editor's overall grade.\n"
        . "Return: {\"intent\":N,\"coverage\":N,\"facts\":N,\"human\":N,\"overall\":N,\"issues\":[\"short fixable problems\"],\"verdict\":\"approve|revise\"}";

    $res = ai_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.2);
    if (isset($res['error'])) return null;
    $j = ai_json($res['content'] ?? '');
    if (!$j || !isset($j['overall'])) return null;
    $clamp = fn($n) => max(0, min(10, (int)round((float)$n)));
    return [
        'intent'   => $clamp($j['intent'] ?? 0),
        'coverage' => $clamp($j['coverage'] ?? 0),
        'facts'    => $clamp($j['facts'] ?? 0),
        'human'    => $clamp($j['human'] ?? 0),
        'overall'  => $clamp($j['overall'] ?? 0),
        'issues'   => array_slice((array)($j['issues'] ?? []), 0, 5),
        'verdict'  => ($j['verdict'] ?? 'revise') === 'approve' ? 'approve' : 'revise',
        'provider' => $res['provider'] ?? '',
    ];
}

/** Turn the AI editor's scores into gate-department check rows (>=7/10 = pass). */
function gate_quality_ai_departments(int $page_id): array {
    $ai = gate_quality_ai($page_id);
    if (!$ai) return ['_ai_down' => true];
    $row = fn($label, $n, $w, $hard = false) => ['label' => $label, 'pass' => $n >= 7, 'detail' => "{$n}/10", 'weight' => $w, 'hard' => $hard];
    return [
        'AI Editor — Relevance' => [
            $row('search-intent match', $ai['intent'], 3, true),
            $row('topic coverage / depth', $ai['coverage'], 3, false),
        ],
        'AI Editor — Integrity' => [
            $row('facts supportable (no invention)', $ai['facts'], 3, true),
            $row('reads human, not AI filler', $ai['human'], 2, false),
            ['label' => "editor's verdict", 'pass' => $ai['verdict'] === 'approve', 'detail' => $ai['verdict'] . ($ai['issues'] ? ' — ' . implode('; ', array_slice($ai['issues'], 0, 2)) : ''), 'weight' => 2, 'hard' => false],
        ],
    ];
}
