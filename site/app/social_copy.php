<?php
/* GenZHype | shared social copy composer (2026-08-08).
   One place that turns a story into platform-native text, so the queue
   endpoint, the posters and the phone copy-page never drift apart.

   YouTube rules (2026 Shorts rulebook): subject-first title, ~40 visible
   chars, rotating brand suffix, hashtags in the description only.
   TikTok rules (2026 TikTok rulebook): the NAME is the first thing in the
   caption because TikTok search is how people find drama by person; 4-5
   hashtags, 2 fixed niche anchors + story-specific; never #fyp. */
declare(strict_types=1);

const SOCIAL_YT_SUFFIXES = ['the receipts', 'full timeline in 60s', 'explained'];

/** r79 — learned style hints, applied automatically but NEVER silently.
 *  Reads the intelligence engine's high-confidence rules (comp_rule
 *  scope='video') and returns bounded hints. Every application is written to
 *  rule_apply_log and shown in the admin, so the owner can see exactly what
 *  the learning changed and roll a rule back by deactivating it. Fails
 *  closed: no DB, no rule, low confidence -> no hints -> behaviour is
 *  exactly what it was before this existed. */
function social_style_hints(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    // r114: the engine had written FIVE rules and exactly ONE of them reached
    // any code. `title_shape` passed a single number (a char target) and threw
    // away the three findings that actually separate winners from normal
    // videos; `publish_hours_utc`, `duration_band` and `top_outliers` were
    // collected daily and read by nothing. All four are wired here. Same
    // contract as before: bounded, logged, and fails closed to old behaviour.
    $cache = [
        'title_target_chars' => null,   // title_shape: length
        'title_traits'       => [],     // title_shape: what winners DO
        'outlier_examples'   => [],     // top_outliers: titles that actually won
        'slot_hours'         => null,   // publish_hours_utc: when rivals post
        'duration_target_s'  => null,   // duration_band: dormant until it has a signal
        'ig_target_chars'    => null,   // ig_caption_shape: winning caption length
    ];
    if (!function_exists('db')) return $cache;
    try {
        $rows = [];
        foreach (db()->query("SELECT rule_key, rule_value, confidence FROM comp_rule
                              WHERE scope='video' AND active=1") as $r) {
            $rows[$r['rule_key']] = $r;
        }

        // --- title_shape -------------------------------------------------
        if (isset($rows['title_shape']) && (int)$rows['title_shape']['confidence'] >= 75) {
            $v = json_decode((string)$rows['title_shape']['rule_value'], true) ?: [];
            $t = (int)($v['median_chars_outlier'] ?? 0);
            if ($t >= 20) $cache['title_target_chars'] = max(24, min(70, $t));
            // The gaps ARE the finding: how many points more often a winner
            // does something than a normal video. Only gaps big enough to be
            // more than noise become instructions.
            $gaps = (array)($v['biggest_gaps_pp'] ?? []);
            $say = [
                'name_first'  => 'open with the person or brand name, before anything else',
                'has_number'  => 'put a concrete number in it',
                'has_caps'    => 'let ONE word carry the weight in caps',
                'has_quote'   => 'quote the words that were actually said',
                'has_question'=> 'ask the question the viewer already has',
            ];
            foreach ($say as $k => $text) {
                if ((float)($gaps[$k] ?? 0) >= 5.0) $cache['title_traits'][$k] = $text;
            }
        }

        // --- top_outliers: the receipts behind the traits ------------------
        if (isset($rows['top_outliers']) && (int)$rows['top_outliers']['confidence'] >= 75) {
            $v = json_decode((string)$rows['top_outliers']['rule_value'], true) ?: [];
            foreach (array_slice((array)$v, 0, 5) as $o) {
                $t = trim((string)($o['title'] ?? ''));
                if ($t !== '') {
                    $cache['outlier_examples'][] = [
                        'title' => mb_substr($t, 0, 90),
                        'x'     => (float)($o['x_median'] ?? 0),
                    ];
                }
            }
        }

        // --- publish_hours_utc: when the lane actually posts ---------------
        // Confidence is only 60 and the sample is thin, so this NEVER replaces
        // our slots — it adds the single best-evidenced rival hour alongside
        // them, and only if that hour was seen at least 3 times.
        if (isset($rows['publish_hours_utc'])) {
            $v = json_decode((string)$rows['publish_hours_utc']['rule_value'], true) ?: [];
            $tops = (array)($v['top_hours'] ?? []);
            $ours = array_map('intval', (array)($v['ours_now'] ?? []));
            arsort($tops);
            foreach ($tops as $hour => $n) {
                $h = (int)$hour;
                if ((int)$n < 3 || $h < 0 || $h > 23) continue;
                if (in_array($h, $ours, true)) continue;      // already covered
                $cache['slot_hours'] = array_values(array_unique(
                    array_merge($ours ?: [16, 23], [$h])));
                sort($cache['slot_hours']);
                break;                                        // ONE new slot, not a rewrite
            }
        }

        // --- ig_caption_shape: how long a WINNING Instagram caption runs ---
        // Measured on 1,584 rival posts (r118 deep pull, full captions): posts
        // that beat their own account's median run ~375 chars vs ~276 normal.
        // The owner had spotted this by eye weeks before the data confirmed
        // it; the first 39-post study missed it because the collector cut
        // captions at 100 chars. Bounded so a weird rule can never demand an
        // essay or a stub.
        if (isset($rows['ig_caption_shape']) && (int)$rows['ig_caption_shape']['confidence'] >= 75) {
            $v = json_decode((string)$rows['ig_caption_shape']['rule_value'], true) ?: [];
            $t = (int)($v['outlier']['caption_chars'] ?? 0);
            $base = (int)($v['baseline']['caption_chars'] ?? 0);
            if ($t >= 150 && $t > $base) {
                $cache['ig_target_chars'] = max(250, min(500, $t));
            }
        }

        // --- duration_band: deliberately dormant ---------------------------
        // The engine's own numbers say outlier median == baseline median (36s
        // vs 36s), i.e. length is NOT what separates a winner. Wiring that
        // would be wiring noise. The plumbing is here and switches itself on
        // the day the two medians actually diverge.
        if (isset($rows['duration_band']) && (int)$rows['duration_band']['confidence'] >= 70) {
            $v = json_decode((string)$rows['duration_band']['rule_value'], true) ?: [];
            $out = (int)($v['outlier_median_s'] ?? 0);
            $base = (int)($v['baseline_median_s'] ?? 0);
            if ($out >= 15 && $base > 0 && abs($out - $base) >= 5) {
                $cache['duration_target_s'] = max(20, min(90, $out));
            }
        }
    } catch (Throwable $e) { /* hints are optional, never fatal */ }
    return $cache;
}

/** r114 — the learned title findings as prompt text, or '' when the engine
 *  has nothing confident to say. Used by the writer that composes the spoken
 *  hook, which is the one line where a title trait can still change the
 *  outcome (by the time copy is composed, the title already exists). */
function social_title_guidance(): string {
    $h = social_style_hints();
    if (!$h['title_traits'] && !$h['outlier_examples']) return '';
    $out = '';
    if ($h['title_traits']) {
        $out .= ' WHAT WINS IN THIS LANE RIGHT NOW (measured on rival videos that '
              . 'beat their own channel median, not opinion): ' . implode('; ', $h['title_traits']) . '.';
    }
    if ($h['outlier_examples']) {
        $ex = [];
        foreach ($h['outlier_examples'] as $o) {
            $ex[] = '"' . $o['title'] . '" (' . round($o['x'], 1) . 'x its channel median)';
        }
        $out .= ' Recent winners: ' . implode(' / ', $ex)
              . '. Match the SHAPE, never the subject.';
    }
    return $out;
}

function social_style_log(string $rule, ?int $pageId, string $what,
                          string $before, string $after): void {
    if (!function_exists('db')) return;
    try {
        // the queue is fetched many times a day by the posters — log each
        // (rule, page) decision once a week, not once per fetch
        $q = db()->prepare("SELECT COUNT(*) FROM rule_apply_log
                            WHERE rule_key=? AND page_id<=>?
                              AND at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)");
        $q->execute([$rule, $pageId]);
        if ((int)$q->fetchColumn() > 0) return;
        db()->prepare("INSERT INTO rule_apply_log (rule_key, page_id, what, before_val, after_val, at)
                       VALUES (?,?,?,?,?,UTC_TIMESTAMP())")
            ->execute([$rule, $pageId, mb_substr($what, 0, 110),
                       mb_substr($before, 0, 250), mb_substr($after, 0, 250)]);
    } catch (Throwable $e) { /* logging must never break composition */ }
}

/** Lane hashtag from the story's own words — we only run 4 lanes. */
function social_lane(string $text): string {
    $t = mb_strtolower($text);
    foreach (['game', 'gaming', 'warcraft', 'fortnite', 'minecraft', 'roblox',
              'valorant', 'esports', 'speedrun', 'dev'] as $w) {
        if (str_contains($t, $w)) return 'gamingnews';
    }
    foreach (['meme', 'viral trend', 'tiktok trend', 'brainrot', 'copypasta'] as $w) {
        if (str_contains($t, $w)) return 'memeexplained';
    }
    return 'streamerdrama';
}

/** Subject hashtag: only ever a name we can actually stand behind. */
function social_subject(string $title, string $peopleJson): string {
    $people = json_decode($peopleJson ?: '[]', true);
    $name = '';
    foreach (is_array($people) ? $people : [] as $p) {          // must be IN the title
        $n = (string)($p['name'] ?? '');
        if ($n !== '' && stripos($title, $n) !== false) { $name = $n; break; }
    }
    if ($name === '') {
        $pat = '/^((?:[A-Z][\w\x27\x{2019}]+)(?:\s+(?:of|the|and|for|in|vs)\s+[A-Z][\w\x27\x{2019}]+|\s+[A-Z][\w\x27\x{2019}]+){0,2})/u';
        if (preg_match($pat, $title, $m)) {
            $stop = ['The', 'A', 'An', 'How', 'Why', 'What', 'When', 'Inside', 'New'];
            $words = preg_split('/\s+/', trim($m[1])) ?: [];
            while ($words && in_array($words[0], $stop, true)) array_shift($words);
            $notName = ['faces', 'over', 'responds', 'addresses', 'accused',
                        'sparks', 'slams', 'calls', 'breaks', 'reveals',
                        'denies', 'backlash', 'rise', 'fallout', 'hits',
                        'goes', 'gets', 'says', 'after', 'with'];
            foreach ($words as $w) {
                if (in_array(mb_strtolower($w), $notName, true)) return '';
            }
            $name = implode(' ', array_slice($words, 0, 3));
        }
    }
    $generic = ['world', 'creator', 'video', 'online', 'internet', 'streamer',
                'drama', 'famous', 'popular', 'people', 'twitter'];
    $tag = (string)preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    if (in_array($tag, $generic, true)) return '';
    return (strlen($tag) >= 4 && strlen($tag) <= 24) ? $tag : '';
}

/** r120 — does this story belong on OUR YouTube channel?
 *
 *  The evidence, three ways, all pointing the same direction:
 *    - the same celeb-gossip brands that carry millions of followers on
 *      Instagram are NOBODY on YouTube (measured in vid_rival: Pop Crave
 *      480 subs, popbase 1, CultureCrave 4) while the streamer/gaming lane
 *      is where YouTube's drama audience actually lives (DramaAlert 5.2M,
 *      Dexerto 881K);
 *    - our own only breakout (88 views vs a 5-view average) was the
 *      Asmongold/Twitch story;
 *    - YouTube's 2026 recommendation groups viewers by watch-history
 *      clusters, and a channel that mixes celeb + gaming + memes never
 *      builds a cluster identity, so every Short dies in the seed test.
 *
 *  So YouTube gets ONLY the streamer/gaming/internet-culture stories, and
 *  the channel becomes one thing the algorithm can classify. Celeb and music
 *  stories still ship everywhere else — this changes YouTube, not the story.
 *
 *  Deliberately keyword-simple: auditable in one glance, and a miss costs
 *  one story on ONE platform. Names update as the scene moves. */
function social_yt_fit(string $title, string $desc, string $peopleJson): bool {
    // TWO HARD-WON LESSONS in this function:
    //  - never scan raw people_json: every entity carries its sameAs links,
    //    which include the person's youtube.com URL — so the word "youtube"
    //    fired on ARIANA GRANDE and classified every celebrity as lane-fit;
    //  - word boundaries, not substrings: bare "stream" claimed every
    //    music-STREAMING story, "kick" matched "kicked".
    //
    // The people data is still the best signal we have — via its ROLE field,
    // where Wikidata says outright whether someone is a "Twitch streamer" or
    // an "American singer". Roles first, then keywords on title+desc only.
    $roleFit = '/\b(?:streamer|youtuber|gamer|esports?|game\s+developer|'
             . 'twitch|speedrunner|internet\s+personality)\b/iu';
    foreach ((array)(json_decode($peopleJson, true) ?: []) as $p) {
        $role = (string)($p['role'] ?? '');
        if ($role !== '' && preg_match($roleFit, $role)) { return true; }
    }

    $t = mb_strtolower($title . ' ' . $desc);
    $re = '/\b(?:'
        . 'twitch|kick|streamer|youtube|youtuber|'
        . 'gamer?s?|gaming|esports?|speedrunn?(?:er|ing)?|warcraft|fortnite|'
        . 'minecraft|roblox|valorant|nintendo|playstation|xbox|'
        . 'asmongold|mrbeast|kai\s+cenat|ishowspeed|adin\s+ross|xqc|'
        . 'pokimane|ludwig|hasanabi|mizkif|penguinz0|jacksepticeye|'
        . 'markiplier|sneako|jidion|ksi|logan\s+paul|jake\s+paul|'
        . 'dr\s*disrespect|nickmercs|tfue|'
        . 'memes?|brainrot|reddit|discord|4chan'
        . ')\b/u';
    return (bool)preg_match($re, $t);
}

/** r134 — THE 20-HASHTAG EXPERIMENT (owner decision 2026-08-19, one week).
 *  The old counts (3-5) came from the rulebooks; the owner ordered 20 on
 *  TikTok, Instagram and YouTube to test the opposite. Honesty notes carried
 *  with it: our own 1,584-post IG study measured hashtag count as noise
 *  (winners 2.3 vs normal 2.0), and the "IG capped at 5" rulebook line was
 *  never primary-source verified — so this experiment is also the test that
 *  settles it. Facebook stays at 3 (>5 is Meta's own stated penalty zone).
 *  Next week's game tape + follower counts judge it; revert = set the env.
 *
 *  Tags are REAL, not filler: people names first (search is our measured
 *  follower door), then story keywords, then the niche pool. */
const SOCIAL_TAG_TARGET = 20;   // override: SOCIAL_TAG_TARGET env via config if needed

function social_tags_expand(string $title, string $desc, string $peopleJson,
                            string $kw = '', int $target = SOCIAL_TAG_TARGET): array {
    $tags = [];
    $add = function ($t) use (&$tags) {
        $t = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$t)));
        if ($t !== '' && mb_strlen($t) >= 2 && mb_strlen($t) <= 30
                && !in_array($t, $tags, true)) { $tags[] = $t; }
    };

    // 1. People: every named person, full name AND first name (search terms).
    foreach ((array)json_decode($peopleJson, true) as $p) {
        $n = trim((string)(is_array($p) ? ($p['name'] ?? '') : $p));
        if ($n === '') continue;
        $add($n);
        $parts = preg_split('/\s+/', $n) ?: [];
        if (count($parts) > 1) { $add($parts[0]); }
    }
    // 2. The story's own keywords: primary_kw + capitalized/title words.
    $add($kw);
    foreach (preg_split('/\s+/u', $title . ' ' . $kw) ?: [] as $w) {
        $w = trim($w, ".,:;!?'\"()");
        if (mb_strlen($w) >= 4 && !preg_match('/^(the|and|with|over|after|from|that|this|their|about)$/i', $w)) {
            $add($w);
        }
    }
    // 3. Platform + lane.
    $add(social_platform_tag($title . ' ' . $desc));
    $add(social_lane($title . ' ' . $desc));
    // 4. Niche pool fill — our actual beat, no #fyp-class spam tags.
    foreach (['internetculture', 'internetdrama', 'drama', 'genz', 'viral',
              'trending', 'creatornews', 'influencernews', 'streamer',
              'popculture', 'celebnews', 'internetnews', 'timeline',
              'receipts', 'genzhype', 'onlinedrama', 'socialmedia',
              'contentcreator', 'exposed', 'tea'] as $t) {
        if (count($tags) >= $target) break;
        $add($t);
    }
    return array_slice($tags, 0, $target);
}

