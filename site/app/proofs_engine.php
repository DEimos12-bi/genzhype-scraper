<?php
/* GenZHype | PROOF SCREENSHOTS ENGINE (r155, 2026-09-11). Server logic behind the proof screenshots:
   - the queue: which source URLs cited by a timeline event on a published story page still need a
     capture (api/proofjobs.php),
   - the ingest checks and the pending store (api/proofingest.php),
   - the failure log with its backoff (app/cache/proofs_failed.json),
   - the promotion of pending captures after the safety check (hourly run and `php app/cli.php proofs`).
   The endpoints stay thin; the CLI calls the same functions. No AI and no outbound HTTP run anywhere
   in this file except proofs_promote(), which refuses to run inside a web request. */
declare(strict_types=1);

if (!function_exists('proof_key')) require_once __DIR__ . '/proofs.php';

const PROOFS_MAX_BYTES    = 4194304;    // upload cap: 4 MB
const PROOFS_MAX_PIXELS   = 10000000;   // decode guard: width x height read from the header before decoding
const PROOFS_MIN_W        = 300;
const PROOFS_MIN_H        = 150;
const PROOFS_STORE_W      = 1000;       // stored width cap, aspect kept
const PROOFS_WEBP_Q       = 80;
const PROOFS_GIVE_UP      = 5;          // attempts >= 5: never offered again
const PROOFS_BACKOFF_BASE = 21600;      // 6 h after the first failure, doubling
const PROOFS_BACKOFF_CAP  = 604800;     // 7 days
const PROOFS_KEY_RE       = '/^[0-9a-f]{16}$/D';
const PROOFS_SAFETY_PROMPT = 'General-audience site check. Does this image contain sexual content, gore, or visible slurs? STRICT JSON: {"unsafe":false}';
const PROOFS_SAFETY_PROMPT_NVIDIA = 'Does this image contain sexual content, gore, or visible slurs? STRICT JSON: {"unsafe":false}';

/* ------------------------------------------------------------------ small helpers */

function proofs_err(int $code, string $error): array {
    return ['ok' => false, 'code' => $code, 'error' => $error];
}

/** A request field as a string ('' when absent or not a scalar string, e.g. field[]=x). */
function proofs_field(array $in, string $name): string {
    $v = $in[$name] ?? '';
    return is_string($v) ? $v : '';
}

/** Plain one-line text: valid UTF-8, no tags, no control characters, no em-dashes, at most $max chars. */
function proofs_clean_text(string $s, int $max): string {
    $s = mb_scrub($s, 'UTF-8');
    $s = strip_tags($s);
    $s = str_replace("\u{2014}", '-', $s);
    $s = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return trim(mb_substr($s, 0, $max));
}

/** The ingest token check (same secret as api/imgjobs.php; an empty configured token denies everyone). */
function proofs_token_ok(string $given): bool {
    $want = (string)($GLOBALS['CONFIG']['ingest_token'] ?? '');
    return $want !== '' && hash_equals($want, $given);
}

/** php.ini size ("256M") to bytes; 0 = no limit. */
function proofs_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // fall through
        case 'm': $n *= 1024;   // fall through
        case 'k': $n *= 1024;
    }
    return $n;
}

/* ------------------------------------------------------------------ cited URLs (queue + ingest) */

/**
 * Every distinct source URL cited by a timeline event on a published story page, one row per URL:
 * url, source_id (lowest), publisher, title, published_at (newest citing page), page_id (that page).
 * One SELECT; grouped on the exact bytes of the URL because the column collation is case-insensitive
 * and a YouTube id differs by case alone. Newest page first, then sources.id.
 */
