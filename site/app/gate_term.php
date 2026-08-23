<?php
// GenZHype | SLANG/entry publish gate. Structural quality bar a page must clear
// before auto-publish, PLUS a uniqueness sentinel so the library can never drift
// into templated sameness (the permanent anti scaled-content-abuse guard).
require_once __DIR__ . '/db.php';

// ===========================================================================
// SEO-BATCH-1 (2026-08-04) : THE TRUTH GATE
//
// Diagnosis (reports/seo-diagnosis-and-plan-2026-08-03.md): the site does not
// have an SEO problem, it has a TRUTH problem. This gate checked SEO hygiene and
// had no opinion on whether the page was true, so pages asserting invented
// etymology published cleanly. Proof, straight out of the database:
//
//   tung-tung-tung-sahur   first_seen = "debated"
//                          -> old check `strlen(first_seen) > 4` PASSED on it
//                          sources  = knowyourmeme.com/search?q=... (a SEARCH
//                                     url, not an article) + urbandictionary
//                          examples = invented sentences, no platform/handle/date
//
// The real origin is documented everywhere: a @noxaasht TikTok, 28 Feb 2025,
// 31M views. The page instead asserted that no origin can be pinpointed.
//
// So the gate now demands EVIDENCE, not prose:
//   1. a named originating artifact URL carrying a real DATE
//   2. >= 3 dated, attributed citations of the term in the wild (platform,
//      handle, date) — sightings, not invented example sentences
//   3. >= 2 sources that are not Urban Dictionary, not a wiki, and not another
//      slang SEO site (citing the commodity tier we compete against gives
//      Google nothing it does not already have)
//   4. no INVENTED NEGATIVES: the exact phrasing that produced the false pages
//
// Absence of evidence in the drafting context is not evidence of absence. A term
// with no retrievable origin goes back in the queue; it does not get written
// around.
// ===========================================================================

/** Idempotent: evidence columns the truth gate reads. Safe to call repeatedly. */
function gate_term_install(PDO $pdo): void {
    foreach ([
        "ADD COLUMN origin_url VARCHAR(500) NULL",     // the originating artifact
        "ADD COLUMN origin_date VARCHAR(32) NULL",     // its date, ANY precision
        "ADD COLUMN origin_date_src VARCHAR(500) NULL",// the source that STATES that date
        "ADD COLUMN origin_type VARCHAR(20) NULL",     // social_post|archive|official|video|lexicographic
        "ADD COLUMN citations LONGTEXT NULL",          // JSON [{platform,handle,date,url}]
    ] as $alter) {
        try { $pdo->exec("ALTER TABLE terms {$alter}"); } catch (Throwable $e) {}
    }
}

/**
 * Phrases that assert an absence the drafter cannot possibly have established.
 * This exact family of wording produced the tung-tung-tung-sahur fabrication,
 * where a single 31M-view TikTok is documented by both Wikipedia and Know Your
 * Meme. Matching is done on normalised text so punctuation cannot smuggle one
 * through.
 */
function gate_term_banned_negatives(): array {
    return [
        'no single creator can be credited',
        'no origin can be pinpointed',
        'cannot be pinpointed as the source',
        'spread through a decentralized process',
        'spread through a decentralised process',
        'no single viral video',
        'no clear originator',
        'no known originator',
        'origins are debated',
        'origin is unclear',
        'no one can be credited',
        'impossible to trace',
        'cannot be traced to a single',
        'no definitive origin',
    ];
}

/**
 * Sources at the commodity tier: they prove nothing Google does not have.
 *
 * SEO-BATCH-1 REVISION: this was a DOMAIN LIST and `slang--dict.com` walked
 * straight through it, because an exact-match list only catches spellings
 * somebody already thought of. A glossary site can mint a new domain in
 * minutes. So the list is now a SEED and the rule is a heuristic on the domain
 * NAME with separators stripped — slang--dict, slang_dict and slangdict all
 * collapse to the same token.
 */
