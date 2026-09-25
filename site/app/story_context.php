<?php
// GenZHype | STORY CONTEXT (2026-09-24, owner: "make new pages say something of
// their own"). A story page restated its articles: 1,774 editor verdicts in 21
// days had people-first (original value) below 7 in 95% and AI-citability the
// lowest score in 70%. Two story-level sections add what a reader cannot get
// from any one article, built only from what the sources say:
//   why_matters  2-3 sentences: what is at stake, who is affected, what it changes
//   whats_next   up to 3 items the sources say are scheduled, pending or awaited
// Both are checked against the source text (fact_guard.php) before they are
// kept. A "next" item whose date has passed is hidden on the page and from the
// editor (story_context_shape), so it can never go stale in front of a reader.

require_once __DIR__ . '/fact_guard.php';

const SC_WHY_MIN   = 80;
const SC_WHY_MAX   = 520;
const SC_NEXT_MAX  = 3;
const SC_NEXT_MINC = 15;
const SC_NEXT_MAXC = 300;

/** Idempotent. Run OUTSIDE any transaction: an ALTER commits an open one on MariaDB (r151). */
function story_context_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    foreach (["ADD COLUMN why_matters TEXT NULL", "ADD COLUMN whats_next TEXT NULL"] as $alter) {
        try { $pdo->exec("ALTER TABLE dramas {$alter}"); } catch (Throwable $e) { /* already there */ }
    }
    $done = true;
}

/** True when a YYYY-MM-DD / YYYY-MM-00 / YYYY-00-00 date is still today or later at its own precision. */
function story_context_not_past(string $d, string $today): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    if ($m[2] === '00') return $m[1] >= substr($today, 0, 4);
    if ($m[3] === '00') return "{$m[1]}-{$m[2]}" >= substr($today, 0, 7);
    return $d >= $today;
}

/**
 * Keep only what the facts support. $next items: ['date' => 'YYYY-MM-DD'|'YYYY-MM-00'|'', 'text' => ...].
 * Returns ['why' => string, 'next' => list, 'dropped' => list of reasons].
 */
function story_context_clean(string $why, array $next, string $corpus, array $hosts): array {
    $today = gmdate('Y-m-d');
    $out = ['why' => '', 'next' => [], 'dropped' => []];
    $why = trim($why);
    if ($why !== '') {
        $wl = mb_strlen($why);
        $drift = fact_drift($why, $corpus, $hosts);
        if ($wl < SC_WHY_MIN || $wl > SC_WHY_MAX) $out['dropped'][] = "why: {$wl} characters";
        elseif ($drift) $out['dropped'][] = 'why: not in the sources: ' . implode(', ', array_slice($drift, 0, 4));
        else $out['why'] = $why;
    }
    foreach ($next as $it) {
        if (count($out['next']) >= SC_NEXT_MAX) break;
        $text = trim((string)($it['text'] ?? ''));
        $date = trim((string)($it['date'] ?? ''));
        $tl = mb_strlen($text);
        if ($tl < SC_NEXT_MINC || $tl > SC_NEXT_MAXC) { $out['dropped'][] = "next: {$tl} characters"; continue; }
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
        if ($date !== '' && !story_context_not_past($date, $today)) { $out['dropped'][] = "next: {$date} has passed"; continue; }
        $drift = fact_drift($text, $corpus, $hosts);
        if ($drift) { $out['dropped'][] = 'next: not in the sources: ' . implode(', ', array_slice($drift, 0, 4)); continue; }
        $out['next'][] = ['date' => $date, 'text' => $text];
    }
    return $out;
}

/** What the page and the editor show: the why text, and the next items that have not passed. */
function story_context_shape(?string $why, ?string $nextJson): array {
    $today = gmdate('Y-m-d');
    $next = [];
    foreach ((array)json_decode((string)$nextJson, true) as $it) {
        $d = (string)($it['date'] ?? '');
        if ($d !== '' && !story_context_not_past($d, $today)) continue;
        $next[] = ['date' => $d, 'text' => (string)($it['text'] ?? '')];
    }
    return ['why_matters' => trim((string)$why), 'whats_next' => $next];
}

/** Human date for a next item: "October 15, 2026", "October 2026", "2026", or ''. */
function story_context_date_label(string $d): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return '';
    if ($m[2] === '00') return $m[1];
    $ts = strtotime("{$m[1]}-{$m[2]}-" . ($m[3] === '00' ? '01' : $m[3]));
    return $m[3] === '00' ? date('F Y', $ts) : date('F j, Y', $ts);
}

/** The drafter's two fields checked against the sources it was given (draft_drama()). */
function story_context_from_draft(array $j, array $sources, string $topic): array {
    $corpus = $topic . "\n" . implode("\n", array_map(fn($s) => ($s['publisher'] ?? '') . "\n" . ($s['excerpt'] ?? ''), $sources));
    $hosts  = fact_hosts(array_merge(array_column($sources, 'url'), array_column($sources, 'publisher')));
    return story_context_clean((string)($j['why_it_matters'] ?? ''), (array)($j['whats_next'] ?? []), $corpus, $hosts);
}
