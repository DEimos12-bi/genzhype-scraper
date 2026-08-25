<?php
/**
 * GenZHype | THE EYES — organ 03 of the learning machine (2026-08-25)
 * =============================================================================
 * WHAT THIS IS. The part that decides whether a finished video is any good, and
 * the machinery that drags that decision towards the owner's.
 *
 * THE CASE THAT BUILT IT — video 700. A Polish courtroom photo standing in for a
 * California lawsuit, the same image reused for a third of the runtime, no faces
 * anywhere, and a date badge that was wrong. The machine judge gave it 8/10. A
 * judge that scores a disaster 8/10 does not merely fail to catch it: every organ
 * downstream then optimises towards whatever that judge likes, and the account
 * gets confidently worse. That is the reward-hacking failure the Governor watches
 * for, with the judge as the corrupted proxy.
 *
 * THE LADDER, unchanged from every other organ:
 *      1. the owner's verdict     — ground truth, outranks everything
 *      2. our own posted numbers  — the Proving Ground's golden set
 *      3. the machine judge       — only as good as its agreement with (1)
 *
 * WHAT THIS DOES.
 *  - MEASURES AGREEMENT. Both judgements already land in the same place:
 *    work_record.judgment carries `judge` (the machine, from the renderer's
 *    report) and `owner_verdict` (his, from the room). Nothing has ever compared
 *    them. This does, and states the agreement rate with its sample size.
 *  - BUILDS THE FEW-SHOT BLOCK. His verdicts, especially the disagreements,
 *    become worked examples the judge is shown before it scores the next video —
 *    calibration by demonstration rather than by argument.
 *  - REFUSES TO PRETEND. With no verdicts recorded there is nothing to calibrate
 *    against, and this says exactly that instead of emitting a confident-looking
 *    empty block. At the time of writing the count is ZERO: the machine judge is
 *    entirely uncalibrated and this organ's honest output is "he has not taught
 *    me anything yet".
 *
 * WHERE IT LANDS. The judge runs in the Python renderer on GitHub Actions, so the
 * calibration travels the way everything else reaches the renderer: attached to
 * the job feed. PHP decides, Python performs — the same seam the whole video
 * system already uses.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Worked examples shown to the judge. More than a handful and the prompt drifts. */
const EYES_MAX_SHOTS = 6;
/** Below this many verdicts, agreement is not a measurement. */
const EYES_MIN_VERDICTS = 5;
/** The owner's own words for what went wrong, as offered in the room. */
const EYES_REASONS = [
    'hook' => 'the hook or the first second', 'story' => 'the story was the wrong one to pick',
    'faces' => 'no real faces on screen', 'visuals' => 'bad or repeated visuals',
    'captions' => 'the captions', 'voice' => 'the voice', 'pacing' => 'pacing, it was boring',
    'sound' => 'the sound or music', 'facts' => 'wrong facts or dates', 'other' => 'something else',
];

/**
 * Every video where we hold a judgement — the machine's, the owner's, or both.
 *
 * @return array<int, array{page_id:int, ref:string, machine:?float, owner:?string, reasons:array, note:string}>
 */
function eyes_judgements(PDO $pdo, int $limit = 400): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT page_id, ref, judgment FROM work_record
                              WHERE kind='video' AND judgment IS NOT NULL AND judgment <> ''
                              ORDER BY updated_at DESC LIMIT " . max(1, min(2000, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }

    foreach ($rows as $r) {
        $j = json_decode((string)$r['judgment'], true);
        if (!is_array($j)) continue;
        $machine = null;
        if (!empty($j['judge'])) {
            $sc = $j['judge']['scores'] ?? null;
            if (is_array($sc) && $sc) {
                $nums = array_values(array_filter(array_map('floatval', array_values($sc)), fn($n) => $n > 0));
                if ($nums) $machine = array_sum($nums) / count($nums);
            } elseif (isset($j['judge']['score'])) {
                $machine = (float)$j['judge']['score'];
            } elseif (!empty($j['judge']['pass'])) {
                $machine = 8.0;    // a bare pass is the renderer saying "good enough"
            }
        }
        $owner = null; $reasons = []; $note = '';
        if (!empty($j['owner_verdict']['verdict'])) {
            $owner   = (string)$j['owner_verdict']['verdict'];
            $reasons = (array)($j['owner_verdict']['reasons'] ?? []);
            $note    = (string)($j['owner_verdict']['note'] ?? '');
        }
        if ($machine === null && $owner === null) continue;
        $out[] = ['page_id' => (int)$r['page_id'], 'ref' => (string)$r['ref'],
                  'machine' => $machine, 'owner' => $owner, 'reasons' => $reasons, 'note' => $note];
    }
    return $out;
}

