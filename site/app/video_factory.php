<?php
// GenZHype | faceless VIDEO factory (server side). Pre-generates a tight ~35s vertical-video
// voiceover SCRIPT per drama from the REAL dated receipts, so the feed endpoint (video_next.php)
// serves it instantly with NO AI in the web request (no 504). The external maker (GitHub Actions:
// edge-tts + FFmpeg) renders the 9:16 MP4 and POSTs it back to video_receive.php. This mirrors the
// Reddit pre-draft pattern: cron generates with the full robust provider chain (CLI, no web timeout).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/receipt_cards.php';   // v4.5 REAL-VISUALS: evidence cards per event
require_once __DIR__ . '/social_copy.php';     // r114: learned title traits reach the hook writer

function video_factory_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_scripts (
      page_id       INT UNSIGNED PRIMARY KEY,
      slug          VARCHAR(200) NOT NULL,
      title         VARCHAR(300) NOT NULL,
      hook          VARCHAR(200) NOT NULL,
      script        MEDIUMTEXT   NOT NULL,
      image         VARCHAR(500) NOT NULL,
      video_path    VARCHAR(300) NULL,
      video_status  ENUM('pending','ready') NOT NULL DEFAULT 'pending',
      video_made_at DATETIME     NULL,
      created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // migrate any pre-existing table to carry the finished-video columns (ignore dup-column)
    foreach ([
        "ADD COLUMN video_path VARCHAR(300) NULL",
        "ADD COLUMN video_status ENUM('pending','ready') NOT NULL DEFAULT 'pending'",
        "ADD COLUMN video_made_at DATETIME NULL",
        "ADD COLUMN tpl TINYINT NOT NULL DEFAULT 0",   // script template version (2 = creator-playbook era)
        "ADD COLUMN broll VARCHAR(400) NULL",          // JSON array of stock-footage search terms for the maker
        "ADD COLUMN shotlist MEDIUMTEXT NULL",         // v4 DIRECTOR: word-anchored EDL (see videorepos/V4-EDITOR-SPEC.md)
        "ADD COLUMN gravity VARCHAR(10) NOT NULL DEFAULT 'standard'",
        "MODIFY COLUMN video_status ENUM('pending','ready','skipped') NOT NULL DEFAULT 'pending'",   // THE EYES: gated-out stories park here, out of every pending queue
        "ADD COLUMN skip_reason VARCHAR(200) NULL",   // r16: 'grave' = tragedy register (no debate outros)
    ] as $alter) { try { $pdo->exec("ALTER TABLE video_scripts {$alter}"); } catch (Throwable $e) {} }
}

/**
 * r16 GRAVITY SYSTEM (decency law): classify a story as 'grave' when a death,
 * serious violence or a serious human tragedy is involved — those videos must
 * NEVER get the savage-hook / debate-outro drama treatment. Deterministic
 * keyword match over title + summary + dated events text, case-insensitive,
 * erring TOWARD grave (a hype edit on a tragedy is brand-killing; a sober edit
 * on a spicy story is merely bland). Parallels drama_is_sensitive() but is
 * scoped to real tragedy (no 'minor'/'child' alone — a story about a
 * childhood friend is not a funeral).
 */
function video_story_gravity(string $title, string $summary = '', string $events = ''): string {
    $t = ' ' . mb_strtolower($title . ' ' . $summary . ' ' . $events) . ' ';
    // r21 (seen live): game-ability names in quotes ('Area Death', 'Death
    // Touch', instakill lore) false-graved a VIDEOGAME story -> the machine
    // held a funeral for a game patch. Quoted spans are titles/abilities, not
    // human tragedy: strip them before matching.
    $t = preg_replace('/"[^"]{1,60}"/u', ' ', $t) ?? $t;
    $t = preg_replace('/‘[^’]{1,60}’|\'[^\']{1,60}\'/u', ' ', $t) ?? $t;
    // gaming-context guard: death-y words alongside dungeon/raid/GM/gameplay
    // vocab and NO human-death phrase = game lore, not a funeral.
    if (preg_match('/\b(dungeon|raid|mythic|gameplay|game master|\bgm\b|instakill|respawn|loot)\b/iu', $t)
        && !preg_match('/\b(passed away|found dead|found lifeless|body was found|cause of death|autopsy|funeral|obituar|shot dead|killed (him|her|a |the ))\b/iu', $t)) {
        $t = preg_replace('/\b(death\w*|dead|died|dies|dying|killed|killing|kills)\b/iu', ' ', $t) ?? $t;
    }
    $re = '/\b(dead|death\w*|died|dies|dying|killed|killing|kills|murder\w*|homicide\w*|manslaughter'
        . '|fatal\w*|suicid\w*|overdos\w*|passed away|passing away|autopsy|coroner|funeral|obituar\w*'
        . '|rest in peace|r\.i\.p|sexual assault\w*|sexually assault\w*|sexual abuse|rape\w*|raping'
        . '|molest\w*|groomed|grooming|traffick\w*|abus\w*|domestic violence|shooting|shot dead'
        . '|shot and killed|gunman|gunfire|stabb\w*|stabbing|strangl\w*|hospitalized|hospitalised'
        . '|critical condition|life support|life.threatening|coma|kidnap\w*|abduct\w*|self.?harm'
        . '|found unresponsive|found lifeless|body was found|cause of death)\b/iu';
    return preg_match($re, $t) ? 'grave' : 'standard';
}

/** r16: does the grave story involve a DEATH (drives the 'Our thoughts are with those affected.' close)? */
function video_story_involves_death(string $text): bool {
    return (bool)preg_match('/\b(dead|death\w*|died|dies|dying|killed|fatal\w*|suicid\w*|overdos\w*'
        . '|murder\w*|homicide\w*|manslaughter|passed away|autopsy|coroner|funeral|obituar\w*'
        . '|rest in peace|shot dead|shot and killed|found lifeless|body was found|cause of death)\b/iu', $text);
}

/**
 * NAME GUARD: the model occasionally misspells a person's name in the hook/script
 * ("Mizikif" for Mizkif) — and a video burns that into the captions + voiceover.
 * Deterministic fix: any token within levenshtein<=2 of a known person-name token
 * (len>=5) is replaced by the canonical spelling. No AI, no false positives on
 * short/common words.
 */
function video_fix_names(string $text, array $people): string {
    $canon = [];
    foreach ($people as $p) foreach (preg_split('/\s+/', trim((string)$p)) as $tok) {
        if (mb_strlen($tok) >= 5) $canon[] = $tok;
    }
    if (!$canon) return $text;
    return preg_replace_callback('/\b[\p{L}][\p{L}\'-]{4,}\b/u', function ($m) use ($canon) {
        $w = $m[0];
        // r16 GUARD FIX: fuzzy correction applies ONLY to capitalized (proper-
        // noun-looking) tokens — it turned the common word "filed" into the
        // name "Filly" (levenshtein 2) mid-sentence. Case-only fixes still
        // apply to any casing.
        $isCap = (bool)preg_match('/^\p{Lu}/u', $w);
        foreach ($canon as $c) {
            if (strcasecmp($w, $c) === 0) return $c;                       // fix casing only
            if (!$isCap) continue;
            $d = levenshtein(mb_strtolower($w), mb_strtolower($c));
            if ($d > 0 && $d <= 2 && abs(mb_strlen($w) - mb_strlen($c)) <= 2) return $c;
        }
        return $w;
    }, $text) ?? $text;
}

/** Write (or refresh) the video script for one drama row. Full robust AI chain — CLI only. */
/**
 * r59: hold the script to its picture budget, deterministically.
 *
 * Asking the writer nicely is not enough — page 484 has 3 usable images (a
 * 55-70 word budget) and the model still returned 116 words. So enforce it in
 * code, the same way the Director's pins and the promo-card law are enforced
 * rather than requested.
 *
 * This is SAFE here and ONLY here: the shot list is authored AFTER the script
 * is stored, so trimming now simply means the Director plans against the final
 * text. Trimming in the renderer would desync the Director's word indexes from
 * the narration, and caption sync is sacred.
 *
 * Whole sentences only, dropped from the END of the body: the first sentence
 * (the spoken hook) and the last (the sign-off / CTA) always survive, so the
 * script keeps its opening and its close and never ends mid-thought.
 */
function video_trim_script_to_budget(string $script, int $wHi): string {
    $wc = static fn(string $s): int => count(preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY));
    if ($wc($script) <= $wHi) return $script;
    $parts = preg_split('/(?<=[.!?])\s+/u', trim($script), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || count($parts) < 4) return $script;   // too short to cut safely
    $head = array_shift($parts);          // the spoken hook
    $tail = array_pop($parts);            // the sign-off / CTA
    while ($parts && $wc($head . ' ' . implode(' ', $parts) . ' ' . $tail) > $wHi) {
        array_pop($parts);                // drop the least essential body beat
    }
    $out = trim($head . ' ' . implode(' ', $parts) . ' ' . $tail);
    return $wc($out) >= 35 ? $out : $script;   // never cut into nonsense
}

