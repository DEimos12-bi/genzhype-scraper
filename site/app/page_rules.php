<?php
// GenZHype | THE OWNER'S PAGE RULES (2026-09-27, final). Code checks, not AI instructions, wherever a rule is
// mechanical ("an AI can forget an instruction, code can't"). The AI still writes titles and our take; code checks
// what it wrote. Each rule has cases in the permanent test set (rule_tests.php, `php app/cli.php ruletest`).
//   1 the status matches today: status from dates; no "ongoing" text on a story that is over; no "announces" for what
//     already happened (a recap title instead); past timeline entries in the past tense
//   2 the publish date never changes (db.php pages_publish_date_lock, a database trigger); a redo moves "Updated" only
//   3 our take names a fact (a name or number) from the timeline, or it is dropped
//   4 a plain slang definition goes to the glossary (term_route)
//   5 titles and descriptions: a death or crime is attributed or quoted; a quote is real and has its speaker
//   6 crime, abuse and death stories: no take, no evidence read; an accusation rests on an outlet, an official or
//     court record, or the person's own words, never on an anonymous post alone
//   7 those stories wait for the owner before going live (human_review.php, wider words)
// Hold reasons start "rule N:" so admin, the test set and the logs can tell which rule held a page.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backing.php';
require_once __DIR__ . '/human_review.php';

const PR_QUIET_DAYS = 14;   // [ours] nothing new for 14 days and nothing dated ahead = the story is over for its label
const PR_SOCIAL_RX = '/(^|\.)(x|twitter|tiktok|youtube|youtu|instagram|facebook|threads|reddit|twitch|kick|bsky|discord|snapchat)\.(com|be|tv|net|app|gg)$/i';

// ---------------------------------------------------------------- rule 1: the status matches today

/** Is anything dated still ahead? Undated "awaited" items do not count: the writer adds one to almost every story. */
function pr_has_ahead(array $next): bool {
    require_once __DIR__ . '/story_context.php';
    $today = gmdate('Y-m-d');
    foreach ($next as $n) if (($d = (string)($n['date'] ?? '')) !== '' && story_context_not_past($d, $today)) return true;
    return false;
}

/** The last day a stored date can mean ('2026-08-00' = Aug 31), as a timestamp; 0 when none. */
function pr_date_end(?string $d): int {
    [$y, $m, $dd] = array_map('intval', array_pad(explode('-', substr((string)$d, 0, 10)), 3, '0'));
    if ($y < 1900) return 0;
    return $m === 0 ? mktime(23, 59, 59, 12, 31, $y) : ($dd === 0 ? mktime(23, 59, 59, $m + 1, 0, $y) : mktime(23, 59, 59, $m, $dd, $y));
}

/** Over = no event in PR_QUIET_DAYS and nothing dated ahead, or a source reported the ending. */
function pr_story_over(?string $lifecycle, ?string $lastEvent, array $next): bool {
    if ($lifecycle === 'resolved') return true;
    if (pr_has_ahead($next)) return false;
    $end = pr_date_end($lastEvent);
    return $end > 0 && $end < time() - PR_QUIET_DAYS * 86400;
}

