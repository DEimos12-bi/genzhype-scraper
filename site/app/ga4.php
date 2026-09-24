<?php
/* GenZHype | GA4 Data API, reusable (2026-09-06).
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
