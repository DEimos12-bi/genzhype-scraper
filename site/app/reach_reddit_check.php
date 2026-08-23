<?php
// REACH | Reddit cookie'd read — the production path for discovery
// (dt_reddit_questions). Reads a PUBLIC subreddit listing with the owner's
// burner session, which is the exact and only thing the pipeline will do.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$c = require __DIR__ . '/config.php';
$cookie = (string)($c['social_tokens']['reddit_cookie'] ?? '');
if ($cookie === '') { echo "no reddit_cookie in config\n"; exit(1); }
$ch = curl_init('https://www.reddit.com/r/OutOfTheLoop/hot.json?limit=3');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
    CURLOPT_COOKIE => $cookie,
]);
$b = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = json_decode((string)$b, true);
$n = 0;
foreach (($j['data']['children'] ?? []) as $p) { echo '   - ', mb_substr($p['data']['title'] ?? '', 0, 70), "\n"; $n++; }
echo "REDDIT cookie'd read: http=$code posts=$n\n";