/** A page's story facts: [lifecycle, newest past event date, next items as stored (passed ones included)]. */
function pr_story_state(PDO $pdo, int $pageId): ?array {
    $st = $pdo->prepare("SELECT d.id did, d.lifecycle, d.whats_next, (SELECT MAX(e.event_date) FROM events e WHERE e.drama_id=d.id AND e.video_only=0
                         AND e.event_date <= UTC_DATE()) last_event FROM dramas d WHERE d.page_id=?");
    $st->execute([$pageId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['did' => (int)$r['did'], 'lifecycle' => (string)$r['lifecycle'], 'last' => (string)$r['last_event'],
                 'next' => (array)json_decode((string)$r['whats_next'], true)] : null;
}

const PR_ACTIVE_RX = '/\b(ongoing|developing|still unfolding|unfolding|continues to (?:unfold|develop|escalate|grow)|is escalating|heating up)\b/i';
const PR_ANNOUNCE_RX = '/\b(announces|reveals|unveils|confirms|teases|schedules|launches|drops|releases|plans|is coming|coming soon|upcoming|will (?:air|release|launch|drop|arrive|premiere|reveal|announce|hold|stream)|set to (?:air|release|launch|premiere|arrive))\b/i';

/** "Sep 12" / "Sep 12, 2025" / "August 2026" for a stored date. */
function pr_date_label(string $d): string {
    require_once __DIR__ . '/story_context.php';
    [$label] = story_event_date($d);
    return (string)preg_replace('/, ' . gmdate('Y') . '$/', '', $label);
}

/** Rule 1 problems on a story page (reasons start "rule 1:"). */
function pr_status_problems(PDO $pdo, int $pageId): array {
    $s = pr_story_state($pdo, $pageId);
    if (!$s) return [];
    $p = $pdo->query("SELECT h1, title_tag, meta_desc, summary FROM pages WHERE id=" . $pageId)->fetch(PDO::FETCH_ASSOC);
    $out = [];
    $over = pr_story_over($s['lifecycle'], $s['last'], $s['next']);
    $since = $s['last'] !== '' ? pr_date_label($s['last']) : '';
    if ($over) {
        $texts = ['title' => $p['h1'] . ' ' . $p['title_tag'], 'description' => $p['meta_desc'], 'summary' => $p['summary']];
        foreach ($pdo->query("SELECT answer FROM faqs WHERE drama_id=" . $s['did'])->fetchAll(PDO::FETCH_COLUMN) as $a) $texts['FAQ'] = ($texts['FAQ'] ?? '') . ' ' . $a;
        foreach ($texts as $where => $t) if (preg_match(PR_ACTIVE_RX, (string)$t, $m))
            $out[] = "rule 1: the {$where} calls the story \"{$m[1]}\", but nothing new has happened since {$since}";
    }
    // "announces" about something already done: the story is over, or a date the page gave as ahead has passed
    $passed = false;
    foreach ($s['next'] as $n) if (($d = (string)($n['date'] ?? '')) !== '' && pr_date_end($d) > 0 && pr_date_end($d) < time()) $passed = true;
    if (($over || $passed) && preg_match(PR_ANNOUNCE_RX, $p['h1'] . ' | ' . $p['title_tag'] . ' | ' . $p['meta_desc'], $m))
        $out[] = "rule 1: the title or description says \"{$m[1]}\" about something that already happened (a recap title is needed)";
    return $out;
}

/** A status sentence built from the dates only: "As of September 28, 2026, no new developments have been reported since Sep 12." */
function pr_status_sentence(string $last): string {
    return 'As of ' . gmdate('F j, Y') . ', no new developments have been reported' . ($last !== '' ? ' since ' . pr_date_label($last) : '') . '.';
}

/** The sentences of $text that call the story going on, replaced by one status sentence built from the dates. */
function pr_fix_status_text(string $text, string $status): string {
    $parts = back_sentences($text) ?: [$text];
    $hit = false;
    foreach ($parts as $i => $sent) if (preg_match(PR_ACTIVE_RX, $sent)) { $parts[$i] = $hit ? '' : $status; $hit = true; }
    return $hit ? trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts)))) : $text;
}

/** Past tense for the verb that opens a past timeline entry ("Nintendo announces two Directs" -> "announced"). */
const PR_PAST = ['announces' => 'announced', 'reveals' => 'revealed', 'unveils' => 'unveiled', 'confirms' => 'confirmed', 'teases' => 'teased',
    'launches' => 'launched', 'releases' => 'released', 'drops' => 'dropped', 'says' => 'said', 'posts' => 'posted', 'responds' => 'responded',
    'replies' => 'replied', 'denies' => 'denied', 'apologizes' => 'apologized', 'claims' => 'claimed', 'accuses' => 'accused', 'calls' => 'called',
    'files' => 'filed', 'sues' => 'sued', 'shares' => 'shared', 'returns' => 'returned', 'quits' => 'quit', 'leaves' => 'left', 'joins' => 'joined',
    'bans' => 'banned', 'wins' => 'won', 'loses' => 'lost', 'addresses' => 'addressed', 'admits' => 'admitted', 'slams' => 'slammed',
    'fires' => 'fired', 'schedules' => 'scheduled', 'airs' => 'aired', 'begins' => 'began', 'starts' => 'started', 'ends' => 'ended',
    'reacts' => 'reacted', 'issues' => 'issued', 'publishes' => 'published', 'uploads' => 'uploaded', 'streams' => 'streamed', 'tells' => 'told',
    'reports' => 'reported', 'alleges' => 'alleged', 'speaks' => 'spoke', 'cancels' => 'canceled', 'delays' => 'delayed', 'adds' => 'added',
    'removes' => 'removed', 'deletes' => 'deleted', 'holds' => 'held', 'faces' => 'faced', 'receives' => 'received', 'signs' => 'signed',
    'breaks' => 'broke', 'hits' => 'hit', 'sets' => 'set', 'plans' => 'planned', 'shows' => 'showed', 'explains' => 'explained', 'warns' => 'warned',
    'asks' => 'asked', 'agrees' => 'agreed', 'refuses' => 'refused', 'appears' => 'appeared', 'gets' => 'got', 'goes' => 'went', 'makes' => 'made'];

