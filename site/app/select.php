<?php
// GenZHype | SELECT stage. One cheap AI judgment per candidate:
// is this a real, sourceable creator drama worth a timeline page? Y/N + angle.
// Updates candidates.status: new -> selected | rejected (with reason).

require_once __DIR__ . '/ai.php';

/**
 * Compact list of situations we ALREADY cover (drama page titles + already-selected
 * candidate names) so the editor can reject same-story duplicates. The LLM matches
 * semantically — "Reckless Ben" vs "Ben Schneider" vs "the $200K Lego case" — which
 * the old exact-title dedupe in discovery could never catch.
 */
function select_existing_context(PDO $pdo): string {
    $lines = [];
    foreach ($pdo->query("SELECT h1 FROM pages WHERE type='drama' AND status IN ('published','draft','review')
                          ORDER BY updated_at DESC LIMIT 90")->fetchAll(PDO::FETCH_COLUMN) as $t) $lines[] = '- ' . $t;
    foreach ($pdo->query("SELECT name FROM candidates WHERE status='selected' AND type='drama'
                          ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_COLUMN) as $n) $lines[] = '- ' . $n;
    return implode("\n", array_slice(array_values(array_unique($lines)), 0, 110));
}

function select_candidate(int $cand_id, ?string $existing = null): array {
    $pdo = db();
    $c = $pdo->prepare("SELECT * FROM candidates WHERE id=?");
    $c->execute([$cand_id]);
    $cand = $c->fetch();
    if (!$cand) return ['error' => 'candidate not found'];
    if ($cand['status'] !== 'new') return ['skip' => "already {$cand['status']}"];
    if ($existing === null) $existing = select_existing_context($pdo);

    $signals = json_decode($cand['signals'] ?? '{}', true) ?: [];
    $context = '';
    foreach (['news_title', 'desc_excerpt', 'selftext_excerpt'] as $k) {
        if (!empty($signals[$k])) $context .= "{$k}: {$signals[$k]}\n";
    }

    $sys = "You are the GenZHype assignment editor. Decide if a candidate is worth building into a sourced, dated CREATOR/INFLUENCER DRAMA timeline page for a US Gen Z audience. SELECT only if: it is about an internet creator/streamer/influencer (YouTube/TikTok/Twitch/Kick/OnlyFans/podcast), there is a real dispute/controversy/event (not just a product launch, game update, or generic celebrity gossip), and it is plausibly sourceable. REJECT: pure product/tech/gaming news, sports, mainstream-celebrity-only stories with no creator angle, vague viral clips with no named people, anything not a 'situation'. CRITICAL — REJECT AS DUPLICATE: if the candidate is the SAME situation as one already in EXISTING PAGES (same person/people AND same core controversy), set build=false even when the headline is worded differently or only adds a minor update. We keep ONE evolving timeline per situation, never multiple pages for the same story. When unsure if two refer to the same situation, treat as duplicate. Output STRICT JSON only: {\"build\": true|false, \"reason\": \"<12 words\", \"angle\": \"the timeline angle if build\", \"primary_people\": [\"name\"], \"search_query\": \"best web search to find sources\"}.";

    $user = "CANDIDATE: {$cand['name']}\nSOURCE: " . ($signals['source'] ?? '?') . "\nHEAT: {$cand['heat_score']}\n{$context}\n"
          . "EXISTING PAGES (reject the candidate if it is the same situation as any of these):\n" . ($existing ?: '(none yet)');

    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], ['gemini', 'openrouter', 'nvidia'], 0.1);
    if (isset($res['error'])) return $res;

    $j = ai_json($res['content']);
    if (!$j || !array_key_exists('build', $j)) return ['error' => 'select returned bad JSON'];

    $build = (bool)$j['build'];
    if ($build) {
        $pdo->prepare("UPDATE candidates SET status='selected', angle=?, ai_verdict=? WHERE id=?")
            ->execute([mb_substr($j['angle'] ?? $cand['angle'] ?? '', 0, 250), json_encode($j, JSON_UNESCAPED_SLASHES), $cand_id]);
    } else {
        $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=?, ai_verdict=? WHERE id=?")
            ->execute([mb_substr($j['reason'] ?? 'not a creator drama', 0, 250), json_encode($j, JSON_UNESCAPED_SLASHES), $cand_id]);
    }
    ai_log(null, 'selection', $res, $j, $build);

    return [
        'build'   => $build,
        'reason'  => $j['reason'] ?? '',
        'angle'   => $j['angle'] ?? '',
        'people'  => $j['primary_people'] ?? [],
        'query'   => $j['search_query'] ?? '',
        'provider'=> $res['provider'],
    ];
}

// Run SELECT across all 'new' candidates (newest/hottest first), capped.
function select_run(int $limit = 25): array {
    $pdo = db();
    $existing = select_existing_context($pdo);   // built once; grows as we select within the batch
    $rows = $pdo->query("SELECT id, name FROM candidates WHERE status='new' ORDER BY heat_score DESC, id DESC LIMIT {$limit}")->fetchAll();
    $sel = 0; $rej = 0; $err = 0; $out = [];
    foreach ($rows as $r) {
        $res = select_candidate((int)$r['id'], $existing);
        if (isset($res['error'])) { $err++; continue; }
        if (isset($res['skip']))  continue;
        if ($res['build']) { $sel++; $existing .= "\n- " . $r['name']; }  // dedupe the rest of THIS batch against it
        else $rej++;
        $out[] = ['id' => (int)$r['id']] + $res;
    }
    return ['selected' => $sel, 'rejected' => $rej, 'errors' => $err, 'detail' => $out];
}