function video_write_script(PDO $pdo, array $pick): ?array {
    $ev = $pdo->prepare("SELECT event_date, title, description FROM events WHERE drama_id=? ORDER BY sort_order, event_date LIMIT 12");
    $ev->execute([(int)$pick['did']]);
    $beats = '';
    foreach ($ev->fetchAll() as $e) {
        require_once __DIR__ . '/inspector.php';
        $bd = insp_chip((string)($e['event_date'] ?? ''), true);   // never strtotime: a partial date would be spoken as a false one
        $beats .= '- ' . ($bd !== '' ? $bd . ': ' : '')
                . trim((string)$e['title']) . ' — ' . mb_substr(trim((string)$e['description']), 0, 220) . "\n";
    }
    // r16 GRAVITY: classify BEFORE any template choice. Grave stories (death,
    // serious violence, tragedy) get a measured news-explainer register:
    // restrained factual hooks, NO savage arsenal, NEVER a debate outro.
    $summaryTxt = (string)($pick['summary'] ?: $pick['meta_desc']);
    $gravity = video_story_gravity((string)$pick['title'], $summaryTxt, $beats);

    // ------------------------------------------------------------------
    // r59 LENGTH FOLLOWS THE PICTURES (owner-approved).
    // The video's runtime IS the narration's length — nothing downstream ever
    // looked at how many images the story actually has. So a story with 3
    // photos still got a 170-word script, which the Director then had to spread
    // over 22 shots: every picture on screen ~4 times. That is the "same image
    // again and again" complaint, and it is arithmetic, not a bug — 22 slots
    // cannot be filled from 3 pictures without repeating.
    // Measured on 2026-07-31 renders: page 536 = 3 visuals -> 22 scenes, 6
    // distinct stills, worst image carried 32% of the video. Page 487 = 14
    // visuals -> 17 scenes, 16 distinct, worst image 12%.
    // So: count the pictures FIRST and commission a script the pool can carry.
    // This is done HERE, at the writer, on purpose — the script and the shot
    // list are authored together, so a shorter script yields a matching shot
    // list. Trimming later in the renderer would desync the Director's word
    // indexes from the narration, and caption sync is sacred.
    // Speaking rate measured off tonight's three renders: ~2.3 words/second
    // (170w/76.4s, 135w/58.5s, 123w/51.8s).
    $estVis = 0;
    try {
        [$vu, ] = video_event_visuals($pdo, (int)$pick['did'],
                                      (string)($pick['cover'] ?? ''));
        $estVis = count($vu);
        $pq = $pdo->prepare("SELECT people_json FROM dramas WHERE id=?");
        $pq->execute([(int)$pick['did']]);
        $pj = json_decode((string)$pq->fetchColumn(), true);
        // each NAMED person reliably yields ~2 usable portraits once the
        // resolver + Openverse have run (Openverse was returning HTTP 401 on
        // every call until 2026-07-31, which is why pools looked so much
        // thinner than this estimate would suggest).
        if (is_array($pj)) $estVis += 2 * min(4, count($pj));
    } catch (Throwable $e) { $estVis = 0; }

    if     ($estVis <= 4)  { $wLo = 55;  $wHi = 70;  $secs = '25-30'; }
    elseif ($estVis <= 8)  { $wLo = 80;  $wHi = 105; $secs = '35-45'; }
    elseif ($estVis <= 14) { $wLo = 120; $wHi = 155; $secs = '52-67'; }
    else                   { $wLo = 155; $wHi = 195; $secs = '67-85'; }

    $lenRule = "LENGTH IS A HARD BUDGET, NOT A SUGGESTION: write {$wLo}-{$wHi} "
             . "words (about {$secs} seconds spoken). This story has roughly "
             . "{$estVis} usable images, and a short vertical video shows a new "
             . "picture every 2-3 seconds — a longer script than this cannot be "
             . "illustrated and would force the same photo on screen over and "
             . "over, which reads as a bot-made slideshow. A tight "
             . "{$secs}-second cut with a fresh image on every beat beats a "
             . "padded one every time. Cut the least essential fact rather than "
             . "exceeding {$wHi} words. NEVER pad. ";
    error_log("video_write_script: length budget ~{$estVis} visuals -> "
              . "{$wLo}-{$wHi} words ({$secs}s) for {$pick['slug']}");
    if ($gravity === 'grave') {
        $death = video_story_involves_death($pick['title'] . ' ' . $summaryTxt . ' ' . $beats);
        $graveHooks = [
            'FACTUAL EXPLAINER: "What happened to [name], explained." Calm, direct, factual, zero hype.',
            'TIMELINE OPENER: "The [name] case, from the first report to today." Measured, chronological framing.',
            'CONFIRMED UPDATE: "Here is what is actually confirmed in the [name] story." Sober; separates fact from rumor.',
        ];
        // THE PLAYBOOK (organ 12). The archetype now comes from the skill library,
        // which can version and retire one. With every skill active this returns
        // exactly what the array above returns - proven in pb_selftest, not assumed.
        // The array stays as the fallback: the library may never be the reason a
        // video fails to get written.
        $style = $graveHooks[(crc32($pick['slug']) & 0x7fffffff) % 3];
        try {
            require_once __DIR__ . '/playbook.php';
            $sk = pb_pick($pdo, 'grave_hook', (string)$pick['slug'], true);
            if ($sk) { $style = (string)$sk['body']; pb_record_use($pdo, (string)$sk['skill_key'], (int)($pick['id'] ?? $pick['page_id'] ?? 0)); }
        } catch (Throwable $e) { error_log('playbook grave: ' . $e->getMessage()); }
        $sys = "You write the voiceover for a {$secs} second VERTICAL news-recap video (TikTok/Reels/Shorts) for a US Gen Z "
             . "internet-culture channel. THIS IS A GRAVE STORY (a death, serious violence or a serious human tragedy is "
             . "involved): the register is a measured, respectful news explainer. No hype, no drama-tease energy, no jokes. "
             . "HOOK (first sentence, <=12 spoken words): use this restrained factual archetype -> {$style} "
             . "No greeting, no intro, no channel name. "
             . "BEAT TEMPLATE after the hook: (1) one-sentence catch-up ONLY ('quick context:'), (2) the confirmed facts in "
             . "CHRONOLOGICAL order, one specific dated fact per sentence, calmly stated, (3) around the halfway mark the "
             . "re-hook is exactly this quiet register: 'then the story took a darker turn' (use that line verbatim when it "
             . "fits the facts), (4) where it stands RIGHT NOW - anything unproven framed as alleged/reportedly, "
             . "(5) ENDING LAW (absolute, non-negotiable): NEVER end with a debate question, never 'whose side are you on', "
             . "never any engagement bait. The final lines are a measured close: "
             . ($death
                 ? "first the exact sentence 'Our thoughts are with those affected.' then "
                 : "one short respectful closing sentence (no question), then ")
             . "the literal last line: 'The full dated timeline is on GenZHype dot com.' "
             . "SENTENCE CHAIN RULE: short, clear spoken sentences; connect with 'but', 'so' or 'then' - NEVER 'and then'. "
             . "SAY the story's natural search phrase once, early (e.g. 'the [name] case explained') - spoken words are "
             . "search-indexed. "
             . "NEVER: sarcasm, 'tea', gleeful tone, 'follow for part 2', 'like if', hashtags, invented facts, or any name "
             . "spelled unlike the receipts. "
             . $lenRule
             . 'Output STRICT JSON: {"hook":"3-7 word ON-SCREEN text hook, calm and factual (e.g. WHAT WE KNOW)",'
             . '"script":"the FULL voiceover as one paragraph, starting with the spoken hook sentence","broll":["4-6 '
             . 'stock-footage search phrases matching the story beats IN ORDER - concrete RESPECTFUL filmable scenes '
             . '(e.g. courthouse steps dawn, candle window night, empty stage lights, newspaper printing press), '
             . 'NEVER a person\'s name, never graphic imagery"]}.';
    } else {
    // CREATOR-PLAYBOOK TEMPLATE (researched + codified 2026-07-12, see memory
    // reference_video_creator_playbook): 60-90s master = TikTok-monetizable (60s+ gate)
    // + the documented >1min reach premium + Reels' best band. Hook archetype ROTATES
    // per topic (anti-template variation = the #1 monetization survival rule).
    $hookStyles = [
        'IN-MEDIAS-RES: open mid-conflict on the NEWEST, most explosive development — zero setup, as if the viewer walked in on the fight',
        'DECLARATIVE BOMB: "[Name] just [did the shocking thing] — and the receipts are worse." Named person + completed action + tension',
        'STAKES TEASE: lead with the consequence ("This one screenshot might end his career"), then reveal whose',
    ];
    $style = $hookStyles[crc32($pick['slug']) % 3];
    try {
        require_once __DIR__ . '/playbook.php';
        $sk = pb_pick($pdo, 'hook', (string)$pick['slug'], false);
        if ($sk) { $style = (string)$sk['body']; pb_record_use($pdo, (string)$sk['skill_key'], (int)($pick['id'] ?? $pick['page_id'] ?? 0)); }
    } catch (Throwable $e) { error_log('playbook hook: ' . $e->getMessage()); }
    // r12 HOOK ARSENAL (DIRECTOR-UPGRADE-RESEARCH.md §2 — MIT-licensed formula corpus,
    // rediumvex/viral-hooks-skill + our validated re-hooks). Rotation is pinned per slug
    // so no two consecutive videos open with the same formula.
    $arsenalOpeners = [
        'NEGATION: "Not [common belief]." (e.g. "Not the apology. The COMMENTS under the apology.")',
        'BELIEF-FLIP: "[Common belief] is a lie." (e.g. "His whole redemption arc is a lie, here are the receipts.")',
        'REAL-CAUSE: "It\'s not [common cause], it\'s [real cause]." (e.g. "It\'s not the video that ended him, it\'s the reply he posted at 2am.")',
        'HARD-NUMBER: "[Specific number]: that\'s what/how many [thing]." (e.g. "$4.3 million: that\'s what one deleted tweet just cost him.")',
        'EXACT-MOMENT: "[Date/time]: the exact moment [thing happened]." (e.g. "3:07am: the exact minute the apology dropped, and fans noticed why.")',
        'FORBIDDEN-SHARE: "I wasn\'t supposed to share this, but..." (fits leak-driven dramas)',
        'SILENCE-CALLOUT: "Nobody\'s talking about what [Name] deleted last night."',
        'FRESH-FIND: "Fans just found a [message/post/date] that changes everything."',
    ];
    $arsenalRehooks = [
        '"It gets worse."',
        '"That\'s not even the bad part."',
        '"But one date doesn\'t add up." (the receipts-brand re-hook)',
        '"But the comments found something worse."',
    ];
    $oPick = (crc32($pick['slug'] . '-arsenal') & 0x7fffffff) % count($arsenalOpeners);
    $rPick = (crc32($pick['slug'] . '-rehook') & 0x7fffffff) % count($arsenalRehooks);
    $oList = [];
    foreach ($arsenalOpeners as $i => $f) $oList[] = ($i + 1) . '. ' . $f;
    $rList2 = [];
    foreach ($arsenalRehooks as $i => $f) $rList2[] = ($i + 1) . '. ' . $f;
    $arsenal = 'HOOK ARSENAL (12 proven viral formulas; the rotation number changes every video so no two videos in a row may open the same way). '
             . 'OPENERS, your assigned rotation pick is #' . ($oPick + 1) . ': use it when it fits THIS story, otherwise the closest-fitting formula, never a forced bad match: '
             . implode(' | ', $oList) . '. '
             . 'RE-HOOKS (the mid-video turn), assigned pick #' . ($rPick + 1) . ': ' . implode(' | ', $rList2) . '. '
             . 'PAIRING RULE: the ON-SCREEN text hook uses a stop-scroll class (negation, number, question) while the SPOKEN first sentence uses a retention class (story, stakes); two different classes, never the same line twice. ';
    $sys = "You write the voiceover for a {$secs} second VERTICAL drama-recap video (TikTok/Reels/Shorts) for a US Gen Z "
         . "internet-culture channel, engineered like a top human creator. "
         . "HOOK (first sentence, <=12 spoken words): use this archetype -> {$style}. No greeting, no intro, no channel name. "
         . "BEAT TEMPLATE after the hook: (1) one-sentence catch-up ONLY ('quick context:'), (2) the receipts in RISING-stakes "
         . "order, one specific dated fact per sentence — open at 80% intensity and save the single biggest fact for near the END, "
         . "(3) plant ONE open loop early ('but that was just the start' / 'wait till you see the reply'), (4) a mid-video re-hook "
         . "around the halfway mark: use your assigned RE-HOOK from the HOOK ARSENAL below, or 'and this is where it gets messy' "
         . "only when it genuinely fits better, (5) where it stands RIGHT NOW — anything unproven framed "
         . "as alleged/reportedly, (6) END with a debate question the viewer must answer ('So whose side are you on?'), then the "
         . "literal last line: 'Full dated timeline on GenZHype dot com.' "
         . "SENTENCE CHAIN RULE: connect every beat with 'but', 'so' or 'then' — NEVER 'and then'. Short punchy spoken sentences. "
         . "SAY the story's natural search phrase once, early (e.g. 'the [names] drama explained') — spoken words are search-indexed. "
         . "NEVER: 'follow for part 2', 'like if', 'link in bio' mid-video, hashtags, invented facts, or any name spelled unlike the receipts. "
         . $lenRule
         . $arsenal
         . 'Output STRICT JSON: {"hook":"3-7 word ON-SCREEN text hook, ALL CAPS punch (e.g. CAUGHT LYING?)","script":"the FULL '
         . 'voiceover as one paragraph, starting with the spoken hook sentence","broll":["4-6 stock-footage search phrases '
         . 'matching the story beats IN ORDER — concrete filmable scenes (e.g. courtroom gavel, handcuffs arrest night, '
         . 'smartphone scrolling dark, airport police escort), NEVER a person\'s name"]}.';
    }
    $usr = "STORY: {$pick['title']}\nSUMMARY: " . $summaryTxt . "\n\nDATED RECEIPTS:\n" . ($beats ?: '(use the summary only)');
    // THE MEMORY (organ 04): the owner's approved rules reach the hands here.
    // Empty string when he has approved nothing - behaviour then is exactly
    // what it was before the Memory existed (fail closed).
    try { require_once __DIR__ . '/memory.php'; $sys .= memory_prompt_block($pdo, 'script', (int)($pick['id'] ?? $pick['page_id'] ?? 0)); }
    catch (Throwable $e) { error_log('memory block: ' . $e->getMessage()); }
    $res = ai_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]], ['gemini', 'openrouter', 'nvidia'], 0.7);
    if (isset($res['error'])) return null;
    $j = ai_json($res['content']);
    if (!$j || empty($j['script']) || empty($j['hook'])) return null;
    // canonical person names for the guard (people_json on the drama row).
    // v8: entity.php keeps only Wikidata-resolvable people, so small-creator
    // and death-topic dramas sit with people_json='[]' and the Director never
    // learned their names (no PERSON-LAW shots, no face resolution). The
    // resolver heals that: cached AI name extraction (CLI/cron only) + face
    // pre-warming, so the shotlist and the feed both know the people.
    // The AI call above can hold this process for minutes. MySQL's wait_timeout
    // expires on the idle connection while we wait, so the very next query throws
    // "2006 server has gone away" and the whole video step dies - it did, on every
    // tick since July 12. db_alive() pings and reconnects only if needed.
    $pdo = db_alive();
    $pj = $pdo->prepare("SELECT people_json FROM dramas WHERE id=?");
    $pj->execute([(int)$pick['did']]);
    $people = [];
    try {
        require_once __DIR__ . '/video_people.php';
        $ctx = (string)($pick['summary'] ?? '');
        if ($ctx === '') $ctx = (string)($pick['meta_desc'] ?? '');
        foreach (video_people_resolve($pdo, (int)$pick['id'], (string)$pj->fetchColumn(),
                                      (string)$pick['title'], $ctx) as $pp) {
            $people[] = $pp['name'];
        }
    } catch (Throwable $e) { /* names are an enhancement, never fatal */ }
    $row = [
        'page_id' => (int)$pick['id'], 'slug' => $pick['slug'], 'title' => $pick['title'],
        'hook' => mb_substr(video_fix_names(trim((string)$j['hook']), $people), 0, 190),
        'script' => video_trim_script_to_budget(
                        video_fix_names(trim((string)$j['script']), $people), $wHi),
        'image' => url($pick['cover']),
    ];
    $broll = array_slice(array_values(array_filter(array_map('trim', (array)($j['broll'] ?? [])))), 0, 6);
    $pdo->prepare("INSERT INTO video_scripts (page_id,slug,title,hook,script,image,tpl,broll,gravity) VALUES (?,?,?,?,?,?,2,?,?)
                   ON DUPLICATE KEY UPDATE hook=VALUES(hook), script=VALUES(script), image=VALUES(image), tpl=2,
                                           broll=VALUES(broll), gravity=VALUES(gravity)")
        ->execute([$row['page_id'], $row['slug'], $row['title'], $row['hook'], $row['script'], $row['image'],
                   $broll ? json_encode($broll, JSON_UNESCAPED_SLASHES) : null, $gravity]);
    $row['broll'] = $broll;
    $row['gravity'] = $gravity;
    // THE RECORD (organ 02): snapshot the plan. Observer only.
    try { require_once __DIR__ . '/record.php'; record_video_plan_for_page($pdo, (int)$row['page_id']); } catch (Throwable $e) {}
    // EVIDENCE ENGINE (2026-08-06): harvest the story's REAL platform clips
    // (TikTok/Twitch/Kick/YouTube embedded in its articles) BEFORE direction,
    // so the maker can play the actual artifact the script talks about. The
    // r28 gatherer existed but had NO call site — page 483 talked about a
    // TikTok whose URL sat in its own Variety source, unfetched. Never fatal.
    try {
        require_once __DIR__ . '/fetch_sources.php';
        $clips = footage_clips_gather((int)$pick['did']);
        if ($clips) error_log('video: gathered ' . count($clips)
                              . " story clip(s) for page {$pick['id']}");
    } catch (Throwable $e) { /* clips are an enhancement */ }

    // r74 VISUAL DIRECTOR — WIRED. It was written in r70 and then called from
    // NOWHERE: 1 story of 67 had a plan, and that one only because someone ran
    // it by hand. Every other story therefore searched a bare NAME, which is
    // how a fictional AI actress returned circuit boards and a streamer row
    // returned gingerbread houses. The plan turns the script into EVENT-shaped
    // queries ("Tom Brady Logan Paul Fanatics Fest NYC confrontation video")
    // that the clip hunter and image search actually need. Never fatal: on any
    // failure the plan stays empty and the helpers behave exactly as before.
    try {
        $plan = visual_director((int)$pick['did']);
        if ($plan) {
            $pdo->prepare("UPDATE video_scripts SET visual_plan=? WHERE page_id=?")
                ->execute([json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                           (int)$pick['id']]);
            error_log('video: visual plan written for page ' . $pick['id']
                      . ' (' . count($plan['clip_queries'] ?? []) . ' clip queries)');
        }
    } catch (Throwable $e) { /* direction is an enhancement, never a blocker */ }
    // v4 DIRECTOR pass: turn the fresh script into a word-anchored shot list
    $row['shotlist'] = video_write_shotlist($pdo, (int)$pick['id'], $people);
    return $row;
}

