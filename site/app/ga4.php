<?php
/* GenZHype | GA4 Data API, reusable (2026-09-06), and the Search Console URL Inspection (gsc_inspect).
 * The Search Console/Analytics consent lives in gsc_token.json (refresh token
 * obtained through yt_oauth.php?gsc=1 — the ONLY redirect Google authorizes,
 * r130b). Property id is pinned in ga4_property.txt (541286062 = GenZHype;
 * the token also sees mbjagency's property, listed FIRST — never take the first). */

function ga4_cfg_find(array $a, string $k) {
    foreach ($a as $kk => $v) {
        if ($kk === $k) return $v;
        if (is_array($v)) { $r = ga4_cfg_find($v, $k); if ($r !== null) return $r; }
    }
    return null;
}

/** Short-lived access token from the stored refresh token. null on failure. */
function ga4_access_token(): ?string {
    static $tok = null, $until = 0;
    if ($tok && time() < $until) return $tok;
    $f = __DIR__ . '/gsc_token.json';
    if (!is_file($f)) return null;
    $t = json_decode((string)file_get_contents($f), true) ?: [];
    if (empty($t['refresh_token']) || !str_contains((string)($t['scope'] ?? ''), 'analytics')) return null;
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => $t['refresh_token'],
            'client_id'     => (string)ga4_cfg_find($cfg, 'yt_client_id'),
            'client_secret' => (string)ga4_cfg_find($cfg, 'yt_client_secret'),
            'grant_type'    => 'refresh_token'])]);
    $j = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    if (empty($j['access_token'])) return null;
    $tok = $j['access_token']; $until = time() + (int)($j['expires_in'] ?? 3000) - 60;
    return $tok;
}

function ga4_property(): string {
    $p = trim((string)@file_get_contents(__DIR__ . '/ga4_property.txt'));
    return $p !== '' ? $p : 'properties/541286062';
}

/** runReport. Returns ['rows'=>[[dims...],[metrics...]], 'error'=>?]. Never throws. */
function ga4_report(array $body, int $timeout = 40): array {
    $at = ga4_access_token();
    if (!$at) return ['rows' => [], 'error' => 'no analytics token'];
    $ch = curl_init('https://analyticsdata.googleapis.com/v1beta/' . ga4_property() . ':runReport');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $at", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $j = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    if (isset($j['error'])) return ['rows' => [], 'error' => (string)($j['error']['message'] ?? 'ga4 error')];
    $rows = [];
    foreach ($j['rows'] ?? [] as $r) {
        $rows[] = [array_map(fn($x) => $x['value'], $r['dimensionValues'] ?? []),
                   array_map(fn($x) => $x['value'], $r['metricValues'] ?? [])];
    }
    return ['rows' => $rows, 'error' => null];
}

/**
 * Search Console's current word on one of our URLs (URL Inspection API, the same consent,
 * webmasters.readonly; 2,000 a day for the property), stored in gsc_inspection.
 * The stored fields, or null when Google did not answer.
 */
function gsc_inspect(PDO $pdo, int $pageId, string $url): ?array {
    $at = ga4_access_token();
    if (!$at) return null;
    $ch = curl_init('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $at", 'Content-Type: application/json'],
        // the URL-prefix property: the token is refused on sc-domain:genzhype.com
        CURLOPT_POSTFIELDS => json_encode(['inspectionUrl' => $url, 'siteUrl' => 'https://genzhype.com/'])]);
    $j = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    $s = $j['inspectionResult']['indexStatusResult'] ?? null;
    if (!is_array($s) || empty($s['coverageState'])) return null;
    $row = ['coverage' => (string)$s['coverageState'], 'verdict' => (string)($s['verdict'] ?? ''), 'last_crawl' => (string)($s['lastCrawlTime'] ?? ''),
            'google_canonical' => (string)($s['googleCanonical'] ?? ''), 'robots_state' => (string)($s['robotsTxtState'] ?? '')];
    $pdo->prepare("REPLACE INTO gsc_inspection (page_id, url, coverage, verdict, last_crawl, google_canonical, robots_state, checked_at)
                   VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())")
        ->execute([$pageId, $url, $row['coverage'], $row['verdict'], $row['last_crawl'], $row['google_canonical'], $row['robots_state']]);
    return $row;
}
