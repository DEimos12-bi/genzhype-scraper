<?php
/**
 * GenZHype | TERM SOURCE REPAIR (2026-08-27)
 * =============================================================================
 * 27 meme/gaming pages are live but hidden from Google. Every one fails the same
 * family of checks: >=3 dated citations (27), sources screened on-topic (23),
 * >=2 non-commodity sources (21).
 *
 * They are not bad pages. They were built BEFORE two things were repaired today:
 *   1. sources_topical_fit() judged articles on the FIRST 900 chars of the page,
 *      which on a news site is the cookie banner - so real coverage was discarded
 *      as "off-topic" and the pages were left with Urban Dictionary / Wiktionary
 *      (the commodity tier the gate refuses).
 *   2. a dated reference from a real publication now counts as a citation.
 *
 * So the repair is not to loosen anything: it is to RE-FETCH sources with the
 * working retrieval, keep what passes the existing screens, and let the existing
 * citation rules read them. A page that still cannot find real sources stays
 * hidden - that is the honest outcome, not a failure of this function.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/draft_term.php';
require_once __DIR__ . '/gate_term.php';

function term_resource_run(PDO $pdo, int $cap = 5, bool $dry = false): array
{
    $rows = $pdo->query(
        "SELECT t.page_id, t.lane, t.term, t.sources, t.citations
           FROM terms t JOIN pages p ON p.id = t.page_id
          WHERE p.status = 'published' AND p.robots = 'noindex'
          ORDER BY t.page_id")->fetchAll(PDO::FETCH_ASSOC);

    $done = 0; $improved = 0; $noLuck = 0; $report = [];
    foreach ($rows as $r) {
        if ($done >= $cap) { break; }
        $done++;
        $term = (string)$r['term'];
        $before = count(gate_term_valid_citations(
            (array)json_decode((string)$r['citations'], true), $term));

        // the repaired retrieval + the existing on-topic screen
        try { $fresh = fetch_term_sources($term, 4, (string)$r['lane']); }
        catch (Throwable $e) { $fresh = []; }
        if (!$fresh) { $noLuck++; $report[] = "{$term}: no usable sources found"; continue; }

        // merge, de-duplicated by URL; never drop what the page already had
        $have = (array)json_decode((string)$r['sources'], true);
        $byUrl = [];
        foreach (array_merge($have, $fresh) as $s) {
            if (!is_array($s)) { continue; }
            $u = trim((string)($s['url'] ?? ''));
            if ($u !== '') { $byUrl[$u] = $s; }
        }
        $merged = array_values($byUrl);

        // citations come from the sources under the CURRENT rules; nothing invented
        $cand = (array)json_decode((string)$r['citations'], true);
        foreach ($merged as $s) {
            $cand[] = [
                'url'      => (string)($s['url'] ?? ''),
                'date'     => (string)($s['date'] ?? ''),
                'title'    => (string)($s['title'] ?? ($s['publisher'] ?? '')),
                'platform' => (string)($s['publisher'] ?? ''),
                'quote'    => (string)($s['excerpt'] ?? ''),
            ];
        }
        $valid = gate_term_valid_citations($cand, $term);
        if (!$dry) {
            $pdo->prepare("UPDATE terms SET sources=?, citations=? WHERE page_id=?")
                ->execute([
                    json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode(array_values($valid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (int)$r['page_id'],
                ]);
        }
        if (count($valid) > $before) { $improved++; }
        $report[] = sprintf('%s: sources %d->%d, citations %d->%d',
            $term, count($have), count($merged), $before, count($valid));
    }
    return ['checked' => $done, 'improved' => $improved,
            'no_sources' => $noLuck, 'report' => $report];
}
