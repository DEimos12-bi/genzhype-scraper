"""CLIP SUPPLY, the site's own routes brought onto this machine (copied from site/app/clip_fetch.php r96-r160,
site/app/clip_supply.php r140-r160 and site/app/reach.php, with their measured numbers; nothing here talks to the server).

What the site measured (clip_supply.php, 2026-09-11, all time): TikTok 56/56, X syndication 9/9, direct files fine,
YouTube 1/5208. So the routes are, in the order they are trusted:
  tiktok   the tikwm resolver gives the no-watermark file address; the TikTok CDN answers a download that carries a
           Referer header (without it: 403); no cookies, no key.
  x        the syndication CDN lists the mp4 variants of any post (material.py reads the same JSON).
  twitch   clips only (clips.twitch.tv/<id>, twitch.tv/<ch>/clip/<id>): yt-dlp, bounded to a 30 s section and 40 MB.
  kick     clips only (kick.com/<ch>/clips/clip_<id>): the same, bounded (an unbounded Kick clip was 94 MB and 5 min).
  youtube  the Innertube player with the ANDROID_VR client, "a route, opportunistic, never relied upon".
  file     a direct .mp4 address.
  reddit   a post through Reddit's own embed page (the JSON API refuses this machine, the embed answers): its packaged
           mp4 with sound, its caption track (one cue per spoken word: what is SAID, with times), or the clip it links to.
THE HUNT (clip_hunt_clips): Exa's keyless search honours "site:<platform> <topic>" (6 of 8 hits were real TikTok videos
for the first story it was measured on). One search per platform this machine can download from; a hit must echo two
distinctive words of the story, or one name of its people; every platform keeps its own finds and they are interleaved,
strongest match first, so no platform is ahead by code order.
THE STORE: every fetched clip is kept in v3/store/clips/<md5 of the url>.mp4 with a .json beside it, so a clip is
fetched once for every video that needs it (the site keeps 14 days; this machine keeps them)."""
import hashlib
import html
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request

import footage

HERE = os.path.dirname(os.path.abspath(__file__))
STORE = os.path.join(HERE, 'store', 'clips')
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
MAX_BYTES = 60000000            # 60 MB ceiling per clip (clip_fetch.php CF_MAX_BYTES)
PAGE_TIMEOUT, VIDEO_TIMEOUT = 30, 120
YTDLP_TIMEOUT, YTDLP_SECONDS, YTDLP_MAXSIZE = 90, 30, '40M'
SHAPES = {                      # the URL shape a real clip has (clip_supply.php clip_hunt_legs / clip_fetch.php cf_ytdlp_target)
    'tiktok': re.compile(r'^https?://(?:www\.)?tiktok\.com/@[^/]+/video/\d+', re.I),
    'twitch': re.compile(r'^https?://(?:(?:www\.)?clips\.twitch\.tv/[A-Za-z0-9_-]{6,}|(?:www\.|m\.)?twitch\.tv/[^/]+/clip/[A-Za-z0-9_-]{6,})', re.I),
    'kick': re.compile(r'^https?://(?:www\.)?kick\.com/[^/]+/clips/clip_[A-Za-z0-9]{6,}', re.I),
}
LAST = {'err': ''}


def platform(url):
    u = url.lower()
    if 'tiktok.com' in u:
        return 'tiktok'
    if re.search(r'//(?:www\.|mobile\.)?(?:x|twitter)\.com/', u):
        return 'x'
    if 'youtube.com' in u or 'youtu.be' in u:
        return 'youtube'
    if 'twitch.tv' in u:
        return 'twitch'
    if 'kick.com' in u:
        return 'kick'
    if re.search(r'//(?:www\.|old\.|new\.)?reddit\.com/r/[^/]+/comments/', u):
        return 'reddit'
    if re.search(r'\.(mp4|m4v|mov|webm)(\?|$)', u):
        return 'file'
    return 'other'