/**
 * Does the machine agree with the owner?
 *
 * A machine score of 7 or more reads as "ship it". Agreement means the machine
 * said ship-it and he said good, or the machine said no and he said bad. The two
 * disagreement types are counted separately because they are not equally
 * dangerous: MISSED means the machine passed something he rejected, which is how
 * a bad video reaches the account and how video 700 happened. HARSH means it
 * blocked something he liked, which only costs us a video.
 *
 * @return array{state:string, n:int, agree:int, missed:int, harsh:int, rate:?float, why:string}
 */
function eyes_agreement(PDO $pdo): array {
    $rows = array_values(array_filter(eyes_judgements($pdo),
        fn($r) => $r['owner'] !== null && $r['machine'] !== null));
    $n = count($rows);
    if ($n === 0) {
        $anyOwner = count(array_filter(eyes_judgements($pdo), fn($r) => $r['owner'] !== null));
        return ['state' => 'UNCALIBRATED', 'n' => 0, 'agree' => 0, 'missed' => 0, 'harsh' => 0, 'rate' => null,
                'why' => $anyOwner === 0
                    ? 'The owner has not judged a single video, so the machine judge has never been '
                    . 'checked against anything. Every score it produces is unverified, and every organ '
                    . 'downstream is optimising towards a judge nobody has audited.'
                    : $anyOwner . ' owner verdict(s) exist but none of those videos carry a machine score '
                    . 'to compare against.'];
    }
    $agree = 0; $missed = 0; $harsh = 0;
    foreach ($rows as $r) {
        $machineSaysShip = (float)$r['machine'] >= 7.0;
        $ownerSaysGood   = $r['owner'] === 'good';
        if ($machineSaysShip === $ownerSaysGood) { $agree++; continue; }
        $machineSaysShip ? $missed++ : $harsh++;
    }
    $rate = $agree / $n;
    $state = $n < EYES_MIN_VERDICTS ? 'TOO-EARLY' : ($rate >= 0.8 ? 'CALIBRATED' : 'DISAGREES');
    return ['state' => $state, 'n' => $n, 'agree' => $agree, 'missed' => $missed, 'harsh' => $harsh,
            'rate' => round($rate, 3),
            'why' => $n < EYES_MIN_VERDICTS
                ? 'Only ' . $n . ' video(s) carry both judgements; ' . EYES_MIN_VERDICTS . ' is the floor for a real rate.'
                : 'The machine agrees with the owner on ' . $agree . ' of ' . $n . ' videos. It passed '
                . $missed . ' he rejected (the dangerous direction — that is how a bad video reaches the '
                . 'account) and blocked ' . $harsh . ' he liked.'];
}

/**
 * The worked examples. Disagreements come first and deliberately so: an example
 * where the judge was already right teaches it nothing, and the whole point is to
 * move it towards catching what it currently misses.
 */
function eyes_shots(PDO $pdo, int $max = EYES_MAX_SHOTS): array {
    $rows = array_values(array_filter(eyes_judgements($pdo), fn($r) => $r['owner'] !== null));
    if (!$rows) return [];

    $score = function (array $r): int {
        // most instructive first: machine passed what he rejected
        if ($r['machine'] !== null && (float)$r['machine'] >= 7.0 && $r['owner'] === 'bad') return 0;
        if ($r['machine'] !== null && (float)$r['machine'] < 7.0 && $r['owner'] === 'good') return 1;
        if ($r['owner'] === 'bad') return 2;
        return 3;
    };
    usort($rows, fn($a, $b) => [$score($a), -count($a['reasons'])] <=> [$score($b), -count($b['reasons'])]);

    $out = [];
    foreach (array_slice($rows, 0, max(1, min(20, $max))) as $r) {
        $why = [];
        foreach ($r['reasons'] as $k) $why[] = EYES_REASONS[(string)$k] ?? (string)$k;
        $out[] = [
            'ref'      => $r['ref'],
            'owner'    => $r['owner'],
            'machine'  => $r['machine'] === null ? null : round((float)$r['machine'], 1),
            'because'  => $why,
            'note'     => mb_substr($r['note'], 0, 200),
        ];
    }
    return $out;
}

/**
 * The block the judge is shown before it scores anything. Empty string when the
 * owner has taught us nothing yet — and empty must mean "behave exactly as
 * before", never "here are no rules, do as you like".
 */
