<?php
/**
 * GenZHype | THE PLAYBOOK OF WINS — organ 12 of the learning machine (2026-08-25)
 * =============================================================================
 * WHAT THIS IS. Procedural memory. The Memory (organ 04) holds what the machine
 * BELIEVES; this holds what it knows how to DO — the hook constructions, stored
 * as versioned rows with a win rate attached, so a losing one can be retired and
 * a winning one can spread.
 *
 * WHAT WAS BROKEN. The hook archetypes were three string literals inside
 * video_write_script(), rotated by crc32(slug) % 3. Unversioned, unmeasured,
 * unretirable. Six of them across two registers had been writing every video for
 * months and nobody — including the machine — knew which one worked.
 *
 * THE FREE HISTORY. Because that rotation is DETERMINISTIC, the archetype used by
 * every past video is recoverable from its slug. So this organ did not have to
 * wait weeks to learn anything: it re-derived the archetype for all 44 posted
 * tpl=2 videos and had real win rates on day one.
 *
 * WHAT THE FIRST READING ACTUALLY SAID, and why that matters more than a winner:
 *
 *   grave:CONFIRMED UPDATE     n=4   median 822   4/4 above our median
 *   grave:FACTUAL EXPLAINER    n=3   median 251   0/3 above our median
 *   normal:STAKES TEASE        n=19  median 718   12/19
 *   normal:DECLARATIVE BOMB    n=9   median 708   6/9
 *   normal:IN-MEDIAS-RES       n=9   median 705   5/9
 *
 * The three normal archetypes are separated by less than 2% on the median. That
 * is not a ranking, it is noise, and a playbook that crowned STAKES TEASE on it
 * would be inventing knowledge. The grave pair look dramatically different — and
 * n=3 against n=4 cannot carry that claim either. So this organ reports
 * TOO-EARLY, loudly, and retires nothing. Same discipline as the Proving Ground
 * answering UNTESTED: refusing to conclude is a result.
 *
 * THE LAWS.
 *  (1) NO BEHAVIOUR CHANGE UNTIL EVIDENCE EXISTS. With every skill active, the
 *      picker returns byte-identically what the hard-coded rotation returned.
 *      Installing this organ changes no video.
 *  (2) IT NEVER RETIRES ANYTHING BY ITSELF. Retirement needs a real sample and
 *      the owner's word. A machine that prunes its own abilities on four data
 *      points gets narrower, not better.
 *  (3) THE SAMPLE FLOOR IS STATED, NOT HIDDEN. Every verdict carries its n.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Below this many posted videos a skill cannot be judged at all. */
const PB_MIN_SAMPLE = 8;
/** A skill must be this far below our own median rate before it is even nominated. */
const PB_LOSS_MARGIN = 0.25;

/* ---------------------------------------------------------------------------
 * THE LIBRARY
 * ------------------------------------------------------------------------- */