function pr_past_title(string $t): string {
    // the subject: 1-4 words opening the entry, none a possessive ("Alpha's claims go viral" is left alone)
    return (string)preg_replace_callback('/^((?:[\p{Lu}\p{N}][\p{L}\p{N}.&\-]*\s+){1,4})([a-z]+)\b/u', function ($m) {
        if (preg_match("/['’]s?\s*$/u", $m[1]) || !isset(PR_PAST[$m[2]])) return $m[0];
        return $m[1] . PR_PAST[$m[2]];
    }, $t, 1);
}

// ---------------------------------------------------------------- rule 3: our take names a fact from the timeline

const PR_GENERIC_NAMES = ['twitch', 'youtube', 'tiktok', 'reddit', 'instagram', 'discord', 'kick', 'facebook', 'twitter', 'internet', 'fans',
    'viewers', 'creators', 'streamers', 'gamers', 'players', 'people', 'everyone', 'nobody', 'someone', 'genzhype', 'our', 'it', 'if', 'as'];

/** True when the take names a person, place, product or number that the timeline also has (case-sensitive: "Fans" is not a name). */
function pr_take_specific(string $take, string $timeline): bool {
    if (trim($take) === '' || trim($timeline) === '') return false;
    preg_match_all('/\b\d[\d,.]*\b/u', $take, $nums);
    foreach ($nums[0] as $n) if (preg_match('/(?<![\d,.])' . preg_quote(rtrim($n, '.,'), '/') . '(?![\d])/', $timeline)) return true;
    preg_match_all("/(?<![\p{L}\p{N}])(\p{Lu}[\p{L}\p{N}\-]{2,})(?:['’]s)?/u", $take, $caps);
    foreach ($caps[1] as $w) {
        $lw = mb_strtolower($w);
        if (in_array($lw, BACK_STOP, true) || in_array($lw, PR_GENERIC_NAMES, true)) continue;
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $timeline)) return true;
    }
    return false;
}

// ---------------------------------------------------------------- rule 5: titles and descriptions

const PR_HARM_RX = '/\b(dead|dies|died|death(?!\s+threats?)|killed|kills|murder(?:ed|s)?|arrested|assault(?:ed|s)?|abused?|abusing|attacked|punched|stabbed|shot dead|charged with|raped?|groomed|overdosed?|suicide|jailed|convicted|stole|scammed|harassed|choked|strangled|kidnapped|swatted)\b/i';
const PR_ATTRIB_RX = '/\b(says?|said|claims?|claimed|alleges?|alleged(?:ly)?|allegations?|accus\w*|denies|denied|investigat\w*|sentenced|convicted|guilty|pleads?|pleaded|sues|sued|reportedly|according to|reports?|reported|police|court|jury|judge|prosecutors?|lawsuit|charges|confirms?|confirmed|family|clip shows|video shows|footage shows|posts? show)\b/i';
const PR_SAY_VERBS = 'says|said|writes|wrote|tweets|tweeted|posts|posted|replies|replied|responds|responded|claims|claimed|admits|admitted|tells|told|asks|asked|calls|called|insists|insisted|jokes|joked|slams|slammed|shares|shared';
// game and film names that carry a harm word (a game about the dead is not a death)
const PR_TITLE_NAMES_RX = '/\b(dead by daylight|red dead\w*|dead space|dead island|left 4 dead|walking dead|death stranding|deathloop|killing floor|dead cells|deadlock|dying light|assassin\'s creed|shot online)\b/i';

