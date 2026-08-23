<?php
/**
 * GenZHype | THE RECORD — organ 02 of the learning machine (2026-08-23)
 * =============================================================================
 * One joined story per unit of work. "Show me everything about page/video X"
 * in one command: what was planned, what was built, what every check said,
 * what the renderer did, what the judge and the owner said, how it performed.
 *
 * WHY. Diagnosing video-700 took an hour of archaeology across a drop branch,
 * a render log, a judge line and five tables — and the machine itself could
 * not do it at all, which is why it cannot learn. Every learning organ
 * (Reflector, Proving Ground, Experiment Desk) reads THIS; without it they
 * stare at nothing.
 *
 * SHAPE. One row per (kind, page_id, ref). Five sections, each a JSON blob in
 * MEDIUMTEXT (the codebase's own pattern — video_scripts.shotlist — immune to
 * MySQL JSON-type surprises on shared hosting). Writers merge into THEIR
 * section only; the other sections are never read or touched by a writer.
 *
 *   plan       what was decided   (video_factory)
 *   build      pipeline outcomes  (cli.php drama loop: draft/verify/quality/gate)
 *   delivery   render report      (video_bridge ingest: stats, substitutions)
 *   judgment   judge + OWNER verdict (bridge + api/verdict_ingest; owner outranks)
 *   outcome    platform metrics over time (social_metrics collection)
 *
 * LAWS. (1) Observer, never participant: every caller wraps record_touch() in
 * try/catch; a record failure logs and the pipeline continues. (2) Reference,
 * don't duplicate: ai_log / platform_metrics / social_posts / video_scripts
 * stay canonical; this stores compact snapshots + ids. (3) Exactly one
 * indexed upsert per hook — this must never cost the shop anything
 * measurable. (4) Doers never READ it; organs do.
 *
 * Spec: repo _team/specs/SPEC-record.md · Plan: _team/tasks/record/
 */
declare(strict_types=1);

const RECORD_SECTIONS = ['plan', 'build', 'delivery', 'judgment', 'outcome'];
const RECORD_KINDS    = ['drama', 'term', 'video', 'post'];

/** Create/migrate the table. Guarded ALTERs, same pattern as video_factory_install. */
function record_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_record (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      kind        ENUM('drama','term','video','post') NOT NULL,
      page_id     INT UNSIGNED NOT NULL,
      ref         VARCHAR(120) NOT NULL DEFAULT '',
      plan        MEDIUMTEXT NULL,
      build       MEDIUMTEXT NULL,
      delivery    MEDIUMTEXT NULL,
      judgment    MEDIUMTEXT NULL,
      outcome     MEDIUMTEXT NULL,
      created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_unit (kind, page_id, ref),
      KEY idx_page (page_id),
      KEY idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // future columns land here as guarded ALTERs, never by editing the CREATE
    foreach ([] as $alter) { try { $pdo->exec("ALTER TABLE work_record {$alter}"); } catch (Throwable $e) {} }
}

/**
 * Merge $data into ONE section of one record (upsert). Top-level keys in $data
 * overwrite the same keys in the stored section; other keys survive. Keys
 * listed in $appendKeys are treated as lists and APPENDED to (used by outcome
 * snapshots). Returns true on write, false on refusal (bad kind/section).
 *
 * Cost: one SELECT by unique key + one INSERT ... ON DUPLICATE KEY UPDATE.
 */
function record_touch(PDO $pdo, string $kind, int $pageId, string $ref, string $section,
                      array $data, array $appendKeys = []): bool {
    if (!in_array($kind, RECORD_KINDS, true) || !in_array($section, RECORD_SECTIONS, true)) {
        error_log("record: refused kind={$kind} section={$section}");
        return false;
    }
    record_install($pdo);
    $ref = mb_substr($ref, 0, 120);

    $cur = $pdo->prepare("SELECT {$section} FROM work_record WHERE kind=? AND page_id=? AND ref=?");
    $cur->execute([$kind, $pageId, $ref]);
    $existing = json_decode((string)$cur->fetchColumn(), true);
    if (!is_array($existing)) $existing = [];

    foreach ($data as $k => $v) {
        if (in_array($k, $appendKeys, true)) {
            $list = is_array($existing[$k] ?? null) ? $existing[$k] : [];
            foreach ((array)$v as $item) $list[] = $item;
            $existing[$k] = array_slice($list, -200);   // bounded: a record is a story, not a warehouse
        } else {
            $existing[$k] = $v;
        }
    }
    $existing['_at'] = gmdate('c');
    $json = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { error_log("record: json_encode failed for {$kind}/{$pageId}/{$ref}/{$section}"); return false; }

    $pdo->prepare("INSERT INTO work_record (kind, page_id, ref, {$section}) VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE {$section}=VALUES({$section})")
        ->execute([$kind, $pageId, $ref, $json]);
    return true;
}

