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
    $texts = ['title' => $p['h1'] . ' ' . $p['title_tag'], 'description' => $p['meta_desc'], 'summary' => $p['summary']];
    foreach ($pdo->query("SELECT answer FROM faqs WHERE drama_id=" . $s['did'])->fetchAll(PDO::FETCH_COLUMN) as $a) $texts['FAQ'] = ($texts['FAQ'] ?? '') . ' ' . $a;
    if ($over) {
        foreach ($texts as $where => $t) if (preg_match(PR_ACTIVE_RX, (string)$t, $m))
            $out[] = "rule 1: the {$where} calls the story \"{$m[1]}\", but nothing new has happened since {$since}";
    }
    // a status sentence dated by no source, or naming none ("As of <the day we wrote it>")
    [$srcDates] = pr_page_status_facts($pdo, $s['did']);
    foreach (['summary' => $p['summary'], 'description' => $p['meta_desc']] + (isset($texts['FAQ']) ? ['FAQ' => $texts['FAQ']] : []) as $where => $t)
        foreach (back_sentences((string)$t) as $sent)
            if (preg_match('/\bAs of\b/', $sent) && !pr_asof_sourced($sent, $srcDates)) { $out[] = "rule 1: the {$where} dates a status \"" . mb_substr($sent, 0, 60) . "...\" by no source it names"; break; }
    // "announces" about something already done: the story is over, or a date the page gave as ahead has passed
    $passed = false;
    foreach ($s['next'] as $n) if (($d = (string)($n['date'] ?? '')) !== '' && pr_date_end($d) > 0 && pr_date_end($d) < time()) $passed = true;
    if (($over || $passed) && preg_match(PR_ANNOUNCE_RX, $p['h1'] . ' | ' . $p['title_tag'] . ' | ' . $p['meta_desc'], $m))
        $out[] = "rule 1: the title or description says \"{$m[1]}\" about something that already happened (a recap title is needed)";
    return $out;
}

/**
 * The latest development a source reported, as one sentence dated by that report (owner 2026-09-28: "never stamp today's
 * date on a status ... the status must come from a source"): "The latest development, reported by Dexerto on Sep 21: ...".
 * $latest: ['date' => the event's date, 'by' => its source's outlet, 'title' => the event]. '' when there is none.
 */
function pr_latest_sentence(array $latest): string {
    $title = rtrim(pr_past_title(trim((string)($latest['title'] ?? ''))), " .");
    if ($title === '') return '';
    $raw = (string)($latest['by'] ?? '');
    $by = trim((string)preg_replace('/\s*\(original post\)\s*$/i', '', $raw));
    $when = ($latest['date'] ?? '') !== '' ? pr_date_label((string)$latest['date']) : '';
    return 'The latest development' . ($by !== '' ? (stripos($raw, 'original post') !== false ? ", posted on {$by}" : ", reported by {$by}") : '')
         . ($when !== '' ? " on {$when}" : '') . ': ' . $title . '.';
}

/** Is a sentence's "As of <date>" the date of one of the page's sources (a day either side), with a source named? */
function pr_asof_sourced(string $sent, array $srcDates): bool {
    if (!preg_match('/\bAs of ([A-Z][a-z]+\.?\s+\d{1,2}(?:,?\s+\d{4})?|[A-Z][a-z]+\s+\d{4})\b/u', $sent, $m)) return !preg_match('/\bAs of\b/', $sent);
    $txt = str_replace('.', '', $m[1]);
    $monthOnly = !preg_match('/\s\d{1,2}(\b|,)/', $txt);
    $ts = strtotime($monthOnly ? "1 {$txt}" : (preg_match('/\d{4}/', $txt) ? $txt : $txt . ' ' . gmdate('Y')));
    if (!$ts) return false;
    $dated = false;
    foreach ($srcDates as $sd) {
        if (!$sd || !($st = strtotime((string)$sd))) continue;
        if ($monthOnly ? gmdate('Y-m', $st) === gmdate('Y-m', $ts) : abs($st - $ts) <= 86400) $dated = true;
    }
    return $dated && (bool)preg_match('/\b(per|according to|reported|reports|said|says|told|posted|wrote)\b/i', $sent);
}