function proofs_cited_rows(PDO $pdo): array {
    return $pdo->query(
        "SELECT MIN(s.url) AS url,
                MIN(s.id) AS source_id,
                MAX(s.publisher) AS publisher,
                MAX(s.title) AS title,
                MAX(p.published_at) AS newest_pub,
                CAST(SUBSTRING_INDEX(GROUP_CONCAT(p.id ORDER BY p.published_at DESC, p.id DESC), ',', 1) AS UNSIGNED) AS page_id
           FROM sources s
           JOIN events e ON e.source_id = s.id
           JOIN dramas d ON d.id = e.drama_id
           JOIN pages  p ON p.id = d.page_id
          WHERE p.status = 'published' AND p.type = 'drama'
            AND (s.url LIKE 'http://%' OR s.url LIKE 'https://%')
          GROUP BY CAST(s.url AS BINARY)
          ORDER BY newest_pub DESC, MIN(s.id) ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** A cited row is capturable when it is an http(s) URL with a known platform. Returns [url, platform] or null. */
function proofs_capturable(string $rawUrl): ?array {
    $url = trim($rawUrl);
    if (!preg_match('#^https?://#i', $url)) return null;
    $platform = proof_platform($url);
    return $platform === '' ? null : [$url, $platform];
}

/**
 * The published-story check for one URL (same join as the queue): the source row plus the newest
 * published story page citing it, or null. The SQL match is collation-loose; the exact byte match
 * is enforced here.
 */
function proofs_cited_source(PDO $pdo, string $url): ?array {
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) return null;
    $st = $pdo->prepare(
        "SELECT s.id AS source_id, s.url, s.publisher, s.title, p.id AS page_id
           FROM sources s
           JOIN events e ON e.source_id = s.id
           JOIN dramas d ON d.id = e.drama_id
           JOIN pages  p ON p.id = d.page_id
          WHERE p.status = 'published' AND p.type = 'drama' AND s.url = ?
          ORDER BY p.published_at DESC, s.id ASC"
    );
    $st->execute([$url]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (trim((string)$r['url']) === $url) return $r;
    }
    return null;
}

/* ------------------------------------------------------------------ failure log */

function proofs_failed_path(): string {
    return __DIR__ . '/cache/proofs_failed.json';
}

/** Seconds to wait after attempt n: 6 h * 2^(n-1), capped at 7 days. */
function proofs_backoff_seconds(int $attempts): int {
    $n = max(1, min(30, $attempts));
    return (int)min(PROOFS_BACKOFF_CAP, PROOFS_BACKOFF_BASE * (2 ** ($n - 1)));
}

/** May this URL be offered to the runner now, given its failure-log entry (or null)? */
function proofs_retry_allowed(?array $entry, ?int $now = null): bool {
    if (!$entry) return true;
    if ((int)($entry['attempts'] ?? 0) >= PROOFS_GIVE_UP) return false;
    $next = strtotime((string)($entry['next_try_at'] ?? ''));
    return $next === false || $next <= ($now ?? time());
}

/** The whole log. Writers replace the file by atomic rename, so a plain read never sees half a file. */
function proofs_failed_read(): array {
    $f = proofs_failed_path();
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

/**
 * Read-modify-write of the log under an exclusive lock (app/cache/proofs_failed.json.lock).
 * $fn(array &$log) edits the log in place; its return value is returned.
 */
function proofs_failed_update(callable $fn): mixed {
    $f = proofs_failed_path();
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('proofs: cache dir missing');
    $lock = @fopen($f . '.lock', 'c');
    if (!$lock) throw new RuntimeException('proofs: failure log lock unavailable');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('proofs: failure log lock failed');
        $log = proofs_failed_read();
        $ret = $fn($log);
        $tmp = $f . '.tmp' . getmypid();
        $json = json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $f)) {
            @unlink($tmp);
            throw new RuntimeException('proofs: failure log write failed');
        }
        return $ret;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** One more failed attempt for a key (or straight to "give up" with $giveUp). Returns the new entry. */
function proofs_record_failure(string $key, string $url, string $error, bool $giveUp = false): array {
    if (!preg_match(PROOFS_KEY_RE, $key)) throw new InvalidArgumentException('proofs: bad key');
    $error = proofs_clean_text($error, 250);
    return proofs_failed_update(function (array &$log) use ($key, $url, $error, $giveUp): array {
        $prev = is_array($log[$key] ?? null) ? $log[$key] : [];
        $attempts = (int)($prev['attempts'] ?? 0) + 1;
        if ($giveUp) $attempts = max($attempts, PROOFS_GIVE_UP);
        $log[$key] = [
            'url'         => trim($url),
            'attempts'    => $attempts,
            'last_error'  => $error !== '' ? $error : 'capture failed',
            'next_try_at' => date(DATE_ATOM, time() + proofs_backoff_seconds($attempts)),
        ];
        return $log[$key];
    });
}

/** Forget a key's failures (after its proof went live). */
function proofs_failed_clear(string $key): void {
    if (!array_key_exists($key, proofs_failed_read())) return;
    proofs_failed_update(function (array &$log) use ($key): void { unset($log[$key]); });
}

/* ------------------------------------------------------------------ queue */

/**
 * The runner's work list: capturable cited URLs with no live or pending proof that are not backing
 * off. ['jobs' => [{key,url,platform,publisher,title,page_id}], 'remaining' => eligible URLs not in
 * this batch]. One SELECT, then file and log filters in PHP.
 */
function proofs_queue(PDO $pdo, int $limit = 25): array {
    $limit = max(1, min(40, $limit));
    $dir = proof_dir();
    $failed = proofs_failed_read();
    $now = time();
    $jobs = [];
    $eligible = 0;
    foreach (proofs_cited_rows($pdo) as $r) {
        $cap = proofs_capturable((string)$r['url']);
        if (!$cap) continue;
        [$url, $platform] = $cap;
        $key = proof_key($url);
        if (!proofs_retry_allowed(is_array($failed[$key] ?? null) ? $failed[$key] : null, $now)) continue;
        if (is_file("{$dir}/{$key}.webp") || is_file("{$dir}/pending/{$key}.webp")) continue;
        $eligible++;
        if (count($jobs) < $limit) {
            $jobs[] = [
                'key'       => $key,
                'url'       => $url,
                'platform'  => $platform,
                'publisher' => (string)($r['publisher'] ?? ''),
                'title'     => (string)($r['title'] ?? ''),
                'page_id'   => (int)$r['page_id'],
            ];
        }
    }
    return ['jobs' => $jobs, 'remaining' => $eligible - count($jobs)];
}

/* ------------------------------------------------------------------ ingest */

/**
 * Validate the fields of one ingest POST (no image bytes, no writes).
 * Failure: ['ok'=>false,'code','error'] plus 'key'/'url' once the URL is verified as a cited source of
 * a published story (the endpoint records those rejections in the failure log so a bad capture backs
 * off instead of being offered again every run).
 * Success: ['ok'=>true,'mode'=>'failed','key','url','error'] or
 *          ['ok'=>true,'mode'=>'image','key','url','platform','kind','credit'].
 */
function proofs_ingest_check(PDO $pdo, array $post): array {
    if (!proofs_token_ok(proofs_field($post, 'token'))) return proofs_err(403, 'bad token');
    $key = proofs_field($post, 'key');
    $url = trim(proofs_field($post, 'url'));
    if ($key === '' || $url === '') return proofs_err(400, 'missing fields');
    if (!preg_match(PROOFS_KEY_RE, $key)) return proofs_err(400, 'bad key');
    if (strlen($url) > 500 || !proofs_capturable($url)) return proofs_err(400, 'bad url');
    if ($key !== proof_key($url)) return proofs_err(400, 'key does not match url');
    if (!proofs_cited_source($pdo, $url)) return proofs_err(404, 'unknown url');

    if (proofs_field($post, 'failed') === '1') {
        return ['ok' => true, 'mode' => 'failed', 'key' => $key, 'url' => $url,
                'error' => proofs_clean_text(proofs_field($post, 'error'), 250) ?: 'capture failed'];
    }
    $verified = ['key' => $key, 'url' => $url];
    $platform = proofs_field($post, 'platform');
    $kind     = proofs_field($post, 'kind');
    $credit   = proofs_clean_text(proofs_field($post, 'credit'), 180);
    if ($platform === '' || $kind === '' || $credit === '') return proofs_err(400, 'missing fields') + $verified;
    if ($platform !== proof_platform($url)) return proofs_err(400, 'platform mismatch') + $verified;
    if ($kind !== 'screenshot' && $kind !== 'thumbnail') return proofs_err(400, 'bad kind') + $verified;
    return ['ok' => true, 'mode' => 'image', 'key' => $key, 'url' => $url,
            'platform' => $platform, 'kind' => $kind, 'credit' => $credit];
}

/** The uploaded file's bytes from a $_FILES entry. $mustBeUploaded=false only for CLI tests. */
function proofs_upload_bytes(mixed $f, bool $mustBeUploaded = true): array {
    if (!is_array($f) || !isset($f['error']) || !is_int($f['error'])) return proofs_err(400, 'missing file');
    switch ($f['error']) {
        case UPLOAD_ERR_OK:        break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return proofs_err(413, 'too large');
        case UPLOAD_ERR_NO_FILE:   return proofs_err(400, 'missing file');
        case UPLOAD_ERR_PARTIAL:   return proofs_err(400, 'partial upload');
        default:                   return proofs_err(500, 'upload failed');
    }
    $tmp = is_string($f['tmp_name'] ?? null) ? $f['tmp_name'] : '';
    if ($tmp === '' || !($mustBeUploaded ? is_uploaded_file($tmp) : is_file($tmp))) return proofs_err(400, 'missing file');
    if ((int)($f['size'] ?? 0) > PROOFS_MAX_BYTES || (int)@filesize($tmp) > PROOFS_MAX_BYTES) return proofs_err(413, 'too large');
    $bytes = @file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '') return proofs_err(400, 'missing file');
    return ['ok' => true, 'bytes' => $bytes];
}

