<?php
/**
 * ARCHIVE SWEEP (r93) — capture sources that have no copy yet, then ask the
 * Wayback Machine for the public ones.
 *
 *   php app/source_archive_run.php --limit=40      # capture 40 uncaptured
 *   php app/source_archive_run.php --wayback=20    # submit 20 queued
 *   php app/source_archive_run.php --recheck       # re-capture the ones we
 *                                                  # previously found dead
 *   php app/source_archive_run.php --report        # what we hold, no network
 *
 * Newest sources first: a page published today is the one most likely to
 * vanish before anyone notices, and the oldest ones have already survived.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/source_archive.php';

$pdo = db();
sa_install($pdo);

$args = $_SERVER['argv'] ?? [];
$opt = function (string $name, $default = null) use ($args) {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) return substr($a, strlen($name) + 3);
        if ($a === "--{$name}") return true;
    }
    return $default;
};

if ($opt('report')) {
    $rows = $pdo->query(
        "SELECT status, COUNT(*) n, SUM(bytes) b FROM source_archive GROUP BY status")->fetchAll();
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM sources")->fetchColumn();
    $cap = (int)$pdo->query("SELECT COUNT(*) FROM source_archive")->fetchColumn();
    $wb  = (int)$pdo->query("SELECT COUNT(*) FROM source_archive WHERE wayback_state='ok'")->fetchColumn();
    printf("captured %d of %d source(s); %d have a public Wayback copy\n\n", $cap, $tot, $wb);
    foreach ($rows as $r) {
        printf("  %-8s %5d   %s\n", $r['status'], (int)$r['n'],
               $r['b'] ? round((int)$r['b'] / 1048576, 1) . ' MB kept' : '');
    }
    exit(0);
}

if ($opt('audit')) {
    // WHY THIS EXISTS. Keeping the page's title turned out to catch a class of
    // bad citation that no status code can: the soft-404. A repaired Variety
    // link answers 200 on the article path and serves the FRONT PAGE (archived
    // title: "Variety"), and a Hindu link cited for a biryani row archives as
    // "160 kg of ganja seized near Madurai". Both look perfectly healthy to a
    // link checker. Compare what the page actually SAYS against the event it
    // is cited for. Reports only — a wording difference is not proof of error,
    // so a human decides.
    $stop = ['the','and','with','from','that','this','after','over','into','says','said',
             'news','video','his','her','its','for','are','was','were','have','has','who',
             'why','how','when','what'];
    $words = function (string $t) use ($stop): array {
        preg_match_all("/[A-Za-z0-9']{4,}/", $t, $m);
        $o = [];
        foreach ($m[0] as $w) { $lw = mb_strtolower($w); if (!in_array($lw, $stop, true)) $o[$lw] = 1; }
        return array_keys($o);
    };
    $rows = $pdo->query(
        "SELECT a.source_id, a.title atitle, a.description adesc, a.url,
                e.title etitle, e.description edesc,
                p.id pid, p.slug, p.status pstatus, p.h1 ptitle,
                d.people_json pj
           FROM source_archive a
           JOIN events e   ON e.source_id = a.source_id
           JOIN dramas d   ON d.id = e.drama_id
           JOIN pages  p   ON p.id = d.page_id
          WHERE a.status='ok' AND a.title <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $bad = 0; $skipped = 0;
    foreach ($rows as $r) {
        // The PAGE's own title counts too. An event line reads "Fans flood
        // social media with tributes" while the article it cites is headlined
        // "What happened to Metronade? Cause of death revealed" — the right
        // source, phrased differently. Matching the story's subject as well as
        // the event's wording keeps that from being called a bad citation.
        $want = $words((string)$r['etitle'] . ' ' . (string)$r['edesc']
                       . ' ' . (string)$r['ptitle']);
        // What the PAGE says, never the URL. The URL is the thing under
        // suspicion — letting it vouch for itself is circular, and it hid the
        // Hindu case on the first run: the slug reads "...biryani-remark"
        // while the page that comes back is about a drug seizure.
        $hay  = mb_strtolower((string)$r['atitle'] . ' ' . (string)$r['adesc']);
        // Social platforms never hand a real title to a server fetch: TikTok
        // answers "TikTok - Make Your Day", YouTube "- YouTube", X just the
        // account name. Judging those as wrong citations was four of the five
        // live flags on the first pass — noise that would have buried the one
        // real problem. A post's existence is proved elsewhere (the card
        // renderer asks X itself); silence here is not evidence.
        $t = trim(mb_strtolower((string)$r['atitle']));
        if ($t === '' || preg_match('/^(- )?(youtube|tiktok|instagram|facebook|x|twitter)\b/', $t)
                || str_contains($t, 'make your day')
                || preg_match('/\(@[A-Za-z0-9_]+\) on x$/', $t)) {
            $skipped++;
            continue;
        }
        // r101 THE NAME IS NOT EVIDENCE. "JiDion Leads Online Sting" cites an
        // article headlined "JiDion detained while removing a squatter from
        // McDonald's" — a different incident entirely — and this audit passed
        // it because both contain the word JiDion. On a one-person story the
        // subject's name appears in EVERY article about them, so a match on the
        // name alone links nothing. Count the matches that are not the person.
        // ONLY the actual people, from people_json — not every word in the page
        // title. Excluding the whole title also stripped the TOPIC words
        // ("streaming", "controversy") and flagged 358 sources instead of the
        // handful that are genuinely wrong.
        $people = [];
        foreach ((array)json_decode((string)$r['pj'], true) as $pp) {
            $nm = is_array($pp) ? ($pp['name'] ?? '') : $pp;
            foreach (preg_split("/[^A-Za-z0-9']+/", (string)$nm) as $pw) {
                $pw = mb_strtolower($pw);
                if (mb_strlen($pw) >= 4) $people[$pw] = 1;
            }
        }
        $hits = 0; $nameOnly = 0;
        // (string) because PHP turns an array key that looks like a number
        // into an int — a story word such as "2026" crashed str_contains and
        // killed this audit four rows in.
        foreach ($want as $w) {
            if (!str_contains($hay, (string)$w)) continue;
            if (isset($people[(string)$w])) { $nameOnly++; continue; }
            $hits++;
        }
        if ($hits >= 1) continue;
        $why = $nameOnly ? "ONLY the subject name links these" : "nothing links these";
        $bad++;
        printf("page %-5s %-28s\n  event:  %s\n  source: %s\n  says:   %s\n  ^^ %s\n\n",
               $r['pid'] . ($r['pstatus'] === 'published' ? '' : '*'),
               mb_substr((string)$r['slug'], 0, 28),
               mb_substr((string)$r['etitle'], 0, 74),
               mb_substr(preg_replace('#^https?://(www\.)?#', '', (string)$r['url']), 0, 74),
               mb_substr((string)$r['atitle'], 0, 74), $why);
    }
    printf("%d citation(s) whose page says nothing about the event, of %d checked\n"
         . "(%d skipped: a social platform that never exposes a real title)\n",
           $bad, count($rows), $skipped);
    exit(0);
}

if ($wbN = $opt('wayback')) {
    $r = sa_wayback_sweep($pdo, (int)$wbN);
    printf("wayback: %d saved, %d to retry, of %d tried\n", $r['done'], $r['failed'], $r['tried']);
    exit(0);
}

$limit = (int)($opt('limit', 25));
$sql = $opt('recheck')
    // a "dead" verdict can be a bad minute on the publisher's side, so the
    // ones we condemned get another hearing before anybody acts on it
    ? "SELECT s.id, s.url FROM sources s
         JOIN source_archive a ON a.source_id = s.id
        WHERE a.status IN ('dead','error') AND s.url LIKE 'http%'
        ORDER BY a.captured_at ASC LIMIT " . max(1, $limit)
    : "SELECT s.id, s.url FROM sources s
         LEFT JOIN source_archive a ON a.source_id = s.id
        WHERE a.id IS NULL AND s.url LIKE 'http%'
        ORDER BY s.id DESC LIMIT " . max(1, $limit);

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
printf("capturing %d source(s)%s\n\n", count($rows), $opt('recheck') ? ' (recheck)' : '');

$tally = [];
foreach ($rows as $r) {
    [$status, $note] = sa_capture($pdo, (int)$r['id'], (string)$r['url']);
    $tally[$status] = ($tally[$status] ?? 0) + 1;
    printf("  %-8s %-58s %s\n", $status,
           mb_substr(preg_replace('#^https?://(www\.)?#', '', (string)$r['url']), 0, 58),
           $note);
}
echo "\n";
foreach ($tally as $k => $n) printf("%s: %d\n", $k, $n);
echo "\nnext: --wayback=20 to put the public copies in place\n";
