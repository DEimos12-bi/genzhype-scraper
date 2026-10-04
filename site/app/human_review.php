<?php
// GenZHype | A PERSON READS IT FIRST (owner 2026-09-26, from an outside site check: 45 live pages
// carried allegations of sexual assault, abuse of minors or domestic violence and 26 dwelt on deaths,
// every one published by the machine with nobody reading it). A story about a death, sexual violence,
// abuse of minors or domestic abuse is held (status 'review', out of the publish queue) until the
// owner approves it in admin > Human check with his name; the story page then says who reviewed it.
// Measured on the live site the same day: 87 of 685 stories (63 death, 27 sexual violence, 3 minors,
// 3 domestic). A false alarm costs a minute of reading; a miss publishes a grave page unread, so a
// match in the title or summary is enough, and elsewhere on the timeline it takes two.
// Owner rule 7 (2026-09-27): violence and crime words hold a story too (assault, punched, attacked, abuse, arrested,
// police, charged), and the owner reads it before it goes live.

require_once __DIR__ . '/db.php';

const HR_CATEGORIES = [
    'death'           => '/\b(murder\w*|homicide\w*|manslaughter|killed|killing|shot dead|shot and killed|stabbed to death|found dead|dead body|body was found|died|dies|death of|deaths?\b(?! threat)|passed away|suicide\w*|overdos\w*|fatal\w*|funeral|obituar\w*|autopsy|coroner)\b/iu',
    'sexual violence' => '/\b(sexual(ly)? (assault\w*|abuse\w*|misconduct|harass\w*|exploit\w*)|sex(?:ual)?[ -](?:trafficking|trafficker\w*|crimes?|offen[cs]es?|offenders?)|pa?edophil\w*|rape\w*|raping|molest\w*|grooming|groomed|predator\w*|csam|child (sexual|porn\w*)|indecent|non-?consensual)\b/iu',
    'abuse of minors' => '/\b(child abuse|abus\w* (a |his |her |their )?(child|children|kids?|minors?|son|daughter)|minors?\b.{0,40}\b(abus\w*|exploit\w*|endanger\w*)|child endangerment|endanger\w* (a |the |his |her )?(child|children|kids?))\b/iu',
    // owner rule 7 (2026-09-27): "widen the detector words: assault, punched, attacked, abuse, arrested, police, charged, death, killed"
    'violence or crime' => '/\b(assault\w*|punch(?:ed|es|ing)|attack(?:ed|s|ing)|abus(?:e|ed|es|ing|ive)|arrest(?:ed|s|ing)?|police|charged)\b/iu',
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
    // game lore is not a death or an attack (the same idea as video_story_gravity), judged sentence by sentence: a
    // sentence about a game (a boss, a dungeon, respawning) loses those words unless it also names something real.
    // 2026-09-27: the whole-page version read "a 15,000-viewer raid" (a Twitch raid) as game lore and missed
    // "Nitro Camden attacked by mother on stream"
    $lore = '/\b(boss(?:es| fights?)?|dungeons?|mythic|respawn\w*|instakill|loot|npcs?|enem(?:y|ies)|monsters?|zombies?|in-game|gameplay|game master|quests?|patch notes?)\b/iu';
    $real = '/\b(passed away|found dead|body was found|cause of death|autopsy|funeral|obituar\w*|shot dead|police|arrest\w*|streamer|on stream|irl|mother|mom|father|dad|wife|husband|fans?|convention|hospital)\b/iu';
    $strip = fn(string $t): string => implode(' ', array_map(fn(string $s) => preg_match($lore, $s) && !preg_match($real, $s)
        ? (string)preg_replace('/\b(death\w*|dead|died|dies|killed|killing|attack(?:ed|s|ing)?|punch(?:ed|es|ing)?)\b/iu', ' ', $s) : $s,
        preg_split('/(?<=[.!?])\s+/u', $t) ?: [$t]));
    $core = $strip($core);
    $all = $strip($all);
    $out = [];
    foreach (HR_CATEGORIES as $cat => $rx) {
        if (preg_match($rx, $core, $m)) $out[] = "{$cat}: \"" . mb_strtolower($m[0]) . '"';
        elseif (preg_match_all($rx, $all, $mm) >= 2) $out[] = "{$cat}: \"" . mb_strtolower($mm[0][0]) . '" (timeline, ' . count($mm[0]) . 'x)';
    }
    return $out;
}

/**
 * The text of a meme or term page as a reader sees it, for the same detector (owner 2026-10-04: "extend the sensitive
 * detector and Human check to meme and term pages, with the same rules as stories"; /meme/epstein/ went live unread).
 * [title, summary + definition, everything else on the page]
 */
function hr_term_texts(array $t): array {
    $flat = function ($v) use (&$flat): string {
        if (is_string($v)) { $j = json_decode($v, true); return is_array($j) ? $flat($j) : $v; }
        if (!is_array($v)) return '';
        $out = [];
        foreach ($v as $k => $x) { if (in_array($k, ['url', 'handle', 'platform', 'date'], true)) continue; $s = $flat($x); if ($s !== '') $out[] = $s; }
        return implode('. ', $out);
    };
    $body = [];
    foreach (['also_known_as', 'origin', 'usage_note', 'examples', 'related', 'faqs', 'meaning', 'why_trending'] as $k) $body[] = $flat($t[$k] ?? '');
    return [(string)($t['h1'] ?? ''), trim((string)($t['summary'] ?? '') . ' ' . (string)($t['short_def'] ?? '')), implode(' ', array_filter($body))];
}

