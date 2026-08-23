<?php
/* GenZHype | r131 — ALLEGED-FRAMING REPAIR (the owner's option 3: fix and earn).
 *
 * THE SITUATION. Every published drama sits at robots=noindex because of a
 * deliberate legal hold: the gate's own audit found 458/467 drama pages fail
 * the defamation shield — unconfirmed claims about real, named people stated
 * as flat fact, with no "allegedly / reportedly / according to" framing.
 * The nightly watchdog restores a page to the index the moment it passes the
 * gate, so the ONLY missing piece is repairing the framing itself.
 *
 * WHAT THIS DOES. Finds unconfirmed events whose description lacks framing
 * language (the gate's own regex), and has the AI REWRITE the sentence with
 * attribution/hedging added — under hard orders to change nothing else: no
 * new facts, no dropped facts, no tone shift. Adding "reportedly" to an
 * unproven claim is a pure safety upgrade; it cannot make a sentence less
 * true. Every rewrite is logged to rule_apply_log (before/after), and when a
 * page's gate turns green its robots flips to index on the spot — each drama
 * EARNS its way back, none are waved through.
 *
 * BOUNDED. Max events per run capped; one AI call repairs up to 8 events.
 * AI refusal/garbage -> the event is skipped, never blanked. */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/gate.php';

const FR_FRAMING_RX = 'alleg|reportedly|claims?|according to|unverified|appears to';

/** Repair up to $cap unframed events. Returns a plain report array. */
function framing_repair_run(PDO $pdo, int $cap = 24): array {
    $rows = $pdo->query(
        "SELECT e.id, e.description, d.page_id, p.slug
           FROM events e
           JOIN dramas d ON d.id = e.drama_id
           JOIN pages p ON p.id = d.page_id
          -- 2026-08-22: DRAFTS TOO, not just published pages. Fresh drafts
          -- were failing the publishing gate on exactly this check
          -- (the alleged-framing check, up to 9 violations each) and
          -- then archiving themselves after 7 days unread. Repairing only
          -- what was ALREADY published meant the fix never reached the
          -- stories it could actually rescue. Drafts first — they are the
          -- ones with a deadline.
          WHERE p.type='drama' AND p.status IN ('published','draft','review')
            AND e.is_confirmed = 0
            AND e.description NOT REGEXP '" . FR_FRAMING_RX . "'
          ORDER BY (p.status='draft') DESC, p.published_at DESC
          LIMIT " . max(1, min(60, $cap)))->fetchAll();
    if (!$rows) { return ['repaired' => 0, 'pages_indexed' => 0, 'left' => 0]; }

    $repaired = 0;
    foreach (array_chunk($rows, 8) as $chunk) {
        $in = [];
        foreach ($chunk as $e) { $in[(string)$e['id']] = (string)$e['description']; }
        $sys = 'You are a defamation-safety editor for a news timeline. Each sentence '
             . 'describes an UNCONFIRMED event about real people. Rewrite each so the '
             . 'claim carries clear attribution or hedging (reportedly / allegedly / '
             . 'according to <the obvious source in the sentence> / appears to). HARD '
             . 'RULES: change NOTHING else — no new facts, no removed facts, no names '
             . 'altered, keep roughly the same length, keep the plain news tone. If a '
             . 'sentence already reads as attributed opinion or verifiable public record '
             . '(e.g. "X posted Y"), still add minimal hedging to any consequence claim. '
             . 'Output STRICT JSON: {"<id>":"rewritten sentence", ...} with EVERY id.';
        $res = ai_chat([['role' => 'system', 'content' => $sys],
                        ['role' => 'user', 'content' => json_encode($in, JSON_UNESCAPED_UNICODE)]],
                       ['gemini', 'openrouter', 'nvidia'], 0.2);
        if (isset($res['error'])) { continue; }
        $j = ai_json($res['content']);
        if (!is_array($j)) { continue; }

        $upd = $pdo->prepare("UPDATE events SET description=? WHERE id=?");
        foreach ($chunk as $e) {
            $new = trim((string)($j[(string)$e['id']] ?? ''));
            // Accept only rewrites that actually carry framing now, kept the
            // sentence recognizable (length sanity), and stayed non-empty.
            if ($new === '' || !preg_match('/' . FR_FRAMING_RX . '/i', $new)) { continue; }
            $lo = mb_strlen((string)$e['description']);
            if (mb_strlen($new) < $lo * 0.6 || mb_strlen($new) > $lo * 1.8) { continue; }
            $upd->execute([mb_substr($new, 0, 500), (int)$e['id']]);
            $repaired++;
            try {
                $pdo->prepare("INSERT INTO rule_apply_log (rule_key, page_id, what, before_val, after_val, at)
                               VALUES ('alleged_framing_fix', ?, 'defamation-shield rewrite', ?, ?, UTC_TIMESTAMP())")
                    ->execute([(int)$e['page_id'],
                               mb_substr((string)$e['description'], 0, 250),
                               mb_substr($new, 0, 250)]);
            } catch (Throwable $x) {}
        }
    }

    // Pages touched this run: if the gate is green now, the page earns its
    // index back immediately (same rule the 3am watchdog applies).
    $indexed = 0;
    $pages = array_values(array_unique(array_column($rows, 'page_id')));
    foreach ($pages as $pid) {
        try {
            $g = gate_check_drama((int)$pid);
            if (!empty($g['pass'])) {
                $st = $pdo->prepare("UPDATE pages SET robots='index', updated_at=NOW()
                                      WHERE id=? AND robots='noindex'");
                $st->execute([(int)$pid]);
                if ($st->rowCount()) { $indexed++; }
            }
        } catch (Throwable $x) {}
    }

    $left = (int)$pdo->query(
        "SELECT COUNT(*) FROM events e
           JOIN dramas d ON d.id=e.drama_id
           JOIN pages p ON p.id=d.page_id
          WHERE p.type='drama' AND p.status='published' AND e.is_confirmed=0
            AND e.description NOT REGEXP '" . FR_FRAMING_RX . "'")->fetchColumn();
    return ['repaired' => $repaired, 'pages_indexed' => $indexed, 'left' => $left];
}
