<?php
// GenZHype | SAME MEME = UPDATE, NOT A NEW PAGE (owner 2026-10-04: "Apply the 'same story = update, not a new page'
// rule to memes and terms as well"; /meme/haminations-cringe/ and /meme/haminations-cringe-spreads-on-tiktok/ were
// both live, the second one a headline about the first).
//
//   ts_words()        a name's own words: no "meme", "trend", "spreads on TikTok", "becomes a meme"
//   ts_suspect()      pure: do two names look like the same thing? (one name's words are all inside the other's)
//   ts_confirm()      a strict AI reading decides: the same meme or term, or only related
//   ts_find()         the existing page a new name belongs to, if any
//   term_merge_into() a copy goes to the page we keep: off the site, its address answers 301 to the keeper
//   ts_mark_update()  the kept page is written again from the new material at its next turn (trend.php tr_rebuild_stuck)
// On only with app/TREND_ON (it sits behind the trend gate in the build's term loop).

require_once __DIR__ . '/db.php';

const TS_STOP = ['the', 'a', 'an', 'of', 'on', 'in', 'to', 'and', 'is', 'as', 'at', 'for', 'with', 'by', 'its', 'it', 'this', 'that',
    'meme', 'memes', 'trend', 'trends', 'trending', 'tiktok', 'tiktoks', 'viral', 'spreads', 'spread', 'spreading', 'becomes', 'become', 'became', 'goes', 'going',
    'message', 'reaction', 'explained', 'meaning', 'video', 'videos', 'format', 'template', 'edits', 'edit', 'sound', 'audio', 'challenge', 'online', 'internet', 'twitter', 'x'];

/** A name's own words, lower case, possessives and punctuation off. */
function ts_words(string $name): array {
    $t = mb_strtolower(str_replace(['’', '‘'], "'", $name));
    $t = preg_replace("/'s\b/u", '', $t);
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    $stop = array_flip(TS_STOP);
    return array_values(array_unique(array_filter(explode(' ', trim($t)), fn($w) => $w !== '' && !isset($stop[$w]))));
}

/**
 * Pure. 'same'    the two names are the same words ("Relax Bro meme" / "Relax Bro")
 *       'suspect' every word of the shorter name (2+ words) is in the longer one ("Haminations Cringe" /
 *                 "Haminations Cringe Spreads On TikTok"): an AI reading decides
 *       ''        different names. One shared word is never enough ("slay" / "slay queen").
 */
function ts_suspect(string $a, string $b): string {
    $wa = ts_words($a); $wb = ts_words($b);
    if (!$wa || !$wb) return '';
    $small = count($wa) <= count($wb) ? $wa : $wb; $large = count($wa) <= count($wb) ? $wb : $wa;
    if (array_diff($small, $large)) return '';
    if (count($small) === count($large)) return 'same';
    return count($small) >= 2 ? 'suspect' : '';
}

/** Strict: the same meme or term (another name or a headline for it), not only the same person, game or topic. ['same' => bool|null, 'why'] */
function ts_confirm(string $newName, string $newNote, string $oldName, string $oldDef): array {
    require_once __DIR__ . '/ai.php';
    require_once __DIR__ . '/story_picker.php';
    $res = ai_chat([['role' => 'user', 'content' => "A: \"{$newName}\"" . ($newNote !== '' ? " ({$newNote})" : '') . "\nB: \"{$oldName}\": " . mb_substr($oldDef, 0, 400)
        . "\n\nIs A the SAME meme, trend or slang term as B, only under another name or as a headline about it? Answer true only when a page about B would already cover A. "
        . "A different meme about the same person, game or show is false. STRICT JSON {\"same\": true|false, \"why\": \"<max 15 words>\"}"]], SP_AI_ORDER, 0.0, 45, sp_ai_skip());
    $j = isset($res['error']) ? null : ai_json((string)$res['content']);
    if (!is_array($j) || !isset($j['same'])) return ['same' => null, 'why' => 'no AI answer'];
    return ['same' => (bool)$j['same'], 'why' => mb_substr((string)($j['why'] ?? ''), 0, 160)];
}

