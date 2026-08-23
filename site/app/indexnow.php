<?php
// GenZHype | IndexNow ping (Bing/Copilot + partners; Google does NOT use IndexNow).
function indexnow_ping(array $urls): string {
    global $CONFIG;
    $key = $CONFIG['indexnow_key'] ?? '';
    if (!$key || !$urls) return 'skipped (no key or no urls)';
    $host = parse_url($CONFIG['base_url'], PHP_URL_HOST);
    $payload = json_encode([
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => rtrim($CONFIG['base_url'], '/') . '/' . $key . '.txt',
        'urlList'     => array_values($urls),
    ]);
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return "IndexNow HTTP {$code} for " . count($urls) . ' url(s)';
}
