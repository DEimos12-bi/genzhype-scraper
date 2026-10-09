"""FOOTAGE SUPPLY: everything the plan wants on screen is fetched into <work>/assets, and nothing else.
  1. the videos and pictures of the story's own posts (X's media servers: these answer from anywhere);
  2. the plan's hunts: a YouTube search, the best match by title, one section of it (works on a home connection; a
     machine YouTube refuses gets nothing and the hunt is marked missing: no cookies are used to get around that);
       a hunt that names a video game takes that game's official trailer from its Steam store page first;
  3. if that leaves too little, neutral stock footage (Pexels, the site's own key), only ever used blurred behind cards.
The step can be run again: what is already there is kept. usage: footage.py <work folder> [seconds it may spend]"""
import json
import os
import re
import signal
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request

import ai

CFG = ai.CONFIG['footage']
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'
STOP = {'the', 'and', 'with', 'from', 'that', 'this', 'what', 'for', 'into', 'official', 'trailer', 'gameplay', 'video', 'clip', 'full', 'new', 'reveal', 'season', 'patch', 'update', 'game', 'games'}
OFF = ('reaction', 'reacts', 'podcast', 'tier list', 'review', 'explained', 'drama', 'news', 'rant', 'tutorial', 'guide', 'how to', 'ranking', 'iceberg', 'interview', '#shorts', 'live stream')


def run(cmd, timeout):
    """Runs a tool with a real time limit: at the limit the tool AND what it started (yt-dlp starts ffmpeg) are stopped.
    Output goes to temporary files, not pipes: a child that outlives its parent cannot hold this process up."""
    with tempfile.TemporaryFile() as fo, tempfile.TemporaryFile() as fe:
        try:
            p = subprocess.Popen(cmd, stdout=fo, stderr=fe, stdin=subprocess.DEVNULL, **({'creationflags': subprocess.CREATE_NEW_PROCESS_GROUP} if os.name == 'nt' else {'start_new_session': True}))
        except FileNotFoundError:
            return 127, '', cmd[0] + ' is not installed'
        try:
            rc = p.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            if os.name == 'nt':
                subprocess.run(['taskkill', '/F', '/T', '/PID', str(p.pid)], capture_output=True)
            else:
                try:
                    os.killpg(p.pid, signal.SIGKILL)
                except OSError:
                    p.kill()
            p.wait()
            return 124, '', 'timeout after %ds' % timeout
        fo.seek(0); fe.seek(0)
        return rc, fo.read().decode('utf-8', 'replace'), fe.read().decode('utf-8', 'replace')[-300:]


def probe(path):
    rc, out, _ = run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', path], 30)
    try:
        j = json.loads(out)
        st = j['streams'][0]
        return int(st['width']), int(st['height']), float(j['format'].get('duration') or 0)
    except Exception:  # noqa: BLE001
        return 0, 0, 0.0


def save(url, dest, headers=None, timeout=90):
    req = urllib.request.Request(url, headers=dict({'User-Agent': UA}, **(headers or {})))
    with urllib.request.urlopen(req, timeout=timeout) as r, open(dest, 'wb') as f:
        while True:
            chunk = r.read(1 << 16)
            if not chunk:
                break
            f.write(chunk)
    return os.path.getsize(dest)


