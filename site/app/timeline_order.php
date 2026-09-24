<?php
// GenZHype | timeline order. A story page lists its events by sort_order
// (repo.php), and sort_order was the order events were INSERTED: the drafter's
// own order, then anything drama_deepen or fetch_sources added later appended at
// the end. Measured 2026-09-24: 238 of 720 live or held stories showed their
// timeline out of date order (page 1134: May and September 2026 events listed
// after a later one). events_resort() puts the dated events in date order and
// leaves undated ones in the slots they already hold.

require_once __DIR__ . '/db.php';

/** Re-number one story's displayed events by date. Returns true if the order changed. */
function events_resort(PDO $pdo, int $dramaId): bool {
    $st = $pdo->prepare("SELECT id, event_date FROM events WHERE drama_id=? AND video_only=0 ORDER BY sort_order, event_date");
    $st->execute([$dramaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $slots = []; $dated = [];
    foreach ($rows as $i => $r) {
        $d = (string)($r['event_date'] ?? '');
        if ($d === '' || strncmp($d, '0000', 4) === 0) continue;   // undated: keeps its slot
        $slots[] = $i; $dated[] = ['i' => $i, 'd' => $d, 'row' => $r];
    }
    usort($dated, fn($a, $b) => [$a['d'], $a['i']] <=> [$b['d'], $b['i']]);   // stable: same date keeps its order
    $new = $rows;
    foreach ($slots as $k => $slot) $new[$slot] = $dated[$k]['row'];
    if (array_column($new, 'id') === array_column($rows, 'id')) return false;
    $up = $pdo->prepare("UPDATE events SET sort_order=? WHERE id=?");
    $pdo->beginTransaction();
    try {
        foreach ($new as $n => $r) $up->execute([$n + 1, (int)$r['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
        throw $e;
    }
    return true;
}
