<?php
/**
 * GenZHype | Entity Graph engine — component 1 of the Citation Engine (GEO).
 *
 * Resolves the real people in our pages to their authoritative entities (Wikidata,
 * Wikipedia, official site + social profiles) and emits schema.org `sameAs` arrays,
 * and resolves terms to their reference pages (Wikipedia/Wiktionary/KnowYourMeme).
 * `sameAs` is the strongest "this page is about a REAL, known entity" signal there
 * is — it lets Google's Knowledge Graph and AI answer engines connect our pages to
 * the entity they already trust, which is what gets a new site cited.
 *
 * Resolution is expensive (Wikidata API), so it runs ONCE per entity at build/backfill
 * time and is cached in the `entities` table; templates only read the cached array.
 */

/**
 * Resolve entities for any published page that doesn't have them yet. Dramas: extract
 * people -> Wikidata sameAs -> dramas.people_json. Terms: authoritative reference pages
 * -> entities cache. Time-budgeted; skips already-resolved dramas. Runs weekly from cron
 * + on demand (`php app/cli.php entities`). Returns a summary.
 */
function entity_backfill_run(PDO $pdo, int $budget = 200): array {
    entity_init($pdo);
    require_once __DIR__ . '/drama_image.php';   // drama_people_ai
    $start = time(); $dramas = 0; $terms = 0;
    $rows = $pdo->query("SELECT p.h1, p.summary, d.id AS did, d.people_json
                         FROM pages p JOIN dramas d ON d.page_id=p.id
                         WHERE p.type='drama' AND p.status='published' AND p.robots='index'
                           AND (d.people_json IS NULL OR d.people_json='')
                         ORDER BY p.id DESC")->fetchAll();
    foreach ($rows as $r) {
        if (time() - $start > $budget) break;
        $out = [];
        try {
            foreach (array_slice(drama_people_ai($r['h1'], $r['summary'] ?? ''), 0, 4) as $name) {
                $e = entity_resolve_person($pdo, $name);
                if (!empty($e['sameAs'])) $out[] = ['name' => $name, 'role' => $e['description'] ?: 'Featured', 'sameAs' => $e['sameAs']];
            }
        } catch (Throwable $ex) { continue; }
        $pdo->prepare("UPDATE dramas SET people_json=? WHERE id=?")->execute([json_encode($out, JSON_UNESCAPED_SLASHES), $r['did']]);
        $dramas++;
    }
    foreach ($pdo->query("SELECT t.term, t.sources FROM pages p JOIN terms t ON t.page_id=p.id
                          WHERE p.type='term' AND p.status='published' AND p.robots='index'
                            AND NOT EXISTS (SELECT 1 FROM entities e WHERE e.kind='term' AND e.name=t.term)")->fetchAll() as $t) {
        if (time() - $start > $budget) break;
        entity_resolve_term($pdo, $t['term'], json_decode($t['sources'] ?: '[]', true) ?: []);
        $terms++;
    }
    return ['dramas' => $dramas, 'terms' => $terms];
}

function entity_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS entities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(12) NOT NULL,
        name VARCHAR(190) NOT NULL,
        qid VARCHAR(24) NULL,
        same_as TEXT NULL,
        fetched_at DATETIME NOT NULL,
        UNIQUE KEY uniq_kind_name (kind, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Cached sameAs lookup (no network). Returns [] if not resolved yet. */
function entity_cached(PDO $pdo, string $kind, string $name): array {
    try {
        $st = $pdo->prepare("SELECT same_as FROM entities WHERE kind=? AND name=?");
        $st->execute([$kind, trim($name)]);
        $row = $st->fetch();
        return $row ? (json_decode($row['same_as'] ?: '[]', true) ?: []) : [];
    } catch (Throwable $e) { return []; }
}

/**
 * Resolve a PERSON to Wikidata + Wikipedia + official site/socials. Verifies the
 * entity is a human (P31=Q5) so a same-named company/song never leaks in. 2 API
 * calls, then cached. Returns ['qid'=>?, 'sameAs'=>[...]].
 */
function entity_resolve_person(PDO $pdo, string $name): array {
    entity_init($pdo);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) < 2) return ['qid' => null, 'sameAs' => []];

    require_once __DIR__ . '/images.php';  // wm_api_host()
    $sameAs = []; $qid = null;
    // a candidate's one-line description must read like a public figure / creator —
    // precision over recall: better to emit no sameAs than to claim the wrong person.
    // r29: BROADENED. The old whitelist listed 'athlete' but Wikidata actually
    // writes "American football player" / "basketball player", so EVERY athlete
    // (and many other public figures) failed this pre-filter, resolved to no
    // sameAs, and was silently dropped from people_json by the caller — Tom
    // Brady vanished from his own story, leaving one cover photo to be reused
    // ~10 times in the video. Sports positions + common public professions added.
    $figure = '/youtub|stream|rapper|music|internet|content|influenc|comedian|personality|presenter|\bhost\b|gamer|podcast|tiktok|twitch|singer|actor|actress|media|celebrity|public figure|drag queen|\bmodel\b|wrestler|boxer|athlete|entrepreneur'
            . '|player|footballer|quarterback|basketball|baseball|soccer|hockey|tennis|golfer|sprinter|gymnast|skater|fighter|mma|ufc|nfl|nba|mlb'
            . '|politician|journalist|author|writer|director|producer|dancer|artist|chef|designer|businessman|businesswoman|executive|coach|broadcaster|television|\bTV\b|reality|socialite|philanthropist/i';

    $s = wm_api_host('www.wikidata.org', ['action' => 'wbsearchentities', 'search' => $name,
        'language' => 'en', 'type' => 'item', 'limit' => 5]);
    // r29 TWO PASSES, precision first: pass 1 = candidates whose description
    // reads like a public figure; pass 2 = anything else, admitted ONLY when it
    // has an English Wikipedia article (a strong, description-independent
    // notability signal). Wikidata returns candidates in relevance order, so the
    // famous bearer of a name still wins. Still never claims a non-human.
    $pass1 = []; $pass2 = [];
    foreach (($s['search'] ?? []) as $cand) {
        if (empty($cand['id'])) continue;
        if (preg_match($figure, (string)($cand['description'] ?? ''))) $pass1[] = $cand;
        else $pass2[] = $cand;
    }
    foreach ([[$pass1, false], [$pass2, true]] as [$list, $needWiki]) {
        foreach ($list as $cand) {
            $q = $cand['id'];
            $ent = wm_api_host('www.wikidata.org', ['action' => 'wbgetentities', 'ids' => $q,
                'props' => 'claims|sitelinks/urls|descriptions', 'languages' => 'en']);
            $e = $ent['entities'][$q] ?? null;
            if (!$e) continue;
            // must be a human
            $isHuman = false;
            foreach (($e['claims']['P31'] ?? []) as $c)
                if (($c['mainsnak']['datavalue']['value']['id'] ?? '') === 'Q5') { $isHuman = true; break; }
            if (!$isHuman) continue;
            if ($needWiki && empty($e['sitelinks']['enwiki']['url'])) continue;
            $description = $e['descriptions']['en']['value'] ?? ($cand['description'] ?? '');

            $qid = $q;
            $sameAs[] = "https://www.wikidata.org/wiki/$q";
            if (!empty($e['sitelinks']['enwiki']['url'])) $sameAs[] = $e['sitelinks']['enwiki']['url'];
            // official site + socials
            $map = [
                'P856'  => fn($v) => $v,                                            // official website
                'P2002' => fn($v) => "https://x.com/$v",                            // X / Twitter
                'P2397' => fn($v) => "https://www.youtube.com/channel/$v",          // YouTube channel id
                'P7085' => fn($v) => "https://www.tiktok.com/@$v",                  // TikTok
                'P2003' => fn($v) => "https://www.instagram.com/$v",               // Instagram
                'P6634' => fn($v) => "https://www.linkedin.com/in/$v",             // LinkedIn
            ];
            foreach ($map as $p => $fmt) {
                $v = $e['claims'][$p][0]['mainsnak']['datavalue']['value'] ?? null;
                if (is_string($v) && $v !== '') $sameAs[] = $fmt($v);
            }
            break 2;
        }
    }
    $sameAs = array_values(array_unique($sameAs));
    $pdo->prepare("INSERT INTO entities (kind,name,qid,same_as,fetched_at) VALUES ('person',?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE qid=VALUES(qid), same_as=VALUES(same_as), fetched_at=NOW()")
        ->execute([$name, $qid, json_encode($sameAs, JSON_UNESCAPED_SLASHES)]);
    return ['qid' => $qid, 'sameAs' => $sameAs, 'description' => $description ?? ''];
}

/**
 * A TERM's sameAs = its authoritative reference pages, extracted from the sources we
 * already fetched (Wikipedia / Wiktionary / KnowYourMeme entry, but NOT a search URL).
 * No network call. Caches under kind='term'.
 */
function entity_resolve_term(PDO $pdo, string $term, array $sources): array {
    entity_init($pdo);
    $auth = ['en.wikipedia.org', 'en.wiktionary.org', 'knowyourmeme.com'];
    $sameAs = [];
    foreach ($sources as $s) {
        $u = is_array($s) ? ($s['url'] ?? '') : (string)$s;
        if ($u === '' || str_contains($u, '/search?') || str_contains($u, '/search/')) continue;  // skip search URLs
        $host = parse_url($u, PHP_URL_HOST) ?: '';
        if (in_array($host, $auth, true)) $sameAs[] = $u;
    }
    $sameAs = array_values(array_unique($sameAs));
    $pdo->prepare("INSERT INTO entities (kind,name,qid,same_as,fetched_at) VALUES ('term',?,NULL,?,NOW())
                   ON DUPLICATE KEY UPDATE same_as=VALUES(same_as), fetched_at=NOW()")
        ->execute([trim($term), json_encode($sameAs, JSON_UNESCAPED_SLASHES)]);
    return ['sameAs' => $sameAs];
}