def fetchable(url):
    """Whether this machine has a route for the url: a clip-shaped one for twitch and kick (a channel page is a live
    stream with no end), any post for tiktok and x."""
    p = platform(url.split('#t=')[0])
    if p in ('twitch', 'kick'):
        return bool(SHAPES[p].match(url.split('#t=')[0]))
    return p in ('tiktok', 'x', 'file', 'reddit')


def _get(url, headers=None, timeout=30, data=None):
    req = urllib.request.Request(url, data=data, headers=dict({'User-Agent': UA, 'Accept-Language': 'en-US,en;q=0.9'}, **(headers or {})), method='POST' if data is not None else 'GET')
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.status, dict(r.headers), r.read().decode('utf-8', 'replace')


# ---- resolvers: the address of the media file, per platform (clip_fetch.php cf_resolve_*) ----------------------------
def resolve_tiktok(url):
    """-> (media address, meta) via the tikwm resolver (no-watermark 'play' first), else the post's own page."""
    try:
        code, _, body = _get('https://www.tikwm.com/api/?url=' + urllib.parse.quote(url, safe=''), timeout=30)
        d = (json.loads(body) or {}).get('data') or {} if code == 200 else {}
    except Exception:  # noqa: BLE001
        d = {}
    meta = {'plays': int(d.get('play_count') or 0), 'likes': int(d.get('digg_count') or 0), 'created': int(d.get('create_time') or 0),
            'author': str((d.get('author') or {}).get('unique_id') or ''), 'title': str(d.get('title') or '')[:300], 'duration': int(d.get('duration') or 0)} if d else {}
    for k in ('play', 'hdplay', 'wmplay'):
        v = str(d.get(k) or '')
        if v.startswith('http'):
            return v, meta
    try:                                                       # the post's own page carries the real video address
        code, _, html = _get(url, timeout=PAGE_TIMEOUT)
    except Exception as e:  # noqa: BLE001
        LAST['err'] = 'tiktok page: %s' % str(e)[:60]
        return None, meta
    for key in ('playAddr', 'downloadAddr'):
        m = re.search(r'"' + key + r'":"([^"]{40,})"', html)
        if m:
            v = m.group(1).replace('\\u002F', '/').replace('\\/', '/')
            if v.startswith('http'):
                return v, meta
    LAST['err'] = 'tiktok: no video address in the resolver nor the page'
    return None, meta


def resolve_x(tweet_id):
    """The best mp4 of a post up to 720p, from the syndication CDN (no key, no cookies)."""
    try:
        _, _, body = _get('https://cdn.syndication.twimg.com/tweet-result?id=%s&token=x' % tweet_id, timeout=20)
        j = json.loads(body)
    except Exception as e:  # noqa: BLE001
        LAST['err'] = 'x syndication: %s' % str(e)[:60]
        return None
    best, best_br = None, -1
    for md in j.get('mediaDetails') or []:
        for v in (md.get('video_info') or {}).get('variants') or []:
            if v.get('content_type') != 'video/mp4':
                continue
            m = re.search(r'/(\d+)x(\d+)/', v.get('url', ''))
            if m and int(m.group(2)) > 720:
                continue
            if int(v.get('bitrate') or 0) > best_br:
                best_br, best = int(v.get('bitrate') or 0), v['url']
    return best