/** Status sentences not dated by a source that names it, replaced by the latest development (one; the rest go). */
function pr_fix_asof(string $text, array $srcDates, string $latestSentence): string {
    $parts = back_sentences($text) ?: [$text];
    $hit = false;
    foreach ($parts as $i => $sent) if (preg_match('/\bAs of\b/', $sent) && !pr_asof_sourced($sent, $srcDates)) { $parts[$i] = $hit ? '' : $latestSentence; $hit = true; }
    return $hit ? trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts)))) : $text;
}

/** A page's source dates and its latest reported development, from the stored page. */
function pr_page_status_facts(PDO $pdo, int $did): array {
    $dates = $pdo->query("SELECT DISTINCT s.published_on FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id={$did} AND s.published_on IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $l = $pdo->query("SELECT e.event_date date, e.title, COALESCE(s.publisher, '') `by` FROM events e LEFT JOIN sources s ON s.id=e.source_id
                      WHERE e.drama_id={$did} AND e.video_only=0 AND e.event_date <= UTC_DATE() AND e.event_date NOT LIKE '%-00' ORDER BY e.event_date DESC, e.sort_order DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    return [array_values(array_filter($dates)), pr_latest_sentence($l)];
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
    'asks' => 'asked', 'agrees' => 'agreed', 'comments' => 'commented', 'reaches' => 'reached', 'gains' => 'gained', 'pleads' => 'pleaded', 'criticizes' => 'criticized', 'defends' => 'defended', 'accepts' => 'accepted', 'refuses' => 'refused', 'appears' => 'appeared', 'gets' => 'got', 'goes' => 'went', 'makes' => 'made'];

function pr_past_title(string $t): string {
    // the subject: 1-4 words opening the entry, none a possessive ("Alpha's claims go viral" is left alone)
    return (string)preg_replace_callback('/^((?:[\p{Lu}\p{N}][\p{L}\p{N}_.&\-\x{2010}\x{2011}]*\s+){1,4})([a-z]+)\b/u', function ($m) {
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
    // rule 1: a story that is over no longer says it is going on, and no status is dated by anything but a source that it
    // names (owner 2026-09-28): such a sentence becomes the latest development, dated by its report (summary and FAQ
    // answers; the title and description go to the AI rewrite)
    [$srcDates, $latest] = pr_page_status_facts($pdo, $did);
    $over = pr_story_over($s['lifecycle'], $s['last'], $s['next']);
    $fix = fn(string $text): string => pr_fix_asof($over && $latest !== '' ? pr_fix_status_text($text, $latest) : $text, $srcDates, $latest);
    $sum = (string)$pdo->query("SELECT summary FROM pages WHERE id={$pageId}")->fetchColumn();
    if ($latest !== '' && ($new = $fix($sum)) !== $sum) { $pdo->prepare("UPDATE pages SET summary=? WHERE id=?")->execute([$new, $pageId]); $done[] = 'summary status: the latest development, dated by its report'; }
    foreach ($pdo->query("SELECT id, answer FROM faqs WHERE drama_id={$did}")->fetchAll(PDO::FETCH_ASSOC) as $f)
        if ($latest !== '' && ($new = $fix((string)$f['answer'])) !== (string)$f['answer']) { $pdo->prepare("UPDATE faqs SET answer=? WHERE id=?")->execute([$new, (int)$f['id']]); $done[] = 'FAQ status: the latest development, dated by its report'; }
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
    // rule 1: an ended story's summary and FAQ never say it is going on, and a status is dated by a source it names, never
    // by the day we wrote it (owner 2026-09-28); such a sentence becomes the latest development, dated by its report
    $byId = []; $srcDates = [];
    foreach ($d['sources'] ?? [] as $src) { $byId[(int)$src['id']] = (string)($src['publisher'] ?? ''); if (!empty($src['published_on'])) $srcDates[] = (string)$src['published_on']; }
    $latest = ['date' => '', 'by' => '', 'title' => ''];
    foreach ($d['events'] ?? [] as $e) {
        $iso = (string)($e['date_iso'] ?? '');
        if (strlen($iso) !== 10 || $iso > gmdate('Y-m-d') || $iso < $latest['date']) continue;
        $latest = ['date' => $iso, 'by' => $byId[(int)($e['sources'][0] ?? 0)] ?? '', 'title' => (string)($e['title'] ?? '')];
    }
    $latestSentence = pr_latest_sentence($latest);
    if ($latestSentence !== '') {
        $over = str_starts_with((string)($d['status'] ?? ''), 'No new developments') || ($d['status'] ?? '') === 'Resolved';
        $fix = fn(string $t): string => pr_fix_asof($over ? pr_fix_status_text($t, $latestSentence) : $t, $srcDates, $latestSentence);
        $d['summary'] = $fix((string)($d['summary'] ?? ''));
        foreach ($d['faqs'] ?? [] as $i => $f) $d['faqs'][$i]['a'] = $fix((string)$f['a']);
    }
    return $d;
}

// ---------------------------------------------------------------- rule 4: a plain definition goes to the glossary

const PR_TERM_DEMAND_MIN = 10.0;   // owner 2026-09-27: 10+ Wikipedia/Wiktionary views a day is real demand

/**
 * Where a term belongs (owner 2026-09-28, "new beats demand"): a term first seen in the last 60 days or rising now gets its
 * own page, explaining why it is everywhere now; an older term keeps one only with an original part AND real demand
 * (10+ Wikipedia/Wiktionary views a day); everything else is a glossary entry.
 */
function term_route(bool $original, ?float $viewsPerDay, bool $current = false): string {
    if ($current) return 'page';
    return $original && $viewsPerDay !== null && $viewsPerDay >= PR_TERM_DEMAND_MIN ? 'page' : 'glossary';
}

const PR_TERM_FRESH_DAYS  = 60;   // owner 2026-09-28: first seen in the last 60 days
const PR_TERM_RISING_DAYS = 14;   // owner: several posts in the last 2 weeks, or outlet coverage
const PR_TERM_RISING_POSTS = 3;       // owner 2026-09-28: "3+ different posts with real reach"
const PR_TERM_REACH_MIN    = 10000;   // owner: "e.g. 10k+ views each"; a post with no view count does not count

/** A comment under someone else's post or video (YouTube &lc=, a Reddit comment permalink): "seen in use" only, never
 *  evidence that a term is rising (owner 2026-09-28: "3 random comments isn't a trend"). */
function term_cite_is_comment(array $c): bool {
    $u = (string)($c['url'] ?? '');
    return (bool)preg_match('#[?&]lc=#', $u) || (bool)preg_match('#reddit\.com/r/[^/]+/comments/[^/]+/[^/]+/[a-z0-9]+#i', $u);
}

/** A citation that is a person using the term (a post, a comment: "seen in use"), not a source writing about it. */
function term_cite_is_post(array $c): bool {
    // by its address first: some rows store a website's name as their "platform" ("bbc.co.uk")
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url((string)($c['url'] ?? ''), PHP_URL_HOST)));
    if ($host !== '' && preg_match(PR_SOCIAL_RX, $host)) return true;
    return trim((string)($c['publication'] ?? '')) === ''
        && (bool)preg_match('/^(x|twitter|reddit|youtube|tiktok|instagram|threads|bluesky|bsky|facebook|twitch|kick|steam|discord)$/i', trim((string)($c['platform'] ?? '')));
}

/** A term's citations with each post on its own link: a post whose link another person's post also carries (the writer
 *  copied one link onto several posts, 2026-09-28) gets its own from the posts we collected, or is left out. */
function term_cites_clean(array $cites, string $term): array {
    static $own = [];
    $byUrl = [];
    foreach ($cites as $c) if (is_array($c) && term_cite_is_post($c)) $byUrl[(string)($c['url'] ?? '')][mb_strtolower(ltrim((string)($c['handle'] ?? ''), '@'))] = 1;
    // the posts we collected are read only when a link is shared (two cache files; pages render this)
    if (!isset($own[$term]) && array_filter($byUrl, fn($hs) => count($hs) > 1)) {
        $own[$term] = [];
        require_once __DIR__ . '/reach_usage.php';
        ob_start();   // it narrates for the build log
        foreach (reach_usage_citations($term, 40) as $u) $own[$term][mb_strtolower(ltrim((string)$u['handle'], '@'))] = $u;
        ob_end_clean();
    }
    $out = []; $seen = [];
    foreach ($cites as $c) {
        if (!is_array($c) || trim((string)($c['url'] ?? '')) === '') continue;
        // a post link the writer made up: a tweet id is digits (2026-09-28: /slang/crit/ cited status/gXxYoZjSfE, a 404)
        if (preg_match('#(?:twitter|x)\.com/[^/]+/status(?:es)?/([^/?\#]+)#i', (string)$c['url'], $sm) && !ctype_digit($sm[1])) continue;
        if (term_cite_is_post($c)) {
            $h = mb_strtolower(ltrim((string)($c['handle'] ?? ''), '@'));
            if (count($byUrl[(string)$c['url']] ?? []) > 1) {          // one link, several people: not this post's own link
                if (!isset($own[$term][$h])) continue;   // no link of its own: left out
                $c['url'] = (string)$own[$term][$h]['url'];
                $c['date'] = (string)($own[$term][$h]['date'] ?? ($c['date'] ?? ''));
            }
        }
        $key = rtrim(strtolower((string)$c['url']), '/');
        if (isset($seen[$key])) continue;                              // the same link twice: once
        $seen[$key] = 1;
        $out[] = $c;
    }
    return $out;
}

/**
 * Did an outlet write ABOUT the term (not just use it)? The headline names the term and is about it as a word or a meme
 * (2026-09-28: "GTA 6 'Breaking Records' For XBOX Pre-Orders" uses "pre-order"; "Abuse Goblin Meme Meaning and Origin"
 * is about "abuse goblin"). Blog platforms are not outlets.
 */
function term_cite_is_coverage(array $c, string $term): bool {
    if (term_cite_is_post($c)) return false;
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url((string)($c['url'] ?? ''), PHP_URL_HOST)));
    if (preg_match('/(^|\.)(note\.com|medium\.com|substack\.com|blogspot\.com|wordpress\.com|tumblr\.com|quora\.com|fandom\.com|wikia\.com|urbandictionary\.com|wiktionary\.org|wikipedia\.org|knowyourmeme\.com)$/', $host)) return false;
    $title = mb_strtolower((string)($c['title'] ?? ''));
    $stem = preg_replace('/(es|s)$/u', '', mb_strtolower(trim($term)));
    if ($stem === '' || !str_contains(preg_replace('/[^\p{L}\p{N} ]/u', ' ', $title), preg_replace('/[^\p{L}\p{N} ]/u', ' ', $stem))) return false;
    return (bool)preg_match('/\b(meme|memes|meaning|means|mean|slang|trend|trending|viral|origin|explained|explainer|what is|what are|what does|what\'s|rise of|phenomenon|term|phrase|word|craze|everywhere|why (everyone|people|gen z))\b/u', $title);
}

