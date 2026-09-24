<?php
/* GenZHype | THE ROLODEX — the people who send readers (owner go 2026-09-06).
 *
 * WHY. On 2026-09-01 one newsletter (Big Spaceship's Internet Brunch, issue
 * 9.1, curated by a strategist) linked our misery-girls page in one line and
 * ~500 readers came in an afternoon — the first external referral the site
 * ever had, found five days late by reading GA4. Google's August diagnosis
 * measured ZERO referring domains as the #1 problem. People like that curator
 * are the only honest way to fix it.
 *
 * OWNER'S BRIEF: "like if we employed a human to keep finding daily these
 * people, when we found them we add them on a table, and all get the same
 * thing." So: a daily finder, one table, one treatment. The SENDING stays
 * human and one-at-a-time (owner, from the site's own mailbox) — the finder
 * only tells the owner WHO and drafts WHAT.
 *
 * [RULE] verified 2026-09-06: Muck Rack's pitching guide — reference a recent
 * article they wrote; 79% of journalists reject pitches for lack of relevance,
 * 88% delete off-beat pitches. So every row carries the EVIDENCE (the issue /
 * article where they cited an explainer) and the match is by what they covered
 * RECENTLY, not by who they are.
 *
 * THREE JOBS.  rolodex_discover()  daily: search → read → AI extract → table
 *              rolodex_mentions()  daily: GA4 referrers → alarm + auto-add
 *              rolodex_match()     per page: who covered this lately → note draft
 */

const ROLODEX_STAMP = __DIR__ . '/cache/rolodex_last.txt';

