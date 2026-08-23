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
             : (str_starts_with($k, 'media:') ? VP_TTL_MEDIA
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
function vp_wikidata_p18_guarded(string $name, string $context = ''): ?string {
    static $creatorish = ['youtuber','streamer','internet','influencer','content creator','twitch',
        'social media','online','personality','gamer','tiktok','podcaster','celebrit','rapper','media'];
    $s = wm_api_host('www.wikidata.org', ['action' => 'wbsearchentities', 'search' => $name,
                                          'language' => 'en', 'type' => 'item', 'limit' => 5]);
    $top = $s['search'][0] ?? null;
    if (!$top || empty($top['id'])) return null;
    $desc = mb_strtolower((string)($top['description'] ?? ''));
    if ($desc === '') return null;                    // description-less item: unverifiable
    $isCreator = false;
    foreach ($creatorish as $w) if (str_contains($desc, $w)) { $isCreator = true; break; }
    if (!$isCreator) {
        // historical / clearly-other-era figures are never our story's subject
        if (preg_match('/\b1[0-8]\d\d\b|\b19[0-6]\d\b|sailor|soldier|navy|military|explorer|navigator|bishop|saint|monarch|missionary|colonel|general\b/', $desc)) {
            return null;
        }
        // require the entity's own description words to appear in the story
        // context (title + script excerpt) — "American rapper" passes on a rap
        // story; "australian cricketer" fails on a streamer death story.
        $ctx = mb_strtolower($name . ' ' . $context);
        $ok = false;
        foreach (preg_split('/[^a-z]+/', $desc) ?: [] as $w) {
            if (mb_strlen($w) >= 5 && !in_array($w, ['american','british','english','canadian','australian'], true)
                && str_contains($ctx, $w)) { $ok = true; break; }
        }
        if (!$ok) return null;
    }
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
            $photo = vp_photo_by_qid($m[1]);
            if ($photo) { $source = 'wikidata-entity(site)'; }
            break;   // one QID per person; a P18-less entity falls through to (b)
        }
    }
    // (b) the site's unified face resolver: verified Wikidata creator photo,
    //     else YouTube Data API channel avatar (the small-creator fix)
    if (!$photo) {
        $f = null;
        try { $f = drama_person_photo($name); } catch (Throwable $e) {}
        if ($f && !empty($f['url'])) {
            if (mb_stripos((string)($f['source'] ?? ''), 'youtube') !== false) {
                $photo = $f['url']; $source = 'youtube-avatar';
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
function video_person_recent_media(string $name, int $max = 4, bool $liveLookups = true): array {
    $name = trim($name);
    if (mb_strlen($name) < 2) return [];
    $max = max(1, min(VP_PHOTOS_MAX, $max));   // r29: was hard-capped at 4
    $cache = vp_cache_load();
    $key = 'media:' . mb_strtolower($name);
    $hit = $cache[$key] ?? null;
    if ($hit && (time() - (int)($hit['at'] ?? 0)) < VP_TTL_MEDIA) {
        return array_slice((array)($hit['media'] ?? []), 0, $max);
    }
    if (!$liveLookups) return [];                      // web request: cache only
    $media = [];
    try {
        foreach ((drama_channel_media($name, VP_PHOTOS_MAX * 2)['thumbs'] ?? []) as $t) {
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
            $media[] = ['url' => $u, 'title' => 'recent photo of ' . $name
                . ($mon !== '' ? " (their {$mon} video)" : ' (their recent video)')];
            if (count($media) >= VP_PHOTOS_MAX) break;  // r29: was 4
        }
    } catch (Throwable $e) { $media = []; }
    $cache = vp_cache_load();                          // reload: lookups take seconds
    $cache[$key] = ['media' => $media, 'at' => time()];
    vp_cache_save($cache);
    return array_slice($media, 0, $max);
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
    if (!$people) {
        $cache = vp_cache_load();
        $nk = 'names:' . $pageId;
        $hit = $cache[$nk] ?? null;
        if ($hit && (time() - (int)($hit['at'] ?? 0)) < VP_TTL_NAMES) {
            foreach ((array)($hit['names'] ?? []) as $n) $people[] = ['name' => (string)$n, 'sameAs' => []];
        } elseif (PHP_SAPI === 'cli' && $title !== '') {
            $names = [];
            try { $names = vp_people_ai($title, $context); } catch (Throwable $e) {}
            $cache = vp_cache_load();
            $cache[$nk] = ['names' => array_values($names), 'at' => time()];
            vp_cache_save($cache);
            foreach ($names as $n) $people[] = ['name' => (string)$n, 'sameAs' => []];
        }
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
            foreach (video_person_recent_media($p['name'], $capPhotos, $live) as $mrow) {
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
    if (!$names) {
        $hit = vp_cache_load()['names:' . $pageId] ?? null;
        if ($hit && (time() - (int)($hit['at'] ?? 0)) < VP_TTL_NAMES) {
            $names = array_values(array_filter(array_map('strval', (array)($hit['names'] ?? []))));
        }
    }
    return array_slice($names, 0, $max);
}
