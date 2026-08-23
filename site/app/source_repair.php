<?php
/**
 * SOURCE REPAIR (r92) — find the REAL article behind a dead citation.
 *
 * WHY THIS EXISTS. The stale-backlog pass added ending events to nine
 * published pages. The events were true, but several of their source URLs
 * were written in the SHAPE of a plausible URL instead of copied from the
 * article: apnews.com/article/mrbeast-lawsuit-harassment-beast-industries
 * never existed, while the real piece sits at
 * apnews.com/article/mrbeast-sexual-harassment-pregnant-maternity-leave-...
 * A citation that 404s is worse than no citation: the page still promises
 * the reader a source, and the promise is empty.
 *
 * HOW IT REPAIRS. For each dead citation it searches dated press for the
 * event, then accepts a replacement ONLY when all three hold:
 *   - the SAME publisher (a different outlet is a different citation, and
 *     swapping outlets silently would rewrite what the page claims);
 *   - published within a few days of the event we attached it to;
 *   - the candidate's title or URL carries distinctive words from the event.
 * Anything short of that is left alone and listed for a human. Bot-walled
 * links (403/406) are never touched — they are alive, just rude to robots.
 *
 * Dry run by default; --apply writes. Every change is echoed and logged.
 *   php app/source_repair.php            # show what it WOULD do
 *   php app/source_repair.php --apply    # do it
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/reach.php';

$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
$pdo = db();

/** Registrable-ish host: news.bbc.co.uk and bbc.co.uk are the same publisher. */
function sr_host(string $u): string {
    $h = strtolower((string)(parse_url($u, PHP_URL_HOST) ?: ''));
    $h = preg_replace('/^(www|amp|m)\./', '', $h);
    $p = explode('.', $h);
    return count($p) > 2 ? implode('.', array_slice($p, -2)) : $h;
}

function sr_words(string $t): array {
    static $stop = ['the','and','with','from','that','this','after','over','into','says','said',
        'news','video','2026','2025','his','her','its','for','are','was','were','have','has'];
    $out = [];
    foreach (preg_match_all("/[A-Za-z0-9']{4,}/", $t, $m) ? $m[0] : [] as $w) {
        $lw = mb_strtolower($w);
        if (!in_array($lw, $stop, true)) $out[$lw] = 1;
    }
    return array_keys($out);
}

// Dead citations worth repairing. 403/406 are bot walls: the link is ALIVE and
// a reader in a browser reaches it, so touching it would destroy a good source.
$rows = $pdo->query(
    "SELECT li.url, li.status, li.kind, li.pages
       FROM link_issues li
      WHERE li.kind IN ('broken','gone')
        AND li.status NOT IN (403, 406, 429, 0)")->fetchAll(PDO::FETCH_ASSOC);

printf("%d dead citation(s) to repair (bot-walled links left alone)\n\n", count($rows));

$fixed = 0; $manual = 0;
foreach ($rows as $r) {
    $dead = (string)$r['url'];
    $host = sr_host($dead);

    // the event(s) this URL is cited by
    $q = $pdo->prepare(
        "SELECT e.id, e.title, e.description, e.event_date, s.id sid, p.id pid, p.slug
           FROM sources s
           JOIN events e ON e.source_id = s.id
           JOIN dramas d ON d.id = e.drama_id
           JOIN pages  p ON p.id = d.page_id
          WHERE s.url = ?");
    $q->execute([$dead]);
    $evs = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$evs) { echo "  (no event cites it any more) {$dead}\n"; continue; }
    $e = $evs[0];

    $query = trim(mb_substr((string)$e['title'], 0, 90) . ' ' . $host);
    $want  = sr_words((string)$e['title'] . ' ' . (string)$e['description']);
    $evTs  = strtotime((string)$e['event_date']) ?: 0;

    $best = null;
    foreach (reach_exa_search($query, 8) as $c) {
        $cu = (string)($c['url'] ?? '');
        if ($cu === '' || sr_host($cu) !== $host) continue;          // same publisher only
        $pub = strtotime((string)($c['published'] ?? '')) ?: 0;
        if ($evTs && $pub && abs($pub - $evTs) > 5 * 86400) continue; // same few days
        $hay = mb_strtolower((string)($c['title'] ?? '') . ' ' . $cu);
        $hits = 0;
        foreach ($want as $w) if (str_contains($hay, $w)) $hits++;
        if ($hits < 2) continue;                                      // must be THIS story
        $best = ['url' => $cu, 'title' => (string)($c['title'] ?? ''),
                 'published' => (string)($c['published'] ?? ''), 'hits' => $hits];
        break;
    }

    echo "page {$e['pid']} · " . mb_substr((string)$e['slug'], 0, 34) . "\n";
    echo "  DEAD  ({$r['status']}) " . mb_substr($dead, 8, 74) . "\n";
    if (!$best) {
        echo "  ->    no same-publisher match found; LEFT for a human\n\n";
        $manual++;
        continue;
    }
    if (rtrim($best['url'], '/') === rtrim($dead, '/')) {
        // The search found the very URL we already store. So the citation is
        // correct and the failure was the server's, not ours — a 500, or a
        // redirect that lands back on itself. Rewriting it would change
        // nothing and would let us claim a repair we did not make.
        echo "  ->    the stored URL IS the real one; the outage was the "
           . "publisher's. Left untouched.\n\n";
        $manual++;
        continue;
    }
    echo "  REAL  " . mb_substr($best['url'], 8, 74) . "\n";
    echo "        " . mb_substr($best['title'], 0, 74) . "  ["
       . substr($best['published'], 0, 10) . ", {$best['hits']} matching words]\n";
    if ($apply) {
        $pdo->prepare("UPDATE sources SET url=? WHERE id=?")
            ->execute([$best['url'], (int)$e['sid']]);
        echo "  APPLIED\n";
    }
    echo "\n";
    $fixed++;
}

printf("%s: %d repairable, %d need a human\n",
       $apply ? "APPLIED" : "DRY RUN (add --apply to write)", $fixed, $manual);