/**
 * v6 SHARED VISUALS EXTRACTOR: the drama's REAL event images (hero cover +
 * event YouTube thumbnails), with aligned human-readable titles. Used by BOTH
 * video_next.php (feed: visuals[] + visual_titles[]) and the Director
 * (numbered VISUALS list -> shot.visual_i), so index n means the SAME image
 * in both places. Deterministic order (sort_order, event_date, id).
 *
 * v8 DEPTH: the site's image engine already stores REAL images per drama on
 * disk — the article's inline gallery (/assets/memes/{slug}-gallery.json,
 * written by image_beast) and alternate cover renders ({slug}-face.webp /
 * {slug}-hero.webp / {slug}-featured.webp). Those are APPENDED after the
 * event thumbnails (append-only keeps every stored shotlist's visual_i
 * pointing at the same image), each titled honestly by what it is.
 */
function video_event_visuals(PDO $pdo, int $did, string $hero): array {
    $urls = []; $titles = [];
    if ($hero !== '') { $urls[] = $hero; $titles[] = 'cover photo of the story'; }
    $ev = $pdo->prepare("SELECT title, embed_html FROM events
                         WHERE drama_id=? AND embed_html<>''
                         ORDER BY sort_order, event_date, id LIMIT 8");
    $ev->execute([$did]);
    foreach ($ev->fetchAll() as $e) {
        if (preg_match('/videoid="([A-Za-z0-9_-]{11})"/', (string)$e['embed_html'], $vm)) {
            $u = 'https://i.ytimg.com/vi/' . $vm[1] . '/hqdefault.jpg';
            if (in_array($u, $urls, true)) continue;
            $t = trim(preg_replace('/\s+/u', ' ', (string)$e['title']) ?? '');
            $urls[] = $u;
            $titles[] = 'event thumbnail: ' . ($t !== '' ? mb_substr($t, 0, 120) : 'video from the story');
        }
    }
    // v8: images the SITE already associated with this drama (append-only).
    try {
        $sq = $pdo->prepare("SELECT p.slug FROM dramas d JOIN pages p ON p.id=d.page_id WHERE d.id=?");
        $sq->execute([$did]);
        $slug = (string)$sq->fetchColumn();
        if ($slug !== '') {
            $pub = dirname(__DIR__) . '/public_html';
            // the article's inline image gallery (real photos the page shows)
            $gf = $pub . '/assets/memes/' . $slug . '-gallery.json';
            if (is_file($gf)) {
                foreach ((array)json_decode((string)@file_get_contents($gf), true) as $g) {
                    $rel = (string)(is_array($g) ? ($g['img'] ?? '') : '');
                    if ($rel === '' || !is_file($pub . $rel)) continue;
                    $u = url($rel);
                    if (in_array($u, $urls, true)) continue;
                    $credit = trim((string)(is_array($g) ? ($g['credit'] ?? '') : ''));
                    // r25 (owner: "clean off the off-topic stills"): GIPHY/Tenor
                    // gallery images are REACTION GIFS — article decoration, not
                    // evidence. In a video they land as meaningless off-topic
                    // stills (a random desert / money / music-video frame over a
                    // "Twitch stream" line). Keep them off the video pool; real
                    // report photos + interview thumbnails + portraits remain.
                    if (preg_match('/giphy|tenor/i', $credit)
                        || preg_match('#/(giphy|tenor)[-_.]#i', $rel)) {
                        continue;
                    }
                    $urls[] = $u;
                    $titles[] = 'article inline image' . ($credit !== '' ? ' (' . mb_substr($credit, 0, 80) . ')' : '');
                    if (count($urls) >= 12) break;
                }
            }
            // alternate cover renders the image engine produced for this story
            foreach (['-face.webp' => 'real photo of the story\'s person (site cover render)',
                      '-hero.webp' => 'real story image (site cover render)',
                      '-featured.webp' => 'real story image (site featured render)'] as $suf => $label) {
                if (count($urls) >= 12) break;
                $rel = '/assets/covers/' . $slug . $suf;
                if (!is_file($pub . $rel)) continue;
                $u = url($rel);
                if (in_array($u, $urls, true)) continue;
                $urls[] = $u;
                $titles[] = $label;
            }
        }
    } catch (Throwable $e) { /* depth extras are never fatal to the feed */ }
    // v10 EXACT-MOMENT IMAGERY (owner round-10, from the competitor study): every
    // news article about an event carries its own photo of THAT moment (og:image) —
    // the party article has the party photo. These join the same numbered list, so
    // the Director can pin the exact moment's photo to the words describing it
    // (visual_i). Fetches are cached and CLI-only (web requests read cache).
    $pid = 0; $peopleJson = null;
    try {
        require_once __DIR__ . '/event_sources.php';
        $pq = $pdo->prepare("SELECT page_id, people_json FROM dramas WHERE id=?");
        $pq->execute([$did]);
        if ($prow = $pq->fetch()) { $pid = (int)$prow['page_id']; $peopleJson = (string)$prow['people_json']; }
        if ($pid) foreach (event_sources_for_page($pdo, $pid) as $es) {
            if (count($urls) >= 24) break;                 // r11: cap 16 -> 24
            $u = (string)($es['og_image'] ?? '');
            if ($u === '' || in_array($u, $urls, true)) continue;
            require_once __DIR__ . '/inspector.php';
            $dl = insp_chip((string)($es['date'] ?? ''), false);
            $d = $dl !== '' ? $dl . ' ' : '';
            $urls[] = $u;
            $titles[] = 'the actual moment (' . $d . 'report photo): '
                      . ($es['title'] !== '' ? $es['title'] : 'scene from the story');
        }
    } catch (Throwable $e) { /* never fatal */ }
    // r11 PER-PERSON DEPTH (owner round-11: "more images and persons" — a
    // 17-shot video ran on a 3-image pool). Each resolved person contributes
    // their RECENT REAL imagery: 3-4 recent thumbnails from their own verified
    // channel (identity-safe: name-matched + sub-gated), titled so the Director
    // can match image to words. Appended LAST (append-only keeps stored
    // visual_i stable), max ~8 additions, 7d-cached per person, CLI-only
    // lookups (web serves cache).
    try {
        require_once __DIR__ . '/video_people.php';
        $added = 0;
        if ($pid) foreach (vp_known_names($pid, $peopleJson, 3) as $nm) {
            foreach (video_person_recent_media($nm, 3, PHP_SAPI === 'cli') as $mrow) {
                if (count($urls) >= 24 || $added >= 8) break 2;
                if (in_array($mrow['url'], $urls, true)) continue;
                $urls[] = $mrow['url'];
                $titles[] = $mrow['title'];
                $added++;
            }
        }
    } catch (Throwable $e) { /* depth extras are never fatal */ }
    return [$urls, $titles];
}

/**
 * v4.5 fact-token extractor for the receipt BACKSTOP: crude-stemmed content
 * tokens (first 6 chars of words len>=5, minus glue words) weight 1; tokens
 * carrying digits ($2.7, 2026, 25) weight 2 — numbers are the fingerprint of
 * a dated fact. Deterministic, no AI.
 */
function video_fact_tokens(string $text): array {
    static $stop = ['about','after','their','there','would','could','which','while','these','those','being',
                    'again','against','other','because','before','under','where','still','since','later',
                    'allege','alleged','allegedly','report','reported','reportedly','stated','statement',
                    'follow','following','around','during','between','include','including'];
    $out = [];
    foreach (preg_split('/[^\p{L}\p{N}$.]+/u', mb_strtolower($text)) ?: [] as $t) {
        $t = trim($t, '.');
        if ($t === '') continue;
        if (preg_match('/\d/', $t)) { $out[$t] = 2; continue; }
        if (mb_strlen($t) < 5) continue;
        $stem = mb_substr($t, 0, 6);
        if (in_array($t, $stop, true) || in_array($stem, array_map(fn($s) => mb_substr($s, 0, 6), $stop), true)) continue;
        $out[$stem] = max($out[$stem] ?? 0, 1);
    }
    return $out;
}

/**
 * v5 POST-CARD PIN (deterministic, no AI): a REAL-POST card must sit on the
 * shot that SPEAKS its author's name ("Keri Hilson added a cautionary tweet"
 * shows @KeriHilson's tweet, not the next sentence). The Director gets this
 * roughly right but drifts by one shot; names are unambiguous, so we correct
 * mechanically: for each post card, find the first shot whose words contain
 * an author-name/handle token (len>=4) and pin the card there; any other shot
 * still holding that card is demoted to subject. Conservative: never steals a
 * shot that is already a receipt of a DIFFERENT card, never creates adjacent
 * duplicates of the same card.
 */
function video_pin_post_cards(array $shots, array $receipts, array $words): array {
    $phraseOf = function (array $s) use ($words): string {
        $p = ' ' . mb_strtolower(implode(' ', array_slice($words, $s['w_in'], $s['w_out'] - $s['w_in'] + 1))) . ' ';
        return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $p) ?? $p;
    };
    foreach ($receipts as $rc) {
        if (($rc['kind'] ?? 'event') !== 'post') continue;
        $idx = (int)$rc['idx'];
        $toks = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(($rc['author'] ?? '') . ' ' . ($rc['handle'] ?? ''))) as $t) {
            if (mb_strlen($t) >= 4) $toks[] = $t;
        }
        if (!$toks) continue;
        // where did the Director put this card (if anywhere)?
        $directed = null;
        foreach ($shots as $si => $s) {
            if ($s['shot_class'] === 'receipt' && (int)($s['receipt_i'] ?? -1) === $idx) { $directed = $si; break; }
        }
        if ($directed === null) {
            // r26 (owner: "Twitter needs to be a HUGE source — people would
            // admire screens of these tweets"): the Director SKIPPED this real
            // tweet. Don't lose it — FORCE-place it on the shot that best
            // speaks the author's name and/or a posting verb ("he tweeted /
            // posted / demanded"), so the receipt the audience wants actually
            // shows. Never steals a shot already holding another card.
            $best = null; $bestScore = 0;
            foreach ($shots as $si => $s) {
                if (($s['shot_class'] ?? '') === 'receipt') continue;
                $phrase = $phraseOf($s);
                $hasName = false;
                foreach ($toks as $t) {
                    if (strpos($phrase, ' ' . $t . ' ') !== false) { $hasName = true; break; }
                }
                $hasVerb = (bool)preg_match('/ (tweet|tweeted|tweets|posted|posts|wrote|replied|responded|announced|demanded|demanding|clapped|fired) /u', $phrase);
                $score = ($hasName ? 2 : 0) + ($hasVerb ? 1 : 0);
                if ($score > $bestScore) { $bestScore = $score; $best = $si; }
            }
            if ($best === null || $bestScore === 0) continue;   // nowhere sensible
            $shots[$best]['shot_class'] = 'receipt';
            $shots[$best]['receipt_i'] = $idx;
            $shots[$best]['query'] = '';
            $shots[$best]['person'] = null;
            $shots[$best]['visual_i'] = null;
            $shots[$best]['clip'] = false;
            error_log("video_pin_post_cards: FORCE-placed unused tweet card {$idx} (@{$rc['handle']}) at shot {$best} (score {$bestScore})");
            continue;
        }
        // shots that SPEAK the author's name
        $named = [];
        foreach ($shots as $si => $s) {
            $phrase = $phraseOf($s);
            foreach ($toks as $t) {
                if (strpos($phrase, ' ' . $t . ' ') !== false) { $named[] = $si; break; }
            }
        }
        if (!$named) continue;                     // name never spoken: leave as directed
        if (in_array($directed, $named, true)) continue;   // Director already on a name shot
        // closest name shot within +-2 of the Director's placement = the drift fix
        $target = null; $bestD = PHP_INT_MAX;
        foreach ($named as $si) {
            $d = abs($si - $directed);
            if ($d <= 2 && $d < $bestD) { $bestD = $d; $target = $si; }
        }
        if ($target === null) continue;            // no nearby name shot: don't second-guess across the video
        if ($shots[$target]['shot_class'] === 'receipt') continue;   // occupied by another card: never steal
        // adjacent-duplicate guard (same card twice in a row = frozen frame)
        foreach ([$target - 1, $target + 1] as $nb) {
            if ($nb !== $directed && isset($shots[$nb]) && $shots[$nb]['shot_class'] === 'receipt'
                && (int)$shots[$nb]['receipt_i'] === $idx) continue 2;
        }
        $shots[$directed]['shot_class'] = 'subject';                 // demote the drifted placement
        $shots[$directed]['receipt_i'] = null;
        $shots[$target]['shot_class'] = 'receipt';
        $shots[$target]['receipt_i'] = $idx;
        $shots[$target]['query'] = '';
        $shots[$target]['person'] = null;                            // v6: receipt wins the shot
        $shots[$target]['visual_i'] = null;
        $shots[$target]['clip'] = false;                             // r17
        error_log("video_pin_post_cards: moved post card {$idx} (@{$rc['handle']}) shot {$directed} -> {$target}");
    }
    return $shots;
}

/** r27 PROOF SPREAD (owner: "the more the video goes the more content they show,
 *  they don't keep repeating the same imgs"). The demon scraper gathers MANY
 *  distinct proofs, but the Director tends to pin the same 1-2 articles to every
 *  receipt shot. This deterministic pass reassigns the EVENT-receipt shots to
 *  WALK THROUGH every distinct-URL proof (dexerto -> xxl -> hiphopwired -> ...)
 *  before any repeats — so a 50s video reveals 6-8 DIFFERENT receipts as it runs
 *  instead of looping two. Post cards (tweets, placed on the author's name) and
 *  the final promo are left exactly where they are. */
function video_spread_receipts(array $shots, array $receipts): array {
    $kindOf = [];
    foreach ($receipts as $rc) $kindOf[(int)$rc['idx']] = $rc['kind'] ?? 'event';
    // ordered list of distinct-URL EVENT proofs (each = a unique screenshot)
    $distinct = []; $seenUrl = [];
    foreach ($receipts as $rc) {
        if (($rc['kind'] ?? 'event') !== 'event') continue;
        $u = trim((string)($rc['source_url'] ?? ''));
        if ($u === '' || isset($seenUrl[$u])) continue;
        $seenUrl[$u] = 1;
        $distinct[] = (int)$rc['idx'];
    }
    if (count($distinct) < 2) return $shots;               // nothing to spread
    // the EVENT-receipt shots, in timeline order (leave posts + promo alone)
    $eventShots = [];
    foreach ($shots as $si => $s) {
        if (($s['shot_class'] ?? '') !== 'receipt') continue;
        $ri = (int)($s['receipt_i'] ?? -1);
        if (($kindOf[$ri] ?? 'event') === 'event') $eventShots[] = $si;
    }
    if (count($eventShots) < 2) return $shots;
    // walk every distinct proof before repeating; never the SAME proof back-to-back
    $j = 0; $prev = null;
    foreach ($eventShots as $si) {
        $pick = $distinct[$j % count($distinct)];
        if ($pick === $prev && count($distinct) > 1) { $j++; $pick = $distinct[$j % count($distinct)]; }
        $shots[$si]['receipt_i'] = $pick;
        $prev = $pick; $j++;
    }
    error_log('video_spread_receipts: spread ' . count($eventShots)
              . ' receipt shot(s) across ' . count($distinct) . ' distinct proofs');
    return $shots;
}