/** hr_reasons() for a page: a story from its title, summary and timeline; a meme or term from its title, definition and body. */
function hr_page_reasons(PDO $pdo, int $pageId): array {
    $st = $pdo->prepare("SELECT p.h1, p.summary, d.id did FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $st->execute([$pageId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        $tq = $pdo->prepare("SELECT p.h1, p.summary, t.* FROM pages p JOIN terms t ON t.page_id=p.id WHERE p.id=? AND p.type='term'");
        $tq->execute([$pageId]);
        $t = $tq->fetch(PDO::FETCH_ASSOC);
        return $t ? hr_reasons(...hr_term_texts($t)) : [];
    }
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
    foreach ($pdo->query("SELECT id FROM pages WHERE type IN ('drama','term') AND status='published' AND human_review IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
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
function hr_decide(PDO $pdo, int $pageId, string $do, string $reviewer, string $reason = ''): array {
    hr_install($pdo);
    $reviewer = trim(preg_replace('/\s+/', ' ', $reviewer));
    if (mb_strlen($reviewer) < 2 || mb_strlen($reviewer) > 100) return [false, 'Type your name (2 to 100 characters): it is shown on the page as the reviewer.'];
    $st = $pdo->prepare("SELECT h1, status, human_review FROM pages WHERE id=? AND type IN ('drama','term')");
    $st->execute([$pageId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return [false, 'That page was not found.'];
    // what Laya will learn from (owner 2026-09-28): the decision and the reason given for it (laya_log.php)
    if (in_array($do, ['approve', 'reject'], true)) { require_once __DIR__ . '/laya_log.php'; laya_log_decision($pdo, $pageId, $do, $reason, $reviewer); }
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

/**
 * Human check by email (owner 2026-09-28: "keep emailing them to me"). The stories that wait for his approval (not on the
 * site until he approves) and the count of live ones still to read, with links. [subject, body] or null when none waits.
 */
function hr_digest_text(PDO $pdo): ?array {
    hr_install($pdo);
    $wait = $pdo->query("SELECT p.id, p.h1, p.path, p.review_reason, (SELECT r.passed FROM ai_reviews r WHERE r.page_id=p.id AND r.stage='verify' ORDER BY r.id DESC LIMIT 1) fact_check FROM pages p WHERE p.type IN ('drama','term') AND p.human_review='needed' AND p.status='review' ORDER BY p.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$wait) return null;
    $live = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE type IN ('drama','term') AND human_review='needed' AND status='published'")->fetchColumn();
    $n = count($wait);
    $body = "{$n} " . ($n === 1 ? 'story waits' : 'stories wait') . " for your approval. They are not on the site until you approve them.\n\n";
    foreach (array_slice($wait, 0, 25) as $i => $w)
        $body .= ($i + 1) . '. ' . $w['h1'] . "\n   Why it waits: " . (preg_replace('/;? ?fact-checked, waiting for your approval\.?/', '', (string)$w['review_reason']) ?: 'crime, abuse or death story')
                 . "\n   Fact check: " . ($w['fact_check'] === null ? 'not run yet' : ((int)$w['fact_check'] === 1 ? 'passed' : 'found problems (it stays off the site either way until you approve)')) . "\n   Read it (log in to admin first): https://genzhype.com" . $w['path'] . "?preview=1\n\n";
    if ($n > 25) $body .= "... and " . ($n - 25) . " more.\n\n";
    $body .= "Approve or reject them in admin > Human check: https://genzhype.com/admin/?tab=review\n";
    if ($live) $body .= "{$live} stories already live are listed there too, for a read.\n";
    $body .= "\nThis email comes once a day while a story waits.\n";
    return ['GenZHype: ' . $n . ' ' . ($n === 1 ? 'story waits' : 'stories wait') . ' for your approval', $body];
}

/**
 * The hourly tick asks; the email goes out once a day (from 08:00 UTC) while stories wait, to the address in
 * app/NOTIFY_EMAIL, through app/mailer.php (PHP's mail() delivers nothing on this host). A failed send is tried again
 * 6 hours later. Every attempt is written to app/notify.log. $force: send now (cli.php humandigest send).
 */
function hr_digest_send(PDO $pdo, bool $force = false): string {
    $to = trim((string)@file_get_contents(__DIR__ . '/NOTIFY_EMAIL'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 'no address in app/NOTIFY_EMAIL';
    $stateFile = __DIR__ . '/cache/hr_digest.json';
    $st = json_decode((string)@file_get_contents($stateFile), true) ?: [];
    if (!$force) {
        if ((int)gmdate('G') < 8) return 'before 08:00 UTC';
        if (!empty($st['sent_at']) && strtotime($st['sent_at']) > time() - 20 * 3600) return 'already sent today';
        if (!empty($st['failed_at']) && strtotime($st['failed_at']) > time() - 6 * 3600) return 'last try failed less than 6 hours ago';
    }
    $mail = hr_digest_text($pdo);
    if (!$mail) return 'nothing waits';
    require_once __DIR__ . '/mailer.php';
    $r = mailer_send($to, $mail[0], $mail[1]);
    $st[$r['ok'] ? 'sent_at' : 'failed_at'] = gmdate('c');
    @file_put_contents($stateFile, json_encode($st));
    @file_put_contents(__DIR__ . '/notify.log', date('c') . ' human check digest smtp = ' . ($r['ok'] ? 'SENT' : 'FAILED: ' . $r['error']) . "\n", FILE_APPEND);
    return $r['ok'] ? 'sent' : 'failed: ' . $r['error'];
}
