<?php
/**
 * GenZHype | THE MEMORY — organ 04 of the learning machine (2026-08-23)
 * =============================================================================
 * WHAT WAS BROKEN. The machine could think (strategist), propose (strategist_reco)
 * and the owner could rule (the room) — and then nothing happened. His approval
 * changed a row's status and stopped there. video_factory.php, the file that
 * actually writes every script and directs every shot, read NO rule store at
 * all: not comp_rule, not strategist_knowledge, nothing. The knowledge never
 * reached the hands.
 *
 * WHAT THIS IS. The one place the Doers ask "what do we believe about this?"
 * before they write. An approved recommendation becomes a DIRECTIVE — a plain
 * sentence, dated, with the recommendation it came from — and every script the
 * factory writes from then on carries it.
 *
 * REUSE, not reinvention (owner rule #1): the store is the existing comp_rule
 * table (scope='video', confidence-scored, versioned, active flag = rollback by
 * deactivating). social_copy.php already reads it this way; social_style_log()
 * already records every application so the owner can see what learning changed.
 * This adds the owner's own rulings to the same shelf and lets the video factory
 * read from it.
 *
 * LAWS.
 *  (1) OWNER DIRECTIVES OUTRANK MINED RULES. His approval is confidence 100.
 *  (2) FAIL CLOSED. No DB, no rules, bad JSON -> empty string -> the prompt is
 *      exactly what it was before this file existed. Learning may never be the
 *      reason a video fails to get written.
 *  (3) BOUNDED. At most 6 directives per surface, 240 chars each — a prompt is
 *      not a filing cabinet, and an unbounded one degrades the model.
 *  (4) VISIBLE. Every application is logged; deactivating the rule undoes it.
 *  (5) NOT A GUARDRAIL STORE. The publish gate, the alleged/reportedly shield,
 *      CC0-only audio and the dignity rules live in CODE, not here, precisely
 *      so no learned rule can ever soften them.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MEMORY_SCOPE       = 'video';
const MEMORY_MAX_RULES   = 6;
const MEMORY_MAX_CHARS   = 240;
/** Which writing surface a directive applies to. 'all' reaches every one. */
const MEMORY_SURFACES    = ['hook', 'script', 'director', 'caption', 'all'];

/**
 * Guess which surface a recommendation is about, from the owner's own words.
 * Deliberately simple and conservative: anything unclear becomes 'all', which
 * is the honest answer — a rule we cannot place still applies generally.
 */
function memory_surface_of(string $text): string {
    $t = mb_strtolower($text);
    if (preg_match('/\b(hook|title|first second|opening|thumbnail|cover)\b/', $t)) return 'hook';
    if (preg_match('/\b(caption|hashtag|description|post text)\b/', $t))          return 'caption';
    if (preg_match('/\b(shot|visual|footage|image|face|b-?roll|scene|edit)\b/', $t)) return 'director';
    if (preg_match('/\b(script|voiceover|narration|word|sentence)\b/', $t))       return 'script';
    return 'all';
}

/**
 * An approved recommendation becomes a directive the Doers will read.
 * Called when the owner clicks Approve. Idempotent by rule_key.
 */
function memory_adopt(PDO $pdo, int $recoId, string $title, string $why = '', string $ownerNote = ''): bool {
    $directive = trim($ownerNote !== '' ? $ownerNote : $title);
    if ($directive === '') return false;
    $surface = memory_surface_of($title . ' ' . $why . ' ' . $ownerNote);
    $value = json_encode([
        'directive'   => mb_substr($directive, 0, MEMORY_MAX_CHARS),
        'surface'     => $surface,
        'from_reco'   => $recoId,
        'approved_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    try {
        $pdo->prepare(
            "INSERT INTO comp_rule (scope, rule_key, rule_value, confidence, evidence, version, active, updated_at)
             VALUES (?,?,?,100,?,1,1,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value), confidence=100, active=1,
                                     version=version+1, evidence=VALUES(evidence), updated_at=UTC_TIMESTAMP()")
            ->execute([MEMORY_SCOPE, 'owner_directive_' . $recoId, $value,
                       'approved by the owner in the build room, ' . gmdate('Y-m-d')]);
        return true;
    } catch (Throwable $e) {
        error_log('memory_adopt: ' . $e->getMessage());
        return false;
    }
}

/** The owner's approved directives for one surface. Highest confidence first. */
function memory_directives(PDO $pdo, string $surface): array {
    if (!in_array($surface, MEMORY_SURFACES, true)) return [];
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT rule_key, rule_value FROM comp_rule
                                  WHERE scope='" . MEMORY_SCOPE . "' AND active=1
                                    AND rule_key LIKE 'owner_directive_%'
                                  ORDER BY updated_at DESC") as $r) {
                $v = json_decode((string)$r['rule_value'], true);
                if (!is_array($v) || empty($v['directive'])) continue;
                $cache[] = ['key' => (string)$r['rule_key'],
                            'directive' => (string)$v['directive'],
                            'surface' => (string)($v['surface'] ?? 'all')];
            }
        } catch (Throwable $e) { $cache = []; }   // fail closed
    }
    $out = [];
    foreach ($cache as $d) {
        if ($d['surface'] === $surface || $d['surface'] === 'all') $out[] = $d;
        if (count($out) >= MEMORY_MAX_RULES) break;
    }
    return $out;
}