/** Rule 5 problems in one title or description. $sources: the page's source text; $lane gaming guards game lore. */
function pr_headline_problems(string $text, string $sources, string $where = 'title', string $lane = 'drama'): array {
    $out = [];
    $plain = (string)preg_replace('/["“][^"”]*["”]/u', ' ', $text);   // quoted words are the speaker's, checked below
    $plain = (string)preg_replace(PR_TITLE_NAMES_RX, ' ', $plain);
    if ($lane === 'gaming' && preg_match('/\b(raid|boss|dungeon|quest|respawn|campaign|expansion|season|patch|mode|character|hero|villain)\b/i', $plain)
        && !preg_match('/\b(passed away|found dead|police|arrested|court|funeral|family)\b/i', $plain))
        $plain = (string)preg_replace('/\b(dead|dies|died|death|killed|kills|attacked)\b/i', ' ', $plain);
    if (preg_match(PR_HARM_RX, $plain, $m) && !preg_match(PR_ATTRIB_RX, $plain))
        $out[] = "rule 5: the {$where} states \"{$m[1]}\" as fact with no attribution (it needs \"police say\", \"alleged\", \"X says\" or the person's words in quotes)";
    preg_match_all('/["“]([^"”]{3,160})["”]/u', $text, $q, PREG_OFFSET_CAPTURE);
    foreach ($q[1] as [$quote, $at]) {
        $norm = fn(string $t) => trim(preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '“', '”'], ["'", "'", '"', '"'], mb_strtolower(html_entity_decode($t, ENT_QUOTES)))), " .,!?;:");
        $found = mb_strlen($quote) >= 20 ? back_quote_found($quote, $sources) : str_contains($norm($sources), $norm($quote));
        if (!$found) { $out[] = "rule 5: the {$where} quotes \"{$quote}\", which no source says"; continue; }
        // a name in quotes (an album, a show, a meme, a slogan: two or more words, each long word capitalised) is not speech
        if (preg_match('/^\s*\S+\s+\S/u', $quote) && !preg_match('/(?<![\p{L}\x27’])\p{Ll}[\p{L}\x27’]{3,}/u', $quote)) continue;
        $before = substr($text, 0, max(0, $at - 1));
        $after = substr($text, $at + strlen($quote) + 1);
        $name = "[\p{L}\p{N}_'’.&\-]*\p{Lu}[\p{L}\p{N}_'’.&\-]*(?:\s+[\p{L}\p{N}_'’.&\-]*\p{Lu}[\p{L}\p{N}_'’.&\-]*){0,3}";
        $spoken = preg_match("/{$name}\s*(?:(?:" . PR_SAY_VERBS . ')\s*:?|:)\s*$/u', $before)
               || preg_match('/^["”]?,?\s*(?:(?:' . PR_SAY_VERBS . ")\s+{$name}|{$name}\s+(?:" . PR_SAY_VERBS . '))/u', ltrim($after, '"”'));
        if (!$spoken) $out[] = "rule 5: the {$where} quotes \"{$quote}\" without saying who said it";
    }
    return $out;
}

// ---------------------------------------------------------------- rule 6: crime, abuse and death stories

/** Is this a crime, abuse or death story? (the same test that holds it for the owner, human_review.php) */
function pr_is_grave(PDO $pdo, int $pageId): bool {
    return hr_page_reasons($pdo, $pageId) !== [];
}

/** The social account a post comes from, from its address or its stored text ("Original tweet by Dexerto (@Dexerto): ..."). */
function pr_post_author(string $url, string $excerpt): array {
    $who = [];
    if (preg_match('#(?:x|twitter)\.com/([A-Za-z0-9_]{1,20})/status/#i', $url, $m)) $who[] = $m[1];
    if (preg_match('#(?:tiktok|youtube)\.com/@([\w.\-]+)#i', $url, $m)) $who[] = $m[1];
    if (preg_match('#(?:instagram\.com|twitch\.tv|kick\.com)/([\w.\-]+)/?(?:$|\?|p/|reel/|clip/|videos?/)#i', $url, $m) && !in_array(strtolower($m[1]), ['p', 'reel', 'tv', 'videos'], true)) $who[] = $m[1];
    if (preg_match('#reddit\.com/(?:u|user)/([\w\-]+)#i', $url, $m)) $who[] = $m[1];
    if (preg_match('/^Original \w+ (?:post )?by ([^:(]+?)\s*(?:\(@([\w.\-]+)\))?:/u', $excerpt, $m)) { $who[] = trim($m[1]); if (!empty($m[2])) $who[] = $m[2]; }
    return array_values(array_unique(array_filter($who)));
}

