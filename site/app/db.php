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
