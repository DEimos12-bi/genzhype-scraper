<?php
// GenZHype | DRAMA REAL HERO. The honest, hard path: get the ACTUAL image of the
// drama (the real coverage's thumbnail of the real people/event), not a text card.
// Avoids the missing-child wrong-match by searching the SPECIFIC story (not a bare
// name), checking title relevance, then vision-verifying the pick. Branded card is
// only the true last resort, when nothing real verifies.

require_once __DIR__ . '/fetch_sources.php';

/** True if a name token (>=4 chars) appears in the drama title — i.e. this person is
 *  the actual subject, not someone merely mentioned. Lets us trust a verified face. */
function _drama_anchored(string $name, string $title): bool {
    $tl = mb_strtolower($title);
    foreach (preg_split('/\s+/', mb_strtolower($name)) as $tok)
        if (mb_strlen($tok) >= 4 && str_contains($tl, $tok)) return true;
    return false;
}

/** The single dignity test used everywhere: stories where putting a real person's face
 *  on the headline would be cruel, defamatory, or exploitative (death, sexual violence,
 *  abuse, arrest, mental health, body/cosmetic criticism, minors). These stay branded
 *  cards unless vision itself approves a non-exploitative image — never a blind pick,
 *  never an auto-backfill. */
function drama_is_sensitive(string $title, string $summary): bool {
    // TRULY-HARMFUL only (per owner policy 2026-06-21): a real face on these would be cruel,
    // defamatory or unlawful. Borderline-but-public topics — self-disclosed mental health,
    // cosmetic surgery, public-record arrests — are NOT here, so they may get a respectful
    // real photo. Kept: death/suicide, sexual violence, abuse/harassment/grooming/trafficking,
    // kidnap, minors, eating disorders, self-harm.
    return (bool)preg_match('/\b(murder\w*|kill\w*|dead|dies|died|death|suicid\w*|overdose|funeral|obituar\w*|rape\w*|sexual\w*|molest\w*|assault\w*|abus\w*|harass\w*|groom\w*|traffick\w*|miscarriage|stillbirth|kidnap\w*|abduct\w*|minor|underage|child\w*|eating disorder|self.?harm)\b/i', $title . ' ' . $summary);
}

/**
 * A creator's REAL FACE from their YouTube channel avatar (via YouTube Data API).
 * Picks the channel whose TITLE best matches the name (so "Reckless Ben" doesn't
 * grab "Reckless Ben UnRedacted" or a fan/clips channel). Returns ['url','name',
 * 'source','channel_id'] or null.
 */
function yt_creator_face(string $name, string $context = ''): ?array {
    $ch = yt_channel_lookup($name);
    if (!$ch) return null;
    // 2026-09-24: a name match + 10k subscribers is not identity (see
    // yt_channel_same_person). With the story's text, the channel must pass.
    if ($context !== '' && !yt_channel_same_person($ch['item'], $ch['subs'], $name, $context)) return null;
    $url = $ch['thumb'];
    return ['url' => $url, 'name' => $ch['item']['snippet']['title'],
            'source' => 'YouTube channel @' . $ch['item']['snippet']['title'], 'channel_id' => $ch['item']['id']['channelId'],
            'channel_item' => $ch['item'], 'subs' => $ch['subs']];
}

/**
 * The channel search behind yt_creator_face(): the channel whose TITLE best
 * matches the name, gated at 10k subscribers. Returns ['item','subs','thumb']
 * or null. 2026-09-24 QUOTA: a channel search costs 100 of the 10,000 daily
 * YouTube API units and the same names come back story after story, so the
 * result is cached per name for 30 days (a miss for 7) in
 * app/cache/yt_name_search.json. Identity is NOT decided here: that is the
 * per-story check in yt_creator_face().
 */