/** All records for a page (every kind/ref), sections decoded. */
function record_get(PDO $pdo, int $pageId): array {
    record_install($pdo);
    $st = $pdo->prepare("SELECT * FROM work_record WHERE page_id=? ORDER BY kind, ref");
    $st->execute([$pageId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (RECORD_SECTIONS as $s) {
            $row[$s] = $row[$s] !== null ? (json_decode((string)$row[$s], true) ?: null) : null;
        }
        $out[] = $row;
    }
    return $out;
}

/** Records updated since a timestamp — the export / Reflector feed. */
function record_since(PDO $pdo, string $sinceIso, ?string $kind = null, int $limit = 200): array {
    record_install($pdo);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT * FROM work_record WHERE updated_at >= ?" . ($kind ? " AND kind=?" : '') . " ORDER BY updated_at ASC LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($kind ? [$sinceIso, $kind] : [$sinceIso]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (RECORD_SECTIONS as $s) {
            $row[$s] = $row[$s] !== null ? (json_decode((string)$row[$s], true) ?: null) : null;
        }
        $out[] = $row;
    }
    return $out;
}

/** The story, human-readable — `php cli.php record <page_id>`. */
function record_story(PDO $pdo, int $pageId): string {
    $rows = record_get($pdo, $pageId);
    if (!$rows) return "no record for page {$pageId}\n";
    $title = '';
    try {
        $t = $pdo->prepare("SELECT h1 AS title, slug, status FROM pages WHERE id=?");   // pages has h1, not title
        $t->execute([$pageId]);
        if ($p = $t->fetch(PDO::FETCH_ASSOC)) $title = "{$p['title']}  [{$p['slug']}, {$p['status']}]";
    } catch (Throwable $e) {}
    $out = "== page {$pageId}" . ($title ? "  {$title}" : '') . " ==\n";
    foreach ($rows as $r) {
        $out .= "\n-- {$r['kind']}" . ($r['ref'] !== '' ? " / {$r['ref']}" : '') . "  (updated {$r['updated_at']})\n";
        foreach (RECORD_SECTIONS as $s) {
            if ($r[$s] === null) continue;
            $out .= "   {$s}:\n";
            $out .= record_fmt($r[$s], 6);
        }
    }
    return $out;
}

/** Small indented pretty-printer (keeps the story readable in a terminal). */
function record_fmt($v, int $indent): string {
    $pad = str_repeat(' ', $indent);
    if (!is_array($v)) return $pad . (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) . "\n";
    $out = '';
    $isList = array_keys($v) === range(0, count($v) - 1);
    foreach ($v as $k => $item) {
        $label = $isList ? '-' : "{$k}:";
        if (is_array($item)) {
            $out .= "{$pad}{$label}\n" . record_fmt($item, $indent + 2);
        } else {
            $s = is_bool($item) ? ($item ? 'true' : 'false') : (string)$item;
            if (mb_strlen($s) > 160) $s = mb_substr($s, 0, 157) . '...';
            $out .= "{$pad}{$label} {$s}\n";
        }
    }
    return $out;
}

/** write → read → delete → PASS. Proves the table and the merge live, on the real DB. */
function record_selftest(PDO $pdo): string {
    $pid = 0; $ref = 'selftest-' . bin2hex(random_bytes(3));
    try {
        record_touch($pdo, 'video', $pid, $ref, 'plan', ['hook' => 'a', 'tpl' => 3]);
        record_touch($pdo, 'video', $pid, $ref, 'plan', ['hook' => 'b']);               // overwrite one key
        record_touch($pdo, 'video', $pid, $ref, 'outcome', ['snapshots' => [['views' => 1]]], ['snapshots']);
        record_touch($pdo, 'video', $pid, $ref, 'outcome', ['snapshots' => [['views' => 2]]], ['snapshots']);  // append
        $rows = array_values(array_filter(record_get($pdo, $pid), fn($r) => $r['ref'] === $ref));
        $r = $rows[0] ?? null;
        $ok = $r && ($r['plan']['hook'] ?? '') === 'b' && ($r['plan']['tpl'] ?? 0) === 3
                 && count($r['outcome']['snapshots'] ?? []) === 2
                 && $r['build'] === null;                                              // untouched section stays null
        $pdo->prepare("DELETE FROM work_record WHERE kind='video' AND page_id=0 AND ref=?")->execute([$ref]);
        return $ok ? "record-selftest: PASS (merge, append, isolation, cleanup)\n"
                   : "record-selftest: FAIL — got " . json_encode($r) . "\n";
    } catch (Throwable $e) {
        return "record-selftest: FAIL — " . $e->getMessage() . "\n";
    }
}

/**
 * One-shot seeding so the Reflector is born with history, not amnesia.
 * Pages: the last $limit published dramas, build section from ai_reviews (the
 * existing per-stage AI trail) + pages. Videos: EVERY video_scripts row, plan
 * section from what the factory already stored (incl. skipped ones + their
 * reason). Idempotent — re-running merges, never duplicates.
 */
function record_backfill(PDO $pdo, int $limit = 30): array {
    record_install($pdo);
    $pages = 0; $videos = 0;
    $rows = $pdo->query("SELECT p.id, p.slug, p.status, p.published_at
                         FROM pages p JOIN dramas d ON d.page_id=p.id
                         WHERE p.status='published'
                         ORDER BY p.published_at DESC LIMIT " . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $p) {
        $pid = (int)$p['id'];
        $build = ['published' => true, 'published_at' => (string)$p['published_at'], 'backfilled' => true];
        try {
            $ar = $pdo->prepare("SELECT stage, provider, passed, verdict FROM ai_reviews WHERE page_id=? ORDER BY id ASC");
            $ar->execute([$pid]);
            foreach ($ar->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $stage = (string)$a['stage'];
                if (!in_array($stage, ['draft', 'verify', 'quality'], true)) continue;
                $v = json_decode((string)$a['verdict'], true);
                $build[$stage] = ['provider' => (string)$a['provider'], 'pass' => $a['passed'] === null ? null : (bool)$a['passed']]
                               + (is_array($v) ? array_intersect_key($v, array_flip(['events', 'scores', 'flags', 'red_flags', 'issues'])) : []);
            }
        } catch (Throwable $e) {}
        if (record_touch($pdo, 'drama', $pid, '', 'build', $build)) $pages++;
    }
    $vs = $pdo->query("SELECT v.* FROM video_scripts v")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vs as $v) { if (record_video_plan_row($pdo, $v)) $videos++; }
    return ['pages' => $pages, 'videos' => $videos];
}