/** Names and accounts that count as a story's own people, and outlets whose own accounts count as the outlet. */
function pr_voices(PDO $pdo, int $did): array {
    $norm = fn(string $x) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($x));
    $mine = [];
    foreach ((array)json_decode((string)$pdo->query("SELECT people_json FROM dramas WHERE id={$did}")->fetchColumn(), true) as $pe) {
        if (!empty($pe['name'])) $mine[$norm((string)$pe['name'])] = 1;
        foreach ((array)($pe['sameAs'] ?? []) as $u)
            if (preg_match('#(?:x|twitter|tiktok|youtube|instagram|twitch|kick|reddit)\.(?:com|tv)/(?:@|u/|user/)?([\w.\-]+)#i', (string)$u, $m)) $mine[$norm($m[1])] = 1;
    }
    static $outlets = null;
    if ($outlets === null) {
        $outlets = [];
        foreach ($pdo->query("SELECT DISTINCT domain, publisher FROM sources WHERE domain IS NOT NULL AND domain<>''") as $r) {
            $h = preg_replace('/^www\./', '', (string)$r['domain']);
            if (preg_match(PR_SOCIAL_RX, $h)) continue;
            foreach ([preg_replace('/\.[a-z.]{2,6}$/', '', $h), (string)$r['publisher']] as $x) if (strlen($k = $norm($x)) >= 3) $outlets[$k] = 1;
        }
    }
    return [$mine, $outlets, $norm];
}

/** Does an event accuse someone of a crime, abuse, violence or a death? */
function pr_is_accusation(string $t): bool {
    return (bool)preg_match(PR_HARM_RX, $t) || (bool)preg_match('/\b(accus\w*|alleg\w*|misconduct|harass\w*|predator\w*|groom\w*)\b/i', $t);
}

/** Accusation events that rest only on an anonymous post: [['id','title','url','why'], ...]. */
function pr_anonymous_accusations(PDO $pdo, int $pageId): array {
    $did = (int)$pdo->query("SELECT id FROM dramas WHERE page_id=" . $pageId)->fetchColumn();
    if (!$did || !pr_is_grave($pdo, $pageId)) return [];
    [$mine, $outlets, $norm] = pr_voices($pdo, $did);
    $out = [];
    $q = $pdo->query("SELECT e.id, e.title, e.description, s.url, s.domain, s.excerpt FROM events e LEFT JOIN sources s ON s.id=e.source_id
                      WHERE e.drama_id={$did} AND e.video_only=0");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if (!pr_is_accusation($e['title'] . '. ' . $e['description'])) continue;
        $host = preg_replace('/^www\./', '', strtolower((string)($e['domain'] ?: parse_url((string)$e['url'], PHP_URL_HOST))));
        if ($host !== '' && !preg_match(PR_SOCIAL_RX, $host)) continue;               // an outlet or an official or court record
        $ok = false;
        foreach (pr_post_author((string)$e['url'], (string)$e['excerpt']) as $a) if (isset($mine[$norm($a)]) || isset($outlets[$norm($a)])) $ok = true;
        if (!$ok) $out[] = ['id' => (int)$e['id'], 'title' => (string)$e['title'], 'description' => (string)$e['description'], 'url' => (string)$e['url'],
                            'why' => 'rule 6: the accusation "' . mb_substr((string)$e['title'], 0, 80) . '" rests only on a post by someone who is not in the story'];
    }
    return $out;
}

// ---------------------------------------------------------------- the checks and the code fixes, in one place

/** Every rule 1, 5 and 6 reason that holds a story page (acc_hard_fails adds them). */
function pr_hard_fails(PDO $pdo, int $pageId): array {
    $p = $pdo->query("SELECT p.h1, p.title_tag, p.meta_desc, d.id did, d.lane FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=" . $pageId)->fetch(PDO::FETCH_ASSOC);
    if (!$p) return [];
    $why = pr_status_problems($pdo, $pageId);
    $src = implode("\n", $pdo->query("SELECT DISTINCT s.excerpt FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=" . (int)$p['did'])->fetchAll(PDO::FETCH_COLUMN));
    foreach (['title' => $p['h1'], 'title tag' => $p['title_tag'], 'description' => $p['meta_desc']] as $where => $t)
        foreach (pr_headline_problems((string)$t, $src, $where, (string)$p['lane']) as $w) $why[] = $w;
    foreach (pr_anonymous_accusations($pdo, $pageId) as $a) $why[] = $a['why'];
    return array_values(array_unique($why));
}

