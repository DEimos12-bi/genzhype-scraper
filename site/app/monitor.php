<?php
/**
 * Site monitors (2026-06-17). Two self-contained health checks that gather their own
 * targets, create their own tables, store results, and surface in the admin Monitor
 * tab. Run weekly from the existing cron tick (no new hPanel job needed) or by hand:
 *   php app/cli.php psi          php app/cli.php linkaudit
 *
 *  1) monitor_psi()   - Lighthouse scores via Google PageSpeed Insights API (the
 *                       shared-hosting-native stand-in for Lighthouse CI).
 *  2) monitor_links() - HTTP-validates every outbound source link (the lychee
 *                       stand-in); flags broken/redirected citations.
 */

/** Create the two monitor tables if missing (idempotent, self-contained). */
function monitor_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS psi_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(255) NOT NULL,
        strategy VARCHAR(10) NOT NULL DEFAULT 'mobile',
        perf TINYINT NULL, seo TINYINT NULL, accessibility TINYINT NULL, best_practices TINYINT NULL,
        lcp_ms INT NULL, cls DECIMAL(6,3) NULL, tbt_ms INT NULL, fcp_ms INT NULL, inp_ms INT NULL,
        checked_at DATETIME NOT NULL,
        KEY url_idx (url), KEY checked_idx (checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS link_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(512) NOT NULL,
        status SMALLINT NOT NULL,
        kind VARCHAR(12) NOT NULL,
        final_url VARCHAR(512) NULL,
        pages TEXT NULL,
        checked_at DATETIME NOT NULL,
        KEY checked_idx (checked_at), KEY kind_idx (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Days since the most recent row in a monitor table (PHP_INT_MAX if never run). */
function monitor_days_since(PDO $pdo, string $table): float {
    try {
        $t = $pdo->query("SELECT MAX(checked_at) FROM {$table}")->fetchColumn();
    } catch (Throwable $e) { return PHP_INT_MAX; }
    if (!$t) return PHP_INT_MAX;
    return (time() - strtotime($t)) / 86400;
}

/** Pick representative pages so every distinct template gets a score: the homepage,
 *  the newest indexed drama, and the newest indexed entry in each lane (slang, meme,
 *  gaming). Falls back to any published page if a lane has no indexed entry yet. */
function monitor_target_urls(PDO $pdo): array {
    $base = rtrim($GLOBALS['CONFIG']['base_url'] ?? 'https://genzhype.com', '/');
    $urls = [$base . '/'];
    $d = $pdo->query("SELECT path FROM pages WHERE type='drama' AND status='published' AND robots='index'
                      ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
    if ($d) $urls[] = $base . $d;
    $q = $pdo->prepare("SELECT p.path FROM pages p JOIN terms t ON t.page_id=p.id
                        WHERE p.status='published' AND p.robots='index' AND t.lane=?
                        ORDER BY p.updated_at DESC LIMIT 1");
    foreach (['slang', 'meme', 'gaming'] as $lane) {
        $q->execute([$lane]);
        if ($path = $q->fetchColumn()) $urls[] = $base . $path;
    }
    return array_values(array_unique($urls));
}

/** One PageSpeed Insights call -> normalized scores + lab Core Web Vitals. */
function psi_fetch(string $url, string $strategy = 'mobile'): ?array {
    $key = $GLOBALS['CONFIG']['psi_key'] ?? ($GLOBALS['CONFIG']['youtube_key'] ?? '');
    $api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . rawurlencode($url)
         . '&strategy=' . $strategy
         . '&category=performance&category=seo&category=accessibility&category=best-practices'
         . ($key ? '&key=' . $key : '');
    $j = null;
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 70, CURLOPT_CONNECTTIMEOUT => 12]);
        $raw = curl_exec($ch); curl_close($ch);
        $j = $raw ? json_decode($raw, true) : null;
        if ($j && !isset($j['error'])) break;
        $msg = $j['error']['message'] ?? '';
        // transient: API just enabled (propagation), or rate blip -> short backoff + retry
        if (stripos($msg, 'has not been used') === false && stripos($msg, 'propagat') === false
            && stripos($msg, 'rate') === false && stripos($msg, 'try again') === false && $raw) break;
        usleep(6000000);
    }
    if (!$j) return null;
    if (isset($j['error'])) return ['error' => $j['error']['message'] ?? 'no data'];
    $cat = $j['lighthouseResult']['categories'] ?? [];
    $aud = $j['lighthouseResult']['audits'] ?? [];
    $sc = fn($k) => isset($cat[$k]['score']) ? (int)round($cat[$k]['score'] * 100) : null;
    $nv = fn($k) => isset($aud[$k]['numericValue']) ? $aud[$k]['numericValue'] : null;
    $inp = $j['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile']
        ?? ($j['loadingExperience']['metrics']['EXPERIMENTAL_INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null);
    return [
        'perf' => $sc('performance'), 'seo' => $sc('seo'),
        'accessibility' => $sc('accessibility'), 'best_practices' => $sc('best-practices'),
        'lcp_ms' => $nv('largest-contentful-paint') !== null ? (int)round($nv('largest-contentful-paint')) : null,
        'cls'    => $nv('cumulative-layout-shift') !== null ? round($nv('cumulative-layout-shift'), 3) : null,
        'tbt_ms' => $nv('total-blocking-time') !== null ? (int)round($nv('total-blocking-time')) : null,
        'fcp_ms' => $nv('first-contentful-paint') !== null ? (int)round($nv('first-contentful-paint')) : null,
        'inp_ms' => $inp !== null ? (int)$inp : null,
    ];
}

/** Run PSI across the target pages, store a row each. Returns a summary. */
function monitor_psi(PDO $pdo, int $budget = 220): array {
    monitor_init($pdo);
    $now = date('Y-m-d H:i:s');
    $urls = monitor_target_urls($pdo);
    $ins = $pdo->prepare("INSERT INTO psi_scores
        (url,strategy,perf,seo,accessibility,best_practices,lcp_ms,cls,tbt_ms,fcp_ms,inp_ms,checked_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $done = 0; $errs = []; $start = time();
    foreach ($urls as $u) {
        if (time() - $start > $budget) { $errs[] = "budget hit at $done/" . count($urls); break; }
        $r = psi_fetch($u, 'mobile');
        if (!$r || isset($r['error'])) { $errs[] = $u . ': ' . ($r['error'] ?? 'no data'); continue; }
        $ins->execute([$u, 'mobile', $r['perf'], $r['seo'], $r['accessibility'], $r['best_practices'],
            $r['lcp_ms'], $r['cls'], $r['tbt_ms'], $r['fcp_ms'], $r['inp_ms'], $now]);
        $done++;
        echo "  PSI $u  perf={$r['perf']} seo={$r['seo']} a11y={$r['accessibility']} bp={$r['best_practices']}\n";
    }
    foreach ($errs as $e) echo "  PSI-ERR $e\n";
    return ['checked' => $done, 'errors' => $errs, 'targets' => count($urls)];
}

/** Gather every outbound SOURCE url on published pages, HTTP-check, store issues. */
/**
 * r91: a citation that lands on the publisher's FRONT PAGE is dead, however
 * cheerful its status code. Variety and the Times of India both answer 200
 * for articles we cite and hand back the homepage — from our own server as
 * well as from a runner, so this is neither link-rot-we-can-argue-with nor a
 * bot wall. Filed as "redirect" it reads as harmless housekeeping; it is in
 * fact a source the reader cannot check, on a site whose whole promise is
 * that every claim has one.
 */
function monitor_link_is_gone(string $url, string $final): bool {
    if ($final === '') return false;
    $fp = trim((string)(parse_url($final, PHP_URL_PATH) ?: ''), '/');
    $op = trim((string)(parse_url($url, PHP_URL_PATH) ?: ''), '/');
    if ($op === '') return false;                 // we asked for a homepage
    if ($fp === '') return true;                  // article -> front page
    // also gone: a deep article collapsed to a bare section ("/news")
    return substr_count($op, '/') >= 2 && substr_count($fp, '/') === 0
        && mb_strlen($fp) <= 12;
}

function monitor_links(PDO $pdo, int $budget = 220, bool $fixDead = false): array {
    monitor_init($pdo);
    require_once __DIR__ . '/helpers.php';
    $map = [];
    $add = function (?string $u, string $page) use (&$map) {
        $u = trim((string)$u);
        if ($u === '' || !preg_match('#^https?://#i', $u)) return;
        $map[$u]['pages'][$page] = 1;
    };
    foreach ($pdo->query("SELECT p.slug, s.url FROM pages p
                          JOIN dramas d ON d.page_id=p.id JOIN events e ON e.drama_id=d.id JOIN sources s ON s.id=e.source_id
                          WHERE p.type='drama' AND p.status='published'")->fetchAll() as $r) $add($r['url'], 'drama/' . $r['slug']);
    $terms = $pdo->query("SELECT p.id,p.slug,t.lane,t.sources FROM pages p JOIN terms t ON t.page_id=p.id
                          WHERE p.type='term' AND p.status='published'")->fetchAll();
    foreach ($terms as $r) {
        $pre = ($r['lane'] === 'meme' ? 'meme/' : ($r['lane'] === 'gaming' ? 'gaming/' : 'slang/')) . $r['slug'];
        foreach ((json_decode($r['sources'] ?: '[]', true) ?: []) as $s) $add(is_array($s) ? ($s['url'] ?? '') : (string)$s, $pre);
    }
    $urls = array_keys($map);
    $now = date('Y-m-d H:i:s');
    $issues = []; $okN = 0; $checked = 0; $start = time(); $cut = false;
    foreach ($urls as $u) {
        if (time() - $start > $budget) { $cut = true; break; }
        $r = link_status($u, 12); $checked++;
        $pages = implode(', ', array_keys($map[$u]['pages']));
        // SEO-BATCH-1: THREE outcomes, not two. "blocked" is a bot-wall (Reddit
        // 403, X 404/503 for a live post) — it is NOT evidence the link is dead,
        // and it must never reach the $fixDead path below, which blanks URLs.
        // Measured before this change: 6 reported broken, only 2 actually dead.
        if (!empty($r['blocked']) && !$r['ok']) $issues[] = [$u, $r['status'], 'blocked', $r['final'], $pages];
        elseif (!empty($r['blocked']))          { $okN++; }          // wall, but verified alive
        elseif (!$r['ok'])        $issues[] = [$u, $r['status'], 'broken',   $r['final'], $pages];
        elseif ($r['redirected'] && monitor_link_is_gone($u, (string)$r['final']))
                                  $issues[] = [$u, $r['status'], 'gone',     $r['final'], $pages];
        elseif ($r['redirected']) $issues[] = [$u, $r['status'], 'redirect', $r['final'], $pages];
        else                      $okN++;
    }
    // replace the issue snapshot only when the sweep completed (avoid wiping on a partial run)
    if (!$cut) {
        $pdo->exec("DELETE FROM link_issues");
        $ins = $pdo->prepare("INSERT INTO link_issues (url,status,kind,final_url,pages,checked_at) VALUES (?,?,?,?,?,?)");
        foreach ($issues as $it) $ins->execute([$it[0], $it[1], $it[2], $it[3], $it[4], $now]);
    }
    if ($fixDead) {
        $dead = array_column(array_filter($issues, fn($i) => $i[2] === 'broken'), 0);
        $dead = array_flip($dead);
        foreach ($terms as $r) {
            $src = json_decode($r['sources'] ?: '[]', true); if (!is_array($src)) continue; $ch = false;
            foreach ($src as &$s) { if (is_array($s) && isset($dead[$s['url'] ?? ''])) { $s['url'] = ''; $ch = true; } }
            unset($s);
            if ($ch) $pdo->prepare("UPDATE terms SET sources=? WHERE page_id=?")->execute([json_encode($src, JSON_UNESCAPED_SLASHES), $r['id']]);
        }
    }
    $broken = count(array_filter($issues, fn($i) => $i[2] === 'broken'));
    $redir  = count(array_filter($issues, fn($i) => $i[2] === 'redirect'));
    $blocked = count(array_filter($issues, fn($i) => $i[2] === 'blocked'));   // SEO-BATCH-1: bot-walls, NOT dead links
    echo "  LINKS checked $checked/" . count($urls) . " · $okN ok · $redir redirect · $broken broken · $blocked blocked(bot-wall)" . ($cut ? " (budget hit, snapshot kept)\n" : "\n");
    foreach ($issues as $it) if ($it[2] === 'broken') echo "    BROKEN {$it[1]}  {$it[0]}  [{$it[4]}]\n";
    foreach ($issues as $it) if ($it[2] === 'blocked') echo "    BLOCKED {$it[1]} (bot-wall, NOT dead - never auto-removed)  {$it[0]}\n";
    return ['checked' => $checked, 'total' => count($urls), 'ok' => $okN, 'broken' => $broken, 'redirect' => $redir, 'blocked' => $blocked, 'complete' => !$cut];
}
