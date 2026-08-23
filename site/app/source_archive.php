<?php
/**
 * ARCHIVE AT CAPTURE (r93) — keep a copy of every source the moment we cite it.
 *
 * THE RULE THIS IMPLEMENTS. Open-source verification practice puts this first:
 * archive immediately on discovery, BEFORE publication, because "a social
 * media post may be deleted by a user after you publish an investigation".
 * The second reason is anti-forgery — "screenshots can be easily forged, so it
 * is vital that you find a way to retain the materials in a way that shows
 * that you did not have the opportunity to modify the content". Archiving is
 * NOT verification: we capture first and judge afterwards.
 * See videorepos/SOURCING-PLAYBOOK.md for the quoted sources.
 *
 * WHY WE NEEDED IT. A sweep on 2026-08-10 found 30 of 423 cited sources broken
 * and THIRTEEN citations on published pages pointing at articles that had
 * moved or never existed at that address. Every one of them would have
 * survived as a copy taken the day we found it. It also makes the fabricated
 * URL impossible to miss: you cannot archive a page that does not exist, so
 * the failure becomes loud at write time instead of silent for weeks.
 *
 * WHAT IT KEEPS, AND WHERE.
 *   - our own gzipped copy of the page, PRIVATE (outside public_html), with a
 *     sha256 so a later reader can prove it was not edited;
 *   - the context the rule asks for: title, publisher, published date,
 *     description, byline;
 *   - a public Wayback Machine URL, which is the link a reader can check —
 *     a third party holding the same bytes is the whole point.
 * The private copy is EVIDENCE, not publication: republishing someone's
 * article wholesale is a different act with different rights attached, so the
 * snapshot never goes under public_html.
 *
 * SPEED. The local capture is one HTTP fetch and is synchronous. The Wayback
 * submission can take up to a minute, so it is queued and swept separately —
 * a slow third party must never hold up the pipeline.
 */
declare(strict_types=1);

const SA_DIR       = __DIR__ . '/../storage/snapshots';
const SA_UA        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                   . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const SA_TIMEOUT   = 25;
const SA_MAX_BYTES = 3000000;      // 3 MB of HTML is already an outlier

function sa_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS source_archive (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        url VARCHAR(768) NOT NULL,
        status VARCHAR(12) NOT NULL,          -- ok | dead | blocked | error
        http_code SMALLINT NOT NULL DEFAULT 0,
        final_url VARCHAR(768) NULL,
        title VARCHAR(512) NULL,
        publisher VARCHAR(190) NULL,
        published VARCHAR(40) NULL,
        description TEXT NULL,
        snapshot_path VARCHAR(255) NULL,
        sha256 CHAR(64) NULL,
        bytes INT NOT NULL DEFAULT 0,
        wayback_url VARCHAR(768) NULL,
        wayback_state VARCHAR(12) NOT NULL DEFAULT 'queued',
        note VARCHAR(255) NULL,
        captured_at DATETIME NOT NULL,
        UNIQUE KEY uniq_source (source_id),
        KEY idx_status (status),
        KEY idx_wayback (wayback_state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!is_dir(SA_DIR)) @mkdir(SA_DIR, 0750, true);
}

/** One fetch, browser-shaped, redirects followed. Never throws. */
function sa_fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => SA_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_USERAGENT => SA_UA,
        CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml',
                               'Accept-Language: en-US,en;q=0.9'],
    ]);
    $body = (string)curl_exec($ch);
    $out = [
        'code'  => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'final' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'err'   => (string)curl_error($ch),
        'body'  => mb_strlen($body, '8bit') > SA_MAX_BYTES
                     ? substr($body, 0, SA_MAX_BYTES) : $body,
    ];
    curl_close($ch);
    return $out;
}

/**
 * Is this page actually the thing we cited? A 200 is not enough: publishers
 * answer a dead article with their front page, and that reads as success to
 * every naive checker we had before today.
 */
