<?php
/**
 * GenZHype | THE PROVING GROUND — organ 10 of the learning machine (2026-08-23)
 * =============================================================================
 * WHAT THIS IS. No change reaches the owner on argument alone. Every proposal
 * the Reflector writes is first replayed against videos whose real outcome we
 * already know, and arrives at his desk carrying a verdict: SUPPORTED,
 * CONTRADICTED, or UNTESTED by our own evidence.
 *
 * WHY IT EXISTS. Tonight the Reflector proposed "put a specific person's name
 * in every hook", citing outliers. Our own posted videos say something else:
 *   1199 views  "OBSCENE CONTENT UNLEASHED?"      no name
 *   1129 views  "JAW BREAK MAY END HER CAREER?"   no name
 *      8 views  "ETHAN KLEIN SUES IDUBBBZ FOR $10M"  two names
 * A plausible, well-argued, evidence-citing proposal that the evidence may not
 * support. Approve it and every future hook changes. That is precisely the
 * reward-hacking failure mode the literature warns about: 73.8% of
 * self-improving optimisations improve a proxy while the real thing gets worse.
 *
 * THE GOLDEN SET is not opinion — it is our own posted videos labelled by what
 * actually happened to them, compared against OUR OWN median (never a global
 * benchmark; a small account only competes with itself). The owner's verdicts
 * join the same set as they arrive and outrank the numbers when present.
 *
 * COST. One AI call per test, no rendering, no GPU. That is the whole point:
 * testing a decision is cheap, testing a render is not.
 *
 * WHAT IT IS NOT. Not an approver. It produces evidence; the owner still rules.
 * A CONTRADICTED verdict does not block him - it tells him what he is
 * overruling.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

const PG_MIN_CASES = 8;     // below this the set cannot separate anything honestly
const PG_SIDE      = 6;     // winners / losers shown per side

/**
 * The golden set: every posted video we have a real number for, with its hook,
 * labelled against our own median. Frozen only in the sense that the past does
 * not change — it grows as more videos are posted and as the owner judges them.
 */
function pg_cases(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT pv.page_id, MAX(m.views) views, MAX(m.likes) likes,
                vs.hook, vs.tpl, p.h1 title
           FROM platform_videos pv
           JOIN platform_metrics m ON m.video_id = pv.id
           LEFT JOIN video_scripts vs ON vs.page_id = pv.page_id
           LEFT JOIN pages p ON p.id = pv.page_id
          WHERE vs.hook IS NOT NULL AND vs.hook <> ''
          GROUP BY pv.page_id
          HAVING views > 0
          ORDER BY views DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < PG_MIN_CASES) return ['cases' => $rows, 'median' => 0, 'enough' => false];

    $v = array_map(fn($r) => (int)$r['views'], $rows);
    sort($v);
    $mid = intdiv(count($v), 2);
    $median = count($v) % 2 ? $v[$mid] : (int)round(($v[$mid - 1] + $v[$mid]) / 2);

    // The owner's own verdicts outrank the numbers wherever he has spoken.
    $verdicts = [];
    try {
        foreach ($pdo->query("SELECT page_id, judgment FROM work_record
                              WHERE kind='video' AND judgment IS NOT NULL") as $r) {
            $j = json_decode((string)$r['judgment'], true);
            if (!empty($j['owner_verdict']['verdict'])) {
                $verdicts[(int)$r['page_id']] = $j['owner_verdict'];
            }
        }
    } catch (Throwable $e) {}

    foreach ($rows as &$r) {
        $pid = (int)$r['page_id'];
        $r['label'] = (int)$r['views'] >= $median ? 'above_median' : 'below_median';
        if (isset($verdicts[$pid])) {
            $r['owner'] = (string)$verdicts[$pid]['verdict'];
            $r['owner_reasons'] = (array)($verdicts[$pid]['reasons'] ?? []);
            $r['label'] = $r['owner'] === 'good' ? 'owner_good'
                        : ($r['owner'] === 'bad' ? 'owner_bad' : $r['label']);
        }
    }
    unset($r);
    return ['cases' => $rows, 'median' => $median, 'enough' => true];
}

/**
 * Replay one proposed directive against the golden set.
 *
 * The test is deliberately blunt and honest: show the model our best and worst
 * performing hooks WITH their real numbers, state the proposed rule, and ask
 * whether following that rule would have produced the winners — or whether our
 * own evidence points the other way. It must answer UNTESTED when the set
 * cannot tell, which is the most common honest answer at this volume.
 *
 * @return array{verdict:string, confidence:int, why:string, tested_on:int, detail:string}
 */