function gate_term_is_commodity_source(string $url): bool {
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($h === '') return true;                       // unparseable = not credit
    if (str_contains($url, '/search?') || str_contains($url, '?q=')) return true; // a SEARCH url is not a source

    // seed list (exact hosts we already knew about)
    foreach (['urbandictionary.com', 'wikipedia.org', 'wiktionary.org', 'fandom.com',
              'wikihow.com', 'slang.net', 'slangwise.com', 'lendinglanguagelab.com',
              'dictionary.university', 'slangdefine.org', 'onlineslangdictionary.com',
              'knowyourmeme.com'] as $bad) {
        if ($h === $bad || str_ends_with($h, '.' . $bad)) return true;
    }

    // HEURISTIC: strip the TLD, then strip every separator, then look for the
    // vocabulary a glossary site advertises in its own name. Citing one of these
    // while competing against it hands Google nothing it does not already have.
    $name = preg_replace('/\.[a-z.]{2,10}$/', '', $h);      // drop the TLD
    $name = preg_replace('/[^a-z0-9]/', '', $name);          // slang--dict -> slangdict
    foreach (['slang', 'dict', 'meaning', 'urban', 'define', 'definition',
              'glossary', 'lingo', 'jargon', 'acronym', 'abbrev', 'thesaurus',
              'wordnik', 'idiom'] as $tok) {
        if (str_contains($name, $tok)) return true;
    }
    return false;
}

/**
 * SEO-BATCH-1 REVISION (owner arbitration, 2026-08-04):
 * THE DATE RULE NOW TESTS ATTRIBUTION, NOT PRECISION.
 *
 * The first version demanded month+year and rejected bare years. That measured
 * the wrong property. Compare what actually separates the fabrications from the
 * legitimate cases:
 *
 *   "mid-2010s (online forums & TikTok)"   vague AND unsourced -> FALSE
 *   "late 2010s"                           vague AND unsourced -> FALSE
 *   "by 2000, per Usenet postings to
 *    rec.games.computer.ultima.online"     vague BUT sourced   -> TRUE
 *   "a 2011 promotional video, Pogs
 *    Championship by Gutierrez"            vague BUT sourced   -> TRUE
 *
 * All four are imprecise. Precision is not the discriminator; ATTRIBUTION is.
 * And demanding precision actively INCENTIVISES fabrication: if the true answer
 * is "2011" and the gate insists on a month, the model's cheapest way to pass is
 * to invent one — manufacturing the exact failure this gate exists to prevent.
 * Measured cost of the old rule: gaming recall 0/8, because every well-sourced
 * gaming origin is dated to a year.
 *
 * So this function now asks only "is this date-SHAPED at all" (any precision).
 * Whether it is TRUE is decided by gate_term_date_is_attributed(), which
 * requires a citable source URL stored alongside the claim. "mid-2010s gaming
 * forums" still fails — not for being vague, but because nothing sources it.
 * The banned-phrase list is unchanged.
 */
function gate_term_is_real_date(?string $d): bool {
    $d = trim((string)$d);
    if ($d === '') return false;
    return (bool)preg_match('/\b(19|20)\d{2}\b/', $d);   // any precision, must name a year
}

/** A date claim is only as good as the source that states it. */
function gate_term_date_is_attributed(?string $date, ?string $srcUrl): bool {
    if (!gate_term_is_real_date($date)) return false;
    return filter_var(trim((string)$srcUrl), FILTER_VALIDATE_URL) !== false;
}

/**
 * Hosts an artifact of each TYPE must actually live on.
 * The host check is the strongest thing in this gate — a claimed source must
 * live where it says it lives — so expanding the TYPES must not weaken it.
 */
function gate_term_artifact_hosts(): array {
    return [
        'social_post'   => ['tiktok.com', 'x.com', 'twitter.com', 'youtube.com', 'youtu.be',
                            'instagram.com', 'reddit.com', 'twitch.tv'],
        // Usenet / mailing-list archives and web captures
        'archive'       => ['groups.google.com', 'web.archive.org', 'archive.org',
                            'marc.info', 'mail-archive.com', 'usenetarchives.com'],
        // developer / publisher statements, patch notes, changelogs — on their OWN domain
        'official'      => ['valvesoftware.com', 'steampowered.com', 'store.steampowered.com',
                            'dota2.com', 'counter-strike.net', 'riotgames.com',
                            'leagueoflegends.com', 'valorant.com', 'blizzard.com',
                            'battle.net', 'playoverwatch.com', 'worldofwarcraft.com',
                            'ea.com', 'ubisoft.com', 'epicgames.com', 'fortnite.com',
                            'minecraft.net', 'mojang.com', 'rockstargames.com',
                            'bungie.net', 'nintendo.com', 'playstation.com', 'xbox.com',
                            'roblox.com', 'discord.com', 'twitch.tv'],
        // a named promotional / origin video with a canonical URL
        'video'         => ['youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com'],
        // dictionaries carrying a first-known-use date (NOT Urban Dictionary,
        // NOT Wiktionary — those are the commodity tier this site competes with)
        'lexicographic' => ['merriam-webster.com', 'oed.com', 'dictionary.com',
                            'cambridge.org', 'collinsdictionary.com', 'britannica.com'],
    ];
}