def resolve_youtube(vid):
    """The Innertube player with the ANDROID_VR client: a progressive mp4 under 720p when YouTube answers (it answered
    1 of 3 on the site's first probe; opportunistic)."""
    ctx = {'clientName': 'ANDROID_VR', 'clientVersion': '1.62.27', 'deviceModel': 'Quest 3'}
    body = json.dumps({'context': {'client': ctx}, 'videoId': vid, 'contentCheckOk': True, 'racyCheckOk': True}).encode()
    try:
        _, _, text = _get('https://www.youtube.com/youtubei/v1/player?prettyPrint=false', data=body, timeout=20, headers={
            'Content-Type': 'application/json', 'User-Agent': 'com.google.android.apps.youtube.vr.oculus/1.62.27 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip',
            'X-YouTube-Client-Name': '28', 'X-YouTube-Client-Version': '1.62.27'})
        j = json.loads(text)
    except Exception as e:  # noqa: BLE001
        LAST['err'] = 'youtube: %s' % str(e)[:60]
        return None
    st = (j.get('playabilityStatus') or {}).get('status', '')
    if st != 'OK':
        LAST['err'] = 'youtube %s: %s' % (st or 'no answer', str((j.get('playabilityStatus') or {}).get('reason', ''))[:60])
        return None
    best, best_h = None, -1
    for f in (j.get('streamingData') or {}).get('formats') or []:
        h = int(f.get('height') or 0)
        if f.get('url') and h <= 720 and h > best_h:
            best_h, best = h, f['url']
    if not best:
        LAST['err'] = 'youtube: no progressive mp4 under 720p'
    return best


# ---- download, bounded (clip_fetch.php cf_download / cf_ytdlp / cf_trim) ---------------------------------------------
def vtt_words(text):
    """A caption track as words with times: [[word, start, end]]. Reddit marks the spoken word of each cue (<u>word</u>);
    a plain track gives each cue's words an even share of its time."""
    stamp = lambda h, m, s: int(h or 0) * 3600 + int(m) * 60 + float(s)
    out = []
    for h1, m1, s1, h2, m2, s2, body in re.findall(r'(?:(\d+):)?(\d\d):(\d\d\.\d+)\s+-->\s+(?:(\d+):)?(\d\d):(\d\d\.\d+)[^\n]*\n(.*?)(?:\n\s*\n|\Z)', text or '', re.S):
        a, b = stamp(h1, m1, s1), stamp(h2, m2, s2)
        u = re.search(r'<u>(.*?)</u>', body, re.S)
        if u:
            out.append([html.unescape(re.sub(r'<[^>]+>', '', u.group(1))).strip(), round(a, 2), round(b, 2)])
        else:
            ws = html.unescape(re.sub(r'<[^>]+>', ' ', body)).split()
            out += [[w, round(a + (b - a) * i / len(ws), 2), round(a + (b - a) * (i + 1) / len(ws), 2)] for i, w in enumerate(ws)]
    out = [w for w in out if w[0]]
    for i, w in enumerate(out):                                # a marked word lasts until the next one starts
        nxt = out[i + 1][1] if i + 1 < len(out) else w[2]
        w[2] = round(min(max(nxt, w[1] + 0.08), w[1] + 1.2), 2)
    return out


