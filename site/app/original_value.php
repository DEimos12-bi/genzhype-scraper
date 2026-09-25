<?php
// GenZHype | ORIGINAL VALUE (owner 2026-09-25: "Every page needs something the
// sources don't have ... If a page has none of these, don't publish it").
// The editor scored "people-first" (original value) below 7 on 95% of pages:
// a story restated its articles. This names the five things a story page can
// add that no single source has, and says which ones a page carries:
//   receipts        the timeline shows an original post or cites a primary source
//   confirmed_split events confirmed by a primary source, set apart from claims
//   both_sides      each side's own statement, side by side        (not built yet)
//   verdict         our read of the evidence, with reasons         (not built yet)
//   own_numbers     figures we collected ourselves                 (not built yet)
// Report-only for now: nothing is held back on this until the missing pieces
// exist, so switching it on does not cut the daily output (owner, same day).

require_once __DIR__ . '/gate.php';

const OV_ELEMENTS = ['receipts', 'confirmed_split', 'both_sides', 'verdict', 'own_numbers'];

/** Which original-value pieces a story page carries. ['has' => [element => bool], 'count' => n, 'why' => [element => detail]] */
function original_value_check(PDO $pdo, int $pageId): array {
    $has = array_fill_keys(OV_ELEMENTS, false);
    $why = [];
    $d = $pdo->prepare("SELECT d.id FROM dramas d WHERE d.page_id=?");
    $d->execute([$pageId]);
    $did = (int)$d->fetchColumn();
    if ($did) {
        $ev = $pdo->prepare("SELECT e.embed_html, e.is_confirmed, s.url FROM events e LEFT JOIN sources s ON s.id=e.source_id
                             WHERE e.drama_id=? AND e.video_only=0");
        $ev->execute([$did]);
        $posts = 0; $primary = 0; $confirmed = 0; $claims = 0;
        foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
            if ((string)($e['embed_html'] ?? '') !== '') $posts++;
            elseif (!empty($e['url']) && gate_event_source_is_primary((string)$e['url'])) $primary++;
            (int)$e['is_confirmed'] === 1 ? $confirmed++ : $claims++;
        }
        if ($posts + $primary > 0) { $has['receipts'] = true; $why['receipts'] = "{$posts} original post(s) shown, {$primary} more primary source(s)"; }
        if ($confirmed > 0 && $claims > 0) { $has['confirmed_split'] = true; $why['confirmed_split'] = "{$confirmed} confirmed, {$claims} still claims"; }
    }
    return ['has' => $has, 'count' => count(array_filter($has)), 'why' => $why];
}

/** One line for logs: "receipts, confirmed_split (2 of 5)" or "none (0 of 5)". */
function original_value_label(array $ov): string {
    $on = array_keys(array_filter($ov['has']));
    return ($on ? implode(', ', $on) : 'none') . " ({$ov['count']} of " . count(OV_ELEMENTS) . ')';
}
