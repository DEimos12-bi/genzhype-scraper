<?php
// GenZHype | VIDEO person-face resolver (round 8 "bridge the site's arsenal").
//
// The maker used to resolve faces by itself with ONE source (Wikidata P18) —
// so small creators (no Wikidata item) and most death-topic subjects rendered
// with NO person image. The SITE's image engine is far deeper: verified
// Wikidata creator photos, YouTube Data API channel avatars (youtube_key,
// name-scored + >=10k-sub gated — works for small creators), stored entity
// graph sameAs QIDs. This module bridges that arsenal into the video feed.
//
// CHAIN per person, deepest first:
//   (a) the site's ALREADY-RESOLVED entity: dramas.people_json sameAs carries
//       a verified Wikidata QID (entity.php resolved + human-verified it) ->
//       P18 by QID directly. No search, no namesake risk.
//   (b) drama_person_photo() — the site's unified face resolver:
//       wikidata_creator_photo (creator-gated Wikidata search) first, then
//       yt_creator_face (YouTube Data API channel search -> channel avatar).
//   (c) guarded Wikidata P18 by name (port of the maker's flow: namesake-word
//       guard on the entity description, context-aware) — covers non-creator
//       public figures the creator gate refuses.
//   (d) none -> photo stays null (the card/receipt pool still carries the video).
//
// PEOPLE-LIST GAP FIX: entity.php stores ONLY people that resolve to Wikidata
// sameAs, so dramas about small creators sit with people_json='[]' and the
// video path never even learns the names. When the list is empty, this module
// (CLI/cron context only — never inside a web request) extracts the names via
// drama_people_ai() and caches them, so the static feed builder heals the gap.
//
// CACHE: name-keyed JSON sidecar (app/video_people_cache.json), 30d TTL for
// hits, 7d for misses (a new creator's channel may appear), 7d for extracted
// name lists. All lookups are strictly non-fatal.

require_once __DIR__ . '/drama_image.php';   // drama_person_photo, drama_people_ai
require_once __DIR__ . '/images.php';        // wm_api_host
require_once __DIR__ . '/fetch_sources.php'; // fs_http_get

const VP_TTL_HIT   = 2592000;   // 30d: a resolved face URL
const VP_TTL_MISS  = 604800;    // 7d: chain exhausted, retry later
const VP_TTL_NAMES = 604800;    // 7d: AI-extracted people list for a page
const VP_TTL_MEDIA = 604800;    // 7d: a person's recent-channel-media list (r11)
// r29: distinct images to gather per person. A 20+ shot video needs a deep pool
// or the same face repeats; 4 (the old hard-coded cap) was the starvation point.
const VP_PHOTOS_MAX = 8;

function vp_cache_file(): string { return __DIR__ . '/video_people_cache.json'; }

/**
 * r29: upgrade a YouTube thumbnail URL to the largest variant that really exists.
 * hqdefault is only 480x360, so any push-in on it renders mush in a 1080-wide
 * video. maxresdefault (1280x720) is absent for many videos and hq720 for some,
 * hence the probe: first variant that answers 200 wins, hqdefault is the floor.
 * One HEAD per variant, result cached with the media list (7d), so the cost is
 * paid once per person per week. Any failure returns the input untouched.
 */
function vp_best_thumb(string $url): string
{
    if (!preg_match('#^(https?://i\.ytimg\.com/vi/[^/]+/)([a-z0-9]+)\.jpg#i', $url, $m)) return $url;
    [$full, $base] = [$m[0], $m[1]];
    foreach (['maxresdefault', 'hq720', 'sddefault'] as $variant) {
        $try = $base . $variant . '.jpg';
        $ch = curl_init($try);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GenZHypeBot/1.0)',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) return $try;
    }
    return $url;
}

function vp_cache_load(): array {
    $f = vp_cache_file();
    if (!is_file($f)) return [];
    return (array)json_decode((string)@file_get_contents($f), true);
}