/** A stored or written date ("24 Sep 2026", "2026-09-24", "August 2026") as Y-m-d, '' when there is none. */
function term_date(string $d): string {
    $d = trim($d);
    if ($d === '' || !preg_match('/\d{4}/', $d)) return '';
    // a bare year is January 1 of it (strtotime read "2021" as 20:21 today, and rizz came out first seen this week)
    $ts = strtotime(preg_match('/^\d{4}$/', $d) ? "{$d}-01-01" : (preg_match('/^\d{4}-\d{2}$/', $d) ? "{$d}-01" : (preg_match('/^[A-Za-z]+\.?\s+\d{4}$/', $d) ? "1 {$d}" : $d)));
    return $ts ? gmdate('Y-m-d', $ts) : '';
}

/**
 * What makes a term current, from what the page holds: when it was first seen, which outlets wrote about it lately, and
 * which posts with 10k+ views used it (comments never count). ['fresh', 'rising', 'first_seen', 'posts', 'outlets', 'line' => one "why now" sentence or ''].
 */
function term_trend(array $t): array {
    $today = time();
    $first = term_date((string)($t['origin_date'] ?? ''));
    if ($first === '' && preg_match('/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(?:\d{1,2},?\s+)?\d{4})\b/i', (string)($t['first_seen'] ?? ''), $m)) $first = term_date($m[1]);
    $posts = []; $outlets = [];
    foreach (term_cites_clean((array)json_decode((string)($t['citations'] ?? '[]'), true), (string)($t['term'] ?? '')) as $c) {
        $d = term_date((string)($c['date'] ?? ''));
        if ($d === '' || strtotime($d) > $today || strtotime($d) < $today - 30 * 86400) continue;
        if (term_cite_is_post($c)) {
            // only a post (not a comment) with 10k+ views is evidence of a trend; the rest is "seen in use"
            if (term_cite_is_comment($c) || (int)($c['views'] ?? 0) < PR_TERM_REACH_MIN) continue;
            $posts[mb_strtolower((string)($c['handle'] ?? '')) ?: (string)$c['url']] = ['date' => $d, 'platform' => trim((string)($c['platform'] ?? '')) ?: (string)parse_url((string)$c['url'], PHP_URL_HOST)];
        }
        elseif (term_cite_is_coverage($c, (string)($t['term'] ?? ''))) {   // an article about the term, not one that just uses it
            $oh = mb_strtolower(preg_replace('/^www\./', '', (string)parse_url((string)$c['url'], PHP_URL_HOST)));
            $outlets[$oh] = ['date' => $d, 'name' => trim((string)($c['publication'] ?? '')) ?: $oh];
        }
    }
    $since = gmdate('Y-m-d', $today - PR_TERM_RISING_DAYS * 86400);
    $recentPosts = array_filter($posts, fn($p) => $p['date'] >= $since);
    $recentOutlets = array_filter($outlets, fn($o) => $o['date'] >= $since);
    $fresh = $first !== '' && $first >= gmdate('Y-m-d', $today - PR_TERM_FRESH_DAYS * 86400);
    $rising = count($recentPosts) >= PR_TERM_RISING_POSTS || count($recentOutlets) >= 1;
    // the "why now" line: dates and places, only from what the page cites (owner 2026-09-28 #3)
    $bits = [];
    if ($posts) {
        $ds = array_column($posts, 'date'); sort($ds);
        $plats = array_values(array_unique(array_column($posts, 'platform')));
        $bits[] = count($posts) . ' post' . (count($posts) > 1 ? 's' : '') . ' with ' . number_format(PR_TERM_REACH_MIN / 1000) . 'k+ views on ' . implode(' and ', array_slice($plats, 0, 3))
                . ($ds[0] === end($ds) ? ' on ' . date('M j, Y', strtotime($ds[0])) : ' between ' . date('M j', strtotime($ds[0])) . ' and ' . date('M j, Y', strtotime(end($ds))));
    }
    if ($outlets) {
        uasort($outlets, fn($a, $b) => strcmp($b['date'], $a['date']));
        $o = reset($outlets);
        $names = array_values(array_unique(array_filter(array_column($outlets, 'name'))));
        $bits[] = implode(' and ', array_slice($names ?: ['a news site'], 0, 2)) . ' wrote about it' . (count($outlets) > 1 ? ', most recently' : '') . ' on ' . date('M j, Y', strtotime($o['date']));
    }
    if ($fresh && !$bits) $bits[] = 'first seen ' . date('M j, Y', strtotime($first));
    return ['fresh' => $fresh, 'rising' => $rising, 'first_seen' => $first, 'posts' => $posts, 'outlets' => $outlets,
            'line' => $bits ? 'Why now: ' . implode('; ', $bits) . '.' : ''];
}