function yt_channel_lookup(string $name): ?array {
    $key = $GLOBALS['CONFIG']['youtube_key'] ?? '';
    if (!$key || mb_strlen(trim($name)) < 2) return null;
    $nl = mb_strtolower(trim($name));
    $cfile = __DIR__ . '/cache/yt_name_search.json';
    $cc = json_decode((string)@file_get_contents($cfile), true) ?: [];
    $c = $cc[$nl] ?? null;
    if (is_array($c) && time() - (int)($c['at'] ?? 0) < (empty($c['cid']) ? 7 : 30) * 86400) {
        if (empty($c['cid'])) return null;
        return ['item' => ['id' => ['channelId' => $c['cid']],
                           'snippet' => ['title' => (string)$c['title'], 'description' => (string)$c['desc']]],
                'subs' => (int)$c['subs'], 'thumb' => (string)$c['thumb']];
    }
    $save = function (?array $r) use ($cfile, $nl): ?array {
        $cc = json_decode((string)@file_get_contents($cfile), true) ?: [];
        $cc[$nl] = $r ? ['cid' => $r['item']['id']['channelId'], 'title' => $r['item']['snippet']['title'],
                         'desc' => $r['item']['snippet']['description'], 'subs' => $r['subs'], 'thumb' => $r['thumb'], 'at' => time()]
                      : ['cid' => '', 'at' => time()];
        @file_put_contents($cfile, json_encode($cc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $r;
    };
    $raw = fs_http_get('https://www.googleapis.com/youtube/v3/search?part=snippet&type=channel&maxResults=5&q='
        . rawurlencode($name) . '&key=' . $key, 15);
    $j = $raw ? json_decode($raw, true) : null;
    if (!$j || !isset($j['items'])) return null;                 // API failure: not cached, retried next time
    if (empty($j['items'])) return $save(null);
    $best = null; $bestScore = -1;
    foreach ($j['items'] as $it) {
        $title = $it['snippet']['title'] ?? '';
        $tl = mb_strtolower($title);
        if ($tl === $nl) $score = 100;
        elseif (mb_strpos($tl, $nl) === 0) $score = 80;
        elseif (mb_strpos($tl, $nl) !== false) $score = 55;
        else { $nw = preg_split('/\s+/', $nl); $hit = 0; foreach ($nw as $w) if (mb_strlen($w) >= 3 && mb_strpos($tl, $w) !== false) $hit++; $score = $nw ? ($hit / count($nw)) * 45 : 0; }
        if (preg_match('/\b(unredacted|clips|fan|reacts|highlights|shorts|archive|topic|tribute|edits|fanpage)\b/i', $title)) $score -= 35;
        if ($score > $bestScore) { $bestScore = $score; $best = $it; }
    }
    if (!$best || $bestScore < 45) return $save(null);
    // REAL-CREATOR gate: a drama subject worth covering has a real channel. A random
    // same-named personal channel (the wrong "Alicia Brown") has ~no subscribers ->
    // skip it. (Identity is checked per story in yt_creator_face.)
    $cid = (string)($best['id']['channelId'] ?? '');
    if ($cid === '') return $save(null);
    $st = fs_http_get('https://www.googleapis.com/youtube/v3/channels?part=statistics&id=' . $cid . '&key=' . $key, 12);
    $sj = $st ? json_decode($st, true) : null;
    if (!$sj || !isset($sj['items'])) return null;               // API failure: not cached
    $subs = (int)($sj['items'][0]['statistics']['subscriberCount'] ?? 0);
    if ($subs < 10000) return $save(null);                       // not an established creator -> too risky
    $t = $best['snippet']['thumbnails'] ?? [];
    $url = $t['high']['url'] ?? $t['medium']['url'] ?? $t['default']['url'] ?? '';
    if (!$url) return $save(null);
    if (strpos($url, '//') === 0) $url = 'https:' . $url;
    $url = preg_replace('/=s\d+(-c)?/', '=s800', $url);         // larger avatar
    return $save(['item' => ['id' => ['channelId' => $cid],
                             'snippet' => ['title' => (string)($best['snippet']['title'] ?? ''),
                                           'description' => mb_substr((string)($best['snippet']['description'] ?? ''), 0, 400)]],
                  'subs' => $subs, 'thumb' => $url]);
}

/** A channel's latest upload titles (2 cheap Data API calls; [] on any failure). */
function yt_channel_recent_titles(string $cid, int $n = 6): array {
    $key = $GLOBALS['CONFIG']['youtube_key'] ?? '';
    if (!$key || $cid === '') return [];
    $raw = fs_http_get('https://www.googleapis.com/youtube/v3/channels?part=contentDetails&id=' . rawurlencode($cid) . '&key=' . $key, 12);
    $j = $raw ? json_decode($raw, true) : null;
    $up = (string)($j['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
    if ($up === '') return [];
    $raw = fs_http_get('https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=' . max(1, min(10, $n))
        . '&playlistId=' . rawurlencode($up) . '&key=' . $key, 12);
    $j = $raw ? json_decode($raw, true) : null;
    $out = [];
    foreach ((array)($j['items'] ?? []) as $it) {
        $t = trim((string)($it['snippet']['title'] ?? ''));
        if ($t !== '') $out[] = mb_substr($t, 0, 90);
    }
    return $out;
}

/**
 * 2026-09-24 NAMESAKE CHECK FOR CHANNELS. yt_creator_face() picks the channel
 * whose title matches the name and has 10k+ subscribers, and nothing asked
 * whether it is the person in the story: "Tatu", a FURIA esports player,
 * resolved to the channel of t.A.T.u., the Russian pop duo, and two videos
 * rendered their photo before the judge caught it. Same rule as the Wikidata
 * path (video_people.php r175): one AI yes/no per channel + story, cached 30
 * days; only "same" passes. No answer = not verified, because a missing face
 * is safer than a wrong one. $mayAsk=false (web requests) reads the cache only.
 */
function yt_channel_same_person(array $item, int $subs, string $name, string $context, bool $mayAsk = true): bool {
    $cid = (string)($item['id']['channelId'] ?? '');
    if ($cid === '' || trim($context) === '') return false;
    $ck = $cid . ':' . md5(mb_strtolower($name . '|' . mb_substr($context, 0, 900)));
    $file = __DIR__ . '/cache/yt_channel_verdicts.json';
    $cache = json_decode((string)@file_get_contents($file), true) ?: [];
    if (isset($cache[$ck]['same']) && time() - (int)($cache[$ck]['at'] ?? 0) < 30 * 86400) return (bool)$cache[$ck]['same'];
    if (!$mayAsk) return false;
    require_once __DIR__ . '/ai.php';
    $sn = (array)($item['snippet'] ?? []);
    // the latest video titles are the best evidence: tested 2026-09-24, the real
    // CBLOL player "Stepz" has an EMPTY channel description and was refused on
    // description alone, while his uploads are CBLOL match videos
    $titles = yt_channel_recent_titles($cid, 6);
    $res = ai_chat([['role' => 'user', 'content' =>
        "A news story says: \"" . mb_substr($context, 0, 900) . "\"\n\n"
        . "Candidate YouTube channel: \"" . (string)($sn['title'] ?? '') . "\" (" . number_format($subs) . " subscribers).\n"
        . "Channel description: \"" . mb_substr((string)($sn['description'] ?? ''), 0, 400) . "\"\n"
        . "Its latest video titles:\n" . ($titles ? '- ' . implode("\n- ", $titles) : '(none found)') . "\n\n"
        . "Is this the channel of the SAME real person as \"{$name}\" in the story (their own or official channel)? "
        . "Compare what the story says the person does (job, field, game or scene, country, team, who they work with) "
        . "with the channel. A name match alone proves nothing. Answer \"same\" when the channel fits the story's person, "
        . "\"different\" when it clearly belongs to someone else (another field, a band, a brand, a namesake), "
        . "\"unsure\" otherwise. STRICT JSON: {\"verdict\": \"same\"|\"different\"|\"unsure\", \"why\": \"<12 words\"}"]],
        ['gemini', 'openrouter', 'nvidia'], 0.0, 60);
    $j = isset($res['error']) ? null : ai_json($res['content'] ?? '');
    if (!is_array($j) || !in_array($j['verdict'] ?? null, ['same', 'different', 'unsure'], true)) return false;   // no verdict: not cached
    $same = ($j['verdict'] === 'same');
    $cache = json_decode((string)@file_get_contents($file), true) ?: [];
    $cache[$ck] = ['same' => $same, 'channel' => (string)($sn['title'] ?? ''), 'name' => $name,
                   'why' => mb_substr((string)($j['why'] ?? ''), 0, 120), 'at' => time()];
    @file_put_contents($file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if (!$same) error_log("yt_channel_same_person: '{$name}' -> channel '" . ($sn['title'] ?? '') . "' judged {$j['verdict']}: " . ($j['why'] ?? ''));
    return $same;
}

/**
 * IDENTITY-SAFE media for a creator: resolve their REAL YouTube channel (name-matched +
 * >=10k-sub gated by yt_creator_face) and pull recent landscape video thumbnails from THEIR
 * OWN uploads. Because these come from the person's verified channel, they actually depict
 * the right person — unlike a Wikipedia lookup, which returns a famous NAMESAKE for common
 * names (the "Ben Schneider the musician on an arrest story" defamation trap). Returns
 * ['channel_id','name','avatar','thumbs'=>[{url,fallback,title}]] or [].
 */
function drama_channel_media(string $name, int $maxVids = 8, string $context = ""): array {
    $key = $GLOBALS['CONFIG']['youtube_key'] ?? '';
    if (!$key) return [];
    $face = yt_creator_face($name, $context);           // resolves the real channel (gated; identity-checked when the story is given)
    if (!$face || empty($face['channel_id'])) return [];
    $cid = $face['channel_id'];
    $out = ['channel_id' => $cid, 'name' => $face['name'], 'avatar' => $face['url'], 'thumbs' => [],
            'channel_item' => $face['channel_item'] ?? null, 'subs' => (int)($face['subs'] ?? 0)];
    // uploads playlist for the channel
    $raw = fs_http_get('https://www.googleapis.com/youtube/v3/channels?part=contentDetails&id=' . $cid . '&key=' . $key, 12);
    $j = $raw ? json_decode($raw, true) : null;
    $uploads = $j['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
    if (!$uploads) return $out;
    $raw = fs_http_get('https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=' . (int)$maxVids . '&playlistId=' . $uploads . '&key=' . $key, 15);
    $j = $raw ? json_decode($raw, true) : null;
    foreach ($j['items'] ?? [] as $it) {
        $sn = $it['snippet'] ?? [];
        $vid = $sn['resourceId']['videoId'] ?? '';
        if (!$vid) continue;
        $th = $sn['thumbnails']['high'] ?? $sn['thumbnails']['medium'] ?? [];
        if (($th['width'] ?? 480) < ($th['height'] ?? 360)) continue;       // skip vertical shorts
        $out['thumbs'][] = ['url' => "https://i.ytimg.com/vi/$vid/maxresdefault.jpg",
            'fallback' => $th['url'] ?? "https://i.ytimg.com/vi/$vid/hqdefault.jpg", 'title' => $sn['title'] ?? '',
            'published' => $sn['publishedAt'] ?? ''];   // r11: lets callers date the upload ("their Jun video")
    }
    return $out;
}

/** Unified real face for a person: Wikimedia CC (licensed) first, else YouTube avatar. */
function drama_person_photo(string $name, string $context = ''): ?array {
    require_once __DIR__ . '/images.php';
    $w = wikidata_creator_photo($name);
    if ($w && !empty($w['url'])) return ['url' => $w['url'], 'name' => $name, 'source' => $w['source'] ?? 'Wikimedia Commons'];
    return yt_creator_face($name, $context);
}

/** Extract the real PEOPLE in a drama via the LLM (regex grabs title words too). */
function drama_people_ai(string $title, string $summary = ''): array {
    require_once __DIR__ . '/ai.php';
    $res = ai_chat([['role' => 'user', 'content' =>
        "From this creator-drama headline and summary, list ONLY the real PEOPLE involved (creators/individuals by full name), "
        . "most important first, max 3. Do NOT include events, companies, shows, or topics. "
        . "Headline: \"$title\"\nSummary: \"" . mb_substr($summary, 0, 300) . "\"\n"
        . "STRICT JSON: {\"people\":[\"Full Name\"]}"]], ['gemini', 'openrouter', 'nvidia'], 0.1);
    $j = isset($res['error']) ? null : ai_json($res['content'] ?? '');
    return array_slice(array_values(array_filter((array)($j['people'] ?? []), fn($p) => is_string($p) && mb_strlen($p) >= 3)), 0, 3);
}

/**
 * Real-face hero for a drama: resolve each person's face (Wikimedia/YouTube),
 * VISION-VERIFY it's actually that person in this context (kills the common-name
 * wrong-person risk), then VS card (2) or face hero (1). Returns null if nothing verifies.
 */
function drama_real_face_hero(string $slug, array $people, string $mood = '', string $context = ''): ?array {
    require_once __DIR__ . '/images.php';
    require_once __DIR__ . '/vs_card.php';
    require_once __DIR__ . '/vision.php';
    $people = array_values(array_filter(array_map('trim', $people), fn($p) => mb_strlen($p) >= 3));
    $faces = [];
    foreach (array_slice($people, 0, 3) as $p) {
        $f = drama_person_photo($p, $context);   // 2026-09-24: identity-checked against the story
        if (!$f || empty($f['url'])) continue;
        // Wikimedia is self-verified; a YouTube avatar of a COMMON name must be
        // vision-checked that it actually depicts this person/story.
        $needCheck = (mb_strpos($f['source'], 'YouTube') !== false);
        if ($needCheck) {
            $b64 = vision_b64($f['url'], 400);
            if ($b64) {
                $j = vision_nvidia("Avatar of a creator named \"$p\" involved in: \"" . mb_substr($context, 0, 140)
                    . "\". Is this a single real person's face/photo (a plausible creator headshot), not a logo/cartoon/group? STRICT JSON: {\"ok\":true|false}", $b64);
                if (isset($j['ok']) && !$j['ok']) continue;     // vision says not a usable face -> skip
            }
        }
        $faces[$p] = $f;
    }
    if (!$faces) return null;
    $names = array_keys($faces);
    if (count($faces) >= 2 && in_array($mood, ['conflict', 'scandal'], true)) {
        $fa = $faces[$names[0]]; $fb = $faces[$names[1]];
        $vs = make_vs_card(['url' => $fa['url'], 'name' => $names[0]], ['url' => $fb['url'], 'name' => $names[1]], $slug);
        if ($vs) return ['img' => $vs, 'credit' => 'Photos: ' . $fa['source'] . ' / ' . $fb['source'], 'credit_url' => '', 'people' => $names];
    }
    $first = reset($faces); $b = fs_http_get($first['url'], 15);
    if (!$b) return null;
    $relp = '/assets/covers/' . $slug . '-face.webp';
    if (!img_face_hero($b, dirname(__DIR__) . '/public_html' . $relp)) return null;
    return ['img' => $relp, 'credit' => 'Photo: ' . $first['source'], 'credit_url' => '', 'people' => [array_key_first($faces)]];
}

/** Cover-fit raw bytes to 1200x630 webp (thumbnails are ~16:9, minimal crop). */
function drama_thumb_to_hero(string $bytes, string $absPath): bool {
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw < 320) { imagedestroy($src); return false; }      // too small / placeholder
    $tw = 1200; $th = 630;
    $scale = max($tw / $sw, $th / $sh);
    $nw = (int)ceil($sw * $scale); $nh = (int)ceil($sh * $scale);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, (int)(($tw - $nw) / 2), (int)(($th - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);
    $ok = imagewebp($dst, $absPath, 84);
    imagedestroy($dst);
    return $ok && is_file($absPath) && filesize($absPath) > 0;
}

/**
 * Find the real hero image for a drama: search the specific story on YouTube, score
 * candidates by title relevance, vision-verify the top one matches the actual event,
 * and render its thumbnail. Returns ['img','credit','credit_url','video_id'] or null.
 */
function drama_real_hero(string $slug, string $title, string $summary = ''): ?array {
    // 1) a SPECIFIC query from the title (drop filler so we hit the real event, not a namesake)
    $q = preg_replace('/\b(the|a|an|and|of|over|for|s|its|controversy|drama|timeline|saga|explained)\b/i', ' ', $title);
    $q = trim(preg_replace('/[^\w\s$]/u', ' ', preg_replace('/\s+/', ' ', $q)));
    if (mb_strlen($q) < 6) $q = $title;

    $html = fs_http_get('https://www.youtube.com/results?search_query=' . rawurlencode($q) . '&hl=en&gl=US', 18);
    if (!$html) return null;
    // PAIR each videoId with ITS OWN title (the bug: ids were deduped, titles were not
    // -> the right title got the WRONG thumbnail = a missing-child image on a creator
    // drama). This bridges each id to its adjacent title in the same renderer block.
    preg_match_all('/"videoId":"([\w-]{11})".{0,600}?"title":\{"runs":\[\{"text":"([^"]{6,100})"/su', $html, $m);
    $pairs = []; $seen = [];
    for ($i = 0; $i < count($m[1]); $i++) {
        $id = $m[1][$i]; if (isset($seen[$id])) continue; $seen[$id] = 1;
        $pairs[] = ['id' => $id, 'title' => html_entity_decode($m[2][$i])];
    }
    if (!$pairs) return null;

    $kw = array_values(array_filter(preg_split('/\s+/', mb_strtolower($q)),
        fn($w) => mb_strlen($w) >= 3 && !in_array($w, ['with', 'this', 'that', 'they', 'from', 'have'])));
    $kw = array_slice(array_unique($kw), 0, 12);
    // SENSITIVITY guard: never put a missing-child/murder/death thumbnail on a drama
    // that is not itself about that (this is what made the Ailea-Brown image dangerous).
    $sensitive = '/\b(missing|murder|murdered|dead|dies|died|death|suicide|rape|raped|kidnap|child|minor|funeral|obituary|shot|killed|abducted)\b/i';
    $dramaSensitive = preg_match($sensitive, $title . ' ' . $summary);
    $cands = [];
    foreach (array_slice($pairs, 0, 8) as $c) {
        if (!$dramaSensitive && preg_match($sensitive, $c['title'])) continue;     // drop sensitive mismatch
        $vt = mb_strtolower($c['title']);
        $hit = 0; foreach ($kw as $w) if (mb_strpos($vt, $w) !== false) $hit++;
        $c['rel'] = $kw ? $hit / count($kw) : 0;
        if ($c['rel'] >= 0.4) $cands[] = $c;
    }
    if (!$cands) return null;
    usort($cands, fn($a, $b) => $b['rel'] <=> $a['rel']);

    // VISION-VERIFY: no image goes live unless vision CONFIRMS it depicts this story,
    // OR (vision unavailable) the title overlap is very strong AND passed sensitivity.
    require_once __DIR__ . '/vision.php';
    $pick = null;
    foreach (array_slice($cands, 0, 3) as $c) {
        $b64 = vision_b64('https://i.ytimg.com/vi/' . $c['id'] . '/hqdefault.jpg', 448);
        if ($b64) {
            $j = vision_nvidia("YouTube thumbnail. Story: \"" . mb_substr($title . '. ' . $summary, 0, 180)
                . "\". Does this thumbnail depict THAT specific story/people (not a different or unrelated case)? STRICT JSON: {\"match\":true|false}", $b64);
            if (isset($j['match'])) { if ($j['match']) { $pick = $c; break; } continue; }   // honor explicit verdict
        }
        if ($c['rel'] >= 0.55) { $pick = $c; break; }      // vision down: only a very strong title match
    }
    if (!$pick) return null;                                 // nothing safely verified -> branded card

    // 4) render the thumbnail as the hero (maxres -> hq fallback)
    $bytes = fs_http_get('https://i.ytimg.com/vi/' . $pick['id'] . '/maxresdefault.jpg', 15);
    if (!$bytes || strlen($bytes) < 6000) $bytes = fs_http_get('https://i.ytimg.com/vi/' . $pick['id'] . '/hqdefault.jpg', 15);
    if (!$bytes) return null;
    $rel = '/assets/covers/' . $slug . '-hero.webp';
    $abs = dirname(__DIR__) . '/public_html' . $rel;
    if (!drama_thumb_to_hero($bytes, $abs)) return null;
    require_once __DIR__ . '/images.php';
    if (function_exists('img_renditions')) img_renditions($abs);
    return ['img' => $rel, 'credit' => 'Via ' . mb_substr($pick['title'], 0, 60),
            'credit_url' => 'https://www.youtube.com/watch?v=' . $pick['id'], 'video_id' => $pick['id'], 'rel' => $pick['rel']];
}

/**
 * Unified drama hero: real verified faces -> verified event thumbnail -> null
 * (caller uses the branded card). Returns ['img','credit','kind','people'?] or null.
 */
function drama_hero_resolve(string $slug, string $title, string $summary, string $mood): ?array {
    $people = drama_people_ai($title, $summary);
    $f = drama_real_face_hero($slug, $people, $mood, $title . ' ' . $summary);
    if ($f) return $f + ['kind' => 'face'];
    $t = drama_real_hero($slug, $title, $summary);
    if ($t) return $t + ['kind' => 'thumbnail'];
    return null;
}

/**
 * CONTEXT-aware thumbnail candidates: search the full STORY (not a bare name) via the
 * YouTube Data API (ban-safe), so we get the EXACT people in THIS drama. Sensitivity-
 * guarded. Returns [{id,title,url}].
 */
function drama_thumb_candidates(string $title, string $summary, int $max = 5): array {
    $key = $GLOBALS['CONFIG']['youtube_key'] ?? '';
    if (!$key) return [];
    $q = trim($title . ' ' . mb_substr($summary, 0, 40));
    $raw = fs_http_get('https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&maxResults=8&relevanceLanguage=en&q='
        . rawurlencode($q) . '&key=' . $key, 15);
    $j = $raw ? json_decode($raw, true) : null;
    $sensitive = '/\b(missing|murder|murdered|dead|dies|died|death|suicide|rape|raped|kidnap|child|minor|funeral|obituary|killed|abducted)\b/i';
    $dramaSens = preg_match($sensitive, $title . ' ' . $summary);
    $out = [];
    foreach ($j['items'] ?? [] as $it) {
        $vt = $it['snippet']['title'] ?? '';
        if (!$dramaSens && preg_match($sensitive, $vt)) continue;
        $id = $it['id']['videoId'] ?? ''; if (!$id) continue;
        $th = $it['snippet']['thumbnails']['high'] ?? [];
        if (($th['width'] ?? 480) < ($th['height'] ?? 360)) continue;   // skip vertical Shorts -> clean landscape heroes
        $out[] = ['id' => $id, 'title' => $vt, 'url' => "https://i.ytimg.com/vi/$id/maxresdefault.jpg",
                  'fallback' => $th['url'] ?? "https://i.ytimg.com/vi/$id/hqdefault.jpg"];
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Vision picks the best candidate that shows the RIGHT people/event. Returns index or null. */
function drama_vision_pick(array $cands, string $context, string $mood = ''): ?int {
    require_once __DIR__ . '/vision.php';
    $moodHint = $mood ? " The story mood is \"$mood\" — match it when possible (calm/somber for sad or serious; lively for hype/funny)." : '';
    $content = [['type' => 'text', 'text' =>
        "STORY: \"" . mb_substr($context, 0, 220) . "\"." . $moodHint . "\nThese are candidate HERO images for an editorial story. "
        . "Pick the index of the ONE that best represents THIS story's actual people/event AS AN EDITORIAL PHOTO. "
        . "Strongly PREFER a CLEAN image of a SINGLE subject (the central person) with a neutral or mood-fitting expression. "
        . "Among the options, choose the CALMEST, least-cluttered one: avoid busy multi-person collages, exaggerated "
        . "shocked/open-mouth reaction faces, and heavy clickbait text WHEN a calmer option exists — but a plain real photo of "
        . "the right person is ALWAYS better than no photo, so do pick the best real one. "
        . "ONLY return -1 if EVERY option is genuinely unusable: gore/nsfw, a depicted private (non-public-figure) victim or a "
        . "minor, a mugshot / police-bodycam / arrest frame, or none shows the right people at all. "
        . "Output STRICT JSON: {\"best\":<0-based index or -1>,\"reason\":\"short\"}."]];
    $sent = 0;
    foreach ($cands as $c) {
        $b64 = vision_b64($c['url'], 448);
        if (!$b64 && !empty($c['fallback'])) $b64 = vision_b64($c['fallback'], 448);
        $content[] = $b64 ? ['type' => 'image_url', 'image_url' => ['url' => $b64]] : ['type' => 'text', 'text' => "(image $sent failed)"];
        $sent++;
    }
    $res = null;
    for ($try = 0; $try < 2; $try++) {
        $res = ai_chat([['role' => 'user', 'content' => $content]], ['gemini'], 0.1);
        if (!isset($res['error'])) break;
        if (stripos((string)($res['error'] ?? ''), '429') === false) break;  // not a rate limit -> stop
        usleep(2500000);  // one short backoff; if still throttled, caller falls back to title-pick
    }
    if (isset($res['error'])) return null;
    $j = ai_json($res['content'] ?? '');
    $b = $j['best'] ?? -1;
    return (is_numeric($b) && $b >= 0 && $b < count($cands)) ? (int)$b : null;
}

/** Non-AI fallback: best THUMB candidate by title overlap with the story (used only
 *  by the eye-checked manual redeploy when vision is rate-limited, never autonomously). */
function drama_best_by_title(array $cands, string $title): ?int {
    $words = array_filter(preg_split('/\W+/', strtolower($title)), fn($w) => strlen($w) >= 4);
    $bi = null; $bs = -1;
    foreach ($cands as $i => $c) {
        if (($c['kind'] ?? '') !== 'thumb') continue;
        $ct = strtolower($c['title'] ?? $c['credit'] ?? '');
        $s = 0; foreach ($words as $w) if (str_contains($ct, $w)) $s++;
        if ($s > $bs) { $bs = $s; $bi = $i; }
    }
    return $bi;   // null if no thumb candidate
}

/** Topic/context images for stories with NO verified person (events, shut-down sites, AI personas):
 *  licensed themed visuals (Openverse CC/PD + Pexels/Pixabay). The story subject is distilled from the
 *  title (people + drama-filler stripped). Vision downstream still gates relevance, so a loose match is
 *  rejected, not forced -- this only ADDS options for stories that would otherwise be a blank card. */
function drama_topic_candidates(string $title, string $summary, array $people = []): array {
    require_once __DIR__ . '/images.php';
    $q = preg_split('/[:\x{2014}\x{2013}\-]/u', $title)[0];               // subject before a colon/dash
    foreach ($people as $p) $q = str_ireplace($p, ' ', $q);
    $q = preg_replace('/\b(the|a|an|and|or|of|in|on|for|to|amid|over|as|vs|sparks?|debate|backlash|drama|controvers\w*|goes|viral|shut|down|rise|fall|fallout|denies|allegations?|lawsuit|timeline|inside|public|online|nationwide)\b/i', ' ', $q);
    $q = trim(preg_replace('/\s+/', ' ', $q));
    if (mb_strlen($q) < 3) return [];
    $out = [];
    foreach (openverse_find_all($q, 4) as $o)
        $out[] = ['url' => $o['url'], 'kind' => 'topic', 'title' => ($o['title'] ?: $q),
            'credit' => trim("Photo: {$o['creator']} ({$o['license']}), via {$o['source']}"), 'credit_url' => $o['foreign']];
    $sp = stock_photo($q);
    if ($sp) $out[] = ['url' => $sp['url'], 'kind' => 'topic', 'title' => $sp['title'],
        'credit' => "Photo: {$sp['creator']}, {$sp['source']}", 'credit_url' => $sp['foreign']];
    return $out;
}

/** Tasteful NON-PERSON concept image for a story where no safe face is available: an object/symbol
 *  that fits the THEME (gavel for a lawsuit, candle for a death, flag for politics) so a story is never
 *  imageless -- and shows NO person, so it's safe for dignity AND wrong-person cases alike. Theme is read
 *  from the TITLE only (summaries are noisy and misfire). Pexels/Pixabay first (clean stock), then Openverse. */
function drama_concept_candidates(string $title): array {
    require_once __DIR__ . '/images.php';
    $t = mb_strtolower($title);
    $map = [
        'death|died|dies|passed away|obituary|funeral|memorial|tribute|\bdead\b|suicide' => 'lit candle vigil white flowers',
        'vote|election|maga|trump|political|protest|rally|republican|democrat|conservative' => 'american flag stars and stripes',
        'lawsuit|sued|court|legal|allegation|denies|defamation|settlement|assault|abuse|harass' => 'wooden gavel courthouse',
        'arrest|police|custody|jail|prison|detained|bodycam' => 'gavel scales of justice',
        'artificial intelligence|\bai\b|deepfake|virtual influencer|avatar|ai-generated' => 'robot circuit board technology',
        'benefit|welfare|salary|income|debt|\bcash\b|money|budget|pounds|dollars' => 'dollar bills cash stack',
        'divorce|breakup|split|marriage|cheat|affair' => 'broken heart wedding rings',
        'piracy|leak|hack|copyright|torrent' => 'cyber security laptop code',
    ];
    $q = '';
    foreach ($map as $re => $qq) if (preg_match('/(' . $re . ')/i', $t)) { $q = $qq; break; }
    if ($q === '') return [];
    $out = [];
    $sp = stock_photo($q);
    if ($sp) $out[] = ['url' => $sp['url'], 'kind' => 'concept', 'title' => $q, 'credit' => "Photo: {$sp['creator']}, {$sp['source']}", 'credit_url' => $sp['foreign']];
    foreach (openverse_find_all($q, 2) as $o)
        $out[] = ['url' => $o['url'], 'kind' => 'concept', 'title' => $q, 'credit' => trim("Photo: {$o['creator']} ({$o['license']}), via {$o['source']}"), 'credit_url' => $o['foreign']];
    return $out;
}

/** Build a concept hero directly (no vision needed -- an object/symbol is inherently safe). Null if none. */
/**
 * 2026-09-24: photo agencies run bots that find their photos on other sites and
 * send bills (Getty/PicRights, AP). News sites re-host agency photos on their
 * own CDNs, so the file name and path are checked too ("GettyImages-123.jpg").
 */
function drama_is_agency_photo(string $url): bool {
    return (bool)preg_match('#getty|apimages|/ap[-_]?photo|\bap[-_]\d|reuters|\bafp\b|afp[-_]|shutterstock|alamy|zuma(press)?|wireimage'
        . '|filmmagic|imago[-_]|sipa|dpa[-_]|\bepa[-_]|eyevine|rexfeatures|rex[-_]features|pa[-_]images|abaca|splashnews|backgrid#i', $url);
}

/**
 * The report photos (og:image) of the articles a story cites, as cover
 * candidates: [{url, kind:'photo', credit, credit_url}], agency photos dropped,
 * one per article. Uses the event_sources og cache (the cron may fetch live).
 */
function drama_article_photos(int $pageId, int $max = 4): array {
    require_once __DIR__ . '/event_sources.php';
    $out = []; $seen = [];
    try {
        foreach (event_sources_for_page(db(), $pageId) as $es) {
            $img = trim((string)($es['og_image'] ?? ''));
            $src = trim((string)($es['source_url'] ?? ''));
            if ($img === '' || $src === '' || isset($seen[$img]) || drama_is_agency_photo($img)) continue;
            if (!preg_match('#^https?://#i', $img)) continue;
            $seen[$img] = 1;
            $host = preg_replace('/^www\./', '', (string)parse_url($src, PHP_URL_HOST));
            $out[] = ['url' => $img, 'kind' => 'photo', 'title' => (string)($es['title'] ?? ''),
                      'credit' => 'Photo: ' . $host . ' (from the cited article)', 'credit_url' => $src];
            if (count($out) >= $max) break;
        }
    } catch (Throwable $e) { /* never fatal: the other candidates stand */ }
    return $out;
}

function drama_concept_hero(string $slug, string $title): ?array {
    require_once __DIR__ . '/images.php';
    foreach (drama_concept_candidates($title) as $c) {
        $bytes = fs_http_get($c['url'], 15);
        if (!$bytes || strlen($bytes) < 2000) continue;
        $abs = dirname(__DIR__) . '/public_html/assets/covers/' . $slug . '-hero.webp';
        if (drama_thumb_to_hero($bytes, $abs)) {
            if (function_exists('img_renditions')) img_renditions($abs);
            return ['img' => "/assets/covers/$slug-hero.webp", 'credit' => $c['credit'], 'credit_url' => $c['credit_url'], 'kind' => 'concept', 'pool' => 0, 'via' => 'concept'];
        }
    }
    return null;
}

/**
 * THE drama image picker: pool candidates from ALL sources (Wikimedia faces + context
 * event thumbnails), vision-pick the best, build the hero. Returns ['img','credit','kind'] or null.
 */
function drama_image_smart(string $slug, string $title, string $summary, string $mood, bool $fallbackPick = false,
                           int $pageId = 0): ?array {
    require_once __DIR__ . '/images.php';
    require_once __DIR__ . '/vs_card.php';
    $people = drama_people_ai($title, $summary);
    // verified faces (Wikidata-confirmed = the correct person, never a guess)
    $faces = [];
    foreach (array_slice($people, 0, 3) as $p) {
        $f = wikidata_creator_photo($p);
        if ($f && !empty($f['url'])) $faces[] = ['name' => $p, 'url' => $f['url'],
            'credit' => "Photo: " . ($f['creator'] ?? 'Wikimedia') . " (" . ($f['license'] ?? 'cc') . "), via Wikimedia Commons", 'credit_url' => $f['foreign'] ?? ''];
    }
    // BROADER SOURCE so an image is always available: a creator's own YouTube CHANNEL AVATAR
    // is a clean headshot of the verified-right person (name-matched + >=10k-sub gated). This
    // is what lets famous creators with NO Wikimedia photo (KSI, etc.) still get a real face
    // instead of a branded card. Added only for people we don't already have a face for.
    $haveNames = array_column($faces, 'name');
    foreach (array_slice($people, 0, 3) as $p) {
        if (in_array($p, $haveNames, true)) continue;
        $av = yt_creator_face($p, $title . ". " . $summary);   // 2026-09-24: identity-checked against the story
        if ($av && !empty($av['url'])) $faces[] = ['name' => $p, 'url' => $av['url'],
            'credit' => 'Via ' . ($av['source'] ?? 'YouTube channel'),
            'credit_url' => !empty($av['channel_id']) ? 'https://www.youtube.com/channel/' . $av['channel_id'] : ''];
    }
    // FEUD: two verified faces + conflict/scandal -> VS composite. GATE: both people
    // must be title-anchored (a name token appears in the H1), so we never composite
    // famous-but-wrong people the extractor merely *mentioned* (e.g. MrBeast/Jake Paul
    // on an Airrack story). Belt-and-suspenders against the wrong-person trap.
    if (count($faces) >= 2 && in_array($mood, ['conflict', 'scandal'], true)) {
        $tl = mb_strtolower($title);
        $anchored = function ($name) use ($tl) {
            foreach (preg_split('/\s+/', mb_strtolower($name)) as $tok)
                if (mb_strlen($tok) >= 4 && str_contains($tl, $tok)) return true;
            return false;
        };
        if ($anchored($faces[0]['name']) && $anchored($faces[1]['name'])) {
            $vs = make_vs_card($faces[0], $faces[1], $slug);
            if ($vs) {
                if (function_exists('img_renditions')) img_renditions(dirname(__DIR__) . '/public_html' . $vs);
                return ['img' => $vs, 'credit' => $faces[0]['credit'] . ' | ' . $faces[1]['credit'],
                        'credit_url' => $faces[0]['credit_url'], 'kind' => 'vs', 'pool' => count($faces)];
            }
        }
    }
    $cands = [];
    foreach ($faces as $f) $cands[] = ['url' => $f['url'], 'kind' => 'face', 'credit' => $f['credit'], 'credit_url' => $f['credit_url']];
    // 2026-09-24 (owner: "let go of the card... get the best one that fits"): the
    // report photos of the articles this story cites - the actual event, the
    // actual people. Credited and linked to the article; agency photos (Getty,
    // AP, Reuters...) are never taken, they are the ones that send bills.
    if ($pageId > 0) foreach (drama_article_photos($pageId, 4) as $a) $cands[] = $a;
    // IDENTITY-SAFE real photos: each subject's OWN channel uploads (verified-right person,
    // unlike a Wikipedia namesake). Gives vision many real frames of the actual creator to
    // choose a clean editorial one from — the main lever for "every drama shows the people".
    foreach (array_slice($people, 0, 2) as $p) {
        $cm = drama_channel_media($p, 8, $title . ". " . $summary);   // 2026-09-24: identity-checked
        foreach (($cm['thumbs'] ?? []) as $t)
            $cands[] = ['url' => $t['url'], 'fallback' => $t['fallback'] ?? '', 'kind' => 'thumb', 'title' => $t['title'],
                'credit' => 'Via ' . ($cm['name'] ?? $p) . ' on YouTube', 'credit_url' => 'https://www.youtube.com/channel/' . $cm['channel_id']];
    }
    foreach (drama_thumb_candidates($title, $summary) as $t)
        $cands[] = ['url' => $t['url'], 'fallback' => $t['fallback'] ?? '', 'kind' => 'thumb', 'title' => $t['title'],
            'credit' => 'Via "' . mb_substr(html_entity_decode($t['title'], ENT_QUOTES | ENT_HTML5), 0, 60) . '"', 'credit_url' => 'https://www.youtube.com/watch?v=' . $t['id']];
    // NO named person at all (a pure concept / event / place story: a shut-down site, a city incident)
    // -> add licensed TOPIC images so it still gets a fitting visual. Gate is empty($people), NOT
    // empty($faces): a named-but-unverified person (e.g. an AI persona) must NEVER get a stock stranger
    // standing in for them -- that's a wrong-person error, worse than a card. Vision still gates the
    // concept matches below (a loose one is rejected, kept a card), so no unrelated stock photo slips in.
    if (empty($people))
        foreach (drama_topic_candidates($title, $summary, $people) as $tc) $cands[] = $tc;
    if (!$cands) return drama_concept_hero($slug, $title);   // no photo candidates -> tasteful concept, else card
    // dignity topics (death, suicide, sexual violence, pregnancy loss, minors) must
    // never get a blind title-pick: a sensational thumbnail of grief/tragedy is wrong.
    // Only vision (which can reject exploitative framing) may pick here, else branded.
    $dignity = drama_is_sensitive($title, $summary);
    $via = 'vision';
    $best = drama_vision_pick($cands, $title . '. ' . $summary, $mood);
    if ($best === null) {
        // Vision unavailable: do NOT blind-use an unverified face. For a common name (e.g.
        // "Ben Schneider") Wikidata returns a famous NAMESAKE — a folk musician — and a wrong
        // face on an arrest/abuse story is defamatory to an innocent person. A clean card is
        // far safer; the nightly backfill retries when vision is back. (Eye-checked manual
        // redeploy may still title-pick.)
        // vision couldn't verify a real face -> a tasteful NON-PERSON concept image (gavel/candle/flag)
        // beats a blank card and carries no wrong-person/dignity risk (shows no person). Card only if
        // the theme is unmapped (concept returns null).
        if (!$fallbackPick) return drama_concept_hero($slug, $title);
        $best = drama_best_by_title($cands, $title);
        if ($best === null) return drama_concept_hero($slug, $title);
        $via = 'title';
    }
    $c = $cands[$best];
    $bytes = fs_http_get($c['url'], 15);
    if ((!$bytes || strlen($bytes) < 2000) && !empty($c['fallback'])) $bytes = fs_http_get($c['fallback'], 15);  // maxres 404 -> API thumb
    if (!$bytes || strlen($bytes) < 2000) return null;
    $rel = '/assets/covers/' . $slug . '-hero.webp';
    $abs = dirname(__DIR__) . '/public_html' . $rel;
    $ok = $c['kind'] === 'face' ? img_face_hero($bytes, $abs) : drama_thumb_to_hero($bytes, $abs);
    if (!$ok) return null;
    if (function_exists('img_renditions')) img_renditions($abs);
    return ['img' => $rel, 'credit' => $c['credit'], 'credit_url' => $c['credit_url'], 'kind' => $c['kind'], 'pool' => count($cands), 'via' => $via];
}

/**
 * SELF-HEALING image backfill. The picker commits to a branded card whenever vision is
 * rate-limited at draft time — and never tries again. This re-runs the picker on published
 * dramas that are still a branded card (no photo credit), so any drama whose real image was
 * simply unavailable in that moment gets it later, automatically. Runs a few per day from
 * the cron tick = quota-safe. Sensitive stories are finalized as cards and never retried.
 * Returns ['tried','fixed','still','dignity_final'].
 */
function drama_image_backfill_run(PDO $pdo, int $limit = 3): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS drama_img_retry (
        page_id INT PRIMARY KEY,
        attempts INT NOT NULL DEFAULT 0,
        last_try INT NOT NULL DEFAULT 0,
        resolved TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // branded-card dramas (no photo credit) not yet resolved or exhausted, freshest-first
    $rows = $pdo->query("SELECT p.id,p.slug,p.h1,p.summary,d.mood
        FROM pages p JOIN dramas d ON d.page_id=p.id
        LEFT JOIN drama_img_retry r ON r.page_id=p.id
        WHERE p.type='drama' AND p.status='published'
          AND (p.cover_credit IS NULL OR p.cover_credit='')
          AND COALESCE(r.resolved,0)=0 AND COALESCE(r.attempts,0) < 6
        ORDER BY COALESCE(r.attempts,0) ASC, COALESCE(r.last_try,0) ASC, p.id DESC")->fetchAll();

    $out = ['tried' => 0, 'fixed' => 0, 'still' => 0, 'dignity_final' => 0];
    $now = time();
    $mark = $pdo->prepare("INSERT INTO drama_img_retry (page_id,attempts,last_try,resolved) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE attempts=VALUES(attempts), last_try=VALUES(last_try), resolved=VALUES(resolved)");
    foreach ($rows as $r) {
        // Policy (owner, 2026-06-21): do NOT card by TOPIC. Try to represent every story —
        // vision picks an image that shows what it's about and rejects bad IMAGES (gore/nsfw,
        // a depicted victim/minor, mockery, bodycam). Normal sources never serve graphic gore,
        // so topic-blocking just leaves good stories imageless. Let the smart picker do its job.
        if ($out['tried'] >= $limit) break;
        $out['tried']++;
        $res = null;
        try { $res = drama_image_smart($r['slug'], $r['h1'], $r['summary'] ?? '', $r['mood'] ?? 'neutral'); }
        catch (Throwable $e) { /* treat as a failed attempt */ }
        if ($res && !empty($res['img'])) {
            $pdo->prepare("UPDATE pages SET cover=?, featured_img=?, cover_credit=?, cover_credit_url=?, updated_at=NOW() WHERE id=?")
                ->execute([$res['img'], $res['img'], $res['credit'] ?? null, $res['credit_url'] ?? null, $r['id']]);
            $a = (int)$pdo->query("SELECT COALESCE(attempts,0)+1 FROM drama_img_retry WHERE page_id=" . (int)$r['id'])->fetchColumn();
            $mark->execute([$r['id'], $a ?: 1, $now, 1]);   // resolved
            $out['fixed']++;
        } else {
            $a = (int)$pdo->query("SELECT COALESCE(attempts,0) FROM drama_img_retry WHERE page_id=" . (int)$r['id'])->fetchColumn();
            $mark->execute([$r['id'], $a + 1, $now, 0]);     // count the attempt; gives up after 6
            $out['still']++;
        }
    }
    return $out;
}

/**
 * 2026-09-24 COVER POLICY v2 (owner: "let go of that card... the best one that
 * fits the topic"; plus "be sure it's the right person"). One pass over every
 * published drama whose cover is our branded card OR a YouTube image (171 of
 * those were never identity-checked). Each gets a fresh pick from the full,
 * now identity-checked pool incl. the cited articles' report photos.
 *   - a pick          -> it becomes the cover (credited, linked)
 *   - no pick, the old cover was a YouTube image -> back to the card at once
 *     (an unverified face must not stay while we wait), then retried as a card
 *   - no pick on a card -> retried up to 3 times (vision may have been busy)
 * YouTube covers first (identity risk), then cards, newest first. Limit per
 * call is small: a pick costs vision + YouTube quota (10,000 units a day).
 */
function drama_image_backfill_v2(PDO $pdo, int $limit = 2): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS drama_img_v2 (
        page_id INT PRIMARY KEY,
        outcome VARCHAR(40) NOT NULL,
        tries INT NOT NULL DEFAULT 0,
        at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $rows = $pdo->query("SELECT p.id, p.slug, p.h1, p.summary, p.cover_credit, p.cover_credit_url, d.mood,
               (COALESCE(p.cover_credit,'') LIKE '%YouTube%' OR COALESCE(p.cover_credit_url,'') LIKE '%youtube.com%') AS yt
        FROM pages p JOIN dramas d ON d.page_id = p.id
        LEFT JOIN drama_img_v2 v ON v.page_id = p.id
        WHERE p.type = 'drama' AND p.status = 'published'
          AND (COALESCE(p.cover_credit,'') = '' OR COALESCE(p.cover_credit,'') LIKE '%YouTube%'
               OR COALESCE(p.cover_credit_url,'') LIKE '%youtube.com%')
          AND (v.page_id IS NULL OR (v.outcome = 'retry' AND v.tries < 3 AND v.at < NOW() - INTERVAL 6 HOUR))
        ORDER BY yt DESC, (p.published_at > NOW() - INTERVAL 3 DAY) DESC, p.published_at DESC
        LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    $out = ['tried' => 0, 'photo' => 0, 'back_to_card' => 0, 'retry' => 0, 'pages' => []];
    $mark = $pdo->prepare("INSERT INTO drama_img_v2 (page_id, outcome, tries, at) VALUES (?, ?, 1, NOW())
                           ON DUPLICATE KEY UPDATE outcome = VALUES(outcome), tries = tries + 1, at = NOW()");
    $set = $pdo->prepare("UPDATE pages SET cover = ?, featured_img = ?, cover_credit = ?, cover_credit_url = ?, updated_at = NOW() WHERE id = ?");
    foreach ($rows as $r) {
        $out['tried']++;
        $res = null;
        try { $res = drama_image_smart($r['slug'], $r['h1'], (string)($r['summary'] ?? ''), (string)($r['mood'] ?? 'neutral'), false, (int)$r['id']); }
        catch (Throwable $e) { error_log('drama_image_backfill_v2 ' . $r['id'] . ': ' . $e->getMessage()); }
        if ($res && !empty($res['img'])) {
            $set->execute([$res['img'], $res['img'], $res['credit'] ?? null, $res['credit_url'] ?? null, $r['id']]);
            $mark->execute([$r['id'], 'photo:' . ($res['kind'] ?? '?')]);
            $out['photo']++; $out['pages'][] = $r['id'] . ' photo(' . ($res['kind'] ?? '?') . ')';
            continue;
        }
        if ((int)$r['yt'] === 1) {
            $card = '/assets/covers/' . $r['slug'] . '.png';
            if (is_file(dirname(__DIR__) . '/public_html' . $card)) {
                $set->execute([$card, $card, null, null, $r['id']]);
                $out['back_to_card']++; $out['pages'][] = $r['id'] . ' back-to-card';
            }
        }
        $mark->execute([$r['id'], 'retry']);
        $out['retry']++;
    }
    return $out;
}
