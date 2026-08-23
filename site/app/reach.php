<?php
// GenZHype | REACH LAYER (from the agent-reach study, 2026-08-05).
// The two channels usable from shared PHP hosting, as plain functions:
//   reach_exa_search()  — keyless semantic web search via Exa's free MCP
//                         endpoint (LIVE-VERIFIED from this server: returned
//                         Ars Technica w/ title+URL+published date+quotes).
//   reach_jina_read()   — keyless readable-markdown fetch of any URL via
//                         r.jina.ai (LIVE-VERIFIED: read Cambridge delulu).
// Plus reach_doctor(): the agent-reach "doctor" pattern applied to OUR OWN
// fetch chains, so a platform wall shows up in a report instead of being
// discovered through a broken render.  php app/reach.php doctor
// RULE: these are BEST-EFFORT ADDITIONS to existing chains, never a hard
// dependency — free tiers are unguaranteed; every caller must survive [].

function reach_http(string $url, array $headers = [], ?string $body = null, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/130.0',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen)];
}

/** Exa MCP session: initialize -> session id header -> initialized notice. */
function reach_exa_session(): ?string {
    $init = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass(),
                     'clientInfo' => ['name' => 'genzhype-reach', 'version' => '1.0']]]);
    $h = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    $r = reach_http('https://mcp.exa.ai/mcp', $h, $init);
    if ($r['code'] !== 200 || !preg_match('/^mcp-session-id:\s*(\S+)/mi', $r['headers'], $m)) return null;
    $sid = trim($m[1]);
    reach_http('https://mcp.exa.ai/mcp', array_merge($h, ['mcp-session-id: ' . $sid]),
        json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'notifications/initialized']), 10);
    return $sid;
}

/**
 * Keyless semantic web search. Returns [] on ANY failure (never throws).
 * Each hit: ['url','title','published'(Y-m-d or ''),'text'(highlights)].
 */
function reach_exa_search(string $query, int $n = 5): array {
    static $sid = null;
    if ($sid === null) $sid = reach_exa_session() ?: '';
    if ($sid === '') return [];
    $call = json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => ['name' => 'web_search_exa',
                     'arguments' => ['query' => $query, 'numResults' => max(1, min(8, $n))]]]);
    $r = reach_http('https://mcp.exa.ai/mcp',
        ['Content-Type: application/json', 'Accept: application/json, text/event-stream',
         'mcp-session-id: ' . $sid], $call, 40);
    if ($r['code'] !== 200) { $sid = null; return []; }
    // SSE frame(s): take the LAST data: line's JSON
    $json = null;
    foreach (explode("\n", $r['body']) as $line) {
        if (str_starts_with(trim($line), 'data:')) $json = trim(substr(trim($line), 5));
    }
    $j = $json ? json_decode($json, true) : null;
    $text = $j['result']['content'][0]['text'] ?? '';
    if (!is_string($text) || $text === '') return [];
    // Exa returns plain-text blocks: Title/URL/Published/Highlights per hit
    $out = [];
    foreach (preg_split('/\n(?=Title:\s)/', $text) as $blk) {
        if (!preg_match('/^Title:\s*(.+)$/m', $blk, $t)) continue;
        if (!preg_match('/^URL:\s*(\S+)/m', $blk, $u)) continue;
        $pub = '';
        if (preg_match('/^Published:\s*(\d{4}-\d{2}-\d{2})/m', $blk, $p)) $pub = $p[1];
        $hl = '';
        if (preg_match('/Highlights:\s*(.+)$/s', $blk, $hm)) $hl = trim($hm[1]);
        $out[] = ['url' => trim($u[1]), 'title' => trim($t[1]),
                  'published' => $pub, 'text' => mb_substr($hl, 0, 2000)];
        if (count($out) >= $n) break;
    }
    return $out;
}

/** Keyless readable fetch of any URL as markdown. null on failure. */
function reach_jina_read(string $url, int $timeout = 30): ?string {
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $r = reach_http('https://r.jina.ai/' . $url, ['Accept: text/plain'], null, $timeout);
    if ($r['code'] !== 200 || mb_strlen($r['body']) < 200) return null;
    if (stripos($r['body'], 'Target URL returned error') !== false) return null;
    return $r['body'];
}

/** Doctor for OUR fetch chains. Returns [name => [status, detail]]. */
function reach_doctor(): array {
    $out = [];
    $t0 = microtime(true);
    $exa = reach_exa_search('twitch emote news', 1);
    $out['exa_search']  = [$exa ? 'ok' : 'DOWN', $exa ? (count($exa) . ' hit(s), ' . round(microtime(true) - $t0, 1) . 's') : 'no results/handshake failed'];
    // probe a page with real content: example.com renders under the 200-byte
    // floor and false-alarmed DOWN on the doctor's first ever run
    $j = reach_jina_read('https://www.wikipedia.org');
    $out['jina_reader'] = [$j ? 'ok' : 'DOWN', $j ? strlen($j) . ' bytes' : 'unreachable'];
    $b = reach_http('https://www.bing.com/search?q=test&format=rss&mkt=en-US');
    $out['bing_rss']    = [(substr_count($b['body'], '<item>') > 3) ? 'ok' : 'DECOY/DOWN', substr_count($b['body'], '<item>') . ' items'];
    $d = reach_http('https://html.duckduckgo.com/html/?q=test');
    $out['duckduckgo']  = [(strpos($d['body'], 'result__a') !== false) ? 'ok' : 'BOT-WALLED', 'http ' . $d['code']];
    $w = reach_http('https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/PogChamp/daily/20260701/20260710');
    $out['wikimedia']   = [($w['code'] === 200) ? 'ok' : ($w['code'] === 429 ? 'RATE-LIMITED' : 'http ' . $w['code']), 'http ' . $w['code']];
    $r = reach_http('https://www.reddit.com/r/OutOfTheLoop/hot.json?limit=1');
    $out['reddit_anon'] = [($r['code'] === 200) ? 'ok' : 'BLOCKED', 'http ' . $r['code'] . ' (agent-reach: no free path exists; needs rdt-cli+cookies)'];
    $s = reach_http('https://cdn.syndication.twimg.com/tweet-result?id=20&token=x');
    $out['x_syndication'] = [(strpos($s['body'], '__typename') !== false) ? 'ok' : 'DOWN', 'http ' . $s['code']];
    return $out;
}

// CLI: php app/reach.php doctor | php app/reach.php search "query"
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $cmd = $argv[1] ?? 'doctor';
    if ($cmd === 'doctor') {
        foreach (reach_doctor() as $name => [$st, $detail])
            printf("  %-14s %-12s %s\n", $name, $st, $detail);
    } elseif ($cmd === 'search') {
        foreach (reach_exa_search($argv[2] ?? 'test', (int)($argv[3] ?? 5)) as $h)
            printf("  %s | %s\n    %s\n", $h['published'] ?: '(undated)', $h['title'], $h['url']);
    }
}
