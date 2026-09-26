<?php
// GenZHype | A PERSON READS IT FIRST (owner 2026-09-26, from an outside site check: 45 live pages
// carried allegations of sexual assault, abuse of minors or domestic violence and 26 dwelt on deaths,
// every one published by the machine with nobody reading it). A story about a death, sexual violence,
// abuse of minors or domestic abuse is held (status 'review', out of the publish queue) until the
// owner approves it in admin > Human check with his name; the story page then says who reviewed it.
// Measured on the live site the same day: 87 of 685 stories (63 death, 27 sexual violence, 3 minors,
// 3 domestic). A false alarm costs a minute of reading; a miss publishes a grave page unread, so a
// match in the title or summary is enough, and elsewhere on the timeline it takes two.

require_once __DIR__ . '/db.php';

const HR_CATEGORIES = [
    'death'           => '/\b(murder\w*|homicide\w*|manslaughter|killed|killing|shot dead|shot and killed|stabbed to death|found dead|dead body|body was found|died|dies|death of|deaths?\b(?! threat)|passed away|suicide\w*|overdos\w*|fatal\w*|funeral|obituar\w*|autopsy|coroner)\b/iu',
    'sexual violence' => '/\b(sexual(ly)? (assault\w*|abuse\w*|misconduct|harass\w*|exploit\w*)|rape\w*|raping|molest\w*|grooming|groomed|predator\w*|csam|child (sexual|porn\w*)|indecent|non-?consensual)\b/iu',
    'abuse of minors' => '/\b(child abuse|abus\w* (a |his |her |their )?(child|children|kids?|minors?|son|daughter)|minors?\b.{0,40}\b(abus\w*|exploit\w*|endanger\w*)|child endangerment|endanger\w* (a |the |his |her )?(child|children|kids?))\b/iu',
    'domestic abuse'  => '/\b(domestic (violence|abuse|assault|battery)|abusive (relationship|partner|ex|boyfriend|girlfriend|husband|wife)|(beat|hit|choked|strangled) (his|her) (girlfriend|boyfriend|wife|husband|partner|ex)|intimate partner violence)\b/iu',
];

