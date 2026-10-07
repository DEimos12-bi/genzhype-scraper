#!/usr/bin/env python3
"""v2 fetch.py <work>: the clips the server cannot fetch, pulled on the runner with yt-dlp.
   - assets of kind 'clip' with platform 'youtube' (a YouTube link the page cites)
   - assets of kind 'hunt' (the director asked for footage by search words: a trailer, gameplay, an event)
   Cookies: $YT_COOKIES_FILE (the workflow writes the YT_COOKIES secret there), as the old maker does; without
   them YouTube's bot wall usually refuses cloud IPs. At most 12 attempts a run (account safety, old rule kept).
   A clip that cannot be fetched is marked missing and its line falls back to a paper card in shots.py."""
import json, os, re, subprocess, sys

CAP = 12
STOP = {'the', 'and', 'with', 'from', 'that', 'this', 'what', 'for', 'into', 'official', 'trailer', 'gameplay', 'video', 'clip', 'full', 'new', 'reveal', 'season', 'patch', 'update'}


def say(*a):
    print(*a, flush=True)


def run(cmd, timeout=240):
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return p.returncode, (p.stdout or ''), (p.stderr or '')[-400:]
    except subprocess.TimeoutExpired:
        return 124, '', 'timeout'


def main():
    work = sys.argv[1]
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    ck = os.environ.get('YT_COOKIES_FILE', '')
    ck = ck if ck and os.path.isfile(ck) and os.path.getsize(ck) > 100 else None
    say('cookies:', 'active' if ck else 'none (YouTube will mostly refuse)')
    imp = 'chrome' in (run(['yt-dlp', '--list-impersonate-targets'], 60)[1] or '').lower()

    def base():
        c = ['yt-dlp', '-q', '--no-warnings', '--no-playlist', '--socket-timeout', '30', '--retries', '2']
        if ck: c += ['--cookies', ck]
        if imp: c += ['--impersonate', 'chrome']
        return c

    tries = 0
    def search(query):
        nonlocal tries
        tries += 1
        rc, out, err = run(base() + ['--flat-playlist', '--print', '%(id)s\t%(duration)s\t%(title)s\t%(channel)s\t%(view_count)s\t%(live_status)s', 'ytsearch8:' + query], 120)
        keys = [w for w in re.findall(r'[a-z0-9]{4,}', query.lower()) if w not in STOP]
        best = None
        for ln in out.splitlines():
            p = ln.split('\t')
            if len(p) < 6: continue
            vid, dur, title, channel, views, live = p[:6]
            try: dur = float(dur); views = float(views or 0)
            except ValueError: dur = 0; views = 0
            if live not in ('', 'None', 'not_live', 'was_live') or not (20 <= dur <= 900): continue
            if keys and not any(k in title.lower() for k in keys): continue   # the clip's title must name the thing (r172 rule)
            if best is None or views > best[4]: best = (vid, dur, title, channel, views)
        if best is None: say('  search found nothing usable for', repr(query), '|', (err or out)[:160].replace('\n', ' '))
        return best

    def download(url, dest, start, seconds):
        nonlocal tries
        tries += 1
        rc, out, err = run(base() + ['-f', 'bv*[height<=720][ext=mp4]+ba[ext=m4a]/b[height<=720]/bv*+ba/b', '--merge-output-format', 'mp4',
                                     '--download-sections', '*%d-%d' % (start, start + seconds), '--force-keyframes-at-cuts', '-o', dest, url], 300)
        ok = rc == 0 and os.path.isfile(dest) and os.path.getsize(dest) > 50000
        if not ok: say('  download failed:', (err or out)[-200:].replace('\n', ' '))
        return ok

    for aid, a in plan.get('assets', {}).items():
        kind = a.get('kind'); plat = a.get('platform', '')
        if a.get('file') or not (kind == 'hunt' or (kind == 'clip' and plat == 'youtube')): continue
        if tries >= CAP: a['missing'] = 'fetch cap reached'; say(aid, 'skipped: cap'); continue
        dest = os.path.join(adir, aid + '.mp4')
        if kind == 'hunt':
            q = (a.get('query') or '').strip()
            say(aid, 'hunt:', repr(q))
            best = search(q) if q else None
            if not best: a['missing'] = 'no YouTube result for the search'; continue
            vid, dur, title, channel, views = best
            start = int(a.get('in') or min(8, dur * 0.1))
            if download('https://www.youtube.com/watch?v=' + vid, dest, start, 24):
                a.update({'file': aid + '.mp4', 'credit': 'CLIP · ' + (channel or 'YOUTUBE').upper()[:28] + ' / YOUTUBE', 'title': title, 'url': 'https://www.youtube.com/watch?v=' + vid, 'seconds': dur})
                say(aid, 'got', repr(title[:70]), 'by', channel, '%.0fs' % dur)
            else:
                a['missing'] = 'download refused'
        else:
            say(aid, 'youtube link:', a.get('url'))
            if download(a['url'], dest, int(a.get('in') or 0), 30):
                a['file'] = aid + '.mp4'; say(aid, 'got')
            else:
                a['missing'] = 'download refused'
    # a line whose clip is missing shows a paper card instead (never a blank, never a wrong clip)
    for ln in plan.get('lines', []):
        v = ln.get('visual') or {}
        a = plan['assets'].get(v.get('asset', ''), {})
        if a and a.get('kind') in ('clip', 'hunt') and not a.get('file'):
            say('line', ln.get('id'), 'falls back to a card (', v.get('asset'), a.get('missing', ''), ')')
            ln['visual'] = {'asset': 'card'}
    json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    say('fetch done: %d yt-dlp calls' % tries)


if __name__ == '__main__':
    main()
