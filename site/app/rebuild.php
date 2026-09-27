<?php
// GenZHype | MAKE AN EXISTING PAGE AGAIN FROM THE BEGINNING (owner 2026-09-26: "choose another 6 pages, run
// the pipeline to make them again from the beginning ... run the test on them"). The pipeline does the work,
// not a hand edit: fresh sources, the writer a new page gets (draft_drama / draft_term in rebuild mode), then
// the checks a new page gets (story_checks.php; the term gate + quality department). The page keeps its
// address; a copy of everything it held is written to storage/rebuilds/ first. A live page stays live: the
// index rules decide only whether Google is offered it, and its published date never moves. The writer
// closes it to Google in the same save as the new words (a run cut off before the checks leaves it closed).
//   php app/cli.php rebuild <pageId>[,<pageId>...] [write|check|restore]   (no word = write + check)

require_once __DIR__ . '/db.php';

/** A copy of the page as it is now (page row + story, timeline, questions, or the term entry). Its path.
 *  $sub 'replaced': the copy page_restore() keeps of the version it takes down (not a rebuild's copy). */
function rebuild_backup(PDO $pdo, int $pageId, string $sub = ''): string {
    $one = function (string $sql, array $args = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); };
    $out = ['taken_at' => gmdate('c'), 'page' => $one("SELECT * FROM pages WHERE id=?", [$pageId])[0] ?? null];
    if (($out['page']['type'] ?? '') === 'drama') {
        $out['drama'] = $one("SELECT * FROM dramas WHERE page_id=?", [$pageId])[0] ?? null;
        $did = (int)($out['drama']['id'] ?? 0);
        $out['events'] = $one("SELECT e.*, s.url source_url FROM events e LEFT JOIN sources s ON s.id=e.source_id WHERE e.drama_id=? ORDER BY e.sort_order", [$did]);
        $out['faqs'] = $one("SELECT * FROM faqs WHERE drama_id=? ORDER BY sort_order", [$did]);
    } else {
        $out['term'] = $one("SELECT * FROM terms WHERE page_id=?", [$pageId])[0] ?? null;
    }
    $dir = dirname(__DIR__) . '/storage/rebuilds' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = "{$dir}/{$pageId}-" . gmdate('Ymd-His') . '.json';
    file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $path;
}

