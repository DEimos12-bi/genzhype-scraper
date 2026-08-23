<?php
/**
 * GenZHype | THE INSPECTOR — organ 11 of the learning machine (2026-08-23)
 * =============================================================================
 * WHAT THIS IS. The last checkpoint between the database and pixels. Nothing
 * false, unsupported or structurally broken may become a frame of video.
 *
 * WHY IT EXISTS — the case that built it. Video 700 burned "JAN 1, 2024" onto
 * the screen as a dated receipt. The date was not a parse bug: rc_date_label()
 * is correct and already supports partial dates ('2024-00-00' -> "2024"). The
 * fault was upstream — draft.php's prompt demanded "date":"YYYY-MM-DD", ALWAYS
 * a full date, so when the source said only "as early as 2024" the model had no
 * way to say so and invented a day. The site then printed that invented day as
 * a fact. On a brand whose whole position is "the receipts, not the gossip",
 * manufactured precision is worse than vagueness.
 *
 * THE LAW OF THIS FILE: FAIL SAFE, NEVER LOUD.
 *   A failed check renders NOTHING rather than something wrong.
 *   No date badge beats a wrong date badge.
 *   It may drop a field or a shot; it may never crash the tick, and it must
 *   never become a second publish gate that blocks everything (this site
 *   already publishes too little — a false-positive Inspector would be worse
 *   than no Inspector).
 *
 * WHAT IT IS NOT. Not a guardrail store. The publish gate, the alleged/
 * reportedly shield, CC0-only audio and the dignity rules live in code
 * elsewhere and are not tunable from here.
 */
declare(strict_types=1);

/**
 * Phrases that mean "the source did not give us a day". If the event text
 * hedges like this, any day-level precision in the date was invented by the
 * model, not read from the source.
 */
const INSP_VAGUE_MARKERS = [
    'as early as', 'sometime in', 'sometime around', 'around ', 'circa ',
    'back in', 'in early', 'in late', 'in mid', 'earlier in', 'at some point',
    'reportedly began', 'allegedly began', 'began scraping', 'as far back as',
    'dating back', 'since at least', 'no later than', 'by the end of',
];

/**
 * DATE PRECISION. Returns the date string that is HONEST to publish, given the
 * event's own words. Downgrades day-precision to month or year when the text
 * shows the source never supplied a day; returns the input untouched otherwise.
 *
 * Uses the site's existing partial-date convention (YYYY-MM-00, YYYY-00-00),
 * which rc_date_label() already renders correctly and 179 stored events already
 * use — so this adds no new format, it just stops inventing precision.
 *
 * @return array{date:string, changed:bool, why:string}
 */
function insp_date_precision(?string $date, string $text): array {
    $d = trim((string)$date);
    if ($d === '' || $d === '0000-00-00') return ['date' => $d, 'changed' => false, 'why' => ''];
    [$y, $m, $day] = array_pad(explode('-', $d), 3, '00');
    // already partial -> nothing to downgrade
    if ((int)$day === 0) return ['date' => $d, 'changed' => false, 'why' => ''];

    $t = mb_strtolower($text);
    $hit = '';
    // A hedge only counts when it is POINTING AT A DATE. "around" alone matches
    // "turned around" and "around 200 viewers" — the first dry run found exactly
    // that and would have destroyed a correct Aug-21 date. So the marker must be
    // followed, within a few words, by a year or a month name. A false-positive
    // Inspector is worse than no Inspector.
    $monthRe = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|'
             . 'aug(?:ust)?|sep(?:t|tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
    foreach (INSP_VAGUE_MARKERS as $marker) {
        $re = '/' . preg_quote(trim($marker), '/') . '\s+(?:the\s+)?(?:' . $monthRe . '|\d{4}|that\s+year|last\s+year)\b/u';
        if (preg_match($re, $t)) { $hit = trim($marker); break; }
    }
    if ($hit === '') return ['date' => $d, 'changed' => false, 'why' => ''];

    // The text names a month -> keep the month, drop the invented day.
    // It names no month -> keep only the year.
    // Keep the month only when the hedge itself names one ("in early August").
    $hasMonth = (bool)preg_match('/' . preg_quote($hit, '/') . '\s+(?:the\s+)?(?:' . $monthRe . ')\b/u', $t);
    $safe = $hasMonth ? sprintf('%04d-%02d-00', (int)$y, (int)$m) : sprintf('%04d-00-00', (int)$y);
    return ['date' => $safe, 'changed' => true,
            'why' => 'source hedges ("' . $hit . '") — day was not supported'];
}