function sa_verdict(string $url, array $r): array {
    if ($r['code'] === 0)                     return ['error',   'no response: ' . mb_substr($r['err'], 0, 80)];
    if (in_array($r['code'], [401, 403, 406, 429], true))
                                              return ['blocked',  'bot wall (http ' . $r['code'] . ') — alive for a reader'];
    if ($r['code'] >= 400)                    return ['dead',     'http ' . $r['code']];
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $r['body'], $m)) {
        $t = mb_strtolower(trim(html_entity_decode(strip_tags($m[1]))));
        foreach (['404', 'not found', 'page unavailable', 'page not found',
                  'access denied', 'no longer available'] as $bad) {
            if (str_contains($t, $bad)) return ['dead', 'dead-page title: ' . mb_substr($t, 0, 60)];
        }
    }
    // article collapsed onto the publisher's front page
    require_once __DIR__ . '/monitor.php';
    if (function_exists('monitor_link_is_gone')
            && monitor_link_is_gone($url, (string)$r['final'])) {
        return ['dead', 'redirected to the front page'];
    }
    return ['ok', ''];
}

/** The context the archiving rule asks to preserve. */
function sa_extract(string $html): array {
    $meta = function (string $prop) use ($html): string {
        foreach (["/<meta[^>]+(?:property|name)=[\"']" . preg_quote($prop, '/')
                  . "[\"'][^>]+content=[\"']([^\"']*)/i",
                  "/<meta[^>]+content=[\"']([^\"']*)[\"'][^>]+(?:property|name)=[\"']"
                  . preg_quote($prop, '/') . "[\"']/i"] as $re) {
            if (preg_match($re, $html, $m)) return trim(html_entity_decode($m[1]));
        }
        return '';
    };
    $title = $meta('og:title');
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1])));
    }
    return [
        'title'       => mb_substr($title, 0, 500),
        'publisher'   => mb_substr($meta('og:site_name'), 0, 180),
        'published'   => mb_substr($meta('article:published_time')
                          ?: $meta('datePublished') ?: $meta('date'), 0, 40),
        'description' => mb_substr($meta('og:description') ?: $meta('description'), 0, 1000),
    ];
}

/**
 * Capture one source. Returns [status, note]. Writes the snapshot, the
 * metadata, and sources.archived_url (once Wayback answers, in the sweep).
 */