/** Post-shaped paths, so a bare PROFILE can never pass as an artifact. */
function gate_term_social_post_shape(string $url): bool {
    foreach ([
        '#tiktok\.com/@[A-Za-z0-9._-]{2,30}/video/\d{6,}#i',
        '#(?:x|twitter)\.com/[A-Za-z0-9_]{2,30}/status/\d{6,}#i',
        '#(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)[A-Za-z0-9_-]{11}#i',
        '#instagram\.com/(?:p|reel)/[A-Za-z0-9_-]{5,}#i',
        '#reddit\.com/r/[A-Za-z0-9_]{2,30}/comments/[a-z0-9]{5,}#i',
        '#twitch\.tv/(?:videos/\d{6,}|[A-Za-z0-9_]{2,30}/clip/[A-Za-z0-9_-]{5,})#i',
    ] as $re) if (preg_match($re, $url)) return true;
    return false;
}

/**
 * Which artifact TYPE this URL is, verified by host — or null if it is none of
 * them. Gaming origins are Usenet threads, promo videos, patch notes and
 * developer statements; they are no less primary than a TikTok, and encoding
 * one meme's anatomy as universal is what produced 0% gaming recall.
 */
function gate_term_artifact_type(string $url): ?string {
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return null;
    $on = function (string $h, array $list): bool {
        foreach ($list as $d) if ($h === $d || str_ends_with($h, '.' . $d)) return true;
        return false;
    };
    $hosts = gate_term_artifact_hosts();
    // social_post first, and ONLY when the path is a real post
    if ($on($host, $hosts['social_post']) && gate_term_social_post_shape($url)) return 'social_post';
    if ($on($host, $hosts['archive']))       return 'archive';
    if ($on($host, $hosts['official']))      return 'official';
    if ($on($host, $hosts['video'])
        && preg_match('#(?:watch\?v=|shorts/|youtu\.be/|vimeo\.com/\d+)#i', $url)) return 'video';
    if ($on($host, $hosts['lexicographic'])) return 'lexicographic';
    return null;
}

/** Host that a claimed platform must actually live on. */
function gate_term_platform_hosts(): array {
    return [
        'tiktok'    => ['tiktok.com'],
        'x'         => ['x.com', 'twitter.com'],
        'twitter'   => ['x.com', 'twitter.com'],
        'youtube'   => ['youtube.com', 'youtu.be'],
        'instagram' => ['instagram.com'],
        'reddit'    => ['reddit.com'],
        'twitch'    => ['twitch.tv'],
        'facebook'  => ['facebook.com'],
    ];
}

/**
 * Citations that actually attribute: platform + handle + a real date + a URL
 * that genuinely belongs to that platform.
 *
 * The URL check was added after the first passing page: hawk-tuah shipped three
 * "citations", two of which claimed platform=X with handles @ZachXBT and
 * @Haliey Welch while BOTH urls pointed at the same Benzinga news article. The
 * model had read names and dates out of a news story and dressed them up as
 * primary sightings. Platform+handle+date alone could not tell the difference,
 * which made the count look like evidence when it was a paraphrase. A citation
 * must point at the post it claims to be.
 */
/**
 * Is this text the term being USED, or the term being DEFINED?
 * That is the real discriminator for a lexicographic citation — not whether the
 * source is social or journalism. Merriam-Webster's delulu entry carries three
 * social posts AND "Evie Woods quoted in Image, 17 July 2024". Both are usage.
 * An article explaining what the word means is not a citation, it is a
 * competitor page.
 */