/** Decode and size-check an uploaded capture. ['ok'=>true,'im'=>GdImage,'w','h'] or an error. */
function proofs_decode_image(string $bytes): array {
    if ($bytes === '') return proofs_err(400, 'missing file');
    if (strlen($bytes) > PROOFS_MAX_BYTES) return proofs_err(413, 'too large');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
        || ($info[2] === IMAGETYPE_PNG && substr($bytes, 12, 4) !== 'IHDR')) {   // PNG signature over garbage
        return proofs_err(415, 'not an image');
    }
    if ((int)$info[0] * (int)$info[1] > PROOFS_MAX_PIXELS) return proofs_err(413, 'too large');
    $im = @imagecreatefromstring($bytes);
    if (!$im) return proofs_err(415, 'not an image');
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < PROOFS_MIN_W || $h < PROOFS_MIN_H) {
        imagedestroy($im);
        return proofs_err(400, "image too small ({$w}x{$h}, minimum " . PROOFS_MIN_W . 'x' . PROOFS_MIN_H . ')');
    }
    return ['ok' => true, 'im' => $im, 'w' => $w, 'h' => $h];
}

/**
 * Store a checked capture as pending/<key>.webp (max 1000 px wide, transparency flattened on white,
 * quality 80) + pending/<key>.json. JSON is renamed into place first and the webp last, so the webp
 * (what the queue and the promoter look for) never exists without its metadata.
 * $meta: key, url, platform, kind, credit. $pendingDir is for tests; default proof_dir()/pending.
 */