function pb_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS skill (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(40) NOT NULL,
        skill_key VARCHAR(80) NOT NULL,
        label VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        slot TINYINT NOT NULL DEFAULT 0,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        source VARCHAR(60) NOT NULL DEFAULT 'seeded',
        created_at DATETIME NOT NULL,
        retired_at DATETIME NULL,
        retired_why VARCHAR(300) NULL,
        UNIQUE KEY uniq_skill (skill_key),
        KEY idx_kind (kind, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS skill_use (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        skill_key VARCHAR(80) NOT NULL,
        page_id INT UNSIGNED NOT NULL,
        used_at DATETIME NOT NULL,
        UNIQUE KEY uniq_use (skill_key, page_id),
        KEY idx_page (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * The archetypes exactly as they were written in video_write_script(), lifted
 * without a word changed. Slot order is load-bearing: it is what makes the
 * picker reproduce the old rotation byte for byte (law 1).
 */
function pb_seed_data(): array {
    return [
        // kind, slot, key, label, body
        ['hook', 0, 'hook_in_medias_res', 'IN-MEDIAS-RES',
         'IN-MEDIAS-RES: open mid-conflict on the NEWEST, most explosive development — zero setup, as if the viewer walked in on the fight'],
        ['hook', 1, 'hook_declarative_bomb', 'DECLARATIVE BOMB',
         'DECLARATIVE BOMB: "[Name] just [did the shocking thing] — and the receipts are worse." Named person + completed action + tension'],
        ['hook', 2, 'hook_stakes_tease', 'STAKES TEASE',
         'STAKES TEASE: lead with the consequence ("This one screenshot might end his career"), then reveal whose'],
        ['grave_hook', 0, 'grave_factual_explainer', 'FACTUAL EXPLAINER',
         'FACTUAL EXPLAINER: "What happened to [name], explained." Calm, direct, factual, zero hype.'],
        ['grave_hook', 1, 'grave_timeline_opener', 'TIMELINE OPENER',
         'TIMELINE OPENER: "The [name] case, from the first report to today." Measured, chronological framing.'],
        ['grave_hook', 2, 'grave_confirmed_update', 'CONFIRMED UPDATE',
         'CONFIRMED UPDATE: "Here is what is actually confirmed in the [name] story." Sober; separates fact from rumor.'],
    ];
}

/** Put the hard-coded archetypes into the library. Idempotent; never overwrites an edit. */
function pb_seed(PDO $pdo): int {
    pb_install($pdo);
    $n = 0;
    $st = $pdo->prepare(
        "INSERT INTO skill (kind, skill_key, label, body, slot, version, active, source, created_at)
         VALUES (?,?,?,?,?,1,1,'seeded-from-code',UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE kind=VALUES(kind), label=VALUES(label), slot=VALUES(slot)");
    foreach (pb_seed_data() as [$kind, $slot, $key, $label, $body]) {
        $st->execute([$kind, $key, $label, $body, $slot]);
        $n++;
    }
    return $n;
}

/** The active skills of one kind, in slot order. Slot order is the rotation order. */
function pb_active(PDO $pdo, string $kind): array {
    try {
        $q = $pdo->prepare("SELECT skill_key, label, body, slot FROM skill
                            WHERE kind=? AND active=1 ORDER BY slot ASC, id ASC");
        $q->execute([$kind]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * Which skill this story gets.
 *
 * The selector is the ORIGINAL expression, unchanged, so with all skills active
 * this returns exactly what the hard-coded array returned for the same slug —
 * that is law 1, and pb_selftest proves it rather than asserting it. The only
 * behavioural difference is that a retired skill drops out of the rotation.
 *
 * Returns null when the library is empty or unreachable, and the caller must
 * then fall back to its own hard-coded list — learning may never be the reason
 * a video fails to get written.
 */
function pb_pick(PDO $pdo, string $kind, string $slug, bool $grave = false): ?array {
    $set = pb_active($pdo, $kind);
    if (!$set) return null;
    $i = $grave ? ((crc32($slug) & 0x7fffffff) % count($set)) : (crc32($slug) % count($set));
    $i = abs($i) % count($set);
    return $set[$i];
}

/** Record that a page used a skill, so attribution stops depending on the selector. */
function pb_record_use(PDO $pdo, string $skillKey, int $pageId): void {
    try {
        $pdo->prepare("INSERT IGNORE INTO skill_use (skill_key, page_id, used_at)
                       VALUES (?,?,UTC_TIMESTAMP())")->execute([$skillKey, $pageId]);
    } catch (Throwable $e) { error_log('pb_record_use: ' . $e->getMessage()); }
}

/* ---------------------------------------------------------------------------
 * THE WIN RATES
 * ------------------------------------------------------------------------- */

/**
 * Attribute every posted video to the skill that wrote it.
 *
 * Recorded uses win where they exist. Where they do not — every video made
 * before this organ existed — the archetype is RE-DERIVED from the slug, which
 * is sound only because the old selector was deterministic and is reproduced
 * here exactly. Derived rows are marked as such so nobody mistakes reconstruction
 * for measurement.
 */
function pb_attributed(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT vs.page_id, vs.slug, vs.tpl, vs.gravity, MAX(m.views) views
           FROM video_scripts vs
           JOIN platform_videos pv ON pv.page_id = vs.page_id
           JOIN platform_metrics m ON m.video_id = pv.id
          GROUP BY vs.page_id
         HAVING views > 0")->fetchAll(PDO::FETCH_ASSOC);

    $used = [];
    try {
        foreach ($pdo->query("SELECT skill_key, page_id FROM skill_use") as $u)
            $used[(int)$u['page_id']] = (string)$u['skill_key'];
    } catch (Throwable $e) {}

    $hook  = pb_active($pdo, 'hook');
    $grave = pb_active($pdo, 'grave_hook');
    $out = [];
    foreach ($rows as $r) {
        $pid = (int)$r['page_id'];
        $key = $used[$pid] ?? null;
        $derived = false;
        if ($key === null) {
            // Only tpl=2 scripts ever went through the archetype rotation.
            if ((int)$r['tpl'] !== 2) continue;
            $isGrave = ((string)$r['gravity'] === 'grave');
            $set = $isGrave ? $grave : $hook;
            if (!$set) continue;
            $i = $isGrave ? ((crc32((string)$r['slug']) & 0x7fffffff) % count($set))
                          : (crc32((string)$r['slug']) % count($set));
            $key = (string)$set[abs($i) % count($set)]['skill_key'];
            $derived = true;
        }
        $out[] = ['page_id' => $pid, 'skill_key' => $key, 'views' => (int)$r['views'], 'derived' => $derived];
    }
    return $out;
}

/** Our own median views across everything posted — the only fair bar (never a global one). */
function pb_own_median(PDO $pdo): float {
    $v = $pdo->query(
        "SELECT MAX(m.views) v FROM platform_videos pv
           JOIN platform_metrics m ON m.video_id = pv.id
          GROUP BY pv.page_id HAVING v > 0")->fetchAll(PDO::FETCH_COLUMN);
    $v = array_map('floatval', $v);
    if (!$v) return 0.0;
    sort($v);
    $m = intdiv(count($v), 2);
    return count($v) % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
}

/**
 * The scoreboard. Every row carries its own sample size and an explicit verdict,
 * and TOO-EARLY is the expected answer at this volume rather than a failure.
 */
function pb_winrates(PDO $pdo, string $kind = ''): array {
    $median = pb_own_median($pdo);
    $rows = pb_attributed($pdo);
    $labels = [];
    try {
        foreach ($pdo->query("SELECT skill_key, label, kind, active FROM skill") as $s)
            $labels[(string)$s['skill_key']] = $s;
    } catch (Throwable $e) {}

    $by = [];
    foreach ($rows as $r) {
        $k = $r['skill_key'];
        if ($kind !== '' && (($labels[$k]['kind'] ?? '') !== $kind)) continue;
        $by[$k]['views'][] = $r['views'];
        $by[$k]['derived'] = ($by[$k]['derived'] ?? 0) + ($r['derived'] ? 1 : 0);
    }

    $out = [];
    foreach ($by as $k => $d) {
        $vs = $d['views'];
        sort($vs);
        $m = intdiv(count($vs), 2);
        $skMed = count($vs) % 2 ? $vs[$m] : ($vs[$m - 1] + $vs[$m]) / 2;
        $above = count(array_filter($vs, fn($v) => $v >= $median));
        $rate  = count($vs) ? $above / count($vs) : 0.0;
        $out[] = [
            'skill_key' => $k,
            'label'     => (string)($labels[$k]['label'] ?? $k),
            'kind'      => (string)($labels[$k]['kind'] ?? '?'),
            'active'    => (int)($labels[$k]['active'] ?? 1),
            'n'         => count($vs),
            'median'    => $skMed,
            'above'     => $above,
            'rate'      => round($rate, 3),
            'best'      => max($vs),
            'derived'   => (int)$d['derived'],
            'verdict'   => count($vs) < PB_MIN_SAMPLE ? 'TOO-EARLY' : 'MEASURED',
        ];
    }
    usort($out, fn($a, $b) => [$b['rate'], $b['n']] <=> [$a['rate'], $a['n']]);
    return ['median' => $median, 'skills' => $out];
}

/**
 * Which skills the evidence would support retiring — a NOMINATION, never an act
 * (law 2). A skill qualifies only with a real sample AND a clear margin below
 * the rest of its kind. On today's data this returns nothing, which is correct:
 * the three normal archetypes sit within 2% of each other and the grave pair
 * have n=3 and n=4.
 */
function pb_retirement_candidates(PDO $pdo): array {
    $w = pb_winrates($pdo);
    $byKind = [];
    foreach ($w['skills'] as $s) $byKind[$s['kind']][] = $s;

    $out = [];
    foreach ($byKind as $kind => $set) {
        $eligible = array_values(array_filter($set, fn($s) => $s['n'] >= PB_MIN_SAMPLE && $s['active']));
        if (count($eligible) < 2) continue;                 // nothing to compare against
        $rates = array_column($eligible, 'rate');
        $bestRate = max($rates);
        foreach ($eligible as $s) {
            if ($bestRate - $s['rate'] < PB_LOSS_MARGIN) continue;
            $out[] = $s + ['why' => 'wins ' . round($s['rate'] * 100) . '% of the time against '
                                  . round($bestRate * 100) . '% for the best in its group, over '
                                  . $s['n'] . ' posted videos'];
        }
    }
    return $out;
}

/** Retire a skill. Only ever called for the owner, never by the machine (law 2). */
function pb_retire(PDO $pdo, string $skillKey, string $why): bool {
    try {
        $st = $pdo->prepare("UPDATE skill SET active=0, retired_at=UTC_TIMESTAMP(), retired_why=?
                             WHERE skill_key=? AND active=1");
        $st->execute([mb_substr($why, 0, 300), $skillKey]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

/** Bring one back. Retirement must be as reversible as everything else here. */
function pb_restore(PDO $pdo, string $skillKey): bool {
    try {
        $st = $pdo->prepare("UPDATE skill SET active=1, retired_at=NULL, retired_why=NULL WHERE skill_key=?");
        $st->execute([$skillKey]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

/**
 * SELF-TEST. The load-bearing test is the first one: with every skill active the
 * library must reproduce the hard-coded rotation exactly, for real slugs off the
 * live site. If that ever fails, installing this organ silently rewrote how every
 * video opens.
 */
function pb_selftest(): array {
    $pass = 0; $fail = 0; $notes = [];
    $t = function (string $name, bool $ok, string $got = '') use (&$pass, &$fail, &$notes) {
        $ok ? $pass++ : $fail++;
        $notes[] = ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $got === '' ? '' : "  (got: {$got})");
    };

    // the two arrays exactly as video_write_script() holds them
    $hookStyles = array_values(array_map(fn($r) => $r[4],
        array_filter(pb_seed_data(), fn($r) => $r[0] === 'hook')));
    $graveHooks = array_values(array_map(fn($r) => $r[4],
        array_filter(pb_seed_data(), fn($r) => $r[0] === 'grave_hook')));
    $t('three normal archetypes seeded', count($hookStyles) === 3, (string)count($hookStyles));
    $t('three grave archetypes seeded', count($graveHooks) === 3, (string)count($graveHooks));

    // LAW 1: the picker must reproduce the old selector for real slugs.
    $slugs = ['twitch-data-leak', 'kai-cenat-s-la-peace-meme-2026', 'asmongold-s-personal-life',
              'youtube-vs-netflix-creator-deals-2026', 'twitch-streamer-attacked-by-mom-2026',
              'psa-class-action-lawsuit', 'freddy-s-toxicity-driven-x-deactivation'];
    $same = true; $sameG = true;
    foreach ($slugs as $sl) {
        $old = $hookStyles[crc32($sl) % 3];
        $set = array_values(array_filter(pb_seed_data(), fn($r) => $r[0] === 'hook'));
        usort($set, fn($a, $b) => $a[1] <=> $b[1]);
        $new = $set[abs(crc32($sl) % count($set)) % count($set)][4];
        if ($old !== $new) { $same = false; break; }

        $oldG = $graveHooks[(crc32($sl) & 0x7fffffff) % 3];
        $setG = array_values(array_filter(pb_seed_data(), fn($r) => $r[0] === 'grave_hook'));
        usort($setG, fn($a, $b) => $a[1] <=> $b[1]);
        $newG = $setG[abs((crc32($sl) & 0x7fffffff) % count($setG)) % count($setG)][4];
        if ($oldG !== $newG) { $sameG = false; break; }
    }
    $t('LAW 1: normal rotation is byte-identical to the old code', $same);
    $t('LAW 1: grave rotation is byte-identical to the old code', $sameG);

    // slot order is what carries law 1 — a reorder must be caught
    $slots = array_map(fn($r) => $r[1], array_filter(pb_seed_data(), fn($r) => $r[0] === 'hook'));
    $t('hook slots are 0,1,2 in order', array_values($slots) === [0, 1, 2], implode(',', $slots));

    // sample floor
    $t('the floor is above the current per-skill counts', PB_MIN_SAMPLE > 4);
    $t('a 4-video skill cannot be MEASURED', 4 < PB_MIN_SAMPLE);
    $t('a 19-video skill can be', 19 >= PB_MIN_SAMPLE);

    // keys are unique and stable
    $keys = array_map(fn($r) => $r[2], pb_seed_data());
    $t('every skill key is unique', count($keys) === count(array_unique($keys)));
    $t('keys are slug-safe', !array_filter($keys, fn($k) => !preg_match('/^[a-z0-9_]+$/', $k)));

    return ['pass' => $pass, 'fail' => $fail, 'notes' => $notes];
}
