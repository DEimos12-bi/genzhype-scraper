<?php
/**
 * GenZHype | Competitor Intelligence Engine — the PHP BRAIN.
 *
 * The external Python watcher (GitHub Actions, reuses scraper.py's browser-TLS) crawls
 * competitors and POSTs compact signal JSON to /api/comp_ingest.php. This file owns the
 * storage, the two-tier-hash change detection, the field-level diff, and the living
 * rulebook. Pattern lifted from changedetection.io (MD5-of-normalized-text is the change
 * signal; filter/normalize BEFORE hashing so dynamic chrome never fires a false change).
 *
 * Also ships a PHP JSON-LD extractor (ported from extruct/jsonld.py) used both to read
 * competitor schema and to self-check OUR own pages against the learned rules.
 */

function competitor_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS competitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(190) NOT NULL,
        name VARCHAR(190) NULL,
        tier ENUM('big','mid','peer') NOT NULL DEFAULT 'peer',
        seen_count INT NOT NULL DEFAULT 0,
        added_at DATETIME NOT NULL,
        UNIQUE KEY uniq_domain (domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_watch (
        id INT AUTO_INCREMENT PRIMARY KEY,
        competitor_id INT NULL,
        url VARCHAR(700) NOT NULL,
        url_hash CHAR(32) NOT NULL,
        watch_type ENUM('page','sitemap') NOT NULL DEFAULT 'page',
        interval_sec INT NOT NULL DEFAULT 86400,
        filter_config_hash CHAR(32) NULL,
        last_checked INT NOT NULL DEFAULT 0,
        last_content_hash CHAR(32) NULL,
        last_raw_hash CHAR(32) NULL,
        paused TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_url (url_hash), KEY tier_idx (competitor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_snapshot (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        watch_id INT NOT NULL,
        captured_at INT NOT NULL,
        content_hash CHAR(32) NOT NULL,
        fields_json MEDIUMTEXT NULL,
        KEY w_time (watch_id, captured_at), KEY w_hash (watch_id, content_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_change (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        watch_id INT NOT NULL,
        detected_at INT NOT NULL,
        change_type VARCHAR(40) NOT NULL,
        field VARCHAR(60) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        KEY w_time (watch_id, detected_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // watcher self-diagnosis: every run logs which engine it used + per-competitor counts +
    // errors, so the pipeline is observable from the DB without reading GitHub Action logs.
    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_run_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        run_at INT NOT NULL,
        engine VARCHAR(40) NULL,
        harvested INT NOT NULL DEFAULT 0,
        delivered_json TEXT NULL,
        domains_json MEDIUMTEXT NULL,
        notes TEXT NULL,
        KEY t (run_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // DESIGN intelligence: one row per site (rivals + ours) holding the full design-token
    // bundle dembrandt extracts (palette, typography, spacing, components). The distiller
    // diffs rivals-vs-ours into design rules that guide our redesign.
    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_design (
        id INT AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(190) NOT NULL,
        is_ours TINYINT NOT NULL DEFAULT 0,
        url VARCHAR(700) NULL,
        tokens_json MEDIUMTEXT NULL,
        captured_at INT NOT NULL,
        UNIQUE KEY uniq_domain (domain), KEY t (captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // BACKLINK / off-page intelligence: per-site domain authority + referring domains, so the
    // distiller can benchmark rivals' authority vs ours and surface the link-opportunity list
    // (domains linking to rivals but not us = exactly where to go get backlinks).
    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_backlink (
        id INT AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(190) NOT NULL,
        is_ours TINYINT NOT NULL DEFAULT 0,
        authority DECIMAL(5,2) NULL,
        rank_position BIGINT NULL,
        ref_domains_json MEDIUMTEXT NULL,
        ref_count INT NOT NULL DEFAULT 0,
        captured_at INT NOT NULL,
        UNIQUE KEY uniq_domain (domain), KEY t (captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // PER-RIVAL PROFILE: the deep breakdown the corpus distiller computes then discards, now
    // persisted ONE ROW PER RIVAL PER RUN (append-only) so the walk-in dossier renders instantly
    // AND every signal trends over time. profile_json holds depth/structure/schema/sourcing/
    // authors/format/cadence for that single competitor (or our own baseline when is_ours=1).
    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_profile (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        competitor_id INT NULL,
        domain VARCHAR(190) NOT NULL,
        is_ours TINYINT NOT NULL DEFAULT 0,
        profile_json MEDIUMTEXT NULL,
        n_articles INT NOT NULL DEFAULT 0,
        computed_at INT NOT NULL,
        KEY d_time (domain, computed_at), KEY c_time (competitor_id, computed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // the living rulebook: what the winners do, distilled, versioned, feeding our systems
    $pdo->exec("CREATE TABLE IF NOT EXISTS comp_rule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope ENUM('gate','drafter','citation','global') NOT NULL DEFAULT 'global',
        rule_key VARCHAR(80) NOT NULL,
        rule_value MEDIUMTEXT NULL,
        confidence TINYINT NOT NULL DEFAULT 50,
        evidence TEXT NULL,
        version INT NOT NULL DEFAULT 1,
        active TINYINT NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_rule (scope, rule_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Extract every JSON-LD object from HTML (ported from extruct/jsonld.py, including its
 * comment-strip retry, and the modern @graph flattening it only handles implicitly).
 * Returns a flat list of schema objects — inspect each ['@type'], ['sameAs'], etc.
 */
function extract_jsonld(string $html): array {
    if (trim($html) === '') return [];
    $out = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    foreach ((new DOMXPath($dom))->query('//script[@type="application/ld+json"]') as $node) {
        $script = trim($node->textContent);
        if ($script === '') continue;
        $data = json_decode($script, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $cleaned = preg_replace('/^\s*(\/\/.*|<!--.*?-->)/m', '', $script);   // strip leading comments
            $data = json_decode($cleaned, true);
            if ($data === null) continue;
        }
        if (!is_array($data)) continue;
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $it) if (is_array($it)) $out[] = $it;       // dominant 2026 pattern
        } elseif (array_is_list($data)) {
            foreach ($data as $it) if (is_array($it)) $out[] = $it;
        } else {
            $out[] = $data;
        }
    }
    return $out;
}

/** The @type histogram of a page's JSON-LD (e.g. ['NewsArticle'=>1,'FAQPage'=>1]). */
function jsonld_types(array $items): array {
    $h = [];
    foreach ($items as $it) {
        foreach ((array)($it['@type'] ?? []) as $t) if (is_string($t)) $h[$t] = ($h[$t] ?? 0) + 1;
    }
    return $h;
}

/** Normalize a signal bundle for hashing: recursive-ksort + sort arrays so key/order
 *  churn never registers as a change (changedetection.io's anti-noise core). */
function comp_normalize($v) {
    if (is_array($v)) {
        $isList = array_is_list($v);
        $v = array_map('comp_normalize', $v);
        if ($isList) { sort($v); } else { ksort($v); }
    }
    return $v;
}

/** Per-field diff between two normalized field sets → attributed change rows. */
function comp_diff_fields(array $old, array $new): array {
    $changes = [];
    foreach ($new as $k => $nv) {
        $ov = $old[$k] ?? null;
        if ($ov === $nv) continue;
        if (is_array($nv) || is_array($ov)) {
            $added = array_values(array_diff((array)$nv, (array)$ov));
            $removed = array_values(array_diff((array)$ov, (array)$nv));
            if (!$added && !$removed) continue;
            $changes[] = ['type' => "set:$k", 'field' => $k,
                'old' => implode(', ', (array)$removed) ?: null, 'new' => implode(', ', (array)$added) ?: null];
        } else {
            $changes[] = ['type' => $k, 'field' => $k, 'old' => (string)$ov, 'new' => (string)$nv];
        }
    }
    return $changes;
}

/**
 * Ingest one competitor URL's extracted signals (POSTed by the watcher). Two-tier hash:
 * cheap raw-hash skip-gate, then normalized composite hash = the change signal. On a real
 * change: field-diff → comp_change rows, append snapshot, update pointers. First sight of
 * a URL records a baseline without firing changes.
 */
function competitor_ingest(PDO $pdo, array $payload): array {
    competitor_init($pdo);
    $url = trim((string)($payload['url'] ?? ''));
    if ($url === '') return ['error' => 'no url'];
    $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
    $rawHash = (string)($payload['raw_text_hash'] ?? md5(json_encode($fields)));
    $urlHash = md5($url);
    $now = time();

    $w = $pdo->prepare("SELECT * FROM comp_watch WHERE url_hash=?");
    $w->execute([$urlHash]);
    $watch = $w->fetch();
    if (!$watch) {
        // first sight: attach to (or create) the competitor by domain, baseline only.
        // Canonicalize host (lowercase, strip leading www.) so www/non-www URLs from the
        // same site collapse to ONE competitor row instead of splitting the intelligence.
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $host = preg_replace('/^www\./', '', $host);
        $cid = null;
        if ($host) {
            $pdo->prepare("INSERT INTO competitors (domain,tier,seen_count,added_at) VALUES (?, 'peer', 1, NOW())
                           ON DUPLICATE KEY UPDATE seen_count=seen_count+1")->execute([$host]);
            $cid = (int)$pdo->query("SELECT id FROM competitors WHERE domain=" . $pdo->quote($host))->fetchColumn();
        }
        $norm = comp_normalize($fields);
        $ch = md5(json_encode($norm));
        $pdo->prepare("INSERT INTO comp_watch (competitor_id,url,url_hash,watch_type,last_checked,last_content_hash,last_raw_hash)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$cid, mb_substr($url, 0, 700), $urlHash, $payload['watch_type'] ?? 'page', $now, $ch, $rawHash]);
        $wid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO comp_snapshot (watch_id,captured_at,content_hash,fields_json) VALUES (?,?,?,?)")
            ->execute([$wid, $now, $ch, json_encode($norm, JSON_UNESCAPED_SLASHES)]);
        return ['ok' => true, 'first_seen' => true, 'watch_id' => $wid];
    }

    // cheap skip-gate
    if ($watch['last_raw_hash'] === $rawHash) {
        $pdo->prepare("UPDATE comp_watch SET last_checked=? WHERE id=?")->execute([$now, $watch['id']]);
        return ['ok' => true, 'unchanged' => true];
    }
    $norm = comp_normalize($fields);
    $ch = md5(json_encode($norm));
    if ($ch === $watch['last_content_hash']) {
        $pdo->prepare("UPDATE comp_watch SET last_checked=?, last_raw_hash=? WHERE id=?")->execute([$now, $rawHash, $watch['id']]);
        return ['ok' => true, 'unchanged' => true];
    }

    // real change → attribute it against the last snapshot
    $prev = $pdo->prepare("SELECT fields_json FROM comp_snapshot WHERE watch_id=? ORDER BY captured_at DESC LIMIT 1");
    $prev->execute([$watch['id']]);
    $prevFields = json_decode($prev->fetchColumn() ?: '[]', true) ?: [];
    $diffs = comp_diff_fields($prevFields, $norm);
    $ins = $pdo->prepare("INSERT INTO comp_change (watch_id,detected_at,change_type,field,old_value,new_value) VALUES (?,?,?,?,?,?)");
    foreach ($diffs as $d) $ins->execute([$watch['id'], $now, mb_substr($d['type'], 0, 40), mb_substr($d['field'], 0, 60),
        $d['old'] !== null ? mb_substr($d['old'], 0, 2000) : null, $d['new'] !== null ? mb_substr($d['new'], 0, 2000) : null]);
    $pdo->prepare("INSERT INTO comp_snapshot (watch_id,captured_at,content_hash,fields_json) VALUES (?,?,?,?)")
        ->execute([$watch['id'], $now, $ch, json_encode($norm, JSON_UNESCAPED_SLASHES)]);
    $pdo->prepare("UPDATE comp_watch SET last_checked=?, last_content_hash=?, last_raw_hash=? WHERE id=?")
        ->execute([$now, $ch, $rawHash, $watch['id']]);
    return ['ok' => true, 'changed' => true, 'changes' => count($diffs)];
}

/**
 * Read one active rule's value (decoded). This is the single door every other system uses
 * to consume the competitor rulebook — the gate, the drafter, the Citation Engine all call
 * this. Returns $default if the rule isn't set yet (so callers degrade gracefully).
 */
function comp_rule_get(PDO $pdo, string $scope, string $key, $default = null) {
    try {
        $st = $pdo->prepare("SELECT rule_value FROM comp_rule WHERE scope=? AND rule_key=? AND active=1");
        $st->execute([$scope, $key]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) { return $default; }       // table may not exist before first distill
    if ($v === false || $v === null) return $default;
    $dec = json_decode($v, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $dec : $v;   // plain-string rules stay strings
}

/** Store one site's design-token bundle (dembrandt output). Upsert by canonical domain. */
function comp_design_ingest(PDO $pdo, array $payload): array {
    competitor_init($pdo);
    $domain = preg_replace('/^www\./', '', strtolower(trim((string)($payload['domain'] ?? ''))));
    if ($domain === '') return ['error' => 'no domain'];
    $tokens = $payload['tokens'] ?? null;
    $tj = is_string($tokens) ? $tokens : json_encode($tokens, JSON_UNESCAPED_SLASHES);
    if (!$tj || $tj === 'null') return ['error' => 'no tokens'];
    $pdo->prepare("INSERT INTO comp_design (domain,is_ours,url,tokens_json,captured_at) VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE is_ours=VALUES(is_ours), url=VALUES(url), tokens_json=VALUES(tokens_json), captured_at=VALUES(captured_at)")
        ->execute([$domain, !empty($payload['is_ours']) ? 1 : 0, mb_substr((string)($payload['url'] ?? ''), 0, 700), mb_substr($tj, 0, 8000000), time()]);
    return ['ok' => true, 'domain' => $domain];
}

/** Store one site's backlink/off-page snapshot (authority + referring domains). Upsert by domain. */
function comp_backlink_ingest(PDO $pdo, array $payload): array {
    competitor_init($pdo);
    $domain = preg_replace('/^www\./', '', strtolower(trim((string)($payload['domain'] ?? ''))));
    if ($domain === '') return ['error' => 'no domain'];
    $refs = $payload['ref_domains'] ?? [];
    if (!is_array($refs)) $refs = [];
    $refs = array_values(array_unique(array_filter(array_map(fn($d) => preg_replace('/^www\./', '', strtolower(trim((string)$d))), $refs))));
    // Two stages write here: Stage 1 (authority watcher) sends authority+rank, Stage 2 (refs miner)
    // sends ref_domains. NULL out the fields a payload doesn't carry + COALESCE on update so the two
    // stages MERGE instead of clobbering each other (otherwise refs-only runs erase authority & vice versa).
    $hasRefs  = count($refs) > 0;
    $refsJson = $hasRefs ? mb_substr(json_encode($refs, JSON_UNESCAPED_SLASHES), 0, 4000000) : null;
    $refCount = $hasRefs ? count($refs) : null;
    $pdo->prepare("INSERT INTO comp_backlink (domain,is_ours,authority,rank_position,ref_domains_json,ref_count,captured_at)
                   VALUES (?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE is_ours=VALUES(is_ours),
                       authority=COALESCE(VALUES(authority), authority),
                       rank_position=COALESCE(VALUES(rank_position), rank_position),
                       ref_domains_json=COALESCE(VALUES(ref_domains_json), ref_domains_json),
                       ref_count=COALESCE(VALUES(ref_count), ref_count),
                       captured_at=VALUES(captured_at)")
        ->execute([$domain, !empty($payload['is_ours']) ? 1 : 0,
            isset($payload['authority']) ? (float)$payload['authority'] : null,
            isset($payload['rank']) ? (int)$payload['rank'] : null,
            $refsJson, $refCount, time()]);
    return ['ok' => true, 'domain' => $domain, 'refs' => count($refs)];
}

/** Store one watcher run's self-diagnosis (engine, per-domain counts, errors). */
function comp_run_log(PDO $pdo, array $report): void {
    competitor_init($pdo);
    $pdo->prepare("INSERT INTO comp_run_log (run_at,engine,harvested,delivered_json,domains_json,notes)
                   VALUES (?,?,?,?,?,?)")
        ->execute([
            time(),
            mb_substr((string)($report['engine'] ?? ''), 0, 40),
            (int)($report['harvested'] ?? 0),
            json_encode($report['delivered'] ?? null),
            mb_substr((string)json_encode($report['domains'] ?? null, JSON_UNESCAPED_SLASHES), 0, 60000),
            mb_substr((string)($report['notes'] ?? ''), 0, 2000),
        ]);
}