def resolve_reddit(url):
    """A Reddit post, read from Reddit's own embed page: title, subreddit, author, date, comments; its own video (the
    best packaged mp4, sound included) and its caption track, or the address the post links to. None when it does not answer."""
    m = re.search(r'reddit\.com/r/([^/]+)/comments/([a-z0-9]+)(?:/([^/?#]*))?', url, re.I)
    if not m:
        return None
    try:
        _, _, raw = _get('https://embed.reddit.com/r/%s/comments/%s/%s/?embed=true&ref_source=embed&ref=share&showmedia=true&theme=light' % (m.group(1), m.group(2), m.group(3) or '_'), timeout=25)
    except Exception as e:  # noqa: BLE001
        LAST['err'] = 'reddit embed: %s' % str(e)[:80]
        return None
    out = {'id': m.group(2), 'sub': 'r/' + m.group(1), 'title': '', 'author': '', 'date': '', 'comments': 0, 'video': '', 'vtt': '', 'link': '', 'seconds': 0}
    h1 = re.search(r'(?is)<h1[^>]*>(.*?)</h1>', raw)
    out['title'] = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', h1.group(1)))).strip() if h1 else ''
    who = re.search(r'reddit\.com/user/([^/"?&]+)', raw)
    out['author'] = who.group(1) if who else ''
    ts = re.search(r'faceplate-timeago ts="(\d{4}-\d\d-\d\d)', raw)
    out['date'] = ts.group(1) if ts else ''
    nc = re.search(r'View\s+([\d,.]+[kK]?)\s+comments', raw)
    out['comments'] = nc.group(1) if nc else 0
    ctx = re.search(r'data-faceplate-tracking-context="([^"]*&quot;post&quot;[^"]*)"', raw)
    try:
        post = (json.loads(html.unescape(ctx.group(1))) if ctx else {}).get('post') or {}
    except Exception:  # noqa: BLE001
        post = {}
    link = str(post.get('url') or '')
    pm = re.search(r'packaged-media-json="([^"]*)"', raw)
    try:
        mp4s = ((json.loads(html.unescape(pm.group(1))) if pm else {}).get('playbackMp4s') or {})
    except Exception:  # noqa: BLE001
        mp4s = {}
    perms = [p.get('source') or {} for p in mp4s.get('permutations') or []]
    perms = [p for p in perms if p.get('url') and (p.get('dimensions') or {}).get('height', 0) <= 1080]
    if perms:
        out['video'] = max(perms, key=lambda p: (p.get('dimensions') or {}).get('height', 0))['url']
        out['seconds'] = int(mp4s.get('duration') or 0)
        cap = re.search(r'caption-url="([^"]+)"', raw)
        out['vtt'] = html.unescape(cap.group(1)) if cap else ''
    elif link and 'redd.it' not in link and 'reddit.com' not in link:
        out['link'] = link                                     # the post shares a clip that lives elsewhere
    return out if out['title'] else None


def _looks_like_video(path):
    try:
        size = os.path.getsize(path)
        with open(path, 'rb') as f:
            sig = f.read(12)[4:8]
        return 100000 <= size <= MAX_BYTES and sig == b'ftyp'
    except OSError:
        return False


def download(media_url, page_url, dest):
    """The media file, with the page as Referer (the whole trick for TikTok's CDN) and a Range cap."""
    req = urllib.request.Request(media_url, headers={'User-Agent': UA, 'Referer': page_url, 'Accept': '*/*', 'Sec-Fetch-Dest': 'video', 'Sec-Fetch-Mode': 'no-cors', 'Range': 'bytes=0-%d' % MAX_BYTES})
    try:
        with urllib.request.urlopen(req, timeout=VIDEO_TIMEOUT) as r, open(dest, 'wb') as f:
            while True:
                chunk = r.read(1 << 16)
                if not chunk:
                    break
                f.write(chunk)
    except Exception as e:  # noqa: BLE001
        LAST['err'] = 'download: %s' % str(e)[:80]
        if os.path.exists(dest):
            os.remove(dest)
        return False
    if not _looks_like_video(dest):
        LAST['err'] = 'download: %d bytes, not an mp4' % (os.path.getsize(dest) if os.path.exists(dest) else 0)
        os.remove(dest)
        return False
    return True


def ytdlp_clip(url, dest):
    """A Twitch or Kick clip through yt-dlp, bounded five ways: a clip-shaped url only, a 90 s hard stop, a 30 s
    section, a 40 MB ceiling, and the same mp4 check as every download."""
    plat = platform(url)
    if plat not in ('twitch', 'kick') or not SHAPES[plat].match(url):
        LAST['err'] = 'not a clip url'
        return False
    tmp = dest + '.dl.mp4'
    rc, _, err = footage.run(['yt-dlp', '--no-playlist', '--no-warnings', '--no-progress', '--no-part', '--no-cache-dir', '--socket-timeout', '20', '--retries', '2',
                              '--max-filesize', YTDLP_MAXSIZE, '--download-sections', '*0-%d' % YTDLP_SECONDS, '-f', 'best[height<=720]/best', '-o', tmp, '--', url], YTDLP_TIMEOUT)
    if rc == 0 and os.path.isfile(tmp) and _looks_like_video(tmp):
        os.replace(tmp, dest)
        return True
    LAST['err'] = 'yt-dlp: ' + (' '.join(err.split())[-150:] or 'rc=%d' % rc)
    if os.path.exists(tmp):
        os.remove(tmp)
    return False