function gate_term_is_definitional(string $text): bool {
    $t = ' ' . strtolower(preg_replace('/\s+/', ' ', $text)) . ' ';
    foreach (['what does', 'what is the meaning', 'meaning of', ' means ', ' meaning', ' explained',
              ' definition', ' defined as', 'refers to', 'is a term', 'is slang for',
              'stands for', 'is used to describe'] as $p) {
        if (str_contains($t, $p)) return true;
    }
    return false;
}

/**
 * Citations that actually attribute. TWO valid types, per owner decision
 * 2026-08-04 — the old social-only rule was unachievable by construction,
 * because fetch_term_sources() supplies journalism and the gate demanded posts,
 * so the model repackaged articles as fake "posts" and correctly scored 0:
 *
 *   social_post      platform + handle + date + a post URL on that platform
 *   published_usage  publication + date + article URL + THE EXTRACTED QUOTE
 *                    showing the term in USE (never being defined)
 *
 * Three of either kind are still required. The count was not lowered.
 */
function gate_term_valid_citations(array $cites, string $term = ''): array {
    $hosts = gate_term_platform_hosts();
    $ok = [];
    foreach ($cites as $c) {
        if (!is_array($c)) continue;
        $date = trim((string)($c['date'] ?? ''));
        $url  = trim((string)($c['url'] ?? ''));
        if (!gate_term_is_real_date($date)) continue;
        if (filter_var($url, FILTER_VALIDATE_URL) === false) continue;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        $platform = trim((string)($c['platform'] ?? ''));
        $want = $hosts[strtolower($platform)] ?? null;

        // --- type 1: social_post ---
        // SEO-BATCH-1 MISROUTING FIX. This branch used to `continue` whenever a
        // citation named a platform but its URL was not on that platform — which
        // silently DESTROYED valid published_usage citations. Real case, pogchamp:
        //   platform=Twitch, handle=@Twitch, date=January 7 2021,
        //   url=avclub.com/twitch-replaces-pogchamp, quote=Twitch's own statement.
        // The model filled `platform` with WHO SAID IT (accurate) rather than
        // where it was hosted, so a fully-valid press citation was thrown away
        // without ever being tested as published_usage. Failing the social_post
        // shape must FALL THROUGH to the published_usage test, not end the loop.
        // This adds nothing that does not already clear the full published_usage
        // bar (real date, quote contains the term, non-definitional, non-commodity).
        if ($want !== null) {
            $handle = trim((string)($c['handle'] ?? ''));
            $match = false;
            foreach ($want as $h) if ($host === $h || str_ends_with($host, '.' . $h)) { $match = true; break; }
            if ($match && $handle !== '') {
                // SEO-BATCH-1: A WELL-SHAPED POST URL IS NOT EVIDENCE THAT THE
                // POST EXISTS. This run produced platform=Twitch, handle=@Twitch,
                // date=2021-01-07, url=twitter.com/Twitch/status/1347012726114451456
                // — perfectly shaped, and the id resolves to a 404. The old code
                // checked shape only, so a fabricated post url would have counted
                // toward the citation minimum. Verify the ones we CAN verify.
                // Fail closed: a citation we cannot confirm is not evidence.
                // ($host is a bare hostname — never match it with a /-anchored
                // pattern; that typo silently skipped this verification once.)
                if (preg_match('#(?:^|\.)(?:x|twitter)\.com$#i', $host)) {
                    if (link_tweet_alive($url) !== true) continue;
                }
                $c['citation_type'] = 'social_post';
                $ok[] = $c;
                continue;
            }
            // not a genuine post on that platform -> fall through, judge it as press
        }

        // --- type 2: published_usage ---
        $quote = trim((string)($c['quote'] ?? ''));
        if ($quote === '') continue;                      // no quote, no citation
        if ($term !== '' && mb_stripos($quote, $term) === false) continue;   // must contain the term
        if (gate_term_is_commodity_source($url)) continue;                   // never the commodity tier

        // OWNER DECISION 2026-08-22. This branch used to require the quote to
        // be NON-DEFINITIONAL — real-world usage only, never a page that
        // explains the word. The intent was right (prove the term exists and
        // we did not invent it) but the test rejects the only evidence we can
        // actually obtain: casual usage lives on TikTok/Reddit/X, none of
        // which we can retrieve, while a dated Merriam-Webster or CNN entry —
        // stronger proof a term is real than three anonymous posts — was
        // thrown away for the crime of being an explanation. Measured: 0
        // valid citations harvestable from our stored sources, and a live
        // search returned Cambridge, Merriam-Webster, CNN, Wikipedia.
        //
        // Two kinds of proof are now accepted, and BOTH still require a real
        // attributed date, a valid URL, the term present in the quote, and a
        // non-commodity host — so fabrication remains impossible:
        //   published_usage    - the term used as an ordinary word (best)
        //   published_reference- a dated write-up in a real publication
        $titleish = (string)($c['title'] ?? '') . ' ' . $url;
        $isGloss  = gate_term_is_definitional($quote)
                 || gate_term_is_definitional($titleish);
        $c['citation_type'] = $isGloss ? 'published_reference' : 'published_usage';
        $ok[] = $c;
    }
    return $ok;
}