/**
 * v4 DIRECTOR (see videorepos/V4-EDITOR-SPEC.md): a second AI pass that converts the
 * voiceover script into a word-anchored EDL — the "vertical editing" fix for the
 * owner's v3 verdict ("the watch was one word but its scene dragged"). Every shot is
 * glued to a word range; the maker maps word indexes -> exact ms via the TTS
 * WordBoundary timings it already collects. Deterministic validation clamps whatever
 * the model returns into a contiguous, law-abiding cut plan.
 */
function video_write_shotlist(PDO $pdo, int $pageId, array $people = []): ?array {
    $pdo = db_alive();
    $r = $pdo->prepare("SELECT script, title, gravity FROM video_scripts WHERE page_id=?");
    $r->execute([$pageId]);
    $row = $r->fetch();
    if (!$row || trim((string)$row['script']) === '') return null;
    $grave = (string)($row['gravity'] ?? 'standard') === 'grave';   // r16 GRAVITY
    $words = preg_split('/\s+/', trim($row['script']));
    $n = count($words);
    if ($n < 20) return null;
    $numbered = '';
    foreach ($words as $i => $w) $numbered .= "[$i]$w ";

    // v4.5 REAL-VISUALS: render/refresh the evidence cards BEFORE the AI call so
    // the Director can pin every spoken fact to its REAL receipt (idx-addressed).
    $receipts = [];
    try { $receipts = receipt_cards_for_page($pdo, $pageId); }
    catch (Throwable $e) { $receipts = []; }
    $rCount = count($receipts);
    $rList = ''; $postCount = 0; $promoIdx = null;
    foreach ($receipts as $rc) {
        // v5: REAL-POST cards (actual tweets rendered verbatim) are tagged so
        // the Director can pin "person/fans said X" narration to the REAL post.
        if (($rc['kind'] ?? 'event') === 'post') {
            $postCount++;
            $rList .= "[{$rc['idx']}] [REAL POST by @{$rc['handle']}] {$rc['event_date']}: \""
                    . mb_substr($rc['excerpt'], 0, 140) . "\"\n";
        } elseif (($rc['kind'] ?? 'event') === 'promo') {
            // v6: the ONLY branded card — final-CTA use only, never evidence
            $promoIdx = (int)$rc['idx'];
            $rList .= "[{$rc['idx']}] [PROMO CARD - GenZHype branded, NOT evidence]\n";
        } else {
            // r17: beige cards retired — an event receipt resolves maker-side
            // to a real article screenshot or the report's own photo. Same
            // semantics for the model: a dated proof shown on its fact.
            $rList .= "[{$rc['idx']}] [ARTICLE receipt: real screenshot or report photo] "
                    . "{$rc['event_date']}: " . mb_substr($rc['excerpt'], 0, 140) . "\n";
        }
    }

    // v6 DIRECTOR TASTE: the story's REAL event images, numbered for visual_i
    // (same extraction + order as the feed's visuals[], so index n = the same
    // image the maker downloads).
    $mq = $pdo->prepare("SELECT d.id did, p.cover FROM dramas d JOIN pages p ON p.id=d.page_id WHERE d.page_id=?");
    $mq->execute([$pageId]);
    $mrow = $mq->fetch();
    $visTitles = []; $visUrls = []; $sighted = false;
    if ($mrow) {
        [$visUrls, $visTitles] = video_event_visuals($pdo, (int)$mrow['did'],
                                                     $mrow['cover'] !== '' ? url($mrow['cover']) : '');
        // r14 SEEING PASS (owner: "the director doesn't really SEE what's going
        // on"): one batched Gemini-vision call describes every not-yet-seen pool
        // image (forever-cached per URL); the guessed labels become "SEEN: <what
        // is actually in the image> (origin)". Never fatal — on any failure the
        // guessed labels stand.
        try {
            require_once __DIR__ . '/visual_sight.php';
            $sight = visual_sight_describe($pdo, $visUrls);
            if ($sight) {
                $visTitles = visual_sight_titles($visUrls, $visTitles, $sight);
                $sighted = (bool)array_filter($visTitles, fn($t) => strpos((string)$t, 'SEEN: ') === 0);
            }
        } catch (Throwable $e) {
            error_log('video_write_shotlist: seeing pass failed (' . $e->getMessage() . '); guessed labels stand');
        }
    }
    // r17: visuals that ARE videos (YouTube thumbnails) get a [HAS VIDEO]
    // marker — the Director can ORDER the real moving clip there (clip:true).
    $vList = '';
    foreach ($visTitles as $vi => $vt) {
        $hasVid = isset($visUrls[$vi])
               && preg_match('#^https?://i\.ytimg\.com/vi/#i', (string)$visUrls[$vi]);
        $vList .= "[{$vi}] {$vt}" . ($hasVid ? ' [HAS VIDEO]' : '') . "\n";
    }
    $postLaw = $postCount
        ? "Cards tagged [REAL POST by @handle] are the person's ACTUAL social post rendered verbatim (a snapshot of "
        . "what they really said). LAW: whenever the narration quotes, paraphrases or references what a person or "
        . "the fans posted/tweeted/replied/said online, that shot MUST be THAT post's card (shot_class 'receipt' "
        . "with the post's number) - showing the real post while its words are spoken is the whole trust move; "
        . "stock or a face there is a failure. Match by content: use the post whose text says what the narration says. "
        : "";
    $receiptLaw = $rCount
        ? "THE #1 LAW - THE DRAMA-GENRE TRUST MOVE: 'receipt' = one of the numbered REAL evidence cards below shown "
        . "full-frame while its fact is spoken. Every shot whose words state a specific dated fact, claim, number, "
        . "quote or charge MUST be {\"shot_class\":\"receipt\",\"receipt_i\":<matching card number>}. In a receipts-"
        . "driven recap that is typically 4-7 of the shots - if your plan has fewer than 3 receipt shots you have "
        . "failed the assignment. Match card to words (do not show a receipt whose text says something else); reuse "
        . "a receipt_i only if the script returns to that same fact. Priority order: receipt > subject > broll. "
        . "EVERY specific mention (a post, a date, a filing, a statement, a number) must carry its proof ON those "
        . "exact words - if a proof exists for a mention and you show anything else, that is a directing failure. "
        . $postLaw
        : "";
    $sys = "You are a professional short-form video DIRECTOR/EDITOR cutting a 9:16 Gen Z drama recap. You get the "
         . "voiceover with every word numbered. Split the WHOLE script into consecutive SHOTS covering every word "
         . "exactly once (no gaps, no overlaps). "
         . ($grave
             ? "GRAVITY NOTE (overrides pacing/sfx defaults below) - THIS IS A GRAVE STORY (a death or serious human "
             . "tragedy): direct it like a measured news piece, not a hype edit. MEASURED PACING: shots span 5-8 words "
             . "(slower, calmer turnover). MINIMAL SFX: no 'riser' and no 'impact' unless the shot is a legal-reveal "
             . "moment (charges filed, an arrest, a verdict); soft 'pop' only when a dated document/receipt lands; most "
             . "shots sfx 'none'. RESPECTFUL TONE: no punch_hit snap zooms on words about human loss (prefer punch_build "
             . "or zoom_out there); the edit must never feel like it is enjoying the tragedy. "
             : "")
         . "THE LAWS: "
         . "(1) A shot spans 3-6 words (~1.2-2.4s of speech); 8 words is the hard max. A shot must die when its idea dies - short dense shots mean the screen turns over as fast as the facts do. "
         . "(2) shot_class: " . $receiptLaw
         . "'subject' = real photos of the actual people/story — use it for phrases that name a person, a role "
         . "(assistant, doctor, streamer) or a reaction, and whenever in doubt" . ($rCount ? " and no receipt matches" : "") . ". "
         . "Drama channels are ~80% receipts + real faces; generic stock is what betrays amateurs. 'broll' = stock "
         . "footage — LAST RESORT ONLY, max 1 shot in any 3, ONLY for a concrete impersonal object/place explicitly "
         . "spoken at that moment (a watch, a stadium, a courtroom) or a pure mood bridge. NEVER stock for people, "
         . "roles, or events — a stock 'assistant' clip about MrBeast's assistant is the exact failure we fired the "
         . "last editor for. "
         . "(3) broll query = concrete OBJECT + SHOT-TYPE + MOOD ('luxury watch macro velvet', 'police car night rain', "
         . "'smartphone scrolling dark close up'). When the phrase names a concrete THING, show THAT thing exactly on "
         . "those words (literal). For emotion/stakes phrases use a conceptual shot that embodies the feeling. Queries "
         . "must be REAL-WORLD FILMABLE scenes that exist on a stock site: a camera pointed at a physical thing/person/"
         . "place. NEVER: UI mockups, 'animation', 'overlay', 'graphic', 'montage', 'collage', 'split screen', text "
         . "renders; never abstract nouns ('betrayal','controversy'); never office/handshake/boardroom cliches. "
         . "(4) motion — never the same twice in a row: punch_hit (snap zoom on the key word), punch_build (slow ease in), "
         . "zoom_out, pan_left, pan_right. "
         . "(5) SFX budget 3-5 TOTAL for the whole video: 'whoosh' on major story-beat changes; exactly ONE 'riser' on "
         . "the shot BEFORE the biggest reveal and 'impact' ON the reveal shot; 'pop' when a receipt/specific damning "
         . "fact lands; otherwise 'none'. "
         . "(6) music: 'bed' default; 'silence' ONLY on the riser shot (kill the music before the bomb); 'duck' on the "
         . "reveal shot. "
         . "(7) emphasis_w = the single most important word index in the shot (the punch/caption accent lands there). "
         . "(8) PERSON LAW (a human editor's reflex): every shot carries \"person\" - when the shot's words SPEAK a "
         . "person's name from the PEOPLE list, that shot MUST be shot_class 'subject' with person set to that EXACT "
         . "name (copy the spelling) so the renderer shows THAT person's real photo on exactly those words" . ($rCount
             ? " (exception: if the shot is a receipt of what that person posted/said, the receipt card wins and person stays null)"
             : "") . ". person is null otherwise; NEVER a name outside the PEOPLE list. "
         . ($vList !== ''
             ? "(9) REAL-IMAGE / EXACT-MOMENT LAW (top drama channels live by this - the caption should translate "
             . "what is ON SCREEN): the story's REAL images are numbered under VISUALS with descriptive titles, "
             . "including 'the actual moment' report photos of specific events and recent photos of each person. "
             . "When the narration describes a specific moment, scene or event (the party, the arrest, the "
             . "restaurant, the stream, the match), you MUST show THAT exact moment: set \"visual_i\" to that "
             . "image's number (subject shots only). EVERY subject shot MUST set either \"person\" or \"visual_i\" - "
             . "a subject shot with neither is lazy directing and will be rejected; choose the image whose "
             . "description matches what the words are saying RIGHT THEN. NO-REPEAT LAW: the same visual_i may not "
             . "appear twice within any 4 consecutive shots, and never more than 3 times in the whole video - with "
             . "this many real images, showing the same one again while the story moves on is a directing failure. "
             . "PLANNED-CLIP LAW (a DIRECTOR DECISION, never luck): VISUALS entries marked [HAS VIDEO] are moments "
             . "that exist as real video. When the narration DESCRIBES a moment that HAS a video - something that "
             . "happened on stream, a quote said on camera, a match/event whose thumbnail is marked [HAS VIDEO] - "
             . "the shot SHOULD pin that visual_i AND set \"clip\": true: an explicit order to play the real MOVING "
             . "clip of that exact moment right there (a few muted seconds under the narration). Set clip on the "
             . "shot where the moment is DESCRIBED, not merely name-dropped; \"clip\" is false everywhere else and "
             . "only means something on a shot pinned to a [HAS VIDEO] visual. "
             . ($sighted
                 ? "VISUALS descriptions marked 'SEEN:' come from actually LOOKING at each image - trust them; "
                 . "match what the image SHOWS to what the words SAY. "
                 : "")
             : "")
         . ($promoIdx !== null
             ? "(10) PROMO LAW: card [{$promoIdx}] is OUR OWN branded promo card - it is NOT evidence and using it "
             . "mid-video is a failure. Use receipt_i {$promoIdx} ONLY on the literal final CTA shot ('Full dated "
             . "timeline on GenZHype dot com') or a shot whose spoken words say 'GenZHype'. The FINAL shot MUST be "
             . "that promo card when the script ends with the GenZHype CTA line. "
             : "")
         . 'Output STRICT JSON: {"shots":[{"w_in":0,"w_out":6,"shot_class":"subject","receipt_i":null,"person":null,'
         . '"visual_i":null,"clip":false,"query":"","motion":"punch_build","emphasis_w":3,"sfx":"none","music":"bed",'
         . '"why":"max five words"}'
         . ($rCount ? ',{"w_in":7,"w_out":13,"shot_class":"receipt","receipt_i":2,"person":null,"visual_i":null,'
                    . '"clip":false,"query":"","motion":"punch_build","emphasis_w":9,"sfx":"pop","music":"bed",'
                    . '"why":"dated charge on screen"}' : '')
         . ']}';
    $usr = "STORY: {$row['title']}\nPEOPLE: " . (implode(', ', $people) ?: '(none)')
         . ($rList ? "\nRECEIPTS (real numbered evidence cards, dated):\n{$rList}" : '')
         . ($vList ? "\nVISUALS (real numbered story images):\n{$vList}" : '')
         . "\nNUMBERED SCRIPT ({$n} words):\n{$numbered}";
    // v7: the Director gets its DEDICATED reasoning brain first (own NVIDIA key
    // + quota, deepseek-v4-pro); the shared chain stays behind it as fallback.
    // r24: the director's deepseek-v4-pro reasoning generation (up to 16k tokens)
    // routinely runs 2-4min under NIM load; the default 120s timeout was cutting
    // it off and cascading through every fallback (~500s total failure, planless
    // renders). This is CLI/cron only (no web caller to block), so give the brain
    // the room it needs — 300s per provider.
    // THE MEMORY (organ 04): the owner's approved rules reach the hands here.
    // Empty string when he has approved nothing - behaviour then is exactly
    // what it was before the Memory existed (fail closed).
    try { require_once __DIR__ . '/memory.php'; $sys .= memory_prompt_block($pdo, 'director', $pageId); }
    catch (Throwable $e) { error_log('memory block: ' . $e->getMessage()); }
    $res = ai_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
                   ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.4, 300);
    if (isset($res['error'])) {
        error_log("video_write_shotlist: ai error for page {$pageId}: " . json_encode($res['error']));
        return null;
    }
    error_log("video_write_shotlist: director brain = {$res['provider']}/{$res['model']} for page {$pageId}");
    // The AI call above can hold this process for minutes. MySQL's wait_timeout
    // expires on the idle connection while we wait, so the very next query throws
    // "2006 server has gone away" and the whole video step dies - it did, on every
    // tick since July 12. db_alive() pings and reconnects only if needed.
    $pdo = db_alive();
    $j = ai_json($res['content']);
    $shots = $j['shots'] ?? null;
    if (!is_array($shots) || count($shots) < 4) {
        error_log("video_write_shotlist: unusable director JSON for page {$pageId} ("
                  . (is_array($shots) ? count($shots) . ' shots' : 'no shots array') . '): '
                  . mb_substr((string)$res['content'], 0, 300));
        return null;
    }

    // deterministic validation: sort, clamp, force contiguous coverage, enforce budgets
    usort($shots, fn($a, $b) => (int)($a['w_in'] ?? 0) <=> (int)($b['w_in'] ?? 0));
    $clean = []; $cursor = 0; $sfxUsed = 0; $riser = false; $lastMotion = ''; $lastReceipt = null;
    $motions = ['punch_hit', 'punch_build', 'zoom_out', 'pan_left', 'pan_right'];
    foreach ($shots as $s) {
        if ($cursor >= $n) break;
        $in  = $cursor;                                            // force contiguity
        $out = min($n - 1, max($in + 2, min((int)($s['w_out'] ?? $in + 5), $in + 7)));
        $rawClass = (string)($s['shot_class'] ?? '');
        // v4.5: 'receipt' accepted when cards exist; receipt_i clamped to range;
        // an invalid/absent receipt reference degrades to 'subject'.
        if ($rawClass === 'receipt' && $rCount > 0) {
            $class = 'receipt';
            $ri = max(0, min($rCount - 1, (int)($s['receipt_i'] ?? 0)));
            if ($ri === $lastReceipt) { $class = 'subject'; $ri = null; }   // same card twice in a row = a frozen frame
        } else {
            $class = $rawClass === 'broll' ? 'broll' : 'subject';
            $ri = null;
        }
        $lastReceipt = $ri;
        if (!$clean && $class === 'broll') $class = 'subject';     // law: hook is never stock (a receipt IS owned)
        // HARD CAP (owner verdict: off-topic stock is the #1 tell): stock is last
        // resort — never two in a row, never more than ~1/3 of all shots so far.
        if ($class === 'broll') {
            $prevBroll = $clean && end($clean)['shot_class'] === 'broll';
            $brollSoFar = count(array_filter($clean, fn($c) => $c['shot_class'] === 'broll'));
            if ($prevBroll || ($brollSoFar + 1) > (int)ceil((count($clean) + 1) / 3)) $class = 'subject';
        }
        // SAFETY WALL (owner round-4: keyword search put a BLM-protest clip on an
        // unrelated story — brand-killing). Charged/human-context stock is BANNED:
        // keyword search cannot be trusted with people or sensitive imagery. Also
        // kills abstract-concept queries ("demanding accountability" -> protest).
        if ($class === 'broll') {
            $q = ' ' . mb_strtolower((string)($s['query'] ?? '')) . ' ';
            static $bannedQ = ['protest','rally','march','riot','crowd','police','flag','racis','black lives','blm',
                'religio','church','mosque','temple','war','soldier','military','gun','blood','funeral','grave',
                'child','kid','baby','school','hospital','patient','doctor','nurse','fan ','fans','audience',
                'people','person','man ','woman','girl','boy','face','activis','politic','vote','demand','account',
                'sponsor','communit','reaction','angry','sad ','crying'];
            if (trim($q) === '') $class = 'subject';
            else foreach ($bannedQ as $bq) { if (strpos($q, $bq) !== false) { $class = 'subject'; break; } }
        }
        // v6 PERSON LAW validation: person must be one of the known names
        // (exact case-insensitive, or a single name token like "Freddy" for
        // "Freddy Alvarez") else it is DROPPED. Receipt wins over person; a
        // person on a broll shot forces subject (stock people are banned).
        $person = trim((string)($s['person'] ?? ''));
        $matched = null;
        if ($person !== '' && $people) {
            foreach ($people as $pName) {
                if (strcasecmp($person, trim((string)$pName)) === 0) { $matched = trim((string)$pName); break; }
            }
            if ($matched === null) {
                foreach ($people as $pName) {
                    foreach (preg_split('/\s+/', trim((string)$pName)) ?: [] as $tok) {
                        if (mb_strlen($tok) >= 3 && strcasecmp($person, $tok) === 0) { $matched = trim((string)$pName); break 2; }
                    }
                }
            }
        }
        if ($matched !== null && $class === 'broll') $class = 'subject';
        if ($class !== 'subject') $matched = null;

        // v6 REAL-IMAGE LAW validation: visual_i clamped into range; subject
        // shots only; a person pin outranks it (the name law is the owner's
        // explicit demand).
        $vi = $s['visual_i'] ?? null;
        $vCount = count($visTitles);
        if ($vi !== null && $vi !== '' && is_numeric($vi) && $vCount > 0
            && $class === 'subject' && $matched === null) {
            $vi = max(0, min($vCount - 1, (int)$vi));
            // r22 (filmstrip verdict: the romance-pendant COVER reached the
            // screen through a Director PIN, bypassing the fallback ban):
            // designed covers are UNPINNABLE — real photos and moments only.
            if (stripos((string)($visTitles[$vi] ?? ''), 'cover photo of the story') !== false
                || stripos((string)($visTitles[$vi] ?? ''), 'cover render') !== false) {
                $vi = null;
            }
        } else {
            $vi = null;
        }

        // r17 PLANNED CLIP: an explicit Director order to play the real
        // moving clip of a described moment. Only meaningful on a shot whose
        // pinned visual is a YouTube thumbnail (the only visuals the maker
        // can fetch footage for) — clamped false everywhere else.
        $clip = false;
        if (!empty($s['clip']) && $vi !== null
            && preg_match('#^https?://i\.ytimg\.com/vi/#i', (string)($visUrls[$vi] ?? ''))) {
            $clip = true;
        }

        $motion = in_array($s['motion'] ?? '', $motions, true) ? $s['motion'] : 'punch_build';
        if ($motion === $lastMotion) $motion = $motions[(array_search($motion, $motions, true) + 1) % count($motions)];
        $lastMotion = $motion;
        $sfx = in_array($s['sfx'] ?? '', ['whoosh', 'riser', 'impact', 'pop'], true) ? $s['sfx'] : 'none';
        if ($sfx === 'riser') { if ($riser) $sfx = 'none'; else $riser = true; }
        if ($sfx !== 'none' && $sfx !== 'impact' && $sfxUsed >= 5) $sfx = 'none';
        if ($sfx !== 'none') $sfxUsed++;
        $music = in_array($s['music'] ?? '', ['bed', 'silence', 'duck'], true) ? $s['music'] : 'bed';
        if ($music === 'silence' && $sfx !== 'riser') $music = 'bed';   // silence only rides the riser
        $emph = (int)($s['emphasis_w'] ?? $in);
        $clean[] = [
            'w_in' => $in, 'w_out' => $out, 'shot_class' => $class,
            'receipt_i' => $ri,                                    // v4.5: evidence card index (receipt shots only)
            'person' => $matched,                                  // v6: exact known name -> that person's photo
            'visual_i' => $vi,                                     // v6: index into the feed's visuals[]
            'clip' => $clip,                                       // r17: planned real-footage order
            'query' => $class === 'broll' ? mb_substr(trim((string)($s['query'] ?? '')), 0, 90) : '',
            'motion' => $motion, 'emphasis_w' => max($in, min($out, $emph)),
            'sfx' => $sfx, 'music' => $music,
        ];
        $cursor = $out + 1;
    }
    // Tail coverage: a small remainder joins the last shot; a big one becomes
    // extra ~8-word subject shots (v6 fix: an under-split Director answer used
    // to freeze the final card on screen for 10+ seconds via the old
    // absorb-everything rule — a shot must still die when its idea dies).
    if ($cursor < $n && $clean) {
        if ($n - $cursor <= 4) {
            $clean[count($clean) - 1]['w_out'] = $n - 1;
        } else {
            while ($cursor < $n) {
                $out = min($n - 1, $cursor + 7);
                if ($n - 1 - $out <= 2) $out = $n - 1;           // no 1-2 word slivers
                $motion = $motions[(array_search($lastMotion, $motions, true) + 1) % count($motions)];
                $lastMotion = $motion;
                $clean[] = ['w_in' => $cursor, 'w_out' => $out, 'shot_class' => 'subject',
                            'receipt_i' => null, 'person' => null, 'visual_i' => null,
                            'clip' => false, 'query' => '', 'motion' => $motion,
                            'emphasis_w' => $cursor, 'sfx' => 'none', 'music' => 'bed'];
                $cursor = $out + 1;
            }
        }
    }
    if (count($clean) < 4) return null;

    // v6 PROMO DEMOTION (deterministic): the branded promo card is NEVER
    // evidence. Any non-final promo shot whose spoken words don't say
    // 'genzhype' is demoted to subject before the backstop runs.
    if ($promoIdx !== null) {
        $lastI = count($clean) - 1;
        foreach ($clean as $ci => $c) {
            if ($c['shot_class'] === 'receipt' && (int)$c['receipt_i'] === $promoIdx && $ci !== $lastI) {
                $phrase = mb_strtolower(implode(' ', array_slice($words, $c['w_in'], $c['w_out'] - $c['w_in'] + 1)));
                if (strpos($phrase, 'genzhype') === false) {
                    $clean[$ci]['shot_class'] = 'subject';
                    $clean[$ci]['receipt_i'] = null;
                    error_log("video_write_shotlist: demoted promo-as-evidence shot {$ci} for page {$pageId}");
                }
            }
        }
    }

    // v4.5 BACKSTOP (fix the system, not the instance): if the model ignored the
    // receipt class entirely, pin cards deterministically by lexical overlap
    // between each shot's spoken words and each card's REAL text. Conservative:
    // a wrong receipt is worse than a face, so a shot converts only on a clear
    // unique winner (score>=3 with a strict lead over the runner-up).
    if ($rCount && !array_filter($clean, fn($c) => $c['shot_class'] === 'receipt')) {
        $rTok = [];
        foreach ($receipts as $k => $rc) {
            if (($rc['kind'] ?? 'event') === 'promo') continue;   // v6: promo is never lexical evidence
            $rTok[$k] = video_fact_tokens($rc['excerpt'] . ' ' . $rc['event_date']);
        }
        $pinned = 0; $lastRi = null;
        foreach ($clean as $ci => $c) {
            $phrase = implode(' ', array_slice($words, $c['w_in'], $c['w_out'] - $c['w_in'] + 1));
            $pTok = video_fact_tokens($phrase);
            if (!$pTok) { $lastRi = null; continue; }
            $bestK = null; $bestS = 0; $secondS = 0;
            foreach ($rTok as $k => $tt) {
                $s = 0;
                foreach ($pTok as $tok => $w1) if (isset($tt[$tok])) $s += max($w1, $tt[$tok]);
                if ($s > $bestS) { $secondS = $bestS; $bestS = $s; $bestK = $k; }
                elseif ($s > $secondS) { $secondS = $s; }
            }
            if ($bestK !== null && $bestS >= 3 && $bestS > $secondS && $bestK !== $lastRi) {
                $clean[$ci]['shot_class'] = 'receipt';
                $clean[$ci]['receipt_i'] = $bestK;
                $clean[$ci]['query'] = '';
                $clean[$ci]['person'] = null;          // v6: receipt wins the shot
                $clean[$ci]['visual_i'] = null;
                $clean[$ci]['clip'] = false;           // r17: receipt kills the clip order
                $lastRi = $bestK;
                $pinned++;
            } else {
                $lastRi = null;
            }
        }
        if ($pinned) error_log("video_write_shotlist: backstop pinned {$pinned} receipt shot(s) for page {$pageId}");
    }

    // v5: deterministic post-card correction — the card of a REAL post rides
    // the shot that speaks its author's name (fixes the Director's +-1 drift).
    if ($rCount) $clean = video_pin_post_cards($clean, $receipts, $words);

    // r27: spread the event-receipt shots across EVERY distinct proof (the demon
    // scraper gathered many; the Director reused two) so proofs escalate and
    // never repeat. Runs AFTER post-card placement so tweets keep their anchor.
    if ($rCount) $clean = video_spread_receipts($clean, $receipts);

    // v6 FINAL-PROMO ENFORCEMENT (deterministic): if the last sentence speaks
    // the GenZHype CTA and the last shot is not the promo card, make it so.
    if ($promoIdx !== null && $clean) {
        $lastI = count($clean) - 1;
        $lastPhrase = mb_strtolower(implode(' ', array_slice(
            $words, $clean[$lastI]['w_in'], $clean[$lastI]['w_out'] - $clean[$lastI]['w_in'] + 1)));
        if (strpos($lastPhrase, 'genzhype') !== false
            && !($clean[$lastI]['shot_class'] === 'receipt' && (int)$clean[$lastI]['receipt_i'] === $promoIdx)) {
            $clean[$lastI]['shot_class'] = 'receipt';
            $clean[$lastI]['receipt_i'] = $promoIdx;
            $clean[$lastI]['query'] = '';
            $clean[$lastI]['person'] = null;
            $clean[$lastI]['visual_i'] = null;
            $clean[$lastI]['clip'] = false;            // r17
            error_log("video_write_shotlist: final CTA shot pinned to the promo card for page {$pageId}");
        }
    }

    // r11 NO-REPEAT LAW (deterministic): the same visual_i may not appear
    // twice within any 4 consecutive shots, and max 3 uses total per video.
    // Violations null the LATER visual_i — the renderer's LRU smart fallback
    // picks a fresh image there instead of freezing on a repeat.
    $viTotal = []; $viNulled = 0;
    foreach ($clean as $ci => $c) {
        if ($c['visual_i'] === null) continue;
        $vi = (int)$c['visual_i'];
        $windowDup = false;
        for ($k = max(0, $ci - 3); $k < $ci; $k++) {
            if ($clean[$k]['visual_i'] !== null && (int)$clean[$k]['visual_i'] === $vi) { $windowDup = true; break; }
        }
        if ($windowDup || ($viTotal[$vi] ?? 0) >= 3) {
            $clean[$ci]['visual_i'] = null;
            $clean[$ci]['clip'] = false;               // r17: no pin, no clip order
            $viNulled++;
        } else {
            $viTotal[$vi] = ($viTotal[$vi] ?? 0) + 1;
        }
    }
    if ($viNulled) error_log("video_write_shotlist: no-repeat law nulled {$viNulled} visual_i repeat(s) for page {$pageId}");

    // r11 OBSERVABILITY: subject shots pinned to nothing (no person, no
    // visual_i) are tolerated (the renderer's LRU fallback covers them) but
    // counted; >30% unpinned = the Director is being lazy — log + note it in
    // the shotlist meta so the trend is visible in the DB.
    $meta = null;
    $subj = array_filter($clean, fn($c) => $c['shot_class'] === 'subject');
    $unpinned = count(array_filter($subj, fn($c) => $c['person'] === null && $c['visual_i'] === null));
    if ($subj) {
        $pct = (int)round(100 * $unpinned / count($subj));
        if ($pct > 30) {
            $meta = ['warn' => 'lazy directing', 'unpinned_subject_pct' => $pct,
                     'unpinned' => $unpinned, 'subject_shots' => count($subj)];
            error_log("video_write_shotlist: WARNING {$pct}% of subject shots unpinned "
                    . "({$unpinned}/" . count($subj) . ") for page {$pageId}");
        }
    }

    $payload = ['words' => $n, 'shots' => $clean];
    if ($meta) $payload['meta'] = $meta;
    $pdo->prepare("UPDATE video_scripts SET shotlist=? WHERE page_id=?")
        ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES), $pageId]);
    return $clean;
}

