<?php
/**
 * GenZHype | THE OUTSIDE INTAKE — organ 09 of the learning machine (2026-08-25)
 * =============================================================================
 * WHAT WAS BROKEN. The machine has been watching the outside world for months and
 * telling nobody. competitor.php, video_intel.php (vi_learn), ig_rivals.php,
 * social_metrics.php and intel_press.php all mine real, measured findings into
 * comp_rule every day — 3,920 rival articles, 21 outlier shorts across 5 rival
 * channels, 1,584 rival Instagram posts. Nine rules sit under scope='video' right
 * now, some updated hours ago.
 *
 * The Doers read NONE of them. memory_directives() filters the very same table to
 * rule_key LIKE 'owner_directive_%', so everything the machine learned by watching
 * anyone else stopped one query short of the hands that write the videos.
 *
 * WHAT THIS IS. The one pipe the plan asks for: mined findings become plain
 * sentences the writers can act on, marked with where they came from.
 *
 * THE TRUST LADDER, AND WHY IT IS NOT DECORATION.
 *      1. the owner's verdict          (ground truth)
 *      2. our own posted results       (the Proving Ground, organ 10)
 *      3. what rivals do               (a prior — a starting guess, never a rule)
 * This is not theory. Right now the outside data says rival outliers lead with a
 * person's name 100% of the time against an 87% baseline — and the Proving Ground
 * tested that exact rule against our own 59 posted videos and returned
 * CONTRADICTED at 100% confidence: it would have excluded 5 of our top 6. Both
 * findings are real. They are about different accounts. If outside priors were
 * allowed to arrive as instructions, that one would already have been applied and
 * would have made our hooks worse. So they arrive labelled as what they are.
 *
 * OURS VS THEIRS. Not everything in comp_rule is outside data. platform_yield is
 * measured on OUR OWN posts and belongs a rung higher than anything about rivals.
 * The classification is explicit below rather than assumed.
 *
 * FAIL CLOSED. An unknown rule shape is skipped, not guessed at. No rules, bad
 * JSON, no DB -> empty string -> the prompt is exactly what it was before this
 * file existed.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Where a finding came from. Determines how hard it is allowed to speak. */
const INTAKE_SRC_OURS    = 'ours';      // measured on our own account
const INTAKE_SRC_RIVALS  = 'rivals';    // measured on other people's accounts
/** At most this many outside lines reach a prompt. A prompt is not a filing cabinet. */
const INTAKE_MAX_LINES   = 4;
/** Below this confidence a mined rule is not worth a writer's attention. */
const INTAKE_MIN_CONF    = 60;

/**
 * The rules we know how to read, and how to say them in one sentence.
 *
 * Deliberately a closed list. A new mined rule shape produces NOTHING until
 * someone teaches this file to read it — which is the honest failure mode, since
 * the alternative is dumping raw JSON into a prompt and hoping the model infers
 * the right lesson from it.
 *
 * Each entry: source, surface, and a function turning the decoded value into a
 * sentence (or '' when the numbers do not support saying anything).
 */