function vp_cache_save(array $c): void {
    // prune expired entries so the sidecar never grows unbounded
    $now = time();
    foreach ($c as $k => $v) {
        $ttl = str_starts_with($k, 'names:') ? VP_TTL_NAMES
             : (str_starts_with($k, 'media:') || str_starts_with($k, 'media2:') ? VP_TTL_MEDIA
             : (empty($v['photo']) ? VP_TTL_MISS : VP_TTL_HIT));
        if (($now - (int)($v['at'] ?? 0)) > $ttl * 2) unset($c[$k]);
    }
    @file_put_contents(vp_cache_file(), json_encode($c, JSON_UNESCAPED_SLASHES));
    @chmod(vp_cache_file(), 0644);
}

/** Commons Special:FilePath URL (width-capped — P18 originals can be 50MP). */
function vp_commons_url(string $fileTitle): string {
    $fn = preg_replace('/^File:/i', '', trim($fileTitle));
    $fn = str_replace(' ', '_', $fn);
    return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($fn) . '?width=1400';
}

/** (a) P18 straight from a site-verified Wikidata QID (entity graph sameAs). */
function vp_photo_by_qid(string $qid): ?string {
    $c = wm_api_host('www.wikidata.org', ['action' => 'wbgetclaims', 'entity' => $qid, 'property' => 'P18']);
    $file = $c['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
    return (is_string($file) && $file !== '') ? vp_commons_url($file) : null;
}

/**
 * (c) Guarded Wikidata P18 by NAME — STRICTER than the maker's original flow.
 * The maker's namesake-word list let "John Davis" resolve to a historical
 * SAILOR on a death story (caught in this round's live test — the exact
 * wrong-person-on-a-death-story defamation trap). New rule: a top hit whose
 * description is NOT creatorish is accepted ONLY when the description's own
 * words overlap the story context ("American rapper" on a rap story), and a
 * historical/military-flavored description is always refused. Misses a few
 * legitimate celebrities (safe: photo stays null), never the wrong person.
 */
/** r175: a creator-shaped description (the only case accepted without a check). */
function vp_desc_is_creator(string $desc): bool {
    static $creatorish = ['youtuber','streamer','internet','influencer','content creator','twitch',
        'social media','online','personality','gamer','tiktok','podcaster','celebrit','rapper','media'];
    $desc = mb_strtolower($desc);
    foreach ($creatorish as $w) if (str_contains($desc, $w)) return true;
    return false;
}

/**
 * r175: the text an entity linker judges a mention by — the story title plus
 * the sentences that actually NAME the person (a first-200-chars excerpt never
 * mentioned Ben Crump, so a correct link could not be confirmed).
 */
function vp_mention_context(string $name, string $context): string {
    $parts = preg_split('/(?<=[.!?])\s+/u', $context) ?: [];
    $head = (string)($parts[0] ?? '');
    $tokens = array_filter(preg_split('/\s+/u', mb_strtolower($name)) ?: [], fn($t) => mb_strlen($t) >= 3);
    $hits = [];
    foreach (array_slice($parts, 1) as $sent) {
        $ls = mb_strtolower($sent);
        foreach ($tokens as $t) if (str_contains($ls, $t)) { $hits[] = $sent; break; }
        if (mb_strlen(implode(' ', $hits)) > 600) break;
    }
    return trim($head . ' ' . implode(' ', $hits));
}

/** r175: an entity's English description (one cheap wbgetentities call). */
function vp_qid_description(string $qid): string {
    static $memo = [];
    if (isset($memo[$qid])) return $memo[$qid];
    $r = wm_api_host('www.wikidata.org', ['action' => 'wbgetentities', 'ids' => $qid,
                                          'props' => 'descriptions', 'languages' => 'en']);
    return $memo[$qid] = (string)($r['entities'][$qid]['descriptions']['en']['value'] ?? '');
}

/**
 * r175 SECOND OPINION for a site entity the description guard cannot vouch for.
 * Measured on all 166 entity links: of 52 descriptions that do not echo the
 * story, about half are real namesakes (Q739412 dancehall DJ for streamer
 * Agent 00; a porn film director for Ateez's San; a CFL player who died in
 * 2012 for a 2026 disappearance) and half are the right person whose job the
 * story never names (Katy Perry "American singer" on a cultural-appropriation
 * story). Entity linkers settle exactly this with a context-aware verifier;
 * one AI yes/no per entity + story, cached. true = same person; false or no
 * answer = not verified (the photo path then refuses the entity — a missing
 * face is recoverable, the wrong face on a crime story is not).
 */
/**
 * r182: the candidate's evidence an entity linker compares against the story —
 * the Wikidata description plus the English Wikipedia lead (job, era, dates).
 * A one-line description alone ("American jurist" for Ben Crump, "record
 * producer" for J. White Did It) made the verifier strip correct links.
 */
function vp_qid_evidence(string $qid, string $desc): string {
    $out = 'Wikidata description: "' . $desc . '"';
    try {
        $r = wm_api_host('www.wikidata.org', ['action' => 'wbgetentities', 'ids' => $qid, 'languages' => 'en|mul',
                                              'props' => 'sitelinks|labels', 'sitefilter' => 'enwiki']);
        $t = (string)($r['entities'][$qid]['sitelinks']['enwiki']['title'] ?? '');
        $label = (string)($r['entities'][$qid]['labels']['en']['value'] ?? $r['entities'][$qid]['labels']['mul']['value'] ?? '');
        if ($label !== '') $out .= "\nWikidata name: \"{$label}\"";
        // a thin entity (no English article, e.g. Iyanna "Yaya" Mayweather):
        // birth/death dates are the era evidence the lead would have given
        if ($t === '') {
            foreach (['P569' => 'born', 'P570' => 'died'] as $prop => $word) {
                $c = wm_api_host('www.wikidata.org', ['action' => 'wbgetclaims', 'entity' => $qid, 'property' => $prop]);
                $tm = (string)($c['claims'][$prop][0]['mainsnak']['datavalue']['value']['time'] ?? '');
                if (preg_match('/^\+?(\d{4}-\d\d-\d\d)/', $tm, $mm)) $out .= "\n{$word}: {$mm[1]}";
            }
        }
        if ($t !== '') {
            $w = wm_api_host('en.wikipedia.org', ['action' => 'query', 'prop' => 'extracts', 'exintro' => 1,
                                                  'explaintext' => 1, 'exsentences' => 3, 'titles' => $t, 'redirects' => 1]);
            foreach ((array)($w['query']['pages'] ?? []) as $pg) {
                $x = trim((string)($pg['extract'] ?? ''));
                if ($x !== '') $out .= "\nWikipedia article \"{$t}\" begins: \"" . mb_substr($x, 0, 600) . '"';
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

function vp_entity_same_person(string $qid, string $desc, string $name, string $context): bool {
    $context = vp_mention_context($name, $context);
    $ck = 'ent2:' . $qid . ':' . md5(mb_strtolower($name . '|' . mb_substr($context, 0, 900)));
    $cache = vp_cache_load();
    if (isset($cache[$ck]['same']) && (time() - (int)($cache[$ck]['at'] ?? 0)) < VP_TTL_HIT) {
        return (bool)$cache[$ck]['same'];
    }
    require_once __DIR__ . '/ai.php';
    // r182: tested 10/10 on known namesakes (porn director for San, dancehall
    // DJ for Agent 00, CFL player d.2012, actor d.1980, wrestler d.1936) and
    // right people (Ben Crump, J. White Did It, Katy Perry, Tom Brady, Al Sharpton)
    $res = ai_chat([['role' => 'user', 'content' =>
        "A news story says: \"" . mb_substr($context, 0, 900) . "\"\n\n"
        . "Candidate entity {$qid}:\n" . vp_qid_evidence($qid, $desc) . "\n\n"
        . "Is this candidate the SAME real person as \"{$name}\" in the story? Compare what the story says the person does "
        . "(job, field, era, country, who they work with) with the candidate's evidence. A name match alone proves nothing. "
        . "Answer \"same\" when the evidence fits the story, \"different\" when it clearly describes someone else "
        . "(other job, other era, dead before the events), \"unsure\" otherwise. "
        . "STRICT JSON: {\"verdict\": \"same\"|\"different\"|\"unsure\", \"why\": \"<12 words\"}"]],
        ['gemini', 'openrouter', 'nvidia'], 0.0, 60);
    $j = isset($res['error']) ? null : ai_json($res['content'] ?? '');
    if (!is_array($j) || !in_array($j['verdict'] ?? null, ['same', 'different', 'unsure'], true)) return false;   // no verdict: not cached
    $same = ($j['verdict'] === 'same');
    $cache = vp_cache_load();
    $cache[$ck] = ['same' => $same, 'qid' => $qid, 'at' => time(), 'photo' => 'n/a'];
    vp_cache_save($cache);
    return $same;
}

function vp_wikidata_p18_guarded(string $name, string $context = ''): ?string {
    $s = wm_api_host('www.wikidata.org', ['action' => 'wbsearchentities', 'search' => $name,
                                          'language' => 'en', 'type' => 'item', 'limit' => 5]);
    $top = $s['search'][0] ?? null;
    if (!$top || empty($top['id'])) return null;
    // r182b: a prefix hit ("Donald Trump" -> Donald Trump Jr.) is not the name
    $key = fn(string $v) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($v));
    if ($key((string)($top['match']['text'] ?? $top['label'] ?? '')) !== $key($name)) return null;
    $d = (string)($top['description'] ?? '');
    if ($d === '') return null;                       // description-less item: unverifiable
    // historical / clearly-other-era figures are never our story's subject
    if (preg_match('/\b1[0-8]\d\d\b|\b19[0-6]\d\b|sailor|soldier|navy|military|explorer|navigator|bishop|saint|monarch|missionary|colonel|general\b/', mb_strtolower($d))) {
        return null;
    }
    // r175: same rule as the site-entity path — creator-shaped, or verified
    if (!vp_desc_is_creator($d) && !vp_entity_same_person((string)$top['id'], $d, $name, $context)) return null;
    return vp_photo_by_qid((string)$top['id']);
}

/**
 * Resolve ONE person to a real face URL through the full chain. $sameAs is the
 * entity-graph array from people_json (may carry the verified Wikidata URL).
 * Returns ['photo' => url|null, 'source' => which chain link hit].
 */
function video_person_photo(string $name, array $sameAs = [], string $context = '',
                            bool $liveLookups = true): array {
    $name = trim($name);
    if (mb_strlen($name) < 2) return ['photo' => null, 'source' => 'none'];
    $cache = vp_cache_load();
    $key = 'photo:' . mb_strtolower($name);
    $hit = $cache[$key] ?? null;
    // r175: a cached photo that came from the site-entity path is re-checked
    // once against the namesake guard (the wrong Agent 00 sat cached for 30d)
    $siteQid = null;
    foreach ($sameAs as $u) {
        if (is_string($u) && preg_match('#wikidata\.org/(?:wiki|entity)/(Q\d+)#', $u, $m)) { $siteQid = $m[1]; break; }
    }
    if ($hit && !empty($hit['photo']) && str_starts_with((string)($hit['source'] ?? ''), 'wikidata-entity(site)')
            && empty($hit['fit_checked']) && $siteQid && $liveLookups) {
        $d = '';
        try { $d = vp_qid_description($siteQid); } catch (Throwable $e) {}
        if ($d !== '' && !vp_desc_is_creator($d)
                && !vp_entity_same_person($siteQid, $d, $name, $context)) {
            error_log("video_people: cached photo for '$name' came from $siteQid ('$d'), not verified as this story's person; re-resolving");
            $hit = null;
        } elseif ($d !== '') {
            $cache[$key]['fit_checked'] = 1;
            vp_cache_save($cache);
        }
    }
    // 2026-09-24: a YouTube avatar is cached by NAME, but a name can be several
    // people: "Tatu" (a FURIA player) was saved as t.A.T.u., the pop duo. So a
    // cached avatar is checked against THIS story every time (the verdict is
    // cached per channel + story, so it costs one AI call per story). An old
    // entry saved without its channel details is looked up again.
    if ($hit && !empty($hit['photo']) && ($hit['source'] ?? '') === 'youtube-avatar') {
        require_once __DIR__ . '/drama_image.php';
        $ch = $hit['channel'] ?? null;
        if (!is_array($ch) || empty($ch['item'])) {
            if (!$liveLookups) return ['photo' => null, 'source' => 'youtube-avatar (unchecked; web: cache-only)'];
            $hit = null;
        } elseif (!yt_channel_same_person((array)$ch['item'], (int)($ch['subs'] ?? 0), $name,
                                          vp_mention_context($name, $context), $liveLookups)) {
            return ['photo' => null, 'source' => 'youtube-avatar (not verified as this story\'s person)'];
        }
    }
    if ($hit && (time() - (int)($hit['at'] ?? 0)) < (empty($hit['photo']) ? VP_TTL_MISS : VP_TTL_HIT)) {
        return ['photo' => $hit['photo'] ?: null, 'source' => ($hit['source'] ?? 'cache') . ' (cached)'];
    }
    // WEB-SAFETY: live multi-API lookups belong to the cron (static feed
    // builder). A web request (video_next.php fallback) serves cache only —
    // this endpoint exists precisely because AI/network work in-request 504s.
    if (!$liveLookups) return ['photo' => null, 'source' => 'uncached (web: cache-only)'];
    $photo = null; $source = 'none';
    // (a) site-verified entity QID -> P18 (deepest: zero namesake risk)
    foreach ($sameAs as $u) {
        if (is_string($u) && preg_match('#wikidata\.org/(?:wiki|entity)/(Q\d+)#', $u, $m)) {
            // r175: "site-verified" is not proof — the same namesake guard as (c)
            $d = '';
            try { $d = vp_qid_description($m[1]); } catch (Throwable $e) {}
            if ($d !== '' && !vp_desc_is_creator($d)
                    && !vp_entity_same_person($m[1], $d, $name, $context)) {
                error_log("video_people: site entity {$m[1]} ('$d') not verified as the story's '$name'; namesake skipped");
                break;   // fall through to (b)/(c)
            }
            $photo = vp_photo_by_qid($m[1]);
            if ($photo) { $source = 'wikidata-entity(site)'; }
            break;   // one QID per person; a P18-less entity falls through to (b)
        }
    }
    // (b) the site's unified face resolver: verified Wikidata creator photo,
    //     else YouTube Data API channel avatar (the small-creator fix)
    $channel = null;
    if (!$photo) {
        $f = null;
        // 2026-09-24: the story goes along, so a YouTube avatar must pass the
        // same-person check (yt_channel_same_person) before it is used
        try { $f = drama_person_photo($name, vp_mention_context($name, $context)); } catch (Throwable $e) {}
        if ($f && !empty($f['url'])) {
            if (mb_stripos((string)($f['source'] ?? ''), 'youtube') !== false) {
                $photo = $f['url']; $source = 'youtube-avatar';
                $channel = ['item' => $f['channel_item'] ?? null, 'subs' => (int)($f['subs'] ?? 0)];
            } elseif (!empty($f['title'])) {          // wm_file_info row -> width-capped URL
                $photo = vp_commons_url($f['title']); $source = 'wikidata-creator';
            } else {
                $photo = $f['url']; $source = 'wikidata-creator';
            }
        }
    }
    // (c) guarded plain P18 (public figures the creator gate refuses)
    if (!$photo) {
        try { $photo = vp_wikidata_p18_guarded($name, $context); } catch (Throwable $e) {}
        if ($photo) $source = 'wikidata-p18';
    }
    $cache = vp_cache_load();                          // reload: lookups above take seconds
    $cache[$key] = ['photo' => $photo, 'source' => $source, 'at' => time()];
    if ($source === 'youtube-avatar' && $channel) $cache[$key]['channel'] = $channel;   // for the per-story check
    vp_cache_save($cache);
    return ['photo' => $photo, 'source' => $source];
}

/**
 * r11 POOL DEPTH: a person's RECENT REAL imagery — 3-4 recent video thumbnails
 * from their OWN verified YouTube channel (drama_channel_media: name-matched +
 * >=10k-sub gated, so they actually depict the right person). These are real
 * recent images of the creator, titled so the Director can pick deliberately
 * ("recent photo of Emiru (their Jun video)"). Cached 7d per person in the
 * same JSON sidecar. CLI-only lookups ($liveLookups=false serves cache only).
 * Returns [['url'=>..., 'title'=>...], ...] (may be empty). Never fatal.
 */
/**
 * r180: does a creator's recent VIDEO belong in THIS story? Its thumbnail is
 * that video's cover art, not a portrait: page 920 put ElRubius's "Fui al
 * Safari Mas Salvaje de Africa" thumbnail (a cheetah) into a Rockstar North
 * story and the judge failed "random cheetah filler"; all 12 of Agent 00's
 * recent videos (pizza, the FBI, cave diving) rode a Nitro Camden story. Same
 * rule as the maker's clip fit: the video title must share a real word with the
 * story headline, the person's own name excluded. ("Esto es GTA VI" passes a
 * "Rockstar North visit and GTA 6" story on "gta".)
 */
function vp_video_fits_story(string $videoTitle, string $storyTitle, string $personName): bool {
    $story = array_diff(vp_title_tokens($storyTitle), vp_title_tokens($personName));
    return (bool)array_intersect($story, vp_title_tokens($videoTitle));
}

/** r180 tokens (r184: shared with the Director's clip-to-sentence check). */
function vp_title_tokens(string $t): array {
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(['the','and','for','with','from','that','this','his','her','their','they','them',
            'was','were','has','have','had','are','not','but','all','out','new','how','why','what','who','when',
            'after','before','over','into','about','just','more','most','video','videos','vlog','epic','live',
            'stream','streams','streamer','streamers','streaming','twitch','kick','youtube','tiktok','instagram',
            'twitter','drama','viral','news','update','full','part','official','reaction','explained','timeline',
            'shorts','short','clip','clips','today','day','days','year','years','time','first','last','best',
            'world','people','story','incident','controversy','response','details','amid','behind','major',
            'esto','los','las','del','con','una','por','para','que','mas','mis','the','was','you','your','our']);
    }
    $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)) ?: [];
    return array_values(array_unique(array_filter($w, fn($x) => mb_strlen($x) >= 3 && !isset($stop[$x]))));
}

function video_person_recent_media(string $name, int $max = 4, bool $liveLookups = true,
                                   string $storyTitle = ''): array {
    $name = trim($name);
    if (mb_strlen($name) < 2) return [];
    $max = max(1, min(VP_PHOTOS_MAX, $max));   // r29: was hard-capped at 4
    $cache = vp_cache_load();
    $key = 'media2:' . mb_strtolower($name);    // r180: rows now keep the real video title
    $hit = $cache[$key] ?? null;
    $pick = function (array $rows) use ($storyTitle, $name, $max): array {
        if ($storyTitle === '') return array_slice($rows, 0, $max);
        $keep = array_values(array_filter($rows, fn($r) =>
            vp_video_fits_story((string)($r['vtitle'] ?? ''), $storyTitle, $name)));
        return array_slice($keep, 0, $max);
    };
    // 2026-09-24: the thumbnails come from the channel matched by NAME, so they
    // are checked against THIS story like the avatar (yt_channel_same_person).
    // An old entry saved without its channel details is fetched again.
    if ($hit && !empty($hit['media']) && $storyTitle !== '') {
        $ch = $hit['channel'] ?? null;
        if (!is_array($ch) || empty($ch['item'])) {
            if (!$liveLookups) return [];
            $hit = null;
        } elseif (!yt_channel_same_person((array)$ch['item'], (int)($ch['subs'] ?? 0), $name, $storyTitle, $liveLookups)) {
            return [];
        }
    }
    if ($hit && (time() - (int)($hit['at'] ?? 0)) < VP_TTL_MEDIA) {
        return $pick((array)($hit['media'] ?? []));
    }
    if (!$liveLookups) return [];                      // web request: cache only
    $media = [];
    $channel = null;
    try {
        $cm = drama_channel_media($name, VP_PHOTOS_MAX * 2, $storyTitle);
        if (!empty($cm['channel_item'])) $channel = ['item' => $cm['channel_item'], 'subs' => (int)($cm['subs'] ?? 0)];
        foreach (($cm['thumbs'] ?? []) as $t) {
            // r29 RESOLUTION: hqdefault is 480x360 — once the shot zooms in it
            // turns to mush (the owner saw blurry face crops). Prefer the biggest
            // variant that ACTUALLY exists (maxres 1280x720 -> hq720 -> sd 640x480),
            // verified once and cached 7d, falling back to hqdefault as before.
            $u = (string)($t['fallback'] ?? '');
            if ($u === '' && !empty($t['url'])) $u = str_replace('maxresdefault', 'hqdefault', (string)$t['url']);
            if ($u === '' || !preg_match('#^https?://#i', $u)) continue;
            $u = vp_best_thumb($u);
            $mon = '';
            if (!empty($t['published']) && ($ts = strtotime((string)$t['published']))) $mon = date('M', $ts);
            $vt = trim((string)($t['title'] ?? ''));
            $media[] = ['url' => $u, 'vtitle' => $vt, 'title' => 'recent photo of ' . $name
                . ($mon !== '' ? " (their {$mon} video" : ' (their recent video')
                . ($vt !== '' ? ': ' . mb_substr($vt, 0, 60) : '') . ')'];
            if (count($media) >= VP_PHOTOS_MAX) break;  // r29: was 4
        }
    } catch (Throwable $e) { $media = []; }
    $cache = vp_cache_load();                          // reload: lookups take seconds
    $cache[$key] = ['media' => $media, 'at' => time()];
    if ($channel) $cache[$key]['channel'] = $channel;   // for the per-story check
    vp_cache_save($cache);
    return $pick($media);
}

/**
 * VIDEO-flavored people extraction: like drama_people_ai but asks for the name
 * the INTERNET knows the person by (handle/channel/stage name). For creator
 * stories the handle IS the resolvable identity — "Metronade" finds the real
 * channel avatar; the legal name "Roger Elliot Moore" resolves to nothing (or
 * worse, a namesake). CLI/cron only; results are cached by the caller.
 */
function vp_people_ai(string $title, string $summary = ''): array {
    require_once __DIR__ . '/ai.php';
    $res = ai_chat([['role' => 'user', 'content' =>
        "From this creator-drama headline and summary, list ONLY the real PEOPLE involved, "
        . "most important first, max 3. Use the name the INTERNET knows each person by — their "
        . "channel/handle/stage name (e.g. 'Metronade', 'MrBeast', 'Emiru') when they are an online "
        . "creator, the full real name otherwise. Do NOT include events, companies, shows, or topics. "
        . "Headline: \"$title\"\nSummary: \"" . mb_substr($summary, 0, 300) . "\"\n"
        . "STRICT JSON: {\"people\":[\"Name\"]}"]], ['gemini', 'openrouter', 'nvidia'], 0.1);
    $j = isset($res['error']) ? null : ai_json($res['content'] ?? '');
    return array_slice(array_values(array_filter((array)($j['people'] ?? []),
        fn($p) => is_string($p) && mb_strlen(trim($p)) >= 3)), 0, 3);
}

/** Names (+sameAs) from a people_json blob; tolerates strings and objects. */
function vp_names_from_json(?string $peopleJson): array {
    $out = [];
    foreach ((array)json_decode((string)$peopleJson, true) as $p) {
        $n = trim((string)(is_array($p) ? ($p['name'] ?? '') : $p));
        if ($n === '') continue;
        $out[] = ['name' => $n, 'sameAs' => is_array($p) ? (array)($p['sameAs'] ?? []) : []];
    }
    return $out;
}

/**
 * THE feed entry point: the drama's people as
 * [{"name":..., "photo":url|null, "photos":[urls]}] (r11: photos PLURAL —
 * first entry is the resolved avatar exactly as before, then that person's
 * recent-channel thumbnails, so the maker gets variety per person).
 * Empty people_json (the small-creator gap) is healed by AI name extraction —
 * CLI/cron only, cached per page — so the static feed builder learns the names
 * the entity graph dropped. Web requests (video_next.php) use cache only.
 */
function video_people_resolve(PDO $pdo, int $pageId, ?string $peopleJson,
                              string $title = '', string $context = '', int $max = 4): array {
    $people = vp_names_from_json($peopleJson);
    // r189: the AI-read names are MERGED in, not only used when the verified
    // list is empty. people_json keeps only people with a verified entity, so a
    // private person the headline is about vanished: page 1022 ("Patrick
    // Clancy Legal Threats...") listed only Lindsay Clancy while this very
    // cache held ["Patrick Clancy"], and the video shipped without him.
    // Verified people stay first; extracted ones join after, deduplicated.
    $have = [];
    foreach ($people as $p) $have[mb_strtolower(trim((string)$p['name']))] = 1;
    $cache = vp_cache_load();
    $nk = 'names:' . $pageId;
    $hit = $cache[$nk] ?? null;
    $extracted = null;
    if ($hit && (time() - (int)($hit['at'] ?? 0)) < VP_TTL_NAMES) {
        $extracted = (array)($hit['names'] ?? []);
    } elseif (PHP_SAPI === 'cli' && $title !== '') {
        $extracted = [];
        try { $extracted = vp_people_ai($title, $context); } catch (Throwable $e) {}
        $cache = vp_cache_load();
        $cache[$nk] = ['names' => array_values($extracted), 'at' => time()];
        vp_cache_save($cache);
    }
    foreach ((array)$extracted as $n) {
        $k = mb_strtolower(trim((string)$n));
        if ($k === '' || isset($have[$k])) continue;
        $people[] = ['name' => trim((string)$n), 'sameAs' => []];
        $have[$k] = 1;
    }
    $live = (PHP_SAPI === 'cli');
    $out = [];
    foreach (array_slice($people, 0, $max) as $p) {
        $r = video_person_photo($p['name'], $p['sameAs'], $title . ' ' . $context, $live);
        // r11: photos PLURAL — avatar first (today's photo), then recent real
        // channel thumbnails of that same verified person. Deduped, max 4.
        // r29 (owner: videos repeat the same face because the pool is tiny):
        // the old cap of 4 starved a 20+ shot video, so one photo got reused ~10
        // times. Pull up to VP_PHOTOS_MAX distinct images per person — every
        // extra photo is one fewer repeat on screen.
        $photos = $r['photo'] ? [$r['photo']] : [];
        $capPhotos = (int)(defined('VP_PHOTOS_MAX') ? VP_PHOTOS_MAX : 8);
        try {
            // r180: only thumbnails of videos that belong to THIS story
            foreach (video_person_recent_media($p['name'], $capPhotos, $live, $title) as $mrow) {
                if (!in_array($mrow['url'], $photos, true)) $photos[] = $mrow['url'];
                if (count($photos) >= $capPhotos) break;
            }
        } catch (Throwable $e) { /* variety is an enhancement, never fatal */ }
        $out[] = ['name' => $p['name'], 'photo' => $r['photo'], 'photos' => $photos];
    }
    return $out;
}

/**
 * r11: the drama's KNOWN person names only — people_json first, else the
 * cached AI-extracted list. READ-ONLY (never calls the AI), so it is safe
 * from any context; video_event_visuals uses it to expand the pool with
 * per-person recent imagery.
 */
function vp_known_names(int $pageId, ?string $peopleJson, int $max = 3): array {
    $names = array_column(vp_names_from_json($peopleJson), 'name');
    // r189: union with the AI-read names (cache only here), same as the resolver
    $hit = vp_cache_load()['names:' . $pageId] ?? null;
    if ($hit && (time() - (int)($hit['at'] ?? 0)) < VP_TTL_NAMES) {
        $have = array_flip(array_map(fn($x) => mb_strtolower(trim((string)$x)), $names));
        foreach ((array)($hit['names'] ?? []) as $n) {
            $k = mb_strtolower(trim((string)$n));
            if ($k !== '' && !isset($have[$k])) { $names[] = trim((string)$n); $have[$k] = 1; }
        }
    }
    return array_slice($names, 0, $max);
}