def yt_search(query, log):
    rc, out, err = run(['yt-dlp', '-q', '--no-warnings', '--flat-playlist', '--print', '%(id)s\t%(duration)s\t%(title)s\t%(channel)s\t%(view_count)s\t%(live_status)s', 'ytsearch15:' + query], 45)
    keys = [w for w in re.findall(r'[a-z0-9]{3,}', query.lower()) if w not in STOP]
    found = []
    for ln in out.splitlines():
        p = ln.split('\t')
        if len(p) < 6:
            continue
        try:
            dur, views = float(p[1]), float(p[4] or 0)
        except ValueError:
            continue
        title = p[2].lower()
        if p[5] in ('is_live', 'is_upcoming', 'post_live') or not 45 <= dur <= 10800:     # long enough to offer several moments
            continue
        hit = sum(k in title for k in keys)
        if keys and hit < 1:                                     # the title must name the thing
            continue
        score = hit * 10 + (8 if hit >= (len(keys) + 1) // 2 else 0) - 6 * sum(o in title for o in OFF) + min(4, views / 250000) + (7 if 60 <= dur <= 600 else -8 if dur > 1800 else 0)      # short videos come whole and fast; a long broadcast is slow to cut into
        found.append((score, p[0], dur, p[2], p[3]))
    if not found:
        log('  search "%s": nothing usable (%s)' % (query, (err or 'no match by title')[:90].replace('\n', ' ')))
    return [f[1:] for f in sorted(found, reverse=True)[:3]]


def yt_section(vid, dest, start, seconds, dur, log, limit=170):
    """One stretch of a YouTube video as an mp4 without sound."""
    url, tmp = 'https://www.youtube.com/watch?v=' + vid, dest + '.whole.mp4'
    common = ['yt-dlp', '-q', '--no-warnings', '--no-playlist', '--socket-timeout', '20', '--retries', '1']
    if dur <= 600:                                             # a short video: the whole file (fast), trimmed here
        top = CFG['max_height'] if dur <= 330 else 720
        rc, out, err = run(common + ['-N', '4', '--max-filesize', '450M', '-f', 'bv*[height<=%d][ext=mp4]/b[height<=%d][ext=mp4]/b[height<=%d]' % (top, top, top), '-o', tmp, url], int(limit))
        if rc == 0 and os.path.isfile(tmp):
            run(['ffmpeg', '-y', '-v', 'error', '-ss', str(start), '-i', tmp, '-t', str(seconds), '-an', '-c:v', 'copy', dest], 60)
    else:                                                      # a long one (a full match, a stream): one minute of it, 720p
        seconds = min(seconds, 40)                              # YouTube serves sections at about playing speed: kept short
        rc, out, err = run(common + ['-f', 'bv*[height<=720][height>=480][ext=mp4][fps<=30]/bv*[height<=720][height>=480][ext=mp4]', '--download-sections', '*%d-%d' % (start, start + seconds), '-o', dest, url], int(limit))
    for f in (tmp, tmp + '.part', dest + '.part'):
        if os.path.exists(f):
            os.remove(f)
    if os.path.isfile(dest) and os.path.getsize(dest) > 80000 and min(probe(dest)[:2]) >= 480:      # too small a picture is no footage
        return True
    if os.path.exists(dest):
        os.remove(dest)
    log('  download refused or failed: %s' % (' '.join((err or out)[-150:].split()) or 'exit %d' % rc))
    return False


def steam_trailer(game, dest, seconds, log):
    """An official trailer of a video game from Steam's public store data: no login, no robot check, and it answers
    GitHub's machines too. Only the store entry whose name is exactly the game's name is used."""
    def get(u):
        return json.loads(urllib.request.urlopen(urllib.request.Request(u, headers={'User-Agent': UA}), timeout=20).read().decode('utf-8', 'replace'))
    want = set(re.findall(r'[a-z0-9]+', game.lower())) - {'the', 'of', 'and', 'a'}
    try:
        items = get('https://store.steampowered.com/api/storesearch/?l=english&cc=US&term=' + urllib.parse.quote(game)).get('items', [])
    except Exception as e:  # noqa: BLE001
        log('  steam: no answer (%s)' % str(e)[:70]); return None
    for it in items[:6]:
        words = set(re.findall(r'[a-z0-9]+', it.get('name', '').lower()))
        if not want or words - {'the', 'of', 'and', 'a'} != want:      # the same name, not a sequel, a spin-off or an add-on ("Minecraft" is not "Minecraft Dungeons II")
            continue
        try:
            movies = ((get('https://store.steampowered.com/api/appdetails?l=english&cc=US&appids=%d' % it['id']).get(str(it['id'])) or {}).get('data') or {}).get('movies') or []
        except Exception:  # noqa: BLE001
            continue
        movies = sorted(movies, key=lambda m: 'gameplay' not in m.get('name', '').lower())          # a gameplay trailer first
        for m in movies[:2]:
            url = m.get('hls_h264') or (m.get('mp4') or {}).get('max') or (m.get('webm') or {}).get('max')
            if not url:
                continue
            run(['ffmpeg', '-y', '-v', 'error', '-ss', '3', '-i', url, '-t', str(seconds), '-map', '0:v:0', '-an', '-c', 'copy', dest], 100)
            if os.path.isfile(dest) and os.path.getsize(dest) > 80000 and min(probe(dest)[:2]) >= 480:
                return {'name': it['name'], 'trailer': m.get('name', 'trailer'), 'page': 'https://store.steampowered.com/app/%d/' % it['id']}
            if os.path.exists(dest):
                os.remove(dest)
    return None


def stock(query, adir, assets, log, want=3):
    key = os.environ.get('PEXELS_API_KEY', '').strip()
    if not key or not query:
        return 0
    try:
        req = urllib.request.Request('https://api.pexels.com/videos/search?per_page=8&query=' + urllib.parse.quote(query), headers={'Authorization': key, 'User-Agent': UA})
        j = json.loads(urllib.request.urlopen(req, timeout=25).read().decode())
    except Exception as e:  # noqa: BLE001
        log('  stock search failed: %s' % str(e)[:90]); return 0
    n = 0
    for v in j.get('videos', []):
        files = [f for f in v.get('video_files', []) if f.get('file_type') == 'video/mp4' and 700 <= min(f.get('width') or 0, f.get('height') or 0) <= 1200]
        if not files or not 6 <= (v.get('duration') or 0) <= 90 or n >= want:
            continue
        aid, f = 'stock%d' % (n + 1), min(files, key=lambda f: abs(min(f['width'], f['height']) - 1080))
        try:
            save(f['link'], os.path.join(adir, aid + '.mp4'))
        except Exception:  # noqa: BLE001
            continue
        assets[aid] = {'kind': 'stock', 'file': aid + '.mp4', 'credit': '', 'about': 'neutral stock footage: ' + query, 'page': v.get('url', ''), 'by': (v.get('user') or {}).get('name', 'Pexels')}
        n += 1
    log('  stock "%s": %d clips' % (query, n))
    return n


def fetch_all(work, budget=None, log=lambda *a: print(*a, flush=True)):
    t0 = time.time()
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    assets, more = plan['assets'], False
    memes = [a for a in assets.values() if a.get('whole') and a.get('file') and a.get('usable', True)]      # a meme's own examples, fetched and looked at by the director (memes.py)
    mfile = os.path.join(work, 'material.json')                # the page's own pictures (a meme's examples, the cover) are footage too
    mat = json.load(open(mfile, encoding='utf-8')) if os.path.isfile(mfile) else {}
    for i, im in enumerate(mat.get('images') or []):
        if mat.get('examples') and '/covers/' not in im['url']:      # a meme page's small pictures are copies of its examples, which were fetched and looked at themselves
            continue
        assets.setdefault('page%d' % i, {'kind': 'photo', 'url': im['url'], 'credit': '', 'about': im.get('alt', ''), 'by': 'the page', 'page': plan.get('url', '')})
    over = lambda: budget is not None and time.time() - t0 > budget
    for aid, a in assets.items():                                 # 1. the story's own posts
        if a.get('file') or a.get('missing') or a['kind'] not in ('clip', 'photo'):
            continue
        name = aid + ('.mp4' if a['kind'] == 'clip' else '.jpg')
        try:
            save(a['url'] + ('?name=orig' if a['kind'] == 'photo' and 'twimg.com' in a['url'] and '?' not in a['url'] else ''), os.path.join(adir, name))
            a['file'] = name
            log('%s: fetched from %s' % (aid, a.get('by', '?') if a.get('by') == 'the page' else 'the post of ' + a.get('by', '?')))
        except Exception as e:  # noqa: BLE001
            a['missing'] = str(e)[:80]; log('%s: not fetched (%s)' % (aid, a['missing']))
    for h in plan.get('hunts', []):                               # 2. the hunts
        aid = h['id']
        if aid in assets:
            continue
        if over() or (budget is not None and budget - (time.time() - t0) < 40):
            more = True; break
        got, game = None, str(h.get('game') or '').strip()
        if game:                                               # a video game: its own trailer from its store page first
            st = steam_trailer(game, os.path.join(adir, aid + '.mp4'), CFG['clip_seconds'], log)
            if st:
                clean = re.sub(r'[^A-Za-z0-9 :&\-\.]', '', st['name']).strip()
                got = {'kind': 'hunt', 'file': aid + '.mp4', 'credit': 'TRAILER · %s / STEAM' % clean.upper()[:30], 'title': '%s: %s' % (clean, st['trailer']),
                       'about': '%s, official trailer "%s" from its Steam page (must show: %s)' % (clean, st['trailer'], h.get('must_show', '')), 'page': st['page'], 'by': 'Steam', 'must_show': h.get('must_show', '')}
                log('%s: got the trailer "%s" of %s from Steam' % (aid, st['trailer'][:50], clean))
            else:
                log('%s: no trailer of "%s" on Steam; searching YouTube' % (aid, game))
        if not got:
            log('%s: searching "%s"' % (aid, h['query'])) if ai.CONFIG.get('youtube', True) and not os.environ.get('GITHUB_ACTIONS') else None
        tube = ai.CONFIG.get('youtube', True) and not os.environ.get('GITHUB_ACTIONS')      # GitHub's machines are refused by YouTube: not even asked
        if not got and not tube:
            log('%s: YouTube searches are off here; "%s" is not fetched' % (aid, h['query']))
        for vid, dur, title, channel in ([] if got or not tube else yt_search(h['query'], log)):
            if vid in h.setdefault('tried', []):               # a result that already failed or was too slow is not asked again
                continue
            h['tried'].append(vid)
            start =int(6 if dur < 100 else dur * 0.14 if dur <= 1200 else dur * 0.4)      # a long broadcast opens on talk: go to the middle
            secs = int(min(CFG['clip_seconds'], max(15, dur - start - 4)))
            if yt_section(vid, os.path.join(adir, aid + '.mp4'), start, secs, dur, log, limit=120 if budget is None else min(70, max(30, budget - (time.time() - t0)))):
                got = {'kind': 'hunt', 'file': aid + '.mp4', 'credit': 'CLIP · %s / YOUTUBE' % re.sub(r'[^A-Za-z0-9 &\-\.]', '', channel).upper()[:26].strip(), 'about': '%s (searched: %s; must show: %s)' % (title, h['query'], h.get('must_show', '')),
                       'title': title, 'page': 'https://www.youtube.com/watch?v=' + vid, 'by': channel, 'must_show': h.get('must_show', '')}
                log('%s: got "%s" by %s (%ds from %d:%02d)' % (aid, title[:60], channel, secs, start // 60, start % 60))
                break
            if over():
                break
        if not got and over():
            more = True; break                                    # out of time, not out of options: the next run tries again
        assets[aid] = got or {'kind': 'hunt', 'missing': 'no usable result or the download was refused', 'about': h['query']}
        json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    for aid, a in assets.items():                                 # sizes and lengths
        if a.get('file') and 'w' not in a:
            a['w'], a['h'], a['dur'] = probe(os.path.join(adir, a['file'])) if a['kind'] != 'photo' else probe(os.path.join(adir, a['file']))[:2] + (0.0,)
            if not a['w']:
                a.pop('file'); a['missing'] = 'unreadable file'
    clips = [a for a in assets.values() if a.get('file') and a['kind'] in ('clip', 'hunt')]
    if not more and CFG.get('stock_fallback') and sum(a['dur'] for a in clips) < 45 and len(memes) < 4 and not any(a['kind'] == 'stock' for a in assets.values()):   # 3. too little: neutral stock, blurred only (a meme with its own pictures needs none)
        log('little footage (%d clips, %.0f s): adding neutral stock, used blurred only' % (len(clips), sum(a['dur'] for a in clips)))
        stock(plan.get('stock') or '', adir, assets, log)
        for aid, a in assets.items():
            if a.get('file') and 'w' not in a:
                a['w'], a['h'], a['dur'] = probe(os.path.join(adir, a['file']))
    json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    have = {k: a for k, a in assets.items() if a.get('file')}
    log('footage: %s | missing: %s%s' % (', '.join('%s %dx%d %.0fs' % (k, a['w'], a['h'], a.get('dur', 0)) for k, a in have.items()) or 'none',
                                         ', '.join(k for k, a in assets.items() if not a.get('file')) or 'none', ' | MORE TO FETCH: run this step again' if more else ''))
    return not more


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    done = fetch_all(sys.argv[1], float(sys.argv[2]) if len(sys.argv) > 2 else None)
    sys.exit(0 if done else 3)