function gate_check_term(int $page_id): array {
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM pages WHERE id=? AND type='term'");
    $p->execute([$page_id]);
    $page = $p->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['pass' => false, 'checks' => [['label' => 'page exists', 'pass' => false, 'detail' => 'not found']]];
    $t = $pdo->prepare("SELECT * FROM terms WHERE page_id=?");
    $t->execute([$page_id]);
    $term = $t->fetch(PDO::FETCH_ASSOC);
    if (!$term) return ['pass' => false, 'checks' => [['label' => 'term row', 'pass' => false, 'detail' => 'missing']]];

    $jd = fn($v) => (is_array($x = json_decode($v ?? '[]', true)) ? $x : []);
    $meaning   = $jd($term['meaning']);
    $origin    = $jd($term['origin']);
    $examples  = $jd($term['examples']);
    $faqs      = $jd($term['faqs']);
    $sources   = $jd($term['sources']);
    $why       = $jd($term['why_trending']);
    $bodyText  = implode(' ', array_map(fn($x) => is_string($x) ? $x : '', array_merge($meaning, $origin, $why)));
    $bodyWords = str_word_count(strip_tags($bodyText));

    $tt = mb_strlen($page['title_tag']);
    $md = mb_strlen($page['meta_desc']);
    $sd = mb_strlen($term['short_def'] ?? '');

    $checks = [
        ['label' => 'title tag 40-60', 'pass' => $tt >= 40 && $tt <= 60, 'detail' => "{$tt} chars"],
        ['label' => 'meta desc 110-135', 'pass' => $md >= 110 && $md <= 135, 'detail' => "{$md} chars"],
        ['label' => 'one-line definition 20-180', 'pass' => $sd >= 20 && $sd <= 180, 'detail' => "{$sd} chars"],
        ['label' => 'body depth >= 380 words', 'pass' => $bodyWords >= 380, 'detail' => "{$bodyWords} words"],
        // SEO-BATCH-1: the legacy 'origin / when answered' check is REMOVED.
        // It required count($origin) >= 1 || strlen(first_seen) > 4 — i.e. it
        // made an origin MANDATORY, which directly contradicts the
        // origin-optional decision and silently failed every page the
        // redrafter had correctly written with no origin. It is also the exact
        // check that passed the literal string "debated" as an origin. The two
        // conditional checks below (typed artifact + attributed date) replace
        // it completely and are strictly stronger.
        ['label' => '>= 2 sources', 'pass' => count($sources) >= 2, 'detail' => count($sources) . ' sources'],
        ['label' => '>= 1 example', 'pass' => count($examples) >= 1, 'detail' => count($examples) . ' examples'],
        ['label' => '>= 2 FAQs', 'pass' => count($faqs) >= 2, 'detail' => count($faqs) . ' faqs'],
        ['label' => 'has H1', 'pass' => mb_strlen($page['h1']) > 6, 'detail' => $page['h1']],
        ['label' => 'summary present', 'pass' => mb_strlen($page['summary'] ?? '') >= 80, 'detail' => mb_strlen($page['summary'] ?? '') . ' chars'],
    ];

    // ---------------------------------------------------------------
    // SEO-BATCH-1 TRUTH CHECKS. These sit alongside the hygiene checks
    // above; a page must clear BOTH to publish.
    // ---------------------------------------------------------------
    $originUrl  = trim((string)($term['origin_url'] ?? ''));
    $originDate = trim((string)($term['origin_date'] ?? ''));
    $originSrc  = trim((string)($term['origin_date_src'] ?? ''));
    $cites      = $jd($term['citations'] ?? '[]');
    $goodCites  = gate_term_valid_citations($cites, (string)($term["term"] ?? ""));

    // ---------------------------------------------------------------
    // ORIGIN IS OPTIONAL, BUT AN ORIGIN CLAIM IS NOT FREE.
    // (owner decision 2026-08-04, after 0/82 pages passed.)
    //
    // The disease was FABRICATED origins, not ABSENT ones. Cambridge Dictionary
    // ranks #1 for "delulu meaning" with no etymology at all, and Merriam-
    // Webster asserts "First Known Use: 2015" with no linked artifact — both
    // would fail a mandatory-origin gate, and both outrank us. So:
    //   claims an origin  -> must produce a typed artifact + an attributed date
    //   claims no origin  -> must say NOTHING about where the term came from
    // Fabrication is structurally impossible on either path.
    // ---------------------------------------------------------------
    $claimsOrigin = count($origin) >= 1 || trim((string)($term['first_seen'] ?? '')) !== '';
    $aType  = $originUrl !== '' ? gate_term_artifact_type($originUrl) : null;
    $dateOk = gate_term_date_is_attributed($originDate, $originSrc);

    $checks[] = ['label' => 'origin artifact (typed + host-verified)',
                 'pass' => !$claimsOrigin || $aType !== null,
                 'detail' => !$claimsOrigin ? 'no origin claimed (allowed)'
                     : ($aType !== null ? $aType . ' @ ' . parse_url($originUrl, PHP_URL_HOST)
                        : ($originUrl === '' ? 'origin CLAIMED but no artifact url'
                           : 'url is not a recognised artifact type/host: ' . parse_url($originUrl, PHP_URL_HOST)))];

    $checks[] = ['label' => 'origin date attributed to a source',
                 'pass' => !$claimsOrigin || $dateOk,
                 'detail' => !$claimsOrigin ? 'no origin claimed (allowed)'
                     : ($originDate === '' ? 'origin CLAIMED but no date'
                        : (!gate_term_is_real_date($originDate) ? "'{$originDate}' names no year"
                           : ($originSrc === '' ? "'{$originDate}' stated by nothing (no source url)"
                              : "'{$originDate}' per " . parse_url($originSrc, PHP_URL_HOST))))];

    // 2. >= 3 dated attributed sightings of the term in the wild.
    $checks[] = ['label' => '>= 3 dated attributed citations', 'pass' => count($goodCites) >= 3,
                 'detail' => count($goodCites) . ' valid of ' . count($cites)
                             . ' (need platform + handle + real date)'];

    // 3. >= 2 sources above the commodity tier.
    $realSrc = 0;
    foreach ($sources as $s) {
        $u = is_array($s) ? (string)($s['url'] ?? '') : (string)$s;
        if ($u !== '' && !gate_term_is_commodity_source($u)) $realSrc++;
    }
    $checks[] = ['label' => '>= 2 non-commodity sources', 'pass' => $realSrc >= 2,
                 'detail' => "{$realSrc} of " . count($sources) . ' (excl. UD / wiki / slang-SEO / search urls)'];

    // 3b. SOURCES MUST BE ON TOPIC — the gate backstop.
    // A source can be real, non-commodity AND about entirely the wrong thing:
    // `cracked` was grounded in a Lumma Stealer malware article and a Denuvo DRM
    // piece, both of which cleared every other source check. Whichever path
    // produced a page, an unscreened source can never pass here. The screen runs
    // at draft/redraft time (sources_topical_fit) and stamps each source; a
    // source with no verdict is treated as unscreened, which legacy pages are —
    // correctly, since they are exactly what the replay exists to rebuild.
    $unscreened = 0;
    foreach ($sources as $s) {
        if (!is_array($s) || ($s['topical_fit'] ?? null) !== true) $unscreened++;
    }
    $checks[] = ['label' => 'sources screened on-topic', 'pass' => $unscreened === 0,
                 'detail' => $unscreened === 0
                     ? count($sources) . ' source(s) screened against the term\'s sense'
                     : "{$unscreened} of " . count($sources) . ' source(s) never screened for topical fit'];

    // 4. no invented negatives. Scanned across every prose field, not just the
    // origin block, because the assertion can land anywhere in the page.
    $proseAll = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ',
        $bodyText . ' ' . implode(' ', array_map(fn($x) => is_string($x) ? $x : '', $examples))
        . ' ' . (string)($term['short_def'] ?? '') . ' ' . (string)($page['summary'] ?? '')));
    $proseAll = preg_replace('/\s+/', ' ', $proseAll);
    $hits = [];
    foreach (gate_term_banned_negatives() as $bad) {
        if (str_contains($proseAll, $bad)) $hits[] = $bad;
    }
    $checks[] = ['label' => 'no invented negatives', 'pass' => !$hits,
                 'detail' => $hits ? 'found: "' . implode('", "', $hits) . '"' : 'clean'];

    // 5. A page that claims NO origin must not date the term anywhere else.
    // Without this the no-origin path is just a hiding place: drop the origin
    // block, then write "the phrase first appeared in 2019" in the meaning
    // section and the fabrication is back, unverified, one heading away.
    $datingRe = '/\b(?:originated|first (?:appeared|used|surfaced|emerged|coined)|coined|dates? back|traces? back|goes back)\b[^.]{0,80}\b(?:19|20)\d{2}\b/i';
    $smuggled = (!$claimsOrigin && preg_match($datingRe, $proseAll, $sm)) ? trim($sm[0]) : '';
    $checks[] = ['label' => 'no undated-origin smuggling', 'pass' => $smuggled === '',
                 'detail' => $smuggled === '' ? ($claimsOrigin ? 'origin claimed + verified above' : 'says nothing about origin')
                     : 'no origin section, but the body still dates the term: "' . mb_substr($smuggled, 0, 70) . '"'];

    // UNIQUENESS SENTINEL: reject any draft that reads too much like a page that
    // already exists in its lane. This is what keeps the site survivor-tier as it
    // grows to thousands of pages, with no human watching.
    $dup = term_max_overlap($page_id, $term['lane'] ?? 'slang', $bodyText);
    $checks[] = ['label' => 'unique vs siblings (<35% overlap)', 'pass' => $dup['pct'] < 35,
                 'detail' => $dup['pct'] . '% vs ' . ($dup['slug'] ?: 'none')];

    // Source-COUNT checks no longer block publishing (owner rule 2026-08-22);
    // page_publish_live() uses them to decide INDEXABLE instead. Everything
    // else — dated citations, on-topic screening, structure — still blocks.
    $advisoryLabels = ['>= 2 sources', '>= 2 non-commodity sources'];
    $blocking = array_filter($checks, fn($c) => !in_array($c['label'], $advisoryLabels, true));
    $pass = !in_array(false, array_column($blocking, 'pass'), true);
    return ['pass' => $pass, 'checks' => $checks];
}