def trim(dest, start=0, keep=25):
    """Keep the beat, drop the rest: 12 s around a given second, else the first `keep` seconds (stream copy)."""
    frm, length = (max(0, start - 2), 12) if start > 0 else (0, keep)
    tmp = dest + '.trim.mp4'
    rc, _, _ = footage.run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', str(frm), '-i', dest, '-t', str(length), '-c', 'copy', '-movflags', '+faststart', tmp], 60)
    if rc == 0 and os.path.isfile(tmp) and os.path.getsize(tmp) > 100000:
        os.replace(tmp, dest)
    elif os.path.exists(tmp):
        os.remove(tmp)


# ---- the store and the one call that fetches a clip ------------------------------------------------------------------
def store_path(url):
    os.makedirs(STORE, exist_ok=True)
    return os.path.join(STORE, hashlib.md5(url.encode()).hexdigest()[:16] + '.mp4')


def fetch(url, dest, start=0, keep=25, log=print):
    """One clip of any fetchable platform into dest, from the store when it was fetched before. -> meta dict or None.
    The file is trimmed like the site trims (the feed must stay light; a video shows a few seconds of a clip)."""
    LAST['err'] = ''
    url = url.split('#t=')[0]
    kept, meta_file = store_path(url), store_path(url)[:-4] + '.json'
    if os.path.isfile(kept) and _looks_like_video(kept):
        import shutil
        shutil.copy(kept, dest)
        meta = json.load(open(meta_file, encoding='utf-8')) if os.path.isfile(meta_file) else {}
        log('  %s: from the store (fetched %s)' % (platform(url), meta.get('fetched', 'before')))
        return meta
    plat, meta, ok, t0 = platform(url), {}, False, time.time()
    if plat == 'tiktok':
        media, meta = resolve_tiktok(url)
        ok = bool(media) and download(media, url, dest)
    elif plat == 'x':
        m = re.search(r'/status/(\d+)', url)
        media = resolve_x(m.group(1)) if m else None
        ok = bool(media) and download(media, url, dest)
    elif plat in ('twitch', 'kick'):
        ok = ytdlp_clip(url, dest)
    elif plat == 'youtube':
        m = re.search(r'(?:v=|/shorts/|youtu\.be/)([A-Za-z0-9_-]{11})', url)
        media = resolve_youtube(m.group(1)) if m else None
        ok = bool(media) and download(media, url, dest)
    elif plat == 'file':
        ok = download(url, url, dest)
    elif plat == 'reddit':
        r = resolve_reddit(url)
        if r and r.get('video'):
            ok = download(r['video'], url, dest)
            meta = {'title': r['title'], 'author': r['author'], 'sub': r['sub'], 'date': r['date']}
            if ok and r.get('vtt') and start <= 0:             # what is said in it, word by word (the times hold while the clip starts at its first second)
                try:
                    words = vtt_words(_get(r['vtt'], timeout=20)[2])
                    if words:
                        meta['said'] = {'by': 'the captions Reddit made for it', 'lang': 'en', 'words': [w for w in words if w[1] < keep]}
                except Exception:  # noqa: BLE001
                    pass
        elif r and r.get('link') and fetchable(r['link']):
            got = fetch(r['link'], dest, start, keep, log)
            if got is not None:
                got.update(sub=r['sub'], shared_as=r['title'])
            return got
        else:
            LAST['err'] = LAST['err'] or 'the post has no video of its own and links to none this machine can fetch'
    else:
        LAST['err'] = 'no route for this platform'
    if not ok:
        log('  %s: not fetched (%s)' % (plat, LAST['err'][:120]))
        return None
    trim(dest, start, keep)
    w, h, dur = footage.probe(dest)
    if not w or dur < 1:
        LAST['err'] = 'unreadable file'
        os.remove(dest)
        log('  %s: not fetched (unreadable file)' % plat)
        return None
    meta.update(platform=plat, url=url, w=w, h=h, dur=round(dur, 2), fetched=time.strftime('%Y-%m-%d'), seconds=round(time.time() - t0, 1))
    import shutil
    shutil.copy(dest, kept)
    json.dump(meta, open(meta_file, 'w', encoding='utf-8'), ensure_ascii=False)
    log('  %s: fetched %dx%d %.0fs in %.0fs%s' % (plat, w, h, dur, meta['seconds'], (' by @' + meta['author']) if meta.get('author') else ''))
    return meta