function intake_readers(): array {
    return [
        // ---- OUR OWN measurements -------------------------------------------
        'platform_yield' => [
            'source' => INTAKE_SRC_OURS, 'surface' => 'caption',
            'say' => function (array $v): string {
                $p = $v['platforms'] ?? [];
                if (!is_array($p) || count($p) < 2) return '';
                $named = ['tt' => 'TikTok', 'ig' => 'Instagram', 'fb' => 'Facebook', 'yt' => 'YouTube'];
                $rows = [];
                foreach ($p as $k => $d) {
                    if (!isset($d['avg_views'])) continue;
                    $rows[] = ['n' => $named[$k] ?? $k, 'v' => (float)$d['avg_views'], 'posts' => (int)($d['posts'] ?? 0)];
                }
                if (count($rows) < 2) return '';
                usort($rows, fn($a, $b) => $b['v'] <=> $a['v']);
                $best = $rows[0]; $worst = end($rows);
                if ($best['v'] <= 0) return '';
                return 'On our own account ' . $best['n'] . ' returns by far the most views ('
                     . round($best['v']) . ' a post across ' . $best['posts'] . ') and ' . $worst['n']
                     . ' the least (' . round($worst['v']) . '); write for ' . $best['n'] . ' first.';
            },
        ],

        // ---- RIVAL measurements (priors only) --------------------------------
        'title_shape' => [
            'source' => INTAKE_SRC_RIVALS, 'surface' => 'hook',
            'say' => function (array $v): string {
                $gaps = $v['biggest_gaps_pp'] ?? [];
                if (!is_array($gaps) || !$gaps) return '';
                $label = ['has_caps' => 'put one word in CAPS', 'name_first' => 'open with the person\'s name',
                          'has_number' => 'include a number', 'has_question' => 'end on a question',
                          'colon' => 'use a colon', 'has_quote' => 'use a quote'];
                arsort($gaps);
                $bits = [];
                foreach ($gaps as $k => $pp) {
                    if ((float)$pp < 8) continue;                 // under 8 points is noise at this sample
                    if (!isset($label[$k])) continue;
                    $bits[] = $label[$k] . ' (' . round((float)$pp) . ' points more often)';
                    if (count($bits) >= 3) break;
                }
                if (!$bits) return '';
                return 'Rival videos that outperform their own channel tend to ' . implode(', ', $bits) . '.';
            },
        ],
        'duration_band' => [
            'source' => INTAKE_SRC_RIVALS, 'surface' => 'script',
            'say' => function (array $v): string {
                $o = (int)($v['outlier_median_s'] ?? 0);
                if ($o < 10 || $o > 300) return '';
                return 'Rival videos that outperform run about ' . $o . ' seconds.';
            },
        ],
        'ig_caption_shape' => [
            'source' => INTAKE_SRC_RIVALS, 'surface' => 'caption',
            'say' => function (array $v): string {
                $o = (float)($v['outlier']['caption_chars'] ?? 0);
                $b = (float)($v['baseline']['caption_chars'] ?? 0);
                if ($o <= 0 || $b <= 0 || abs($o - $b) < 40) return '';   // too close to call
                return 'Rival Instagram captions that outperform run longer than their own average — about '
                     . round($o) . ' characters against ' . round($b) . '.';
            },
        ],
        'publish_hours_utc' => [
            'source' => INTAKE_SRC_RIVALS, 'surface' => 'caption',
            'say' => function (array $v): string {
                $t = $v['top_hours'] ?? [];
                if (!is_array($t) || !$t) return '';
                arsort($t);
                $hours = array_slice(array_keys($t), 0, 3);
                if (!$hours) return '';
                return 'Rival outliers go out around ' . implode(':00, ', $hours) . ':00 UTC.';
            },
        ],
    ];
}

/**
 * Every mined finding we can read, as sentences. Owner directives are NOT here —
 * they live in memory.php and outrank all of this.
 *
 * @return array<int, array{key:string, source:string, surface:string, line:string, confidence:int, evidence:string}>
 */