/** Plan section from one video_scripts row — used by the factory hook and backfill. */
function record_video_plan_row(PDO $pdo, array $v): bool {
    $shots = json_decode((string)($v['shotlist'] ?? ''), true);
    $plan = [
        'tpl'        => (int)($v['tpl'] ?? 0),
        'hook'       => (string)($v['hook'] ?? ''),
        'gravity'    => (string)($v['gravity'] ?? 'standard'),
        'style'      => (string)($v['style'] ?? ''),
        'status'     => (string)($v['video_status'] ?? ''),
        'video_path' => (string)($v['video_path'] ?? ''),
        'made_at'    => (string)($v['video_made_at'] ?? ''),
        'shots'      => is_array($shots) ? count($shots['shots'] ?? $shots) : 0,
        'words'      => str_word_count((string)($v['script'] ?? '')),
    ];
    if (!empty($v['skip_reason'])) $plan['skipped_why'] = (string)$v['skip_reason'];
    return record_touch($pdo, 'video', (int)$v['page_id'], (string)($v['slug'] ?? ''), 'plan', $plan);
}

/** The factory hook: after any script/shotlist persist or a gate skip, snapshot the row. */
function record_video_plan_for_page(PDO $pdo, int $pageId): bool {
    $st = $pdo->prepare("SELECT * FROM video_scripts WHERE page_id=?");
    $st->execute([$pageId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    return $v ? record_video_plan_row($pdo, $v) : false;
}

/**
 * Rejected renders never get a drop-meta sidecar (the bridge ingests only
 * accepted videos), but the judge's REASONS on a reject are exactly what the
 * learning loop needs — video-700 was a reject. Best-effort parse of the drop's
 * render.log: per page, the judge line (pass/scores/issues) and the scene
 * count. Non-fatal, bounded, never touches an accepted page's delivery.
 */
function record_ingest_render_log(PDO $pdo, string $logPath): int {
    if (!is_file($logPath)) return 0;
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $touched = 0;
    $judgeByPage = []; $scenesByPage = []; $currentPage = 0;
    foreach ($lines as $ln) {
        // the maker announces each job: "processing page_id=700 slug=..."
        if (preg_match('/processing page_id=(\d+)/', $ln, $m)) { $currentPage = (int)$m[1]; }
        if (preg_match('/INFO\s+scene \d+:/', $ln) && $currentPage) {
            $scenesByPage[$currentPage] = ($scenesByPage[$currentPage] ?? 0) + 1;
        }
        if (preg_match('/vision judge rejected page (\d+).*?weird=(\[.*?\])(?: mism=(\[.*?\]))?(?: issues=(\[.*?\]))?/', $ln, $m)) {
            $pid = (int)$m[1];
            $judgeByPage[$pid] = ($judgeByPage[$pid] ?? []) + [
                'pass' => false,
                'weird' => mb_substr($m[2], 0, 600),
                'issues' => mb_substr($m[4] ?? '', 0, 400),
            ];
        }
        if (preg_match('/vision judge: pass=(True|False).*?scores=\{([^}]*)\}/', $ln, $m)) {
            $scores = [];
            if (preg_match_all("/'([a-z_]+)':\s*(\d+)/", $m[2], $sm, PREG_SET_ORDER)) {
                foreach ($sm as $x) $scores[$x[1]] = (int)$x[2];
            }
            $pid = $currentPage;
            if ($pid) $judgeByPage[$pid] = ['pass' => $m[1] === 'True', 'scores' => $scores] + ($judgeByPage[$pid] ?? []);
        }
    }
    foreach ($judgeByPage as $pid => $judge) {
        try {
            $st = $pdo->prepare("SELECT slug FROM video_scripts WHERE page_id=?");
            $st->execute([$pid]);
            $slug = (string)($st->fetchColumn() ?: '');
            record_touch($pdo, 'video', $pid, $slug, 'judgment', ['judge' => $judge + ['source' => 'render.log']]);
            if (!empty($scenesByPage[$pid])) {
                record_touch($pdo, 'video', $pid, $slug, 'delivery', ['scenes' => $scenesByPage[$pid], 'rejected' => empty($judge['pass'])]);
            }
            $touched++;
        } catch (Throwable $e) { error_log('record render.log: ' . $e->getMessage()); }
    }
    return $touched;
}

/**
 * THE REFLECTOR'S FOOD (organ 05 reads this).
 *
 * The joined STORY of recent videos, compacted to readable lines. This is the
 * GEPA move: a learner that reads whole traces beats one that reads averages,
 * with far fewer trials — which is the only reason learning is possible at a
 * few videos a day. Averages hide the thing that matters most: video 700
 * scored 8/10 from the judge and "disaster" from the owner.
 *
 * Capped hard (default 25) so a free-tier context can hold it.
 */
function record_traces(PDO $pdo, int $limit = 25): array {
    record_install($pdo);
    $limit = max(1, min(60, $limit));
    $rows = $pdo->query("SELECT page_id, ref, plan, build, delivery, judgment, outcome, updated_at
                         FROM work_record WHERE kind='video' AND plan IS NOT NULL
                         ORDER BY updated_at DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $plan = json_decode((string)$r['plan'], true) ?: [];
        $jud  = json_decode((string)$r['judgment'], true) ?: [];
        $del  = json_decode((string)$r['delivery'], true) ?: [];
        $t = [
            'page_id' => (int)$r['page_id'],
            'hook'    => mb_substr((string)($plan['hook'] ?? ''), 0, 90),
            'tpl'     => $plan['tpl'] ?? null,
            'shots'   => $plan['shots'] ?? null,
            'words'   => $plan['words'] ?? null,
            'status'  => $plan['status'] ?? null,
        ];
        if (!empty($plan['style']))       $t['style'] = $plan['style'];
        if (!empty($plan['skipped_why'])) $t['skipped_why'] = mb_substr((string)$plan['skipped_why'], 0, 120);
        if (!empty($del['scenes']))       $t['scenes'] = $del['scenes'];
        if (!empty($del['substitutions'])) $t['substitutions'] = array_slice((array)$del['substitutions'], 0, 4);

        if (!empty($jud['judge'])) {
            $j = $jud['judge'];
            $t['judge'] = ['pass' => (bool)($j['pass'] ?? false)];
            if (!empty($j['scores'])) $t['judge']['scores'] = $j['scores'];
            $why = (string)($j['issues'] ?? ($j['weird'] ?? ''));
            if ($why !== '') $t['judge']['why'] = mb_substr($why, 0, 200);
        }
        // GROUND TRUTH. Labelled loudly so the model cannot treat it as one
        // opinion among several: the owner's eye outranks every machine score.
        if (!empty($jud['owner_verdict'])) {
            $ov = $jud['owner_verdict'];
            $t['OWNER_VERDICT'] = [
                'verdict' => (string)($ov['verdict'] ?? ''),
                'reasons' => array_slice((array)($ov['reasons'] ?? []), 0, 6),
                'note'    => mb_substr((string)($ov['note'] ?? ''), 0, 200),
            ];
        }
        // Outcome lives on the post record(s) for the same page.
        try {
            $o = $pdo->prepare("SELECT ref, outcome FROM work_record WHERE kind='post' AND page_id=? AND outcome IS NOT NULL");
            $o->execute([(int)$r['page_id']]);
            foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $po) {
                $snap = json_decode((string)$po['outcome'], true)['snapshots'] ?? [];
                if ($snap) {
                    $last = end($snap);
                    $t['results'][(string)$po['ref']] = ['views' => $last['views'] ?? 0, 'likes' => $last['likes'] ?? 0];
                }
            }
        } catch (Throwable $e) {}
        $out[] = array_filter($t, fn($v) => $v !== null && $v !== '' && $v !== []);
    }
    return $out;
}

/**
 * THE QUESTION LEDGER (organ 08) — what the machine is trying to answer.
 * Deliberately tiny. Questions arrive from the owner's verdicts, from rival
 * comparisons, and from every "I could not explain this" in a reflection pass.
 * A machine that cannot say "I don't know, and here is what I'd need" can only
 * ever learn what it already measures.
 */
function question_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS question_ledger (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      question    VARCHAR(300) NOT NULL,
      why         TEXT NULL,
      needs       VARCHAR(300) NULL,
      status      ENUM('open','experimenting','answered','needs_tool') NOT NULL DEFAULT 'open',
      raised_by   VARCHAR(24) NOT NULL DEFAULT 'reflector',
      raised_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resolved_note TEXT NULL,
      UNIQUE KEY uq_q (question)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function question_add(PDO $pdo, string $question, string $why = '', string $needs = '', string $by = 'reflector'): bool {
    $q = trim(mb_substr($question, 0, 300));
    if ($q === '') return false;
    question_install($pdo);
    $pdo->prepare("INSERT IGNORE INTO question_ledger (question, why, needs, raised_by) VALUES (?,?,?,?)")
        ->execute([$q, mb_substr($why, 0, 1000), mb_substr($needs, 0, 300), $by]);
    return true;
}

function question_open(PDO $pdo, int $limit = 20): array {
    question_install($pdo);
    return $pdo->query("SELECT id, question, why, needs, status, raised_by, raised_at
                        FROM question_ledger WHERE status IN ('open','experimenting','needs_tool')
                        ORDER BY id DESC LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);
}