# ---- Exa, keyless (reach.php reach_exa_session / reach_exa_search) --------------------------------------------------
_SESSION = {'id': None}


def _exa_headers():
    h = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}
    key = os.environ.get('EXA_API_KEY', '').strip()         # optional: the keyless tier allows about 20 searches an hour
    if key:
        h['Authorization'] = 'Bearer ' + key
    return h


def _exa_session():
    init = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2025-03-26', 'capabilities': {}, 'clientInfo': {'name': 'genzhype-video', 'version': '3'}}}).encode()
    try:
        code, headers, _ = _get('https://mcp.exa.ai/mcp', headers=_exa_headers(), data=init, timeout=25)
    except Exception as e:  # noqa: BLE001
        LAST['err'] = 'exa: %s' % str(e)[:60]
        return None
    sid = next((v for k, v in headers.items() if k.lower() == 'mcp-session-id'), None)
    if code != 200 or not sid:
        LAST['err'] = 'exa: no session (HTTP %s)' % code
        return None
    try:
        _get('https://mcp.exa.ai/mcp', headers=dict(_exa_headers(), **{'mcp-session-id': sid}), data=json.dumps({'jsonrpc': '2.0', 'id': 2, 'method': 'notifications/initialized'}).encode(), timeout=10)
    except Exception:  # noqa: BLE001
        pass
    return sid.strip()