/** A page's timeline text (titles and descriptions), what our take must draw on. */
function pr_timeline_text(PDO $pdo, int $did): string {
    return implode("\n", $pdo->query("SELECT CONCAT(COALESCE(title, ''), '. ', description) FROM events WHERE drama_id={$did} AND video_only=0")->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The rules code can apply by itself, on the stored page (the accuracy step runs this first; the site applies 3 and 6 at
 * render time too). Returns what it changed. Nothing here calls an AI.
 */
function rules_fix_page(PDO $pdo, int $pageId): array {
    $s = pr_story_state($pdo, $pageId);
    if (!$s) return ['error' => 'not a story page'];
    $did = $s['did'];
    $done = [];
    // rule 1: past timeline entries in the past tense
    foreach ($pdo->query("SELECT id, title FROM events WHERE drama_id={$did} AND video_only=0 AND event_date <= UTC_DATE()")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $t = pr_past_title((string)$e['title']);
        if ($t !== (string)$e['title']) { $pdo->prepare("UPDATE events SET title=? WHERE id=?")->execute([$t, (int)$e['id']]); $done[] = "entry now \"{$t}\""; }
    }
    // rule 1: a story that is over no longer says it is going on (summary and FAQ answers; titles go to the AI rewrite)
    if (pr_story_over($s['lifecycle'], $s['last'], $s['next'])) {
        $status = pr_status_sentence($s['last']);
        $fix = fn(string $text): string => pr_fix_status_text($text, $status);
        $sum = (string)$pdo->query("SELECT summary FROM pages WHERE id={$pageId}")->fetchColumn();
        if (($new = $fix($sum)) !== $sum) { $pdo->prepare("UPDATE pages SET summary=? WHERE id=?")->execute([$new, $pageId]); $done[] = 'summary status from the dates'; }
        foreach ($pdo->query("SELECT id, answer FROM faqs WHERE drama_id={$did}")->fetchAll(PDO::FETCH_ASSOC) as $f)
            if (($new = $fix((string)$f['answer'])) !== (string)$f['answer']) { $pdo->prepare("UPDATE faqs SET answer=? WHERE id=?")->execute([$new, (int)$f['id']]); $done[] = 'FAQ status from the dates'; }
    }
    $grave = pr_is_grave($pdo, $pageId);
    $timeline = pr_timeline_text($pdo, $did);
    // rule 6: a crime, abuse or death story carries no take and no evidence read
    // rule 3: elsewhere, a take naming nothing from the timeline is dropped
    $why = (string)$pdo->query("SELECT why_matters FROM dramas WHERE id={$did}")->fetchColumn();
    if (trim($why) !== '' && ($grave || !pr_take_specific($why, $timeline))) {
        $pdo->prepare("UPDATE dramas SET why_matters=NULL WHERE id=?")->execute([$did]);
        $done[] = $grave ? 'take removed (crime, abuse or death story)' : 'take removed (named nothing from the timeline)';
    }
    foreach ($pdo->query("SELECT id, why_matters FROM events WHERE drama_id={$did} AND why_matters IS NOT NULL AND why_matters<>''")->fetchAll(PDO::FETCH_ASSOC) as $e)
        if ($grave || !pr_take_specific((string)$e['why_matters'], $timeline)) { $pdo->prepare("UPDATE events SET why_matters=NULL WHERE id=?")->execute([(int)$e['id']]); $done[] = 'an entry take removed'; }
    if ($grave && (string)$pdo->query("SELECT COALESCE(verdict, '') FROM dramas WHERE id={$did}")->fetchColumn() !== '') {
        $pdo->prepare("UPDATE dramas SET verdict=NULL WHERE id=?")->execute([$did]);
        $done[] = 'evidence read removed (crime, abuse or death story)';
    }
    // rule 6: an accusation resting on an anonymous post moves to an outlet on the page that reports it, or comes out
    if ($anon = pr_anonymous_accusations($pdo, $pageId)) {
        $outs = [];
        foreach ($pdo->query("SELECT DISTINCT s.id, s.domain, s.excerpt, s.title, s.publisher FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id={$did}")->fetchAll(PDO::FETCH_ASSOC) as $o)
            if (!preg_match(PR_SOCIAL_RX, preg_replace('/^www\./', '', (string)$o['domain']))) $outs[] = $o;
        foreach ($anon as $a) {
            $best = null;
            foreach ($outs as $o) {
                $corpus = ['words' => array_fill_keys(back_words($o['excerpt'] . ' ' . $o['title']), 1), 'low' => mb_strtolower($o['excerpt'] . ' ' . $o['title'] . ' ' . $o['publisher'])];
                [$bad, $support] = back_check($a['title'] . '. ' . $a['description'], $corpus);
                if (!$bad && $support >= 0.6 && (!$best || $support > $best[1])) $best = [(int)$o['id'], $support];
            }
            if ($best) { $pdo->prepare("UPDATE events SET source_id=? WHERE id=?")->execute([$best[0], $a['id']]); $done[] = "accusation now cites the outlet that reports it: \"{$a['title']}\""; }
            else {
                require_once __DIR__ . '/accuracy.php';
                acc_backup($pdo, $pageId, 'rule6');
                $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$a['id']]);
                $done[] = "accusation removed (only an anonymous post said it): \"{$a['title']}\"";
            }
        }
    }
    if ($done) $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);   // upkeep: "Updated" stays, the cache and fact check see the change
    return ['changed' => $done];
}