function rolodex_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rolodex_people (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        outlet VARCHAR(160) NOT NULL,
        outlet_domain VARCHAR(120) NOT NULL DEFAULT '',
        role VARCHAR(120) NOT NULL DEFAULT '',
        kind ENUM('newsletter','press','creator','agency','other') NOT NULL DEFAULT 'other',
        url VARCHAR(500) NOT NULL DEFAULT '',
        email VARCHAR(160) NOT NULL DEFAULT '',
        contact_route VARCHAR(255) NOT NULL DEFAULT '',
        topics_json TEXT NULL,
        cadence VARCHAR(40) NOT NULL DEFAULT '',
        evidence_url VARCHAR(500) NOT NULL DEFAULT '',
        evidence_title VARCHAR(255) NOT NULL DEFAULT '',
        evidence_date DATE NULL,
        why VARCHAR(255) NOT NULL DEFAULT '',
        found_via VARCHAR(80) NOT NULL DEFAULT '',
        status ENUM('new','verified','contacted','replied','linked','dead') NOT NULL DEFAULT 'new',
        notes TEXT NULL,
        last_seen DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY u_person (outlet_domain, name),
        KEY idx_status (status, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rolodex_mentions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(160) NOT NULL,
        medium VARCHAR(40) NOT NULL,
        landing VARCHAR(255) NOT NULL DEFAULT '',
        sessions INT NOT NULL DEFAULT 0,
        first_seen DATE NOT NULL,
        last_seen DATE NOT NULL,
        seen_by_owner TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY u_src (source, medium, landing)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rolodex_notes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        person_id INT UNSIGNED NOT NULL,
        page_id INT UNSIGNED NOT NULL,
        subject VARCHAR(80) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY u_pp (person_id, page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rolodex_seen_urls (
        url_hash CHAR(32) PRIMARY KEY, seen_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
}

/* The finder's questions. One or two per day, rotating, so a month covers the
 * whole map without repeating itself. General web search (Exa, keyless) plus
 * Bing News for the press side: writers who cite explainers by name. */
function rolodex_queries(): array {
    return [
        ['exa',  'daily newsletter about internet culture, memes and TikTok trends'],
        ['exa',  'newsletter issue this week viral TikTok meme explained link roundup'],
        ['exa',  'substack newsletter internet culture creator drama weekly'],
        ['exa',  'a newsletter issue rounding up this week\'s viral TikTok memes and internet drama, with links'],
        ['exa',  'reporter who covers TikTok trends, memes and creators — author bio page with recent articles'],
        ['exa',  'agency or strategist daily email digest of what is trending on the internet today'],
        ['news', '"according to Know Your Meme"'],
        ['news', 'TikTok meme explained origin'],
        ['news', 'viral trend explained "Know Your Meme"'],
        ['exa',  'internet culture reporter TikTok trends memes byline'],
        ['exa',  'gaming culture newsletter weekly community memes'],
    ];
}

function rolodex_domain(string $url): string {
    $h = mb_strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    return preg_replace('/^www\./', '', $h);
}

/* DAILY FINDER. Budget-bound: 1-2 searches, ≤4 page reads, 1 AI call. */
function rolodex_discover(PDO $pdo, int $deadlineSec = 200, int $maxReads = 4): array {
    require_once __DIR__ . '/reach.php';
    require_once __DIR__ . '/fetch_sources.php';
    require_once __DIR__ . '/ai.php';
    rolodex_install($pdo);
    $t0 = time();
    $stats = ['searched' => 0, 'hits' => 0, 'read' => 0, 'added' => 0, 'skipped' => 0, 'error' => null];
    $qs = rolodex_queries();
    $day = (int)date('z');
    $todays = [$qs[$day % count($qs)], $qs[($day + 5) % count($qs)]];

    $hits = [];
    foreach ($todays as [$engine, $q]) {
        if (time() - $t0 > $deadlineSec) break;
        $stats['searched']++;
        if ($engine === 'exa') {
            foreach (reach_exa_search($q, 6) as $h) $hits[] = ['url' => $h['url'], 'title' => $h['title'], 'published' => $h['published'], 'text' => $h['text'], 'via' => 'exa'];
        } else {
            foreach (fs_news_search($q, 6) as $u) $hits[] = ['url' => $u, 'title' => '', 'published' => '', 'text' => '', 'via' => 'news'];
        }
    }
    $stats['hits'] = count($hits);
    // never read the same page twice; skip our own site and the giants that are not people
    // anchored: a bare 'x\.com' matched vox.com and skipped a real culture reporter (measured 2026-09-06)
    $skipHosts = '/(^|\.)(genzhype\.com|knowyourmeme\.com|wikipedia\.org|reddit\.com|youtube\.com|tiktok\.com|x\.com|twitter\.com|instagram\.com|facebook\.com|linkedin\.com|amazon\.com|google\.com|beehiiv\.com|substack\.com)$/i';
    $reads = [];
    foreach ($hits as $h) {
        if (count($reads) >= $maxReads || time() - $t0 > $deadlineSec) break;
        $dom = rolodex_domain($h['url']);
        if ($dom === '' || preg_match($skipHosts, $dom)) { $stats['skipped']++; continue; }
        $hash = md5($h['url']);
        $seen = $pdo->prepare("SELECT 1 FROM rolodex_seen_urls WHERE url_hash=?");
        $seen->execute([$hash]);
        if ($seen->fetch()) { $stats['skipped']++; continue; }
        $pdo->prepare("INSERT IGNORE INTO rolodex_seen_urls (url_hash, seen_at) VALUES (?, NOW())")->execute([$hash]);
        $md = reach_exa_fetch($h['url'], 35) ?: reach_jina_read($h['url'], 25);
        if (!$md) {   // fallback: plain fetch, tags stripped (Jina answered nothing on 2026-09-06)
            $raw = fs_http_get($h['url'], 15);
            if ($raw) { $raw = preg_replace('#<(script|style|nav|footer)[^>]*>.*?</\\1>#is', ' ', $raw); $md = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')); }
        }
        if ((!$md || mb_strlen($md) < 300) && mb_strlen((string)$h['text']) >= 120) {
            $md = '[EXCERPT ONLY — search highlights, the page itself could not be opened] ' . $h['title'] . "\n" . $h['text'];
        }
        if (!$md || mb_strlen($md) < 120) { $stats['skipped']++; continue; }
        $reads[] = ['url' => $h['url'], 'domain' => $dom, 'via' => $h['via'], 'published' => $h['published'],
                    'text' => mb_substr(preg_replace('/\s+/u', ' ', $md), 0, 3500)];
        $stats['read']++;
    }
    if (!$reads) return $stats;

    $blocks = [];
    foreach ($reads as $i => $r) $blocks[] = "PAGE #$i  url={$r['url']}  domain={$r['domain']}" . ($r['published'] ? "  published={$r['published']}" : '') . "\n" . $r['text'];
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            'You build a media contact list for a small internet-culture site (memes, slang, creator drama, gaming). '
          . 'You are shown web pages. For each page decide whether it is written by a PERSON or OUTLET who regularly '
          . 'curates or reports internet culture and LINKS to explainer sources (a daily/weekly newsletter, a culture '
          . 'reporter, a trends digest). Extract only what the page itself shows. Never invent an email. Output STRICT JSON only.'],
        ['role' => 'user', 'content' =>
            implode("\n\n", $blocks) . "\n\n"
          . 'Return JSON: {"pages":[{"i":0,"is_curator":true|false,"name":"person name or empty","outlet":"newsletter/publication name",'
          . '"role":"e.g. editor, strategist, reporter","kind":"newsletter|press|creator|agency|other","email":"only if printed on the page, else empty",'
          . '"contact_route":"how to reach them per the page (email / tips address / X handle / form), else empty",'
          . '"topics":["3-6 short topics they covered on this page"],"cadence":"daily|weekly|irregular|unknown",'
          . '"evidence_title":"title of this issue/article","evidence_date":"YYYY-MM-DD or empty","why":"<=120 chars, from the page"}]}'],
    ], ['nvidia', 'gemini', 'openrouter'], 0.1, 90);
    $pdo = db_alive();
    $j = isset($res['content']) ? ai_json($res['content']) : null;
    if (!$j || !isset($j['pages'])) { $stats['error'] = $res['error'] ?? 'bad JSON'; return $stats; }
    foreach ((array)$j['pages'] as $p) {
        $i = (int)($p['i'] ?? -1);
        if (!isset($reads[$i]) || empty($p['is_curator'])) continue;
        $outlet = trim((string)($p['outlet'] ?? ''));
        if ($outlet === '') $outlet = $reads[$i]['domain'];
        $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 120);
        $email = trim((string)($p['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $kind = in_array($p['kind'] ?? '', ['newsletter', 'press', 'creator', 'agency', 'other'], true) ? $p['kind'] : 'other';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['evidence_date'] ?? '')) ? $p['evidence_date'] : ($reads[$i]['published'] ?: null);
        $ins = $pdo->prepare("INSERT INTO rolodex_people (name,outlet,outlet_domain,role,kind,url,email,contact_route,topics_json,cadence,
                                evidence_url,evidence_title,evidence_date,why,found_via,status,last_seen,created_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'new',NOW(),NOW())
                              ON DUPLICATE KEY UPDATE last_seen=NOW(), evidence_url=VALUES(evidence_url), evidence_title=VALUES(evidence_title),
                                evidence_date=VALUES(evidence_date), topics_json=VALUES(topics_json),
                                email=IF(email='', VALUES(email), email), contact_route=IF(contact_route='', VALUES(contact_route), contact_route)");
        $ins->execute([$name, mb_substr($outlet, 0, 160), $reads[$i]['domain'], mb_substr((string)($p['role'] ?? ''), 0, 120), $kind,
                       mb_substr($reads[$i]['url'], 0, 500), mb_substr($email, 0, 160), mb_substr((string)($p['contact_route'] ?? ''), 0, 255),
                       json_encode(array_slice((array)($p['topics'] ?? []), 0, 6), JSON_UNESCAPED_UNICODE), mb_substr((string)($p['cadence'] ?? ''), 0, 40),
                       mb_substr($reads[$i]['url'], 0, 500), mb_substr((string)($p['evidence_title'] ?? ''), 0, 255), $date,
                       mb_substr((string)($p['why'] ?? ''), 0, 255), $reads[$i]['via']]);
        if ($ins->rowCount() === 1) $stats['added']++;
    }
    return $stats;
}

/* THE MENTION ALARM. New referring sources in GA4 (referral + email) over the
 * last 3 days → rolodex_mentions, and a rolodex row with status 'linked' so the
 * people who already sent readers sit at the top of the table. */
function rolodex_mentions(PDO $pdo): array {
    require_once __DIR__ . '/ga4.php';
    rolodex_install($pdo);
    $stats = ['rows' => 0, 'new' => 0, 'error' => null];
    $r = ga4_report(['dateRanges' => [['startDate' => '3daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'sessionSource'], ['name' => 'sessionMedium'], ['name' => 'landingPage'], ['name' => 'date']],
        'metrics' => [['name' => 'sessions']],
        'dimensionFilter' => ['filter' => ['fieldName' => 'sessionMedium', 'inListFilter' => ['values' => ['referral', 'email']]]],
        'limit' => 200]);
    if ($r['error']) { $stats['error'] = $r['error']; return $stats; }
    foreach ($r['rows'] as [$d, $m]) {
        [$src, $med, $landing, $date] = $d;
        $sess = (int)$m[0];
        if ($sess < 1) continue;
        $stats['rows']++;
        $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        $ins = $pdo->prepare("INSERT INTO rolodex_mentions (source,medium,landing,sessions,first_seen,last_seen)
                              VALUES (?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE sessions=GREATEST(sessions, VALUES(sessions)), last_seen=GREATEST(last_seen, VALUES(last_seen))");
        $ins->execute([mb_substr($src, 0, 160), $med, mb_substr($landing, 0, 255), $sess, $day, $day]);
        if ($ins->rowCount() === 1) {
            $stats['new']++;
            if ($sess < 3) continue;   // one stray click (a Word doc, a Yandex hit) is not a contact
            // a source that sent readers is a contact, even before we know the person's name
            $dom = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $src) ? rolodex_domain('https://' . $src) : mb_strtolower($src);
            $pdo->prepare("INSERT INTO rolodex_people (name,outlet,outlet_domain,kind,found_via,status,why,last_seen,created_at)
                           VALUES ('',?,?, ?,'ga4','linked',?,NOW(),NOW())
                           ON DUPLICATE KEY UPDATE status=IF(status IN ('new','verified','contacted'),'linked',status), last_seen=NOW()")
                ->execute([mb_substr($src, 0, 160), mb_substr($dom, 0, 120), $med === 'email' ? 'newsletter' : 'other', "sent $sess readers to $landing on $day"]);
        }
    }
    return $stats;
}

/* Daily driver for the tick: once per day, both jobs, hard budget. */
function rolodex_daily(PDO $pdo, int $deadlineSec = 220): ?array {
    $last = (int)@file_get_contents(ROLODEX_STAMP);
    if (time() - $last < 20 * 3600) return null;
    @file_put_contents(ROLODEX_STAMP, (string)time());
    $out = ['mentions' => null, 'discover' => null];
    try { $out['mentions'] = rolodex_mentions($pdo); } catch (Throwable $e) { $out['mentions'] = ['error' => $e->getMessage()]; }
    try { $pdo = db_alive(); $out['discover'] = rolodex_discover($pdo, $deadlineSec, 4); } catch (Throwable $e) { $out['discover'] = ['error' => $e->getMessage()]; }
    return $out;
}

/* WHO TO WRITE TO for a page: people whose RECENT evidence overlaps the page's
 * words. Keyword overlap, recency, and 'linked' first. Top 3. */
function rolodex_match(PDO $pdo, int $pageId, int $top = 3): array {
    rolodex_install($pdo);
    $pg = $pdo->prepare("SELECT id, slug, h1 AS title, summary, type FROM pages WHERE id=?");
    $pg->execute([$pageId]);
    $page = $pg->fetch(PDO::FETCH_ASSOC);
    if (!$page) return [];
    $words = array_filter(array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($page['title'] . ' ' . ($page['summary'] ?? '')))),
                          fn($w) => mb_strlen($w) >= 4);
    $stop = ['this', 'that', 'with', 'from', 'what', 'about', 'their', 'have', 'been', 'into', 'after', 'timeline', 'full', 'meaning', 'drama'];
    $words = array_values(array_diff($words, $stop));
    $out = [];
    foreach ($pdo->query("SELECT * FROM rolodex_people WHERE status<>'dead' ORDER BY last_seen DESC LIMIT 400") as $p) {
        $hay = mb_strtolower($p['outlet'] . ' ' . $p['evidence_title'] . ' ' . $p['why'] . ' ' . implode(' ', (array)json_decode((string)$p['topics_json'], true)));
        $score = 0;
        foreach ($words as $w) if (str_contains($hay, $w)) $score += 2;
        // the genre words that make someone a fit even without a shared noun
        foreach (['meme', 'tiktok', 'viral', 'internet culture', 'trend', 'creator', 'gaming'] as $g) if (str_contains($hay, $g)) $score += 1;
        if ($p['status'] === 'linked') $score += 4;
        if ($p['evidence_date'] && strtotime($p['evidence_date']) > time() - 30 * 86400) $score += 2;
        if ($p['email'] !== '' || $p['contact_route'] !== '') $score += 1;
        if ($score >= 3) { $p['score'] = $score; $out[] = $p; }
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $top);
}

/* THE NOTE. Drafted for the owner to send BY HAND from the site's mailbox.
 * Rules (owner brief 2026-09-06 + [RULE] measured limits):
 *  - subject ≤ 40 chars: Gmail mobile shows ~41, the safest measured is 30-33
 *    (emailtooltester / BuzzStream 2025 tests). Lowercase, names the thing they
 *    covered, no "quick question", no "collaboration", no exclamation.
 *  - first line ≤ 90 chars = the preview text on phones: a specific fact about
 *    THEIR issue/article that proves a person read it (Muck Rack: reference a
 *    recent piece). It must stand alone.
 *  - ≤ 90 words, plain text, no HTML, no attachment, the site named once, no
 *    other links, no ask for a link, no template smell. Ends with a first name.
 *  - deliverability: send from the site's own mailbox (SPF/DKIM come with the
 *    Hostinger mailbox), one at a time, no tracking, no bulk. */
function rolodex_note(PDO $pdo, int $personId, int $pageId, string $ownerFirstName = ''): ?array {
    require_once __DIR__ . '/ai.php';
    rolodex_install($pdo);
    $c = $pdo->prepare("SELECT subject, body FROM rolodex_notes WHERE person_id=? AND page_id=?");
    $c->execute([$personId, $pageId]);
    if ($hit = $c->fetch(PDO::FETCH_ASSOC)) return $hit;
    $p = $pdo->query("SELECT * FROM rolodex_people WHERE id=" . (int)$personId)->fetch(PDO::FETCH_ASSOC);
    $g = $pdo->query("SELECT slug, h1 AS title, summary, path FROM pages WHERE id=" . (int)$pageId)->fetch(PDO::FETCH_ASSOC);
    if (!$p || !$g) return null;
    $res = ai_chat([
        ['role' => 'system', 'content' =>
            'You write a short plain email FROM the person who runs a small internet-culture site TO a newsletter curator or reporter. '
          . 'It must read like one busy person writing to another. Rules, all hard: subject = 2 to 6 lowercase words naming the topic of OUR page in plain words (what they would click to learn about), '
          . 'no dates, no numbers, no punctuation, 40 characters max; never "quick question", never "collaboration", never "I loved your piece", no exclamation marks. '
          . 'First sentence 90 characters max and must be a concrete fact about THEIR issue or article (title, date, what they said) — it is '
          . 'the phone preview and must prove a human read it. Whole body 90 words max. Plain text. Name the site once as genzhype.com, '
          . 'no other links, no attachments, no request for a link, no flattery, no marketing words, no "hope this finds you well". '
          . 'One useful offer at most: the page exists if they ever need it. The LAST LINE of the body is exactly the FROM value and nothing else (no "FROM:", no dash, no title). Output STRICT JSON only.'],
        ['role' => 'user', 'content' =>
            "TO: " . ($p['name'] !== '' ? $p['name'] : 'the editor') . " at {$p['outlet']} ({$p['role']}, {$p['kind']})\n"
          . "WHAT THEY COVERED: {$p['evidence_title']} ({$p['evidence_date']}) — {$p['why']}\n"
          . "TOPICS: " . implode(', ', (array)json_decode((string)$p['topics_json'], true)) . "\n"
          . "OUR PAGE: {$g['title']} — " . mb_substr((string)$g['summary'], 0, 300) . "\n"
          . "FROM: " . ($ownerFirstName !== '' ? $ownerFirstName : '[your name]  (sign with exactly this placeholder; never invent a name)') . "\n\n"
          . 'Return JSON: {"subject":"...","body":"..."}'],
    ], ['nvidia', 'gemini', 'openrouter'], 0.4, 60);
    $pdo = db_alive();
    $j = isset($res['content']) ? ai_json($res['content']) : null;
    if (!$j || empty($j['subject']) || empty($j['body'])) return null;
    $subject = mb_strtolower(trim((string)$j['subject']));
    $subject = trim(preg_replace(['/\d{4}-\d{2}-\d{2}/', '/[^\p{L}\p{N} ]/u', '/\s+/'], ['', '', ' '], $subject));
    $subject = mb_substr($subject, 0, 40);
    $body = trim((string)$j['body']);
    // house rules: no dashes (site-wide rule), and a first draft once signed itself 'Sam' — never an invented name
    $body = str_replace([' — ', '—', ' – ', '–'], [', ', ', ', ', ', ', '], $body);
    $sign = $ownerFirstName !== '' ? $ownerFirstName : '[your name]';
    $body = preg_replace('/\s*(FROM:|From:)?\s*\[your name\]\s*$/u', '', rtrim($body));   // strip whatever sign-off the model produced
    $body = preg_replace('/\n\s*(cheers|best|thanks|regards)[,.]?\s*(\n\s*[\p{L}.\- ]{1,30})?\s*$/iu', '', rtrim($body));
    $body = rtrim($body) . "\n\n" . $sign;
    if (str_word_count($body) > 110) return null;   // over budget = not usable, do not store
    $pdo->prepare("INSERT IGNORE INTO rolodex_notes (person_id,page_id,subject,body,created_at) VALUES (?,?,?,?,NOW())")
        ->execute([$personId, $pageId, $subject, $body]);
    return ['subject' => $subject, 'body' => $body];
}