/**
 * The prompt block a Doer appends to its system prompt. Empty string when
 * there is nothing to say — callers must treat '' as "behave exactly as before".
 * $pageId is only for the apply log.
 */
function memory_prompt_block(PDO $pdo, string $surface, int $pageId = 0): string {
    $ds = memory_directives($pdo, $surface);

    // THE OUTSIDE INTAKE (organ 09). Months of mined findings sat in this very
    // table, under this very scope, and the writers never saw one of them: the
    // query above filters to owner_directive_%. They arrive BELOW the owner's
    // rules and clearly labelled, never as instructions - rival data currently
    // recommends a hook rule our own posted videos have already disproved.
    $outside = '';
    try { require_once __DIR__ . '/intake.php'; $outside = intake_prompt_block($pdo, $surface); }
    catch (Throwable $e) { error_log('intake block: ' . $e->getMessage()); }

    // THE EXPERIMENT DESK (organ 06). If this surface is under test and this
    // video is in the variant arm, the instruction rides here. Videos in the
    // permanent holdout never receive one.
    $xp = '';
    try { require_once __DIR__ . '/experiment.php'; $xp = xp_prompt_block($pdo, $surface, $pageId); }
    catch (Throwable $e) { error_log('xp block: ' . $e->getMessage()); }

    if (!$ds) return $outside . $xp;
    $lines = '';
    foreach ($ds as $i => $d) { $lines .= ' ' . ($i + 1) . ') ' . $d['directive']; }
    $block = ' THE OWNER HAS APPROVED THESE STANDING RULES — they outrank your defaults'
           . ' and you must follow every one:' . $lines . ' ';
    $block .= $outside . $xp;
    if (function_exists('social_style_log')) {
        try {
            social_style_log('owner_directives', $pageId,
                             'applied ' . count($ds) . ' owner-approved directive(s) to the ' . $surface . ' prompt',
                             'no owner directives', mb_substr(trim($lines), 0, 200));
        } catch (Throwable $e) {}
    }
    return $block;
}

/** Everything the machine currently believes, for the CLI and the room. */
function memory_all(PDO $pdo): array {
    $out = ['owner_directives' => [], 'mined_rules' => []];
    try {
        foreach ($pdo->query("SELECT rule_key, rule_value, confidence, updated_at, active
                              FROM comp_rule WHERE scope='" . MEMORY_SCOPE . "'
                              ORDER BY confidence DESC, updated_at DESC") as $r) {
            if (strpos((string)$r['rule_key'], 'owner_directive_') === 0) {
                $v = json_decode((string)$r['rule_value'], true) ?: [];
                $out['owner_directives'][] = [
                    'key' => $r['rule_key'], 'directive' => (string)($v['directive'] ?? ''),
                    'surface' => (string)($v['surface'] ?? 'all'),
                    'active' => (int)$r['active'], 'since' => (string)$r['updated_at'],
                ];
            } else {
                $out['mined_rules'][] = ['key' => $r['rule_key'], 'confidence' => (int)$r['confidence'],
                                         'active' => (int)$r['active'], 'updated' => (string)$r['updated_at']];
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Roll one directive back — the owner changed his mind, or it stopped working. */
function memory_deactivate(PDO $pdo, string $ruleKey): bool {
    try {
        $pdo->prepare("UPDATE comp_rule SET active=0, updated_at=UTC_TIMESTAMP()
                       WHERE scope='" . MEMORY_SCOPE . "' AND rule_key=?")->execute([$ruleKey]);
        return true;
    } catch (Throwable $e) { return false; }
}