/**
 * The page a new name belongs to. ['page' => id|0, 'term', 'path', 'status', 'why', 'pending' => bool]. 'pending': a
 * suspect was found but no AI answered, so the candidate keeps its turn and nothing is written this run.
 * $skipPageId: the page itself, when two existing pages are compared.
 */
function ts_find(PDO $pdo, string $name, string $note = '', int $skipPageId = 0): array {
    $none = ['page' => 0, 'term' => '', 'path' => '', 'status' => '', 'why' => '', 'pending' => false];
    $rows = $pdo->query("SELECT p.id, p.path, p.status, p.summary, t.term, t.short_def FROM pages p JOIN terms t ON t.page_id=p.id
                         WHERE p.type='term' AND p.status IN ('published','review','draft') ORDER BY (p.status='published') DESC, p.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ((int)$r['id'] === $skipPageId) continue;
        $s = ts_suspect($name, (string)$r['term']);
        if ($s === '') continue;
        if ($s === 'same') return ['page' => (int)$r['id'], 'term' => $r['term'], 'path' => $r['path'], 'status' => $r['status'], 'why' => 'the same name', 'pending' => false];
        $c = ts_confirm($name, $note, (string)$r['term'], (string)($r['short_def'] ?: $r['summary']));
        if ($c['same'] === null) return ['pending' => true, 'why' => "looks like \"{$r['term']}\" and no AI answered"] + $none;
        if ($c['same']) return ['page' => (int)$r['id'], 'term' => $r['term'], 'path' => $r['path'], 'status' => $r['status'], 'why' => $c['why'], 'pending' => false];
    }
    return $none;
}

/** The kept page is written again from the new material at its next turn. Outside a transaction (it may add a column). */
function ts_mark_update(PDO $pdo, int $pageId, string $why): void {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM terms")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('update_due', $cols, true)) $pdo->exec("ALTER TABLE terms ADD COLUMN update_due VARCHAR(200) NULL COMMENT 'new material arrived for this page (term_same.php)'");
    $pdo->prepare("UPDATE terms SET update_due=? WHERE page_id=?")->execute([mb_substr($why, 0, 200), $pageId]);
}

/**
 * A copy goes to the page we keep (the term twin of dup_merge_into): a full copy is saved first, the copy leaves the
 * site, and its address answers 301 to the keeper for good. Nothing is deleted. True when done.
 */
function term_merge_into(PDO $pdo, int $copyId, int $keeperId): bool {
    if ($copyId === $keeperId) return false;
    $k = $pdo->prepare("SELECT path FROM pages WHERE id=? AND type='term' AND status='published'");
    $k->execute([$keeperId]);
    $to = (string)$k->fetchColumn();
    if ($to === '') return false;
    require_once __DIR__ . '/rebuild.php';
    rebuild_backup($pdo, $copyId, 'merged');
    $pdo->prepare("UPDATE pages SET status='archived', robots='noindex', redirect_to=?, updated_at=NOW() WHERE id=? AND type='term'")->execute([$to, $copyId]);
    $pdo->prepare("UPDATE pages SET redirect_to=? WHERE type='term' AND redirect_to=(SELECT path FROM (SELECT path FROM pages WHERE id=?) x)")
        ->execute([$to, $copyId]);   // an earlier merge into the copy follows it to the keeper (no redirect chains)
    return true;
}