/** Platform the story lives on, for one extra TikTok hashtag. */
function social_platform_tag(string $text): string {
    foreach (['twitch' => 'twitch', 'youtube' => 'youtube', 'tiktok' => 'tiktok',
              'kick' => 'kick', 'instagram' => 'instagram', 'discord' => 'discord']
             as $needle => $tag) {
        if (stripos($text, $needle) !== false) return $tag;
    }
    return '';
}

/** TikTok caption: name first (search), context sentence, 4-5 hashtags. */
function social_tt_caption(string $title, string $desc, string $peopleJson,
                           int $pageId = 0): string {
    $lane = social_lane($title . ' ' . $desc);
    $subj = social_subject($title, $peopleJson);
    $plat = social_platform_tag($title . ' ' . $desc);
    // r134: 20-tag experiment (see social_tags_expand)
    $tags = social_tags_expand($title, $desc, $peopleJson);
    $line = rtrim($title, '.') . ': the complete timeline with receipts.';
    if (trim($desc) !== '') $line .= ' ' . rtrim(trim($desc), '.') . '.';
    return $line . "\n\n" . social_cta($pageId, 'tt')
         . "\n\n#" . implode(' #', $tags);
}

/** Instagram Reels caption (2026 rules):
 *  - Instagram CAPPED hashtags at 5 in Dec 2025; 3-5 in the CAPTION only
 *    (Mosseri: comment hashtags don't register for search at all).
 *  - Captions truncate at ~125 chars, so the subject + hook must lead.
 *  - No URL: IG captions aren't clickable, so a raw link is pure clutter
 *    (Mosseri also debunked the "links cost reach" myth — it's neither
 *    penalty nor benefit, just wasted characters). Brand name only. */
