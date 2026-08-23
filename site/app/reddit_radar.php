<?php
// GenZHype | REDDIT OPPORTUNITY RADAR (read-only, domain-safe).
// The external radar (GitHub Actions + PRAW, READ-ONLY) POSTs candidate threads
// from the drama/GenZ subreddits. This matches each thread to a PUBLISHED page
// (creator names, slang terms, drama keywords), scores the opportunity, and (on
// demand) drafts a natural, genuinely-helpful comment that cites our page as a
// SOURCE. A human reviews + posts it by hand.
//
// WHY human-in-the-loop: reading Reddit is 100% safe, but a DOMAIN flagged as spam
// on Reddit is auto-blocked across subs, ~permanently. Auto-posting a fresh site's
// links is the one move that burns the domain. So we automate only the FINDING and
// the DRAFTING -- the click that carries the risk stays human. All upside, no domain risk.

require_once __DIR__ . '/db.php';

/** One-time (idempotent) table for stored opportunities. */
function reddit_radar_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reddit_opps (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      reddit_id     VARCHAR(24)  NOT NULL UNIQUE,
      subreddit     VARCHAR(80)  NOT NULL,
      title         VARCHAR(500) NOT NULL,
      permalink     VARCHAR(600) NOT NULL,
      selftext      MEDIUMTEXT,
      author        VARCHAR(80),
      created_utc   INT UNSIGNED,
      num_comments  INT UNSIGNED DEFAULT 0,
      ups           INT DEFAULT 0,
      matched_page_id INT UNSIGNED,
      matched_slug  VARCHAR(200),
      matched_kind  VARCHAR(20),
      matched_url   VARCHAR(500),
      matched_title VARCHAR(300),
      match_terms   VARCHAR(400),
      match_score   INT DEFAULT 0,
      draft_comment MEDIUMTEXT,
      status        ENUM('new','done','dismissed') NOT NULL DEFAULT 'new',
      seen_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY st (status), KEY sc (match_score)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Common English homographs of slang/aliases that must NOT drive a match on their own. */
function reddit_term_ambiguous(string $t): bool {
    $t = strtolower(trim($t));
    if (mb_strlen($t) < 4) return true;   // too short = too noisy (e, gg, gas, mid, unc, pog, npc)
    // multi-char words that also have a mundane everyday meaning -> would false-match
    static $stop = ['uncle','cook','cooked','cooking','cracked','crack','shipping','ship',
                    'ratio','clip','clips','main','based','pull','pulled','nerf','gas','mid'];
    return in_array($t, $stop, true);
}

/**
 * Mega-famous public figures (politicians / global celebs) who appear in countless
 * unrelated threads. A niche creator-drama page should NOT match on their name alone
 * (it still matches on its specific keyword phrase). Our lane is creator drama, not politics.
 */
function reddit_name_too_common(string $n): bool {
    $n = strtolower(trim($n));
    static $stop = ['donald trump','trump','kamala harris','joe biden','biden','elon musk',
                    'barack obama','obama','hillary clinton','vladimir putin','putin','joe rogan'];
    return in_array($n, $stop, true);
}

/**
 * Build the match index from PUBLISHED, indexable pages. Each entry:
 * ['page_id','slug','kind','title','url','signals'=>[ ['text'=>.., 'weight'=>..], ... ]].
 * Signals are the distinctive things whose presence in a thread = a real opportunity.
 */
function reddit_page_signals(PDO $pdo): array {
    $idx = [];

    // DRAMAS -> people names (strongest) + the primary keyword phrase
    $dr = $pdo->query("SELECT p.id,p.slug,d.title,d.primary_kw,d.people_json
                       FROM dramas d JOIN pages p ON p.id=d.page_id
                       WHERE p.status='published' AND p.robots='index'")->fetchAll();
    foreach ($dr as $r) {
        $sig = [];
        foreach ((json_decode($r['people_json'] ?? '[]', true) ?: []) as $p) {
            $name = is_array($p) ? ($p['name'] ?? '') : (string)$p;
            $name = trim($name);
            if ($name === '' || reddit_name_too_common($name)) continue;   // skip Trump-tier flood names
            if (strpos($name, ' ') !== false && mb_strlen($name) >= 7)      $sig[] = ['text'=>$name, 'weight'=>6];  // full name
            elseif (mb_strlen($name) >= 6 && !reddit_term_ambiguous($name)) $sig[] = ['text'=>$name, 'weight'=>4];  // distinctive handle
        }
        $kw = trim((string)$r['primary_kw']);
        if ($kw !== '' && str_word_count($kw) >= 3) $sig[] = ['text'=>$kw, 'weight'=>5];
        if ($sig) $idx[] = ['page_id'=>(int)$r['id'],'slug'=>$r['slug'],'kind'=>'drama',
                            'title'=>$r['title'],'url'=>url('/drama/'.$r['slug'].'/'),'signals'=>$sig];
    }

    // TERMS -> the term + aliases (word-boundary, ambiguity-filtered)
    require_once __DIR__ . '/lanes.php';
    $tm = $pdo->query("SELECT p.id,p.slug,t.term,t.also_known_as,t.lane
                       FROM terms t JOIN pages p ON p.id=t.page_id
                       WHERE p.status='published' AND p.robots='index'")->fetchAll();
    $prefix = ['slang'=>'/slang/','meme'=>'/meme/','gaming'=>'/gaming/','music'=>'/hype/'];
    foreach ($tm as $r) {
        $sig = [];
        $cands = array_merge([$r['term']], (array)(json_decode($r['also_known_as'] ?? '[]', true) ?: []));
        foreach ($cands as $c) {
            $c = trim((string)$c);
            if ($c === '' || reddit_term_ambiguous($c)) continue;
            $w = (strpos($c, ' ') !== false || mb_strlen($c) >= 6) ? 4 : 3;
            $sig[] = ['text'=>$c, 'weight'=>$w];
        }
        if ($sig) {
            $pre = $prefix[$r['lane']] ?? '/slang/';
            $idx[] = ['page_id'=>(int)$r['id'],'slug'=>$r['slug'],'kind'=>'term',
                      'title'=>$r['term'],'url'=>url($pre.$r['slug'].'/'),'signals'=>$sig];
        }
    }
    return $idx;
}

/** Best page match for a thread. Returns null if below the opportunity threshold. */
function reddit_match_thread(array $index, string $title, string $selftext): ?array {
    $hay = ' ' . mb_strtolower($title . ' ' . mb_substr($selftext, 0, 1200)) . ' ';
    $best = null;
    foreach ($index as $pg) {
        $score = 0; $hits = [];
        foreach ($pg['signals'] as $s) {
            $needle = mb_strtolower($s['text']);
            $re = '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/u';
            if (preg_match($re, $hay)) { $score += $s['weight']; $hits[] = $s['text']; }
        }
        if (count($hits) > 1) $score += min(4, 2 * (count($hits) - 1));   // multi-signal bonus
        // threshold 3 = a single distinctive slang term (e.g. "rizz") qualifies; short
        // common words never reach here (filtered out by reddit_term_ambiguous above).
        if ($score >= 3 && (!$best || $score > $best['match_score'])) {
            $best = ['match_score'=>$score, 'match_terms'=>implode(', ', array_unique($hits))] + $pg;
        }
    }
    return $best;
}

/**
 * Ingest raw threads from the radar. Dedups by reddit_id, matches, stores hits.
 * $threads: [ ['id'(t3_..),'subreddit','title','permalink','selftext','author','created_utc','num_comments','ups'], ... ]
 */
function reddit_ingest_threads(PDO $pdo, array $threads): array {
    reddit_radar_install($pdo);
    $index = reddit_page_signals($pdo);
    $seen = 0; $matched = 0; $dupes = 0; $stale = 0;
    // only recent threads are actionable: you can't usefully comment on (or be seen in)
    // a months-old thread, and Reddit locks/archives old ones. Hard cutoff = 45 days.
    $cutoff = time() - 45 * 86400;
    $ins = $pdo->prepare("INSERT IGNORE INTO reddit_opps
        (reddit_id,subreddit,title,permalink,selftext,author,created_utc,num_comments,ups,
         matched_page_id,matched_slug,matched_kind,matched_url,matched_title,match_terms,match_score)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($threads as $t) {
        if (!is_array($t) || empty($t['id']) || empty($t['title'])) continue;
        $seen++;
        $cu = (int)($t['created_utc'] ?? 0);
        if ($cu && $cu < $cutoff) { $stale++; continue; }   // skip old/archived threads
        $m = reddit_match_thread($index, (string)$t['title'], (string)($t['selftext'] ?? ''));
        if (!$m) continue;
        $ins->execute([
            substr((string)$t['id'],0,24), substr((string)($t['subreddit']??''),0,80),
            mb_substr((string)$t['title'],0,500), substr((string)($t['permalink']??''),0,600),
            mb_substr((string)($t['selftext']??''),0,8000), substr((string)($t['author']??''),0,80),
            (int)($t['created_utc']??0), (int)($t['num_comments']??0), (int)($t['ups']??0),
            $m['page_id'], $m['slug'], $m['kind'], $m['url'], mb_substr($m['title'],0,300),
            mb_substr($m['match_terms'],0,400), $m['match_score'],
        ]);
        if ($ins->rowCount() > 0) $matched++; else $dupes++;
    }
    return ['seen'=>$seen, 'matched'=>$matched, 'duplicates'=>$dupes, 'stale'=>$stale];
}

/**
 * Draft a natural, genuinely-helpful Reddit comment for one opportunity, citing
 * our page as a source. Real-Redditor voice, one link, no hype/marketing tells.
 */
function reddit_draft_comment(PDO $pdo, int $oppId, bool $fast = false): array {
    require_once __DIR__ . '/ai.php';
    $o = $pdo->prepare("SELECT * FROM reddit_opps WHERE id=?");
    $o->execute([$oppId]); $opp = $o->fetch();
    if (!$opp) return ['error'=>'opportunity not found'];

    // gist of our matched page for grounding the comment
    $pg = $pdo->prepare("SELECT summary,meta_desc FROM pages WHERE id=?");
    $pg->execute([(int)$opp['matched_page_id']]); $page = $pg->fetch() ?: [];
    $gist = trim(($page['summary'] ?? '') . ' ' . ($page['meta_desc'] ?? ''));

    $sys = "You write a SINGLE Reddit comment as a normal, helpful user in a Gen Z / internet-culture "
        . "subreddit. Someone asked about a topic our site has a detailed, dated, sourced page on. Write a "
        . "genuinely useful reply that actually answers them in 2-4 short sentences, casual lowercase-ish Reddit "
        . "voice, THEN mention our page once as a source with the raw URL. HARD RULES: sound like a real person, "
        . "not marketing. No hype words, no emojis, no 'check out', no 'we', no hashtags. Don't oversell -- frame "
        . "the link as 'there's a full timeline/breakdown here if you want the receipts'. Never claim facts beyond "
        . "the gist provided. If the gist is thin, keep the answer short and honest. Output ONLY the comment text.";
    $usr = "SUBREDDIT: r/{$opp['subreddit']}\nTHREAD TITLE: {$opp['title']}\n"
        . ("" !== trim((string)$opp['selftext']) ? "THREAD BODY: " . mb_substr($opp['selftext'],0,600) . "\n" : "")
        . "OUR PAGE: {$opp['matched_title']}\nOUR PAGE URL: {$opp['matched_url']}\n"
        . "WHAT OUR PAGE COVERS (gist, do not exceed): " . mb_substr($gist,0,600);
    $msgs = [['role'=>'system','content'=>$sys],['role'=>'user','content'=>$usr]];
    // $fast (interactive web click): ONE quick provider + tight 22s timeout so the
    // request can never blow past the web server's ~60s cap (that was the 504). The
    // hourly cron pre-drafts every opportunity with the full robust path anyway, so a
    // fast miss just means "it'll be ready on the next refresh".
    $res = $fast
        ? ai_chat($msgs, ['openrouter'], 0.7, 22)
        : ai_chat($msgs, ['gemini','openrouter','nvidia'], 0.7);
    if (isset($res['error'])) return ['error'=>$res['error']];
    $txt = trim($res['content'] ?? '', " \n\"'");
    if ($txt === '') return ['error'=>'empty draft'];
    $pdo->prepare("UPDATE reddit_opps SET draft_comment=? WHERE id=?")->execute([$txt, $oppId]);
    return ['ok'=>true, 'comment'=>$txt];
}