function proofs_store_pending(GdImage $im, array $meta, ?string $pendingDir = null): array {
    $key = (string)($meta['key'] ?? '');
    if (!preg_match(PROOFS_KEY_RE, $key)) return proofs_err(500, 'store failed');
    $dir = $pendingDir ?? proof_dir() . '/pending';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return proofs_err(500, 'store failed');

    $w = imagesx($im);
    $h = imagesy($im);
    $tw = min($w, PROOFS_STORE_W);
    $th = $w > PROOFS_STORE_W ? max(1, (int)round($h * PROOFS_STORE_W / $w)) : $h;
    $dst = imagecreatetruecolor($tw, $th);
    imagefilledrectangle($dst, 0, 0, $tw - 1, $th - 1, (int)imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $w, $h);

    $tmpW = "{$dir}/{$key}.part" . getmypid() . 'w';
    $tmpJ = "{$dir}/{$key}.part" . getmypid() . 'j';
    $json = json_encode([
        'url'         => trim((string)$meta['url']),
        'platform'    => (string)$meta['platform'],
        'kind'        => (string)$meta['kind'],
        'credit'      => (string)$meta['credit'],
        'captured_at' => date(DATE_ATOM),
        'w'           => $tw,
        'h'           => $th,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $okW = imagewebp($dst, $tmpW, PROOFS_WEBP_Q) && is_file($tmpW) && filesize($tmpW) > 0;
    imagedestroy($dst);
    $okJ = $okW && $json !== false && @file_put_contents($tmpJ, $json) === strlen($json);
    if (!$okW || !$okJ || !@rename($tmpJ, "{$dir}/{$key}.json") || !@rename($tmpW, "{$dir}/{$key}.webp")) {
        @unlink($tmpW);
        @unlink($tmpJ);
        return proofs_err(500, 'store failed');
    }
    return ['ok' => true, 'key' => $key, 'w' => $tw, 'h' => $th];
}

/* ------------------------------------------------------------------ promotion (CLI only) */

/** Pending capture files, oldest first (only well-formed <key>.webp names). */
function proofs_pending_files(?string $pendingDir = null): array {
    $dir = $pendingDir ?? proof_dir() . '/pending';
    $out = [];
    foreach (glob("{$dir}/*.webp") ?: [] as $f) {
        if (preg_match('/^[0-9a-f]{16}\.webp$/', basename($f))) $out[$f] = (int)@filemtime($f);
    }
    asort($out);
    return array_keys($out);
}

/**
 * The receipts safety check (app/cli.php RECEIPTS ENGINE block): Gemini vision, NVIDIA vision fallback.
 * 'safe' | 'unsafe' | 'unreadable' (GD cannot decode the file) | 'no_verdict' (both providers failed).
 */
function proofs_safety_verdict(string $path, int $timeout): string {
    $b64 = beast_b64(['path' => $path]);
    if (!$b64) return 'unreadable';
    $vr = ai_chat([['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => PROOFS_SAFETY_PROMPT],
        ['type' => 'image_url', 'image_url' => ['url' => $b64]],
    ]]], ['gemini'], 0.1, $timeout);
    $vj = isset($vr['error']) ? null : ai_json((string)($vr['content'] ?? ''));
    if (!$vj) $vj = vision_nvidia(PROOFS_SAFETY_PROMPT_NVIDIA, $b64);
    if (!$vj) return 'no_verdict';
    return !empty($vj['unsafe']) ? 'unsafe' : 'safe';
}

/**
 * Move a checked pending pair live: <key>.webp + <key>.json (captured_at kept, w/h measured from the
 * file), old renditions removed, img_renditions() on the live webp. $proofDir is for tests.
 */
function proofs_publish_pending(string $key, ?string $proofDir = null): bool {
    if (!preg_match(PROOFS_KEY_RE, $key)) return false;
    $dir = $proofDir ?? proof_dir();
    $pw = "{$dir}/pending/{$key}.webp";
    $pj = "{$dir}/pending/{$key}.json";
    $meta = json_decode((string)@file_get_contents($pj), true);
    $size = @getimagesize($pw);
    if (!is_array($meta) || !$size) return false;
    $live = [
        'url'         => (string)($meta['url'] ?? ''),
        'platform'    => (string)($meta['platform'] ?? ''),
        'kind'        => (string)($meta['kind'] ?? 'screenshot'),
        'credit'      => (string)($meta['credit'] ?? ''),
        'captured_at' => (string)($meta['captured_at'] ?? date(DATE_ATOM, (int)@filemtime($pw))),
        'w'           => (int)$size[0],
        'h'           => (int)$size[1],
    ];
    $json = json_encode($live, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $tmpJ = "{$dir}/{$key}.part" . getmypid() . 'j';
    if ($json === false || @file_put_contents($tmpJ, $json) !== strlen($json)) { @unlink($tmpJ); return false; }
    foreach ([480, 768] as $tw) @unlink("{$dir}/{$key}-{$tw}.webp");
    if (!@rename($pw, "{$dir}/{$key}.webp")) { @unlink($tmpJ); return false; }
    if (!@rename($tmpJ, "{$dir}/{$key}.json")) { @unlink($tmpJ); return false; }
    @unlink($pj);
    img_renditions("{$dir}/{$key}.webp");
    return true;
}

/**
 * Safety-check and publish up to $max pending captures, oldest first, within $deadlineSec.
 * unsafe -> pending pair deleted, failure recorded with attempts=5 (never retried);
 * unreadable or broken pair -> deleted, failure recorded (normal backoff, recaptured later);
 * no verdict (both vision providers down) -> left pending for the next run, loop stops.
 * Returns ['promoted'=>n,'rejected'=>n,'left'=>n] ('left' = pending files after the run).
 */
function proofs_promote(PDO $pdo, int $max = 6, int $deadlineSec = 90): array {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('proofs_promote runs from the CLI only (no AI inside web requests)');
    require_once __DIR__ . '/ai.php';           // ai_chat, ai_json
    require_once __DIR__ . '/images.php';       // img_renditions
    require_once __DIR__ . '/vision.php';       // vision_nvidia
    require_once __DIR__ . '/image_beast.php';  // beast_b64

    $t0 = time();
    $out = ['promoted' => 0, 'rejected' => 0, 'left' => 0];
    $dir = proof_dir();
    $checked = 0;

    // stale temp files from an interrupted ingest or promotion (older than 1 hour)
    foreach (array_merge(glob("{$dir}/pending/*.part*") ?: [], glob("{$dir}/*.part*") ?: []) as $tf) {
        if ((int)@filemtime($tf) < $t0 - 3600) @unlink($tf);
    }

    foreach (proofs_pending_files() as $pf) {
        if ($checked >= $max || time() - $t0 >= $deadlineSec) break;
        $key = basename($pf, '.webp');
        $pj = "{$dir}/pending/{$key}.json";
        $meta = json_decode((string)@file_get_contents($pj), true);
        $url = is_array($meta) ? trim((string)($meta['url'] ?? '')) : '';
        if ($url === '' || proof_key($url) !== $key) {
            // broken pair: nothing to check and no trustworthy URL to log
            @unlink($pf); @unlink($pj);
            $out['rejected']++;
            echo "  proofs: {$key} dropped (pending metadata missing or not matching)\n";
            continue;
        }
        $checked++;
        $left = $deadlineSec - (time() - $t0);
        $verdict = proofs_safety_verdict($pf, max(15, min(60, $left)));
        if ($verdict === 'no_verdict') {
            echo "  proofs: {$key} waiting (no vision verdict, providers unavailable)\n";
            break;
        }
        if ($verdict === 'unsafe' || $verdict === 'unreadable') {
            @unlink($pf); @unlink($pj);
            try {
                proofs_record_failure($key, $url, $verdict === 'unsafe' ? 'rejected by safety check' : 'pending file unreadable', $verdict === 'unsafe');
            } catch (Throwable $e) { echo "  proofs: {$key} failure log error: " . $e->getMessage() . "\n"; }
            $out['rejected']++;
            echo "  proofs: {$key} REJECTED ({$verdict})\n";
            continue;
        }
        if (proofs_publish_pending($key)) {
            try { proofs_failed_clear($key); } catch (Throwable $e) {}
            $out['promoted']++;
            echo "  proofs: {$key} published\n";
        } else {
            echo "  proofs: {$key} publish failed (left pending)\n";
        }
    }
    $out['left'] = count(proofs_pending_files());
    return $out;
}

/* ------------------------------------------------------------------ stats */

/** ['sources' => capturable cited URLs, 'live', 'pending', 'failed' (still retrying), 'gave_up'] */
function proofs_stats(PDO $pdo): array {
    $sources = 0;
    foreach (proofs_cited_rows($pdo) as $r) if (proofs_capturable((string)$r['url'])) $sources++;
    $dir = proof_dir();
    $live = 0;
    foreach (glob("{$dir}/*.webp") ?: [] as $f) {
        $b = basename($f);
        if (preg_match('/^[0-9a-f]{16}\.webp$/', $b) && is_file("{$dir}/" . substr($b, 0, 16) . '.json')) $live++;
    }
    $failed = 0;
    $gaveUp = 0;
    foreach (proofs_failed_read() as $e) {
        if (!is_array($e)) continue;
        if ((int)($e['attempts'] ?? 0) >= PROOFS_GIVE_UP) $gaveUp++; else $failed++;
    }
    return ['sources' => $sources, 'live' => $live, 'pending' => count(proofs_pending_files()),
            'failed' => $failed, 'gave_up' => $gaveUp];
}

/* ------------------------------------------------------------------ git bus ingest (CLI only) */

/**
 * r155 GIT BUS. The GitHub runner cannot reach the site over HTTP (the host firewall answers its IPs with a
 * 403 page, measured on the first two runs), so it commits its captures to the proof-drop branch and
 * ~/genzhype-proofs-bridge.sh clones that branch into $dir. Each capture is <key>.json =
 * {key, url, platform, kind, credit, file} next to <key>.png|jpg|webp, or {key, url, failed: "1", error}.
 * Every manifest goes through the same proofs_ingest_check() that api/proofingest.php applies, so the branch
 * is no easier a door than the endpoint. Keys that are already live are skipped. Returns counts.
 */
function proofs_ingest_dir(PDO $pdo, string $dir): array {
    $out = ['manifests' => 0, 'stored' => 0, 'failed_recorded' => 0, 'skipped_live' => 0, 'rejected' => 0, 'errors' => []];
    $dir = rtrim($dir, '/');
    $token = (string)($GLOBALS['CONFIG']['ingest_token'] ?? '');
    $note = static function (string $key, string $url, string $why) use (&$out): void {
        try { proofs_record_failure($key, $url, $why); $out['failed_recorded']++; }
        catch (Throwable $e) { $out['errors'][] = "{$key}: " . $e->getMessage(); }
    };
    foreach (glob($dir . '/*.json') ?: [] as $mf) {
        $base = basename($mf, '.json');
        if (!preg_match(PROOFS_KEY_RE, $base)) continue;            // only <key>.json files
        $out['manifests']++;
        if (is_file(proof_dir() . "/{$base}.webp")) { $out['skipped_live']++; continue; }
        $m = json_decode((string)@file_get_contents($mf), true);
        if (!is_array($m)) { $out['rejected']++; $out['errors'][] = "{$base}: unreadable manifest"; continue; }
        $post = ['token' => $token];
        foreach (['key', 'url', 'platform', 'kind', 'credit', 'failed', 'error'] as $f) {
            if (isset($m[$f]) && is_scalar($m[$f])) $post[$f] = (string)$m[$f];
        }
        if (($post['key'] ?? '') !== $base) { $out['rejected']++; $out['errors'][] = "{$base}: key does not match the file name"; continue; }
        $chk = proofs_ingest_check($pdo, $post);
        if (!$chk['ok']) {
            if (isset($chk['key'], $chk['url']) && (int)$chk['code'] < 500) $note($chk['key'], $chk['url'], 'upload rejected: ' . $chk['error']);
            $out['rejected']++; $out['errors'][] = "{$base}: " . $chk['error'];
            continue;
        }
        if ($chk['mode'] === 'failed') { $note($chk['key'], $chk['url'], $chk['error']); continue; }
        $file = (string)($m['file'] ?? '');
        $path = "{$dir}/{$file}";
        if (!preg_match('/^' . preg_quote($base, '/') . '\.(png|jpe?g|webp)$/D', $file) || !is_file($path)) {
            $note($chk['key'], $chk['url'], 'upload rejected: missing file');
            $out['rejected']++; $out['errors'][] = "{$base}: missing file";
            continue;
        }
        if ((int)@filesize($path) > PROOFS_MAX_BYTES) {
            $note($chk['key'], $chk['url'], 'upload rejected: too large');
            $out['rejected']++; $out['errors'][] = "{$base}: too large";
            continue;
        }
        $img = proofs_decode_image((string)@file_get_contents($path));
        if (!$img['ok']) {
            $note($chk['key'], $chk['url'], 'upload rejected: ' . $img['error']);
            $out['rejected']++; $out['errors'][] = "{$base}: " . $img['error'];
            continue;
        }
        $st = proofs_store_pending($img['im'], $chk);
        imagedestroy($img['im']);
        if ($st['ok']) $out['stored']++; else $out['errors'][] = "{$base}: store failed";
    }
    $out['errors'] = array_slice($out['errors'], 0, 20);
    return $out;
}