function social_ig_caption(string $title, string $desc, string $peopleJson,
                           string $story = '', int $pageId = 0): string {
    $lane = social_lane($title . ' ' . $desc);
    $subj = social_subject($title, $peopleJson);
    $plat = social_platform_tag($title . ' ' . $desc);
    // r134: 20-tag experiment (the 'capped at 5' rulebook line was never
    // primary-source verified; official cap is 30, we use 20)
    $tags = social_tags_expand($title, $desc, $peopleJson);

    // r119 STORY CAPTION. Measured on 1,584 rival posts: Instagram posts that
    // beat their own account's median carry ~375-char captions that TELL the
    // story, not ~276-char teasers pointing elsewhere. So when the engine's
    // ig_caption_shape rule is live and we have the video's spoken script
    // (already one plain sentence per event), the caption becomes the story
    // itself. Rule inactive, low-confidence, or no script -> the old teaser,
    // exactly as before.
    $target = social_style_hints()['ig_target_chars'] ?? null;
    $story = trim($story);
    if ($target !== null && $story !== '') {
        $hookLine = rtrim($title, '.') . ': the full timeline.';
        // First 125 chars are what shows uncollapsed, so the hook must lead;
        // then story sentences up to the learned length. A sentence that just
        // restates the title is skipped — it reads as a stutter under it.
        $body = '';
        $titleKey = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?? '');
        foreach (preg_split('/(?<=[.!?])\s+/u', $story) ?: [] as $sent) {
            $sent = trim($sent);
            if ($sent === '') continue;
            $sentKey = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $sent) ?? '');
            if ($sentKey !== '' && str_starts_with($sentKey, mb_substr($titleKey, 0, 24))) continue;
            if (mb_strlen($hookLine . "\n\n" . $body . ' ' . $sent) > $target) break;
            $body .= ($body === '' ? '' : ' ') . $sent;
        }
        if (mb_strlen($body) >= 120) {          // enough story to be worth it
            $cap = $hookLine . "\n\n" . $body
                 . "\n\n" . social_cta($pageId, 'ig')
                 . "\n\n#" . implode(' #', $tags);
            social_style_log('ig_caption_shape', null,
                             'story caption (learned: winners run ~' . $target . ' chars)',
                             'teaser caption', mb_substr($cap, 0, 200));
            return $cap;
        }
    }

    $line = rtrim($title, '.') . ': the full timeline, with receipts.';
    if (trim($desc) !== '') $line .= "\n\n" . rtrim(trim($desc), '.') . '.';
    $line .= "\n\n" . social_cta($pageId, 'ig');
    return $line . "\n\n#" . implode(' #', $tags);
}

