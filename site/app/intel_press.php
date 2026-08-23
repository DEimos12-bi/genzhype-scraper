<?php
/**
 * INTEL PRESS + DAILY BRIEF (r79) — the multi-source "any type of data" arm.
 *
 * Sweeps dated press for platform/algorithm/creator-meta news through the
 * proven reach layer (keyless Exa), stores each dated find once, and then has
 * the free-AI rotation write ONE short daily brief over everything new that
 * arrived today (press + the forum posts intel_forums.py sent in + trends).
 * The brief lands in comp_rule scope='video', rule_key='daily_brief' —
 * versioned like every learned rule, with the source URLs as evidence.
 *
 * This is deliberately evidence-first: raw finds are stored verbatim with
 * their URLs, and the AI only SUMMARISES what was found — it never invents a
 * finding, and every line of the brief can be traced to a stored URL.
 */
declare(strict_types=1);

require_once __DIR__ . '/reach.php';

const IP_QUERIES = [
    // platform mechanics — the rules of the game changing under us
    'YouTube Shorts algorithm change update creators',
    'TikTok algorithm update creators reach',
    'Instagram Reels update creators reach',
    // craft meta — what practitioners say is working
    'short form video what works creators strategy',
    // the beat itself
    'streamer drama internet culture news this week',
];

function ip_store(PDO $pdo, string $kind, array $f): bool {
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO intel_find (kind, url, title, source, published, score, note, found_at)
         VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $ins->execute([$kind, mb_substr((string)$f['url'], 0, 490),
        mb_substr((string)$f['title'], 0, 390),
        mb_substr((string)($f['source'] ?? (parse_url((string)$f['url'], PHP_URL_HOST) ?: '')), 0, 110),
        mb_substr((string)($f['published'] ?? ''), 0, 19),
        (int)($f['score'] ?? 0),
        mb_substr((string)($f['note'] ?? ''), 0, 1500)]);
    return $ins->rowCount() > 0;
}

/** Press sweep: only DATED results from the last ~10 days are worth keeping. */
function ip_sweep(PDO $pdo): array {
    $out = ['queries' => 0, 'stored' => 0];
    $cut = date('Y-m-d', time() - 10 * 86400);
    foreach (IP_QUERIES as $q) {
        $out['queries']++;
        foreach (reach_exa_search($q, 5) as $h) {
            $pub = (string)($h['published'] ?? '');
            if ($pub === '' || $pub < $cut) continue;
            $note = trim(preg_replace('/\s+/', ' ', (string)($h['text'] ?? '')));
            if (ip_store($pdo, 'press', ['url' => $h['url'], 'title' => $h['title'],
                    'published' => $pub, 'note' => mb_substr($note, 0, 800)])) {
                $out['stored']++;
            }
        }
    }
    return $out;
}

/** One bounded AI call: brief the day's NEW finds. Fails closed to no brief. */
function ip_daily_brief(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT kind, title, source, published, score, LEFT(note, 300) note, url
         FROM intel_find WHERE found_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 26 HOUR)
         ORDER BY kind, score DESC LIMIT 40")->fetchAll();
    if (count($rows) < 3) return ['brief' => false, 'reason' => 'fewer than 3 new finds'];

    require_once __DIR__ . '/ai.php';
    $lines = [];
    foreach ($rows as $r) {
        $lines[] = sprintf('[%s|%s|%s] %s — %s', $r['kind'], $r['source'],
            $r['published'] ?: 'n/a', $r['title'], $r['note']);
    }
    $resp = ai_chat([
        ['role' => 'system', 'content' =>
            'You brief the operator of GenZHype, an automated internet-culture '
            . 'video publisher (YouTube Shorts, TikTok, Instagram Reels). From the '
            . 'raw finds below, write a plain-English brief: (1) anything that '
            . 'changes HOW we should publish (algorithm/platform changes), '
            . '(2) what practitioners report working right now, (3) anything '
            . 'time-sensitive to act on. Only use what is in the finds — never '
            . 'invent. 120 words maximum. If nothing matters, say so in one line.'],
        ['role' => 'user', 'content' => implode("\n", $lines)],
    ]);
    $text = trim((string)($resp['content'] ?? ''));
    if ($text === '' || isset($resp['error'])) {
        return ['brief' => false, 'reason' => 'AI unavailable: ' . ($resp['error'] ?? 'empty')];
    }
    $urls = array_slice(array_column($rows, 'url'), 0, 12);
    require_once __DIR__ . '/video_intel.php';
    vi_put_rule($pdo, 'daily_brief',
        ['date' => gmdate('Y-m-d'), 'brief' => $text, 'finds' => count($rows)],
        70, implode(' | ', $urls));
    return ['brief' => true, 'finds' => count($rows)];
}
