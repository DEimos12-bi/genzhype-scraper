<?php
/* GenZHype | PROOF SCREENSHOTS (r155, owner 2026-09-11: "the proofs, the YouTube and Twitter,
   Reddit, Instagram and all the posts that we use as proofs" must show on the pages).
   One proof image per source URL, keyed by proof_key(url). The GitHub runner (proofs-worker)
   captures it and uploads it to api/proofingest.php (assets/proofs/pending/); the hourly run
   safety-checks and promotes it (proofs_engine.php); the story template shows it under the
   timeline event that cites the source.
   Live files: public_html/assets/proofs/<key>.webp (+ img_renditions) and <key>.json. */

function proof_key(string $url): string {
    return substr(sha1(trim($url)), 0, 16);
}

/** news | x | youtube | reddit | tiktok | instagram, or '' when the URL has no host. */
function proof_platform(string $url): string {
    $h = strtolower((string)parse_url(trim($url), PHP_URL_HOST));
    $h = (string)preg_replace('/^(www\.|m\.|mobile\.|old\.|new\.|vm\.)/', '', $h);
    if ($h === '') return '';
    if ($h === 'x.com' || $h === 'twitter.com') return 'x';
    if ($h === 'youtube.com' || $h === 'youtu.be' || str_ends_with($h, '.youtube.com')) return 'youtube';
    if ($h === 'reddit.com' || str_ends_with($h, '.reddit.com')) return 'reddit';
    if ($h === 'tiktok.com' || str_ends_with($h, '.tiktok.com')) return 'tiktok';
    if ($h === 'instagram.com' || str_ends_with($h, '.instagram.com')) return 'instagram';
    return 'news';
}

function proof_dir(): string {
    return dirname(__DIR__) . '/public_html/assets/proofs';
}

/** The live proof for one URL, or null.
    ['img', 'w', 'h', 'credit', 'url', 'platform', 'kind', 'captured'] */
function proof_for_url(?string $url): ?array {
    if (!$url || !preg_match('#^https?://#i', trim($url))) return null;
    $k = proof_key($url);
    $dir = proof_dir();
    if (!is_file("{$dir}/{$k}.webp") || !is_file("{$dir}/{$k}.json")) return null;
    $m = json_decode((string)@file_get_contents("{$dir}/{$k}.json"), true);
    if (!is_array($m) || empty($m['w']) || empty($m['h'])) return null;
    return [
        'img'      => "/assets/proofs/{$k}.webp",
        'w'        => (int)$m['w'],
        'h'        => (int)$m['h'],
        'credit'   => (string)($m['credit'] ?? ''),
        'url'      => (string)($m['url'] ?? trim($url)),
        'platform' => (string)($m['platform'] ?? proof_platform($url)),
        'kind'     => (string)($m['kind'] ?? 'screenshot'),
        'captured' => (string)($m['captured_at'] ?? ''),
    ];
}
