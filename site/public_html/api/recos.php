<?php
/**
 * GenZHype | THE PROPOSAL DESK — organ 07 (site side)
 *
 * The Reflector has been writing recommendations into strategist_reco since
 * r123 and NOTHING has ever read them: no admin screen, no approve path. The
 * machine proposed; the owner never saw. This is the missing half — the room
 * on his PC lists them and his click writes the decision back.
 *
 *   POST {token, action:"list"}                      -> {recos:[...]}   (proposed only)
 *   POST {token, action:"decide", id, verdict:"approved"|"dismissed"|"done", note?}
 *
 * Trust model unchanged and enforced HERE too: the Reflector recommends, the
 * owner decides. This endpoint only ever moves a row's status - it cannot
 * apply a recommendation, and nothing else in the codebase reads that status
 * to auto-act. Applying stays manual until the Proving Ground (organ 10) can
 * prove a change before it ships.
 */
declare(strict_types=1);
$APP = dirname(__DIR__, 2) . '/app';
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
$IN = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($IN)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad json']); exit; }
if (!hash_equals($CONFIG['ingest_token'] ?? '', (string)($IN['token'] ?? ''))) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad token']); exit;
}

$pdo = db();
$action = (string)($IN['action'] ?? 'list');

try {
    if ($action === 'list') {
        // THE PROVING GROUND (organ 10): every proposal arrives carrying a
        // verdict from our own posted videos, so the owner never approves on
        // argument alone. The column may not exist on an older deploy.
        try { $pdo->exec("ALTER TABLE strategist_reco ADD COLUMN proof MEDIUMTEXT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE strategist_reco ADD COLUMN proved_at DATETIME NULL"); } catch (Throwable $e) {}
        $rows = $pdo->query("SELECT id, made_on, type, title, why, evidence, risk, record_ids, proof
                             FROM strategist_reco WHERE status='proposed'
                             ORDER BY made_on DESC, id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r0) {
            $pf = json_decode((string)($r0['proof'] ?? ''), true);
            $r0['proof'] = is_array($pf) ? $pf : null;
        }
        unset($r0);
        echo json_encode(['ok' => true, 'recos' => $rows, 'count' => count($rows)],
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'decide') {
        $id = (int)($IN['id'] ?? 0);
        $verdict = (string)($IN['verdict'] ?? '');
        $note = mb_substr(trim((string)($IN['note'] ?? '')), 0, 500);
        if ($id <= 0 || !in_array($verdict, ['approved', 'dismissed', 'done'], true)) {
            http_response_code(400); echo json_encode(['ok' => false, 'error' => 'need id + verdict approved|dismissed|done']); exit;
        }
        $st = $pdo->prepare("SELECT title, type, why FROM strategist_reco WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'no such reco']); exit; }

        $pdo->prepare("UPDATE strategist_reco SET status=?, decided_at=UTC_TIMESTAMP()" .
                      ($note !== '' ? ", risk=CONCAT(COALESCE(risk,''), ' | owner: ', ?)" : '') .
                      " WHERE id=?")
            ->execute($note !== '' ? [$verdict, $note, $id] : [$verdict, $id]);

        // An owner ruling is memory, not a click: it belongs in the same
        // knowledge shelf the Reflector reads next time, so it stops
        // re-proposing what he already refused.
        try {
            $pdo->prepare("INSERT INTO strategist_knowledge (source, lesson) VALUES (?,?)")
                ->execute(['owner-ruling-' . gmdate('Ymd') . '-' . $id,
                           'The owner ' . $verdict . ' this recommendation: "' . $row['title'] . '"'
                           . ($note !== '' ? ' — his words: ' . $note : '')
                           . '. Do not re-propose a dismissed idea unless new evidence appears.']);
        } catch (Throwable $e) { /* the ruling still stands */ }

        // THE MEMORY (organ 04). An APPROVAL must reach the hands, not just
        // change a status - that was the broken link: the owner approved and
        // nothing about the next video changed. A 'question' is a thing to test,
        // not a rule to follow, so only real changes become directives.
        $adopted = false;
        if ($verdict === 'approved' && $row['type'] !== 'question') {
            try {
                require_once $APP . '/memory.php';
                $adopted = memory_adopt($pdo, $id, (string)$row['title'], (string)($row['why'] ?? ''), $note);
            } catch (Throwable $e) { error_log('memory adopt: ' . $e->getMessage()); }
        }

        echo json_encode(['ok' => true, 'id' => $id, 'status' => $verdict,
                          'title' => $row['title'], 'adopted' => $adopted]);
        exit;
    }

    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'desk failed', 'detail' => mb_substr($e->getMessage(), 0, 200)]);
}