/** Facebook Page caption (2026 rules).
 *  Meta's own creator guidance is the whole spec, verbatim: "Captions
 *  without links, using minimal capital letters, and with five or fewer
 *  hashtags perform better." Clickbait Links is also one of only four
 *  remaining demotion categories — so we name the destination in plain
 *  text instead of embedding a clickable URL, keep it declarative
 *  (no ALL CAPS, no "!!!", no comment/share bait), and stay short. */
function social_fb_caption(string $title, string $desc, string $peopleJson,
                           string $story = '', int $pageId = 0): string {
    $lane = social_lane($title . ' ' . $desc);
    $subj = social_subject($title, $peopleJson);
    $tags = array_slice(array_values(array_unique(array_filter(
        [$subj, $lane, 'internetculture']))), 0, 3);

    // r119 STORY CAPTION, Facebook arm. The length target is BORROWED from
    // the Instagram measurement (ig_caption_shape, 1,584 posts: winners ~375
    // chars of actual story) — Facebook rival data has no free source since
    // CrowdTangle died, so its own arm cannot be measured. Same feed company,
    // same mechanics, closest evidence we have; logged as borrowed so the day
    // FB numbers say otherwise this is easy to find and cut. A story caption
    // also matters MORE here: Meta's 2026 originality policy names narrated
    // clip formats as deprioritized, and original text on the post is one of
    // the few originality signals a Page can add. Everything else keeps
    // Meta's own caption guidance: no links, minimal caps, <=3 hashtags.
    $target = social_style_hints()['ig_target_chars'] ?? null;
    $story = trim($story);
    if ($target !== null && $story !== '') {
        $hookLine = rtrim($title, '.') . '. The full dated timeline:';
        $body = '';
        $titleKey = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?? '');
        foreach (preg_split('/(?<=[.!?])\s+/u', $story) ?: [] as $sent) {
            $sent = trim($sent);
            if ($sent === '') continue;
            $sentKey = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $sent) ?? '');
            if ($sentKey !== '' && str_starts_with($sentKey, mb_substr($titleKey, 0, 24))) continue;
            if (mb_strlen($hookLine . "\n\n" . $body . ' ' . $sent) > $target) break;
            $body .= ($body === '' ? '' : ' ') . $sent;
        }
        if (mb_strlen($body) >= 120) {
            $cap = $hookLine . "\n\n" . $body
                 . "\n\n" . social_cta($pageId, 'fb')
                 . "\n\n#" . implode(' #', $tags);
            social_style_log('ig_caption_shape', null,
                             'FB story caption (length borrowed from the IG measurement)',
                             'teaser caption', mb_substr($cap, 0, 200));
            return $cap;
        }
    }

    $line = rtrim($title, '.') . '. The full dated timeline, with every receipt.';
    $line .= "\n\n" . social_cta($pageId, 'fb');
    return $line . "\n\n#" . implode(' #', $tags);
}

