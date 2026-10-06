<?php
// GenZHype | LAYA, REPORT-ONLY (owner 2026-10-01, step 3): "Laya on the GitHub runner, text judgments only: 'real slang/meme
// term or ordinary word?' and 'our kind of topic or not?'. Log its answer next to the real decision; it decides nothing.
// Weekly: how often Laya agreed with the real outcome." Owner's Laya rules (2026-09-24): shadow mode first, agreement %
// before any switch, the old check stays the fallback, GitHub runners never Hostinger.
//
//   export <dir>    the real decisions of the last 14 days with their text -> <dir>/shadow/feed.json (+ content.md5)
//                   (genzhype-laya-bridge.sh commits it to .social/laya-shadow/ on main; laya.yml runs laya_shadow.py)
//   ingest <dir>    the runner's answers (<dir>/shadow/answers.json) -> table laya_shadow, next to the real decision
//   report [days]   how often Laya agreed, overall and when it was sure (confidence >= 0.8)
// NOTHING here writes to candidates, pages, trend_decisions or story_decisions: Laya decides nothing.

require_once __DIR__ . '/db.php';

const LS_DAYS = 14;        // the decisions exported: the last 14 days
const LS_MAX_PER_KIND = 250;   // newest first; the CPU runner needs ~1-5 s an item
const LS_SURE = 0.8;       // "Laya was sure" line for the report [OURS]

/** The two questions, in the owner's words, in Laya's bench format (type noul = yes/no with a confidence). */
function ls_questions(): array {
    return [
        'term' => ['real_term' => ['type' => 'noul', 'instructions' => 'Is `term` a real slang word or meme name that young people use online right now (not an ordinary dictionary word, not plain gaming vocabulary)? `examples` are posts that used it.']],
        'story' => ['our_topic' => ['type' => 'noul', 'instructions' => 'Is `headline` the kind of story GenZHype covers: online creators, streamers, gaming news, internet culture and memes for US Gen Z (not general politics, sports, finance, business or local news)? `summary` is the article\'s own blurb.']],
    ];
}

