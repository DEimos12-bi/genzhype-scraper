<?php
// GenZHype | PDO connection (used only when config use_db = true).
function db(bool $force = false) {
    static $pdo = null;
    if ($pdo !== null && !$force) return $pdo;
    global $CONFIG;
    $d = $CONFIG['db'];
    $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // PROBE_PROFILE=1 (CLI diagnostics only): per-query timings via SHOW PROFILES
    if (PHP_SAPI === 'cli' && getenv('PROBE_PROFILE')) { try { $pdo->exec('SET profiling=1, profiling_history_size=100'); } catch (Throwable $e) {} }
    return $pdo;
}

/**
 * SEO-BATCH-1: a live handle, reconnecting if MySQL dropped us.
 *
 * The drafting path now does REAL retrieval before it writes (origin artifact
 * discovery + source fetching = minutes of HTTP), and the cached PDO idles past
 * MySQL's wait_timeout in the meantime. The first gaming draft died exactly
 * there: "MySQL server has gone away" on the INSERT, after all the expensive
 * work had already succeeded. Losing a finished draft to an idle timeout is
 * pure waste, so ping and reconnect before any long-delayed write.
 */
function db_alive(): PDO {
    try {
        db()->query('SELECT 1');
        return db();
    } catch (Throwable $e) {
        return db(true);
    }
}

/**
 * A REAL update of a page: a new dated event on its timeline. Moves the date readers and search
 * engines see (pages.content_updated_at: the byline, dateModified, the citation, the sitemap) and
 * the cache version (updated_at). Every other change (covers, embeds, both sides, evidence reads,
 * summary refreshes, trend numbers) moves only updated_at: on 2026-09-26 an outside site check found
 * 184 pages "updated" with nothing new, because one column did both jobs.
 */
function page_content_touched(PDO $pdo, int $pageId): void {
    $pdo->prepare("UPDATE pages SET content_updated_at=UTC_TIMESTAMP(), updated_at=NOW() WHERE id=?")->execute([$pageId]);
}

/** A page made again (a redo): "Updated" moves to now (owner rule 2, 2026-09-27); the publish date stays (pages_publish_date_lock). */
function page_redone(PDO $pdo, int $pageId): void {
    page_content_touched($pdo, $pageId);
}

/**
 * THE PUBLISH DATE NEVER CHANGES (owner rule 2, 2026-09-27). Once a page has been live (pages.live_once), the database
 * itself keeps its published_at: every UPDATE that tries to move it is undone by a trigger, whatever code sent it (six
 * code paths wrote published_at=NOW(), so a held page approved, or a live page published again, got a new date). A page
 * going live for the first time still gets its date. Fixing a wrong date on purpose means dropping the trigger first.
 * Idempotent; run outside a transaction (DDL commits an open one, r151).
 */
function pages_publish_date_lock(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='pages_publish_date_lock'")->fetchColumn() > 0) return;
    try { $pdo->exec("ALTER TABLE pages ADD COLUMN live_once TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
    // live before: published now, merged copies (they were live), pages a person was asked to read while live, and
    // pages whose publish time came after their draft time (page_publish_live set it)
    $pdo->exec("UPDATE pages SET live_once=1 WHERE status='published' OR redirect_to IS NOT NULL OR human_review IS NOT NULL
                OR published_at > created_at + INTERVAL 5 MINUTE");
    $pdo->exec("CREATE TRIGGER pages_publish_date_lock BEFORE UPDATE ON pages FOR EACH ROW
                BEGIN
                  IF OLD.live_once = 1 THEN SET NEW.published_at = OLD.published_at; SET NEW.live_once = 1;
                  ELSEIF NEW.status = 'published' THEN SET NEW.live_once = 1;
                  END IF;
                END");
    $pdo->exec("CREATE TRIGGER pages_publish_date_lock_new BEFORE INSERT ON pages FOR EACH ROW
                BEGIN IF NEW.status = 'published' THEN SET NEW.live_once = 1; END IF; END");
}

/**
 * sources.published_on (2026-09-26): the source's own date (an article's publish date, a post's date),
 * NULL when it states none. retrieved_on cannot tell: an undated source stores the day we fetched it
 * there. The fact check (verify.php) shows it, so an event dated by its article ("Bustle reports ...")
 * is not called unsourced. Idempotent; run outside a transaction (an ALTER commits an open one, r151).
 */
function sources_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE sources ADD COLUMN published_on DATE NULL"); } catch (Throwable $e) { /* already there */ }
    $done = true;
}

/** A real YYYY-MM-DD for sources.published_on, or null (no date, month-only, or not a calendar day). */
function source_date(?string $d): ?string {
    $d = substr(trim((string)$d), 0, 10);
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
}

/** The newest real timeline day of a story (a full date, not in the future), '' when it has none. */
function page_newest_event(PDO $pdo, int $pageId): string {
    $st = $pdo->prepare("SELECT COALESCE(MAX(e.event_date), '') FROM events e JOIN dramas d ON d.id=e.drama_id
                         WHERE d.page_id=? AND e.video_only=0 AND e.event_date NOT LIKE '%-00' AND e.event_date <= UTC_DATE()");
    $st->execute([$pageId]);
    return (string)$st->fetchColumn();
}

/**
 * Events were added: the public date moves only when the page now has a newer development than $before (its newest
 * day before the change). Owner 2026-09-27: 8 pages showed "Updated Sep 26" for events dated June and July. True
 * when it moved; otherwise only the cache version moves.
 */
function page_content_touched_if_newer(PDO $pdo, int $pageId, string $before): bool {
    if (page_newest_event($pdo, $pageId) > $before) { page_content_touched($pdo, $pageId); return true; }
    $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$pageId]);
    return false;
}

/**
 * An article's own title for the source list (owner 2026-09-27: "show real titles"; 1,559 of 1,714 sources on live
 * stories showed the article's first words). The site's name after " | ", or after " - " when that part is the site's
 * name, comes off; a block or error page's title ("Just a moment...") is no title. null when there is none.
 */
function source_title(?string $t, string $url = ''): ?string {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$t), ENT_QUOTES)));
    if ($t === '' || preg_match('/^(just a moment|access denied|attention required|page not found|404|403|forbidden|are you a robot|sign in|log in|captcha)/i', $t)) return null;
    $core = preg_replace('/[^a-z0-9]/', '', preg_replace('/\.[a-z.]{2,12}$/', '', preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)))));
    $t = preg_replace('/\s+\|\s+[^|]{1,40}$/u', '', $t);
    if (preg_match('/^(.*\S)\s+[-–—]\s+([^-–—]{1,40})$/u', $t, $m)) {
        $tail = preg_replace('/[^a-z0-9]/', '', strtolower($m[2]));
        if ($tail !== '' && (($core !== '' && (str_contains($core, $tail) || str_contains($tail, $core))) || in_array($tail, ['wikipedia', 'reddit', 'youtube', 'twitter', 'x'], true))) $t = $m[1];
    }
    return mb_strlen($t) >= 8 ? mb_substr($t, 0, 250) : null;
}