/** Idempotent; run outside a transaction (an ALTER commits an open one on MariaDB, r151). */
function hr_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    foreach (["ADD COLUMN human_review VARCHAR(10) NULL", "ADD COLUMN review_reason VARCHAR(255) NULL",
              "ADD COLUMN reviewed_by VARCHAR(100) NULL", "ADD COLUMN reviewed_at DATETIME NULL"] as $alter)
        try { $pdo->exec("ALTER TABLE pages {$alter}"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/** Why a story needs a person: ['death: "died"', ...], [] when it does not. */
function hr_reasons(string $title, string $summary, string $eventsText): array {
    $core = ' ' . $title . '. ' . $summary . ' ';
    $all = $core . ' ' . $eventsText;
    // game lore is not a death (the same guard as video_story_gravity)
    if (preg_match('/\b(dungeon|raid|mythic|gameplay|game master|instakill|respawn|loot|boss fight|patch)\b/iu', $all)
        && !preg_match('/\b(passed away|found dead|body was found|cause of death|autopsy|funeral|obituar\w*|shot dead)\b/iu', $all)) {
        $core = (string)preg_replace('/\b(death\w*|dead|died|dies|killed|killing)\b/iu', ' ', $core);
        $all = (string)preg_replace('/\b(death\w*|dead|died|dies|killed|killing)\b/iu', ' ', $all);
    }
    $out = [];
    foreach (HR_CATEGORIES as $cat => $rx) {
        if (preg_match($rx, $core, $m)) $out[] = "{$cat}: \"" . mb_strtolower($m[0]) . '"';
        elseif (preg_match_all($rx, $all, $mm) >= 2) $out[] = "{$cat}: \"" . mb_strtolower($mm[0][0]) . '" (timeline, ' . count($mm[0]) . 'x)';
    }
    return $out;
}

/** hr_reasons() for a story page, from its title, summary and timeline. */
function hr_page_reasons(PDO $pdo, int $pageId): array {
    $st = $pdo->prepare("SELECT p.h1, p.summary, d.id did FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $st->execute([$pageId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return [];
    $ev = $pdo->prepare("SELECT GROUP_CONCAT(CONCAT(title, '. ', COALESCE(description, '')) SEPARATOR ' ') FROM events WHERE drama_id=? AND video_only=0");
    $ev->execute([(int)$p['did']]);
    return hr_reasons((string)$p['h1'], (string)$p['summary'], (string)$ev->fetchColumn());
}

/**
 * The hold, asked before a story goes live: '' when it may (nothing grave, or a person approved it),
 * else the reasons, with the page marked for admin > Human check.
 */
function hr_hold(PDO $pdo, int $pageId): string {
    hr_install($pdo);
    $state = (string)$pdo->query("SELECT COALESCE(human_review, '') FROM pages WHERE id=" . $pageId)->fetchColumn();
    if ($state === 'approved') return '';
    $why = implode('; ', hr_page_reasons($pdo, $pageId));
    if ($why === '') return '';
    if ($state !== 'rejected')
        $pdo->prepare("UPDATE pages SET human_review='needed', review_reason=? WHERE id=?")->execute([mb_substr($why, 0, 255), $pageId]);
    return $why;
}

/** Stories already live that a person has not read: marked for admin > Human check, left live. */
function hr_mark_live(PDO $pdo): int {
    hr_install($pdo);
    $n = 0;
    $set = $pdo->prepare("UPDATE pages SET human_review='needed', review_reason=? WHERE id=?");
    foreach ($pdo->query("SELECT id FROM pages WHERE type='drama' AND status='published' AND human_review IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $why = implode('; ', hr_page_reasons($pdo, (int)$pid));
        if ($why === '') continue;
        $set->execute([mb_substr($why, 0, 255), (int)$pid]);
        $n++;
    }
    return $n;
}

/**
 * The owner's decision (admin > Human check). approve: marked reviewed by $reviewer; a held story
 * then goes live through page_publish_live (the index rules still apply). reject: the story goes
 * off the site (status 'archived', nothing deleted). [ok, message]
 */
function hr_decide(PDO $pdo, int $pageId, string $do, string $reviewer): array {
    hr_install($pdo);
    $reviewer = trim(preg_replace('/\s+/', ' ', $reviewer));
    if (mb_strlen($reviewer) < 2 || mb_strlen($reviewer) > 100) return [false, 'Type your name (2 to 100 characters): it is shown on the page as the reviewer.'];
    $st = $pdo->prepare("SELECT h1, status, human_review FROM pages WHERE id=? AND type='drama'");
    $st->execute([$pageId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return [false, 'That story was not found.'];
    if ($do === 'approve') {
        $pdo->prepare("UPDATE pages SET human_review='approved', reviewed_by=?, reviewed_at=UTC_TIMESTAMP(), updated_at=NOW() WHERE id=?")
            ->execute([$reviewer, $pageId]);
        if ($p['status'] !== 'review') return [true, "Marked as reviewed by {$reviewer}: {$p['h1']}"];
        require_once __DIR__ . '/gate.php';
        ob_start();                                    // page_publish_live narrates for the cron log; the admin redirects
        $indexed = page_publish_live($pdo, $pageId);
        ob_end_clean();
        return [true, "Approved by {$reviewer} and published" . ($indexed ? ' (open to Google)' : ' (live, not yet open to Google: the usual index rules)') . ": {$p['h1']}"];
    }
    if ($do === 'reject') {
        $pdo->prepare("UPDATE pages SET human_review='rejected', reviewed_by=?, reviewed_at=UTC_TIMESTAMP(), status='archived', robots='noindex', updated_at=NOW() WHERE id=?")
            ->execute([$reviewer, $pageId]);
        return [true, "Rejected by {$reviewer} and kept off the site (archived, nothing deleted): {$p['h1']}"];
    }
    return [false, 'Unknown action.'];
}
