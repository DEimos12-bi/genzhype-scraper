<?php
/**
 * GenZHype | GRADUAL REVEAL (2026-08-28)
 * =============================================================================
 * Owner: "fixing them yes right now, but publishing them we can make it periods
 * — I don't wanna break any rule publishing all of them the same day."
 *
 * Correct instinct, and the site already protects the OTHER door: velocity_drain
 * caps review->live at daily_publish_cap (12/day) precisely as an anti
 * scaled-content-abuse guard. But the 79 repaired pages are already 'published'
 * and merely noindex, so flipping them to index does NOT pass through that cap —
 * without this, a repair run could hand Google 70 new indexable URLs in one
 * minute, which is exactly the burst pattern the cap exists to prevent.
 *
 * So reveals get their own budget, deliberately smaller than the publish cap:
 * a repaired page only becomes visible once it PASSES ITS OWN GATE, and only a
 * few per day. Nothing is rushed, nothing is forced live, and a page that never
 * passes simply stays hidden.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gate_term.php';

// OWNER DECISION 2026-08-29: 40/day. Checked against Google first - their spam
// policy names NO page-per-day limit; scaled content abuse is judged on value
// and intent ("little to no value to users, no matter how it is created"),
// not volume. The old 6 (and the old 12 publish cap) were numbers this project
// invented and then treated as law. 40 also sits above our real output of
// ~10-20/day, so it constrains nothing we actually make - it just stops the
// repaired backlog appearing in one burst.
const REVEAL_PER_DAY = 40;

function reveal_run(PDO $pdo, bool $dry = false): array
{
    // how many did we already reveal today?
    $today = (int)$pdo->query(
        "SELECT COUNT(*) FROM pages
          WHERE robots='index' AND status='published'
            AND updated_at >= CURDATE()")->fetchColumn();
    $budget = max(0, REVEAL_PER_DAY - $today);
    if ($budget <= 0) {
        return ['budget' => 0, 'revealed' => 0, 'note' => 'daily reveal budget already used'];
    }

    $rows = $pdo->query(
        "SELECT t.page_id, t.term, t.lane FROM terms t JOIN pages p ON p.id = t.page_id
          WHERE p.status='published' AND p.robots='noindex'
          ORDER BY t.page_id")->fetchAll(PDO::FETCH_ASSOC);

    $revealed = 0; $checked = 0; $names = [];
    foreach ($rows as $r) {
        if ($revealed >= $budget) { break; }
        $checked++;
        $g = gate_check_term((int)$r['page_id']);
        if (empty($g['pass'])) { continue; }          // not ready: stays hidden
        if (!$dry) {
            $pdo->prepare("UPDATE pages SET robots='index', updated_at=NOW() WHERE id=?")
                ->execute([(int)$r['page_id']]);
        }
        $revealed++;
        $names[] = $r['lane'] . '/' . $r['term'];
    }
    // DRAMA PAGES (2026-08-31). This function only ever queried the TERMS
    // table, so a published-but-hidden drama page could never be revealed -
    // it would wait at noindex forever. That went unnoticed while the hidden
    // backlog was all slang; it became critical the moment 212 drama pages
    // came back out of the archive. Same budget, same one-at-a-time gate:
    // gate_check_drama decides quality, and page_publish_live() applies the
    // owner's PER-PAGE defamation rule (2026-08-22) and writes robots itself.
    if ($revealed < $budget) {
        require_once __DIR__ . '/gate.php';
        $drow = $pdo->query(
            "SELECT p.id, p.slug FROM pages p
              WHERE p.type='drama' AND p.status='published' AND p.robots='noindex'
              ORDER BY p.published_at DESC, p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($drow as $d) {
            if ($revealed >= $budget) { break; }
            $checked++;
            $g = gate_check_drama((int)$d['id']);
            if (empty($g['pass'])) { continue; }
            // 2026-09-24 a story the index rules still block is skipped here: calling
            // page_publish_live() on it re-stamped published_at and updated_at every hour
            if (drama_index_block($pdo, (int)$d['id']) !== '') { continue; }
            if ($dry) { $revealed++; $names[] = 'drama/' . $d['slug']; continue; }
            $live = false;
            try { $live = page_publish_live($pdo, (int)$d['id']); }
            catch (Throwable $e) { continue; }
            if ($live) { $revealed++; $names[] = 'drama/' . $d['slug']; }
        }
    }

    return ['budget' => $budget, 'checked' => $checked,
            'revealed' => $revealed, 'pages' => $names];
}