function pg_prove(PDO $pdo, string $directive): array {
    $g = pg_cases($pdo);
    if (!$g['enough']) {
        return ['verdict' => 'UNTESTED', 'confidence' => 0, 'tested_on' => count($g['cases']),
                'why' => 'Only ' . count($g['cases']) . ' posted videos have real numbers; '
                       . PG_MIN_CASES . ' is the floor for an honest comparison.',
                'detail' => ''];
    }
    $cases = $g['cases'];
    $top = array_slice($cases, 0, PG_SIDE);
    $bot = array_slice($cases, -PG_SIDE);

    $fmt = function (array $rs): string {
        $s = '';
        foreach ($rs as $r) {
            $s .= '  ' . str_pad((string)$r['views'], 5, ' ', STR_PAD_LEFT) . ' views | "'
                . trim((string)$r['hook']) . '"'
                . (isset($r['owner']) ? '  [OWNER SAID: ' . strtoupper((string)$r['owner'])
                    . (empty($r['owner_reasons']) ? '' : ' — ' . implode(', ', $r['owner_reasons'])) . ']' : '')
                . "\n";
        }
        return $s;
    };

    $sys = 'You are testing a proposed rule against real performance data from ONE small '
         . 'account. You are not being asked whether the rule sounds good — you are being '
         . 'asked whether THIS account\'s own results support it. '
         . 'Be willing to say CONTRADICTED when the winners break the rule, and UNTESTED '
         . 'when the data genuinely cannot tell (small samples usually cannot). Saying '
         . 'UNTESTED is a correct, valuable answer, not a failure. '
         . 'A verdict marked [OWNER SAID] is ground truth and outranks the view counts. '
         . 'Output STRICT JSON: {"verdict":"SUPPORTED|CONTRADICTED|UNTESTED",'
         . '"confidence":0-100,"why":"2-3 plain sentences a non-technical owner can act on",'
         . '"detail":"the specific hooks that support or break the rule"}';

    $usr = "PROPOSED RULE:\n" . $directive . "\n\n"
         . "OUR BEST-PERFORMING VIDEOS (median across all " . count($cases) . " is {$g['median']} views):\n"
         . $fmt($top) . "\nOUR WORST-PERFORMING VIDEOS:\n" . $fmt($bot)
         . "\nQuestion: if this rule had been in force, would it have produced the winners "
         . "and avoided the losers? Or do the winners break the rule?";

    $res = ai_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
                   ['gemini', 'openrouter', 'nvidia'], 0.2);
    if (isset($res['error'])) {
        return ['verdict' => 'UNTESTED', 'confidence' => 0, 'tested_on' => count($cases),
                'why' => 'The brain could not be reached to run the test.', 'detail' => ''];
    }
    $j = ai_json($res['content']);
    $v = strtoupper((string)($j['verdict'] ?? ''));
    if (!in_array($v, ['SUPPORTED', 'CONTRADICTED', 'UNTESTED'], true)) $v = 'UNTESTED';
    return [
        'verdict'   => $v,
        'confidence'=> max(0, min(100, (int)($j['confidence'] ?? 0))),
        'why'       => mb_substr((string)($j['why'] ?? ''), 0, 600),
        'detail'    => mb_substr((string)($j['detail'] ?? ''), 0, 600),
        'tested_on' => count($cases),
    ];
}

/** Prove one pending recommendation and store the verdict on it. */
function pg_prove_reco(PDO $pdo, int $recoId): ?array {
    $st = $pdo->prepare("SELECT id, title, why, type FROM strategist_reco WHERE id=?");
    $st->execute([$recoId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if ((string)$r['type'] === 'question') {
        $out = ['verdict' => 'UNTESTED', 'confidence' => 0, 'tested_on' => 0,
                'why' => 'This is a question to investigate, not a rule to test.', 'detail' => ''];
    } else {
        $out = pg_prove($pdo, (string)$r['title'] . '. ' . (string)$r['why']);
    }
    try {
        foreach (["ADD COLUMN proof MEDIUMTEXT NULL", "ADD COLUMN proved_at DATETIME NULL"] as $alt) {
            try { $pdo->exec("ALTER TABLE strategist_reco {$alt}"); } catch (Throwable $e) {}
        }
        $pdo->prepare("UPDATE strategist_reco SET proof=?, proved_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $recoId]);
    } catch (Throwable $e) { error_log('pg_prove_reco store: ' . $e->getMessage()); }
    return $out + ['id' => $recoId, 'title' => (string)$r['title']];
}

/** Prove everything still waiting on the owner. Bounded — one AI call each. */
function pg_prove_pending(PDO $pdo, int $max = 5): array {
    $ids = $pdo->query("SELECT id FROM strategist_reco WHERE status='proposed'
                        AND (proof IS NULL OR proof='') ORDER BY id DESC
                        LIMIT " . max(1, min(10, $max)))->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $id) {
        $r = pg_prove_reco($pdo, (int)$id);
        if ($r) $out[] = $r;
    }
    return $out;
}