/** The candidate a story was built from (its search, angle, people and links), from the page's build record. */
function rebuild_candidate(PDO $pdo, int $pageId): ?array {
    $st = $pdo->prepare("SELECT build FROM work_record WHERE kind='drama' AND page_id=? AND ref='' LIMIT 1");
    $st->execute([$pageId]);
    $cid = (int)(json_decode((string)$st->fetchColumn(), true)['candidate_id'] ?? 0);
    if (!$cid) return null;
    $c = $pdo->prepare("SELECT * FROM candidates WHERE id=?");
    $c->execute([$cid]);
    return $c->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The newest copy rebuild_backup() kept of a page ('' when none): what the check half compares against. */
function rebuild_last_backup(int $pageId): string {
    $all = glob(dirname(__DIR__) . "/storage/rebuilds/{$pageId}-*.json") ?: [];
    sort($all);
    return (string)end($all);
}

/**
 * A story made again: where it began (its candidate, else its title) + the articles it cites, then the
 * full pipeline. $step 'write' (sources + writer), 'check' (the checks + the index decision, on a page
 * the write half made) or 'all'. The halves exist because the whole run can pass 10 minutes of AI.
 */
function story_rebuild(PDO $pdo, int $pageId, string $step = 'all'): array {
    require_once __DIR__ . '/fetch_sources.php';
    require_once __DIR__ . '/draft.php';
    require_once __DIR__ . '/story_checks.php';
    $st = $pdo->prepare("SELECT p.id, p.h1, p.path, p.status, p.human_review, d.id did, d.lane, d.primary_kw
                         FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=? AND p.type='drama'");
    $st->execute([$pageId]);
    if (!($p = $st->fetch(PDO::FETCH_ASSOC))) return ['error' => 'not a story page'];

    if ($step !== 'check') {
        $cand = rebuild_candidate($pdo, $pageId);
        $verdict = json_decode((string)($cand['ai_verdict'] ?? '{}'), true) ?: [];
        $signals = json_decode((string)($cand['signals'] ?? '{}'), true) ?: [];
        $query = trim((string)($verdict['search_query'] ?? '')) ?: ($cand ? $cand['name'] . ' explained' : (string)$p['h1']);
        $seeds = [];
        foreach (['url', 'permalink', 'external_url'] as $k) if (!empty($signals[$k])) $seeds[] = (string)$signals[$k];
        // the articles the page cites (original posts come back through the articles that embed them)
        $cited = $pdo->prepare("SELECT DISTINCT s.url FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=? AND e.video_only=0");
        $cited->execute([(int)$p['did']]);
        foreach ($cited->fetchAll(PDO::FETCH_COLUMN) as $u)
            if (!preg_match('#//([^/]+\.)?(x|twitter|tiktok|instagram|youtube|youtu|reddit|facebook|twitch|threads|bsky|kick)\.(com|be|tv|net|app)/#i', (string)$u)) $seeds[] = (string)$u;
        echo "  sources: searching \"{$query}\" + " . count($seeds) . " link(s) the story began from or cites\n";
        $sources = fs_fetch_sources($query, array_slice(array_values(array_unique($seeds)), 0, 8), 4);
        if (isset($sources['error'])) return ['error' => 'sources: ' . $sources['error'] . ' (page left as it was)'];
        echo "  sources: " . count($sources) . " read (" . implode(', ', array_unique(array_map(fn($s) => (string)$s['publisher'], $sources))) . ")\n";

        $backup = rebuild_backup($pdo, $pageId);
        $d = draft_drama(['topic' => trim((string)($verdict['angle'] ?? '')) ?: ($cand['name'] ?? '') ?: ((string)$p['primary_kw'] ?: (string)$p['h1']),
                          'people' => (array)($verdict['primary_people'] ?? []), 'sources' => $sources,
                          'lane' => (string)$p['lane'], 'rebuild_page_id' => $pageId]);
        $pdo = db_alive();   // drafting = minutes of AI
        if (isset($d['error'])) return ['error' => 'writer: ' . $d['error'], 'backup' => $backup];
        echo "  written by {$d['provider']}: {$d['events']} events, " . (int)$d['embeds'] . " embeds\n";
        try {
            require_once __DIR__ . '/record.php';
            record_touch($pdo, 'drama', $pageId, '', 'build', ['rebuilt' => ['written_at' => gmdate('c'), 'provider' => (string)$d['provider'],
                'events' => (int)$d['events'], 'backup' => basename($backup)]]);
        } catch (Throwable $e) { error_log('record rebuild: ' . $e->getMessage()); }
        if ($step === 'write') return ['written' => true, 'backup' => $backup, 'path' => $p['path']];
    }
    $backup = rebuild_last_backup($pageId);
    if ($backup === '') return ['error' => 'nothing to check: this page was never rebuilt'];
    $before = json_decode((string)file_get_contents($backup), true);

    $c = story_checks($pdo, $pageId, 'tavily');   // an old page: Tavily (owner 2026-09-25; Exa is for new stories)
    $pdo = db_alive();
    $seo = seo_audit_page($pageId);
    $fails = [];
    foreach (($c['g']['checks'] ?? []) as $ck) if (empty($ck['pass'])) $fails[] = 'gate: ' . $ck['label'];
    if (!($c['q']['pass'] ?? false)) $fails[] = 'editor: ' . implode('; ', array_slice((array)($c['q']['flags'] ?? []), 0, 3)) . ' ' . json_encode($c['q']['scores'] ?? null);
    if (!($c['v']['pass'] ?? false)) $fails[] = 'fact check: ' . mb_substr(json_encode(array_slice((array)($c['v']['issues'] ?? []), 0, 2), JSON_UNESCAPED_UNICODE), 0, 200);
    foreach (($seo['fails'] ?? []) as $sf) $fails[] = 'seo: ' . $sf;

    // the public date moves only when the timeline gained a newer dated event (real dates, 2026-09-26)
    $oldLast = (string)max(array_merge([''], array_column(array_filter((array)($before['events'] ?? []), fn($e) => empty($e['video_only'])), 'event_date')));
    $newLast = (string)$pdo->query("SELECT MAX(event_date) FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0")->fetchColumn();
    if ($newLast > $oldLast) page_content_touched($pdo, $pageId);

    $indexed = null;
    if ($p['status'] === 'published') {
        // new words nobody has read: a grave story goes back on the owner's Human check list, and stays live
        require_once __DIR__ . '/human_review.php';
        if ($p['human_review'] === 'approved') $pdo->prepare("UPDATE pages SET human_review=NULL WHERE id=?")->execute([$pageId]);
        $hold = hr_hold($pdo, $pageId);
        if ($hold !== '') echo "  human check: on the list again ({$hold})\n";
        $why = $c['ok'] && !empty($seo['pass']) ? drama_index_block($pdo, $pageId) : '';
        if ($why !== '') $fails[] = 'index rules: ' . $why;
        $indexed = $c['ok'] && !empty($seo['pass']) && $why === '';
        $pdo->prepare("UPDATE pages SET robots=?, updated_at=NOW() WHERE id=?")->execute([$indexed ? 'index' : 'noindex', $pageId]);
        if ($indexed) indexnow_ping([rtrim((string)$GLOBALS['CONFIG']['base_url'], '/') . $p['path']]);
    }
    try {
        require_once __DIR__ . '/record.php';
        record_touch($pdo, 'drama', $pageId, '', 'build', ['rebuilt' => ['checked_at' => gmdate('c'), 'ready' => $c['ok'], 'indexed' => $indexed,
            'fails' => $fails, 'backup' => basename($backup)]]);
    } catch (Throwable $e) { error_log('record rebuild: ' . $e->getMessage()); }
    return ['ok' => $c['ok'] && !empty($seo['pass']), 'indexed' => $indexed, 'fails' => $fails, 'backup' => $backup, 'path' => $p['path']];
}

/** A term entry made again: the term drafter's own fresh sources and writer, then the term gate + quality department. */
function term_rebuild(PDO $pdo, int $pageId, string $step = 'all'): array {
    require_once __DIR__ . '/draft_term.php';
    require_once __DIR__ . '/gate_term.php';
    require_once __DIR__ . '/gate_quality.php';
    $st = $pdo->prepare("SELECT p.path, p.status, t.term, t.lane FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.id=? AND p.type='term'");
    $st->execute([$pageId]);
    if (!($p = $st->fetch(PDO::FETCH_ASSOC))) return ['error' => 'not a term page'];
    if ($step !== 'check') {
        $backup = rebuild_backup($pdo, $pageId);
        $d = draft_term(['term' => (string)$p['term'], 'lane' => (string)$p['lane'], 'rebuild_page_id' => $pageId]);
        $pdo = db_alive();
        if (isset($d['error'])) return ['error' => 'writer: ' . $d['error'], 'backup' => $backup];
        echo "  written by {$d['provider']}\n";
        try {
            require_once __DIR__ . '/record.php';
            record_touch($pdo, 'term', $pageId, '', 'build', ['rebuilt' => ['written_at' => gmdate('c'), 'provider' => (string)$d['provider'], 'backup' => basename($backup)]]);
        } catch (Throwable $e) { error_log('record rebuild: ' . $e->getMessage()); }
        if ($step === 'write') return ['written' => true, 'backup' => $backup, 'path' => $p['path']];
    }
    $backup = rebuild_last_backup($pageId);
    if ($backup === '') return ['error' => 'nothing to check: this page was never rebuilt'];
    $g = gate_check_term($pageId);
    $q = gate_quality($pageId, true);
    $seo = seo_audit_page($pageId);
    $fails = [];
    foreach (($g['checks'] ?? []) as $ck) if (empty($ck['pass'])) $fails[] = "gate: {$ck['label']} (" . ($ck['detail'] ?? '') . ')';
    foreach (($q['hard_fails'] ?? []) as $hf) $fails[] = 'quality: ' . $hf;
    foreach (($seo['fails'] ?? []) as $sf) $fails[] = 'seo: ' . $sf;
    $ok = $g['pass'] && empty($q['hard_fails']) && !empty($seo['pass']);
    $indexed = null;
    if ($p['status'] === 'published') {
        $srcN = term_source_domains($pdo, $pageId);
        if ($ok && $srcN < GATE_MIN_SOURCE_DOMAINS) $fails[] = "index rules: {$srcN} source domain(s)";
        $indexed = $ok && $srcN >= GATE_MIN_SOURCE_DOMAINS;
        $pdo->prepare("UPDATE pages SET robots=?, updated_at=NOW() WHERE id=?")->execute([$indexed ? 'index' : 'noindex', $pageId]);
        if ($indexed) indexnow_ping([rtrim((string)$GLOBALS['CONFIG']['base_url'], '/') . $p['path']]);
    }
    try {
        require_once __DIR__ . '/record.php';
        record_touch($pdo, 'term', $pageId, '', 'build', ['rebuilt' => ['checked_at' => gmdate('c'), 'ready' => $ok, 'indexed' => $indexed,
            'fails' => $fails, 'quality' => $q['score'] ?? null, 'backup' => basename($backup)]]);
    } catch (Throwable $e) { error_log('record rebuild: ' . $e->getMessage()); }
    return ['ok' => $ok, 'indexed' => $indexed, 'fails' => $fails, 'backup' => $backup, 'path' => $p['path'], 'quality' => $q['score'] ?? null];
}

/**
 * UNDO A REBUILD (owner 2026-09-26: "put flick back"): the page exactly as the newest rebuild copy holds it,
 * text, timeline, questions or term entry, cover, Google setting and public date. The version it takes down
 * is kept in storage/rebuilds/replaced/ first. One transaction: all of it comes back or none of it.
 */
function page_restore(PDO $pdo, int $pageId, string $from = ''): array {   // $from: a given copy (accuracy.php keeps its own)
    $from = $from !== '' ? $from : rebuild_last_backup($pageId);
    if ($from === '') return ['error' => 'no rebuild copy of this page to put back'];
    $b = json_decode((string)file_get_contents($from), true);
    $pg = $b['page'] ?? null;
    if (!$pg || (int)$pg['id'] !== $pageId) return ['error' => "the copy {$from} is not this page"];
    $kept = rebuild_backup($pdo, $pageId, 'replaced');
    $insert = function (string $table, array $row) use ($pdo) {
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO {$table} (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($row));
    };
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE pages SET h1=?, title_tag=?, meta_desc=?, summary=?, cover=?, cover_credit=?, cover_credit_url=?, featured_img=?,
                       robots=?, content_updated_at=?, updated_at=NOW() WHERE id=?")
            ->execute([$pg['h1'], $pg['title_tag'], $pg['meta_desc'], $pg['summary'], $pg['cover'], $pg['cover_credit'], $pg['cover_credit_url'],
                       $pg['featured_img'], $pg['robots'], $pg['content_updated_at'] ?? null, $pageId]);
        if ($pg['type'] === 'drama') {
            $d = $b['drama'];
            $pdo->prepare("UPDATE dramas SET title=?, lifecycle=?, started_on=?, background=?, mood=?, why_matters=?, whats_next=?, both_sides=?, verdict=? WHERE id=?")
                ->execute([$d['title'], $d['lifecycle'], $d['started_on'], $d['background'], $d['mood'], $d['why_matters'], $d['whats_next'], $d['both_sides'], $d['verdict'], (int)$d['id']]);
            $pdo->prepare("DELETE FROM events WHERE drama_id=? AND video_only=0")->execute([(int)$d['id']]);
            $pdo->prepare("DELETE FROM faqs WHERE drama_id=?")->execute([(int)$d['id']]);
            foreach ((array)$b['events'] as $e) if (empty($e['video_only'])) { unset($e['source_url']); $insert('events', $e); }
            foreach ((array)$b['faqs'] as $q) $insert('faqs', $q);
        } else {
            $pdo->prepare("DELETE FROM terms WHERE page_id=?")->execute([$pageId]);
            $insert('terms', $b['term']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
        return ['error' => 'not put back (nothing changed): ' . $e->getMessage(), 'backup' => $kept];
    }
    try {
        require_once __DIR__ . '/record.php';
        record_touch($pdo, $pg['type'] === 'drama' ? 'drama' : 'term', $pageId, '', 'build', ['rebuilt' => ['restored_at' => gmdate('c'), 'from' => basename($from), 'kept' => 'replaced/' . basename($kept)]]);
    } catch (Throwable $e) { error_log('record restore: ' . $e->getMessage()); }
    return ['restored' => true, 'from' => $from, 'kept' => $kept, 'path' => $pg['path'], 'robots' => $pg['robots']];
}

/** Either kind, by the page's type. $step: 'all', 'write' or 'check'. */
function page_rebuild(PDO $pdo, int $pageId, string $step = 'all'): array {
    $t = (string)$pdo->query("SELECT type FROM pages WHERE id=" . $pageId)->fetchColumn();
    if ($t === 'drama') return story_rebuild($pdo, $pageId, $step);
    if ($t === 'term') return term_rebuild($pdo, $pageId, $step);
    return ['error' => $t === '' ? 'no such page' : "a {$t} page is not rebuilt"];
}