/** YouTube title + description + keyword tags. */
function social_yt_meta(int $pageId, string $title, string $desc,
                        string $peopleJson, string $link, string $kwSource): array {
    $suffix = SOCIAL_YT_SUFFIXES[$pageId % count(SOCIAL_YT_SUFFIXES)];
    $ytTitle = $title . ' | ' . $suffix;
    // r79 AUTO-APPLY (logged): the rival study found winning Shorts titles are
    // SHORT (median 29 chars) — when the story title alone already exceeds the
    // learned target, a brand suffix only pushes further past what wins, so it
    // is dropped. Subject-first composition is untouched (100% of winners do
    // it too). Rule off or low-confidence -> old behaviour exactly.
    $hint = social_style_hints();
    if (($hint['title_target_chars'] ?? null) !== null
            && mb_strlen($title) >= $hint['title_target_chars']) {
        social_style_log('title_shape', $pageId, 'dropped suffix (title over learned target)',
                         $ytTitle, $title);
        $ytTitle = $title;
    }
    if (mb_strlen($ytTitle) > 90) $ytTitle = mb_substr($title, 0, 90);

    $lane = social_lane($title . ' ' . $kwSource);
    $subj = social_subject($title, $peopleJson);
    // r134: 20-tag experiment — YouTube allows 60, shows the first 3 above
    // the title, so the searchable trio leads: shorts + person + lane.
    $tags = array_slice(array_values(array_unique(array_merge(
        array_filter(['shorts', $subj, $lane]),
        social_tags_expand($title, $desc, $peopleJson, $kwSource)))), 0, 20);

    $d = $title . "\n";
    if (trim($desc) !== '') $d .= trim($desc) . "\n";
    $d .= "\nFull sourced timeline and every receipt: " . $link . "\n";
    $d .= "Every claim above is sourced and dated on that page.\n\n";
    $d .= '#' . implode(' #', $tags);

    $kw = array_slice(array_values(array_unique(array_filter(
        preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title . ' ' . $lane)) ?: [],
        fn($w) => mb_strlen($w) > 3))), 0, 10);

    return ['title' => $ytTitle, 'desc' => $d, 'tags' => $kw];
}

