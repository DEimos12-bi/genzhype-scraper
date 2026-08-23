<?php
/**
 * GenZHype | SLANG ORIGIN REPAIR (2026-08-22)
 * =============================================================================
 * The slang/meme/gaming lanes published nothing for weeks, and 34 of 40 pages
 * failed on ONE gate check: "origin artifact (typed + host-verified)".
 *
 * That gate is not broken and is not being loosened. Read its own rule:
 *     claims an origin -> must produce a typed artifact + an attributed date
 *     claims no origin -> must say NOTHING about where the term came from
 * Both paths are legitimate; fabrication is impossible on either. Cambridge
 * ranks #1 for "delulu meaning" with no etymology at all.
 *
 * What was actually wrong: the DRAFTER always writes an origin, because an AI
 * asked "where did this word come from" will always answer. So every page took
 * path 1 while only 4 of 108 had a real artifact URL to back it — and the gate
 * correctly refused the rest.
 *
 * This repair moves an unprovable page onto path 2 instead of letting it die:
 * if there is no verified artifact, the origin CLAIM is removed, and the page
 * simply stops making a claim it cannot support. Nothing is invented, nothing
 * is asserted, and the page can publish on its remaining merits.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gate_term.php';

/**
 * Strip unprovable origin claims. $dry=true reports without writing.
 * Returns ['checked'=>, 'stripped'=>, 'kept'=>, 'examples'=>[]].
 */
function slang_repair_origins(PDO $pdo, int $cap = 200, bool $dry = false): array
{
    $rows = $pdo->query(
        "SELECT t.page_id, t.term, t.origin, t.first_seen, t.origin_url,
                t.origin_date, t.origin_date_src
           FROM terms t JOIN pages p ON p.id = t.page_id
          WHERE p.status IN ('published','draft','review')
          LIMIT " . max(1, min(500, $cap)))->fetchAll(PDO::FETCH_ASSOC);

    $checked = 0; $stripped = 0; $kept = 0; $examples = [];
    $up = $pdo->prepare("UPDATE terms
                            SET origin = '[]', first_seen = '',
                                origin_url = NULL, origin_date = NULL,
                                origin_date_src = NULL, origin_type = NULL
                          WHERE page_id = ?");

    foreach ($rows as $r) {
        $checked++;
        $origin    = (array)json_decode((string)$r['origin'], true);
        $firstSeen = trim((string)$r['first_seen']);
        $claims    = count($origin) >= 1 || $firstSeen !== '';
        if (!$claims) { continue; }                       // already on path 2

        $url  = trim((string)$r['origin_url']);
        $type = $url !== '' ? gate_term_artifact_type($url) : null;
        $dOk  = gate_term_date_is_attributed((string)$r['origin_date'],
                                             (string)$r['origin_date_src']);
        if ($type !== null && $dOk) { $kept++; continue; }  // provable, leave it

        if (!$dry) { $up->execute([(int)$r['page_id']]); }
        $stripped++;
        if (count($examples) < 6) {
            $examples[] = $r['term'] . ' (' . ($url === '' ? 'no artifact url'
                        : ($type === null ? 'unrecognised host' : 'date not attributed')) . ')';
        }
    }
    return ['checked' => $checked, 'stripped' => $stripped,
            'kept' => $kept, 'examples' => $examples];
}