/** Max 3-word-shingle overlap (%) of $bodyText vs published siblings in $lane. */
function term_max_overlap(int $excludePageId, string $lane, string $bodyText): array {
    $pdo = db();
    $shingle = function (string $t): array {
        $t = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $t));
        $w = preg_split('/\s+/', trim($t));
        $s = [];
        for ($i = 0; $i + 2 < count($w); $i++) $s[$w[$i] . ' ' . $w[$i+1] . ' ' . $w[$i+2]] = 1;
        return $s;
    };
    $mine = $shingle($bodyText);
    if (count($mine) < 10) return ['pct' => 0.0, 'slug' => ''];
    $rows = $pdo->prepare("SELECT p.slug, t.meaning, t.origin, t.why_trending FROM pages p JOIN terms t ON t.page_id=p.id
                           WHERE p.type='term' AND p.status='published' AND t.lane=? AND p.id<>? LIMIT 300");
    $rows->execute([$lane, $excludePageId]);
    $max = 0.0; $who = '';
    foreach ($rows->fetchAll() as $r) {
        $txt = '';
        foreach (['meaning','origin','why_trending'] as $f) {
            foreach ((array)(json_decode($r[$f] ?? '[]', true) ?: []) as $p) $txt .= ' ' . (is_string($p) ? $p : '');
        }
        $theirs = $shingle($txt);
        if (count($theirs) < 10) continue;
        $inter = count(array_intersect_key($mine, $theirs));
        $uni = count($mine + $theirs);
        $pct = $uni ? ($inter / $uni) * 100 : 0;
        if ($pct > $max) { $max = $pct; $who = $r['slug']; }
    }
    return ['pct' => round($max, 1), 'slug' => $who];
}
