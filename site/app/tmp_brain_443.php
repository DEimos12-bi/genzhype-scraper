<?php
/* EDITORIAL PASS on drama 335 / page 443, done by hand.
   Findings that drove it:
   - all six "timeline" events carried the SAME date (2026-07-22) — the day we
     scraped, not the days things happened. A timeline format with one date is
     not a timeline.
   - the story RESOLVED and we never said so: Blizzard fired the developer on
     2026-07-24 (Kotaku, GameSpot, MMO.net, VGC) after its own statement on
     2026-07-23. Our script ended on "will act".
   - Blizzard's own forum post is the strongest receipt available and was not
     among our sources.
   Everything below is sourced; nothing is invented. */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
$pdo = db();
$DID = 335; $PAGE = 443;

/* 1. the primary source + the firing source */
$srcIns = $pdo->prepare(
    "INSERT INTO sources (url, domain, publisher, title, reliability, retrieved_on, excerpt)
     VALUES (?,?,?,?,?,CURDATE(),?)");
$srcId = function (string $url) use ($pdo) {
    $q = $pdo->prepare("SELECT id FROM sources WHERE url=? LIMIT 1");
    $q->execute([$url]); return (int)($q->fetchColumn() ?: 0);
};

$blizUrl = 'https://us.forums.blizzard.com/en/wow/t/prohibited-gameplay-incident-and-response/2329585';
if (!$srcId($blizUrl)) {
    $srcIns->execute([$blizUrl, 'us.forums.blizzard.com', 'Blizzard Entertainment',
        'Prohibited Gameplay Incident and Response', 5,
        'As we mentioned, we internally monitored and confirmed a recent incident in which a '
        . 'World of Warcraft group gained an unfair advantage by abuse of access to '
        . 'development-only spells on a live realm. We can confirm that this was the action of '
        . 'a single Blizzard employee, in violation of our Code of Conduct.']);
}
$kotakuUrl = 'https://kotaku.com/blizzard-fires-dev-wow-cheating-scandal-2000719107';
if (!$srcId($kotakuUrl)) {
    $srcIns->execute([$kotakuUrl, 'kotaku.com', 'Kotaku',
        'Blizzard Fires Dev At The Center Of WoW Cheating Scandal', 4,
        'Blizzard has fired a senior QA developer after an investigation turned up that the '
        . 'developer had helped a group of players cheat in a World of Warcraft dungeon.']);
}
$blizSrc = $srcId($blizUrl); $kotakuSrc = $srcId($kotakuUrl);

/* 2. real dates. Discovery + community on the 22nd, Blizzard's statement on the
      23rd, the firing on the 24th. */
$pdo->prepare("UPDATE events SET event_date='2026-07-23', source_id=?, sort_order=10
               WHERE drama_id=? AND title='Blizzard Response'")
    ->execute([$blizSrc, $DID]);

/* 3. the ending the video never had */
$has = $pdo->prepare("SELECT COUNT(*) FROM events WHERE drama_id=? AND title=?");
$has->execute([$DID, 'Developer Fired']);
if (!$has->fetchColumn()) {
    $pdo->prepare(
        "INSERT INTO events (drama_id, event_date, title, description, source_id,
                             is_confirmed, sort_order, video_only, confirmed_by, confirmed_src)
         VALUES (?,?,?,?,?,1,20,0,?,?)")
        ->execute([$DID, '2026-07-24', 'Developer Fired',
            'Blizzard confirmed the senior QA developer behind the development-only spell abuse '
            . 'is no longer with the company, and said it also actioned the players who benefited.',
            $kotakuSrc, 'editorial-verification', $kotakuUrl]);
}

/* 4. show the corrected spine */
echo "TIMELINE NOW:\n";
foreach ($pdo->query("SELECT e.event_date, e.title, s.domain FROM events e
                      LEFT JOIN sources s ON s.id=e.source_id
                      WHERE e.drama_id=$DID AND e.video_only=0
                      ORDER BY e.event_date, e.sort_order") as $e) {
    printf("  %s  %-32s %s\n", $e['event_date'], $e['title'], $e['domain'] ?: '-');
}