/**
 * Convenience for callers that only want the honest label. Falls back to the
 * raw label if receipt_cards is not loaded, so this file is safe standalone.
 */
function insp_date_label(?string $date, string $text): string {
    $r = insp_date_precision($date, $text);
    if (function_exists('rc_date_label')) return rc_date_label($r['date']);
    return $r['date'];
}

/**
 * Record an Inspector action on the page's story (organ 02), so a refusal is
 * never silent and the Governor can later alarm on a spike of them.
 * Non-fatal by construction.
 */
function insp_note(PDO $pdo, int $pageId, string $ref, string $check, string $action, string $detail): void {
    try {
        require_once __DIR__ . '/record.php';
        record_touch($pdo, 'video', $pageId, $ref, 'inspection',
                     ['checks' => [[
                         'at' => gmdate('c'), 'check' => $check,
                         'action' => $action, 'detail' => mb_substr($detail, 0, 200),
                     ]]], ['checks']);
    } catch (Throwable $e) { error_log('insp_note: ' . $e->getMessage()); }
}

/**
 * Sweep stored events for invented precision and report (or repair) them.
 * Read-only unless $apply is true. This is the backfill twin of the live check:
 * the live check stops NEW ones, this finds the ones already written.
 *
 * @return array{scanned:int, found:int, fixed:int, samples:array}
 */
function insp_sweep_dates(PDO $pdo, int $limit = 500, bool $apply = false): array {
    $rows = $pdo->query("SELECT id, event_date, title, description FROM events
                         WHERE event_date IS NOT NULL AND event_date <> '0000-00-00'
                           AND DAY(event_date) > 0
                         ORDER BY id DESC LIMIT " . max(1, min(5000, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    $found = 0; $fixed = 0; $samples = [];
    $upd = $pdo->prepare("UPDATE events SET event_date=? WHERE id=?");
    foreach ($rows as $r) {
        $res = insp_date_precision((string)$r['event_date'], (string)$r['title'] . ' ' . (string)$r['description']);
        if (!$res['changed']) continue;
        $found++;
        if (count($samples) < 8) {
            $samples[] = ['id' => (int)$r['id'], 'was' => (string)$r['event_date'],
                          'now' => $res['date'], 'why' => $res['why'],
                          'title' => mb_substr((string)$r['title'], 0, 50)];
        }
        if ($apply) { $upd->execute([$res['date'], (int)$r['id']]); $fixed++; }
    }
    return ['scanned' => count($rows), 'found' => $found, 'fixed' => $fixed, 'samples' => $samples];
}

/**
 * DATE -> ON-SCREEN / SPOKEN TEXT. The one safe formatter for the video path.
 *
 * WHY THIS EXISTS, measured on the live server's PHP 8.2:
 *     strtotime('2024-00-00')  ->  "Nov 30, 2023"
 *     strtotime('2024-08-00')  ->  "Jul 31, 2024"
 *     strtotime('0000-00-00')  ->  "Nov 30, -0001"
 * timelib treats "00" as a real month/day and normalises by borrowing, so a
 * partial date silently becomes a DIFFERENT, PLAUSIBLE-LOOKING date — wrong
 * day, wrong month, sometimes wrong year. That is far more damaging than an
 * obvious glitch: "Nov 30, 2023" reads as a fact. The video path called
 * date(strtotime(...)) in four places, so the site's own partial-date
 * convention — the very mechanism that exists to prevent invented precision —
 * would have manufactured a false date on every partial beat.
 *
 * $withYear: true -> "Aug 20, 2026" style; false -> "Aug 20" (chips inside a
 * single calendar year). Partial dates ignore the hint and render honestly
 * ("Oct 2025", "2024"). Returns '' when there is nothing honest to print —
 * callers must then omit the chip entirely. No chip beats a wrong chip.
 */
function insp_chip(?string $date, bool $withYear = true): string {
    $d = trim((string)$date);
    if ($d === '' || $d === '0000-00-00') return '';
    [$y, $m, $day] = array_pad(explode('-', $d), 3, '0');
    $y = (int)$y; $m = (int)$m; $day = (int)$day;
    if ($y <= 0) return '';
    $mon = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    if ($m < 1 || $m > 12) return (string)$y;                 // year only
    if ($day < 1)          return $mon[$m] . ' ' . $y;        // month + year
    return $withYear ? ($mon[$m] . ' ' . $day . ', ' . $y) : ($mon[$m] . ' ' . $day);
}

/** The year, read from the string — never via strtotime (see insp_chip). 0 = unknown. */
function insp_year(?string $date): int {
    $y = (int)substr(trim((string)$date), 0, 4);
    return $y > 1900 ? $y : 0;
}