/** The live meme and term pages that are the same thing under two names, for the owner's list (merging existing pages is his call). */
function ts_live_pairs(PDO $pdo, bool $confirm = true): array {
    $rows = $pdo->query("SELECT p.id, p.path, p.summary, t.term, t.short_def FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.type='term' AND p.status='published' ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $i => $a) for ($j = $i + 1; $j < count($rows); $j++) {
        $b = $rows[$j];
        $s = ts_suspect((string)$a['term'], (string)$b['term']);
        if ($s === '') continue;
        $why = 'the same name'; $same = true;
        if ($s === 'suspect' && $confirm) { $c = ts_confirm((string)$b['term'], '', (string)$a['term'], (string)($a['short_def'] ?: $a['summary'])); $same = $c['same']; $why = $c['why']; }
        $out[] = ['a' => (int)$a['id'], 'a_path' => $a['path'], 'b' => (int)$b['id'], 'b_path' => $b['path'], 'same' => $same, 'why' => $why];
    }
    return $out;
}

/**
 * A new candidate that is an existing page under another name: no new page. The candidate is closed, the decision is
 * logged (trend_decisions, action 'merge'), and a live trend page is marked to be written again from the new material.
 */
function ts_merge_candidate(PDO $pdo, array $cand, array $same): void {
    try { $pdo->exec("ALTER TABLE trend_decisions MODIFY action ENUM('write','skip','wait','drop','merge') NOT NULL"); } catch (Throwable $e) { /* already there */ }
    $why = "same as page #{$same['page']} \"{$same['term']}\" ({$same['why']}): an update, not a new page";
    $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute([mb_substr("merged into page #{$same['page']}: same meme or term", 0, 255), (int)$cand['id']]);
    $pdo->prepare("INSERT INTO trend_decisions (cand_id, term, action, trend, why) VALUES (?,?,?,?,?)")->execute([(int)$cand['id'], mb_substr((string)$cand['name'], 0, 120), 'merge', 0, mb_substr($why, 0, 400)]);
    if ($same['status'] === 'published') ts_mark_update($pdo, (int)$same['page'], 'cand:' . (int)$cand['id'] . ' ' . $cand['name']);
}

/**
 * One live page with new material is written again from it (called when no stuck draft took the run's rebuild turn). The
 * new version stays only if every check passes; otherwise the page is put back exactly as it was. At most once in 3 days
 * a page. ['line' => log line or '']
 */
function ts_update_one(PDO $pdo): array {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM terms")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('update_due', $cols, true)) return ['line' => ''];
    $r = $pdo->query("SELECT p.id, p.robots, t.term, t.update_due FROM pages p JOIN terms t ON t.page_id=p.id
                      WHERE p.type='term' AND p.status='published' AND t.update_due IS NOT NULL
                        AND (t.trend_rebuilt_at IS NULL OR t.trend_rebuilt_at < UTC_TIMESTAMP() - INTERVAL 3 DAY) ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['line' => ''];
    $pid = (int)$r['id'];
    $seed = preg_match('/^cand:(\d+)/', (string)$r['update_due'], $m) ? (int)$m[1] : 0;
    $pdo->prepare("UPDATE terms SET update_due=NULL, trend_rebuilt_at=UTC_TIMESTAMP() WHERE page_id=?")->execute([$pid]);   // one try, whatever happens
    require_once __DIR__ . '/rebuild.php';
    try { $rr = term_rebuild($pdo, $pid, 'all', $seed); } catch (Throwable $e) { $rr = ['error' => get_class($e) . ': ' . $e->getMessage()]; }
    $pdo = db_alive();
    if (!isset($rr['error']) && !empty($rr['ok'])) return ['line' => "term update: \"{$r['term']}\" written again from new material, checks PASS"];
    $back = !empty($rr['backup']) ? page_restore($pdo, $pid, (string)$rr['backup']) : ['error' => 'no copy'];   // the old version comes back
    $pdo->prepare("UPDATE terms SET update_due=NULL, trend_rebuilt_at=UTC_TIMESTAMP() WHERE page_id=?")->execute([$pid]);
    return ['line' => "term update: \"{$r['term']}\" " . (isset($rr['error']) ? 'not written (' . mb_substr((string)$rr['error'], 0, 80) . ')' : 'did not pass its checks')
                    . (isset($back['error']) ? '; OLD VERSION NOT PUT BACK: ' . $back['error'] : '; the old version stays')];
}
