<?php
/**
 * TWITCH CLIPS (r85) — the closest machine equivalent to a human editor
 * scrubbing a stream for the moment.
 *
 * THE PROBLEM IT SOLVES. Our clip supply came almost entirely from what
 * reporters chose to embed in their articles, because TikTok, Instagram,
 * Twitch and Kick have no keyless search — we could download any clip whose
 * URL we already held, but we could not go looking. YouTube and archive.org
 * were the only searchable sources.
 *
 * WHY TWITCH IS DIFFERENT. Twitch publishes a free Helix API (app token, no
 * review) with two endpoints that together do the human job:
 *   /helix/search/channels  — name -> the real broadcaster
 *   /helix/clips            — that broadcaster's TOP clips in a date window
 * "Top clips" are the moments that streamer's OWN audience clipped and
 * watched. That is exactly what an editor scrolls a VOD hoping to find, and
 * it arrives ranked, dated and already trimmed.
 *
 * IDENTITY IS FREE HERE. A clip pulled from a broadcaster's own channel has
 * that broadcaster as its author, so the clip-identity gate in
 * video_factory.php matches on the person's name without any new trust rule.
 *
 * FAILS CLOSED. No credentials, no channel match, no clips in the window ->
 * returns [] and the story keeps exactly the supply it already had.
 */
declare(strict_types=1);

const TW_TOKEN_FILE = __DIR__ . '/twitch_token.json';
const TW_API = 'https://api.twitch.tv/helix/';

function tw_creds(): array {
    $c = $GLOBALS['CONFIG']['ai_rotation'] ?? [];
    return [(string)($c['twitch_client_id'] ?? ''), (string)($c['twitch_client_secret'] ?? '')];
}

/** App access token (client-credentials), cached until shortly before expiry. */
function tw_token(): string {
    [$id, $secret] = tw_creds();
    if ($id === '' || $secret === '') return '';
    $cached = json_decode((string)@file_get_contents(TW_TOKEN_FILE), true);
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 300) {
        return (string)$cached['token'];
    }
    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $id, 'client_secret' => $secret,
            'grant_type' => 'client_credentials'])]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($r['access_token'])) return '';
    @file_put_contents(TW_TOKEN_FILE, json_encode([
        'token' => $r['access_token'],
        'expires_at' => time() + (int)($r['expires_in'] ?? 3600)]));
    @chmod(TW_TOKEN_FILE, 0600);
    return (string)$r['access_token'];
}

function tw_get(string $path, array $params): array {
    [$id, ] = tw_creds();
    $tok = tw_token();
    if ($tok === '') return [];
    $ch = curl_init(TW_API . $path . '?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Client-Id: ' . $id, 'Authorization: Bearer ' . $tok]]);
    $d = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return is_array($d) ? $d : [];
}

/**
 * Resolve a person's name to a broadcaster. Deliberately strict: the channel
 * display name must match the person we are looking for, because a loose
 * match here would put a STRANGER'S stream in someone else's story — the
 * wrong-person failure the whole image engine exists to prevent.
 */
function tw_find_broadcaster(string $name): ?array {
    $name = trim($name);
    if (mb_strlen($name) < 3) return null;
    $d = tw_get('search/channels', ['query' => $name, 'first' => 10]);
    $want = preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    foreach (($d['data'] ?? []) as $c) {
        foreach ([(string)($c['display_name'] ?? ''), (string)($c['broadcaster_login'] ?? '')] as $cand) {
            if (preg_replace('/[^a-z0-9]/', '', mb_strtolower($cand)) === $want) {
                return ['id' => (string)$c['id'], 'login' => (string)$c['broadcaster_login'],
                        'name' => (string)$c['display_name']];
            }
        }
    }
    return null;
}

/** Top clips for a broadcaster inside a date window (RFC3339). */
function tw_top_clips(string $broadcasterId, string $fromDate, string $toDate, int $first = 8): array {
    $d = tw_get('clips', [
        'broadcaster_id' => $broadcasterId,
        'started_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($fromDate . ' 00:00:00')),
        'ended_at'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($toDate . ' 23:59:59')),
        'first' => max(1, min(50, $first)),
    ]);
    $out = [];
    foreach (($d['data'] ?? []) as $c) {
        if (empty($c['url'])) continue;
        $out[] = [
            'url' => (string)$c['url'],
            'title' => (string)($c['title'] ?? ''),
            'views' => (int)($c['view_count'] ?? 0),
            'created_at' => (string)($c['created_at'] ?? ''),
            'duration' => (float)($c['duration'] ?? 0),
            'creator' => (string)($c['creator_name'] ?? ''),
        ];
    }
    usort($out, fn($a, $b) => $b['views'] <=> $a['views']);   // audience ranking
    return $out;
}

/**
 * Find and store Twitch clips for a story: for each named person, resolve
 * their channel and take the top clips from the window its timeline covers
 * (padded a couple of days each side, because the clip usually predates the
 * write-up). Appends to footage_clips, deduped by URL. Returns count added.
 */
function tw_clips_for_story(PDO $pdo, int $pageId, int $maxPerPerson = 4): int {
    [$id, $secret] = tw_creds();
    if ($id === '' || $secret === '') {
        error_log('twitch clips: no credentials configured; skipped');
        return 0;
    }
    $row = $pdo->prepare(
        "SELECT v.footage_clips, d.id did, d.people_json,
                MIN(e.event_date) d0, MAX(e.event_date) d1
         FROM video_scripts v JOIN dramas d ON d.page_id = v.page_id
         LEFT JOIN events e ON e.drama_id = d.id AND e.event_date IS NOT NULL
         WHERE v.page_id = ? GROUP BY v.page_id");
    $row->execute([$pageId]);
    $r = $row->fetch();
    if (!$r || empty($r['d0'])) return 0;

    $clips = json_decode((string)($r['footage_clips'] ?: '[]'), true);
    $clips = is_array($clips) ? $clips : [];
    $have = array_column($clips, 'url');
    $from = date('Y-m-d', strtotime((string)$r['d0'] . ' -3 days'));
    $to   = date('Y-m-d', strtotime((string)$r['d1'] . ' +2 days'));

    $added = 0;
    foreach ((array)(json_decode((string)($r['people_json'] ?: '[]'), true) ?: []) as $p) {
        $name = trim((string)(is_array($p) ? ($p['name'] ?? '') : $p));
        if ($name === '') continue;
        $b = tw_find_broadcaster($name);
        if (!$b) { error_log("twitch clips: no channel matching '{$name}'"); continue; }
        $found = tw_top_clips($b['id'], $from, $to, $maxPerPerson * 2);
        $take = 0;
        foreach ($found as $c) {
            if ($take >= $maxPerPerson) break;
            if (in_array($c['url'], $have, true)) continue;
            $clips[] = [
                'platform' => 'twitch',
                'url' => $c['url'],
                'embed' => false,
                'start' => 0,
                'author' => strtolower($b['login']),   // = identity for the gate
                'src' => '',                            // search-found, not embedded
                'note' => sprintf('twitch top clip (%s views): %s',
                                  number_format($c['views']), $c['title']),
            ];
            $have[] = $c['url'];
            $added++; $take++;
        }
        error_log("twitch clips: {$b['name']} -> " . count($found)
                  . " clip(s) in {$from}..{$to}, took {$take}");
    }
    if ($added) {
        $pdo->prepare("UPDATE video_scripts SET footage_clips=? WHERE page_id=?")
            ->execute([json_encode($clips, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $pageId]);
    }
    return $added;
}