function sa_capture(PDO $pdo, int $sourceId, string $url, ?string $html = null): array {
    sa_install($pdo);
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) return ['error', 'not an http url'];

    // The ingest path has usually just downloaded this page to read it. Taking
    // the copy it already holds keeps us to ONE request per source: the point
    // is to keep what we read, and re-fetching would also risk archiving a
    // different version than the one the event was written from.
    $r = ($html !== null && $html !== '')
        ? ['code' => 200, 'final' => $url, 'err' => '', 'body' => $html]
        : sa_fetch($url);
    [$status, $note] = sa_verdict($url, $r);

    $path = null; $sha = null; $bytes = 0; $meta = ['title' => '', 'publisher' => '',
                                                    'published' => '', 'description' => ''];
    if ($status === 'ok' && $r['body'] !== '') {
        $meta  = sa_extract($r['body']);
        $sha   = hash('sha256', $r['body']);
        $bytes = strlen($r['body']);
        $rel   = sprintf('%03d/%d-%s.html.gz', $sourceId % 500, $sourceId, substr($sha, 0, 12));
        $abs   = SA_DIR . '/' . $rel;
        if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0750, true);
        if (@file_put_contents($abs, gzencode($r['body'], 6)) !== false) {
            @chmod($abs, 0640);
            $path = $rel;
        }
    }

    $pdo->prepare(
        "INSERT INTO source_archive
           (source_id,url,status,http_code,final_url,title,publisher,published,
            description,snapshot_path,sha256,bytes,wayback_state,note,captured_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
           status=VALUES(status), http_code=VALUES(http_code), final_url=VALUES(final_url),
           title=VALUES(title), publisher=VALUES(publisher), published=VALUES(published),
           description=VALUES(description), snapshot_path=VALUES(snapshot_path),
           sha256=VALUES(sha256), bytes=VALUES(bytes), note=VALUES(note),
           captured_at=UTC_TIMESTAMP()")
      ->execute([$sourceId, mb_substr($url, 0, 760), $status, (int)$r['code'],
                 mb_substr((string)$r['final'], 0, 760), $meta['title'], $meta['publisher'],
                 $meta['published'], $meta['description'], $path, $sha, $bytes,
                 $status === 'ok' ? 'queued' : 'skip', mb_substr($note, 0, 250)]);

    // fill in the source row's own context if it was never recorded
    if ($status === 'ok' && $meta['title'] !== '') {
        $pdo->prepare("UPDATE sources SET title = COALESCE(NULLIF(title,''), ?),
                              retrieved_on = COALESCE(retrieved_on, CURDATE())
                        WHERE id = ?")->execute([$meta['title'], $sourceId]);
    }
    return [$status, $note];
}

/**
 * Ask the Wayback Machine to keep a public copy. Slow and third-party, so it
 * runs in a sweep rather than in the capture path. A reader can check this
 * link; our private snapshot only proves we did not alter what we read.
 */
function sa_wayback_sweep(PDO $pdo, int $limit = 20): array {
    sa_install($pdo);
    $rows = $pdo->query(
        "SELECT id, source_id, url FROM source_archive
          WHERE wayback_state = 'queued' AND status = 'ok'
          ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    $done = 0; $failed = 0;
    foreach ($rows as $row) {
        $ch = curl_init('https://web.archive.org/save/' . $row['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 75,
            CURLOPT_FOLLOWLOCATION => 1, CURLOPT_USERAGENT => SA_UA,
            CURLOPT_HEADER => 1, CURLOPT_NOBODY => 0]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $eff  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $wb = '';
        if (preg_match('#(https?://web\.archive\.org/web/\d{14}/\S+)#', $resp, $m)) {
            $wb = rtrim($m[1]);
        } elseif (str_contains($eff, '/web/')) {
            $wb = $eff;
        }
        if ($wb !== '' && $code < 400) {
            $pdo->prepare("UPDATE source_archive SET wayback_url=?, wayback_state='ok' WHERE id=?")
                ->execute([mb_substr($wb, 0, 760), (int)$row['id']]);
            $pdo->prepare("UPDATE sources SET archived_url=? WHERE id=?")
                ->execute([mb_substr($wb, 0, 760), (int)$row['source_id']]);
            $done++;
        } else {
            // Save Page Now rate-limits hard; leave it queued for the next sweep
            $pdo->prepare("UPDATE source_archive SET note=? WHERE id=?")
                ->execute(['wayback retry (http ' . $code . ')', (int)$row['id']]);
            $failed++;
        }
    }
    return ['done' => $done, 'failed' => $failed, 'tried' => count($rows)];
}

/** Read a stored snapshot back (for the admin, or to prove what we read). */
function sa_snapshot_html(PDO $pdo, int $sourceId): ?string {
    $q = $pdo->prepare("SELECT snapshot_path, sha256 FROM source_archive WHERE source_id=?");
    $q->execute([$sourceId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r || empty($r['snapshot_path'])) return null;
    $abs = SA_DIR . '/' . $r['snapshot_path'];
    if (!is_file($abs)) return null;
    $html = (string)gzdecode((string)file_get_contents($abs));
    // the hash is the point: if it does not match, the file was touched
    if (!empty($r['sha256']) && hash('sha256', $html) !== $r['sha256']) {
        error_log("source_archive: sha256 MISMATCH for source {$sourceId}");
        return null;
    }
    return $html;
}