/**
 * THE CLOSING LINE — the owner's ask: "a slick way to say it without making it
 * clear... move their emotion" (2026-08-20).
 *
 * [RULE] Berger & Milkman 2012 (JMR, ~7,000 NYT articles): sharing is driven
 *        by HIGH-AROUSAL feeling — anger, awe, amusement, anxiety. Sadness
 *        suppresses it. So the closing line's job is to leave a feeling
 *        standing, not to make a request.
 * [RULE] Asking is also the penalised path: Meta's creator guidance names
 *        engagement bait ("like, share, tag a friend") as demoted, and
 *        TikTok's unoriginal/bait rules say the same. So we never use the
 *        words like/share/tag/comment/follow anywhere in these lines.
 * [OURS] The emotion comes from the Producer's stored verdict for the page
 *        (producer_emotion_for_page). No verdict -> a neutral stance line
 *        that is safe on every lane and platform.
 *
 * Deterministic per page (page_id % count) so one story keeps one voice, and
 * different stories do not all close the same way — the same rotation trick
 * the voice picker uses.
 */
function social_cta(int $pageId, string $platform = 'ig'): string
{
    $emotion = 'unknown';
    if (function_exists('producer_emotion_for_page')) {
        try {
            $e = producer_emotion_for_page(db(), $pageId);
            if (!empty($e['emotion'])) { $emotion = (string)$e['emotion']; }
        } catch (Throwable $ex) { /* captions never depend on the Producer */ }
    }

    // Anger/conflict -> invite a SIDE (stance is what makes people argue).
    // Amusement -> a SEND trigger (Instagram's real growth signal is sends,
    // and "someone you know" is a send without asking for one).
    // Anxiety -> unresolved, come back. Awe -> the number does the work.
    $lines = [
        'anger' => [
            'Both sides think they are the one being wronged here.',
            'The dates decide this one, not the takes.',
            'Read the order things happened in before picking a side.',
        ],
        'amusement' => [
            'Somebody you know still gets this one wrong.',
            'Try explaining this out loud to anyone over thirty.',
            'This is the part that makes no sense out of context.',
        ],
        'anxiety' => [
            'This one is still moving, and the timeline moves with it.',
            'Nobody has the ending yet, including the people in it.',
        ],
        'awe' => [
            'The numbers on this one look made up. They are not.',
            'The scale of this is the part people keep missing.',
        ],
        'sadness' => [
            'The full record, dated, without the noise.',
        ],
    ];
    // No verdict (the Producer has never weighed this page — most older
    // videos): still close on something with a pulse, but never a line
    // that just restates the destination underneath it.
    $pool = $lines[$emotion] ?? [
        'The order things happened in is the whole story.',
        'The receipts are dated. The takes are not.',
        'Everything here can be checked against a date.',
    ];
    $line = $pool[$pageId % count($pool)];

    // Destination: Facebook demotes clickbait links and Instagram kills URLs
    // in captions, so the domain is named in plain text, never linked.
    $where = $platform === 'fb'
        ? 'Full dated timeline on GenZHype.'
        : 'Every source, dated, on GenZHype.com';
    return $line . "\n\n" . $where;
}