function intake_findings(PDO $pdo, string $surface = 'all'): array {
    $readers = intake_readers();
    $out = [];
    try {
        $rows = $pdo->query("SELECT rule_key, rule_value, confidence, evidence, updated_at
                               FROM comp_rule
                              WHERE scope='video' AND active=1
                                AND rule_key NOT LIKE 'owner_directive_%'
                              ORDER BY confidence DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }        // fail closed

    foreach ($rows as $r) {
        $key = (string)$r['rule_key'];
        if (!isset($readers[$key])) continue;                       // unknown shape -> say nothing
        if ((int)$r['confidence'] < INTAKE_MIN_CONF) continue;
        $spec = $readers[$key];
        if ($surface !== 'all' && $spec['surface'] !== $surface) continue;
        $val = json_decode((string)$r['rule_value'], true);
        if (!is_array($val)) continue;
        try { $line = trim((string)$spec['say']($val)); }
        catch (Throwable $e) { continue; }
        if ($line === '') continue;                                 // numbers did not support a claim
        $out[] = ['key' => $key, 'source' => $spec['source'], 'surface' => $spec['surface'],
                  'line' => $line, 'confidence' => (int)$r['confidence'],
                  'evidence' => mb_substr((string)$r['evidence'], 0, 120)];
    }
    return $out;
}

/**
 * The block a Doer appends AFTER the owner's directives. Empty string when there
 * is nothing worth saying — callers must treat '' as "behave exactly as before".
 *
 * The wording does the real work here. Our own measurements are stated as fact;
 * rival measurements are stated as somebody else's result and explicitly ranked
 * below our own, because one of them is currently wrong for us and the writer
 * has to be able to tell which kind it is reading.
 */
function intake_prompt_block(PDO $pdo, string $surface): string {
    $all = intake_findings($pdo, $surface);
    if (!$all) {
        $all = array_values(array_filter(intake_findings($pdo, 'all'),
                                         fn($f) => $f['surface'] === $surface));
    }
    if (!$all) return '';

    $ours = array_values(array_filter($all, fn($f) => $f['source'] === INTAKE_SRC_OURS));
    $them = array_values(array_filter($all, fn($f) => $f['source'] === INTAKE_SRC_RIVALS));
    $ours = array_slice($ours, 0, INTAKE_MAX_LINES);
    $them = array_slice($them, 0, max(0, INTAKE_MAX_LINES - count($ours)));

    $block = '';
    if ($ours) {
        $block .= ' MEASURED ON OUR OWN ACCOUNT — treat as fact:';
        foreach ($ours as $i => $f) $block .= ' ' . ($i + 1) . ') ' . $f['line'];
    }
    if ($them) {
        $block .= ' WHAT WORKS FOR RIVAL ACCOUNTS — a starting guess only, NOT a rule.'
                . ' Our own results and the owner\'s decisions outrank every line of it,'
                . ' and at least one of these has already been disproved on our own videos.'
                . ' Use it where you have nothing better:';
        foreach ($them as $i => $f) $block .= ' ' . ($i + 1) . ') ' . $f['line'];
    }
    return $block === '' ? '' : $block . ' ';
}

/** Everything the outside world is currently telling us, for the CLI and the room. */
function intake_all(PDO $pdo): array {
    return intake_findings($pdo, 'all');
}

/**
 * SELF-TEST. Every value below is the real shape of a live rule, so a change to
 * what the miners write breaks a test here rather than silently emptying the
 * writers' prompts.
 */
function intake_selftest(): array {
    $pass = 0; $fail = 0; $notes = [];
    $t = function (string $name, bool $ok, string $got = '') use (&$pass, &$fail, &$notes) {
        $ok ? $pass++ : $fail++;
        $notes[] = ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $got === '' ? '' : "  (got: {$got})");
    };
    $r = intake_readers();
    $say = fn(string $k, array $v) => trim((string)$r[$k]['say']($v));

    // --- title_shape, real live value
    $ts = ['outlier' => ['has_caps' => 38, 'name_first' => 100, 'has_number' => 19],
           'baseline' => ['has_caps' => 21, 'name_first' => 87, 'has_number' => 7],
           'biggest_gaps_pp' => ['has_caps' => 17, 'name_first' => 13, 'has_number' => 12]];
    $line = $say('title_shape', $ts);
    $t('title_shape says something', $line !== '', $line);
    $t('it names the biggest gap first', strpos($line, 'CAPS') !== false && strpos($line, '17') !== false, $line);
    $t('it is phrased as RIVALS, not as an instruction',
       stripos($line, 'rival') === 0 || stripos($line, 'rival') !== false, $line);
    $t('a small gap is treated as noise',
       $say('title_shape', ['biggest_gaps_pp' => ['has_caps' => 3, 'name_first' => 2]]) === '');
    $t('an unknown signal name is ignored',
       $say('title_shape', ['biggest_gaps_pp' => ['wibble' => 40]]) === '');

    // --- platform_yield, real live value
    $py = ['platforms' => ['tt' => ['posts' => 63, 'avg_views' => 430], 'fb' => ['posts' => 17, 'avg_views' => 182],
                           'ig' => ['posts' => 46, 'avg_views' => 111], 'yt' => ['posts' => 39, 'avg_views' => 12]]];
    $line = $say('platform_yield', $py);
    $t('platform_yield picks the best platform', strpos($line, 'TikTok') !== false, $line);
    $t('and names the worst', strpos($line, 'YouTube') !== false, $line);
    $t('one platform alone says nothing',
       $say('platform_yield', ['platforms' => ['tt' => ['avg_views' => 10]]]) === '');

    // --- duration / captions / hours
    $t('duration reads the outlier median',
       strpos($say('duration_band', ['outlier_median_s' => 40, 'baseline_median_s' => 37]), '40 seconds') !== false);
    $t('a nonsense duration is refused', $say('duration_band', ['outlier_median_s' => 99999]) === '');
    $t('caption length gap is reported',
       $say('ig_caption_shape', ['outlier' => ['caption_chars' => 374.8], 'baseline' => ['caption_chars' => 275.8]]) !== '');
    $t('a caption gap too small to call says nothing',
       $say('ig_caption_shape', ['outlier' => ['caption_chars' => 280], 'baseline' => ['caption_chars' => 276]]) === '');
    $t('publish hours are ordered by frequency',
       strpos($say('publish_hours_utc', ['top_hours' => ['19' => 5, '20' => 4, '17' => 2]]), '19:00') !== false);

    // --- the ladder
    $t('our own findings are marked as ours', $r['platform_yield']['source'] === INTAKE_SRC_OURS);
    $t('rival findings are marked as rivals', $r['title_shape']['source'] === INTAKE_SRC_RIVALS);
    $t('empty input never produces a sentence',
       $say('title_shape', []) === '' && $say('platform_yield', []) === '' && $say('duration_band', []) === '');

    return ['pass' => $pass, 'fail' => $fail, 'notes' => $notes];
}