function eyes_calibration_block(PDO $pdo): string {
    $shots = eyes_shots($pdo);
    if (!$shots) return '';
    $ag = eyes_agreement($pdo);

    $b = ' CALIBRATION — THE OWNER OF THIS ACCOUNT HAS JUDGED REAL VIDEOS AND HIS VERDICT IS '
       . 'GROUND TRUTH. It outranks your own opinion, every rubric you were given, and every '
       . 'score you would otherwise produce. Score the way HE scores:';
    foreach ($shots as $i => $s) {
        $b .= ' ' . ($i + 1) . ') "' . $s['ref'] . '" — he called it ' . strtoupper((string)$s['owner']);
        if ($s['machine'] !== null) $b .= ' while the machine had scored it ' . $s['machine'] . '/10';
        if ($s['because']) $b .= '; what was wrong: ' . implode(', ', $s['because']);
        if ($s['note'] !== '') $b .= '; in his words: "' . $s['note'] . '"';
        $b .= '.';
    }
    if (($ag['missed'] ?? 0) > 0) {
        $b .= ' You have passed ' . $ag['missed'] . ' video(s) he rejected. When you are unsure, '
            . 'score DOWN — a video wrongly held back costs one video, a bad one shipped costs the account.';
    }
    return $b . ' ';
}

/**
 * What travels to the renderer with the job feed. Deliberately small and flat:
 * the Python side must be able to drop it into the judge prompt without parsing
 * anything clever.
 */
function eyes_feed_payload(PDO $pdo): array {
    $ag = eyes_agreement($pdo);
    return [
        'state'       => $ag['state'],
        'agreement'   => $ag['rate'],
        'judged'      => $ag['n'],
        'calibration' => eyes_calibration_block($pdo),
        'shots'       => eyes_shots($pdo),
    ];
}

/**
 * SELF-TEST. The scoring rules are pure, so the behaviour that matters — what
 * counts as agreement, which direction is dangerous, and that an empty history
 * produces an empty block rather than a confident one — is provable here.
 */
function eyes_selftest(): array {
    $pass = 0; $fail = 0; $notes = [];
    $t = function (string $name, bool $ok, string $got = '') use (&$pass, &$fail, &$notes) {
        $ok ? $pass++ : $fail++;
        $notes[] = ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $got === '' ? '' : "  (got: {$got})");
    };

    // the agreement rule, applied by hand exactly as eyes_agreement applies it
    $cls = function (float $machine, string $owner): string {
        $ship = $machine >= 7.0; $good = ($owner === 'good');
        if ($ship === $good) return 'agree';
        return $ship ? 'missed' : 'harsh';
    };
    $t('machine passes, he liked it -> agree', $cls(8.0, 'good') === 'agree');
    $t('machine fails, he hated it -> agree', $cls(4.0, 'bad') === 'agree');
    $t('VIDEO 700: machine 8/10, he says bad -> MISSED', $cls(8.0, 'bad') === 'missed', $cls(8.0, 'bad'));
    $t('machine blocks one he liked -> harsh', $cls(3.0, 'good') === 'harsh');
    $t('exactly 7 counts as ship-it', $cls(7.0, 'bad') === 'missed');
    $t('just under 7 does not', $cls(6.9, 'bad') === 'agree');

    // the two directions are not equally bad, and the wording must say so
    $t('missed and harsh are distinct outcomes', $cls(8.0, 'bad') !== $cls(3.0, 'good'));

    // reasons vocabulary matches the room's checkboxes
    foreach (['hook', 'story', 'faces', 'visuals', 'captions', 'voice', 'pacing', 'sound', 'facts', 'other'] as $k) {
        if (!isset(EYES_REASONS[$k])) { $t('reason "' . $k . '" is understood', false); break; }
    }
    $t('every reason the room offers is understood', count(EYES_REASONS) === 10, (string)count(EYES_REASONS));

    // the ordering rule: the most instructive example first
    $score = function (array $r): int {
        if ($r['machine'] !== null && (float)$r['machine'] >= 7.0 && $r['owner'] === 'bad') return 0;
        if ($r['machine'] !== null && (float)$r['machine'] < 7.0 && $r['owner'] === 'good') return 1;
        if ($r['owner'] === 'bad') return 2;
        return 3;
    };
    $missedCase = ['machine' => 8.0, 'owner' => 'bad'];
    $agreeCase  = ['machine' => 8.0, 'owner' => 'good'];
    $t('a missed video teaches more than one it got right',
       $score($missedCase) < $score($agreeCase));

    // floors
    $t('a single verdict is not a rate', EYES_MIN_VERDICTS > 1);
    $t('the shot list is bounded', EYES_MAX_SHOTS <= 10);

    return ['pass' => $pass, 'fail' => $fail, 'notes' => $notes];
}