function ls_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    if (!$pdo->query("SHOW TABLES LIKE 'laya_shadow'")->fetchColumn())
        $pdo->exec("CREATE TABLE IF NOT EXISTS laya_shadow (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL, ref_id INT UNSIGNED NOT NULL, text VARCHAR(300) NOT NULL,
            real_yes TINYINT NOT NULL COMMENT 'the real decision: 1 = real term / our topic',
            real_by VARCHAR(80) NOT NULL COMMENT 'who decided for real (trend gate, editor)',
            laya_yes TINYINT NULL, laya_conf DECIMAL(4,3) NULL, agree TINYINT NULL,
            decided_at DATETIME NOT NULL, answered_at DATETIME NULL,
            UNIQUE KEY uq (kind, ref_id), KEY idx_answered (answered_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** The real decisions with their text. ['term' => [...], 'story' => [...]] */
function ls_items(PDO $pdo): array {
    $out = ['term' => [], 'story' => []];
    // TERMS: the trend gate's "ordinary word" skips (no) and its writes (yes); the topical gate's "not a slang/meme term" (no)
    $q = $pdo->query("SELECT td.cand_id, td.term, td.action, td.why, td.decided_at FROM trend_decisions td
                      WHERE td.decided_at >= UTC_TIMESTAMP() - INTERVAL " . LS_DAYS . " DAY AND (td.action='write' OR (td.action='skip' AND td.why LIKE '%ordinary%'))
                      ORDER BY td.id DESC LIMIT " . LS_MAX_PER_KIND);
    $ex = $pdo->prepare("SELECT posts_json FROM scout_vocab WHERE term=? LIMIT 1");
    $seen = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = mb_strtolower((string)$r['term']);
        if (isset($seen[$k])) continue; $seen[$k] = 1;
        $ex->execute([$k]); $posts = json_decode((string)$ex->fetchColumn(), true) ?: [];
        $examples = implode(' | ', array_map(fn($p) => mb_substr(trim((string)($p['text'] ?? '')), 0, 160), array_slice(array_filter($posts, fn($p) => trim((string)($p['text'] ?? '')) !== ''), 0, 3)));
        $out['term'][] = ['ref_id' => (int)$r['cand_id'], 'state' => ['term' => (string)$r['term'], 'examples' => $examples],
                          'real_yes' => $r['action'] === 'write' ? 1 : 0, 'real_by' => 'trend gate: ' . mb_substr((string)$r['why'], 0, 60), 'decided_at' => $r['decided_at']];
    }
    foreach ($pdo->query("SELECT id, name, reject_reason, created_at FROM candidates WHERE type IN ('term','meme','gaming') AND status='rejected' AND reject_reason='not a slang/meme term'
                          AND created_at >= UTC_TIMESTAMP() - INTERVAL " . LS_DAYS . " DAY ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = mb_strtolower((string)$r['name']); if (isset($seen[$k])) continue; $seen[$k] = 1;
        $out['term'][] = ['ref_id' => (int)$r['id'], 'state' => ['term' => (string)$r['name'], 'examples' => ''], 'real_yes' => 0, 'real_by' => 'topical gate: not a slang/meme term', 'decided_at' => $r['created_at']];
    }
    // STORIES: the editor's yes (build:true) or no, from its own verdict on each idea
    foreach ($pdo->query("SELECT id, name, signals, ai_verdict, created_at FROM candidates WHERE type='drama' AND ai_verdict IS NOT NULL AND ai_verdict<>''
                          AND created_at >= UTC_TIMESTAMP() - INTERVAL " . LS_DAYS . " DAY ORDER BY id DESC LIMIT " . (LS_MAX_PER_KIND * 2))->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = json_decode((string)$r['ai_verdict'], true); if (!is_array($v) || !array_key_exists('build', $v)) continue;
        $sg = json_decode((string)$r['signals'], true) ?: [];
        $out['story'][] = ['ref_id' => (int)$r['id'], 'state' => ['headline' => mb_substr((string)$r['name'], 0, 200), 'summary' => mb_substr((string)($sg['desc'] ?? ''), 0, 300)],
                           'real_yes' => !empty($v['build']) ? 1 : 0, 'real_by' => 'editor: ' . mb_substr((string)($v['reason'] ?? ''), 0, 60), 'decided_at' => $r['created_at']];
        if (count($out['story']) >= LS_MAX_PER_KIND) break;
    }
    return $out;
}

/** The feed for the runner, and the rows waiting for an answer. */
function ls_export(PDO $pdo, string $dir): array {
    ls_install($pdo);
    $items = ls_items($pdo);
    $ins = $pdo->prepare("INSERT IGNORE INTO laya_shadow (kind, ref_id, text, real_yes, real_by, decided_at) VALUES (?,?,?,?,?,?)");
    $feed = ['made_at' => gmdate('c'), 'questions' => ls_questions(), 'items' => []];
    foreach ($items as $kind => $list) foreach ($list as $it) {
        $ins->execute([$kind, $it['ref_id'], mb_substr(implode(' ', array_filter([$it['state']['term'] ?? '', $it['state']['headline'] ?? ''])), 0, 300), $it['real_yes'], $it['real_by'], $it['decided_at']]);
        $feed['items'][] = ['kind' => $kind, 'ref_id' => $it['ref_id'], 'state' => $it['state']];   // the real decision is NOT sent: Laya answers blind
    }
    // only the unanswered ones go
    $answered = $pdo->query("SELECT CONCAT(kind, ':', ref_id) FROM laya_shadow WHERE answered_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $skip = array_flip($answered);
    $feed['items'] = array_values(array_filter($feed['items'], fn($i) => !isset($skip[$i['kind'] . ':' . $i['ref_id']])));
    if (!is_dir("$dir/shadow")) mkdir("$dir/shadow", 0755, true);
    $json = json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    file_put_contents("$dir/shadow/feed.json", $json);
    file_put_contents("$dir/shadow/content.md5", md5(json_encode(array_column($feed['items'], 'ref_id'))) . "\n");
    return ['items' => count($feed['items']), 'terms' => count($items['term']), 'stories' => count($items['story'])];
}

/** The runner's answers, written next to the real decisions. */
function ls_ingest(PDO $pdo, string $dir): array {
    ls_install($pdo);
    $j = json_decode((string)@file_get_contents("$dir/shadow/answers.json"), true);
    if (!is_array($j) || !isset($j['answers'])) return ['error' => 'no answers file'];
    $up = $pdo->prepare("UPDATE laya_shadow SET laya_yes=?, laya_conf=?, agree=(real_yes=?), answered_at=UTC_TIMESTAMP() WHERE kind=? AND ref_id=? AND answered_at IS NULL");
    $n = 0;
    foreach ((array)$j['answers'] as $a) {
        if (!isset($a['kind'], $a['ref_id'], $a['yes'])) continue;
        $up->execute([(int)(bool)$a['yes'], round((float)($a['confidence'] ?? 0), 3), (int)(bool)$a['yes'], (string)$a['kind'], (int)$a['ref_id']]);
        $n += $up->rowCount();
    }
    return ['answered' => $n, 'run' => (string)($j['made_at'] ?? ''), 'errors' => count((array)($j['errors'] ?? []))];
}

/** Pure: agreement numbers for the report. $rows: [['kind','real_yes','laya_yes','laya_conf'], ...] */
function ls_agreement(array $rows, float $sure = LS_SURE): array {
    $out = [];
    foreach ($rows as $r) {
        $k = (string)$r['kind'];
        $out[$k] ??= ['answered' => 0, 'agree' => 0, 'sure' => 0, 'sure_agree' => 0, 'laya_yes_real_no' => 0, 'laya_no_real_yes' => 0];
        $o = &$out[$k];
        $o['answered']++;
        $ag = (int)$r['real_yes'] === (int)$r['laya_yes'];
        if ($ag) $o['agree']++; elseif ((int)$r['laya_yes']) $o['laya_yes_real_no']++; else $o['laya_no_real_yes']++;
        if ((float)$r['laya_conf'] >= $sure) { $o['sure']++; if ($ag) $o['sure_agree']++; }
        unset($o);
    }
    foreach ($out as &$o) { $o['agree_pct'] = $o['answered'] ? (int)round(100 * $o['agree'] / $o['answered']) : null; $o['sure_pct'] = $o['sure'] ? (int)round(100 * $o['sure_agree'] / $o['sure']) : null; }
    return $out;
}

/** The weekly report, in plain words. */
function ls_report(PDO $pdo, int $days = 7): string {
    ls_install($pdo);
    $rows = $pdo->query("SELECT kind, real_yes, laya_yes, laya_conf FROM laya_shadow WHERE answered_at IS NOT NULL AND answered_at >= UTC_TIMESTAMP() - INTERVAL " . (int)$days . " DAY")->fetchAll(PDO::FETCH_ASSOC);
    $waiting = (int)$pdo->query("SELECT COUNT(*) FROM laya_shadow WHERE answered_at IS NULL")->fetchColumn();
    if (!$rows) return "Laya (report-only): no answers in the last {$days} days; {$waiting} decision(s) wait for the runner.\n";
    $names = ['term' => 'real slang/meme term or ordinary word', 'story' => 'our kind of topic or not'];
    $s = "Laya, report-only, last {$days} days (it decides nothing; the real check decided):\n";
    foreach (ls_agreement($rows) as $k => $o)
        $s .= "  " . ($names[$k] ?? $k) . ": agreed with the real decision on {$o['agree']} of {$o['answered']} ({$o['agree_pct']}%)"
            . ($o['sure'] ? "; when it was sure (confidence >= " . LS_SURE . "): {$o['sure_agree']} of {$o['sure']} ({$o['sure_pct']}%)" : '; it was never sure')
            . "; said yes where we said no: {$o['laya_yes_real_no']}; said no where we said yes: {$o['laya_no_real_yes']}\n";
    $s .= "  waiting for an answer: {$waiting}\n";
    return $s;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
    require_once __DIR__ . '/helpers.php';
    $pdo = db();
    $cmd = $argv[1] ?? ''; $dir = $argv[2] ?? '';
    if ($cmd === 'export' && $dir !== '') { $r = ls_export($pdo, $dir); echo "laya shadow feed: {$r['items']} item(s) to ask ({$r['terms']} terms, {$r['stories']} stories in the last " . LS_DAYS . " days)\n"; }
    elseif ($cmd === 'ingest' && $dir !== '') { $r = ls_ingest($pdo, $dir); echo isset($r['error']) ? "laya shadow: {$r['error']}\n" : "laya shadow: {$r['answered']} answer(s) logged next to the real decision (run {$r['run']}, {$r['errors']} error(s))\n"; }
    else echo ls_report($pdo, (int)($argv[2] ?? 7));
}