/** Wiktionary and Know Your Meme named in a text become links to their entries (owner 2026-09-28: "if we cite Wiktionary
 *  or Know Your Meme, link it"). $escapedHtml is already escaped; $sources: the page's own source links. */
function term_link_references(string $escapedHtml, string $term, array $sourceUrls): string {
    $find = fn(string $rx) => array_values(array_filter($sourceUrls, fn($u) => preg_match($rx, (string)$u)))[0] ?? '';
    $wikt = $find('#en\.wiktionary\.org/wiki/#') ?: 'https://en.wiktionary.org/wiki/' . rawurlencode(str_replace(' ', '_', mb_strtolower($term)));
    $kym  = $find('#knowyourmeme\.com/memes/#') ?: 'https://knowyourmeme.com/search?q=' . rawurlencode($term);
    foreach (['/\b(Wiktionary)\b(?![^<]*<\/a>)/u' => $wikt, '/\b(Know\s?Your\s?Meme)\b(?![^<]*<\/a>)/u' => $kym] as $rx => $url)
        $escapedHtml = (string)preg_replace($rx, '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener nofollow">$1</a>', $escapedHtml);
    return $escapedHtml;
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
    $t = $pdo->query("SELECT t.term, t.origin_url, t.origin_type, t.origin_date, t.first_seen, t.citations, t.scene_embed_provider, t.demand_views, t.demand_at FROM terms t WHERE t.page_id=" . $pageId)->fetch(PDO::FETCH_ASSOC);
    if (!$t) return 'unknown';
    $trend = term_trend($t);
    if ($trend['fresh'] || $trend['rising']) return 'page';   // new beats demand (owner 2026-09-28)
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

// ---------------------------------------------------------------- old past-event news: outcome source or held, once

/** pages.retry_block: why the pipeline must not try this page again (owner 2026-09-28). Outside a transaction. */
function pr_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE pages ADD COLUMN retry_block VARCHAR(160) NULL"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/** Old news about something that already happened: the story is over, or a date it gave as ahead has passed, and its
 *  title or description still announces it (Nintendo's two Directs, announced and aired). */
function pr_past_news(PDO $pdo, int $pageId): bool {
    return (bool)preg_grep('/announces what already happened|about something that already happened/', pr_status_problems($pdo, $pageId));
}

/**
 * May a redo rewrite this page from these sources? (owner 2026-09-28: "the redo doesn't work (Nintendo came out worse).
 * For these, either rewrite as 'what happened' with a new source about the outcome, or leave them held. Don't keep
 * retrying.") '' = yes; else why not, and the page is marked so nothing tries it again.
 */
function pr_redo_allowed(PDO $pdo, int $pageId, array $sources): string {
    pr_install($pdo);
    $block = (string)$pdo->query("SELECT COALESCE(retry_block, '') FROM pages WHERE id=" . $pageId)->fetchColumn();
    if ($block !== '') return $block;
    if (!pr_past_news($pdo, $pageId)) return '';
    $last = page_newest_event($pdo, $pageId);
    foreach ($sources as $src) if (($d = source_date((string)($src['date'] ?? ''))) && $d > $last) return '';
    $why = 'past news, no source about the outcome (' . gmdate('Y-m-d') . '): held as it is, not retried';
    $pdo->prepare("UPDATE pages SET retry_block=? WHERE id=?")->execute([$why, $pageId]);
    return $why;
}