/**
 * Re-template old-style scripts: rewrite NOT-YET-RENDERED (pending) scripts written
 * before the creator-playbook template (tpl<2) so no more videos render in the weak
 * v1 format. Bounded batch, runs from the cron until the backlog is converted.
 */
function video_scripts_retemplate(PDO $pdo, int $limit = 3): int {
    $pdo = db_alive();
    video_factory_install($pdo);
    $limit = max(1, min(10, $limit));
    $rows = $pdo->query("SELECT p.id, p.slug, p.cover, d.id did, d.title, p.summary, p.meta_desc
                         FROM video_scripts v
                         JOIN pages p ON p.id=v.page_id
                         JOIN dramas d ON d.page_id=p.id
                         WHERE v.video_status='pending' AND v.tpl < 2
                           AND p.status='published'
                         ORDER BY v.created_at DESC LIMIT {$limit}")->fetchAll();
    $n = 0;
    foreach ($rows as $r) { if (video_write_script($pdo, $r)) $n++; }
    return $n;
}

/**
 * THE EYES, part 1 — is this story even a VIDEO story? (2026-08-23)
 *
 * Born from watching video-700: a Twitch/Amazon lawsuit rendered as 36 seconds
 * of one stock POLISH courtroom (33% of all scenes), zero human faces, and the
 * machine's own judge scored it 8/10. The failure was not the render — it was
 * letting a story with no people into the video lane at all. Drama needs faces
 * and receipts; institutional news has neither, and no edit can fix that.
 *
 * Deterministic, reuses the resolvers we already trust (video_people_resolve,
 * receipt_cards_for_page — both cached), no new AI call. A refused page gets a
 * video_scripts row with video_status='skipped' + the reason, so it is visible
 * in the DB, excluded from every pending queue, and never retried in a loop
 * (the generate query only picks pages with NO row). To re-open one after
 * fixing its people/receipts: DELETE its video_scripts row.
 */
function video_story_videoable(PDO $pdo, int $pageId, int $did, string $title): array {
    require_once __DIR__ . '/video_people.php';
    require_once __DIR__ . '/receipt_cards.php';
    $pj = (string)$pdo->query("SELECT people_json FROM dramas WHERE id={$did}")->fetchColumn();
    $names = vp_names_from_json($pj);
    if (!$names) {
        return ['ok' => false, 'why' => 'no named people — institutional/news story, nothing to put on screen'];
    }
    $faces = 0;
    try {
        foreach (video_people_resolve($pdo, $pageId, $pj, $title, '') as $pe) {
            if (!empty($pe['photo'])) { $faces++; }
        }
    } catch (Throwable $e) { /* resolver down -> fall through to the face check */ }
    if ($faces < 1) {
        return ['ok' => false, 'why' => 'no face photo resolvable for ' . mb_substr(implode(', ', array_slice($names, 0, 3)), 0, 120)];
    }
    $rc = 0;
    try {
        foreach (receipt_cards_for_page($pdo, $pageId) as $r) {
            if (!empty($r['url']) || !empty($r['og_image'])) { $rc++; }
        }
    } catch (Throwable $e) {}
    if ($rc < 2) {
        return ['ok' => false, 'why' => "only {$rc} receipt visual(s) — the video would ride one stock still"];
    }
    return ['ok' => true, 'why' => "{$faces} face(s), {$rc} receipt visual(s)"];
}

/** Pre-generate scripts for the newest dramas that don't have one yet. Bounded batch (cron). */
function video_scripts_generate(PDO $pdo, int $limit = 2): int {
    $pdo = db_alive();   // callers may hand us a handle that timed out during their own AI work
    video_factory_install($pdo);
    $limit = max(1, min(10, $limit));
    $rows = $pdo->query("SELECT p.id, p.slug, p.cover, d.id did, d.title, p.summary, p.meta_desc
                         FROM dramas d JOIN pages p ON p.id=d.page_id
                         LEFT JOIN video_scripts v ON v.page_id=p.id
                         WHERE p.status='published' AND p.cover<>'' AND v.page_id IS NULL
                         ORDER BY p.published_at DESC LIMIT {$limit}")->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $g = video_story_videoable($pdo, (int)$r['id'], (int)$r['did'], (string)$r['title']);
        if (!$g['ok']) {
            try {
                $pdo->prepare("INSERT IGNORE INTO video_scripts (page_id,slug,title,hook,script,image,video_status,skip_reason)
                               VALUES (?,?,?,?,?,?,'skipped',?)")
                    ->execute([(int)$r['id'], (string)$r['slug'], (string)$r['title'], '', '', (string)$r['cover'], mb_substr($g['why'], 0, 200)]);
            } catch (Throwable $e) {}
            error_log("video gate: page {$r['id']} ({$r['slug']}) skipped — {$g['why']}");
            // THE RECORD (organ 02): snapshot the plan. Observer only.
            try { require_once __DIR__ . '/record.php'; record_video_plan_for_page($pdo, (int)$r['id']); } catch (Throwable $e) {}
            continue;
        }
        if (video_write_script($pdo, $r)) $n++;
        $pdo = db_alive();   // the writer just spent minutes in AI; revive before the next row
    }
    return $n;
}

/**
 * TIMELINE CONTRACT script generator (format pivot 2026-08-06).
 *
 * The format study's core finding: the system was never too dumb — the
 * FORMAT (free-narrative documentary) demanded editor intelligence it does
 * not have (the Director mis-placed artifacts against sentences). This
 * generator kills that failure class BY CONSTRUCTION: the script is built
 * FROM the drama's dated timeline rows, one event = one artifact = one
 * spoken sentence, and the word-anchored shot list is computed
 * deterministically as the script is assembled. The only AI involvement is
 * one bounded call compressing each event into a spoken sentence (fallback:
 * the event title verbatim). Artifact per beat is a DATA JOIN, in priority:
 * the event's own tweet card > its platform clip > its article receipt.
 * Writes script/hook/shotlist, tpl=3 (timeline era). Returns payload|null.
 */
function video_write_timeline_script(PDO $pdo, int $pageId): ?array {
    $meta = $pdo->prepare(
        "SELECT d.id did, d.people_json, v.title, v.gravity, v.footage_clips
         FROM video_scripts v JOIN dramas d ON d.page_id=v.page_id
         WHERE v.page_id=?");
    $meta->execute([$pageId]);
    $m = $meta->fetch();
    if (!$m) return null;

    $eq = $pdo->prepare(
        "SELECT e.id, e.title, e.description, e.event_date, e.embed_html,
                e.is_confirmed, s.url AS src_url
         FROM events e LEFT JOIN sources s ON s.id=e.source_id
         WHERE e.drama_id=? AND e.video_only=0 AND e.event_date IS NOT NULL
         ORDER BY e.event_date, e.sort_order LIMIT 9");
    $eq->execute([(int)$m['did']]);
    $events = $eq->fetchAll();
    if (count($events) < 4) {
        error_log("timeline script: page {$pageId} has <4 dated events; not a timeline story");
        return null;
    }

    // r82 CUT THE COAT TO THE CLOTH. Page 116 was judge-rejected four times
    // and the last two were pure arithmetic: a corrected 8-event timeline
    // became an 18-scene video carried by ~8 usable backgrounds, so
    // something HAD to repeat, and repetition is a hard fail. The story
    // length must fit the story's evidence. Supply is measured here, before
    // a single sentence is written:
    //   - distinct real visuals (thumbnails excluded — the maker strips
    //     them in timeline mode, so counting them would lie),
    //   - clips, weighted heavier (one clip covers beats a still cannot),
    //   - a screenshot expectancy per sourced event (~40% shoot cleanly).
    // Calibration comes from tonight's real verdicts: 443 PASSED at about
    // one scene per unit of supply; 116 FAILED at about double that. The
    // 0.52 factor sits on the passing side of those two data points.
    $supply = 0.0;
    try {
        [$visUrls, ] = video_event_visuals($pdo, (int)$m['did'], '');
        $real = array_filter($visUrls, fn($u) => !str_contains((string)$u, 'ytimg.com/vi'));
        $clips = json_decode((string)($m['footage_clips'] ?: '[]'), true);
        $nClips = is_array($clips) ? count($clips) : 0;
        $withSrc = count(array_filter($events, fn($e) => !empty($e['src_url'])));
        $supply = count(array_unique($real)) + 1.5 * min($nClips, 4) + 0.4 * $withSrc;
    } catch (Throwable $e) { $supply = 99; /* measurement failure never shrinks */ }
    $maxEvents = max(4, min(9, (int)floor(0.52 * $supply)));
    if (count($events) > $maxEvents) {
        // Keep the OPENING and the ENDING (the resolution is why the
        // timeline exists); rank the middle by evidential weight and keep
        // the strongest, in chronological order.
        $n = count($events);
        $mid = range(1, $n - 2);
        usort($mid, function ($a, $b) use ($events) {
            $score = fn($i) => (!empty($events[$i]['src_url']) ? 2 : 0)
                             + (!empty($events[$i]['embed_html']) ? 1 : 0);
            return [$score($b), $a] <=> [$score($a), $b];   // strong first, stable
        });
        $keep = array_merge([0, $n - 1], array_slice($mid, 0, $maxEvents - 2));
        sort($keep);
        $cut = $n - count($keep);
        $events = array_values(array_map(fn($i) => $events[$i], $keep));
        error_log("timeline script: page {$pageId} coat-to-cloth — supply "
                  . round($supply, 1) . " carries {$maxEvents} beats; kept "
                  . count($events) . " of {$n} events ({$cut} cut)");
        try {   // visible in the admin's "what the learning changed" table
            $pdo->prepare("INSERT INTO rule_apply_log (rule_key, page_id, what, before_val, after_val, at)
                           VALUES ('coat_to_cloth', ?, ?, ?, ?, UTC_TIMESTAMP())")
                ->execute([$pageId,
                    'shortened timeline to fit visual supply',
                    "{$n} events (supply " . round($supply, 1) . ")",
                    count($events) . ' events']);
        } catch (Throwable $e) { /* logging never blocks the script */ }
    }

    require_once __DIR__ . '/receipt_cards.php';
    $eventIdx = []; $postIdx = []; $promoIdx = null;
    foreach (receipt_cards_for_page($pdo, $pageId) as $rc) {
        if (($rc['kind'] ?? '') === 'event') {
            $eventIdx[mb_substr((string)$rc['excerpt'], 0, 60)] = (int)$rc['idx'];
        } elseif (($rc['kind'] ?? '') === 'post' && !empty($rc['tweet_id'])) {
            $postIdx[(string)$rc['tweet_id']] = (int)$rc['idx'];
        } elseif (($rc['kind'] ?? '') === 'promo') {
            $promoIdx = (int)$rc['idx'];
        }
    }

    // ONE bounded AI call: a spoken sentence per event. Any failure -> the
    // event title verbatim stands (the timeline still works, just drier).
    $list = '';
    foreach ($events as $e) {
        $flag = empty($e['is_confirmed']) ? ' [UNCONFIRMED]' : '';
        $list .= "[{$e['id']}]{$flag} {$e['event_date']} - "
               . mb_substr(trim((string)($e['description'] ?: $e['title'])), 0, 300) . "\n";
    }
    $grave = (string)$m['gravity'] === 'grave';
    $sys = 'You turn dated timeline events into ONE spoken sentence each for a fast faceless recap video. '
         . 'Rules: max 18 words per sentence, present tense, punchy but factual, NO dates inside the '
         . 'sentence (a date chip is on screen), keep real names, attribute claims ("per Variety"). '
         // THE INSPECTOR (organ 11): the publish gate forces alleged/reportedly
         // framing onto every unproven claim before a PAGE may go live. This
         // rewriter then compressed those same claims to 18 "punchy" words for
         // the VOICEOVER, and punchy is exactly the edit that deletes
         // "reportedly" - so the page passed the defamation shield and the
         // video that speaks the same claim about the same named person never
         // faced it. 2,445 of 2,504 stored events are unconfirmed.
         . 'CRITICAL: an event marked [UNCONFIRMED] is an ALLEGATION, not a fact. Its sentence '
         . 'MUST carry hedging ("allegedly", "reportedly", "claims", "according to X"). Never state '
         . 'an unconfirmed claim about a named person as flat fact, however punchy. '
         . ($grave ? 'GRAVE STORY: sober, respectful register, no hype words. ' : '')
         // r126 HOOK STUDY: viewers left at 0:01 because the opener restated
         // the title flatly. The spoken hook must OPEN A LOOP (tease the
         // consequence, withhold the resolution), and the screen text is its
         // own thing: 4-8 words, name first — TikTok's own guidance is 5-10
         // readable words per second, so a 30-word wall was never readable.
         . 'Output STRICT JSON: {"hook":"spoken opener, max 12 words: name the person, '
         . 'then tease the consequence or what is coming WITHOUT resolving it — an open '
         . 'loop, never a flat restatement of the title' . ($grave ? ' (sober register)' : '') . '",'
         . '"screen":"on-screen opener, 4 to 8 words TOTAL, MUST start with the main '
         . 'person or brand name, present tense, the sharpest fact or stake",'
         . '"s":{"<event_id>":"sentence",...}} with EVERY event id present.';
    // r114: the intelligence engine measures what separates rival videos that
    // BEAT their own channel median from ones that do not, and until now that
    // finding died in a database table. The hook is the one line where it can
    // still change the outcome, so the measured traits and the actual winning
    // titles go into the prompt. Grave stories are exempt: hype shape on a
    // death story is the wrong call whatever the numbers say. Engine silent or
    // low-confidence -> empty string -> the prompt is exactly what it was.
    if (!$grave && function_exists('social_title_guidance')) {
        $guide = social_title_guidance();
        if ($guide !== '') {
            $sys .= $guide;
            if (function_exists('social_style_log')) {
                social_style_log('title_shape+top_outliers', $pageId,
                                 'fed measured winning-title traits into the hook writer',
                                 'generic hook prompt', mb_substr(trim($guide), 0, 200));
            }
        }
    }
    // THE MEMORY (organ 04): the owner's approved rules reach the hands here.
    // Empty string when he has approved nothing - behaviour then is exactly
    // what it was before the Memory existed (fail closed).
    try { require_once __DIR__ . '/memory.php'; $sys .= memory_prompt_block($pdo, 'hook', $pageId); }
    catch (Throwable $e) { error_log('memory block: ' . $e->getMessage()); }
    $res = ai_chat([['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => "STORY: {$m['title']}\nEVENTS:\n{$list}"]],
                   ['gemini', 'openrouter', 'nvidia'], 0.4);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    $sent = (array)($j['s'] ?? []);
    // The AI call above can hold this process for minutes. MySQL's wait_timeout
    // expires on the idle connection while we wait, so the very next query throws
    // "2006 server has gone away" and the whole video step dies - it did, on every
    // tick since July 12. db_alive() pings and reconnects only if needed.
    $pdo = db_alive();

    // THE INSPECTOR ENFORCES IT. A prompt is a request; this is the check.
    // Any unconfirmed event whose rewritten sentence lost its hedging falls
    // back to the event's own description - the text the publish gate already
    // verified - rather than shipping an unframed allegation as narration.
    // Uses the site's single framing definition so page and video can never
    // drift apart.
    try {
        require_once __DIR__ . '/framing_repair.php';
        require_once __DIR__ . '/inspector.php';
        $unframed = 0;
        foreach ($events as $e) {
            if (!empty($e['is_confirmed'])) continue;
            $k = (string)$e['id'];
            $txt = trim((string)($sent[$k] ?? $sent[(int)$e['id']] ?? ''));
            if ($txt === '' || preg_match('/' . FR_FRAMING_RX . '/i', $txt)) continue;
            $desc = trim((string)$e['description']);
            $sent[$k] = (preg_match('/' . FR_FRAMING_RX . '/i', $desc) && $desc !== '')
                        ? mb_substr($desc, 0, 240)          // the gate-verified wording
                        : 'Reportedly, ' . lcfirst(rtrim($txt, '.')) . '.';
            $unframed++;
        }
        if ($unframed) {
            error_log("inspector: restored framing on {$unframed} unconfirmed beat(s) for page {$pageId}");
            insp_note($pdo, $pageId, '', 'alleged_framing', 'restored',
                      "{$unframed} unconfirmed beat(s) had their hedging stripped by the rewriter");
        }
    } catch (Throwable $e) { error_log('inspector framing: ' . $e->getMessage()); }
    $hookSpoken = trim((string)($j['hook'] ?? ''));
    if ($hookSpoken === '') $hookSpoken = $m['title'] . ': the full timeline, with receipts.';

    // r126: the on-screen opener. AI-provided 4-8 word name-first line; the
    // fallback trims the title to its first 8 words. Either way, NEVER the
    // old 'THE FULL TIMELINE: <entire headline>' wall — the brand line
    // already lives at the END of every video (the CTA shot).
    $screen = trim((string)($j['screen'] ?? ''));
    $sw = preg_split('/\s+/u', $screen) ?: [];
    if ($screen === '' || count($sw) < 2 || count($sw) > 9) {
        $screen = implode(' ', array_slice(preg_split('/\s+/u', (string)$m['title']) ?: [], 0, 8));
    }
    $screen = video_fix_names($screen, $people ?? []);

    // r96 THE ALLOWLIST WAS THE BUG. This accepted only tiktok/twitch/kick,
    // written when YouTube fetching was disabled from CI. VIDEO_YT_FETCH has
    // been "1" for a long time, and the situation has since REVERSED: TikTok
    // now serves our runners a challenge page (21 download attempts, every
    // impersonation/cookie/build combination, all failed) while YouTube and
    // plain CDN files download fine. Page 131 held 2 YouTube clips and a CDN
    // file embedded in its own source articles; this line filtered all three
    // out before the picker saw them, so every beat could only ever choose a
    // TikTok it would fail to download. Judging a clip by whether we can
    // actually play it belongs in the ranking below, not in a hardcoded list
    // that silently deletes the working sources.
    $clips = array_values(array_filter(
        (array)json_decode((string)$m['footage_clips'], true),
        fn($c) => is_array($c) && !empty($c['url'])
                  && in_array($c['platform'] ?? '',
                              ['tiktok', 'twitch', 'kick', 'youtube', 'file', 'facebook', 'instagram'], true)));
    $clipK = 0;

    $people = [];
    foreach ((array)json_decode((string)$m['people_json'], true) as $pp) {
        $nm = trim((string)(is_array($pp) ? ($pp['name'] ?? '') : $pp));
        if ($nm !== '') $people[] = $nm;
    }

    // CLIP IDENTITY JOIN (owner-directed 2026-08-06, run #241 lesson: a
    // commentary creator's TikTok rode the "Alex Cooper launches" beat, and
    // order-based assignment clustered clips at the start). New law: a clip
    // may ride a beat ONLY when its AUTHOR (parsed from the platform URL —
    // deterministic, no AI) IS one of the story's people or is named in that
    // beat's event text. Among matches, the clip embedded by THIS event's
    // own source article wins. No identity match anywhere = the clip stays
    // OUT of the video (fail closed). Position follows the beat's date —
    // clips land wherever their moment is, never "at the beginning" by rule.
    $normId = fn(string $x) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($x));
    $peopleNorm = array_values(array_filter(array_map($normId, $people)));
    $clipUsed = [];
    require_once __DIR__ . '/fetch_sources.php';   // fs_clip_author()
    // r84: the URLs this story actually cites — provenance whitelist for clips.
    $storySrcUrls = [];
    foreach ($events as $_e) {
        if (!empty($_e['src_url'])) $storySrcUrls[] = (string)$_e['src_url'];
    }
    $storySrcUrls = array_values(array_unique($storySrcUrls));
    $clipPick = function (array $e) use ($clips, &$clipUsed, $peopleNorm, $normId, $storySrcUrls): int {
        $evText = $normId((string)$e['title'] . ' ' . (string)$e['description']);
        $best = -1; $bestRank = -1;
        foreach ($clips as $ci => $c) {
            if (!empty($clipUsed[$ci])) continue;
            // r96: provenance BEFORE the author check. A YouTube clip or a
            // direct CDN file carries no handle in its URL, so $a is empty and
            // this line used to discard it outright — page 131 held 2 YouTube
            // clips and a CDN file embedded in its own source articles, threw
            // all three away here, bound two TikToks instead, and rendered
            // with no footage because TikTok blocks our runners. An author is
            // one way to prove a clip belongs; the story's own article
            // embedding it is another, and it was already trusted below.
            $src = (string)($c['src'] ?? '');
            $fromOurSource = $src !== '' && in_array($src, $storySrcUrls, true);
            $a = $normId((string)($c['author'] ?? fs_clip_author((string)$c['url'])));
            if (strlen($a) < 4 && !$fromOurSource) continue;
            $identity = in_array($a, $peopleNorm, true);
            foreach ($peopleNorm as $pn) {
                if ($identity) break;
                if (strlen($pn) >= 5
                        && (str_contains($a, $pn) || str_contains($pn, $a))) {
                    $identity = true;
                }
            }
            // r84 PROVENANCE TRUST. The identity rule alone made a clips-rich
            // story unusable: page 192 held 8 TikToks that ARE the story (the
            // hoax videos themselves plus the reactions), and only 1 attached,
            // because the handles (@ssoftblooms, @aliceangleoo...) are not the
            // one "person" the resolver found. Yet every one of those clips was
            // HARVESTED FROM THIS STORY'S OWN SOURCE ARTICLE — the article
            // embedded it as evidence, which is a stronger topical guarantee
            // than a name match. The gate exists to stop WRONG-PERSON footage;
            // a clip the story's own source chose to embed cannot be that.
            // Search-found clips (no src) still need identity, unchanged.
            if (!$identity && !$fromOurSource
                    && (strlen($a) < 5 || !str_contains($evText, $a))) continue;
            // r96 PREFER A CLIP WE CAN ACTUALLY GET. Measured 2026-08-11:
            // TikTok serves GitHub's runners a challenge page — twenty-one
            // download attempts across every impersonation, cookie and build
            // combination failed identically — so binding a beat to a TikTok
            // usually means that beat ends up with no footage at all. Page 131
            // held 2 YouTube clips and a direct CDN file it could have used
            // and chose two TikToks instead, then rendered with nothing.
            // This is a PREFERENCE, not an exclusion: a TikTok is still bound
            // when it is the only candidate, and the day TikTok opens up again
            // this simply stops mattering. Same-article provenance still wins
            // over platform — evidence first, convenience second.
            $sameArticle = (string)($c['src'] ?? '') !== ''
                && (string)($c['src'] ?? '') === (string)($e['src_url'] ?? '');
            $plat = strtolower((string)($c['platform'] ?? ''));
            // r97 MEASURED, not assumed. Facebook downloads fine from a runner (two real
            // URLs, 8.3MB and 21.6MB). Instagram answers "empty media response —
            // check if this post is accessible without being logged-in", so it is
            // the YouTube case (needs a login) and stays out until cookies exist.
            // TikTok refuses outright. I had listed Instagram here on a guess;
            // this line is now evidence.
            // r108: TikTok is GETTABLE again. The server resolves and downloads it, and
            // the bridge stages the file into the feed, so the runner never has to
            // reach TikTok at all. Instagram stays out — its account checkpoints.
            // r109 NOT ALL "DOWNLOADABLE" IS EQUAL. One bucket was hiding a
            // real difference in reliability:
            //   TIER 2 — the server fetches it and the bridge stages the FILE
            //            into the feed (tiktok), or it needs no login at all
            //            (facebook, a direct .mp4, twitch, kick). The runner
            //            cannot fail to have these: they are already there.
            //   TIER 1 — youtube. Works only while a cookie export is fresh,
            //            and those die within hours of a render using them.
            //   TIER 0 — instagram. The account checkpoints on first use.
            // Yung Miami bound three YouTube clips over its two staged TikToks
            // purely because both scored "gettable", and YouTube is the one
            // that stops working overnight.
            $tier = in_array($plat, ['tiktok', 'file', 'facebook', 'twitch', 'kick'], true) ? 2
                  : ($plat === 'youtube' ? 1 : 0);
            $gettable = $tier > 0;
            $rank = $tier * 2 + ($sameArticle ? 1 : 0);
            if (getenv("CLIP_DEBUG")) fwrite(STDERR, sprintf("      cand[%d] %-8s same=%d tier=%d rank=%d\n",$ci,$c["platform"]??"?",$sameArticle?1:0,$tier,$rank));
            if ($rank === 5) { if(getenv("CLIP_DEBUG")) fwrite(STDERR,"      -> PICK $ci (staged + same article)\n"); return $ci; }
            if ($rank > $bestRank) { $bestRank = $rank; $best = $ci; }
        }
        return $best;
    };

    $wc = 0; $shots = []; $parts = [];
    $push = function (string $text, array $shot) use (&$wc, &$shots, &$parts): void {
        $n = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        if ($n === 0) return;
        $shot['w_in'] = $wc;
        $shot['w_out'] = $wc + $n - 1;
        $wc += $n;
        $parts[] = trim($text);
        $shots[] = $shot;
    };

    $push($hookSpoken, ['shot_class' => 'subject', 'person' => $people[0] ?? null,
                        'motion' => 'punch_build', 'sfx' => 'none', 'music' => 'bed']);
    // r135 YEAR-AWARE CHIPS (owner watch session, page 116): chips printed
    // "JAN 26" with no year, so a timeline spanning Jan 2024 -> Dec 2025 read
    // as dates jumping BACKWARDS. When the beats cross a calendar year, or
    // none of them is from the current year, every chip carries its year.
    $chipYears = [];
    foreach ($events as $e) {
        // THE INSPECTOR: read the year from the string. strtotime('2024-00-00')
        // returns Nov 30 2023, which would register a SECOND year and force
        // 'M j, Y' onto every chip in a timeline that never left 2024.
        require_once __DIR__ . '/inspector.php';
        $yr = insp_year((string)$e['event_date']);
        if ($yr) $chipYears[$yr] = 1;
    }
    $chipFmt = (count($chipYears) > 1 || !isset($chipYears[(int)date('Y')]))
        ? 'M j, Y' : 'M j';
    $k = 0; $nEv = count($events);
    foreach ($events as $e) {
        $txt = trim((string)($sent[(string)$e['id']] ?? $sent[(int)$e['id']] ?? ''));
        if ($txt === '') $txt = rtrim(trim((string)$e['title']), '.') . '.';
        // r110 STOP THE WHOOSH ON EVERY CUT. This alternated whoosh/pop on
        // EVERY beat, so a 7-beat video fired seven effects — one per cut, at
        // half the volume of the voice. That is what makes it tiring to watch:
        // a real editor uses a sound to MARK something, and a sound that
        // happens every time marks nothing. Now: silence by default, one
        // whoosh at the first turn, and the riser/impact kept for the ending
        // where the story actually lands.
        $shot = ['shot_class' => 'subject', 'motion' => 'punch_build',
                 'sfx' => ($k === 1 ? 'whoosh' : 'none'), 'music' => 'bed',
                 'date' => insp_chip((string)$e['event_date'], $chipFmt === 'M j, Y')];
        // No chip beats a wrong chip: an undated/zero event ships without the key.
        if ($shot['date'] === '') unset($shot['date']);
        if ($k === $nEv - 2) { $shot['sfx'] = 'riser';  $shot['music'] = 'silence'; }
        if ($k === $nEv - 1) { $shot['sfx'] = 'impact'; $shot['music'] = 'duck'; }
        $shot["event_id"] = (int)$e["id"];          // carousel + clip-frame join key
        $shot["src_url"] = (string)($e["src_url"] ?? "");
        // ---- the artifact JOIN (priority: own clip > own tweet > article) --
        $tid = rc_tweet_id((string)($e['src_url'] ?? ''), (string)($e['description'] ?? ''),
                           (string)($e['embed_html'] ?? ''));
        $key = mb_substr(rc_norm((string)($e['description'] ?: $e['title'])), 0, 60);
        $ci = -1;
        // r89 CLIPS FIRST. The old order was tweet-card > clip, and a clip was
        // only even CONSIDERED when the event's wording happened to contain
        // "tiktok/video/clip/stream". Page 192 is sourced almost entirely from
        // tweets, so every beat became a still picture of a tweet and all EIGHT
        // of its clips went unused — a clips-first format with no clips in it.
        // A card is a photograph of a post; a clip is the thing that happened.
        // So: try the clip first, and let the card take the beats that have no
        // clip (which is what a card is for). The keyword test survives only
        // for clips matched by IDENTITY — a clip embedded in this very event's
        // own article needs no keyword to prove it belongs.
        $clipWordy = (bool)preg_match('/tiktok|video|clip|stream|posted|filmed|footage/i',
                                      $e['title'] . ' ' . $e['description']);
        if (getenv('CLIP_DEBUG')) fwrite(STDERR, sprintf("   EVENT %s wordy=%d  %s\n",
            $e['id'], $clipWordy ? 1 : 0, mb_substr((string)$e['title'], 0, 44)));
        $ownArticleClip = false;
        if (($ci = $clipPick($e)) >= 0) {
            $ownArticleClip = (string)($clips[$ci]['src'] ?? '') !== ''
                && (string)($clips[$ci]['src'] ?? '') === (string)($e['src_url'] ?? '');
            if (getenv('CLIP_DEBUG')) fwrite(STDERR, sprintf("      picked %d (%s) own=%d -> %s\n",
                $ci, $clips[$ci]['platform'] ?? '?', $ownArticleClip ? 1 : 0,
                (!$ownArticleClip && !$clipWordy) ? 'KILLED by keyword gate' : 'kept'));
            if (!$ownArticleClip && !$clipWordy) $ci = -1;   // identity match: keep the old proof
        } elseif (getenv('CLIP_DEBUG')) {
            fwrite(STDERR, "      no candidate at all\n");
        }
        if ($ci < 0 && $tid !== null && isset($postIdx[$tid])) {
            $shot['shot_class'] = 'receipt';
            $shot['receipt_i'] = $postIdx[$tid];
        } elseif ($ci >= 0) {
            $clipUsed[$ci] = true;
            $clipK++;
            $shot['clip_url'] = (string)$clips[$ci]['url'];
            error_log("timeline clip join: event {$e['id']} <- "
                      . ($clips[$ci]['author'] ?? '?') . " ("
                      . (($clips[$ci]['src'] ?? '') === ($e['src_url'] ?? '')
                         ? 'same-article' : 'identity') . ")");
            if (isset($eventIdx[$key])) {          // fetch-failure fallback
                $shot['shot_class'] = 'receipt';
                $shot['receipt_i'] = $eventIdx[$key];
            }
        } elseif (isset($eventIdx[$key])) {
            $shot['shot_class'] = 'receipt';
            $shot['receipt_i'] = $eventIdx[$key];
        }
        $push($txt, $shot);
        $k++;
    }
    $ctaShot = ['shot_class' => 'subject', 'motion' => 'zoom_out',
                'sfx' => 'whoosh', 'music' => 'bed'];
    if ($promoIdx !== null) {
        $ctaShot['shot_class'] = 'receipt';
        $ctaShot['receipt_i'] = $promoIdx;
    }
    // r136 THE CLOSING FEELING (owner: "a slick way to say it without making
    // it clear... move their emotion"). Berger & Milkman 2012: sharing is
    // driven by high-arousal feeling, and BOTH TikTok and Meta demote
    // explicit "like/share/comment" bait — so the last spoken line leaves a
    // feeling standing instead of asking for anything. The feeling is the
    // one the Producer judged this story to carry; unknown -> the plain
    // line exactly as before.
    $closeLine = '';
    try {
        require_once __DIR__ . '/producer.php';
        $emo = producer_emotion_for_page($pdo, $pageId)['emotion'] ?? '';
        $closers = [
            'anger'     => 'Read the order it happened in, then pick a side.',
            'amusement' => 'Somebody you know still gets this wrong.',
            'anxiety'   => 'This one is still moving.',
            'awe'       => 'The numbers look made up. They are not.',
        ];
        $closeLine = $closers[$emo] ?? '';
    } catch (Throwable $e) { $closeLine = ''; }
    $push(trim($closeLine . ' Every source and the full dated timeline: '
               . 'GenZHype dot com.'), $ctaShot);

    foreach ($shots as $i => &$s) { $s['i'] = $i; }
    unset($s);
    $payload = ['words' => $wc, 'shots' => $shots, 'meta' => ['timeline' => 1]];
    $pdo->prepare("UPDATE video_scripts SET script=?, hook=?, shotlist=?, tpl=3, broll=NULL WHERE page_id=?")
        ->execute([implode(' ', $parts),
                   // r126: 4-8 word name-first opener (see $screen above), not
                   // the full-headline caps wall that made viewers swipe at 0:01
                   mb_substr(mb_strtoupper($screen), 0, 90),
                   json_encode($payload, JSON_UNESCAPED_SLASHES), $pageId]);
    // THE RECORD (organ 02): snapshot the plan. Observer only.
    try { require_once __DIR__ . '/record.php'; record_video_plan_for_page($pdo, $pageId); } catch (Throwable $e) {}
    error_log("timeline script: page {$pageId} = " . count($events) . " beat(s), {$wc} words, "
              . $clipK . " clip beat(s)");
    return $payload;
}

/**
 * FORMAT B — "THE MOMENT" script generator (tpl=4, added 2026-08-08).
 *
 * Why a second format at all: three posts a day in ONE template is exactly
 * what TikTok's unoriginal-content filter punishes, and that filter is the
 * single biggest threat to a faceless recap channel. Format A (tpl=3) is the
 * full dated timeline with receipts. Format B is its opposite: ONE moment,
 * a hard hook, ~22 seconds, no timeline scaffolding.
 *
 * It is built around the money clip — the footage the reporter embedded at a
 * timestamp — because the hook research is blunt: ~1/3 of viewers leave inside
 * 3 seconds, so the event itself must be frame one. If a story has no clip we
 * still build it (the picker falls back to the best still), but clip stories
 * are the natural candidates.
 *
 * Structure (deliberately rigid so the AI cannot wander):
 *   hook      - max 9 words, states the shocking thing, no preamble
 *   what      - one sentence: what actually happened
 *   detail    - one sentence: the specific detail that makes it real
 *   reaction  - one sentence: what people/the parties did next
 *   payoff    - one sentence: why it matters / where the full story is
 *
 * Returns payload|null. Never touches tpl=3 rows unless asked.
 */
function video_write_moment_script(PDO $pdo, int $pageId): ?array
{
    $meta = $pdo->prepare(
        "SELECT d.id did, d.people_json, v.title, v.gravity, v.footage_clips
         FROM video_scripts v JOIN dramas d ON d.page_id=v.page_id
         WHERE v.page_id=?");
    $meta->execute([$pageId]);
    $m = $meta->fetch();
    if (!$m) return null;

    $people = [];
    foreach ((array)json_decode((string)$m['people_json'], true) as $p) {
        if (!empty($p['name'])) $people[] = (string)$p['name'];
    }
    $clips = (array)json_decode((string)($m['footage_clips'] ?? ''), true);

    // THE moment: prefer the event the reporter embedded footage for, else the
    // most substantial confirmed event.
    $ev = $pdo->prepare(
        "SELECT e.id, e.title, e.description, e.event_date, s.url AS src_url
         FROM events e LEFT JOIN sources s ON s.id=e.source_id
         WHERE e.drama_id=? AND e.video_only=0
         ORDER BY CHAR_LENGTH(COALESCE(e.description,'')) DESC
         LIMIT 6");
    $ev->execute([(int)$m['did']]);
    $events = $ev->fetchAll();
    if (!$events) return null;
    $moment = $events[0];

    $grave = (string)$m['gravity'] === 'grave';
    $sys = 'You write ONE-MOMENT scripts for a 22-second faceless news short. '
         . 'The viewer decides in 3 seconds, so the hook states the shocking thing '
         . 'immediately — no "in this video", no throat-clearing, no date. '
         . 'Rules: hook max 9 words. Each body sentence max 16 words, present tense, '
         . 'real names kept, claims attributed ("per Dexerto"). Total body under 60 words. '
         . ($grave ? 'GRAVE STORY: sober and respectful, no hype words. ' : '')
         . 'The "payoff" sentence MUST send the viewer to the site — it is the only '
         . 'call to action in the video (e.g. "Full timeline and every receipt on GenZHype"). '
         . 'Output STRICT JSON: {"hook":"...","what":"...","detail":"...",'
         . '"reaction":"...","payoff":"..."}';
    $ctx = "STORY: {$m['title']}\nTHE MOMENT: "
         . mb_substr(trim((string)($moment['description'] ?: $moment['title'])), 0, 500);
    $res = ai_chat([['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $ctx]],
                   ['gemini', 'openrouter', 'nvidia'], 0.5);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    // The AI call above can hold this process for minutes. MySQL's wait_timeout
    // expires on the idle connection while we wait, so the very next query throws
    // "2006 server has gone away" and the whole video step dies - it did, on every
    // tick since July 12. db_alive() pings and reconnects only if needed.
    $pdo = db_alive();

    $hook = trim((string)($j['hook'] ?? ''));
    if ($hook === '') $hook = rtrim(trim((string)$moment['title']), '.') . '.';
    $body = [];
    foreach (['what', 'detail', 'reaction', 'payoff'] as $k) {
        $t = trim((string)($j[$k] ?? ''));
        if ($t !== '') $body[$k] = $t;
    }
    if (!$body) {
        $body['what'] = rtrim(trim((string)($moment['description'] ?: $moment['title'])), '.') . '.';
    }
    $cta = 'Full timeline and every receipt on GenZHype.';
    if (($body['payoff'] ?? '') === '') {
        $body['payoff'] = $cta;
    } elseif (stripos($body['payoff'], 'genzhype') === false) {
        // TikTok/Shorts are a TRAFFIC engine, not the destination — every
        // Format B video has to name the site out loud, so a model that
        // wandered off the brief gets the CTA appended rather than dropped.
        $body['payoff'] = rtrim($body['payoff'], '. ') . '. ' . $cta;
    }

    // Word-anchored shots, same contract as tpl=3.
    $wc = 0; $shots = []; $parts = [];
    $push = function (string $text, array $shot) use (&$wc, &$shots, &$parts): void {
        $n = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        if ($n === 0) return;
        $shot['w_in'] = $wc; $shot['w_out'] = $wc + $n - 1; $wc += $n;
        $parts[] = trim($text); $shots[] = $shot;
    };

    // Beat 1 = THE CLIP. The maker's hook law opens on the money moment when a
    // clip exists; 'clip'=>true asks for it explicitly here.
    $push($hook, ['shot_class' => 'subject', 'person' => $people[0] ?? null,
                  'clip' => !empty($clips), 'motion' => 'punch_hit',
                  'sfx' => 'impact', 'music' => 'bed']);
    $order = ['what' => 0, 'detail' => 1, 'reaction' => 2, 'payoff' => 3];
    foreach ($order as $key => $i) {
        if (empty($body[$key])) continue;
        $push($body[$key], [
            'shot_class' => $key === 'payoff' ? 'receipt' : 'subject',
            'person'     => $people[$i % max(1, count($people))] ?? null,
            'motion'     => $i % 2 ? 'zoom_out' : 'punch_build',
            'sfx'        => $key === 'reaction' ? 'pop' : 'none',
            'music'      => 'bed',
        ]);
    }

    $script   = implode(' ', $parts);
    $shotlist = json_encode(['shots' => $shots], JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE video_scripts SET script=?, hook=?, shotlist=?, tpl=4,
                   broll=NULL WHERE page_id=?")
        ->execute([$script, $hook, $shotlist, $pageId]);
    // THE RECORD (organ 02): snapshot the plan. Observer only.
    try { require_once __DIR__ . '/record.php'; record_video_plan_for_page($pdo, $pageId); } catch (Throwable $e) {}
    return ['hook' => $hook, 'script' => $script, 'shots' => count($shots),
            'words' => $wc, 'tpl' => 4];
}