/**
 * What the site shows, whatever is stored (rules 1, 3 and 6 at render time, so a page nobody has re-run yet still obeys):
 * a crime, abuse or death story loses our take, the entry takes and the evidence read; any other story keeps a take only
 * if it names something from its timeline; an ended story's summary and FAQ never call it ongoing.
 */
function pr_story_view(array $d): array {
    $timeline = implode("\n", array_map(fn($e) => ($e['title'] ?? '') . '. ' . ($e['desc'] ?? ''), $d['events'] ?? []));
    $grave = hr_reasons((string)($d['title'] ?? ''), (string)($d['summary'] ?? ''), $timeline) !== [];
    if ($grave || !pr_take_specific((string)($d['why_matters'] ?? ''), $timeline)) $d['why_matters'] = '';
    foreach ($d['events'] ?? [] as $i => $e)
        if (!empty($e['why']) && ($grave || !pr_take_specific((string)$e['why'], $timeline))) $d['events'][$i]['why'] = null;
    if ($grave) $d['verdict'] = null;
    // rule 1: an ended story's summary and FAQ never say it is going on (a status refresh can write that back between
    // two checks): the sentence is replaced by one built from the dates
    if (str_starts_with((string)($d['status'] ?? ''), 'No new developments') || ($d['status'] ?? '') === 'Resolved') {
        $past = array_filter(array_column($d['events'] ?? [], 'date_iso'), fn($x) => $x !== '' && $x <= gmdate('Y-m-d'));
        $last = $past ? (string)max($past) : '';
        $last = strlen($last) === 7 ? $last . '-00' : (strlen($last) === 4 ? $last . '-00-00' : $last);
        $status = pr_status_sentence($last);
        $d['summary'] = pr_fix_status_text((string)($d['summary'] ?? ''), $status);
        foreach ($d['faqs'] ?? [] as $i => $f) $d['faqs'][$i]['a'] = pr_fix_status_text((string)$f['a'], $status);
    }
    return $d;
}

// ---------------------------------------------------------------- rule 4: a plain definition goes to the glossary

const PR_TERM_DEMAND_MIN = 10.0;   // owner 2026-09-27: 10+ Wikipedia/Wiktionary views a day is real demand

/** 'page' only with an original part AND real demand; everything else is a glossary entry. */
function term_route(bool $original, ?float $viewsPerDay): string {
    return $original && $viewsPerDay !== null && $viewsPerDay >= PR_TERM_DEMAND_MIN ? 'page' : 'glossary';
}

/** A term page's original part: a dated origin artifact of a real kind (a post, an archive, an official record, a video,
 *  a dictionary's dated entry), or a scene clip. The same test the 2026-09-27 keep/fold count used. */