def exa_search(query, n=5):
    """Keyless semantic web search. [] on any failure (the reason in LAST['err']). Each hit: url, title, published, text."""
    if _SESSION['id'] is None:
        _SESSION['id'] = _exa_session() or ''
    if not _SESSION['id']:
        return []
    call = json.dumps({'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'web_search_exa', 'arguments': {'query': query, 'numResults': max(1, min(8, n))}}}).encode()
    try:
        code, _, body = _get('https://mcp.exa.ai/mcp', headers=dict(_exa_headers(), **{'mcp-session-id': _SESSION['id']}), data=call, timeout=40)
    except urllib.error.HTTPError as e:
        _SESSION['id'] = None
        LAST['err'] = 'exa: HTTP %d%s' % (e.code, ' (rate limit)' if e.code == 429 else '')
        return []
    except Exception as e:  # noqa: BLE001
        _SESSION['id'] = None
        LAST['err'] = 'exa: %s' % str(e)[:60]
        return []
    LAST['err'] = ''
    data = None
    for line in body.split('\n'):                              # SSE frames: the LAST data: line holds the answer
        if line.strip().startswith('data:'):
            data = line.strip()[5:].strip()
    try:
        text = ((json.loads(data) if data else {}).get('result') or {}).get('content', [{}])[0].get('text', '')
    except Exception:  # noqa: BLE001
        text = ''
    out = []
    for blk in re.split(r'\n(?=Title:\s)', text or ''):       # plain-text blocks: Title / URL / Published / Highlights per hit
        t, u = re.search(r'^Title:\s*(.+)$', blk, re.M), re.search(r'^URL:\s*(\S+)', blk, re.M)
        if not (t and u):
            continue
        p, hl = re.search(r'^Published:\s*(\d{4}-\d{2}-\d{2})', blk, re.M), re.search(r'Highlights:\s*(.+)$', blk, re.S)
        out.append({'url': u.group(1).strip(), 'title': t.group(1).strip(), 'published': p.group(1) if p else '', 'text': (hl.group(1).strip() if hl else '')[:2000]})
        if len(out) >= n:
            break
    return out


# ---- the hunt (clip_supply.php clip_hunt_legs / clip_hunt_clips) ----------------------------------------------------
def hunt_legs(q):
    return {'tiktok': ('site:tiktok.com ' + q, SHAPES['tiktok']), 'twitch': ('site:clips.twitch.tv ' + q, SHAPES['twitch']), 'kick': ('site:kick.com clips ' + q, SHAPES['kick'])}


def hunt(topic, people=(), have=(), maximum=4, max_seconds=90, log=print):
    """Clips of a topic on the platforms this machine can download from. `topic`: the story's title or a hunt's search
    words; `people`: names the story is about. -> [{platform, url, title, published, author, hits}], best match first."""
    title = re.sub(r'^(what does|the)\s+|[\'"‘’“”?]|\s+(mean|explained)(\s+in\s+\w+)?$', ' ', topic, flags=re.I)
    q = ' '.join((' '.join(list(people)[:2]) + ' ' + re.sub(r'\s+', ' ', title)).split())
    if not q:
        return []
    need, names = {}, {}
    for w in re.split(r'[^\w]+', q.lower()):                   # distinctive words the hit must echo (people names count double)
        if len(w) >= 5:
            need[w] = 1
    for p in people:
        for w in re.split(r'\s+', p.lower()):
            if len(w) >= 3:
                need[w], names[w] = 1, 1
    seen, per_leg, notes, t0 = set(have), {}, [], time.time()
    for plat, (query, shape) in hunt_legs(q).items():
        if time.time() - t0 > max_seconds:
            notes.append(plat + '=skipped(time)')
            continue
        hits, kept = exa_search(query, 8), []
        for h in hits:
            if len(kept) >= maximum:
                break
            m = shape.match(h['url'])
            if not m or m.group(0) in seen:
                continue
            hay = (h['title'] + ' ' + h['text']).lower()
            hit = sum(1 for w in need if w in hay)
            nm = sum(1 for w in names if w in hay)
            if hit < 2 and nm < 1:                             # two distinctive words is the floor; a name opens the door by itself
                continue
            seen.add(m.group(0))
            author = re.search(r'tiktok\.com/@([^/]+)/', m.group(0)) or re.search(r'(?:twitch\.tv|kick\.com)/([^/]+)/', m.group(0))
            kept.append({'platform': plat, 'url': m.group(0), 'title': h['title'][:120], 'published': h['published'], 'author': author.group(1) if author else '', 'hits': hit + 2 * nm, 'src': 'hunt:exa'})
        if kept:
            per_leg[plat] = kept
        notes.append('%s=%d/%d%s' % (plat, len(kept), len(hits), (' ' + LAST['err']) if not hits and LAST['err'] else ''))
    by_hits = {}                                               # rank before the cap, then one per platform in turn
    for plat, kept in per_leg.items():
        for k in kept:
            by_hits.setdefault(k['hits'], {}).setdefault(plat, []).append(k)
    found = []
    for _, group in sorted(by_hits.items(), reverse=True):
        i = 0
        while len(found) < maximum:
            took = False
            for kept in group.values():
                if i < len(kept):
                    found.append(kept[i]); took = True
                    if len(found) >= maximum:
                        break
            if not took:
                break
            i += 1
        if len(found) >= maximum:
            break
    log('  hunt "%s": legs [%s], kept %d' % (q[:60], ' '.join(notes), len(found)))
    return found


if __name__ == '__main__':
    import sys
    sys.stdout.reconfigure(encoding='utf-8')
    if len(sys.argv) > 2 and sys.argv[1] == 'hunt':
        for f in hunt(' '.join(sys.argv[2:])):
            print(f)
    elif len(sys.argv) > 2:
        print(fetch(sys.argv[1], sys.argv[2]))
    else:
        print('usage: supply.py <clip url> <dest.mp4> | supply.py hunt <topic words>')
