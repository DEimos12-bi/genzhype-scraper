<?php
/* Second editorial pass on page 443.
   The regenerated timeline script was correct but unreadable: seven beats ran
   together with no punctuation, which the voice would read as one breathless
   sentence. Shots anchor by WORD INDEX (w_in/w_out over 46 words), so the fix
   adds punctuation to existing tokens ONLY — no word is added, removed or
   reordered, and every shot stays locked to its beat.
   The director's fresh queries also came back generic ("Blizzard Entertainment
   logo") because a terse script gave it little to read, so the searches are
   written here from the actual reporting language instead. */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
$pdo = db();

$old = $pdo->query("SELECT script FROM video_scripts WHERE page_id=443")->fetchColumn();
$before = count(preg_split('/\s+/', trim((string)$old)));

$new = 'World of Warcraft cheating scandal. Player discovers suspicious activity. '
     . 'Blizzard investigates allegations. Developer uses instakill abilities. '
     . 'Player confesses to asking for help. Community reacts with outrage. '
     . 'Blizzard reaffirms anti-cheating stance. Blizzard fires developer and penalizes players. '
     . 'Every source and the full dated timeline: GenZHype dot com.';
$after = count(preg_split('/\s+/', trim($new)));

/* hard guard: identical word count or we do not ship it */
if ($before !== $after) {
    exit("REFUSED: word count changed $before -> $after; shot anchors would break\n");
}
$stripped = fn($s) => preg_replace('/[^a-z0-9 ]/', '', mb_strtolower(preg_replace('/\s+/', ' ', trim($s))));
if ($stripped($old) !== $stripped($new)) {
    exit("REFUSED: words themselves changed, not just punctuation\n");
}

$plan = [
    'clip_queries' => [
        'Blizzard fires World of Warcraft developer cheating scandal',
        'WoW dev cheating scandal Death Touch Mythic plus explained',
        'World of Warcraft Mythic 23 dungeon run cheating exposed',
        'Blizzard response World of Warcraft prohibited gameplay incident',
    ],
    'image_queries' => [
        'Blizzard forums Prohibited Gameplay Incident and Response statement',
        'World of Warcraft Mythic plus dungeon run screenshot',
        'World of Warcraft Death Touch developer spell tooltip',
    ],
    'skip' => false,
];

$pdo->prepare("UPDATE video_scripts SET script=?, visual_plan=?, video_status='pending',
               force_render=1, video_path=NULL, video_made_at=NULL WHERE page_id=443")
    ->execute([$new, json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

echo "word count held at $after — shot anchors intact\n\n";
echo "SCRIPT NOW:\n" . wordwrap($new, 76) . "\n";