function term_original(array $t): bool {
    return (!empty($t['origin_url']) && in_array((string)($t['origin_type'] ?? ''), ['social_post', 'archive', 'official', 'video', 'lexicographic'], true))
        || !empty($t['scene_embed_provider']);
}

/** terms.demand_views (daily Wikipedia/Wiktionary views, 90-day average) and when it was read. Outside a transaction. */
function term_demand_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    foreach (["ADD COLUMN demand_views DECIMAL(10,1) NULL", "ADD COLUMN demand_at DATE NULL"] as $alter)
        try { $pdo->exec("ALTER TABLE terms {$alter}"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/**
 * Real demand for a term: its daily views over the last 90 days on Wikipedia (the article Wikipedia's own search finds)
 * or Wiktionary (lower case and as written), whichever is higher. One request a second, a named user agent (the reader
 * was cut off when it went faster, 2026-09-27). null when Wikimedia could not be reached; 0.0 when no page exists.
 */
function term_demand_measure(string $term): ?float {
    $reached = false;
    $get = function (string $u) use (&$reached): ?array {
        usleep(1000000);
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'GenZHypeBot/1.0 (https://genzhype.com; contact@genzhype.com)']);
        $r = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 || $code === 404) $reached = true;
        return $code === 200 ? (json_decode((string)$r, true) ?: null) : null;
    };
    $avg = function (string $proj, string $title) use ($get): float {
        $j = $get("https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/{$proj}/all-access/user/" . rawurlencode(str_replace(' ', '_', $title))
                  . '/daily/' . gmdate('Ymd', strtotime('-92 days')) . '/' . gmdate('Ymd', strtotime('-2 days')));
        $items = (array)($j['items'] ?? []);
        return $items ? array_sum(array_column($items, 'views')) / count($items) : 0.0;
    };
    $norm = fn(string $s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    $best = 0.0;
    $os = $get('https://en.wikipedia.org/w/api.php?action=opensearch&limit=3&namespace=0&format=json&search=' . rawurlencode($term));
    foreach ((array)($os[1] ?? []) as $cand)
        if (str_contains($norm((string)$cand), $norm($term)) || str_contains($norm($term), $norm((string)$cand))) { $best = max($best, $avg('en.wikipedia', (string)$cand)); break; }
    foreach (array_unique([mb_strtolower($term), $term]) as $cand) $best = max($best, $avg('en.wiktionary', $cand));
    return $reached ? round($best, 1) : null;
}

/** Where a term page belongs (rule 4): 'page', 'glossary', or 'unknown' when its demand could not be read. */
function term_route_page(PDO $pdo, int $pageId): string {
    term_demand_install($pdo);
    $t = $pdo->query("SELECT t.term, t.origin_url, t.origin_type, t.scene_embed_provider, t.demand_views, t.demand_at FROM terms t WHERE t.page_id=" . $pageId)->fetch(PDO::FETCH_ASSOC);
    if (!$t) return 'unknown';
    if (!term_original($t)) return 'glossary';   // a plain definition: no need to ask about demand
    $views = $t['demand_views'] !== null && (string)$t['demand_at'] >= gmdate('Y-m-d', strtotime('-30 days')) ? (float)$t['demand_views'] : null;
    if ($views === null) {
        $views = term_demand_measure((string)$t['term']);
        if ($views === null) return 'unknown';
        $pdo->prepare("UPDATE terms SET demand_views=?, demand_at=UTC_DATE() WHERE page_id=?")->execute([$views, $pageId]);
    }
    return term_route(true, $views);
}

/** A term page folded into its lane's glossary: off its own address (a 301 to its glossary section), nothing deleted. */
function term_fold(PDO $pdo, int $pageId): bool {
    require_once __DIR__ . '/lanes.php';
    $t = $pdo->query("SELECT p.slug, t.lane FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.id=" . $pageId . " AND p.type='term'")->fetch(PDO::FETCH_ASSOC);
    $prefix = $t ? (lanes()[$t['lane']]['prefix'] ?? '') : '';
    if (!$t || !in_array($t['lane'], ['slang', 'meme', 'gaming'], true) || $prefix === '') return false;
    $pdo->prepare("UPDATE pages SET status='archived', robots='noindex', redirect_to=?, updated_at=NOW() WHERE id=?")
        ->execute([$prefix . 'glossary/#' . $t['slug'], $pageId]);
    return true;
}
